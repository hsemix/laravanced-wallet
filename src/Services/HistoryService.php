<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Services;

use Carbon\CarbonInterface;
use Hsemix\AdvancedWallet\Models\Account;
use Hsemix\AdvancedWallet\Models\Transfer;
use Hsemix\AdvancedWallet\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Read-side queries over the transfer ledger. Every movement touching the
 * account is included (fees and chargebacks too), because they all move money.
 */
class HistoryService
{
    /**
     * Paginated history, newest first. Each item gets `direction` (credit|debit)
     * and `signed_amount` (positive for money in, negative for money out).
     */
    public function history(Account $account, null|string|CarbonInterface $from = null, null|string|CarbonInterface $to = null, int $perPage = 20, int $page = 1): LengthAwarePaginator
    {
        $paginator = $this->touching($account, $from, $to)
            ->with(['transaction', 'transferType', 'fromAccount', 'toAccount'])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        $paginator->getCollection()->transform(function (Transfer $t) use ($account) {
            $credit = $t->to_account_id === $account->id;
            $t->setAttribute('direction', $credit ? 'credit' : 'debit');
            $t->setAttribute('signed_amount', $credit ? Money::round($t->amount) : Money::round(Money::sub('0', $t->amount)));

            return $t;
        });

        return $paginator;
    }

    public function receivedCount(Account $account, null|string|CarbonInterface $from = null, null|string|CarbonInterface $to = null): int
    {
        return $this->period(Transfer::where('to_account_id', $account->id), $from, $to)->count();
    }

    public function sentCount(Account $account, null|string|CarbonInterface $from = null, null|string|CarbonInterface $to = null): int
    {
        return $this->period(Transfer::where('from_account_id', $account->id), $from, $to)->count();
    }

    public function totalReceived(Account $account, null|string|CarbonInterface $from = null, null|string|CarbonInterface $to = null): string
    {
        return Money::round((string) $this->period(Transfer::where('to_account_id', $account->id), $from, $to)->sum('amount'));
    }

    public function totalSent(Account $account, null|string|CarbonInterface $from = null, null|string|CarbonInterface $to = null): string
    {
        return Money::round((string) $this->period(Transfer::where('from_account_id', $account->id), $from, $to)->sum('amount'));
    }

    /** @return Builder<Transfer> */
    private function touching(Account $account, mixed $from, mixed $to): Builder
    {
        $query = Transfer::query()->where(fn (Builder $q) => $q
            ->where('from_account_id', $account->id)->orWhere('to_account_id', $account->id));

        return $this->period($query, $from, $to);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function period(Builder $query, mixed $from, mixed $to): Builder
    {
        if ($from !== null) {
            $query->where('created_at', '>=', is_string($from) ? Carbon::parse($from)->startOfDay() : $from);
        }

        if ($to !== null) {
            $query->where('created_at', '<=', is_string($to) && strlen($to) <= 10 ? Carbon::parse($to)->endOfDay() : $to);
        }

        return $query;
    }
}
