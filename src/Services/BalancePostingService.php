<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Services;

use Hsemix\AdvancedWallet\Exceptions\InsufficientBalanceException;
use Hsemix\AdvancedWallet\Models\Account;
use Hsemix\AdvancedWallet\Models\AccountBalance;
use Hsemix\AdvancedWallet\Models\Transfer;
use Hsemix\AdvancedWallet\Support\Money;
use Illuminate\Support\Collection;

/**
 * Applies transfers to the cached balance projection.
 *
 * MUST be called inside a database transaction: account_balances rows are
 * read with SELECT ... FOR UPDATE (ordered by id to avoid deadlocks) so two
 * concurrent payments can never spend the same money.
 */
class BalancePostingService
{
    /**
     * @param  Collection<int, Transfer>  $transfers
     * @param  bool  $enforce  false = skip the sufficient-funds check (forced chargebacks)
     */
    public function post(Collection $transfers, bool $enforce = true): void
    {
        $net = []; // account_id => signed delta (credits - debits)

        foreach ($transfers as $transfer) {
            $net[$transfer->from_account_id] = Money::sub($net[$transfer->from_account_id] ?? '0', $transfer->amount);
            $net[$transfer->to_account_id] = Money::add($net[$transfer->to_account_id] ?? '0', $transfer->amount);
        }

        ksort($net);

        foreach ($net as $accountId => $delta) {
            if (Money::isZero($delta)) {
                continue;
            }
            $this->apply((int) $accountId, $delta, $enforce);
        }
    }

    /** Lock + load the balance row (creating it if missing). */
    public function lockedBalance(int $accountId): AccountBalance
    {
        AccountBalance::firstOrCreate(['account_id' => $accountId], ['balance' => 0, 'reserved' => 0]);

        return AccountBalance::where('account_id', $accountId)->lockForUpdate()->firstOrFail();
    }

    /** Earmark funds on an account. Throws if they are not available. */
    public function reserve(Account $account, string $amount): void
    {
        $row = $this->lockedBalance($account->id);
        $account->loadMissing('accountType');

        if (! $account->accountType->isUnlimited()) {
            $available = Money::add(Money::sub($row->balance, $row->reserved), $account->credit_limit);
            if (Money::gt($amount, $available)) {
                throw new InsufficientBalanceException("Insufficient balance on account {$account->id}.");
            }
        }

        $row->reserved = Money::round(Money::add($row->reserved, $amount));
        $row->save();
    }

    /** Release (part of) a reservation. Never drops below zero. */
    public function release(Account $account, string $amount): void
    {
        $row = $this->lockedBalance($account->id);
        $next = Money::sub($row->reserved, $amount);

        $row->reserved = Money::isPositive($next) ? Money::round($next) : '0';
        $row->save();
    }

    private function apply(int $accountId, string $delta, bool $enforce): void
    {
        $account = Account::with('accountType')->findOrFail($accountId);
        $row = $this->lockedBalance($accountId);

        $new = Money::add($row->balance, $delta);

        if ($enforce && Money::isPositive(Money::sub('0', $delta)) && ! $account->accountType->isUnlimited()) {
            // balance - reserved must stay >= -credit_limit
            $floor = Money::sub('0', $account->credit_limit);

            if (Money::lt(Money::sub($new, $row->reserved), $floor)) {
                throw new InsufficientBalanceException("Insufficient balance on account {$accountId}.");
            }
        }

        $row->balance = Money::round($new);
        $row->save();

        $negative = Money::lt($new, '0');
        if ($negative && $account->negative_since === null) {
            $account->forceFill(['negative_since' => now()])->save();
        } elseif (! $negative && $account->negative_since !== null) {
            $account->forceFill(['negative_since' => null])->save();
        }
    }
}
