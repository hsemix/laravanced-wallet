<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Models;

use Hsemix\AdvancedWallet\Enums\ScheduleUnit;
use Hsemix\AdvancedWallet\Enums\TransactionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $reference
 * @property int $from_account_id
 * @property int $to_account_id
 * @property int $transfer_type_id
 * @property string $amount
 * @property string $reserved_amount
 * @property string|null $description
 * @property TransactionStatus $status
 * @property string|null $by_type
 * @property int|null $by_id
 * @property int|null $chargeback_of_id
 * @property bool $scheduled
 * @property bool $first_installment_immediate
 * @property int|null $installments_count
 * @property int|null $frequency_rate
 * @property ScheduleUnit|null $frequency_unit
 * @property Carbon|null $process_date
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $decided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string|null $idempotency_key
 * @property string|null $decided_by_type
 * @property int|null $decided_by_id
 * @property string|null $decision_reason
 */
class Transaction extends WalletModel
{
    protected string $baseTable = 'transactions';

    protected $casts = [
        'status' => TransactionStatus::class,
        'scheduled' => 'boolean',
        'first_installment_immediate' => 'boolean',
        'process_date' => 'date',
        'decided_at' => 'datetime',
        'meta' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $transaction) {
            $transaction->reference ??= (string) Str::uuid();
        });
    }

    /** @return BelongsTo<Account, $this> */
    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'from_account_id');
    }

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

    /** @return MorphTo<Model, $this> */
    public function by(): MorphTo
    {
        return $this->morphTo();
    }

    /** Who authorized, denied or cancelled this transaction (null if it needed no decision). */
    /** @return MorphTo<Model, $this> */
    public function decidedBy(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasMany<Transfer, $this> */
    public function transfers(): HasMany
    {
        return $this->hasMany(Transfer::class);
    }

    /** @return HasMany<Installment, $this> */
    public function installments(): HasMany
    {
        return $this->hasMany(Installment::class)->orderBy('number');
    }

    /** @return BelongsTo<Transaction, $this> */
    public function chargebackOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'chargeback_of_id');
    }

    /** @return HasMany<Transaction, $this> */
    public function chargebacks(): HasMany
    {
        return $this->hasMany(self::class, 'chargeback_of_id');
    }

    public function isPending(): bool
    {
        return $this->status === TransactionStatus::Pending;
    }
}
