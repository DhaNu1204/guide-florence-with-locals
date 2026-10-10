<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only (step 6.17): never deployed
/**
 * Step 6.17 - "Counts as" (tour_groups.billing_product_id) through the REAL endpoints, on synthetic
 * bookings only (date 2030-02-16, external_id LIKE 'FWL-T617-%') of the real Combo (962885) and
 * Uffizi Vasari (1130528) / Uffizi small group (961801) products, with temporary sessions for the
 * P&L owner, a second admin and a viewer, all deleted at the end. STAGING ONLY.
 *
 *   FWL_API_DIR=<staging api> php tools/billing_product_http_check.php --host=https://stagingwithlocals.deetech.cc
 */
$apiDir = getenv('FWL_API_DIR') ?: __DIR__ . '/../public_html/api';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/cli';
require $apiDir . '/config.php';
require_once $apiDir . '/Middleware.php';
require_once $apiDir . '/lib/billing_product.php';
require_once $apiDir . '/guide_digest.php';
if (!defined('BOKUN_SYNC_LIB')) { define('BOKUN_SYNC_LIB', true); }
require_once $apiDir . '/bokun_sync.php';

$host = null;
foreach (array_slice($argv, 1) as $a) { if (preg_match('/^--host=(.+)$/', $a, $m)) { $host = rtrim($m[1], '/'); } }
$env = class_exists('EnvLoader') ? (string) EnvLoader::get('APP_ENV', '') : (string) getenv('APP_ENV');
if (!$host || stripos($host, 'staging') === false || strcasecmp($env, 'staging') !== 0) {
    fwrite(STDERR, "refused: staging only (host=$host env=$env)\n");
    exit(2);
}

const T_DATE = '2030-02-16';
const T_PREFIX = 'FWL-T617-';
const P_COMBO = 962885;
const P_UFFIZI = 1130528;
const P_UFFIZI2 = 961801;
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
function productTitle($conn, $pid) {
    $r = $conn->query("SELECT title FROM tours WHERE product_id = " . (int) $pid . " AND title <> '' ORDER BY id DESC LIMIT 1")->fetch_assoc();
    return $r ? $r['title'] : null;
}
function addTour($conn, $n, $pid, $title, $pax) {
    $ext = T_PREFIX . $n; $bid = 'T617' . $n; $d = T_DATE; $time = '14:15:00';
    $s = $conn->prepare("INSERT INTO tours (external_id, bokun_booking_id, title, date, time, participants, product_id,
                                            is_private, cancelled, paid, language, booking_channel, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 0, 'English', 'GetYourGuide', NOW(), NOW())");
    $s->bind_param('sssssii', $ext, $bid, $title, $d, $time, $pax, $pid);
    $s->execute(); $id = (int) $conn->insert_id; $s->close();
    return $id;
}
function groupRow($conn, $gid) { return $conn->query("SELECT * FROM tour_groups WHERE id = " . (int) $gid)->fetch_assoc(); }
function session($conn, $userId) {
    $raw = bin2hex(random_bytes(32));
    $hash = Middleware::hashToken($raw);
    $sid = Middleware::SESSION_ID_PREFIX . $hash;
    $s = $conn->prepare("INSERT INTO sessions (session_id, token, user_id, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))");
    $s->bind_param('ssi', $sid, $hash, $userId); $s->execute(); $s->close();
    return [$raw, $sid];
}
function api($token, $method, $path, $body = null) {
    global $host;
    usleep(1300000); // the host drops bursts faster than ~1 request/second
    $ctx = stream_context_create(['http' => [
        'method' => $method,
        'header' => "Authorization: Bearer $token\r\nContent-Type: application/json\r\n",
        'content' => $body === null ? '' : json_encode($body),
        'timeout' => 40, 'ignore_errors' => true,
    ]]);
    $out = @file_get_contents($host . '/api/' . $path, false, $ctx);
    $code = 0;
    foreach ($http_response_header ?? [] as $h) { if (preg_match('#^HTTP/\S+ (\d{3})#', $h, $m)) { $code = (int) $m[1]; } }
    return [$code, json_decode((string) $out, true)];
}
function pnlRow($token, $unit) {
    list($c, $j) = api($token, 'GET', 'pnl.php?date=' . T_DATE);
    foreach ($j['data']['rows'] ?? [] as $r) { if (($r['unit'] ?? null) === $unit) { return [$c, $r]; } }
    return [$c, null];
}
function reportRow($token, $guideId, $gid) {
    list($c, $j) = api($token, 'GET', "guide-tour-report.php?guide_id=$guideId&start=" . T_DATE . '&end=' . T_DATE);
    foreach ($j['data']['tours'] ?? [] as $r) { if ((int) ($r['group_id'] ?? 0) === $gid) { return [$c, $r]; } }
    return [$c, null];
}
function digestTitle($conn, $gid) {
    foreach (collectDigestDepartures($conn, T_DATE) as $guideRow) {
        foreach ($guideRow['departures'] as $d) { if (($d['unit'] ?? null) === "g$gid") { return $d['title']; } }
    }
    return null;
}

// Users: the P&L owner, another admin, a viewer.
$owner = $other = $viewer = null;
$res = $conn->query("SELECT id, username, email, role FROM users ORDER BY id");
while ($u = $res->fetch_assoc()) {
    if ($u['role'] === 'admin' && Middleware::isPnlOwner($u)) { $owner = $owner ?? (int) $u['id']; }
    elseif ($u['role'] === 'admin') { $other = $other ?? (int) $u['id']; }
    elseif ($u['role'] === 'viewer') { $viewer = $viewer ?? (int) $u['id']; }
}
if (!$owner || !$other || !$viewer) { fwrite(STDERR, "need an owner, a second admin and a viewer\n"); exit(2); }
list($tOwner, $sOwner) = session($conn, $owner);
list($tOther, $sOther) = session($conn, $other);
list($tViewer, $sViewer) = session($conn, $viewer);

$settings = pnlLoadSettings($conn);
$rateCombo = (float) $settings['guide_rate_combo'];
$rateUffizi = (float) $settings['guide_rate_uffizi'];
$titleCombo = productTitle($conn, P_COMBO);
$titleUffizi = productTitle($conn, P_UFFIZI);
$titleUffizi2 = productTitle($conn, P_UFFIZI2);
$guide = (int) $conn->query("SELECT id FROM guides WHERE active = 1 ORDER BY id LIMIT 1")->fetch_assoc()['id'];

cleanup($conn);
try {
    // The 10 Oct case: the Uffizi booking is older (lower id) than the Combo one.
    $uff = addTour($conn, 'uff', P_UFFIZI, $titleUffizi, 2);
    $combo = addTour($conn, 'combo', P_COMBO, $titleCombo, 3);

    // Singles first: each booking's own P&L (the reference for tickets and rates).
    list(, $pU) = pnlRow($tOwner, "t$uff");
    list(, $pC) = pnlRow($tOwner, "t$combo");
    $ticketSingles = round($pU['costs']['ticket_cost'] + $pC['costs']['ticket_cost'], 2);
    check('singles: Uffizi booking = Uffizi rate, Combo booking = Combo rate',
        $pU && $pC && $pU['costs']['guide_cost'] == $rateUffizi && $pC['costs']['guide_cost'] == $rateCombo,
        sprintf('%.2f / %.2f, tickets %.2f + %.2f', $pU['costs']['guide_cost'] ?? -1, $pC['costs']['guide_cost'] ?? -1, $pU['costs']['ticket_cost'] ?? -1, $pC['costs']['ticket_cost'] ?? -1));

    // --- merge combo 3 PAX + Uffizi 2 PAX ----------------------------------------------------------
    list($cM, $jM) = api($tOwner, 'POST', 'tour-groups.php?action=manual-merge', ['tour_ids' => [$uff, $combo]]);
    $g = (int) ($jM['group']['id'] ?? 0);
    $row = groupRow($conn, $g);
    check('merge: 200, billing_product_id = Combo', $cM === 200 && (int) ($row['billing_product_id'] ?? 0) === P_COMBO, "$cM " . json_encode($row['billing_product_id'] ?? null));
    check('... group title = the Combo title (not the lower-id Uffizi one)', ($row['display_name'] ?? '') === $titleCombo, $row['display_name'] ?? '');
    api($tOwner, 'PUT', "tour-groups.php/$g", ['guide_id' => $guide]);

    list($cL, $jL) = api($tOwner, 'GET', 'tour-groups.php?date=' . T_DATE);
    $listed = null;
    foreach ($jL['data'] ?? [] as $gr) { if ((int) $gr['id'] === $g) { $listed = $gr; } }
    $pids = array_map(function ($t) { return $t['product_id']; }, $listed['tours'] ?? []);
    check('Tours list: title Combo, billing_product_id Combo, members carry product_id',
        $listed && $listed['display_name'] === $titleCombo && $listed['billing_product_id'] === P_COMBO && in_array(P_UFFIZI, $pids, true), json_encode($pids));

    list($cN, $pG) = pnlRow($tOwner, "g$g");
    check('P&L: category Combo, guide cost = Combo rate', $cN === 200 && $pG && $pG['category'] === 'Combo' && $pG['costs']['guide_cost'] == $rateCombo,
        sprintf('%s %.2f', $pG['category'] ?? '?', $pG['costs']['guide_cost'] ?? -1));
    check('P&L: ticket cost = 3 x Combo + 2 x Uffizi (sum of the two bookings)', $pG && round($pG['costs']['ticket_cost'], 2) === $ticketSingles,
        sprintf('%.2f vs %.2f', $pG['costs']['ticket_cost'] ?? -1, $ticketSingles));
    list($cP, $jP) = api($tOwner, 'GET', "participants.php?unit=g$g&format=json");
    check('participant sheet: Combo title', $cP === 200 && ($jP['data']['product'] ?? null) === $titleCombo, $jP['data']['product'] ?? (string) $cP);
    check('digest: Combo title', digestTitle($conn, $g) === $titleCombo, (string) digestTitle($conn, $g));
    list($cR, $rG) = reportRow($tOwner, $guide, $g);
    check('Guide Tour Report: type Combo, Combo title', $cR === 200 && $rG && $rG['category'] === 'Combo' && $rG['title'] === $titleCombo, json_encode([$rG['category'] ?? null, $rG['title'] ?? null]));

    // --- who may change it ------------------------------------------------------------------------
    list($cV) = api($tViewer, 'POST', 'tour-groups.php?action=billing-product', ['group_id' => $g, 'product_id' => P_UFFIZI]);
    check('viewer: 403', $cV === 403, (string) $cV);
    list($cO) = api($tOther, 'POST', 'tour-groups.php?action=billing-product', ['group_id' => $g, 'product_id' => P_UFFIZI]);
    check('second admin (not the P&L owner): 403', $cO === 403, (string) $cO);
    check('... nothing changed', (int) groupRow($conn, $g)['billing_product_id'] === P_COMBO);
    list($cX) = api($tOwner, 'POST', 'tour-groups.php?action=billing-product', ['group_id' => $g, 'product_id' => P_UFFIZI2]);
    check('owner, a product that is not in the group: 400', $cX === 400, (string) $cX);

    // --- switch to Uffizi ---------------------------------------------------------------------------
    list($cS) = api($tOwner, 'POST', 'tour-groups.php?action=billing-product', ['group_id' => $g, 'product_id' => P_UFFIZI]);
    $row = groupRow($conn, $g);
    check('owner switches to Uffizi: 200, stored, title Uffizi', $cS === 200 && (int) $row['billing_product_id'] === P_UFFIZI && $row['display_name'] === $titleUffizi, "$cS " . $row['display_name']);
    list(, $pG2) = pnlRow($tOwner, "g$g");
    check('P&L: category Uffizi, guide cost = Uffizi rate, tickets unchanged',
        $pG2 && $pG2['category'] === 'Uffizi' && $pG2['costs']['guide_cost'] == $rateUffizi && round($pG2['costs']['ticket_cost'], 2) === $ticketSingles,
        sprintf('%s %.2f %.2f', $pG2['category'] ?? '?', $pG2['costs']['guide_cost'] ?? -1, $pG2['costs']['ticket_cost'] ?? -1));
    check('digest: Uffizi title', digestTitle($conn, $g) === $titleUffizi);
    list(, $rG2) = reportRow($tOwner, $guide, $g);
    check('Guide Tour Report: type Uffizi', $rG2 && $rG2['category'] === 'Uffizi' && $rG2['title'] === $titleUffizi, json_encode([$rG2['category'] ?? null, $rG2['title'] ?? null]));

    // --- the sync's grouping pass leaves it alone -------------------------------------------------
    $before = groupRow($conn, $g);
    autoGroupAfterSync($conn, T_DATE, T_DATE);
    $after = groupRow($conn, $g);
    check('autoGroupAfterSync: billing_product_id, title and updated_at unchanged',
        $after && $after['billing_product_id'] === $before['billing_product_id'] && $after['display_name'] === $before['display_name'] && $after['updated_at'] === $before['updated_at']);

    // --- dissolve: each booking back to its own product and rate --------------------------------
    list($cD) = api($tOwner, 'DELETE', "tour-groups.php/$g");
    list(, $pU2) = pnlRow($tOwner, "t$uff");
    list(, $pC2) = pnlRow($tOwner, "t$combo");
    check('dissolve: 200, group gone', $cD === 200 && groupRow($conn, $g) === null);
    check('... Uffizi booking: Uffizi rate, Combo booking: Combo rate',
        $pU2 && $pC2 && $pU2['category'] === 'Uffizi' && $pU2['costs']['guide_cost'] == $rateUffizi && $pC2['category'] === 'Combo' && $pC2['costs']['guide_cost'] == $rateCombo);

    // --- same-type merge (two Uffizi products): no billing product, old title rule --------------
    $u2 = addTour($conn, 'uff2', P_UFFIZI2, $titleUffizi2, 2);
    list($cT, $jT) = api($tOwner, 'POST', 'tour-groups.php?action=manual-merge', ['tour_ids' => [$u2, $uff]]);
    $gT = (int) ($jT['group']['id'] ?? 0);
    $rowT = groupRow($conn, $gT);
    check('two Uffizi products: billing_product_id NULL, title = the lower-id booking (unchanged rule)',
        $cT === 200 && $rowT && $rowT['billing_product_id'] === null && $rowT['display_name'] === $titleUffizi, json_encode([$rowT['billing_product_id'] ?? 'x', $rowT['display_name'] ?? '']));
    list($cT2) = api($tOwner, 'POST', 'tour-groups.php?action=billing-product', ['group_id' => $gT, 'product_id' => P_UFFIZI2]);
    check('... "Counts as" refused there (400)', $cT2 === 400, (string) $cT2);
} catch (Throwable $e) {
    $failures++;
    echo "FAIL  exception: " . $e->getMessage() . "\n";
}
cleanup($conn);
foreach ([$sOwner, $sOther, $sViewer] as $sid) {
    $d = $conn->prepare("DELETE FROM sessions WHERE session_id = ?");
    $d->bind_param('s', $sid); $d->execute(); $d->close();
}
echo $failures === 0 ? "\nall checks passed\n" : "\n$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
