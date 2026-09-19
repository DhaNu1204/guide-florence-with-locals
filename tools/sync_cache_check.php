<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only check (step 3.4): never deployed
/**
 * Step 3.4 unit checks for the pure parts of the sync speed-up (no database, no Bokun):
 *   php tools/sync_cache_check.php
 * Exit code 0 = all assertions hold.
 */

require_once __DIR__ . '/../public_html/api/BokunAPI.php';

$failures = 0;
function check($label, $ok, $detail = '') {
    global $failures;
    if (!$ok) { $failures++; }
    printf("%s  %s%s\n", $ok ? 'ok  ' : 'FAIL', $label, $detail !== '' ? "  ($detail)" : '');
}

// --- languageFromRateTitle: the ladder the sync has always used --------------------------
foreach ([
    'Uffizi Tour - Italian'                 => 'Italian',
    'Visita guidata in SPANISH'             => 'Spanish',
    'french guided tour'                    => 'French',
    'Tour auf German'                       => 'German',
    'English guided tour'                   => 'English',
    'Entry Ticket + Audio Guide'            => 'English', // no language named = default rate
    'Accademia Ticket - Audio Guide'        => 'English',
    ''                                      => 'English',
] as $title => $expected) {
    $got = BokunAPI::languageFromRateTitle($title);
    check("'" . ($title ?: '(empty)') . "' -> $expected", $got === $expected, $got);
}
// order matters: the first keyword found wins, exactly as the old if/elseif chain did
check("'Italian and English' -> Italian (first match wins)",
    BokunAPI::languageFromRateTitle('Italian and English') === 'Italian');

// --- the per-product cache ----------------------------------------------------------------
class CountingBokunAPI extends BokunAPI {
    public $calls = 0;
    public $failOn = null;
    public function getProduct($productId) {
        $this->calls++;
        if ($this->failOn !== null && (string) $productId === (string) $this->failOn) {
            throw new Exception('boom');
        }
        return ['id' => $productId, 'rates' => [['id' => 7, 'title' => 'Tour in Italian']]];
    }
}

BokunAPI::resetRequestStats();
$api = new CountingBokunAPI(['access_key' => 'k', 'secret_key' => 's', 'vendor_id' => 1]);
for ($i = 0; $i < 50; $i++) { $api->getProductCached(961802); }
check('50 bookings on one product -> 1 HTTP call', $api->calls === 1, 'calls=' . $api->calls);
$stats = BokunAPI::requestStats();
check('... 1 cache miss, 49 hits',
    $stats['product_calls'] === 1 && $stats['product_cache_hits'] === 49, json_encode($stats));

$api->getProductCached(809838);
check('a second product is fetched once', $api->calls === 2, 'calls=' . $api->calls);
check('cached product keeps its rates',
    $api->getProductCached(961802)['rates'][0]['title'] === 'Tour in Italian');

// a product that cannot be fetched is remembered as unavailable, not retried per booking
$api2 = new CountingBokunAPI(['access_key' => 'k', 'secret_key' => 's', 'vendor_id' => 1]);
$api2->failOn = 999;
$first = $api2->getProductCached(999);
for ($i = 0; $i < 20; $i++) { $api2->getProductCached(999); }
check('a failing product is fetched once and returns null',
    $first === null && $api2->calls === 1, 'calls=' . $api2->calls . ' first=' . var_export($first, true));

// --- counters -----------------------------------------------------------------------------
BokunAPI::resetRequestStats();
$zero = BokunAPI::requestStats();
check('resetRequestStats zeroes the counters',
    $zero['bokun_requests'] === 0 && $zero['product_calls'] === 0
    && $zero['product_cache_hits'] === 0 && $zero['rate_limit_sleeps'] === 0, json_encode($zero));

echo $failures === 0 ? "\nall checks passed\n" : "\n$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
