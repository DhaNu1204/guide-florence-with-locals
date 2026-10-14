<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only (step 6.19): never deployed
/**
 * Step 6.19 - one-time repair: a non-cancelled booking that already has a guide must not say
 * "needs a guide" (needs_guide_assignment = 1). Before 6.19 the single-booking guide edit never
 * cleared the flag. updated_at is kept as it was (it is ON UPDATE CURRENT_TIMESTAMP).
 *
 *   FWL_API_DIR=<api dir> php tools/needs_guide_flag_fix.php                 # dry run: counts only
 *   FWL_API_DIR=<api dir> php tools/needs_guide_flag_fix.php --apply --ids-out=<file>
 *
 * --apply writes the affected ids to --ids-out first (so the change can be undone), then updates.
 */
$apiDir = getenv('FWL_API_DIR') ?: __DIR__ . '/../public_html/api';
require $apiDir . '/config.php';

$apply = false; $idsOut = null;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--apply') { $apply = true; }
    elseif (preg_match('/^--ids-out=(.+)$/', $a, $m)) { $idsOut = $m[1]; }
}
if ($apply && !$idsOut) { fwrite(STDERR, "--apply needs --ids-out=<file>\n"); exit(2); }

$where = "cancelled = 0 AND guide_id IS NOT NULL AND needs_guide_assignment = 1";
$today = (new DateTime('now', new DateTimeZone('Europe/Rome')))->format('Y-m-d');
$db = $conn->query("SELECT DATABASE() d")->fetch_assoc()['d'];
$c = $conn->query("SELECT COUNT(*) n, SUM(date >= '$today') future, SUM(date < '$today') past,
                          SUM(group_id IS NULL) single, SUM(group_id IS NOT NULL) in_group
                   FROM tours WHERE $where")->fetch_assoc();
echo "db $db  (today $today Rome)\n";
printf("to fix: %d  (future %d / past %d; single %d / in a group %d)\n",
    $c['n'], $c['future'], $c['past'], $c['single'], $c['in_group']);
$chk = $conn->query("SELECT COUNT(*) n FROM tours WHERE cancelled = 0 AND guide_id IS NULL AND needs_guide_assignment = 0")->fetch_assoc();
echo "for reference, non-cancelled WITHOUT a guide but flag 0 (not touched): {$chk['n']}\n";

if (!$apply) { echo "dry run - nothing written\n"; exit(0); }

$ids = [];
$r = $conn->query("SELECT id FROM tours WHERE $where ORDER BY id");
while ($x = $r->fetch_assoc()) { $ids[] = (int) $x['id']; }
if (file_put_contents($idsOut, implode("\n", $ids) . "\n") === false) { fwrite(STDERR, "cannot write $idsOut\n"); exit(1); }
echo "ids saved: " . count($ids) . " -> $idsOut\n";

$conn->query("UPDATE tours SET needs_guide_assignment = 0, updated_at = updated_at WHERE $where");
echo "updated: {$conn->affected_rows}\n";
$after = $conn->query("SELECT COUNT(*) n FROM tours WHERE $where")->fetch_assoc();
echo "left after: {$after['n']}\n";
