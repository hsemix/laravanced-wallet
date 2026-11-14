<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Models;

use Hsemix\AdvancedWallet\Enums\InstallmentStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $transaction_id
 * @property int $number
 * @property string $amount
 * @property string $principal
 * @property string $interest
 * @property string $balance
 * @property Carbon $due_date
 * @property InstallmentStatus $status
 * @property Carbon|null $processed_at
 * @property string|null $failure_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Installment extends WalletModel
{
    protected string $baseTable = 'installments';

    protected $casts = [
        'status' => InstallmentStatus::class,
        'due_date' => 'date',
        'processed_at' => 'datetime',
    ];

    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
