# Chargebacks

A chargeback reverses money by booking **new** mirrored transfers. The original transfers are never edited or deleted
(they are immutable), so the ledger keeps the full history.

```php
$reversal = Wallet::payments()->chargeback($tx);                        // whole transaction
$reversal = Wallet::payments()->chargeback($transfer);                  // one root transfer (+ its fees)
$reversal = Wallet::payments()->chargeback($tx, $admin, 'Duplicate charge');
$reversal = Wallet::payments()->chargeback($tx, force: true);           // see below
```

Signature: `chargeback(Transaction|Transfer|int $subject, ?Model $by = null, ?string $description = null, bool $force = false)`.

## What it does

- Creates a new `Transaction` (status `successful`, `chargeback_of_id` = the original) whose `from`/`to` are swapped, amount is
  the sum of the reversed root transfers, and description defaults to `Chargeback for: …`.
- For every reversed transfer, including **fee transfers**, writes a mirror transfer with `from`/`to` swapped, linked
  through `chargeback_of_id`.
- Updates balances atomically. Fires `PaymentChargedBack(original, chargeback)`.

Example: Ann paid Bob 50 with a 10% fee (Ann −55, Bob +50, fees +5). The chargeback restores all three.

## Rules

- Each transfer can be charged back **once**. Charging back a transaction again, with nothing left to reverse, throws
  `InvalidTransactionException`.
- A chargeback cannot itself be charged back.
- A fee transfer cannot be charged back alone; charge back its parent (the payment).
- A pending, denied or cancelled transaction has no transfers, so there is nothing to charge back.
- The reversal is a real payment from the receiver back to the payer, so the receiver must be able to afford it.
  Otherwise `InsufficientBalanceException` and nothing changes.
- The transfer type's [chargeback rules](#controlling-chargebacks) and the optional authorizer are checked first.

## Controlling chargebacks

Everything here is **optional**; the defaults behave exactly as before.

**Per transfer type** (columns on `wallet_transfer_types`; the first two mirror the original system's
`max_chargeback_time_amount` / `max_chargeback_time_field`):

| Setting | Default | Meaning |
|---|---|---|
| `max_chargeback_time_amount` + `max_chargeback_time_field` | `null` | Time window after booking. Field is `minute`, `hour`, `day`, `week`, `month` or `year`; set both or neither. |
| `allows_chargebacks` | `true` | `false` forbids chargebacks of this type entirely. |
| `allows_forced_chargebacks` | `true` | `false` makes `force: true` fail for this type. |

```php
Wallet::transferTypes()->create([... 'max_chargeback_time_amount' => 30, 'max_chargeback_time_field' => 'day',
    'allows_forced_chargebacks' => false]);
```

- The window runs from the moment each root transfer was **booked**, not from when the transaction was created. Charging
  back a whole scheduled transaction therefore fails if any not-yet-reversed installment is outside the window; reverse
  the recent installments one by one (pass their root transfers).
- A breach throws `ChargebackNotAllowedException` and nothing changes.

**Who may charge back** is yours to decide: the package has no roles, so it asks one callback (the original system kept
this in `products_*_chargebacks` permission tables). The default allows everyone, as before:

```php
Wallet::authorizeChargebacksUsing(function (?Model $actor, Transaction $original, bool $force): bool {
    return $actor?->can('wallet-refund') && (!$force || $actor->can('wallet-force-refund'));
});
// Wallet::authorizeChargebacksUsing(null) restores the default.
```

A `false` throws `UnauthorizedChargebackException` (an `UnauthorizedActionException`, map it to HTTP 403). Pass the acting
user as `$by`; it is stored on the reversal transaction. The callback is plain PHP, so register it at runtime, not in config.

## `force`

`force: true` skips the sufficient-funds check so the reversal always completes, leaving the receiver **negative**
(`negative_since` is set). Use it for fraud/error recovery where you accept the loss risk. Normal use should be unforced.

## Scheduled payments

Charging back a scheduled transaction reverses every installment already processed. To reverse a single installment,
pass its root transfer: `Transfer::where('installment_id', $installment->id)->whereNull('parent_id')->first()`.

## Finding reversals

```php
$tx->chargebacks;            // reversal transactions
$reversal->chargebackOf;     // original
$transfer->isChargedBack();  // bool
$transfer->chargeback;       // the mirror transfer
```
