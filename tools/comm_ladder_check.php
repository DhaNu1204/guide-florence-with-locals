<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only (step 6.6): never deployed
/**
 * Step 6.6 - READ ONLY. Which revenue path does every channel in the data actually take,
 * and which of them reach the guessed percentage at all?
 *
 * Classifies every distinct booking_channel value the database holds against the ladder in
 * pnlExtractRevenue(), and says for each whether Bokun gives us a reseller invoice (OTA
 * truth), only a customer invoice (a direct sale - commission really is zero), or neither
 * (the only case where a percentage should ever be used).
 *
 *   FWL_API_DIR=... php tools/comm_ladder_check.php [--all-time]
 */
$apiDir = getenv('FWL_API_DIR') ?: __DIR__ . '/../public_html/api';
require_once $apiDir . '/config.php';

$allTime = in_array('--all-time', array_slice($argv, 1), true);
$window = $allTime
    ? "1=1"
    : "date >= CURDATE() - INTERVAL 90 DAY AND date < CURDATE() + INTERVAL 1 DAY";

/** The ladder exactly as pnl.php:333-345 has it TODAY (before this step). */
function ladderBefore($ch) {
    $c = mb_strtolower(trim((string) $ch));
    if ($c === '' || $c === 'bokun' || strpos($c, 'direct') !== false || strpos($c, 'website') !== false) {
        return 'direct 0%';
    }
    if (strpos($c, 'getyourguide') !== false || strpos($c, 'gyg') !== false) return 'comm_getyourguide';
    if (strpos($c, 'viator') !== false || strpos($c, 'tripadvisor') !== false) return 'comm_viator';
    if (strpos($c, 'headout') !== false) return 'comm_headout';
    return 'comm_default  <-- the guess';
}

echo "=== step 6.6: where every channel lands, " . ($allTime ? "ALL TIME" : "last 90 days") . " ===\n";
echo "db: " . $conn->query("SELECT DATABASE()")->fetch_row()[0] . "\n\n";

$sql = "SELECT COALESCE(NULLIF(TRIM(booking_channel),''),'(empty)') ch,
               COUNT(*) n,
               SUM(cancelled = 0) live_rows,
               SUM(JSON_EXTRACT(bokun_data,'$.productBookings[0].resellerInvoice.total') IS NOT NULL) has_reseller,
               SUM(JSON_EXTRACT(bokun_data,'$.productBookings[0].resellerInvoice.total') IS NULL
                   AND JSON_EXTRACT(bokun_data,'$.productBookings[0].customerInvoice.total') IS NOT NULL) has_customer_only,
               SUM(bokun_data IS NULL) no_payload,
               ROUND(SUM(CASE WHEN cancelled = 0
                    AND JSON_EXTRACT(bokun_data,'$.productBookings[0].resellerInvoice.total') IS NULL
                    THEN CAST(JSON_EXTRACT(bokun_data,'$.productBookings[0].customerInvoice.total') AS DECIMAL(12,2)) END), 2) direct_retail
          FROM tours WHERE $window
         GROUP BY ch ORDER BY n DESC";
$res = $conn->query($sql);

printf("%-30s %6s %6s %10s %14s %10s %14s  %s\n",
    'channel', 'rows', 'live', 'reseller', 'customerOnly', 'noPayload', 'directRetail', 'ladder lands on');
echo str_repeat('-', 130) . "\n";
$guessTotal = 0.0;
$guessRows = 0;
while ($r = $res->fetch_assoc()) {
    $lands = ladderBefore($r['ch'] === '(empty)' ? '' : $r['ch']);
    // Only rows with NO reseller invoice ever reach the ladder at all.
    $reachesLadder = (int) $r['has_customer_only'] + (int) $r['no_payload'];
    printf("%-30s %6s %6s %10s %14s %10s %14s  %s%s\n",
        $r['ch'], $r['n'], $r['live_rows'], $r['has_reseller'], $r['has_customer_only'],
        $r['no_payload'], $r['direct_retail'] ?? '-', $lands,
        $reachesLadder > 0 ? '' : '   (never reached: reseller invoice always present)');
    if ($reachesLadder > 0 && strpos($lands, 'comm_default') === 0) {
        $guessTotal += (float) ($r['direct_retail'] ?? 0);
        $guessRows  += (int) $r['live_rows'];
    }
}

echo "\n--- channels that actually reach comm_default (30%) ---\n";
printf("  live bookings: %d, retail EUR %s, wrongly deducted at 30%%: EUR %s\n",
    $guessRows, number_format($guessTotal, 2), number_format(round($guessTotal * 0.30, 2), 2));

echo "\n--- do any of those have a NON-zero commission in their customer invoice? ---\n";
$q = $conn->query("SELECT COUNT(*) n,
        SUM(CAST(JSON_EXTRACT(bokun_data,'$.productBookings[0].customerInvoice.totalCommission') AS DECIMAL(12,2)) <> 0) nonzero
      FROM tours
     WHERE $window AND cancelled = 0
       AND JSON_EXTRACT(bokun_data,'$.productBookings[0].resellerInvoice.total') IS NULL
       AND JSON_EXTRACT(bokun_data,'$.productBookings[0].customerInvoice.total') IS NOT NULL");
$x = $q->fetch_assoc();
printf("  %s direct-sale bookings, of which %s carry a non-zero commission\n", $x['n'], $x['nonzero']);
printf("  (a non-zero one would mean 'use the invoice' is not simply 'commission = 0')\n");

echo "\n--- manual overrides that could be affected ---\n";
$q = $conn->query("SELECT COUNT(*) n, SUM(revenue_override IS NOT NULL) rev FROM pnl_tour_costs");
$x = $q->fetch_assoc();
printf("  pnl_tour_costs rows: %s, of which %s set a revenue_override\n", $x['n'], $x['rev']);

echo "\ndone (nothing was written)\n";
