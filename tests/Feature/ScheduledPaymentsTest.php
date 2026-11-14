<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Tests\Feature;

use Hsemix\AdvancedWallet\Enums\InstallmentStatus;
use Hsemix\AdvancedWallet\Enums\TransactionStatus;
use Hsemix\AdvancedWallet\Exceptions\InvalidParameterException;
use Hsemix\AdvancedWallet\Exceptions\InvalidTransferTypeException;
use Hsemix\AdvancedWallet\Facades\Wallet;
use Hsemix\AdvancedWallet\Models\Installment;
use Hsemix\AdvancedWallet\Support\Money;
use Hsemix\AdvancedWallet\Tests\TestCase;
use Illuminate\Support\Carbon;

class ScheduledPaymentsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-01-15 09:00:00');
        $this->bootstrapLedger();

        Wallet::transferTypes()->create([
            'name' => 'Plan', 'internal_name' => 'plan', 'from_account_type' => 'wallet', 'to_account_type' => 'wallet',
            'allows_scheduled_payments' => true, 'allows_recurring_payments' => true, 'max_installments' => 12,
        ]);
        Wallet::transferTypes()->create([
            'name' => 'Loan', 'internal_name' => 'loan', 'from_account_type' => 'wallet', 'to_account_type' => 'wallet',
            'allows_scheduled_payments' => true, 'allows_interest' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_immediate_first_installment_then_due_dates_process_on_schedule(): void
    {
        [$ann, $annAcc] = $this->funded('Ann', 100);
        [$bob, $bobAcc] = $this->funded('Bob');

        $tx = Wallet::scheduled()->schedule(['from' => $ann, 'to' => $bob, 'type' => 'wallet.plan', 'amount' => 90, 'installments' => 3, 'by' => $ann]);

        $this->assertSame(TransactionStatus::Open, $tx->status);
        $this->assertSame(['2026-01-15', '2026-02-15', '2026-03-15'], $tx->installments->map(fn ($i) => $i->due_date->toDateString())->all());
        $this->assertMoney(30, $this->bal($bobAcc), 'first installment is paid immediately');

        $this->assertSame(['processed' => 0, 'failed' => 0, 'deferred' => 0], Wallet::scheduled()->processDue());

        $this->assertSame(['processed' => 1, 'failed' => 0, 'deferred' => 0], Wallet::scheduled()->processDue('2026-02-15'));
        $this->assertMoney(60, $this->bal($bobAcc));

        // a missed run is caught up, not skipped
        $this->assertSame(['processed' => 1, 'failed' => 0, 'deferred' => 0], Wallet::scheduled()->processDue('2026-04-01'));
        $this->assertMoney(90, $this->bal($bobAcc));
        $this->assertSame(TransactionStatus::Closed, $tx->fresh()->status);

        // idempotent
        $this->assertSame(['processed' => 0, 'failed' => 0, 'deferred' => 0], Wallet::scheduled()->processDue('2026-05-01'));
        $this->assertMoney(90, $this->bal($bobAcc));
    }

    public function test_installments_absorb_rounding_so_total_is_exact(): void
    {
        [$ann] = $this->funded('Ann', 100);
        [$bob] = $this->funded('Bob');

        $tx = Wallet::scheduled()->schedule(['from' => $ann, 'to' => $bob, 'type' => 'wallet.plan', 'amount' => 100, 'installments' => 3, 'immediate' => false, 'first_date' => '2026-01-20']);

        $this->assertMoney(100, $tx->installments->reduce(fn ($c, $i) => Money::add($c, $i->amount), '0'));
        $this->assertMoney('33.3333', $tx->installments[0]->amount);
        $this->assertMoney('33.3334', $tx->installments[2]->amount);
    }

    public function test_failed_installment_is_marked_failed_and_others_continue(): void
    {
        [$ann, $annAcc] = $this->funded('Ann', 30);
        [$bob, $bobAcc] = $this->funded('Bob');

        $tx = Wallet::scheduled()->schedule(['from' => $ann, 'to' => $bob, 'type' => 'wallet.plan', 'amount' => 60, 'installments' => 2, 'by' => $ann]);
        $this->assertMoney(30, $this->bal($bobAcc));   // 1st paid, Ann now at 0

        $this->assertSame(['processed' => 0, 'failed' => 1, 'deferred' => 0], Wallet::scheduled()->processDue('2026-02-15'));

        $second = $tx->installments()->where('number', 2)->first();
        $this->assertSame(InstallmentStatus::Failed, $second->status);
        $this->assertNotEmpty($second->failure_reason);
        $this->assertSame(TransactionStatus::Closed, $tx->fresh()->status);
    }

    public function test_reserving_the_total_protects_funds_and_releases_as_installments_settle(): void
    {
        Wallet::transferTypes()->create([
            'name' => 'Held', 'internal_name' => 'held', 'from_account_type' => 'wallet', 'to_account_type' => 'wallet',
            'allows_scheduled_payments' => true, 'reserve_total_amount_on_scheduled_payments' => true,
        ]);
        [$ann, $annAcc] = $this->funded('Ann', 100);
        [$bob] = $this->funded('Bob');

        $tx = Wallet::scheduled()->schedule(['from' => $ann, 'to' => $bob, 'type' => 'wallet.held', 'amount' => 100, 'installments' => 4, 'immediate' => false, 'first_date' => '2026-02-01']);

        $this->assertMoney(100, Wallet::accounts()->balance($annAcc)->reserved);
        $this->assertMoney(0, Wallet::accounts()->balance($annAcc)->available);

        Wallet::scheduled()->processDue('2026-02-01');
        $b = Wallet::accounts()->balance($annAcc);
        $this->assertMoney(75, $b->balance);
        $this->assertMoney(75, $b->reserved);

        Wallet::scheduled()->cancel($tx);
        $b = Wallet::accounts()->balance($annAcc);
        $this->assertMoney(0, $b->reserved);
        $this->assertMoney(75, $b->available);
        $this->assertSame(0, Installment::where('status', InstallmentStatus::Scheduled)->count());
    }

    public function test_flat_interest_is_spread_evenly(): void
    {
        [$ann] = $this->funded('Ann', 1000);
        [$bob] = $this->funded('Bob');

        $tx = Wallet::scheduled()->schedule(['from' => $ann, 'to' => $bob, 'type' => 'wallet.loan', 'amount' => 1000, 'installments' => 4,
            'interest_rate' => 8, 'immediate' => false, 'first_date' => '2026-02-01']);

        $this->assertMoney(1080, $tx->installments->reduce(fn ($c, $i) => Money::add($c, $i->amount), '0'));
        $this->assertMoney(270, $tx->installments[0]->amount);
        $this->assertMoney(20, $tx->installments[0]->interest);
        $this->assertMoney(0, $tx->installments[3]->balance);
    }

    public function test_annual_interest_amortises_a_declining_balance(): void
    {
        Wallet::transferTypes()->create([
            'name' => 'Mortgage', 'internal_name' => 'mortgage', 'from_account_type' => 'wallet', 'to_account_type' => 'wallet',
            'allows_scheduled_payments' => true, 'allows_interest' => true, 'interest_is_annual' => true, 'interest_rate' => 12,
        ]);
        [$ann] = $this->funded('Ann', 10);
        [$bob] = $this->funded('Bob');

        $tx = Wallet::scheduled()->schedule(['from' => $ann, 'to' => $bob, 'type' => 'wallet.mortgage', 'amount' => 1000,
            'installments' => 12, 'immediate' => false, 'first_date' => '2026-02-01']);

        $rows = $tx->installments;
        // standard 12% APR / 12 months / 1000 principal => 88.8488 per month
        $this->assertEqualsWithDelta(88.85, (float) $rows[0]->amount, 0.01);
        $this->assertMoney(10, $rows[0]->interest);                // 1% of 1000
        $this->assertTrue(Money::gt($rows[0]->interest, $rows[11]->interest), 'interest declines');
        $this->assertMoney(0, $rows[11]->balance);
        $this->assertMoney(1000, $rows->reduce(fn ($c, $i) => Money::add($c, $i->principal), '0'));
    }

    public function test_validation_rules(): void
    {
        [$ann] = $this->funded('Ann', 10);
        [$bob] = $this->funded('Bob');
        $base = ['from' => $ann, 'to' => $bob, 'amount' => 10, 'installments' => 2];

        $expect = function (string $exception, array $override) use ($base) {
            try {
                Wallet::scheduled()->schedule($override + $base);
                $this->fail("expected {$exception}");
            } catch (\Throwable $e) {
                $this->assertInstanceOf($exception, $e, $e->getMessage());
            }
        };

        $expect(InvalidTransferTypeException::class, ['type' => 'wallet.transfer']);                  // not schedulable
        $expect(InvalidParameterException::class, ['type' => 'wallet.plan', 'installments' => 13]);    // over max
        $expect(InvalidParameterException::class, ['type' => 'wallet.loan', 'frequency_unit' => 'week']); // no recurring
        $expect(InvalidParameterException::class, ['type' => 'wallet.loan']);                          // interest rate missing
        $expect(InvalidParameterException::class, ['type' => 'wallet.plan', 'immediate' => false, 'first_date' => '2026-01-01']); // past
    }
}
