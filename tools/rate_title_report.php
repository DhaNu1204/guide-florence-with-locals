<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only (step 6.14): never deployed
/**
 * Step 6.14 "read first" + backfill proof - READ ONLY.
 *
 *   FWL_API_DIR=/path/to/api php tools/rate_title_report.php [--codes=GET-1,GET-2]
 *
 * For every tour with a stored Bokun payload: where the rate title sits
 * (productBookings[0].rateTitle vs other places), and per product the bookings per rate
 * title, upcoming / past, live / cancelled. Lists every product with more than one rate
 * title in use. If tours.rate_title exists, also compares it with the payload.
 */
$apiDir = getenv('FWL_API_DIR') ?: __DIR__ . '/../public_html/api';
require_once $apiDir . '/config.php';

$codes = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--codes=(.+)$/', $arg, $m)) { $codes = array_filter(explode(',', $m[1])); }
}

$today = date('Y-m-d');
$hasCol = false;
$r = $conn->query("SHOW COLUMNS FROM tours LIKE 'rate_title'");
if ($r && $r->num_rows > 0) { $hasCol = true; }

$sql = "SELECT id, date, product_id, title, cancelled, external_id, bokun_confirmation_code, participants, bokun_data"
     . ($hasCol ? ", rate_title" : "") . " FROM tours WHERE bokun_data IS NOT NULL AND bokun_data <> ''";
$res = $conn->query($sql, MYSQLI_USE_RESULT);

$paths = ['productBookings[0].rateTitle' => 0, 'productBookings[0].fields.rateTitle only' => 0, 'none' => 0];
$byProduct = [];   // pid => ['title'=>..., 'rates'=>[rate=>[up_live,up_canc,past_live,past_canc,up_pax]]]
$total = 0; $colMatch = 0; $colDiff = 0; $colNullPayloadHas = 0; $diffIds = [];
$found = [];

while ($row = $res->fetch_assoc()) {
    $total++;
    $d = json_decode($row['bokun_data'], true);
    $pb = is_array($d) ? ($d['productBookings'][0] ?? null) : null;
    $rate = null;
    if (is_array($pb) && isset($pb['rateTitle']) && is_string($pb['rateTitle']) && trim($pb['rateTitle']) !== '') {
        $rate = $pb['rateTitle']; $paths['productBookings[0].rateTitle']++;
    } elseif (is_array($pb) && !empty($pb['fields']['rateTitle'])) {
        $paths['productBookings[0].fields.rateTitle only']++;
    } else {
        $paths['none']++;
    }
    $pid = (string) ($row['product_id'] ?: ($pb['product']['id'] ?? '?'));
    if (!isset($byProduct[$pid])) {
        $byProduct[$pid] = ['title' => $row['title'], 'ext' => $pb['product']['externalId'] ?? ($pb['productExternalId'] ?? ''), 'rates' => []];
    }
    $key = $rate === null ? '(no rate title)' : $rate;
    if (!isset($byProduct[$pid]['rates'][$key])) { $byProduct[$pid]['rates'][$key] = [0, 0, 0, 0, 0]; }
    $up = $row['date'] >= $today; $canc = (int) $row['cancelled'] === 1;
    $byProduct[$pid]['rates'][$key][($up ? 0 : 2) + ($canc ? 1 : 0)]++;
    if ($up && !$canc) { $byProduct[$pid]['rates'][$key][4] += (int) $row['participants']; }

    if ($hasCol) {
        $stored = $row['rate_title'];
        if ($stored === $rate) { $colMatch++; }
        else { $colDiff++; if ($stored === null && $rate !== null) { $colNullPayloadHas++; } if (count($diffIds) < 20) { $diffIds[] = $row['id']; } }
    }
    foreach ($codes as $c) {
        if ($c === $row['bokun_confirmation_code'] || $c === $row['external_id']) {
            $found[$c] = sprintf("t%d %s pid=%s pax=%d cancelled=%d rate=%s%s", $row['id'], $row['date'], $pid,
                $row['participants'], $row['cancelled'], json_encode($rate),
                $hasCol ? ' stored=' . json_encode($row['rate_title']) : '');
        }
    }
}
$res->close();

echo "today (Rome) $today | tours with bokun_data: $total\n";
echo "rate title location:\n";
foreach ($paths as $p => $n) { printf("  %-42s %6d\n", $p, $n); }

echo "\nproducts with MORE THAN ONE rate title in use (up_live/up_canc/past_live/past_canc, upcoming live PAX):\n";
foreach ($byProduct as $pid => $p) {
    $named = array_diff(array_keys($p['rates']), ['(no rate title)']);
    if (count($named) < 2 && !preg_match('/vasari/i', $p['title'])) { continue; }
    printf("  product %s  ext=%s  %s\n", $pid, $p['ext'] ?: '-', $p['title']);
    ksort($p['rates']);
    foreach ($p['rates'] as $rate => $c) {
        printf("    %-48s %4d/%3d/%5d/%4d  pax %d\n", mb_substr($rate, 0, 48), $c[0], $c[1], $c[2], $c[3], $c[4]);
    }
}

if ($hasCol) {
    printf("\ntours.rate_title vs payload: match %d | differ %d (stored NULL but payload has one: %d)%s\n",
        $colMatch, $colDiff, $colNullPayloadHas, $diffIds ? ' ids ' . implode(',', $diffIds) : '');
} else {
    echo "\ntours.rate_title: column does not exist yet\n";
}
foreach ($codes as $c) { echo "$c: " . ($found[$c] ?? 'NOT FOUND') . "\n"; }
