<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Console;

use Hsemix\AdvancedWallet\Models\Account;
use Hsemix\AdvancedWallet\Services\AccountService;
use Hsemix\AdvancedWallet\Support\Money;
use Illuminate\Console\Command;

class ReconcileBalancesCommand extends Command
{
    protected $signature = 'wallet:reconcile {--account= : Only this account id} {--fix : Rewrite drifted balance rows from the ledger}';

    protected $description = 'Compare cached account balances and holds with the transfer ledger and open reservations (and optionally repair them)';

    public function handle(AccountService $accounts): int
    {
        $drifted = 0;

        Account::query()
            ->when($this->option('account'), fn ($q, $id) => $q->whereKey($id))
            ->each(function (Account $account) use ($accounts, &$drifted) {
                $cached = $accounts->balance($account);
                $ledger = $accounts->ledgerBalance($account);
                $held = $accounts->ledgerReserved($account);

                $balanceDrifted = Money::comp($cached->balance, $ledger) !== 0;
                $reservedDrifted = Money::comp($cached->reserved, $held) !== 0;

                if (! $balanceDrifted && ! $reservedDrifted) {
                    return;
                }

                $drifted++;
                if ($balanceDrifted) {
                    $this->warn("Account {$account->id}: cached balance ".Money::round($cached->balance).' != ledger '.Money::round($ledger).'');
                }
                if ($reservedDrifted) {
                    $this->warn("Account {$account->id}: cached reserved ".Money::round($cached->reserved).' != open holds '.Money::round($held).'');
                }

                if ($this->option('fix')) {
                    $accounts->reconcile($account);
                    $this->line('  repaired');
                }
            });

        $drifted === 0 ? $this->info('All balances and holds match the ledger.') : $this->error("{$drifted} account(s) drifted.");

        return $drifted === 0 || $this->option('fix') ? self::SUCCESS : self::FAILURE;
    }
}
