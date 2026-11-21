# Reference

## Configuration (`config/wallet.php`)

| Key | Default | Meaning |
|---|---|---|
| `table_prefix` | `wallet_` | Prefix for every package table. Set **before** migrating. |
| `currency` | `USD` | Default currency code stored on new account types (informational, see limitations). |
| `connection` | `null` | Database connection for the package (null = app default). |
| `account_number_length` | `6` | Zero-padded width of the generated number after the type's prefix. |
| `max_open_requests_per_payer` | `null` | Cap on open payment requests per payer account (null = unlimited). |
| `commands` | `true` | Register the artisan commands. |

## Entry points

```php
Wallet::accounts()      // AccountService
Wallet::transferTypes() // TransferTypeService
Wallet::fees()          // TransferFeeService
Wallet::accountFees()   // AccountFeeService
Wallet::payments()      // PaymentService
Wallet::scheduled()     // ScheduledPaymentService
Wallet::history()       // HistoryService
Wallet::pay($array)     // = payments()->perform
Wallet::preview($array) // = payments()->preview
Wallet::balance($acct)  // = accounts()->balance
```

All services are container singletons; type-hint them for dependency injection instead of using the facade.

## API index

**AccountService**: `createAccountType(array)`, `resolveType($t)`, `createAccount($owner, $type, $options)`, `findFor($owner, $type)`,
`systemAccount($type)`, `resolveHolder($holder, AccountType)`, `balance($account)`, `ledgerBalance($account, $asOf)`, `ledgerReserved($account)`, `reconcile($account)`.

**TransferTypeService**: `create(array)`, `resolve($type)`.

**TransferFeeService**: `create(array)`.

**AccountFeeService**: `create(array)`, `exempt($account, $exempt)`, `chargeOpening($account)`, `processDue($on)`.

**PaymentService**: `perform($input)`, `preview($input)`, `authorize($tx, $by)`, `deny($tx, $by, $reason)`, `cancel($tx, $by)`,
`chargeback($subject, $by, $description, $force)`.

**ScheduledPaymentService**: `schedule(array)`, `processDue($on)`, `cancel($tx, $by)`.

**HistoryService**: `history(...)`, `receivedCount`, `sentCount`, `totalReceived`, `totalSent`.

**HasWallet trait**: `walletAccounts()`, `openAccount($type, $options)`, `walletAccount($type)`, `walletBalance($type)`,
`payTo($to, $type, $amount, $description, $meta, $idempotencyKey)`.

Lower-level services (`BalancePostingService`, `TransferPlanner`, `LimitGuard`) are public for advanced use but are
not needed day to day. If you call `BalancePostingService` directly, do so inside a database transaction.

## Models (`Hsemix\AdvancedWallet\Models`)

`AccountType`, `Account`, `AccountBalance`, `TransferType`, `TransferFee`, `AccountFee`, `AccountFeeCharge`, `Transaction`, `Transfer`, `Installment`.
Enums (`Hsemix\AdvancedWallet\Enums`): `AccountKind` (user, system), `TransferKind` (payment, generated),
`TransactionStatus`, `InstallmentStatus`, `ChargeMode`, `PayerReceiver` (source, destination, system, fixed_account, source_owner, destination_owner), `ScheduleUnit`, `AccountFeeTrigger`, `AccountFeeChargeStatus`, `InsufficientFundsPolicy`.

## Tables

With the default prefix:

| Table | Purpose |
|---|---|
| `wallet_account_types` | Account categories, number generation, system limit type |
| `wallet_accounts` | Accounts (nullable morph owner), `credit_limit`, `negative_since`, `active`, `fees_waived` |
| `wallet_account_balances` | One row per account: `balance`, `reserved` (unique `account_id`) |
| `wallet_transfer_types` | Transfer types and all their rules |
| `wallet_transfer_fees` | Fee rules |
| `wallet_account_fees` | [Account fee](account-fees.md) rules (opening / recurring) |
| `wallet_account_fee_charges` | One row per account, fee and period: the audit trail and the guard against double charging |
| `wallet_transactions` | Operations; unique `(from_account_id, idempotency_key)`; decision audit (`decided_by`, `decision_reason`) |
| `wallet_transfers` | The immutable ledger; fee transfers link via `parent_id`; chargebacks via `chargeback_of_id` |
| `wallet_installments` | Scheduled payment installments |

Foreign keys restrict deleting accounts, account types and transfer types that have history.

## Limitations

- **One database per tenant, not a shared one.** There is no tenant scoping; see [multi-tenancy](multi-tenancy.md).
- **Single currency per ledger path.** `currency` is stored on account types but transfers between different currencies are not
  converted or blocked; keep each transfer type within one currency.
- **Scheduled payments** do not go through authorization, do not retry *failed* installments (installments blocked only by a
  period limit are deferred and retried), and have no idempotency key.
- **Authorization is single-step.** The deciding model, time and reason are recorded, but there are no approval levels or per-approver permissions.
- **Immutability is application-level.** Raw SQL and bulk `query()->update()` bypass model events. Withhold `UPDATE`/`DELETE`
  on `wallet_transfers` from the app DB user for a hard guarantee.
- **No notifications are sent** by the package; use the events.
- **Row locking needs MySQL/PostgreSQL.** SQLite works for development and tests but doesn't lock rows.
- Authorization re-plans fees using the fee configuration at authorization time.
