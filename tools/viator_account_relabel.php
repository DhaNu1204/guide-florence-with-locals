<?php
// Step 6.18 - correct tours.viator_account from the Viator product code. CLI only, never deployed.
//   FWL_API_DIR=<api dir> php viator_account_relabel.php            (dry run: counts + what would change)
//   FWL_API_DIR=<api dir> php viator_account_relabel.php --apply    (writes, then re-takes the watchdog baseline)
// Only rows WITH a product code are ever written (viatorAccountRelabel); hand-entered rows keep their label.
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }
$apiDir = getenv('FWL_API_DIR') ?: __DIR__ . '/../public_html/api';
require_once $apiDir . '/config.php';
require_once $apiDir . '/viator_helpers.php';

$apply = in_array('--apply', $argv, true);
$today = (new DateTime('now', new DateTimeZone('Europe/Rome')))->format('Y-m-d');
echo "db = {$GLOBALS['db_name']}   today (Rome) = $today   mode = " . ($apply ? 'APPLY' : 'dry run') . "\n";

// What every screen shows (viatorAccountForDisplay), live bookings only, future and all time.
function viatorCounts($conn, $today) {
    $out = ['future' => [], 'all' => [], 'ids' => []];
    $res = $conn->query("SELECT id, external_id, date, time, booking_channel, viator_account, source FROM tours
                          WHERE (booking_channel LIKE '%Viator%' OR booking_channel LIKE '%Tripadvisor%') AND cancelled = 0
                          ORDER BY date, time, id");
    while ($r = $res->fetch_assoc()) {
        $a = viatorAccountForDisplay($r['booking_channel'], $r['viator_account']) ?? 'none';
        $name = ['legacy' => 'old', 'current' => 'new', 'unknown' => 'unknown'][$a] ?? $a;
        $out['all'][$name] = ($out['all'][$name] ?? 0) + 1;
        if ($r['date'] >= $today) {
            $out['future'][$name] = ($out['future'][$name] ?? 0) + 1;
            if ($name !== 'old') {
                $out['ids'][] = sprintf("%-7s t%d %s %s %s%s", $name, $r['id'], $r['external_id'] ?? '(hand-entered)',
                    $r['date'], substr($r['time'], 0, 5), $r['source'] === 'manual' ? ' manual' : '');
            }
        }
    }
    return $out;
}
function viatorPrint($label, $c) {
    $f = $c['future']; $a = $c['all'];
    printf("%s  future live: old %d / new %d / unknown %d     all-time live: old %d / new %d / unknown %d\n", $label,
        $f['old'] ?? 0, $f['new'] ?? 0, $f['unknown'] ?? 0, $a['old'] ?? 0, $a['new'] ?? 0, $a['unknown'] ?? 0);
    foreach ($c['ids'] as $line) { echo "      $line\n"; }
}

viatorPrint('BEFORE', viatorCounts($conn, $today));

$changes = viatorAccountRelabel($conn, $apply);
echo "\n" . ($apply ? 'changed' : 'would change') . ": " . count($changes) . " row(s)\n";
foreach ($changes as $c) { printf("  t%d  %s -> %s\n", $c['id'], $c['from'] ?? 'NULL', $c['to']); }

if ($apply) {
    // Bookings that were never old-account bookings have just left 'legacy': without a fresh
    // baseline tomorrow's watchdog would read that as a disappearance.
    $w = viatorWatchdogRun($conn, true, true);
    echo "\nwatchdog re-baselined: " . json_encode($w) . "\n";
    echo "\n";
    viatorPrint('AFTER ', viatorCounts($conn, $today));
}
