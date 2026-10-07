<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only (step 6.16): never deployed
/**
 * Step 6.16 - a manual merge's departure time through the REAL endpoints, on synthetic rows only
 * (date 2030-02-15, external_id LIKE 'FWL-T616-%', fake product 999000016), with a temporary admin
 * session that is deleted at the end. STAGING ONLY: refuses unless the environment says staging.
 *
 *   FWL_API_DIR=<staging api> php tools/departure_time_http_check.php --host=https://stagingwithlocals.deetech.cc
 */
$apiDir = getenv('FWL_API_DIR') ?: __DIR__ . '/../public_html/api';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/cli';
require $apiDir . '/config.php';
require_once $apiDir . '/Middleware.php';

$host = null;
foreach (array_slice($argv, 1) as $a) { if (preg_match('/^--host=(.+)$/', $a, $m)) { $host = rtrim($m[1], '/'); } }
$env = class_exists('EnvLoader') ? (string) EnvLoader::get('APP_ENV', '') : (string) getenv('APP_ENV');
if (!$host || stripos($host, 'staging') === false || strcasecmp($env, 'staging') !== 0) {
    fwrite(STDERR, "refused: staging only (host=$host env=$env)\n");
    exit(2);
}

const T_DATE = '2030-02-15';
const T_PREFIX = 'FWL-T616-';
const T_PRODUCT = 999000016;
const T_TITLE = 'Uffizi Gallery Departure Time Test';
$failures = 0;
function check($label, $ok, $detail = '') {
    global $failures;
    if (!$ok) { $failures++; }
    printf("%s  %s%s\n", $ok ? 'ok  ' : 'FAIL', $label, $detail !== '' ? "  ($detail)" : '');
}
function cleanup($conn) {
    $conn->query("UPDATE tours SET group_id = NULL WHERE external_id LIKE '" . T_PREFIX . "%'");
    $conn->query("DELETE FROM tour_groups WHERE group_date = '" . T_DATE . "'");
    $conn->query("DELETE FROM tours WHERE external_id LIKE '" . T_PREFIX . "%'");
    $conn->query("DELETE FROM products WHERE bokun_product_id = " . T_PRODUCT);
}
function addTour($conn, $n, $time, $pax) {
    $ext = T_PREFIX . $n; $bid = 'T616' . $n; $prod = T_PRODUCT; $d = T_DATE; $title = T_TITLE;
    $s = $conn->prepare("INSERT INTO tours (external_id, bokun_booking_id, title, date, time, participants, product_id,
                                            is_private, cancelled, paid, language, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 0, 'English', NOW(), NOW())");
    $s->bind_param('sssssii', $ext, $bid, $title, $d, $time, $pax, $prod);
    $s->execute(); $id = (int) $conn->insert_id; $s->close();
    return $id;
}
function tourRow($conn, $id) { return $conn->query("SELECT group_id, time FROM tours WHERE id = " . (int) $id)->fetch_assoc(); }
function groupRow($conn, $gid) { return $conn->query("SELECT * FROM tour_groups WHERE id = " . (int) $gid)->fetch_assoc(); }
function groupCount($conn) { return (int) $conn->query("SELECT COUNT(*) n FROM tour_groups WHERE group_date = '" . T_DATE . "'")->fetch_assoc()['n']; }

$raw = bin2hex(random_bytes(32));
$hash = Middleware::hashToken($raw);
$sid = Middleware::SESSION_ID_PREFIX . $hash;
$admin = (int) $conn->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetch_assoc()['id'];
$s = $conn->prepare("INSERT INTO sessions (session_id, token, user_id, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))");
$s->bind_param('ssi', $sid, $hash, $admin); $s->execute(); $s->close();

function api($method, $path, $body = null, $raw_out = false) {
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
    return [$code, $raw_out ? $out : json_decode((string) $out, true)];
}
function unitTime(array $rows, $unit, $unitKey = 'unit', $timeKey = 'time') {
    foreach ($rows as $r) { if (($r[$unitKey] ?? null) === $unit) { return substr((string) $r[$timeKey], 0, 5); } }
    return null;
}

cleanup($conn);
$conn->query("INSERT IGNORE INTO products (bokun_product_id, title, product_type) VALUES (" . T_PRODUCT . ", 'FWL 6.16 test product', 'tour')");
try {
    // The 7 Oct case: the 14:30 booking is created first, so it has the lower id.
    $late = addTour($conn, 'late', '14:30:00', 2);
    $early = addTour($conn, 'early', '12:30:00', 4);

    // --- validation ------------------------------------------------------------------------------
    list($cBad) = api('POST', 'tour-groups.php?action=manual-merge', ['tour_ids' => [$late, $early], 'departure_time' => '25:00']);
    check('a bad departure_time is refused (400) and nothing is merged', $cBad === 400 && groupCount($conn) === 0, "$cBad groups=" . groupCount($conn));

    // --- old client (no departure_time): exactly as before ---------------------------------------
    list($cOld, $jOld) = api('POST', 'tour-groups.php?action=manual-merge', ['tour_ids' => [$early, $late]]);
    $gOld = (int) ($jOld['group']['id'] ?? 0);
    $rOld = groupRow($conn, $gOld);
    check('merge without departure_time: 200, departure_time NULL', $cOld === 200 && $rOld && $rOld['departure_time'] === null, "$cOld " . json_encode($rOld['departure_time'] ?? 'missing'));
    check('... group_time is the lowest-id booking\'s time, 14:30 (the old rule, unchanged)', ($rOld['group_time'] ?? null) === '14:30:00', $rOld['group_time'] ?? '');
    list($cD0) = api('DELETE', "tour-groups.php/$gOld");
    check('... dissolve 200', $cD0 === 200 && groupCount($conn) === 0);

    // --- the fix: merge the 14:30 booking into the 12:30 departure, at 12:30 ----------------------
    list($cM, $jM) = api('POST', 'tour-groups.php?action=manual-merge', ['tour_ids' => [$early, $late], 'departure_time' => '12:30']);
    $g = (int) ($jM['group']['id'] ?? 0);
    $row = groupRow($conn, $g);
    check('merge with departure_time 12:30: 200, stored 12:30:00', $cM === 200 && ($row['departure_time'] ?? null) === '12:30:00', "$cM " . json_encode($row['departure_time'] ?? null));
    check('... the response carries departure_time', substr((string) ($jM['group']['departure_time'] ?? ''), 0, 5) === '12:30');
    check('... bookings keep their own times', tourRow($conn, $early)['time'] === '12:30:00' && tourRow($conn, $late)['time'] === '14:30:00');

    list($cL, $jL) = api('GET', 'tour-groups.php?date=' . T_DATE);
    $listed = null;
    foreach ($jL['data'] ?? [] as $gr) { if ((int) $gr['id'] === $g) { $listed = $gr; } }
    check('Tours list (tour-groups.php GET) sends departure_time 12:30', $cL === 200 && $listed && substr((string) $listed['departure_time'], 0, 5) === '12:30', (string) $cL);

    list($cP, $jP) = api('GET', "participants.php?unit=g$g&format=json");
    $bk = [];
    foreach ($jP['data']['bookings'] ?? [] as $b) { $bk[] = $b['booked']; }
    sort($bk);
    check('participant sheet: time 12:30', $cP === 200 && ($jP['data']['time'] ?? null) === '12:30', "$cP " . ($jP['data']['time'] ?? ''));
    check('... only the 14:30 booking says "booked 14:30"', $bk === ['', '14:30'], json_encode($bk));
    list($cPdf, $pdf) = api('GET', "participants.php?unit=g$g", null, true);
    check('... the PDF renders (200, %PDF)', $cPdf === 200 && strncmp((string) $pdf, '%PDF', 4) === 0, $cPdf . ' ' . strlen((string) $pdf) . ' B');

    list($cR, $jR) = api('GET', 'radios.php?action=plan&date=' . T_DATE);
    $rDeps = $jR['data']['departures'] ?? $jR['departures'] ?? [];
    check('radios: the group is one departure at 12:30', $cR === 200 && unitTime($rDeps, "g$g") === '12:30', "$cR " . json_encode(unitTime($rDeps, "g$g")));

    list($cU, $jU) = api('GET', 'tours.php?action=unassigned-report&date=' . T_DATE);
    $uDeps = $jU['departures'] ?? $jU['data']['departures'] ?? [];
    check('unassigned report: 12:30', $cU === 200 && unitTime($uDeps, "g$g", 'tour_unit') === '12:30', "$cU " . json_encode(unitTime($uDeps, "g$g", 'tour_unit')));

    list($cN, $jN) = api('GET', 'pnl.php?date=' . T_DATE);
    $nRows = $jN['data']['rows'] ?? $jN['rows'] ?? $jN['data'] ?? [];
    check('P&L: the departure row says 12:30 (or the user is not the P&L owner)',
        $cN === 403 || ($cN === 200 && unitTime(is_array($nRows) ? $nRows : [], "g$g") === '12:30'), "$cN " . json_encode(unitTime(is_array($nRows) ? $nRows : [], "g$g")));

    // --- all members at the same time: nothing to choose, NULL ------------------------------------
    $same1 = addTour($conn, 'same1', '16:00:00', 2); $same2 = addTour($conn, 'same2', '16:00:00', 2);
    list($cS, $jS) = api('POST', 'tour-groups.php?action=manual-merge', ['tour_ids' => [$same1, $same2], 'departure_time' => '16:00']);
    $gS = (int) ($jS['group']['id'] ?? 0);
    check('same-time merge stores no departure_time (old behaviour)', $cS === 200 && groupRow($conn, $gS)['departure_time'] === null);

    // --- dissolve: the group (and its time) is gone, each booking back at its own time -----------
    list($cD) = api('DELETE', "tour-groups.php/$g");
    check('dissolve: 200, group row gone', $cD === 200 && groupRow($conn, $g) === null);
    check('... each booking ungrouped at its own time',
        tourRow($conn, $early)['group_id'] === null && tourRow($conn, $late)['group_id'] === null
        && tourRow($conn, $early)['time'] === '12:30:00' && tourRow($conn, $late)['time'] === '14:30:00');
    list($cU2, $jU2) = api('GET', 'tours.php?action=unassigned-report&date=' . T_DATE);
    $u2 = $jU2['departures'] ?? $jU2['data']['departures'] ?? [];
    check('... the report lists them as two departures, 12:30 and 14:30',
        unitTime($u2, "t$early", 'tour_unit') === '12:30' && unitTime($u2, "t$late", 'tour_unit') === '14:30',
        json_encode([unitTime($u2, "t$early", 'tour_unit'), unitTime($u2, "t$late", 'tour_unit')]));
} catch (Throwable $e) {
    $failures++;
    echo "FAIL  exception: " . $e->getMessage() . "\n";
}
cleanup($conn);
$d = $conn->prepare("DELETE FROM sessions WHERE session_id = ?");
$d->bind_param('s', $sid); $d->execute(); $d->close();

echo $failures === 0 ? "\nall checks passed\n" : "\n$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
