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
 * Month overview across all guides — group-aware unit count + category breakdown per guide.
 * Collapses each tour-group to one unit and classifies it by its MEMBER bookings'
 * titles (a group mixing categories counts as "Mixed"), via buildComposition().
 */
function getAllGuidesOverview($conn, $range, $period) {
    $sql = "SELECT
                t.guide_id,
                g.name AS guide_name,
                t.id,
                t.group_id,
                t.title,
                tg.display_name AS group_display_name
            FROM tours t
            JOIN guides g ON g.id = t.guide_id
            LEFT JOIN tour_groups tg ON tg.id = t.group_id
            WHERE t.date >= ? AND t.date <= ?
              AND t.cancelled = 0
              AND t.guide_id IS NOT NULL
              AND (NOT EXISTS (
                    SELECT 1 FROM products pr
                    WHERE pr.bokun_product_id = t.product_id AND pr.product_type = 'ticket'
                  ))
            ORDER BY g.name, t.date, t.time";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $range['start'], $range['end']);
    $stmt->execute();
    $result = $stmt->get_result();

    $byGuide = []; // guide_id => aggregate row (with internal _groups member-title map)

    while ($row = $result->fetch_assoc()) {
        $gid = intval($row['guide_id']);
        if (!isset($byGuide[$gid])) {
            $byGuide[$gid] = [
                'guide_id'    => $gid,
                'guide_name'  => $row['guide_name'],
                'total_tours' => 0,
                'by_category' => [
                    'Combo'     => 0,
                    'Uffizi'    => 0,
                    'Pitti'     => 0,
                    'Accademia' => 0,
                    'Other'     => 0,
                    'Mixed'     => 0
                ],
                '_groups'     => [] // group_id => list of member titles (this guide's)
            ];
        }

        if ($row['group_id']) {
            // Accumulate member titles; each group becomes exactly ONE unit below.
            $byGuide[$gid]['_groups'][intval($row['group_id'])][] = $row['title'];
        } else {
            $category = classifyTourCategory($row['title']);
            $byGuide[$gid]['by_category'][$category]++;
            $byGuide[$gid]['total_tours']++;
        }
    }

    // Resolve each group to one unit: single member category, or "Mixed" when
    // the members span more than one category. Then drop internal bookkeeping.
    $guides = [];
    foreach ($byGuide as $g) {
        foreach ($g['_groups'] as $memberTitles) {
            list($category, , ) = buildComposition($memberTitles);
            $g['by_category'][$category]++;
            $g['total_tours']++;
        }
        unset($g['_groups']);
        $guides[] = $g;
    }
    usort($guides, function ($a, $b) {
        return strcmp($a['guide_name'], $b['guide_name']);
    });

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

    $tours = [];        // representative rows (one per unit)
    $groupAccum = [];   // group_id => ['titles' => [...], 'date' => ..., 'time' => ..., 'title' => ...]

    // First pass: emit standalone rows directly; accumulate ALL member titles per
    // group so the group's category reflects its members, not the display title.
    while ($row = $result->fetch_assoc()) {
        if ($row['group_id']) {
            $gid = intval($row['group_id']);
            if (!isset($groupAccum[$gid])) {
                $groupAccum[$gid] = [
                    'titles' => [],
                    'date'   => $row['date'],
                    'time'   => $row['time'],
                    'title'  => $row['title']
                ];
            }
            $groupAccum[$gid]['titles'][] = $row['title'];
        } else {
            list($category, $composition, $label) = buildComposition([$row['title']]);
            $tours[] = [
                'date'              => $row['date'],
                'time'              => $row['time'],
                'title'             => $row['title'],
                'category'          => $category,
                'composition'       => $composition,
                'composition_label' => $label,
                'group_id'          => null
            ];
        }
    }

    // Second pass: one representative row per group, classified by its members.
    foreach ($groupAccum as $gid => $accum) {
        // Prefer the group's canonical date/time/title when available
        $groupStmt = $conn->prepare("SELECT display_name, group_date, group_time FROM tour_groups WHERE id = ?");
        $groupStmt->bind_param("i", $gid);
        $groupStmt->execute();
        $groupInfo = $groupStmt->get_result()->fetch_assoc();

        list($category, $composition, $label) = buildComposition($accum['titles']);
        $tours[] = [
            'date'              => $groupInfo && $groupInfo['group_date'] ? $groupInfo['group_date'] : $accum['date'],
            'time'              => $groupInfo && $groupInfo['group_time'] ? $groupInfo['group_time'] : $accum['time'],
            'title'             => $groupInfo && $groupInfo['display_name'] ? $groupInfo['display_name'] : $accum['title'],
            'category'          => $category,
            'composition'       => $composition,
            'composition_label' => $label,
            'group_id'          => $gid
        ];
    }

    // Sort representative rows by date, then time
    usort($tours, function ($a, $b) {
        $d = strcmp($a['date'], $b['date']);
        if ($d !== 0) return $d;
        return strcmp($a['time'] ?? '', $b['time'] ?? '');
    });

    // Category breakdown — always include all keys in this fixed order.
    // A Mixed group increments ONLY "Mixed" (not its member categories),
    // so the bucket sum still equals total_tours.
    $summary_by_category = [
        'Combo'     => 0,
        'Uffizi'    => 0,
        'Pitti'     => 0,
        'Accademia' => 0,
        'Other'     => 0,
        'Mixed'     => 0
    ];
    foreach ($tours as $t) {
        $summary_by_category[$t['category']]++;
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'guide_info'          => $guide_info,
            'period'              => $period,
            'range'               => $range,
            'total_tours'         => count($tours),
            'summary_by_category' => $summary_by_category,
            'tours'               => $tours
        ]
    ]);
}

/**
 * Classify a set of member titles into one unit category + composition.
 *
 * Returns [category, composition, composition_label]:
 *   - composition: ordered list of ['category' => ..., 'count' => ...] entries
 *     (fixed order Combo, Uffizi, Pitti, Accademia, Other; only present categories)
 *   - category: the single category when all members agree, else 'Mixed'
 *   - composition_label: 'Combo ×2, Uffizi ×1' for Mixed units, '' otherwise
 */
function buildComposition($titles) {
    $counts = [];
    foreach ($titles as $title) {
        $c = classifyTourCategory($title);
        $counts[$c] = isset($counts[$c]) ? $counts[$c] + 1 : 1;
    }

    $composition = [];
    foreach (['Combo', 'Uffizi', 'Pitti', 'Accademia', 'Other'] as $cat) {
        if (isset($counts[$cat])) {
            $composition[] = ['category' => $cat, 'count' => $counts[$cat]];
        }
    }

    if (count($composition) === 0) {
        return ['Other', [['category' => 'Other', 'count' => 0]], ''];
    }
    if (count($composition) === 1) {
        return [$composition[0]['category'], $composition, ''];
    }

    $parts = [];
    foreach ($composition as $entry) {
        $parts[] = $entry['category'] . ' ×' . $entry['count'];
    }
    return ['Mixed', $composition, implode(', ', $parts)];
}

/**
 * Classify a tour by its title (case-insensitive).
 *
 * Keyword rules (tweak here):
 *   uffizi    = title contains "uffizi"
 *   accademia = title contains "accademia" OR "david"
 *   pitti     = title contains "pitti" OR "boboli" OR "palatina" OR "palatine"
 *
 * Category:
 *   2+ of {uffizi, accademia, pitti} present -> "Combo"
 *   else uffizi    -> "Uffizi"
 *   else pitti     -> "Pitti"
 *   else accademia -> "Accademia"
 *   else           -> "Other"
 */
function classifyTourCategory($title) {
    $t = mb_strtolower($title ?? '');

    $uffizi    = (strpos($t, 'uffizi') !== false);
    $accademia = (strpos($t, 'accademia') !== false) || (strpos($t, 'david') !== false);
    $pitti     = (strpos($t, 'pitti') !== false)
                 || (strpos($t, 'boboli') !== false)
                 || (strpos($t, 'palatina') !== false)
                 || (strpos($t, 'palatine') !== false);

    $count = ($uffizi ? 1 : 0) + ($accademia ? 1 : 0) + ($pitti ? 1 : 0);

    if ($count >= 2) {
        return 'Combo';
    } elseif ($uffizi) {
        return 'Uffizi';
    } elseif ($pitti) {
        return 'Pitti';
    } elseif ($accademia) {
        return 'Accademia';
    }
    return 'Other';
}

$conn->close();
?>
