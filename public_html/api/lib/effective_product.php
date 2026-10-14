<?php
/**
 * Step 6.22: the "effective product" - what a booking's Bokun product COUNTS AS.
 *
 * products.groups_as_product_id links a product (e.g. the new Viator account's copy of a tour,
 * 5686765P3) to the original it stands for (962885). A linked product counts as its original for:
 * the auto-grouping key, the group cap, the guide-rate category, ticket cost, gelato, radios,
 * duration, meeting point, tour/ticket type (Tours, Dashboard, assistant, payments, reports) and
 * Daily P&L. Each booking keeps its own Bokun title on screen, and the old/new Viator label (6.18)
 * keeps reading the booking's own product code.
 *
 * Links are ONE hop: an original never has a link itself (products.php refuses it), so following
 * groups_as_product_id once is always enough. Nothing is ever linked automatically.
 *
 * The title rules (museums, gelato, category, max PAX, radio heading) read the RULE TITLE: for a
 * linked product, the title of the original's most recent booking (= its current Bokun name;
 * products.title is only the name at first sight and can be stale), else the booking's own title.
 *
 * Functions only - no output, safe to require_once from endpoints, the sync and CLI tools.
 */

if (!function_exists('ensureProductGroupsAsColumn')) {
    function ensureProductGroupsAsColumn($conn) {
        static $done = false;
        if ($done) return;
        $has = $conn->query("SHOW COLUMNS FROM products LIKE 'groups_as_product_id'");
        if ($has && $has->num_rows === 0) {
            $conn->query("ALTER TABLE products ADD COLUMN groups_as_product_id INT NULL DEFAULT NULL");
            $conn->query("ALTER TABLE products ADD KEY idx_products_groups_as (groups_as_product_id)");
        }
        $done = true;
    }
}

if (!function_exists('fwlProductJoinSql')) {
    /**
     * LEFT JOIN that gives `$alias` = the EFFECTIVE product row of tour alias `$t` (the original
     * when linked, else the product itself). Drop-in for
     * "LEFT JOIN products pr ON pr.bokun_product_id = t.product_id".
     */
    function fwlProductJoinSql($t = 't', $alias = 'pr') {
        $own = $alias . '_own';
        return "LEFT JOIN products {$own} ON {$own}.bokun_product_id = {$t}.product_id "
             . "LEFT JOIN products {$alias} ON {$alias}.bokun_product_id = COALESCE({$own}.groups_as_product_id, {$t}.product_id)";
    }

    /** SQL boolean: the booking's effective product is a ticket. */
    function fwlIsTicketSql($t = 't') {
        return "EXISTS (SELECT 1 FROM products epo JOIN products ept"
             . " ON ept.bokun_product_id = COALESCE(epo.groups_as_product_id, epo.bokun_product_id)"
             . " WHERE epo.bokun_product_id = {$t}.product_id AND ept.product_type = 'ticket')";
    }

    /** SQL expression: the booking's effective product id (NULL stays NULL). */
    function fwlEffectivePidSql($t = 't') {
        return "COALESCE((SELECT epl.groups_as_product_id FROM products epl WHERE epl.bokun_product_id = {$t}.product_id), {$t}.product_id)";
    }
}

if (!function_exists('fwlEffectiveProducts')) {
    /**
     * The linked products, once per request: pid => ['effective_id' => int, 'rule_title' => ?string].
     * Unlinked products are not in the map (they are their own effective product).
     * $reset = true re-reads (after products.php changes a link in the same request, or in tests).
     */
    function fwlEffectiveProducts($conn, $reset = false) {
        static $map = null;
        if ($reset) { $map = null; }
        if ($map !== null) return $map;
        ensureProductGroupsAsColumn($conn);
        $map = [];
        $res = $conn->query("
            SELECT p.bokun_product_id AS pid, p.groups_as_product_id AS orig, o.title AS orig_title,
                   (SELECT t.title FROM tours t WHERE t.product_id = p.groups_as_product_id
                       AND t.title IS NOT NULL AND t.title <> '' ORDER BY t.id DESC LIMIT 1) AS latest_title
            FROM products p LEFT JOIN products o ON o.bokun_product_id = p.groups_as_product_id
            WHERE p.groups_as_product_id IS NOT NULL");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $title = trim((string) $r['latest_title']) !== '' ? $r['latest_title']
                       : (trim((string) $r['orig_title']) !== '' ? $r['orig_title'] : null);
                $map[(int) $r['pid']] = ['effective_id' => (int) $r['orig'], 'rule_title' => $title];
            }
        }
        return $map;
    }

    /** The product a booking counts as (null stays null - hand-entered rows have no product). */
    function fwlEffectiveProductId($conn, $productId) {
        if ($productId === null || $productId === '' || (int) $productId === 0) return $productId === null || $productId === '' ? null : (int) $productId;
        $map = fwlEffectiveProducts($conn);
        $pid = (int) $productId;
        return isset($map[$pid]) ? $map[$pid]['effective_id'] : $pid;
    }

    /** The title the keyword rules read: the original's for a linked product, else the own title. */
    function fwlRuleTitle($conn, $productId, $ownTitle) {
        if ($productId === null || $productId === '') return $ownTitle;
        $map = fwlEffectiveProducts($conn);
        $pid = (int) $productId;
        return (isset($map[$pid]) && $map[$pid]['rule_title'] !== null) ? $map[$pid]['rule_title'] : $ownTitle;
    }

    /** Adds `rule_title` (and `effective_product_id`) to an API tour row that has product_id + title. */
    function fwlWithRuleTitle($conn, array $row) {
        $pid = $row['product_id'] ?? null;
        $row['rule_title'] = fwlRuleTitle($conn, $pid, $row['title'] ?? '');
        $row['effective_product_id'] = fwlEffectiveProductId($conn, $pid);
        return $row;
    }
}
