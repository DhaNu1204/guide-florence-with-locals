<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only (step 4.6): never deployed
/**
 * Step 4.6 - prefill products.meeting_point from Bokun (GET /activity.json/{id},
 * startPoints[0].address.addressLine1, shortened before ", Florence"/", Firenze"). Read-only
 * towards Bokun; fills ONLY products whose meeting point is still NULL, tours only.
 *
 *   FWL_API_DIR=<api dir> php tools/product_meeting_point_prefill.php            (dry run)
 *   FWL_API_DIR=<api dir> php tools/product_meeting_point_prefill.php --apply
 */
$apiDir = getenv('FWL_API_DIR') ?: __DIR__ . '/../public_html/api';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/cli';
define('BOKUN_SYNC_LIB', true);
require $apiDir . '/config.php';
require_once $apiDir . '/bokun_sync.php';
require_once $apiDir . '/lib/guide_product_fields.php';

/** "Statua di Leonardo da Vinci, Piazzale degli Uffizi, Florence, Metropolitan City..." -> before ", Florence" */
function meetingPointShort($line) {
    $line = trim((string) $line);
    if ($line === '') return null;
    $cut = preg_split('/,\s*(Florence|Firenze)\b/i', $line, 2);
    $short = trim($cut[0], " ,");
    return mb_substr($short !== '' ? $short : $line, 0, 255);
}

$apply = in_array('--apply', $argv, true);
ensureProductMeetingPointColumn($conn);
$api = new BokunAPI(getBokunConfig() ?: []);

$rows = [];
$r = $conn->query("SELECT p.bokun_product_id, p.meeting_point,
                          COALESCE(NULLIF(TRIM(p.title), ''), (SELECT MAX(t.title) FROM tours t WHERE t.product_id = p.bokun_product_id)) AS title
                   FROM products p WHERE p.product_type = 'tour' ORDER BY p.bokun_product_id");
while ($x = $r->fetch_assoc()) { $rows[] = $x; }

$set = []; $without = []; $kept = 0;
foreach ($rows as $p) {
    $id = (int) $p['bokun_product_id'];
    if ($p['meeting_point'] !== null) { $kept++; continue; }
    $mp = null; $why = '';
    try {
        $prod = $api->getProduct($id);
        $mp = meetingPointShort($prod['startPoints'][0]['address']['addressLine1'] ?? '');
        if ($mp === null) $why = 'Bokun has no start point address';
    } catch (Throwable $e) {
        $why = 'Bokun did not return the product (' . substr($e->getMessage(), 0, 60) . ')';
    }
    if ($mp !== null) {
        if ($apply) {
            $s = $conn->prepare("UPDATE products SET meeting_point = ? WHERE bokun_product_id = ? AND meeting_point IS NULL");
            $s->bind_param('si', $mp, $id);
            $s->execute();
            $s->close();
        }
        $set[] = sprintf("  %-8d %s  ->  %s", $id, $p['title'], $mp);
    } else {
        $without[] = sprintf("  %-8d %s  - %s", $id, $p['title'], $why);
    }
    usleep(400000); // gentle on Bokun
}

$db = $conn->query("SELECT DATABASE() d")->fetch_assoc()['d'];
echo ($apply ? 'APPLIED' : 'DRY RUN') . " on $db: " . count($rows) . " tour products, $kept already had a meeting point (left alone)\n";
echo count($set) . ($apply ? " got a meeting point:\n" : " would get a meeting point:\n") . implode("\n", $set) . "\n";
echo count($without) . " without one:\n" . implode("\n", $without) . "\n";
