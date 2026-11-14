<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Models;

use Hsemix\AdvancedWallet\Enums\PaymentRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A request for money: the payee (to_account) asks the payer (from_account) to pay.
 * Nothing moves until the request is accepted, at which point a normal payment is performed.
 *
 * @property int $id
 * @property string $reference
 * @property int $from_account_id
 * @property int $to_account_id
 * @property int $transfer_type_id
 * @property string $amount
 * @property string|null $description
 * @property PaymentRequestStatus $status
 * @property Carbon|null $expires_at
 * @property string|null $requested_by_type
 * @property int|null $requested_by_id
 * @property Carbon|null $decided_at
 * @property string|null $decided_by_type
 * @property int|null $decided_by_id
 * @property bool|null $decided_by_owner
 * @property string|null $decision_reason
 * @property int|null $transaction_id
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string|null $reserved_amount
 */
class MoneyRequest extends WalletModel
{
    protected string $baseTable = 'payment_requests';

    protected $casts = [
        'status' => PaymentRequestStatus::class,
        'expires_at' => 'datetime',
        'decided_at' => 'datetime',
        'decided_by_owner' => 'boolean',
        'meta' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $request) {
            $request->reference ??= (string) Str::uuid();
        });
    }

    /** The payer. */
    /** @return BelongsTo<Account, $this> */
    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'from_account_id');
    }

    /** The payee / requester. */
    /** @return BelongsTo<Account, $this> */
    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'to_account_id');
    }

    /** @return BelongsTo<TransferType, $this> */
    public function transferType(): BelongsTo
    {
        return $this->belongsTo(TransferType::class);
    }

    /** The payment created when the request was accepted. */
    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /** @return MorphTo<Model, $this> */
    public function requestedBy(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphTo<Model, $this> */
    public function decidedBy(): MorphTo
    {
        return $this->morphTo();
    }

    public function isOpen(): bool
    {
        return $this->status === PaymentRequestStatus::Requested;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
