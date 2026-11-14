# Payments

## Performing a payment

```php
$tx = Wallet::pay([
    'from' => $ann,                       // see "Addressing accounts"
    'to'   => $bob,
    'type' => 'wallet.transfer',
    'amount' => 40,
    'description' => 'rent',              // optional (required if the type says so)
    'by' => $ann,                         // optional: model recorded as the initiator
    'meta' => ['order_id' => 123],        // optional: stored as JSON
    'idempotency_key' => $requestId,      // optional: see below
]);

// equivalent shortcuts
Wallet::payments()->perform([...]);
$ann->payTo($bob, 'wallet.transfer', 40, 'rent', meta: [...], idempotencyKey: $requestId);   // from = $ann, by = $ann
```

Returns a `Transaction` with `status` `successful` (transfers loaded), or `pending` if the type
[requires authorization](authorization.md). Failures throw (see [Errors](errors.md)) and leave **nothing** booked.

### Addressing accounts

`from` and `to` accept:

| Value | Meaning |
|---|---|
| `Account` model | that account (its type must match the transfer type) |
| any Eloquent model | that owner's account of the type the transfer type expects |
| `'system'` | the system account of the expected type |
| `int` | an account id |

The transfer type decides the account types, so you say `wallet.transfer` and pass users. Passing an account of the wrong
type, an inactive account, a missing account, or the same account as both sides throws `InvalidAccountException`.

`type` accepts `"account_type.transfer_type"`, a `TransferType` model, an id, or a bare unique `internal_name`.

### What a payment writes

1. A `Transaction` (UUID `reference`, status, amount, description, `by`, `meta`).
2. A `Transfer` for the payment, plus one child transfer per applicable fee.
3. Balance updates for every account touched, netted per account, under row locks. The payer's net debit must be
   affordable (`balance − reserved − debit >= −credit_limit`), else `InsufficientBalanceException`.

Event `PaymentPerformed` fires after commit.

## Previewing

```php
$preview = Wallet::preview(['from' => $ann, 'to' => $bob, 'type' => 'wallet.transfer', 'amount' => 50]);
```

Validates exactly as `perform()` does (type, accounts, description, limits, funds) but books nothing. Uses the same
plan, so the numbers are what would be booked:

```php
[
  'from_account' => ['id' => 1, 'number' => '10000001', 'type' => 'wallet', 'kind' => 'user', 'owner_type' => '...', 'owner_id' => 1],
  'to_account'   => [...],
  'transfer_type' => ['id' => 2, 'name' => 'Transfer', 'internal_name' => 'wallet.transfer'],
  'description' => null,
  'payment_amount' => '50.0000',     // what you asked
  'main_amount'    => '50.0000',     // what the recipient receives (less deducted fees)
  'fees' => [['name' => 'Fee', 'internal_name' => 'transfer_fee', 'amount' => '0.5000',
              'charge_mode' => 'percentage', 'deduct_amount' => false, 'payer' => 'source']],
  'total_amount'   => '50.5000',     // net the payer is debited
  'balance_after'  => '49.5000',     // payer's balance after
  'pending_authorization' => false,
  'confirmation_message' => null,
]
```

Typical flow: show the preview and `confirmation_message`, then call `perform()` on confirmation.

## Limits

Configured on the [transfer type](transfer-types.md#limits-per-paying-account-per-transfer-type); checked per **paying
account and transfer type**, before booking.

- Per-payment `min_amount` / `max_amount`.
- Per day / week / month totals and counts. Periods start at `startOfDay`, `startOfWeek` (Monday) and `startOfMonth`
  in the app timezone.
- Counted: booked payments (main transfers only, not fees) that were not charged back, **plus pending** payments.
- A payment is refused if it would push a period total or count **above** the limit; landing exactly on it is fine.
- Scheduled installments are checked against the same limits (see [Scheduled payments → Limits](scheduled-payments.md#limits)).
- A limit that is used up *right now* (day/week/month) throws `PeriodLimitExceededException`, a subclass of
  `LimitExceededException`, so you can tell "try again later" from "this amount can never be paid".

## Idempotency

Retried requests (client timeouts, double clicks, queue retries) must not pay twice. Supply a unique `idempotency_key`
(≤ 100 chars, e.g. a UUID your client generates once per intended payment):

```php
$first  = $ann->payTo($bob, 'wallet.transfer', 40, idempotencyKey: 'order-123');
$second = $ann->payTo($bob, 'wallet.transfer', 40, idempotencyKey: 'order-123');

$second->id === $first->id;          // true: same transaction, money moved once
$second->wasRecentlyCreated;         // false: use this to tell a replay from a new payment
```

- The replay lookup runs **before** balance and limit checks, so retrying a payment that succeeded does not fail because the money is now spent.
- Reusing a key with a different payee, type or amount throws `IdempotencyConflictException`.
- Keys are scoped to the paying account (unique index on `from_account_id + idempotency_key`); two payers may use the same key.
- Two simultaneous identical requests resolve to one payment (the unique index decides; the loser returns the winner's transaction).
- Works for pending (authorization) payments too. Not applied to scheduled payments or chargebacks.
- Without a key, nothing is deduplicated.

HTTP example (controller reading a header):

```php
$tx = $user->payTo($payee, 'wallet.transfer', $amount, idempotencyKey: $request->header('Idempotency-Key'));
return response()->json([...], $tx->wasRecentlyCreated ? 201 : 200);
```

## Transaction fields

`reference` (UUID), `idempotency_key`, `from_account_id`, `to_account_id`, `transfer_type_id`, `amount`,
`reserved_amount`, `description`, `status`, `by` (morph: who initiated), `chargeback_of_id`, `scheduled` and schedule fields,
`meta`, and the decision audit (`decided_at`, `decided_by` morph, `decision_reason`, see [Authorization](authorization.md#audit-trail)). Statuses: `pending`, `successful`, `failed`, `denied`, `cancelled`, `open`, `closed`.
Relations: `transfers`, `installments`, `fromAccount`, `toAccount`, `transferType`, `by`, `decidedBy`, `chargebackOf`, `chargebacks`.
