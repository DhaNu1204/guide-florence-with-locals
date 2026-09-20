<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only (step 6.3): never deployed
/**
 * Step 6.3 - READ ONLY. The museum heading each product would get in the radio message, so the
 * owner can correct the mapping before anything is built on top of it.
 *
 * Rule under test: the first museum named in the product title decides the section
 * (Uffizi > Accademia > Pitti > Borghese > Duomo/other). "Confident" means exactly one museum
 * keyword matched; anything else is a guess and is flagged.
 */
$apiDir = getenv('FWL_API_DIR') ?: __DIR__ . '/../public_html/api';
require_once $apiDir . '/config.php';
require_once $apiDir . '/radio_helpers.php';

$res = $conn->query("SELECT p.bokun_product_id, p.title, p.product_type,
                            (SELECT COUNT(*) FROM tours t WHERE t.product_id = p.bokun_product_id
                              AND t.cancelled = 0 AND t.date >= CURDATE() - INTERVAL 60 DAY) recent
                     FROM products p ORDER BY p.product_type, p.title");

printf("%-10s %-8s %-11s %-9s %s\n", 'PRODUCT', 'TYPE', 'MUSEUM', 'CONFIDENT', 'TITLE');
echo str_repeat('-', 118) . "\n";
$guesses = [];
while ($p = $res->fetch_assoc()) {
    $m = radioMuseumForTitle($p['title']);
    if (!$m['confident'] && $p['product_type'] !== 'ticket') { $guesses[] = $p; }
    printf("%-10s %-8s %-11s %-9s %s\n",
        $p['bokun_product_id'], $p['product_type'], $m['museum'],
        $m['confident'] ? 'yes' : 'GUESS', substr($p['title'], 0, 62));
}

echo "\nGuided products whose museum is a guess: " . count($guesses) . "\n";
foreach ($guesses as $g) {
    $m = radioMuseumForTitle($g['title']);
    printf("  product %-9s -> %-10s  (%s)  %s\n", $g['bokun_product_id'], $m['museum'], $m['why'], $g['title']);
}
echo "\ndone (nothing was written)\n";
