<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Models;

use Hsemix\AdvancedWallet\Enums\ChargeMode;
use Hsemix\AdvancedWallet\Enums\PayerReceiver;
use Hsemix\AdvancedWallet\Support\Money;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $internal_name
 * @property string|null $description
 * @property int $original_transfer_type_id
 * @property int $generated_transfer_type_id
 * @property ChargeMode $charge_mode
 * @property string $amount
 * @property string|null $min_amount
 * @property string|null $max_amount
 * @property PayerReceiver $payer
 * @property PayerReceiver $receiver
 * @property int|null $payer_account_id
 * @property int|null $receiver_account_id
 * @property bool $deduct_amount
 * @property bool $enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TransferFee extends WalletModel
{
    protected string $baseTable = 'transfer_fees';

    protected $casts = [
        'charge_mode' => ChargeMode::class,
        'payer' => PayerReceiver::class,
        'receiver' => PayerReceiver::class,
        'deduct_amount' => 'boolean',
        'enabled' => 'boolean',
    ];

    /** @return BelongsTo<TransferType, $this> */
    public function originalTransferType(): BelongsTo
    {
        return $this->belongsTo(TransferType::class, 'original_transfer_type_id');
    }

    /** @return BelongsTo<TransferType, $this> */
    public function generatedTransferType(): BelongsTo
    {
        return $this->belongsTo(TransferType::class, 'generated_transfer_type_id');
    }

    /** Does this fee apply to a payment of $amount? (min <= amount <= max, either bound optional) */
    public function appliesTo(string $amount): bool
    {
        if (! $this->enabled) {
            return false;
        }

        if ($this->min_amount !== null && Money::lt($amount, $this->min_amount)) {
            return false;
        }

        if ($this->max_amount !== null && Money::gt($amount, $this->max_amount)) {
            return false;
        }

        return true;
    }

    /** Fee amount for a payment of $amount (0 when the fee does not apply). */
    public function calculate(string $amount): string
    {
        if (! $this->appliesTo($amount)) {
            return '0';
        }

        return $this->charge_mode === ChargeMode::Percentage
            ? Money::round(Money::div(Money::mul($amount, $this->amount), '100'))
            : Money::round($this->amount);
    }
}
