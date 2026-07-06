<?php
/**
 * Daily P&L (Profit & Loss) API — ADMIN ONLY
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

// Financial data: admin only
Middleware::requireRole($conn, 'admin');

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? trim($_GET['action']) : '';

// ---------------------------------------------------------------------------
// Self-provision tables (same CREATE TABLE IF NOT EXISTS pattern as products /
// availability_requests / guide_reminders).
// ---------------------------------------------------------------------------
function pnlEnsureTables($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS pnl_settings (
        setting_key   VARCHAR(64) NOT NULL PRIMARY KEY,
        setting_value DECIMAL(10,2) NOT NULL DEFAULT 0,
        updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS pnl_tour_costs (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        tour_unit        VARCHAR(24) NOT NULL UNIQUE,
        date             DATE NOT NULL,
        ticket_cost      DECIMAL(10,2) NULL,
        guide_cost       DECIMAL(10,2) NULL,
        radio_cost       DECIMAL(10,2) NULL,
        gelato_cost      DECIMAL(10,2) NULL,
        staff_cost       DECIMAL(10,2) NULL,
        other_cost       DECIMAL(10,2) NULL,
        revenue_override DECIMAL(10,2) NULL,
        outsourced       TINYINT(1) NOT NULL DEFAULT 0,
        notes            VARCHAR(500) NULL,
        updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_pnl_costs_date (date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Column guard for installs created before the outsourced feature
    $res = $conn->query("SHOW COLUMNS FROM pnl_tour_costs LIKE 'outsourced'");
    if ($res && $res->num_rows === 0) {
        $conn->query("ALTER TABLE pnl_tour_costs ADD COLUMN outsourced TINYINT(1) NOT NULL DEFAULT 0 AFTER revenue_override");
    }
}

// Whitelisted setting keys (all numeric EUR amounts; comm_* are percentages).
function pnlSettingKeys() {
    return [
        // Guide pay per tour unit, by category
        'guide_rate_combo', 'guide_rate_uffizi', 'guide_rate_accademia',
        'guide_rate_pitti', 'guide_rate_other',
        // Museum ticket cost per person (what YOU pay the museum)
        'ticket_uffizi_adult', 'ticket_uffizi_child',
        'ticket_uffizi_adult_pm', 'ticket_uffizi_child_pm', // Uffizi entry from 16:00
        'ticket_accademia_adult', 'ticket_accademia_child',
        'ticket_pitti_adult', 'ticket_pitti_child',
        'ticket_borghese_adult', 'ticket_borghese_child',
        // Per-person extras
        'radio_per_person', 'gelato_per_person',
        // Flat fee paid when a booking is given to another agency
        'outsource_fee',
        // Monthly overheads (not per tour)
        'staff_monthly', 'office_monthly', 'other_monthly',
        // Fallback commission % when bokun_data has no invoice info
        'comm_getyourguide', 'comm_viator', 'comm_headout', 'comm_default'
    ];
}

function pnlDefaultSettings() {
    $defaults = array_fill_keys(pnlSettingKeys(), 0.0);
    // Business defaults (owner can change in Rates & Costs)
    $defaults['ticket_uffizi_adult']     = 29.0; // €25 + €4 advance reservation
    $defaults['ticket_uffizi_adult_pm']  = 20.0; // €16 + €4, entry from 16:00 (since 1 Jan 2026)
    $defaults['ticket_accademia_adult']  = 20.0; // €16 + €4 reservation
    $defaults['ticket_borghese_adult']   = 17.0;
    $defaults['ticket_borghese_child']   = 17.0; // no child ticket — adults' price applies
    $defaults['outsource_fee']           = 10.0;
    $defaults['comm_getyourguide'] = 30.0;
    $defaults['comm_viator']       = 30.0;
    $defaults['comm_headout']      = 25.0;
    $defaults['comm_default']      = 30.0;
    return $defaults;
}

function pnlLoadSettings($conn) {
    $settings = pnlDefaultSettings();
    $res = $conn->query("SELECT setting_key, setting_value FROM pnl_settings");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            if (array_key_exists($row['setting_key'], $settings)) {
                $settings[$row['setting_key']] = floatval($row['setting_value']);
            }
        }
    }
    return $settings;
}

// ---------------------------------------------------------------------------
// Classification helpers (same keyword rules as guide-tour-report.php; local
// copies because that file is an executing endpoint, not a library).
// ---------------------------------------------------------------------------
function pnlMuseumsInTitle($title) {
    $t = mb_strtolower($title ?? '');
    $museums = [];
    if (strpos($t, 'uffizi') !== false) $museums[] = 'uffizi';
    if (strpos($t, 'accademia') !== false || strpos($t, 'david') !== false) $museums[] = 'accademia';
    if (strpos($t, 'pitti') !== false || strpos($t, 'boboli') !== false
        || strpos($t, 'palatina') !== false || strpos($t, 'palatine') !== false) $museums[] = 'pitti';
    if (strpos($t, 'borghese') !== false) $museums[] = 'borghese';
    return $museums;
}

function pnlCategory($title) {
    $m = pnlMuseumsInTitle($title);
    if (count($m) >= 2) return 'Combo';
    if (in_array('uffizi', $m)) return 'Uffizi';
    if (in_array('pitti', $m)) return 'Pitti';
    if (in_array('accademia', $m)) return 'Accademia';
    if (in_array('borghese', $m)) return 'Borghese';
    return 'Other';
}

function pnlGuideRateForCategory($category, $settings) {
    switch ($category) {
        case 'Combo':     return $settings['guide_rate_combo'];
        case 'Uffizi':    return $settings['guide_rate_uffizi'];
        case 'Accademia': return $settings['guide_rate_accademia'];
        case 'Pitti':     return $settings['guide_rate_pitti'];
        default:          return $settings['guide_rate_other'];
    }
}

// ---------------------------------------------------------------------------
// Revenue extraction from stored bokun_data JSON.
// Bokun invoices can appear at several depths depending on which API path
// stored the booking (search vs detail vs webhook shape) — check them all.
// Returns [retail, commission, net, estimated(bool)].
// ---------------------------------------------------------------------------
function pnlExtractRevenue($bokunDataRaw, $channel, $fallbackAmount, $settings) {
    $b = null;
    if (is_string($bokunDataRaw) && $bokunDataRaw !== '') {
        $b = json_decode($bokunDataRaw, true);
    } elseif (is_array($bokunDataRaw)) {
        $b = $bokunDataRaw;
    }

    $candidates = [];
    if (is_array($b)) {
        $candidates[] = $b;
        foreach (['productBookings', 'activityBookings'] as $k) {
            if (isset($b[$k][0]) && is_array($b[$k][0])) {
                $candidates[] = $b[$k][0];
            }
        }
    }

    // 1) Best source: resellerInvoice (retail, commission, net all present)
    foreach ($candidates as $c) {
        if (isset($c['resellerInvoice']) && is_array($c['resellerInvoice'])) {
            $inv = $c['resellerInvoice'];
            $retail = isset($inv['total']) ? floatval($inv['total']) : null;
            $comm   = isset($inv['totalCommission']) ? floatval($inv['totalCommission']) : null;
            $net    = null;
            if (isset($inv['totalSansCommission'])) {
                $net = floatval($inv['totalSansCommission']);
            } elseif (isset($inv['totalDue'])) {
                $net = floatval($inv['totalDue']);
            }
            if ($retail !== null && ($net !== null || $comm !== null)) {
                if ($net === null)  $net  = $retail - $comm;
                if ($comm === null) $comm = $retail - $net;
                return [$retail, $comm, $net, false];
            }
        }
    }

    // 2) sellerCommission + customerInvoice/totalPrice
    $retail = null;
    foreach ($candidates as $c) {
        if ($retail === null && isset($c['customerInvoice']['total'])) {
            $retail = floatval($c['customerInvoice']['total']);
        }
        if ($retail === null && isset($c['totalPrice']) && floatval($c['totalPrice']) > 0) {
            $retail = floatval($c['totalPrice']);
        }
    }
    $comm = null;
    foreach ($candidates as $c) {
        if (isset($c['sellerCommission']) && floatval($c['sellerCommission']) > 0) {
            $comm = floatval($c['sellerCommission']);
            break;
        }
    }
    if ($retail !== null && $retail > 0 && $comm !== null) {
        return [$retail, $comm, $retail - $comm, false];
    }

    // 3) Fallback: retail from column + estimated commission % by channel
    if (($retail === null || $retail <= 0) && $fallbackAmount > 0) {
        $retail = floatval($fallbackAmount);
    }
    if ($retail === null || $retail <= 0) {
        return [0.0, 0.0, 0.0, true];
    }
    $ch = mb_strtolower(trim($channel ?? ''));
    if ($ch === '' || $ch === 'bokun' || strpos($ch, 'direct') !== false || strpos($ch, 'website') !== false) {
        // Direct sale — no OTA commission
        $pct = 0.0;
    } elseif (strpos($ch, 'getyourguide') !== false || strpos($ch, 'gyg') !== false) {
        $pct = $settings['comm_getyourguide'];
    } elseif (strpos($ch, 'viator') !== false || strpos($ch, 'tripadvisor') !== false) {
        $pct = $settings['comm_viator'];
    } elseif (strpos($ch, 'headout') !== false) {
        $pct = $settings['comm_headout'];
    } else {
        $pct = $settings['comm_default'];
    }
    $comm = round($retail * $pct / 100, 2);
    return [$retail, $comm, $retail - $comm, true];
}

// ---------------------------------------------------------------------------
// Core: build per-unit P&L rows for a date range.
// ---------------------------------------------------------------------------
function pnlBuildRows($conn, $start, $end, $settings) {
    $sql = "SELECT t.id, t.group_id, t.title, t.date, t.time, t.participants,
                   t.cancelled, t.booking_channel, t.total_amount_paid, t.bokun_data,
                   t.guide_id, g.name AS guide_name,
                   tg.display_name AS group_display_name, tg.group_time,
                   (CASE WHEN pr.product_type = 'ticket' THEN 1 ELSE 0 END) AS is_ticket_product
            FROM tours t
            LEFT JOIN guides g  ON g.id = t.guide_id
            LEFT JOIN tour_groups tg ON tg.id = t.group_id
            LEFT JOIN products pr ON pr.bokun_product_id = t.product_id
            WHERE t.date >= ? AND t.date <= ?
            ORDER BY t.date, t.time, t.id";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $start, $end);
    $stmt->execute();
    $result = $stmt->get_result();

    $units = []; // key => accumulator
    while ($row = $result->fetch_assoc()) {
        $key = $row['group_id'] ? ('g' . intval($row['group_id'])) : ('t' . intval($row['id']));
        if (!isset($units[$key])) {
            $units[$key] = [
                'unit'            => $key,
                'date'            => $row['date'],
                'time'            => $row['group_id'] && $row['group_time'] ? $row['group_time'] : $row['time'],
                'title'           => $row['group_id'] && $row['group_display_name'] ? $row['group_display_name'] : $row['title'],
                'is_group'        => $row['group_id'] ? true : false,
                'is_ticket'       => intval($row['is_ticket_product']) === 1,
                'guide_name'      => null,
                'channels'        => [],
                'titles'          => [],
                'bookings'        => 0,
                'cancelled'       => 0,
                'adults'          => 0,
                'children'        => 0,
                'infants'         => 0,
                'retail'          => 0.0,
                'commission'      => 0.0,
                'net'             => 0.0,
                'estimated'       => false,
                'ticket_cost_auto'=> 0.0,
                'has_gelato'      => false
            ];
        }
        $u = &$units[$key];

        if ($row['guide_name']) $u['guide_name'] = $row['guide_name'];

        if (intval($row['cancelled']) === 1) {
            $u['cancelled']++;
            unset($u);
            continue;
        }

        $u['bookings']++;
        $u['titles'][] = $row['title'];

        $ch = $row['booking_channel'] ?: 'Direct';
        if (!in_array($ch, $u['channels'])) $u['channels'][] = $ch;

        // PAX breakdown (adults/children/infants) from bokun_data
        $pax = computePaxBreakdown($row['bokun_data'], intval($row['participants']));
        $u['adults']   += $pax['adults'];
        $u['children'] += $pax['children'];
        $u['infants']  += $pax['infants'];

        // Revenue
        list($retail, $comm, $net, $estimated) = pnlExtractRevenue(
            $row['bokun_data'], $row['booking_channel'], floatval($row['total_amount_paid']), $settings
        );
        $u['retail']     += $retail;
        $u['commission'] += $comm;
        $u['net']        += $net;
        if ($estimated && $retail > 0) $u['estimated'] = true;

        // Museum ticket cost for THIS booking (per museum mentioned in ITS title).
        // Uffizi has a cheaper afternoon rate for entries from 16:00.
        foreach (pnlMuseumsInTitle($row['title']) as $museum) {
            $adultKey = 'ticket_' . $museum . '_adult';
            $childKey = 'ticket_' . $museum . '_child';
            if ($museum === 'uffizi' && substr((string)$row['time'], 0, 5) >= '16:00') {
                $adultKey = 'ticket_uffizi_adult_pm';
                $childKey = 'ticket_uffizi_child_pm';
            }
            $u['ticket_cost_auto'] +=
                $pax['adults']   * $settings[$adultKey] +
                $pax['children'] * $settings[$childKey];
        }
        if (strpos(mb_strtolower($row['title']), 'gelato') !== false) {
            $u['has_gelato'] = true;
        }
        unset($u);
    }

    // Load overrides for the range
    $overrides = [];
    $stmt = $conn->prepare("SELECT * FROM pnl_tour_costs WHERE date >= ? AND date <= ?");
    $stmt->bind_param("ss", $start, $end);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $overrides[$row['tour_unit']] = $row;
    }

    // Finalize rows
    $rows = [];
    foreach ($units as $key => $u) {
        $pax = $u['adults'] + $u['children'] + $u['infants'];
        if ($u['bookings'] === 0 && $u['cancelled'] === 0) continue;

        // Unit category from member titles (mirrors guide-tour-report logic)
        $cats = [];
        foreach ($u['titles'] as $ttl) {
            $c = pnlCategory($ttl);
            $cats[$c] = isset($cats[$c]) ? $cats[$c] + 1 : 1;
        }
        if (count($cats) === 0) {
            $category = 'Other';
        } elseif (count($cats) === 1) {
            $category = array_key_first($cats);
        } else {
            $category = 'Mixed';
        }

        // Overrides row (needed early: the outsourced flag changes auto costs)
        $ov = isset($overrides[$key]) ? $overrides[$key] : null;
        $isOutsourced = $ov !== null && intval($ov['outsourced']) === 1;

        // Auto costs
        $auto = [
            'ticket_cost' => round($u['ticket_cost_auto'], 2),
            'guide_cost'  => 0.0,
            'radio_cost'  => 0.0,
            'gelato_cost' => 0.0,
            'staff_cost'  => 0.0,
            'other_cost'  => 0.0
        ];
        if ($isOutsourced) {
            // Given to another agency: you pay the ticket + a flat handling fee.
            // No guide, no radio, no gelato from your side.
            $auto['other_cost'] = floatval($settings['outsource_fee']);
        } elseif (!$u['is_ticket'] && $u['bookings'] > 0) {
            if ($category === 'Mixed') {
                // A mixed merged group is one tour — pay the highest member rate
                $rate = 0.0;
                foreach (array_keys($cats) as $c) {
                    $rate = max($rate, floatval(pnlGuideRateForCategory($c, $settings)));
                }
                $auto['guide_cost'] = $rate;
            } else {
                $auto['guide_cost'] = floatval(pnlGuideRateForCategory($category, $settings));
            }
            $auto['radio_cost'] = round(($u['adults'] + $u['children']) * $settings['radio_per_person'], 2);
            if ($u['has_gelato']) {
                $auto['gelato_cost'] = round(($u['adults'] + $u['children']) * $settings['gelato_per_person'], 2);
            }
        }

        // Apply overrides (NULL column = keep auto value)
        $costs = [];
        $overriddenFields = [];
        foreach (['ticket_cost', 'guide_cost', 'radio_cost', 'gelato_cost', 'staff_cost', 'other_cost'] as $f) {
            if ($ov !== null && $ov[$f] !== null) {
                $costs[$f] = floatval($ov[$f]);
                $overriddenFields[] = $f;
            } else {
                $costs[$f] = $auto[$f];
            }
        }
        $totalCost = round(array_sum($costs), 2);

        $net = round($u['net'], 2);
        $revenueOverridden = false;
        if ($ov !== null && $ov['revenue_override'] !== null) {
            $net = floatval($ov['revenue_override']);
            $revenueOverridden = true;
        }

        $rows[] = [
            'unit'        => $key,
            'date'        => $u['date'],
            'time'        => $u['time'],
            'title'       => $u['title'],
            'category'    => $category,
            'is_group'    => $u['is_group'],
            'is_ticket'   => $u['is_ticket'],
            'guide_name'  => $u['guide_name'],
            'channels'    => $u['channels'],
            'bookings'    => $u['bookings'],
            'cancelled'   => $u['cancelled'],
            'pax'         => [
                'adults'   => $u['adults'],
                'children' => $u['children'],
                'infants'  => $u['infants'],
                'total'    => $pax
            ],
            'revenue'     => [
                'retail'     => round($u['retail'], 2),
                'commission' => round($u['commission'], 2),
                'net'        => $net,
                'estimated'  => $u['estimated'],
                'overridden' => $revenueOverridden
            ],
            'costs'       => array_merge($costs, [
                'total'      => $totalCost,
                'auto'       => $auto,
                'overridden' => $overriddenFields
            ]),
            'outsourced'  => $isOutsourced,
            'profit'      => round($net - $totalCost, 2),
            'notes'       => $ov !== null ? $ov['notes'] : null
        ];
    }

    usort($rows, function ($a, $b) {
        $d = strcmp($a['date'], $b['date']);
        if ($d !== 0) return $d;
        return strcmp($a['time'] ?? '', $b['time'] ?? '');
    });
    return $rows;
}

function pnlTotals($rows) {
    $t = [
        'units' => 0, 'tour_units' => 0, 'ticket_units' => 0,
        'bookings' => 0, 'cancelled' => 0, 'pax' => 0,
        'retail' => 0.0, 'commission' => 0.0, 'net' => 0.0,
        'ticket_cost' => 0.0, 'guide_cost' => 0.0, 'radio_cost' => 0.0,
        'gelato_cost' => 0.0, 'staff_cost' => 0.0, 'other_cost' => 0.0,
        'total_cost' => 0.0, 'profit' => 0.0
    ];
    foreach ($rows as $r) {
        if ($r['bookings'] === 0) { $t['cancelled'] += $r['cancelled']; continue; }
        $t['units']++;
        if ($r['is_ticket']) $t['ticket_units']++; else $t['tour_units']++;
        $t['bookings']   += $r['bookings'];
        $t['cancelled']  += $r['cancelled'];
        $t['pax']        += $r['pax']['total'];
        $t['retail']     += $r['revenue']['retail'];
        $t['commission'] += $r['revenue']['commission'];
        $t['net']        += $r['revenue']['net'];
        foreach (['ticket_cost', 'guide_cost', 'radio_cost', 'gelato_cost', 'staff_cost', 'other_cost'] as $f) {
            $t[$f] += $r['costs'][$f];
        }
        $t['total_cost'] += $r['costs']['total'];
        $t['profit']     += $r['profit'];
    }
    foreach ($t as $k => $v) {
        if (is_float($v)) $t[$k] = round($v, 2);
    }
    return $t;
}

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

    if ($method === 'POST' && $action === 'costs') {
        applyRateLimit('update');
        $input = json_decode(file_get_contents('php://input'), true);
        $unit = isset($input['tour_unit']) ? trim($input['tour_unit']) : '';
        $date = isset($input['date']) ? trim($input['date']) : '';
        if (!preg_match('/^[gt]\d+$/', $unit) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
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

        // Build upsert dynamically (values bound as strings; MySQL casts to DECIMAL)
        $allCols = array_merge(['tour_unit', 'date'], $cols, $notesProvided ? ['notes'] : []);
        $placeholders = implode(', ', array_fill(0, count($allCols), '?'));
        $updateParts = [];
        foreach (array_merge($cols, $notesProvided ? ['notes'] : []) as $c) {
            $updateParts[] = "$c = VALUES($c)";
        }
        $sql = "INSERT INTO pnl_tour_costs (" . implode(', ', $allCols) . ")
                VALUES ($placeholders)
                ON DUPLICATE KEY UPDATE " . implode(', ', $updateParts);
        $stmt = $conn->prepare($sql);

        $bindVals = [$unit, $date];
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
