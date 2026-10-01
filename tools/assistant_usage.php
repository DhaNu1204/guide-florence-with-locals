<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only (step 7.6): never deployed
/**
 * Step 7.6 - assistant usage per day (Europe/Rome), read-only.
 *
 *   FWL_API_DIR=<api dir> php82 tools/assistant_usage.php [--days=7] [--end=YYYY-MM-DD]
 *   (--end: the period is the N days ending on that Rome date - a late weekly review still covers
 *   the same week, and the review's permission rule can stay one exact command)
 *
 * Per day and user: questions, tokens (uncached in / out / cache read / cache write, and "cap
 * tokens" = what counts toward ASSISTANT_DAILY_TOKEN_CAP: in + out + cache write + cache read / 10),
 * errors (upstream / busy / internal, cap hits NOT included), cap hits (429 daily_cap_reached,
 * logged since 7.6), average seconds. Then per day: assignment confirms, undos, WhatsApps
 * really sent, and WhatsApp lines that were dry runs / not sent / failed.
 * Step 4.10b: then the home-screen app: loads vs failures per day, each failure with what the server
 * check found, and the CDN-test recommendation (2+ full stalls with the server reachable).
 * Nothing is written - missing tables are reported, never created.
 */
$apiDir = getenv('FWL_API_DIR') ?: __DIR__ . '/../public_html/api';
$_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REQUEST_URI'] = '/cli';
require $apiDir . '/config.php';
require_once $apiDir . '/Middleware.php';
require_once $apiDir . '/lib/assistant_core.php'; // assistantDailyTokenCap() / assistantEnabled(): the endpoint's own rules

$days = 7; $end = null;
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--days=(\d{1,3})$/', $a, $m)) $days = max(1, (int) $m[1]);
    if (preg_match('/^--end=(\d{4}-\d{2}-\d{2})$/', $a, $m)) $end = $m[1];
}

$rome = new DateTimeZone('Europe/Rome');
$utc = new DateTimeZone('UTC');
$last = $end ? new DateTime($end . ' 00:00:00', $rome) : new DateTime('today', $rome);
$first = (clone $last)->modify('-' . ($days - 1) . ' days');
$fromUtc = (clone $first)->setTimezone($utc)->format('Y-m-d H:i:s');
$toUtc = (clone $last)->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
$has = function ($t) use ($conn) { $r = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($t) . "'"); return $r && $r->num_rows > 0; };
// created_at is TIMESTAMP and the DB session is UTC (step 2.1): read it as UTC, group by the Rome day
$romeDay = function ($ts) use ($rome, $utc) { return (new DateTime($ts, $utc))->setTimezone($rome)->format('Y-m-d D'); };

$env = (string) EnvLoader::get('APP_ENV', '?');
$cap = assistantDailyTokenCap();
echo "assistant usage - $env - $days day(s) " . $first->format('Y-m-d') . " .. " . $last->format('Y-m-d') . " (Europe/Rome)"
    . " - enabled=" . (assistantEnabled() ? 'true' : 'false') . " cap=$cap\n"
    . "run at " . (new DateTime('now', $rome))->format('D d M Y H:i') . " Europe/Rome\n\n"; // the review's late-run check reads this

$users = [];
$r = $conn->query("SELECT id, username FROM users");
while ($x = $r->fetch_assoc()) $users[(int) $x['id']] = $x['username'];

if (!$has('assistant_logs')) {
    echo "no assistant_logs table yet (the assistant has never answered here)\n";
} else {
    $st = $conn->prepare("SELECT user_id, created_at, input_tokens, output_tokens, COALESCE(cache_read_tokens, 0) cr,
                                 COALESCE(cache_write_tokens, 0) cw, ms, error
                          FROM assistant_logs WHERE created_at >= ? AND created_at < ? ORDER BY created_at");
    $st->bind_param('ss', $fromUtc, $toUtc); $st->execute(); $res = $st->get_result();
    $agg = []; $dayCap = [];
    while ($x = $res->fetch_assoc()) {
        $d = $romeDay($x['created_at']);
        $u = $users[(int) $x['user_id']] ?? ('#' . $x['user_id']);
        $a = &$agg[$d][$u];
        if (!$a) $a = ['q' => 0, 'in' => 0, 'out' => 0, 'cr' => 0, 'cw' => 0, 'err' => 0, 'cap' => 0, 'ms' => 0];
        if ($x['error'] === 'daily_cap_reached') { $a['cap']++; unset($a); continue; }
        $a['q']++;
        foreach (['in' => 'input_tokens', 'out' => 'output_tokens', 'cr' => 'cr', 'cw' => 'cw', 'ms' => 'ms'] as $k => $f) $a[$k] += (int) $x[$f];
        if ($x['error'] !== null && $x['error'] !== '') $a['err']++;
        $dayCap[$d] = ($dayCap[$d] ?? 0) + (int) $x['input_tokens'] + (int) $x['output_tokens'] + (int) $x['cw'] + (int) ceil($x['cr'] / 10);
        unset($a);
    }
    $st->close();
    printf("%-15s %-10s %5s %9s %8s %10s %9s %10s %5s %5s %7s\n", 'day', 'user', 'asked', 'in', 'out', 'cache rd', 'cache wr', 'cap tokens', 'err', 'cap!', 'avg s');
    $tot = ['q' => 0, 'err' => 0, 'cap' => 0, 'captok' => 0];
    if (!$agg) echo "(no questions in this period)\n";
    foreach ($agg as $d => $byUser) {
        foreach ($byUser as $u => $a) {
            $capTok = $a['in'] + $a['out'] + $a['cw'] + (int) ceil($a['cr'] / 10);
            printf("%-15s %-10s %5d %9d %8d %10d %9d %10d %5d %5d %7s\n", $d, $u, $a['q'], $a['in'], $a['out'], $a['cr'], $a['cw'],
                $capTok, $a['err'], $a['cap'], $a['q'] ? number_format($a['ms'] / $a['q'] / 1000, 1) : '-');
            $tot['q'] += $a['q']; $tot['err'] += $a['err']; $tot['cap'] += $a['cap'];
        }
        printf("%-15s %-10s %5s %9s %8s %10s %9s %10d %5s %5s   (%d%% of the cap)\n", '', '= day', '', '', '', '', '', $dayCap[$d] ?? 0, '', '', $cap > 0 ? round(100 * ($dayCap[$d] ?? 0) / $cap) : 0);
        $tot['captok'] += $dayCap[$d] ?? 0;
    }
    echo "\ntotal: {$tot['q']} questions, {$tot['captok']} cap tokens, {$tot['err']} errors, {$tot['cap']} cap hits\n";
    $e = $conn->prepare("SELECT error, COUNT(*) n FROM assistant_logs WHERE created_at >= ? AND created_at < ? AND error IS NOT NULL AND error <> 'daily_cap_reached' GROUP BY error ORDER BY n DESC LIMIT 5");
    $e->bind_param('ss', $fromUtc, $toUtc); $e->execute(); $er = $e->get_result();
    while ($x = $er->fetch_assoc()) echo "  error x{$x['n']}: " . mb_substr($x['error'], 0, 100) . "\n";
    $e->close();
}

echo "\n";
if (!$has('assistant_actions')) {
    echo "no assistant_actions table yet (no assignment confirmed from a card here)\n";
} else {
    $st = $conn->prepare("SELECT action, whatsapp_sent, whatsapp_result, created_at FROM assistant_actions WHERE created_at >= ? AND created_at < ? ORDER BY created_at");
    $st->bind_param('ss', $fromUtc, $toUtc); $st->execute(); $res = $st->get_result();
    $act = [];
    while ($x = $res->fetch_assoc()) {
        $d = $romeDay($x['created_at']);
        $a = &$act[$d];
        if (!$a) $a = ['confirm' => 0, 'undo' => 0, 'wa_sent' => 0, 'wa_dry' => 0, 'wa_not' => 0, 'wa_fail' => 0];
        if ($x['action'] === 'undo') $a['undo']++; else $a['confirm']++;
        $w = (string) $x['whatsapp_result'];
        if ((int) $x['whatsapp_sent'] === 1) $a['wa_sent']++;
        elseif (strpos($w, 'DRY RUN') === 0) $a['wa_dry']++;
        elseif (strpos($w, 'failed') === 0) $a['wa_fail']++;
        elseif (strpos($w, 'not sent') === 0) $a['wa_not']++;
        unset($a);
    }
    $st->close();
    printf("%-15s %8s %6s %8s %8s %9s %9s\n", 'day', 'confirms', 'undos', 'WA sent', 'WA dry', 'WA not', 'WA failed');
    if (!$act) echo "(no confirms or undos in this period)\n";
    foreach ($act as $d => $a) printf("%-15s %8d %6d %8d %8d %9d %9d\n", $d, $a['confirm'], $a['undo'], $a['wa_sent'], $a['wa_dry'], $a['wa_not'], $a['wa_fail']);
}

// Step 4.10b: the home-screen app (installed PWA) - loads vs failures per day, and for each failure
// what the server check found. Rules in tools/pwa_load_review_lib.php.
require_once __DIR__ . '/pwa_load_review_lib.php';
echo "\nHOME-SCREEN APP (client_perf, display_mode=standalone)\n";
if (!$has('client_perf')) {
    echo "no client_perf table yet\n";
} else {
    $st = $conn->prepare("SELECT id, user_id, created_at, route, reason, verify_status, chunk_status, list_status, timeouts,
                                 shell_fallback, display_mode, stuck, probe_status, probe_ms, sent_late
                          FROM client_perf WHERE created_at >= ? AND created_at < ? ORDER BY created_at");
    $st->bind_param('ss', $fromUtc, $toUtc); $st->execute(); $res = $st->get_result();
    $pwa = []; $tabs = 0; $tabFail = 0;
    while ($x = $res->fetch_assoc()) {
        $x['rome_day'] = $romeDay($x['created_at']);
        if ($x['display_mode'] === 'standalone') { $pwa[] = $x; continue; }
        $tabs++;
        if (pwaIsFailure($x)) $tabFail++;
    }
    $st->close();
    $allDays = [];
    for ($i = 0; $i < $days; $i++) $allDays[] = (clone $first)->modify("+$i days")->format('Y-m-d D');
    $rev = pwaReview($pwa, $allDays);
    printf("%-15s %6s %8s %11s %16s\n", 'day', 'loads', 'failures', 'full stalls', 'server reachable');
    foreach ($rev['byDay'] as $d => $a) printf("%-15s %6d %8d %11d %16d\n", $d, $a['loads'], $a['failures'], $a['full_stalls'], $a['reachable_stalls']);
    if (!$rev['failures']) echo "(no failed home-screen app loads in this period)\n";
    foreach ($rev['failures'] as $f) {
        $t = (new DateTime($f['created_at'], $utc))->setTimezone($rome)->format('D H:i:s');
        $u = $users[(int) $f['user_id']] ?? ('#' . $f['user_id']);
        printf("  %s %-8s %-18s %-22s check: %s%s%s\n", $t, $u, mb_substr((string) $f['route'], 0, 18), pwaFailureKind($f),
            pwaCheckVerdict($f['probe_status']), $f['probe_ms'] !== null ? " ({$f['probe_ms']} ms)" : '',
            ($f['stuck'] ? " - stalled: {$f['stuck']}" : '') . ((int) $f['sent_late'] === 1 ? ' - arrived late' : ''));
    }
    echo "(browser tabs, for comparison: $tabs loads, $tabFail failures)\n";
    echo pwaRecommendation($rev), "\n";
}
