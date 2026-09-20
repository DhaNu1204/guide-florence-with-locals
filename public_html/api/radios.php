<?php
/**
 * radios.php - step 6.3: the radio order the owner sends Vox Firenze every afternoon.
 *
 * ADMIN ONLY. Reads tours/groups read-only and writes ONLY to its own self-provisioned
 * radio_orders table. Touches NO payment logic, no grouping, no reminders, no digest.
 *
 *   GET  ?action=plan&date=YYYY-MM-DD   the sections, the lines, the totals and the message
 *                                       (date defaults to tomorrow, Europe/Rome)
 *   GET  ?action=order&date=YYYY-MM-DD  the last order recorded for that date, if any
 *   POST ?action=sent {date, message, receivers_total, transmitters_total}
 *                                       record what was actually sent
 *
 * A "line" is one DEPARTURE: a group of bookings counts once with the group's PAX, and two
 * departures at the same time are two lines. Ticket-only products and cancelled bookings are
 * excluded. The number on the line is guests only - the supplier adds the guide's transmitter.
 */

require_once 'config.php';
require_once 'Middleware.php';
require_once __DIR__ . '/radio_helpers.php';

// Radio orders are money: admin only, for reads as well as writes.
Middleware::requireRole($conn, 'admin');

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? trim($_GET['action']) : 'plan';

/**
 * Self-provision the record of what was sent. Also in
 * database/migrations/20260921_radio_orders.sql.
 */
function radioEnsureTables($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS radio_orders (
        id                 INT AUTO_INCREMENT PRIMARY KEY,
        order_date         DATE NOT NULL,
        message_text       TEXT NOT NULL,
        receivers_total    INT NOT NULL DEFAULT 0,
        transmitters_total INT NOT NULL DEFAULT 0,
        created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        created_by         INT NULL,
        KEY idx_radio_orders_date (order_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Tomorrow in Europe/Rome - what he is ordering for when he writes in the afternoon. */
function radioDefaultDate() {
    return (new DateTimeImmutable('now', new DateTimeZone('Europe/Rome')))
        ->add(new DateInterval('P1D'))->format('Y-m-d');
}

/**
 * Every guided departure on that date, one row per tour unit.
 * A group counts once, with the group's time and its members' PAX added up.
 * Ticket products (products.product_type, the single source of truth since 3.8) and cancelled
 * bookings are excluded.
 */
function radioLoadDepartures($conn, $date) {
    $sql = "SELECT
                IF(t.group_id IS NOT NULL, CONCAT('g', t.group_id), CONCAT('t', t.id)) AS unit,
                COALESCE(tg.group_time, MIN(t.time))  AS time,
                SUM(t.participants)                   AS pax,
                COUNT(*)                              AS bookings,
                MIN(t.product_id)                     AS product_id,
                COALESCE(MIN(tg.display_name), MIN(t.title)) AS title,
                COALESCE(tg.guide_id, MIN(t.guide_id)) AS guide_id,
                MIN(g.name)                           AS guide_name
            FROM tours t
            LEFT JOIN tour_groups tg ON tg.id = t.group_id
            LEFT JOIN guides g ON g.id = COALESCE(tg.guide_id, t.guide_id)
            WHERE t.date = ?
              AND t.cancelled = 0
              AND NOT EXISTS (SELECT 1 FROM products pr
                               WHERE pr.bokun_product_id = t.product_id AND pr.product_type = 'ticket')
            GROUP BY unit, tg.group_time, tg.guide_id
            ORDER BY time, unit";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $date);
    $stmt->execute();
    $res = $stmt->get_result();

    $departures = [];
    while ($row = $res->fetch_assoc()) {
        $museum = radioMuseumForTitle($row['title']);
        $departures[] = [
            'unit'       => $row['unit'],
            'time'       => $row['time'],
            'pax'        => (int) $row['pax'],
            'bookings'   => (int) $row['bookings'],
            'product_id' => $row['product_id'] !== null ? (int) $row['product_id'] : null,
            'title'      => $row['title'],
            'guide_id'   => $row['guide_id'] !== null ? (int) $row['guide_id'] : null,
            'guide_name' => $row['guide_name'],
            'museum'     => $museum['museum'],
            'museum_confident' => $museum['confident'],
        ];
    }
    $stmt->close();
    return $departures;
}

function radioLastOrder($conn, $date) {
    $stmt = $conn->prepare("SELECT id, order_date, message_text, receivers_total, transmitters_total, created_at
                              FROM radio_orders WHERE order_date = ? ORDER BY id DESC LIMIT 1");
    $stmt->bind_param("s", $date);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

try {
    radioEnsureTables($conn);

    if ($method === 'GET' && ($action === 'plan' || $action === '')) {
        applyRateLimit('read');
        $date = isset($_GET['date']) ? trim($_GET['date']) : radioDefaultDate();
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            http_response_code(400);
            echo json_encode(['error' => 'date must be YYYY-MM-DD']);
            exit();
        }

        $departures = radioLoadDepartures($conn, $date);
        $sections = radioBuildSections($departures);
        $romeNow = new DateTimeImmutable('now', new DateTimeZone('Europe/Rome'));
        $greeting = radioGreeting($romeNow);
        $message = radioRenderMessage($sections, $greeting);

        echo json_encode([
            'success' => true,
            'data' => [
                'date'       => $date,
                'greeting'   => $greeting,
                'closing'    => 'Grazie',
                'sections'   => $sections,
                'departures' => $departures,
                'totals'     => radioTotals($departures),
                'message'    => $message,
                'last_order' => radioLastOrder($conn, $date),
            ],
        ]);
        exit();
    }

    if ($method === 'GET' && $action === 'order') {
        applyRateLimit('read');
        $date = isset($_GET['date']) ? trim($_GET['date']) : radioDefaultDate();
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            http_response_code(400);
            echo json_encode(['error' => 'date must be YYYY-MM-DD']);
            exit();
        }
        echo json_encode(['success' => true, 'data' => radioLastOrder($conn, $date)]);
        exit();
    }

    if ($method === 'POST' && $action === 'sent') {
        applyRateLimit('update');
        $input = json_decode(file_get_contents('php://input'), true);
        $date = isset($input['date']) ? trim($input['date']) : '';
        $message = isset($input['message']) ? trim((string) $input['message']) : '';

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            http_response_code(400);
            echo json_encode(['error' => 'date must be YYYY-MM-DD']);
            exit();
        }
        if ($message === '') {
            http_response_code(400);
            echo json_encode(['error' => 'The message is empty - nothing to record']);
            exit();
        }
        if (mb_strlen($message) > 8000) {
            http_response_code(400);
            echo json_encode(['error' => 'That message is too long to record']);
            exit();
        }

        $receivers = isset($input['receivers_total']) ? max(0, intval($input['receivers_total'])) : 0;
        $transmitters = isset($input['transmitters_total']) ? max(0, intval($input['transmitters_total'])) : 0;
        $userId = isset($GLOBALS['auth_user']['id']) ? intval($GLOBALS['auth_user']['id']) : null;

        $stmt = $conn->prepare("INSERT INTO radio_orders (order_date, message_text, receivers_total, transmitters_total, created_by)
                                VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("ssiii", $date, $message, $receivers, $transmitters, $userId);
        $stmt->execute();
        $id = $conn->insert_id;
        $stmt->close();

        echo json_encode(['success' => true, 'data' => radioLastOrder($conn, $date) + ['id' => $id]]);
        exit();
    }

    http_response_code(400);
    echo json_encode(['error' => 'Invalid action']);
} catch (Exception $e) {
    http_response_code(500);
    error_log('radios.php error: ' . $e->getMessage());
    echo json_encode(['error' => 'An internal error occurred']);
}
