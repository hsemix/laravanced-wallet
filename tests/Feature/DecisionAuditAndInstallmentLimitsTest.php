<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Tests\Feature;

use Hsemix\AdvancedWallet\Enums\InstallmentStatus;
use Hsemix\AdvancedWallet\Enums\TransactionStatus;
use Hsemix\AdvancedWallet\Exceptions\LimitExceededException;
use Hsemix\AdvancedWallet\Exceptions\PeriodLimitExceededException;
use Hsemix\AdvancedWallet\Facades\Wallet;
use Hsemix\AdvancedWallet\Models\Transaction;
use Hsemix\AdvancedWallet\Models\Transfer;
use Hsemix\AdvancedWallet\Tests\TestCase;
use Illuminate\Support\Carbon;

class DecisionAuditAndInstallmentLimitsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-01-15 09:00:00');
        $this->bootstrapLedger();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ---------------------------------------------------- decision audit */

    private function wire(): void
    {
        Wallet::transferTypes()->create(['name' => 'Wire', 'internal_name' => 'wire', 'from_account_type' => 'wallet',
            'to_account_type' => 'wallet', 'requires_authorization' => true]);
    }

    public function test_authorize_records_who_approved_and_when(): void
    {
        $this->wire();
        [$ann] = $this->funded('Ann', 100);
        [$bob] = $this->funded('Bob');
        $admin = $this->user('Admin');

        $tx = $ann->payTo($bob, 'wallet.wire', 10);
        $this->assertNull($tx->fresh()->decided_by_id, 'nobody has decided yet');

        Wallet::payments()->authorize($tx, $admin);

        $tx = $tx->fresh();
        $this->assertTrue($tx->decidedBy->is($admin));
        $this->assertNotNull($tx->decided_at);
        $this->assertNull($tx->decision_reason);
    }

    public function test_deny_records_who_and_the_reason_and_cancel_records_the_payer(): void
    {
        $this->wire();
        [$ann] = $this->funded('Ann', 100);
        [$bob] = $this->funded('Bob');
        $admin = $this->user('Admin');

        $denied = $ann->payTo($bob, 'wallet.wire', 10);
        Wallet::payments()->deny($denied, $admin, 'Looks like fraud');
        $denied = $denied->fresh();
        $this->assertTrue($denied->decidedBy->is($admin));
        $this->assertSame('Looks like fraud', $denied->decision_reason);

        $cancelled = $ann->payTo($bob, 'wallet.wire', 10);
        Wallet::payments()->cancel($cancelled, $ann);
        $this->assertTrue($cancelled->fresh()->decidedBy->is($ann));
    }

    public function test_decider_is_optional_and_a_long_reason_is_trimmed(): void
    {
        $this->wire();
        [$ann] = $this->funded('Ann', 100);
        [$bob] = $this->funded('Bob');

        $tx = $ann->payTo($bob, 'wallet.wire', 10);
        Wallet::payments()->deny($tx, null, str_repeat('x', 900));

        $this->assertNull($tx->fresh()->decidedBy);
        $this->assertSame(500, mb_strlen($tx->fresh()->decision_reason));
    }

    public function test_cancelling_a_schedule_records_who_cancelled_it(): void
    {
        Wallet::transferTypes()->create(['name' => 'Plan', 'internal_name' => 'plan', 'from_account_type' => 'wallet',
            'to_account_type' => 'wallet', 'allows_scheduled_payments' => true]);
        [$ann] = $this->funded('Ann', 100);
        [$bob] = $this->funded('Bob');

        $tx = Wallet::scheduled()->schedule(['from' => $ann, 'to' => $bob, 'type' => 'wallet.plan', 'amount' => 30,
            'installments' => 3, 'immediate' => false, 'first_date' => '2026-02-01']);
        Wallet::scheduled()->cancel($tx, $ann);

        $this->assertTrue($tx->fresh()->decidedBy->is($ann));
    }

    /* ------------------------------------------------ installment limits */

    private function limitedPlan(array $limits): void
    {
        Wallet::transferTypes()->create([
            'name' => 'Plan', 'internal_name' => 'plan', 'from_account_type' => 'wallet', 'to_account_type' => 'wallet',
            'allows_scheduled_payments' => true, 'allows_recurring_payments' => true,
        ] + $limits);
    }

    public function test_an_installment_outside_the_per_payment_limits_is_refused_when_scheduling(): void
    {
        $this->limitedPlan(['min_amount' => 5, 'max_amount' => 20]);
        [$ann] = $this->funded('Ann', 1000);
        [$bob] = $this->funded('Bob');
        $base = ['from' => $ann, 'to' => $bob, 'type' => 'wallet.plan', 'immediate' => false, 'first_date' => '2026-02-01'];

        foreach ([['amount' => 90, 'installments' => 3], ['amount' => 12, 'installments' => 4]] as $case) {   // 30 each / 3 each
            try {
                Wallet::scheduled()->schedule($case + $base);
                $this->fail('expected LimitExceededException');
            } catch (LimitExceededException $e) {
                $this->assertNotInstanceOf(PeriodLimitExceededException::class, $e);
            }
        }

        $this->assertNotNull(Wallet::scheduled()->schedule(['amount' => 60, 'installments' => 4] + $base));   // 15 each is fine
    }

    public function test_an_installment_larger_than_a_whole_period_cap_is_refused_when_scheduling(): void
    {
        $this->limitedPlan(['max_amount_per_day' => 50]);
        [$ann] = $this->funded('Ann', 1000);
        [$bob] = $this->funded('Bob');

        $this->expectException(LimitExceededException::class);
        Wallet::scheduled()->schedule(['from' => $ann, 'to' => $bob, 'type' => 'wallet.plan', 'amount' => 120, 'installments' => 2,
            'immediate' => false, 'first_date' => '2026-02-01']);   // 60 each, can never fit a 50/day cap
    }

    public function test_a_used_up_daily_limit_defers_the_installment_until_the_next_day(): void
    {
        $this->limitedPlan(['max_amount_per_day' => 50]);
        [$ann, $annAcc] = $this->funded('Ann', 1000);
        [$bob, $bobAcc] = $this->funded('Bob');

        // two 30s due on consecutive days; processing both on the 20th hits the 50/day cap on the second
        $tx = Wallet::scheduled()->schedule(['from' => $ann, 'to' => $bob, 'type' => 'wallet.plan', 'amount' => 60, 'installments' => 2,
            'immediate' => false, 'first_date' => '2026-01-15', 'frequency_unit' => 'day']);

        $this->assertSame(['processed' => 1, 'failed' => 0, 'deferred' => 1], Wallet::scheduled()->processDue('2026-01-20'));
        $this->assertMoney(30, $this->bal($bobAcc));
        $second = $tx->installments()->where('number', 2)->first();
        $this->assertSame(InstallmentStatus::Scheduled, $second->status, 'deferred, not failed');
        $this->assertSame(TransactionStatus::Open, $tx->fresh()->status);

        // nothing was booked for the deferred installment
        $this->assertSame(1, Transfer::where('installment_id', '!=', null)->whereNull('parent_id')->count());

        // same day again: still capped, still deferred
        $this->assertSame(['processed' => 0, 'failed' => 0, 'deferred' => 1], Wallet::scheduled()->processDue('2026-01-20'));

        // next day the cap resets and the catch-up run pays it
        Carbon::setTestNow('2026-01-16 09:00:00');
        $this->assertSame(['processed' => 1, 'failed' => 0, 'deferred' => 0], Wallet::scheduled()->processDue('2026-01-20'));
        $this->assertMoney(60, $this->bal($bobAcc));
        $this->assertSame(TransactionStatus::Closed, $tx->fresh()->status);
    }

    public function test_period_limits_also_count_payments_made_outside_the_schedule(): void
    {
        $this->limitedPlan(['max_transfers_per_day' => 1]);
        [$ann] = $this->funded('Ann', 1000);
        [$bob, $bobAcc] = $this->funded('Bob');

        $ann->payTo($bob, 'wallet.plan', 5);   // uses today's only slot (ordinary payment of the same type)

        $tx = Wallet::scheduled()->schedule(['from' => $ann, 'to' => $bob, 'type' => 'wallet.plan', 'amount' => 20, 'installments' => 1,
            'immediate' => false, 'first_date' => '2026-01-15']);

        $this->assertSame(['processed' => 0, 'failed' => 0, 'deferred' => 1], Wallet::scheduled()->processDue());
        $this->assertSame(InstallmentStatus::Scheduled, $tx->installments()->first()->status);
    }

    public function test_an_immediate_first_installment_that_hits_a_limit_fails_the_whole_schedule(): void
    {
        $this->limitedPlan(['max_transfers_per_day' => 1]);
        [$ann] = $this->funded('Ann', 1000);
        [$bob] = $this->funded('Bob');
        $ann->payTo($bob, 'wallet.plan', 5);

        try {
            Wallet::scheduled()->schedule(['from' => $ann, 'to' => $bob, 'type' => 'wallet.plan', 'amount' => 20, 'installments' => 2]);
            $this->fail('expected PeriodLimitExceededException');
        } catch (PeriodLimitExceededException) {
        }

        $this->assertSame(0, Transaction::where('scheduled', true)->count(), 'no half-created schedule');
    }

    public function test_installments_are_unaffected_when_the_type_has_no_limits(): void
    {
        $this->limitedPlan([]);
        [$ann] = $this->funded('Ann', 100);
        [$bob, $bobAcc] = $this->funded('Bob');

        Wallet::scheduled()->schedule(['from' => $ann, 'to' => $bob, 'type' => 'wallet.plan', 'amount' => 90, 'installments' => 3]);

        $this->assertMoney(30, $this->bal($bobAcc));
    }
}
