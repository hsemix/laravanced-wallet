<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Services;

use Carbon\CarbonInterface;
use Hsemix\AdvancedWallet\Enums\AccountKind;
use Hsemix\AdvancedWallet\Exceptions\DuplicateRecordException;
use Hsemix\AdvancedWallet\Exceptions\InvalidAccountException;
use Hsemix\AdvancedWallet\Exceptions\InvalidAccountTypeException;
use Hsemix\AdvancedWallet\Exceptions\InvalidParameterException;
use Hsemix\AdvancedWallet\Exceptions\MissingParameterException;
use Hsemix\AdvancedWallet\Models\Account;
use Hsemix\AdvancedWallet\Models\AccountBalance;
use Hsemix\AdvancedWallet\Models\AccountType;
use Hsemix\AdvancedWallet\Models\Transaction;
use Hsemix\AdvancedWallet\Models\Transfer;
use Hsemix\AdvancedWallet\Support\BalanceSummary;
use Hsemix\AdvancedWallet\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AccountService
{
    /* ---------------------------------------------------------------- types */

    /**
     * @param  array<string, mixed>  $data  name, internal_name and kind are required; optional: description, system_limit_type,
     *                                      generate_account_number, account_number_prefix, currency
     */
    public function createAccountType(array $data): AccountType
    {
        foreach (['name', 'internal_name', 'kind'] as $field) {
            if (! isset($data[$field]) || $data[$field] === '') {
                throw new MissingParameterException("Missing parameter ({$field}).");
            }
        }

        $kind = $data['kind'] instanceof AccountKind ? $data['kind'] : AccountKind::tryFrom((string) $data['kind']);
        if (! $kind) {
            throw new InvalidAccountTypeException('kind must be "user" or "system".');
        }

        if (AccountType::where('internal_name', $data['internal_name'])->exists()) {
            throw new DuplicateRecordException("Account type {$data['internal_name']} already exists.");
        }

        $limit = $data['system_limit_type'] ?? null;
        if ($limit !== null) {
            if ($kind !== AccountKind::System || ! in_array($limit, ['limited', 'unlimited'], true)) {
                throw new InvalidParameterException('system_limit_type is "limited" or "unlimited" and only valid for system accounts.');
            }
        }

        $generate = (bool) ($data['generate_account_number'] ?? false);
        $prefix = $data['account_number_prefix'] ?? null;

        if ($prefix !== null) {
            if (! $generate) {
                throw new InvalidParameterException('account_number_prefix requires generate_account_number.');
            }
            if (AccountType::where('account_number_prefix', $prefix)->exists()) {
                throw new DuplicateRecordException("Account number prefix {$prefix} is already used.");
            }
        }

        return DB::transaction(function () use ($data, $kind, $limit, $generate, $prefix) {
            $type = AccountType::create([
                'name' => $data['name'],
                'internal_name' => $data['internal_name'],
                'description' => $data['description'] ?? null,
                'kind' => $kind,
                'system_limit_type' => $limit,
                'generate_account_number' => $generate,
                'account_number_prefix' => $prefix,
                'currency' => $data['currency'] ?? config('wallet.currency', 'USD'),
            ]);

            // A system account type has exactly one account, created with it.
            if ($kind === AccountKind::System) {
                $account = Account::create(['account_type_id' => $type->id]);
                AccountBalance::create(['account_id' => $account->id, 'balance' => 0, 'reserved' => 0]);
            }

            return $type;
        });
    }

    public function resolveType(AccountType|int|string $type): AccountType
    {
        if ($type instanceof AccountType) {
            return $type;
        }

        $found = is_int($type)
            ? AccountType::find($type)
            : AccountType::where('internal_name', $type)->first();

        return $found ?? throw new InvalidAccountTypeException("Unknown account type: {$type}.");
    }

    /* ------------------------------------------------------------- accounts */

    /**
     * Open a user account for an owner (any Eloquent model).
     *
     * @param  array{credit_limit?:int|float|string}  $options
     */
    public function createAccount(Model $owner, AccountType|int|string $type, array $options = []): Account
    {
        $type = $this->resolveType($type);

        if ($type->isSystem()) {
            throw new InvalidAccountTypeException('System account types already own a single system account.');
        }

        if ($this->findFor($owner, $type)) {
            throw new DuplicateRecordException("Owner already has a {$type->internal_name} account.");
        }

        return DB::transaction(function () use ($owner, $type, $options) {
            $account = Account::create([
                'account_type_id' => $type->id,
                'owner_type' => $owner->getMorphClass(),
                'owner_id' => $owner->getKey(),
                'number' => $type->generate_account_number ? $this->nextNumber($type) : null,
                'credit_limit' => Money::round($options['credit_limit'] ?? 0),
                'active' => true,
            ]);

            AccountBalance::create(['account_id' => $account->id, 'balance' => 0, 'reserved' => 0]);

            return $account->setRelation('accountType', $type);
        });
    }

    public function findFor(Model $owner, AccountType|int|string $type): ?Account
    {
        $type = $this->resolveType($type);

        return Account::where('account_type_id', $type->id)
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->first();
    }

    public function systemAccount(AccountType|int|string $type): Account
    {
        $type = $this->resolveType($type);

        return Account::where('account_type_id', $type->id)->whereNull('owner_id')->first()
            ?? throw new InvalidAccountException("No system account exists for type {$type->internal_name}.");
    }

    /**
     * Turn "whatever the caller passed" into an account of the expected type.
     * Accepts: Account, owner Model, "system", or an account id.
     */
    public function resolveHolder(mixed $holder, AccountType $expected): Account
    {
        $account = match (true) {
            $holder instanceof Account => $holder,
            $holder instanceof Model => $this->findFor($holder, $expected),
            is_string($holder) && strtolower($holder) === 'system' => $this->systemAccount($expected),
            is_int($holder) => Account::find($holder),
            default => null,
        };

        if (! $account) {
            throw new InvalidAccountException("No {$expected->internal_name} account found for the given holder.");
        }

        if ($account->account_type_id !== $expected->id) {
            throw new InvalidAccountException("Account {$account->id} is not a {$expected->internal_name} account.");
        }

        if (! $account->active) {
            throw new InvalidAccountException("Account {$account->id} is inactive.");
        }

        return $account->loadMissing('accountType');
    }

    private function nextNumber(AccountType $type): string
    {
        $prefix = (string) $type->account_number_prefix;
        $last = Account::where('account_type_id', $type->id)->orderByDesc('id')->lockForUpdate()->value('number');
        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, (int) config('wallet.account_number_length', 6), '0', STR_PAD_LEFT);
    }

    /* ------------------------------------------------------------- balances */

    /** Fast read of the cached projection. */
    public function balance(Account $account): BalanceSummary
    {
        $row = AccountBalance::where('account_id', $account->id)->first();

        return new BalanceSummary((string) ($row->balance ?? '0'), (string) ($row->reserved ?? '0'));
    }

    /**
     * Balance computed from the transfer history (the source of truth),
     * optionally as of a date. A date without time means end of that day.
     */
    public function ledgerBalance(Account $account, null|string|CarbonInterface $asOf = null): string
    {
        $end = $this->endOf($asOf);

        $credits = Transfer::where('to_account_id', $account->id)
            ->when($end, fn ($q) => $q->where('created_at', '<=', $end))->sum('amount');
        $debits = Transfer::where('from_account_id', $account->id)
            ->when($end, fn ($q) => $q->where('created_at', '<=', $end))->sum('amount');

        return Money::round(Money::sub((string) $credits, (string) $debits));
    }

    /** Rebuild the projection row from the ledger and open reservations. */
    public function reconcile(Account $account): BalanceSummary
    {
        return DB::transaction(function () use ($account) {
            $row = app(BalancePostingService::class)->lockedBalance($account->id);

            $row->balance = $this->ledgerBalance($account);
            $row->reserved = Money::round((string) Transaction::where('from_account_id', $account->id)
                ->whereIn('status', ['pending', 'open'])->sum('reserved_amount'));
            $row->save();

            $negative = Money::lt($row->balance, '0');
            $account->forceFill(['negative_since' => $negative ? ($account->negative_since ?? now()) : null])->save();

            return new BalanceSummary((string) $row->balance, (string) $row->reserved);
        });
    }

    private function endOf(null|string|CarbonInterface $date): ?CarbonInterface
    {
        if ($date === null) {
            return null;
        }

        if (is_string($date)) {
            $dateOnly = strlen($date) <= 10;
            $date = Carbon::parse($date);

            return $dateOnly ? $date->endOfDay() : $date;
        }

        return $date;
    }
}
