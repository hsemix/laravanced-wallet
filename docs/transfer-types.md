# Transfer types

A transfer type defines **what can move from one account type to another and under which rules**.

```php
Wallet::transferTypes()->create([
    'name' => 'Wire',
    'internal_name' => 'wire',
    'from_account_type' => 'wallet',       // model | id | internal_name
    'to_account_type'   => 'wallet',
    'requires_authorization' => true,
    'max_amount' => 5000,
]);
```

Addressed afterwards as `wallet.wire` (`"{from account type}.{internal_name}"`). `internal_name` is unique **per from-account-type**,
so `treasury.deposit` and `wallet.deposit` can coexist; a bare `deposit` then raises `InvalidTransferTypeException` (ambiguous).

Required: `name`, `internal_name`, `from_account_type`, `to_account_type`. Optional: `kind` (`payment` default, or `generated`),
`description`, `confirmation_message` (returned by `preview()` so your UI can show it).

## Options

All booleans must be real booleans (`true`/`false`), numbers must be non-negative.

### Availability and rules

| Option | Default | Effect |
|---|---|---|
| `enabled` | `true` | Disabled types cannot be used for new payments. History is unaffected. |
| `requires_description` | `false` | Payments need a non-empty `description` (`MissingParameterException`). |
| `requires_authorization` | `false` | Payments stay **pending** until authorized. See [Authorization](authorization.md). |

### Limits (per paying account, per transfer type)

| Option | Effect |
|---|---|
| `min_amount`, `max_amount` | Per-payment bounds (inclusive). `min_amount` must not exceed `max_amount`. |
| `max_amount_per_day` / `_week` / `_month` | Total that may be paid in the period, including this payment. |
| `max_transfers_per_day` / `_week` / `_month` | Number of payments allowed in the period, including this one. |

A payment that would take a period **past** a limit throws `LimitExceededException`; exactly reaching the limit is allowed.
They apply to ordinary payments **and** to scheduled installments. See [Payments → Limits](payments.md#limits).

### Scheduled, recurring and interest

| Option | Default | Effect |
|---|---|---|
| `allows_scheduled_payments` | `false` | Required to use `Wallet::scheduled()->schedule()`. |
| `allows_recurring_payments` | `false` | Permits day/week/year frequencies. Without it only monthly schedules are allowed. |
| `reserve_total_amount_on_scheduled_payments` | `false` | Hold the full schedule total on the payer at creation. |
| `max_installments` | none | Upper bound on installment count. |
| `allows_payment_requests` | `false` | Required to use `Wallet::requests()->create()`. |
| `reserve_on_payment_request` | `false` | Hold the payer's money while a request is open. Needs the authorizer's `'reserve'` approval. See [Payment requests](payment-requests.md). |
| `allows_chargebacks` / `allows_forced_chargebacks` | `true` | Allow chargebacks / forced chargebacks of this type. |
| `max_chargeback_time_amount` + `max_chargeback_time_field` | `null` | Chargeback window (e.g. `30` + `day`). See [Chargebacks](chargebacks.md#controlling-chargebacks). |
| `allows_interest` | `false` | Adds interest to installments. Requires `allows_scheduled_payments`. |
| `interest_rate` | none | Default interest percentage; may be overridden per schedule. |
| `interest_is_annual` | `false` | `false` = flat interest on the principal; `true` = annual rate, declining-balance amortisation. |

See [Scheduled payments](scheduled-payments.md).

### Notification flags

`notify_payment_received`, `notify_payment_sender` are stored on the type for **your** listeners to read; the package does
not send notifications itself. See [Events](events-and-commands.md).

## Generated types

Create with `'kind' => 'generated'` for fee transfers. They are rejected as payments
(`InvalidTransferTypeException`) and are what [fees](fees.md) point at.

## Resolving

```php
Wallet::transferTypes()->resolve('wallet.wire');   // TransferType (with fromAccountType, toAccountType loaded)
Wallet::transferTypes()->resolve(5);               // by id
$type->fullName();                                 // "wallet.wire"
```
