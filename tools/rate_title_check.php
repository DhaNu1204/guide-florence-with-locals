<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only check (step 6.14): never deployed
/**
 * Step 6.14 unit tests for the rate-title rules (no database):
 *   php tools/rate_title_check.php
 */
require_once __DIR__ . '/../public_html/api/rate_helpers.php';

$failures = 0;
function check($label, $ok, $detail = '') {
    global $failures;
    if (!$ok) { $failures++; }
    printf("%s  %s%s\n", $ok ? 'ok  ' : 'FAIL', $label, $detail !== '' ? "  ($detail)" : '');
}

// --- rateIsVasari(): the one rule --------------------------------------------------------------
check('"Vasari Corridor Access" is Vasari', rateIsVasari('Vasari Corridor Access') === true);
check('"Uffizi Gallery Tour" is not', rateIsVasari('Uffizi Gallery Tour') === false);
check('an empty title is not', rateIsVasari('') === false);
check('a blank title is not', rateIsVasari('   ') === false);
check('a null title is not', rateIsVasari(null) === false);
check('case-insensitive: "... + vasari" (a live rate on 1130528)', rateIsVasari('Uffizi Gallery Small-Group Guided Tour + vasari') === true);
check('"Uffizi Tour with Vasari Corridor Access" is Vasari', rateIsVasari('Uffizi Tour with Vasari Corridor Access') === true);
check('a non-string is not', rateIsVasari(['Vasari']) === false && rateIsVasari(1) === false);
check('the Uffizi small-group promo rate is not', rateIsVasari('Small Group - Guided Tour SPECIAL PROMO') === false);

// --- rateTitleFromBokun(): productBookings[0].rateTitle -----------------------------------------
$b = ['productBookings' => [['rateTitle' => '  Vasari Corridor Access ', 'fields' => ['rateId' => 1]]]];
check('reads productBookings[0].rateTitle and trims it', rateTitleFromBokun($b) === 'Vasari Corridor Access');
check('accepts the stored JSON string', rateTitleFromBokun(json_encode($b)) === 'Vasari Corridor Access');
check('no rate title -> null', rateTitleFromBokun(['productBookings' => [['fields' => []]]]) === null);
check('empty rate title -> null', rateTitleFromBokun(['productBookings' => [['rateTitle' => '  ']]]) === null);
check('no productBookings -> null', rateTitleFromBokun(['id' => 1]) === null);
check('broken JSON -> null', rateTitleFromBokun('{nope') === null);
check('null payload (a hand-entered row) -> null', rateTitleFromBokun(null) === null);
check('capped at the column width', mb_strlen(rateTitleFromBokun(['productBookings' => [['rateTitle' => str_repeat('é', 300)]]])) === 255);

echo $failures === 0 ? "\nall checks passed\n" : "\n$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
