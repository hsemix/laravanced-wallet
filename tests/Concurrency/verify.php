<?php

$app = require __DIR__.'/boot.php';

use Hsemix\AdvancedWallet\Facades\Wallet;
use Hsemix\AdvancedWallet\Models\Account;
use Hsemix\AdvancedWallet\Models\Transaction;
use Hsemix\AdvancedWallet\Models\Transfer;
use Hsemix\AdvancedWallet\Support\Money;

$drift = 0;
$sum = '0';
$neg = [];
foreach (Account::with('accountType')->get() as $a) {
    $c = Wallet::accounts()->balance($a)->balance;
    $l = Wallet::accounts()->ledgerBalance($a);
    if (Money::comp($c, $l) !== 0) {
        $drift++;
        echo "DRIFT acct {$a->id}: cached $c ledger $l\n";
    }
    $sum = Money::add($sum, $c);
    if ($a->accountType->kind->value === 'user' && Money::comp($c, '0') < 0) {
        $neg[] = "{$a->id}=$c";
    }
}
echo "accounts drifted: $drift\nsum of all balances (must be 0): $sum\nnegative user accounts: ".(count($neg) ? implode(',', $neg) : 'none')."\n";
foreach (['capped' => 'amount', 'counted' => 'count'] as $name => $what) {
    $type = Wallet::transferTypes()->resolve("wallet.$name");
    $q = Transfer::where('transfer_type_id', $type->id)->whereNull('parent_id');
    echo "booked on wallet.$name today: ".$q->count().' transfers, '.Money::round((string) $q->sum('amount'))
        .' total (limit: '.($what === 'amount' ? Money::round($type->max_amount_per_day) : $type->max_transfers_per_day.' transfers').")\n";
}
echo 'transactions: '.Transaction::count().' transfers: '.Transfer::count()."\n";
foreach (Account::whereHas('accountType', fn ($q) => $q->where('internal_name', 'wallet'))->get() as $a) {
    echo "  acct {$a->id}: ".Wallet::accounts()->balance($a)->balance."\n";
}
