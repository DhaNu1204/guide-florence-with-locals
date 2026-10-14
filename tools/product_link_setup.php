<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only (step 6.22): never deployed
/**
 * Step 6.22 - owner-approved 2026-10-15: link the four new-Viator-account products to the
 * originals they copy, and create the products rows that do not exist yet (Bokun has the
 * products, but no booking has arrived, so the sync never registered them). 1324951 is a ticket
 * product: its own row is created as 'ticket' so it is right even before the link is read.
 *
 *   5686765P1 1324951 -> 961802  (Uffizi tickets)
 *   5686765P2 1324950 -> 961801  (Uffizi Small Group Guided Tour with Tickets)
 *   5686765P3 1324953 -> 962885  (Uffizi & Accademia Combo)
 *   5686765P4 1324952 -> 1130528 (Uffizi with optional Vasari)
 *
 * A link already set to the same original is left alone; a DIFFERENT existing link is reported and
 * not touched. Titles of new rows come from Bokun (GET /activity.json, read only), else the names
 * below. Writes only the products table.
 *
 *   FWL_API_DIR=<api> php tools/product_link_setup.php            # dry run
 *   FWL_API_DIR=<api> php tools/product_link_setup.php --apply    # write
 */
$apiDir = getenv('FWL_API_DIR') ?: __DIR__ . '/../public_html/api';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/cli';
define('BOKUN_SYNC_LIB', true);
require $apiDir . '/config.php';
require_once $apiDir . '/bokun_sync.php';
require_once $apiDir . '/lib/effective_product.php';
$apply = in_array('--apply', $argv, true);

$LINKS = [
    1324951 => ['orig' => 961802,  'type' => 'ticket', 'name' => 'Uffizi Gallery Reserved Entry Ticket with Audio Guide App'],
    1324950 => ['orig' => 961801,  'type' => 'tour',   'name' => 'Florence Small Group Uffizi Gallery Tour and Priority Ticket'],
    1324953 => ['orig' => 962885,  'type' => 'tour',   'name' => 'Uffizi Accademia and David Tour with Art Historian'],
    1324952 => ['orig' => 1130528, 'type' => 'tour',   'name' => 'Uffizi Small Group Tour with Optional Vasari Corridor Access'],
];

ensureProductGroupsAsColumn($conn);
$db = $conn->query("SELECT DATABASE() d")->fetch_assoc()['d'];
echo ($apply ? 'APPLY' : 'DRY RUN') . " on $db\n";

$row = function ($id) use ($conn) {
    $s = $conn->prepare("SELECT bokun_product_id, title, product_type, groups_as_product_id FROM products WHERE bokun_product_id = ?");
    $s->bind_param('i', $id); $s->execute();
    $r = $s->get_result()->fetch_assoc(); $s->close();
    return $r;
};
$api = null;
$problems = 0;
foreach ($LINKS as $pid => $l) {
    $orig = $row($l['orig']);
    if (!$orig) { echo "  $pid: original {$l['orig']} has no products row - NOT linked\n"; $problems++; continue; }
    if ($orig['groups_as_product_id'] !== null) { echo "  $pid: original {$l['orig']} is linked itself - NOT linked\n"; $problems++; continue; }
    $cur = $row($pid);
    if (!$cur) {
        $title = $l['name'];
        try {
            if ($api === null) { $api = new BokunAPI(getBokunConfig() ?: []); }
            $p = $api->getProduct($pid);
            if (is_array($p) && !empty($p['title'])) { $title = trim($p['title']); }
        } catch (Throwable $e) { /* keep the known name */ }
        echo "  $pid: create row '{$title}' type {$l['type']}, counts as {$l['orig']}\n";
        if ($apply) {
            $s = $conn->prepare("INSERT INTO products (bokun_product_id, title, product_type, groups_as_product_id) VALUES (?, ?, ?, ?)");
            $s->bind_param('issi', $pid, $title, $l['type'], $l['orig']); $s->execute(); $s->close();
        }
        continue;
    }
    if ($cur['groups_as_product_id'] !== null && (int) $cur['groups_as_product_id'] !== $l['orig']) {
        echo "  $pid: already counts as {$cur['groups_as_product_id']} - left alone\n"; $problems++; continue;
    }
    $sets = [];
    if ($cur['groups_as_product_id'] === null) { $sets[] = "link -> {$l['orig']}"; }
    if ($cur['product_type'] !== $l['type']) { $sets[] = "type {$cur['product_type']} -> {$l['type']}"; }
    echo "  $pid: " . ($sets ? implode(', ', $sets) : 'already done') . "  ({$cur['title']})\n";
    if ($apply && $sets) {
        $s = $conn->prepare("UPDATE products SET groups_as_product_id = ?, product_type = ? WHERE bokun_product_id = ?");
        $s->bind_param('isi', $l['orig'], $l['type'], $pid); $s->execute(); $s->close();
    }
}
echo "after:\n";
foreach (array_keys($LINKS) as $pid) {
    $r = $row($pid);
    echo "  " . ($r ? json_encode($r, JSON_UNESCAPED_UNICODE) : "$pid: no row") . "\n";
}
echo $problems ? "$problems problem(s)\n" : "ok\n";
