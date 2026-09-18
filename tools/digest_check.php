<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only check (step 3.10): never deployed
/**
 * Step 3.10 unit tests for the pure digest builder (no database, no Twilio):
 *   php tools/digest_check.php
 * Exit code 0 = all assertions hold.
 */

require_once __DIR__ . '/../public_html/api/guide_digest.php';

$failures = 0;
function check($label, $ok, $detail = '') {
    global $failures;
    if (!$ok) { $failures++; }
    printf("%s  %s%s\n", $ok ? 'ok  ' : 'FAIL', $label, $detail !== '' ? "  ($detail)" : '');
}
$DOT = "\xC2\xB7";

// --- Italian date for {{2}} ------------------------------------------------------------
check("2026-09-17 -> 'gio 17 set' (a Thursday)", digestItalianDate('2026-09-17') === 'gio 17 set', digestItalianDate('2026-09-17'));
check("2026-09-22 -> 'mar 22 set'", digestItalianDate('2026-09-22') === 'mar 22 set', digestItalianDate('2026-09-22'));
check("2026-01-05 -> 'lun 5 gen' (no leading zero)", digestItalianDate('2026-01-05') === 'lun 5 gen', digestItalianDate('2026-01-05'));
check("2026-12-31 -> 'gio 31 dic'", digestItalianDate('2026-12-31') === 'gio 31 dic', digestItalianDate('2026-12-31'));

// --- first name for {{1}} ---------------------------------------------------------------
check("'Caterina Cavalcaselle' -> 'Caterina'", digestFirstName('Caterina Cavalcaselle') === 'Caterina');
check("empty name -> 'Guida'", digestFirstName('  ') === 'Guida');

// --- case 1: a departure with 3 bookings is ONE line -------------------------------------
// (collectDigestDepartures groups by tour unit, so the builder receives one row for it)
$oneDeparture = [['unit' => 'g12', 'start_time' => '09:30', 'title' => 'Uffizi Gallery Tour', 'pax' => 7, 'is_private' => false, 'bookings' => 3]];
$lines = buildDigestLines($oneDeparture);
check('3 bookings in one group -> 1 line', count($lines) === 1, implode(' / ', $lines));
check('... line is "HH:MM title DOT N pax"', $lines[0] === '09:30 Uffizi Gallery Tour ' . $DOT . ' 7 pax', $lines[0]);

// --- case 2: a guide with two departures -> two lines, sorted by time --------------------
$two = [
    ['unit' => 't9', 'start_time' => '14:15', 'title' => 'Accademia Tour', 'pax' => 2, 'is_private' => false],
    ['unit' => 'g3', 'start_time' => '09:30', 'title' => 'Uffizi Tour', 'pax' => 4, 'is_private' => false],
];
$lines = buildDigestLines($two);
check('2 departures -> 2 lines', count($lines) === 2);
check('... sorted by start time', strpos($lines[0], '09:30') === 0 && strpos($lines[1], '14:15') === 0, implode(' / ', $lines));

// --- case 4: no departures -> no lines (the sender skips the guide entirely) -------------
check('no departures -> 0 lines', count(buildDigestLines([])) === 0);

// --- same start time -> human departure marker ------------------------------------------
$same = [
    ['unit' => 'g20', 'start_time' => '09:30', 'title' => 'Uffizi Gallery Tour', 'pax' => 4, 'is_private' => false],
    ['unit' => 'g21', 'start_time' => '09:30', 'title' => 'Uffizi Gallery Tour', 'pax' => 3, 'is_private' => false],
    ['unit' => 't99', 'start_time' => '15:00', 'title' => 'Accademia Tour', 'pax' => 2, 'is_private' => false],
];
$lines = buildDigestLines($same);
check('two lines at the same time are disambiguated',
    strpos($lines[0], 'gruppo A') !== false && strpos($lines[1], 'gruppo B') !== false, implode(' / ', $lines));
check('... the single 15:00 line gets no marker', strpos($lines[2], 'gruppo') === false, $lines[2]);
check('... markers follow departure order (g20 before g21)',
    strpos($lines[0], '4 pax') !== false && strpos($lines[1], '3 pax') !== false);

// --- private departure -------------------------------------------------------------------
$priv = [['unit' => 't5', 'start_time' => '10:00', 'title' => 'Private Uffizi', 'pax' => 2, 'is_private' => true]];
check('private departure is marked', strpos(buildDigestLines($priv)[0], $DOT . ' privato') !== false, buildDigestLines($priv)[0]);

// --- long titles are trimmed, long lists are capped --------------------------------------
$long = [['unit' => 't1', 'start_time' => '09:00', 'title' => str_repeat('Uffizi and Accademia Walking Tour ', 5), 'pax' => 2, 'is_private' => false]];
check('long title trimmed to <= ~70 chars + ellipsis', mb_strlen(buildDigestLines($long)[0], 'UTF-8') < 90, buildDigestLines($long)[0]);
$many = [];
for ($i = 0; $i < 40; $i++) {
    $many[] = ['unit' => 't' . $i, 'start_time' => sprintf('%02d:00', 8 + ($i % 12)), 'title' => 'Uffizi Gallery Guided Tour', 'pax' => 2, 'is_private' => false];
}
$lines = buildDigestLines($many);
check('40 departures -> list capped and overflow summarised',
    count($lines) < 40 && strpos(end($lines), 'e altri') !== false, 'lines=' . count($lines) . ' last=' . end($lines));
check('... body stays under the WhatsApp limit', strlen(implode("\n", $lines)) < 1024, strlen(implode("\n", $lines)) . ' chars');

// --- the whole message -------------------------------------------------------------------
$vars = buildDigestVariables('Caterina Cavalcaselle', '2026-09-22', $two);
check("{{1}} is the first name", $vars['1'] === 'Caterina');
check("{{2}} is the Italian date", $vars['2'] === 'mar 22 set', $vars['2']);
// A WhatsApp template parameter may not contain a newline (Twilio 21656), so the lines are
// comma-joined - exactly like the sample of the approved guide_daily_digest_it template.
check("{{3}} joins the lines with ', ' and has NO newline",
    strpos($vars['3'], ', ') !== false && strpos($vars['3'], "
") === false, json_encode($vars['3']));
$body = renderDigestBody($vars);
check('rendered body matches the approved template',
    strpos($body, 'Ciao Caterina, ecco i tuoi tour di domani mar 22 set:') === 0 && substr($body, -14) === "\nBuona serata!", $body);

// --- a separator fallback (if WhatsApp rejects newlines in a parameter) -------------------
$varsPipe = buildDigestVariables('Anna', '2026-09-22', $two, ' | ');
check('separator is configurable', strpos($varsPipe['3'], ' | ') !== false && strpos($varsPipe['3'], "\n") === false);
check('no template variable ever contains a newline or a tab',
    !preg_match('/[
	]/', $vars['1'] . $vars['2'] . $vars['3']));

// --- the 21:30 Europe/Rome cron window (the server clock is UTC) ---------------------------
$rome = new DateTimeZone('Europe/Rome');
$at = function ($utc) use ($rome) { return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($rome); };
// summer (CEST, UTC+2): the 19:30 UTC run is 21:30 Rome and sends; 20:30 UTC is 22:30 Rome
check('summer 19:30 UTC (= 21:30 Rome) sends', digestIsSendWindow($at('2026-07-01 19:30:00')) === true, $at('2026-07-01 19:30:00')->format('H:i'));
check('summer 20:30 UTC (= 22:30 Rome) does not', digestIsSendWindow($at('2026-07-01 20:30:00')) === false, $at('2026-07-01 20:30:00')->format('H:i'));
// winter (CET, UTC+1): the other way round
check('winter 19:30 UTC (= 20:30 Rome) does not', digestIsSendWindow($at('2026-12-01 19:30:00')) === false, $at('2026-12-01 19:30:00')->format('H:i'));
check('winter 20:30 UTC (= 21:30 Rome) sends', digestIsSendWindow($at('2026-12-01 20:30:00')) === true, $at('2026-12-01 20:30:00')->format('H:i'));
check('midday never sends', digestIsSendWindow($at('2026-07-01 10:00:00')) === false);

// --- masking ------------------------------------------------------------------------------
check('phone masked to the last 4 digits', maskPhone('+39 333 1234567') === '***4567', maskPhone('+39 333 1234567'));
check('empty phone', maskPhone('') === '(no number)');

echo $failures === 0 ? "\nall checks passed\n" : "\n$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
