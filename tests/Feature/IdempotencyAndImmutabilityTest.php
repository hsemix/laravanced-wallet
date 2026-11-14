<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Tests\Feature;

use Hsemix\AdvancedWallet\Exceptions\IdempotencyConflictException;
use Hsemix\AdvancedWallet\Exceptions\ImmutableRecordException;
use Hsemix\AdvancedWallet\Facades\Wallet;
use Hsemix\AdvancedWallet\Models\Transaction;
use Hsemix\AdvancedWallet\Models\Transfer;
use Hsemix\AdvancedWallet\Tests\TestCase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

class IdempotencyAndImmutabilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapLedger();
    }

    public function test_repeating_a_request_pays_once_and_returns_the_original(): void
    {
        [$ann, $annAcc] = $this->funded('Ann', 100);
        [$bob, $bobAcc] = $this->funded('Bob');

        $first = $ann->payTo($bob, 'wallet.transfer', 30, null, [], 'req-1');
        $second = $ann->payTo($bob, 'wallet.transfer', 30, null, [], 'req-1');

        $this->assertTrue($first->wasRecentlyCreated);
        $this->assertFalse($second->wasRecentlyCreated, 'replay must not look like a new payment');
        $this->assertSame($first->id, $second->id);
        $this->assertMoney(70, $this->bal($annAcc));
        $this->assertMoney(30, $this->bal($bobAcc));
        $this->assertSame(1, Transaction::where('idempotency_key', 'req-1')->count());
    }

    public function test_a_retry_succeeds_even_when_the_money_is_now_spent(): void
    {
        [$ann, $annAcc] = $this->funded('Ann', 50);
        [$bob] = $this->funded('Bob');

        $first = $ann->payTo($bob, 'wallet.transfer', 50, null, [], 'all-in');
        $this->assertMoney(0, $this->bal($annAcc));

        // Without idempotency this would throw InsufficientBalanceException.
        $this->assertSame($first->id, $ann->payTo($bob, 'wallet.transfer', 50, null, [], 'all-in')->id);
    }

    public function test_reusing_a_key_for_a_different_payment_is_a_conflict(): void
    {
        [$ann] = $this->funded('Ann', 100);
        [$bob] = $this->funded('Bob');
        [$cat] = $this->funded('Cat');

        $ann->payTo($bob, 'wallet.transfer', 10, null, [], 'k');

        foreach ([[$bob, 11], [$cat, 10]] as [$to, $amount]) {
            try {
                $ann->payTo($to, 'wallet.transfer', $amount, null, [], 'k');
                $this->fail('expected conflict');
            } catch (IdempotencyConflictException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_keys_are_scoped_to_the_payer(): void
    {
        [$ann] = $this->funded('Ann', 100);
        [$bob, $bobAcc] = $this->funded('Bob', 100);
        [$cat, $catAcc] = $this->funded('Cat');

        $a = $ann->payTo($cat, 'wallet.transfer', 5, null, [], 'same-key');
        $b = $bob->payTo($cat, 'wallet.transfer', 5, null, [], 'same-key');

        $this->assertNotSame($a->id, $b->id);
        $this->assertMoney(10, $this->bal($catAcc));
    }

    public function test_pending_payment_is_not_reserved_twice_on_replay(): void
    {
        Wallet::transferTypes()->create(['name' => 'Wire', 'internal_name' => 'wire', 'from_account_type' => 'wallet',
            'to_account_type' => 'wallet', 'requires_authorization' => true]);
        [$ann, $annAcc] = $this->funded('Ann', 100);
        [$bob] = $this->funded('Bob');

        $ann->payTo($bob, 'wallet.wire', 60, null, [], 'wire-1');
        $ann->payTo($bob, 'wallet.wire', 60, null, [], 'wire-1');

        $this->assertMoney(60, Wallet::accounts()->balance($annAcc)->reserved);
    }

    public function test_payments_without_a_key_are_never_deduplicated(): void
    {
        [$ann, $annAcc] = $this->funded('Ann', 100);
        [$bob] = $this->funded('Bob');

        $ann->payTo($bob, 'wallet.transfer', 10);
        $ann->payTo($bob, 'wallet.transfer', 10);

        $this->assertMoney(80, $this->bal($annAcc));
    }

    public function test_the_database_itself_rejects_a_duplicate_key_for_one_payer(): void
    {
        [$ann, $annAcc] = $this->funded('Ann', 10);
        [$bob, $bobAcc] = $this->funded('Bob');
        $ann->payTo($bob, 'wallet.transfer', 1, null, [], 'race');

        $row = Transaction::where('idempotency_key', 'race')->first()->only(
            ['from_account_id', 'to_account_id', 'transfer_type_id', 'amount', 'status', 'idempotency_key']
        );

        $this->expectException(QueryException::class);
        Transaction::create($row + ['reference' => (string) Str::uuid()]);
    }

    public function test_transfers_cannot_be_edited_or_deleted(): void
    {
        [$ann] = $this->funded('Ann', 100);
        [$bob] = $this->funded('Bob');
        $ann->payTo($bob, 'wallet.transfer', 10);
        $transfer = Transfer::latest('id')->first();

        try {
            $transfer->update(['amount' => 1]);
            $this->fail('update allowed');
        } catch (ImmutableRecordException) {
        }

        try {
            $transfer->delete();
            $this->fail('delete allowed');
        } catch (ImmutableRecordException) {
        }

        $this->assertMoney(10, $transfer->fresh()->amount);
    }

    public function test_corrections_still_work_through_a_chargeback(): void
    {
        [$ann, $annAcc] = $this->funded('Ann', 100);
        [$bob] = $this->funded('Bob');
        $tx = $ann->payTo($bob, 'wallet.transfer', 10);

        Wallet::payments()->chargeback($tx);

        $this->assertMoney(100, $this->bal($annAcc));
    }
}
