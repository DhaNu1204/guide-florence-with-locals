<?php
/**
 * Payment Reports API
 * Generates detailed payment reports with flexible filtering and export options
 *
 * Endpoints:
 * GET /api/payment-reports.php?type=summary&start_date=YYYY-MM-DD&end_date=YYYY-MM-DD
 * GET /api/payment-reports.php?type=detailed&guide_id=X&start_date=YYYY-MM-DD&end_date=YYYY-MM-DD
 * GET /api/payment-reports.php?type=export&format=txt&start_date=YYYY-MM-DD&end_date=YYYY-MM-DD
 * GET /api/payment-reports.php?type=monthly&year=YYYY&month=MM
 */

require_once 'config.php';
require_once 'Middleware.php';

// Require authentication for all payment report operations
Middleware::requireAuth($conn);

// Apply rate limiting (read operations)
applyRateLimit('read');

// Get parameters
$type = isset($_GET['type']) ? $_GET['type'] : 'summary';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : null;
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : null;
$guide_id = isset($_GET['guide_id']) ? intval($_GET['guide_id']) : null;
$format = isset($_GET['format']) ? $_GET['format'] : 'json';
$year = isset($_GET['year']) ? intval($_GET['year']) : null;
$month = isset($_GET['month']) ? intval($_GET['month']) : null;

try {
    switch ($type) {
        case 'summary':
            generateSummaryReport($conn, $start_date, $end_date, $format);
            break;

        case 'detailed':
            generateDetailedReport($conn, $start_date, $end_date, $guide_id, $format);
            break;

        case 'monthly':
            generateMonthlyReport($conn, $year, $month, $format);
            break;

        case 'export':
            exportReport($conn, $start_date, $end_date, $guide_id, $format);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid report type']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}

/**
 * Generate summary report for a date range
 */
function generateSummaryReport($conn, $start_date, $end_date, $format) {
    // Default to last 30 days if no dates provided
    if (!$start_date || !$end_date) {
        $end_date = date('Y-m-d');
        $start_date = date('Y-m-d', strtotime('-30 days'));
    }

    // Validate date format
    if (!validateDate($start_date) || !validateDate($end_date)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid date format. Use YYYY-MM-DD']);
        return;
    }

    $report = [
        'report_type' => 'Summary Report',
        'period' => ['start_date' => $start_date, 'end_date' => $end_date],
        'generated_at' => date('Y-m-d H:i:s')
    ];

    // Overall statistics for the period
    // Group-aware: count groups as 1 tour unit
    $stmt = $conn->prepare("SELECT
                                COUNT(DISTINCT pt.id) as total_transactions,
                                COUNT(DISTINCT IF(t.group_id IS NOT NULL, CONCAT('g', t.group_id), CONCAT('t', t.id))) as tours_with_payments,
                                COUNT(DISTINCT pt.guide_id) as guides_with_payments,
                                SUM(pt.amount) as total_amount,
                                AVG(pt.amount) as avg_payment,
                                MIN(pt.amount) as min_payment,
                                MAX(pt.amount) as max_payment
                            FROM payments pt
                            JOIN tours t ON pt.tour_id = t.id
                            WHERE pt.payment_date BETWEEN ? AND ?");

    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $overall_stats = $result->fetch_assoc();

    // Format numeric values
    foreach (['total_amount', 'avg_payment', 'min_payment', 'max_payment'] as $field) {
        $overall_stats[$field] = floatval($overall_stats[$field]);
    }

    $report['overall_statistics'] = $overall_stats;

    // Payment method breakdown
    $stmt = $conn->prepare("SELECT
                                payment_method,
                                COUNT(*) as transaction_count,
                                SUM(amount) as total_amount,
                                AVG(amount) as avg_amount
                            FROM payments
                            WHERE payment_date BETWEEN ? AND ?
                            GROUP BY payment_method
                            ORDER BY total_amount DESC");

    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();

    $payment_methods = [];
    while ($row = $result->fetch_assoc()) {
        $row['total_amount'] = floatval($row['total_amount']);
        $row['avg_amount'] = floatval($row['avg_amount']);
        $payment_methods[] = $row;
    }
    $report['payment_methods'] = $payment_methods;

    // Guide performance for the period
    // Group-aware: count groups as 1 tour unit
    $stmt = $conn->prepare("SELECT
                                g.id,
                                g.name,
                                g.email,
                                COUNT(DISTINCT pt.id) as payment_count,
                                COUNT(DISTINCT IF(t.group_id IS NOT NULL, CONCAT('g', t.group_id), CONCAT('t', t.id))) as tours_paid,
                                SUM(pt.amount) as total_earned,
                                AVG(pt.amount) as avg_payment
                            FROM guides g
                            JOIN payments pt ON g.id = pt.guide_id
                            JOIN tours t ON pt.tour_id = t.id
                            WHERE pt.payment_date BETWEEN ? AND ?
                            GROUP BY g.id, g.name, g.email
                            ORDER BY total_earned DESC");

    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();

    $guide_performance = [];
    while ($row = $result->fetch_assoc()) {
        $row['total_earned'] = floatval($row['total_earned']);
        $row['avg_payment'] = floatval($row['avg_payment']);
        $guide_performance[] = $row;
    }
    $report['guide_performance'] = $guide_performance;

    // Daily breakdown
    $stmt = $conn->prepare("SELECT
                                DATE(payment_date) as date,
                                COUNT(*) as transaction_count,
                                SUM(amount) as daily_total
                            FROM payments
                            WHERE payment_date BETWEEN ? AND ?
                            GROUP BY DATE(payment_date)
                            ORDER BY date");

    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();

    $daily_breakdown = [];
    while ($row = $result->fetch_assoc()) {
        $row['daily_total'] = floatval($row['daily_total']);
        $daily_breakdown[] = $row;
    }
    $report['daily_breakdown'] = $daily_breakdown;

    echo json_encode(['success' => true, 'data' => $report]);
}

/**
 * Generate detailed report with all payment transactions
 */
function generateDetailedReport($conn, $start_date, $end_date, $guide_id, $format) {
    // Default to last 30 days if no dates provided
    if (!$start_date || !$end_date) {
        $end_date = date('Y-m-d');
        $start_date = date('Y-m-d', strtotime('-30 days'));
    }

    // Validate date format
    if (!validateDate($start_date) || !validateDate($end_date)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid date format. Use YYYY-MM-DD']);
        return;
    }

    $report = [
        'report_type' => 'Detailed Payment Report',
        'period' => ['start_date' => $start_date, 'end_date' => $end_date],
        'guide_filter' => $guide_id,
        'generated_at' => date('Y-m-d H:i:s')
    ];

    // Build query with optional guide filter
    $sql = "SELECT
                pt.id,
                pt.tour_id,
                pt.amount,
                pt.payment_method,
                pt.payment_date,
                pt.payment_time,
                pt.transaction_reference,
                pt.notes,
                pt.created_at,
                g.name as guide_name,
                g.email as guide_email,
                t.title as tour_title,
                t.date as tour_date,
                t.time as tour_time,
                t.customer_name,
                t.payment_status as tour_payment_status
            FROM payments pt
            JOIN guides g ON pt.guide_id = g.id
            JOIN tours t ON pt.tour_id = t.id
            WHERE pt.payment_date BETWEEN ? AND ?";

    $params = [$start_date, $end_date];
    $types = "ss";

    if ($guide_id) {
        $sql .= " AND pt.guide_id = ?";
        $params[] = $guide_id;
        $types .= "i";
    }

    $sql .= " ORDER BY pt.payment_date DESC, pt.created_at DESC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $transactions = [];
    $total_amount = 0;

    while ($row = $result->fetch_assoc()) {
        $row['amount'] = floatval($row['amount']);
        $total_amount += $row['amount'];
        $transactions[] = $row;
    }

    $report['summary'] = [
        'total_transactions' => count($transactions),
        'total_amount' => $total_amount
    ];
    $report['transactions'] = $transactions;

    echo json_encode(['success' => true, 'data' => $report]);
}

/**
 * Generate monthly report
 */
function generateMonthlyReport($conn, $year, $month, $format) {
    if (!$year || !$month) {
        $year = date('Y');
        $month = date('n');
    }

    $start_date = sprintf('%04d-%02d-01', $year, $month);
    $end_date = date('Y-m-t', strtotime($start_date));

    $report = [
        'report_type' => 'Monthly Payment Report',
        'year' => $year,
        'month' => $month,
        'month_name' => date('F', mktime(0, 0, 0, $month, 1, $year)),
        'period' => ['start_date' => $start_date, 'end_date' => $end_date],
        'generated_at' => date('Y-m-d H:i:s')
    ];

    // Use the existing monthly_payment_summary view
    $stmt = $conn->prepare("SELECT * FROM monthly_payment_summary
                            WHERE payment_year = ? AND payment_month = ?
                            ORDER BY guide_name");

    $stmt->bind_param("ii", $year, $month);
    $stmt->execute();
    $result = $stmt->get_result();

    $monthly_data = [];
    while ($row = $result->fetch_assoc()) {
        $row['total_amount'] = floatval($row['total_amount']);
        $row['cash_amount'] = floatval($row['cash_amount']);
        $row['bank_amount'] = floatval($row['bank_amount']);
        $row['avg_payment_amount'] = floatval($row['avg_payment_amount']);
        $monthly_data[] = $row;
    }

    $report['monthly_data'] = $monthly_data;

    echo json_encode(['success' => true, 'data' => $report]);
}

/**
 * Export report as text file
 */
function exportReport($conn, $start_date, $end_date, $guide_id, $format) {
    // Default to last 30 days if no dates provided
    if (!$start_date || !$end_date) {
        $end_date = date('Y-m-d');
        $start_date = date('Y-m-d', strtotime('-30 days'));
    }

    if ($format === 'txt') {
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="payment-report-' . $start_date . '-to-' . $end_date . '.txt"');

        echo "FLORENCE TOURS - PAYMENT REPORT\n";
        echo "===============================\n\n";
        echo "Report Period: " . $start_date . " to " . $end_date . "\n";
        echo "Generated: " . date('Y-m-d H:i:s') . "\n\n";

        // Get overall statistics
        $stmt = $conn->prepare("SELECT
                                    COUNT(DISTINCT pt.id) as total_transactions,
                                    COUNT(DISTINCT pt.guide_id) as guides_with_payments,
                                    SUM(pt.amount) as total_amount
                                FROM payments pt
                                WHERE pt.payment_date BETWEEN ? AND ?");

        $stmt->bind_param("ss", $start_date, $end_date);
        $stmt->execute();
        $result = $stmt->get_result();
        $stats = $result->fetch_assoc();

        echo "SUMMARY\n";
        echo "-------\n";
        echo "Total Transactions: " . $stats['total_transactions'] . "\n";
        echo "Guides with Payments: " . $stats['guides_with_payments'] . "\n";
        echo "Total Amount: €" . number_format($stats['total_amount'], 2) . "\n\n";

        // Get detailed transactions
        $sql = "SELECT
                    pt.payment_date,
                    pt.amount,
                    pt.payment_method,
                    g.name as guide_name,
                    t.title as tour_title,
                    t.customer_name
                FROM payments pt
                JOIN guides g ON pt.guide_id = g.id
                JOIN tours t ON pt.tour_id = t.id
                WHERE pt.payment_date BETWEEN ? AND ?";

        $params = [$start_date, $end_date];
        $types = "ss";

        if ($guide_id) {
            $sql .= " AND pt.guide_id = ?";
            $params[] = $guide_id;
            $types .= "i";
            echo "FILTERED BY GUIDE ID: " . $guide_id . "\n\n";
        }

        $sql .= " ORDER BY pt.payment_date DESC, g.name";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        echo "DETAILED TRANSACTIONS\n";
        echo "--------------------\n";
        echo sprintf("%-12s %-10s %-15s %-20s %-30s %-20s\n",
            "Date", "Amount", "Method", "Guide", "Tour", "Customer");
        echo str_repeat("-", 120) . "\n";

        while ($row = $result->fetch_assoc()) {
            echo sprintf("%-12s €%-9s %-15s %-20s %-30s %-20s\n",
                $row['payment_date'],
                number_format($row['amount'], 2),
                ucfirst(str_replace('_', ' ', $row['payment_method'])),
                substr($row['guide_name'], 0, 19),
                substr($row['tour_title'], 0, 29),
                substr($row['customer_name'] ?: 'N/A', 0, 19)
            );
        }

        echo "\n\nREPORT END\n";
        echo "Generated by Florence Tours Payment System\n";

    } else {
        // For other formats, return JSON with export data
        generateDetailedReport($conn, $start_date, $end_date, $guide_id, 'json');
    }
}

/**
 * Validate date format
 */
function validateDate($date) {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

$conn->close();
?>