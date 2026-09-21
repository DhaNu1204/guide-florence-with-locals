<?php
// Step 6.9 item 2 proof - run a REAL sync over a date that holds a legacy-labelled Viator
// booking and show the label is untouched. Staging only. CLI, never deployed.
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }
$apiDir = getenv('FWL_API_DIR');
require_once $apiDir . '/config.php';
require_once $apiDir . '/viator_helpers.php';

if (strpos((string) ($GLOBALS['db_name'] ?? ''), '_stg') === false && !in_array('--force', $argv, true)) {
    fwrite(STDERR, "refusing: this does not look like the staging database (" . ($GLOBALS['db_name'] ?? '?') . ")\n");
    exit(1);
}

// A date inside the sync window that holds a legacy Viator booking.
$today = (new DateTime('now', new DateTimeZone('Europe/Rome')))->format('Y-m-d');
$horizon = (new DateTime('+55 days', new DateTimeZone('Europe/Rome')))->format('Y-m-d');
$r = $conn->query("SELECT date, COUNT(*) n FROM tours
                    WHERE viator_account = 'legacy' AND cancelled = 0
                      AND date >= '$today' AND date <= '$horizon'
                    GROUP BY date ORDER BY n DESC, date ASC LIMIT 1");
$pick = $r ? $r->fetch_assoc() : null;
if (!$pick) { fwrite(STDERR, "no legacy Viator booking inside the sync window\n"); exit(1); }
$date = $pick['date'];
echo "date under test: $date ({$pick['n']} legacy Viator booking(s))\n\n";

function snapshot($conn, $date) {
    $out = [];
    $r = $conn->query("SELECT * FROM tours WHERE date = '$date' AND viator_account IS NOT NULL ORDER BY id");
    while ($row = $r->fetch_assoc()) { $out[$row['id']] = $row; }
    return $out;
}

$before = snapshot($conn, $date);
echo "-- before --\n";
foreach ($before as $id => $row) {
    printf("  id=%-6s %s  viator_account=%-8s last_sync=%s  fingerprint=%s\n",
        $id, $row['external_id'], $row['viator_account'], $row['last_sync'], md5(json_encode($row)));
}

define('BOKUN_SYNC_LIB', true);
require_once $apiDir . '/bokun_sync.php';
$t0 = microtime(true);
$res = syncBookings($date, $date, 'manual', 'step-6.9-proof');
printf("\nsync: %s in %.2fs  %s\n\n", json_encode($res['success'] ?? false), microtime(true) - $t0,
    json_encode(['found' => $res['found'] ?? null, 'created' => $res['created'] ?? null,
                 'updated' => $res['updated'] ?? null, 'error' => $res['error'] ?? null]));

$after = snapshot($conn, $date);
echo "-- after --\n";
$bad = 0;
foreach ($before as $id => $row) {
    $a = $after[$id] ?? null;
    if (!$a) { printf("  id=%-6s ROW GONE\n", $id); $bad++; continue; }
    $labelSame = $a['viator_account'] === $row['viator_account'];
    $synced    = $a['last_sync'] !== $row['last_sync'];
    if (!$labelSame) { $bad++; }
    printf("  id=%-6s %s  viator_account=%-8s %s  last_sync %s  fingerprint=%s\n",
        $id, $a['external_id'], $a['viator_account'],
        $labelSame ? 'UNCHANGED' : '*** CHANGED ***',
        $synced ? 'advanced (Bokun really touched this row)' : 'unchanged',
        md5(json_encode($a)));
}
foreach ($after as $id => $row) {
    if (!isset($before[$id])) { printf("  id=%-6s NEW ROW  viator_account=%s\n", $id, $row['viator_account'] ?? 'NULL'); }
}
echo "\n" . ($bad === 0 ? "PASS: every label survived a real sync\n" : "FAIL: $bad row(s) changed\n");
exit($bad === 0 ? 0 : 1);
