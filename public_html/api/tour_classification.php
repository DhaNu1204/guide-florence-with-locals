<?php
/**
 * tour_classification.php — single source of truth for private-tour classification
 * and per-product PAX capacity. Pure logic: NO DB access, NO side effects, NO
 * payment logic. Safe to require_once from anywhere (guarded against redefinition).
 */

if (!function_exists('isPrivateBooking')) {

    // Products that are ALWAYS private (every rate under them is private).
    function fwlFullyPrivateProducts() {
        return [809837, 828971, 850642, 878643, 911547, 945194, 962886, 947299, 1145330, 1233544, 1115569];
    }

    // Shared products that sell SOME private rates — private only for these rate IDs.
    function fwlMixedPrivateRates() {
        return [
            962885  => [2266785], // "Private Tour - 4 Hours"
            1130528 => [2244449], // "Private Guided Tour"
        ];
    }

    /**
     * Decide whether a booking is a private tour.
     *
     * @param mixed  $productId  Bokun product id (int-ish)
     * @param mixed  $rateId     Bokun rate id from productBookings[0].fields.rateId (int-ish, may be null/empty)
     * @param mixed  $rateTitle  productBookings[0].rateTitle (string, used only as a fallback)
     * @return bool
     */
    function isPrivateBooking($productId, $rateId, $rateTitle) {
        $pid = (int) $productId;

        if (in_array($pid, fwlFullyPrivateProducts(), true)) {
            return true;
        }

        $mixed = fwlMixedPrivateRates();
        if (isset($mixed[$pid])) {
            $hasRate = !($rateId === null || $rateId === '' || $rateId === false);
            if ($hasRate) {
                return in_array((int) $rateId, $mixed[$pid], true);
            }
            // rateId missing → fall back to a trimmed/lowercased rateTitle keyword.
            return strpos(strtolower(trim((string) $rateTitle)), 'private') !== false;
        }

        return false;
    }

    /**
     * Per-product max PAX, derived from a (display) title.
     *   contains 'uffizi'                  -> 9  (covers Uffizi and Uffizi+Accademia combos)
     *   else contains 'accademia'/'david'  -> 19
     *   else                               -> 9
     */
    function getMaxPaxForTitle($title) {
        $t = strtolower((string) $title);
        if (strpos($t, 'uffizi') !== false) return 9;
        if (strpos($t, 'accademia') !== false || strpos($t, 'david') !== false) return 19;
        return 9;
    }

    /**
     * Extract [rateId, rateTitle] from a booking (decoded array or JSON string).
     *   rateId    = productBookings[0].fields.rateId
     *   rateTitle = productBookings[0].rateTitle
     *
     * @return array [mixed $rateId, mixed $rateTitle]
     */
    function bokunRateInfo($bokunData) {
        if (is_string($bokunData)) {
            $bokunData = json_decode($bokunData, true);
        }
        if (!is_array($bokunData)) {
            return [null, null];
        }
        $pb = $bokunData['productBookings'][0] ?? null;
        if (!is_array($pb)) {
            return [null, null];
        }
        $rateId = $pb['fields']['rateId'] ?? null;
        $rateTitle = $pb['rateTitle'] ?? null;
        return [$rateId, $rateTitle];
    }
}
