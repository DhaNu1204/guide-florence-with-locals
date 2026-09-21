<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only (6.4 follow-up): never deployed
/**
 * 6.4 follow-up, Part A — READ ONLY. Which real departures end up costed at a guide fee of
 * exactly EUR 0.00, and what does that do to the Daily P&L?
 *
 * Deliberately asks the LIVE endpoint day by day (GET pnl.php?date=) rather than
 * re-implementing the costing: the numbers below are exactly what the Daily P&L page shows.
 * It looks for departures that have a guide assigned, are not a ticket product, are not
 * outsourced and carry no guide_cost override — and still show guide_cost 0.00.
 *
 *   php tools/guide_rate_other_check.php --host=https://withlocals.deetech.cc \
 *       --token=<admin token> --start=2026-07-01 --end=2026-07-31 [--label=JULY]
 *
 * Makes GET requests only; writes nothing anywhere.
 */
$host = null; $token = null; $start = null; $end = null; $label = ''; $delayMs = 0;
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--host=(.+)$/', $a, $m))  { $host = rtrim($m[1], '/'); }
    elseif (preg_match('/^--token=([0-9a-f]{64})$/', $a, $m)) { $token = $m[1]; }
    elseif (preg_match('/^--start=(\d{4}-\d{2}-\d{2})$/', $a, $m)) { $start = $m[1]; }
    elseif (preg_match('/^--end=(\d{4}-\d{2}-\d{2})$/', $a, $m)) { $end = $m[1]; }
    elseif (preg_match('/^--label=(.+)$/', $a, $m)) { $label = $m[1]; }
    elseif (preg_match('/^--delay=([0-9]+)$/', $a, $m)) { $delayMs = (int) $m[1]; }
}
if (!$host || !$token || !$start || !$end) {
    fwrite(STDERR, "--host, --token, --start and --end are required\n");
    exit(1);
}

function getJson($host, $token, $path) {
    $ctx = stream_context_create(['http' => [
        'method'        => 'GET',
        'header'        => "Authorization: Bearer $token\r\n",
        'timeout'       => 60,
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($host . $path, false, $ctx);
    return json_decode((string) $body, true);
}

printf("=== %s  %s .. %s   (%s) ===\n", $label ?: 'RANGE', $start, $end, $host);

$settings = null;
$zero = [];
$byCategory = [];
$guidedWithGuide = 0;
$totalGuideCost = 0.0;
$days = 0;

$cursor = new DateTimeImmutable($start);
$last   = new DateTimeImmutable($end);
while ($cursor <= $last) {
    $d = $cursor->format('Y-m-d');
    $cursor = $cursor->add(new DateInterval('P1D'));
    if ($delayMs > 0) { usleep($delayMs * 1000); }
    $res = getJson($host, $token, '/api/pnl.php?date=' . $d);
    if (!is_array($res) || empty($res['success'])) {
        // one retry after a pause: the read rate limiter, not a real failure, is the usual cause
        sleep(2);
        $res = getJson($host, $token, '/api/pnl.php?date=' . $d);
    }
    if (!is_array($res) || empty($res['success'])) { fwrite(STDERR, "  ! $d failed\n"); continue; }
    $days++;
    if ($settings === null) { $settings = $res['data']['settings'] ?? []; }
    foreach (($res['data']['rows'] ?? []) as $r) {
        if (!empty($r['is_ticket']) || (int) $r['bookings'] === 0) { continue; }
        $totalGuideCost += (float) $r['costs']['guide_cost'];
        if (empty($r['guide_name'])) { continue; }
        $guidedWithGuide++;
        if (round((float) $r['costs']['guide_cost'], 2) !== 0.0) { continue; }
        if (!empty($r['outsourced'])) { continue; }                                     // a handling fee instead
        if (in_array('guide_cost', $r['costs']['overridden'] ?? [], true)) { continue; } // 0 typed on purpose
        $r['_date'] = $d;
        $zero[] = $r;
        $cat = $r['category'] . (empty($r['is_private']) ? '' : ' (private)');
        $byCategory[$cat] = ($byCategory[$cat] ?? 0) + 1;
    }
}

printf("days read: %d\n", $days);
if ($settings !== null) {
    printf("guide_rate_other = %.2f   guide_rate_private_other = %.2f\n",
        (float) ($settings['guide_rate_other'] ?? -1), (float) ($settings['guide_rate_private_other'] ?? -1));
}
printf("guided departures WITH a guide assigned : %d\n", $guidedWithGuide);
printf("of those, costed at EXACTLY 0.00        : %d\n", count($zero));
printf("guide cost those departures contribute  : 0.00\n");
printf("guide cost for the whole range          : %.2f\n\n", round($totalGuideCost, 2));

if (count($zero) > 0) {
    echo "the rule that won, by category:\n";
    arsort($byCategory);
    foreach ($byCategory as $cat => $n) { printf("   %-26s %d\n", $cat, $n); }

    $byTitle = [];
    foreach ($zero as $r) {
        $k = $r['title'];
        if (!isset($byTitle[$k])) { $byTitle[$k] = ['n' => 0, 'pax' => 0]; }
        $byTitle[$k]['n']++;
        $byTitle[$k]['pax'] += (int) $r['pax']['total'];
    }
    uasort($byTitle, function ($a, $b) { return $b['n'] - $a['n']; });
    echo "\nproducts involved:\n";
    foreach ($byTitle as $t => $x) { printf("   %-3d departures %-5d pax  %s\n", $x['n'], $x['pax'], substr($t, 0, 60)); }

    echo "\n3 examples:\n";
    $shown = 0;
    foreach ($zero as $r) {
        if ($shown++ >= 3) { break; }
        $private = !empty($r['is_private']);
        printf("   %s %s  %-44s  guide %-20s  category %s%s -> guide_rate_%s = %.2f\n",
            $r['_date'], substr($r['time'], 0, 5), substr($r['title'], 0, 43), $r['guide_name'],
            $r['category'], $private ? ' PRIVATE' : '',
            $private ? 'private_other' : 'other',
            (float) ($private ? ($settings['guide_rate_private_other'] ?? 0) : ($settings['guide_rate_other'] ?? 0)));
    }

    echo "\nif these were costed at a real rate, the range's profit would fall by:\n";
    foreach ([60.0, 90.0, 120.0] as $rate) {
        printf("   EUR %-6.2f per departure  ->  guide cost +%.2f   profit -%.2f\n",
            $rate, count($zero) * $rate, count($zero) * $rate);
    }
}

echo "\ndone (nothing was written)\n";
