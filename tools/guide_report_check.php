<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only (step 6.15): never deployed
/**
 * Step 6.15 checks for the Guide Tour Report effective type.
 *
 *   php tools/guide_report_check.php                      pure checks of buildComposition()
 *   FWL_API_DIR=/path/to/api php tools/guide_report_check.php --scan
 *       READ ONLY: every merged departure (guide assigned or not, cancelled bookings and
 *       ticket products excluded, as the report does) whose bookings span more than one
 *       type, counted by composition; lists every UNDECIDED one (highest rank shared,
 *       e.g. Uffizi + Accademia with no Combo).
 */
$apiDir = getenv('FWL_API_DIR') ?: __DIR__ . '/../public_html/api';
// The branch's copy when run from a checkout (before the deploy), else the deployed one
$core = __DIR__ . '/../public_html/api/lib/guide_report_core.php';
require_once is_file($core) ? $core : $apiDir . '/lib/guide_report_core.php';

$scan = in_array('--scan', $argv, true);

if (!$scan) {
    $pass = 0; $fail = 0;
    $check = function ($name, $titles, $want) use (&$pass, &$fail) {
        $got = buildComposition($titles);
        $g = [$got[0], $got[2], $got[3]];
        if ($g === $want) { $pass++; echo "PASS $name\n"; }
        else { $fail++; echo "FAIL $name\n  want " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n  got  " . json_encode($g, JSON_UNESCAPED_UNICODE) . "\n"; }
    };
    $combo = 'Uffizi & Accademia Combo Tour with David';
    $uff   = 'Uffizi Gallery and Palazzo Vecchio Tour';
    $acc   = 'Accademia Gallery Guided Tour';
    $pit   = 'Pitti Palace & Boboli Gardens';
    $oth   = 'Ponte Vecchio Food Walk';
    // non-mixed: unchanged
    $check('single booking Uffizi', [$uff], ['Uffizi', '', null]);
    $check('two Combo bookings', [$combo, $combo], ['Combo', '', null]);
    $check('no titles', [], ['Other', '', null]);
    // the 18 Sept case: Uffizi booking first, Combo second -> Combo, combo title
    $check('1 Uffizi + 1 Combo (Uffizi first)', [$uff, $combo], ['Combo', '1 Combo + 1 Uffizi booking', 1]);
    $check('2 Combo + 3 Uffizi', [$combo, $uff, $uff, $combo, $uff], ['Combo', '2 Combo + 3 Uffizi bookings', 0]);
    $check('Combo + Accademia', [$acc, $combo], ['Combo', '1 Combo + 1 Accademia booking', 1]);
    $check('Combo + Other', [$oth, $combo], ['Combo', '1 Combo + 1 Other booking', 1]);
    $check('Uffizi + Other', [$oth, $oth, $uff], ['Uffizi', '1 Uffizi + 2 Other bookings', 2]);
    $check('Accademia + Other', [$acc, $oth], ['Accademia', '1 Accademia + 1 Other booking', 0]);
    $check('Pitti + Other', [$oth, $pit], ['Pitti', '1 Pitti + 1 Other booking', 1]);
    // undecided: highest rank shared, no Combo -> stays Mixed, no title choice
    $check('Uffizi + Accademia (undecided)', [$uff, $acc], ['Mixed', '1 Uffizi + 1 Accademia booking', null]);
    $check('Uffizi + Pitti (undecided)', [$pit, $uff], ['Mixed', '1 Uffizi + 1 Pitti booking', null]);
    $check('Uffizi + Accademia + Other (undecided)', [$uff, $acc, $oth], ['Mixed', '1 Uffizi + 1 Accademia + 1 Other booking', null]);
    // Combo wins over an Uffizi + Accademia mix
    $check('Combo + Uffizi + Accademia', [$uff, $acc, $combo], ['Combo', '1 Combo + 1 Uffizi + 1 Accademia booking', 2]);
    echo "\n$pass passed, $fail failed\n";
    exit($fail === 0 ? 0 : 1);
}

require_once $apiDir . '/config.php';
$sql = "SELECT t.group_id, t.title, t.date, t.time, t.guide_id, g.name AS guide_name
        FROM tours t
        LEFT JOIN guides g ON g.id = t.guide_id
        WHERE t.group_id IS NOT NULL
          AND t.cancelled = 0
          AND (NOT EXISTS (
                SELECT 1 FROM products pr
                WHERE pr.bokun_product_id = t.product_id AND pr.product_type = 'ticket'
              ))
        ORDER BY t.date, t.time";
$res = $conn->query($sql);
$units = [];
while ($r = $res->fetch_assoc()) {
    $gid = (int) $r['group_id'];
    if (!isset($units[$gid])) { $units[$gid] = ['date' => $r['date'], 'time' => $r['time'], 'guide' => $r['guide_name'], 'titles' => []]; }
    $units[$gid]['titles'][] = $r['title'];
}
$byLabel = []; $undecided = []; $mixed = 0; $mixedWithGuide = 0;
foreach ($units as $gid => $u) {
    list($cat, , $label) = buildComposition($u['titles']);
    if ($label === '') { continue; }
    $mixed++;
    if ($u['guide'] !== null) { $mixedWithGuide++; }
    $shape = $cat . ' <= ' . preg_replace('/\d+ /', '', $label);
    $byLabel[$shape] = ($byLabel[$shape] ?? 0) + 1;
    if ($cat === 'Mixed') { $undecided[] = "g$gid {$u['date']} " . substr((string) $u['time'], 0, 5) . " guide=" . ($u['guide'] ?? '-') . " | $label"; }
}
echo "group units scanned: " . count($units) . "\n";
echo "mixed units: $mixed (with a guide: $mixedWithGuide)\n";
ksort($byLabel);
foreach ($byLabel as $k => $n) { echo "  $n x $k\n"; }
echo "UNDECIDED (highest rank shared, no Combo): " . count($undecided) . "\n";
foreach ($undecided as $line) { echo "  $line\n"; }
