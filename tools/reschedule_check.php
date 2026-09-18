<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only check (step 3.2): never deployed
/**
 * Step 3.2 local check for the pure reschedule helpers (no database needed):
 *   php tools/reschedule_check.php
 * Exit code 0 = all assertions hold.
 */
require_once __DIR__ . '/../public_html/api/tour_classification.php';

$failures = 0;
function check($label, $ok, $detail = '') {
    global $failures;
    if (!$ok) { $failures++; }
    printf("%s  %s%s\n", $ok ? 'ok  ' : 'FAIL', $label, $detail !== '' ? "  ($detail)" : '');
}

// --- time normalisation --------------------------------------------------------
check("'10:00:00' -> '10:00'", bokunNormalizeTime('10:00:00') === '10:00');
check("'10:00' -> '10:00'", bokunNormalizeTime('10:00') === '10:00');
check("'9:30' -> '09:30'", bokunNormalizeTime('9:30') === '09:30');
check("' 14:15:00 ' -> '14:15'", bokunNormalizeTime(' 14:15:00 ') === '14:15');
check("null / '' / 'abc' -> null", bokunNormalizeTime(null) === null && bokunNormalizeTime('') === null && bokunNormalizeTime('abc') === null);
check("date '2026-09-20 00:00:00' -> '2026-09-20'", bokunNormalizeDate('2026-09-20 00:00:00') === '2026-09-20');

// --- the bug: MySQL TIME vs Bokun startTimeStr -----------------------------------
check("10:00:00 vs 10:00, same date -> NOT rescheduled", bokunIsRescheduled('2026-09-20', '10:00:00', '2026-09-20', '10:00') === false);
check("09:30:00 vs 9:30 -> NOT rescheduled", bokunIsRescheduled('2026-09-20', '09:30:00', '2026-09-20', '9:30') === false);

// --- real reschedules ------------------------------------------------------------
check("10:00 vs 14:30 -> rescheduled", bokunIsRescheduled('2026-09-20', '10:00', '2026-09-20', '14:30') === true);
check("10:00:00 vs 14:30 -> rescheduled", bokunIsRescheduled('2026-09-20', '10:00:00', '2026-09-20', '14:30') === true);
check("date moved, same time -> rescheduled", bokunIsRescheduled('2026-09-20', '10:00:00', '2026-09-21', '10:00') === true);
check("date and time moved -> rescheduled", bokunIsRescheduled('2026-09-20', '10:00:00', '2026-09-22', '15:00') === true);

// --- unusable input never raises a false flag -------------------------------------
check("missing new time -> NOT rescheduled", bokunIsRescheduled('2026-09-20', '10:00:00', '2026-09-20', null) === false);
check("missing old time -> NOT rescheduled", bokunIsRescheduled('2026-09-20', null, '2026-09-20', '10:00') === false);
check("garbage date -> NOT rescheduled", bokunIsRescheduled('n/a', '10:00', '2026-09-20', '10:00') === false);

echo $failures === 0 ? "\nall checks passed\n" : "\n$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
