# Account types & accounts

## Creating account types

```php
Wallet::accounts()->createAccountType([
    'name' => 'Savings',
    'internal_name' => 'savings',          // unique; used everywhere in code
    'kind' => 'user',                      // 'user' | 'system' (or AccountKind enum)
    'description' => 'Member savings',     // optional
    'currency' => 'USD',                   // optional, defaults to config('wallet.currency')
    'generate_account_number' => true,     // optional
    'account_number_prefix' => '20',       // optional, unique, requires generate_account_number
    'system_limit_type' => 'limited',      // system types only: 'limited' | 'unlimited'
]);
```

Returns the `AccountType` model. Throws `MissingParameterException`, `InvalidAccountTypeException` (bad `kind`),
`InvalidParameterException`, or `DuplicateRecordException` (duplicate `internal_name` or prefix).

**System account types** automatically get their single system account (and balance row). You never create system
accounts yourself. Fetch one with:

```php
$treasury = Wallet::accounts()->systemAccount('treasury');   // Account; accepts model, id or internal_name
```

`system_limit_type`:

- `unlimited`: the account may go arbitrarily negative (treasury, mint).
- `limited` (or null): behaves like any account: it needs funds, up to its `credit_limit`.

## Opening accounts

```php
$account = $user->openAccount('savings');                      // via HasWallet
$account = $user->openAccount('savings', ['credit_limit' => 500]);

// or without the trait
$account = Wallet::accounts()->createAccount($user, 'savings', ['credit_limit' => 500]);
```

- One account per owner and account type (`DuplicateRecordException` otherwise).
- System types cannot be opened for owners (`InvalidAccountTypeException`).
- `credit_limit` is a **positive overdraft allowance**: with `credit_limit = 500` the account may reach −500.
- A number like `20000001` is generated when the type has `generate_account_number` (prefix + zero-padded sequence,
  width from `config('wallet.account_number_length')`).

## Finding accounts

```php
$user->walletAccount('savings');                        // ?Account
Wallet::accounts()->findFor($user, 'savings');          // ?Account
$user->walletAccounts;                                  // all accounts (MorphMany)
Wallet::accounts()->resolveType('savings');             // AccountType from model|id|internal_name
```

## Deactivating an account

Set `active` to `false` (`$account->update(['active' => false])`). Payments to or from an inactive account throw
`InvalidAccountException`. Existing history and balances are untouched.

## Account fields

| Field | Notes |
|---|---|
| `account_type_id` | |
| `owner_type`, `owner_id` | Morph; both null for system accounts |
| `number` | Generated number or null |
| `credit_limit` | Overdraft allowance, default 0 |
| `negative_since` | Set when the balance goes below zero, cleared when it recovers |
| `active` | |

Relations: `accountType`, `accountBalance`, `owner`, `outgoingTransfers`, `incomingTransfers`.
