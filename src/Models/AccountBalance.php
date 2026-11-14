<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Models;

use Hsemix\AdvancedWallet\Support\Money;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Cached projection of the transfer ledger. The transfers remain the source
 * of truth; use Wallet::accounts()->reconcile() to rebuild this row.
 *
 * @property int $id
 * @property int $account_id
 * @property string $balance
 * @property string $reserved
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AccountBalance extends WalletModel
{
    protected string $baseTable = 'account_balances';

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function available(): string
    {
        return Money::sub($this->balance, $this->reserved);
    }
}
