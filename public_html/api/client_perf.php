<?php
/**
 * client_perf.php - step 4.7: field instrumentation for the mobile investigation.
 *
 * MEASUREMENT ONLY. This endpoint records what a real phone experienced while loading the
 * app, one row per page load, and reads those rows back for an admin. It changes nothing
 * about how the app behaves.
 *
 *   POST /api/client_perf.php            - write one row (any logged-in user)
 *   GET  /api/client_perf.php?action=list - read rows (admin only)
 *
 * Why the token is in the POST body: the browser sends this with navigator.sendBeacon, which
 * cannot carry an Authorization header. It is the same bearer token over the same TLS to the
 * same origin; it is looked up with Middleware::findSessionUser(), which is a plain SELECT -
 * it does NOT create a session and does NOT extend one - and the body is never logged.
 *
 * Privacy: the only thing stored that relates to a person is `user_id`. No tour data, no
 * customer data, no names, no URLs beyond a route name with its ids stripped, no free text.
 */

require_once 'config.php';
require_once 'Middleware.php';

autoRateLimit('client_perf');

function clientPerfEnsureTable($conn) {
    $conn->query("
        CREATE TABLE IF NOT EXISTS `client_perf` (
          `id`                  INT(11) NOT NULL AUTO_INCREMENT,
          `created_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `user_id`             INT(11) NOT NULL,
          `release_tag`         VARCHAR(32) NULL DEFAULT NULL,
          `route`               VARCHAR(40) NULL DEFAULT NULL,
          `reason`              VARCHAR(12) NOT NULL DEFAULT 'complete',
          `entry_at`            INT(11) NULL DEFAULT NULL,
          `verify_start`        INT(11) NULL DEFAULT NULL,
          `verify_end`          INT(11) NULL DEFAULT NULL,
          `verify_status`       VARCHAR(8) NOT NULL DEFAULT 'none',
          `chunk_start`         INT(11) NULL DEFAULT NULL,
          `chunk_end`           INT(11) NULL DEFAULT NULL,
          `chunk_status`        VARCHAR(8) NOT NULL DEFAULT 'none',
          `list_start`          INT(11) NULL DEFAULT NULL,
          `list_end`            INT(11) NULL DEFAULT NULL,
          `list_status`         VARCHAR(8) NOT NULL DEFAULT 'none',
          `rate_limited`        SMALLINT(6) NOT NULL DEFAULT 0,
          `online`              TINYINT(1) NOT NULL DEFAULT 1,
          `sw_controlled`       TINYINT(1) NOT NULL DEFAULT 0,
          `first_after_release` TINYINT(1) NOT NULL DEFAULT 0,
          `effective_type`      VARCHAR(12) NULL DEFAULT NULL,
          `conn_rtt`            INT(11) NULL DEFAULT NULL,
          `conn_downlink`       DECIMAL(6,2) NULL DEFAULT NULL,
          `device`              VARCHAR(40) NULL DEFAULT NULL,
          PRIMARY KEY (`id`),
          KEY `idx_client_perf_created_at` (`created_at`),
          KEY `idx_client_perf_user` (`user_id`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/**
 * Step 4.8: the columns that say how a bad load resolved (see
 * database/migrations/20260923_client_perf_step48.sql). Added in place on the first write after
 * the deploy; one cheap information_schema lookup per beacon (~100 a day) after that.
 */
function clientPerfEnsureColumns($conn) {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) n FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'client_perf' AND COLUMN_NAME = 'shell_fallback'");
    $stmt->execute();
    $has = (int) ($stmt->get_result()->fetch_assoc()['n'] ?? 0) > 0;
    $stmt->close();
    if ($has) { return; }
    $conn->query("
        ALTER TABLE `client_perf`
          ADD COLUMN `verify_error`   VARCHAR(16) NULL DEFAULT NULL AFTER `verify_status`,
          ADD COLUMN `verify_retry`   VARCHAR(8)  NOT NULL DEFAULT 'none' AFTER `verify_error`,
          ADD COLUMN `timeouts`       SMALLINT(6) NOT NULL DEFAULT 0 AFTER `rate_limited`,
          ADD COLUMN `auto_retries`   SMALLINT(6) NOT NULL DEFAULT 0 AFTER `timeouts`,
          ADD COLUMN `auto_retry_ok`  SMALLINT(6) NOT NULL DEFAULT 0 AFTER `auto_retries`,
          ADD COLUMN `user_retries`   SMALLINT(6) NOT NULL DEFAULT 0 AFTER `auto_retry_ok`,
          ADD COLUMN `shell_fallback` TINYINT(1)  NOT NULL DEFAULT 0 AFTER `sw_controlled`
    ");
}

/**
 * Step 4.10: which app sent the row and what stalled (see
 * database/migrations/20260930_client_perf_step410.sql). Same in-place pattern as 4.8.
 *   display_mode  'standalone' (home-screen app) | 'browser' (a tab) - the 2026-09-30 question
 *   install_id    random id per app install / browser profile (not a person, not a device id)
 *   build         entry-script hash the load ran
 *   stuck         endpoint file names whose timer fired, e.g. "tours.php,guide-payments.php"
 *   probe_status / probe_ms  the reachability check after a failed load
 *   load_id       client id of the load, UNIQUE: a row re-sent later is stored once
 *   sent_late     1 = delivered by a later load (the beacon of the failed load never arrived)
 */
function clientPerfEnsureColumns410($conn) {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) n FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'client_perf' AND COLUMN_NAME = 'load_id'");
    $stmt->execute();
    $has = (int) ($stmt->get_result()->fetch_assoc()['n'] ?? 0) > 0;
    $stmt->close();
    if ($has) { return; }
    $conn->query("
        ALTER TABLE `client_perf`
          ADD COLUMN `display_mode` VARCHAR(12) NULL DEFAULT NULL AFTER `device`,
          ADD COLUMN `install_id`   VARCHAR(16) NULL DEFAULT NULL AFTER `display_mode`,
          ADD COLUMN `build`        VARCHAR(24) NULL DEFAULT NULL AFTER `install_id`,
          ADD COLUMN `stuck`        VARCHAR(120) NULL DEFAULT NULL AFTER `build`,
          ADD COLUMN `probe_status` VARCHAR(8)  NULL DEFAULT NULL AFTER `stuck`,
          ADD COLUMN `probe_ms`     INT(11)     NULL DEFAULT NULL AFTER `probe_status`,
          ADD COLUMN `load_id`      VARCHAR(20) NULL DEFAULT NULL AFTER `probe_ms`,
          ADD COLUMN `sent_late`    TINYINT(1)  NOT NULL DEFAULT 0 AFTER `load_id`,
          ADD UNIQUE KEY `uniq_client_perf_load_id` (`load_id`)
    ");
}

/** Retention: 60 days, applied on roughly one insert in fifty so it costs nothing per request. */
function clientPerfPrune($conn) {
    if (random_int(1, 50) !== 1) { return; }
    $conn->query("DELETE FROM client_perf WHERE created_at < NOW() - INTERVAL 60 DAY");
}

function perfInt($v, $min = 0, $max = 3600000) {
    if ($v === null || $v === '' || !is_numeric($v)) { return null; }
    $n = (int) $v;
    if ($n < $min || $n > $max) { return null; }
    return $n;
}

function perfStatus($v) {
    $allowed = ['ok', 'failed', 'pending', 'none'];
    return in_array($v, $allowed, true) ? $v : 'none';
}

function perfStr($v, $len) {
    if (!is_string($v) || trim($v) === '') { return null; }
    // Deliberately narrow: letters, digits and a handful of separators. No free text can survive.
    $clean = preg_replace('/[^A-Za-z0-9 ._:\/@-]/', '', $v);
    return substr($clean, 0, $len);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ---------------------------------------------------------------------------------------
// POST: one row per page load.
// ---------------------------------------------------------------------------------------
/** One row. Step 4.10: moved out of the POST branch so a batch of late rows can reuse it. */
function clientPerfInsert($conn, $userId, array $in, $late) {
    $release = perfStr($in['release'] ?? null, 32);
    $route   = perfStr($in['route'] ?? null, 40);
    $reason  = in_array($in['reason'] ?? '', ['complete', 'deadline', 'hidden', 'pagehide'], true)
        ? $in['reason'] : 'complete';
    $entryAt = perfInt($in['entry_at'] ?? null);
    $vs = perfInt($in['verify_start'] ?? null); $ve = perfInt($in['verify_end'] ?? null);
    $cs = perfInt($in['chunk_start'] ?? null);  $ce = perfInt($in['chunk_end'] ?? null);
    $ls = perfInt($in['list_start'] ?? null);   $le = perfInt($in['list_end'] ?? null);
    $vst = perfStatus($in['verify_status'] ?? 'none');
    $cst = perfStatus($in['chunk_status'] ?? 'none');
    $lst = perfStatus($in['list_status'] ?? 'none');
    $rl  = max(0, min(9999, (int) ($in['rate_limited'] ?? 0)));
    $online = !empty($in['online']) ? 1 : 0;
    $sw = !empty($in['sw_controlled']) ? 1 : 0;
    $far = !empty($in['first_after_release']) ? 1 : 0;
    $eff = perfStr($in['effective_type'] ?? null, 12);
    $rtt = perfInt($in['conn_rtt'] ?? null, 0, 600000);
    $dl  = (isset($in['conn_downlink']) && is_numeric($in['conn_downlink']))
        ? round((float) $in['conn_downlink'], 2) : null;
    $dev = perfStr($in['device'] ?? null, 40);
    // Step 4.8: how the load resolved. Short fixed codes and counters only.
    $verr = perfStr($in['verify_error'] ?? null, 16);
    $vrt  = perfStatus($in['verify_retry'] ?? 'none');
    $tmo  = max(0, min(999, (int) ($in['timeouts'] ?? 0)));
    $arr  = max(0, min(999, (int) ($in['auto_retries'] ?? 0)));
    $aok  = max(0, min($arr, (int) ($in['auto_retry_ok'] ?? 0)));
    $urt  = max(0, min(999, (int) ($in['user_retries'] ?? 0)));
    $shell = !empty($in['shell_fallback']) ? 1 : 0;
    // Step 4.10
    $dmode = in_array($in['display_mode'] ?? '', ['standalone', 'browser'], true) ? $in['display_mode'] : null;
    $inst  = perfStr($in['install_id'] ?? null, 16);
    $build = perfStr($in['build'] ?? null, 24);
    $stuck = perfStr($in['stuck'] ?? null, 120);
    $pst   = in_array($in['probe_status'] ?? '', ['ok', 'timeout', 'network', 'http', 'offline'], true) ? $in['probe_status'] : null;
    $pms   = perfInt($in['probe_ms'] ?? null, 0, 600000);
    $loadId = perfStr($in['load_id'] ?? null, 20);
    $late  = $late ? 1 : 0;
    // A late row keeps the time of the load it describes (client clock, epoch seconds), if sane.
    $at = perfInt($in['at_s'] ?? null, 0, 4102444800);
    if (!$late || $at === null || $at < time() - 7 * 86400 || $at > time() + 300) { $at = null; }
    
    $stmt = $conn->prepare(
        "INSERT IGNORE INTO client_perf
            (created_at, user_id, release_tag, route, reason, entry_at,
             verify_start, verify_end, verify_status,
             chunk_start, chunk_end, chunk_status,
             list_start, list_end, list_status,
             rate_limited, online, sw_controlled, first_after_release,
             effective_type, conn_rtt, conn_downlink, device,
             verify_error, verify_retry, timeouts, auto_retries, auto_retry_ok, user_retries, shell_fallback,
             display_mode, install_id, build, stuck, probe_status, probe_ms, load_id, sent_late)
         VALUES (COALESCE(FROM_UNIXTIME(?), CURRENT_TIMESTAMP), ?,?,?,?,?, ?,?,?, ?,?,?, ?,?,?, ?,?,?,?, ?,?,?,?, ?,?,?,?,?,?,?, ?,?,?,?,?,?,?,?)");
    $stmt->bind_param(
        'i' . 'isssiiisiisiisiiiisidsssiiiii' . 'sssssisi',
        $at, $userId, $release, $route, $reason, $entryAt,
        $vs, $ve, $vst,
        $cs, $ce, $cst,
        $ls, $le, $lst,
        $rl, $online, $sw, $far,
        $eff, $rtt, $dl, $dev,
        $verr, $vrt, $tmo, $arr, $aok, $urt, $shell,
        $dmode, $inst, $build, $stuck, $pst, $pms, $loadId, $late
    );
    $stmt->execute();
    $stmt->close();
}

if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $in = json_decode($raw, true);
    if (!is_array($in)) {
        http_response_code(400);
        echo json_encode(['success' => false]);
        exit();
    }

    // Step 4.10: {items: [...]} = rows of earlier loads whose beacon never arrived, re-sent by a
    // later load in one request (at most 10). Each row carries the token it was recorded with.
    $late = isset($in['items']) && is_array($in['items']);
    $rows = $late ? array_slice(array_values(array_filter($in['items'], 'is_array')), 0, 10) : [$in];

    clientPerfEnsureTable($conn);
    clientPerfEnsureColumns($conn);
    clientPerfEnsureColumns410($conn);

    // Auth without touching the session (see the file header).
    $stored = 0;
    $users = [];
    foreach ($rows as $row) {
        $token = isset($row['token']) ? (string) $row['token'] : '';
        if (!array_key_exists($token, $users)) {
            $users[$token] = $token === '' ? null : Middleware::findSessionUser($conn, $token);
        }
        if (!$users[$token]) { continue; }
        clientPerfInsert($conn, (int) $users[$token]['id'], $row, $late);
        $stored++;
    }
    if (!$late && $stored === 0) {
        // Quietly: a beacon has no user to tell, and a noisy 401 here would only add log noise.
        http_response_code(401);
        echo json_encode(['success' => false]);
        exit();
    }

    clientPerfPrune($conn);

    // A late row whose session has ended is dropped on purpose: the client may forget it.
    echo json_encode(['success' => true, 'stored' => $stored]);
    exit();
}

// ---------------------------------------------------------------------------------------
// GET ?action=list - the reading end. Admin only.
// ---------------------------------------------------------------------------------------
Middleware::requireRole($conn, 'admin');
clientPerfEnsureTable($conn);
clientPerfEnsureColumns($conn);
clientPerfEnsureColumns410($conn);

$action = $_GET['action'] ?? 'list';
if ($action !== 'list') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit();
}

$date = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) ? $_GET['date'] : null;
$unfinishedOnly = !empty($_GET['unfinished']);
$limit = isset($_GET['limit']) ? max(1, min(500, (int) $_GET['limit'])) : 200;

$where = [];
$params = [];
$types = '';
if ($date !== null) {
    $where[] = 'DATE(created_at) = ?';
    $params[] = $date;
    $types .= 's';
}
if ($unfinishedOnly) {
    // "Did not finish" = something was still running when the beacon went out.
    $where[] = "(verify_status = 'pending' OR chunk_status = 'pending' OR list_status = 'pending')";
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "SELECT cp.*, u.username
          FROM client_perf cp
          LEFT JOIN users u ON u.id = cp.user_id
          $whereSql
         ORDER BY cp.id DESC
         LIMIT ?";
$params[] = $limit;
$types .= 'i';
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
$rows = [];
while ($r = $res->fetch_assoc()) {
    foreach (['id', 'user_id', 'entry_at', 'verify_start', 'verify_end', 'chunk_start',
              'chunk_end', 'list_start', 'list_end', 'rate_limited', 'online',
              'sw_controlled', 'first_after_release', 'conn_rtt',
              'timeouts', 'auto_retries', 'auto_retry_ok', 'user_retries', 'shell_fallback',
              'probe_ms', 'sent_late'] as $k) {
        $r[$k] = !isset($r[$k]) ? null : (int) $r[$k];
    }
    $r['conn_downlink'] = $r['conn_downlink'] === null ? null : (float) $r['conn_downlink'];
    $rows[] = $r;
}
$stmt->close();

// Summary for the day in view (today when no date is given).
$day = $date ?? date('Y-m-d');
$sum = $conn->prepare(
    "SELECT COUNT(*) loads,
            SUM(list_status = 'ok') list_ok,
            SUM(verify_status = 'pending' OR chunk_status = 'pending' OR list_status = 'pending') unfinished,
            MAX(CASE WHEN list_status = 'ok' THEN list_end END) worst_list
       FROM client_perf WHERE DATE(created_at) = ?");
$sum->bind_param('s', $day);
$sum->execute();
$summary = $sum->get_result()->fetch_assoc();
$sum->close();

// Median time to list, computed in PHP (MySQL 5.7 here has no percentile function).
$med = $conn->prepare("SELECT list_end FROM client_perf
                        WHERE DATE(created_at) = ? AND list_status = 'ok' AND list_end IS NOT NULL
                        ORDER BY list_end");
$med->bind_param('s', $day);
$med->execute();
$vals = [];
$mr = $med->get_result();
while ($x = $mr->fetch_assoc()) { $vals[] = (int) $x['list_end']; }
$med->close();
$median = null;
if (count($vals) > 0) {
    $n = count($vals);
    $median = ($n % 2) ? $vals[($n - 1) / 2] : (int) round(($vals[$n / 2 - 1] + $vals[$n / 2]) / 2);
}

echo json_encode([
    'success' => true,
    'data' => [
        'day' => $day,
        'rows' => $rows,
        'summary' => [
            'loads'           => (int) ($summary['loads'] ?? 0),
            'list_ok'         => (int) ($summary['list_ok'] ?? 0),
            'unfinished'      => (int) ($summary['unfinished'] ?? 0),
            'median_to_list'  => $median,
            'worst_to_list'   => $summary['worst_list'] === null ? null : (int) $summary['worst_list'],
        ],
    ],
]);
