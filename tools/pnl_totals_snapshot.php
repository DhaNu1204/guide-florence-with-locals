<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only (step 6.12): never deployed
/**
 * Step 6.12 - READ ONLY. Every field of the live P&L `data.totals` per range, one JSON line per
 * range, so two runs can be compared to the cent (`diff before.txt after.txt`).
 *
 *   php tools/pnl_totals_snapshot.php --host=https://withlocals.deetech.cc --token=<owner admin>
 *       [--ranges=2026-07-01:2026-07-31,2026-08-01:2026-08-31,...]
 *
 * GET requests only.
 */
$host = null; $token = null;
$ranges = ['2026-07-01:2026-07-31', '2026-08-01:2026-08-31', '2026-09-01:2026-09-30',
           '2026-09-01:2026-09-23', '2026-09-24:2026-09-30', '2026-10-01:2026-10-31', '2026-11-01:2026-11-30'];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--host=(.+)$/', $a, $m)) { $host = rtrim($m[1], '/'); }
    elseif (preg_match('/^--token=([0-9a-f]{64})$/', $a, $m)) { $token = $m[1]; }
    elseif (preg_match('/^--ranges=(.+)$/', $a, $m)) { $ranges = explode(',', $m[1]); }
}
if (!$host || !$token) { fwrite(STDERR, "--host and --token are required\n"); exit(1); }

$ctx = stream_context_create(['http' => [
    'method' => 'GET', 'header' => "Authorization: Bearer $token\r\n", 'timeout' => 120, 'ignore_errors' => true,
]]);
foreach ($ranges as $r) {
    list($start, $end) = explode(':', $r);
    $j = null;
    for ($try = 0; $try < 3 && !$j; $try++) {
        $body = @file_get_contents("$host/api/pnl.php?start=$start&end=$end", false, $ctx);
        $d = json_decode((string) $body, true);
        if (is_array($d) && !empty($d['success'])) { $j = $d; } else { sleep(2); }
    }
    if (!$j) { echo "$r FAILED\n"; continue; }
    $t = $j['data']['totals'];
    ksort($t);
    echo $r, ' ', json_encode($t), "\n";
}
