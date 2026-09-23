<?php
/**
 * participant_helpers.php - step 6.8: the printable participant list for one departure.
 *
 * Functions only - no output, no routing, safe to require_once (the pattern group_helpers.php
 * and manual_helpers.php already use), which is what lets tools/participants_check.php test
 * them without a database.
 *
 * A "departure" here is the SAME tour unit the rest of the system uses -
 * `IF(group_id IS NOT NULL, CONCAT('g',group_id), CONCAT('t',id))` - so an auto-group and a
 * manual merge both resolve to one sheet. No second notion of a departure is invented.
 */

if (!function_exists('participantsParseUnit')) {
    /**
     * 'g1508270' / 't6054' -> ['type' => 'g'|'t', 'id' => int], or null when it is not a unit.
     * Step 6.2's merged COSTING units (m<key>) are deliberately NOT accepted: a P&L merge says
     * two departures shared one guide fee, it does not make them one tour at the museum door.
     */
    function participantsParseUnit($unit) {
        if (!is_string($unit)) { return null; }
        if (!preg_match('/^([gt])(\d{1,10})$/', trim($unit), $m)) { return null; }
        return ['type' => $m[1], 'id' => (int) $m[2]];
    }
}

if (!function_exists('participantsText')) {
    /**
     * Text for the PDF. FPDF's core fonts are CP1252, so anything outside it is transliterated
     * rather than dropped - "Atakhan Yildiz" is readable at a museum door, a box is not.
     *
     * Measured on production: of 7,270 customer names, 6,874 are already CP1252, 388 more
     * transliterate cleanly, and 8 (Chinese, Korean, Greek, Cyrillic) cannot be carried at all.
     * Those 8 get an explicit marker instead of a row of question marks, because a name the
     * sheet cannot spell is better admitted than faked - the booking reference identifies them.
     */
    function participantsText($s, $fallback = '(name in another script - see reference)') {
        $s = (string) $s;
        if ($s === '') { return ''; }
        $out = @iconv('UTF-8', 'CP1252//TRANSLIT', $s);
        if ($out === false) { return $fallback; }
        // How much of it survived? '?' that was not in the original means a lost character.
        $qIn  = substr_count($s, '?');
        $qOut = substr_count($out, '?');
        $lost = max(0, $qOut - $qIn);
        $len  = max(1, mb_strlen($s, 'UTF-8'));
        if ($lost > 0 && ($lost / $len) > 0.3) { return $fallback; }
        return str_replace('?', '', $lost > 0 ? $out : $out) === '' ? $fallback : $out;
    }
}

if (!function_exists('participantsReference')) {
    /**
     * The reference to print. The owner's hand-made lists show the channel's own code
     * (GYGBLHFZ4HKX), which is what a GetYourGuide voucher shows, so that is preferred;
     * Bokun's own confirmation code (GET-104286390) is the fallback.
     */
    function participantsReference($row, $bokun = null) {
        if (is_array($bokun) && !empty($bokun['externalBookingReference'])) {
            return (string) $bokun['externalBookingReference'];
        }
        if (!empty($row['external_id'])) { return (string) $row['external_id']; }
        if (is_array($bokun) && !empty($bokun['confirmationCode'])) { return (string) $bokun['confirmationCode']; }
        if (!empty($row['bokun_booking_id'])) { return (string) $row['bokun_booking_id']; }
        return '-';
    }
}

if (!function_exists('participantsNames')) {
    /**
     * Everyone on the booking, not just whoever paid.
     *
     * `tours.participant_names` holds what the channel gave us ([{first,last}, ...] - the
     * GetYourGuide portal's "Traveler 1, Traveler 2"). When it is there we print all of them,
     * because a list with every name is what the museum actually wants; when it is not, we
     * print the lead name alone and the PAX count carries the rest.
     */
    function participantsNames($row) {
        $names = [];
        $raw = isset($row['participant_names']) ? $row['participant_names'] : null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $p) {
                    if (!is_array($p)) { continue; }
                    $n = trim((string) ($p['first'] ?? '') . ' ' . (string) ($p['last'] ?? ''));
                    if ($n !== '') { $names[] = $n; }
                }
            }
        }
        if (count($names) === 0) {
            $lead = trim((string) ($row['customer_name'] ?? ''));
            if ($lead !== '') { $names[] = $lead; }
        }
        return $names;
    }
}

if (!function_exists('participantsAgency')) {
    /**
     * The reselling agency, i.e. reference A's "Azienda" column.
     *
     * Bokun would carry it as the reseller invoice's issuer. Measured on production: 0 of 2,627
     * bookings in the last 90 days have one, because he sells through the OTAs directly rather
     * than through B2B agencies. The column therefore exists and stays blank; `seller.title` is
     * NOT used for it, because for an OTA that is just the channel again and for a direct sale
     * it is his own company.
     */
    function participantsAgency($bokun) {
        if (!is_array($bokun)) { return ''; }
        $issuer = $bokun['productBookings'][0]['resellerInvoice']['issuer']['title']
            ?? ($bokun['resellerInvoice']['issuer']['title'] ?? null);
        return is_string($issuer) ? $issuer : '';
    }
}

if (!function_exists('participantsFilename')) {
    /**
     * Findable in a Downloads folder six weeks later:
     *   <museum-or-product>-MM-DD-YYYY-HHMM-participants.pdf
     * e.g. uffizi-09-30-2026-1000-participants.pdf
     */
    function participantsFilename($museum, $date, $time) {
        $slug = strtolower((string) $museum);
        $slug = @iconv('UTF-8', 'ASCII//TRANSLIT', $slug) ?: $slug;
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim((string) $slug, '-');
        if ($slug === '') { $slug = 'tour'; }
        // A tour that names no museum falls back to its product name, which can run to 60
        // characters; cut it at a word boundary so the file is still readable in a phone's
        // download list. The date and time after it are what actually identify the sheet.
        if (strlen($slug) > 40) {
            $slug = substr($slug, 0, 40);
            $cut = strrpos($slug, '-');
            if ($cut !== false && $cut >= 20) { $slug = substr($slug, 0, $cut); }
            $slug = rtrim($slug, '-');
        }
        $d = date_create_from_format('Y-m-d', substr((string) $date, 0, 10));
        $datePart = $d ? $d->format('m-d-Y') : 'unknown-date';
        $timePart = preg_replace('/[^0-9]/', '', substr((string) $time, 0, 5));
        if ($timePart === '') { $timePart = '0000'; }
        return sprintf('%s-%s-%s-participants.pdf', $slug, $datePart, $timePart);
    }
}

if (!function_exists('participantsTitleLine')) {
    /**
     * Reference A's one-line summary, in English and with his own wording:
     *   "Uffizi & Accademia Walking Tour - 9 pax - 14:15 - English - 22/09/2026"
     * Everything a guide needs to know he has the right sheet, on one line.
     */
    function participantsTitleLine($product, $pax, $time, $language, $date) {
        $d = date_create_from_format('Y-m-d', substr((string) $date, 0, 10));
        $parts = [
            trim((string) $product),
            $pax . ' pax',
            substr((string) $time, 0, 5),
            trim((string) $language) !== '' ? (string) $language : 'language not set',
            $d ? $d->format('d/m/Y') : (string) $date,
        ];
        return implode(' - ', array_filter($parts, function ($p) { return $p !== ''; }));
    }
}

// ---------------------------------------------------------------------------------------------
// Step 6.11: the DAY sheet for one museum's ticket bookings (Priority Tickets, Uffizi and
// Accademia tabs). One sheet per date, every ticket booking for that museum sorted by time.
// ---------------------------------------------------------------------------------------------

if (!defined('PARTICIPANTS_DAY_MUSEUMS')) { define('PARTICIPANTS_DAY_MUSEUMS', 'Uffizi,Accademia'); }

if (!function_exists('participantsDayMuseum')) {
    /** 'accademia' / 'Uffizi ' -> 'Accademia' / 'Uffizi'; anything else -> null. */
    function participantsDayMuseum($museum) {
        if (!is_string($museum)) { return null; }
        foreach (explode(',', PARTICIPANTS_DAY_MUSEUMS) as $m) {
            if (strcasecmp(trim($museum), $m) === 0) { return $m; }
        }
        return null;
    }
}

if (!function_exists('participantsReferencePair')) {
    /**
     * The owner's choice (2026-09-23): the reference the CUSTOMER holds prints large - GYG... for
     * GetYourGuide, the Viator number for Viator - with our Bokun code (GET-/VIA-...) small
     * underneath. A website booking has no channel reference, so its Bokun code prints alone.
     * Returns ['primary' => string, 'secondary' => string] (secondary may be '').
     */
    function participantsReferencePair($row, $bokun = null) {
        $ours = '';
        if (is_array($bokun) && !empty($bokun['confirmationCode'])) { $ours = (string) $bokun['confirmationCode']; }
        elseif (!empty($row['external_id'])) { $ours = (string) $row['external_id']; }
        elseif (!empty($row['bokun_booking_id'])) { $ours = (string) $row['bokun_booking_id']; }

        $theirs = (is_array($bokun) && !empty($bokun['externalBookingReference']))
            ? (string) $bokun['externalBookingReference'] : '';

        if ($theirs !== '' && $theirs !== $ours) { return ['primary' => $theirs, 'secondary' => $ours]; }
        if ($ours !== '') { return ['primary' => $ours, 'secondary' => '']; }
        return ['primary' => '-', 'secondary' => ''];
    }
}

if (!function_exists('participantsDayFilename')) {
    /** accademia-participants-2026-09-23.pdf */
    function participantsDayFilename($museum, $date) {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $museum)), '-');
        $d = date_create_from_format('Y-m-d', substr((string) $date, 0, 10));
        return sprintf('%s-participants-%s.pdf', $slug !== '' ? $slug : 'tickets', $d ? $d->format('Y-m-d') : 'unknown-date');
    }
}

if (!function_exists('participantsDayBuild')) {
    /**
     * Pure: the day sheet's data from the day's TICKET rows (already limited to product_type
     * 'ticket' and the date by the caller). Each row needs id, title, time, cancelled,
     * participants, customer_name, participant_names, booking_channel, external_id, bokun_data.
     *
     * - The museum comes from each booking's own title through radioMuseumForTitle() (step 6.3),
     *   not from the products table: three ticket products have a blank title there.
     * - Cancelled bookings never print; they are only counted for the footnote.
     * - One section per product (the booking title), each sorted by time; sections in order of
     *   their first booking. A single-product day is one section, printed like his example.
     * - Numbering runs through the whole day, 1..N.
     *
     * $paxOf is computePaxBreakdown (passed in so the CLI checks can use a stand-in).
     */
    function participantsDayBuild(array $rows, $museum, $date, callable $paxOf) {
        $live = [];
        $cancelled = 0;
        foreach ($rows as $r) {
            $m = radioMuseumForTitle($r['title'] ?? '');
            if (!is_array($m) || $m['museum'] !== $museum) { continue; }
            if ((int) ($r['cancelled'] ?? 0) === 1) { $cancelled++; continue; }
            $live[] = $r;
        }
        usort($live, function ($a, $b) {
            $c = strcmp(substr((string) $a['time'], 0, 5), substr((string) $b['time'], 0, 5));
            return $c !== 0 ? $c : ((int) $a['id'] <=> (int) $b['id']);
        });

        $sections = [];
        $tot = ['pax' => 0, 'adults' => 0, 'children' => 0, 'infants' => 0];
        foreach ($live as $r) {
            $bokun = !empty($r['bokun_data']) ? json_decode($r['bokun_data'], true) : null;
            $pax = $paxOf($r['bokun_data'] ?? null, (int) ($r['participants'] ?? 0));
            $n = $pax['adults'] + $pax['children'] + $pax['infants'];
            $product = trim((string) $r['title']);
            if (!isset($sections[$product])) {
                $sections[$product] = ['product' => $product, 'bookings' => [],
                    'pax' => 0, 'adults' => 0, 'children' => 0, 'infants' => 0];
            }
            $ref = participantsReferencePair($r, $bokun);
            $sections[$product]['bookings'][] = [
                'time'            => substr((string) $r['time'], 0, 5),
                'reference'       => $ref['primary'],
                'reference_small' => $ref['secondary'],
                'names'           => participantsNames($r),
                'pax'             => $n,
                'adults'          => $pax['adults'],
                'children'        => $pax['children'],
                'infants'         => $pax['infants'],
                'channel'         => (string) (($r['booking_channel'] ?? '') ?: 'Direct'),
            ];
            foreach (['adults', 'children', 'infants'] as $k) {
                $sections[$product][$k] += $pax[$k];
                $tot[$k] += $pax[$k];
            }
            $sections[$product]['pax'] += $n;
            $tot['pax'] += $n;
        }
        $sections = array_values($sections);

        $d = date_create_from_format('Y-m-d', substr((string) $date, 0, 10));
        $dateShort = $d ? $d->format('d/m/Y') : (string) $date;
        // His example's summary line: "<product> - 7 pax - 23/09/2026". With two products on the
        // day there is no single product to name, so the line names the museum instead.
        $what = count($sections) === 1 ? $sections[0]['product'] : ($museum . ' ticket bookings');

        return [
            'mode' => 'day',
            'museum' => $museum,
            'date' => substr((string) $date, 0, 10),
            'title_line' => $what . ' - ' . $tot['pax'] . ' pax - ' . $dateShort,
            'sections' => $sections,
            'booking_count' => count($live),
            'total_pax' => $tot['pax'], 'total_adults' => $tot['adults'],
            'total_children' => $tot['children'], 'total_infants' => $tot['infants'],
            'cancelled_bookings' => $cancelled,
            'filename' => participantsDayFilename($museum, $date),
        ];
    }
}
