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

// Apply rate limiting (read operations)
applyRateLimit('read');

// Compute current Rome datetime once for all queries
// Tours become "pending payment" as soon as their tour time has passed
$romeNow = (new DateTime('now', new DateTimeZone('Europe/Rome')))->format('Y-m-d H:i:s');

// Handle request based on parameters
$guide_id = isset($_GET['guide_id']) ? intval($_GET['guide_id']) : null;
$period = isset($_GET['period']) ? $_GET['period'] : null;
$action = isset($_GET['action']) ? $_GET['action'] : null;

try {
    if ($action === 'overview') {
        getPaymentOverview($conn);
    } elseif ($action === 'pending_tours') {
        getPendingTours($conn);
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
 * Fixed: unpaid_tours now correctly counts PAST tours with guide assigned but NO payment recorded
 */
function getAllGuidePaymentSummaries($conn) {
    global $romeNow;
    // Get base summary from view
    $sql = "SELECT * FROM guide_payment_summary ORDER BY total_payments_received DESC, guide_name";

    $result = $conn->query($sql);

    if ($result) {
        $summaries = [];
        while ($row = $result->fetch_assoc()) {
            // Format monetary values
            $row['total_payments_received'] = floatval($row['total_payments_received']);
            $row['cash_payments'] = floatval($row['cash_payments']);
            $row['bank_payments'] = floatval($row['bank_payments']);

            // Group-aware unpaid_tours: count groups as 1 tour unit
            // A tour unit is unpaid if NO tour in it has a payment for the guide
            $guide_id = intval($row['guide_id']);
            $unpaidQuery = $conn->prepare("
                SELECT COUNT(*) as unpaid_count FROM (
                    SELECT IF(t.group_id IS NOT NULL, CONCAT('g', t.group_id), CONCAT('t', t.id)) as tour_unit
                    FROM tours t
                    LEFT JOIN payments p ON p.tour_id = t.id AND p.guide_id = t.guide_id
                    WHERE t.guide_id = ?
                      AND CONCAT(t.date, ' ', COALESCE(t.time, '00:00:00')) < ?
                      AND t.cancelled = 0
                      AND t.title NOT LIKE '%Entry Ticket%'
                      AND t.title NOT LIKE '%Entrance Ticket%'
                      AND t.title NOT LIKE '%Priority Ticket%'
                      AND t.title NOT LIKE '%Skip the Line%'
                      AND t.title NOT LIKE '%Skip-the-Line%'
                    GROUP BY tour_unit
                    HAVING MAX(p.id) IS NULL
                ) unpaid_units
            ");
            $unpaidQuery->bind_param("is", $guide_id, $romeNow);
            $unpaidQuery->execute();
            $unpaidResult = $unpaidQuery->get_result();
            $unpaidRow = $unpaidResult->fetch_assoc();
            $row['unpaid_tours'] = intval($unpaidRow['unpaid_count']);

            // Group-aware paid_tours: count groups as 1 tour unit
            $paidQuery = $conn->prepare("
                SELECT COUNT(*) as paid_count FROM (
                    SELECT IF(t.group_id IS NOT NULL, CONCAT('g', t.group_id), CONCAT('t', t.id)) as tour_unit
                    FROM tours t
                    INNER JOIN payments p ON p.tour_id = t.id AND p.guide_id = t.guide_id
                    WHERE t.guide_id = ?
                      AND CONCAT(t.date, ' ', COALESCE(t.time, '00:00:00')) < ?
                      AND t.cancelled = 0
                      AND t.title NOT LIKE '%Entry Ticket%'
                      AND t.title NOT LIKE '%Entrance Ticket%'
                      AND t.title NOT LIKE '%Priority Ticket%'
                      AND t.title NOT LIKE '%Skip the Line%'
                      AND t.title NOT LIKE '%Skip-the-Line%'
                    GROUP BY tour_unit
                ) paid_units
            ");
            $paidQuery->bind_param("is", $guide_id, $romeNow);
            $paidQuery->execute();
            $paidResult = $paidQuery->get_result();
            $paidRow = $paidResult->fetch_assoc();
            $row['paid_tours'] = intval($paidRow['paid_count']);

            // Total tours = unpaid + paid (past completed tours only, excluding tickets)
            $row['total_tours'] = $row['unpaid_tours'] + $row['paid_tours'];

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
    global $romeNow;
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

    // Override summary counts with group-aware values (1 group = 1 tour unit)
    $unpaidQuery = $conn->prepare("
        SELECT COUNT(*) as cnt FROM (
            SELECT IF(t.group_id IS NOT NULL, CONCAT('g', t.group_id), CONCAT('t', t.id)) as tour_unit
            FROM tours t
            LEFT JOIN payments p ON p.tour_id = t.id AND p.guide_id = t.guide_id
            WHERE t.guide_id = ?
              AND CONCAT(t.date, ' ', COALESCE(t.time, '00:00:00')) < ?
              AND t.cancelled = 0
              AND t.title NOT LIKE '%Entry Ticket%'
              AND t.title NOT LIKE '%Entrance Ticket%'
              AND t.title NOT LIKE '%Priority Ticket%'
              AND t.title NOT LIKE '%Skip the Line%'
              AND t.title NOT LIKE '%Skip-the-Line%'
            GROUP BY tour_unit
            HAVING MAX(p.id) IS NULL
        ) u
    ");
    $unpaidQuery->bind_param("is", $guide_id, $romeNow);
    $unpaidQuery->execute();
    $guide_summary['unpaid_tours'] = intval($unpaidQuery->get_result()->fetch_assoc()['cnt']);

    $paidQuery = $conn->prepare("
        SELECT COUNT(*) as cnt FROM (
            SELECT IF(t.group_id IS NOT NULL, CONCAT('g', t.group_id), CONCAT('t', t.id)) as tour_unit
            FROM tours t
            INNER JOIN payments p ON p.tour_id = t.id AND p.guide_id = t.guide_id
            WHERE t.guide_id = ?
              AND CONCAT(t.date, ' ', COALESCE(t.time, '00:00:00')) < ?
              AND t.cancelled = 0
              AND t.title NOT LIKE '%Entry Ticket%'
              AND t.title NOT LIKE '%Entrance Ticket%'
              AND t.title NOT LIKE '%Priority Ticket%'
              AND t.title NOT LIKE '%Skip the Line%'
              AND t.title NOT LIKE '%Skip-the-Line%'
            GROUP BY tour_unit
        ) p
    ");
    $paidQuery->bind_param("is", $guide_id, $romeNow);
    $paidQuery->execute();
    $guide_summary['paid_tours'] = intval($paidQuery->get_result()->fetch_assoc()['cnt']);
    $guide_summary['total_tours'] = $guide_summary['unpaid_tours'] + $guide_summary['paid_tours'];

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
    // Group-aware: count groups as 1 tour unit
    $stmt = $conn->prepare("SELECT
                                YEAR(pt.payment_date) as year,
                                MONTH(pt.payment_date) as month,
                                MONTHNAME(pt.payment_date) as month_name,
                                COUNT(pt.id) as payment_count,
                                COUNT(DISTINCT IF(t.group_id IS NOT NULL, CONCAT('g', t.group_id), CONCAT('t', t.id))) as tours_paid,
                                SUM(pt.amount) as total_amount,
                                SUM(CASE WHEN pt.payment_method = 'cash' THEN pt.amount ELSE 0 END) as cash_amount,
                                SUM(CASE WHEN pt.payment_method = 'bank_transfer' THEN pt.amount ELSE 0 END) as bank_amount,
                                AVG(pt.amount) as avg_payment
                            FROM payments pt
                            JOIN tours t ON pt.tour_id = t.id
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

    // Get unpaid tours for this guide — group-aware (1 group = 1 row)
    // Uses the same time-based "completed" check and NOT EXISTS pattern as getPendingTours
    $romeNowEsc = $conn->real_escape_string($romeNow);
    $stmt = $conn->prepare("SELECT
                                t.id,
                                t.title,
                                t.date,
                                t.time,
                                t.customer_name,
                                t.participants,
                                t.expected_amount,
                                t.group_id
                            FROM tours t
                            WHERE t.guide_id = ?
                              AND CONCAT(t.date, ' ', COALESCE(t.time, '00:00:00')) < '$romeNowEsc'
                              AND t.cancelled = 0
                              AND t.title NOT LIKE '%Entry Ticket%'
                              AND t.title NOT LIKE '%Entrance Ticket%'
                              AND t.title NOT LIKE '%Priority Ticket%'
                              AND t.title NOT LIKE '%Skip the Line%'
                              AND t.title NOT LIKE '%Skip-the-Line%'
                              AND NOT EXISTS (
                                  SELECT 1 FROM payments p
                                  WHERE p.tour_id = t.id AND p.guide_id = t.guide_id
                              )
                            ORDER BY t.date DESC");

    $stmt->bind_param("i", $guide_id);
    $stmt->execute();
    $unpaid_result = $stmt->get_result();

    $ungrouped_unpaid = [];
    $grouped_unpaid = []; // group_id => [tours]

    while ($row = $unpaid_result->fetch_assoc()) {
        $row['participants'] = intval($row['participants'] ?? 0);
        $row['expected_amount'] = $row['expected_amount'] ? floatval($row['expected_amount']) : null;

        if ($row['group_id']) {
            $gid = intval($row['group_id']);
            if (!isset($grouped_unpaid[$gid])) {
                $grouped_unpaid[$gid] = [];
            }
            $grouped_unpaid[$gid][] = $row;
        } else {
            $row['is_group'] = false;
            $row['group_id'] = null;
            $ungrouped_unpaid[] = $row;
        }
    }

    // Collapse grouped tours into single entries
    $unpaid_tours = $ungrouped_unpaid;

    foreach ($grouped_unpaid as $gid => $groupTours) {
        // Check if any tour in this group already has a payment for this guide
        $checkStmt = $conn->prepare("
            SELECT COUNT(*) as cnt FROM payments p
            INNER JOIN tours t ON p.tour_id = t.id
            WHERE t.group_id = ? AND p.guide_id = ?
        ");
        $checkStmt->bind_param("ii", $gid, $guide_id);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result()->fetch_assoc();

        if (intval($checkResult['cnt']) > 0) {
            continue; // Group already paid via another tour
        }

        // Get group info
        $groupStmt = $conn->prepare("SELECT id, display_name, group_date, group_time, total_pax FROM tour_groups WHERE id = ?");
        $groupStmt->bind_param("i", $gid);
        $groupStmt->execute();
        $groupInfo = $groupStmt->get_result()->fetch_assoc();

        $totalPax = array_sum(array_map(function($t) { return $t['participants']; }, $groupTours));
        $totalExpected = array_sum(array_map(function($t) { return floatval($t['expected_amount'] ?? 0); }, $groupTours));

        $unpaid_tours[] = [
            'id' => $groupTours[0]['id'],
            'title' => $groupInfo ? $groupInfo['display_name'] : $groupTours[0]['title'],
            'date' => $groupInfo ? $groupInfo['group_date'] : $groupTours[0]['date'],
            'time' => $groupInfo ? $groupInfo['group_time'] : $groupTours[0]['time'],
            'participants' => $groupInfo ? intval($groupInfo['total_pax']) : $totalPax,
            'expected_amount' => $totalExpected > 0 ? $totalExpected : null,
            'group_id' => $gid,
            'is_group' => true,
            'booking_count' => count($groupTours)
        ];
    }

    // Sort by date DESC
    usort($unpaid_tours, function($a, $b) {
        return strcmp($b['date'], $a['date']);
    });

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
    global $romeNow;
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

    // Tour status breakdown - based on actual payments table
    // Count tours that have payment records vs those that don't
    // Only count past tours with guides assigned (eligible for guide payment)
    $tour_statuses = [];

    // Group-aware: count groups as 1 tour unit (paid)
    // Compare date+time against Rome timezone for same-day detection
    $romeNowEsc = $conn->real_escape_string($romeNow);
    $result = $conn->query("SELECT COUNT(*) as count FROM (
                                SELECT IF(t.group_id IS NOT NULL, CONCAT('g', t.group_id), CONCAT('t', t.id)) as tour_unit
                                FROM tours t
                                INNER JOIN payments p ON p.tour_id = t.id
                                WHERE CONCAT(t.date, ' ', COALESCE(t.time, '00:00:00')) < '$romeNowEsc'
                                  AND t.cancelled = 0
                                  AND t.guide_id IS NOT NULL
                                  AND t.title NOT LIKE '%Entry Ticket%'
                                  AND t.title NOT LIKE '%Entrance Ticket%'
                                  AND t.title NOT LIKE '%Priority Ticket%'
                                  AND t.title NOT LIKE '%Skip the Line%'
                                  AND t.title NOT LIKE '%Skip-the-Line%'
                                GROUP BY tour_unit
                            ) paid_units");
    if ($result && $row = $result->fetch_assoc()) {
        $tour_statuses[] = ['status' => 'paid', 'count' => intval($row['count'])];
    }

    // Group-aware: count groups as 1 tour unit (unpaid)
    $result = $conn->query("SELECT COUNT(*) as count FROM (
                                SELECT IF(t.group_id IS NOT NULL, CONCAT('g', t.group_id), CONCAT('t', t.id)) as tour_unit
                                FROM tours t
                                LEFT JOIN payments p ON p.tour_id = t.id
                                WHERE CONCAT(t.date, ' ', COALESCE(t.time, '00:00:00')) < '$romeNowEsc'
                                  AND t.cancelled = 0
                                  AND t.guide_id IS NOT NULL
                                  AND t.title NOT LIKE '%Entry Ticket%'
                                  AND t.title NOT LIKE '%Entrance Ticket%'
                                  AND t.title NOT LIKE '%Priority Ticket%'
                                  AND t.title NOT LIKE '%Skip the Line%'
                                  AND t.title NOT LIKE '%Skip-the-Line%'
                                GROUP BY tour_unit
                                HAVING MAX(p.id) IS NULL
                            ) unpaid_units");
    if ($result && $row = $result->fetch_assoc()) {
        $tour_statuses[] = ['status' => 'unpaid', 'count' => intval($row['count'])];
    }

    // Group-aware: count groups as 1 tour unit (upcoming)
    $result = $conn->query("SELECT COUNT(*) as count FROM (
                                SELECT IF(t.group_id IS NOT NULL, CONCAT('g', t.group_id), CONCAT('t', t.id)) as tour_unit
                                FROM tours t
                                WHERE CONCAT(t.date, ' ', COALESCE(t.time, '00:00:00')) >= '$romeNowEsc'
                                  AND t.cancelled = 0
                                  AND t.title NOT LIKE '%Entry Ticket%'
                                  AND t.title NOT LIKE '%Entrance Ticket%'
                                  AND t.title NOT LIKE '%Priority Ticket%'
                                  AND t.title NOT LIKE '%Skip the Line%'
                                  AND t.title NOT LIKE '%Skip-the-Line%'
                                GROUP BY tour_unit
                            ) upcoming_units");
    if ($result && $row = $result->fetch_assoc()) {
        $tour_statuses[] = ['status' => 'upcoming', 'count' => intval($row['count'])];
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

/**
 * Get all pending (unpaid) tours across all guides
 * Returns past tours with assigned guides that have NO payment record in payments table
 */
function getPendingTours($conn) {
    global $romeNow;
    // Get all individual pending tours (with group_id for grouping)
    // Compare date+time against Rome timezone so tours show as pending
    // immediately after their scheduled time passes
    $romeNowEsc = $conn->real_escape_string($romeNow);
    $sql = "SELECT
                t.id,
                t.title,
                t.date,
                t.time,
                t.guide_id,
                g.name as guide_name,
                g.email as guide_email,
                t.customer_name,
                t.participants,
                t.expected_amount,
                t.payment_status,
                t.bokun_booking_id,
                t.group_id
            FROM tours t
            JOIN guides g ON t.guide_id = g.id
            WHERE CONCAT(t.date, ' ', COALESCE(t.time, '00:00:00')) < '$romeNowEsc'
              AND t.cancelled = 0
              AND t.title NOT LIKE '%Entry Ticket%'
              AND t.title NOT LIKE '%Entrance Ticket%'
              AND t.title NOT LIKE '%Priority Ticket%'
              AND t.title NOT LIKE '%Skip the Line%'
              AND t.title NOT LIKE '%Skip-the-Line%'
              AND NOT EXISTS (
                  SELECT 1 FROM payments p
                  WHERE p.tour_id = t.id AND p.guide_id = t.guide_id
              )
            ORDER BY t.date DESC";

    $result = $conn->query($sql);

    if ($result) {
        $ungrouped = [];
        $grouped = []; // group_id => [tours]

        while ($row = $result->fetch_assoc()) {
            $row['expected_amount'] = $row['expected_amount'] ? floatval($row['expected_amount']) : null;
            $row['participants'] = intval($row['participants'] ?? 0);

            if ($row['group_id']) {
                $gid = intval($row['group_id']);
                if (!isset($grouped[$gid])) {
                    $grouped[$gid] = [];
                }
                $grouped[$gid][] = $row;
            } else {
                $row['is_group'] = false;
                $ungrouped[] = $row;
            }
        }

        // Collapse grouped tours into single entries
        $tours = $ungrouped;

        foreach ($grouped as $gid => $groupTours) {
            // Check if any tour in this group has a payment for the guide
            // (another tour in the group might have been paid even though these weren't)
            $guideId = intval($groupTours[0]['guide_id']);
            $checkStmt = $conn->prepare("
                SELECT COUNT(*) as cnt FROM payments p
                INNER JOIN tours t ON p.tour_id = t.id
                WHERE t.group_id = ? AND p.guide_id = ?
            ");
            $checkStmt->bind_param("ii", $gid, $guideId);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result()->fetch_assoc();

            if (intval($checkResult['cnt']) > 0) {
                // Group already has a payment via another tour — skip
                continue;
            }

            // Get group info
            $groupStmt = $conn->prepare("SELECT id, display_name, group_date, group_time, total_pax FROM tour_groups WHERE id = ?");
            $groupStmt->bind_param("i", $gid);
            $groupStmt->execute();
            $groupInfo = $groupStmt->get_result()->fetch_assoc();

            // Aggregate tour data into a single group entry
            $totalPax = array_sum(array_map(function($t) { return $t['participants']; }, $groupTours));
            $totalExpected = array_sum(array_map(function($t) { return floatval($t['expected_amount'] ?? 0); }, $groupTours));
            $customers = array_filter(array_map(function($t) { return $t['customer_name']; }, $groupTours));

            $tours[] = [
                'id' => $groupTours[0]['id'],
                'title' => $groupInfo ? $groupInfo['display_name'] : $groupTours[0]['title'],
                'date' => $groupInfo ? $groupInfo['group_date'] : $groupTours[0]['date'],
                'time' => $groupInfo ? $groupInfo['group_time'] : $groupTours[0]['time'],
                'guide_id' => $groupTours[0]['guide_id'],
                'guide_name' => $groupTours[0]['guide_name'],
                'guide_email' => $groupTours[0]['guide_email'],
                'customer_name' => implode(', ', $customers),
                'participants' => $totalPax,
                'expected_amount' => $totalExpected > 0 ? $totalExpected : null,
                'payment_status' => 'unpaid',
                'bokun_booking_id' => null,
                'group_id' => $gid,
                'is_group' => true,
                'booking_count' => count($groupTours),
                'tours' => $groupTours
            ];
        }

        // Sort by date DESC
        usort($tours, function($a, $b) {
            return strcmp($b['date'], $a['date']);
        });

        echo json_encode([
            'success' => true,
            'data' => $tours,
            'count' => count($tours)
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to fetch pending tours: ' . $conn->error]);
    }
}

$conn->close();
?>