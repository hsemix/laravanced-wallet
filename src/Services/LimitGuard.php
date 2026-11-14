<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Services;

use Carbon\CarbonInterface;
use Hsemix\AdvancedWallet\Enums\TransactionStatus;
use Hsemix\AdvancedWallet\Exceptions\LimitExceededException;
use Hsemix\AdvancedWallet\Exceptions\PeriodLimitExceededException;
use Hsemix\AdvancedWallet\Models\Account;
use Hsemix\AdvancedWallet\Models\Transaction;
use Hsemix\AdvancedWallet\Models\Transfer;
use Hsemix\AdvancedWallet\Models\TransferType;
use Hsemix\AdvancedWallet\Support\Money;

/**
 * Enforces a transfer type's min/max amount and per day / week / month
 * amount and count limits for the paying account.
 *
 * A payment is refused when it would take the period past the limit (the
 * original implementation compared with "==", which let an over-limit
 * payment through whenever the running total skipped past the limit).
 * Scheduled installments are checked too; see ScheduledPaymentService.
 */
class LimitGuard
{
    /**
     * Full check for a payment about to be booked now.
     *
     * @throws PeriodLimitExceededException a day/week/month limit is used up (temporary)
     * @throws LimitExceededException the amount can never satisfy the type's limits
     */
    public function check(TransferType $type, Account $from, string $amount): void
    {
        $this->checkStatic($type, $amount);

        $periods = [
            'day' => now()->startOfDay(),
            'week' => now()->startOfWeek(),
            'month' => now()->startOfMonth(),
        ];

        foreach ($periods as $name => $since) {
            $maxAmount = $type->{"max_amount_per_{$name}"};
            $maxCount = $type->{"max_transfers_per_{$name}"};

            if ($maxAmount === null && $maxCount === null) {
                continue;
            }

            [$count, $sum] = $this->usage($type, $from, $since);

            if ($maxAmount !== null && Money::gt(Money::add($sum, $amount), $maxAmount)) {
                throw new PeriodLimitExceededException("The {$name}ly amount limit would be exceeded.");
            }

            if ($maxCount !== null && $count + 1 > $maxCount) {
                throw new PeriodLimitExceededException("The {$name}ly transfer count limit has been reached.");
            }
        }
    }

    /**
     * Limits that depend only on the amount and the transfer type: min/max per payment, and
     * a single payment larger than a whole day/week/month cap (which could never fit).
     * Used when a schedule is created, so impossible installments are rejected up front.
     */
    public function checkStatic(TransferType $type, string $amount): void
    {
        if ($type->min_amount !== null && Money::lt($amount, $type->min_amount)) {
            throw new LimitExceededException('Amount cannot be less than '.Money::round($type->min_amount).'.');
        }

        if ($type->max_amount !== null && Money::gt($amount, $type->max_amount)) {
            throw new LimitExceededException('Amount cannot be more than '.Money::round($type->max_amount).'.');
        }

        foreach (['day', 'week', 'month'] as $name) {
            $cap = $type->{"max_amount_per_{$name}"};

            if ($cap !== null && Money::gt($amount, $cap)) {
                throw new LimitExceededException("Amount exceeds the {$name}ly amount limit of ".Money::round($cap).'.');
            }
        }
    }

    /** @return array{0:int, 1:string} [count, sum] of payments by $from of this type since $since */
    private function usage(TransferType $type, Account $from, CarbonInterface $since): array
    {
        // Booked payments that were not charged back (root transfers only, no fees).
        $booked = Transfer::where('from_account_id', $from->id)
            ->where('transfer_type_id', $type->id)
            ->whereNull('parent_id')
            ->whereNull('chargeback_of_id')
            ->whereDoesntHave('chargeback')
            ->where('created_at', '>=', $since);

        // Pending payments are not booked yet but already count against the limit.
        $pending = Transaction::where('from_account_id', $from->id)
            ->where('transfer_type_id', $type->id)
            ->where('status', TransactionStatus::Pending)
            ->where('created_at', '>=', $since);

        return [
            (clone $booked)->count() + (clone $pending)->count(),
            Money::add((string) $booked->sum('amount'), (string) $pending->sum('amount')),
        ];
    }
}
