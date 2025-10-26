<?php
/**
 * Payment Management API
 * Handles payment transactions for tours
 *
 * Endpoints:
 * GET    /api/payments.php - Get all payment transactions
 * POST   /api/payments.php - Create new payment transaction
 * PUT    /api/payments.php?id=X - Update payment transaction
 * DELETE /api/payments.php?id=X - Delete payment transaction
 * GET    /api/payments.php?tour_id=X - Get payments for specific tour
 */

require_once 'config.php';

// Handle request based on HTTP method
$method = $_SERVER['REQUEST_METHOD'];
$tour_id = isset($_GET['tour_id']) ? intval($_GET['tour_id']) : null;
$payment_id = isset($_GET['id']) ? intval($_GET['id']) : null;

try {
    switch ($method) {
        case 'GET':
            if ($payment_id) {
                getPaymentById($conn, $payment_id);
            } elseif ($tour_id) {
                getPaymentsByTour($conn, $tour_id);
            } else {
                getAllPayments($conn);
            }
            break;

        case 'POST':
            createPayment($conn);
            break;

        case 'PUT':
            if (!$payment_id) {
                http_response_code(400);
                echo json_encode(['error' => 'Payment ID required for update']);
                exit();
            }
            updatePayment($conn, $payment_id);
            break;

        case 'DELETE':
            if (!$payment_id) {
                http_response_code(400);
                echo json_encode(['error' => 'Payment ID required for deletion']);
                exit();
            }
            deletePayment($conn, $payment_id);
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}

/**
 * Get all payment transactions with tour and guide details
 */
function getAllPayments($conn) {
    $sql = "SELECT
                pt.id,
                pt.tour_id,
                pt.guide_id,
                pt.amount,
                pt.payment_method,
                pt.payment_date,
                pt.payment_time,
                pt.transaction_reference,
                pt.notes,
                pt.created_at,
                t.title as tour_title,
                t.date as tour_date,
                t.time as tour_time,
                t.customer_name,
                g.name as guide_name,
                g.email as guide_email
            FROM payments pt
            JOIN tours t ON pt.tour_id = t.id
            JOIN guides g ON pt.guide_id = g.id
            ORDER BY pt.payment_date DESC, pt.created_at DESC";

    $result = $conn->query($sql);

    if ($result) {
        $payments = [];
        while ($row = $result->fetch_assoc()) {
            $payments[] = $row;
        }
        echo json_encode(['success' => true, 'data' => $payments]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to fetch payments: ' . $conn->error]);
    }
}

/**
 * Get payment by ID
 */
function getPaymentById($conn, $payment_id) {
    $stmt = $conn->prepare("SELECT
                                pt.id,
                                pt.tour_id,
                                pt.guide_id,
                                pt.amount,
                                pt.payment_method,
                                pt.payment_date,
                                pt.payment_time,
                                pt.transaction_reference,
                                pt.notes,
                                pt.created_at,
                                t.title as tour_title,
                                t.date as tour_date,
                                t.time as tour_time,
                                t.customer_name,
                                g.name as guide_name,
                                g.email as guide_email
                            FROM payments pt
                            JOIN tours t ON pt.tour_id = t.id
                            JOIN guides g ON pt.guide_id = g.id
                            WHERE pt.id = ?");

    $stmt->bind_param("i", $payment_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $row = $result->fetch_assoc()) {
        echo json_encode(['success' => true, 'data' => $row]);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Payment not found']);
    }
}

/**
 * Get payments for a specific tour
 */
function getPaymentsByTour($conn, $tour_id) {
    $stmt = $conn->prepare("SELECT
                                pt.id,
                                pt.tour_id,
                                pt.guide_id,
                                pt.amount,
                                pt.payment_method,
                                pt.payment_date,
                                pt.payment_time,
                                pt.transaction_reference,
                                pt.notes,
                                pt.created_at,
                                g.name as guide_name,
                                g.email as guide_email
                            FROM payments pt
                            JOIN guides g ON pt.guide_id = g.id
                            WHERE pt.tour_id = ?
                            ORDER BY pt.payment_date DESC, pt.created_at DESC");

    $stmt->bind_param("i", $tour_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $payments = [];
    while ($row = $result->fetch_assoc()) {
        $payments[] = $row;
    }

    // Also get tour info and current payment status
    $tour_stmt = $conn->prepare("SELECT
                                    title,
                                    payment_status,
                                    total_amount_paid,
                                    expected_amount,
                                    customer_name,
                                    date,
                                    time
                                FROM tours WHERE id = ?");
    $tour_stmt->bind_param("i", $tour_id);
    $tour_stmt->execute();
    $tour_result = $tour_stmt->get_result();
    $tour_info = $tour_result->fetch_assoc();

    echo json_encode([
        'success' => true,
        'data' => [
            'tour_info' => $tour_info,
            'payments' => $payments,
            'summary' => [
                'total_paid' => $tour_info['total_amount_paid'],
                'payment_count' => count($payments),
                'status' => $tour_info['payment_status']
            ]
        ]
    ]);
}

/**
 * Create new payment transaction
 */
function createPayment($conn) {
    $input = json_decode(file_get_contents('php://input'), true);

    // Validate required fields
    $required_fields = ['tour_id', 'guide_id', 'amount', 'payment_method', 'payment_date'];
    foreach ($required_fields as $field) {
        if (!isset($input[$field]) || empty($input[$field])) {
            http_response_code(400);
            echo json_encode(['error' => "Field '$field' is required"]);
            return;
        }
    }

    // Validate amount is positive
    if ($input['amount'] <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Amount must be greater than 0']);
        return;
    }

    // Validate payment method
    $valid_methods = ['cash', 'bank_transfer', 'credit_card', 'paypal', 'other'];
    if (!in_array($input['payment_method'], $valid_methods)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid payment method']);
        return;
    }

    // Validate tour and guide exist
    $stmt = $conn->prepare("SELECT id FROM tours WHERE id = ? AND guide_id = ?");
    $stmt->bind_param("ii", $input['tour_id'], $input['guide_id']);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_assoc()) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid tour ID or guide ID mismatch']);
        return;
    }

    // Insert payment transaction
    $stmt = $conn->prepare("INSERT INTO payments
                            (tour_id, guide_id, amount, payment_method, payment_date, payment_time, transaction_reference, notes)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

    $payment_time = isset($input['payment_time']) ? $input['payment_time'] : null;
    $transaction_reference = isset($input['transaction_reference']) ? $input['transaction_reference'] : null;
    $notes = isset($input['notes']) ? $input['notes'] : null;

    $stmt->bind_param("iidsssss",
        $input['tour_id'],
        $input['guide_id'],
        $input['amount'],
        $input['payment_method'],
        $input['payment_date'],
        $payment_time,
        $transaction_reference,
        $notes
    );

    if ($stmt->execute()) {
        $payment_id = $conn->insert_id;

        // Get the updated tour payment status
        $tour_stmt = $conn->prepare("SELECT payment_status, total_amount_paid FROM tours WHERE id = ?");
        $tour_stmt->bind_param("i", $input['tour_id']);
        $tour_stmt->execute();
        $tour_result = $tour_stmt->get_result();
        $tour_info = $tour_result->fetch_assoc();

        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Payment recorded successfully',
            'data' => [
                'payment_id' => $payment_id,
                'tour_payment_status' => $tour_info['payment_status'],
                'tour_total_paid' => $tour_info['total_amount_paid']
            ]
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to create payment: ' . $stmt->error]);
    }
}

/**
 * Update payment transaction
 */
function updatePayment($conn, $payment_id) {
    $input = json_decode(file_get_contents('php://input'), true);

    // Check if payment exists
    $stmt = $conn->prepare("SELECT id FROM payments WHERE id = ?");
    $stmt->bind_param("i", $payment_id);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_assoc()) {
        http_response_code(404);
        echo json_encode(['error' => 'Payment not found']);
        return;
    }

    $update_fields = [];
    $values = [];
    $types = "";

    // Build dynamic update query
    if (isset($input['amount']) && $input['amount'] > 0) {
        $update_fields[] = "amount = ?";
        $values[] = $input['amount'];
        $types .= "d";
    }

    if (isset($input['payment_method'])) {
        $valid_methods = ['cash', 'bank_transfer', 'credit_card', 'paypal', 'other'];
        if (in_array($input['payment_method'], $valid_methods)) {
            $update_fields[] = "payment_method = ?";
            $values[] = $input['payment_method'];
            $types .= "s";
        }
    }

    if (isset($input['payment_date'])) {
        $update_fields[] = "payment_date = ?";
        $values[] = $input['payment_date'];
        $types .= "s";
    }

    if (isset($input['payment_time'])) {
        $update_fields[] = "payment_time = ?";
        $values[] = $input['payment_time'];
        $types .= "s";
    }

    if (isset($input['transaction_reference'])) {
        $update_fields[] = "transaction_reference = ?";
        $values[] = $input['transaction_reference'];
        $types .= "s";
    }

    if (isset($input['notes'])) {
        $update_fields[] = "notes = ?";
        $values[] = $input['notes'];
        $types .= "s";
    }

    if (empty($update_fields)) {
        http_response_code(400);
        echo json_encode(['error' => 'No valid fields to update']);
        return;
    }

    $sql = "UPDATE payments SET " . implode(", ", $update_fields) . " WHERE id = ?";
    $values[] = $payment_id;
    $types .= "i";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$values);

    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Payment updated successfully']);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to update payment: ' . $stmt->error]);
    }
}

/**
 * Delete payment transaction
 */
function deletePayment($conn, $payment_id) {
    // Get tour_id before deletion for updating status
    $stmt = $conn->prepare("SELECT tour_id FROM payments WHERE id = ?");
    $stmt->bind_param("i", $payment_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if (!$result || !($payment = $result->fetch_assoc())) {
        http_response_code(404);
        echo json_encode(['error' => 'Payment not found']);
        return;
    }

    $tour_id = $payment['tour_id'];

    // Delete payment
    $stmt = $conn->prepare("DELETE FROM payments WHERE id = ?");
    $stmt->bind_param("i", $payment_id);

    if ($stmt->execute()) {
        // Get updated tour payment status after deletion
        $tour_stmt = $conn->prepare("SELECT payment_status, total_amount_paid FROM tours WHERE id = ?");
        $tour_stmt->bind_param("i", $tour_id);
        $tour_stmt->execute();
        $tour_result = $tour_stmt->get_result();
        $tour_info = $tour_result->fetch_assoc();

        echo json_encode([
            'success' => true,
            'message' => 'Payment deleted successfully',
            'data' => [
                'tour_payment_status' => $tour_info['payment_status'],
                'tour_total_paid' => $tour_info['total_amount_paid']
            ]
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to delete payment: ' . $stmt->error]);
    }
}

$conn->close();
?>