<?php
/**
 * today.php - step 4.6: the /today page in ONE small request (target < 20 KB raw).
 *
 *   GET /api/today.php  ->  today's and tomorrow's departures (Europe/Rome), one row per
 *                           departure (group or single tour), time-sorted:
 *                           time, title, languages, guests, guide (null = "No guide"), meeting point
 *
 * Read-only. Same departure rule as the reports (lib/departure_queries.php fwlDepartureUnits):
 * cancelled bookings and ticket products left out, the effective guide is the group's guide,
 * else a member tour's. Any logged-in user may read it (viewers included, like the Tours page).
 */

require_once 'config.php';
require_once 'Middleware.php';
require_once __DIR__ . '/lib/departure_queries.php';
require_once __DIR__ . '/lib/guide_product_fields.php';

Middleware::requireAuth($conn);
autoRateLimit('today');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    // PHP runs in Europe/Rome (the DB session is UTC), so "today" is the Florence day.
    $today = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime($today . ' +1 day'));

    $units = fwlDepartureUnits($conn, $today, $tomorrow);

    // Meeting point per departure: the product's (a group is one product).
    ensureProductMeetingPointColumn($conn);
    $mp = [];
    $st = $conn->prepare("SELECT IF(t.group_id IS NOT NULL, CONCAT('g', t.group_id), CONCAT('t', t.id)) AS tour_unit,
                                 MAX(pr.meeting_point) AS meeting_point
                          FROM tours t
                          LEFT JOIN products pr ON t.product_id = pr.bokun_product_id
                          WHERE t.date >= ? AND t.date <= ? AND t.cancelled = 0
                          GROUP BY tour_unit");
    $st->bind_param('ss', $today, $tomorrow);
    $st->execute();
    $res = $st->get_result();
    while ($r = $res->fetch_assoc()) {
        if ($r['meeting_point'] !== null && $r['meeting_point'] !== '') $mp[$r['tour_unit']] = $r['meeting_point'];
    }
    $st->close();

    $days = [$today => [], $tomorrow => []];
    foreach ($units as $u) {
        if (!isset($days[$u['date']])) continue;
        $days[$u['date']][] = [
            'id' => $u['departure_id'],
            'time' => $u['time'],
            'title' => $u['title'],
            'language' => $u['languages'] ? implode(', ', $u['languages']) : null,
            'guests' => $u['guests'],
            'guide' => $u['guide_name'] !== null && $u['guide_name'] !== '' ? $u['guide_name'] : null,
            'meeting_point' => $mp[$u['departure_id']] ?? null,
        ];
    }

    $out = [];
    foreach ($days as $date => $deps) $out[] = ['date' => $date, 'departures' => $deps];

    echo json_encode([
        'success' => true,
        'data' => ['generated_at' => date('c'), 'days' => $out],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('today.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not load today\'s departures']);
}
