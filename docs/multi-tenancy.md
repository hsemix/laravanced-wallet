# Multi-tenancy (one database per tenant)

If you are building a SaaS where every customer ("tenant") has their own wallet ledger, the supported model is **one
database per tenant**. Each tenant gets a complete, separate copy of the wallet tables, so one tenant's accounts, transfers
and balances cannot reach another's. There is no tenant column to forget to filter on.

A **shared database with a `tenant_id` column is not supported.** The package has no tenant scoping. Don't point two
tenants at the same wallet tables.

## How it works

The wallet lives on the connection named by `config('wallet.connection')` (`null` means the application's default
connection). Every model, every migration and every transaction the package opens uses that connection, including the
`FOR UPDATE` row locks that make [concurrent payments safe](payments.md). Switch the connection and you have switched tenant.

There are two ways to wire that up, depending on how your tenancy tooling works:

| Your tenancy tooling... | Do this |
|---|---|
| keeps a named connection for the tenant (e.g. `tenant`) and changes its database | set `wallet.connection` to that name |
| swaps the application's **default** connection to the tenant | leave `wallet.connection` as `null` |

```php
// when the tenant becomes current (for the first way)
config(['wallet.connection' => 'tenant']);
```

Nothing is cached between tenants: the services are singletons, but they hold no data, and every setting is read from config
at the moment it is used.

## Creating a tenant's wallet

Run the package migrations on the tenant's database, then create its ledger like any other install
([getting started](getting-started.md)): account types, transfer types and fees are per tenant, because they are rows in that
tenant's database.

```php
config(['wallet.connection' => 'tenant']);                       // the tenant's connection, already pointing at its database
Artisan::call('migrate', ['--database' => 'tenant', '--force' => true]);

// then seed the tenant's ledger
Wallet::accounts()->createAccountType([...]);
Wallet::transferTypes()->create([...]);
```

Or publish the migrations (`php artisan vendor:publish --tag=wallet-migrations`) into the folder your tenancy package runs
for each tenant. The migrations read `wallet.connection` themselves, so make sure it is set (or null, for the second way)
while they run.

Two tenants may use the same internal names (`wallet`, `wallet.transfer`), the same account numbers and even the same
idempotency keys: they are in different databases.

## Settings per tenant

`currency`, `account_number_length` and `max_open_requests_per_payer` are read when they are used, so you can change them
for the current tenant:

```php
config([
    'wallet.currency' => $tenant->currency,
    'wallet.account_number_length' => 8,
]);
```

`table_prefix` is read the same way, but it must match the prefix the tenant's tables were **created** with.

## Permission callbacks

`Wallet::authorizeRequestsUsing()` and `Wallet::authorizeChargebacksUsing()` store your closure on a singleton service. In a
long-lived process (Octane, queue workers) it outlives the request. So:

- Register it **once**, e.g. in a service provider, not per request or per tenant.
- Look up the current tenant **inside** the closure, when it runs, not when you register it.

```php
Wallet::authorizeRequestsUsing(function ($actor, $request, $action) {
    return $actor !== null && TenantContext::current()->isAdmin($actor);   // resolved when called
});
```

Watch for arrow functions: `fn () => ... $tenant ...` captures `$tenant` **by value** when it is created, so it keeps the
tenant that was current at registration. Use `function () use (&$tenant)`, or better, read the tenant from a service as above.

## Scheduled work

`wallet:process-scheduled` (installments, [account fees](account-fees.md), expiring requests) and `wallet:reconcile` act on the
current connection only. Run them once per tenant:

```php
Schedule::call(function () {
    Tenant::all()->each(function ($tenant) {
        $tenant->makeCurrent();                                   // however your tenancy tooling does it
        Artisan::call('wallet:process-scheduled');
        Artisan::call('wallet:reconcile');
    });
})->daily();
```

Do the same for queued listeners of the wallet's [events](events-and-commands.md): the job must run with the right tenant
current, or the model it carries will be looked up in the wrong database.

## Owners

An account's owner is a polymorphic reference (`owner_type`, `owner_id`) with no foreign key, so your `User` model can live in
the tenant's own database or in a central one. If it is central, remember that an owner id only means something together with
the tenant whose wallet holds the account.

## What this does not cover

- **No cross-tenant payments.** A payment moves money between accounts in one database. Moving money between tenants is an
  application-level job (two payments, plus your own reconciliation).
- **One database, one currency path:** see [limitations](reference.md#limitations).
- **Hot system accounts.** A busy tenant's fee or commission account is locked by every payment that pays into it, so those
  payments queue. That is per tenant, so one tenant's volume does not slow another's.

## Verifying it

`tests/Feature/TenantDatabasesTest.php` runs two tenants side by side on separate connections: atomic payments, writes inside
a transaction on the tenant connection, no data leakage, same names and keys in both, per-tenant settings, and one authorizer
serving both. The MySQL concurrency harness has a tenant mode (`WALLET_TENANT_DB`, see `tests/Concurrency/README.md`) that
puts the wallet in a second database on its own connection and checks that row locking still holds.
