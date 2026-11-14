<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Tests\Feature;

use Hsemix\AdvancedWallet\Enums\PaymentRequestStatus;
use Hsemix\AdvancedWallet\Enums\TransactionStatus;
use Hsemix\AdvancedWallet\Events\PaymentRequestAccepted;
use Hsemix\AdvancedWallet\Events\PaymentRequested;
use Hsemix\AdvancedWallet\Events\PaymentRequestExpired;
use Hsemix\AdvancedWallet\Events\PaymentRequestRejected;
use Hsemix\AdvancedWallet\Exceptions\InsufficientBalanceException;
use Hsemix\AdvancedWallet\Exceptions\InvalidParameterException;
use Hsemix\AdvancedWallet\Exceptions\InvalidTransactionException;
use Hsemix\AdvancedWallet\Exceptions\InvalidTransferTypeException;
use Hsemix\AdvancedWallet\Exceptions\LimitExceededException;
use Hsemix\AdvancedWallet\Exceptions\UnauthorizedRequestActionException;
use Hsemix\AdvancedWallet\Facades\Wallet;
use Hsemix\AdvancedWallet\Models\MoneyRequest;
use Hsemix\AdvancedWallet\Models\Transaction;
use Hsemix\AdvancedWallet\Tests\TestCase;
use Illuminate\Support\Facades\Event;

class PaymentRequestsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapLedger();
        $this->setTransferType(['allows_payment_requests' => true]);
    }

    protected function tearDown(): void
    {
        Wallet::authorizeRequestsUsing(null);
        parent::tearDown();
    }

    private function setTransferType(array $attributes): void
    {
        Wallet::transferTypes()->resolve('wallet.transfer')->update($attributes);
    }

    private function ask($payer, $payee, $amount = 40, array $extra = [])
    {
        return Wallet::requests()->create(['from' => $payer, 'to' => $payee, 'type' => 'wallet.transfer', 'amount' => $amount, 'by' => $payee] + $extra);
    }

    public function test_requesting_moves_no_money_and_accepting_pays(): void
    {
        [$ann, $annAcc] = $this->funded('Ann', 100);
        [$bob, $bobAcc] = $this->funded('Bob');

        $request = $this->ask($ann, $bob, 40, ['description' => 'Lunch']);

        $this->assertSame(PaymentRequestStatus::Requested, $request->status);
        $this->assertMoney(100, $this->bal($annAcc));
        $this->assertMoney(0, $this->bal($bobAcc));

        $accepted = Wallet::requests()->accept($request, $ann);

        $this->assertSame(PaymentRequestStatus::Accepted, $accepted->status);
        $this->assertTrue($accepted->decided_by_owner);
        $this->assertSame(TransactionStatus::Successful, $accepted->transaction->status);
        $this->assertSame('Lunch', $accepted->transaction->description);
        $this->assertMoney(60, $this->bal($annAcc));
        $this->assertMoney(40, $this->bal($bobAcc));
    }

    public function test_only_types_that_allow_requests_can_be_requested(): void
    {
        $this->setTransferType(['allows_payment_requests' => false]);
        [$ann] = $this->funded('Ann', 100);
        [$bob] = $this->funded('Bob');

        $this->expectException(InvalidTransferTypeException::class);
        $this->ask($ann, $bob);
    }

    public function test_rejects_non_positive_amounts_and_past_expiry(): void
    {
        [$ann] = $this->funded('Ann', 100);
        [$bob] = $this->funded('Bob');

        try {
            $this->ask($ann, $bob, 0);
            $this->fail('zero amount accepted');
        } catch (InvalidParameterException) {
        }

        $this->expectException(InvalidParameterException::class);
        $this->ask($ann, $bob, 5, ['expires_at' => now()->subDay()]);
    }

    public function test_by_default_only_the_payer_owner_may_accept_or_reject(): void
    {
        [$ann] = $this->funded('Ann', 100);
        [$bob] = $this->funded('Bob');
        $request = $this->ask($ann, $bob);

        foreach ([$bob, null] as $intruder) {
            try {
                Wallet::requests()->accept($request, $intruder);
                $this->fail('unauthorized accept succeeded');
            } catch (UnauthorizedRequestActionException) {
            }
        }

        $this->expectException(UnauthorizedRequestActionException::class);
        Wallet::requests()->reject($request, $bob);
    }

    public function test_an_admin_can_act_on_behalf_via_the_authorizer_and_it_is_recorded(): void
    {
        [$ann, $annAcc] = $this->funded('Ann', 100);
        [$bob, $bobAcc] = $this->funded('Bob');
        $admin = $this->user('Admin');

        Wallet::authorizeRequestsUsing(fn ($actor, $req, $action) => $actor !== null && ($actor->is($admin) || $req->fromAccount->owner->is($actor)));

        $accepted = Wallet::requests()->accept($this->ask($ann, $bob, 30), $admin);

        $this->assertMoney(30, $this->bal($bobAcc));
        $this->assertFalse($accepted->decided_by_owner);
        $this->assertTrue($accepted->decidedBy->is($admin));
        $this->assertTrue(Transaction::find($accepted->transaction_id)->by->is($admin));
    }

    public function test_reject_records_reason_and_cannot_be_accepted_afterwards(): void
    {
        Event::fake([PaymentRequestRejected::class]);
        [$ann, $annAcc] = $this->funded('Ann', 100);
        [$bob] = $this->funded('Bob');
        $request = $this->ask($ann, $bob);

        $rejected = Wallet::requests()->reject($request, $ann, 'Not mine');

        $this->assertSame(PaymentRequestStatus::Rejected, $rejected->status);
        $this->assertSame('Not mine', $rejected->decision_reason);
        Event::assertDispatched(PaymentRequestRejected::class);

        $this->expectException(InvalidTransactionException::class);
        Wallet::requests()->accept($request, $ann);
    }

    public function test_requester_can_cancel_but_not_the_payer_by_default(): void
    {
        [$ann] = $this->funded('Ann', 100);
        [$bob] = $this->funded('Bob');
        $request = $this->ask($ann, $bob);

        try {
            Wallet::requests()->cancel($request, $ann);
            $this->fail('payer cancelled');
        } catch (UnauthorizedRequestActionException) {
        }

        $this->assertSame(PaymentRequestStatus::Cancelled, Wallet::requests()->cancel($request, $bob)->status);
    }

    public function test_failed_payment_leaves_the_request_open_for_a_retry(): void
    {
        [$ann, $annAcc] = $this->funded('Ann', 10);
        [$bob, $bobAcc] = $this->funded('Bob');
        $request = $this->ask($ann, $bob, 40);

        try {
            Wallet::requests()->accept($request, $ann);
            $this->fail('accepted without funds');
        } catch (InsufficientBalanceException) {
        }

        $this->assertSame(PaymentRequestStatus::Requested, $request->fresh()->status);
        $this->assertSame(0, Transaction::where('description', null)->where('to_account_id', $bobAcc->id)->count());

        Wallet::pay(['from' => 'system', 'to' => $ann, 'type' => 'treasury.deposit', 'amount' => 100]);
        $this->assertSame(PaymentRequestStatus::Accepted, Wallet::requests()->accept($request, $ann)->status);
        $this->assertMoney(40, $this->bal($bobAcc));
    }

    public function test_accepting_charges_the_normal_fees(): void
    {
        Wallet::fees()->create(['name' => 'Fee', 'internal_name' => 'pct', 'transfer_type' => 'wallet.transfer',
            'generated_transfer_type' => 'wallet.fee', 'charge_mode' => 'percentage', 'amount' => 10]);
        [$ann, $annAcc] = $this->funded('Ann', 100);
        [$bob, $bobAcc] = $this->funded('Bob');

        Wallet::requests()->accept($this->ask($ann, $bob, 50), $ann);

        $this->assertMoney(45, $this->bal($annAcc));
        $this->assertMoney(50, $this->bal($bobAcc));
        $this->assertMoney(5, $this->bal($this->feeAccount()));
    }

    public function test_a_type_needing_authorization_leaves_the_payment_pending_after_acceptance(): void
    {
        $this->setTransferType(['requires_authorization' => true]);
        [$ann, $annAcc] = $this->funded('Ann', 100);
        [$bob, $bobAcc] = $this->funded('Bob');

        $accepted = Wallet::requests()->accept($this->ask($ann, $bob, 40), $ann);

        $this->assertSame(PaymentRequestStatus::Accepted, $accepted->status);
        $this->assertSame(TransactionStatus::Pending, $accepted->transaction->status);
        $this->assertMoney(0, $this->bal($bobAcc));
    }

    public function test_expired_requests_cannot_be_accepted_and_are_swept(): void
    {
        Event::fake([PaymentRequestExpired::class]);
        [$ann] = $this->funded('Ann', 100);
        [$bob] = $this->funded('Bob');
        $request = $this->ask($ann, $bob, 10, ['expires_at' => now()->addHour()]);

        $this->travel(2)->hours();

        try {
            Wallet::requests()->accept($request, $ann);
            $this->fail('expired request accepted');
        } catch (InvalidTransactionException) {
        }

        $this->assertSame(1, Wallet::requests()->expireDue());
        $this->assertSame(0, Wallet::requests()->expireDue());
        $this->assertSame(PaymentRequestStatus::Expired, $request->fresh()->status);
        Event::assertDispatched(PaymentRequestExpired::class);
    }

    public function test_the_scheduled_command_expires_requests(): void
    {
        [$ann] = $this->funded('Ann', 100);
        [$bob] = $this->funded('Bob');
        $request = $this->ask($ann, $bob, 10, ['expires_at' => now()->addMinute()]);

        $this->travel(5)->minutes();
        $this->artisan('wallet:process-scheduled')->expectsOutputToContain('Expired 1 payment request')->assertSuccessful();

        $this->assertSame(PaymentRequestStatus::Expired, $request->fresh()->status);
    }

    public function test_events_fire_on_request_and_acceptance(): void
    {
        Event::fake([PaymentRequested::class, PaymentRequestAccepted::class]);
        [$ann] = $this->funded('Ann', 100);
        [$bob] = $this->funded('Bob');

        Wallet::requests()->accept($this->ask($ann, $bob), $ann);

        Event::assertDispatched(PaymentRequested::class);
        Event::assertDispatched(PaymentRequestAccepted::class);
    }

    /* ---------------------------------------------------------- reservation */

    private function reserving(): void
    {
        $this->setTransferType(['reserve_on_payment_request' => true]);
        Wallet::authorizeRequestsUsing(fn ($actor, $req, $action) => $action === 'reserve' || ($actor && $req->fromAccount->owner->is($actor)) || ($actor && $action === 'cancel'));
    }

    public function test_reserving_a_type_holds_the_payers_money_including_fees(): void
    {
        Wallet::fees()->create(['name' => 'Fee', 'internal_name' => 'pct', 'transfer_type' => 'wallet.transfer',
            'generated_transfer_type' => 'wallet.fee', 'charge_mode' => 'percentage', 'amount' => 10]);
        $this->reserving();
        [$ann, $annAcc] = $this->funded('Ann', 100);
        [$bob, $bobAcc] = $this->funded('Bob');

        $request = $this->ask($ann, $bob, 50);

        $summary = Wallet::accounts()->balance($annAcc);
        $this->assertMoney(100, $summary->balance);
        $this->assertMoney(55, $summary->reserved);       // 50 + 10% fee
        $this->assertMoney(45, $summary->available);
        $this->assertMoney(55, $request->reserved_amount);
    }

    public function test_held_money_cannot_be_spent_elsewhere(): void
    {
        $this->reserving();
        [$ann] = $this->funded('Ann', 100);
        [$bob] = $this->funded('Bob');
        $this->ask($ann, $bob, 80);

        $this->expectException(InsufficientBalanceException::class);
        $ann->payTo($bob, 'wallet.transfer', 30);
    }

    public function test_cannot_reserve_more_than_the_payer_has(): void
    {
        $this->reserving();
        [$ann, $annAcc] = $this->funded('Ann', 10);
        [$bob] = $this->funded('Bob');

        try {
            $this->ask($ann, $bob, 50);
            $this->fail('reserved beyond balance');
        } catch (InsufficientBalanceException) {
        }

        $this->assertSame(0, MoneyRequest::count());
        $this->assertMoney(0, Wallet::accounts()->balance($annAcc)->reserved);
    }

    public function test_the_default_authorizer_never_approves_a_reservation(): void
    {
        $this->setTransferType(['reserve_on_payment_request' => true]);
        [$ann, $annAcc] = $this->funded('Ann', 100);
        [$bob] = $this->funded('Bob');

        try {
            $this->ask($ann, $bob, 20);
            $this->fail('reserved without approval');
        } catch (UnauthorizedRequestActionException) {
        }

        $this->assertMoney(0, Wallet::accounts()->balance($annAcc)->reserved);
        $this->assertSame(0, MoneyRequest::count());
    }

    public function test_accepting_spends_the_hold_and_leaves_nothing_reserved(): void
    {
        $this->reserving();
        [$ann, $annAcc] = $this->funded('Ann', 100);
        [$bob, $bobAcc] = $this->funded('Bob');
        $request = $this->ask($ann, $bob, 60);

        $accepted = Wallet::requests()->accept($request, $ann);

        $summary = Wallet::accounts()->balance($annAcc);
        $this->assertMoney(40, $summary->balance);
        $this->assertMoney(0, $summary->reserved);
        $this->assertMoney(60, $this->bal($bobAcc));
        $this->assertNull($accepted->reserved_amount);
    }

    public function test_a_failed_accept_keeps_the_hold(): void
    {
        $this->reserving();
        [$ann, $annAcc] = $this->funded('Ann', 100);
        [$bob] = $this->funded('Bob');
        $request = $this->ask($ann, $bob, 60);
        $this->setTransferType(['max_amount' => 10]);     // now the payment itself will be refused

        try {
            Wallet::requests()->accept($request, $ann);
            $this->fail('limit not enforced');
        } catch (LimitExceededException) {
        }

        $this->assertMoney(60, Wallet::accounts()->balance($annAcc)->reserved);
        $this->assertSame(PaymentRequestStatus::Requested, $request->fresh()->status);
    }

    public function test_reject_cancel_and_expiry_release_the_hold(): void
    {
        $this->reserving();
        [$ann, $annAcc] = $this->funded('Ann', 100);
        [$bob] = $this->funded('Bob');

        $a = $this->ask($ann, $bob, 10);
        $b = $this->ask($ann, $bob, 20);
        $c = $this->ask($ann, $bob, 30, ['expires_at' => now()->addHour()]);
        $this->assertMoney(60, Wallet::accounts()->balance($annAcc)->reserved);

        Wallet::requests()->reject($a, $ann);
        $this->assertMoney(50, Wallet::accounts()->balance($annAcc)->reserved);

        Wallet::requests()->cancel($b, $bob);
        $this->assertMoney(30, Wallet::accounts()->balance($annAcc)->reserved);

        $this->travel(2)->hours();
        Wallet::requests()->expireDue();
        $this->assertMoney(0, Wallet::accounts()->balance($annAcc)->reserved);
        $this->assertNull($c->fresh()->reserved_amount);
    }

    public function test_accepting_a_reserved_request_that_needs_authorization_re_holds_the_money(): void
    {
        $this->reserving();
        $this->setTransferType(['requires_authorization' => true]);
        [$ann, $annAcc] = $this->funded('Ann', 100);
        [$bob, $bobAcc] = $this->funded('Bob');

        Wallet::requests()->accept($this->ask($ann, $bob, 40), $ann);

        $summary = Wallet::accounts()->balance($annAcc);
        $this->assertMoney(100, $summary->balance);
        $this->assertMoney(40, $summary->reserved);       // held once, by the pending payment, not twice
    }

    public function test_the_open_request_cap_per_payer(): void
    {
        config(['wallet.max_open_requests_per_payer' => 2]);
        [$ann] = $this->funded('Ann', 100);
        [$bob] = $this->funded('Bob');

        $first = $this->ask($ann, $bob, 1);
        $this->ask($ann, $bob, 1);

        try {
            $this->ask($ann, $bob, 1);
            $this->fail('cap not enforced');
        } catch (LimitExceededException) {
        }

        Wallet::requests()->cancel($first, $bob);
        $this->assertSame(PaymentRequestStatus::Requested, $this->ask($ann, $bob, 1)->status);   // a slot freed up
    }
}
