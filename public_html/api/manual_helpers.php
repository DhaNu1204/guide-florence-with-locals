<?php
/**
 * manual_helpers.php - step 6.4: the rules behind a hand-entered departure.
 *
 * Functions only - no output, no routing, safe to require_once (same pattern as
 * group_helpers.php / payment_helpers.php), which is what makes them testable from
 * tools/manual_check.php without a database.
 *
 * Why manual rows exist: a GetYourGuide listing whose Connectivity Settings say
 * "Not connected" never reaches Bokun, so no sync can ever produce it. The owner
 * records the departure by hand so it still gets a guide, a radio line, a digest
 * line and a P&L row. Such a row carries `source = 'manual'` and `product_id NULL`.
 */

if (!defined('MANUAL_TOUR_SOURCE')) {
    define('MANUAL_TOUR_SOURCE', 'manual');
}

if (!function_exists('manualIsManualRow')) {
    /** One definition of "this row was typed in, not synced", used everywhere. */
    function manualIsManualRow($row) {
        if (is_array($row)) {
            $row = array_key_exists('source', $row) ? $row['source'] : null;
        }
        return is_string($row) && strtolower(trim($row)) === MANUAL_TOUR_SOURCE;
    }
}

if (!function_exists('ensureManualColumns')) {
    /**
     * Self-provision the three columns a manual row needs (the pattern the rest of the
     * API uses until Phase 8), beside database/migrations/20260921_tours_manual_source.sql.
     * Called by every endpoint whose SQL names them, so a half-migrated database cannot
     * take the sync down.
     */
    function ensureManualColumns($conn) {
        static $done = false;
        if ($done) { return; }
        $done = true;
        $c = $conn->query("SHOW COLUMNS FROM tours LIKE 'source'");
        if ($c && $c->num_rows === 0) {
            $conn->query("ALTER TABLE tours
                              ADD COLUMN `source` VARCHAR(16) NULL DEFAULT NULL AFTER `external_source`,
                              ADD COLUMN `manual_revenue` DECIMAL(10,2) NULL DEFAULT NULL AFTER `source`,
                              ADD COLUMN `manual_currency` CHAR(3) NULL DEFAULT NULL AFTER `manual_revenue`");
            $conn->query("ALTER TABLE tours ADD KEY `idx_tours_source` (`source`)");
            error_log("Step 6.4: added tours.source / manual_revenue / manual_currency");
        }
    }
}

if (!function_exists('manualNormalizeTime')) {
    /**
     * 'HH:MM' from whatever the browser or MySQL hands us ('9:30', '09:30:00').
     * Returns null when the value is not a real time - the caller turns that into a 400.
     */
    function manualNormalizeTime($value) {
        if (!is_string($value) && !is_int($value)) { return null; }
        $v = trim((string) $value);
        if (!preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $v, $m)) { return null; }
        $h = (int) $m[1];
        $i = (int) $m[2];
        if ($h < 0 || $h > 23 || $i < 0 || $i > 59) { return null; }
        return sprintf('%02d:%02d', $h, $i);
    }
}

if (!function_exists('manualNormalizeDate')) {
    /** 'YYYY-MM-DD' that is a REAL calendar date (2026-02-31 is refused), else null. */
    function manualNormalizeDate($value) {
        if (!is_string($value)) { return null; }
        $v = trim($value);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) { return null; }
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) { return null; }
        return $v;
    }
}

if (!function_exists('manualNormalizeCurrency')) {
    /** Three letters, upper case; empty input means EUR. Null = not a currency code. */
    function manualNormalizeCurrency($value) {
        if ($value === null || (is_string($value) && trim($value) === '')) { return 'EUR'; }
        if (!is_string($value)) { return null; }
        $v = strtoupper(trim($value));
        return preg_match('/^[A-Z]{3}$/', $v) ? $v : null;
    }
}

if (!function_exists('manualTourErrors')) {
    /**
     * The single gate on a hand-entered departure. Returns null when the input is
     * acceptable, or the message to send with HTTP 400.
     *
     * The amount is optional (the owner does not always know yet what GetYourGuide
     * will pay him) but when present it passes the SAME check as a payment amount -
     * step 3.8's paymentAmountError() - so "abc", 0, -5 and 999999999 are refused here
     * too. The guide is optional on purpose: a departure is normally entered before
     * anyone is free to run it.
     */
    function manualTourErrors($in) {
        if (!is_array($in)) { return 'Invalid request body'; }

        $title = isset($in['title']) && is_string($in['title']) ? trim($in['title']) : '';
        if ($title === '') { return 'A tour name is required'; }
        if (mb_strlen($title) > 255) { return 'The tour name is too long (max 255 characters)'; }

        if (manualNormalizeDate(isset($in['date']) ? $in['date'] : null) === null) {
            return 'Date must be a real date in YYYY-MM-DD format';
        }
        if (manualNormalizeTime(isset($in['time']) ? $in['time'] : null) === null) {
            return 'Start time must be in HH:MM format';
        }

        $pax = isset($in['participants']) ? $in['participants'] : null;
        if ($pax === null || is_array($pax) || is_bool($pax) || !is_numeric($pax)
            || (float) $pax != (int) $pax || (int) $pax < 1) {
            return 'Participants must be a whole number of 1 or more';
        }
        if ((int) $pax > 200) { return 'Participants looks wrong (over 200)'; }

        $channel = isset($in['booking_channel']) && is_string($in['booking_channel'])
            ? trim($in['booking_channel']) : '';
        if ($channel === '') { return 'A channel is required (e.g. GetYourGuide (direct))'; }
        if (mb_strlen($channel) > 100) { return 'The channel name is too long (max 100 characters)'; }

        $amount = isset($in['manual_revenue']) ? $in['manual_revenue'] : null;
        if ($amount !== null && !(is_string($amount) && trim($amount) === '')) {
            if (function_exists('paymentAmountError')) {
                $err = paymentAmountError($amount);
                if ($err !== null) { return $err; }
            } elseif (!is_numeric($amount) || (float) $amount <= 0) {
                return 'Amount must be a number greater than 0';
            }
        }
        if (manualNormalizeCurrency(isset($in['manual_currency']) ? $in['manual_currency'] : null) === null) {
            return 'Currency must be a three-letter code such as EUR';
        }

        if (isset($in['notes']) && is_string($in['notes']) && mb_strlen($in['notes']) > 2000) {
            return 'Notes are too long (max 2000 characters)';
        }
        return null;
    }
}

if (!function_exists('manualDuplicateKey')) {
    /**
     * What makes two departures "possibly the same one": the same DATE, the same
     * HH:MM and the same PAX. Deliberately coarse - it only ever raises a flag for
     * the owner to look at, it never merges anything.
     */
    function manualDuplicateKey($date, $time, $pax) {
        $d = manualNormalizeDate(substr((string) $date, 0, 10));
        $t = manualNormalizeTime($time);
        if ($d === null || $t === null) { return null; }
        return $d . '|' . $t . '|' . (int) $pax;
    }
}

if (!function_exists('manualGuideIdError')) {
    /**
     * A guide is optional on a manual departure, but if one is named it must exist.
     * Returns null when acceptable, or the message for the 400.
     */
    function manualGuideIdError($conn, $guideId) {
        if ($guideId === null || $guideId === '' || $guideId === 0 || $guideId === '0') { return null; }
        if (!is_numeric($guideId) || (int) $guideId < 1) { return 'Unknown guide'; }
        $stmt = $conn->prepare("SELECT id FROM guides WHERE id = ?");
        $id = (int) $guideId;
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $found = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $found ? null : 'Unknown guide';
    }
}

if (!function_exists('manualTourFields')) {
    /**
     * The validated input turned into exactly the columns the INSERT/UPDATE binds.
     * Call only after manualTourErrors() has returned null.
     *
     * `language` goes through step 6.1's tourLanguageCanonical() so a hand-typed "eng"
     * lands in the same bucket as the sync's "English" and the language filter finds it.
     * `product_id` is not here at all: a manual row never has one.
     */
    function manualTourFields($conn, $in) {
        $guideId = isset($in['guide_id']) ? $in['guide_id'] : null;
        $guideId = ($guideId === null || $guideId === '' || (int) $guideId < 1) ? null : (int) $guideId;

        $language = isset($in['language']) ? $in['language'] : null;
        if (function_exists('tourLanguageCanonical')) {
            $language = tourLanguageCanonical($language);
        } elseif (is_string($language)) {
            $language = trim($language) === '' ? null : trim($language);
        }

        $revenue = isset($in['manual_revenue']) ? $in['manual_revenue'] : null;
        $revenue = ($revenue === null || (is_string($revenue) && trim($revenue) === ''))
            ? null : round((float) $revenue, 2);

        $notes = isset($in['notes']) && is_string($in['notes']) ? trim($in['notes']) : null;

        return [
            'title'                  => trim((string) $in['title']),
            'date'                   => manualNormalizeDate($in['date']),
            'time'                   => manualNormalizeTime($in['time']) . ':00',
            'participants'           => (int) $in['participants'],
            'language'               => $language,
            'guide_id'               => $guideId,
            'booking_channel'        => trim((string) $in['booking_channel']),
            'manual_revenue'         => $revenue,
            'manual_currency'        => $revenue === null ? null
                                        : manualNormalizeCurrency(isset($in['manual_currency']) ? $in['manual_currency'] : null),
            'notes'                  => ($notes === null || $notes === '') ? null : $notes,
            'needs_guide_assignment' => $guideId === null ? 1 : 0,
        ];
    }
}

if (!function_exists('manualLoadTour')) {
    /** One tour row with its guide name, in the shape the Tours page already renders. */
    function manualLoadTour($conn, $id) {
        $stmt = $conn->prepare("SELECT t.*, g.name AS guide_name
                                  FROM tours t LEFT JOIN guides g ON g.id = t.guide_id
                                 WHERE t.id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) { return null; }
        $row['id'] = (int) $row['id'];
        $row['cancelled'] = !empty($row['cancelled']);
        $row['paid'] = !empty($row['paid']);
        $row['is_manual'] = manualIsManualRow($row);
        if (array_key_exists('manual_revenue', $row)) {
            $row['manual_revenue'] = $row['manual_revenue'] !== null ? (float) $row['manual_revenue'] : null;
        }
        return $row;
    }
}

if (!function_exists('toursAttachDuplicateFlags')) {
    /**
     * Add `possible_duplicate_of` (a list of tour ids) to the rows about to be returned.
     *
     * Read-only by design: the sync may not touch a manual row, so the flag cannot live
     * in a column the sync would have to maintain. One cheap count decides whether any
     * work is needed at all - on a range with no manual departures this costs a single
     * indexed COUNT and nothing else, which is every range until the owner adds his first.
     */
    function toursAttachDuplicateFlags($conn, $tours) {
        if (!is_array($tours) || count($tours) === 0) { return $tours; }
        $dates = [];
        foreach ($tours as $t) {
            $d = isset($t['date']) ? substr((string) $t['date'], 0, 10) : null;
            if ($d !== null && manualNormalizeDate($d) !== null) { $dates[$d] = true; }
        }
        if (count($dates) === 0) { return $tours; }
        $dates = array_keys($dates);
        $ph = implode(',', array_fill(0, count($dates), '?'));
        $types = str_repeat('s', count($dates));

        $chk = $conn->prepare("SELECT COUNT(*) c FROM tours WHERE source = ? AND cancelled = 0 AND date IN ($ph)");
        $src = MANUAL_TOUR_SOURCE;
        $chk->bind_param('s' . $types, $src, ...$dates);
        $chk->execute();
        $has = (int) ($chk->get_result()->fetch_assoc()['c'] ?? 0);
        $chk->close();
        if ($has === 0) {
            foreach ($tours as &$t) { $t['possible_duplicate_of'] = []; }
            unset($t);
            return $tours;
        }

        $stmt = $conn->prepare("SELECT id, date, time, participants, source, cancelled
                                  FROM tours WHERE date IN ($ph)");
        $stmt->bind_param($types, ...$dates);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) { $rows[] = $r; }
        $stmt->close();

        $pairs = manualFindDuplicates($rows);
        foreach ($tours as &$t) {
            $id = isset($t['id']) ? (int) $t['id'] : 0;
            $t['possible_duplicate_of'] = isset($pairs[$id]) ? $pairs[$id] : [];
        }
        unset($t);
        return $tours;
    }
}

if (!function_exists('manualCountDuplicateCandidates')) {
    /**
     * How many manual departures in a date range now look like something Bokun is also
     * sending. Used only for the one line the sync report prints - it writes nothing.
     */
    function manualCountDuplicateCandidates($conn, $startDate, $endDate) {
        $stmt = $conn->prepare("SELECT id, date, time, participants, source, cancelled
                                  FROM tours WHERE date >= ? AND date <= ?");
        $stmt->bind_param('ss', $startDate, $endDate);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        $manual = 0;
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
            if (manualIsManualRow($r) && empty($r['cancelled'])) { $manual++; }
        }
        $stmt->close();
        if ($manual === 0) { return ['manual' => 0, 'flagged' => 0]; }
        $pairs = manualFindDuplicates($rows);
        $flagged = 0;
        foreach ($rows as $r) {
            if (manualIsManualRow($r) && !empty($pairs[(int) $r['id']])) { $flagged++; }
        }
        return ['manual' => $manual, 'flagged' => $flagged];
    }
}

if (!function_exists('manualFindDuplicates')) {
    /**
     * Pair hand-entered rows with synced rows that look like the same departure.
     *
     * Input: rows with id, date, time, participants, source, cancelled.
     * Output: tourId => [counterpart ids], set on BOTH sides so either row can show
     * the badge. Cancelled rows on either side are ignored (a cancelled booking is
     * not a departure), and two manual rows are never paired with each other - the
     * point of the flag is "Bokun has started sending this one as well".
     */
    function manualFindDuplicates($rows) {
        $manual = [];
        $synced = [];
        foreach ($rows as $r) {
            if (!empty($r['cancelled'])) { continue; }
            $key = manualDuplicateKey(
                isset($r['date']) ? $r['date'] : null,
                isset($r['time']) ? $r['time'] : null,
                isset($r['participants']) ? $r['participants'] : 0
            );
            if ($key === null) { continue; }
            if (manualIsManualRow($r)) {
                $manual[$key][] = (int) $r['id'];
            } else {
                $synced[$key][] = (int) $r['id'];
            }
        }
        $out = [];
        foreach ($manual as $key => $manualIds) {
            if (!isset($synced[$key])) { continue; }
            foreach ($manualIds as $mid) {
                $out[$mid] = array_values($synced[$key]);
            }
            foreach ($synced[$key] as $sid) {
                $out[$sid] = array_values($manualIds);
            }
        }
        return $out;
    }
}
