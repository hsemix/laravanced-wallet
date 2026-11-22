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

### Switching tenants: use `Wallet::onConnection()`

If you re-point one named connection at a different database, **purge it**. Laravel caches the open connection by name, so
changing `database.connections.tenant.database` alone leaves the next query on the previous tenant's database. And if you
set `wallet.connection` yourself, nothing sets it back, so a queue worker or scheduler run carries one tenant into the next
job. `Wallet::onConnection()` does both for you: it purges the connection, points the wallet at it, runs your callback, and
restores the previous connection in a `finally`, even if the callback throws.

Point the connection at the tenant's database first, then enter the helper (it purges on the way in, so it picks that database up):

```php
config(['database.connections.tenant.database' => $tenant->database]);

$transaction = Wallet::onConnection('tenant', fn () => Wallet::pay([...]));
```

- It nests: an inner call restores what the outer one had set.
- It refuses (`LogicException`) while a transaction is open on either connection, because purging would drop it.
- It returns whatever your callback returns.
- Tenancy tools that swap the application's **default** connection don't need it; leave `wallet.connection` as `null`.
- If you give every tenant its own connection **name**, nothing is cached across tenants and a plain
  `config(['wallet.connection' => $name])` is enough, but the helper still gives you the restore.

Nothing is cached between tenants: the services are singletons, but they hold no data, and every setting is read from config
at the moment it is used.

## Creating a tenant's wallet

Run the package migrations on the tenant's database, then create its ledger like any other install
([getting started](getting-started.md)): account types, transfer types and fees are per tenant, because they are rows in that
tenant's database.

```php
config(['wallet.connection' => 'tenant']);                       // the tenant's connection, already pointing at its database
Artisan::call('migrate', ['--database' => 'tenant', '--force' => true]);   // see "Migrations" below if run_migrations is false

// then seed the tenant's ledger
Wallet::accounts()->createAccountType([...]);
Wallet::transferTypes()->create([...]);
```

The migrations read `wallet.connection` themselves, so make sure it is set (or null, for the second way) while they run.
Keep the wallet tables **off your central database**: see [Migrations](#migrations).

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
        config(['database.connections.tenant.database' => $tenant->database]);

        Wallet::onConnection('tenant', function () {          // purges, switches, and restores afterwards
            Artisan::call('wallet:process-scheduled');
            Artisan::call('wallet:reconcile');
        });
    });
})->daily();
```

Because the helper restores the connection, one tenant's run can never leak into the next, or into whatever the scheduler does
afterwards.

## Events, listeners and queued jobs

**After commit.** Every wallet event implements `ShouldDispatchAfterCommit`: it fires only once the transaction on the wallet's
connection has committed (`TenantDatabasesTest` asserts the transaction level is 0 inside the listener). A queued listener
can therefore never start before the payment exists. If you call `Wallet::pay()` inside a transaction
of your own, the events wait for the outermost commit, as they should.

**The worker must be on the right tenant.** A queued listener or job that carries a wallet model is serialised with the
model's *connection name* and id, and the worker looks it up again on whatever that connection points at *when the job runs*.
If your connection name is the same for every tenant (`tenant`), a worker that picks up tenant B's job while still on tenant
A's database will load the wrong row, or none. So make the tenant part of the job and set it before the job runs.

With a tenancy package, use its queue support rather than doing this by hand. For example
[stancl/tenancy](https://tenancyforlaravel.com) ships `QueueTenancyBootstrapper` (enable it in `config/tenancy.php`): it stamps
the current tenant on every job you dispatch and initialises that tenant before the job runs, and it works for queued listeners
too. [spatie/laravel-multitenancy](https://spatie.be/docs/laravel-multitenancy) does the same with the `TenantAware` interface
(or `queues_are_tenant_aware_by_default`). Check each package's current docs for the exact setting.

Without one, carry the tenant yourself and enter it with `Wallet::onConnection()`:

```php
class NotifyPayee implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $tenantDatabase, public int $transactionId) {}

    public function handle(): void
    {
        config(['database.connections.tenant.database' => $this->tenantDatabase]);

        Wallet::onConnection('tenant', function () {
            $tx = Transaction::findOrFail($this->transactionId);   // looked up on the right database
            // ...
        });
    }
}

// the listener, registered once, hands over the ids rather than the model
Event::listen(PaymentPerformed::class, fn (PaymentPerformed $e) => NotifyPayee::dispatch(
    Tenant::current()->database,
    $e->transaction->id,
));
```

Passing the id (not the model) means the job never depends on which database was current when it was serialised.

## Migrations

By default the package registers its migrations, so `php artisan migrate` creates the wallet tables on the application's
default database. With a database per tenant that is wrong: the central database should hold only your tenants, domains and
plans. Turn it off:

```php
// config/wallet.php
'run_migrations' => false,
```

Then migrate each tenant's database yourself. Either publish them
(`php artisan vendor:publish --tag=wallet-migrations`) into the folder your tenancy tooling migrates per tenant (e.g.
`database/migrations/tenant` for stancl/tenancy), or run them once per tenant with the connection pointed at that tenant:

```bash
php artisan migrate --database=tenant --path=vendor/hsemix/laravanced-wallet/database/migrations
```

The migrations read `wallet.connection`, so they create the tables on the wallet's connection. If you publish them, publish
once and keep them out of the central migrations folder.

## Owners

An account's owner is a polymorphic reference (`owner_type`, `owner_id`) with no foreign key, so your `User` model can live in
the tenant's own database or in a central one. If it is central, remember that an owner id only means something together with
the tenant whose wallet holds the account.

**A central owner needs a pinned connection.** When the wallet is on a tenant connection and your `User` is in the central
database, `$account->owner` (and `with('owner')`) is loaded on the *account's* connection unless the owner model names its
own. Laravel gives a polymorphic relation's model the parent's connection when it declares none, so the lookup would hit the
tenant database and fail with "table users not found". Give the central model `protected $connection = 'central';` (or
whatever your central connection is called).

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
