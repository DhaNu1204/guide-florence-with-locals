<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only check (step 3.8): never deployed
/**
 * Step 3.8 unit tests for paymentAmountError() - the single gate on every payment write:
 *   php tools/payment_amount_check.php
 * Exit code 0 = all assertions hold.
 */

require_once __DIR__ . '/../public_html/api/payment_helpers.php';

$failures = 0;
function check($label, $ok, $detail = '') {
    global $failures;
    if (!$ok) { $failures++; }
    printf("%s  %s%s\n", $ok ? 'ok  ' : 'FAIL', $label, $detail !== '' ? "  ($detail)" : '');
}
function rejected($v) { return paymentAmountError($v) !== null; }
function accepted($v) { return paymentAmountError($v) === null; }

// --- exactly what the step asks for --------------------------------------------------------
check('"abc" is rejected', rejected('abc'), var_export(paymentAmountError('abc'), true));
check('0 is rejected', rejected(0));
check('"0" is rejected', rejected('0'));
check('-5 is rejected', rejected(-5));
check('"-5" is rejected', rejected('-5'));
check('a valid amount (45.50) is accepted', accepted(45.50));
check('a valid amount as a string ("45.50") is accepted', accepted('45.50'));

// --- the shapes a JSON body can actually deliver ---------------------------------------------
check('null is rejected', rejected(null));
check('"" is rejected', rejected(''));
check('" " is rejected', rejected(' '));
check('true is rejected (not a number)', rejected(true));
check('false is rejected', rejected(false));
check('an array is rejected', rejected([1]));
check('"12abc" is rejected', rejected('12abc'));
check('"1e3" is accepted (a real number in JSON)', accepted('1e3'));
check('0.01 is accepted', accepted(0.01));
check('-0.01 is rejected', rejected(-0.01));
check('NAN is rejected', rejected(NAN));
check('INF is rejected', rejected(INF));
check('an absurd amount (1000000) is rejected', rejected(1000000));
check('100000 exactly is accepted', accepted(100000));

// --- the message is usable ---------------------------------------------------------------------
check('the message names the rule',
    strpos(paymentAmountError('abc'), 'greater than 0') !== false, paymentAmountError('abc'));

echo $failures === 0 ? "\nall checks passed\n" : "\n$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
