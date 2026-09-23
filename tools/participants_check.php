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
$long = participantsFilename("Florence: Michelangelo's Life and Legacy 3.5 Hr Guided Tour", '2026-08-21', '09:30');
check('a long product name is cut at a word, not left 90 characters long',
    $long === 'florence-michelangelo-s-life-and-legacy-08-21-2026-0930-participants.pdf', $long);
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

// =====================================================================================
// Step 6.11: the DAY sheet (Priority Tickets, Uffizi / Accademia tabs)
// =====================================================================================
require_once __DIR__ . '/../public_html/api/radio_helpers.php';
require_once __DIR__ . '/../public_html/api/tour_classification.php';

check('museum: accademia -> Accademia', participantsDayMuseum('accademia') === 'Accademia');
check('museum: Uffizi -> Uffizi', participantsDayMuseum(' Uffizi') === 'Uffizi');
foreach (['Borghese', 'Palazzo Vecchio', '', null, 'Uffizi;x'] as $bad) {
    check('museum rejected: ' . var_export($bad, true), participantsDayMuseum($bad) === null);
}

// reference: the customer's own large, ours small underneath; a website booking has only ours
$gyg = participantsReferencePair(['external_id' => 'GET-104496297'],
    ['externalBookingReference' => 'GYGBLHFZ4HKX', 'confirmationCode' => 'GET-104496297']);
check('GetYourGuide: GYG code large, GET- small', $gyg === ['primary' => 'GYGBLHFZ4HKX', 'secondary' => 'GET-104496297']);
$via = participantsReferencePair([], ['externalBookingReference' => '1426885809', 'confirmationCode' => 'VIA-98167710']);
check('Viator: the Viator number large, VIA- small', $via === ['primary' => '1426885809', 'secondary' => 'VIA-98167710']);
$web = participantsReferencePair(['external_id' => 'WEB-98701751'], ['confirmationCode' => 'WEB-98701751']);
check('website: our code alone', $web === ['primary' => 'WEB-98701751', 'secondary' => '']);
check('nothing at all: a dash, never empty', participantsReferencePair([], null) === ['primary' => '-', 'secondary' => '']);

check('filename accademia-participants-2026-09-23.pdf',
    participantsDayFilename('Accademia', '2026-09-23') === 'accademia-participants-2026-09-23.pdf');

// fixture day: the owner's example (23 Sep) plus things that must NOT print
$bd = function ($ad, $ch = 0, $in = 0, $conf = 'GET-1', $ext = '') {
    $cats = [];
    if ($ad) { $cats[] = ['quantity' => $ad, 'pricingCategory' => ['ticketCategory' => 'ADULT']]; }
    if ($ch) { $cats[] = ['quantity' => $ch, 'pricingCategory' => ['ticketCategory' => 'CHILD']]; }
    if ($in) { $cats[] = ['quantity' => $in, 'pricingCategory' => ['ticketCategory' => 'INFANT']]; }
    $b = ['confirmationCode' => $conf,
          'productBookings' => [['fields' => ['priceCategoryBookings' => $cats, 'totalParticipants' => $ad + $ch + $in]]]];
    if ($ext !== '') { $b['externalBookingReference'] = $ext; }
    return json_encode($b);
};
$AUDIO = 'Accademia Gallery Entry Ticket with Exclusive Audio Guide App';
$SKIP  = 'Florence: Accademia Gallery Skip-the-Line Entry Ticket';
$row = function ($id, $time, $title, $name, $pax, $bokun, $channel = 'GetYourGuide', $cancelled = 0) {
    return ['id' => $id, 'time' => $time, 'title' => $title, 'customer_name' => $name, 'participants' => $pax,
            'participant_names' => null, 'booking_channel' => $channel, 'external_id' => null,
            'bokun_data' => $bokun, 'cancelled' => $cancelled];
};
$day = [
    $row(20, '14:00:00', $AUDIO, 'Breana Chauntler', 2, $bd(2, 0, 0, 'GET-104310646', 'GYGAAA111BBB')),
    $row(10, '12:30:00', $AUDIO, 'Rodolfo Irizar', 5, $bd(5, 0, 0, 'GET-104496297', 'GYGCCC222DDD')),
    $row(30, '10:00:00', $AUDIO, 'Cancelled Person', 3, $bd(3), 'GetYourGuide', 1),
    $row(40, '09:00:00', 'Florence: Uffizi Gallery Reserved Ticket & Digital Audio Guide', 'Uffizi Guest', 2, $bd(2)),
    $row(50, '11:00:00', 'Borghese Gallery Entry Ticket and Audio Guide', 'Borghese Guest', 2, $bd(2)),
];
$one = participantsDayBuild($day, 'Accademia', '2026-09-23', 'computePaxBreakdown');
$times = array_column($one['sections'][0]['bookings'] ?? [], 'time');
check("his example: one section", count($one['sections']) === 1, count($one['sections']) . ' section(s)');
check('sorted by time (12:30 before 14:00)', $times === ['12:30', '14:00'], implode(',', $times));
check('only Accademia: no Uffizi, no Borghese', $one['booking_count'] === 2, (string) $one['booking_count']);
check('cancelled never printed, but counted', $one['cancelled_bookings'] === 1
    && !in_array('Cancelled Person', array_merge(...array_map(function ($b) { return $b['names']; }, $one['sections'][0]['bookings']))));
check('total 7 pax / 7 adults', $one['total_pax'] === 7 && $one['total_adults'] === 7, $one['total_pax'] . '/' . $one['total_adults']);
check('title line as in his example', $one['title_line'] === $AUDIO . ' - 7 pax - 23/09/2026', $one['title_line']);
check('the customer holds the GYG code', $one['sections'][0]['bookings'][0]['reference'] === 'GYGCCC222DDD'
    && $one['sections'][0]['bookings'][0]['reference_small'] === 'GET-104496297');

// two products on one day -> two headed sections with their own subtotals
$two = $day;
$two[] = $row(60, '13:00:00', $SKIP, 'Ana Child', 3, $bd(1, 1, 1, 'VIA-1', '1426885809'), 'Viator.com');
$two[] = $row(61, '08:30:00', $SKIP, 'Early Bird', 1, $bd(1, 0, 0, 'WEB-5'), 'www.florencewithlocals.com');
$b2 = participantsDayBuild($two, 'Accademia', '2026-09-23', 'computePaxBreakdown');
check('two products -> two sections', count($b2['sections']) === 2, count($b2['sections']) . ' section(s)');
check('sections in order of their first booking (08:30 product first)',
    ($b2['sections'][0]['product'] ?? '') === $SKIP, $b2['sections'][0]['product'] ?? '');
check('subtotals 4 and 7', ($b2['sections'][0]['pax'] ?? 0) === 4 && ($b2['sections'][1]['pax'] ?? 0) === 7,
    ($b2['sections'][0]['pax'] ?? '?') . ' + ' . ($b2['sections'][1]['pax'] ?? '?'));
check('children and infants carried', $b2['sections'][0]['children'] === 1 && $b2['sections'][0]['infants'] === 1);
check('day total 11', $b2['total_pax'] === 11, (string) $b2['total_pax']);
check('two products: the line names the museum', $b2['title_line'] === 'Accademia ticket bookings - 11 pax - 23/09/2026', $b2['title_line']);
check('the Uffizi tab builds from the same code', participantsDayBuild($day, 'Uffizi', '2026-09-23', 'computePaxBreakdown')['total_pax'] === 2);

$pages = function ($pdf) { return substr_count($pdf, '/Type /Page' . "\n"); };
$p1 = participantsRenderDayPdf($one);
check('day sheet renders a real PDF', substr($p1, 0, 5) === '%PDF-', substr($p1, 0, 8));
check('his example fits one page', $pages($p1) === 1, $pages($p1) . ' page(s)');
check('day sheet is small', strlen($p1) < 20000, strlen($p1) . ' bytes');
$p2 = participantsRenderDayPdf($b2);
check('two-product sheet renders, one page', substr($p2, 0, 5) === '%PDF-' && $pages($p2) === 1, $pages($p2) . ' page(s)');
$p0 = participantsRenderDayPdf(participantsDayBuild([$day[2]], 'Accademia', '2026-09-23', 'computePaxBreakdown'));
check('a day with only a cancellation still prints', substr($p0, 0, 5) === '%PDF-');
$pe = participantsRenderDayPdf(participantsDayBuild([], 'Uffizi', '2026-09-23', 'computePaxBreakdown'));
check('an empty day still prints', substr($pe, 0, 5) === '%PDF-');
$many = $two;
for ($k = 0; $k < 45; $k++) { $many[] = $row(100 + $k, sprintf('%02d:%02d:00', 8 + intdiv($k, 6), ($k % 6) * 10), $AUDIO,
    'Zoë Müller-Łukasz ' . $k, 2, $bd(2, 0, 0, 'GET-9' . $k, 'GYGZZZ' . $k)); }
$pm = participantsRenderDayPdf(participantsDayBuild($many, 'Accademia', '2026-09-23', 'computePaxBreakdown'));
check('a busy day breaks onto more pages', $pages($pm) > 1, $pages($pm) . ' page(s)');
if (getenv('FWL_WRITE_SAMPLES')) {
    file_put_contents(getenv('FWL_WRITE_SAMPLES') . '/day-one.pdf', $p1);
    file_put_contents(getenv('FWL_WRITE_SAMPLES') . '/day-two.pdf', $p2);
    file_put_contents(getenv('FWL_WRITE_SAMPLES') . '/day-many.pdf', $pm);
}

echo $failures === 0 ? "\nall checks passed\n" : "\n$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
