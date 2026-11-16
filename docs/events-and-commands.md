# Events & commands

## Events

All implement `ShouldDispatchAfterCommit`: they fire only after the surrounding database transaction commits, so listeners
never see rolled-back payments. Namespace `Hsemix\AdvancedWallet\Events`.

| Event | Properties | Fired when |
|---|---|---|
| `PaymentPerformed` | `transaction` | A payment is booked (immediately, or on `authorize`) |
| `PaymentPending` | `transaction` | A payment is held for authorization |
| `PaymentDenied` | `transaction`, `reason` | `deny()` |
| `PaymentRequested` / `PaymentRequestAccepted` / `PaymentRequestRejected` / `PaymentRequestCancelled` / `PaymentRequestExpired` | `request` (a `MoneyRequest`) | `Wallet::requests()` create / accept / reject / cancel / `expireDue()` |
| `PaymentCancelled` | `transaction` | `cancel()` on a pending payment, or cancelling a scheduled payment |
| `PaymentChargedBack` | `original`, `chargeback` | `chargeback()` |
| `InstallmentProcessed` | `installment` | An installment was paid (including the immediate first one) |
| `InstallmentFailed` | `installment` | An installment failed for lack of funds |
| `AccountFeeCharged` / `AccountFeeSkipped` | `charge` (an `AccountFeeCharge`) | An [account fee](account-fees.md) was collected / forgiven for lack of funds |

`PaymentPerformed` is **not** fired for installments (use `InstallmentProcessed`) or for chargebacks (`PaymentChargedBack`).

### Example: notifications

```php
Event::listen(PaymentPerformed::class, function (PaymentPerformed $e) {
    $type = $e->transaction->transferType;

    if ($type->notify_payment_received) {
        $e->transaction->toAccount->owner?->notify(new PaymentReceived($e->transaction));
    }
    if ($type->notify_payment_sender) {
        $e->transaction->fromAccount->owner?->notify(new PaymentSent($e->transaction));
    }
});
```

(`owner` is null for system accounts.)

## Commands

Registered when `config('wallet.commands')` is true (default).

### `wallet:process-scheduled`

```bash
php artisan wallet:process-scheduled [--date=YYYY-MM-DD]
```

Runs `Wallet::scheduled()->processDue()` and prints `Processed N installment(s), M failed, K deferred.` Then collects due [account fees](account-fees.md) (`Account fees: N charged, M skipped, K waived, P pending.`) and expires overdue [payment requests](payment-requests.md) (`Expired N payment request(s).`, always based on the current time, not `--date`). Always exits 0.

### `wallet:reconcile`

```bash
php artisan wallet:reconcile [--account=ID] [--fix]
```

Compares each account's cached balance with the ledger. Prints drifted accounts. Exit code 1 if any drifted
(and `--fix` not given). With `--fix`, rewrites drifted rows from the ledger and exits 0.

## Scheduler

```php
// routes/console.php (Laravel 11+) or app/Console/Kernel.php schedule()
Schedule::command('wallet:process-scheduled')->daily();
Schedule::command('wallet:reconcile')->dailyAt('03:00');   // non-zero exit on drift; hook into your alerting
```
