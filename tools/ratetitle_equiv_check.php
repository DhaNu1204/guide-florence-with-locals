<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only (step 3.4): never deployed
/**
 * Step 3.4 correctness proof - READ ONLY (SELECTs + one Bokun GET per distinct product).
 *
 * Today the sync calls GET /activity.json/{productId} for every booking and picks the rate whose
 * id matches productBookings[0].fields.rateId, then derives the language from that rate title.
 * Step 3.4 wants to use productBookings[0].rateTitle, which is already in the payload.
 *
 * This script proves the two give the same answer: for every distinct product id in the window it
 * fetches the product ONCE and compares, per booking, the API rate title against the payload
 * rate title, and the language derived from each.
 */
$apiDir = getenv('FWL_API_DIR') ?: __DIR__ . '/../public_html/api';
require_once $apiDir . '/config.php';
require_once $apiDir . '/BokunAPI.php';
define('BOKUN_SYNC_LIB', true);
require_once $apiDir . '/bokun_sync.php';

// the exact keyword ladder BokunAPI uses on a rate title
function langFromRateTitle($title) {
    $t = strtolower((string) $title);
    foreach (['italian' => 'Italian', 'spanish' => 'Spanish', 'french' => 'French',
              'german' => 'German', 'english' => 'English'] as $needle => $lang) {
        if (strpos($t, $needle) !== false) { return $lang; }
    }
    return 'English'; // the else-branch of the existing loop: default rate = English
}

$cfg = getBokunConfig();
if (!$cfg || empty($cfg['access_key'])) { fwrite(STDERR, "no bokun_config\n"); exit(1); }
$api = new BokunAPI(['access_key' => $cfg['access_key'], 'secret_key' => $cfg['secret_key'], 'vendor_id' => $cfg['vendor_id']]);

$res = $conn->query("SELECT id, language, bokun_data FROM tours
                     WHERE date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                       AND date <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)
                       AND bokun_data IS NOT NULL AND bokun_data <> ''");
$rows = [];
while ($r = $res->fetch_assoc()) {
    $b = json_decode($r['bokun_data'], true);
    if (!is_array($b)) { continue; }
    $pb = $b['productBookings'][0] ?? ($b['activityBookings'][0] ?? []);
    if (!isset($pb['fields']['rateId'], $pb['product']['id'])) { continue; }
    $rows[] = ['id' => $r['id'], 'stored' => $r['language'],
               'pid' => (string) $pb['product']['id'], 'rid' => (string) $pb['fields']['rateId'],
               'payloadTitle' => $pb['rateTitle'] ?? ($pb['fields']['rateTitle'] ?? null)];
}
echo "=== rate-title equivalence over " . count($rows) . " bookings ===\n";

$productRates = []; $calls = 0;
foreach ($rows as $r) {
    if (!isset($productRates[$r['pid']])) {
        $calls++;
        try {
            $p = $api->getProduct($r['pid']);
            $map = [];
            foreach (($p['rates'] ?? []) as $rate) {
                if (isset($rate['id'], $rate['title'])) { $map[(string) $rate['id']] = $rate['title']; }
            }
            $productRates[$r['pid']] = $map;
        } catch (Exception $e) {
            $productRates[$r['pid']] = null;
            echo "  ! product {$r['pid']} failed: " . $e->getMessage() . "\n";
        }
    }
}
echo "  distinct products fetched (one call each): $calls\n";

$same = 0; $diffTitle = 0; $diffLang = 0; $noApiRate = 0; $noPayload = 0; $examples = [];
foreach ($rows as $r) {
    $map = $productRates[$r['pid']];
    if ($map === null) { continue; }
    $apiTitle = $map[$r['rid']] ?? null;
    if ($apiTitle === null) { $noApiRate++; continue; }
    if ($r['payloadTitle'] === null || $r['payloadTitle'] === '') { $noPayload++; continue; }
    $la = langFromRateTitle($apiTitle);
    $lp = langFromRateTitle($r['payloadTitle']);
    if ($apiTitle === $r['payloadTitle']) { $same++; }
    else {
        $diffTitle++;
        if (count($examples) < 5) { $examples[] = "tour {$r['id']}: api='{$apiTitle}' payload='{$r['payloadTitle']}'"; }
    }
    if ($la !== $lp) { $diffLang++; }
}
printf("  identical rate title  : %d\n", $same);
printf("  different rate title  : %d\n", $diffTitle);
printf("  DIFFERENT LANGUAGE    : %d   <- must be 0\n", $diffLang);
printf("  rateId missing from the product's rate list (API path gives nothing today): %d\n", $noApiRate);
printf("  payload carries no rate title (would still need the call): %d\n", $noPayload);
foreach ($examples as $e) { echo "    e.g. $e\n"; }

echo "\ndone (nothing was written)\n";
