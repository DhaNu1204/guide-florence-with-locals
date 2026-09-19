<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only (step 3.4): never deployed
/**
 * Step 3.4 measurement - READ ONLY. Answers "where does a sync spend its time?" from data that
 * is already in the database, without running a sync:
 *   - how many of the bookings in a sync window reach the per-booking `GET /activity.json/{id}`
 *     (the N+1 call), and how many distinct product ids they cover (= calls after a cache);
 *   - whether productBookings[0].rateTitle is present (= no call needed at all);
 *   - how long the per-booking existence lookup takes, measured on the real table.
 * SELECT only, and one optional live Bokun GET (--probe) to time a single product call.
 */

$apiDir = getenv('FWL_API_DIR') ?: __DIR__ . '/../public_html/api';
require_once $apiDir . '/config.php';

$probe = in_array('--probe', array_slice($argv, 1), true);
$loops = 50;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--loops=(\d+)$/', $arg, $m)) { $loops = (int) $m[1]; }
}

echo "=== step 3.4 sync breakdown (read only) ===\n";
echo "db: " . $conn->query("SELECT DATABASE() d")->fetch_assoc()['d'] . "   now(utc): " . gmdate('c') . "\n\n";

// --- A. replay the language logic over the stored payloads -------------------------------
$res = $conn->query("SELECT bokun_data FROM tours
                     WHERE date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                       AND date <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)
                       AND bokun_data IS NOT NULL AND bokun_data <> ''");
$total = 0; $fromNotes = 0; $needsCall = 0; $noRate = 0; $withRateTitle = 0; $products = [];
while ($row = $res->fetch_assoc()) {
    $booking = json_decode($row['bokun_data'], true);
    if (!is_array($booking)) { continue; }
    $total++;
    $pb = $booking['productBookings'][0] ?? ($booking['activityBookings'][0] ?? []);

    // Method 1: language from the notes
    $language = null;
    if (isset($pb['notes']) && is_array($pb['notes'])) {
        foreach ($pb['notes'] as $note) {
            if (!isset($note['body'])) { continue; }
            if (preg_match('/GUIDE\s*:\s*([A-Za-z]+)/i', $note['body'])) { $language = 'x'; break; }
            if (preg_match('/Booking languages.*?:\s*([A-Za-z]+)/is', $note['body'])) { $language = 'x'; break; }
        }
    }
    if ($language) { $fromNotes++; continue; }

    // Method 2: the N+1 product call
    if (isset($pb['fields']['rateId']) && isset($pb['product']['id'])) {
        $needsCall++;
        $products[(string) $pb['product']['id']] = true;
        foreach (['rateTitle', 'rateTitle'] as $k) { /* keep the loop shape explicit */ }
        if (!empty($pb['rateTitle']) || !empty($pb['fields']['rateTitle']) || !empty($pb['rate']['title'])) {
            $withRateTitle++;
        }
    } else {
        $noRate++;
    }
}
printf("--- language extraction over %d stored bookings in a 60-day window ---\n", $total);
printf("  language found in the notes (no API call)      : %d\n", $fromNotes);
printf("  reaches GET /activity.json/{id}  (ONE PER BOOKING today) : %d\n", $needsCall);
printf("  ... distinct product ids behind those calls (= calls with a per-run cache): %d\n", count($products));
printf("  ... of those bookings, a rate title is ALREADY in the payload: %d\n", $withRateTitle);
printf("  no rateId/product in the payload (skips the call anyway)   : %d\n", $noRate);

// --- B. time the per-booking existence lookup --------------------------------------------
$sample = [];
$res = $conn->query("SELECT bokun_booking_id, external_id FROM tours
                     WHERE bokun_booking_id <> '' ORDER BY id DESC LIMIT $loops");
while ($r = $res->fetch_assoc()) { $sample[] = $r; }

$sqlOr  = "SELECT id, date, time, rescheduled, original_date, original_time FROM tours WHERE bokun_booking_id = ? OR external_id = ?";
$sqlExt = "SELECT id, date, time, rescheduled, original_date, original_time FROM tours WHERE external_id = ?";

$t0 = microtime(true);
$stmt = $conn->prepare($sqlOr);
foreach ($sample as $s) {
    $stmt->bind_param("ss", $s['bokun_booking_id'], $s['external_id']);
    $stmt->execute();
    $stmt->get_result()->fetch_assoc();
}
$stmt->close();
$orMs = (microtime(true) - $t0) * 1000;

$t0 = microtime(true);
$stmt = $conn->prepare($sqlExt);
foreach ($sample as $s) {
    $stmt->bind_param("s", $s['external_id']);
    $stmt->execute();
    $stmt->get_result()->fetch_assoc();
}
$stmt->close();
$extMs = (microtime(true) - $t0) * 1000;

printf("\n--- per-booking existence lookup, %d real ids ---\n", count($sample));
printf("  OR form  (today)              : %.1f ms total = %.2f ms/booking -> %.1f s for %d bookings\n",
    $orMs, $orMs / max(1, count($sample)), ($orMs / max(1, count($sample))) * 811 / 1000, 811);
printf("  external_id only (indexed)    : %.1f ms total = %.2f ms/booking -> %.1f s for %d bookings\n",
    $extMs, $extMs / max(1, count($sample)), ($extMs / max(1, count($sample))) * 811 / 1000, 811);

// --- C. one live Bokun product call, timed (optional) -------------------------------------
if ($probe) {
    require_once $apiDir . '/BokunAPI.php';
    define('BOKUN_SYNC_LIB', true);
    require_once $apiDir . '/bokun_sync.php';
    $cfg = getBokunConfig();
    if ($cfg && !empty($cfg['access_key'])) {
        $api = new BokunAPI([
            'access_key' => $cfg['access_key'],
            'secret_key' => $cfg['secret_key'],
            'vendor_id'  => $cfg['vendor_id'],
        ]);
        $pid = array_key_first($products);
        if ($pid) {
            echo "\n--- live GET /activity.json/{id}, three times ---\n";
            for ($i = 0; $i < 3; $i++) {
                $t0 = microtime(true);
                try { $api->getProduct($pid); $ok = 'ok'; } catch (Exception $e) { $ok = 'failed: ' . $e->getMessage(); }
                printf("  #%d : %.0f ms (%s)\n", $i + 1, (microtime(true) - $t0) * 1000, $ok);
            }
        }
    } else {
        echo "\n  (probe skipped: no bokun_config row)\n";
    }
}

echo "\ndone (nothing was written)\n";
