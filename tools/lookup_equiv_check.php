<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only (step 3.4): never deployed
/**
 * Step 3.4 correctness proof - READ ONLY. The sync finds an existing booking with
 *     WHERE bokun_booking_id = ? OR external_id = ?           (full table scan today)
 * and step 3.4 replaces it with two indexed lookups (external_id, then bokun_booking_id).
 * This proves the two forms pick the SAME row for every booking currently in the table,
 * and reports the only way they could ever differ (a duplicated bokun_booking_id).
 */
$apiDir = getenv('FWL_API_DIR') ?: __DIR__ . '/../public_html/api';
require_once $apiDir . '/config.php';

$limit = 1000;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) { $limit = (int) $m[1]; }
}

echo "=== lookup equivalence (OR scan vs two indexed lookups) ===\n";
echo "db: " . $conn->query("SELECT DATABASE() d")->fetch_assoc()['d'] . "\n";

foreach ([
    "rows with an empty/NULL bokun_booking_id" => "SELECT COUNT(*) n FROM tours WHERE bokun_booking_id IS NULL OR bokun_booking_id = ''",
    "rows with an empty/NULL external_id"      => "SELECT COUNT(*) n FROM tours WHERE external_id IS NULL OR external_id = ''",
    "DUPLICATED bokun_booking_id values"       => "SELECT COUNT(*) n FROM (SELECT bokun_booking_id FROM tours WHERE bokun_booking_id <> '' GROUP BY bokun_booking_id HAVING COUNT(*) > 1) x",
] as $label => $sql) {
    printf("  %-42s : %s\n", $label, $conn->query($sql)->fetch_assoc()['n']);
}

$pairs = [];
$res = $conn->query("SELECT bokun_booking_id, external_id FROM tours
                     WHERE bokun_booking_id <> '' AND external_id IS NOT NULL
                     ORDER BY id DESC LIMIT $limit");
while ($r = $res->fetch_assoc()) { $pairs[] = $r; }

$or   = $conn->prepare("SELECT id FROM tours WHERE bokun_booking_id = ? OR external_id = ?");
$ext  = $conn->prepare("SELECT id FROM tours WHERE external_id = ?");
$bbid = $conn->prepare("SELECT id FROM tours WHERE bokun_booking_id = ? ORDER BY id ASC LIMIT 1");

$same = 0; $diff = 0; $examples = [];
foreach ($pairs as $p) {
    $or->bind_param("ss", $p['bokun_booking_id'], $p['external_id']);
    $or->execute();
    $oldId = ($or->get_result()->fetch_assoc() ?: ['id' => null])['id'];

    $ext->bind_param("s", $p['external_id']);
    $ext->execute();
    $newId = ($ext->get_result()->fetch_assoc() ?: ['id' => null])['id'];
    if ($newId === null) {
        $bbid->bind_param("s", $p['bokun_booking_id']);
        $bbid->execute();
        $newId = ($bbid->get_result()->fetch_assoc() ?: ['id' => null])['id'];
    }

    if ((string) $oldId === (string) $newId) { $same++; }
    else {
        $diff++;
        if (count($examples) < 5) { $examples[] = "booking {$p['bokun_booking_id']} / {$p['external_id']}: OR -> $oldId, split -> $newId"; }
    }
}
$or->close(); $ext->close(); $bbid->close();

printf("\n  checked %d real bookings: same row %d, DIFFERENT row %d  <- must be 0\n", count($pairs), $same, $diff);
foreach ($examples as $e) { echo "    $e\n"; }
echo "\ndone (nothing was written)\n";
