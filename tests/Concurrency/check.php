<?php

// Runs every scenario against the host app and asserts the expected results (see README.md). Exits 1 on any miss.
//
//   WALLET_APP_PATH  a Laravel app with the wallet installed and a User with HasWallet
//   DB_*             the scratch database (and WALLET_TENANT_DB for tenant mode)
//
// Usage: php tests/Concurrency/check.php
$scenarios = [
    // name => [workers, iterations, expected non-zero counters]
    'overspend' => [10, 3, ['ok' => 3, 'insufficient' => 27]],
    'crosspay' => [12, 40, ['ok' => 480]],
    'idem' => [12, 3, ['ok' => 36], 1],   // 4th element: exactly this many NEW transactions
    'limit' => [10, 1, ['ok' => 1, 'limit' => 9]],
    'limitcount' => [10, 1, ['ok' => 3, 'limit' => 7]],
    'chargeback' => [10, 1, ['ok' => 1, 'replay' => 9]],
    'decide' => [10, 1, ['ok' => 1, 'replay' => 9]],
    'accept' => [10, 1, ['ok' => 1, 'replay' => 9]],
];

$run = function (string $cmd): string {
    return (string) shell_exec('php '.__DIR__."/{$cmd} 2>&1");
};
$transactions = fn (string $verify): int => preg_match('/^transactions: (\d+)/m', $verify, $m) ? (int) $m[1] : -1;

$failed = 0;
foreach ($scenarios as $name => $spec) {
    [$workers, $iters, $expected] = $spec;
    $run('setup.php');
    $before = $transactions($run('verify.php'));
    $result = json_decode($run("run.php $name $workers $iters"), true);
    $verify = $run('verify.php');
    $problems = [];

    if (! is_array($result)) {
        $problems[] = 'run.php did not return JSON';
    } else {
        foreach (['ok', 'insufficient', 'replay', 'limit', 'deadlock', 'other'] as $k) {
            $want = $expected[$k] ?? 0;
            if (($result[$k] ?? null) !== $want) {
                $problems[] = "$k: got ".var_export($result[$k] ?? null, true).", expected $want";
            }
        }
    }
    if (! str_contains($verify, 'accounts drifted: 0')) {
        $problems[] = 'balances drifted from the ledger';
    }
    if (! preg_match('/sum of all balances \(must be 0\): -?0\.0+$/m', $verify)) {
        $problems[] = 'balances do not sum to 0';
    }
    if (! str_contains($verify, 'negative user accounts: none')) {
        $problems[] = 'an account went negative';
    }
    if (isset($spec[3]) && $transactions($verify) - $before !== $spec[3]) {
        $problems[] = 'expected '.$spec[3].' new transaction(s), got '.($transactions($verify) - $before);
    }

    echo ($problems ? 'FAIL ' : 'ok   ').str_pad($name, 11).json_encode(array_filter($result ?? [])).($problems ? '  '.implode('; ', $problems) : '')."\n";
    $failed += count($problems) > 0;
}

exit($failed ? 1 : 0);
