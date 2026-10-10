<?php
/**
 * Step 6.17: the product a MIXED manual merge counts as (tour_groups.billing_product_id).
 *
 * The owner adds Uffizi-only guests to a Combo departure; that departure is a Combo and the guide
 * is paid the Combo rate. A manual merge whose live bookings span more than one category stores
 * the member product it is billed as: its title becomes the group title and its category sets the
 * guide rate (Daily P&L, Guide Tour Report). Revenue, tickets, radios and gelato stay per booking.
 *
 * Readers apply it only while it still describes the group: the product must belong to a live
 * member and the live members must still span more than one category. Otherwise the old rules
 * apply, so a cancellation (the sync never writes this column) can never leave a stale rate.
 *
 * Functions only - no output, no routing, safe to require_once.
 */

require_once __DIR__ . '/pnl_core.php'; // pnlCategory(), pnlGuideRateForCategory(), pnlLoadSettings()

if (!function_exists('ensureGroupBillingProductColumn')) {

/** Self-provision (also database/migrations/20261010_tour_groups_billing_product.sql). */
function ensureGroupBillingProductColumn($conn) {
    static $done = false;
    if ($done) { return; }
    $done = true;
    $c = $conn->query("SHOW COLUMNS FROM tour_groups LIKE 'billing_product_id'");
    if ($c && $c->num_rows === 0) {
        $after = $conn->query("SHOW COLUMNS FROM tour_groups LIKE 'departure_time'");
        $pos = ($after && $after->num_rows > 0) ? ' AFTER `departure_time`' : '';
        $conn->query("ALTER TABLE tour_groups ADD COLUMN `billing_product_id` INT(11) NULL DEFAULT NULL" . $pos);
        error_log("Step 6.17: added tour_groups.billing_product_id");
    }
}

/** Number of distinct P&L categories among member titles. */
function billingDistinctCategories(array $members) {
    $cats = [];
    foreach ($members as $m) { $cats[pnlCategory($m['title'] ?? '')] = true; }
    return count($cats);
}

/**
 * Index in $members (live bookings: product_id, title) of the booking carrying the billing
 * product, or null when the billing product does not apply (none set, no live booking of that
 * product, or the live bookings no longer mix categories).
 */
function billingMemberIndex(array $members, $billingProductId) {
    if ($billingProductId === null || $billingProductId === '' || (int) $billingProductId === 0) { return null; }
    if (billingDistinctCategories($members) < 2) { return null; }
    foreach ($members as $i => $m) {
        if ((int) ($m['product_id'] ?? 0) === (int) $billingProductId) { return $i; }
    }
    return null;
}

/**
 * The default billing product of a set of live members (product_id, title, duration_minutes):
 * the member product with the highest guide rate; equal rates -> the longest duration_minutes;
 * still equal -> the lowest product id. Null when the members do not mix categories.
 */
function billingDefaultProductId(array $members, array $settings) {
    if (billingDistinctCategories($members) < 2) { return null; }
    $best = null;
    foreach ($members as $m) {
        $pid = (int) ($m['product_id'] ?? 0);
        if ($pid <= 0) { continue; }
        $cand = [
            'pid'  => $pid,
            'rate' => (float) pnlGuideRateForCategory(pnlCategory($m['title'] ?? ''), $settings),
            'dur'  => (int) ($m['duration_minutes'] ?? 0),
        ];
        if ($best === null
            || $cand['rate'] > $best['rate']
            || ($cand['rate'] == $best['rate'] && $cand['dur'] > $best['dur'])
            || ($cand['rate'] == $best['rate'] && $cand['dur'] === $best['dur'] && $cand['pid'] < $best['pid'])) {
            $best = $cand;
        }
    }
    return $best === null ? null : $best['pid'];
}

/**
 * The product choices for "Counts as": one entry per distinct live member product
 * (product_id, title of its first booking, category), in booking order.
 */
function billingProductOptions(array $members) {
    $out = [];
    foreach ($members as $m) {
        $pid = (int) ($m['product_id'] ?? 0);
        if ($pid <= 0 || isset($out[$pid])) { continue; }
        $out[$pid] = ['product_id' => $pid, 'title' => $m['title'], 'category' => pnlCategory($m['title'] ?? '')];
    }
    return array_values($out);
}

/**
 * Guide Tour Report (step 6.15 composition): when the group's billing product applies, the unit's
 * type and title are that product's - the same rule as the Daily P&L, not a second one. $result is
 * buildComposition()'s [category, composition, label, title_index]; $titles / $productIds are the
 * unit's live bookings in the same order. Needs classifyTourCategory() (lib/guide_report_core.php).
 */
function billingApplyToComposition(array $result, array $titles, array $productIds, $billingProductId) {
    $members = [];
    foreach ($titles as $i => $t) { $members[] = ['title' => $t, 'product_id' => $productIds[$i] ?? null]; }
    $idx = billingMemberIndex($members, $billingProductId);
    if ($idx === null) { return $result; }
    $result[0] = classifyTourCategory($titles[$idx]);
    $result[3] = $idx;
    return $result;
}

/** Live (non-cancelled, non-ticket) bookings of a group, oldest id first. */
function groupBillingMembers($conn, $groupId) {
    $stmt = $conn->prepare("SELECT t.id, t.product_id, t.title, pr.duration_minutes
                            FROM tours t
                            LEFT JOIN products pr ON pr.bokun_product_id = t.product_id
                            WHERE t.group_id = ? AND t.cancelled = 0
                              AND (pr.product_type IS NULL OR pr.product_type <> 'ticket')
                            ORDER BY t.id");
    $gid = (int) $groupId;
    $stmt->bind_param('i', $gid);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) { $rows[] = $r; }
    $stmt->close();
    return $rows;
}

/** Write billing_product_id; a product also becomes the group title (its first live booking's title). */
function groupBillingWrite($conn, $groupId, $productId, array $members, $titleWhenCleared = null) {
    $gid = (int) $groupId;
    if ($productId === null) {
        if ($titleWhenCleared !== null) {
            $stmt = $conn->prepare("UPDATE tour_groups SET billing_product_id = NULL, display_name = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param('si', $titleWhenCleared, $gid);
        } else {
            $stmt = $conn->prepare("UPDATE tour_groups SET billing_product_id = NULL, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param('i', $gid);
        }
    } else {
        $title = null;
        foreach ($members as $m) { if ((int) $m['product_id'] === (int) $productId) { $title = $m['title']; break; } }
        $pid = (int) $productId;
        $stmt = $conn->prepare("UPDATE tour_groups SET billing_product_id = ?, display_name = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param('isi', $pid, $title, $gid);
    }
    $stmt->execute();
    $stmt->close();
}

/** A new manual merge: store the default billing product (and its title) when the members mix categories. */
function groupBillingApplyDefault($conn, $groupId) {
    ensureGroupBillingProductColumn($conn);
    $members = groupBillingMembers($conn, $groupId);
    $pid = billingDefaultProductId($members, pnlLoadSettings($conn));
    if ($pid !== null) { groupBillingWrite($conn, $groupId, $pid, $members); }
    return $pid;
}

/**
 * After a booking leaves a group that has a billing product: keep it while it still applies;
 * otherwise use the new default, or clear it (title = the oldest live booking's title) when the
 * group no longer mixes categories.
 */
function groupBillingRefresh($conn, $groupId) {
    ensureGroupBillingProductColumn($conn);
    $stmt = $conn->prepare("SELECT billing_product_id FROM tour_groups WHERE id = ?");
    $gid = (int) $groupId;
    $stmt->bind_param('i', $gid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || $row['billing_product_id'] === null) { return; }
    $members = groupBillingMembers($conn, $groupId);
    if (billingMemberIndex($members, $row['billing_product_id']) !== null) { return; }
    $pid = billingDefaultProductId($members, pnlLoadSettings($conn));
    if ($pid !== null) {
        groupBillingWrite($conn, $groupId, $pid, $members);
    } else {
        groupBillingWrite($conn, $groupId, null, $members, $members ? $members[0]['title'] : null);
    }
}

}
