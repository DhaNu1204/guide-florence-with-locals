<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only (step 6.19): never deployed
/**
 * Step 6.19 - the "needs a guide" flag and guide_assignment_log through the REAL endpoints, on
 * synthetic rows only (date 2030-02-17, external_id LIKE 'FWL-T619-%'), with a temporary admin
 * session. Everything it creates (tours, group, log rows, session) is deleted at the end.
 *
 *   FWL_API_DIR=<staging api> php tools/guide_log_http_check.php --host=https://stagingwithlocals.deetech.cc
 *   FWL_API_DIR=<production api> php tools/guide_log_http_check.php --host=https://withlocals.deetech.cc --production
 *
 * Production runs the single-booking part only (one synthetic booking); staging also runs the
 * group part.
 */
$apiDir = getenv('FWL_API_DIR') ?: __DIR__ . '/../public_html/api';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/cli';
require $apiDir . '/config.php';
require_once $apiDir . '/Middleware.php';
require_once $apiDir . '/lib/guide_assignment_log.php';

$host = null; $prod = false;
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--host=(.+)$/', $a, $m)) { $host = rtrim($m[1], '/'); }
    if ($a === '--production') { $prod = true; }
}
$env = class_exists('EnvLoader') ? (string) EnvLoader::get('APP_ENV', '') : (string) getenv('APP_ENV');
$isStaging = $host && stripos($host, 'staging') !== false && strcasecmp($env, 'staging') === 0;
$isProd = $prod && $host === 'https://withlocals.deetech.cc' && strcasecmp($env, 'production') === 0;
if (!$isStaging && !$isProd) {
    fwrite(STDERR, "refused: staging, or production with --production (host=$host env=$env)\n");
    exit(2);
}

const T_DATE = '2030-02-17';
const T_PREFIX = 'FWL-T619-';
$failures = 0;
function check($label, $ok, $detail = '') {
    global $failures;
    if (!$ok) { $failures++; }
    printf("%s  %s%s\n", $ok ? 'ok  ' : 'FAIL', $label, $detail !== '' ? "  ($detail)" : '');
}
function testIds($conn) {
    $ids = [];
    $r = $conn->query("SELECT id FROM tours WHERE external_id LIKE '" . T_PREFIX . "%'");
    while ($x = $r->fetch_assoc()) { $ids[] = (int) $x['id']; }
    return $ids;
}
function cleanup($conn) {
    ensureGuideAssignmentLogTable($conn);
    $ids = testIds($conn);
    $gids = [];
    $r = $conn->query("SELECT id FROM tour_groups WHERE group_date = '" . T_DATE . "' AND display_name LIKE 'FWL 6.19%'");
    while ($x = $r->fetch_assoc()) { $gids[] = (int) $x['id']; }
    if ($ids) { $conn->query("DELETE FROM guide_assignment_log WHERE tour_id IN (" . implode(',', $ids) . ")"); }
    if ($gids) { $conn->query("DELETE FROM guide_assignment_log WHERE group_id IN (" . implode(',', $gids) . ")"); }
    $conn->query("UPDATE tours SET group_id = NULL WHERE external_id LIKE '" . T_PREFIX . "%'");
    if ($gids) { $conn->query("DELETE FROM tour_groups WHERE id IN (" . implode(',', $gids) . ")"); }
    $conn->query("DELETE FROM tours WHERE external_id LIKE '" . T_PREFIX . "%'");
}
function addTour($conn, $n) {
    $ext = T_PREFIX . $n; $bid = 'T619' . $n; $d = T_DATE; $title = 'FWL 6.19 test booking';
    $s = $conn->prepare("INSERT INTO tours (external_id, bokun_booking_id, title, date, time, participants,
                                            is_private, cancelled, paid, language, needs_guide_assignment, created_at, updated_at)
                         VALUES (?, ?, ?, ?, '17:00:00', 2, 0, 0, 0, 'English', 1, NOW(), NOW())");
    $s->bind_param('ssss', $ext, $bid, $title, $d);
    $s->execute(); $id = (int) $conn->insert_id; $s->close();
    return $id;
}
function tourRow($conn, $id) { return $conn->query("SELECT guide_id, needs_guide_assignment FROM tours WHERE id = " . (int) $id)->fetch_assoc(); }
function logRows($conn, $where) {
    $rows = [];
    $r = $conn->query("SELECT * FROM guide_assignment_log WHERE $where ORDER BY id");
    while ($x = $r->fetch_assoc()) { $rows[] = $x; }
    return $rows;
}

$raw = bin2hex(random_bytes(32));
$hash = Middleware::hashToken($raw);
$sid = Middleware::SESSION_ID_PREFIX . $hash;
$adminRow = $conn->query("SELECT id, username FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetch_assoc();
$admin = (int) $adminRow['id'];
$s = $conn->prepare("INSERT INTO sessions (session_id, token, user_id, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))");
$s->bind_param('ssi', $sid, $hash, $admin); $s->execute(); $s->close();

function api($method, $path, $body = null) {
    global $host, $raw;
    usleep(1300000); // the host drops bursts faster than ~1 request/second
    $ctx = stream_context_create(['http' => [
        'method' => $method,
        'header' => "Authorization: Bearer $raw\r\nContent-Type: application/json\r\n",
        'content' => $body === null ? '' : json_encode($body),
        'timeout' => 40, 'ignore_errors' => true,
    ]]);
    $out = @file_get_contents($host . '/api/' . $path, false, $ctx);
    $code = 0;
    foreach ($http_response_header ?? [] as $h) { if (preg_match('#^HTTP/\S+ (\d{3})#', $h, $m)) { $code = (int) $m[1]; } }
    return [$code, json_decode((string) $out, true)];
}

try {
    cleanup($conn);
    // two guides that have nothing on the test date (it is in 2030)
    $g = [];
    $r = $conn->query("SELECT id FROM guides ORDER BY id LIMIT 2");
    while ($x = $r->fetch_assoc()) { $g[] = (int) $x['id']; }
    $tid = addTour($conn, 1);
    echo "test booking t$tid, guides " . implode(', ', $g) . ", admin user $admin\n";

    // 1. Tours page: assign
    list($c) = api('PUT', "tours.php/$tid", ['guide_id' => $g[0]]);
    $t = tourRow($conn, $tid);
    $l = logRows($conn, "tour_id = $tid");
    check('assign -> 200', $c === 200, "HTTP $c");
    check('assign -> guide set, needs_guide_assignment 0', (int) $t['guide_id'] === $g[0] && (int) $t['needs_guide_assignment'] === 0, json_encode($t));
    check('assign -> 1 log row (NULL -> guide, tours_page, user)', count($l) === 1 && $l[0]['old_guide_id'] === null
        && (int) $l[0]['new_guide_id'] === $g[0] && $l[0]['source'] === 'tours_page' && (int) $l[0]['user_id'] === $admin
        && $l[0]['username'] === $adminRow['username'] && $l[0]['group_id'] === null, json_encode(end($l)));

    // 2. same guide again: no new row
    list($c) = api('PUT', "tours.php/$tid", ['guide_id' => $g[0]]);
    check('same guide again -> still 1 log row', $c === 200 && count(logRows($conn, "tour_id = $tid")) === 1, "HTTP $c");

    // 3. Tours page: unassign
    list($c) = api('PUT', "tours.php/$tid", ['guide_id' => null]);
    $t = tourRow($conn, $tid);
    $l = logRows($conn, "tour_id = $tid");
    check('unassign -> 200', $c === 200, "HTTP $c");
    check('unassign -> guide NULL, needs_guide_assignment 1', $t['guide_id'] === null && (int) $t['needs_guide_assignment'] === 1, json_encode($t));
    check('unassign -> 2nd log row (guide -> NULL, tours_page)', count($l) === 2 && (int) $l[1]['old_guide_id'] === $g[0]
        && $l[1]['new_guide_id'] === null && $l[1]['source'] === 'tours_page', json_encode(end($l)));

    // 4. other fields only: guide + flag untouched, no row
    list($c) = api('PUT', "tours.php/$tid", ['notes' => 'FWL 6.19 note']);
    $t = tourRow($conn, $tid);
    check('notes only -> flag untouched, no log row', $c === 200 && (int) $t['needs_guide_assignment'] === 1 && count(logRows($conn, "tour_id = $tid")) === 2, json_encode($t));

    // 5. assistant confirm + undo (the confirm card's body)
    list($c) = api('PUT', "tours.php/$tid", ['guide_id' => $g[1], 'expected_previous_guide_id' => null]);
    list($c2) = api('PUT', "tours.php/$tid", ['guide_id' => null, 'expected_previous_guide_id' => $g[1], 'assistant_undo' => true, 'force' => true]);
    $l = logRows($conn, "tour_id = $tid");
    $t = tourRow($conn, $tid);
    check('assistant confirm + undo -> 200 / 200', $c === 200 && $c2 === 200, "HTTP $c / $c2");
    check('assistant rows: source assistant then assistant_undo', count($l) === 4 && $l[2]['source'] === 'assistant'
        && (int) $l[2]['new_guide_id'] === $g[1] && $l[3]['source'] === 'assistant_undo' && $l[3]['new_guide_id'] === null,
        implode(',', array_column($l, 'source')));
    check('after undo -> needs_guide_assignment 1', (int) $t['needs_guide_assignment'] === 1, json_encode($t));

    // 6. stale card still refused, and writes nothing
    list($c) = api('PUT', "tours.php/$tid", ['guide_id' => $g[0], 'expected_previous_guide_id' => $g[1]]);
    check('stale card -> 409, no log row', $c === 409 && count(logRows($conn, "tour_id = $tid")) === 4, "HTTP $c");

    if ($isStaging) {
        // 7. group assign / unassign (staging only)
        $t2 = addTour($conn, 2);
        $t3 = addTour($conn, 3);
        $d = T_DATE;
        $s = $conn->prepare("INSERT INTO tour_groups (group_date, group_time, display_name, max_pax, total_pax, is_manual_merge, created_at, updated_at)
                             VALUES (?, '17:00:00', 'FWL 6.19 test group', 9, 4, 1, NOW(), NOW())");
        $s->bind_param('s', $d); $s->execute(); $gid = (int) $conn->insert_id; $s->close();
        $conn->query("UPDATE tours SET group_id = $gid WHERE id IN ($t2, $t3)");

        list($c) = api('PUT', "tour-groups.php/$gid", ['guide_id' => $g[0]]);
        $a = tourRow($conn, $t2); $b = tourRow($conn, $t3);
        $l = logRows($conn, "group_id = $gid");
        check('group assign -> 200, members guide + flag 0', $c === 200 && (int) $a['guide_id'] === $g[0] && (int) $a['needs_guide_assignment'] === 0
            && (int) $b['needs_guide_assignment'] === 0, "HTTP $c " . json_encode([$a, $b]));
        check('group assign -> 1 group log row (group_assign)', count($l) === 1 && $l[0]['tour_id'] === null
            && $l[0]['source'] === 'group_assign' && (int) $l[0]['new_guide_id'] === $g[0] && (int) $l[0]['user_id'] === $admin, json_encode(end($l)));

        list($c) = api('PUT', "tour-groups.php/$gid", ['guide_id' => null]);
        $a = tourRow($conn, $t2);
        $l = logRows($conn, "group_id = $gid");
        check('group unassign -> members flag 1, 2nd row guide -> NULL', $c === 200 && (int) $a['needs_guide_assignment'] === 1
            && count($l) === 2 && $l[1]['new_guide_id'] === null && (int) $l[1]['old_guide_id'] === $g[0], "HTTP $c");

        list($c) = api('PUT', "tour-groups.php/$gid", ['display_name' => 'FWL 6.19 test group renamed']);
        check('group rename only -> no log row', $c === 200 && count(logRows($conn, "group_id = $gid")) === 2, "HTTP $c");
    }
} finally {
    cleanup($conn);
    $d = $conn->prepare("DELETE FROM sessions WHERE session_id = ?");
    $d->bind_param('s', $sid); $d->execute(); $d->close();
    $left = count(testIds($conn));
    echo "cleanup: test bookings left $left, session removed\n";
}

echo $failures === 0 ? "ALL OK\n" : "$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
