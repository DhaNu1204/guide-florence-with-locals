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

// --- step 6.18: the account comes from the Viator supplier number in the product code -------
$pb = function (...$codes) {
    return ['productBookings' => array_map(function ($c) { return ['productExternalId' => $c]; }, $codes)];
};
check('the two supplier numbers are configured in one place',
    VIATOR_SUPPLIER_ACCOUNTS === ['426324' => VIATOR_ACCOUNT_LEGACY, '5686765' => VIATOR_ACCOUNT_CURRENT]);
check('old account product code -> legacy', viatorAccountFromCode('426324P2') === VIATOR_ACCOUNT_LEGACY);
check('old account, two-digit option -> legacy', viatorAccountFromCode('426324P14') === VIATOR_ACCOUNT_LEGACY);
check('new account product code (BR-1456081981) -> current', viatorAccountFromCode('5686765P3') === VIATOR_ACCOUNT_CURRENT);
check('lower-case p still parses', viatorAccountFromCode('5686765p4') === VIATOR_ACCOUNT_CURRENT);
check('a supplier number we do not know -> unknown', viatorAccountFromCode('999999P1') === VIATOR_ACCOUNT_UNKNOWN);
check('a prefix of a known number is not that number', viatorAccountFromCode('42632P1') === VIATOR_ACCOUNT_UNKNOWN);
check('a code without the P shape -> unknown', viatorAccountFromCode('5686765') === VIATOR_ACCOUNT_UNKNOWN);

check('payload: GetYourGuide -> null, whatever the code',
    viatorAccountFromPayload('GetYourGuide', $pb('5686765P3')) === null);
check('payload: old code -> legacy', viatorAccountFromPayload('Viator.com', $pb('426324P2')) === VIATOR_ACCOUNT_LEGACY);
check('payload: new code -> current', viatorAccountFromPayload('Viator.com', $pb('5686765P3')) === VIATOR_ACCOUNT_CURRENT);
check('payload as a JSON string, as the sync stores it',
    viatorAccountFromPayload('Viator.com', json_encode($pb('5686765P3'))) === VIATOR_ACCOUNT_CURRENT);
check('payload with no product code -> null (keep what is stored, never default to old)',
    viatorAccountFromPayload('Viator.com', ['productBookings' => [['productExternalId' => '']]]) === null);
check('no payload at all (hand-entered) -> null', viatorAccountFromPayload('Viator', null) === null);
check('two products on one supplier -> that account',
    viatorAccountFromPayload('Viator.com', $pb('426324P2', '426324P14')) === VIATOR_ACCOUNT_LEGACY);
check('two suppliers mixed in one booking -> unknown',
    viatorAccountFromPayload('Viator.com', $pb('426324P2', '5686765P3')) === VIATOR_ACCOUNT_UNKNOWN);

check('display: a stored label is shown as is', viatorAccountForDisplay('Viator.com', 'current') === VIATOR_ACCOUNT_CURRENT);
check('display: a Viator row with no label -> unknown', viatorAccountForDisplay('Viator', null) === VIATOR_ACCOUNT_UNKNOWN);
check('display: a non-Viator row -> null even with a stray label', viatorAccountForDisplay('GetYourGuide', 'legacy') === null);
check('display: garbage in the column -> unknown', viatorAccountForDisplay('Viator.com', 'xyz') === VIATOR_ACCOUNT_UNKNOWN);

// --- what is shown, and what is not changed --------------------------------------------------
check('a legacy Viator booking reads as the old account',
    viatorChannelLabel('Viator.com', VIATOR_ACCOUNT_LEGACY) === 'Viator (old account)');
check('a new-account Viator booking reads as the new account (step 6.18)',
    viatorChannelLabel('Viator.com', VIATOR_ACCOUNT_CURRENT) === 'Viator (new account)');
check('a Viator booking we cannot place reads "Viator ?" (step 6.18)',
    viatorChannelLabel('Viator', null) === 'Viator ?');
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
