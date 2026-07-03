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

    /**
     * Participant category breakdown from a booking (decoded array or JSON string).
     *
     * Sums productBookings[0].fields.priceCategoryBookings[].quantity (default 1 when
     * absent) grouped by pricingCategory.ticketCategory: ADULT->adults, CHILD->children,
     * INFANT->infants. When the enum is absent, falls back to a title/bookedTitle keyword
     * ('infant'->infants, 'child'/'children'->children, else adults). If no usable
     * breakdown is found (missing/empty/parse error, or it sums to 0), returns the flat
     * fallback as all-adults (totalParticipants from the JSON if present, else
     * $fallbackParticipants). Mirrors getPaxBreakdown() in src/utils/tourCapacity.js.
     * Pure — no DB, no payment logic.
     *
     * @param mixed $bokunData            decoded array or JSON string (may be null)
     * @param mixed $fallbackParticipants tours.participants (int-ish) used when no breakdown
     * @return array ['adults'=>int, 'children'=>int, 'infants'=>int]
     */
    function computePaxBreakdown($bokunData, $fallbackParticipants = 0) {
        if (is_string($bokunData)) {
            $bokunData = json_decode($bokunData, true);
        }

        $pcb = null;
        $totalParticipants = null;
        if (is_array($bokunData)) {
            $fields = $bokunData['productBookings'][0]['fields'] ?? null;
            if (is_array($fields)) {
                if (isset($fields['totalParticipants'])) {
                    $totalParticipants = (int) $fields['totalParticipants'];
                }
                if (isset($fields['priceCategoryBookings']) && is_array($fields['priceCategoryBookings'])) {
                    $pcb = $fields['priceCategoryBookings'];
                }
            }
        }

        if (is_array($pcb) && count($pcb) > 0) {
            $adults = 0; $children = 0; $infants = 0;
            foreach ($pcb as $el) {
                if (!is_array($el)) continue;
                $qty = (array_key_exists('quantity', $el) && $el['quantity'] !== null) ? (int) $el['quantity'] : 1;
                $pc = (isset($el['pricingCategory']) && is_array($el['pricingCategory'])) ? $el['pricingCategory'] : [];
                $enum = strtoupper((string) ($pc['ticketCategory'] ?? ''));
                if ($enum === 'INFANT') {
                    $infants += $qty;
                } elseif ($enum === 'CHILD') {
                    $children += $qty;
                } elseif ($enum === 'ADULT') {
                    $adults += $qty;
                } else {
                    $label = strtolower((string) ($pc['title'] ?? ($el['bookedTitle'] ?? '')));
                    if (strpos($label, 'infant') !== false) $infants += $qty;
                    elseif (strpos($label, 'child') !== false) $children += $qty;
                    else $adults += $qty;
                }
            }
            if (($adults + $children + $infants) > 0) {
                return ['adults' => $adults, 'children' => $children, 'infants' => $infants];
            }
        }

        // Fallback: no usable breakdown — treat everyone as adults.
        $total = $totalParticipants !== null ? $totalParticipants : (int) $fallbackParticipants;
        if ($total < 0) $total = 0;
        return ['adults' => $total, 'children' => 0, 'infants' => 0];
    }
}
