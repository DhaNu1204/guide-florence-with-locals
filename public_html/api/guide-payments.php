<?php
/**
 * Guide Payment Summary API
 * Provides payment summaries and analysis for tour guides
 *
 * Endpoints:
 * GET /api/guide-payments.php - Get payment summary for all guides
 * GET /api/guide-payments.php?guide_id=X - Get detailed payment info for specific guide
 * GET /api/guide-payments.php?guide_id=X&period=YYYY-MM - Get guide payments for specific month
 * GET /api/guide-payments.php?action=overview - Get overall payment statistics
 */

require_once 'config.php';

// Handle request based on parameters
$guide_id = isset($_GET['guide_id']) ? intval($_GET['guide_id']) : null;
$period = isset($_GET['period']) ? $_GET['period'] : null;
$action = isset($_GET['action']) ? $_GET['action'] : null;

try {
    if ($action === 'overview') {
        getPaymentOverview($conn);
    } elseif ($guide_id) {
        if ($period) {
            getGuidePaymentsByPeriod($conn, $guide_id, $period);
        } else {
            getGuidePaymentDetails($conn, $guide_id);
        }
    } else {
        getAllGuidePaymentSummaries($conn);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}

/**
 * Get payment summary for all guides
 */
function getAllGuidePaymentSummaries($conn) {
    $sql = "SELECT * FROM guide_payment_summary ORDER BY total_payments_received DESC, guide_name";

    $result = $conn->query($sql);

    if ($result) {
        $summaries = [];
        while ($row = $result->fetch_assoc()) {
            // Format monetary values
            $row['total_payments_received'] = floatval($row['total_payments_received']);
            $row['cash_payments'] = floatval($row['cash_payments']);
            $row['bank_payments'] = floatval($row['bank_payments']);

            // Calculate percentages
            if ($row['total_tours'] > 0) {
                $row['payment_completion_rate'] = round(($row['paid_tours'] / $row['total_tours']) * 100, 1);
            } else {
                $row['payment_completion_rate'] = 0;
            }

            // Add payment method breakdown
            if ($row['total_payments_received'] > 0) {
                $row['cash_percentage'] = round(($row['cash_payments'] / $row['total_payments_received']) * 100, 1);
                $row['bank_percentage'] = round(($row['bank_payments'] / $row['total_payments_received']) * 100, 1);
            } else {
                $row['cash_percentage'] = 0;
                $row['bank_percentage'] = 0;
            }

            $summaries[] = $row;
        }

        echo json_encode(['success' => true, 'data' => $summaries]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to fetch guide payment summaries: ' . $conn->error]);
    }
}

/**
 * Get detailed payment information for a specific guide
 */
function getGuidePaymentDetails($conn, $guide_id) {
    // Get guide info and summary
    $stmt = $conn->prepare("SELECT * FROM guide_payment_summary WHERE guide_id = ?");
    $stmt->bind_param("i", $guide_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if (!$result || !($guide_summary = $result->fetch_assoc())) {
        http_response_code(404);
        echo json_encode(['error' => 'Guide not found']);
        return;
    }

    // Get detailed payment transactions for this guide
    $stmt = $conn->prepare("SELECT
                                pt.id,
                                pt.tour_id,
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
                                t.payment_status as tour_payment_status
                            FROM payments pt
                            JOIN tours t ON pt.tour_id = t.id
                            WHERE pt.guide_id = ?
                            ORDER BY pt.payment_date DESC, pt.created_at DESC");

    $stmt->bind_param("i", $guide_id);
    $stmt->execute();
    $payments_result = $stmt->get_result();

    $payments = [];
    while ($row = $payments_result->fetch_assoc()) {
        $row['amount'] = floatval($row['amount']);
        $payments[] = $row;
    }

    // Get monthly breakdown for this guide
    $stmt = $conn->prepare("SELECT
                                YEAR(pt.payment_date) as year,
                                MONTH(pt.payment_date) as month,
                                MONTHNAME(pt.payment_date) as month_name,
                                COUNT(pt.id) as payment_count,
                                COUNT(DISTINCT pt.tour_id) as tours_paid,
                                SUM(pt.amount) as total_amount,
                                SUM(CASE WHEN pt.payment_method = 'cash' THEN pt.amount ELSE 0 END) as cash_amount,
                                SUM(CASE WHEN pt.payment_method = 'bank_transfer' THEN pt.amount ELSE 0 END) as bank_amount,
                                AVG(pt.amount) as avg_payment
                            FROM payments pt
                            WHERE pt.guide_id = ?
                            GROUP BY YEAR(pt.payment_date), MONTH(pt.payment_date)
                            ORDER BY year DESC, month DESC
                            LIMIT 12");

    $stmt->bind_param("i", $guide_id);
    $stmt->execute();
    $monthly_result = $stmt->get_result();

    $monthly_breakdown = [];
    while ($row = $monthly_result->fetch_assoc()) {
        $row['total_amount'] = floatval($row['total_amount']);
        $row['cash_amount'] = floatval($row['cash_amount']);
        $row['bank_amount'] = floatval($row['bank_amount']);
        $row['avg_payment'] = floatval($row['avg_payment']);
        $monthly_breakdown[] = $row;
    }

    // Get unpaid tours for this guide
    $stmt = $conn->prepare("SELECT
                                id,
                                title,
                                date,
                                time,
                                customer_name,
                                payment_status,
                                total_amount_paid,
                                expected_amount
                            FROM tours
                            WHERE guide_id = ? AND payment_status IN ('unpaid', 'partial')
                            ORDER BY date DESC");

    $stmt->bind_param("i", $guide_id);
    $stmt->execute();
    $unpaid_result = $stmt->get_result();

    $unpaid_tours = [];
    while ($row = $unpaid_result->fetch_assoc()) {
        $row['total_amount_paid'] = floatval($row['total_amount_paid']);
        $row['expected_amount'] = $row['expected_amount'] ? floatval($row['expected_amount']) : null;
        $unpaid_tours[] = $row;
    }

    // Format guide summary monetary values
    $guide_summary['total_payments_received'] = floatval($guide_summary['total_payments_received']);
    $guide_summary['cash_payments'] = floatval($guide_summary['cash_payments']);
    $guide_summary['bank_payments'] = floatval($guide_summary['bank_payments']);

    echo json_encode([
        'success' => true,
        'data' => [
            'guide_summary' => $guide_summary,
            'recent_payments' => $payments,
            'monthly_breakdown' => $monthly_breakdown,
            'unpaid_tours' => $unpaid_tours
        ]
    ]);
}

/**
 * Get guide payments for a specific period (YYYY-MM format)
 */
function getGuidePaymentsByPeriod($conn, $guide_id, $period) {
    // Validate period format
    if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid period format. Use YYYY-MM']);
        return;
    }

    $year = substr($period, 0, 4);
    $month = substr($period, 5, 2);

    // Get guide basic info
    $stmt = $conn->prepare("SELECT name, email FROM guides WHERE id = ?");
    $stmt->bind_param("i", $guide_id);
    $stmt->execute();
    $guide_result = $stmt->get_result();

    if (!$guide_result || !($guide_info = $guide_result->fetch_assoc())) {
        http_response_code(404);
        echo json_encode(['error' => 'Guide not found']);
        return;
    }

    // Get payments for the specific month
    $stmt = $conn->prepare("SELECT
                                pt.id,
                                pt.tour_id,
                                pt.amount,
                                pt.payment_method,
                                pt.payment_date,
                                pt.payment_time,
                                pt.transaction_reference,
                                pt.notes,
                                t.title as tour_title,
                                t.date as tour_date,
                                t.time as tour_time,
                                t.customer_name
                            FROM payments pt
                            JOIN tours t ON pt.tour_id = t.id
                            WHERE pt.guide_id = ?
                              AND YEAR(pt.payment_date) = ?
                              AND MONTH(pt.payment_date) = ?
                            ORDER BY pt.payment_date DESC, pt.created_at DESC");

    $stmt->bind_param("iii", $guide_id, $year, $month);
    $stmt->execute();
    $payments_result = $stmt->get_result();

    $payments = [];
    $total_amount = 0;
    $payment_methods = [];

    while ($row = $payments_result->fetch_assoc()) {
        $row['amount'] = floatval($row['amount']);
        $total_amount += $row['amount'];

        if (!isset($payment_methods[$row['payment_method']])) {
            $payment_methods[$row['payment_method']] = 0;
        }
        $payment_methods[$row['payment_method']] += $row['amount'];

        $payments[] = $row;
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'guide_info' => $guide_info,
            'period' => $period,
            'summary' => [
                'total_payments' => count($payments),
                'total_amount' => $total_amount,
                'payment_methods' => $payment_methods
            ],
            'payments' => $payments
        ]
    ]);
}

/**
 * Get overall payment statistics
 */
function getPaymentOverview($conn) {
    // Overall statistics
    $stats = [];

    // Total payments and amount
    $result = $conn->query("SELECT
                                COUNT(*) as total_transactions,
                                SUM(amount) as total_amount,
                                AVG(amount) as avg_payment,
                                MIN(payment_date) as first_payment,
                                MAX(payment_date) as last_payment
                            FROM payments");

    if ($result && $row = $result->fetch_assoc()) {
        $stats['overall'] = [
            'total_transactions' => intval($row['total_transactions']),
            'total_amount' => floatval($row['total_amount']),
            'avg_payment' => floatval($row['avg_payment']),
            'first_payment' => $row['first_payment'],
            'last_payment' => $row['last_payment']
        ];
    }

    // Payment method breakdown
    $result = $conn->query("SELECT
                                payment_method,
                                COUNT(*) as count,
                                SUM(amount) as total_amount
                            FROM payments
                            GROUP BY payment_method
                            ORDER BY total_amount DESC");

    $payment_methods = [];
    while ($row = $result->fetch_assoc()) {
        $payment_methods[] = [
            'method' => $row['payment_method'],
            'count' => intval($row['count']),
            'amount' => floatval($row['total_amount'])
        ];
    }
    $stats['payment_methods'] = $payment_methods;

    // Tour status breakdown
    $result = $conn->query("SELECT
                                payment_status,
                                COUNT(*) as count
                            FROM tours
                            GROUP BY payment_status");

    $tour_statuses = [];
    while ($row = $result->fetch_assoc()) {
        $tour_statuses[] = [
            'status' => $row['payment_status'],
            'count' => intval($row['count'])
        ];
    }
    $stats['tour_statuses'] = $tour_statuses;

    // Recent payment activity (last 30 days)
    $result = $conn->query("SELECT
                                DATE(payment_date) as date,
                                COUNT(*) as payment_count,
                                SUM(amount) as daily_amount
                            FROM payments
                            WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                            GROUP BY DATE(payment_date)
                            ORDER BY date DESC");

    $recent_activity = [];
    while ($row = $result->fetch_assoc()) {
        $recent_activity[] = [
            'date' => $row['date'],
            'payment_count' => intval($row['payment_count']),
            'daily_amount' => floatval($row['daily_amount'])
        ];
    }
    $stats['recent_activity'] = $recent_activity;

    echo json_encode(['success' => true, 'data' => $stats]);
}

$conn->close();
?>