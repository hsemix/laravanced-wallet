<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Base model: applies the configurable table prefix and connection.
 */
abstract class WalletModel extends Model
{
    /** Unprefixed table name. */
    protected string $baseTable;

    protected $guarded = [];

    public function getTable(): string
    {
        return config('wallet.table_prefix', 'wallet_').$this->baseTable;
    }

    public function getConnectionName(): ?string
    {
        return $this->connection ?? config('wallet.connection');
    }
}
