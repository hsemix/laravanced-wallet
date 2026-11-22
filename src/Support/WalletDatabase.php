<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Support;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

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

    /**
     * Run $callback with the wallet pointed at $connection, then put it back, even if the callback throws.
     *
     * The connection is purged first: Laravel caches the open connection by name, so after a tenant tool re-points
     * `$connection` at another database the next query would otherwise still reach the previous one. It is purged again
     * afterwards (unless it is the one we return to) so the next user of that name resolves it afresh.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function using(string $connection, Closure $callback): mixed
    {
        $previous = config('wallet.connection');

        // Purging drops the open connection, which would silently roll back anything in flight on it.
        foreach (array_unique(array_filter([$previous ?? config('database.default'), $connection])) as $name) {
            if (array_key_exists($name, DB::getConnections()) && DB::connection($name)->transactionLevel() > 0) {
                throw new LogicException("Cannot switch the wallet connection while a transaction is open on [{$name}].");
            }
        }

        DB::purge($connection);
        config(['wallet.connection' => $connection]);

        try {
            return $callback();
        } finally {
            config(['wallet.connection' => $previous]);

            if ($previous !== $connection) {
                DB::purge($connection);
            }
        }
    }
}
