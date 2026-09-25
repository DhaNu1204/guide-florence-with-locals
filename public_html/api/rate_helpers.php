<?php
/**
 * rate_helpers.php - step 6.14: which Bokun rate a booking was sold on, and the "Vasari" label.
 *
 * Product 1130528 "Uffizi Gallery Guided Tour with Optional Vasari Corridor Visit" (426324P28)
 * is sold on several rates; "Vasari Corridor Access" includes the corridor, "Uffizi Gallery Tour"
 * does not. Bokun puts the rate's title in every booking at productBookings[0].rateTitle
 * (7,361 of 7,361 stored payloads on production, 2026-09-25), so the sync copies it to
 * tours.rate_title and the rule below reads that.
 *
 * Functions and one self-provisioned column only - no output, no routing, safe to require_once,
 * so tools/rate_title_check.php can test the rule without a database.
 */

require_once __DIR__ . '/tour_classification.php';   // bokunRateInfo()

if (!function_exists('rateIsVasari')) {
    /**
     * THE rule, and the only copy of it: a booking includes the Vasari Corridor when its rate
     * title contains "vasari", case-insensitive. The API sends the result as `vasari` so the
     * frontend never re-implements it. Extend it here (e.g. per product) when the owner decides.
     */
    function rateIsVasari($rateTitle) {
        if (!is_string($rateTitle) || trim($rateTitle) === '') { return false; }
        return stripos($rateTitle, 'vasari') !== false;
    }
}

if (!function_exists('rateTitleFromBokun')) {
    /**
     * productBookings[0].rateTitle from a booking (decoded array or JSON string), trimmed,
     * or null when absent or empty. Capped at the column width.
     */
    function rateTitleFromBokun($bokunData) {
        list(, $rateTitle) = bokunRateInfo($bokunData);
        if (!is_string($rateTitle)) { return null; }
        $t = trim($rateTitle);
        if ($t === '') { return null; }
        return mb_substr($t, 0, 255);
    }
}

if (!function_exists('ensureRateTitleColumn')) {
    /**
     * Self-provision tours.rate_title (beside database/migrations/20260925_tours_rate_title.sql).
     * Adding the column writes nothing else; existing rows are filled by
     * tools/rate_title_backfill.php and every later sync fills the rows it touches.
     */
    function ensureRateTitleColumn($conn) {
        static $done = false;
        if ($done) { return; }
        $done = true;
        $c = $conn->query("SHOW COLUMNS FROM tours LIKE 'rate_title'");
        if ($c && $c->num_rows === 0) {
            $conn->query("ALTER TABLE tours ADD COLUMN `rate_title` VARCHAR(255) NULL DEFAULT NULL");
            error_log("Step 6.14: added tours.rate_title");
        }
    }
}
