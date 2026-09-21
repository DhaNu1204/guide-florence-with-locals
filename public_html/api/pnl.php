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
require_once __DIR__ . '/group_helpers.php'; // step 3.7: groupBucketKey()
require_once __DIR__ . '/pnl_links.php';     // step 6.2: merged costing units
require_once __DIR__ . '/manual_helpers.php'; // step 6.4: hand-entered departures
require_once __DIR__ . '/viator_helpers.php'; // step 6.9: the old-Viator-account label

// Financial data: admin only
Middleware::requireRole($conn, 'admin');

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? trim($_GET['action']) : '';

/**
 * Step 6.2: the merged costing units that touch a date range, as tour_unit => link row.
 * A link is resolved by its tour_unit; if that unit no longer exists (a surrogate id moved) the
 * stored bucket_key still identifies the departure, exactly as pnl_tour_costs does since 3.7.
 */
function pnlLoadUnitLinks($conn, $start, $end) {
    $byUnit = [];
    $stmt = $conn->prepare("SELECT link_key, link_date, tour_unit, bucket_key
                              FROM pnl_unit_links WHERE link_date >= ? AND link_date <= ?
                             ORDER BY id");
    $stmt->bind_param("ss", $start, $end);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $byUnit[$row['tour_unit']] = $row;
    }
    $stmt->close();
    return $byUnit;
}

/**
 * Step 3.7: the natural key of a tour unit - "<product_id>|YYYY-MM-DD|HH:MM".
 * For a group it comes from the group row (falling back to its members), for a single
 * booking from the tour itself. Returns null when the product is unknown.
 */
function pnlBucketKeyForUnit($conn, $unit) {
    if (preg_match('/^g(\d+)$/', $unit, $m)) {
        $stmt = $conn->prepare("SELECT tg.bucket_key, tg.group_date, tg.group_time,
                                       (SELECT MIN(t.product_id) FROM tours t WHERE t.group_id = tg.id) AS product_id
                                  FROM tour_groups tg WHERE tg.id = ?");
        $stmt->bind_param('i', $m[1]);
    } elseif (preg_match('/^t(\d+)$/', $unit, $m)) {
        $stmt = $conn->prepare("SELECT NULL AS bucket_key, date AS group_date, time AS group_time, product_id
                                  FROM tours WHERE id = ?");
        $stmt->bind_param('i', $m[1]);
    } else {
        return null;
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return null;
    }
    if (!empty($row['bucket_key'])) {
        return $row['bucket_key'];
    }
    if (empty($row['product_id'])) {
        return null;
    }
    return groupBucketKey($row['product_id'], $row['group_date'], $row['group_time']);
}

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

    // Step 3.7: the override stops depending on a surrogate group id. `tour_unit` stays the
    // handle (every existing 'g<id>'/'t<id>' row keeps working, and payments/reports use the
    // same string), and `bucket_key` - "<product_id>|YYYY-MM-DD|HH:MM" - is written beside it
    // as a fallback, so an override survives even if the departure's group id ever changes.
    // Also in database/migrations/20260920_group_bucket_key.sql.
    $res = $conn->query("SHOW COLUMNS FROM pnl_tour_costs LIKE 'bucket_key'");
    if ($res && $res->num_rows === 0) {
        $conn->query("ALTER TABLE pnl_tour_costs ADD COLUMN bucket_key VARCHAR(64) NULL DEFAULT NULL AFTER date");
        $conn->query("ALTER TABLE pnl_tour_costs ADD KEY idx_pnl_costs_bucket_key (bucket_key)");
    }

    // Step 6.2: two departures that physically run together under ONE guide, linked by hand for
    // COSTING ONLY. This is a P&L concept: it changes nothing in Tours, grouping, payments,
    // reminders or the digest. Never created automatically - auto-grouping still refuses to mix
    // products. A unit belongs to at most one link (tour_unit is UNIQUE), and every row of a link
    // shares link_key and link_date. bucket_key is carried beside tour_unit for the same reason
    // pnl_tour_costs carries it since 3.7: the natural key survives even if a surrogate id moves.
    // Also in database/migrations/20260920_pnl_unit_links.sql.
    $conn->query("CREATE TABLE IF NOT EXISTS pnl_unit_links (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        link_key    VARCHAR(40) NOT NULL,
        link_date   DATE NOT NULL,
        tour_unit   VARCHAR(24) NOT NULL UNIQUE,
        bucket_key  VARCHAR(64) NULL,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        created_by  INT NULL,
        KEY idx_pnl_links_key (link_key),
        KEY idx_pnl_links_date (link_date)
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
        // Private tours: per-category flat rates (€60/h × hours; hours differ by museum)
        'guide_rate_private_combo', 'guide_rate_private_uffizi',
        'guide_rate_private_accademia', 'guide_rate_private_pitti',
        'guide_rate_private_other',
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
        'comm_getyourguide', 'comm_viator', 'comm_airbnb', 'comm_headout', 'comm_default',
        // Step 6.7: what the card processor keeps on a direct sale
        'fee_card_direct'
    ];
}

function pnlDefaultSettings() {
    $defaults = array_fill_keys(pnlSettingKeys(), 0.0);
    // Business defaults (owner can change in Rates & Costs)
    $defaults['guide_rate_private_combo']     = 240.0; // 4h
    $defaults['guide_rate_private_uffizi']    = 120.0; // 2h
    $defaults['guide_rate_private_accademia'] = 90.0;  // 1.5h
    $defaults['guide_rate_private_pitti']     = 120.0; // 2h
    $defaults['guide_rate_private_other']     = 120.0; // 2h (incl. Borghese)
    $defaults['guide_rate_combo']        = 210.0; // €60/h × 3.5h shared
    $defaults['ticket_uffizi_adult']     = 29.0; // €25 + €4 advance reservation
    $defaults['ticket_uffizi_adult_pm']  = 20.0; // €16 + €4, entry from 16:00 (since 1 Jan 2026)
    $defaults['ticket_accademia_adult']  = 20.0; // €16 + €4 reservation
    $defaults['ticket_borghese_adult']   = 17.0;
    $defaults['ticket_borghese_child']   = 17.0; // no child ticket — adults' price applies
    $defaults['outsource_fee']           = 10.0;
    $defaults['comm_getyourguide'] = 30.0;
    $defaults['comm_viator']       = 30.0;
    $defaults['comm_airbnb']       = 20.0; // step 6.6: what Airbnb really charges
    $defaults['comm_headout']      = 20.0; // step 6.6: was 25.0; Bokun records 20%
    $defaults['comm_default']      = 30.0;
    $defaults['fee_card_direct']   = 1.5;  // step 6.7: Stripe, the owner's stated rate
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

function pnlPrivateGuideRate($category, $settings) {
    switch ($category) {
        case 'Combo':     return $settings['guide_rate_private_combo'];
        case 'Uffizi':    return $settings['guide_rate_private_uffizi'];
        case 'Accademia': return $settings['guide_rate_private_accademia'];
        case 'Pitti':     return $settings['guide_rate_private_pitti'];
        default:          return $settings['guide_rate_private_other'];
    }
}

// ---------------------------------------------------------------------------
/**
 * Step 6.7: did THIS booking's money actually go through the card gateway?
 *
 * Not inferred from the channel - read from the booking's own payment record, because a
 * fee charged on a payment that never touched Stripe would just be a new error replacing
 * the old one. Measured on production over every direct sale we hold: 202 carry
 * `paymentProviderType: STRIPE_TOKEN` with an `authorizationCode` beginning `pi_` (a Stripe
 * PaymentIntent), 2 are VOUCHER redemptions and 1 is a Backend booking with no payment at
 * all and `paymentStatus: NOT_PAID`. Only the first group is charged a card fee.
 *
 * WEB_PAYMENT is accepted alongside the provider name so that changing gateway does not
 * silently switch the fee off; a voucher, a cash sale or an unpaid booking never matches.
 */
function pnlPaidByCard($booking) {
    if (!is_array($booking)) { return false; }
    $payments = $booking['customerInvoice']['payments'] ?? null;
    if (!is_array($payments)) { return false; }
    foreach ($payments as $p) {
        if (!is_array($p)) { continue; }
        $provider = (string) ($p['paymentProviderType'] ?? '');
        $type     = (string) ($p['paymentType'] ?? '');
        if (stripos($provider, 'stripe') !== false) { return true; }
        if (strcasecmp($type, 'WEB_PAYMENT') === 0) { return true; }
    }
    return false;
}

/**
 * Step 6.7: the card-processing fee on a direct sale, as a percentage of what was charged.
 *
 * The owner gave a rate only (Stripe, 1.5%) - there is deliberately NO per-transaction cent
 * amount here, because he did not give one and inventing one would be a guess in his costs.
 * The rate lives in Rates & Costs (`fee_card_direct`) so he can correct it himself; set it
 * to 0 and the deduction disappears entirely.
 */
function pnlCardFee($booking, $amount, $settings) {
    $pct = isset($settings['fee_card_direct']) ? (float) $settings['fee_card_direct'] : 0.0;
    if ($pct <= 0 || $amount <= 0) { return 0.0; }
    if (!pnlPaidByCard($booking)) { return 0.0; }
    return round($amount * $pct / 100, 2);
}

// Revenue extraction from stored bokun_data JSON.
// Bokun invoices can appear at several depths depending on which API path
// stored the booking (search vs detail vs webhook shape) — check them all.
// Returns [retail, commission, net, estimated(bool), cardFee] - step 6.7 added the card
// fee, which is charged ONLY on a direct sale the customer paid by card (see pnlPaidByCard).
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
                // Step 6.7: an OTA collected this money, so no card fee of ours applies.
                // Viator lands here with commission 0 and must NOT gain a deduction.
                return [$retail, $comm, $net, false, 0.0];
            }
        }
    }

    // 1b) Step 6.6: a DIRECT sale has no reseller, so Bokun files it under customerInvoice
    // with totalCommission 0 and totalSansCommission = total. That is not "no information",
    // it is the information: the owner pays nobody, so he keeps the lot. Before this step the
    // code fell past it to the guessed percentage and deducted 30% he never paid - EUR 7,852.21
    // across 205 bookings since 2025-09-30, every cent of it understating his profit.
    // An OTA booking never reaches here (path 1 returns first for all 7,047 of them), so this
    // cannot move a channel figure; the invoice is read as given, whatever the commission says.
    foreach ($candidates as $c) {
        if (isset($c['customerInvoice']) && is_array($c['customerInvoice'])) {
            $inv = $c['customerInvoice'];
            $retail = isset($inv['total']) ? floatval($inv['total']) : null;
            $comm   = isset($inv['totalCommission']) ? floatval($inv['totalCommission']) : null;
            $net    = isset($inv['totalSansCommission']) ? floatval($inv['totalSansCommission']) : null;
            if ($retail !== null && ($net !== null || $comm !== null)) {
                if ($net === null)  { $net  = $retail - $comm; }
                if ($comm === null) { $comm = $retail - $net; }
                // Step 6.7: this is the direct sale, so the card fee comes off on TOP of the
                // invoice figure 6.6 reads - never instead of it.
                $fee = pnlCardFee($b, $net, $settings);
                return [$retail, $comm, $net - $fee, false, $fee];
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
        return [$retail, $comm, $retail - $comm, false, 0.0];
    }

    // 3) Fallback: retail from column + estimated commission % by channel
    if (($retail === null || $retail <= 0) && $fallbackAmount > 0) {
        $retail = floatval($fallbackAmount);
    }
    if ($retail === null || $retail <= 0) {
        return [0.0, 0.0, 0.0, true, 0.0];
    }
    // Step 6.6: from here down nothing is known - every invoice was missing - so whatever
    // comes out is a GUESS and is returned with estimated = true for the UI to say so.
    // The named channels are a safety net only: today every one of them arrives with an
    // invoice and never gets this far.
    $ch = mb_strtolower(trim($channel ?? ''));
    if ($ch === '' || $ch === 'bokun'
        || strpos($ch, 'direct') !== false
        || strpos($ch, 'website') !== false
        || strpos($ch, 'florencewithlocals') !== false   // his own site: he pays no commission
        || strpos($ch, 'payment link') !== false
        || strpos($ch, 'backend') !== false) {
        // Direct sale — no OTA commission. (A card-processing fee is a separate, real cost
        // and is deliberately NOT invented here; it needs the owner's actual rate.)
        $pct = 0.0;
    } elseif (strpos($ch, 'getyourguide') !== false || strpos($ch, 'gyg') !== false) {
        $pct = $settings['comm_getyourguide'];
    } elseif (strpos($ch, 'viator') !== false || strpos($ch, 'tripadvisor') !== false) {
        $pct = $settings['comm_viator'];
    } elseif (strpos($ch, 'airbnb') !== false) {
        $pct = $settings['comm_airbnb'];
    } elseif (strpos($ch, 'headout') !== false) {
        $pct = $settings['comm_headout'];
    } else {
        $pct = $settings['comm_default'];
    }
    $comm = round($retail * $pct / 100, 2);
    $net  = $retail - $comm;
    // Step 6.7: only a direct sale (no OTA commission) can carry our card fee.
    $fee  = ($pct == 0.0) ? pnlCardFee($b, $net, $settings) : 0.0;
    return [$retail, $comm, $net - $fee, true, $fee];
}

// ---------------------------------------------------------------------------
// Core: build per-unit P&L rows for a date range.
// ---------------------------------------------------------------------------
function pnlBuildRows($conn, $start, $end, $settings) {
    ensureManualColumns($conn); // step 6.4
    ensureViatorAccountColumn($conn); // step 6.9
    $sql = "SELECT t.id, t.group_id, t.product_id, t.title, t.date, t.time, t.participants,
                   t.cancelled, t.booking_channel, t.viator_account, t.total_amount_paid, t.bokun_data,
                   t.source, t.manual_revenue, t.manual_currency,
                   t.is_private, t.guide_id, g.name AS guide_name,
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
                // Step 3.7: what this departure IS, independent of the group's surrogate id.
                'bucket_key'      => $row['product_id'] ? groupBucketKey($row['product_id'], $row['date'],
                                        ($row['group_id'] && $row['group_time']) ? $row['group_time'] : $row['time']) : null,
                'date'            => $row['date'],
                'time'            => $row['group_id'] && $row['group_time'] ? $row['group_time'] : $row['time'],
                'title'           => $row['group_id'] && $row['group_display_name'] ? $row['group_display_name'] : $row['title'],
                'is_group'        => $row['group_id'] ? true : false,
                'is_ticket'       => intval($row['is_ticket_product']) === 1,
                'is_private'      => false,
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
                'card_fee'        => 0.0, // step 6.7
                'net'             => 0.0,
                'estimated'       => false,
                'ticket_cost_auto'=> 0.0,
                // Step 6.4: a hand-entered departure has no Bokun product, so the museum
                // ticket cost cannot be derived when its title names no museum. That is
                // UNKNOWN, not zero - the UI must not print a confident 0.00.
                'has_manual'      => false,
                'ticket_known'    => true,
                'has_gelato'      => false
            ];
        }
        $u = &$units[$key];

        if ($row['guide_name']) $u['guide_name'] = $row['guide_name'];
        if (intval($row['is_private']) === 1) $u['is_private'] = true;

        if (intval($row['cancelled']) === 1) {
            $u['cancelled']++;
            unset($u);
            continue;
        }

        $u['bookings']++;
        $u['titles'][] = $row['title'];

        // Step 6.9: a booking on the retiring Viator account gets its own line here, so a day
        // that mixes the two accounts shows both. The commission below still reads
        // $row['booking_channel'], which is untouched, so no figure moves.
        $ch = viatorChannelLabel($row['booking_channel'] ?: 'Direct', $row['viator_account'] ?? null);
        if (!in_array($ch, $u['channels'])) $u['channels'][] = $ch;

        // PAX breakdown (adults/children/infants) from bokun_data
        $pax = computePaxBreakdown($row['bokun_data'], intval($row['participants']));
        $u['adults']   += $pax['adults'];
        $u['children'] += $pax['children'];
        $u['infants']  += $pax['infants'];

        // Revenue
        list($retail, $comm, $net, $estimated, $cardFee) = pnlExtractRevenue(
            $row['bokun_data'], $row['booking_channel'], floatval($row['total_amount_paid']), $settings
        );
        // Step 6.4: a hand-entered departure has no Bokun invoice. What the owner types in
        // is what GetYourGuide actually pays him - already NET of their commission - so it is
        // taken as net and no commission is subtracted a second time. `manual` on the row tells
        // the UI that the retail figure is that same net number, not a gross price we know.
        if (manualIsManualRow($row)) {
            $manualNet = $row['manual_revenue'] !== null ? round((float) $row['manual_revenue'], 2) : 0.0;
            $retail = $manualNet;
            $comm   = 0.0;
            $cardFee = 0.0; // step 6.7: a hand-typed figure is already what he receives
            $net    = $manualNet;
            $estimated = ($row['manual_revenue'] === null);
            $u['has_manual'] = true;
        }
        $u['retail']     += $retail;
        $u['commission'] += $comm;
        $u['card_fee']   += $cardFee; // step 6.7
        $u['net']        += $net;
        if ($estimated && $retail > 0) $u['estimated'] = true;
        if (manualIsManualRow($row) && $row['manual_revenue'] === null) { $u['estimated'] = true; }

        // Museum ticket cost for THIS booking (per museum mentioned in ITS title).
        // Uffizi has a cheaper afternoon rate for entries from 16:00.
        $museumsHere = pnlMuseumsInTitle($row['title']);
        if (manualIsManualRow($row) && count($museumsHere) === 0) {
            // No Bokun product and no museum in the title: we genuinely do not know what the
            // tickets cost. Marked unknown so the P&L shows "-" and asks for an override.
            $u['ticket_known'] = false;
        }
        foreach ($museumsHere as $museum) {
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
        unset($museumsHere);
        if (strpos(mb_strtolower($row['title']), 'gelato') !== false) {
            $u['has_gelato'] = true;
        }
        unset($u);
    }

    // Load overrides for the range.
    // Step 3.7: an override is found by its tour_unit as before, and - for GROUP units only -
    // by bucket_key when the unit string no longer resolves. That is what makes an override
    // survive a departure whose group id changed (10 of the 11 group overrides on production
    // were already orphaned that way before this step). The fallback is deliberately refused
    // when a bucket holds more than one group (the PAX cap can split a departure): applying a
    // cost to the wrong half would be worse than losing it.
    $overrides = [];
    $byBucket = [];
    $bucketSeen = [];
    $stmt = $conn->prepare("SELECT * FROM pnl_tour_costs WHERE date >= ? AND date <= ?");
    $stmt->bind_param("ss", $start, $end);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $overrides[$row['tour_unit']] = $row;
        $bk = isset($row['bucket_key']) ? $row['bucket_key'] : null;
        if ($bk !== null && $bk !== '' && strpos($row['tour_unit'], 'g') === 0) {
            $bucketSeen[$bk] = ($bucketSeen[$bk] ?? 0) + 1;
            $byBucket[$bk] = $row;
        }
    }

    // How many group units share each bucket in this range? (> 1 = split departure, no fallback)
    $unitsPerBucket = [];
    foreach ($units as $u) {
        if (!empty($u['is_group']) && !empty($u['bucket_key'])) {
            $unitsPerBucket[$u['bucket_key']] = ($unitsPerBucket[$u['bucket_key']] ?? 0) + 1;
        }
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
        if ($ov === null && !empty($u['is_group']) && !empty($u['bucket_key'])) {
            $bk = $u['bucket_key'];
            if (isset($byBucket[$bk]) && ($bucketSeen[$bk] ?? 0) === 1 && ($unitsPerBucket[$bk] ?? 0) === 1) {
                $ov = $byBucket[$bk]; // the departure is the same one, only its id moved
            }
        }
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
            if ($u['is_private']) {
                // Private tour: per-category flat rate (hours differ by museum)
                $auto['guide_cost'] = floatval(pnlPrivateGuideRate($category, $settings));
            } elseif ($category === 'Mixed') {
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
            'is_manual'   => !empty($u['has_manual']),
            'is_ticket'   => $u['is_ticket'],
            'is_private'  => $u['is_private'],
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
                // Step 6.7: shown separately because it is NOT an OTA commission - it is what
                // the card processor keeps on a sale he made himself.
                'card_fee'   => round($u['card_fee'], 2),
                'net'        => $net,
                'estimated'  => $u['estimated'],
                'overridden' => $revenueOverridden,
                // Step 6.4: retail here IS the net the owner typed in, not a gross price.
                'manual'     => !empty($u['has_manual'])
            ],
            'costs'       => array_merge($costs, [
                'total'      => $totalCost,
                'auto'       => $auto,
                'overridden' => $overriddenFields,
                // Step 6.4: true when this is a hand-entered departure whose title names no
                // museum and no ticket override has been entered - the number in ticket_cost
                // is 0.00 only because nothing is known, so the UI prints "-" instead.
                'ticket_unknown' => empty($u['ticket_known']) && !in_array('ticket_cost', $overriddenFields, true),
                // Step 6.4: same honesty for the guide fee. A hand-entered tour falls in the
                // "Other" category, whose automatic rate is 0 - which is a real setting for a
                // synced tour, but for a manual one with a guide assigned it only means "we do
                // not know yet". Flagged for manual rows only; nothing about a synced row moves.
                'guide_unknown'  => !empty($u['has_manual']) && !empty($u['guide_name'])
                                    && round((float) $costs['guide_cost'], 2) === 0.0
                                    && !in_array('guide_cost', $overriddenFields, true)
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

    // Step 6.2: two departures the owner linked by hand run as ONE tour with one guide - fold
    // them into a single row (tickets, radios, revenue and PAX still add up). A unit that is not
    // linked is untouched, so a day with no links produces exactly the rows it did before.
    $links = pnlLoadUnitLinks($conn, $start, $end);
    if ($links) {
        $rows = pnlLinkCombineRows($rows, $links, $settings, $overrides);
    }
    return $rows;
}

function pnlTotals($rows) {
    $t = [
        'units' => 0, 'tour_units' => 0, 'ticket_units' => 0,
        // Step 6.6: how many of these units carry a GUESSED revenue figure rather than an
        // invoice. Surfaced so a total can never quietly mix the two.
        'estimated_units' => 0,
        'bookings' => 0, 'cancelled' => 0, 'pax' => 0,
        'retail' => 0.0, 'commission' => 0.0, 'card_fee' => 0.0, 'net' => 0.0,
        'ticket_cost' => 0.0, 'guide_cost' => 0.0, 'radio_cost' => 0.0,
        'gelato_cost' => 0.0, 'staff_cost' => 0.0, 'other_cost' => 0.0,
        'total_cost' => 0.0, 'profit' => 0.0
    ];
    foreach ($rows as $r) {
        if ($r['bookings'] === 0) { $t['cancelled'] += $r['cancelled']; continue; }
        $t['units']++;
        if ($r['is_ticket']) $t['ticket_units']++; else $t['tour_units']++;
        if (!empty($r['revenue']['estimated'])) { $t['estimated_units']++; }
        $t['bookings']   += $r['bookings'];
        $t['cancelled']  += $r['cancelled'];
        $t['pax']        += $r['pax']['total'];
        $t['retail']     += $r['revenue']['retail'];
        $t['commission'] += $r['revenue']['commission'];
        $t['card_fee']   += $r['revenue']['card_fee'] ?? 0.0; // step 6.7
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
