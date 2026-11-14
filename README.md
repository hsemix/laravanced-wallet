# Advanced Laravel Wallet

A double-entry, multi-account wallet for Laravel, ported from the `Yuga's private banking package` services
(a Cyclos-style model) to Eloquent. Transfers are the source of truth; balances are a locked,
reconcilable projection.

## Features

- **Account types** (user / system, unlimited or limited system accounts, generated account numbers) and **accounts** owned by any Eloquent model
- **Transfer types** with min/max, per day/week/month amount and count limits, description / authorization requirements
- **Transfer fees**: fixed or percentage, amount ranges, payer/receiver (source, destination, system, fixed account), "deduct from amount", fees on fees
- **Payments** with `preview()` that is guaranteed to match what `perform()` books
- **Authorization flow**: pending payments reserve funds; `authorize` / `deny` / `cancel`
- **Chargebacks** of a whole transaction or a single transfer (e.g. one installment), including fees; optional `force`
- **Scheduled & recurring payments** with installments, flat or annual (amortised) interest, optional full reservation
- **History**: paginated statement, counts and totals, ledger balance as of a date
- Events, `wallet:process-scheduled`, `wallet:reconcile`

## Testing

```bash
composer install && vendor/bin/phpunit
```
