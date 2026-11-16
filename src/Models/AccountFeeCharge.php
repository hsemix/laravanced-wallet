<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Models;

use Hsemix\AdvancedWallet\Enums\AccountFeeChargeStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One fee owed by one account for one period (0 = the opening fee). This is both the audit trail and the
 * guarantee that a period is never charged twice.
 *
 * @property int $id
 * @property int $account_fee_id
 * @property int $account_id
 * @property int $period
 * @property Carbon $due_date
 * @property AccountFeeChargeStatus $status
 * @property string|null $amount
 * @property int|null $transaction_id
 * @property string|null $reason
 * @property Carbon|null $processed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property AccountFee $accountFee
 * @property Account $account
 */
class AccountFeeCharge extends WalletModel
{
    protected string $baseTable = 'account_fee_charges';

    protected $casts = [
        'status' => AccountFeeChargeStatus::class,
        'due_date' => 'date',
        'processed_at' => 'datetime',
    ];

    /** @return BelongsTo<AccountFee, $this> */
    public function accountFee(): BelongsTo
    {
        return $this->belongsTo(AccountFee::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
