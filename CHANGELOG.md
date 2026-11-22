# Changelog

All notable changes to `hsemix/laravanced-wallet` will be documented in this file.

## [Unreleased]

### Added

- **Account fees**: fees charged to the account itself, either once when it opens or on a recurring schedule (every N days, weeks, months or years). Fixed amounts, or a percentage of the balance with `min_amount` / `max_amount` bounds. Each fee chooses what happens when the account can't pay: `skip`, `retry` or `allow_negative`. Accounts can be exempted with `waive_fees` or `Wallet::accountFees()->exempt()`. Recurring fees are collected by `wallet:process-scheduled`; every period is charged exactly once and missed runs are caught up. New `Wallet::accountFees()`, `AccountFeeCharged` and `AccountFeeSkipped` events, and the `wallet_account_fees` and `wallet_account_fee_charges` tables.
- **Payment requests**: a payee asks, the payer (or someone acting for them) accepts or rejects, locked to transfer types with `allows_payment_requests`. Decisions record who acted and why. Permissions are a pluggable callback, `Wallet::authorizeRequestsUsing()`; the package defines no roles. Optional reservation of the payer's funds (`reserve_on_payment_request`) and a cap of open requests per payer (`max_open_requests_per_payer`). Expired requests are closed by `wallet:process-scheduled`.
- **Chargeback rules** per transfer type: `allows_chargebacks`, `allows_forced_chargebacks` and a time window (`max_chargeback_time_amount` / `max_chargeback_time_field`), plus `Wallet::authorizeChargebacksUsing()` to decide who may charge back. All optional; defaults keep the previous behaviour.
- **Fee roles `source_owner` and `destination_owner`**: a fee can now pay, or be paid by, the same owner's account of a different account type. This is what agent commissions need: the bank pays a commission on a cash-in or cash-out into the agent's own commission account, while the customer pays nothing. A missing or inactive account refuses the payment, so a commission is never silently dropped. The existing roles (`source`, `destination`, `system`, `fixed_account`) are unchanged, and no migration is needed.
- **Multi-tenancy documentation and tests** for the supported model, one database per tenant: how to wire the connection, create a tenant's ledger, per-tenant settings, permission callbacks in long-lived processes, and per-tenant scheduling. Includes a two-tenant test suite and a tenant mode for the MySQL concurrency harness. A shared database with a tenant column is not supported.
- **`Wallet::onConnection($name, fn)`** runs a callback with the wallet on another connection (e.g. a tenant's). It purges the named connection first (Laravel caches open connections by name), then restores the previous `wallet.connection` in a `finally`, so a worker or scheduler loop can't carry one tenant into the next job. It nests, and refuses to switch inside an open transaction.
- **`wallet.run_migrations`** (default `true`). Set it to `false` so `php artisan migrate` on a central database does not create the wallet tables; migrate each tenant's database instead. The multi-tenancy guide also covers queued listeners and jobs under a tenancy package.
- A documentation website built with Astro Starlight from `docs/`, published to GitHub Pages.
- A concurrency test harness for MySQL (`tests/Concurrency`), with a `check.php` runner that asserts every scenario, in default and per-tenant connection mode, and a `concurrency` CI workflow that runs it.

### Changed

- The service provider now uses `spatie/laravel-package-tools`. Publish tags and migration file names are unchanged.
- All event classes are now `final`.
- Larastan (level 5), Pint, strict random-order PHPUnit settings and the Spatie package-skeleton GitHub workflows are in place.

### Fixed

- **Day / week / month limits could be exceeded by concurrent payments.** Two simultaneous payments of 70 against a daily limit of 100 both succeeded, because the usage was read before anything serialized payments from the same account. The payer's balance row is now locked before the usage is read, and the usage is read with locking reads (rows fetched and totalled in PHP, since PostgreSQL does not allow `FOR UPDATE` with an aggregate) so it is current even under MySQL's REPEATABLE READ. Limits are now exact: the same scenario books one payment, verified on MySQL and PostgreSQL. This covers the amount and the transfer-count limits, and every path that checks limits (payments, payment requests, scheduled installments). Transfer types without period limits are unaffected.
- **`reconcile()` released the holds of open payment requests.** It rebuilt an account's `reserved` total from pending and scheduled transactions only, so repairing an account (`wallet:reconcile --fix`, or calling `reconcile()` directly) silently dropped the funds an open request had reserved, and the payer could spend them. Both sources of holds are now counted, via the new `AccountService::ledgerReserved()`.
- **`wallet:reconcile` only checked balances.** It now also compares each account's cached `reserved` with its open holds, reports drift in either, and repairs both with `--fix`.
- **Transactions and row locks were opened on the wrong connection when `wallet.connection` differed from the default.** Every service opened its transaction with `DB::transaction()` (the application's default connection) while the models used `wallet.connection`. With a separate wallet or per-tenant database, wallet writes were therefore not atomic, a failed payment could leave rows behind, and the `FOR UPDATE` locks released as soon as each query finished, silently disabling the protection against overspending, double actions and exceeded limits. On MySQL, 10 workers draining a balance of 100 got 6 payments through instead of 3 and left 7 accounts out of sync with the ledger. Transactions are now opened on the wallet's connection (`WalletDatabase::transaction()`). Installs that use the default connection are unaffected.

### Removed

- **Laravel 10 support.** The package now requires Laravel 11, 12 or 13 (`illuminate/* ^11.0||^12.0||^13.0`).

### Upgrading

Run `php artisan migrate`: new migrations add `wallet_payment_requests`, reservation and chargeback-rule columns on existing tables, `wallet_account_fees`, `wallet_account_fee_charges` and `accounts.fees_waived`.
