<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only (step 7.2): never deployed
/**
 * Step 7.2 - the assistant eval: 12 fixed questions, each expected answer computed straight from SQL
 * at run time, asked through the real engine (lib/assistant_core.php, as the given admin).
 *
 *   FWL_API_DIR=<api dir> php tools/assistant_eval.php [--user=dhanu] [--only=3,7] [--cache-test]
 *
 * Pass = the numbers / lists in the answer (text + blocks) match SQL AND the answer is in the
 * question's language. Names of guides and dates are picked from the data at run time (the
 * questions are fixed templates); the picks are printed.
 * --cache-test asks one question twice (two new conversations) and prints the input tokens of both.
 */
$apiDir = getenv('FWL_API_DIR') ?: __DIR__ . '/../public_html/api';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'POST';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/cli';
require $apiDir . '/config.php';
require_once $apiDir . '/Middleware.php';
require_once $apiDir . '/lib/assistant_core.php';

$username = 'dhanu'; $only = null; $cacheTest = false;
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--user=(.+)$/', $a, $m)) $username = $m[1];
    if (preg_match('/^--only=([\d,]+)$/', $a, $m)) $only = array_map('intval', explode(',', $m[1]));
    if ($a === '--cache-test') $cacheTest = true;
}
if (!assistantEnabled() || ClaudeClient::fromEnv() === null) { fwrite(STDERR, "assistant disabled or no key\n"); exit(3); }
$st = $conn->prepare("SELECT id, role, username, email FROM users WHERE username = ?");
$st->bind_param('s', $username); $st->execute(); $user = $st->get_result()->fetch_assoc(); $st->close();
if (!$user || $user['role'] !== 'admin') { fwrite(STDERR, "need an admin user\n"); exit(2); }
ensureAssistantTables($conn);
$client = ClaudeClient::fromEnv();

// ---- SQL for the expected answers ---------------------------------------------------------------
$rome = new DateTimeZone('Europe/Rome');
$now = new DateTime('now', $rome);
$today = $now->format('Y-m-d');
$tomorrow = (clone $now)->modify('+1 day')->format('Y-m-d');
$dow = (int) $now->format('N');
$sunday = (clone $now)->modify('+' . (7 - $dow) . ' days')->format('Y-m-d');
$satW = $dow >= 6 ? $today : (clone $now)->modify('+' . (6 - $dow) . ' days')->format('Y-m-d');
$monthEnd = (clone $now)->modify('last day of this month')->format('Y-m-d');

function q($sql, $types = '', ...$params) {
    global $conn;
    $s = $conn->prepare($sql);
    if ($types !== '') $s->bind_param($types, ...$params);
    $s->execute(); $r = $s->get_result(); $o = [];
    while ($x = $r->fetch_assoc()) $o[] = $x;
    $s->close();
    return $o;
}
const TOUR_ONLY = "(pr.product_type = 'tour' OR t.product_id IS NULL)";
/** departures (tour units) in a range: cancelled bookings and tickets out */
function units($start, $end) {
    return q("SELECT IF(t.group_id IS NOT NULL, CONCAT('g', t.group_id), CONCAT('t', t.id)) AS u,
                     MIN(t.date) AS d, LEFT(COALESCE(MAX(tg.group_time), MIN(t.time)), 5) AS tm,
                     COALESCE(MAX(tg.display_name), MIN(t.title)) AS title,
                     SUM(COALESCE(t.participants, 0)) AS pax,
                     MAX(tg.guide_id) AS gg, MAX(t.guide_id) AS tg_, COALESCE(MAX(tg.guide_id), MAX(t.guide_id)) AS gid
              FROM tours t LEFT JOIN tour_groups tg ON tg.id = t.group_id LEFT JOIN products pr ON pr.bokun_product_id = t.product_id
              WHERE t.date BETWEEN ? AND ? AND t.cancelled = 0 AND " . TOUR_ONLY . "
              GROUP BY u ORDER BY d, tm, u", 'ss', $start, $end);
}
/** the Tours page's day numbers (group counted once even if all its bookings are cancelled) */
function pageDay($date) {
    $groups = q("SELECT COUNT(DISTINCT t.group_id) n FROM tours t LEFT JOIN products pr ON pr.bokun_product_id = t.product_id
                 WHERE t.date = ? AND t.group_id IS NOT NULL AND " . TOUR_ONLY, 's', $date)[0]['n'];
    $singles = q("SELECT COUNT(*) n FROM tours t LEFT JOIN products pr ON pr.bokun_product_id = t.product_id
                  WHERE t.date = ? AND t.group_id IS NULL AND t.cancelled = 0 AND " . TOUR_ONLY, 's', $date)[0]['n'];
    $guests = q("SELECT COALESCE(SUM(CASE WHEN t.group_id IS NOT NULL THEN COALESCE(t.participants, 0)
                     ELSE COALESCE(NULLIF(CAST(JSON_UNQUOTE(JSON_EXTRACT(t.bokun_data, '$.productBookings[0].fields.totalParticipants')) AS UNSIGNED), 0),
                                   NULLIF(t.participants, 0), 1) END), 0) n
                 FROM tours t LEFT JOIN products pr ON pr.bokun_product_id = t.product_id
                 WHERE t.date = ? AND t.cancelled = 0 AND " . TOUR_ONLY, 's', $date)[0]['n'];
    return [(int) $groups + (int) $singles, (int) $guests];
}
function guidesAll() { return q("SELECT id, name FROM guides ORDER BY name"); }
function firstName($n) { return explode(' ', trim($n))[0]; }
function norm($s) { return function_exists('assistantNorm') ? assistantNorm($s) : strtolower($s); }

// ---- picks from the data -------------------------------------------------------------------------
$week = units($today, $sunday);
$byGuide = [];
foreach ($week as $u) { if ($u['gid'] !== null) { $byGuide[(int) $u['gid']] = ($byGuide[(int) $u['gid']] ?? 0) + 1; } }
arsort($byGuide);
$guides = guidesAll();
$gName = []; foreach ($guides as $g) $gName[(int) $g['id']] = $g['name'];
$firstCounts = array_count_values(array_map(function ($g) { return norm(firstName($g['name'])); }, $guides));
$uniqueBusy = [];
foreach ($byGuide as $gid => $n) {
    if (isset($gName[$gid]) && $firstCounts[norm(firstName($gName[$gid]))] === 1 && strlen(firstName($gName[$gid])) >= 5) $uniqueBusy[] = $gid;
}
if (count($uniqueBusy) < 2) { fwrite(STDERR, "need two guides with unique first names and departures this week\n"); exit(2); }
$g1 = $uniqueBusy[0]; $g2 = $uniqueBusy[1];
$fn1 = firstName($gName[$g1]);
$typo = $fn1[0] . $fn1[2] . $fn1[1] . substr($fn1, 3);           // swap the 2nd and 3rd letters
$dupFirst = null;
foreach ($firstCounts as $f => $n) { if ($n >= 2) { $dupFirst = $f; break; } }
$dupNames = array_values(array_filter(array_map(function ($g) { return $g['name']; }, $guides), function ($n) use ($dupFirst) { return norm(firstName($n)) === $dupFirst; }));

// ambiguous time: a date in the next 7 days with 2+ Uffizi departures within 15 minutes of each other
$amb = null;
foreach (units($tomorrow, (clone $now)->modify('+7 days')->format('Y-m-d')) as $u) {
    if (stripos($u['title'], 'uffizi') === false) continue;
    $same = array_values(array_filter(units($u['d'], $u['d']), function ($x) use ($u) {
        return stripos($x['title'], 'uffizi') !== false && abs(strtotime("2000-01-01 {$x['tm']}") - strtotime("2000-01-01 {$u['tm']}")) <= 900;
    }));
    if (count($same) >= 2) { $amb = ['date' => $u['d'], 'time' => $u['tm'], 'ids' => array_column($same, 'u')]; break; }
}
// empty answer: the first day from +30 days on with no departure at all
$emptyDay = null;
for ($i = 30; $i < 400; $i++) {
    $d = (clone $now)->modify("+$i days")->format('Y-m-d');
    if (pageDay($d)[0] === 0) { $emptyDay = $d; break; }
}
$fmt = function ($ymd, $it = false) {
    $dt = new DateTime($ymd);
    if (!$it) return $dt->format('D j M');
    $days = ['Mon' => 'lun', 'Tue' => 'mar', 'Wed' => 'mer', 'Thu' => 'gio', 'Fri' => 'ven', 'Sat' => 'sab', 'Sun' => 'dom'];
    $months = ['Jan' => 'gennaio', 'Feb' => 'febbraio', 'Mar' => 'marzo', 'Apr' => 'aprile', 'May' => 'maggio', 'Jun' => 'giugno',
               'Jul' => 'luglio', 'Aug' => 'agosto', 'Sep' => 'settembre', 'Oct' => 'ottobre', 'Nov' => 'novembre', 'Dec' => 'dicembre'];
    return $days[$dt->format('D')] . ' ' . $dt->format('j') . ' ' . $months[$dt->format('M')];
};
$free = function ($date, $from, $to) use ($guides) {
    $f = strtotime("2000-01-01 $from"); $t = strtotime("2000-01-01 $to"); $busy = [];
    foreach (units($date, $date) as $u) {
        if ($u['gid'] === null) continue;
        $s = strtotime("2000-01-01 {$u['tm']}");
        if ($s < $t && $s + 7200 > $f) $busy[(int) $u['gid']] = true;
    }
    return array_values(array_map(function ($g) { return $g['name']; }, array_filter($guides, function ($g) use ($busy) { return !isset($busy[(int) $g['id']]); })));
};
$unassigned = function ($s, $e) { return array_column(array_values(array_filter(units($s, $e), function ($u) { return $u['gg'] === null && $u['tg_'] === null; })), 'u'); };
$sched = function ($gid, $s, $e) { return array_column(array_values(array_filter(units($s, $e), function ($u) use ($gid) { return (int) $u['gid'] === $gid; })), 'u'); };
$acc = array_column(array_values(array_filter(units($tomorrow, $tomorrow), function ($u) { return stripos(norm($u['title']), 'accademia') !== false; })), 'u');

list($depTom, $paxTom) = pageDay($tomorrow);
list(, $paxToday) = pageDay($today);

// ---- the 12 questions ------------------------------------------------------------------------------
// kind: count (numbers must appear) | ids (departure_list ids = SQL set) | names (all names + count) | empty
$Q = [
    1  => ['en', "How many tours do we have tomorrow?", 'count', [$depTom, $paxTom]],
    2  => ['it', "Quanti ospiti abbiamo oggi?", 'count', [$paxToday]],
    3  => ['en', "give me unassigned tour list for this month", 'ids', $unassigned($today, $monthEnd)],
    4  => ['it', "Quali tour sono senza guida questo weekend?", 'ids', $unassigned($satW, $sunday)],
    5  => ['en', "What tours does $typo have this week?", 'ids', $sched($g1, $today, $sunday)],
    6  => ['it', "I tour di " . firstName($gName[$g2]) . " questa settimana", 'ids', $sched($g2, $today, $sunday)],
    7  => ['en', "who is free tomorrow 10-12", 'names', $free($tomorrow, '10:00', '12:00')],
    8  => ['it', "Chi è libero domani dalle 15 alle 18?", 'names', $free($tomorrow, '15:00', '18:00')],
    9  => ['en', $amb ? "Which Uffizi tour is at {$amb['time']} on " . $fmt($amb['date']) . "?" : null, 'ids', $amb ? $amb['ids'] : []],
    10 => ['it', $emptyDay ? "Quanti tour abbiamo il " . preg_replace('/^\S+ /', '', $fmt($emptyDay, true)) . " " . substr($emptyDay, 0, 4) . "?" : null, 'empty', []],
    11 => ['en', "What time are the Accademia tours tomorrow?", 'ids', $acc],
    12 => ['en', "Which guides are called " . ucfirst($dupFirst) . "?", 'names', $dupNames],
];
echo "now {$now->format('D Y-m-d H:i')} Rome | this week $today..$sunday | weekend $satW..$sunday | month to $monthEnd\n";
echo "picks: typo '{$typo}' = {$gName[$g1]} (id $g1); Q6 guide {$gName[$g2]} (id $g2); duplicate first name '{$dupFirst}' x" . count($dupNames)
    . "; ambiguous " . ($amb ? "{$amb['date']} {$amb['time']} (" . implode(',', $amb['ids']) . ")" : 'NONE FOUND')
    . "; empty day " . ($emptyDay ?: 'NONE FOUND') . "\n\n";

function isItalian($text) {
    $t = ' ' . norm($text) . ' ';
    $it = 0; $en = 0;
    foreach (['il', 'la', 'di', 'che', 'sono', 'ci', 'non', 'per', 'con', 'domani', 'oggi', 'ospiti', 'partenze', 'nessun', 'nessuna', 'guida', 'guide', 'settimana', 'libere', 'liberi', 'libero', 'dal', 'alle', 'questo', 'questa', 'tutti', 'una', 'un'] as $w) $it += substr_count($t, " $w ");
    foreach (['the', 'is', 'are', 'there', 'no', 'of', 'for', 'with', 'tomorrow', 'today', 'guests', 'departures', 'none', 'guide', 'guides', 'week', 'free', 'from', 'to', 'this', 'all', 'and', 'on', 'at'] as $w) $en += substr_count($t, " $w ");
    return $it > $en;
}
function hasNumber($hay, $n) { return preg_match('/(?<![\d.,:])' . $n . '(?![\d]|[.,:]\d)/', $hay) === 1; }
function noneWord($text) { return preg_match('/\b(no|none|zero|nessun\w*|non ci sono|non abbiamo|0)\b/iu', $text) === 1; }

$pass = 0; $ran = 0; $tokens = ['in' => 0, 'out' => 0, 'cr' => 0, 'cw' => 0];
foreach ($Q as $n => list($lang, $question, $kind, $expected)) {
    if ($only !== null && !in_array($n, $only, true)) continue;
    if ($question === null) { printf("Q%-2d SKIP  no suitable data for this question\n\n", $n); continue; }
    $ran++;
    usleep(300000);
    $res = assistantHandle($conn, $client, $user, $question, null);
    $b = $res['body'];
    $text = (string) ($b['text'] ?? '');
    $blocks = $b['blocks'] ?? [];
    // every scalar value inside the blocks, separated, so "value":10 reads as 10
    $vals = [];
    array_walk_recursive($blocks, function ($v, $k) use (&$vals) { if ($k !== 'type' && $v !== null && !is_bool($v)) $vals[] = (string) $v; });
    $blob = $text . ' | ' . implode(' | ', $vals);
    $ids = [];
    foreach ($blocks as $blk) { if ($blk['type'] === 'departure_list') foreach ($blk['rows'] as $r) $ids[] = $r['departure_id']; }
    $ids = array_values(array_unique($ids)); sort($ids);
    $why = [];
    switch ($kind) {
        case 'count':
            foreach ($expected as $num) { if (!hasNumber($blob, $num)) $why[] = "missing $num"; }
            break;
        case 'ids':
            $exp = $expected; sort($exp);
            if (count($exp) === 0) { if (!noneWord($text) || count($ids) > 0) $why[] = 'expected none'; }
            elseif (count($exp) <= ASSISTANT_LIST_CAP) { if ($ids !== $exp) $why[] = 'ids ' . json_encode($ids) . ' vs SQL ' . json_encode($exp); }
            else { if (count(array_diff($ids, $exp)) > 0 || !hasNumber($blob, count($exp))) $why[] = 'truncated list wrong or total ' . count($exp) . ' not stated'; }
            break;
        case 'names':
            $nb = norm($blob);
            $missing = array_values(array_filter($expected, function ($name) use ($nb) { return strpos($nb, norm($name)) === false; }));
            if ($missing) $why[] = 'missing ' . json_encode($missing);
            if (count($expected) > 1 && !hasNumber($blob, count($expected)) && count($missing) > 0) $why[] = 'count ' . count($expected) . ' not stated';
            break;
        case 'empty':
            if (!noneWord($text)) $why[] = 'expected an empty answer';
            break;
    }
    $isIt = isItalian($text);
    if ($isIt !== ($lang === 'it')) $why[] = 'answered in ' . ($isIt ? 'Italian' : 'English');
    if ($res['status'] !== 200) $why = ['HTTP-equivalent ' . $res['status'] . ' ' . ($b['error'] ?? '')];
    $ok = count($why) === 0;
    if ($ok) $pass++;
    $m = $b['meta'] ?? [];
    foreach (['in' => 'input_tokens', 'out' => 'output_tokens', 'cr' => 'cache_read_tokens', 'cw' => 'cache_write_tokens'] as $k => $f) $tokens[$k] += (int) ($m[$f] ?? 0);
    printf("Q%-2d %s  [%s] %s\n", $n, $ok ? 'PASS' : 'FAIL', strtoupper($lang), $question);
    $expShow = is_array($expected) ? (count($expected) > 12 ? count($expected) . ' items' : json_encode($expected, JSON_UNESCAPED_UNICODE)) : $expected;
    echo "     SQL: $expShow\n";
    echo "     A:   " . str_replace("\n", "\n          ", $text) . "\n";
    if ($blocks) echo "     blocks: " . implode(', ', array_map(function ($x) { return $x['type'] . ($x['type'] === 'departure_list' ? '(' . count($x['rows']) . ')' : ''); }, $blocks)) . ($ids ? ' ids ' . json_encode($ids) : '') . "\n";
    echo "     tools: " . implode(', ', $m['tools_called'] ?? []) . " | tokens in " . ($m['input_tokens'] ?? '?') . " out " . ($m['output_tokens'] ?? '?')
        . " cache r " . ($m['cache_read_tokens'] ?? '?') . " w " . ($m['cache_write_tokens'] ?? '?') . " | " . ($m['ms'] ?? '?') . " ms\n";
    if (!$ok) echo "     WHY: " . implode('; ', $why) . "\n";
    echo "\n";
}
echo "RESULT $pass/$ran passed   tokens: in {$tokens['in']} out {$tokens['out']} cache read {$tokens['cr']} cache write {$tokens['cw']}\n";

if ($cacheTest) {
    echo "\n== cache test: the same question twice, two new conversations ==\n";
    foreach ([1, 2] as $k) {
        $r = assistantHandle($conn, $client, $user, 'How many tours do we have tomorrow?', null);
        $m = $r['body']['meta'];
        printf("run %d: uncached input %d, cache write %d, cache read %d, output %d, %d ms\n", $k, $m['input_tokens'], $m['cache_write_tokens'], $m['cache_read_tokens'], $m['output_tokens'], $m['ms']);
        sleep(2);
    }
}
exit($pass === $ran ? 0 : 1);
