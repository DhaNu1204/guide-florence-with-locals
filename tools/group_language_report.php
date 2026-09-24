<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only (step 6.12): never deployed
/**
 * Step 6.12 - READ ONLY. Groups whose members speak more than one language, today (Rome) and
 * later, split into auto and manual, with what hangs off each one (guide, payments). Plus the
 * number of upcoming bookings that carry no language at all.
 *
 *   FWL_API_DIR=/path/to/api php tools/group_language_report.php [--from=YYYY-MM-DD]
 */
$apiDir = getenv('FWL_API_DIR') ?: __DIR__ . '/../public_html/api';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/cli';
require $apiDir . '/config.php';
// The branch's helpers when run from a checkout copy, so the report works before the deploy too.
$localHelpers = __DIR__ . '/../public_html/api/group_helpers.php';
require_once is_file($localHelpers) ? $localHelpers : $apiDir . '/group_helpers.php';
$conn->query("SET SESSION TRANSACTION READ ONLY");

$from = (new DateTime('now', new DateTimeZone('Europe/Rome')))->format('Y-m-d');
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--from=(\d{4}-\d{2}-\d{2})$/', $a, $m)) { $from = $m[1]; }
}

$stmt = $conn->prepare("
    SELECT tg.id, tg.group_date, tg.group_time, tg.is_manual_merge, tg.bucket_key, tg.guide_id,
           g.name AS guide_name, g.languages AS guide_languages, tg.total_pax, tg.display_name,
           t.id AS tour_id, t.language, t.participants, t.product_id, t.cancelled,
           (SELECT COUNT(*) FROM payments p WHERE p.tour_id = t.id) AS payments
      FROM tour_groups tg
      JOIN tours t ON t.group_id = tg.id
      LEFT JOIN guides g ON g.id = tg.guide_id
     WHERE tg.group_date >= ?
     ORDER BY tg.group_date, tg.group_time, tg.id, t.id");
$stmt->bind_param('s', $from);
$stmt->execute();
$res = $stmt->get_result();
$groups = [];
while ($r = $res->fetch_assoc()) {
    $gid = (int) $r['id'];
    if (!isset($groups[$gid])) {
        $groups[$gid] = ['row' => $r, 'members' => []];
    }
    $groups[$gid]['members'][] = $r;
}
$stmt->close();

$counts = ['auto' => 0, 'manual' => 0, 'groups' => count($groups)];
$lines = [];
foreach ($groups as $gid => $g) {
    $langs = groupMemberLanguages($g['members']);
    if (count($langs) < 2) { continue; }
    $r = $g['row'];
    $kind = $r['is_manual_merge'] ? 'manual' : 'auto';
    $counts[$kind]++;
    $pax = []; $products = []; $payments = 0;
    foreach ($g['members'] as $m) {
        if ((int) $m['cancelled'] === 1) { continue; }
        $l = tourLanguageKey($m['language']) ?? 'Unknown';
        $pax[$l] = ($pax[$l] ?? 0) + (int) $m['participants'];
        $products[(int) $m['product_id']] = true;
        $payments += (int) $m['payments'];
    }
    $paxText = [];
    foreach ($pax as $l => $n) { $paxText[] = "$l $n"; }
    $lines[] = sprintf("%s\tg%d\t%s %s\t%s\t%s\t%s\tguide=%s%s\tpayments=%d\t%s",
        $kind, $gid, $r['group_date'], substr($r['group_time'], 0, 5),
        implode('+', array_keys($products)), implode(', ', $paxText), 'pax=' . array_sum($pax),
        $r['guide_id'] ? $r['guide_name'] : 'none',
        $r['guide_id'] ? ' [' . (string) $r['guide_languages'] . ']' : '',
        $payments, mb_substr((string) $r['display_name'], 0, 45));
}

$u = $conn->prepare("SELECT COUNT(*) n,
                            SUM(t.group_id IS NOT NULL) grouped
                       FROM tours t
                      WHERE t.date >= ? AND t.cancelled = 0
                        AND (t.language IS NULL OR TRIM(t.language) = '')");
$u->bind_param('s', $from);
$u->execute();
$unk = $u->get_result()->fetch_assoc();
$u->close();
$a = $conn->prepare("SELECT COUNT(*) n FROM tours t WHERE t.date >= ? AND t.cancelled = 0");
$a->bind_param('s', $from);
$a->execute();
$all = (int) $a->get_result()->fetch_assoc()['n'];
$a->close();

printf("from %s: %d groups; mixed-language groups: auto %d, manual %d\n", $from, $counts['groups'], $counts['auto'], $counts['manual']);
printf("upcoming active bookings with no language: %d of %d (%d of them in a group)\n", (int) $unk['n'], $all, (int) $unk['grouped']);
foreach ($lines as $l) { echo $l, "\n"; }
