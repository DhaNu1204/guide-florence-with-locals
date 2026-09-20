<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only (step 3.7): never deployed
/**
 * Step 3.7 measurement - READ ONLY. What does "the group id changes on every sync" actually cost?
 *   - orphaned P&L overrides (pnl_tour_costs.tour_unit = 'g<id>' where that group no longer exists)
 *   - what lives on the tour_groups row and would be lost with it (notes / display_name / max_pax)
 *   - how many auto-groups exist and how churn shows up in sync_logs
 *   - cron invocations per 15-minute slot (the duplicate hPanel entry question)
 */
$apiDir = getenv('FWL_API_DIR') ?: __DIR__ . '/../public_html/api';
require_once $apiDir . '/config.php';

function rows($conn, $sql) {
    $res = $conn->query($sql);
    if (!$res) { echo "  ! " . $conn->error . "\n"; return []; }
    $out = [];
    while ($r = $res->fetch_assoc()) { $out[] = $r; }
    return $out;
}
function one($conn, $sql, $col = 'n') {
    $r = rows($conn, $sql);
    return $r ? $r[0][$col] : null;
}

echo "=== step 3.7 group identity check (read only) ===\n";
echo "db: " . one($conn, "SELECT DATABASE() n") . "   now(utc): " . gmdate('c') . "\n\n";

// --- what tour_groups carries -------------------------------------------------------------
echo "--- tour_groups columns ---\n";
$cols = [];
foreach (rows($conn, "SHOW COLUMNS FROM tour_groups") as $c) { $cols[] = $c['Field']; }
echo "  " . implode(', ', $cols) . "\n";

printf("  groups total %s | auto %s | manual merges %s\n",
    one($conn, "SELECT COUNT(*) n FROM tour_groups"),
    one($conn, "SELECT COUNT(*) n FROM tour_groups WHERE is_manual_merge = 0"),
    one($conn, "SELECT COUNT(*) n FROM tour_groups WHERE is_manual_merge = 1"));

foreach (['notes', 'display_name', 'max_pax', 'guide_id'] as $c) {
    if (!in_array($c, $cols, true)) { printf("  %-13s : column does not exist\n", $c); continue; }
    printf("  %-13s : %s of %s auto-group rows are non-empty\n", $c,
        one($conn, "SELECT COUNT(*) n FROM tour_groups WHERE is_manual_merge = 0 AND `$c` IS NOT NULL AND `$c` <> ''"),
        one($conn, "SELECT COUNT(*) n FROM tour_groups WHERE is_manual_merge = 0"));
}

// --- orphaned P&L overrides ---------------------------------------------------------------
echo "\n--- pnl_tour_costs overrides ---\n";
$exists = rows($conn, "SHOW TABLES LIKE 'pnl_tour_costs'");
if (!$exists) { echo "  (table does not exist)\n"; }
else {
    printf("  rows total %s | on a group ('g…') %s | on a single tour ('t…') %s\n",
        one($conn, "SELECT COUNT(*) n FROM pnl_tour_costs"),
        one($conn, "SELECT COUNT(*) n FROM pnl_tour_costs WHERE tour_unit LIKE 'g%'"),
        one($conn, "SELECT COUNT(*) n FROM pnl_tour_costs WHERE tour_unit LIKE 't%'"));

    $orphanSql = "SELECT c.* FROM pnl_tour_costs c
                  LEFT JOIN tour_groups tg ON tg.id = CAST(SUBSTRING(c.tour_unit, 2) AS UNSIGNED)
                  WHERE c.tour_unit LIKE 'g%' AND tg.id IS NULL";
    $orphans = rows($conn, $orphanSql);
    printf("  ORPHANED group overrides (group id no longer exists): %d\n", count($orphans));
    $shown = 0;
    foreach ($orphans as $o) {
        if ($shown++ >= 8) { break; }
        $amounts = [];
        foreach (['ticket_cost', 'guide_cost', 'radio_cost', 'gelato_cost', 'staff_cost', 'other_cost', 'revenue_override'] as $f) {
            if (isset($o[$f]) && $o[$f] !== null) { $amounts[] = "$f=" . $o[$f]; }
        }
        if (!empty($o['outsourced'])) { $amounts[] = 'outsourced=1'; }
        printf("    %-10s date %s  %s%s\n", $o['tour_unit'], $o['date'],
            $amounts ? implode(' ', $amounts) : '(no amounts)',
            !empty($o['notes']) ? '  notes="' . substr($o['notes'], 0, 40) . '"' : '');
    }
    if (count($orphans) > 8) { printf("    … and %d more\n", count($orphans) - 8); }

    // Can an orphan be resolved? It stores the date; a group on that date is a candidate.
    echo "  resolvability of the orphans (they store `date`):\n";
    $res0 = $res1 = $resN = 0;
    foreach ($orphans as $o) {
        $d = $conn->real_escape_string($o['date']);
        $n = (int) one($conn, "SELECT COUNT(*) n FROM tour_groups WHERE group_date = '$d' AND is_manual_merge = 0");
        if ($n === 0) { $res0++; } elseif ($n === 1) { $res1++; } else { $resN++; }
    }
    printf("    date has 0 groups: %d | exactly 1 group: %d | several groups: %d\n", $res0, $res1, $resN);
}

// --- group churn --------------------------------------------------------------------------
echo "\n--- group id range (churn leaves a gap between MIN and COUNT) ---\n";
foreach (rows($conn, "SELECT MIN(id) mn, MAX(id) mx, COUNT(*) n FROM tour_groups WHERE is_manual_merge = 0") as $r) {
    printf("  auto groups: %s rows, ids %s..%s -> %s ids burned so far\n",
        $r['n'], $r['mn'], $r['mx'], $r['mx'] - $r['mn'] + 1 - $r['n']);
}
printf("  AUTO_INCREMENT now: %s\n", one($conn,
    "SELECT AUTO_INCREMENT n FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'tour_groups'"));

// --- cron slots (the duplicate hPanel entry question) --------------------------------------
echo "\n--- sync_logs: runs per 15-minute slot, last 24 h ---\n";
foreach (rows($conn, "SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:%i') slot, COUNT(*) n,
                             GROUP_CONCAT(CONCAT(id, ':', COALESCE(triggered_by,'?'), ':', COALESCE(duration_seconds,'-')) ORDER BY id SEPARATOR '  ') detail
                      FROM sync_logs
                      WHERE created_at >= NOW() - INTERVAL 3 HOUR
                      GROUP BY DATE_FORMAT(created_at, '%Y-%m-%d %H:%i')
                      ORDER BY slot DESC LIMIT 14") as $r) {
    printf("  %s  runs=%s   %s\n", $r['slot'], $r['n'], $r['detail']);
}
foreach (rows($conn, "SELECT COUNT(*) n, COUNT(DISTINCT DATE_FORMAT(created_at, '%Y-%m-%d %H:%i')) slots
                      FROM sync_logs WHERE triggered_by = 'cron' AND created_at >= NOW() - INTERVAL 24 HOUR") as $r) {
    printf("  last 24 h: %s cron rows over %s distinct minutes = %.2f runs per slot\n",
        $r['n'], $r['slots'], $r['slots'] ? $r['n'] / $r['slots'] : 0);
}

echo "\ndone (nothing was written)\n";
