<?php

// usage: worker.php <scenario> <workerId> <iterations>
[, $scenario, $wid, $n] = $argv;
$n = (int) $n;
$app = require __DIR__.'/boot.php';

use App\Models\User;
use Hsemix\AdvancedWallet\Exceptions\InsufficientBalanceException;
use Hsemix\AdvancedWallet\Exceptions\InvalidTransactionException;
use Hsemix\AdvancedWallet\Facades\Wallet;
use Hsemix\AdvancedWallet\Models\MoneyRequest;
use Hsemix\AdvancedWallet\Models\Transaction;

$out = ['ok' => 0, 'insufficient' => 0, 'replay' => 0, 'deadlock' => 0, 'other' => 0, 'otherMsgs' => []];
$users = User::orderBy('id')->get();
usleep(200000); // let siblings boot
for ($i = 0; $i < $n; $i++) {
    try {
        switch ($scenario) {
            case 'overspend': // everyone drains U1 (balance 100) 30 at a time, to different payees
                $r = Wallet::pay(['from' => $users[0], 'to' => $users[1 + ($wid % 5)], 'type' => 'wallet.transfer', 'amount' => '25']);
                break;
            case 'crosspay': // opposing + overlapping transfers among U2..U6 to provoke deadlocks
                $from = $users[1 + (($wid + $i) % 5)];
                $to = $users[1 + (($wid + $i + 1 + ($i % 3)) % 5)];
                if ($from->is($to)) {
                    continue 2;
                }
                $r = Wallet::pay(['from' => $from, 'to' => $to, 'type' => 'wallet.transfer', 'amount' => '3.3333']);
                break;
            case 'chargeback':
                $tx = Transaction::where('description', 'CB-TARGET')->firstOrFail();
                Wallet::payments()->chargeback($tx);
                break;
            case 'decide': // half authorize, half deny the same pending transaction
                $tx = Transaction::where('description', 'AUTH-TARGET')->firstOrFail();
                $wid % 2 ? Wallet::payments()->authorize($tx) : Wallet::payments()->deny($tx, null, 'no');
                break;
            case 'accept': // every worker accepts the same request as the payer
                $req = MoneyRequest::where('description', 'REQ-TARGET')->firstOrFail();
                Wallet::requests()->accept($req, $users[2]);
                break;
            case 'idem': // same key from every worker
                $r = Wallet::pay(['from' => $users[2], 'to' => $users[3], 'type' => 'wallet.transfer', 'amount' => '10', 'idempotency_key' => 'same-key-1']);
                break;
        }
        $out['ok']++;
    } catch (InsufficientBalanceException $e) {
        $out['insufficient']++;
    } catch (InvalidTransactionException $e) {
        $out['replay']++;
    } catch (Throwable $e) {
        $m = $e->getMessage();
        if (stripos($m, 'deadlock') !== false) {
            $out['deadlock']++;
        } else {
            $out['other']++;
            $out['otherMsgs'][get_class($e).': '.substr($m, 0, 160)] = true;
        }
    }
}
$out['otherMsgs'] = array_keys($out['otherMsgs']);
echo json_encode($out);
