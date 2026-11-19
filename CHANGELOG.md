# Changelog

All notable changes to `hsemix/laravanced-wallet` will be documented in this file.

## [Unreleased]

### Added

- **Account fees**: fees charged to the account itself, either once when it opens or on a recurring schedule (every N days, weeks, months or years). Fixed amounts, or a percentage of the balance with `min_amount` / `max_amount` bounds. Each fee chooses what happens when the account can't pay: `skip`, `retry` or `allow_negative`. Accounts can be exempted with `waive_fees` or `Wallet::accountFees()->exempt()`. Recurring fees are collected by `wallet:process-scheduled`; every period is charged exactly once and missed runs are caught up. New `Wallet::accountFees()`, `AccountFeeCharged` and `AccountFeeSkipped` events, and the `wallet_account_fees` and `wallet_account_fee_charges` tables.
- **Payment requests**: a payee asks, the payer (or someone acting for them) accepts or rejects, locked to transfer types with `allows_payment_requests`. Decisions record who acted and why. Permissions are a pluggable callback, `Wallet::authorizeRequestsUsing()`; the package defines no roles. Optional reservation of the payer's funds (`reserve_on_payment_request`) and a cap of open requests per payer (`max_open_requests_per_payer`). Expired requests are closed by `wallet:process-scheduled`.
- **Chargeback rules** per transfer type: `allows_chargebacks`, `allows_forced_chargebacks` and a time window (`max_chargeback_time_amount` / `max_chargeback_time_field`), plus `Wallet::authorizeChargebacksUsing()` to decide who may charge back. All optional; defaults keep the previous behaviour.
- **Fee roles `source_owner` and `destination_owner`**: a fee can now pay, or be paid by, the same owner's account of a different account type. This is what agent commissions need: the bank pays a commission on a cash-in or cash-out into the agent's own commission account, while the customer pays nothing. A missing or inactive account refuses the payment, so a commission is never silently dropped. The existing roles (`source`, `destination`, `system`, `fixed_account`) are unchanged, and no migration is needed.
- A documentation website built with Astro Starlight from `docs/`, published to GitHub Pages.
- A concurrency test harness for MySQL (`tests/Concurrency`).

### Changed

- The service provider now uses `spatie/laravel-package-tools`. Publish tags and migration file names are unchanged.
- All event classes are now `final`.
- Larastan (level 5), Pint, strict random-order PHPUnit settings and the Spatie package-skeleton GitHub workflows are in place.

### Fixed

- **Day / week / month limits could be exceeded by concurrent payments.** Two simultaneous payments of 70 against a daily limit of 100 both succeeded, because the usage was read before anything serialized payments from the same account. The payer's balance row is now locked before the usage is read, and the usage is read with locking reads (rows fetched and totalled in PHP, since PostgreSQL does not allow `FOR UPDATE` with an aggregate) so it is current even under MySQL's REPEATABLE READ. Limits are now exact: the same scenario books one payment, verified on MySQL and PostgreSQL. This covers the amount and the transfer-count limits, and every path that checks limits (payments, payment requests, scheduled installments). Transfer types without period limits are unaffected.

- **`reconcile()` released the holds of open payment requests.** It rebuilt an account's `reserved` total from pending and scheduled transactions only, so repairing an account (`wallet:reconcile --fix`, or calling `reconcile()` directly) silently dropped the funds an open request had reserved, and the payer could spend them. Both sources of holds are now counted, via the new `AccountService::ledgerReserved()`.
- **`wallet:reconcile` only checked balances.** It now also compares each account's cached `reserved` with its open holds, reports drift in either, and repairs both with `--fix`.

### Removed

- **Laravel 10 support.** The package now requires Laravel 11, 12 or 13 (`illuminate/* ^11.0||^12.0||^13.0`).

### Upgrading

Run `php artisan migrate`: new migrations add `wallet_payment_requests`, reservation and chargeback-rule columns on existing tables, `wallet_account_fees`, `wallet_account_fee_charges` and `accounts.fees_waived`.
