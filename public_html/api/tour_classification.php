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

if (!function_exists('deriveListFields')) {
    /**
     * Step 4.2: the fields the Tours list used to dig out of bokun_data in the browser,
     * derived once on the server so tours.php?view=list can drop the JSON blob.
     * Mirrors getParticipantCount(), getBookingTime() and getTourLanguage() in
     * src/pages/Tours.jsx. Pure — no DB.
     *
     * @param mixed $bokunData    decoded array or JSON string (may be null)
     * @param mixed $participants tours.participants
     * @param mixed $language     tours.language (wins when set)
     * @param mixed $title        tours.title (last-resort language keyword source)
     * @return array ['total_participants'=>int, 'start_time_str'=>?string, 'language'=>?string]
     */
if (!function_exists('tourLanguageCanonical')) {
    /**
     * Step 6.1: ONE spelling per language, so a filter can match exactly.
     *
     * Production is already clean (5 values: English, Spanish, Italian, German, French - no
     * codes, no case variants, no stray spaces), so this changes nothing today. It exists so a
     * future "EN" / "espanol" / " italian " from a new channel is folded in at sync time instead
     * of quietly becoming a sixth entry in the owner's dropdown.
     *
     * Returns the canonical full English name, or null when there is nothing usable - a row with
     * no language stays NULL and is filterable as "Unknown".
     */
    function tourLanguageCanonical($value) {
        if ($value === null || !is_string($value)) {
            return null;
        }
        $v = trim($value);
        if ($v === '') {
            return null;
        }
        // Only the first word matters ("English guided tour" -> English).
        $key = mb_strtolower($v);
        $map = [
            'en' => 'English',    'eng' => 'English',    'english' => 'English',   'inglese' => 'English',
            'it' => 'Italian',    'ita' => 'Italian',    'italian' => 'Italian',   'italiano' => 'Italian',
            'es' => 'Spanish',    'spa' => 'Spanish',    'spanish' => 'Spanish',   'espanol' => 'Spanish',
            'español' => 'Spanish', 'spagnolo' => 'Spanish',
            'fr' => 'French',     'fra' => 'French',     'french' => 'French',     'francais' => 'French',
            'français' => 'French', 'francese' => 'French',
            'de' => 'German',     'ger' => 'German',     'deu' => 'German',        'german' => 'German',
            'deutsch' => 'German', 'tedesco' => 'German',
            'pt' => 'Portuguese', 'por' => 'Portuguese', 'portuguese' => 'Portuguese',
            'portugues' => 'Portuguese', 'português' => 'Portuguese',
            'ru' => 'Russian',    'russian' => 'Russian',
            'zh' => 'Chinese',    'chinese' => 'Chinese',
            'ja' => 'Japanese',   'japanese' => 'Japanese',
        ];
        if (isset($map[$key])) {
            return $map[$key];
        }
        // A phrase: take the first known language word inside it.
        foreach ($map as $needle => $name) {
            if (mb_strlen($needle) > 2 && mb_strpos($key, $needle) !== false) {
                return $name;
            }
        }
        // Something we do not know: keep it, but in one predictable shape.
        $first = preg_split('/[\s,;\/]+/', $v)[0];
        return $first === '' ? null : mb_convert_case(mb_strtolower($first), MB_CASE_TITLE, 'UTF-8');
    }
}

    function deriveListFields($bokunData, $participants = 0, $language = null, $title = '') {
        if (is_string($bokunData)) {
            $bokunData = json_decode($bokunData, true);
        }
        $booking = (is_array($bokunData) && isset($bokunData['productBookings'][0]) && is_array($bokunData['productBookings'][0]))
            ? $bokunData['productBookings'][0] : null;
        $fields = ($booking && isset($booking['fields']) && is_array($booking['fields'])) ? $booking['fields'] : null;

        $total = 0;
        if ($fields && !empty($fields['totalParticipants'])) {
            $total = (int) $fields['totalParticipants'];
        }
        if ($total <= 0) $total = (int) $participants;
        if ($total <= 0) $total = 1;

        $startTimeStr = ($fields && !empty($fields['startTimeStr']) && is_string($fields['startTimeStr']))
            ? $fields['startTimeStr'] : null;

        $lang = ($language !== null && $language !== '') ? $language : null;
        if ($lang === null && $booking) {
            if (isset($booking['notes']) && is_array($booking['notes'])) {
                foreach ($booking['notes'] as $note) {
                    $body = (is_array($note) && isset($note['body']) && is_string($note['body'])) ? $note['body'] : '';
                    if ($body === '') continue;
                    if (preg_match('/GUIDE\s*:\s*([A-Za-z]+)/i', $body, $m)
                        || preg_match('/Booking languages.*?:\s*([A-Za-z]+)/is', $body, $m)) {
                        $lang = ucfirst(strtolower($m[1]));
                        break;
                    }
                }
            }
            if ($lang === null && $fields && !empty($fields['language']) && is_string($fields['language'])) {
                $lang = $fields['language'];
            }
            if ($lang === null && !empty($booking['product']['language']) && is_string($booking['product']['language'])) {
                $lang = $booking['product']['language'];
            }
            if ($lang === null) {
                $t = !empty($booking['product']['title']) ? $booking['product']['title']
                    : (!empty($booking['title']) ? $booking['title'] : $title);
                $t = strtolower(is_string($t) ? $t : '');
                foreach (['italian' => 'Italian', 'spanish' => 'Spanish', 'french' => 'French', 'german' => 'German', 'english' => 'English'] as $needle => $name) {
                    if (strpos($t, $needle) !== false) { $lang = $name; break; }
                }
            }
        }

        // Step 6.1: one spelling per language, whatever the payload used.
        return ['total_participants' => $total, 'start_time_str' => $startTimeStr,
                'language' => tourLanguageCanonical($lang)];
    }
}

if (!function_exists('bokunCustomerPrice')) {
    /**
     * Step 3.1: what the CUSTOMER paid for a Bokun booking, and in which currency.
     * This is Bokun's retail amount - it has nothing to do with paying the guide, and it
     * is stored in tours.bokun_total_price / tours.bokun_currency, never in tours.paid,
     * payment_status, total_amount_paid or expected_amount (those are local state).
     *
     * Same source order as the retail figure in pnl.php (pnlExtractRevenue): the booking
     * itself, then productBookings[0], then activityBookings[0]; resellerInvoice.total first,
     * then customerInvoice.total, then a positive totalPrice. The currency comes from the
     * same object as the amount (a booking whose top-level currency is USD still carries an
     * EUR invoice). Pure - no DB.
     *
     * @param mixed $bokunData decoded array or JSON string (may be null)
     * @return array ['price' => float|null, 'currency' => string|null]
     */
    function bokunCustomerPrice($bokunData) {
        if (is_string($bokunData)) {
            $bokunData = json_decode($bokunData, true);
        }
        $none = ['price' => null, 'currency' => null];
        if (!is_array($bokunData)) {
            return $none;
        }

        $candidates = [$bokunData];
        foreach (['productBookings', 'activityBookings'] as $k) {
            if (isset($bokunData[$k][0]) && is_array($bokunData[$k][0])) {
                $candidates[] = $bokunData[$k][0];
            }
        }
        $rootCurrency = (isset($bokunData['currency']) && is_string($bokunData['currency'])) ? $bokunData['currency'] : null;
        $clean = function ($c) {
            $c = strtoupper(trim((string) $c));
            return preg_match('/^[A-Z]{3}$/', $c) ? $c : null;
        };

        foreach (['resellerInvoice', 'customerInvoice'] as $invoiceKey) {
            foreach ($candidates as $c) {
                if (isset($c[$invoiceKey]) && is_array($c[$invoiceKey]) && isset($c[$invoiceKey]['total']) && is_numeric($c[$invoiceKey]['total'])) {
                    $inv = $c[$invoiceKey];
                    $currency = $clean($inv['currency'] ?? '') ?: ($clean($c['currency'] ?? '') ?: $clean($rootCurrency));
                    return ['price' => round((float) $inv['total'], 2), 'currency' => $currency];
                }
            }
        }
        foreach ($candidates as $c) {
            if (isset($c['totalPrice']) && is_numeric($c['totalPrice']) && (float) $c['totalPrice'] > 0) {
                return ['price' => round((float) $c['totalPrice'], 2), 'currency' => $clean($c['currency'] ?? '') ?: $clean($rootCurrency)];
            }
        }
        return $none;
    }
}
if (!function_exists('bokunIsRescheduled')) {
    /**
     * Step 3.2: a clock time as 'HH:MM', whatever shape it arrives in.
     * MySQL TIME gives '10:00:00', Bokun's startTimeStr gives '10:00' (sometimes '9:30').
     * Returns null when there is no usable time.
     */
    function bokunNormalizeTime($time) {
        if ($time === null) return null;
        if (!preg_match('/^\s*(\d{1,2}):(\d{2})/', (string) $time, $m)) return null;
        return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
    }

    /** Step 3.2: a date as 'YYYY-MM-DD' (drops any time part). Null when unusable. */
    function bokunNormalizeDate($date) {
        if ($date === null) return null;
        return preg_match('/^\s*(\d{4}-\d{2}-\d{2})/', (string) $date, $m) ? $m[1] : null;
    }

    /**
     * Step 3.2: did the departure really move? Compares NORMALISED values, so
     * '10:00:00' vs '10:00' is NOT a reschedule and '10:00' vs '14:30' is.
     * A side that cannot be parsed never counts as a change (no false flags). Pure - no DB.
     */
    function bokunIsRescheduled($oldDate, $oldTime, $newDate, $newTime) {
        $od = bokunNormalizeDate($oldDate); $nd = bokunNormalizeDate($newDate);
        $ot = bokunNormalizeTime($oldTime); $nt = bokunNormalizeTime($newTime);
        $dateChanged = ($od !== null && $nd !== null && $od !== $nd);
        $timeChanged = ($ot !== null && $nt !== null && $ot !== $nt);
        return $dateChanged || $timeChanged;
    }
}
