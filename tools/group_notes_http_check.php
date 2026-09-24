<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only (step 6.13): never deployed
/**
 * Step 6.13 - the group-note rules through the REAL endpoint (tour-groups.php), on synthetic rows
 * only (date 2030-02-14, external_id LIKE 'FWL-T613-%'), with a temporary admin session that is
 * deleted at the end. STAGING ONLY: refuses unless the environment says staging.
 *
 *   FWL_API_DIR=<staging api> php tools/group_notes_http_check.php --host=https://stagingwithlocals.deetech.cc
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

const T_DATE = '2030-02-14';
const T_PREFIX = 'FWL-T613-';
const T_PRODUCT = 999000002;
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
}
function addTour($conn, $n, $notes = null) {
    $ext = T_PREFIX . $n; $bid = 'T613' . $n; $prod = T_PRODUCT; $d = T_DATE; $t = '10:00:00';
    $title = 'Uffizi Gallery Note Test';
    $s = $conn->prepare("INSERT INTO tours (external_id, bokun_booking_id, title, date, time, participants, product_id,
                                            is_private, cancelled, paid, language, notes, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, 2, ?, 0, 0, 0, 'English', ?, NOW(), NOW())");
    $s->bind_param('sssssis', $ext, $bid, $title, $d, $t, $prod, $notes);
    $s->execute(); $id = (int) $conn->insert_id; $s->close();
    return $id;
}
function manualGroup($conn, array $tourIds, $note) {
    $d = T_DATE;
    $s = $conn->prepare("INSERT INTO tour_groups (group_date, group_time, display_name, total_pax, is_manual_merge, notes)
                         VALUES (?, '10:00:00', 'Note test', ?, 1, ?)");
    $pax = 2 * count($tourIds);
    $s->bind_param('sis', $d, $pax, $note);
    $s->execute(); $gid = (int) $conn->insert_id; $s->close();
    $conn->query("UPDATE tours SET group_id = $gid WHERE id IN (" . implode(',', array_map('intval', $tourIds)) . ")");
    return $gid;
}
function tourNote($conn, $id) { $r = $conn->query("SELECT notes FROM tours WHERE id = " . (int) $id)->fetch_assoc(); return $r ? $r['notes'] : false; }
function tourGroup($conn, $id) { $r = $conn->query("SELECT group_id FROM tours WHERE id = " . (int) $id)->fetch_assoc(); return $r && $r['group_id'] !== null ? (int) $r['group_id'] : null; }
function groupNote($conn, $gid) { $r = $conn->query("SELECT notes FROM tour_groups WHERE id = " . (int) $gid)->fetch_assoc(); return $r ? $r['notes'] : false; }

$raw = bin2hex(random_bytes(32));
$hash = Middleware::hashToken($raw);
$sid = Middleware::SESSION_ID_PREFIX . $hash;
$admin = (int) $conn->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetch_assoc()['id'];
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

cleanup($conn);
try {
    // --- add, edit, clear ------------------------------------------------------------------
    $a = addTour($conn, 'a'); $b = addTour($conn, 'b');
    $g = manualGroup($conn, [$a, $b], null);
    list($c1) = api('PUT', "tour-groups.php/$g", ['notes' => "  Meet at Loggia dei Lanzi 9:15\r\ncaf\xC3\xA9  "]);
    check('PUT adds a note (200, trimmed, LF)', $c1 === 200 && groupNote($conn, $g) === "Meet at Loggia dei Lanzi 9:15\ncaf\xC3\xA9", "$c1 " . var_export(groupNote($conn, $g), true));
    list($cg, $jg) = api('GET', "tour-groups.php/$g");
    check('GET returns it', $cg === 200 && (($jg['data']['notes'] ?? $jg['group']['notes'] ?? $jg['notes'] ?? null) === "Meet at Loggia dei Lanzi 9:15\ncaf\xC3\xA9"), (string) $cg);
    list($c2) = api('PUT', "tour-groups.php/$g", ['notes' => 'One guest uses a wheelchair']);
    check('PUT edits it', $c2 === 200 && groupNote($conn, $g) === 'One guest uses a wheelchair');
    list($c3) = api('PUT', "tour-groups.php/$g", ['display_name' => 'Note test']);
    check('a PUT without "notes" leaves it alone', $c3 === 200 && groupNote($conn, $g) === 'One guest uses a wheelchair');
    list($c4) = api('PUT', "tour-groups.php/$g", ['notes' => '   ']);
    check('PUT with empty text removes it (NULL)', $c4 === 200 && groupNote($conn, $g) === null, var_export(groupNote($conn, $g), true));
    list($c5) = api('PUT', "tour-groups.php/$g", ['notes' => str_repeat('x', 2001)]);
    check('a note over 2,000 characters is refused (400)', $c5 === 400 && groupNote($conn, $g) === null, (string) $c5);

    // --- dissolve: copied to each member, appended ------------------------------------------
    $d1 = addTour($conn, 'd1', 'Vegetarian'); $d2 = addTour($conn, 'd2');
    $gd = manualGroup($conn, [$d1, $d2], 'Meet at the Loggia 9:15');
    list($cd, $jd) = api('DELETE', "tour-groups.php/$gd");
    check('Dissolve Group: 200, group gone', $cd === 200 && groupNote($conn, $gd) === false && tourGroup($conn, $d1) === null);
    check('... the note is appended to a booking that had its own note',
        tourNote($conn, $d1) === "Vegetarian\n[Group note] Meet at the Loggia 9:15", var_export(tourNote($conn, $d1), true));
    check('... and set on a booking that had none', tourNote($conn, $d2) === '[Group note] Meet at the Loggia 9:15');
    check('... reported as notes_copied=2', (int) ($jd['notes_copied'] ?? -1) === 2, json_encode($jd));
    $d3 = addTour($conn, 'd3'); $d4 = addTour($conn, 'd4');
    $gd2 = manualGroup($conn, [$d1, $d3, $d4], 'Meet at the Loggia 9:15');
    api('DELETE', "tour-groups.php/$gd2");
    check('... the same note is never added twice', substr_count((string) tourNote($conn, $d1), '[Group note] Meet at the Loggia 9:15') === 1);

    // --- unmerge ------------------------------------------------------------------------------
    $u1 = addTour($conn, 'u1'); $u2 = addTour($conn, 'u2'); $u3 = addTour($conn, 'u3', 'Birthday');
    $gu = manualGroup($conn, [$u1, $u2, $u3], 'Wheelchair user');
    list($cu) = api('POST', 'tour-groups.php?action=unmerge', ['tour_id' => $u3]);
    check('Unmerge: the group keeps its note', $cu === 200 && groupNote($conn, $gu) === 'Wheelchair user' && tourGroup($conn, $u1) === $gu);
    check('... the unmerged booking gets a copy appended', tourNote($conn, $u3) === "Birthday\n[Group note] Wheelchair user");
    check('... the bookings still in the group are untouched', tourNote($conn, $u1) === null && tourNote($conn, $u2) === null);
    list($cu2) = api('POST', 'tour-groups.php?action=unmerge', ['tour_id' => $u2]);
    check('Unmerge that leaves one booking: both get the note, group gone',
        $cu2 === 200 && groupNote($conn, $gu) === false && tourNote($conn, $u2) === '[Group note] Wheelchair user' && tourNote($conn, $u1) === '[Group note] Wheelchair user');

    // --- merge two noted groups ----------------------------------------------------------------
    $m1 = addTour($conn, 'm1'); $m2 = addTour($conn, 'm2'); $m3 = addTour($conn, 'm3'); $m4 = addTour($conn, 'm4');
    $gm1 = manualGroup($conn, [$m1, $m2], 'Meet at the Loggia 9:15');
    $gm2 = manualGroup($conn, [$m3, $m4], 'One guest uses a wheelchair');
    list($cm, $jm) = api('POST', 'tour-groups.php?action=manual-merge', ['tour_ids' => [$m1, $m2, $m3, $m4]]);
    $gNew = tourGroup($conn, $m1);
    check('Merging two noted groups keeps both texts, one per line',
        $cm === 200 && $gNew && groupNote($conn, $gNew) === "Meet at the Loggia 9:15\nOne guest uses a wheelchair", var_export($gNew ? groupNote($conn, $gNew) : null, true));
    check('... the old groups are gone and the moved bookings get no extra copy',
        groupNote($conn, $gm1) === false && groupNote($conn, $gm2) === false
        && tourNote($conn, $m1) === null && tourNote($conn, $m2) === null && tourNote($conn, $m3) === null && tourNote($conn, $m4) === null);

    // --- a booking dragged out of a noted group into a new one ---------------------------------
    $p1 = addTour($conn, 'p1'); $p2 = addTour($conn, 'p2'); $p3 = addTour($conn, 'p3'); $p4 = addTour($conn, 'p4');
    $gp = manualGroup($conn, [$p1, $p2, $p3], 'Audio headsets at the desk');
    list($cp) = api('POST', 'tour-groups.php?action=manual-merge', ['tour_ids' => [$p3, $p4]]);
    $gpNew = tourGroup($conn, $p3);
    check('Moving one booking out: the old group keeps its note, the new one carries it too',
        $cp === 200 && groupNote($conn, $gp) === 'Audio headsets at the desk' && $gpNew && groupNote($conn, $gpNew) === 'Audio headsets at the desk');

    // --- a single booking's own note path is unchanged --------------------------------------------
    $sgl = addTour($conn, 'single', 'Own note');
    list($cs) = api('PUT', "tours.php/$sgl", ['notes' => 'Own note, edited']);
    check('single-booking notes still save through tours.php as before', $cs === 200 && tourNote($conn, $sgl) === 'Own note, edited', (string) $cs);
} catch (Throwable $e) {
    $failures++;
    echo "FAIL  exception: " . $e->getMessage() . "\n";
}

cleanup($conn);
$d = $conn->prepare("DELETE FROM sessions WHERE token = ?");
$d->bind_param('s', $hash); $d->execute(); $d->close();
$left = (int) $conn->query("SELECT COUNT(*) n FROM tours WHERE external_id LIKE '" . T_PREFIX . "%'")->fetch_assoc()['n'];
echo "\ncleanup: " . ($left === 0 ? "synthetic rows and the temporary session removed\n" : "WARNING $left rows left\n");
echo $failures === 0 ? "\nall checks passed\n" : "\n$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
