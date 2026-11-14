<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Models;

use Hsemix\AdvancedWallet\Enums\AccountKind;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $internal_name
 * @property string|null $description
 * @property AccountKind $kind
 * @property string|null $system_limit_type
 * @property bool $generate_account_number
 * @property string|null $account_number_prefix
 * @property string $currency
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AccountType extends WalletModel
{
    protected string $baseTable = 'account_types';

    protected $casts = [
        'kind' => AccountKind::class,
        'generate_account_number' => 'boolean',
    ];

    /** @return HasMany<Account, $this> */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /** @return HasMany<TransferType, $this> */
    public function transferTypes(): HasMany
    {
        return $this->hasMany(TransferType::class, 'from_account_type_id');
    }

    public function isSystem(): bool
    {
        return $this->kind === AccountKind::System;
    }

    public function isUnlimited(): bool
    {
        return $this->isSystem() && $this->system_limit_type === 'unlimited';
    }
}
