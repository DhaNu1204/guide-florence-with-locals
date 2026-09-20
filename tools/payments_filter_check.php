<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only (step 3.8): never deployed
/**
 * Step 3.8 measurement - READ ONLY. guide-payments.php decides "is this a guided tour the guide
 * is owed for?" in two different ways: the products table (product_type) in two places, and a
 * list of title keywords in eight others. This counts where the two disagree on the rows that
 * Pending Payments actually shows.
 */
$apiDir = getenv('FWL_API_DIR') ?: __DIR__ . '/../public_html/api';
require_once $apiDir . '/config.php';

$TITLE_FILTER = "t.title NOT LIKE '%Entry Ticket%' AND t.title NOT LIKE '%Entrance Ticket%'
                 AND t.title NOT LIKE '%Priority Ticket%' AND t.title NOT LIKE '%Skip the Line%'
                 AND t.title NOT LIKE '%Skip-the-Line%'";
$PRODUCT_FILTER = "NOT EXISTS (SELECT 1 FROM products pr WHERE pr.bokun_product_id = t.product_id AND pr.product_type = 'ticket')";
// The rows Pending Payments considers at all: a past, non-cancelled tour with a guide and no payment.
$BASE = "FROM tours t
         LEFT JOIN payments p ON p.tour_id = t.id AND p.guide_id = t.guide_id
         WHERE t.guide_id IS NOT NULL
           AND CONCAT(t.date, ' ', COALESCE(t.time, '00:00:00')) < NOW()
           AND t.cancelled = 0
           AND p.id IS NULL";

function rows($conn, $sql) {
    $r = $conn->query($sql);
    if (!$r) { echo "  ! " . $conn->error . "\n"; return []; }
    $o = [];
    while ($x = $r->fetch_assoc()) { $o[] = $x; }
    return $o;
}
function n($conn, $sql) { $r = rows($conn, $sql); return $r ? (int) array_values($r[0])[0] : 0; }

echo "=== step 3.8: title keywords vs the products table ===\n";
echo "db: " . rows($conn, "SELECT DATABASE() d")[0]['d'] . "   now(utc): " . gmdate('c') . "\n\n";

echo "--- products table ---\n";
foreach (rows($conn, "SELECT COALESCE(product_type,'(null)') pt, COUNT(*) n FROM products GROUP BY product_type ORDER BY n DESC") as $r) {
    printf("  %-12s %s\n", $r['pt'], $r['n']);
}
printf("  tours with no product_id: %d\n", n($conn, "SELECT COUNT(*) FROM tours WHERE product_id IS NULL"));
printf("  tours whose product_id has no products row: %d\n",
    n($conn, "SELECT COUNT(*) FROM tours t WHERE t.product_id IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM products pr WHERE pr.bokun_product_id = t.product_id)"));

echo "\n--- pending-payment candidates (past, guided, unpaid, not cancelled) ---\n";
printf("  with NO filter at all          : %d bookings\n", n($conn, "SELECT COUNT(*) $BASE"));
printf("  kept by the TITLE keywords     : %d bookings\n", n($conn, "SELECT COUNT(*) $BASE AND $TITLE_FILTER"));
printf("  kept by the PRODUCTS table     : %d bookings\n", n($conn, "SELECT COUNT(*) $BASE AND $PRODUCT_FILTER"));

$wrongIn = "SELECT COUNT(*) $BASE AND $TITLE_FILTER AND NOT ($PRODUCT_FILTER)";
$wrongOut = "SELECT COUNT(*) $BASE AND NOT ($TITLE_FILTER) AND $PRODUCT_FILTER";
printf("\n  SHOWN BUT SHOULD NOT BE (ticket product, title has no keyword): %d bookings\n", n($conn, $wrongIn));
printf("  HIDDEN BUT SHOULD BE SHOWN (real tour whose title has a keyword): %d bookings\n", n($conn, $wrongOut));

echo "\n  examples of rows Pending Payments shows today but should not:\n";
foreach (rows($conn, "SELECT t.id, t.date, t.title, t.product_id,
                             (SELECT name FROM guides g WHERE g.id = t.guide_id) guide
                      $BASE AND $TITLE_FILTER AND NOT ($PRODUCT_FILTER)
                      ORDER BY t.date DESC LIMIT 6") as $r) {
    printf("    tour %-6s %s  product %-9s %-52s (%s)\n", $r['id'], $r['date'], $r['product_id'],
        substr($r['title'], 0, 52), $r['guide'] ?: 'no guide');
}

echo "\n  examples of rows it hides today but should show:\n";
foreach (rows($conn, "SELECT t.id, t.date, t.title, t.product_id,
                             (SELECT name FROM guides g WHERE g.id = t.guide_id) guide
                      $BASE AND NOT ($TITLE_FILTER) AND $PRODUCT_FILTER
                      ORDER BY t.date DESC LIMIT 6") as $r) {
    printf("    tour %-6s %s  product %-9s %-52s (%s)\n", $r['id'], $r['date'], $r['product_id'],
        substr($r['title'], 0, 52), $r['guide'] ?: 'no guide');
}

// Same thing counted the way the page counts it: per departure, not per booking.
$unit = "IF(t.group_id IS NOT NULL, CONCAT('g', t.group_id), CONCAT('t', t.id))";
printf("\n  as DEPARTURES: title filter %d, products filter %d\n",
    n($conn, "SELECT COUNT(*) FROM (SELECT $unit u $BASE AND $TITLE_FILTER GROUP BY u) x"),
    n($conn, "SELECT COUNT(*) FROM (SELECT $unit u $BASE AND $PRODUCT_FILTER GROUP BY u) x"));

echo "\ndone (nothing was written)\n";
