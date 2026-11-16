# Account fees

An [ordinary fee](fees.md) hangs off a payment: someone pays, so a fee is added. An **account fee** hangs off the
**account itself**. It is charged when the account is **opened**, or **every N days, weeks, months or years** for as long
as the account exists (a maintenance fee).

Each charge is a normal payment from the account to a system account, so it shows up in the account's history, is part of
the ledger's guarantees, fires `PaymentPerformed` and can be [charged back](chargebacks.md).

## 1. A transfer type to book it with

An account fee needs a **payment** transfer type from the charged account type to a **system** account (the account that
collects the fees). It cannot be a `generated` type and cannot require authorization: nobody is there to approve it.

```php
Wallet::transferTypes()->create([
    'name' => 'Account fee',
    'internal_name' => 'account_fee',
    'from_account_type' => 'wallet',
    'to_account_type' => 'fees',          // a system account type
]);
```

Any limits you put on this type (minimum, maximum, period caps) apply to the fee too.

## 2. Define the fee

```php
// Charged once when a wallet is opened.
Wallet::accountFees()->create([
    'name' => 'Account opening fee',
    'internal_name' => 'opening_fee',     // unique
    'account_type' => 'wallet',           // the accounts charged
    'transfer_type' => 'wallet.account_fee',
    'trigger' => 'opening',
    'amount' => 5,
]);

// Charged every month.
Wallet::accountFees()->create([
    'name' => 'Monthly maintenance',
    'internal_name' => 'maintenance',
    'account_type' => 'wallet',
    'transfer_type' => 'wallet.account_fee',
    'trigger' => 'recurring',
    'every_amount' => 1,
    'every_unit' => 'month',              // day | week | month | year
    'amount' => 2,
]);
```

| Option | Default | Meaning |
|---|---|---|
| `trigger` | required | `opening` (once, when the account opens) or `recurring` (every period). |
| `amount` | required | Positive. A percentage cannot exceed 100. |
| `charge_mode` | `fixed` | `fixed`: charge `amount`. `percentage` (recurring only): charge `amount`% of the account's **balance** at the time. An empty or negative account owes nothing. |
| `min_amount` / `max_amount` | none | The floor and cap of a **percentage** fee. |
| `every_amount`, `every_unit` | required for `recurring` | The period: every N days / weeks / months / years. |
| `starts_at` | now | Recurring only. Periods are counted from the later of the account's opening and `starts_at`. |
| `on_insufficient_funds` | `skip` | What happens when the account can't cover the fee. See below. |
| `enabled` | `true` | A disabled fee is ignored. |
| `description` | none | |

A fee only applies to accounts of its `account_type`. System accounts are never charged.

## When the account can't pay

A new account has no money, so an opening fee usually meets an empty balance. Each fee decides what to do with
`on_insufficient_funds`:

| Policy | Behaviour |
|---|---|
| `skip` | That charge is **forgiven**. It is recorded as `skipped` with the reason, and never retried. Later periods are still charged. |
| `retry` | The charge stays `pending` and is retried on every run until the account can pay. |
| `allow_negative` | Charge anyway. The account goes below zero, **past its credit limit**, and `negative_since` is set. |

Opening an account **never fails** because of a fee. The policy decides what happens to the fee, not to the account.

## Running it

**Opening fees** are charged inside `createAccount()` / `openAccount()`, so there is nothing to schedule.

**Recurring fees** are collected by `wallet:process-scheduled`, the same command that processes
[scheduled payments](scheduled-payments.md):

```php
Schedule::command('wallet:process-scheduled')->daily();
```

```
Account fees: 12 charged, 1 skipped, 0 waived, 3 pending.
```

Or call it yourself:

```php
Wallet::accountFees()->processDue();               // today
Wallet::accountFees()->processDue('2026-12-01');   // as of a date
// ['charged' => 12, 'skipped' => 1, 'waived' => 0, 'pending' => 3]
```

It is safe to run as often as you like. Every account, fee and period has exactly **one** charge record, so a period is
charged once however many times the job runs, and two overlapping runs cannot double-charge.

- **Missed runs are caught up.** If the job didn't run for three months, the next run charges the three periods.
- **A new fee never bills the past.** Periods are counted from `starts_at` (default: when you create the fee). Adding a
  maintenance fee today starts billing one period from today, even for year-old accounts.

## Exempting accounts

```php
$user->openAccount('wallet', ['waive_fees' => true]);   // exempt from the start

Wallet::accountFees()->exempt($account);          // exempt every account fee
Wallet::accountFees()->exempt($account, false);   // end the exemption
```

Periods that fall due while an account is exempt (or inactive) are recorded as `waived` (or `skipped`), so lifting the
exemption does **not** bill that time in arrears.

## The record of every charge

`Hsemix\AdvancedWallet\Models\AccountFeeCharge`, one row per account, fee and period:

| Column | |
|---|---|
| `period` | `0` for an opening fee, `1`, `2`, ... for recurring periods |
| `due_date` | When the period fell due |
| `status` | `pending`, `charged`, `skipped` or `waived` |
| `amount`, `transaction_id` | What was taken, and the payment that took it (set when `charged`) |
| `reason` | Why it is pending, skipped or waived |

```php
$account->fresh();
AccountFeeCharge::where('account_id', $account->id)->latest('due_date')->get();
```

## Events

`AccountFeeCharged` and `AccountFeeSkipped`, each with a `charge` (an `AccountFeeCharge`). They fire after commit. A
charged fee also fires `PaymentPerformed` for its payment.

## Validation

`create()` throws `InvalidParameterException` for a bad trigger, mode, policy or period, a non-positive amount, a
percentage opening fee (a new account has no balance), a percentage above 100, `min_amount` above `max_amount`, or a
system account type; and `InvalidTransferTypeException` when the transfer type is generated, requires authorization, or
doesn't run from the charged account type to a system account.
