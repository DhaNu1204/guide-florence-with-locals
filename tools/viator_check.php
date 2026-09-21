<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only check (step 6.9): never deployed
/**
 * Step 6.9 unit tests for the old/new Viator account rules (no database):
 *   php tools/viator_check.php
 */
require_once __DIR__ . '/../public_html/api/viator_helpers.php';

$failures = 0;
function check($label, $ok, $detail = '') {
    global $failures;
    if (!$ok) { $failures++; }
    printf("%s  %s%s\n", $ok ? 'ok  ' : 'FAIL', $label, $detail !== '' ? "  ($detail)" : '');
}

// --- which bookings this is about ---------------------------------------------------------
check('the one spelling production actually holds', viatorIsViatorChannel('Viator.com'));
check('case and spacing do not matter', viatorIsViatorChannel('  VIATOR '));
check('Tripadvisor counts, because the P&L ladder treats it as Viator', viatorIsViatorChannel('Tripadvisor'));
foreach (['GetYourGuide', 'Airbnb', 'www.florencewithlocals.com', 'Headout Inc', 'Backend', '', null] as $other) {
    check('not Viator: ' . var_export($other, true), !viatorIsViatorChannel($other));
}

// --- the label a newly inserted booking gets ------------------------------------------------
$created = function ($iso) { return ['creationDate' => strtotime($iso) * 1000]; };

check('a GetYourGuide booking gets no label at all',
    viatorAccountForInsert('GetYourGuide', $created('2026-09-21 10:00'), '2026-09-25 00:00:00') === null);

// Before he switches there is no cutover, and the old account is still the only one.
check('no cutover yet -> a Viator booking arriving today is still legacy',
    viatorAccountForInsert('Viator.com', $created('2026-09-21 10:00'), null) === VIATOR_ACCOUNT_LEGACY);
check('no cutover yet -> even with no creationDate it is legacy',
    viatorAccountForInsert('Viator.com', null, null) === VIATOR_ACCOUNT_LEGACY);

// After he switches, the booking's OWN creation time decides - a booking made on the old
// account last March must not be adopted by the new channel just because we synced it late.
$cut = '2026-09-25 12:00:00';
check('made before the cutover -> legacy',
    viatorAccountForInsert('Viator.com', $created('2026-03-14 09:00'), $cut) === VIATOR_ACCOUNT_LEGACY);
check('made one minute before the cutover -> legacy',
    viatorAccountForInsert('Viator.com', $created('2026-09-25 11:59'), $cut) === VIATOR_ACCOUNT_LEGACY);
check('made after the cutover -> current',
    viatorAccountForInsert('Viator.com', $created('2026-09-25 12:01'), $cut) === VIATOR_ACCOUNT_CURRENT);
check('no creationDate after a cutover -> current, the safe way round',
    viatorAccountForInsert('Viator.com', null, $cut, strtotime('2026-09-26 08:00')) === VIATOR_ACCOUNT_CURRENT,
    'a new-account booking wrongly called legacy would join the hand-check list and be trusted');
check('an unparseable cutover falls back to legacy rather than relabelling',
    viatorAccountForInsert('Viator.com', $created('2026-09-26 08:00'), 'not a date') === VIATOR_ACCOUNT_LEGACY);

// --- what is shown, and what is not changed --------------------------------------------------
check('a legacy Viator booking reads as the old account',
    viatorChannelLabel('Viator.com', VIATOR_ACCOUNT_LEGACY) === 'Viator (old account)');
check('a new-account Viator booking reads as it always did',
    viatorChannelLabel('Viator.com', VIATOR_ACCOUNT_CURRENT) === 'Viator.com');
check('every other channel is untouched',
    viatorChannelLabel('GetYourGuide', null) === 'GetYourGuide');
check('a stray label on a non-Viator row cannot rename it',
    viatorChannelLabel('GetYourGuide', VIATOR_ACCOUNT_LEGACY) === 'GetYourGuide');

// --- the watchdog alarm -----------------------------------------------------------------------
check('the first run is a baseline, not an alarm', viatorWatchdogStatus(null, 50) === 'baseline');
check('nothing moved -> quiet', viatorWatchdogStatus(50, 50) === 'ok');
check('a departure simply passed -> quiet (expected was already reduced)', viatorWatchdogStatus(49, 49) === 'ok');
check('he took another booking on the old account -> quiet', viatorWatchdogStatus(50, 51) === 'ok');
check('one booking disappeared -> ALERT', viatorWatchdogStatus(50, 49) === 'ALERT');
check('they all disappeared -> ALERT', viatorWatchdogStatus(50, 0) === 'ALERT');

echo $failures === 0 ? "\nall checks passed\n" : "\n$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
