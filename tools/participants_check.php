<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only check (step 6.8): never deployed
/**
 * Step 6.8 unit tests for the participant sheet's rules (no database):
 *   php tools/participants_check.php
 */
require_once __DIR__ . '/../public_html/api/participant_helpers.php';
require_once __DIR__ . '/../public_html/api/participant_sheet.php';

$failures = 0;
function check($label, $ok, $detail = '') {
    global $failures;
    if (!$ok) { $failures++; }
    printf("%s  %s%s\n", $ok ? 'ok  ' : 'FAIL', $label, $detail !== '' ? "  ($detail)" : '');
}

// --- which departure the sheet is for -------------------------------------------------------
check('a group resolves', participantsParseUnit('g1508270') === ['type' => 'g', 'id' => 1508270]);
check('a single tour resolves', participantsParseUnit('t6054') === ['type' => 't', 'id' => 6054]);
check('whitespace is tolerated', participantsParseUnit('  g12 ') === ['type' => 'g', 'id' => 12]);
foreach (['', 'x12', 'g', 'g-1', 'gg1', '12', 'm:m1', 'g1;DROP', null, 'g12345678901'] as $bad) {
    check('rejected: ' . var_export($bad, true), participantsParseUnit($bad) === null);
}
check('a 6.2 COSTING merge is not a departure here', participantsParseUnit('m1') === null);

// --- the reference printed on each row ------------------------------------------------------
$row = ['external_id' => 'GET-104286390', 'bokun_booking_id' => '55512345'];
$bokun = ['externalBookingReference' => 'GYGX7M6HKAQ3', 'confirmationCode' => 'GET-104286390'];
check("the channel's own code wins (what the voucher shows)",
    participantsReference($row, $bokun) === 'GYGX7M6HKAQ3', participantsReference($row, $bokun));
check('Bokun code when the channel gave none',
    participantsReference($row, ['confirmationCode' => 'GET-104286390']) === 'GET-104286390');
check('never empty', participantsReference([], null) === '-');

// --- names ------------------------------------------------------------------------------------
$twoTravellers = ['participant_names' => '[{"first":"Karla","last":"Puga"},{"first":"Tomas","last":"Mendez"}]',
                  'customer_name' => 'Karla Puga'];
check('every traveller is printed when the channel gave them',
    participantsNames($twoTravellers) === ['Karla Puga', 'Tomas Mendez'],
    implode(' | ', participantsNames($twoTravellers)));
check('falls back to the lead name alone',
    participantsNames(['customer_name' => 'Sarah Brown']) === ['Sarah Brown']);
check('nothing at all -> empty, never a made-up name',
    participantsNames(['customer_name' => '', 'participant_names' => 'not json']) === []);

// --- text that a museum door can read -----------------------------------------------------------
check('plain ASCII is untouched', participantsText('Sarah Brown') === 'Sarah Brown');
check('accented Latin survives exactly', participantsText('Michel Hernández Torrado') === iconv('UTF-8', 'CP1252', 'Michel Hernández Torrado'));
check('Begoña survives', participantsText('Begoña Duro Canovas') === iconv('UTF-8', 'CP1252', 'Begoña Duro Canovas'));
$tr = participantsText('Atakhan Yıldız');
check('Turkish dotless i is transliterated, not lost', $tr !== '' && strpos($tr, '?') === false, $tr);
$tr2 = participantsText('Tereza Gabrić');
check('Croatian c-acute is transliterated', $tr2 !== '' && strpos($tr2, '?') === false, $tr2);
check('a name we genuinely cannot spell says so rather than printing boxes',
    participantsText('雪纯 任') === '(name in another script - see reference)', participantsText('雪纯 任'));
check('Greek too', participantsText('ΕΛΕΝΗ ΝΗΣΙΩΤΗ') === '(name in another script - see reference)');
check('empty stays empty', participantsText('') === '');

// --- the reselling agency -------------------------------------------------------------------------
check('an agency is printed when Bokun has one',
    participantsAgency(['productBookings' => [['resellerInvoice' => ['issuer' => ['title' => 'Enjoy Rome']]]]]) === 'Enjoy Rome');
check('blank when there is none (which is every booking today)',
    participantsAgency(['seller' => ['title' => 'GetYourGuide']]) === '');
check('the channel is never passed off as an agency',
    participantsAgency(['seller' => ['title' => 'Florence With Locals Group Tours']]) === '');

// --- the filename he will look for six weeks later ---------------------------------------------------
check('uffizi-09-30-2026-1000-participants.pdf',
    participantsFilename('Uffizi', '2026-09-30', '10:00') === 'uffizi-09-30-2026-1000-participants.pdf',
    participantsFilename('Uffizi', '2026-09-30', '10:00'));
check('spaces and accents become hyphens',
    participantsFilename('Palazzo Vecchio', '2026-08-21', '14:15') === 'palazzo-vecchio-08-21-2026-1415-participants.pdf',
    participantsFilename('Palazzo Vecchio', '2026-08-21', '14:15'));
check('a missing museum still produces a usable name',
    participantsFilename('', '2026-08-21', '09:30') === 'tour-08-21-2026-0930-participants.pdf',
    participantsFilename('', '2026-08-21', '09:30'));

// --- the one-line summary, reference A's idea in his own words ------------------------------------------
$line = participantsTitleLine('Uffizi & Accademia Walking Tour', 9, '14:15', 'English', '2026-09-22');
check('title line carries product, pax, time, language and date',
    $line === 'Uffizi & Accademia Walking Tour - 9 pax - 14:15 - English - 22/09/2026', $line);
$line2 = participantsTitleLine('Uffizi', 2, '09:30', '', '2026-09-22');
check('a missing language says so rather than leaving a gap',
    strpos($line2, 'language not set') !== false, $line2);

// --- the sheet itself renders --------------------------------------------------------------
// participants.php needs a database and a session; participant_sheet.php needs neither, so the
// PDF is built here for real. This is what catches a runtime mistake (a property colliding with
// one of FPDF's own, a font that will not load) before it reaches staging as a 500.
function sheetFixture($overrides = []) {
    $booking = function ($ref, $names, $pax, $channel = 'GetYourGuide', $agency = '') {
        return ['reference' => $ref, 'names' => $names, 'pax' => $pax, 'adults' => $pax,
                'children' => 0, 'infants' => 0, 'channel' => $channel, 'agency' => $agency,
                'manual' => false];
    };
    return array_merge([
        'unit' => 'g1', 'date' => '2026-09-24', 'time' => '09:30',
        'product' => 'Uffizi & Accademia Walking Tour', 'museum' => 'Uffizi', 'language' => 'English',
        'guide_name' => 'Caterina Cavalcaselle', 'guide_phone' => '+39 000 000 0000',
        'title_line' => participantsTitleLine('Uffizi & Accademia Walking Tour', 4, '09:30', 'English', '2026-09-24'),
        'bookings' => [
            $booking('GYGBLHXKHGMK', ['Miyako M Izzo'], 1),
            $booking('GYGBLHKM542N', ['Caroline Merriman', 'Charles Merriman'], 2),
            $booking('GYG7VKNRNAF6', [participantsText('Thais Caroline Nogueira de Albuquerque')], 1, 'Viator', 'Enjoy Rome'),
        ],
        'total_pax' => 4, 'total_adults' => 4, 'total_children' => 0, 'total_infants' => 0,
        'cancelled_bookings' => 1,
        'filename' => 'uffizi-09-24-2026-0930-participants.pdf',
    ], $overrides);
}
$pdf = participantsRenderPdf(sheetFixture());
check('the sheet renders a real PDF', substr($pdf, 0, 5) === '%PDF-', substr($pdf, 0, 8));
check('one page for a normal departure', substr_count($pdf, '/Type /Page' . "
") === 1, substr_count($pdf, '/Type /Page' . "
") . ' page(s)');
check('it is small enough to open on a phone in the street', strlen($pdf) < 20000, strlen($pdf) . ' bytes');

// edge cases that must not throw
$noGuide = participantsRenderPdf(sheetFixture(['guide_name' => '', 'guide_phone' => '']));
check('no guide assigned still prints', substr($noGuide, 0, 5) === '%PDF-');
$empty = participantsRenderPdf(sheetFixture(['bookings' => [], 'total_pax' => 0, 'total_adults' => 0]));
check('a departure with no live bookings still prints', substr($empty, 0, 5) === '%PDF-');
$noMuseum = participantsRenderPdf(sheetFixture(['museum' => '']));
check('a tour that names no museum still prints', substr($noMuseum, 0, 5) === '%PDF-');
$long = sheetFixture();
for ($k = 0; $k < 40; $k++) { $long['bookings'][] = $long['bookings'][1]; }
$long['total_pax'] = 84; $long['total_adults'] = 84;
$big = participantsRenderPdf($long);
check('a long departure breaks onto more pages', substr_count($big, '/Type /Page' . "
") > 1,
    substr_count($big, '/Type /Page' . "
") . ' page(s)');

echo $failures === 0 ? "\nall checks passed\n" : "\n$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
