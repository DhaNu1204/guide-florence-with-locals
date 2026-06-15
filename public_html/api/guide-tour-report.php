<?php
/**
 * Guide Tour Report API (READ-ONLY)
 * Tour-verification report so the owner can check a guide's monthly invoice
 * against the tours they actually performed.
 *
 * This endpoint does NOT touch payments — it only counts/lists completed tours.
 * Counting is group-aware (1 tour-group = 1 unit) and excludes cancelled tours
 * and ticket products, matching the conventions in guide-payments.php.
 *
 * Endpoints:
 * GET /api/guide-tour-report.php?period=YYYY-MM
 *     -> month overview across all guides (guide_id, guide_name, total_tours)
 * GET /api/guide-tour-report.php?guide_id=X&period=YYYY-MM
 *     -> single-guide report with one representative row per tour unit
 * GET /api/guide-tour-report.php?guide_id=X&start=YYYY-MM-DD&end=YYYY-MM-DD
 *     -> single-guide report for a custom date range
 */

require_once 'config.php';
require_once 'Middleware.php';

// Require authentication for all report operations
Middleware::requireAuth($conn);

// Apply rate limiting (read operations)
applyRateLimit('read');

$guide_id = isset($_GET['guide_id']) ? intval($_GET['guide_id']) : null;
$period   = isset($_GET['period']) ? trim($_GET['period']) : null;
$start    = isset($_GET['start']) ? trim($_GET['start']) : null;
$end      = isset($_GET['end']) ? trim($_GET['end']) : null;

try {
    // Resolve the date range from either period (YYYY-MM) or start/end (YYYY-MM-DD)
    $range = resolveDateRange($period, $start, $end);
    if ($range === null) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid date parameters. Use period=YYYY-MM or start=YYYY-MM-DD&end=YYYY-MM-DD']);
        exit();
    }

    if ($guide_id) {
        getGuideReport($conn, $guide_id, $range, $period);
    } else {
        getAllGuidesOverview($conn, $range, $period);
    }
} catch (Exception $e) {
    http_response_code(500);
    error_log("Guide tour report error: " . $e->getMessage());
    echo json_encode(['error' => 'An internal error occurred']);
}

/**
 * Resolve a [start, end] date range (inclusive, YYYY-MM-DD).
 * Priority: explicit start+end > period > current month (fallback).
 * Returns ['start' => ..., 'end' => ...] or null on invalid input.
 */
function resolveDateRange($period, $start, $end) {
    // Custom range takes precedence when both provided
    if ($start && $end) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            return null;
        }
        if ($start > $end) {
            return null;
        }
        return ['start' => $start, 'end' => $end];
    }

    // Period (a calendar month)
    if ($period) {
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            return null;
        }
        $first = $period . '-01';
        $ts = strtotime($first);
        if ($ts === false) {
            return null;
        }
        return ['start' => $first, 'end' => date('Y-m-t', $ts)];
    }

    // Fallback: current month (Rome timezone)
    $now = new DateTime('now', new DateTimeZone('Europe/Rome'));
    return ['start' => $now->format('Y-m-01'), 'end' => $now->format('Y-m-t')];
}

/**
 * Month overview across all guides — group-aware unit count per guide.
 */
function getAllGuidesOverview($conn, $range, $period) {
    $sql = "SELECT
                t.guide_id,
                g.name AS guide_name,
                COUNT(DISTINCT IF(t.group_id IS NOT NULL, CONCAT('g', t.group_id), CONCAT('t', t.id))) AS total_tours
            FROM tours t
            JOIN guides g ON g.id = t.guide_id
            WHERE t.date >= ? AND t.date <= ?
              AND t.cancelled = 0
              AND t.guide_id IS NOT NULL
              AND (NOT EXISTS (
                    SELECT 1 FROM products pr
                    WHERE pr.bokun_product_id = t.product_id AND pr.product_type = 'ticket'
                  ))
            GROUP BY t.guide_id, g.name
            ORDER BY g.name";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $range['start'], $range['end']);
    $stmt->execute();
    $result = $stmt->get_result();

    $guides = [];
    while ($row = $result->fetch_assoc()) {
        $guides[] = [
            'guide_id'    => intval($row['guide_id']),
            'guide_name'  => $row['guide_name'],
            'total_tours' => intval($row['total_tours'])
        ];
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'period' => $period,
            'range'  => $range,
            'guides' => $guides
        ]
    ]);
}

/**
 * Single-guide report — one representative row per tour unit (group = 1 row).
 */
function getGuideReport($conn, $guide_id, $range, $period) {
    // Guide basic info
    $stmt = $conn->prepare("SELECT name, email FROM guides WHERE id = ?");
    $stmt->bind_param("i", $guide_id);
    $stmt->execute();
    $guide_result = $stmt->get_result();

    if (!$guide_result || !($guide_info = $guide_result->fetch_assoc())) {
        http_response_code(404);
        echo json_encode(['error' => 'Guide not found']);
        return;
    }

    // All completed (non-cancelled, non-ticket) tours for this guide in range
    $stmt = $conn->prepare("SELECT
                                t.id,
                                t.title,
                                t.date,
                                t.time,
                                t.group_id
                            FROM tours t
                            WHERE t.guide_id = ?
                              AND t.date >= ? AND t.date <= ?
                              AND t.cancelled = 0
                              AND (NOT EXISTS (
                                    SELECT 1 FROM products pr
                                    WHERE pr.bokun_product_id = t.product_id AND pr.product_type = 'ticket'
                                  ))
                            ORDER BY t.date, t.time");
    $stmt->bind_param("iss", $guide_id, $range['start'], $range['end']);
    $stmt->execute();
    $result = $stmt->get_result();

    $tours = [];          // representative rows (one per unit)
    $seenGroups = [];     // group_id => true (so each group contributes one row)

    while ($row = $result->fetch_assoc()) {
        if ($row['group_id']) {
            $gid = intval($row['group_id']);
            if (isset($seenGroups[$gid])) {
                continue; // already represented by an earlier row in this group
            }
            $seenGroups[$gid] = true;

            // Prefer the group's canonical date/time/title when available
            $groupStmt = $conn->prepare("SELECT display_name, group_date, group_time FROM tour_groups WHERE id = ?");
            $groupStmt->bind_param("i", $gid);
            $groupStmt->execute();
            $groupInfo = $groupStmt->get_result()->fetch_assoc();

            $tours[] = [
                'date'     => $groupInfo && $groupInfo['group_date'] ? $groupInfo['group_date'] : $row['date'],
                'time'     => $groupInfo && $groupInfo['group_time'] ? $groupInfo['group_time'] : $row['time'],
                'title'    => $groupInfo && $groupInfo['display_name'] ? $groupInfo['display_name'] : $row['title'],
                'group_id' => $gid
            ];
        } else {
            $tours[] = [
                'date'     => $row['date'],
                'time'     => $row['time'],
                'title'    => $row['title'],
                'group_id' => null
            ];
        }
    }

    // Sort representative rows by date, then time
    usort($tours, function ($a, $b) {
        $d = strcmp($a['date'], $b['date']);
        if ($d !== 0) return $d;
        return strcmp($a['time'] ?? '', $b['time'] ?? '');
    });

    echo json_encode([
        'success' => true,
        'data' => [
            'guide_info'  => $guide_info,
            'period'      => $period,
            'range'       => $range,
            'total_tours' => count($tours),
            'tours'       => $tours
        ]
    ]);
}

$conn->close();
?>
