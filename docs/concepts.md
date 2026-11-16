# Concepts

## The pieces

```
AccountType ──< Account ──── AccountBalance (cached balance + reserved)
     │
TransferType (from AccountType → to AccountType)
     │  └──< TransferFee ──> generated TransferType
     │
Transaction (one business operation: status, who, why)
     └──< Transfer (ledger rows; fee transfers are children of the transfer that caused them)
     └──< Installment (scheduled payments only)
```

| Concept | Meaning |
|---|---|
| **Account type** | A category of account, e.g. `wallet` (user) or `treasury` (system). Has a kind: `user` or `system`. |
| **Account** | A holder's account of one type. A user account belongs to any Eloquent model (polymorphic owner). A **system account** has no owner; each system account type has exactly one, created with the type. |
| **Transfer type** | A permitted kind of movement from one account type to another, with rules (limits, authorization, scheduling…). Addressed as `"account_type.transfer_type"`, e.g. `wallet.transfer`. |
| **Transfer fee** | A rule on a transfer type that generates an extra transfer (the fee) whenever a payment matches. |
| **Transaction** | One operation: a payment, a chargeback, or a scheduled payment. Has a UUID `reference`, a status and an optional idempotency key. |
| **Account fee** | A fee on the account itself, charged when it opens or on a schedule. See [Account fees](account-fees.md). |
| **Transfer** | One ledger entry: amount, from account, to account. Immutable. A payment with two fees produces three transfers. |

## Transfer kinds

- `payment`: something a member or the system initiates (`wallet.transfer`, `treasury.deposit`).
- `generated`: produced only by a fee (`wallet.fee`). Cannot be used as a payment.

## Balances

Each account has:

- **balance**: money in the account.
- **reserved**: money held for pending payments and reserved scheduled payments.
- **available**: `balance − reserved`.

The transfers are the **source of truth**. The balance row is a cached projection updated, under a row lock, in the same
database transaction that writes the transfers. `wallet:reconcile` verifies it against the ledger and can repair it.

An account may spend while `balance − reserved − amount >= −credit_limit`. `credit_limit` defaults to `0` (no overdraft).
**Unlimited system accounts** (`system_limit_type = unlimited`) skip the check, which is how a treasury can fund deposits
without holding money.

## Money

Amounts are `decimal(20,4)` and handled as strings with bcmath, never floats. You may pass int, float or numeric string;
floats are normalised. Results come back as strings with 4 decimals (SQLite may return `45` rather than `45.0000`; compare
numerically, e.g. with `bccomp`).

## Atomicity

Every operation (`perform`, `authorize`, `chargeback`, each installment) runs in one database transaction. A payment either
books the main transfer, all its fee transfers and the balance changes, or nothing at all. Events are dispatched only
after the transaction commits.

## Double entry

Money is never created or destroyed by a payment: every transfer debits one account and credits another. Deposits work
because the unlimited treasury account goes negative by the same amount the member's account goes positive; the sum of all
balances is always zero.
