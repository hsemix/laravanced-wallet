# Payment requests

A payment request is the payee asking the payer for money. Nothing moves when it is created. If the payer
**accepts**, a normal payment is performed (fees, limits, balance checks and authorization all apply). The payer can
**reject** it, the requester can **cancel** it, and it can **expire**.

The transfer type must have `allows_payment_requests => true`.

```php
use Hsemix\AdvancedWallet\Facades\Wallet;

Wallet::transferTypes()->create([... 'internal_name' => 'transfer', 'allows_payment_requests' => true]);

// Bob asks Ann for 40. `from` is the PAYER, `to` is the PAYEE (the requester) — same shape as a payment.
$request = Wallet::requests()->create([
    'from' => $ann, 'to' => $bob, 'type' => 'wallet.transfer', 'amount' => 40,
    'description' => 'Lunch', 'by' => $bob,
    'expires_at' => now()->addDays(7),      // optional
]);

Wallet::requests()->accept($request, by: $ann);              // performs the payment
Wallet::requests()->reject($request, by: $ann, reason: '…');
Wallet::requests()->cancel($request, by: $bob);              // the requester withdraws it
```

`$request` is a `Models\MoneyRequest` (status `requested`, `accepted`, `rejected`, `cancelled`, `expired`). After
`accept()`, `$request->transaction` is the payment.

## Accepting

- The payment is made with the request's `reference` as its [idempotency key](payments.md), and the request row is
  locked, so two simultaneous accepts pay once; the loser gets `InvalidTransactionException`.
- If the payment can't be made (`InsufficientBalanceException`, a limit, …) the exception propagates and the request
  **stays open**, so the payer can retry after topping up.
- If the transfer type `requires_authorization`, the request becomes `accepted` and the payment is `pending`
  (see [Authorization](authorization.md)).
- An expired request can't be accepted.

## Reserving the payer's money (opt-in)

By default a request holds nothing: the payer hasn't agreed to it, and a payee could otherwise freeze someone else's
balance. For cases like invoices you can opt a transfer type in with `reserve_on_payment_request => true`. Creating
a request then holds the payer's full debit (amount **plus fees**) in `reserved`, so it can't be spent elsewhere:

- The hold is released on reject, cancel and expiry. On accept it is released and the payment takes over: spent
  immediately, or re-held by the pending payment if the type `requires_authorization` (never held twice).
- If the payer can't afford it, or a transfer limit would be hit, `create()` fails and nothing is held.
- If accepting fails (e.g. a limit changed), everything rolls back and the hold stays.
- **Safeguard 1:** creating a reserving request needs the authorizer's explicit `'reserve'` approval. The **default
  authorizer never approves it**, so a flagged type throws `UnauthorizedRequestActionException` until you opt in:

```php
Wallet::authorizeRequestsUsing(function ($actor, MoneyRequest $request, string $action): bool {
    if ($action === 'reserve') {
        return $actor !== null && $actor->can('issue-invoices');   // who may freeze a payer's money
    }
    // ... your accept / reject / cancel rules
});
```

- **Safeguard 2:** set `wallet.max_open_requests_per_payer` (default `null` = unlimited) to cap how many open
  requests one payer account can have. It applies to all requests, reserving or not.

## Expiry

`expires_at` is optional. `wallet:process-scheduled` calls `Wallet::requests()->expireDue()`, which marks overdue
requests `expired` and fires `PaymentRequestExpired`. Accepting checks the date itself, so a request can't be
accepted late even if the sweep hasn't run.

## Who may act (and admins)

The package has **no roles or permissions**. It asks one question through a callback:

| Action | Default: allowed when the actor is… |
|---|---|
| `accept`, `reject` | the owner of the payer account |
| `reserve` | nobody (see above) |
| `cancel` | the owner of the payee account, or the actor who created the request |

A missing actor (`null`) is denied by default. Anything else throws `UnauthorizedRequestActionException`.

To let admins act on someone's behalf, replace the check from your app (e.g. in a service provider's `boot()`).
You decide what "admin" means:

```php
Wallet::authorizeRequestsUsing(function ($actor, MoneyRequest $request, string $action): bool {
    if ($actor === null) return false;
    if ($actor->can('wallet-admin')) return true;                         // your gate / role package
    return $action === 'cancel'
        ? $request->toAccount->owner->is($actor)
        : $request->fromAccount->owner->is($actor);
});
// Wallet::authorizeRequestsUsing(null) restores the default.
```

The callback is plain PHP, so it works with Gates, policies, Spatie permissions, or anything else. It is not
serialisable, so register it at runtime (not in `config/wallet.php`).

### Audit

Every decision records `decided_by` (morph), `decided_at`, `decision_reason`, and `decided_by_owner`: `true` when
the actor owns the account they acted for, `false` when someone acted on their behalf (an admin). The resulting
payment's `by` is the acting user too.

## Events

`PaymentRequested`, `PaymentRequestAccepted`, `PaymentRequestRejected`, `PaymentRequestCancelled`,
`PaymentRequestExpired` — each carries `->request`. Use them to notify the payer or requester.
