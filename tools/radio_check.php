<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only check (step 6.3): never deployed
/**
 * Step 6.3 unit tests for the radio order (no database):
 *   php tools/radio_check.php
 * Exit code 0 = all assertions hold.
 */

require_once __DIR__ . '/../public_html/api/radio_helpers.php';

$failures = 0;
function check($label, $ok, $detail = '') {
    global $failures;
    if (!$ok) { $failures++; }
    printf("%s  %s%s\n", $ok ? 'ok  ' : 'FAIL', $label, $detail !== '' ? "  ($detail)" : '');
}
function dep($unit, $time, $pax, $title = 'Uffizi Gallery Small Group Guided Tour', $guideId = 7) {
    $m = radioMuseumForTitle($title);
    return ['unit' => $unit, 'time' => $time, 'pax' => $pax, 'title' => $title,
            'guide_id' => $guideId, 'museum' => $m['museum']];
}

// --- the museum heading ---------------------------------------------------------------------
check('a plain Uffizi tour is Uffizi', radioMuseumForTitle('Uffizi Gallery Small Group Guided Tour with Tickets')['museum'] === 'Uffizi');
check('a David tour is Accademia', radioMuseumForTitle("Exclusive Evening Tour of Michelangelo's David")['museum'] === 'Accademia');
$combo = radioMuseumForTitle('3H Semi Private Guided Tour to Uffizi Gallery & Accademia Gallery');
check('a Uffizi+Accademia combo meets at the Uffizi', $combo['museum'] === 'Uffizi' && $combo['confident'] === false, $combo['why']);
check('the Vasari Corridor option stays under Uffizi',
    radioMuseumForTitle('Uffizi Gallery Guided Tour with Optional Vasari Corridor Visit')['museum'] === 'Uffizi');
check('Bargello has its own heading (owner 2026-09-21)',
    radioMuseumForTitle('Private Tour in Bargello Museum')['museum'] === 'Bargello');
check('Palazzo Vecchio has its own heading',
    radioMuseumForTitle('Palazzo Vecchio Small Group Tour with Art Historian')['museum'] === 'Palazzo Vecchio');
check('a walking tour with no museum falls to the catch-all',
    radioMuseumForTitle('Medici Family Private Guided Walking Tour')['museum'] === RADIO_OTHER_SECTION);
check('the Highlights tour goes under Accademia (starts at the David)',
    radioMuseumForTitle('Private Florence Highlights Tour with David, Duomo Area & Santa Croce')['museum'] === 'Accademia');
check('Pitti / Boboli is Pitti', radioMuseumForTitle('Private Tour-Pitti Palace & Palatina Gallery, Boboli Gardens')['museum'] === 'Pitti');
check('Borghese is Borghese', radioMuseumForTitle('Borghese Gallery Entry Ticket and Audio Guide')['museum'] === 'Borghese');

// --- the time format -------------------------------------------------------------------------
check("'09:30:00' renders as 9:30", radioFormatTime('09:30:00') === '9:30', radioFormatTime('09:30:00'));
check("'14:15:00' renders as 14:15", radioFormatTime('14:15:00') === '14:15');
check("'09:05' renders as 9:05", radioFormatTime('09:05') === '9:05');

// --- the greeting switches at 15:00 Rome -------------------------------------------------------
$rome = new DateTimeZone('Europe/Rome');
check('14:59 Rome is still Ciao,', radioGreeting(new DateTimeImmutable('2026-09-21 14:59', $rome)) === 'Ciao,');
check('15:00 Rome is Buonasera,', radioGreeting(new DateTimeImmutable('2026-09-21 15:00', $rome)) === 'Buonasera,');
check('20:30 Rome is Buonasera,', radioGreeting(new DateTimeImmutable('2026-09-21 20:30', $rome)) === 'Buonasera,');
check('09:00 Rome is Ciao,', radioGreeting(new DateTimeImmutable('2026-09-21 09:00', $rome)) === 'Ciao,');

// --- one line per departure --------------------------------------------------------------------
// A group of 3 bookings totalling 9 PAX is ONE line: the endpoint sums the members, so the unit
// arrives here with pax = 9.
$group = dep('g12', '09:30:00', 9);
$sections = radioBuildSections([$group]);
check('a group of 3 bookings / 9 PAX is one line "9:30 - 9"',
    count($sections) === 1 && count($sections[0]['lines']) === 1 && $sections[0]['lines'][0]['label'] === '9:30 - 9',
    $sections[0]['lines'][0]['label']);

// two departures at the same time are two lines
$two = radioBuildSections([dep('g12', '09:30:00', 9), dep('g13', '09:30:00', 9)]);
check('two departures at 9:30 are two separate lines',
    count($two[0]['lines']) === 2 && $two[0]['lines'][0]['label'] === '9:30 - 9' && $two[0]['lines'][1]['label'] === '9:30 - 9');

// --- totals ---------------------------------------------------------------------------------------
$mixed = [dep('g12', '09:30:00', 9), dep('t5', '10:00:00', 4), dep('t6', '14:00:00', 3, 'Uffizi Gallery Tour', null)];
$totals = radioTotals($mixed);
check('receivers = guests only (9+4+3 = 16)', $totals['receivers'] === 16, $totals['receivers']);
check('transmitters = departures WITH a guide (2 of 3)', $totals['transmitters'] === 2, $totals['transmitters']);
check('a departure with no guide still appears in the list', count($mixed) === 3);
check('total units = receivers + transmitters', $totals['units'] === 18, $totals['units']);

// --- section order ------------------------------------------------------------------------------
$ordered = radioBuildSections([
    dep('t1', '17:00:00', 4, 'David and Accademia Gallery VIP Tour'),
    dep('t2', '09:00:00', 3, 'Uffizi Gallery Small Group Guided Tour'),
    dep('t3', '11:00:00', 2, 'Medici Family Private Guided Walking Tour'),
    dep('t4', '12:00:00', 5, 'Borghese Gallery Guided Tour'),
]);
check('sections run Uffizi, Accademia, other museums, catch-all last',
    array_column($ordered, 'museum') === ['Uffizi', 'Accademia', 'Borghese', RADIO_OTHER_SECTION],
    implode(' / ', array_column($ordered, 'museum')));

// --- the rendered message, character for character -------------------------------------------------
// The owner's first real example, rebuilt from data.
$fixture = [
    dep('t1', '09:00:00', 3), dep('g1', '09:30:00', 9), dep('g2', '09:30:00', 9),
    dep('t2', '10:00:00', 4), dep('g3', '14:15:00', 9), dep('t3', '14:30:00', 7),
];
$expected = "Ciao,\n\nUffizi\n9:00 - 3\n9:30 - 9\n9:30 - 9\n10:00 - 4\n14:15 - 9\n14:30 - 7\n\nGrazie";
$rendered = radioRenderMessage(radioBuildSections($fixture), 'Ciao,');
check('the message matches his first example exactly', $rendered === $expected,
    $rendered === $expected ? '' : json_encode($rendered));

// His second example: two museums, evening greeting.
$fixture2 = [
    dep('t1', '10:30:00', 5), dep('t2', '14:00:00', 4), dep('t3', '14:15:00', 4),
    dep('t4', '17:00:00', 4, 'David and Accademia Gallery VIP Tour'),
];
$expected2 = "Buonasera,\n\nUffizi\n10:30 - 5\n14:00 - 4\n14:15 - 4\n\nAccademia\n17:00 - 4\n\nGrazie";
$rendered2 = radioRenderMessage(radioBuildSections($fixture2), 'Buonasera,');
check('the message matches his second example exactly', $rendered2 === $expected2,
    $rendered2 === $expected2 ? '' : json_encode($rendered2));

// --- nothing to send ---------------------------------------------------------------------------------
check('a day with no departures still renders a sane message',
    radioRenderMessage(radioBuildSections([]), 'Ciao,') === "Ciao,\n\nGrazie");

echo $failures === 0 ? "\nall checks passed\n" : "\n$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
