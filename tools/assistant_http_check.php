<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only (step 7.1): never deployed
/**
 * Step 7.1 - the assistant through the REAL endpoint, plus the 12-row deploy smoke test.
 * STAGING ONLY. Temporary sessions (owner, second admin, viewer) are created and deleted at the end.
 *
 *   FWL_API_DIR=<staging api> php tools/assistant_http_check.php --host=https://stagingwithlocals.deetech.cc --sha=<git sha>
 *
 * (a) "how many tours today" / "quanti tour domani": the reply must contain the departures and
 *     guests the Tours page shows - computed here from the page's own list endpoint
 *     (tours.php?date=&view=list) folded the way Tours.jsx folds it, independently of day_summary.
 * (b) viewer 403 · (d) 31 requests in a minute -> 429 (per user) · (e) one log row per question.
 */
$apiDir = getenv('FWL_API_DIR') ?: __DIR__ . '/../public_html/api';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/cli';
require $apiDir . '/config.php';
require_once $apiDir . '/Middleware.php';

$host = null; $sha = null; $skipSmoke = false;
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--host=(.+)$/', $a, $m)) { $host = rtrim($m[1], '/'); }
    if (preg_match('/^--sha=(.+)$/', $a, $m)) { $sha = $m[1]; }
    if ($a === '--skip-smoke') { $skipSmoke = true; }
}
$env = (string) EnvLoader::get('APP_ENV', '');
if (!$host || stripos($host, 'staging') === false || strcasecmp($env, 'staging') !== 0) {
    fwrite(STDERR, "refused: staging only (host=$host env=$env)\n");
    exit(2);
}

$failures = 0;
function check($label, $ok, $detail = '') {
    global $failures;
    if (!$ok) { $failures++; }
    printf("%s  %s%s\n", $ok ? 'ok  ' : 'FAIL', $label, $detail !== '' ? "  ($detail)" : '');
}

// ---- temporary sessions ----------------------------------------------------------------------
$tokens = [];
function tempSession($conn, $userId) {
    $raw = bin2hex(random_bytes(32));
    $hash = Middleware::hashToken($raw);
    $sid = Middleware::SESSION_ID_PREFIX . $hash;
    $s = $conn->prepare("INSERT INTO sessions (session_id, token, user_id, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))");
    $s->bind_param('ssi', $sid, $hash, $userId); $s->execute(); $s->close();
    return $raw;
}
$owner = $conn->query("SELECT id, username FROM users WHERE role = 'admin' AND LOWER(username) = 'dhanu' LIMIT 1")->fetch_assoc();
$admin2 = $conn->query("SELECT id, username FROM users WHERE role = 'admin' AND LOWER(username) <> 'dhanu' ORDER BY id LIMIT 1")->fetch_assoc();
$viewer = $conn->query("SELECT id, username FROM users WHERE role = 'viewer' ORDER BY id LIMIT 1")->fetch_assoc();
if (!$owner || !$admin2 || !$viewer) {
    fwrite(STDERR, "need users: an admin 'dhanu', a second admin and a viewer\n");
    exit(2);
}
$tokens['owner'] = tempSession($conn, (int) $owner['id']);
$tokens['admin2'] = tempSession($conn, (int) $admin2['id']);
$tokens['viewer'] = tempSession($conn, (int) $viewer['id']);
echo "users: owner={$owner['username']} admin2={$admin2['username']} viewer={$viewer['username']}\n\n";

/** One HTTP call. $who = owner|admin2|viewer|null. Returns [code, json, rawBody, headers]. */
function http($method, $url, $who = null, $body = null, $timeout = 20, $pause = 1300000) {
    global $tokens;
    usleep($pause); // the host drops bursts faster than ~1 request/second
    $h = "Content-Type: application/json\r\nUser-Agent: Mozilla/5.0 (FWL step 7.1 check)\r\n";
    if ($who !== null) { $h .= "Authorization: Bearer {$tokens[$who]}\r\n"; }
    $ctx = stream_context_create(['http' => [
        'method' => $method, 'header' => $h,
        'content' => $body === null ? '' : (is_string($body) ? $body : json_encode($body)),
        'timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 0,
    ]]);
    $out = @file_get_contents($url, false, $ctx);
    $code = 0; $headers = [];
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+ (\d{3})#', $line, $m)) { $code = (int) $m[1]; $headers = []; continue; }
        $headers[] = $line;
    }
    return [$code, json_decode((string) $out, true), (string) $out, $headers];
}
function headerCount(array $headers, $name) {
    $n = 0;
    foreach ($headers as $h) { if (stripos($h, $name . ':') === 0) { $n++; } }
    return $n;
}

try {
    // ---- the 12-row smoke test (.claude/skills/staging-deploy) --------------------------------
    if (!$skipSmoke) {
        echo "== smoke test ==\n";
        list($c, $j) = http('GET', "$host/api/health.php");
        check('1  health 200 + sha', $c === 200 && $sha !== null && ($j['sha'] ?? '') === $sha, "$c " . ($j['sha'] ?? '-'));
        list($c, , $raw, $hdr) = http('GET', "$host/");
        check('2  GET / 200 with /assets/index-', $c === 200 && strpos($raw, '/assets/index-') !== false, (string) $c);
        check('12 one HSTS, one X-Frame-Options, a CSP on /',
            headerCount($hdr, 'Strict-Transport-Security') === 1 && headerCount($hdr, 'X-Frame-Options') === 1 && headerCount($hdr, 'Content-Security-Policy') >= 1,
            'HSTS ' . headerCount($hdr, 'Strict-Transport-Security') . ' XFO ' . headerCount($hdr, 'X-Frame-Options') . ' CSP ' . headerCount($hdr, 'Content-Security-Policy'));
        list($c) = http('GET', "$host/api/tours.php?upcoming=true&per_page=1");
        check('3  tours no token 401', $c === 401, (string) $c);
        list($c, $j) = http('GET', "$host/api/tours.php?upcoming=true&per_page=1", 'owner');
        check('4  tours admin 200 + data[]', $c === 200 && isset($j['data']) && is_array($j['data']), (string) $c);
        list($c) = http('GET', "$host/api/tour-groups.php?upcoming=true", 'owner');
        check('5  tour-groups admin 200', $c === 200, (string) $c);
        list($c) = http('DELETE', "$host/api/tours.php/999999", 'viewer');
        check('6  DELETE tour as viewer 403', $c === 403, (string) $c);
        list($c) = http('POST', "$host/api/payments.php", 'viewer', '{}');
        check('7  POST payment as viewer 403', $c === 403, (string) $c);
        list($c, , $raw) = http('GET', "$host/api/bokun_sync.php?action=config", 'owner');
        check('8  bokun config 200, no secrets', $c === 200 && stripos($raw, 'secret_key') === false && stripos($raw, 'api_secret') === false, (string) $c);
        $codes = [];
        foreach (['migrate_database.php', 'fix_tour_dates.php', 'check_environment.php'] as $f) { list($codes[]) = http('GET', "$host/api/$f"); }
        check('9  maintenance scripts 404/403', count(array_diff($codes, [403, 404])) === 0, implode(' ', $codes));
        list($e1) = http('GET', "$host/.env.local"); list($e2) = http('GET', "$host/api/.env");
        check('10 .env files 403/404', in_array($e1, [403, 404], true) && in_array($e2, [403, 404], true), "$e1 $e2");
        list($c, $j) = http('GET', "$host/api/bokun_sync.php?action=sync-info", 'owner');
        // last_sync is the latest COMPLETED run (getSyncInfo), so a failed run can never be shown as last
        $last = isset($j['last_sync']['completed_at']) ? $j['last_sync']['completed_at'] . ' ' . ($j['last_sync']['sync_type'] ?? '') : 'none';
        check('11 sync-info 200, last completed run', $c === 200 && array_key_exists('last_sync', (array) $j), "$c last=$last");
        echo "\n";
    }

    // ---- assistant: guards ---------------------------------------------------------------------
    echo "== assistant guards ==\n";
    list($c, $j) = http('GET', "$host/api/assistant.php", 'owner');
    check('GET -> 405', $c === 405, "$c " . ($j['error'] ?? ''));
    list($c) = http('POST', "$host/api/assistant.php", null, ['message' => 'hi']);
    check('no token -> 401', $c === 401, (string) $c);
    list($c, $j) = http('POST', "$host/api/assistant.php", 'viewer', ['message' => 'how many tours today']);
    check('(b) viewer token -> 403', $c === 403, "$c " . ($j['error'] ?? ''));
    list($c, $j) = http('POST', "$host/api/assistant.php", 'owner', ['message' => str_repeat('a', 1001)]);
    check('1001-character message -> 400', $c === 400, "$c " . ($j['error'] ?? ''));
    list($c, $j) = http('POST', "$host/api/assistant.php", 'owner', ['message' => 'hi', 'conversation_id' => 999999999]);
    check("someone else's / unknown conversation -> 404", $c === 404 && ($j['error'] ?? '') === 'conversation_not_found', "$c " . ($j['error'] ?? ''));

    // ---- (a) the two questions vs the Tours page ------------------------------------------------
    echo "\n== (a) questions vs the Tours page ==\n";
    $rome = new DateTimeZone('Europe/Rome');
    $logsBefore = (int) $conn->query("SELECT COUNT(*) AS n FROM assistant_logs")->fetch_assoc()['n'];
    $asked = 0;
    $questions = [
        ['how many tours today', (new DateTime('now', $rome))->format('Y-m-d')],
        ['quanti tour domani', (new DateTime('tomorrow', $rome))->format('Y-m-d')],
        ['how many tours tomorrow', (new DateTime('tomorrow', $rome))->format('Y-m-d')],
    ];
    $convId = null;
    foreach ($questions as $q) {
        list($question, $date) = $q;
        // Tours page numbers: the list endpoint the page uses, folded like Tours.jsx computeItemStats
        list($c, $j) = http('GET', "$host/api/tours.php?date=$date&view=list&per_page=500", 'owner');
        $groups = []; $dep = 0; $pax = 0; $cancelledSingles = 0;
        foreach (($j['data'] ?? []) as $t) {
            if (!empty($t['group_id'])) {
                if (!isset($groups[$t['group_id']])) { $groups[$t['group_id']] = true; $dep++; }
                if (empty($t['cancelled'])) { $pax += (int) $t['participants']; }
            } elseif (!empty($t['cancelled'])) {
                $cancelledSingles++;
            } else {
                $dep++;
                $pax += (int) ($t['total_participants'] ?: $t['participants'] ?: 1);
            }
        }
        echo "Tours page $date: $dep tours · $pax PAX" . ($cancelledSingles ? " · $cancelledSingles cancelled" : '') . "  (list rows: " . count($j['data'] ?? []) . ")\n";

        list($c, $a) = http('POST', "$host/api/assistant.php", 'owner', ['message' => $question], 45);
        $asked++;
        $text = (string) ($a['text'] ?? '');
        echo "Q: $question\nA: " . str_replace("\n", "\n   ", $text) . "\n";
        echo "   tools " . json_encode($a['meta']['tools_called'] ?? null) . ", tokens in " . ($a['meta']['input_tokens'] ?? '?') . " / out " . ($a['meta']['output_tokens'] ?? '?') . ", " . ($a['meta']['ms'] ?? '?') . " ms\n";
        $none = '/\b(no|none|not any|nessun\w*|non ci sono|zero)\b/iu';
        $hasDep = $dep === 0 ? preg_match($none, $text) === 1
            : preg_match('/(?<![\d.,:])' . $dep . '(?![\d.,:]\d|\d)/', $text) === 1;
        $hasPax = $pax === 0 ? $hasDep
            : preg_match('/(?<![\d.,:])' . $pax . '(?![\d.,:]\d|\d)/', $text) === 1;
        // language: an English question must get English back, an Italian one Italian
        $italianQ = preg_match('/\b(quanti|oggi|domani)\b/iu', $question) === 1;
        $italianA = preg_match('/\b(ci sono|oggi|domani|ospiti|nessun\w*|partenze)\b/iu', $text) === 1;
        check("    reply language matches the question (" . ($italianQ ? 'Italian' : 'English') . ")", $italianQ === $italianA);
        check("(a) \"$question\": 200 and the reply has $dep departures and $pax guests", $c === 200 && $hasDep && $hasPax, "$c dep " . ($hasDep ? 'yes' : 'no') . ", pax " . ($hasPax ? 'yes' : 'no'));
        $logRow = $conn->query("SELECT * FROM assistant_logs ORDER BY id DESC LIMIT 1")->fetch_assoc();
        $called = json_decode((string) $logRow['tools_called'], true) ?: [];
        $calledDate = $called[0]['input']['date'] ?? null;
        check("    day_summary called for $date", $calledDate === $date, (string) $calledDate);
        echo "\n";
    }

    // ---- (e) log rows ----------------------------------------------------------------------------
    echo "== (e) assistant_logs ==\n";
    $rows = $conn->query("SELECT id, user_id, LEFT(question, 40) AS q, tools_offered, rounds, input_tokens, output_tokens, ms, error FROM assistant_logs ORDER BY id DESC LIMIT " . (int) $asked);
    $n = 0; $good = 0;
    while ($r = $rows->fetch_assoc()) {
        $n++;
        if ((int) $r['input_tokens'] > 0 && (int) $r['output_tokens'] > 0 && (int) $r['ms'] > 0 && $r['error'] === null) { $good++; }
        printf("   #%d user %d \"%s\" offered %s rounds %d tokens %d/%d %d ms error %s\n", $r['id'], $r['user_id'], $r['q'], $r['tools_offered'], $r['rounds'], $r['input_tokens'], $r['output_tokens'], $r['ms'], $r['error'] ?? '-');
    }
    $logsAfter = (int) $conn->query("SELECT COUNT(*) AS n FROM assistant_logs")->fetch_assoc()['n'];
    check("(e) one log row per question ($asked asked, " . ($logsAfter - $logsBefore) . " new), tokens + ms filled", $logsAfter - $logsBefore === $asked && $good === $asked, "$good good");

    // ---- (d) rate limit, per user ------------------------------------------------------------
    echo "\n== (d) 31 requests in a minute (as {$admin2['username']}, invalid body = no model call) ==\n";
    $codes = [];
    $t0 = microtime(true);
    for ($i = 1; $i <= 31; $i++) {
        list($codes[]) = http('POST', "$host/api/assistant.php", 'admin2', '{}', 20, 1100000);
    }
    $secs = round(microtime(true) - $t0, 1);
    $first30 = array_count_values(array_slice($codes, 0, 30));
    echo "   first 30: " . json_encode($first30) . "   31st: {$codes[30]}   ({$secs} s)\n";
    check('(d) requests 1-30 pass the limiter (400 bad body), the 31st gets 429', ($first30[400] ?? 0) === 30 && $codes[30] === 429);
    list($c) = http('POST', "$host/api/assistant.php", 'owner', '{}');
    check('    the limit is per user: the owner still gets through (400, not 429)', $c === 400, (string) $c);
    $logsAfter2 = (int) $conn->query("SELECT COUNT(*) AS n FROM assistant_logs")->fetch_assoc()['n'];
    check('    rejected requests write no log rows', $logsAfter2 === $logsAfter, ($logsAfter2 - $logsAfter) . ' new');
} finally {
    $d = $conn->prepare("DELETE FROM sessions WHERE token = ?");
    foreach ($tokens as $raw) { $h = Middleware::hashToken($raw); $d->bind_param('s', $h); $d->execute(); }
    $d->close();
    echo "\ntemporary sessions deleted: " . count($tokens) . "\n";
}

echo "\n" . ($failures === 0 ? "ALL OK\n" : "$failures FAILED\n");
exit($failures === 0 ? 0 : 1);
