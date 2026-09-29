<?php
/**
 * Step 7.2b: product settings (admin only) - the /products page.
 *
 *   GET  products.php            -> [{bokun_product_id, title, product_type, duration_minutes,
 *                                     upcoming_bookings, last_date}]
 *   PUT  products.php/{id}       {title?, product_type?, duration_minutes?}  (field omitted = untouched)
 *
 * product_type decides Tours vs Tickets everywhere (the products table has been the only rule since
 * step 3.8). duration_minutes: null = unknown (5..1440 otherwise); used by the assistant's
 * free_guides and, later, clash checks.
 */
require_once 'config.php';
require_once 'Middleware.php';
require_once __DIR__ . '/lib/guide_product_fields.php';

Middleware::requireRole($conn, 'admin'); // settings: admins only, for reading too
autoRateLimit('products');
ensureProductDurationColumn($conn);

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$segments = explode('/', rtrim($path, '/'));
$last = end($segments);
$productId = ctype_digit((string) $last) ? (int) $last : null;

function productsRow($conn, $id) {
    $s = $conn->prepare("SELECT bokun_product_id, title, product_type, duration_minutes FROM products WHERE bokun_product_id = ?");
    $s->bind_param('i', $id);
    $s->execute();
    $r = $s->get_result()->fetch_assoc();
    $s->close();
    if (!$r) return null;
    $r['bokun_product_id'] = (int) $r['bokun_product_id'];
    $r['duration_minutes'] = $r['duration_minutes'] !== null ? (int) $r['duration_minutes'] : null;
    return $r;
}

try {
    if ($method === 'GET' && $productId === null) {
        $today = date('Y-m-d'); // Europe/Rome (PHP), not the DB's UTC CURDATE()
        $stmt = $conn->prepare("
            SELECT p.bokun_product_id, p.title, p.product_type, p.duration_minutes,
                   SUM(CASE WHEN t.date >= ? AND t.cancelled = 0 THEN 1 ELSE 0 END) AS upcoming_bookings,
                   MAX(t.date) AS last_date,
                   MAX(t.title) AS booking_title
            FROM products p
            LEFT JOIN tours t ON t.product_id = p.bokun_product_id
            GROUP BY p.bokun_product_id
            ORDER BY p.product_type ASC, upcoming_bookings DESC, p.title ASC");
        $stmt->bind_param('s', $today);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($r = $res->fetch_assoc()) {
            $out[] = [
                'bokun_product_id' => (int) $r['bokun_product_id'],
                // products.title can be empty for rows seeded by id only - show the bookings' title then
                'title' => trim((string) $r['title']) !== '' ? $r['title'] : (string) $r['booking_title'],
                'product_type' => $r['product_type'],
                'duration_minutes' => $r['duration_minutes'] !== null ? (int) $r['duration_minutes'] : null,
                'upcoming_bookings' => (int) $r['upcoming_bookings'],
                'last_date' => $r['last_date'],
            ];
        }
        $stmt->close();
        echo json_encode(['success' => true, 'data' => $out]);
        exit();
    }

    if ($method === 'PUT' && $productId !== null) {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid request data']);
            exit();
        }
        if (productsRow($conn, $productId) === null) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Product not found']);
            exit();
        }
        $sets = []; $types = ''; $vals = [];
        if (array_key_exists('title', $data)) {
            $title = trim((string) $data['title']);
            if ($title === '' || mb_strlen($title, 'UTF-8') > 500) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Title is required (max 500 characters)']);
                exit();
            }
            $sets[] = 'title = ?'; $types .= 's'; $vals[] = $title;
        }
        if (array_key_exists('product_type', $data)) {
            if (!in_array($data['product_type'], ['tour', 'ticket'], true)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'product_type must be tour or ticket']);
                exit();
            }
            $sets[] = 'product_type = ?'; $types .= 's'; $vals[] = $data['product_type'];
        }
        if (array_key_exists('duration_minutes', $data)) {
            if (!productDurationValid($data['duration_minutes'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Duration must be empty or 5 to 1440 minutes']);
                exit();
            }
            $d = ($data['duration_minutes'] === null || $data['duration_minutes'] === '') ? null : (int) $data['duration_minutes'];
            $sets[] = 'duration_minutes = ?'; $types .= 'i'; $vals[] = $d;
        }
        if (!$sets) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Nothing to update']);
            exit();
        }
        $types .= 'i'; $vals[] = $productId;
        $stmt = $conn->prepare('UPDATE products SET ' . implode(', ', $sets) . ' WHERE bokun_product_id = ?');
        $stmt->bind_param($types, ...$vals);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true, 'data' => productsRow($conn, $productId)]);
        exit();
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
} catch (Throwable $e) {
    error_log('products.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'An internal error occurred']);
}
