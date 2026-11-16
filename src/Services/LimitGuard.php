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
    public function __construct(private readonly BalancePostingService $posting) {}

    /**
     * Full check for a payment about to be booked now.
     *
     * @throws PeriodLimitExceededException a day/week/month limit is used up (temporary)
     * @throws LimitExceededException the amount can never satisfy the type's limits
     *
     * A period limit is "read the usage, then decide", which is only safe if nobody else can book a payment
     * for this account in between. So when the type has one, the payer's balance row is locked first: concurrent
     * payments by the same account queue up here, and each sees the ones before it. The lock is held until the
     * surrounding database transaction ends, which is also when the payment becomes visible.
     */
    public function check(TransferType $type, Account $from, string $amount): void
    {
        $this->checkStatic($type, $amount);

        if ($this->hasPeriodLimit($type)) {
            $this->posting->lockedBalance($from->id);
        }

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

    private function hasPeriodLimit(TransferType $type): bool
    {
        foreach (['day', 'week', 'month'] as $name) {
            if ($type->{"max_amount_per_{$name}"} !== null || $type->{"max_transfers_per_{$name}"} !== null) {
                return true;
            }
        }

        return false;
    }

    /** @return array{0:int, 1:string} [count, sum] of payments by $from of this type since $since */
    private function usage(TransferType $type, Account $from, CarbonInterface $since): array
    {
        // Locking reads (FOR UPDATE) on purpose: under MySQL's default REPEATABLE READ a plain read uses the
        // snapshot taken by this transaction's first read, so it would not see a payment committed while we
        // waited for the balance lock. A locking read always sees the latest committed rows.
        //
        // They fetch the rows and add them up here, instead of using count() / sum(): PostgreSQL refuses
        // FOR UPDATE together with an aggregate function.

        // Booked payments that were not charged back (root transfers only, no fees).
        $booked = Transfer::where('from_account_id', $from->id)
            ->where('transfer_type_id', $type->id)
            ->whereNull('parent_id')
            ->whereNull('chargeback_of_id')
            ->whereDoesntHave('chargeback')
            ->where('created_at', '>=', $since)
            ->lockForUpdate()
            ->pluck('amount');

        // Pending payments are not booked yet but already count against the limit.
        $pending = Transaction::where('from_account_id', $from->id)
            ->where('transfer_type_id', $type->id)
            ->where('status', TransactionStatus::Pending)
            ->where('created_at', '>=', $since)
            ->lockForUpdate()
            ->pluck('amount');

        $sum = '0';
        foreach ($booked->concat($pending) as $amount) {
            $sum = Money::add($sum, (string) $amount);
        }

        return [$booked->count() + $pending->count(), $sum];
    }
}
