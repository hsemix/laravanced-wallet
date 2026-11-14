# Authorization (pending payments)

Set `requires_authorization => true` on a transfer type to hold its payments for approval.

```php
$tx = $ann->payTo($bob, 'wallet.wire', 70);      // status: pending, nothing booked yet
```

## What happens while pending

- No transfers exist yet; balances are unchanged.
- The money the payer **will** be debited (payment plus fees they pay) is **reserved** on their account, so it cannot
  be spent elsewhere. `balance` stays, `reserved` rises, `available` drops.
- The payment already counts toward the payer's period limits.
- `PaymentPending` fires.

## Deciding

```php
Wallet::payments()->authorize($tx);                    // books the transfers; status → successful
Wallet::payments()->deny($tx, $admin, 'suspicious');   // releases the hold; status → denied
Wallet::payments()->cancel($tx, $ann);                 // payer withdraws; status → cancelled
```

Each takes the deciding model as its second argument (`authorize($tx, $by)`, `deny($tx, $by, $reason)`, `cancel($tx, $by)`),
accepts a `Transaction` or its id, and throws `InvalidTransactionException` unless the transaction is currently
pending (so a decision can only be made once). All run atomically.

- **authorize** releases the hold, then books with the normal locked posting, so funds are re-checked at that moment
  (`InsufficientBalanceException` if the payer is short, e.g. a credit limit was lowered). Fees are re-planned from the
  **current** fee configuration. Fires `PaymentPerformed`.
- **deny** fires `PaymentDenied` (with the optional reason).
- **cancel** fires `PaymentCancelled`.

## Audit trail

Every decision records who made it, when and why, on the transaction:

| Field | Set by |
|---|---|
| `decided_at` | every decision |
| `decided_by` (morph: `decided_by_type` / `decided_by_id`) | the `$by` model passed to `authorize`, `deny` or `cancel` (null if you pass none) |
| `decision_reason` | the `$reason` passed to `deny` (trimmed to 500 characters) |

```php
Wallet::payments()->deny($tx, $admin, 'Looks like fraud');

$tx->fresh()->decidedBy;        // the $admin model
$tx->decision_reason;           // 'Looks like fraud'
$tx->decided_at;
Transaction::where('decided_by_id', $admin->id)->where('decided_by_type', $admin->getMorphClass())->get();   // everything an admin decided
```

`$by` is optional so system actions (an automatic expiry job, say) still work, but pass it for anything a person does:
the package cannot know who is logged in. Cancelling a scheduled payment records `$by` the same way.

## Building an approval queue

```php
use Hsemix\AdvancedWallet\Models\Transaction;
use Hsemix\AdvancedWallet\Enums\TransactionStatus;

Transaction::where('status', TransactionStatus::Pending)->with(['fromAccount.owner', 'toAccount.owner'])->get();
```

Authorization is single-step. Multi-level approval chains and per-approver permissions are not provided; enforce who may
call `authorize()` with your own policies.
