<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Services;

use Carbon\CarbonInterface;
use Hsemix\AdvancedWallet\Enums\PaymentRequestStatus;
use Hsemix\AdvancedWallet\Events\PaymentRequestAccepted;
use Hsemix\AdvancedWallet\Events\PaymentRequestCancelled;
use Hsemix\AdvancedWallet\Events\PaymentRequested;
use Hsemix\AdvancedWallet\Events\PaymentRequestExpired;
use Hsemix\AdvancedWallet\Events\PaymentRequestRejected;
use Hsemix\AdvancedWallet\Exceptions\InvalidAccountException;
use Hsemix\AdvancedWallet\Exceptions\InvalidParameterException;
use Hsemix\AdvancedWallet\Exceptions\InvalidTransactionException;
use Hsemix\AdvancedWallet\Exceptions\InvalidTransferTypeException;
use Hsemix\AdvancedWallet\Exceptions\LimitExceededException;
use Hsemix\AdvancedWallet\Exceptions\MissingParameterException;
use Hsemix\AdvancedWallet\Exceptions\UnauthorizedRequestActionException;
use Hsemix\AdvancedWallet\Models\Account;
use Hsemix\AdvancedWallet\Models\MoneyRequest;
use Hsemix\AdvancedWallet\Support\Money;
use Hsemix\AdvancedWallet\Support\PaymentRequest as PaymentInput;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Payment requests: the payee asks the payer for money; the payer accepts (a
 * normal payment is then performed, fees/limits/authorization and all),
 * rejects, or lets it expire. The requester can withdraw it.
 *
 * The package defines no roles. Who may accept/reject/cancel is decided by an
 * authorizer callback (see authorizeUsing); the default allows the payer's
 * owner to accept/reject and the payee's owner (or whoever created the
 * request) to cancel. Every decision records the actor and whether they were
 * the account owner, so "an admin acted on their behalf" stays visible.
 */
class PaymentRequestService
{
    /** @var (callable(?Model, MoneyRequest, string): bool)|null */
    private $authorizer = null;

    public function __construct(
        private readonly AccountService $accounts,
        private readonly TransferTypeService $transferTypes,
        private readonly PaymentService $payments,
        private readonly BalancePostingService $posting,
    ) {}

    /**
     * Replace the default permission check.
     *
     * @param  (callable(?Model $actor, MoneyRequest $request, string $action): bool)|null  $callback
     *                                                                                                 $action is 'accept', 'reject', 'cancel', or 'reserve' (creating a request that holds the payer's money). Pass null to restore the default.
     */
    public function authorizeUsing(?callable $callback): void
    {
        $this->authorizer = $callback;
    }

    /**
     * @param  array{from:mixed,to:mixed,type:mixed,amount:mixed,description?:string,by?:Model,expires_at?:mixed,meta?:array}  $input
     *                                                                                                                                 from = the PAYER, to = the PAYEE (the requester), same shape as a payment.
     */
    public function create(array $input): MoneyRequest
    {
        foreach (['from', 'to', 'type', 'amount'] as $field) {
            if (! isset($input[$field]) || $input[$field] === '') {
                throw new MissingParameterException("Missing parameter ({$field}).");
            }
        }

        $type = $this->transferTypes->resolve($input['type']);

        if (! $type->enabled || $type->isGenerated()) {
            throw new InvalidTransferTypeException("Transfer type {$type->internal_name} cannot be used for payments.");
        }

        if (! $type->allows_payment_requests) {
            throw new InvalidTransferTypeException("Transfer type {$type->internal_name} does not allow payment requests.");
        }

        $from = $this->accounts->resolveHolder($input['from'], $type->fromAccountType);
        $to = $this->accounts->resolveHolder($input['to'], $type->toAccountType);

        if ($from->is($to)) {
            throw new InvalidAccountException('Cannot request a payment from the same account.');
        }

        $amount = Money::normalize($input['amount']);

        if (! Money::isPositive($amount)) {
            throw new InvalidParameterException('The amount must be greater than zero.');
        }

        if ($type->requires_description && trim((string) ($input['description'] ?? '')) === '') {
            throw new MissingParameterException('Missing parameter (description).');
        }

        $expiresAt = isset($input['expires_at']) ? Carbon::parse($input['expires_at']) : null;
        if ($expiresAt !== null && $expiresAt->isPast()) {
            throw new InvalidParameterException('expires_at must be in the future.');
        }

        $by = $input['by'] ?? null;

        return DB::transaction(function () use ($from, $to, $type, $amount, $input, $expiresAt, $by) {
            // Lock the payer's balance row first: serialises the open-request cap and the hold below.
            $this->posting->lockedBalance($from->id);

            $cap = config('wallet.max_open_requests_per_payer');
            if ($cap !== null && MoneyRequest::where('from_account_id', $from->id)->where('status', PaymentRequestStatus::Requested->value)->count() >= (int) $cap) {
                throw new LimitExceededException("This account already has {$cap} open payment requests.");
            }

            $request = MoneyRequest::create([
                'from_account_id' => $from->id,
                'to_account_id' => $to->id,
                'transfer_type_id' => $type->id,
                'amount' => $amount,
                'description' => isset($input['description']) ? mb_substr((string) $input['description'], 0, 500) : null,
                'status' => PaymentRequestStatus::Requested,
                'expires_at' => $expiresAt,
                'requested_by_type' => $by?->getMorphClass(),
                'requested_by_id' => $by?->getKey(),
                'meta' => ($input['meta'] ?? []) ?: null,
            ]);

            if ($type->reserve_on_payment_request) {
                $this->reserveFor($request, $by);
            }

            PaymentRequested::dispatch($request);

            return $request;
        });
    }

    /**
     * Accept: perform the payment. If it cannot be performed (insufficient
     * funds, limit exceeded, ...) the exception propagates and the request
     * stays open so the payer can try again. Idempotent by construction: the
     * request locks, and the payment carries the request reference as its
     * idempotency key.
     */
    public function accept(MoneyRequest|int $request, ?Model $by = null): MoneyRequest
    {
        return DB::transaction(function () use ($request, $by) {
            $request = $this->lockOpen($request);

            if ($request->isExpired()) {
                throw new InvalidTransactionException('This payment request has expired.');
            }

            $this->assertMayAct($by, $request, 'accept');

            // Free the hold; the payment re-reserves (if it needs authorization) or spends it. A failure rolls this back.
            $this->releaseHold($request);

            $transaction = $this->payments->perform([
                'from' => $request->from_account_id,
                'to' => $request->to_account_id,
                'type' => $request->transfer_type_id,
                'amount' => (string) $request->amount,
                'description' => $request->description,
                'by' => $by,
                'meta' => array_merge($request->meta ?? [], ['payment_request' => $request->reference]),
                'idempotency_key' => 'request:'.$request->reference,
            ]);

            $this->decide($request, PaymentRequestStatus::Accepted, $by, null, ['transaction_id' => $transaction->id]);

            PaymentRequestAccepted::dispatch($request);

            return $request->load('transaction');
        });
    }

    public function reject(MoneyRequest|int $request, ?Model $by = null, ?string $reason = null): MoneyRequest
    {
        return DB::transaction(function () use ($request, $by, $reason) {
            $request = $this->lockOpen($request);
            $this->assertMayAct($by, $request, 'reject');
            $this->releaseHold($request);
            $this->decide($request, PaymentRequestStatus::Rejected, $by, $reason);

            PaymentRequestRejected::dispatch($request);

            return $request;
        });
    }

    /** The requester withdraws the request. */
    public function cancel(MoneyRequest|int $request, ?Model $by = null): MoneyRequest
    {
        return DB::transaction(function () use ($request, $by) {
            $request = $this->lockOpen($request);
            $this->assertMayAct($by, $request, 'cancel');
            $this->releaseHold($request);
            $this->decide($request, PaymentRequestStatus::Cancelled, $by);

            PaymentRequestCancelled::dispatch($request);

            return $request;
        });
    }

    /**
     * Mark every open request past its expires_at as expired.
     * Run by wallet:process-scheduled.
     *
     * @return int number of requests expired
     */
    public function expireDue(null|string|CarbonInterface $now = null): int
    {
        $now = $now === null ? now() : Carbon::parse($now);
        $count = 0;

        MoneyRequest::query()
            ->where('status', PaymentRequestStatus::Requested->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->pluck('id')
            ->each(function ($id) use (&$count, $now) {
                DB::transaction(function () use ($id, $now, &$count) {
                    $request = MoneyRequest::lockForUpdate()->find($id);

                    if (! $request || ! $request->isOpen() || $request->expires_at === null || $request->expires_at->gt($now)) {
                        return;
                    }

                    $this->releaseHold($request);
                    $this->decide($request, PaymentRequestStatus::Expired, null);
                    PaymentRequestExpired::dispatch($request);
                    $count++;
                });
            });

        return $count;
    }

    /* ------------------------------------------------------------- internals */

    private function lockOpen(MoneyRequest|int $request): MoneyRequest
    {
        $fresh = MoneyRequest::lockForUpdate()->find($request instanceof MoneyRequest ? $request->id : $request);

        if (! $fresh) {
            throw new InvalidTransactionException('Payment request does not exist.');
        }

        if (! $fresh->isOpen()) {
            throw new InvalidTransactionException("This payment request is already {$fresh->status->value}.");
        }

        return $fresh;
    }

    private function assertMayAct(?Model $actor, MoneyRequest $request, string $action): void
    {
        $allowed = $this->authorizer !== null
            ? (bool) ($this->authorizer)($actor, $request, $action)
            : $this->defaultAuthorizer($actor, $request, $action);

        if (! $allowed) {
            throw new UnauthorizedRequestActionException("This actor may not {$action} this payment request.");
        }
    }

    /**
     * Hold the payer's money (fees included) while the request is open. The payer did not ask for this,
     * so it needs the authorizer's explicit 'reserve' approval, which the default authorizer never gives.
     */
    private function reserveFor(MoneyRequest $request, ?Model $by): void
    {
        $this->assertMayAct($by, $request, 'reserve');

        [, $from,, $plan] = $this->payments->prepare(new PaymentInput(
            $request->from_account_id,
            $request->to_account_id,
            $request->transfer_type_id,
            Money::normalize($request->amount),
            $request->description,
        ));

        $hold = $plan->netDebitFor($from);

        if (Money::isPositive($hold)) {
            $this->posting->reserve($from, $hold);
            $request->update(['reserved_amount' => Money::round($hold)]);
        }
    }

    private function releaseHold(MoneyRequest $request): void
    {
        if ($request->reserved_amount !== null && Money::isPositive((string) $request->reserved_amount)) {
            $this->posting->release($request->fromAccount, (string) $request->reserved_amount);
        }

        $request->reserved_amount = null;
    }

    private function defaultAuthorizer(?Model $actor, MoneyRequest $request, string $action): bool
    {
        if ($actor === null || $action === 'reserve') {
            return false;
        }

        if ($action === 'cancel') {
            return $this->owns($actor, $request->toAccount)
                || ($request->requested_by_type === $actor->getMorphClass() && (string) $request->requested_by_id === (string) $actor->getKey());
        }

        return $this->owns($actor, $request->fromAccount);
    }

    private function owns(Model $actor, Account $account): bool
    {
        return $account->owner_type === $actor->getMorphClass() && (string) $account->owner_id === (string) $actor->getKey();
    }

    private function decide(MoneyRequest $request, PaymentRequestStatus $status, ?Model $by, ?string $reason = null, array $extra = []): void
    {
        $account = $status === PaymentRequestStatus::Cancelled ? $request->toAccount : $request->fromAccount;

        $request->update([
            'status' => $status,
            'decided_at' => now(),
            'decided_by_type' => $by?->getMorphClass(),
            'decided_by_id' => $by?->getKey(),
            'decided_by_owner' => $by !== null ? $this->owns($by, $account) : null,
            'decision_reason' => $reason !== null ? mb_substr($reason, 0, 500) : null,
        ] + $extra);
    }
}
