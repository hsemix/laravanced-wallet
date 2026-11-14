# Getting started

## Requirements

PHP 8.2+, `ext-bcmath`, Laravel 10, 11, 12 or 13. MySQL, PostgreSQL or SQLite. Use MySQL/PostgreSQL in production: the
balance-safety guarantee relies on row locks (`SELECT … FOR UPDATE`), which SQLite does not provide (SQLite serialises writes instead).

## Install

```bash
composer require hsemix/laravanced-wallet
php artisan migrate
```

The service provider and the `Wallet` facade are auto-discovered. Optional publishing:

```bash
php artisan vendor:publish --tag=wallet-config       # config/wallet.php
php artisan vendor:publish --tag=wallet-migrations   # copy migrations into your app
```

Add the trait to every model that can own accounts (users, companies, …):

```php
use Hsemix\AdvancedWallet\Concerns\HasWallet;

class User extends Authenticatable
{
    use HasWallet;
}
```

## Configure a ledger (once)

Typically in a seeder. This creates a member wallet, a system treasury that funds deposits, and a system account that collects fees:

```php
use Hsemix\AdvancedWallet\Facades\Wallet;

// Account types
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

Wallet::accounts()->createAccountType([
    'name' => 'Fees',     
    'internal_name' => 'fees',     
    'kind' => 'system', 
    'system_limit_type' => 'limited'
]);

// Transfer types: what kinds of movement are allowed, and between which account types
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

Wallet::transferTypes()->create([
    'name' => 'Fee', 
    'internal_name' => 'fee', 
    'kind' => 'generated', 
    'from_account_type' => 'wallet', 
    'to_account_type' => 'fees'
]);

// A 1% fee on every wallet transfer, paid by the sender to the system
Wallet::fees()->create([
    'name' => 'Transfer fee', 
    'internal_name' => 'transfer_fee',
    'transfer_type' => 'wallet.transfer',
    'generated_transfer_type' => 'wallet.fee',
    'charge_mode' => 'percentage', 
    'amount' => 1,
]);
```

## First payment

```php
$ann->openAccount('wallet');
$bob->openAccount('wallet');

// Fund Ann from the system treasury
Wallet::pay([
    'from' => 'system', 
    'to' => $ann, 
    'type' => 'treasury.deposit', 
    'amount' => 100
]);

// Ann pays Bob 40 (+0.40 fee)
$tx = $ann->payTo(
    $bob, 
    'wallet.transfer', 
    40, 
    'rent'
);

$ann->walletBalance('wallet')->toArray();
// ['balance' => '59.6000', 'reserved' => '0.0000', 'available' => '59.6000']
```

Next: [Concepts](concepts.md).
