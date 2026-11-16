<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Tests\Feature;

use Hsemix\AdvancedWallet\Exceptions\InsufficientBalanceException;
use Hsemix\AdvancedWallet\Facades\Wallet;
use Hsemix\AdvancedWallet\Models\AccountBalance;
use Hsemix\AdvancedWallet\Tests\TestCase;

/** reconcile() must count every kind of hold, or repairing an account silently releases money that is promised. */
class ReconcileHoldsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapLedger();

        Wallet::transferTypes()->resolve('wallet.transfer')->update(['allows_payment_requests' => true, 'reserve_on_payment_request' => true]);
        Wallet::transferTypes()->create(['name' => 'Approved', 'internal_name' => 'approved', 'from_account_type' => 'wallet', 'to_account_type' => 'wallet', 'requires_authorization' => true]);
        Wallet::authorizeRequestsUsing(fn ($actor, $request, $action) => $action === 'reserve' || ($actor && $action === 'cancel'));
    }

    protected function tearDown(): void
    {
        Wallet::authorizeRequestsUsing(null);
        parent::tearDown();
    }

    private function ask($payer, $payee, $amount)
    {
        return Wallet::requests()->create(['from' => $payer, 'to' => $payee, 'type' => 'wallet.transfer', 'amount' => $amount, 'by' => $payee]);
    }

    public function test_reconcile_keeps_the_hold_of_an_open_payment_request(): void
    {
        [$ann, $annAcc] = $this->funded('Ann', 100);
        [$bob] = $this->funded('Bob');
        $this->ask($ann, $bob, 60);

        $summary = Wallet::accounts()->reconcile($annAcc);

        $this->assertMoney(60, $summary->reserved);
        $this->assertMoney(40, $summary->available);
        $this->assertMoney(60, Wallet::accounts()->balance($annAcc)->reserved);
    }

    public function test_reconcile_adds_request_holds_and_transaction_holds_together(): void
    {
        [$ann, $annAcc] = $this->funded('Ann', 100);
        [$bob] = $this->funded('Bob');
        $this->ask($ann, $bob, 30);                                              // held on the request
        $ann->payTo($bob, 'wallet.approved', 20);                                // held on the pending transaction

        $this->assertMoney(50, Wallet::accounts()->balance($annAcc)->reserved);
        $this->assertMoney(50, Wallet::accounts()->reconcile($annAcc)->reserved);
    }

    public function test_a_closed_request_no_longer_counts(): void
    {
        [$ann, $annAcc] = $this->funded('Ann', 100);
        [$bob] = $this->funded('Bob');
        $request = $this->ask($ann, $bob, 60);

        Wallet::requests()->cancel($request, $bob);

        $this->assertMoney(0, Wallet::accounts()->balance($annAcc)->reserved);
        $this->assertMoney(0, Wallet::accounts()->reconcile($annAcc)->reserved);
    }

    public function test_the_payer_still_cannot_spend_a_held_amount_after_a_repair(): void
    {
        [$ann, $annAcc] = $this->funded('Ann', 100);
        [$bob] = $this->funded('Bob');
        $this->ask($ann, $bob, 80);
        AccountBalance::where('account_id', $annAcc->id)->update(['balance' => 999]);   // drift unrelated to the hold

        $this->artisan('wallet:reconcile', ['--fix' => true])->assertSuccessful();

        $this->assertMoney(100, $this->bal($annAcc));
        $this->assertMoney(80, Wallet::accounts()->balance($annAcc)->reserved);
        $this->expectException(InsufficientBalanceException::class);
        $ann->payTo($bob, 'wallet.transfer', 30);
    }

    public function test_the_command_is_clean_when_holds_match(): void
    {
        [$ann] = $this->funded('Ann', 100);
        [$bob] = $this->funded('Bob');
        $this->ask($ann, $bob, 60);
        $ann->payTo($bob, 'wallet.approved', 10);

        $this->artisan('wallet:reconcile')->expectsOutputToContain('All balances and holds match the ledger.')->assertSuccessful();
    }

    public function test_the_command_detects_and_repairs_drifted_holds(): void
    {
        [$ann, $annAcc] = $this->funded('Ann', 100);
        [$bob] = $this->funded('Bob');
        $this->ask($ann, $bob, 60);
        AccountBalance::where('account_id', $annAcc->id)->update(['reserved' => 5]);   // the balance itself is still right

        $this->artisan('wallet:reconcile')
            ->expectsOutputToContain('cached reserved 5.0000 != open holds 60.0000')
            ->assertFailed();

        $this->artisan('wallet:reconcile', ['--fix' => true])->assertSuccessful();

        $this->assertMoney(60, Wallet::accounts()->balance($annAcc)->reserved);
        $this->artisan('wallet:reconcile')->assertSuccessful();
    }
}
