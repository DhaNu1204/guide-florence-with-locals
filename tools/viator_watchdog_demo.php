<?php
// Step 6.9 item 3 demo - baseline, quiet run, a simulated disappearance, then restore.
// Staging only. CLI, never deployed.
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }
$apiDir = getenv('FWL_API_DIR');
require_once $apiDir . '/config.php';
require_once $apiDir . '/viator_helpers.php';
if (strpos((string) ($GLOBALS['db_name'] ?? ''), '_stg') === false) { fwrite(STDERR, "staging only\n"); exit(1); }

function show($label, $res) {
    if ($res === null) { printf("%-28s (skipped - already checked today)\n", $label); return; }
    printf("%-28s bookings=%-4s departures=%-4s pax=%-4s expected=%-5s passed=%-3s cancelled=%-3s  %s\n",
        $label, $res['future_bookings'], $res['future_departures'], $res['future_pax'],
        $res['expected_bookings'] === null ? '-' : $res['expected_bookings'],
        $res['passed_since_last'], $res['cancelled_future'], $res['status']);
}

show('1 first ever run', viatorWatchdogRun($conn, true));
show('2 nothing changed', viatorWatchdogRun($conn, true));

// Simulate the catastrophe: one legacy booking vanishes from the scope.
$r = $conn->query("SELECT id FROM tours WHERE viator_account='legacy' AND cancelled=0 AND date >= CURDATE() ORDER BY date LIMIT 1");
$id = (int) $r->fetch_row()[0];
$conn->query("UPDATE tours SET viator_account = NULL WHERE id = $id");
show("3 booking $id disappears", viatorWatchdogRun($conn, true));

$conn->query("UPDATE tours SET viator_account = 'legacy' WHERE id = $id");
show('4 restored', viatorWatchdogRun($conn, true));

echo "\n-- what is stored (newest first) --\n";
$r = $conn->query("SELECT checked_at, horizon, future_bookings, future_departures, future_pax, latest_date,
                          expected_bookings, passed_since_last, cancelled_future, status, note
                     FROM viator_watchdog ORDER BY id DESC LIMIT 5");
while ($x = $r->fetch_assoc()) {
    printf("  %s  horizon=%s  %s/%s/%s pax  latest=%s  expected=%s  %-8s %s\n",
        $x['checked_at'], $x['horizon'], $x['future_bookings'], $x['future_departures'], $x['future_pax'],
        $x['latest_date'], $x['expected_bookings'] ?? '-', $x['status'], $x['note'] ?? '');
}
// Leave staging with only the honest readings.
$conn->query("DELETE FROM viator_watchdog");
viatorWatchdogRun($conn, true);
echo "\nstaging left with a single clean baseline row\n";
