<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Tests\Feature;

use Hsemix\AdvancedWallet\Enums\TransactionStatus;
use Hsemix\AdvancedWallet\Exceptions\InsufficientBalanceException;
use Hsemix\AdvancedWallet\Exceptions\InvalidAccountException;
use Hsemix\AdvancedWallet\Exceptions\LimitExceededException;
use Hsemix\AdvancedWallet\Exceptions\MissingParameterException;
use Hsemix\AdvancedWallet\Facades\Wallet;
use Hsemix\AdvancedWallet\Models\Transfer;
use Hsemix\AdvancedWallet\Tests\TestCase;

class PaymentsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapLedger();
    }

    public function test_deposit_and_transfer_move_money_and_ledger_matches_projection(): void
    {
        [$ann, $annAcc] = $this->funded('Ann', 100);
        [$bob, $bobAcc] = $this->funded('Bob');

        $tx = $ann->payTo($bob, 'wallet.transfer', 40, 'lunch');

        $this->assertSame(TransactionStatus::Successful, $tx->status);
        $this->assertMoney(60, $this->bal($annAcc));
        $this->assertMoney(40, $this->bal($bobAcc));
        $this->assertMoney(60, Wallet::accounts()->ledgerBalance($annAcc));
        $this->assertMoney(-100, Wallet::accounts()->ledgerBalance(Wallet::accounts()->systemAccount('treasury')));
    }

    public function test_insufficient_balance_is_rejected_and_nothing_is_booked(): void
    {
        [$ann, $annAcc] = $this->funded('Ann', 10);
        [$bob] = $this->funded('Bob');
        $before = Transfer::count();

        try {
            $ann->payTo($bob, 'wallet.transfer', 10.01);
            $this->fail('expected exception');
        } catch (InsufficientBalanceException) {
        }

        $this->assertSame($before, Transfer::count());
        $this->assertMoney(10, $this->bal($annAcc));
    }

    public function test_credit_limit_allows_overdraft_and_tracks_negative_since(): void
    {
        $ann = $this->user();
        $acc = $ann->openAccount('wallet', ['credit_limit' => 50]);
        [$bob, $bobAcc] = $this->funded('Bob');

        $ann->payTo($bob, 'wallet.transfer', 50);
        $this->assertMoney(-50, $this->bal($acc));
        $this->assertNotNull($acc->fresh()->negative_since);

        $this->expectException(InsufficientBalanceException::class);
        $ann->payTo($bob, 'wallet.transfer', 0.01);
    }

    public function test_cannot_pay_yourself_or_a_user_without_an_account(): void
    {
        [$ann] = $this->funded('Ann', 10);

        $this->expectException(InvalidAccountException::class);
        $ann->payTo($ann, 'wallet.transfer', 1);
    }

    public function test_requires_description_when_configured(): void
    {
        Wallet::transferTypes()->create([
            'name' => 'Invoice', 'internal_name' => 'invoice', 'from_account_type' => 'wallet',
            'to_account_type' => 'wallet', 'requires_description' => true,
        ]);
        [$ann] = $this->funded('Ann', 10);
        [$bob] = $this->funded('Bob');

        $this->expectException(MissingParameterException::class);
        $ann->payTo($bob, 'wallet.invoice', 1);
    }

    public function test_percentage_fee_on_top_is_paid_by_source_to_system(): void
    {
        Wallet::fees()->create(['name' => 'Fee', 'internal_name' => 'pct', 'transfer_type' => 'wallet.transfer',
            'generated_transfer_type' => 'wallet.fee', 'charge_mode' => 'percentage', 'amount' => 2]);

        [$ann, $annAcc] = $this->funded('Ann', 100);
        [$bob, $bobAcc] = $this->funded('Bob');

        $ann->payTo($bob, 'wallet.transfer', 50);

        $this->assertMoney(49, $this->bal($annAcc));   // 50 + 1 fee
        $this->assertMoney(50, $this->bal($bobAcc));
        $this->assertMoney(1, $this->bal($this->feeAccount()));
    }

    public function test_fee_range_and_fixed_mode(): void
    {
        Wallet::fees()->create(['name' => 'Big', 'internal_name' => 'big', 'transfer_type' => 'wallet.transfer',
            'generated_transfer_type' => 'wallet.fee', 'charge_mode' => 'fixed', 'amount' => 5, 'min_amount' => 100]);

        [$ann, $annAcc] = $this->funded('Ann', 1000);
        [$bob] = $this->funded('Bob');

        $ann->payTo($bob, 'wallet.transfer', 99);
        $this->assertMoney(0, $this->bal($this->feeAccount()));

        $ann->payTo($bob, 'wallet.transfer', 100);
        $this->assertMoney(5, $this->bal($this->feeAccount()));
        $this->assertMoney(1000 - 99 - 100 - 5, $this->bal($annAcc));
    }

    public function test_deduct_amount_fee_comes_out_of_the_payment(): void
    {
        Wallet::fees()->create(['name' => 'Cut', 'internal_name' => 'cut', 'transfer_type' => 'wallet.transfer',
            'generated_transfer_type' => 'wallet.fee', 'charge_mode' => 'fixed', 'amount' => 3, 'deduct_amount' => true]);

        [$ann, $annAcc] = $this->funded('Ann', 100);
        [$bob, $bobAcc] = $this->funded('Bob');

        $ann->payTo($bob, 'wallet.transfer', 20);

        $this->assertMoney(80, $this->bal($annAcc));   // pays exactly 20
        $this->assertMoney(17, $this->bal($bobAcc));   // receives 20 - 3
        $this->assertMoney(3, $this->bal($this->feeAccount()));
    }

    public function test_fee_can_be_paid_by_the_destination(): void
    {
        Wallet::fees()->create(['name' => 'Rx', 'internal_name' => 'rx', 'transfer_type' => 'wallet.transfer',
            'generated_transfer_type' => 'wallet.fee', 'charge_mode' => 'fixed', 'amount' => 2, 'payer' => 'destination']);

        [$ann, $annAcc] = $this->funded('Ann', 100);
        [$bob, $bobAcc] = $this->funded('Bob');

        $ann->payTo($bob, 'wallet.transfer', 10);

        $this->assertMoney(90, $this->bal($annAcc));
        $this->assertMoney(8, $this->bal($bobAcc));
        $this->assertMoney(2, $this->bal($this->feeAccount()));
    }

    public function test_preview_matches_what_perform_books(): void
    {
        Wallet::fees()->create(['name' => 'Fee', 'internal_name' => 'pct', 'transfer_type' => 'wallet.transfer',
            'generated_transfer_type' => 'wallet.fee', 'charge_mode' => 'percentage', 'amount' => 10]);

        [$ann, $annAcc] = $this->funded('Ann', 100);
        [$bob] = $this->funded('Bob');

        $preview = Wallet::preview(['from' => $ann, 'to' => $bob, 'type' => 'wallet.transfer', 'amount' => 50]);

        $this->assertMoney(55, $preview['total_amount']);
        $this->assertMoney(45, $preview['balance_after']);
        $this->assertCount(1, $preview['fees']);
        $this->assertMoney(100, $this->bal($annAcc), 'preview must not book anything');

        $ann->payTo($bob, 'wallet.transfer', 50);
        $this->assertMoney($preview['balance_after'], $this->bal($annAcc));
    }

    public function test_limits_min_max_and_period_totals(): void
    {
        Wallet::transferTypes()->create([
            'name' => 'Capped', 'internal_name' => 'capped', 'from_account_type' => 'wallet', 'to_account_type' => 'wallet',
            'min_amount' => 5, 'max_amount' => 50, 'max_amount_per_day' => 70, 'max_transfers_per_day' => 3,
        ]);
        [$ann] = $this->funded('Ann', 1000);
        [$bob] = $this->funded('Bob');

        $deny = function (float|int $amount, string $why) use ($ann, $bob) {
            try {
                $ann->payTo($bob, 'wallet.capped', $amount);
                $this->fail("expected limit failure: {$why}");
            } catch (LimitExceededException) {
                $this->addToAssertionCount(1);
            }
        };

        $deny(4, 'below minimum');
        $deny(51, 'above maximum');

        $ann->payTo($bob, 'wallet.capped', 40);
        $deny(31, 'would take daily total to 71 (the old == check would have missed this)');
        $ann->payTo($bob, 'wallet.capped', 30);   // total exactly 70 is allowed
        $deny(5, 'daily amount exhausted');
    }

    public function test_daily_transfer_count_limit(): void
    {
        Wallet::transferTypes()->create([
            'name' => 'Few', 'internal_name' => 'few', 'from_account_type' => 'wallet', 'to_account_type' => 'wallet',
            'max_transfers_per_day' => 2,
        ]);
        [$ann] = $this->funded('Ann', 100);
        [$bob] = $this->funded('Bob');

        $ann->payTo($bob, 'wallet.few', 1);
        $ann->payTo($bob, 'wallet.few', 1);

        $this->expectException(LimitExceededException::class);
        $ann->payTo($bob, 'wallet.few', 1);
    }
}
