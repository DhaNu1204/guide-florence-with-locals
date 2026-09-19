<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only (step 3.4): never deployed
/**
 * Step 3.4 measurement - READ ONLY. Shows where a Bokun sync spends its time and whether the
 * per-booking lookup can use an index. Runs SELECT / SHOW / EXPLAIN only, never a write.
 *
 *   php tools/sync_speed_check.php
 *   FWL_API_DIR=/path/to/api php tools/sync_speed_check.php   (running the copy on the server)
 *   --limit=N   how many recent completed syncs to summarise (default 20)
 */

$apiDir = getenv('FWL_API_DIR') ?: __DIR__ . '/../public_html/api';
require_once $apiDir . '/config.php';

$limit = 20;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) { $limit = max(1, (int) $m[1]); }
}

function q($conn, $sql) {
    $res = $conn->query($sql);
    if (!$res) { echo "  ! query failed: " . $conn->error . "\n"; return []; }
    $rows = [];
    while ($r = $res->fetch_assoc()) { $rows[] = $r; }
    return $rows;
}
function median(array $v) {
    if (!$v) { return null; }
    sort($v);
    $n = count($v);
    return $n % 2 ? $v[($n - 1) / 2] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
}

echo "=== step 3.4 sync speed check (read only) ===\n";
echo "db: " . $conn->query("SELECT DATABASE() d")->fetch_assoc()['d'] . "   now(utc): " . gmdate('c') . "\n\n";

// --- 1. recent full cron syncs ----------------------------------------------------------
$rows = q($conn, "SELECT id, sync_type, triggered_by, status, bookings_found, bookings_synced,
                         bookings_created, bookings_updated, duration_seconds, created_at, completed_at
                  FROM sync_logs
                  WHERE status = 'completed' AND duration_seconds IS NOT NULL
                    AND (triggered_by = 'cron' OR sync_type = 'cron')
                  ORDER BY id DESC LIMIT $limit");
echo "--- last " . count($rows) . " completed cron syncs ---\n";
$durs = []; $found = [];
foreach ($rows as $r) {
    $durs[] = (float) $r['duration_seconds'];
    $found[] = (int) $r['bookings_found'];
    printf("  #%-6s %s  %6.1fs  found %4d  synced %4d (new %d)\n",
        $r['id'], $r['created_at'], $r['duration_seconds'], $r['bookings_found'], $r['bookings_synced'], $r['bookings_created']);
}
if ($durs) {
    printf("  median %.1fs | worst %.1fs | best %.1fs | median bookings found %.0f | max %d\n",
        median($durs), max($durs), min($durs), median($found), max($found));
}

// --- 2. the per-booking lookup ----------------------------------------------------------
echo "\n--- tours indexes ---\n";
foreach (q($conn, "SHOW INDEX FROM tours") as $r) {
    printf("  %-28s seq %s  col %-24s card %s\n", $r['Key_name'], $r['Seq_in_index'], $r['Column_name'], $r['Cardinality']);
}
$t = q($conn, "SELECT table_rows, ROUND(data_length/1024/1024,1) data_mb, ROUND(index_length/1024/1024,1) idx_mb
               FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'tours'");
if ($t) { printf("  tours: ~%s rows, data %s MB, index %s MB\n", $t[0]['table_rows'], $t[0]['data_mb'], $t[0]['idx_mb']); }

$sample = q($conn, "SELECT bokun_booking_id, external_id FROM tours WHERE bokun_booking_id <> '' ORDER BY id DESC LIMIT 1");
if ($sample) {
    $b = $conn->real_escape_string($sample[0]['bokun_booking_id']);
    $e = $conn->real_escape_string($sample[0]['external_id']);
    echo "\n--- EXPLAIN: the per-booking lookup (OR form, as the sync runs it today) ---\n";
    foreach (q($conn, "EXPLAIN SELECT id, date, time, rescheduled, original_date, original_time
                       FROM tours WHERE bokun_booking_id = '$b' OR external_id = '$e'") as $r) {
        printf("  type=%-8s key=%-22s rows=%-8s filtered=%s extra=%s\n",
            $r['type'], $r['key'] ?? 'NULL', $r['rows'], $r['filtered'] ?? '', $r['Extra'] ?? '');
    }
    echo "--- EXPLAIN: same lookup split in two (external_id first, then bokun_booking_id) ---\n";
    foreach (q($conn, "EXPLAIN SELECT id FROM tours WHERE external_id = '$e'") as $r) {
        printf("  external_id:       type=%-8s key=%-22s rows=%-8s\n", $r['type'], $r['key'] ?? 'NULL', $r['rows']);
    }
    foreach (q($conn, "EXPLAIN SELECT id FROM tours WHERE bokun_booking_id = '$b'") as $r) {
        printf("  bokun_booking_id:  type=%-8s key=%-22s rows=%-8s\n", $r['type'], $r['key'] ?? 'NULL', $r['rows']);
    }
}

// --- 3. how many distinct products a sync window touches --------------------------------
echo "\n--- products seen in the last 60 days of tours (the N+1 denominator) ---\n";
foreach (q($conn, "SELECT COUNT(*) bookings, COUNT(DISTINCT product_id) products
                   FROM tours WHERE date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                                AND date <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)") as $r) {
    printf("  bookings in a typical sync window: %s over %s distinct products\n", $r['bookings'], $r['products']);
}
foreach (q($conn, "SELECT COUNT(*) n FROM tours WHERE (language IS NULL OR language = '')
                     AND date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)") as $r) {
    printf("  tours in that window with no language stored: %s\n", $r['n']);
}

echo "\ndone (nothing was written)\n";
