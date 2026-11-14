<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Tests\Feature;

use Hsemix\AdvancedWallet\Enums\TransactionStatus;
use Hsemix\AdvancedWallet\Events\PaymentChargedBack;
use Hsemix\AdvancedWallet\Events\PaymentPending;
use Hsemix\AdvancedWallet\Events\PaymentPerformed;
use Hsemix\AdvancedWallet\Exceptions\InsufficientBalanceException;
use Hsemix\AdvancedWallet\Exceptions\InvalidTransactionException;
use Hsemix\AdvancedWallet\Facades\Wallet;
use Hsemix\AdvancedWallet\Models\Transfer;
use Hsemix\AdvancedWallet\Tests\TestCase;
use Illuminate\Support\Facades\Event;

class AuthorizationAndChargebackTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapLedger();
        Wallet::transferTypes()->create([
            'name' => 'Wire', 'internal_name' => 'wire', 'from_account_type' => 'wallet',
            'to_account_type' => 'wallet', 'requires_authorization' => true,
        ]);
    }

    public function test_pending_payment_reserves_funds_until_authorized(): void
    {
        Event::fake([PaymentPending::class, PaymentPerformed::class]);
        [$ann, $annAcc] = $this->funded('Ann', 100);
        [$bob, $bobAcc] = $this->funded('Bob');

        $tx = $ann->payTo($bob, 'wallet.wire', 70);

        $this->assertSame(TransactionStatus::Pending, $tx->status);
        $this->assertSame(0, Transfer::where('transaction_id', $tx->id)->count());
        $summary = Wallet::accounts()->balance($annAcc);
        $this->assertMoney(100, $summary->balance);
        $this->assertMoney(70, $summary->reserved);
        $this->assertMoney(30, $summary->available);
        Event::assertDispatched(PaymentPending::class);

        // the held money cannot be spent elsewhere
        try {
            $ann->payTo($bob, 'wallet.transfer', 31);
            $this->fail('reserved money was spendable');
        } catch (InsufficientBalanceException) {
        }

        Wallet::payments()->authorize($tx);

        $this->assertMoney(30, $this->bal($annAcc));
        $this->assertMoney(70, $this->bal($bobAcc));
        $this->assertMoney(0, Wallet::accounts()->balance($annAcc)->reserved);
        $this->assertSame(TransactionStatus::Successful, $tx->fresh()->status);
        Event::assertDispatched(PaymentPerformed::class);
    }

    public function test_deny_and_cancel_release_the_hold_and_cannot_repeat(): void
    {
        [$ann, $annAcc] = $this->funded('Ann', 100);
        [$bob] = $this->funded('Bob');

        $denied = $ann->payTo($bob, 'wallet.wire', 60);
        Wallet::payments()->deny($denied, null, 'suspicious');
        $this->assertMoney(100, Wallet::accounts()->balance($annAcc)->available);
        $this->assertSame(TransactionStatus::Denied, $denied->fresh()->status);

        $cancelled = $ann->payTo($bob, 'wallet.wire', 60);
        Wallet::payments()->cancel($cancelled);
        $this->assertMoney(100, Wallet::accounts()->balance($annAcc)->available);

        $this->expectException(InvalidTransactionException::class);
        Wallet::payments()->authorize($denied);
    }

    public function test_chargeback_reverses_payment_and_fees(): void
    {
        Event::fake([PaymentChargedBack::class]);
        Wallet::fees()->create(['name' => 'Fee', 'internal_name' => 'pct', 'transfer_type' => 'wallet.transfer',
            'generated_transfer_type' => 'wallet.fee', 'charge_mode' => 'percentage', 'amount' => 10]);

        [$ann, $annAcc] = $this->funded('Ann', 100);
        [$bob, $bobAcc] = $this->funded('Bob');
        $tx = $ann->payTo($bob, 'wallet.transfer', 50);
        $this->assertMoney(45, $this->bal($annAcc));

        $reversal = Wallet::payments()->chargeback($tx, $bob);

        $this->assertMoney(100, $this->bal($annAcc));
        $this->assertMoney(0, $this->bal($bobAcc));
        $this->assertMoney(0, $this->bal($this->feeAccount()));
        $this->assertSame($tx->id, $reversal->chargeback_of_id);
        $this->assertSame(2, $reversal->transfers->count());
        $this->assertMoney(100, Wallet::accounts()->ledgerBalance($annAcc));
        Event::assertDispatched(PaymentChargedBack::class);
    }

    public function test_cannot_charge_back_twice(): void
    {
        [$ann] = $this->funded('Ann', 100);
        [$bob] = $this->funded('Bob');
        $tx = $ann->payTo($bob, 'wallet.transfer', 10);

        Wallet::payments()->chargeback($tx);

        $this->expectException(InvalidTransactionException::class);
        Wallet::payments()->chargeback($tx);
    }

    public function test_chargeback_needs_receiver_funds(): void
    {
        [$ann, $annAcc] = $this->funded('Ann', 100);
        [$bob, $bobAcc] = $this->funded('Bob');
        [$cat] = $this->funded('Cat');

        $tx = $ann->payTo($bob, 'wallet.transfer', 100);
        $bob->payTo($cat, 'wallet.transfer', 100);          // Bob has spent it

        try {
            Wallet::payments()->chargeback($tx);
            $this->fail('chargeback should fail: Bob cannot cover it');
        } catch (InsufficientBalanceException) {
        }

        // the failed chargeback left no trace
        $this->assertMoney(0, $this->bal($bobAcc));
        $this->assertMoney(0, $this->bal($annAcc));
        $this->assertSame(0, $tx->chargebacks()->count());
    }

    public function test_forced_chargeback_leaves_receiver_negative(): void
    {
        [$ann, $annAcc] = $this->funded('Ann', 100);
        [$bob, $bobAcc] = $this->funded('Bob');
        [$cat] = $this->funded('Cat');
        $tx = $ann->payTo($bob, 'wallet.transfer', 100);
        $bob->payTo($cat, 'wallet.transfer', 100);          // Bob drained

        Wallet::payments()->chargeback($tx, null, null, force: true);

        $this->assertMoney(100, $this->bal($annAcc));
        $this->assertMoney(-100, $this->bal($bobAcc));
        $this->assertNotNull($bobAcc->fresh()->negative_since);
    }
}
