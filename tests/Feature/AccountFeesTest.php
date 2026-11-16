<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Tests\Feature;

use Hsemix\AdvancedWallet\Enums\AccountFeeChargeStatus;
use Hsemix\AdvancedWallet\Events\AccountFeeCharged;
use Hsemix\AdvancedWallet\Events\AccountFeeSkipped;
use Hsemix\AdvancedWallet\Exceptions\InvalidParameterException;
use Hsemix\AdvancedWallet\Exceptions\InvalidTransferTypeException;
use Hsemix\AdvancedWallet\Exceptions\MissingParameterException;
use Hsemix\AdvancedWallet\Facades\Wallet;
use Hsemix\AdvancedWallet\Models\AccountFee;
use Hsemix\AdvancedWallet\Models\AccountFeeCharge;
use Hsemix\AdvancedWallet\Models\Transaction;
use Hsemix\AdvancedWallet\Tests\TestCase;
use Illuminate\Support\Facades\Event;

class AccountFeesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapLedger();

        // The payment type every account fee is booked with: wallet -> fees (system).
        Wallet::transferTypes()->create(['name' => 'Account fee', 'internal_name' => 'account_fee', 'from_account_type' => 'wallet', 'to_account_type' => 'fees']);
    }

    private function fee(array $overrides = []): AccountFee
    {
        return Wallet::accountFees()->create($overrides + [
            'name' => 'Opening fee', 'internal_name' => 'opening', 'account_type' => 'wallet',
            'transfer_type' => 'wallet.account_fee', 'trigger' => 'opening', 'amount' => 5,
        ]);
    }

    private function monthly(array $overrides = []): AccountFee
    {
        return $this->fee($overrides + [
            'name' => 'Maintenance', 'internal_name' => 'maintenance', 'trigger' => 'recurring',
            'every_amount' => 1, 'every_unit' => 'month', 'amount' => 2,
        ]);
    }

    private function monthsFromNow(int $months): string
    {
        return now()->addMonthsNoOverflow($months)->toDateString();
    }

    /* ---------------------------------------------------------------- opening */

    public function test_an_opening_fee_is_charged_when_the_account_opens(): void
    {
        $this->fee(['on_insufficient_funds' => 'allow_negative']);

        $account = $this->user()->openAccount('wallet');

        $this->assertMoney(-5, $this->bal($account));
        $this->assertMoney(5, $this->bal($this->feeAccount()));
        $this->assertNotNull($account->fresh()->negative_since);

        $charge = AccountFeeCharge::sole();
        $this->assertSame(AccountFeeChargeStatus::Charged, $charge->status);
        $this->assertSame(0, $charge->period);
        $this->assertMoney(5, $charge->amount);
        $this->assertSame($charge->transaction_id, Transaction::sole()->id);
    }

    public function test_skip_forgives_an_opening_fee_the_account_cannot_cover(): void
    {
        $this->fee(); // default policy: skip

        $account = $this->user()->openAccount('wallet');

        $this->assertMoney(0, $this->bal($account));
        $charge = AccountFeeCharge::sole();
        $this->assertSame(AccountFeeChargeStatus::Skipped, $charge->status);
        $this->assertStringContainsString('Insufficient', (string) $charge->reason);

        // Funding the account later does not bring the forgiven fee back.
        Wallet::pay(['from' => 'system', 'to' => $account, 'type' => 'treasury.deposit', 'amount' => 50]);
        $this->assertSame(['charged' => 0, 'skipped' => 0, 'waived' => 0, 'pending' => 0], Wallet::accountFees()->processDue());
        $this->assertMoney(50, $this->bal($account));
    }

    public function test_retry_holds_an_opening_fee_until_the_account_is_funded(): void
    {
        $this->fee(['on_insufficient_funds' => 'retry']);
        $account = $this->user()->openAccount('wallet');

        $this->assertSame(AccountFeeChargeStatus::Pending, AccountFeeCharge::sole()->status);
        $this->assertSame(1, Wallet::accountFees()->processDue()['pending']);   // still cannot pay

        Wallet::pay(['from' => 'system', 'to' => $account, 'type' => 'treasury.deposit', 'amount' => 50]);
        $this->assertSame(1, Wallet::accountFees()->processDue()['charged']);

        $this->assertMoney(45, $this->bal($account));
        $this->assertSame(AccountFeeChargeStatus::Charged, AccountFeeCharge::sole()->status);
        $this->assertSame(0, Wallet::accountFees()->processDue()['charged']);   // never twice
    }

    public function test_opening_an_account_never_fails_because_of_the_fee(): void
    {
        $this->fee();

        $this->assertNotNull($this->user()->openAccount('wallet'));
    }

    public function test_an_opening_fee_only_applies_to_its_account_type(): void
    {
        $this->fee(['on_insufficient_funds' => 'allow_negative']);

        Wallet::accounts()->createAccountType(['name' => 'Savings', 'internal_name' => 'savings', 'kind' => 'user']);
        $this->user()->openAccount('savings');

        $this->assertSame(0, AccountFeeCharge::count());
    }

    /* -------------------------------------------------------------- recurring */

    public function test_a_recurring_fee_is_charged_once_per_period(): void
    {
        [, $account] = $this->funded('Ann', 100);
        $this->monthly();

        $this->assertSame(0, Wallet::accountFees()->processDue()['charged']);   // first period not due yet
        $this->assertMoney(100, $this->bal($account));

        $this->assertSame(1, Wallet::accountFees()->processDue($this->monthsFromNow(1))['charged']);
        $this->assertMoney(98, $this->bal($account));
        $this->assertMoney(2, $this->bal($this->feeAccount()));

        // Running again, even many times, charges nothing more for that period.
        $this->assertSame(0, Wallet::accountFees()->processDue($this->monthsFromNow(1))['charged']);
        $this->assertSame(1, AccountFeeCharge::count());
    }

    public function test_missed_periods_are_caught_up(): void
    {
        [, $account] = $this->funded('Ann', 100);
        $this->monthly();

        $this->assertSame(3, Wallet::accountFees()->processDue($this->monthsFromNow(3))['charged']);
        $this->assertMoney(94, $this->bal($account));
        $this->assertSame([1, 2, 3], AccountFeeCharge::orderBy('period')->pluck('period')->all());
    }

    public function test_a_new_fee_never_bills_the_past(): void
    {
        [, $account] = $this->funded('Ann', 100);

        // The account is a year old by the time the fee is introduced.
        $account->forceFill(['created_at' => now()->subYear()])->save();
        $this->monthly();

        $this->assertSame(0, Wallet::accountFees()->processDue()['charged']);
        $this->assertSame(1, Wallet::accountFees()->processDue($this->monthsFromNow(1))['charged']);
    }

    public function test_a_percentage_fee_takes_a_share_of_the_balance_within_its_bounds(): void
    {
        [, $rich] = $this->funded('Rich', 1000);
        [, $middle] = $this->funded('Middle', 100);
        [, $poor] = $this->funded('Poor', 10);
        [, $empty] = $this->funded('Empty');

        $this->monthly(['charge_mode' => 'percentage', 'amount' => 1, 'min_amount' => 2, 'max_amount' => 5]);

        $result = Wallet::accountFees()->processDue($this->monthsFromNow(1));

        $this->assertMoney(995, $this->bal($rich));    // 1% = 10, capped at 5
        $this->assertMoney(98, $this->bal($middle));   // 1% = 1 -> raised to the floor of 2
        $this->assertMoney(8, $this->bal($poor));      // 1% = 0.10 -> floor 2
        $this->assertMoney(0, $this->bal($empty));     // nothing to take a percentage of
        $this->assertSame(['charged' => 3, 'skipped' => 0, 'waived' => 1, 'pending' => 0], $result);
    }

    public function test_retry_keeps_a_recurring_charge_until_it_can_be_paid(): void
    {
        [, $account] = $this->funded('Ann', 1);
        $this->monthly(['on_insufficient_funds' => 'retry']);

        $this->assertSame(1, Wallet::accountFees()->processDue($this->monthsFromNow(1))['pending']);
        $this->assertMoney(1, $this->bal($account));

        Wallet::pay(['from' => 'system', 'to' => $account, 'type' => 'treasury.deposit', 'amount' => 10]);
        $this->assertSame(1, Wallet::accountFees()->processDue($this->monthsFromNow(1))['charged']);
        $this->assertMoney(9, $this->bal($account));
    }

    public function test_skip_forgives_only_the_period_it_could_not_pay(): void
    {
        [, $account] = $this->funded('Ann', 1);
        $this->monthly();

        $this->assertSame(1, Wallet::accountFees()->processDue($this->monthsFromNow(1))['skipped']);

        Wallet::pay(['from' => 'system', 'to' => $account, 'type' => 'treasury.deposit', 'amount' => 10]);
        $result = Wallet::accountFees()->processDue($this->monthsFromNow(2));

        $this->assertSame(1, $result['charged']);   // month 2 is charged, month 1 stays forgiven
        $this->assertMoney(9, $this->bal($account));
    }

    public function test_allow_negative_charges_past_the_credit_limit(): void
    {
        $user = $this->user();
        $account = $user->openAccount('wallet', ['credit_limit' => 1]);
        $this->monthly(['on_insufficient_funds' => 'allow_negative', 'amount' => 10]);

        $this->assertSame(1, Wallet::accountFees()->processDue($this->monthsFromNow(1))['charged']);
        $this->assertMoney(-10, $this->bal($account));
    }

    public function test_a_disabled_fee_charges_nothing(): void
    {
        $this->funded('Ann', 100);
        $this->monthly(['enabled' => false]);

        $this->assertSame(0, Wallet::accountFees()->processDue($this->monthsFromNow(3))['charged']);
        $this->assertSame(0, AccountFeeCharge::count());
    }

    public function test_inactive_accounts_are_not_charged_and_not_billed_in_arrears_later(): void
    {
        [, $account] = $this->funded('Ann', 100);
        $account->update(['active' => false]);
        $this->monthly();

        $this->assertSame(['charged' => 0, 'skipped' => 2, 'waived' => 0, 'pending' => 0], Wallet::accountFees()->processDue($this->monthsFromNow(2)));

        $account->update(['active' => true]);
        $this->assertSame(1, Wallet::accountFees()->processDue($this->monthsFromNow(3))['charged']);
        $this->assertMoney(98, $this->bal($account));
    }

    public function test_system_accounts_are_never_charged(): void
    {
        $this->monthly();

        $this->assertSame(0, Wallet::accountFees()->processDue($this->monthsFromNow(2))['charged']);
        $this->assertSame(0, AccountFeeCharge::count());
    }

    /* ----------------------------------------------------------- exemptions */

    public function test_an_account_can_be_exempt_from_the_start(): void
    {
        $this->fee(['on_insufficient_funds' => 'allow_negative']);

        $account = $this->user()->openAccount('wallet', ['waive_fees' => true]);

        $this->assertMoney(0, $this->bal($account));
        $this->assertSame(AccountFeeChargeStatus::Waived, AccountFeeCharge::sole()->status);
    }

    public function test_an_account_can_be_exempted_later_and_charged_again_afterwards(): void
    {
        [, $account] = $this->funded('Ann', 100);
        $this->monthly();

        Wallet::accountFees()->exempt($account);
        $this->assertSame(['charged' => 0, 'skipped' => 0, 'waived' => 1, 'pending' => 0], Wallet::accountFees()->processDue($this->monthsFromNow(1)));
        $this->assertMoney(100, $this->bal($account));

        Wallet::accountFees()->exempt($account, false);
        $this->assertSame(1, Wallet::accountFees()->processDue($this->monthsFromNow(2))['charged']);
    }

    public function test_a_charge_pending_when_the_account_becomes_exempt_is_waived(): void
    {
        [, $account] = $this->funded('Ann', 1);
        $this->monthly(['on_insufficient_funds' => 'retry']);
        Wallet::accountFees()->processDue($this->monthsFromNow(1));   // pending, cannot pay

        Wallet::accountFees()->exempt($account);
        $this->assertSame(1, Wallet::accountFees()->processDue($this->monthsFromNow(1))['waived']);
    }

    /* ---------------------------------------------------------------- ledger */

    public function test_a_fee_is_an_ordinary_payment_in_the_history(): void
    {
        [, $account] = $this->funded('Ann', 100);
        $this->monthly(['name' => 'Monthly maintenance']);
        Wallet::accountFees()->processDue($this->monthsFromNow(1));

        $item = Wallet::history()->history($account)->first();

        $this->assertSame('debit', $item->direction);
        $this->assertMoney(-2, $item->signed_amount);
        $this->assertSame('Monthly maintenance', $item->transaction->description);
    }

    public function test_the_ledger_still_reconciles_after_fees(): void
    {
        [, $account] = $this->funded('Ann', 100);
        $this->monthly(['on_insufficient_funds' => 'allow_negative']);
        Wallet::accountFees()->processDue($this->monthsFromNow(4));

        $cached = $this->bal($account);
        Wallet::accounts()->reconcile($account);

        $this->assertMoney($cached, $this->bal($account));
        $this->assertMoney(92, $cached);
    }

    public function test_a_fee_can_be_charged_back(): void
    {
        [, $account] = $this->funded('Ann', 100);
        $this->monthly();
        Wallet::accountFees()->processDue($this->monthsFromNow(1));

        Wallet::payments()->chargeback(AccountFeeCharge::sole()->transaction_id);

        $this->assertMoney(100, $this->bal($account));
    }

    public function test_events_are_dispatched(): void
    {
        Event::fake([AccountFeeCharged::class, AccountFeeSkipped::class]);
        [, $account] = $this->funded('Ann', 100);
        $this->funded('Bob', 1);
        $this->monthly(['amount' => 50]);

        Wallet::accountFees()->processDue($this->monthsFromNow(1));

        Event::assertDispatched(AccountFeeCharged::class, fn ($e) => $e->charge->account_id === $account->id);
        Event::assertDispatched(AccountFeeSkipped::class, 1);
    }

    public function test_the_console_command_reports_account_fees(): void
    {
        $this->funded('Ann', 100);
        $this->monthly();

        $this->artisan('wallet:process-scheduled', ['--date' => $this->monthsFromNow(1)])
            ->expectsOutputToContain('Account fees: 1 charged, 0 skipped, 0 waived, 0 pending.')
            ->assertSuccessful();
    }

    /* ------------------------------------------------------------ validation */

    public function test_a_fee_needs_a_sensible_definition(): void
    {
        $cases = [
            'percentage opening' => [InvalidParameterException::class, ['charge_mode' => 'percentage']],
            'zero amount' => [InvalidParameterException::class, ['amount' => 0]],
            'over 100 percent' => [InvalidParameterException::class, ['trigger' => 'recurring', 'every_amount' => 1, 'every_unit' => 'day', 'charge_mode' => 'percentage', 'amount' => 101]],
            'bad trigger' => [InvalidParameterException::class, ['trigger' => 'weekly']],
            'bad policy' => [InvalidParameterException::class, ['on_insufficient_funds' => 'shrug']],
            'recurring without a period' => [InvalidParameterException::class, ['trigger' => 'recurring']],
            'bad unit' => [InvalidParameterException::class, ['trigger' => 'recurring', 'every_amount' => 1, 'every_unit' => 'fortnight']],
            'min above max' => [InvalidParameterException::class, ['trigger' => 'recurring', 'every_amount' => 1, 'every_unit' => 'day', 'min_amount' => 9, 'max_amount' => 1]],
            'system account type' => [InvalidParameterException::class, ['account_type' => 'treasury']],
            'generated type' => [InvalidTransferTypeException::class, ['transfer_type' => 'wallet.fee']],
            'wrong source type' => [InvalidTransferTypeException::class, ['transfer_type' => 'treasury.deposit']],
        ];

        foreach ($cases as $label => [$exception, $overrides]) {
            try {
                $this->fee($overrides + ['internal_name' => 'case_'.md5($label)]);
                $this->fail("{$label}: accepted");
            } catch (\Throwable $e) {
                $this->assertInstanceOf($exception, $e, $label.': '.$e->getMessage());
            }
        }

        $this->assertSame(0, AccountFee::count());
    }

    public function test_a_fee_cannot_use_a_type_that_needs_authorization(): void
    {
        Wallet::transferTypes()->resolve('wallet.account_fee')->update(['requires_authorization' => true]);

        $this->expectException(InvalidTransferTypeException::class);
        $this->fee();
    }

    public function test_required_fields_are_enforced(): void
    {
        $this->expectException(MissingParameterException::class);
        Wallet::accountFees()->create(['name' => 'x']);
    }
}
