<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only (step 6.6): never deployed
/**
 * Step 6.6 - READ ONLY. The before/after picture for the direct-sale revenue fix.
 *
 * Asks the LIVE P&L endpoint (so the numbers are exactly what the page shows), month by
 * month, and prints net revenue, total cost and profit, plus how much of each month's
 * commission came from a direct sale - the amount this step is expected to give back.
 *
 *   php tools/pnl_revenue_snapshot.php --host=https://withlocals.deetech.cc --token=<admin>
 *       [--months=2026-07,2026-08,2026-09] [--label=BEFORE]
 *
 * GET requests only; writes nothing anywhere.
 */
$host = null; $token = null; $label = ''; $months = ['2026-07', '2026-08', '2026-09'];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--host=(.+)$/', $a, $m)) { $host = rtrim($m[1], '/'); }
    elseif (preg_match('/^--token=([0-9a-f]{64})$/', $a, $m)) { $token = $m[1]; }
    elseif (preg_match('/^--label=(.+)$/', $a, $m)) { $label = $m[1]; }
    elseif (preg_match('/^--months=(.+)$/', $a, $m)) { $months = explode(',', $m[1]); }
}
if (!$host || !$token) { fwrite(STDERR, "--host and --token are required\n"); exit(1); }

function getJson($host, $token, $path) {
    $ctx = stream_context_create(['http' => [
        'method' => 'GET',
        'header' => "Authorization: Bearer $token\r\n",
        'timeout' => 90,
        'ignore_errors' => true,
    ]]);
    for ($try = 0; $try < 3; $try++) {
        $body = @file_get_contents($host . $path, false, $ctx);
        $j = json_decode((string) $body, true);
        if (is_array($j) && !empty($j['success'])) { return $j; }
        sleep(2);
    }
    return null;
}

printf("=== %s  %s ===\n", $label ?: 'SNAPSHOT', $host);
printf("%-10s %12s %12s %12s %12s %12s\n", 'month', 'retail', 'commission', 'net', 'total_cost', 'profit');
echo str_repeat('-', 76) . "\n";

foreach ($months as $mth) {
    $start = $mth . '-01';
    $end = date('Y-m-t', strtotime($start));
    $j = getJson($host, $token, "/api/pnl.php?start=$start&end=$end");
    if (!$j) { printf("%-10s  FAILED\n", $mth); continue; }
    $t = $j['data']['totals'];
    printf("%-10s %12.2f %12.2f %12.2f %12.2f %12.2f\n",
        $mth, $t['retail'], $t['commission'], $t['net'], $t['total_cost'], $t['profit']);
}

// Per-channel, last 90 days, from the same live endpoint - one row per day, summed.
echo "\n--- last 90 days, by channel (single-channel units only, so attribution is exact) ---\n";
$byChannel = [];
$mixed = ['units' => 0, 'retail' => 0.0, 'commission' => 0.0, 'net' => 0.0];
$estimated = 0;
$cursor = new DateTimeImmutable('-90 days');
$last = new DateTimeImmutable('today');
while ($cursor <= $last) {
    $d = $cursor->format('Y-m-d');
    $cursor = $cursor->add(new DateInterval('P1D'));
    $j = getJson($host, $token, '/api/pnl.php?date=' . $d);
    if (!$j) { fwrite(STDERR, "  ! $d failed\n"); continue; }
    foreach (($j['data']['rows'] ?? []) as $r) {
        if ((int) $r['bookings'] === 0) { continue; }
        if (!empty($r['revenue']['estimated'])) { $estimated++; }
        $ch = $r['channels'];
        if (count($ch) !== 1) {
            $mixed['units']++;
            $mixed['retail'] += $r['revenue']['retail'];
            $mixed['commission'] += $r['revenue']['commission'];
            $mixed['net'] += $r['revenue']['net'];
            continue;
        }
        $k = $ch[0];
        if (!isset($byChannel[$k])) { $byChannel[$k] = ['units' => 0, 'retail' => 0.0, 'commission' => 0.0, 'net' => 0.0, 'est' => 0]; }
        $byChannel[$k]['units']++;
        $byChannel[$k]['retail'] += $r['revenue']['retail'];
        $byChannel[$k]['commission'] += $r['revenue']['commission'];
        $byChannel[$k]['net'] += $r['revenue']['net'];
        if (!empty($r['revenue']['estimated'])) { $byChannel[$k]['est']++; }
    }
}
uasort($byChannel, function ($a, $b) { return $b['retail'] <=> $a['retail']; });
printf("  %-30s %6s %12s %12s %8s %12s %6s\n", 'channel', 'units', 'retail', 'commission', 'rate', 'net', 'est');
foreach ($byChannel as $k => $v) {
    printf("  %-30s %6d %12.2f %12.2f %7.1f%% %12.2f %6d\n",
        substr($k, 0, 30), $v['units'], $v['retail'], $v['commission'],
        $v['retail'] > 0 ? 100 * $v['commission'] / $v['retail'] : 0, $v['net'], $v['est']);
}
printf("  %-30s %6d %12.2f %12.2f %7.1f%% %12.2f\n", '(mixed-channel units)', $mixed['units'],
    $mixed['retail'], $mixed['commission'],
    $mixed['retail'] > 0 ? 100 * $mixed['commission'] / $mixed['retail'] : 0, $mixed['net']);
printf("\n  units whose revenue was ESTIMATED (guessed percentage): %d\n", $estimated);

echo "\ndone (nothing was written)\n";
