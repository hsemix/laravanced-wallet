# Advanced Laravel Wallet

A double-entry, multi-account wallet for Laravel, ported from the `Yuga's private banking package` services
(a Cyclos-style model) to Eloquent. Transfers are the source of truth; balances are a locked,
reconcilable projection.

## Features

- **Account types** (user / system, unlimited or limited system accounts, generated account numbers) and **accounts** owned by any Eloquent model
- **Transfer types** with min/max, per day/week/month amount and count limits, description / authorization requirements
- **Transfer fees**: fixed or percentage, amount ranges, payer/receiver (source, destination, system, fixed account), "deduct from amount", fees on fees
- **Payments** with `preview()` that is guaranteed to match what `perform()` books
- **Authorization flow**: pending payments reserve funds; `authorize` / `deny` / `cancel`
- **Chargebacks** of a whole transaction or a single transfer (e.g. one installment), including fees; optional `force`
- **Scheduled & recurring payments** with installments, flat or annual (amortised) interest, optional full reservation
- **History**: paginated statement, counts and totals, ledger balance as of a date
- Events, `wallet:process-scheduled`, `wallet:reconcile`

## Install

```bash
composer require hsemix/laravanced-wallet
php artisan migrate
php artisan vendor:publish --tag=wallet-config   # optional
```

Requires `ext-bcmath`. Money is `decimal(20,4)` and handled as strings, never floats.

## Usage

```php
use Hsemix\AdvancedWallet\Facades\Wallet;
use Hsemix\AdvancedWallet\Concerns\HasWallet;

// Add the trait to any model that owns accounts
class User extends Model 
{ 
    use HasWallet; 
}

// 1. Configure the ledger (once, e.g. in a seeder)
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

Wallet::fees()->create([
    'name' => 'Transfer fee', 
    'internal_name' => 'transfer_fee',
    'transfer_type' => 'wallet.transfer', 'generated_transfer_type' => 'wallet.fee',
    'charge_mode' => 'percentage', 
    'amount' => 1.5, 
    'payer' => 'source', 
    'receiver' => 'system',
]);

// 2. Use it
$ann->openAccount('wallet');
Wallet::pay([
    'from' => 'system', 
    'to' => $ann, 
    'type' => 'treasury.deposit', 
    'amount' => 100
]);

$ann->payTo(
    $bob, 
    'wallet.transfer', 
    40, 
    'rent'
);          // Transaction

Wallet::preview([
    'from' => $ann, 
    'to' => $bob, 
    'type' => 'wallet.transfer', 
    'amount' => 40
]);

$ann->walletBalance('wallet'); // balance / reserved / available

Wallet::payments()->authorize($tx);  // ->deny($tx, $by, 'reason') / ->cancel($tx)
Wallet::payments()->chargeback($tx); // or a root Transfer; ->chargeback($tx, force: true)

// Scheduled: `amount` is the total principal, split across installments
Wallet::scheduled()->schedule([
    'from' => $ann, 
    'to' => $bob, 
    'type' => 'wallet.plan', 
    'amount' => 900, 
    'installments' => 3,
    'immediate' => false, 
    'first_date' => '2026-02-01', 
    'frequency_unit' => 'month',
]);
```

Payment types are addressed as `"account_type.transfer_type"`. `from` / `to` accept an `Account`,
an owner model, `'system'`, or an account id.

## Testing

```bash
composer install && vendor/bin/phpunit
```
