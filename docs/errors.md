# Errors

Every package exception extends `Hsemix\AdvancedWallet\Exceptions\WalletException` (a `RuntimeException`), so one
`catch` handles them all. Failed operations are atomic: nothing is left half-booked.

```php
try {
    $ann->payTo($bob, 'wallet.transfer', 40);
} catch (InsufficientBalanceException $e) {
    // tell the user
} catch (WalletException $e) {
    report($e);
}
```

| Exception | Thrown when |
|---|---|
| `InsufficientBalanceException` | Payer can't afford payment + fees (`balance − reserved − debit < −credit_limit`); also reserving funds, authorizing, and chargebacks the receiver can't cover |
| `LimitExceededException` | Below `min_amount`, above `max_amount`, an amount larger than a whole day/week/month cap, or a period limit used up |
| `PeriodLimitExceededException` | Subclass of the above: a day/week/month amount or count limit is used up **right now** (temporary). Catch it first to distinguish "retry later" from "never allowed". Scheduled installments are deferred on this. |
| `InvalidAccountException` | Account not found, wrong type for the transfer type, inactive, same account on both sides, missing system account, or a fee whose payer/receiver doesn't match the generated type |
| `InvalidAccountTypeException` | Unknown account type, bad `kind`, opening a user account on a system type |
| `InvalidTransferTypeException` | Unknown/ambiguous/disabled type, using a generated type as a payment, scheduling on a type that doesn't allow it, fees nested deeper than 5, invalid fee generated type, an account fee type that is generated, needs authorization or doesn't end at a system account |
| `ChargebackNotAllowedException` | Chargeback disabled for the type, window passed, or forced chargebacks not allowed |
| `UnauthorizedChargebackException` | The chargeback authorizer refused the actor (HTTP 403) |
| `UnauthorizedRequestActionException` | The actor isn't allowed to accept / reject / cancel a payment request (see [Payment requests](payment-requests.md)) |
| `InvalidTransactionException` | Authorize/deny/cancel on a non-pending transaction; chargeback with nothing to reverse, of a chargeback, or of a fee transfer; cancelling a non-open schedule; acting on a payment request that is no longer open or has expired |
| `InvalidParameterException` | Bad numbers/ranges, amount ≤ 0, deducted fees ≥ payment, bad schedule input, idempotency key > 100 chars |
| `MissingParameterException` | A required field (or `description` when required) is missing |
| `DuplicateRecordException` | Duplicate account type, transfer type, fee name, account, or number prefix |
| `IdempotencyConflictException` | An idempotency key reused for a different payment |
| `ImmutableRecordException` | Updating or deleting a transfer through Eloquent |

Other things that can surface: Laravel's `ModelNotFoundException` (e.g. `scheduled()->cancel($unknownId)`) and a database
`QueryException` for genuine database failures.

## Mapping to HTTP

```php
// app/Exceptions/Handler.php or bootstrap/app.php
$exceptions->render(function (WalletException $e) {
    return response()->json(['error' => class_basename($e), 'message' => $e->getMessage()], 422);
});
```
