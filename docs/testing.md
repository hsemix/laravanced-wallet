# Testing

## In your application

The package migrations load automatically, so `RefreshDatabase` creates the wallet tables.

```php
use Hsemix\AdvancedWallet\Facades\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TransferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Wallet::accounts()->createAccountType([
            'name' => 'Wallet', 
            'internal_name' => 'wallet', 
            'kind' => 'user'
        ]);

        Wallet::accounts()->createAccountType([
            'name' => 'Treasury', 
            'internal_name' => 'treasury', 
            'kind' => 'system', 
            'system_limit_type' => 'unlimited'
        ]);

        Wallet::transferTypes()->create([
            'name' => 'Deposit', 
            'internal_name' => 'deposit', 
            'from_account_type' => 'treasury', 
            'to_account_type' => 'wallet'
        ]);

        Wallet::transferTypes()->create([
            'name' => 'Transfer', 
            'internal_name' => 'transfer', 
            'from_account_type' => 'wallet', 
            'to_account_type' => 'wallet'
        ]);
    }

    private function fundedUser(int $amount): User
    {
        $user = User::factory()->create();
        $user->openAccount('wallet');
        Wallet::pay([
            'from' => 'system', 
            'to' => $user, 
            'type' => 'treasury.deposit', 
            'amount' => $amount
        ]);

        return $user;
    }

    public function test_transfer(): void
    {
        $ann = $this->fundedUser(100);
        $bob = $this->fundedUser(0);

        $ann->payTo($bob, 'wallet.transfer', 40);

        $this->assertSame(0, bccomp('60', $ann->walletBalance('wallet')->balance, 4));
    }
}
```

Tips:

- **Compare money numerically**, e.g. `bccomp`, not with string equality: SQLite returns `45`, MySQL `45.0000`.
- **Time-dependent behaviour** (period limits, installments): `Carbon::setTestNow('2026-01-15 09:00')`, then
  `Wallet::scheduled()->processDue('2026-02-15')`.
- **Events:** `Event::fake([PaymentPerformed::class])` then `Event::assertDispatched(...)`; the package's own tests do exactly this.
- **Exceptions:** assert on `InsufficientBalanceException`, `LimitExceededException`, etc., or on `WalletException`.
- Run a smoke test against your production database engine at least once: the row locking only exists on MySQL/PostgreSQL.

## Running the package's own tests

```bash
composer install
composer test        # vendor/bin/phpunit (random order, warnings and risky tests fail the run)
composer analyse     # PHPStan + Larastan, level 5
composer format      # Laravel Pint
```

Uses Orchestra Testbench with in-memory SQLite. The suite covers payments, fees, limits, authorization, chargebacks,
scheduled payments and interest, accounts and history, idempotency and immutability. `tests/TestCase.php` has reusable
helpers (`bootstrapLedger()`, `funded()`, `assertMoney()`).

## Concurrency checks (MySQL)

The PHPUnit suite is single-process SQLite, so it can't exercise row locking. `tests/Concurrency/` has opt-in scripts that run parallel PHP workers against a disposable MySQL database (overspend, opposing transfers, shared idempotency key, double chargeback, authorize-vs-deny). See `tests/Concurrency/README.md`.
