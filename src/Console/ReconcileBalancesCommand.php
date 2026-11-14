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

    protected $description = 'Compare cached account balances with the transfer ledger (and optionally repair them)';

    public function handle(AccountService $accounts): int
    {
        $drifted = 0;

        Account::query()
            ->when($this->option('account'), fn ($q, $id) => $q->whereKey($id))
            ->each(function (Account $account) use ($accounts, &$drifted) {
                $cached = $accounts->balance($account)->balance;
                $ledger = $accounts->ledgerBalance($account);

                if (Money::comp($cached, $ledger) === 0) {
                    return;
                }

                $drifted++;
                $this->warn("Account {$account->id}: cached {$cached} != ledger {$ledger}");

                if ($this->option('fix')) {
                    $accounts->reconcile($account);
                    $this->line('  repaired');
                }
            });

        $drifted === 0 ? $this->info('All balances match the ledger.') : $this->error("{$drifted} account(s) drifted.");

        return $drifted === 0 || $this->option('fix') ? self::SUCCESS : self::FAILURE;
    }
}
