<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Models;

use Carbon\CarbonInterface;
use Hsemix\AdvancedWallet\Enums\AccountFeeTrigger;
use Hsemix\AdvancedWallet\Enums\ChargeMode;
use Hsemix\AdvancedWallet\Enums\InsufficientFundsPolicy;
use Hsemix\AdvancedWallet\Enums\ScheduleUnit;
use Hsemix\AdvancedWallet\Support\Money;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A fee charged to the accounts of one account type, when they open or on a schedule.
 *
 * @property int $id
 * @property string $name
 * @property string $internal_name
 * @property string|null $description
 * @property int $account_type_id
 * @property int $transfer_type_id
 * @property AccountFeeTrigger $trigger
 * @property ChargeMode $charge_mode
 * @property string $amount
 * @property string|null $min_amount
 * @property string|null $max_amount
 * @property int|null $every_amount
 * @property ScheduleUnit|null $every_unit
 * @property Carbon|null $starts_at
 * @property InsufficientFundsPolicy $on_insufficient_funds
 * @property bool $enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AccountFee extends WalletModel
{
    protected string $baseTable = 'account_fees';

    protected $casts = [
        'trigger' => AccountFeeTrigger::class,
        'charge_mode' => ChargeMode::class,
        'every_unit' => ScheduleUnit::class,
        'on_insufficient_funds' => InsufficientFundsPolicy::class,
        'starts_at' => 'datetime',
        'enabled' => 'boolean',
    ];

    /** @return BelongsTo<AccountType, $this> */
    public function accountType(): BelongsTo
    {
        return $this->belongsTo(AccountType::class);
    }

    /** @return BelongsTo<TransferType, $this> */
    public function transferType(): BelongsTo
    {
        return $this->belongsTo(TransferType::class);
    }

    /** @return HasMany<AccountFeeCharge, $this> */
    public function charges(): HasMany
    {
        return $this->hasMany(AccountFeeCharge::class);
    }

    public function isRecurring(): bool
    {
        return $this->trigger === AccountFeeTrigger::Recurring;
    }

    /** When the nth recurring period of $account falls due. Counting starts at the later of the account's opening and starts_at. */
    public function dueDate(Account $account, int $period): CarbonInterface
    {
        $anchor = $account->created_at;
        if ($this->starts_at !== null && $this->starts_at->greaterThan($anchor)) {
            $anchor = $this->starts_at;
        }

        $date = $anchor->copy()->startOfDay();
        $steps = $period * (int) $this->every_amount;

        return match ($this->every_unit) {
            ScheduleUnit::Day => $date->addDays($steps),
            ScheduleUnit::Week => $date->addWeeks($steps),
            ScheduleUnit::Month => $date->addMonthsNoOverflow($steps),
            default => $date->addYearsNoOverflow($steps),
        };
    }

    /** What this fee costs an account holding $balance (0 = nothing to charge). */
    public function calculate(string $balance): string
    {
        if ($this->charge_mode === ChargeMode::Fixed) {
            return Money::round($this->amount);
        }

        // A percentage of the money in the account; an empty or negative account owes nothing.
        if (! Money::isPositive($balance)) {
            return '0';
        }

        $fee = Money::round(Money::div(Money::mul($balance, $this->amount), '100'));

        if ($this->min_amount !== null && Money::lt($fee, $this->min_amount)) {
            $fee = Money::round($this->min_amount);
        }
        if ($this->max_amount !== null && Money::gt($fee, $this->max_amount)) {
            $fee = Money::round($this->max_amount);
        }

        return $fee;
    }
}
