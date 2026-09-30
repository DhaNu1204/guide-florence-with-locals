<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only (step 7.6 go-live): never deployed
/**
 * Step 7.6 go-live check on production: status as admin + viewer, the expected numbers straight from
 * the pages' own sources (Tours day header SQL, the Unassigned Report endpoint, the Daily P&L endpoint),
 * then the 3 read-only questions through the REAL endpoint as the owner. 2.5 s between requests.
 * Temporary sessions are deleted at the end. Asks nothing that changes data.
 *
 *   FWL_API_DIR=<api dir> php82 tools/golive_check.php --host=https://withlocals.deetech.cc --user=dhanu
 */
$apiDir = getenv('FWL_API_DIR');
$_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REQUEST_URI'] = '/cli';
require $apiDir . '/config.php';
require_once $apiDir . '/Middleware.php';
$o = [];
foreach (array_slice($argv, 1) as $a) if (preg_match('/^--([a-z]+)=(.+)$/', $a, $m)) $o[$m[1]] = $m[2];
$host = rtrim($o['host'], '/');

$made = [];
$session = function ($username) use ($conn, &$made) {
    $s = $conn->prepare("SELECT id FROM users WHERE username = ?"); $s->bind_param('s', $username); $s->execute();
    $u = $s->get_result()->fetch_assoc(); $s->close();
    $raw = bin2hex(random_bytes(32)); $h = Middleware::hashToken($raw); $sid = Middleware::SESSION_ID_PREFIX . $h; $uid = (int) $u['id'];
    $s = $conn->prepare("INSERT INTO sessions (session_id, token, user_id, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))");
    $s->bind_param('ssi', $sid, $h, $uid); $s->execute(); $s->close();
    $made[] = $h;
    return $raw;
};
register_shutdown_function(function () use ($conn, &$made) {
    $n = 0;
    foreach ($made as $h) { $d = $conn->prepare("DELETE FROM sessions WHERE token = ?"); $d->bind_param('s', $h); $d->execute(); $n += $d->affected_rows; $d->close(); }
    echo "temporary sessions deleted: $n\n";
});
function http($method, $path, $token, $body = null, $timeout = 60) {
    global $host;
    usleep(2500000); // load rule: >= 2 s between requests
    $ch = curl_init($host . '/api/' . $path);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json', 'User-Agent: Mozilla/5.0 (FWL go-live check)']]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, json_decode((string) $r, true)];
}
function q($sql, $types, ...$p) { global $conn; $s = $conn->prepare($sql); $s->bind_param($types, ...$p); $s->execute(); $r = $s->get_result()->fetch_assoc(); $s->close(); return $r; }

$rome = new DateTimeZone('Europe/Rome');
$now = new DateTime('now', $rome);
$today = $now->format('Y-m-d');
$tomorrow = (clone $now)->modify('+1 day')->format('Y-m-d');
$yesterday = (clone $now)->modify('-1 day')->format('Y-m-d');
$plus6 = (clone $now)->modify('+6 day')->format('Y-m-d');
$plus7 = (clone $now)->modify('+7 day')->format('Y-m-d');
echo "now {$now->format('Y-m-d H:i')} Rome\n\n";

$A = $session($o['user']);
$vr = $conn->query("SELECT username FROM users WHERE role = 'viewer' ORDER BY id LIMIT 1")->fetch_assoc();
$V = $vr ? $session($vr['username']) : null;

list($c, $b) = http('GET', 'assistant.php?action=status', $A);
echo "status as {$o['user']}: $c " . json_encode($b) . "\n";
if ($V) { list($c, $b) = http('GET', 'assistant.php?action=status', $V); echo "status as viewer {$vr['username']}: $c " . json_encode($b) . "\n"; }

// ---- expected, from the pages' own sources ----
$T = "(pr.product_type = 'tour' OR t.product_id IS NULL)"; // the Tours list condition
$groups = q("SELECT COUNT(DISTINCT t.group_id) n FROM tours t LEFT JOIN products pr ON pr.bokun_product_id = t.product_id WHERE t.date = ? AND t.group_id IS NOT NULL AND $T", 's', $tomorrow)['n'];
$singles = q("SELECT COUNT(*) n FROM tours t LEFT JOIN products pr ON pr.bokun_product_id = t.product_id WHERE t.date = ? AND t.group_id IS NULL AND t.cancelled = 0 AND $T", 's', $tomorrow)['n'];
$guests = q("SELECT COALESCE(SUM(CASE WHEN t.group_id IS NOT NULL THEN COALESCE(t.participants, 0)
                ELSE COALESCE(NULLIF(CAST(JSON_UNQUOTE(JSON_EXTRACT(t.bokun_data, '$.productBookings[0].fields.totalParticipants')) AS UNSIGNED), 0), NULLIF(t.participants, 0), 1) END), 0) n
             FROM tours t LEFT JOIN products pr ON pr.bokun_product_id = t.product_id WHERE t.date = ? AND t.cancelled = 0 AND $T", 's', $tomorrow)['n'];
echo "\nEXPECTED 1 Tours page $tomorrow: " . ($groups + $singles) . " departures, $guests guests\n";
list($c, $b) = http('GET', "tours.php?action=unassigned-report&start_date=$today&end_date=$plus6&count_only=true", $A);
$u1 = $b['data']['total'] ?? '?';
list($c, $b) = http('GET', "tours.php?action=unassigned-report&start_date=$tomorrow&end_date=$plus7&count_only=true", $A);
$u2 = $b['data']['total'] ?? '?';
echo "EXPECTED 2 Unassigned Report: $today..$plus6 = $u1, $tomorrow..$plus7 = $u2\n";
list($c, $b) = http('GET', "pnl.php?date=$yesterday", $A);
$t = $b['data']['totals'] ?? [];
echo "EXPECTED 3 Daily P&L $yesterday: " . json_encode($t) . "\n\n";

// ---- the 3 questions, through the real endpoint ----
$Q = ["How many tours do we have tomorrow, and how many guests?", "What's unassigned in the next 7 days?", "How much did we make yesterday?"];
$from = isset($o['from']) ? (int) $o['from'] : 1; // --from=2 skips questions already asked
foreach ($Q as $i => $question) {
    if ($i + 1 < $from) continue;
    list($c, $b) = http('POST', 'assistant.php', $A, ['message' => $question], 90);
    $vals = [];
    $blk = $b['blocks'] ?? []; // a variable: array_walk_recursive takes it by reference
    array_walk_recursive($blk, function ($v, $k) use (&$vals) { if ($k !== 'type' && $v !== null && !is_bool($v)) $vals[] = "$k=$v"; });
    echo "Q" . ($i + 1) . " HTTP $c  $question\n   A: " . str_replace("\n", ' / ', (string) ($b['text'] ?? json_encode($b))) . "\n";
    if (!empty($b['blocks'])) {
        echo "   blocks: " . implode(', ', array_map(function ($x) { return $x['type']; }, $b['blocks'])) . "\n";
        echo "   values: " . mb_substr(implode(' | ', array_filter($vals, function ($v) { return !preg_match('/^(departure_id|route)=/', $v); })), 0, 900) . "\n";
    }
    echo "   meta: tools " . implode(',', $b['meta']['tools_called'] ?? []) . " | in " . ($b['meta']['input_tokens'] ?? '?') . " out " . ($b['meta']['output_tokens'] ?? '?') . " | " . ($b['meta']['ms'] ?? '?') . " ms\n\n";
}
