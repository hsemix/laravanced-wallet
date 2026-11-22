<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Tests\Feature;

use Hsemix\AdvancedWallet\Events\PaymentPerformed;
use Hsemix\AdvancedWallet\Exceptions\IdempotencyConflictException;
use Hsemix\AdvancedWallet\Exceptions\UnauthorizedRequestActionException;
use Hsemix\AdvancedWallet\Facades\Wallet;
use Hsemix\AdvancedWallet\Models\Account;
use Hsemix\AdvancedWallet\Models\AccountType;
use Hsemix\AdvancedWallet\Models\Transaction;
use Hsemix\AdvancedWallet\Models\Transfer;
use Hsemix\AdvancedWallet\Support\WalletDatabase;
use Hsemix\AdvancedWallet\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * One database per tenant: the host app points `wallet.connection` at the current tenant's database. Everything the
 * package does must then happen on THAT connection, including its transactions and row locks, and nothing may leak
 * from one tenant to the next.
 */
class TenantDatabasesTest extends TestCase
{
    /** @var array<string, bool> */
    private array $migrated = [];

    protected function tearDown(): void
    {
        Wallet::authorizeRequestsUsing(null);
        parent::tearDown();
    }

    /** Switch the wallet to a tenant's database, creating and migrating it the first time. */
    private function tenant(string $name): void
    {
        if (! isset($this->migrated[$name])) {
            config(["database.connections.{$name}" => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
            config(['wallet.connection' => $name]);
            $this->artisan('migrate', ['--database' => $name])->run();
            $this->bootstrapLedger();
            $this->migrated[$name] = true;
        }

        config(['wallet.connection' => $name]);
    }

    private function walletAccounts(): int
    {
        return Account::whereHas('accountType', fn ($q) => $q->where('internal_name', 'wallet'))->count();
    }

    public function test_a_payment_is_atomic_on_the_tenant_connection(): void
    {
        $this->tenant('tenant_a');
        [$ann] = $this->funded('Ann', 100);
        [$bob] = $this->funded('Bob');
        $before = Transaction::count();

        // Something fails after the payment has started writing.
        Event::listen('eloquent.created: '.Transfer::class, fn () => throw new \RuntimeException('boom'));

        try {
            $ann->payTo($bob, 'wallet.transfer', 40);
            $this->fail('the failure was swallowed');
        } catch (\RuntimeException) {
        }

        $this->assertSame($before, Transaction::count(), 'a half-finished payment was left behind');
    }

    public function test_every_write_happens_inside_a_transaction_on_the_tenant_connection(): void
    {
        $this->tenant('tenant_a');
        [$ann] = $this->funded('Ann', 100);
        [$bob] = $this->funded('Bob');

        $levels = [];
        Event::listen('eloquent.creating: '.Transfer::class, function () use (&$levels) {
            $levels[] = DB::connection('tenant_a')->transactionLevel();
        });

        $ann->payTo($bob, 'wallet.transfer', 40);

        $this->assertNotEmpty($levels);
        foreach ($levels as $level) {
            $this->assertGreaterThan(0, $level, 'a transfer was written outside a transaction, so its row locks would not hold');
        }
    }

    public function test_events_are_held_until_the_tenant_connection_has_committed(): void
    {
        $this->tenant('tenant_a');
        [$ann] = $this->funded('Ann', 100);
        [$bob] = $this->funded('Bob');
        $levels = [];
        // A queued listener could start the moment it is dispatched: it must find the payment already committed.
        Event::listen(PaymentPerformed::class, function () use (&$levels) {
            $levels[] = WalletDatabase::connection()->transactionLevel();
        });

        $ann->payTo($bob, 'wallet.transfer', 10);

        $this->assertSame([0], $levels);
    }

    /** @return array{0: string, 1: string} two sqlite files, each a migrated tenant ledger */
    private function tenantFiles(): array
    {
        $files = [];
        foreach (['one', 'two'] as $name) {
            $file = tempnam(sys_get_temp_dir(), "wallet-{$name}-");
            $files[] = $file;
            config(['database.connections.tenant' => ['driver' => 'sqlite', 'database' => $file, 'prefix' => '']]);
            Wallet::onConnection('tenant', function () {
                $this->artisan('migrate', ['--database' => 'tenant'])->run();
                $this->bootstrapLedger();
            });
        }
        $this->beforeApplicationDestroyed(function () use ($files) {
            DB::purge('tenant');
            array_map('unlink', $files);
        });

        return $files;
    }

    private function pointTenantAt(string $file): void
    {
        config(['database.connections.tenant.database' => $file]);
    }

    public function test_re_pointing_one_named_connection_leaks_without_a_purge_and_not_with_the_helper(): void
    {
        [$one, $two] = $this->tenantFiles();
        $db = fn () => DB::connection('tenant')->getDatabaseName();

        // The hazard: the open connection is cached by name, so changing the config alone changes nothing.
        $this->pointTenantAt($one);
        DB::purge('tenant');
        $this->assertSame($one, $db());
        $this->pointTenantAt($two);
        $this->assertSame($one, $db(), 'expected the cached connection to still be on the previous tenant');

        // The helper purges, so each switch really lands on the other database.
        $seen = [];
        foreach ([$two, $one, $two] as $file) {
            $this->pointTenantAt($file);
            $seen[] = Wallet::onConnection('tenant', fn () => $db());
        }
        $this->assertSame([$two, $one, $two], $seen);
    }

    public function test_on_connection_restores_the_previous_connection_even_when_the_callback_throws(): void
    {
        $this->tenantFiles();
        config(['wallet.connection' => 'testing']);

        try {
            Wallet::onConnection('tenant', function () {
                $this->assertSame('tenant', config('wallet.connection'));
                throw new \RuntimeException('boom');
            });
            $this->fail('the exception was swallowed');
        } catch (\RuntimeException) {
        }

        $this->assertSame('testing', config('wallet.connection'));
    }

    public function test_on_connection_nests_and_returns_the_callbacks_value(): void
    {
        $this->tenantFiles();
        config(['database.connections.other' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);

        $result = Wallet::onConnection('tenant', function () {
            $inner = Wallet::onConnection('other', fn () => config('wallet.connection'));

            return [$inner, config('wallet.connection')];
        });

        $this->assertSame(['other', 'tenant'], $result);
        $this->assertNull(config('wallet.connection'));
    }

    public function test_on_connection_refuses_to_switch_inside_a_transaction(): void
    {
        $this->tenantFiles();
        config(['wallet.connection' => 'testing']);

        $this->expectException(\LogicException::class);
        DB::connection('testing')->transaction(fn () => Wallet::onConnection('tenant', fn () => null));
    }

    public function test_tenants_do_not_see_each_others_data(): void
    {
        $this->tenant('tenant_a');
        [$ann, $annA] = $this->funded('Ann', 100);
        [$bob] = $this->funded('Bob');
        $ann->payTo($bob, 'wallet.transfer', 30);

        $this->tenant('tenant_b');
        $this->assertSame(0, $this->walletAccounts());   // tenant A's customers are not here
        $this->assertSame(0, Transaction::count());

        [$cat, $catB] = $this->funded('Cat', 500);
        $this->assertMoney(500, $this->bal($catB));

        $this->tenant('tenant_a');
        $this->assertSame(2, $this->walletAccounts());
        $this->assertMoney(70, $this->bal($annA->fresh()));

        // The rows really are in different databases.
        $this->assertSame(0, DB::connection('testing')->table('wallet_accounts')->count());
    }

    public function test_the_same_names_and_keys_can_exist_in_every_tenant(): void
    {
        foreach (['tenant_a', 'tenant_b'] as $name) {
            $this->tenant($name);
            $this->assertNotNull(AccountType::where('internal_name', 'wallet')->first());   // the same internal names

            [$ann] = $this->funded('Ann'.$name, 100);
            [$bob] = $this->funded('Bob'.$name);
            $tx = Wallet::pay(['from' => $ann, 'to' => $bob, 'type' => 'wallet.transfer', 'amount' => 10, 'idempotency_key' => 'order-1']);

            $this->assertTrue($tx->wasRecentlyCreated, "{$name}: the shared idempotency key was treated as a replay");
        }

        $this->tenant('tenant_a');
        $this->assertSame(1, Transaction::where('idempotency_key', 'order-1')->count());
    }

    public function test_a_key_reused_for_a_different_payment_is_still_caught_within_a_tenant(): void
    {
        $this->tenant('tenant_a');
        [$ann] = $this->funded('Ann', 100);
        [$bob] = $this->funded('Bob');
        Wallet::pay(['from' => $ann, 'to' => $bob, 'type' => 'wallet.transfer', 'amount' => 10, 'idempotency_key' => 'k']);

        $this->expectException(IdempotencyConflictException::class);
        Wallet::pay(['from' => $ann, 'to' => $bob, 'type' => 'wallet.transfer', 'amount' => 99, 'idempotency_key' => 'k']);
    }

    public function test_settings_can_differ_per_tenant(): void
    {
        $this->tenant('tenant_a');
        config(['wallet.currency' => 'UGX', 'wallet.account_number_length' => 8]);
        Wallet::accounts()->createAccountType(['name' => 'Local', 'internal_name' => 'local', 'kind' => 'user', 'generate_account_number' => true, 'account_number_prefix' => '7']);
        $a = $this->user('A')->openAccount('local');

        $this->tenant('tenant_b');
        config(['wallet.currency' => 'KES', 'wallet.account_number_length' => 4]);
        Wallet::accounts()->createAccountType(['name' => 'Local', 'internal_name' => 'local', 'kind' => 'user', 'generate_account_number' => true, 'account_number_prefix' => '7']);
        $b = $this->user('B')->openAccount('local');

        $this->assertSame('UGX', AccountType::on('tenant_a')->where('internal_name', 'local')->value('currency'));
        $this->assertSame('KES', AccountType::on('tenant_b')->where('internal_name', 'local')->value('currency'));
        $this->assertSame('700000001', $a->number);   // prefix + a counter padded to 8
        $this->assertSame('70001', $b->number);       // prefix + a counter padded to 4
    }

    public function test_one_authorizer_registered_once_can_serve_every_tenant(): void
    {
        $current = 'tenant_a';
        $admins = ['tenant_a' => 'Ann', 'tenant_b' => 'Bea'];
        // Registered once (e.g. in a service provider). It looks the tenant up when it runs, never when it is registered.
        // NB: not an arrow function: those capture $current by value, when the callback is created.
        Wallet::authorizeRequestsUsing(function ($actor) use (&$current, $admins) {
            return $actor !== null && $actor->name === $admins[$current];
        });

        foreach (['tenant_a' => ['Ann', 'Bea'], 'tenant_b' => ['Bea', 'Ann']] as $name => [$admin, $stranger]) {
            $this->tenant($name);
            $current = $name;
            Wallet::transferTypes()->resolve('wallet.transfer')->update(['allows_payment_requests' => true]);
            [$payer] = $this->funded($name.'-payer', 100);
            [$payee] = $this->funded($name.'-payee');
            $ask = fn () => Wallet::requests()->create(['from' => $payer, 'to' => $payee, 'type' => 'wallet.transfer', 'amount' => 10, 'by' => $payee]);

            try {
                Wallet::requests()->accept($ask(), $this->user($stranger));
                $this->fail("{$stranger} was allowed to act in {$name}");
            } catch (UnauthorizedRequestActionException) {
            }

            $this->assertNotNull(Wallet::requests()->accept($ask(), $this->user($admin)));   // this tenant's admin may
        }
    }
}
