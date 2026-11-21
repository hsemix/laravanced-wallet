# Advanced Laravel Wallet — Documentation

A double-entry, multi-account wallet for Laravel. Money moves between **accounts** through **transfer types**,
optionally generating **fees**; every movement is an immutable **transfer** in a ledger.

| Guide | What it covers |
|---|---|
| [Getting started](getting-started.md) | Install, configure a ledger, make a first payment |
| [Concepts](concepts.md) | The model: account types, accounts, transfer types, transactions vs transfers, balances |
| [Account types & accounts](accounts.md) | Creating and finding accounts, system accounts, credit limits, account numbers |
| [Transfer types](transfer-types.md) | Every option: limits, authorization, scheduling, interest |
| [Fees](fees.md) | Fixed / percentage fees, ranges, who pays, deducted fees, fees on fees |
| [Account fees](account-fees.md) | Fees on the account itself: when it opens, or recurring (maintenance) |
| [Payments](payments.md) | `perform`, `preview`, addressing accounts, limits, idempotency |
| [Authorization](authorization.md) | Pending payments, `authorize` / `deny` / `cancel` |
| [Chargebacks](chargebacks.md) | Reversing a payment, one transfer, or forcing it |
| [Payment requests](payment-requests.md) | Payee asks payer; accept / reject / cancel / expire; who may act |
| [Scheduled payments](scheduled-payments.md) | Installments, recurring, interest, reservation, processing |
| [Balances & history](balances-and-history.md) | Cached vs ledger balance, as-of, reconcile, statements, totals |
| [Events & commands](events-and-commands.md) | Events to listen to, artisan commands, scheduler |
| [Multi-tenancy](multi-tenancy.md) | One database per tenant for SaaS products; settings, callbacks and scheduling per tenant |
| [Errors](errors.md) | Every exception and when it is thrown |
| [Reference](reference.md) | Config, models, tables, full API index, limitations |
| [Testing](testing.md) | Testing your app, running the package suite |

Start with [Getting started](getting-started.md), then [Concepts](concepts.md).
