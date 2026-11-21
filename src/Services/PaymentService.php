<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Services;

use Hsemix\AdvancedWallet\Enums\TransactionStatus;
use Hsemix\AdvancedWallet\Events\PaymentCancelled;
use Hsemix\AdvancedWallet\Events\PaymentChargedBack;
use Hsemix\AdvancedWallet\Events\PaymentDenied;
use Hsemix\AdvancedWallet\Events\PaymentPending;
use Hsemix\AdvancedWallet\Events\PaymentPerformed;
use Hsemix\AdvancedWallet\Exceptions\ChargebackNotAllowedException;
use Hsemix\AdvancedWallet\Exceptions\IdempotencyConflictException;
use Hsemix\AdvancedWallet\Exceptions\InsufficientBalanceException;
use Hsemix\AdvancedWallet\Exceptions\InvalidAccountException;
use Hsemix\AdvancedWallet\Exceptions\InvalidTransactionException;
use Hsemix\AdvancedWallet\Exceptions\InvalidTransferTypeException;
use Hsemix\AdvancedWallet\Exceptions\MissingParameterException;
use Hsemix\AdvancedWallet\Exceptions\UnauthorizedChargebackException;
use Hsemix\AdvancedWallet\Models\Account;
use Hsemix\AdvancedWallet\Models\Transaction;
use Hsemix\AdvancedWallet\Models\Transfer;
use Hsemix\AdvancedWallet\Support\Money;
use Hsemix\AdvancedWallet\Support\PaymentRequest;
use Hsemix\AdvancedWallet\Support\PlannedTransfer;
use Hsemix\AdvancedWallet\Support\WalletDatabase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

class PaymentService
{
    /** @var (callable(?Model, Transaction, bool): bool)|null */
    private $chargebackAuthorizer = null;

    public function __construct(
        private readonly AccountService $accounts,
        private readonly TransferTypeService $transferTypes,
        private readonly TransferPlanner $planner,
        private readonly LimitGuard $limits,
        private readonly BalancePostingService $posting,
    ) {}

    /* -------------------------------------------------------------- preview */

    /**
     * Show what a payment would do (fees, totals, balance after) without booking it.
     *
     * @return array<string,mixed>
     */
    public function preview(array|PaymentRequest $input): array
    {
        $request = PaymentRequest::from($input);
        [$type, $from, $to, $plan] = $this->prepare($request);

        $debit = $plan->netDebitFor($from);
        $balance = $this->accounts->balance($from);

        return [
            'from_account' => $this->describe($from),
            'to_account' => $this->describe($to),
            'transfer_type' => ['id' => $type->id, 'name' => $type->name, 'internal_name' => $type->fullName()],
            'description' => $request->description,
            'main_amount' => Money::round($plan->amount),
            'payment_amount' => Money::round($request->amount),
            'fees' => collect($plan->children)->map(fn (PlannedTransfer $c) => [
                'name' => $c->fee->name,
                'internal_name' => $c->fee->internal_name,
                'amount' => Money::round($c->amount),
                'charge_mode' => $c->fee->charge_mode->value,
                'deduct_amount' => $c->fee->deduct_amount,
                'payer' => $c->from->id === $from->id ? 'source' : ($c->from->id === $to->id ? 'destination' : 'other'),
            ])->all(),
            'total_amount' => Money::round($debit),
            'balance_after' => Money::round(Money::sub($balance->balance, $debit)),
            'pending_authorization' => (bool) $type->requires_authorization,
            'confirmation_message' => $type->confirmation_message,
        ];
    }

    /* -------------------------------------------------------------- perform */

    /**
     * Perform a payment. Returns a Transaction that is either Successful, or
     * Pending when the transfer type requires authorization.
     *
     * With an `idempotency_key`, repeating the same request returns the
     * original transaction instead of paying again (`wasRecentlyCreated` is
     * false on a replay). Reusing a key for a different payment throws
     * IdempotencyConflictException.
     */
    public function perform(array|PaymentRequest $input): Transaction
    {
        $request = PaymentRequest::from($input);

        if ($request->idempotencyKey !== null && ($existing = $this->replay($request))) {
            return $existing;
        }

        try {
            return $this->performOnce($request);
        } catch (QueryException $e) {
            // Two identical requests raced: the unique index let one win. Return its result.
            if ($request->idempotencyKey !== null && ($existing = $this->replay($request))) {
                return $existing;
            }

            throw $e;
        }
    }

    private function performOnce(PaymentRequest $request): Transaction
    {
        return WalletDatabase::transaction(function () use ($request) {
            [$type, $from, $to, $plan] = $this->prepare($request);

            $transaction = Transaction::create([
                'idempotency_key' => $request->idempotencyKey,
                'from_account_id' => $from->id,
                'to_account_id' => $to->id,
                'transfer_type_id' => $type->id,
                'amount' => $request->amount,
                'description' => $request->description,
                'status' => $type->requires_authorization ? TransactionStatus::Pending : TransactionStatus::Successful,
                'by_type' => $request->by?->getMorphClass(),
                'by_id' => $request->by?->getKey(),
                'meta' => $request->meta ?: null,
            ]);

            if ($type->requires_authorization) {
                // Hold the money the payer will be debited so it cannot be spent meanwhile.
                $hold = $plan->netDebitFor($from);
                if (Money::isPositive($hold)) {
                    $this->posting->reserve($from, $hold);
                    $transaction->update(['reserved_amount' => Money::round($hold)]);
                }

                PaymentPending::dispatch($transaction);

                return $transaction->load('transferType');
            }

            $this->record($transaction, $plan, null, ! $request->allowOverdraft);

            PaymentPerformed::dispatch($transaction);

            return $transaction->load('transfers');
        });
    }

    /**
     * The transaction already created for this payer + key, if any.
     * Runs before balance/limit checks on purpose: a retry of a payment that
     * already succeeded must not fail just because the money is now spent.
     */
    private function replay(PaymentRequest $request): ?Transaction
    {
        $type = $this->transferTypes->resolve($request->type);
        $from = $this->accounts->resolveHolder($request->from, $type->fromAccountType);

        $existing = Transaction::where('from_account_id', $from->id)
            ->where('idempotency_key', $request->idempotencyKey)
            ->first();

        if (! $existing) {
            return null;
        }

        $to = $this->accounts->resolveHolder($request->to, $type->toAccountType);

        $same = $existing->transfer_type_id === $type->id
            && $existing->to_account_id === $to->id
            && Money::comp($existing->amount, $request->amount) === 0;

        if (! $same) {
            throw new IdempotencyConflictException;
        }

        return $existing->load('transfers');
    }

    /* ------------------------------------------------- authorization flow */

    public function authorize(Transaction|int $transaction, ?Model $by = null): Transaction
    {
        return WalletDatabase::transaction(function () use ($transaction, $by) {
            $transaction = $this->lockPending($transaction);
            $this->releaseHold($transaction);

            $plan = $this->planner->plan(
                $transaction->transferType()->with(['fromAccountType', 'toAccountType'])->first(),
                $transaction->fromAccount()->with('accountType')->first(),
                $transaction->toAccount()->with('accountType')->first(),
                (string) $transaction->amount,
            );

            $this->record($transaction, $plan);

            $this->decide($transaction, TransactionStatus::Successful, $by);

            PaymentPerformed::dispatch($transaction);

            return $transaction->load('transfers');
        });
    }

    public function deny(Transaction|int $transaction, ?Model $by = null, ?string $reason = null): Transaction
    {
        return WalletDatabase::transaction(function () use ($transaction, $by, $reason) {
            $transaction = $this->lockPending($transaction);
            $this->releaseHold($transaction);
            $this->decide($transaction, TransactionStatus::Denied, $by, $reason);

            PaymentDenied::dispatch($transaction, $reason);

            return $transaction;
        });
    }

    /** The payer withdraws a payment that is still awaiting authorization. */
    public function cancel(Transaction|int $transaction, ?Model $by = null): Transaction
    {
        return WalletDatabase::transaction(function () use ($transaction, $by) {
            $transaction = $this->lockPending($transaction);
            $this->releaseHold($transaction);
            $this->decide($transaction, TransactionStatus::Cancelled, $by);

            PaymentCancelled::dispatch($transaction);

            return $transaction;
        });
    }

    /* ----------------------------------------------------------- chargeback */

    /**
     * Decide who may charge payments back (the package defines no roles; the default allows everyone,
     * as before). The callback receives the acting model (or null), the ORIGINAL transaction, and whether
     * the chargeback is forced. Pass null to restore the default.
     *
     * @param  (callable(?Model $actor, Transaction $original, bool $force): bool)|null  $callback
     */
    public function authorizeChargebacksUsing(?callable $callback): void
    {
        $this->chargebackAuthorizer = $callback;
    }

    /**
     * Reverse a payment. Pass a Transaction to reverse everything not yet
     * reversed, or a root Transfer to reverse just that transfer and its fees
     * (e.g. one installment of a scheduled payment).
     *
     * @param  bool  $force  complete the reversal even if the receiver cannot cover it (leaves them negative)
     */
    public function chargeback(Transaction|Transfer|int $subject, ?Model $by = null, ?string $description = null, bool $force = false): Transaction
    {
        return WalletDatabase::transaction(function () use ($subject, $by, $description, $force) {
            if ($subject instanceof Transfer) {
                if ($subject->parent_id !== null) {
                    throw new InvalidTransactionException('A fee transfer cannot be charged back on its own; charge back its parent transfer.');
                }
                $original = Transaction::lockForUpdate()->findOrFail($subject->transaction_id);
                $roots = collect([$subject]);
            } else {
                $original = Transaction::lockForUpdate()->find($subject instanceof Transaction ? $subject->id : $subject)
                    ?? throw new InvalidTransactionException('Transaction does not exist.');
                $roots = $original->transfers()->whereNull('parent_id')->get();
            }

            if ($original->chargeback_of_id !== null) {
                throw new InvalidTransactionException('A chargeback cannot itself be charged back.');
            }

            $roots = $roots->reject(fn (Transfer $t) => $t->isChargedBack())->values();

            if ($roots->isEmpty()) {
                throw new InvalidTransactionException('Nothing to charge back: the transaction has no booked transfers left, or was already charged back.');
            }

            $this->assertChargebackAllowed($original, $roots, $by, $force);

            $reversal = Transaction::create([
                'from_account_id' => $original->to_account_id,
                'to_account_id' => $original->from_account_id,
                'transfer_type_id' => $original->transfer_type_id,
                'amount' => Money::round($roots->reduce(fn ($carry, Transfer $t) => Money::add($carry, $t->amount), '0')),
                'description' => $description ?? 'Chargeback for: '.($original->description ?? $original->reference),
                'status' => TransactionStatus::Successful,
                'by_type' => $by?->getMorphClass(),
                'by_id' => $by?->getKey(),
                'chargeback_of_id' => $original->id,
            ]);

            $created = collect();
            foreach ($roots as $root) {
                $this->reverse($root, $reversal, null, $created);
            }

            $this->posting->post($created, enforce: ! $force);

            PaymentChargedBack::dispatch($original, $reversal);

            return $reversal->load('transfers');
        });
    }

    /** The transfer type's chargeback rules, then the optional who-may-do-it callback. */
    private function assertChargebackAllowed(Transaction $original, Collection $roots, ?Model $by, bool $force): void
    {
        $type = $original->transferType;

        if (! $type->allows_chargebacks) {
            throw new ChargebackNotAllowedException("Transfer type {$type->internal_name} does not allow chargebacks.");
        }

        if ($force && ! $type->allows_forced_chargebacks) {
            throw new ChargebackNotAllowedException("Transfer type {$type->internal_name} does not allow forced chargebacks.");
        }

        foreach ($roots as $root) {
            $deadline = $type->chargebackDeadline($root->created_at);

            if ($deadline !== null && $deadline->isPast()) {
                throw new ChargebackNotAllowedException(
                    "The chargeback window for {$type->internal_name} ({$type->max_chargeback_time_amount} {$type->max_chargeback_time_field->value}(s)) has passed."
                );
            }
        }

        if ($this->chargebackAuthorizer !== null && ! ($this->chargebackAuthorizer)($by, $original, $force)) {
            throw new UnauthorizedChargebackException;
        }
    }

    /* ------------------------------------------------------------- internals */

    /**
     * Persist a plan as transfers under $transaction and post balances.
     *
     * @return Collection<int, Transfer>
     */
    public function record(Transaction $transaction, PlannedTransfer $plan, ?int $installmentId = null, bool $enforce = true): Collection
    {
        $created = collect();
        $this->persist($plan, $transaction, null, $installmentId, $created);
        $this->posting->post($created, $enforce);

        return $created;
    }

    /** Resolve type, accounts, run every validation and build the plan. */
    public function prepare(PaymentRequest $request): array
    {
        $type = $this->transferTypes->resolve($request->type);

        if (! $type->enabled || $type->isGenerated()) {
            throw new InvalidTransferTypeException("Transfer type {$type->internal_name} cannot be used for payments.");
        }

        $from = $this->accounts->resolveHolder($request->from, $type->fromAccountType);
        $to = $this->accounts->resolveHolder($request->to, $type->toAccountType);

        if ($from->is($to)) {
            throw new InvalidAccountException('Cannot pay an account from itself.');
        }

        if ($type->requires_description && trim((string) $request->description) === '') {
            throw new MissingParameterException('Missing parameter (description).');
        }

        $this->limits->check($type, $from, $request->amount);

        $plan = $this->planner->plan($type, $from, $to, $request->amount);

        if (! $request->allowOverdraft) {
            $this->assertCanAfford($from, $plan);
        }

        return [$type, $from, $to, $plan];
    }

    /** Early, friendly check; the authoritative one is the locked posting step. */
    private function assertCanAfford(Account $from, PlannedTransfer $plan): void
    {
        $debit = $plan->netDebitFor($from);

        if (! Money::isPositive($debit) || $from->accountType->isUnlimited()) {
            return;
        }

        $balance = $this->accounts->balance($from);
        $spendable = Money::add($balance->available, $from->credit_limit);

        if (Money::gt($debit, $spendable)) {
            throw new InsufficientBalanceException("Insufficient balance on account {$from->id}.");
        }
    }

    private function persist(PlannedTransfer $plan, Transaction $transaction, ?Transfer $parent, ?int $installmentId, Collection $created): void
    {
        $transfer = Transfer::create([
            'transaction_id' => $transaction->id,
            'parent_id' => $parent?->id,
            'transfer_type_id' => $plan->type->id,
            'from_account_id' => $plan->from->id,
            'to_account_id' => $plan->to->id,
            'amount' => Money::round($plan->amount),
            'transfer_fee_id' => $plan->fee?->id,
            'installment_id' => $installmentId,
        ]);

        $created->push($transfer);

        foreach ($plan->children as $child) {
            $this->persist($child, $transaction, $transfer, $installmentId, $created);
        }
    }

    /** Mirror a transfer (and its fee children) with from/to swapped. */
    private function reverse(Transfer $original, Transaction $reversal, ?Transfer $reversedParent, Collection $created): void
    {
        $mirror = Transfer::create([
            'transaction_id' => $reversal->id,
            'parent_id' => $reversedParent?->id,
            'transfer_type_id' => $original->transfer_type_id,
            'from_account_id' => $original->to_account_id,
            'to_account_id' => $original->from_account_id,
            'amount' => $original->amount,
            'transfer_fee_id' => $original->transfer_fee_id,
            'installment_id' => $original->installment_id,
            'chargeback_of_id' => $original->id,
        ]);

        $created->push($mirror);

        foreach ($original->children as $child) {
            $this->reverse($child, $reversal, $mirror, $created);
        }
    }

    /** Record the outcome of an authorization decision, including who made it. */
    private function decide(Transaction $transaction, TransactionStatus $status, ?Model $by, ?string $reason = null): void
    {
        $transaction->update([
            'status' => $status,
            'decided_at' => now(),
            'decided_by_type' => $by?->getMorphClass(),
            'decided_by_id' => $by?->getKey(),
            'decision_reason' => $reason !== null ? mb_substr($reason, 0, 500) : null,
        ]);
    }

    private function lockPending(Transaction|int $transaction): Transaction
    {
        $fresh = Transaction::lockForUpdate()->find($transaction instanceof Transaction ? $transaction->id : $transaction);

        if (! $fresh || $fresh->status !== TransactionStatus::Pending) {
            throw new InvalidTransactionException('Only a pending transaction can be authorized, denied or cancelled.');
        }

        return $fresh;
    }

    private function releaseHold(Transaction $transaction): void
    {
        if (Money::isPositive($transaction->reserved_amount)) {
            $this->posting->release($transaction->fromAccount, (string) $transaction->reserved_amount);
            $transaction->reserved_amount = '0';
            $transaction->save();
        }
    }

    private function describe(Account $account): array
    {
        return [
            'id' => $account->id,
            'number' => $account->number,
            'type' => $account->accountType->internal_name,
            'kind' => $account->accountType->kind->value,
            'owner_type' => $account->owner_type,
            'owner_id' => $account->owner_id,
        ];
    }
}
