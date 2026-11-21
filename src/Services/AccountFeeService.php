<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Services;

use Carbon\CarbonInterface;
use Hsemix\AdvancedWallet\Enums\AccountFeeChargeStatus;
use Hsemix\AdvancedWallet\Enums\AccountFeeTrigger;
use Hsemix\AdvancedWallet\Enums\ChargeMode;
use Hsemix\AdvancedWallet\Enums\InsufficientFundsPolicy;
use Hsemix\AdvancedWallet\Enums\ScheduleUnit;
use Hsemix\AdvancedWallet\Events\AccountFeeCharged;
use Hsemix\AdvancedWallet\Events\AccountFeeSkipped;
use Hsemix\AdvancedWallet\Exceptions\DuplicateRecordException;
use Hsemix\AdvancedWallet\Exceptions\InsufficientBalanceException;
use Hsemix\AdvancedWallet\Exceptions\InvalidParameterException;
use Hsemix\AdvancedWallet\Exceptions\InvalidTransferTypeException;
use Hsemix\AdvancedWallet\Exceptions\LimitExceededException;
use Hsemix\AdvancedWallet\Exceptions\MissingParameterException;
use Hsemix\AdvancedWallet\Models\Account;
use Hsemix\AdvancedWallet\Models\AccountFee;
use Hsemix\AdvancedWallet\Models\AccountFeeCharge;
use Hsemix\AdvancedWallet\Support\Money;
use Hsemix\AdvancedWallet\Support\PaymentRequest;
use Hsemix\AdvancedWallet\Support\WalletDatabase;
use Illuminate\Support\Carbon;

/**
 * Fees charged to an account itself, not to a payment: once when it opens, or every N days/weeks/months/years.
 *
 * Every charge is an ordinary payment (account -> system account) so it shows in history, respects the ledger's
 * guarantees and can be charged back. A row in account_fee_charges per account, fee and period makes each period
 * chargeable exactly once, however many times the job runs.
 */
class AccountFeeService
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly AccountService $accounts,
        private readonly TransferTypeService $transferTypes,
    ) {}

    /**
     * @param  array<string, mixed>  $data  name, internal_name, account_type (the accounts charged), transfer_type (account -> system
     *                                      account, kind payment), trigger (opening|recurring), amount. Optional: charge_mode
     *                                      (fixed|percentage; percentage = of the balance, recurring only), min_amount / max_amount
     *                                      (bounds of a percentage fee), every_amount + every_unit (recurring), starts_at (recurring;
     *                                      default now, so a new fee never bills the past), on_insufficient_funds
     *                                      (skip|retry|allow_negative; default skip), enabled, description.
     */
    public function create(array $data): AccountFee
    {
        foreach (['name', 'internal_name', 'account_type', 'transfer_type', 'trigger', 'amount'] as $field) {
            if (! isset($data[$field]) || $data[$field] === '') {
                throw new MissingParameterException("Missing parameter ({$field}).");
            }
        }

        $trigger = $data['trigger'] instanceof AccountFeeTrigger ? $data['trigger'] : AccountFeeTrigger::tryFrom((string) $data['trigger']);
        if (! $trigger) {
            throw new InvalidParameterException('trigger must be "opening" or "recurring".');
        }

        $mode = $data['charge_mode'] ?? ChargeMode::Fixed;
        $mode = $mode instanceof ChargeMode ? $mode : ChargeMode::tryFrom((string) $mode);
        if (! $mode) {
            throw new InvalidParameterException('charge_mode must be "fixed" or "percentage".');
        }

        if (! is_numeric($data['amount']) || $data['amount'] <= 0) {
            throw new InvalidParameterException('amount must be a positive number.');
        }
        if ($mode === ChargeMode::Percentage && $data['amount'] > 100) {
            throw new InvalidParameterException('A percentage fee cannot exceed 100.');
        }
        if ($mode === ChargeMode::Percentage && $trigger === AccountFeeTrigger::Opening) {
            throw new InvalidParameterException('An opening fee must be fixed: a new account has no balance to take a percentage of.');
        }

        $min = $data['min_amount'] ?? null;
        $max = $data['max_amount'] ?? null;
        if (($min !== null && ! is_numeric($min)) || ($max !== null && ! is_numeric($max))) {
            throw new InvalidParameterException('min_amount / max_amount must be numeric.');
        }
        if ($min !== null && $max !== null && $min > $max) {
            throw new InvalidParameterException('min_amount cannot be greater than max_amount.');
        }

        $everyAmount = null;
        $everyUnit = null;
        $startsAt = null;
        if ($trigger === AccountFeeTrigger::Recurring) {
            $everyAmount = $data['every_amount'] ?? null;
            if (! is_numeric($everyAmount) || (int) $everyAmount < 1) {
                throw new InvalidParameterException('A recurring fee needs every_amount of at least 1.');
            }
            $everyAmount = (int) $everyAmount;

            $unit = $data['every_unit'] ?? null;
            $everyUnit = $unit instanceof ScheduleUnit ? $unit : ScheduleUnit::tryFrom((string) $unit);
            if (! $everyUnit) {
                throw new InvalidParameterException('every_unit must be day, week, month or year.');
            }

            $startsAt = isset($data['starts_at']) ? Carbon::parse($data['starts_at']) : now();
        }

        $policy = $data['on_insufficient_funds'] ?? InsufficientFundsPolicy::Skip;
        $policy = $policy instanceof InsufficientFundsPolicy ? $policy : InsufficientFundsPolicy::tryFrom((string) $policy);
        if (! $policy) {
            throw new InvalidParameterException('on_insufficient_funds must be skip, retry or allow_negative.');
        }

        $accountType = $this->accounts->resolveType($data['account_type']);
        if ($accountType->isSystem()) {
            throw new InvalidParameterException('Account fees are charged to user accounts, not system accounts.');
        }

        $type = $this->transferTypes->resolve($data['transfer_type']);
        if ($type->isGenerated() || $type->from_account_type_id !== $accountType->id || ! $type->toAccountType->isSystem()) {
            throw new InvalidTransferTypeException("transfer_type must be a payment type from {$accountType->internal_name} to a system account.");
        }
        if ($type->requires_authorization) {
            throw new InvalidTransferTypeException('A fee transfer type cannot require authorization: nobody is there to approve it.');
        }

        if (AccountFee::where('internal_name', $data['internal_name'])->exists()) {
            throw new DuplicateRecordException("Account fee {$data['internal_name']} already exists.");
        }

        return AccountFee::create([
            'name' => $data['name'],
            'internal_name' => $data['internal_name'],
            'description' => $data['description'] ?? null,
            'account_type_id' => $accountType->id,
            'transfer_type_id' => $type->id,
            'trigger' => $trigger,
            'charge_mode' => $mode,
            'amount' => $data['amount'],
            'min_amount' => $min,
            'max_amount' => $max,
            'every_amount' => $everyAmount,
            'every_unit' => $everyUnit,
            'starts_at' => $startsAt,
            'on_insufficient_funds' => $policy,
            'enabled' => (bool) ($data['enabled'] ?? true),
        ]);
    }

    /** Exempt an account from every account fee (or end the exemption). Pending charges are waived when processed. */
    public function exempt(Account $account, bool $exempt = true): Account
    {
        $account->forceFill(['fees_waived' => $exempt])->save();

        return $account;
    }

    /**
     * Charge the opening fees of a newly opened account. Called by AccountService::createAccount();
     * a fee the account cannot cover is handled by the fee's insufficient-funds policy, it never blocks opening.
     */
    public function chargeOpening(Account $account): void
    {
        if ($account->isSystem()) {
            return;
        }

        AccountFee::where('enabled', true)
            ->where('trigger', AccountFeeTrigger::Opening)
            ->where('account_type_id', $account->account_type_id)
            ->each(function (AccountFee $fee) use ($account) {
                $charge = AccountFeeCharge::firstOrCreate(
                    ['account_fee_id' => $fee->id, 'account_id' => $account->id, 'period' => 0],
                    ['due_date' => today(), 'status' => AccountFeeChargeStatus::Pending],
                );

                $this->settle($charge);
            });
    }

    /**
     * Bill every recurring period that has fallen due, then try every pending charge. Safe to run as often as
     * you like: a period is only ever created once and only ever charged once.
     *
     * @return array{charged:int, skipped:int, waived:int, pending:int}
     */
    public function processDue(null|string|CarbonInterface $on = null): array
    {
        $on = $on ? Carbon::parse($on) : Carbon::today();
        $result = ['charged' => 0, 'skipped' => 0, 'waived' => 0, 'pending' => 0];

        $this->openDuePeriods($on);

        $ids = AccountFeeCharge::where('status', AccountFeeChargeStatus::Pending)
            ->whereDate('due_date', '<=', $on->toDateString())
            ->whereHas('accountFee', fn ($q) => $q->where('enabled', true))
            ->orderBy('due_date')->orderBy('id')
            ->pluck('id');

        foreach ($ids as $id) {
            $status = $this->settle(AccountFeeCharge::findOrFail($id));
            $result[$status->value]++;
        }

        return $result;
    }

    /** Create a pending charge for every recurring period due by $on that does not have one yet. */
    private function openDuePeriods(CarbonInterface $on): void
    {
        AccountFee::where('enabled', true)->where('trigger', AccountFeeTrigger::Recurring)->each(function (AccountFee $fee) use ($on) {
            // Exempt and inactive accounts get their periods too (settled as waived / skipped), so that lifting an
            // exemption or reactivating an account never bills the time it was exempt in arrears.
            Account::where('account_type_id', $fee->account_type_id)
                ->chunkById(500, function ($accounts) use ($fee, $on) {
                    $latest = AccountFeeCharge::where('account_fee_id', $fee->id)
                        ->whereIn('account_id', $accounts->modelKeys())
                        ->groupBy('account_id')
                        ->selectRaw('account_id, max(period) as latest')
                        ->pluck('latest', 'account_id');

                    $rows = [];
                    foreach ($accounts as $account) {
                        $period = (int) ($latest[$account->id] ?? 0);

                        while (($due = $fee->dueDate($account, ++$period))->lessThanOrEqualTo($on)) {
                            $rows[] = [
                                'account_fee_id' => $fee->id,
                                'account_id' => $account->id,
                                'period' => $period,
                                'due_date' => $due->toDateString(),
                                'status' => AccountFeeChargeStatus::Pending->value,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ];
                        }
                    }

                    // The unique index makes a concurrent run harmless: the loser's rows are ignored.
                    foreach (array_chunk($rows, 500) as $batch) {
                        AccountFeeCharge::insertOrIgnore($batch);
                    }
                });
        });
    }

    /** Try to collect one pending charge. Returns the status it ends in (Pending = try again next run). */
    private function settle(AccountFeeCharge $charge): AccountFeeChargeStatus
    {
        $settled = null;

        $status = WalletDatabase::transaction(function () use ($charge, &$settled) {
            $row = AccountFeeCharge::whereKey($charge->id)->lockForUpdate()->firstOrFail();

            if ($row->status !== AccountFeeChargeStatus::Pending) {
                return $row->status;   // someone else got there first
            }

            $fee = $row->accountFee;
            $account = Account::with('accountType')->findOrFail($row->account_id);

            if ($account->fees_waived) {
                return $this->close($row, AccountFeeChargeStatus::Waived, 'The account is exempt from fees.');
            }
            if (! $account->active) {
                return $this->close($row, AccountFeeChargeStatus::Skipped, 'The account is inactive.');
            }

            $amount = $fee->calculate($this->accounts->balance($account)->balance);
            if (! Money::isPositive($amount)) {
                return $this->close($row, AccountFeeChargeStatus::Waived, 'Nothing to charge.');
            }

            try {
                $transaction = $this->payments->perform(new PaymentRequest(
                    from: $account,
                    to: 'system',
                    type: $fee->transfer_type_id,
                    amount: $amount,
                    description: $fee->name,
                    meta: ['account_fee_id' => $fee->id, 'account_fee_charge_id' => $row->id, 'period' => $row->period],
                    idempotencyKey: 'account-fee:'.$row->id,
                    allowOverdraft: $fee->on_insufficient_funds === InsufficientFundsPolicy::AllowNegative,
                ));
            } catch (InsufficientBalanceException $e) {
                return $fee->on_insufficient_funds === InsufficientFundsPolicy::Retry
                    ? $this->leavePending($row, $e->getMessage())
                    : $this->close($row, AccountFeeChargeStatus::Skipped, $e->getMessage());
            } catch (LimitExceededException $e) {
                // A limit on the fee's transfer type. It may clear (day/week/month limits), so try again next run.
                return $this->leavePending($row, $e->getMessage());
            }

            $row->forceFill(['amount' => $amount, 'transaction_id' => $transaction->id]);
            $settled = $row;

            return $this->close($row, AccountFeeChargeStatus::Charged);
        });

        if ($settled !== null) {
            AccountFeeCharged::dispatch($settled);
        }
        if ($status === AccountFeeChargeStatus::Skipped) {
            AccountFeeSkipped::dispatch($charge->refresh());
        }

        return $status;
    }

    private function close(AccountFeeCharge $row, AccountFeeChargeStatus $status, ?string $reason = null): AccountFeeChargeStatus
    {
        $row->forceFill(['status' => $status, 'reason' => $reason, 'processed_at' => now()])->save();

        return $status;
    }

    private function leavePending(AccountFeeCharge $row, string $reason): AccountFeeChargeStatus
    {
        $row->forceFill(['reason' => $reason])->save();

        return AccountFeeChargeStatus::Pending;
    }
}
