<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only (step 3.7): never deployed
/**
 * Step 3.7 - READ ONLY. One line per grouped tour: "<tour id>\t<group id>", plus a group line
 * per group, so two snapshots taken around a sync answer exactly one question:
 * did the departures keep their identity?
 *
 *   FWL_API_DIR=/path/to/api php tools/group_membership_snapshot.php --out=before.tsv
 *   php tools/group_membership_snapshot.php --diff=before.tsv,after.tsv
 */
$apiDir = getenv('FWL_API_DIR') ?: __DIR__ . '/../public_html/api';

$out = null; $diff = null;
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--out=(.+)$/', $a, $m)) { $out = $m[1]; }
    elseif (preg_match('/^--diff=([^,]+),(.+)$/', $a, $m)) { $diff = [$m[1], $m[2]]; }
}

if ($diff) {
    $load = function ($p) {
        $tours = []; $groups = [];
        foreach (file($p, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if ($line === '' || $line[0] === '#') { continue; }
            $f = explode("\t", $line);
            if ($f[0] === 'T') { $tours[$f[1]] = $f[2]; }
            elseif ($f[0] === 'G') { $groups[$f[1]] = array_slice($f, 2); }
        }
        return [$tours, $groups];
    };
    list($ta, $ga) = $load($diff[0]);
    list($tb, $gb) = $load($diff[1]);

    $moved = 0; $movedIds = [];
    foreach ($ta as $tid => $gid) {
        if (isset($tb[$tid]) && $tb[$tid] !== $gid) { $moved++; if (count($movedIds) < 12) { $movedIds[] = "$tid: $gid->{$tb[$tid]}"; } }
    }
    $gone = array_diff_key($ga, $gb);
    $new  = array_diff_key($gb, $ga);
    $changedRows = 0;
    foreach ($ga as $gid => $vals) {
        if (isset($gb[$gid]) && $gb[$gid] !== $vals) { $changedRows++; }
    }
    printf("tours grouped: before %d, after %d\n", count($ta), count($tb));
    printf("groups: before %d, after %d | disappeared %d | new %d\n", count($ga), count($gb), count($gone), count($new));
    printf("TOURS WHOSE GROUP ID CHANGED: %d\n", $moved);
    if ($movedIds) { echo "  e.g. " . implode(' | ', $movedIds) . "\n"; }
    printf("group rows whose values changed (pax/title/time/bucket_key): %d\n", $changedRows);
    if ($gone) { echo "  gone group ids: " . implode(',', array_slice(array_keys($gone), 0, 30)) . "\n"; }
    if ($new)  { echo "  new group ids:  " . implode(',', array_slice(array_keys($new), 0, 30)) . "\n"; }
    exit(0);
}

require_once $apiDir . '/config.php';
if (!$out) { fwrite(STDERR, "--out=<file> is required\n"); exit(1); }

$hasBucket = $conn->query("SHOW COLUMNS FROM tour_groups LIKE 'bucket_key'");
$hasBucket = $hasBucket && $hasBucket->num_rows > 0;

$fh = fopen($out, 'w');
fwrite($fh, "# group membership " . gmdate('c') . " db=" . $conn->query("SELECT DATABASE() d")->fetch_assoc()['d'] . "\n");

$n = 0;
$res = $conn->query("SELECT id, group_id FROM tours WHERE group_id IS NOT NULL ORDER BY id");
while ($r = $res->fetch_assoc()) { fwrite($fh, "T\t{$r['id']}\t{$r['group_id']}\n"); $n++; }

$g = 0;
$res = $conn->query("SELECT id, group_date, group_time, display_name, total_pax, is_manual_merge"
    . ($hasBucket ? ", bucket_key" : "") . " FROM tour_groups ORDER BY id");
while ($r = $res->fetch_assoc()) {
    fwrite($fh, "G\t{$r['id']}\t{$r['group_date']}\t{$r['group_time']}\t{$r['total_pax']}\t{$r['is_manual_merge']}\t"
        . ($hasBucket ? $r['bucket_key'] : '-') . "\t" . str_replace("\t", ' ', (string) $r['display_name']) . "\n");
    $g++;
}
fclose($fh);
echo "snapshot: $n grouped tours, $g groups -> $out\n";
