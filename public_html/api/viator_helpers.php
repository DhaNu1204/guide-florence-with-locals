<?php
/**
 * viator_helpers.php - step 6.9: telling the owner's OLD Viator account from his new one.
 *
 * Functions and one self-provisioned column only - no output, no routing, safe to require_once
 * (the pattern group_helpers.php, manual_helpers.php and participant_helpers.php already use),
 * which is what lets tools/viator_check.php test the rules without a database.
 *
 * Why a column at all. Part A measured every future Viator booking on production and found
 * NOTHING in the stored payload that distinguishes one Viator account from another: all 50
 * carry the same `channel {id: 12296, "Viator.com"}` and `seller {id: 5194, "Viator.com"}` -
 * Bokun's marketplace records, not his account - `externalBookingReference` is a bare 10-digit
 * Viator number, `confirmationCode` is Bokun's own counter, and `vendor` is his own either way.
 * The only reliable marker is one we write ourselves, before he disconnects, because afterwards
 * the information no longer exists anywhere.
 */

if (!defined('VIATOR_ACCOUNT_LEGACY'))  { define('VIATOR_ACCOUNT_LEGACY', 'legacy'); }
if (!defined('VIATOR_ACCOUNT_CURRENT')) { define('VIATOR_ACCOUNT_CURRENT', 'current'); }

if (!function_exists('viatorIsViatorChannel')) {
    /**
     * Is this booking_channel Viator? Production holds exactly one spelling today,
     * 'Viator.com' (2,324 rows), but the P&L already matches Viator loosely
     * (`strpos($ch,'viator') || strpos($ch,'tripadvisor')`) and this must agree with it,
     * or a booking could be costed as Viator and labelled as something else.
     */
    function viatorIsViatorChannel($channel) {
        $c = mb_strtolower(trim((string) $channel));
        if ($c === '') { return false; }
        return strpos($c, 'viator') !== false || strpos($c, 'tripadvisor') !== false;
    }
}

if (!function_exists('ensureViatorAccountColumn')) {
    /**
     * Self-provision `tours.viator_account` (beside database/migrations/20260921_tours_viator_account.sql)
     * and, in the same breath, stamp every Viator booking that already exists as 'legacy'.
     *
     * The stamp is deliberately part of creating the column rather than a separate script:
     * at the moment the column comes into existence, every Viator row in the table belongs to
     * the old account by definition - that is the whole premise - and doing it here means the
     * labelling cannot be forgotten, cannot run twice, and cannot run late.
     */
    function ensureViatorAccountColumn($conn) {
        static $done = false;
        if ($done) { return; }
        $done = true;
        $c = $conn->query("SHOW COLUMNS FROM tours LIKE 'viator_account'");
        if ($c && $c->num_rows === 0) {
            $conn->query("ALTER TABLE tours ADD COLUMN `viator_account` VARCHAR(16) NULL DEFAULT NULL");
            $conn->query("ALTER TABLE tours ADD KEY `idx_tours_viator_account` (`viator_account`)");
            $legacy = VIATOR_ACCOUNT_LEGACY;
            $stmt = $conn->prepare("UPDATE tours SET viator_account = ?
                                     WHERE viator_account IS NULL
                                       AND (booking_channel LIKE '%Viator%' OR booking_channel LIKE '%Tripadvisor%')");
            $stmt->bind_param('s', $legacy);
            $stmt->execute();
            error_log("Step 6.9: added tours.viator_account and stamped {$stmt->affected_rows} existing Viator booking(s) as '" . VIATOR_ACCOUNT_LEGACY . "'");
            $stmt->close();
        }
    }
}

if (!function_exists('ensureViatorSwitchTable')) {
    /**
     * One row (id = 1) recording the moment the owner connected the NEW Viator account.
     *
     * Without it the guard would be wrong rather than merely absent: a booking that arrives on
     * the OLD account tomorrow - he has not switched yet - is still a booking he must honour,
     * and labelling it 'current' would put it on the wrong side of the line. So until a cutover
     * is recorded, EVERY Viator booking the sync inserts is 'legacy'; after it, 'current'.
     */
    function ensureViatorSwitchTable($conn) {
        static $done = false;
        if ($done) { return; }
        $done = true;
        $conn->query("CREATE TABLE IF NOT EXISTS viator_switch (
            id          TINYINT NOT NULL PRIMARY KEY,
            cutover_at  DATETIME NULL,
            note        VARCHAR(255) NULL,
            updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->query("INSERT IGNORE INTO viator_switch (id, cutover_at, note) VALUES (1, NULL, 'no cutover recorded - the old account is still the only one')");
    }
}

if (!function_exists('viatorCutoverAt')) {
    /** The recorded cutover as a 'Y-m-d H:i:s' string, or null while he has not switched yet. */
    function viatorCutoverAt($conn) {
        ensureViatorSwitchTable($conn);
        $r = $conn->query("SELECT cutover_at FROM viator_switch WHERE id = 1");
        $row = $r ? $r->fetch_assoc() : null;
        return ($row && !empty($row['cutover_at'])) ? $row['cutover_at'] : null;
    }
}

if (!function_exists('viatorAccountForInsert')) {
    /**
     * The label a NEWLY INSERTED booking gets. Pure, so tools/viator_check.php can test it.
     *
     *   not a Viator booking          -> null  (the column stays empty for GetYourGuide et al.)
     *   no cutover recorded yet       -> 'legacy'   (the old account is still the only one)
     *   booking created before cutover-> 'legacy'   (made on the old account, we are just late)
     *   otherwise                     -> 'current'
     *
     * `creationDate` is Bokun's millisecond epoch and is present on every one of the 2,324
     * Viator bookings measured in Part A; when it is missing we fall back to "now", which after
     * a cutover means 'current' - the safe way round, because a new-account booking wrongly
     * called legacy would quietly join the list he is told to honour by hand.
     */
    function viatorAccountForInsert($channel, $bokunData, $cutoverAt, $now = null) {
        if (!viatorIsViatorChannel($channel)) { return null; }
        if ($cutoverAt === null || $cutoverAt === '') { return VIATOR_ACCOUNT_LEGACY; }
        $cut = strtotime($cutoverAt);
        if ($cut === false) { return VIATOR_ACCOUNT_LEGACY; }

        $created = null;
        $d = is_string($bokunData) ? json_decode($bokunData, true) : $bokunData;
        if (is_array($d) && isset($d['creationDate']) && is_numeric($d['creationDate'])) {
            $created = (int) floor(((float) $d['creationDate']) / 1000);
        }
        if ($created === null) { $created = $now !== null ? $now : time(); }
        return $created < $cut ? VIATOR_ACCOUNT_LEGACY : VIATOR_ACCOUNT_CURRENT;
    }
}
