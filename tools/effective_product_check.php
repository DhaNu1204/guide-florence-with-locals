<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only (step 6.22): never deployed
/**
 * Step 6.22 checks for the effective product (products.groups_as_product_id).
 *
 *   php tools/effective_product_check.php                        # pure checks only
 *   FWL_API_DIR=<api> php tools/effective_product_check.php      # + the database checks below
 *   FWL_API_DIR=<api> php tools/effective_product_check.php --dry-run-p1=2026-10-20
 *       + a P1 (1324951) booking is inserted INSIDE A TRANSACTION, classified by every reader
 *         (Tours list filter, Tickets filter, payments exclusion, unassigned report, Daily P&L)
 *         and ROLLED BACK. Nothing is kept.
 */
$apiDir = getenv('FWL_API_DIR') ?: null;
$pass = 0; $fail = 0;
function ok($cond, $what) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok   $what\n"; } else { $fail++; echo "  FAIL $what\n"; }
}

if (!$apiDir) {
    require_once __DIR__ . '/../public_html/api/lib/effective_product.php';
} else {
    require $apiDir . '/config.php';
    require_once $apiDir . '/lib/effective_product.php';
    require_once $apiDir . '/lib/pnl_core.php';
    require_once $apiDir . '/lib/departure_queries.php';
}

echo "== pure ==\n";
$j = fwlProductJoinSql('t', 'pr');
ok(strpos($j, 'LEFT JOIN products pr_own ON pr_own.bokun_product_id = t.product_id') !== false, 'join reads the own product row');
ok(strpos($j, 'pr.bokun_product_id = COALESCE(pr_own.groups_as_product_id, t.product_id)') !== false, 'join follows the link one hop');
ok(strpos(fwlIsTicketSql('t'), "ept.product_type = 'ticket'") !== false && strpos(fwlIsTicketSql('t'), 'epo.bokun_product_id = t.product_id') !== false, 'ticket test is on the effective product');
ok(strpos(fwlEffectivePidSql('x'), 'x.product_id') !== false, 'effective pid expression uses the alias');

if (!$apiDir) { echo "\n$pass passed, $fail failed (pure only)\n"; exit($fail ? 1 : 0); }

echo "\n== database " . $conn->query("SELECT DATABASE() d")->fetch_assoc()['d'] . " ==\n";
$map = fwlEffectiveProducts($conn, true);
echo "  linked products: " . count($map) . "\n";
foreach ($map as $pid => $m) { echo "    $pid -> {$m['effective_id']}  rule title: {$m['rule_title']}\n"; }

// Every product: the SQL join, the SQL ticket test and the PHP map must agree.
$res = $conn->query("SELECT p.bokun_product_id, p.product_type, p.groups_as_product_id, o.product_type AS orig_type, o.groups_as_product_id AS orig_link
                       FROM products p LEFT JOIN products o ON o.bokun_product_id = p.groups_as_product_id");
$agree = 0; $disagree = []; $chains = 0;
while ($p = $res->fetch_assoc()) {
    $pid = (int) $p['bokun_product_id'];
    $expectType = $p['groups_as_product_id'] !== null ? $p['orig_type'] : $p['product_type'];
    if ($p['groups_as_product_id'] !== null && $p['orig_link'] !== null) { $chains++; }
    $r = $conn->query("SELECT pr.product_type, (" . fwlIsTicketSql('t') . ") AS is_ticket, " . fwlEffectivePidSql('t') . " AS eff
                         FROM (SELECT $pid AS product_id) t " . fwlProductJoinSql('t', 'pr'))->fetch_assoc();
    $expectEff = $p['groups_as_product_id'] !== null ? (int) $p['groups_as_product_id'] : $pid;
    if ($r['product_type'] === $expectType && (int) $r['is_ticket'] === ($expectType === 'ticket' ? 1 : 0)
        && (int) $r['eff'] === $expectEff && fwlEffectiveProductId($conn, $pid) === $expectEff) {
        $agree++;
    } else {
        $disagree[] = $pid;
    }
}
ok(!$disagree, "SQL join, SQL ticket test and PHP map agree for $agree products" . ($disagree ? ' - differ: ' . implode(',', $disagree) : ''));
ok($chains === 0, 'no chained links (an original is never linked itself)');
ok(fwlEffectiveProductId($conn, null) === null, 'a hand-entered row (no product) stays without a product');

$opt = getopt('', ['dry-run-p1::']);
if (isset($opt['dry-run-p1'])) {
    $D = $opt['dry-run-p1'] ?: '2026-10-20';
    echo "\n== dry run: one P1 (1324951) booking on $D, rolled back ==\n";
    $settings = pnlLoadSettings($conn);
    pnlBuildRows($conn, $D, $D, $settings); // warm-up: every self-provision ran BEFORE the transaction
    $conn->begin_transaction();
    try {
        $ext = 'p622-dryrun-' . getmypid();
        $s = $conn->prepare("INSERT INTO tours (external_id, title, date, time, participants, booking_channel, product_id, cancelled, language, customer_name)
                             VALUES (?, 'Uffizi Gallery Reserved Entry Ticket with Audio Guide App', ?, '11:00:00', 2, 'Viator.com', 1324951, 0, 'English', 'Dry Run')");
        $s->bind_param('ss', $ext, $D); $s->execute(); $id = (int) $conn->insert_id; $s->close();
        echo "  inserted t$id (inside the transaction)\n";
        $one = function ($sql) use ($conn, $id) { return (int) $conn->query(str_replace('{ID}', (string) $id, $sql))->fetch_assoc()['n']; };
        ok($one("SELECT COUNT(*) n FROM tours t " . fwlProductJoinSql('t', 'pr') . " WHERE t.id = {ID} AND (pr.product_type = 'tour' OR t.product_id IS NULL)") === 0, 'Tours list (product_type=tour) leaves it out');
        ok($one("SELECT COUNT(*) n FROM tours t " . fwlProductJoinSql('t', 'pr') . " WHERE t.id = {ID} AND pr.product_type = 'ticket'") === 1, 'Tickets / Priority Tickets list includes it');
        ok($one("SELECT COUNT(*) n FROM tours t WHERE t.id = {ID} AND NOT " . fwlIsTicketSql('t')) === 0, 'guide payments / guide report / radios exclude it (ticket)');
        $rep = fwlUnassignedReport($conn, ["t.date >= ?", "t.date <= ?", "(pr.product_type = 'tour' OR t.product_id IS NULL)", "t.cancelled = 0"], [$D, $D], 'ss');
        ok(!in_array('t' . $id, array_column($rep, 'tour_unit'), true), 'unassigned report (needs a guide) leaves it out');
        $row = null;
        foreach (pnlBuildRows($conn, $D, $D, $settings) as $r) { if ($r['unit'] === 't' . $id) { $row = $r; } }
        ok($row !== null && $row['is_ticket'] === true, 'Daily P&L: a ticket unit');
        ok($row !== null && (float) $row['costs']['guide_cost'] === 0.0 && (float) $row['costs']['radio_cost'] === 0.0, 'Daily P&L: no guide, no radio cost');
        ok($row !== null && (float) $row['costs']['ticket_cost'] > 0, 'Daily P&L: Uffizi ticket cost from the rule title (' . ($row ? $row['costs']['ticket_cost'] : '-') . ')');
        ok(fwlRuleTitle($conn, 1324951, 'x') !== 'x', 'rule title = the original ticket product\'s: ' . fwlRuleTitle($conn, 1324951, 'x'));
    } finally {
        $conn->rollback();
    }
    ok((int) $conn->query("SELECT COUNT(*) n FROM tours WHERE external_id LIKE 'p622-dryrun-%'")->fetch_assoc()['n'] === 0, 'rolled back: nothing kept');
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
