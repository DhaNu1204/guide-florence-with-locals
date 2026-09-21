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

if (!function_exists('ensureViatorWatchdogTable')) {
    /**
     * One row per check. This is the record the owner (or I) look at to answer the only
     * question that matters after the switch: are the bookings he still has to honour
     * still there?
     */
    function ensureViatorWatchdogTable($conn) {
        static $done = false;
        if ($done) { return; }
        $done = true;
        $conn->query("CREATE TABLE IF NOT EXISTS viator_watchdog (
            id                 INT AUTO_INCREMENT PRIMARY KEY,
            checked_at         DATETIME NOT NULL,
            horizon            DATE NOT NULL,
            future_bookings    INT NOT NULL,
            future_departures  INT NOT NULL,
            future_pax         INT NOT NULL,
            latest_date        DATE NULL,
            expected_bookings  INT NULL,
            passed_since_last  INT NOT NULL DEFAULT 0,
            cancelled_future   INT NOT NULL DEFAULT 0,
            status             VARCHAR(16) NOT NULL,
            note               VARCHAR(255) NULL,
            KEY idx_viator_watchdog_checked (checked_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

if (!function_exists('viatorWatchdogStatus')) {
    /**
     * Pure, so the alarm itself can be tested without a database.
     *
     * `$expected` is the previous count with the departures that have since PASSED already
     * subtracted - a tour running is not a disappearance. Anything below that is one.
     * A rise is fine (he is still taking bookings on the old account until he switches).
     */
    function viatorWatchdogStatus($expected, $actual) {
        if ($expected === null) { return 'baseline'; }
        return ((int) $actual < (int) $expected) ? 'ALERT' : 'ok';
    }
}

if (!function_exists('viatorWatchdogRun')) {
    /**
     * Count the live future legacy-Viator bookings and compare them with what the previous
     * check implies. Read-only apart from the one row it records.
     *
     * Runs at most once a day (the sync itself fires every 15 minutes); `$force` is for the
     * CLI check and for tests.
     */
    function viatorWatchdogRun($conn, $force = false) {
        ensureViatorAccountColumn($conn);
        ensureViatorWatchdogTable($conn);

        $horizon = (new DateTime('now', new DateTimeZone('Europe/Rome')))->format('Y-m-d');

        $prev = null;
        $r = $conn->query("SELECT * FROM viator_watchdog ORDER BY id DESC LIMIT 1");
        if ($r && $r->num_rows > 0) { $prev = $r->fetch_assoc(); }
        if (!$force && $prev && substr((string) $prev['checked_at'], 0, 10) === $horizon) {
            return null; // already checked today
        }

        $legacy = VIATOR_ACCOUNT_LEGACY;
        $scope = "viator_account = '" . $conn->real_escape_string($legacy) . "'";
        $one = function ($sql) use ($conn) { $r = $conn->query($sql); $x = $r ? $r->fetch_row() : null; return $x ? $x[0] : null; };

        $bookings   = (int) $one("SELECT COUNT(*) FROM tours WHERE $scope AND cancelled = 0 AND date >= '$horizon'");
        $pax        = (int) $one("SELECT COALESCE(SUM(participants),0) FROM tours WHERE $scope AND cancelled = 0 AND date >= '$horizon'");
        $latest     = $one("SELECT MAX(date) FROM tours WHERE $scope AND cancelled = 0");
        $departures = (int) $one("SELECT COUNT(*) FROM (SELECT IF(t.group_id IS NOT NULL, CONCAT('g',t.group_id), CONCAT('t',t.id)) u
                                    FROM tours t WHERE t.$scope AND t.cancelled = 0 AND t.date >= '$horizon' GROUP BY u) x");

        // expected = what was live-and-future last time
        //            - every legacy row whose date has since passed (cancelled or not)
        //            - the change in how many future rows Bokun has marked cancelled.
        // Both corrections are computed from rows we still hold, so this is exact rather than a
        // tolerance: a tour running is not a disappearance, and neither is a cancellation Bokun
        // told us about.
        // Recorded on EVERY run, including the baseline: the next run subtracts the change in
        // it, so a baseline that stored 0 would make the first comparison too lenient.
        $cancelled = (int) $one("SELECT COUNT(*) FROM tours WHERE $scope AND cancelled = 1 AND date >= '$horizon'");
        $expected = null; $passed = 0;
        if ($prev) {
            $prevHorizon = $conn->real_escape_string((string) $prev['horizon']);
            $passed   = (int) $one("SELECT COUNT(*) FROM tours WHERE $scope AND date >= '$prevHorizon' AND date < '$horizon'");
            $expected = max(0, (int) $prev['future_bookings'] - $passed + (int) $prev['cancelled_future'] - $cancelled);
        }
        $status = viatorWatchdogStatus($expected, $bookings);
        $note   = $status === 'ALERT'
            ? 'live future legacy-Viator bookings fell below what the previous check implies'
            : ($prev ? null : 'baseline taken');

        $stmt = $conn->prepare("INSERT INTO viator_watchdog
            (checked_at, horizon, future_bookings, future_departures, future_pax, latest_date,
             expected_bookings, passed_since_last, cancelled_future, status, note)
            VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('siiisiiiss', $horizon, $bookings, $departures, $pax, $latest,
                          $expected, $passed, $cancelled, $status, $note);
        $stmt->execute();
        $stmt->close();

        if ($status === 'ALERT') {
            // Loud on purpose: this is the line that says the thing we built all of 6.9 to prevent
            // may have happened. ~/logs/api-error.log since step 2.1.
            error_log("Step 6.9 VIATOR WATCHDOG ALERT: live future legacy-Viator bookings are {$bookings}, "
                . "expected at least {$expected} ({$passed} departure(s) passed, {$cancelled} cancelled since the last check). "
                . "Backup: ~/backups/viator_bookings_*.jsonl");
        } else {
            error_log("Step 6.9 viator watchdog: {$bookings} booking(s) / {$departures} departure(s) / {$pax} pax still to honour"
                . ($expected === null ? ' (baseline)' : ", expected >= {$expected}"));
        }

        return ['horizon' => $horizon, 'future_bookings' => $bookings, 'future_departures' => $departures,
                'future_pax' => $pax, 'latest_date' => $latest, 'expected_bookings' => $expected,
                'passed_since_last' => $passed, 'cancelled_future' => $cancelled, 'status' => $status];
    }
}

if (!function_exists('viatorWatchdogLatest')) {
    /** The most recent check, for sync-info. Null before the first one has run. */
    function viatorWatchdogLatest($conn) {
        $c = $conn->query("SHOW TABLES LIKE 'viator_watchdog'");
        if (!$c || $c->num_rows === 0) { return null; }
        $r = $conn->query("SELECT checked_at, future_bookings, future_departures, future_pax,
                                  latest_date, expected_bookings, status
                             FROM viator_watchdog ORDER BY id DESC LIMIT 1");
        $row = $r ? $r->fetch_assoc() : null;
        if (!$row) { return null; }
        return [
            'checked_at'        => $row['checked_at'],
            'future_bookings'   => (int) $row['future_bookings'],
            'future_departures' => (int) $row['future_departures'],
            'future_pax'        => (int) $row['future_pax'],
            'latest_date'       => $row['latest_date'],
            'expected_bookings' => $row['expected_bookings'] === null ? null : (int) $row['expected_bookings'],
            'status'            => $row['status'],
        ];
    }
}

if (!function_exists('viatorChannelLabel')) {
    /**
     * What to SHOW for a booking's channel. `tours.booking_channel` itself is never changed -
     * the P&L's commission ladder matches on it (`strpos($ch,'viator')` -> comm_viator, step
     * 6.6/6.7), the sync rewrites it on every update, and every historical figure depends on
     * it. This is display only, so the owner can see at a glance which of two Viator lines on
     * the same day belongs to the account he is retiring.
     */
    function viatorChannelLabel($channel, $account) {
        if ($account === VIATOR_ACCOUNT_LEGACY && viatorIsViatorChannel($channel)) {
            return 'Viator (old account)';
        }
        return (string) $channel;
    }
}
