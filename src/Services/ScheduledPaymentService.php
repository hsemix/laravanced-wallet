<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Services;

use Carbon\CarbonInterface;
use Hsemix\AdvancedWallet\Enums\InstallmentStatus;
use Hsemix\AdvancedWallet\Enums\ScheduleUnit;
use Hsemix\AdvancedWallet\Enums\TransactionStatus;
use Hsemix\AdvancedWallet\Events\InstallmentFailed;
use Hsemix\AdvancedWallet\Events\InstallmentProcessed;
use Hsemix\AdvancedWallet\Events\PaymentCancelled;
use Hsemix\AdvancedWallet\Exceptions\InsufficientBalanceException;
use Hsemix\AdvancedWallet\Exceptions\InvalidAccountException;
use Hsemix\AdvancedWallet\Exceptions\InvalidParameterException;
use Hsemix\AdvancedWallet\Exceptions\InvalidTransactionException;
use Hsemix\AdvancedWallet\Exceptions\InvalidTransferTypeException;
use Hsemix\AdvancedWallet\Exceptions\LimitExceededException;
use Hsemix\AdvancedWallet\Exceptions\MissingParameterException;
use Hsemix\AdvancedWallet\Exceptions\PeriodLimitExceededException;
use Hsemix\AdvancedWallet\Models\Installment;
use Hsemix\AdvancedWallet\Models\Transaction;
use Hsemix\AdvancedWallet\Models\TransferType;
use Hsemix\AdvancedWallet\Support\Money;
use Hsemix\AdvancedWallet\Support\PaymentRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Scheduled, recurring and interest-bearing payments split into installments.
 */
class ScheduledPaymentService
{
    public function __construct(
        private readonly AccountService $accounts,
        private readonly TransferTypeService $transferTypes,
        private readonly TransferPlanner $planner,
        private readonly PaymentService $payments,
        private readonly BalancePostingService $posting,
        private readonly LimitGuard $limits,
    ) {}

    /**
     * @param  array<string,mixed>  $input  from, to, type, amount, installments (count), and optionally
     *                                      immediate (bool, default true), first_date, frequency_rate (default 1),
     *                                      frequency_unit (day|week|month|year, default month), interest_rate (percent), description, by.
     *                                      `amount` is the total principal; it is split across the installments.
     */
    public function schedule(array $input): Transaction
    {
        foreach (['from', 'to', 'type', 'amount', 'installments'] as $field) {
            if (! isset($input[$field]) || $input[$field] === '') {
                throw new MissingParameterException("Missing parameter ({$field}).");
            }
        }

        $count = $input['installments'];
        if (! is_numeric($count) || (int) $count < 1 || (int) $count != $count) {
            throw new InvalidParameterException('installments must be a whole number of at least 1.');
        }
        $count = (int) $count;

        $immediate = (bool) ($input['immediate'] ?? true);
        $rate = (int) ($input['frequency_rate'] ?? 1);
        $unit = $input['frequency_unit'] ?? ScheduleUnit::Month;
        $unit = $unit instanceof ScheduleUnit ? $unit : ScheduleUnit::tryFrom((string) $unit);

        if ($rate < 1 || ! $unit) {
            throw new InvalidParameterException('frequency_rate must be >= 1 and frequency_unit one of day|week|month|year.');
        }

        $first = Carbon::today();
        if (! $immediate) {
            if (empty($input['first_date'])) {
                throw new MissingParameterException('Missing parameter (first_date).');
            }
            try {
                $first = Carbon::parse($input['first_date'])->startOfDay();
            } catch (\Throwable) {
                throw new InvalidParameterException('first_date is not a valid date.');
            }
            if ($first->lt(Carbon::today())) {
                throw new InvalidParameterException('first_date cannot be in the past.');
            }
        }

        $request = PaymentRequest::from([
            'from' => $input['from'],
            'to' => $input['to'],
            'type' => $input['type'],
            'amount' => $input['amount'],
            'description' => $input['description'] ?? null,
            'by' => $input['by'] ?? null,
            'meta' => $input['meta'] ?? [],
        ]);

        return DB::transaction(function () use ($request, $input, $count, $immediate, $rate, $unit, $first) {
            $type = $this->transferTypes->resolve($request->type);

            if (! $type->enabled || $type->isGenerated() || ! $type->allows_scheduled_payments) {
                throw new InvalidTransferTypeException("Transfer type {$type->internal_name} does not allow scheduled payments.");
            }
            if ($type->max_installments !== null && $count > $type->max_installments) {
                throw new InvalidParameterException("Maximum installment count is {$type->max_installments}.");
            }
            if (! $type->allows_recurring_payments && $unit !== ScheduleUnit::Month) {
                throw new InvalidParameterException('This transfer type only allows monthly installments.');
            }
            if ($type->requires_description && trim((string) $request->description) === '') {
                throw new MissingParameterException('Missing parameter (description).');
            }

            $from = $this->accounts->resolveHolder($request->from, $type->fromAccountType);
            $to = $this->accounts->resolveHolder($request->to, $type->toAccountType);

            if ($from->is($to)) {
                throw new InvalidAccountException('Cannot pay an account from itself.');
            }

            $interest = $this->interestRate($type, $input['interest_rate'] ?? null);
            $rows = $this->buildInstallments($request->amount, $count, $first, $rate, $unit, $interest, $type->interest_is_annual);
            foreach ($rows as $row) {
                $this->limits->checkStatic($type, $row['amount']);   // an installment that can never be paid is refused now
            }

            $total = array_reduce($rows, fn ($c, $r) => Money::add($c, $r['amount']), '0');

            $transaction = Transaction::create([
                'from_account_id' => $from->id,
                'to_account_id' => $to->id,
                'transfer_type_id' => $type->id,
                'amount' => $request->amount,
                'description' => $request->description,
                'status' => TransactionStatus::Open,
                'by_type' => $request->by?->getMorphClass(),
                'by_id' => $request->by?->getKey(),
                'scheduled' => true,
                'first_installment_immediate' => $immediate,
                'installments_count' => $count,
                'frequency_rate' => $rate,
                'frequency_unit' => $unit->value,
                'process_date' => $first->toDateString(),
                'meta' => ['total_with_interest' => Money::round($total)] + ($request->meta ?: []),
            ]);

            foreach ($rows as $row) {
                $transaction->installments()->create($row + ['status' => InstallmentStatus::Scheduled]);
            }

            if ($type->reserve_total_amount_on_scheduled_payments) {
                $this->posting->reserve($from, $total);
                $transaction->update(['reserved_amount' => Money::round($total)]);
            }

            if ($immediate) {
                // The caller should hear about a failed first payment immediately.
                $this->settle($transaction->installments()->first());
            }

            return $transaction->fresh(['installments']);
        });
    }

    /**
     * Process every installment due on or before $on (default today), oldest first.
     * Each installment runs in its own transaction; a failure marks only that one Failed.
     *
     * An installment that hits a temporary day/week/month limit is *deferred*: it stays scheduled and is
     * picked up by a later run. One that can never be paid (insufficient funds, per-payment limit) is failed.
     *
     * @return array{processed:int, failed:int, deferred:int}
     */
    public function processDue(null|string|CarbonInterface $on = null): array
    {
        $on = $on ? Carbon::parse($on) : Carbon::today();
        $result = ['processed' => 0, 'failed' => 0, 'deferred' => 0];

        Installment::where('status', InstallmentStatus::Scheduled)
            ->whereDate('due_date', '<=', $on->toDateString())
            ->whereHas('transaction', fn ($q) => $q->where('status', TransactionStatus::Open))
            ->orderBy('due_date')->orderBy('id')
            ->each(function (Installment $installment) use (&$result) {
                try {
                    $this->settle($installment);
                    $result['processed']++;
                } catch (PeriodLimitExceededException) {
                    $result['deferred']++;
                } catch (InsufficientBalanceException|LimitExceededException $e) {
                    $this->fail($installment, $e->getMessage());
                    $result['failed']++;
                }
            });

        return $result;
    }

    /** Stop a schedule: remaining installments are cancelled and held funds released. */
    public function cancel(Transaction|int $transaction, ?Model $by = null): Transaction
    {
        return DB::transaction(function () use ($transaction, $by) {
            $transaction = Transaction::lockForUpdate()->findOrFail($transaction instanceof Transaction ? $transaction->id : $transaction);

            if (! $transaction->scheduled || $transaction->status !== TransactionStatus::Open) {
                throw new InvalidTransactionException('Only an open scheduled payment can be cancelled.');
            }

            $transaction->installments()->where('status', InstallmentStatus::Scheduled)
                ->update(['status' => InstallmentStatus::Cancelled->value]);

            $this->releaseAll($transaction);
            $transaction->update([
                'status' => TransactionStatus::Cancelled,
                'decided_at' => now(),
                'decided_by_type' => $by?->getMorphClass(),
                'decided_by_id' => $by?->getKey(),
            ]);

            PaymentCancelled::dispatch($transaction);

            return $transaction;
        });
    }

    /* ------------------------------------------------------------- internals */

    private function settle(Installment $installment): void
    {
        DB::transaction(function () use ($installment) {
            $installment = Installment::lockForUpdate()->find($installment->id);
            $transaction = Transaction::lockForUpdate()->find($installment->transaction_id);

            if ($installment->status !== InstallmentStatus::Scheduled || $transaction->status !== TransactionStatus::Open) {
                return;
            }

            $type = TransferType::with(['fromAccountType', 'toAccountType'])->find($transaction->transfer_type_id);
            $from = $transaction->fromAccount()->with('accountType')->first();

            // Same limits as an ordinary payment. Thrown before anything changes, so a deferral leaves no trace.
            $this->limits->check($type, $from, (string) $installment->amount);

            $this->releaseShare($transaction, (string) $installment->amount);

            $plan = $this->planner->plan(
                $type,
                $from,
                $transaction->toAccount()->with('accountType')->first(),
                (string) $installment->amount,
            );

            $this->payments->record($transaction, $plan, $installment->id);

            $installment->update(['status' => InstallmentStatus::Processed, 'processed_at' => now(), 'failure_reason' => null]);

            $this->closeIfDone($transaction);

            InstallmentProcessed::dispatch($installment);
        });
    }

    private function fail(Installment $installment, string $reason): void
    {
        DB::transaction(function () use ($installment, $reason) {
            $installment = Installment::lockForUpdate()->find($installment->id);
            $transaction = Transaction::lockForUpdate()->find($installment->transaction_id);

            if ($installment->status !== InstallmentStatus::Scheduled) {
                return;
            }

            $this->releaseShare($transaction, (string) $installment->amount);
            $installment->update(['status' => InstallmentStatus::Failed, 'processed_at' => now(), 'failure_reason' => $reason]);
            $this->closeIfDone($transaction);

            InstallmentFailed::dispatch($installment);
        });
    }

    private function closeIfDone(Transaction $transaction): void
    {
        if (! $transaction->installments()->where('status', InstallmentStatus::Scheduled)->exists()) {
            $this->releaseAll($transaction);
            $transaction->update(['status' => TransactionStatus::Closed, 'decided_at' => now()]);
        }
    }

    private function releaseShare(Transaction $transaction, string $amount): void
    {
        $held = (string) $transaction->reserved_amount;
        if (! Money::isPositive($held)) {
            return;
        }

        $share = Money::lt($amount, $held) ? $amount : $held;
        $this->posting->release($transaction->fromAccount, $share);
        $transaction->reserved_amount = Money::round(Money::sub($held, $share));
        $transaction->save();
    }

    private function releaseAll(Transaction $transaction): void
    {
        if (Money::isPositive($transaction->reserved_amount)) {
            $this->posting->release($transaction->fromAccount, (string) $transaction->reserved_amount);
            $transaction->reserved_amount = '0';
            $transaction->save();
        }
    }

    private function interestRate(TransferType $type, mixed $requested): ?string
    {
        if (! $type->allows_interest) {
            return null;
        }

        $rate = $requested ?? $type->interest_rate;

        if ($rate === null || ! is_numeric($rate) || $rate < 0) {
            throw new InvalidParameterException('interest_rate (percent) is required for this transfer type.');
        }

        return Money::normalize($rate);
    }

    /**
     * Split $principal into $count installments.
     *
     * - no interest: equal parts
     * - flat interest (interest_is_annual = false): rate% of the principal, spread evenly
     * - annual interest: rate is a yearly percentage; standard amortisation (declining balance)
     *
     * The last installment absorbs rounding so the principal repays exactly.
     *
     * @return list<array<string,mixed>>
     */
    private function buildInstallments(string $principal, int $count, Carbon $first, int $rate, ScheduleUnit $unit, ?string $interestPct, bool $annual): array
    {
        $rows = [];
        $balance = $principal;
        $periodRate = '0';
        $payment = null;

        if ($interestPct !== null && Money::isPositive($interestPct) && $annual) {
            // r = APR / periods per year; payment = P r (1+r)^n / ((1+r)^n - 1)
            $periodRate = bcdiv(bcdiv($interestPct, '100', 12), (string) ($unit->periodsPerYear() / $rate), 12);
            $growth = bcpow(bcadd('1', $periodRate, 12), (string) $count, 12);
            $payment = bcdiv(bcmul(bcmul($principal, $periodRate, 12), $growth, 12), bcsub($growth, '1', 12), 12);
        }

        $flatInterest = ($interestPct !== null && ! $annual)
            ? Money::mul($principal, Money::div($interestPct, '100'))
            : '0';

        for ($i = 1; $i <= $count; $i++) {
            $last = $i === $count;

            if ($payment !== null) {
                $interest = Money::round(Money::mul($balance, $periodRate));
                $principalPart = $last ? $balance : Money::sub(Money::round($payment), $interest);
                $principalPart = Money::round($principalPart);
            } else {
                $principalPart = $last ? $balance : Money::round(Money::div($principal, (string) $count));
                $interest = $last
                    ? Money::sub($flatInterest, Money::mul(Money::round(Money::div($flatInterest, (string) $count)), (string) ($count - 1)))
                    : Money::round(Money::div($flatInterest, (string) $count));
                $interest = Money::round($interest);
            }

            $balance = Money::round(Money::sub($balance, $principalPart));

            $rows[] = [
                'number' => $i,
                'amount' => Money::round(Money::add($principalPart, $interest)),
                'principal' => Money::round($principalPart),
                'interest' => Money::round($interest),
                'balance' => $balance,
                'due_date' => $this->dueDate($first, ($i - 1) * $rate, $unit)->toDateString(),
            ];
        }

        return $rows;
    }

    private function dueDate(Carbon $first, int $steps, ScheduleUnit $unit): Carbon
    {
        $date = $first->copy();

        return match ($unit) {
            ScheduleUnit::Day => $date->addDays($steps),
            ScheduleUnit::Week => $date->addWeeks($steps),
            ScheduleUnit::Month => $date->addMonthsNoOverflow($steps),
            ScheduleUnit::Year => $date->addYearsNoOverflow($steps),
        };
    }
}
