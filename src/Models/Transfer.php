<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Models;

use Hsemix\AdvancedWallet\Exceptions\ImmutableRecordException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One ledger entry: moves $amount from one account to another.
 * Fee transfers are children (parent_id) of the transfer that triggered them.
 *
 * @property int $id
 * @property int $transaction_id
 * @property int|null $parent_id
 * @property int $transfer_type_id
 * @property int $from_account_id
 * @property int $to_account_id
 * @property string $amount
 * @property int|null $transfer_fee_id
 * @property int|null $installment_id
 * @property int|null $chargeback_of_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Transfer extends WalletModel
{
    protected string $baseTable = 'transfers';

    /**
     * The ledger is append-only. This guards Eloquent saves and deletes; bulk
     * query updates and raw SQL bypass model events, so also withhold UPDATE/DELETE
     * on this table from your application's database user if you need a hard guarantee.
     */
    protected static function booted(): void
    {
        static::updating(fn () => throw new ImmutableRecordException);
        static::deleting(fn () => throw new ImmutableRecordException);
    }

    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /** @return BelongsTo<TransferType, $this> */
    public function transferType(): BelongsTo
    {
        return $this->belongsTo(TransferType::class);
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

    /** @return BelongsTo<Transfer, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<Transfer, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return BelongsTo<TransferFee, $this> */
    public function transferFee(): BelongsTo
    {
        return $this->belongsTo(TransferFee::class);
    }

    /** @return BelongsTo<Installment, $this> */
    public function installment(): BelongsTo
    {
        return $this->belongsTo(Installment::class);
    }

    /** @return HasOne<Transfer, $this> */
    public function chargeback(): HasOne
    {
        return $this->hasOne(self::class, 'chargeback_of_id');
    }

    public function isChargedBack(): bool
    {
        return $this->chargeback()->exists();
    }
}
