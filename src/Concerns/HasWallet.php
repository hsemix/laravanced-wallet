<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Concerns;

use Hsemix\AdvancedWallet\Exceptions\InvalidAccountException;
use Hsemix\AdvancedWallet\Models\Account;
use Hsemix\AdvancedWallet\Models\AccountType;
use Hsemix\AdvancedWallet\Models\Transaction;
use Hsemix\AdvancedWallet\Services\AccountService;
use Hsemix\AdvancedWallet\Services\PaymentService;
use Hsemix\AdvancedWallet\Support\BalanceSummary;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Add to any Eloquent model (User, Company, ...) that owns accounts.
 */
trait HasWallet
{
    public function walletAccounts(): MorphMany
    {
        return $this->morphMany(Account::class, 'owner');
    }

    public function openAccount(AccountType|int|string $type, array $options = []): Account
    {
        return app(AccountService::class)->createAccount($this, $type, $options);
    }

    public function walletAccount(AccountType|int|string $type): ?Account
    {
        return app(AccountService::class)->findFor($this, $type);
    }

    public function walletBalance(AccountType|int|string $type): BalanceSummary
    {
        $account = $this->walletAccount($type)
            ?? throw new InvalidAccountException('You have no such account.');

        return app(AccountService::class)->balance($account);
    }

    /**
     * Pay $to from this model.
     *
     * @param  string  $type  "account_type.transfer_type"
     */
    public function payTo(mixed $to, string $type, int|float|string $amount, ?string $description = null, array $meta = [], ?string $idempotencyKey = null): Transaction
    {
        return app(PaymentService::class)->perform([
            'from' => $this,
            'to' => $to,
            'type' => $type,
            'amount' => $amount,
            'description' => $description,
            'by' => $this,
            'meta' => $meta,
            'idempotency_key' => $idempotencyKey,
        ]);
    }
}
