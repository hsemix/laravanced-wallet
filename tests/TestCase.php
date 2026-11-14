<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Tests;

use Hsemix\AdvancedWallet\Concerns\HasWallet;
use Hsemix\AdvancedWallet\Facades\Wallet;
use Hsemix\AdvancedWallet\Models\Account;
use Hsemix\AdvancedWallet\Support\Money;
use Hsemix\AdvancedWallet\WalletServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

class TestUser extends Model
{
    use HasWallet;

    protected $table = 'users';

    protected $guarded = [];
}

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [WalletServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Wallet' => Wallet::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->artisan('migrate')->run();
    }

    /**
     * wallet (user)  <-> wallet           : wallet.transfer
     * treasury (system, unlimited) -> wallet : treasury.deposit
     * fees (system)                         : receives fee.* generated transfers
     */
    protected function bootstrapLedger(): void
    {
        $a = Wallet::accounts();
        $a->createAccountType(['name' => 'Wallet', 'internal_name' => 'wallet', 'kind' => 'user',
            'generate_account_number' => true, 'account_number_prefix' => '10']);
        $a->createAccountType(['name' => 'Treasury', 'internal_name' => 'treasury', 'kind' => 'system', 'system_limit_type' => 'unlimited']);
        $a->createAccountType(['name' => 'Fees', 'internal_name' => 'fees', 'kind' => 'system', 'system_limit_type' => 'limited']);

        $t = Wallet::transferTypes();
        $t->create(['name' => 'Deposit', 'internal_name' => 'deposit', 'from_account_type' => 'treasury', 'to_account_type' => 'wallet']);
        $t->create(['name' => 'Transfer', 'internal_name' => 'transfer', 'from_account_type' => 'wallet', 'to_account_type' => 'wallet']);
        $t->create(['name' => 'Fee', 'internal_name' => 'fee', 'kind' => 'generated', 'from_account_type' => 'wallet', 'to_account_type' => 'fees']);
    }

    protected function user(string $name = 'Ann'): TestUser
    {
        return TestUser::create(['name' => $name]);
    }

    /** Open a wallet for a user and fund it from the treasury. */
    protected function funded(string $name, string|int|float $amount = 0): array
    {
        $user = $this->user($name);
        $account = $user->openAccount('wallet');

        if ($amount) {
            Wallet::pay(['from' => 'system', 'to' => $user, 'type' => 'treasury.deposit', 'amount' => $amount]);
        }

        return [$user, $account->fresh()];
    }

    protected function assertMoney(string|int|float $expected, string|int|float $actual, string $message = ''): void
    {
        $this->assertSame(0, Money::comp($expected, $actual), $message ?: "Expected {$expected}, got {$actual}");
    }

    protected function bal(Account $account): string
    {
        return Wallet::accounts()->balance($account)->balance;
    }

    protected function feeAccount(): Account
    {
        return Wallet::accounts()->systemAccount('fees');
    }
}
