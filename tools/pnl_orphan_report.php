<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only (step 3.7): never deployed
/**
 * Step 3.7 - READ ONLY. Every pnl_tour_costs override that points at a group id which no longer
 * exists, with the candidate departures on that date, so the owner can decide what (if anything)
 * to re-point. Writes nothing; prints an optional --save file of the affected rows.
 */
$apiDir = getenv('FWL_API_DIR') ?: __DIR__ . '/../public_html/api';
require_once $apiDir . '/config.php';

$saveTo = null;
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--save=(.+)$/', $a, $m)) { $saveTo = $m[1]; }
}

function q($conn, $sql) {
    $res = $conn->query($sql);
    if (!$res) { echo "  ! " . $conn->error . "\n"; return []; }
    $out = [];
    while ($r = $res->fetch_assoc()) { $out[] = $r; }
    return $out;
}

echo "=== orphaned P&L overrides (db: " . q($conn, "SELECT DATABASE() d")[0]['d'] . ") ===\n";

$orphans = q($conn, "SELECT c.* FROM pnl_tour_costs c
                     LEFT JOIN tour_groups tg ON tg.id = CAST(SUBSTRING(c.tour_unit, 2) AS UNSIGNED)
                     WHERE c.tour_unit LIKE 'g%' AND tg.id IS NULL
                     ORDER BY c.date, c.tour_unit");
printf("%d orphaned override(s)\n\n", count($orphans));

$lines = [];
$byDate = [];
foreach ($orphans as $o) { $byDate[$o['date']][] = $o; }

foreach ($byDate as $date => $list) {
    $d = $conn->real_escape_string($date);
    echo "--- $date ---\n";
    echo "  orphaned overrides:\n";
    foreach ($list as $o) {
        $amounts = [];
        foreach (['ticket_cost', 'guide_cost', 'radio_cost', 'gelato_cost', 'staff_cost', 'other_cost', 'revenue_override'] as $f) {
            if (isset($o[$f]) && $o[$f] !== null) { $amounts[] = "$f=" . $o[$f]; }
        }
        if (!empty($o['outsourced'])) { $amounts[] = 'outsourced=1'; }
        printf("    %-10s %s%s   (set %s)\n", $o['tour_unit'],
            $amounts ? implode(' ', $amounts) : '(no amounts)',
            !empty($o['notes']) ? ' notes="' . $o['notes'] . '"' : '', $o['updated_at']);
        $lines[] = implode("\t", [$o['id'], $o['tour_unit'], $o['date'], $o['ticket_cost'], $o['guide_cost'],
            $o['radio_cost'], $o['gelato_cost'], $o['staff_cost'], $o['other_cost'], $o['revenue_override'],
            $o['outsourced'], str_replace("\t", ' ', (string) $o['notes']), $o['updated_at']]);
    }
    $groups = q($conn, "SELECT tg.id, tg.group_time, tg.display_name, tg.total_pax, tg.is_manual_merge,
                               (SELECT COUNT(*) FROM tours t WHERE t.group_id = tg.id) members,
                               (SELECT MIN(t.product_id) FROM tours t WHERE t.group_id = tg.id) product_id
                        FROM tour_groups tg WHERE tg.group_date = '$d' ORDER BY product_id, tg.group_time, tg.id");
    printf("  departures that exist on that date today (%d):\n", count($groups));
    foreach ($groups as $g) {
        printf("    g%-8s %s  product %-8s pax %-4s members %-3s %s%s\n", $g['id'], substr($g['group_time'], 0, 5),
            $g['product_id'], $g['total_pax'], $g['members'], substr($g['display_name'], 0, 46),
            $g['is_manual_merge'] ? '  [manual merge]' : '');
    }
    echo "\n";
}

if ($saveTo && $lines) {
    $header = "id\ttour_unit\tdate\tticket_cost\tguide_cost\tradio_cost\tgelato_cost\tstaff_cost\tother_cost\trevenue_override\toutsourced\tnotes\tupdated_at\n";
    file_put_contents($saveTo, $header . implode("\n", $lines) . "\n");
    chmod($saveTo, 0600);
    echo "saved " . count($lines) . " row(s) to $saveTo\n";
}
echo "done (nothing in the database was changed)\n";
