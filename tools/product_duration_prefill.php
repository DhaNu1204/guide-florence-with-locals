<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only (step 7.2b): never deployed
/**
 * Step 7.2b - prefill products.duration_minutes from Bokun (GET /activity.json/{id}, product-level
 * durationWeeks/Days/Hours/Minutes). Read-only towards Bokun; fills ONLY products whose duration is
 * still NULL, never overwrites a value an admin set on /products.
 *
 *   FWL_API_DIR=<api dir> php tools/product_duration_prefill.php            (dry run: shows what it would set)
 *   FWL_API_DIR=<api dir> php tools/product_duration_prefill.php --apply
 */
$apiDir = getenv('FWL_API_DIR') ?: __DIR__ . '/../public_html/api';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/cli';
define('BOKUN_SYNC_LIB', true);
require $apiDir . '/config.php';
require_once $apiDir . '/bokun_sync.php';
require_once $apiDir . '/lib/guide_product_fields.php';

$apply = in_array('--apply', $argv, true);
ensureProductDurationColumn($conn);
$api = new BokunAPI(getBokunConfig() ?: []);

$rows = [];
$r = $conn->query("SELECT p.bokun_product_id, p.product_type, p.duration_minutes,
                          COALESCE(NULLIF(TRIM(p.title), ''), (SELECT MAX(t.title) FROM tours t WHERE t.product_id = p.bokun_product_id)) AS title
                   FROM products p ORDER BY p.product_type, p.bokun_product_id");
while ($x = $r->fetch_assoc()) { $rows[] = $x; }

$set = []; $without = []; $kept = 0;
foreach ($rows as $p) {
    $id = (int) $p['bokun_product_id'];
    if ($p['duration_minutes'] !== null) { $kept++; continue; }
    $minutes = null; $why = '';
    try {
        $minutes = bokunDurationMinutes($api->getProduct($id));
        if ($minutes === null) $why = 'Bokun has no duration for it';
    } catch (Throwable $e) {
        $why = 'Bokun did not return the product (' . substr($e->getMessage(), 0, 60) . ')';
    }
    if ($minutes !== null) {
        if ($apply) {
            $s = $conn->prepare("UPDATE products SET duration_minutes = ? WHERE bokun_product_id = ? AND duration_minutes IS NULL");
            $s->bind_param('ii', $minutes, $id);
            $s->execute();
            $s->close();
        }
        $set[] = sprintf("  %-8d %-6s %4d min  %s", $id, $p['product_type'], $minutes, $p['title']);
    } else {
        $without[] = sprintf("  %-8d %-6s  %s  - %s", $id, $p['product_type'], $p['title'], $why);
    }
    usleep(400000); // gentle on Bokun
}

$db = $conn->query("SELECT DATABASE() d")->fetch_assoc()['d'];
echo ($apply ? 'APPLIED' : 'DRY RUN') . " on $db: " . count($rows) . " products, $kept already had a duration (left alone)\n";
echo count($set) . ($apply ? " got a duration:\n" : " would get a duration:\n") . implode("\n", $set) . "\n";
echo count($without) . " without a duration (120 min is assumed for them):\n" . implode("\n", $without) . "\n";
