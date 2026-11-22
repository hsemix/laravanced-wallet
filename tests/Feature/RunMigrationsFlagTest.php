<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Tests\Feature;

use Hsemix\AdvancedWallet\WalletServiceProvider;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;

/** `wallet.run_migrations`: off keeps `php artisan migrate` from creating wallet tables on the central database. */
class RunMigrationsFlagTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [WalletServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);

        if ($this->name() === 'test_it_can_be_turned_off') {
            $app['config']->set('wallet.run_migrations', false);
        }
    }

    private function loaded(): bool
    {
        $dir = realpath(__DIR__.'/../../database/migrations');

        return in_array($dir, array_map('realpath', $this->app['migrator']->paths()), true);
    }

    public function test_it_runs_the_migrations_by_default(): void
    {
        $this->assertTrue($this->loaded());
    }

    public function test_it_can_be_turned_off(): void
    {
        $this->assertFalse($this->loaded());
        $this->artisan('migrate')->run();
        $this->assertFalse(Schema::hasTable('wallet_accounts'));
    }
}
