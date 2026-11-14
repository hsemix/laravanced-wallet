<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Models;

use Hsemix\AdvancedWallet\Enums\AccountKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $account_type_id
 * @property string|null $owner_type
 * @property int|null $owner_id
 * @property string|null $number
 * @property string $credit_limit
 * @property Carbon|null $negative_since
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property AccountType $accountType
 * @property AccountBalance|null $accountBalance
 */
class Account extends WalletModel
{
    protected string $baseTable = 'accounts';

    protected $casts = [
        'active' => 'boolean',
        'negative_since' => 'datetime',
    ];

    /** @return BelongsTo<AccountType, $this> */
    public function accountType(): BelongsTo
    {
        return $this->belongsTo(AccountType::class);
    }

    /** @return HasOne<AccountBalance, $this> */
    public function accountBalance(): HasOne
    {
        return $this->hasOne(AccountBalance::class);
    }

    /** @return MorphTo<Model, $this> */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasMany<Transfer, $this> */
    public function outgoingTransfers(): HasMany
    {
        return $this->hasMany(Transfer::class, 'from_account_id');
    }

    /** @return HasMany<Transfer, $this> */
    public function incomingTransfers(): HasMany
    {
        return $this->hasMany(Transfer::class, 'to_account_id');
    }

    public function kind(): AccountKind
    {
        return $this->accountType->kind;
    }

    public function isSystem(): bool
    {
        return $this->kind() === AccountKind::System;
    }
}
