<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only (step 6.14): never deployed
/**
 * Step 6.14 backfill: copy productBookings[0].rateTitle from each row's stored bokun_data into
 * tours.rate_title. Writes that ONE column, only where it is still NULL, and keeps updated_at
 * as it was (the column has ON UPDATE CURRENT_TIMESTAMP, so it is set to itself explicitly).
 *
 *   FWL_API_DIR=/path/to/api php tools/rate_title_backfill.php            # dry run: counts only
 *   FWL_API_DIR=/path/to/api php tools/rate_title_backfill.php --apply
 */
$apiDir = getenv('FWL_API_DIR') ?: __DIR__ . '/../public_html/api';
require_once $apiDir . '/config.php';
require_once $apiDir . '/rate_helpers.php';

$apply = in_array('--apply', array_slice($argv, 1), true);
ensureRateTitleColumn($conn);

$counts = function () use ($conn) {
    $r = $conn->query("SELECT COUNT(*) total,
                              SUM(rate_title IS NOT NULL) filled,
                              SUM(rate_title IS NULL AND bokun_data IS NOT NULL AND bokun_data <> '') null_with_payload,
                              SUM(bokun_data IS NULL OR bokun_data = '') no_payload
                         FROM tours")->fetch_assoc();
    return array_map('intval', $r);
};
$fmt = function ($c) { return "tours {$c['total']} | rate_title filled {$c['filled']} | NULL with a payload {$c['null_with_payload']} | no payload (manual) {$c['no_payload']}"; };

$before = $counts();
echo "before: " . $fmt($before) . "\n";

// Read first (unbuffered, keeping only id + title), write afterwards on the same connection.
$todo = []; $noTitle = 0;
$res = $conn->query("SELECT id, bokun_data FROM tours
                      WHERE rate_title IS NULL AND bokun_data IS NOT NULL AND bokun_data <> ''", MYSQLI_USE_RESULT);
while ($row = $res->fetch_assoc()) {
    $t = rateTitleFromBokun($row['bokun_data']);
    if ($t === null) { $noTitle++; continue; }
    $todo[(int) $row['id']] = $t;
}
$res->close();
$vasari = count(array_filter($todo, 'rateIsVasari'));
printf("to fill: %d (of which Vasari: %d) | payload without a rate title: %d\n", count($todo), $vasari, $noTitle);

if (!$apply) { echo "dry run - nothing written (add --apply)\n"; exit(0); }

$stmt = $conn->prepare("UPDATE tours SET rate_title = ?, updated_at = updated_at WHERE id = ? AND rate_title IS NULL");
$written = 0;
foreach ($todo as $id => $title) {
    $stmt->bind_param('si', $title, $id);
    $stmt->execute();
    $written += $stmt->affected_rows;
}
$stmt->close();
echo "written: $written\n";
echo "after:  " . $fmt($counts()) . "\n";
