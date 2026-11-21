<?php

$app = require __DIR__.'/boot.php';

use App\Models\User;
use Hsemix\AdvancedWallet\Facades\Wallet;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

if (getenv('WALLET_TENANT_DB')) {
    Schema::connection('tenant')->dropAllTables();   // migrate:fresh only wipes the default database
}
Artisan::call('migrate:fresh', ['--force' => true]);
$a = Wallet::accounts();
$a->createAccountType(['name' => 'Wallet', 'internal_name' => 'wallet', 'kind' => 'user', 'generate_account_number' => true, 'account_number_prefix' => '10']);
$a->createAccountType(['name' => 'Treasury', 'internal_name' => 'treasury', 'kind' => 'system', 'system_limit_type' => 'unlimited']);
$a->createAccountType(['name' => 'Fees', 'internal_name' => 'fees', 'kind' => 'system', 'system_limit_type' => 'limited']);
$t = Wallet::transferTypes();
$t->create(['name' => 'Deposit', 'internal_name' => 'deposit', 'from_account_type' => 'treasury', 'to_account_type' => 'wallet']);
$t->create(['name' => 'Transfer', 'internal_name' => 'transfer', 'from_account_type' => 'wallet', 'to_account_type' => 'wallet']);
$t->create(['name' => 'Fee', 'internal_name' => 'fee', 'kind' => 'generated', 'from_account_type' => 'wallet', 'to_account_type' => 'fees']);
Wallet::fees()->create(['name' => 'Fee', 'internal_name' => 'pct', 'transfer_type' => 'wallet.transfer', 'generated_transfer_type' => 'wallet.fee', 'charge_mode' => 'percentage', 'amount' => 1]);
foreach (range(1, 6) as $i) {
    $u = User::create(['name' => "U$i", 'email' => "u$i@x.test", 'password' => 'x']);
    $acc = $u->openAccount('wallet');
    Wallet::pay(['from' => 'system', 'to' => $u, 'type' => 'treasury.deposit', 'amount' => $i === 1 ? 100 : 1000]);
}
$t->create(['name' => 'Capped', 'internal_name' => 'capped', 'from_account_type' => 'wallet', 'to_account_type' => 'wallet', 'max_amount_per_day' => 100]);
$t->create(['name' => 'Counted', 'internal_name' => 'counted', 'from_account_type' => 'wallet', 'to_account_type' => 'wallet', 'max_transfers_per_day' => 3]);
$t->create(['name' => 'Approved', 'internal_name' => 'approved', 'from_account_type' => 'wallet', 'to_account_type' => 'wallet', 'requires_authorization' => true]);
$u = User::orderBy('id')->get();
Wallet::pay(['from' => $u[2], 'to' => $u[3], 'type' => 'wallet.transfer', 'amount' => '50', 'description' => 'CB-TARGET']);
Wallet::pay(['from' => $u[4], 'to' => $u[5], 'type' => 'wallet.approved', 'amount' => '200', 'description' => 'AUTH-TARGET']);
Wallet::transferTypes()->resolve('wallet.transfer')->update(['allows_payment_requests' => true]);
Wallet::requests()->create(['from' => $u[2], 'to' => $u[3], 'type' => 'wallet.transfer', 'amount' => '20', 'description' => 'REQ-TARGET', 'by' => $u[3]]);
echo "ok\n";
