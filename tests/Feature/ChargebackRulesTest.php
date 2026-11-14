<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Tests\Feature;

use Hsemix\AdvancedWallet\Exceptions\ChargebackNotAllowedException;
use Hsemix\AdvancedWallet\Exceptions\InvalidParameterException;
use Hsemix\AdvancedWallet\Exceptions\UnauthorizedChargebackException;
use Hsemix\AdvancedWallet\Facades\Wallet;
use Hsemix\AdvancedWallet\Models\Transfer;
use Hsemix\AdvancedWallet\Tests\TestCase;

class ChargebackRulesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapLedger();
    }

    protected function tearDown(): void
    {
        Wallet::authorizeChargebacksUsing(null);
        parent::tearDown();
    }

    private function rules(array $attributes): void
    {
        Wallet::transferTypes()->resolve('wallet.transfer')->update($attributes);
    }

    private function paid(int $amount = 50): array
    {
        [$ann, $annAcc] = $this->funded('Ann', 100);
        [$bob, $bobAcc] = $this->funded('Bob');

        return [$ann->payTo($bob, 'wallet.transfer', $amount), $annAcc, $bobAcc, $ann, $bob];
    }

    public function test_defaults_change_nothing(): void
    {
        [$tx, $annAcc] = $this->paid();

        Wallet::payments()->chargeback($tx);

        $this->assertMoney(100, $this->bal($annAcc));
    }

    public function test_a_type_can_forbid_chargebacks(): void
    {
        $this->rules(['allows_chargebacks' => false]);
        [$tx, $annAcc] = $this->paid();

        try {
            Wallet::payments()->chargeback($tx);
            $this->fail('chargeback was allowed');
        } catch (ChargebackNotAllowedException) {
        }

        $this->assertMoney(50, $this->bal($annAcc));
    }

    public function test_the_time_window_is_enforced(): void
    {
        $this->rules(['max_chargeback_time_amount' => 2, 'max_chargeback_time_field' => 'day']);
        [$tx, $annAcc] = $this->paid();

        $this->travel(47)->hours();     // still inside the window
        $this->assertNotNull(Wallet::payments()->chargeback($tx));
        $this->assertMoney(100, $this->bal($annAcc));
    }

    public function test_after_the_window_a_chargeback_is_refused(): void
    {
        $this->rules(['max_chargeback_time_amount' => 2, 'max_chargeback_time_field' => 'day']);
        [$tx, $annAcc] = $this->paid();

        $this->travel(49)->hours();

        $this->expectException(ChargebackNotAllowedException::class);
        Wallet::payments()->chargeback($tx);
    }

    public function test_a_late_installment_blocks_a_whole_transaction_chargeback_but_not_a_fresh_single_one(): void
    {
        $this->rules(['max_chargeback_time_amount' => 20, 'max_chargeback_time_field' => 'day', 'allows_scheduled_payments' => true]);
        [$ann, $annAcc] = $this->funded('Ann', 100);
        [$bob] = $this->funded('Bob');

        $scheduled = Wallet::scheduled()->schedule(['from' => $ann, 'to' => $bob, 'type' => 'wallet.transfer', 'amount' => 60, 'installments' => 2]);
        $this->travel(25)->days();                                          // the 1st installment is now outside the 20-day window
        Wallet::scheduled()->processDue(now()->addDays(40)->toDateString());   // books the 2nd, today

        $roots = Transfer::where('transaction_id', $scheduled->id)->whereNull('parent_id')->orderBy('id')->get();
        $this->assertCount(2, $roots);

        try {
            Wallet::payments()->chargeback($scheduled);
            $this->fail('late installment was reversed');
        } catch (ChargebackNotAllowedException) {
        }

        Wallet::payments()->chargeback($roots[1]);       // the fresh one alone is fine
        $this->assertMoney(70, $this->bal($annAcc));     // 100 - 30 (1st stays booked)
    }

    public function test_forced_chargebacks_can_be_disabled_per_type(): void
    {
        $this->rules(['allows_forced_chargebacks' => false]);
        [$tx, , $bobAcc] = $this->paid();

        try {
            Wallet::payments()->chargeback($tx, force: true);
            $this->fail('forced chargeback was allowed');
        } catch (ChargebackNotAllowedException) {
        }

        Wallet::payments()->chargeback($tx);        // an ordinary one is still fine
        $this->assertMoney(0, $this->bal($bobAcc));
    }

    public function test_who_may_charge_back_is_decided_by_the_callback(): void
    {
        $admin = $this->user('Admin');
        Wallet::authorizeChargebacksUsing(fn ($actor, $original, $force) => $actor !== null && $actor->is($admin) && ! $force);
        [$tx, $annAcc, , $ann] = $this->paid();

        foreach ([[null, false], [$ann, false], [$admin, true]] as [$actor, $force]) {
            try {
                Wallet::payments()->chargeback($tx, $actor, null, $force);
                $this->fail('unauthorized chargeback succeeded');
            } catch (UnauthorizedChargebackException) {
            }
        }

        $this->assertMoney(50, $this->bal($annAcc));
        $reversal = Wallet::payments()->chargeback($tx, $admin);
        $this->assertTrue($reversal->by->is($admin));
        $this->assertMoney(100, $this->bal($annAcc));
    }

    public function test_window_settings_are_validated_on_create(): void
    {
        $this->expectException(InvalidParameterException::class);
        Wallet::transferTypes()->create(['name' => 'X', 'internal_name' => 'x', 'from_account_type' => 'wallet', 'to_account_type' => 'wallet',
            'max_chargeback_time_amount' => 5]);   // amount without a unit
    }

    public function test_a_window_can_be_configured_on_create(): void
    {
        $type = Wallet::transferTypes()->create(['name' => 'Y', 'internal_name' => 'y', 'from_account_type' => 'wallet', 'to_account_type' => 'wallet',
            'max_chargeback_time_amount' => 3, 'max_chargeback_time_field' => 'hour', 'allows_forced_chargebacks' => false]);

        $this->assertSame(3, $type->max_chargeback_time_amount);
        $this->assertSame('hour', $type->max_chargeback_time_field->value);
        $this->assertFalse($type->allows_forced_chargebacks);
    }
}
