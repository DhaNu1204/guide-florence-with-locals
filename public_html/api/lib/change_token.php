<?php
/**
 * Step 4.11: the auto-refresh change token (tours.php?action=changes&since=<token>).
 *
 * Open Tours / Dashboard / Today pages poll this once a minute. The token says whether anything
 * the owner can see for today .. today+7 (Europe/Rome) has changed:
 *   token = "<unix time of the check>.<10 hex chars of a hash>"
 * The hash covers what the screens show (time, PAX, guide, group, cancelled, language, paid,
 * notes ...) and deliberately NOT updated_at / last_sync / bokun_data: every sync rewrites
 * those on every row, so they would "change" every 5 minutes with nothing new to see.
 *
 * When the hash differs from the one in `since`, the answer also carries the new bookings
 * (tours.created_at) and cancellations (tours.cancelled_at, written by the sync) since the time in
 * `since`, so the app can say "New booking: 15:00 Uffizi Small Group, +1 PAX".
 *
 * Unchanged answer: success + data {token: <the same token>, changed: false} - about 75 bytes.
 */

const CHANGE_TOKEN_DAYS_AHEAD = 7;
const CHANGE_TOKEN_MAX_EVENTS = 5; // per kind

/** "<unix>.<10 hex>" -> ['t' => int, 'hash' => string], anything else -> null. Pure. */
function changeTokenParse($since) {
    if (!is_string($since) || !preg_match('/^(\d{9,11})\.([0-9a-f]{10})\z/', $since, $m)) {
        return null;
    }
    return ['t' => (int) $m[1], 'hash' => $m[2]];
}

/** Pure. */
function changeTokenMake($t, $hash) {
    return ((int) $t) . '.' . $hash;
}

/**
 * Step 4.11: tours.cancelled_at - when the sync saw a booking switch to cancelled (NULL again if
 * it is un-cancelled). Only the sync writes it. Also database/migrations/20261009_tours_cancelled_at.sql.
 */
function ensureCancelledAtColumn($conn) {
    $c = $conn->query("SHOW COLUMNS FROM tours LIKE 'cancelled_at'");
    if ($c && $c->num_rows === 0) {
        $conn->query("ALTER TABLE tours ADD COLUMN `cancelled_at` DATETIME NULL DEFAULT NULL");
    }
}

/** Hash of everything the screens show for $from..$to (tours + tour groups). */
function changeTokenHash($conn, $from, $to) {
    $stmt = $conn->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(CRC32(CONCAT_WS('|',
                id, date, time, participants, cancelled, guide_id, group_id, language, product_id,
                paid, payment_status, is_private, customer_name, notes))), 0) AS s
            FROM tours WHERE date BETWEEN ? AND ?");
    $stmt->bind_param("ss", $from, $to);
    $stmt->execute();
    $t = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(CRC32(CONCAT_WS('|',
                id, group_date, group_time, departure_time, guide_id, total_pax, display_name, notes,
                is_manual_merge))), 0) AS s
            FROM tour_groups WHERE group_date BETWEEN ? AND ?");
    $stmt->bind_param("ss", $from, $to);
    $stmt->execute();
    $g = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return substr(md5("{$t['n']}:{$t['s']}:{$g['n']}:{$g['s']}"), 0, 10);
}

/**
 * New bookings and cancellations at or after unix time $since (tickets excluded, like the Tours
 * page). ">=": times are whole seconds, so a write in the same second as the previous check must
 * still be reported; the app shows each event once.
 */
function changeTokenEvents($conn, $since, $from, $to) {
    $events = [];
    $base = "SELECT t.id, t.date, TIME_FORMAT(t.time, '%H:%i') AS time, t.title, t.participants
               FROM tours t LEFT JOIN products pr ON pr.bokun_product_id = t.product_id
              WHERE t.date BETWEEN ? AND ? AND (pr.product_type IS NULL OR pr.product_type <> 'ticket')";
    $kinds = [
        'new'    => "$base AND t.cancelled = 0 AND t.created_at >= FROM_UNIXTIME(?) ORDER BY t.created_at, t.id LIMIT " . CHANGE_TOKEN_MAX_EVENTS,
        'cancel' => "$base AND t.cancelled = 1 AND t.cancelled_at >= FROM_UNIXTIME(?) ORDER BY t.cancelled_at, t.id LIMIT " . CHANGE_TOKEN_MAX_EVENTS,
    ];
    foreach ($kinds as $kind => $sql) {
        try {
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssi", $from, $to, $since);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $events[] = [
                    'kind'  => $kind,
                    'id'    => (int) $r['id'],
                    'date'  => $r['date'],
                    'time'  => $r['time'],
                    'title' => (string) $r['title'],
                    'pax'   => (int) $r['participants'],
                ];
            }
            $stmt->close();
        } catch (Throwable $e) {
            // e.g. cancelled_at not provisioned yet (the next sync adds it): no events of that kind
            error_log("change_token: $kind events failed: " . $e->getMessage());
        }
    }
    return $events;
}

/** The whole answer. */
function changeTokenRespond($conn, $since) {
    $from = date('Y-m-d'); // Europe/Rome (config.php)
    $to = date('Y-m-d', strtotime('+' . CHANGE_TOKEN_DAYS_AHEAD . ' days'));
    try {
        // the check time comes first: anything written after it is reported again next time
        // (the app ignores an event it has already shown), never lost
        $now = (int) $conn->query("SELECT UNIX_TIMESTAMP() AS u")->fetch_assoc()['u'];
        $hash = changeTokenHash($conn, $from, $to);
    } catch (Throwable $e) {
        error_log("change_token: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Could not check for changes']);
        return;
    }
    $prev = changeTokenParse($since);
    if ($prev !== null && $prev['hash'] === $hash) {
        // unchanged: hand back the SAME token, so the next change reports everything since then
        echo json_encode(['success' => true, 'data' => ['token' => $since, 'changed' => false]]);
        return;
    }
    $data = ['token' => changeTokenMake($now, $hash), 'changed' => $prev !== null];
    if ($prev !== null) {
        $data['events'] = changeTokenEvents($conn, $prev['t'], $from, $to);
    }
    echo json_encode(['success' => true, 'data' => $data]);
}
