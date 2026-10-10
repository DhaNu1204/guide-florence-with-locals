<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only (step 6.17): never deployed
/**
 * Step 6.17 - one-off backfill of tour_groups.billing_product_id on EXISTING manual merges whose
 * live bookings mix categories (Combo + Uffizi), owner-approved 2026-10-10: each counts as its
 * default product (highest guide rate -> Combo) and takes that product's title. Same-category
 * merges and auto-groups are not touched. Rows that already have a billing product are skipped.
 * updated_at is kept as it was (this is a data repair, not an edit).
 *
 *   FWL_API_DIR=<api> php tools/billing_product_backfill.php            # dry run: the list only
 *   FWL_API_DIR=<api> php tools/billing_product_backfill.php --apply    # write
 */
$apiDir = getenv('FWL_API_DIR') ?: __DIR__ . '/../public_html/api';
require $apiDir . '/config.php';
require_once $apiDir . '/lib/billing_product.php';
$apply = in_array('--apply', $argv, true);

ensureGroupBillingProductColumn($conn);
$settings = pnlLoadSettings($conn);
$ids = [];
$res = $conn->query("SELECT id FROM tour_groups WHERE is_manual_merge = 1 AND billing_product_id IS NULL ORDER BY group_date, id");
while ($r = $res->fetch_assoc()) { $ids[] = (int) $r['id']; }

$n = 0; $titles = 0; $written = 0;
foreach ($ids as $gid) {
    $members = groupBillingMembers($conn, $gid);
    $pid = billingDefaultProductId($members, $settings);
    if ($pid === null) { continue; }
    $n++;
    $g = $conn->query("SELECT group_date, COALESCE(departure_time, group_time) AS t, display_name, guide_name FROM tour_groups WHERE id = $gid")->fetch_assoc();
    $title = null;
    foreach ($members as $m) { if ((int) $m['product_id'] === $pid) { $title = $m['title']; break; } }
    $changes = $title !== $g['display_name'];
    if ($changes) { $titles++; }
    $mix = [];
    foreach ($members as $m) { $c = pnlCategory($m['title']); $mix[$c] = ($mix[$c] ?? 0) + 1; }
    printf("g%d %s %s %s | %s | billing %d%s\n", $gid, $g['group_date'], substr($g['t'], 0, 5), $g['guide_name'] ?: '-',
        json_encode($mix), $pid, $changes ? ' | title: ' . mb_substr($g['display_name'], 0, 40) . ' -> ' . mb_substr($title, 0, 40) : '');
    if ($apply) {
        $s = $conn->prepare("UPDATE tour_groups SET billing_product_id = ?, display_name = ?, updated_at = updated_at
                             WHERE id = ? AND billing_product_id IS NULL");
        $s->bind_param('isi', $pid, $title, $gid);
        $s->execute(); $written += $s->affected_rows; $s->close();
    }
}
echo "\nmixed manual groups without a billing product: $n (title changes $titles)" . ($apply ? ", rows written $written" : ' - dry run, nothing written') . "\n";
