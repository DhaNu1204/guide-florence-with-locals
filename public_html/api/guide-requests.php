<?php
/**
 * Guide Availability Requests API (Phase 2)
 *
 * Lets the owner ask a guide "are you available for this tour?" via a tokenized
 * public link. The guide accepts/declines without logging in; accepting assigns
 * them to the tour. READ/ASSIGN only — this endpoint never touches payment logic.
 *
 * AUTH MODEL:
 *   - If a `token` query param is present, the request is handled as PUBLIC
 *     (no auth) — minimal tour data, never any customer names/contact.
 *   - Otherwise the request is an OWNER operation and requires Middleware auth.
 *
 * Endpoints:
 *   PUBLIC:
 *     GET  ?token=XYZ                      -> request + minimal tour info
 *     POST ?token=XYZ  {action:accept|decline}
 *   OWNER (authenticated):
 *     POST {tour_id, guide_id}             -> create (or reuse pending) request
 *     POST {action:'cancel', id}          -> cancel a request
 *     GET  ?tour_id=X                      -> list a tour's requests
 *     GET  ?action=recent&days=7          -> recently answered requests
 */

require_once 'config.php';
require_once 'Middleware.php';

// --- Ensure the availability_requests table exists (self-provision) ---
$conn->query("
    CREATE TABLE IF NOT EXISTS availability_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tour_id INT NOT NULL,
        guide_id INT NOT NULL,
        token VARCHAR(64) NOT NULL UNIQUE,
        status ENUM('pending','accepted','declined','cancelled','expired') NOT NULL DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        responded_at TIMESTAMP NULL,
        INDEX idx_token (token),
        INDEX idx_tour (tour_id),
        INDEX idx_guide (guide_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Optional tours.meeting_point column (only surfaced publicly if present)
$hasMeetingPoint = false;
$mpCheck = $conn->query("SHOW COLUMNS FROM tours LIKE 'meeting_point'");
if ($mpCheck && $mpCheck->num_rows > 0) {
    $hasMeetingPoint = true;
}

// Method-based rate limiting (read for GET, create for POST) — applies to both branches
autoRateLimit('guide-requests');

$method = $_SERVER['REQUEST_METHOD'];
$token = isset($_GET['token']) ? trim($_GET['token']) : '';

try {
    if ($token !== '') {
        // ---------------- PUBLIC (token) branch — NO auth ----------------
        handlePublic($conn, $method, $token, $hasMeetingPoint);
    } else {
        // ---------------- OWNER branch — requires auth -------------------
        Middleware::requireAuth($conn);
        handleOwner($conn, $method);
    }
} catch (Exception $e) {
    http_response_code(500);
    error_log("Guide requests error: " . $e->getMessage());
    echo json_encode(['error' => 'An internal error occurred']);
}

$conn->close();

// =====================================================================
// PUBLIC handlers
// =====================================================================

function handlePublic($conn, $method, $token, $hasMeetingPoint) {
    if ($method === 'GET') {
        publicGetRequest($conn, $token, $hasMeetingPoint);
    } elseif ($method === 'POST') {
        publicRespond($conn, $token);
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }
}

/**
 * Minimal public view of a request + its tour. Never returns customer data.
 */
function publicGetRequest($conn, $token, $hasMeetingPoint) {
    $cols = "ar.status, ar.tour_id, g.name AS guide_name,
             t.date, t.time, t.title, t.language, t.participants";
    if ($hasMeetingPoint) {
        $cols .= ", t.meeting_point";
    }

    $stmt = $conn->prepare(
        "SELECT $cols
         FROM availability_requests ar
         JOIN tours t ON t.id = ar.tour_id
         JOIN guides g ON g.id = ar.guide_id
         WHERE ar.token = ?
         LIMIT 1"
    );
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => 'Request not found']);
        return;
    }

    $tour = [
        'date'         => $row['date'],
        'time'         => $row['time'],
        'title'        => $row['title'],
        'language'     => $row['language'],
        'participants' => isset($row['participants']) ? intval($row['participants']) : 0
    ];
    if ($hasMeetingPoint) {
        $tour['meeting_point'] = $row['meeting_point'];
    }

    echo json_encode([
        'success'    => true,
        'status'     => $row['status'],
        'guide_name' => $row['guide_name'],
        'tour'       => $tour
    ]);
}

/**
 * Guide accepts or declines via the public token link.
 */
function publicRespond($conn, $token) {
    $data = json_decode(file_get_contents('php://input'), true);
    $action = isset($data['action']) ? $data['action'] : '';

    if ($action !== 'accept' && $action !== 'decline') {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
        return;
    }

    // Load the request
    $stmt = $conn->prepare(
        "SELECT id, tour_id, guide_id, status FROM availability_requests WHERE token = ? LIMIT 1"
    );
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $req = $stmt->get_result()->fetch_assoc();

    if (!$req) {
        http_response_code(404);
        echo json_encode(['error' => 'Request not found']);
        return;
    }

    // Already answered/closed — report current status, do nothing
    if ($req['status'] !== 'pending') {
        echo json_encode([
            'success' => true,
            'status'  => $req['status'],
            'message' => 'This request has already been ' . $req['status'] . '.'
        ]);
        return;
    }

    $reqId   = intval($req['id']);
    $tourId  = intval($req['tour_id']);
    $guideId = intval($req['guide_id']);

    // --- DECLINE ---
    if ($action === 'decline') {
        $upd = $conn->prepare("UPDATE availability_requests SET status = 'declined', responded_at = NOW() WHERE id = ?");
        $upd->bind_param("i", $reqId);
        $upd->execute();
        echo json_encode(['success' => true, 'status' => 'declined']);
        return;
    }

    // --- ACCEPT ---
    // Look up the tour's current guide + date/time
    $tStmt = $conn->prepare("SELECT guide_id, date, time FROM tours WHERE id = ? LIMIT 1");
    $tStmt->bind_param("i", $tourId);
    $tStmt->execute();
    $tour = $tStmt->get_result()->fetch_assoc();

    if (!$tour) {
        http_response_code(404);
        echo json_encode(['error' => 'Tour not found']);
        return;
    }

    // Already taken by a different guide -> expire this request
    if ($tour['guide_id'] !== null && intval($tour['guide_id']) !== $guideId) {
        $exp = $conn->prepare("UPDATE availability_requests SET status = 'expired', responded_at = NOW() WHERE id = ?");
        $exp->bind_param("i", $reqId);
        $exp->execute();
        echo json_encode([
            'success' => true,
            'status'  => 'taken',
            'message' => 'This tour has already been assigned.'
        ]);
        return;
    }

    // Double-booking guard: same guide already has another tour at this date+time
    $clashStmt = $conn->prepare(
        "SELECT id FROM tours
         WHERE guide_id = ? AND date = ? AND time = ? AND id != ? AND cancelled = 0
         LIMIT 1"
    );
    $clashStmt->bind_param("issi", $guideId, $tour['date'], $tour['time'], $tourId);
    $clashStmt->execute();
    $clash = $clashStmt->get_result()->fetch_assoc();

    if ($clash) {
        echo json_encode([
            'success' => true,
            'status'  => 'conflict',
            'message' => 'You already have a tour at this date/time.'
        ]);
        return;
    }

    // Assign the guide and accept — wrapped in a transaction for integrity
    $conn->begin_transaction();
    try {
        $assign = $conn->prepare("UPDATE tours SET guide_id = ?, needs_guide_assignment = 0 WHERE id = ?");
        $assign->bind_param("ii", $guideId, $tourId);
        $assign->execute();

        $acc = $conn->prepare("UPDATE availability_requests SET status = 'accepted', responded_at = NOW() WHERE id = ?");
        $acc->bind_param("i", $reqId);
        $acc->execute();

        // Expire any OTHER still-pending requests for the same tour
        $expOthers = $conn->prepare(
            "UPDATE availability_requests SET status = 'expired', responded_at = NOW()
             WHERE tour_id = ? AND status = 'pending' AND id != ?"
        );
        $expOthers->bind_param("ii", $tourId, $reqId);
        $expOthers->execute();

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

    echo json_encode(['success' => true, 'status' => 'accepted']);
}

// =====================================================================
// OWNER handlers (authenticated)
// =====================================================================

function handleOwner($conn, $method) {
    if ($method === 'GET') {
        $action = isset($_GET['action']) ? $_GET['action'] : '';
        if ($action === 'recent') {
            ownerRecent($conn);
        } elseif ($action === 'open') {
            ownerOpen($conn);
        } elseif (isset($_GET['tour_id'])) {
            ownerListForTour($conn, intval($_GET['tour_id']));
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'tour_id, action=open or action=recent required']);
        }
    } elseif ($method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $action = isset($data['action']) ? $data['action'] : '';
        if ($action === 'cancel') {
            ownerCancel($conn, isset($data['id']) ? intval($data['id']) : 0);
        } else {
            ownerCreate($conn, $data);
        }
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }
}

/**
 * Create a new availability request (or reuse an existing pending one).
 */
function ownerCreate($conn, $data) {
    $tourId  = isset($data['tour_id']) ? intval($data['tour_id']) : 0;
    $guideId = isset($data['guide_id']) ? intval($data['guide_id']) : 0;

    if ($tourId <= 0 || $guideId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'tour_id and guide_id are required']);
        return;
    }

    // Fetch tour + guide details (for the link + suggested message)
    $infoStmt = $conn->prepare(
        "SELECT t.date, t.time, t.title, t.language, g.name AS guide_name
         FROM tours t JOIN guides g ON g.id = ?
         WHERE t.id = ? LIMIT 1"
    );
    $infoStmt->bind_param("ii", $guideId, $tourId);
    $infoStmt->execute();
    $info = $infoStmt->get_result()->fetch_assoc();

    if (!$info) {
        http_response_code(404);
        echo json_encode(['error' => 'Tour or guide not found']);
        return;
    }

    // Reuse an existing pending request for this tour+guide if present
    $existStmt = $conn->prepare(
        "SELECT id, token, status FROM availability_requests
         WHERE tour_id = ? AND guide_id = ? AND status = 'pending'
         ORDER BY created_at DESC LIMIT 1"
    );
    $existStmt->bind_param("ii", $tourId, $guideId);
    $existStmt->execute();
    $existing = $existStmt->get_result()->fetch_assoc();

    if ($existing) {
        $id     = intval($existing['id']);
        $token  = $existing['token'];
        $status = $existing['status'];
    } else {
        $token = bin2hex(random_bytes(24));
        $ins = $conn->prepare(
            "INSERT INTO availability_requests (tour_id, guide_id, token, status) VALUES (?, ?, ?, 'pending')"
        );
        $ins->bind_param("iis", $tourId, $guideId, $token);
        $ins->execute();
        $id     = $conn->insert_id;
        $status = 'pending';
    }

    $siteBase = defined('BASE_URL') ? rtrim(BASE_URL, '/') : 'https://withlocals.deetech.cc';
    $link = $siteBase . '/respond/' . $token;

    // Neutral WhatsApp-style suggestion (frontend may override)
    $message = "Hi " . $info['guide_name'] . ", are you available for this tour?\n\n"
        . $info['title'] . "\n"
        . "Date: " . $info['date'] . "\n"
        . "Time: " . $info['time'] . "\n"
        . "Language: " . ($info['language'] ?: 'n/a') . "\n\n"
        . "Tap to accept or decline: " . $link;

    echo json_encode([
        'success' => true,
        'id'      => $id,
        'token'   => $token,
        'status'  => $status,
        'link'    => $link,
        'message' => $message
    ]);
}

/**
 * Cancel a request.
 */
function ownerCancel($conn, $id) {
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'id is required']);
        return;
    }
    $stmt = $conn->prepare("UPDATE availability_requests SET status = 'cancelled' WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    echo json_encode(['success' => true, 'status' => 'cancelled']);
}

/**
 * List all requests for a given tour.
 */
function ownerListForTour($conn, $tourId) {
    if ($tourId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Valid tour_id is required']);
        return;
    }
    $stmt = $conn->prepare(
        "SELECT ar.id, ar.guide_id, g.name AS guide_name, ar.status, ar.created_at, ar.responded_at
         FROM availability_requests ar
         JOIN guides g ON g.id = ar.guide_id
         WHERE ar.tour_id = ?
         ORDER BY ar.created_at DESC"
    );
    $stmt->bind_param("i", $tourId);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($r = $result->fetch_assoc()) {
        $rows[] = [
            'id'           => intval($r['id']),
            'guide_id'     => intval($r['guide_id']),
            'guide_name'   => $r['guide_name'],
            'status'       => $r['status'],
            'created_at'   => $r['created_at'],
            'responded_at' => $r['responded_at']
        ];
    }
    echo json_encode(['success' => true, 'data' => $rows]);
}

/**
 * Open requests (pending or declined) for upcoming tours (date >= today).
 * Used to map persistent request-status badges onto tours.
 */
function ownerOpen($conn) {
    $stmt = $conn->prepare(
        "SELECT ar.id, ar.tour_id, ar.guide_id, g.name AS guide_name, ar.status, ar.created_at, ar.responded_at
         FROM availability_requests ar
         JOIN tours t ON t.id = ar.tour_id
         JOIN guides g ON g.id = ar.guide_id
         WHERE ar.status IN ('pending','declined')
           AND t.date >= CURDATE()
         ORDER BY ar.created_at DESC"
    );
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($r = $result->fetch_assoc()) {
        $rows[] = [
            'id'           => intval($r['id']),
            'tour_id'      => intval($r['tour_id']),
            'guide_id'     => intval($r['guide_id']),
            'guide_name'   => $r['guide_name'],
            'status'       => $r['status'],
            'created_at'   => $r['created_at'],
            'responded_at' => $r['responded_at']
        ];
    }
    echo json_encode(['success' => true, 'data' => $rows]);
}

/**
 * Recently answered (accepted/declined) requests within N days — dashboard summary.
 */
function ownerRecent($conn) {
    $days = isset($_GET['days']) ? intval($_GET['days']) : 7;
    if ($days < 1) { $days = 7; }
    if ($days > 90) { $days = 90; }

    $stmt = $conn->prepare(
        "SELECT ar.id, ar.tour_id, t.title AS tour_title, t.date AS tour_date, t.time AS tour_time,
                ar.guide_id, g.name AS guide_name, ar.status, ar.responded_at
         FROM availability_requests ar
         JOIN tours t ON t.id = ar.tour_id
         JOIN guides g ON g.id = ar.guide_id
         WHERE ar.status IN ('accepted','declined')
           AND ar.responded_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
         ORDER BY ar.responded_at DESC"
    );
    $stmt->bind_param("i", $days);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($r = $result->fetch_assoc()) {
        $rows[] = [
            'id'           => intval($r['id']),
            'tour_id'      => intval($r['tour_id']),
            'tour_title'   => $r['tour_title'],
            'tour_date'    => $r['tour_date'],
            'tour_time'    => $r['tour_time'],
            'guide_id'     => intval($r['guide_id']),
            'guide_name'   => $r['guide_name'],
            'status'       => $r['status'],
            'responded_at' => $r['responded_at']
        ];
    }
    echo json_encode(['success' => true, 'data' => $rows]);
}
?>
