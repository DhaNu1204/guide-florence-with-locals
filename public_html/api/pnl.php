<?php
/**
 * Daily P&L (Profit & Loss) API — OWNER ONLY (step 6.10)
 *
 * Tracks daily operation economics per tour unit (group or standalone booking):
 *   Revenue  — auto-extracted from stored bokun_data (retail, channel commission, net)
 *   Costs    — auto-computed from pnl_settings rates (museum tickets per person,
 *              guide rate per tour category, radio per person, gelato per person)
 *              with per-unit manual overrides in pnl_tour_costs
 *   Profit   — net revenue − total costs
 *
 * Touches NO payment logic. Read-only over tours/groups; writes only to its own
 * self-provisioned tables (pnl_settings, pnl_tour_costs).
 *
 * Endpoints:
 *   GET  ?date=YYYY-MM-DD                  -> per-unit rows + day totals
 *   GET  ?start=YYYY-MM-DD&end=YYYY-MM-DD  -> per-day totals + grand totals (month view)
 *   GET  ?action=settings                  -> settings key/value map
 *   POST ?action=settings  {settings:{k:v}} -> upsert settings
 *   POST ?action=costs     {tour_unit, date, ...cost fields} -> upsert unit overrides
 */

require_once 'config.php';
require_once 'Middleware.php';
require_once 'tour_classification.php';
require_once __DIR__ . '/group_helpers.php'; // step 3.7: groupBucketKey()
require_once __DIR__ . '/pnl_links.php';     // step 6.2: merged costing units
require_once __DIR__ . '/manual_helpers.php'; // step 6.4: hand-entered departures
require_once __DIR__ . '/viator_helpers.php'; // step 6.9: the old-Viator-account label
require_once __DIR__ . '/lib/pnl_core.php';    // step 7.3: the P&L computation (shared with the assistant)

// Financial data: the owner only (step 6.10). Narrower than the admin role on purpose -
// revenue, costs and profit are not visible to any other account, admin or viewer.
Middleware::requirePnlOwner($conn);

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? trim($_GET['action']) : '';

// Step 7.3: the computation (pnlBuildRows, pnlTotals, helpers) lives in lib/pnl_core.php, unchanged,
// shared with the assistant's owner-only money tool.

// ---------------------------------------------------------------------------
// Routing
// ---------------------------------------------------------------------------
try {
    pnlEnsureTables($conn);

    if ($method === 'GET' && $action === 'settings') {
        applyRateLimit('read');
        echo json_encode(['success' => true, 'data' => pnlLoadSettings($conn)]);
        exit();
    }

    if ($method === 'POST' && $action === 'settings') {
        applyRateLimit('update');
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input) || !isset($input['settings']) || !is_array($input['settings'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing settings object']);
            exit();
        }
        $valid = pnlSettingKeys();
        $stmt = $conn->prepare("INSERT INTO pnl_settings (setting_key, setting_value) VALUES (?, ?)
                                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $saved = 0;
        foreach ($input['settings'] as $k => $v) {
            if (!in_array($k, $valid, true) || !is_numeric($v)) continue;
            $val = round(floatval($v), 2);
            $stmt->bind_param("sd", $k, $val);
            $stmt->execute();
            $saved++;
        }
        echo json_encode(['success' => true, 'saved' => $saved, 'data' => pnlLoadSettings($conn)]);
        exit();
    }

    // ---------------------------------------------------------------------------
    // Step 6.2: merged costing units. P&L ONLY - nothing here touches tours,
    // tour_groups, payments, guide reminders or the digest.
    // ---------------------------------------------------------------------------
    if ($method === 'GET' && $action === 'links') {
        applyRateLimit('read');
        $date = isset($_GET['date']) ? trim($_GET['date']) : null;
        $start = isset($_GET['start']) ? trim($_GET['start']) : $date;
        $end   = isset($_GET['end']) ? trim($_GET['end']) : $date;
        if (!$start || !$end || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            http_response_code(400);
            echo json_encode(['error' => 'date (or start and end) in YYYY-MM-DD is required']);
            exit();
        }
        $links = [];
        foreach (pnlLoadUnitLinks($conn, $start, $end) as $unit => $row) {
            $links[$row['link_key']]['link_key'] = $row['link_key'];
            $links[$row['link_key']]['date'] = $row['link_date'];
            $links[$row['link_key']]['units'][] = $unit;
        }
        echo json_encode(['success' => true, 'data' => array_values($links)]);
        exit();
    }

    if ($method === 'POST' && $action === 'link') {
        applyRateLimit('update');
        $input = json_decode(file_get_contents('php://input'), true);
        $date  = isset($input['date']) ? trim($input['date']) : '';
        $units = isset($input['units']) && is_array($input['units']) ? array_values(array_unique($input['units'])) : [];

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            http_response_code(400);
            echo json_encode(['error' => 'A date in YYYY-MM-DD is required']);
            exit();
        }
        if (count($units) < 2) {
            http_response_code(400);
            echo json_encode(['error' => 'Select at least two departures to merge']);
            exit();
        }
        foreach ($units as $u) {
            if (!is_string($u) || !preg_match('/^[gt]\d+$/', $u)) {
                http_response_code(400);
                echo json_encode(['error' => 'Only real departures can be merged']);
                exit();
            }
        }

        // Guard rail 1: every unit must exist AND be on that date - a link never crosses dates.
        $bucketKeys = [];
        foreach ($units as $u) {
            $id = intval(substr($u, 1));
            if ($u[0] === 'g') {
                $stmt = $conn->prepare("SELECT tg.group_date d, tg.bucket_key bk FROM tour_groups tg WHERE tg.id = ?");
            } else {
                $stmt = $conn->prepare("SELECT t.date d, CONCAT(t.product_id, '|', DATE_FORMAT(t.date, '%Y-%m-%d'), '|', DATE_FORMAT(t.time, '%H:%i')) bk
                                          FROM tours t WHERE t.id = ?");
            }
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$row) {
                http_response_code(400);
                echo json_encode(['error' => "Departure $u was not found"]);
                exit();
            }
            if (substr((string) $row['d'], 0, 10) !== $date) {
                http_response_code(400);
                echo json_encode(['error' => "Departure $u is not on $date - a merge only works within one day"]);
                exit();
            }
            $bucketKeys[$u] = $row['bk'];
        }

        // Guard rail 2: a departure belongs to at most one link.
        $placeholders = implode(',', array_fill(0, count($units), '?'));
        $check = $conn->prepare("SELECT tour_unit FROM pnl_unit_links WHERE tour_unit IN ($placeholders)");
        $check->bind_param(str_repeat('s', count($units)), ...$units);
        $check->execute();
        $taken = [];
        $res = $check->get_result();
        while ($r = $res->fetch_assoc()) { $taken[] = $r['tour_unit']; }
        $check->close();
        if ($taken) {
            http_response_code(409);
            echo json_encode(['error' => 'Already merged: ' . implode(', ', $taken) . '. Unmerge it first.']);
            exit();
        }

        $userId = isset($GLOBALS['auth_user']['id']) ? intval($GLOBALS['auth_user']['id']) : null;
        $conn->begin_transaction();
        try {
            $ins = $conn->prepare("INSERT INTO pnl_unit_links (link_key, link_date, tour_unit, bucket_key, created_by)
                                   VALUES (?, ?, ?, ?, ?)");
            $pending = 'pending';
            $firstId = null;
            foreach ($units as $u) {
                $bk = $bucketKeys[$u];
                $ins->bind_param("ssssi", $pending, $date, $u, $bk, $userId);
                $ins->execute();
                if ($firstId === null) { $firstId = $conn->insert_id; }
            }
            $ins->close();
            // The merged unit's own key, usable as a pnl_tour_costs.tour_unit ('m<id>').
            $linkKey = 'm' . $firstId;
            $upd = $conn->prepare("UPDATE pnl_unit_links SET link_key = ? WHERE link_key = 'pending' AND link_date = ? AND tour_unit IN ($placeholders)");
            $args = array_merge([$linkKey, $date], $units);
            $upd->bind_param(str_repeat('s', count($args)), ...$args);
            $upd->execute();
            $upd->close();
            $conn->commit();
        } catch (Exception $e) {
            $conn->rollback();
            error_log('pnl link failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Could not merge those departures']);
            exit();
        }

        echo json_encode(['success' => true, 'data' => ['link_key' => $linkKey, 'date' => $date, 'units' => $units]]);
        exit();
    }

    if ($method === 'POST' && $action === 'unlink') {
        applyRateLimit('update');
        $input = json_decode(file_get_contents('php://input'), true);
        $linkKey = isset($input['link_key']) ? trim($input['link_key']) : '';
        if (!preg_match('/^m\d+$/', $linkKey)) {
            http_response_code(400);
            echo json_encode(['error' => 'A link_key is required']);
            exit();
        }
        // Deleting a link touches nothing else: the members' own overrides and every payment stay.
        $del = $conn->prepare("DELETE FROM pnl_unit_links WHERE link_key = ?");
        $del->bind_param("s", $linkKey);
        $del->execute();
        $removed = $del->affected_rows;
        $del->close();
        if ($removed === 0) {
            http_response_code(404);
            echo json_encode(['error' => 'That merge does not exist']);
            exit();
        }
        echo json_encode(['success' => true, 'data' => ['link_key' => $linkKey, 'units_released' => $removed]]);
        exit();
    }

    if ($method === 'POST' && $action === 'costs') {
        applyRateLimit('update');
        $input = json_decode(file_get_contents('php://input'), true);
        $unit = isset($input['tour_unit']) ? trim($input['tour_unit']) : '';
        $date = isset($input['date']) ? trim($input['date']) : '';
        // Step 6.2: 'm<id>' is a merged costing unit (pnl_unit_links); an override on it wins
        // over the members' own overrides.
        if (!preg_match('/^[gtm]\d+$/', $unit) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid tour_unit or date']);
            exit();
        }

        $fields = ['ticket_cost', 'guide_cost', 'radio_cost', 'gelato_cost',
                   'staff_cost', 'other_cost', 'revenue_override', 'outsourced'];
        $cols = []; $vals = []; $updates = [];
        foreach ($fields as $f) {
            // array_key_exists (NOT isset): present-but-null = clear override
            if (is_array($input) && array_key_exists($f, $input)) {
                $v = $input[$f];
                if ($v !== null && !is_numeric($v)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid value for ' . $f]);
                    exit();
                }
                $cols[] = $f;
                if ($f === 'outsourced') {
                    $vals[$f] = $v ? 1 : 0; // flag column, never NULL
                } else {
                    $vals[$f] = $v === null ? null : round(floatval($v), 2);
                }
            }
        }
        $notesProvided = is_array($input) && array_key_exists('notes', $input);
        $notes = $notesProvided ? ($input['notes'] === null ? null : mb_substr(trim($input['notes']), 0, 500)) : null;

        if (count($cols) === 0 && !$notesProvided) {
            http_response_code(400);
            echo json_encode(['error' => 'No fields to update']);
            exit();
        }

        // Step 3.7: stamp the departure's natural key beside the unit so the override is still
        // findable if the group's surrogate id ever changes.
        $bucketKey = pnlBucketKeyForUnit($conn, $unit);

        // Build upsert dynamically (values bound as strings; MySQL casts to DECIMAL)
        $allCols = array_merge(['tour_unit', 'date', 'bucket_key'], $cols, $notesProvided ? ['notes'] : []);
        $placeholders = implode(', ', array_fill(0, count($allCols), '?'));
        $updateParts = ['bucket_key = VALUES(bucket_key)'];
        foreach (array_merge($cols, $notesProvided ? ['notes'] : []) as $c) {
            $updateParts[] = "$c = VALUES($c)";
        }
        $sql = "INSERT INTO pnl_tour_costs (" . implode(', ', $allCols) . ")
                VALUES ($placeholders)
                ON DUPLICATE KEY UPDATE " . implode(', ', $updateParts);
        $stmt = $conn->prepare($sql);

        $bindVals = [$unit, $date, $bucketKey];
        foreach ($cols as $c) $bindVals[] = $vals[$c] === null ? null : (string)$vals[$c];
        if ($notesProvided) $bindVals[] = $notes;
        $types = str_repeat('s', count($bindVals));
        $stmt->bind_param($types, ...$bindVals);
        $stmt->execute();

        echo json_encode(['success' => true]);
        exit();
    }

    if ($method === 'GET') {
        applyRateLimit('read');
        $settings = pnlLoadSettings($conn);

        $date  = isset($_GET['date']) ? trim($_GET['date']) : null;
        $start = isset($_GET['start']) ? trim($_GET['start']) : null;
        $end   = isset($_GET['end']) ? trim($_GET['end']) : null;

        if ($date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            // Single-day detail view
            $rows = pnlBuildRows($conn, $date, $date, $settings);
            echo json_encode([
                'success' => true,
                'data' => [
                    'date'     => $date,
                    'rows'     => $rows,
                    'totals'   => pnlTotals($rows),
                    'settings' => $settings
                ]
            ]);
            exit();
        }

        if ($start && $end
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)
            && $start <= $end) {
            // Range summary view (e.g. a month) — cap at 92 days
            $days = (strtotime($end) - strtotime($start)) / 86400;
            if ($days > 92) {
                http_response_code(400);
                echo json_encode(['error' => 'Date range too large (max 92 days)']);
                exit();
            }
            $rows = pnlBuildRows($conn, $start, $end, $settings);
            $byDay = [];
            foreach ($rows as $r) {
                $byDay[$r['date']][] = $r;
            }
            $dayTotals = [];
            foreach ($byDay as $d => $dayRows) {
                $t = pnlTotals($dayRows);
                $t['date'] = $d;
                $dayTotals[] = $t;
            }
            usort($dayTotals, function ($a, $b) { return strcmp($a['date'], $b['date']); });

            $monthlyOverhead = round(
                $settings['staff_monthly'] + $settings['office_monthly'] + $settings['other_monthly'], 2
            );
            $grand = pnlTotals($rows);

            // Profit by product line (guided categories + Tickets bucket)
            $byCat = [];
            foreach ($rows as $r) {
                if ($r['bookings'] === 0) continue;
                $cat = $r['is_ticket'] ? 'Tickets' : $r['category'];
                if (!isset($byCat[$cat])) {
                    $byCat[$cat] = ['category' => $cat, 'units' => 0, 'pax' => 0,
                                    'net' => 0.0, 'cost' => 0.0, 'profit' => 0.0];
                }
                $byCat[$cat]['units']++;
                $byCat[$cat]['pax']    += $r['pax']['total'];
                $byCat[$cat]['net']    += $r['revenue']['net'];
                $byCat[$cat]['cost']   += $r['costs']['total'];
                $byCat[$cat]['profit'] += $r['profit'];
            }
            $catOrder = ['Combo', 'Uffizi', 'Accademia', 'Pitti', 'Borghese', 'Mixed', 'Other', 'Tickets'];
            $byCategory = [];
            foreach ($catOrder as $c) {
                if (isset($byCat[$c])) {
                    foreach (['net', 'cost', 'profit'] as $f) {
                        $byCat[$c][$f] = round($byCat[$c][$f], 2);
                    }
                    $byCategory[] = $byCat[$c];
                }
            }
            echo json_encode([
                'success' => true,
                'data' => [
                    'start'                 => $start,
                    'end'                   => $end,
                    'days'                  => $dayTotals,
                    'totals'                => $grand,
                    'by_category'           => $byCategory,
                    'monthly_overhead'      => $monthlyOverhead,
                    'profit_after_overhead' => round($grand['profit'] - $monthlyOverhead, 2),
                    'settings'              => $settings
                ]
            ]);
            exit();
        }

        http_response_code(400);
        echo json_encode(['error' => 'Provide date=YYYY-MM-DD or start & end']);
        exit();
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
} catch (Exception $e) {
    http_response_code(500);
    error_log("PnL API error: " . $e->getMessage());
    echo json_encode(['error' => 'An internal error occurred']);
}

$conn->close();
