<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Support;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * The database connection the wallet lives on (`config('wallet.connection')`, null = the application default).
 *
 * Transactions must be opened on THIS connection, not on the default one. The models already use it; if a
 * transaction were opened elsewhere (the usual case with a separate wallet or per-tenant database), the wallet's
 * writes would not be atomic and its `FOR UPDATE` row locks would be released the moment each query finished.
 */
final class WalletDatabase
{
    public static function connection(): ConnectionInterface
    {
        return DB::connection(config('wallet.connection'));
    }

    /**
     * Run $callback in a transaction on the wallet connection (nested calls become savepoints).
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function transaction(Closure $callback, int $attempts = 1): mixed
    {
        return self::connection()->transaction($callback, $attempts);
    }
}
