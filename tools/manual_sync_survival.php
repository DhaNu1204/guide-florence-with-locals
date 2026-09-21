<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only check (step 6.4): never deployed
/**
 * Step 6.4 — the proof that matters: a real Bokun sync must leave a hand-entered
 * departure byte for byte unchanged.
 *
 * A manual row that a nightly sync deletes, overwrites or marks cancelled would be worse
 * than no feature at all, so this does not reason about the code — it inserts a manual row
 * on a date INSIDE the sync window, fingerprints every column, runs the same syncBookings()
 * the cron runs, and fingerprints again.
 *
 *   FWL_API_DIR=... php tools/manual_sync_survival.php --date=2026-10-02 [--keep] [--force]
 *
 * Refuses to run against a database whose name does not look like staging unless --force
 * is given: this script WRITES a row.
 */
$apiDir = getenv('FWL_API_DIR') ?: __DIR__ . '/../public_html/api';
define('BOKUN_SYNC_LIB', true);
require_once $apiDir . '/config.php';
require_once $apiDir . '/bokun_sync.php';
require_once $apiDir . '/manual_helpers.php';

$date = null; $keep = false; $force = false;
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--date=(\d{4}-\d{2}-\d{2})$/', $a, $m)) { $date = $m[1]; }
    elseif ($a === '--keep')  { $keep = true; }
    elseif ($a === '--force') { $force = true; }
}
if (!$date) { fwrite(STDERR, "--date=YYYY-MM-DD is required\n"); exit(1); }

$dbName = $conn->query("SELECT DATABASE()")->fetch_row()[0];
if (strpos($dbName, '_stg') === false && !$force) {
    fwrite(STDERR, "refusing to write to '$dbName' (not staging). Pass --force if you really mean it.\n");
    exit(1);
}

ensureManualColumns($conn);
echo "=== step 6.4: does a real sync leave a manual row alone? ===\n";
echo "db: $dbName   date: $date\n\n";

// --- 1. a manual departure, exactly as the Add tour form writes one --------------------------
$title = 'STEP 6.4 SURVIVAL CHECK - Michelangelo hand-entered';
$conn->query("DELETE FROM tours WHERE source = 'manual' AND title = '" . $conn->real_escape_string($title) . "'");
$stmt = $conn->prepare(
    "INSERT INTO tours (title, date, time, participants, language, guide_id, booking_channel,
                        external_source, source, manual_revenue, manual_currency, notes,
                        needs_guide_assignment, cancelled, is_private, payment_status, created_at, updated_at)
     VALUES (?, ?, '09:30:00', 2, 'English', NULL, 'GetYourGuide (direct)', 'manual', 'manual',
             418.29, 'EUR', 'step 6.4 survival check', 1, 0, 0, 'unpaid', NOW(), NOW())");
$stmt->bind_param('ss', $title, $date);
$stmt->execute();
$id = $stmt->insert_id;
$stmt->close();
printf("inserted manual tour id %d on %s 09:30, 2 PAX, EUR 418.29\n\n", $id, $date);

function snapshot($conn, $id) {
    $stmt = $conn->prepare("SELECT * FROM tours WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row;
}

$before = snapshot($conn, $id);
$beforeHash = md5(serialize($before));
printf("BEFORE  %d columns, fingerprint %s\n", count($before), $beforeHash);
printf("        date=%s time=%s pax=%s guide=%s source=%s revenue=%s group_id=%s cancelled=%s last_sync=%s\n\n",
    $before['date'], $before['time'], $before['participants'],
    var_export($before['guide_id'], true), var_export($before['source'], true),
    var_export($before['manual_revenue'], true), var_export($before['group_id'], true),
    $before['cancelled'], var_export($before['last_sync'], true));

// --- 2. the real thing: the same call the cron makes ------------------------------------------
$t0 = microtime(true);
$result = syncBookings($date, $date, 'manual', 'step 6.4 survival check');
printf("sync: %s, %s bookings found, %s created, %s updated, %s failed, %.2fs\n",
    !empty($result['success']) ? 'ok' : ('FAILED: ' . ($result['error'] ?? '?')),
    $result['found_count'] ?? $result['bookings_found'] ?? '?',
    $result['created_count'] ?? '?', $result['updated_count'] ?? '?',
    $result['failed_count'] ?? '?', microtime(true) - $t0);
if (isset($result['manual_departures'])) {
    printf("sync report: %d manual departure(s) in range, %d flagged as a possible duplicate\n",
        $result['manual_departures'], $result['manual_possible_duplicates']);
}
echo "\n";

// --- 3. is the row still exactly what we wrote? ------------------------------------------------
$after = snapshot($conn, $id);
if ($after === null) {
    echo "RESULT: FAILED - the sync DELETED the manual row\n";
    exit(1);
}
$afterHash = md5(serialize($after));
printf("AFTER   %d columns, fingerprint %s\n", count($after), $afterHash);

$diff = [];
foreach ($before as $col => $val) {
    $now = array_key_exists($col, $after) ? $after[$col] : '(column gone)';
    if ($val !== $now) { $diff[$col] = [$val, $now]; }
}
if (count($diff) === 0) {
    printf("\nRESULT: PASS - every one of the %d columns is unchanged (identical fingerprint)\n", count($before));
} else {
    echo "\nRESULT: FAILED - the sync changed " . count($diff) . " column(s):\n";
    foreach ($diff as $col => $pair) {
        printf("   %-24s %s  ->  %s\n", $col, var_export($pair[0], true), var_export($pair[1], true));
    }
}

if ($keep) {
    echo "\n(--keep: the row was left in place, id $id)\n";
} else {
    $del = $conn->prepare("DELETE FROM tours WHERE id = ? AND source = 'manual'");
    $del->bind_param('i', $id);
    $del->execute();
    printf("\ncleaned up: removed tour %d\n", $id);
}
exit(count($diff) === 0 ? 0 : 1);
