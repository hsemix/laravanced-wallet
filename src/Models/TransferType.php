<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Models;

use Carbon\CarbonInterface;
use Hsemix\AdvancedWallet\Enums\TimeUnit;
use Hsemix\AdvancedWallet\Enums\TransferKind;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $internal_name
 * @property string|null $description
 * @property TransferKind $kind
 * @property int $from_account_type_id
 * @property int $to_account_type_id
 * @property bool $enabled
 * @property bool $requires_description
 * @property bool $requires_authorization
 * @property string|null $confirmation_message
 * @property string|null $min_amount
 * @property string|null $max_amount
 * @property string|null $max_amount_per_day
 * @property string|null $max_amount_per_week
 * @property string|null $max_amount_per_month
 * @property int|null $max_transfers_per_day
 * @property int|null $max_transfers_per_week
 * @property int|null $max_transfers_per_month
 * @property bool $allows_scheduled_payments
 * @property bool $allows_recurring_payments
 * @property bool $reserve_total_amount_on_scheduled_payments
 * @property int|null $max_installments
 * @property bool $allows_interest
 * @property string|null $interest_rate
 * @property bool $interest_is_annual
 * @property bool $notify_payment_received
 * @property bool $notify_payment_sender
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property bool $allows_payment_requests
 * @property bool $reserve_on_payment_request
 * @property bool $allows_chargebacks
 * @property bool $allows_forced_chargebacks
 * @property int|null $max_chargeback_time_amount
 * @property TimeUnit|null $max_chargeback_time_field
 * @property AccountType $fromAccountType
 * @property AccountType $toAccountType
 */
class TransferType extends WalletModel
{
    protected string $baseTable = 'transfer_types';

    protected $casts = [
        'kind' => TransferKind::class,
        'enabled' => 'boolean',
        'requires_description' => 'boolean',
        'requires_authorization' => 'boolean',
        'allows_scheduled_payments' => 'boolean',
        'allows_recurring_payments' => 'boolean',
        'allows_payment_requests' => 'boolean',
        'allows_chargebacks' => 'boolean',
        'allows_forced_chargebacks' => 'boolean',
        'max_chargeback_time_field' => TimeUnit::class,
        'reserve_on_payment_request' => 'boolean',
        'reserve_total_amount_on_scheduled_payments' => 'boolean',
        'allows_interest' => 'boolean',
        'interest_is_annual' => 'boolean',
        'notify_payment_received' => 'boolean',
        'notify_payment_sender' => 'boolean',
    ];

    /** @return BelongsTo<AccountType, $this> */
    public function fromAccountType(): BelongsTo
    {
        return $this->belongsTo(AccountType::class, 'from_account_type_id');
    }

    /** @return BelongsTo<AccountType, $this> */
    public function toAccountType(): BelongsTo
    {
        return $this->belongsTo(AccountType::class, 'to_account_type_id');
    }

    /** @return HasMany<TransferFee, $this> */
    public function transferFees(): HasMany
    {
        return $this->hasMany(TransferFee::class, 'original_transfer_type_id');
    }

    /** Last moment a transfer booked at $bookedAt may be charged back, or null when there is no time limit. */
    public function chargebackDeadline(CarbonInterface $bookedAt): ?CarbonInterface
    {
        if ($this->max_chargeback_time_amount === null || $this->max_chargeback_time_field === null) {
            return null;
        }

        return $bookedAt->copy()->add($this->max_chargeback_time_field->value, (int) $this->max_chargeback_time_amount);
    }

    public function isGenerated(): bool
    {
        return $this->kind === TransferKind::Generated;
    }

    /** "account_type.transfer_type" — the string callers use to select this type. */
    public function fullName(): string
    {
        return $this->fromAccountType->internal_name.'.'.$this->internal_name;
    }
}
