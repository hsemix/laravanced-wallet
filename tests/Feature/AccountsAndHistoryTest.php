<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Tests\Feature;

use Hsemix\AdvancedWallet\Exceptions\DuplicateRecordException;
use Hsemix\AdvancedWallet\Exceptions\InvalidAccountTypeException;
use Hsemix\AdvancedWallet\Exceptions\InvalidTransferTypeException;
use Hsemix\AdvancedWallet\Facades\Wallet;
use Hsemix\AdvancedWallet\Models\AccountBalance;
use Hsemix\AdvancedWallet\Tests\TestCase;
use Illuminate\Support\Carbon;

class AccountsAndHistoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapLedger();
    }

    public function test_account_numbers_are_generated_with_prefix_and_sequence(): void
    {
        [, $a] = $this->funded('Ann');
        [, $b] = $this->funded('Bob');

        $this->assertSame('10000001', $a->number);
        $this->assertSame('10000002', $b->number);
    }

    public function test_duplicates_and_bad_input_are_rejected(): void
    {
        $this->expectException(DuplicateRecordException::class);
        Wallet::accounts()->createAccountType(['name' => 'Wallet', 'internal_name' => 'wallet', 'kind' => 'user']);
    }

    public function test_one_account_per_owner_and_type_and_no_user_accounts_on_system_types(): void
    {
        $ann = $this->user();
        $ann->openAccount('wallet');

        try {
            $ann->openAccount('wallet');
            $this->fail('duplicate account allowed');
        } catch (DuplicateRecordException) {
        }

        $this->expectException(InvalidAccountTypeException::class);
        $ann->openAccount('treasury');
    }

    public function test_system_account_is_created_with_a_system_account_type(): void
    {
        $this->assertNotNull(Wallet::accounts()->systemAccount('treasury'));
        $this->assertNotNull(Wallet::accounts()->systemAccount('fees'));
    }

    public function test_transfer_type_names_resolve_and_ambiguity_is_reported(): void
    {
        Wallet::transferTypes()->create(['name' => 'Deposit', 'internal_name' => 'deposit', 'from_account_type' => 'wallet', 'to_account_type' => 'wallet']);

        $this->assertSame('treasury', Wallet::transferTypes()->resolve('treasury.deposit')->fromAccountType->internal_name);

        $this->expectException(InvalidTransferTypeException::class);
        Wallet::transferTypes()->resolve('deposit');   // exists under treasury and wallet
    }

    public function test_ledger_balance_as_of_a_date_and_history_totals(): void
    {
        Carbon::setTestNow('2026-03-01 10:00:00');
        [$ann, $annAcc] = $this->funded('Ann', 100);
        [$bob] = $this->funded('Bob');

        Carbon::setTestNow('2026-03-10 10:00:00');
        $ann->payTo($bob, 'wallet.transfer', 25);
        Carbon::setTestNow('2026-03-20 10:00:00');
        $ann->payTo($bob, 'wallet.transfer', 5);
        $bob->payTo($ann, 'wallet.transfer', 2);

        $svc = Wallet::accounts();
        $this->assertMoney(100, $svc->ledgerBalance($annAcc, '2026-03-09'));
        $this->assertMoney(75, $svc->ledgerBalance($annAcc, '2026-03-10'));      // date-only = end of day
        $this->assertMoney(72, $svc->ledgerBalance($annAcc));

        $h = Wallet::history();
        $this->assertSame(2, $h->sentCount($annAcc));
        $this->assertSame(2, $h->receivedCount($annAcc));                       // deposit + 2
        $this->assertMoney(30, $h->totalSent($annAcc));
        $this->assertMoney(2, $h->totalReceived($annAcc, '2026-03-11'));
        $this->assertMoney(25, $h->totalSent($annAcc, null, '2026-03-15'));

        $page = $h->history($annAcc, perPage: 2);
        $this->assertSame(4, $page->total());
        $first = $page->items()[0];
        $this->assertSame('credit', $first->direction);                          // newest: Bob's 2
        $this->assertMoney(2, $first->signed_amount);
        $this->assertMoney(-5, $page->items()[1]->signed_amount);
        Carbon::setTestNow();
    }

    public function test_reconcile_repairs_a_drifted_projection(): void
    {
        [, $acc] = $this->funded('Ann', 100);
        AccountBalance::where('account_id', $acc->id)->update(['balance' => 999]);

        $this->artisan('wallet:reconcile')->assertFailed();
        $this->artisan('wallet:reconcile', ['--fix' => true])->assertSuccessful();

        $this->assertMoney(100, $this->bal($acc));
        $this->artisan('wallet:reconcile')->assertSuccessful();
    }

    public function test_process_scheduled_command_runs(): void
    {
        $this->artisan('wallet:process-scheduled')->expectsOutputToContain('Processed 0')->assertSuccessful();
    }
}
