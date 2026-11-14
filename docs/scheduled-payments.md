# Scheduled payments

Split a payment into **installments** paid on a schedule: a one-off future payment (1 installment), a payment plan, a
recurring subscription, or a loan with interest.

The transfer type must have `allows_scheduled_payments => true`.

```php
$tx = Wallet::scheduled()->schedule([
    'from' => $ann,
    'to' => $bob,
    'type' => 'wallet.plan',
    'amount' => 900,                  // TOTAL principal, split across installments
    'installments' => 3,
    'immediate' => false,             // default true
    'first_date' => '2026-02-01',     // required when immediate = false; not in the past
    'frequency_rate' => 1,            // every N units (default 1)
    'frequency_unit' => 'month',      // day | week | month | year (default month)
    'interest_rate' => 8,             // percent; only for types with allows_interest
    'description' => 'Plan',
    'by' => $ann,
]);
```

Returns the `Transaction` (status `open`) with its `installments`.

## Schedule rules

| Input | Rule |
|---|---|
| `installments` | whole number ≥ 1, ≤ the type's `max_installments` |
| `immediate` | `true` (default): first installment is paid **right now**, inside the call; if it fails the whole call fails. `false`: first due on `first_date`. |
| frequency | Without `allows_recurring_payments` only `month` is accepted. Due dates step from the first date; months use no-overflow (Jan 31 → Feb 28/29). |
| `interest_rate` | Required if the type `allows_interest` and the type has no default `interest_rate`. |
| description | Required if the type `requires_description`. |

Errors: `InvalidTransferTypeException` (type doesn't allow scheduling), `InvalidParameterException`,
`MissingParameterException`, `InvalidAccountException`.

## Installment amounts

Rounded to 4 decimals, with the **last installment absorbing the remainder** so the principal repays exactly
(100 over 3 → 33.3333, 33.3333, 33.3334).

**No interest:** equal parts of the principal.

**Flat interest** (`interest_is_annual = false`): total interest = `principal × rate%`, spread evenly.
1000 at 8% over 4 → each installment 270 (250 principal + 20 interest); total 1080.

**Annual interest** (`interest_is_annual = true`): `rate` is a yearly percentage, amortised on the declining balance.
Per-period rate = `rate% ÷ (periods per year ÷ frequency_rate)` (month = 12, week = 52, day = 365, year = 1).
Payment = `P·r·(1+r)ⁿ ÷ ((1+r)ⁿ − 1)`. 1000 at 12% over 12 months → about 88.85 per month, 10.00 interest in the
first, declining after; the final balance is exactly 0.

Each installment row stores `amount`, `principal`, `interest`, `balance` (principal outstanding afterwards), `due_date`,
`status`. The total including interest is in `$tx->meta['total_with_interest']`.

## Limits

The transfer type's [limits](transfer-types.md#limits-per-paying-account-per-transfer-type) apply to every installment, the same as to an ordinary payment of that amount.

**When you schedule** (permanent problems are refused immediately with `LimitExceededException`):

- an installment amount below `min_amount` or above `max_amount`;
- an installment larger than a whole `max_amount_per_day` / `_week` / `_month` cap, which could never fit.

**When an installment is processed** (temporary problems are *deferred*):

- if today's/this week's/this month's amount or count limit is already used up, the installment is **not** failed. It stays
  `scheduled`, nothing is booked, and a later `processDue()` run pays it once the period rolls over. The run reports it under `deferred`.
- limits count everything of that transfer type from the payer, including ordinary payments, so a plan competes with the payer's other payments.
- the immediate first installment is checked too: if it would breach a limit the whole `schedule()` call throws
  `PeriodLimitExceededException` and no schedule is created.

A deferred installment keeps the schedule `open`; watch for installments that stay `scheduled` long past their due date
(`Installment::where('status', 'scheduled')->whereDate('due_date', '<', now()->subDays(7))`).

## Reserving funds

With `reserve_total_amount_on_scheduled_payments => true` the **entire schedule total** is held on the payer at creation
(`InsufficientBalanceException` if they can't cover it). Each installment releases its share as it is processed or fails, and
anything left is released on cancel or close. Without the flag nothing is held; each installment needs funds on its due date.

## Processing

Run daily (see [Commands](events-and-commands.md#scheduler)):

```php
Wallet::scheduled()->processDue();               // installments due today or earlier
Wallet::scheduled()->processDue('2026-02-15');   // as of a date
// ['processed' => 2, 'failed' => 1, 'deferred' => 0]
```

- Processes every `scheduled` installment with `due_date <= date` on `open` schedules, oldest first, so **missed days are caught up**.
- Each installment runs in its own database transaction. Success books the installment amount (plus the type's fees)
  and fires `InstallmentProcessed`. Insufficient funds, or a per-payment limit that can no longer be met (e.g. the type's
  limits were tightened after scheduling), marks **only that installment** `failed` with a `failure_reason` and fires
  `InstallmentFailed`; others continue. A used-up day/week/month limit **defers** it instead (see [Limits](#limits)).
- Failed installments are **not retried**.
- When no installments remain `scheduled`, the transaction becomes `closed`.
- Safe to run repeatedly: processed installments are never processed again.

## Cancelling

```php
Wallet::scheduled()->cancel($tx);
```

Remaining `scheduled` installments become `cancelled`, held funds are released, and the transaction becomes `cancelled`
(`PaymentCancelled`). Pass the cancelling model as the second argument (`cancel($tx, $user)`) to record it in `decided_by`. Already-paid installments stay paid (reverse them with a [chargeback](chargebacks.md)). Only an
`open` scheduled transaction can be cancelled (`InvalidTransactionException`).

## Reading a schedule

```php
$tx->installments;                                   // ordered by number
$tx->installments->where('status', InstallmentStatus::Failed);
$installment->transaction;
Transfer::where('installment_id', $installment->id)->get();   // the ledger entries it produced
```

Installment statuses: `scheduled`, `processed`, `failed`, `cancelled`.

## Limitations

- `requires_authorization` is not applied to scheduled payments.
- Failed installments are not retried automatically; re-schedule or pay manually.
- No idempotency key support on `schedule()`.
- Fees on the transfer type are charged on **each installment's** amount.
