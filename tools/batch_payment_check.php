<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only (step 5.3): never deployed
/**
 * Step 5.3 measurement - READ ONLY. The Payments page records a batch by POSTing one payment per
 * selected tour, all with the SAME amount and the same minute-resolution timestamp. This finds
 * those clusters so we can say how often a batch was used and whether any of them look like the
 * owner typed a TOTAL where the form wanted a per-tour amount.
 */
$apiDir = getenv('FWL_API_DIR') ?: __DIR__ . '/../public_html/api';
require_once $apiDir . '/config.php';

$days = 90;
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--days=(\d+)$/', $a, $m)) { $days = (int) $m[1]; }
}

function rows($conn, $sql) {
    $r = $conn->query($sql);
    if (!$r) { echo "  ! " . $conn->error . "\n"; return []; }
    $o = [];
    while ($x = $r->fetch_assoc()) { $o[] = $x; }
    return $o;
}
function one($conn, $sql) { $r = rows($conn, $sql); return $r ? array_values($r[0])[0] : null; }

echo "=== step 5.3: batch payments in the last $days days ===\n";
echo "db: " . one($conn, "SELECT DATABASE()") . "   now(utc): " . gmdate('c') . "\n\n";

printf("payments total (all time)   : %s rows, EUR %s\n",
    one($conn, "SELECT COUNT(*) FROM payments"), one($conn, "SELECT ROUND(SUM(amount),2) FROM payments"));
printf("payments in the window      : %s rows, EUR %s\n",
    one($conn, "SELECT COUNT(*) FROM payments WHERE payment_date >= CURDATE() - INTERVAL $days DAY"),
    one($conn, "SELECT COALESCE(ROUND(SUM(amount),2),0) FROM payments WHERE payment_date >= CURDATE() - INTERVAL $days DAY"));
printf("newest / oldest payment_date: %s / %s\n",
    one($conn, "SELECT MAX(payment_date) FROM payments"), one($conn, "SELECT MIN(payment_date) FROM payments"));

// A batch = several rows, same guide, same amount, same minute (payment_time) - what the loop writes.
$clusterSql = "SELECT p.guide_id, g.name guide_name, p.payment_date, p.payment_time, p.amount,
                      COUNT(*) rows_written, ROUND(COUNT(*) * p.amount, 2) total_written,
                      GROUP_CONCAT(p.tour_id ORDER BY p.tour_id) tour_ids,
                      GROUP_CONCAT(p.id ORDER BY p.id) payment_ids,
                      MIN(p.created_at) created_at
               FROM payments p LEFT JOIN guides g ON g.id = p.guide_id
               WHERE p.payment_date >= CURDATE() - INTERVAL $days DAY
               GROUP BY p.guide_id, p.payment_date, p.payment_time, p.amount
               HAVING COUNT(*) > 1
               ORDER BY rows_written DESC, p.payment_date DESC";
$clusters = rows($conn, $clusterSql);

$batchRows = 0;
foreach ($clusters as $c) { $batchRows += (int) $c['rows_written']; }
printf("\nbatch-looking clusters (same guide + amount + minute, 2+ rows): %d\n", count($clusters));
printf("payment rows that came from such a batch: %d\n", $batchRows);

echo "\nexamples:\n";
$shown = 0;
foreach ($clusters as $c) {
    if ($shown++ >= 6) { break; }
    printf("  %s %s  %-22s %s x EUR %-8s = EUR %-9s  tours %s (payments %s)\n",
        $c['payment_date'], substr((string) $c['payment_time'], 0, 5), substr((string) $c['guide_name'], 0, 22),
        $c['rows_written'], $c['amount'], $c['total_written'], $c['tour_ids'], $c['payment_ids']);
}
if (!$clusters) { echo "  (none)\n"; }

// "Accidental multiplication" is a judgement, not a fact in the data. What we CAN show: how the
// per-row amount compares with what this guide is normally paid for one departure.
echo "\n--- how each cluster's per-row amount compares with that guide's usual single payment ---\n";
foreach (array_slice($clusters, 0, 8) as $c) {
    $gid = (int) $c['guide_id'];
    $typical = one($conn, "SELECT ROUND(AVG(amount),2) FROM (
                               SELECT p.amount FROM payments p
                               WHERE p.guide_id = $gid
                               GROUP BY p.payment_date, p.payment_time, p.amount
                               HAVING COUNT(*) = 1) single_rows");
    printf("  %s %s  %-20s per-row EUR %-8s | that guide's usual single payment EUR %s\n",
        $c['payment_date'], substr((string) $c['payment_time'], 0, 5),
        substr((string) $c['guide_name'], 0, 20), $c['amount'], $typical === null ? 'n/a' : $typical);
}

// Same minute, same guide, DIFFERENT amounts - would mean the rows were not one batch.
$mixed = rows($conn, "SELECT p.payment_date, p.payment_time, p.guide_id, COUNT(DISTINCT p.amount) amounts, COUNT(*) n
                      FROM payments p
                      WHERE p.payment_date >= CURDATE() - INTERVAL $days DAY
                      GROUP BY p.payment_date, p.payment_time, p.guide_id
                      HAVING COUNT(*) > 1 AND COUNT(DISTINCT p.amount) > 1");
printf("\nclusters with the same guide+minute but different amounts: %d\n", count($mixed));

// The mixed-guide override: rows written against a guide who is not the tour's guide.
$wrongGuide = rows($conn, "SELECT p.id, p.payment_date, p.amount, p.guide_id paid_to, t.guide_id tour_guide,
                                  gp.name paid_to_name, gt.name tour_guide_name, p.tour_id
                           FROM payments p
                           JOIN tours t ON t.id = p.tour_id
                           LEFT JOIN guides gp ON gp.id = p.guide_id
                           LEFT JOIN guides gt ON gt.id = t.guide_id
                           WHERE t.guide_id IS NOT NULL AND p.guide_id <> t.guide_id
                           ORDER BY p.payment_date DESC LIMIT 10");
printf("\npayments recorded against a guide who is NOT the tour's guide (all time): %d shown\n", count($wrongGuide));
foreach ($wrongGuide as $w) {
    printf("  payment %-5s %s  EUR %-8s tour %-6s paid to %-18s but the tour's guide is %s\n",
        $w['id'], $w['payment_date'], $w['amount'], $w['tour_id'],
        substr((string) $w['paid_to_name'], 0, 18), substr((string) $w['tour_guide_name'], 0, 18));
}

echo "\ndone (nothing was written)\n";
