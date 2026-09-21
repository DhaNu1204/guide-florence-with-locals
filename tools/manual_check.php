<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only check (step 6.4): never deployed
/**
 * Step 6.4 unit tests for hand-entered departures (no database):
 *   php tools/manual_check.php
 */
require_once __DIR__ . '/../public_html/api/payment_helpers.php';
require_once __DIR__ . '/../public_html/api/manual_helpers.php';

$failures = 0;
function check($label, $ok, $detail = '') {
    global $failures;
    if (!$ok) { $failures++; }
    printf("%s  %s%s\n", $ok ? 'ok  ' : 'FAIL', $label, $detail !== '' ? "  ($detail)" : '');
}

// --- what counts as a manual row -----------------------------------------------------------
check('source manual is a manual row', manualIsManualRow(['source' => 'manual']) === true);
check('source MANUAL too (case)', manualIsManualRow(['source' => 'MANUAL']) === true);
check('a synced row (source NULL) is not', manualIsManualRow(['source' => null]) === false);
check('a row with no source column at all is not', manualIsManualRow(['id' => 1]) === false);
check('the bare string works too', manualIsManualRow('manual') === true);

// --- time ------------------------------------------------------------------------------------
check("'9:30' -> 09:30", manualNormalizeTime('9:30') === '09:30', (string) manualNormalizeTime('9:30'));
check("'09:30:00' -> 09:30", manualNormalizeTime('09:30:00') === '09:30');
check("'23:59' kept", manualNormalizeTime('23:59') === '23:59');
foreach (['24:00', '9:60', 'abc', '', '930', null, '9-30'] as $bad) {
    check('not a time: ' . var_export($bad, true), manualNormalizeTime($bad) === null);
}

// --- date --------------------------------------------------------------------------------------
check("'2026-08-21' kept", manualNormalizeDate('2026-08-21') === '2026-08-21');
check("'2026-02-31' refused (not a real day)", manualNormalizeDate('2026-02-31') === null);
check("'21-08-2026' refused", manualNormalizeDate('21-08-2026') === null);
check("'2026-8-21' refused", manualNormalizeDate('2026-8-21') === null);

// --- currency ------------------------------------------------------------------------------------
check('empty currency means EUR', manualNormalizeCurrency('') === 'EUR');
check('null currency means EUR', manualNormalizeCurrency(null) === 'EUR');
check("'usd' -> USD", manualNormalizeCurrency('usd') === 'USD');
check("'EURO' refused", manualNormalizeCurrency('EURO') === null);

// --- the whole form ---------------------------------------------------------------------------------
$good = [
    'title' => "Florence: Michelangelo's Life and Legacy 3.5 Hr Guided Tour",
    'date' => '2026-08-21', 'time' => '09:30', 'participants' => 2,
    'language' => 'English', 'booking_channel' => 'GetYourGuide (direct)',
    'manual_revenue' => '418.29', 'manual_currency' => 'EUR',
];
check('the owner\'s real example passes', manualTourErrors($good) === null, (string) manualTourErrors($good));

$noAmount = $good; unset($noAmount['manual_revenue']);
check('no amount at all is allowed', manualTourErrors($noAmount) === null, (string) manualTourErrors($noAmount));
$emptyAmount = $good; $emptyAmount['manual_revenue'] = '';
check('an empty amount is allowed', manualTourErrors($emptyAmount) === null);
$noGuide = $good; $noGuide['guide_id'] = null;
check('no guide is allowed (assign one later)', manualTourErrors($noGuide) === null);

foreach ([
    ['title', '', 'A tour name is required'],
    ['date', '2026-02-31', 'Date must be a real date in YYYY-MM-DD format'],
    ['date', 'tomorrow', 'Date must be a real date in YYYY-MM-DD format'],
    ['time', '25:00', 'Start time must be in HH:MM format'],
    ['time', 'half nine', 'Start time must be in HH:MM format'],
    ['participants', 0, 'Participants must be a whole number of 1 or more'],
    ['participants', -2, 'Participants must be a whole number of 1 or more'],
    ['participants', 2.5, 'Participants must be a whole number of 1 or more'],
    ['participants', 'two', 'Participants must be a whole number of 1 or more'],
    ['participants', 500, 'Participants looks wrong (over 200)'],
    ['booking_channel', '', 'A channel is required (e.g. GetYourGuide (direct))'],
    ['manual_revenue', 'abc', 'Amount must be a number greater than 0'],
    ['manual_revenue', 0, 'Amount must be a number greater than 0'],
    ['manual_revenue', -50, 'Amount must be a number greater than 0'],
    ['manual_revenue', 999999, 'Amount looks wrong (over 100000)'],
    ['manual_currency', 'EURO', 'Currency must be a three-letter code such as EUR'],
] as [$field, $value, $expected]) {
    $bad = $good;
    $bad[$field] = $value;
    $got = manualTourErrors($bad);
    check("$field = " . var_export($value, true) . ' is refused', $got === $expected, (string) $got);
}

// the amount uses step 3.8's gate, not a looser copy of it
check('the amount check IS paymentAmountError',
    manualTourErrors(array_merge($good, ['manual_revenue' => 'abc'])) === paymentAmountError('abc'));

// --- duplicate matching ------------------------------------------------------------------------------
$rows = [
    ['id' => 1, 'date' => '2026-08-21', 'time' => '09:30:00', 'participants' => 2, 'source' => 'manual', 'cancelled' => 0],
    ['id' => 2, 'date' => '2026-08-21', 'time' => '09:30',    'participants' => 2, 'source' => null,     'cancelled' => 0],
    ['id' => 3, 'date' => '2026-08-21', 'time' => '09:30',    'participants' => 3, 'source' => null,     'cancelled' => 0],
    ['id' => 4, 'date' => '2026-08-22', 'time' => '09:30',    'participants' => 2, 'source' => null,     'cancelled' => 0],
    ['id' => 5, 'date' => '2026-08-21', 'time' => '14:30',    'participants' => 2, 'source' => null,     'cancelled' => 0],
];
$d = manualFindDuplicates($rows);
check('the manual row is paired with the synced one', ($d[1] ?? []) === [2], json_encode($d[1] ?? null));
check('and the synced row points back at it', ($d[2] ?? []) === [1], json_encode($d[2] ?? null));
check('different PAX is not a match', !isset($d[3]));
check('different date is not a match', !isset($d[4]));
check('different time is not a match', !isset($d[5]));
check("MySQL's 09:30:00 matches Bokun's 09:30", count($d) === 2);

$cancelled = [
    ['id' => 1, 'date' => '2026-08-21', 'time' => '09:30', 'participants' => 2, 'source' => 'manual', 'cancelled' => 0],
    ['id' => 2, 'date' => '2026-08-21', 'time' => '09:30', 'participants' => 2, 'source' => null,     'cancelled' => 1],
];
check('a cancelled booking is never a duplicate', manualFindDuplicates($cancelled) === []);

$twoManual = [
    ['id' => 1, 'date' => '2026-08-21', 'time' => '09:30', 'participants' => 2, 'source' => 'manual', 'cancelled' => 0],
    ['id' => 2, 'date' => '2026-08-21', 'time' => '09:30', 'participants' => 2, 'source' => 'manual', 'cancelled' => 0],
];
check('two manual rows are never paired with each other', manualFindDuplicates($twoManual) === []);

$multi = [
    ['id' => 1, 'date' => '2026-08-21', 'time' => '09:30', 'participants' => 2, 'source' => 'manual', 'cancelled' => 0],
    ['id' => 2, 'date' => '2026-08-21', 'time' => '09:30', 'participants' => 2, 'source' => null,     'cancelled' => 0],
    ['id' => 3, 'date' => '2026-08-21', 'time' => '09:30', 'participants' => 2, 'source' => null,     'cancelled' => 0],
];
$dm = manualFindDuplicates($multi);
check('a manual row can point at several synced candidates', ($dm[1] ?? []) === [2, 3], json_encode($dm[1] ?? null));

check('no manual rows at all -> no flags', manualFindDuplicates([
    ['id' => 9, 'date' => '2026-08-21', 'time' => '09:30', 'participants' => 2, 'source' => null, 'cancelled' => 0],
]) === []);

echo $failures === 0 ? "\nall checks passed\n" : "\n$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
