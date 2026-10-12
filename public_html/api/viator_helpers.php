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
 *
 * Step 6.18 corrected that: the account IS in the payload. Each Viator account is its own
 * Viator supplier, and Viator's product code carries the supplier number -
 * `productBookings[].productExternalId` is '426324P2' on the old account and '5686765P3' on the
 * new one (proved on production 2026-10-12: every other field is identical). The label is now
 * worked out from that code on every sync, and the cutover date of 6.9 is gone.
 */

if (!defined('VIATOR_ACCOUNT_LEGACY'))  { define('VIATOR_ACCOUNT_LEGACY', 'legacy'); }
if (!defined('VIATOR_ACCOUNT_CURRENT')) { define('VIATOR_ACCOUNT_CURRENT', 'current'); }
if (!defined('VIATOR_ACCOUNT_UNKNOWN')) { define('VIATOR_ACCOUNT_UNKNOWN', 'unknown'); }

// Step 6.18: THE one place the two Viator supplier numbers live. Viator supplier number (the
// digits before 'P' in the product code) => account. A third account = one more line here.
if (!defined('VIATOR_SUPPLIER_ACCOUNTS')) {
    define('VIATOR_SUPPLIER_ACCOUNTS', [
        '426324'  => VIATOR_ACCOUNT_LEGACY,   // old account, being retired
        '5686765' => VIATOR_ACCOUNT_CURRENT,  // new account, live since 2026-10-11
    ]);
}

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
     * Self-provision `tours.viator_account` (beside database/migrations/20260921_tours_viator_account.sql).
     *
     * Step 6.18: a fresh column is filled from the product code (viatorAccountRelabel), not by
     * stamping every Viator row 'legacy' as 6.9 did - that premise stopped being true the day
     * the new account took its first booking.
     */
    function ensureViatorAccountColumn($conn) {
        static $done = false;
        if ($done) { return; }
        $done = true;
        $c = $conn->query("SHOW COLUMNS FROM tours LIKE 'viator_account'");
        if ($c && $c->num_rows === 0) {
            $conn->query("ALTER TABLE tours ADD COLUMN `viator_account` VARCHAR(16) NULL DEFAULT NULL");
            $conn->query("ALTER TABLE tours ADD KEY `idx_tours_viator_account` (`viator_account`)");
            $changes = viatorAccountRelabel($conn, true);
            error_log("Step 6.18: added tours.viator_account and labelled " . count($changes) . " Viator booking(s) from their product code");
        }
    }
}

if (!function_exists('viatorProductCodes')) {
    /** Every non-empty `productBookings[].productExternalId` in a payload (string or decoded). */
    function viatorProductCodes($bokunData) {
        $d = is_string($bokunData) ? json_decode($bokunData, true) : $bokunData;
        $codes = [];
        if (is_array($d) && isset($d['productBookings']) && is_array($d['productBookings'])) {
            foreach ($d['productBookings'] as $pb) {
                $code = is_array($pb) && isset($pb['productExternalId']) ? trim((string) $pb['productExternalId']) : '';
                if ($code !== '') { $codes[] = $code; }
            }
        }
        return array_values(array_unique($codes));
    }
}

if (!function_exists('viatorAccountFromCode')) {
    /** '426324P2' -> 'legacy', '5686765P3' -> 'current', anything else -> 'unknown'. Pure. */
    function viatorAccountFromCode($code) {
        if (!preg_match('/^\s*(\d+)P/i', (string) $code, $m)) { return VIATOR_ACCOUNT_UNKNOWN; }
        $map = VIATOR_SUPPLIER_ACCOUNTS;
        return isset($map[$m[1]]) ? $map[$m[1]] : VIATOR_ACCOUNT_UNKNOWN;
    }
}

if (!function_exists('viatorAccountFromPayload')) {
    /**
     * The account a booking's own Bokun payload says it belongs to. Pure, so
     * tools/viator_check.php can test it.
     *
     *   not a Viator booking                      -> null      (nothing to say)
     *   no product code in the payload            -> null      (no fact: whoever writes must keep what is stored)
     *   code(s) all on one known supplier         -> 'legacy' | 'current'
     *   unknown supplier, or two suppliers mixed  -> 'unknown' (shown as "Viator ?", never guessed)
     *
     * There is deliberately no default to 'legacy': a new-account booking wrongly called old
     * would quietly join the list he is told to honour by hand.
     */
    function viatorAccountFromPayload($channel, $bokunData) {
        if (!viatorIsViatorChannel($channel)) { return null; }
        $codes = viatorProductCodes($bokunData);
        if (!$codes) { return null; }
        $accounts = array_values(array_unique(array_map('viatorAccountFromCode', $codes)));
        return count($accounts) === 1 ? $accounts[0] : VIATOR_ACCOUNT_UNKNOWN;
    }
}

if (!function_exists('viatorAccountForDisplay')) {
    /**
     * The ONE value every screen reads (tours.php list + full, the P&L). Pure.
     *   not a Viator channel  -> null
     *   stored label          -> that label
     *   no label (a hand-entered Viator booking, or anything the sync never classified) -> 'unknown'
     */
    function viatorAccountForDisplay($channel, $stored) {
        if (!viatorIsViatorChannel($channel)) { return null; }
        $s = is_string($stored) ? strtolower(trim($stored)) : '';
        if (in_array($s, [VIATOR_ACCOUNT_LEGACY, VIATOR_ACCOUNT_CURRENT, VIATOR_ACCOUNT_UNKNOWN], true)) { return $s; }
        return VIATOR_ACCOUNT_UNKNOWN;
    }
}

if (!function_exists('viatorAccountRelabel')) {
    /**
     * Re-derive `viator_account` from the stored payload for every Viator row that HAS a product
     * code; rows without one (hand-entered bookings) are never touched, so a label set by hand
     * (tools/viator_account_set.php) survives. `updated_at = updated_at` keeps the row's
     * timestamp: this is a correction of our label, not a change to the booking.
     *
     * Returns [['id' => .., 'from' => .., 'to' => ..], ...]; writes only when $apply.
     */
    function viatorAccountRelabel($conn, $apply) {
        $changes = [];
        $res = $conn->query("SELECT id, booking_channel, viator_account, bokun_data FROM tours
                              WHERE (booking_channel LIKE '%Viator%' OR booking_channel LIKE '%Tripadvisor%')
                                AND bokun_data IS NOT NULL AND bokun_data <> ''");
        if (!$res) { return $changes; }
        $upd = $apply ? $conn->prepare("UPDATE tours SET viator_account = ?, updated_at = updated_at WHERE id = ?") : null;
        while ($r = $res->fetch_assoc()) {
            $to = viatorAccountFromPayload($r['booking_channel'], $r['bokun_data']);
            if ($to === null || $to === $r['viator_account']) { continue; }
            $changes[] = ['id' => (int) $r['id'], 'from' => $r['viator_account'], 'to' => $to];
            if ($upd) {
                $id = (int) $r['id'];
                $upd->bind_param('si', $to, $id);
                $upd->execute();
            }
        }
        if ($upd) { $upd->close(); }
        return $changes;
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
     * CLI check and for tests. `$rebaseline` (step 6.18) records a fresh baseline instead of a
     * comparison - used once, after labels were corrected, so bookings leaving 'legacy' because
     * they were never old-account bookings are not read as disappearances.
     */
    function viatorWatchdogRun($conn, $force = false, $rebaseline = false) {
        ensureViatorAccountColumn($conn);
        ensureViatorWatchdogTable($conn);

        $horizon = (new DateTime('now', new DateTimeZone('Europe/Rome')))->format('Y-m-d');

        $prev = null;
        $r = $conn->query("SELECT * FROM viator_watchdog ORDER BY id DESC LIMIT 1");
        if ($r && $r->num_rows > 0) { $prev = $r->fetch_assoc(); }
        if ($rebaseline) { $prev = null; }
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
            : ($prev ? null : ($rebaseline ? 'baseline re-taken after relabel (step 6.18)' : 'baseline taken'));

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
     *
     * Step 6.18: both accounts are named, from the same viatorAccountForDisplay() value the
     * Tours page and Booking Details read; a Viator booking we cannot place reads "Viator ?".
     */
    function viatorChannelLabel($channel, $account) {
        $a = viatorAccountForDisplay($channel, $account);
        if ($a === VIATOR_ACCOUNT_LEGACY)  { return 'Viator (old account)'; }
        if ($a === VIATOR_ACCOUNT_CURRENT) { return 'Viator (new account)'; }
        if ($a === VIATOR_ACCOUNT_UNKNOWN) { return 'Viator ?'; }
        return (string) $channel;
    }
}
