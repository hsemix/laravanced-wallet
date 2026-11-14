# Balances & history

## Balance

```php
$summary = Wallet::balance($account);              // or $user->walletBalance('wallet'), Wallet::accounts()->balance($account)
$summary->balance;     // string, money in the account
$summary->reserved;    // string, held for pending / reserved payments
$summary->available;   // string, balance − reserved
$summary->toArray();   // ['balance' => '59.6000', 'reserved' => '0.0000', 'available' => '59.6000']
```

This reads the cached balance row. It is fast and safe to call often.

## Ledger balance (source of truth), optionally as of a date

```php
$accounts = Wallet::accounts();
$accounts->ledgerBalance($account);                    // sum of all transfers in − out, now
$accounts->ledgerBalance($account, '2026-03-10');      // end of that day (date without time = 23:59:59)
$accounts->ledgerBalance($account, '2026-03-10 12:00'); // exact moment
$accounts->ledgerBalance($account, $carbonInstance);
```

Computed from transfers only, so it is unaffected by transfer-type `enabled` state or later configuration changes.

## Reconciling

```php
Wallet::accounts()->reconcile($account);   // rebuilds balance (from the ledger) and reserved (from open holds); returns BalanceSummary
```

Command form, see [Commands](events-and-commands.md): `php artisan wallet:reconcile [--account=ID] [--fix]`. Run it on
a schedule: any mismatch between cached and ledger balance means something wrote outside the package.

## History (statements)

```php
$history = Wallet::history();

$page = $history->history($account);                                   // newest first, 20 per page
$page = $history->history($account, '2026-03-01', '2026-03-31', perPage: 50, page: 2);

foreach ($page->items() as $transfer) {
    $transfer->direction;        // 'credit' (money in) | 'debit' (money out)
    $transfer->signed_amount;    // '25.0000' for money in, '-5.0000' for money out
    $transfer->transaction;      // loaded: transaction, transferType, fromAccount, toAccount
}
$page->total();                  // standard LengthAwarePaginator
```

History includes **every** movement touching the account: payments, fee transfers and chargebacks.

### Totals and counts

```php
$history->totalReceived($account, $from = null, $to = null);   // string
$history->totalSent($account, $from, $to);
$history->receivedCount($account, $from, $to);                 // int
$history->sentCount($account, $from, $to);
```

`$from` / `$to` are inclusive and accept date strings or Carbon. `$from` as a date means start of that day; a `$to` date
without a time means end of that day. These count all transfers, including fees.

## Negative accounts

`accounts.negative_since` is set the first time a balance drops below zero and cleared when it recovers:

```php
Account::whereNotNull('negative_since')->get();   // overdrawn accounts (credit limits, forced chargebacks, unlimited system accounts)
```
