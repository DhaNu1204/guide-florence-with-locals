<?php
/**
 * Step 7.2b: guides.active / guides.is_partner_agency and products.duration_minutes.
 * Migration: database/migrations/20260929_guides_flags_product_duration.sql; the columns are also
 * self-provisioned here (the project's pattern until Phase 8).
 *
 * Rules (plan 7.2b):
 *  - an INACTIVE guide disappears only where a guide is picked for NEW work (assign dropdowns,
 *    the assistant's free_guides, 7.5's suggestions); everything that shows history keeps them;
 *    a departure already assigned to one keeps showing that guide (never silently unassigned)
 *  - a PARTNER AGENCY is a "guide" row that stands for an agency: free_guides lists it apart
 *  - duration_minutes NULL = unknown: callers fall back to 120 minutes and say so
 */

if (!function_exists('ensureGuideFlagColumns')) {
    function ensureGuideFlagColumns($conn) {
        static $done = false;
        if ($done) return;
        $has = $conn->query("SHOW COLUMNS FROM guides LIKE 'active'");
        if ($has && $has->num_rows === 0) {
            $conn->query("ALTER TABLE guides ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1");
        }
        $has = $conn->query("SHOW COLUMNS FROM guides LIKE 'is_partner_agency'");
        if ($has && $has->num_rows === 0) {
            $conn->query("ALTER TABLE guides ADD COLUMN is_partner_agency TINYINT(1) NOT NULL DEFAULT 0");
        }
        $done = true;
    }
}

if (!function_exists('ensureProductDurationColumn')) {
    function ensureProductDurationColumn($conn) {
        static $done = false;
        if ($done) return;
        $has = $conn->query("SHOW COLUMNS FROM products LIKE 'duration_minutes'");
        if ($has && $has->num_rows === 0) {
            $conn->query("ALTER TABLE products ADD COLUMN duration_minutes SMALLINT NULL DEFAULT NULL");
        }
        $done = true;
    }
}

if (!function_exists('ensureProductMeetingPointColumn')) {
    /** Step 4.6: where a product's guests meet the guide (filled from Bokun startPoints by
     *  tools/product_meeting_point_prefill.php; NULL = not known, the /today page shows nothing). */
    function ensureProductMeetingPointColumn($conn) {
        static $done = false;
        if ($done) return;
        $has = $conn->query("SHOW COLUMNS FROM products LIKE 'meeting_point'");
        if ($has && $has->num_rows === 0) {
            $conn->query("ALTER TABLE products ADD COLUMN meeting_point VARCHAR(255) NULL DEFAULT NULL");
        }
        $done = true;
    }
}

if (!function_exists('guideRowFlags')) {
    /** mysqli returns "1"/"0": the API sends real numbers (0/1) for the two flags. */
    function guideRowFlags(array $row) {
        $row['active'] = array_key_exists('active', $row) ? (int) $row['active'] : 1;
        $row['is_partner_agency'] = array_key_exists('is_partner_agency', $row) ? (int) $row['is_partner_agency'] : 0;
        return $row;
    }
}

if (!function_exists('bokunDurationMinutes')) {
    /**
     * Minutes from a Bokun activity payload (GET /activity.json/{id}): the product-level
     * durationWeeks/Days/Hours/Minutes fields. null when the payload has no usable duration.
     * Pure; tools/assistant_check.php covers it.
     */
    function bokunDurationMinutes($activity) {
        if (!is_array($activity)) return null;
        $parts = ['durationWeeks' => 10080, 'durationDays' => 1440, 'durationHours' => 60, 'durationMinutes' => 1];
        $total = 0; $seen = false;
        foreach ($parts as $k => $mult) {
            if (isset($activity[$k]) && is_numeric($activity[$k])) {
                $seen = true;
                $total += (int) $activity[$k] * $mult;
            }
        }
        if (!$seen || $total <= 0 || $total > 32767) return null; // SMALLINT; 0 = not set in Bokun
        return $total;
    }
}

if (!function_exists('productDurationValid')) {
    /** A duration an admin may store: null (unknown) or 5..1440 minutes. */
    function productDurationValid($v) {
        if ($v === null || $v === '') return true;
        return is_numeric($v) && (int) $v == $v && (int) $v >= 5 && (int) $v <= 1440;
    }
}
