<?php

[, $scenario, $workers, $iters] = $argv;
$procs = [];
for ($w = 0; $w < (int) $workers; $w++) {
    $p = proc_open(['php', __DIR__.'/worker.php', $scenario, (string) $w, $iters], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $procs[] = [$p, $pipes];
}
$tot = ['ok' => 0, 'insufficient' => 0, 'replay' => 0, 'limit' => 0, 'deadlock' => 0, 'other' => 0, 'msgs' => []];
foreach ($procs as [$p, $pipes]) {
    $o = stream_get_contents($pipes[1]);
    $e = stream_get_contents($pipes[2]);
    proc_close($p);
    $j = json_decode($o, true);
    if (! $j) {
        echo 'WORKER FAILED: '.substr($o.$e, 0, 400)."\n";

        continue;
    }
    foreach (['ok', 'insufficient', 'replay', 'limit', 'deadlock', 'other'] as $k) {
        $tot[$k] += $j[$k];
    }
    foreach ($j['otherMsgs'] as $m) {
        $tot['msgs'][$m] = true;
    }
}
echo json_encode($tot + ['msgs' => array_keys($tot['msgs'])], JSON_PRETTY_PRINT)."\n";
