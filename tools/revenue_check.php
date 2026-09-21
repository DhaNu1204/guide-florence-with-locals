<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only check (step 6.6): never deployed
/**
 * Step 6.6 unit tests for how a booking's revenue is resolved (no database).
 *
 *   php tools/revenue_check.php
 *
 * pnl.php routes at the bottom and requires an admin, so the two pure functions under test
 * are read out of it by name - the file is never executed. If either function moves, this
 * check fails loudly rather than silently testing a copy.
 */
$src = file_get_contents(__DIR__ . '/../public_html/api/pnl.php');
$wanted = ['pnlExtractRevenue', 'pnlDefaultSettings', 'pnlSettingKeys'];
$code = '';
foreach ($wanted as $fn) {
    $start = strpos($src, "function $fn(");
    if ($start === false) { fwrite(STDERR, "FATAL: $fn() not found in pnl.php\n"); exit(2); }
    // Walk the braces to the end of the function body.
    $i = strpos($src, '{', $start);
    $depth = 0; $end = null;
    for ($p = $i; $p < strlen($src); $p++) {
        if ($src[$p] === '{') { $depth++; }
        elseif ($src[$p] === '}') { $depth--; if ($depth === 0) { $end = $p; break; } }
    }
    if ($end === null) { fwrite(STDERR, "FATAL: could not read $fn()\n"); exit(2); }
    $code .= substr($src, $start, $end - $start + 1) . "\n";
}
$tmp = tempnam(sys_get_temp_dir(), 'rev') . '.php';
file_put_contents($tmp, "<?php\n" . $code);
require $tmp;
unlink($tmp);

$S = pnlDefaultSettings();

$failures = 0;
function check($label, $ok, $detail = '') {
    global $failures;
    if (!$ok) { $failures++; }
    printf("%s  %s%s\n", $ok ? 'ok  ' : 'FAIL', $label, $detail !== '' ? "  ($detail)" : '');
}
function money($v) { return round($v, 2); }

// --- an OTA booking: the reseller invoice wins and must not move -----------------------------
$gyg = json_encode(['productBookings' => [[
    'resellerInvoice' => ['total' => 125.56, 'totalCommission' => 37.67,
                          'totalSansCommission' => 87.89, 'totalDue' => 87.89, 'currency' => 'EUR'],
    'customerInvoice' => ['total' => 125.56, 'totalCommission' => 0, 'totalSansCommission' => 125.56],
    'totalPrice' => 0, 'sellerCommission' => 23.79,
]]]);
[$r, $c, $n, $est] = pnlExtractRevenue($gyg, 'GetYourGuide', 0, $S);
check('GYG GYGLMRN2NY2R: retail 125.56', money($r) === 125.56, (string) $r);
check('GYG: commission 37.67 (the invoice, not 30% of anything else)', money($c) === 37.67, (string) $c);
check('GYG: net 87.89 - the figure GetYourGuide itself reports', money($n) === 87.89, (string) $n);
check('GYG: not estimated', $est === false);

// Airbnb at its real 20%, straight from Bokun
$abnb = json_encode(['productBookings' => [[
    'resellerInvoice' => ['total' => 119.18, 'totalCommission' => 23.84, 'totalSansCommission' => 95.34],
    'customerInvoice' => ['total' => 139.18, 'totalCommission' => 0, 'totalSansCommission' => 139.18],
]]]);
[$r, $c, $n, $est] = pnlExtractRevenue($abnb, 'Airbnb', 0, $S);
check('Airbnb TASABPCF: net 95.34 at the real 20%', money($n) === 95.34, (string) $n);
check('Airbnb: the guest-facing customerInvoice (139.18) is NOT used', money($r) === 119.18, (string) $r);

// Viator reports net, so commission is zero and the total IS the payout
$via = json_encode(['productBookings' => [[
    'resellerInvoice' => ['total' => 121.2, 'totalCommission' => 0, 'totalSansCommission' => 121.2],
]]]);
[$r, $c, $n, $est] = pnlExtractRevenue($via, 'Viator.com', 0, $S);
check('Viator VIA-98834699: 121.20 paid out', money($n) === 121.2 && money($c) === 0.0, "$n / $c");

// --- the fix: a DIRECT sale has a customerInvoice and no reseller -----------------------------
$web = json_encode(['productBookings' => [[
    'customerInvoice' => ['total' => 239.12, 'totalCommission' => 0, 'totalSansCommission' => 239.12,
                          'totalDue' => 239.12, 'currency' => 'EUR'],
    'totalPrice' => 0, 'sellerCommission' => 0,
]]]);
[$r, $c, $n, $est] = pnlExtractRevenue($web, 'www.florencewithlocals.com', 0, $S);
check('t6054 WEB-98701751: retail 239.12', money($r) === 239.12, (string) $r);
check('... commission 0.00, NOT 71.74', money($c) === 0.0, (string) $c);
check('... net 239.12 - he keeps the lot', money($n) === 239.12, (string) $n);
check('... and it is NOT estimated: this came from an invoice', $est === false);

foreach (['www.florencewithlocals.com', 'Payment links', 'Backend'] as $ch) {
    [$r2, $c2, $n2, $e2] = pnlExtractRevenue($web, $ch, 0, $S);
    check("direct channel '$ch' keeps the full 239.12", money($n2) === 239.12, (string) $n2);
}

// a direct sale that really DID carry a commission is still read as given
$withComm = json_encode(['productBookings' => [[
    'customerInvoice' => ['total' => 100.0, 'totalCommission' => 12.5, 'totalSansCommission' => 87.5],
]]]);
[$r, $c, $n, $est] = pnlExtractRevenue($withComm, 'www.florencewithlocals.com', 0, $S);
check('a non-zero customer-invoice commission is honoured, not zeroed',
    money($c) === 12.5 && money($n) === 87.5, "$c / $n");

// only one of the two fields present: the other is derived
$netOnly = json_encode(['productBookings' => [['customerInvoice' => ['total' => 50.0, 'totalSansCommission' => 45.0]]]]);
[$r, $c, $n, $est] = pnlExtractRevenue($netOnly, 'Payment links', 0, $S);
check('commission derived when only the net is given', money($c) === 5.0 && money($n) === 45.0, "$c / $n");

// --- the percentage is a LAST RESORT and says so ------------------------------------------------
$none = json_encode(['productBookings' => [[]]]);
[$r, $c, $n, $est] = pnlExtractRevenue($none, 'GetYourGuide', 100.0, $S);
check('no invoice at all -> GYG falls back to 30%', money($c) === 30.0 && money($n) === 70.0, "$c / $n");
check('... and is flagged estimated', $est === true);

[$r, $c, $n, $est] = pnlExtractRevenue($none, 'Airbnb', 100.0, $S);
check('no invoice -> Airbnb uses 20%, not the 30% default', money($c) === 20.0, (string) $c);
[$r, $c, $n, $est] = pnlExtractRevenue($none, 'Headout Inc', 100.0, $S);
check('no invoice -> Headout uses 20% (was 25%)', money($c) === 20.0, (string) $c);
foreach (['www.florencewithlocals.com', 'Payment links', 'Backend', 'Direct', 'bokun', ''] as $ch) {
    [$r2, $c2, $n2, $e2] = pnlExtractRevenue($none, $ch, 100.0, $S);
    check("no invoice -> direct channel '" . ($ch === '' ? '(empty)' : $ch) . "' deducts nothing",
        money($c2) === 0.0, (string) $c2);
}
[$r, $c, $n, $est] = pnlExtractRevenue($none, 'Some New OTA', 100.0, $S);
check('an unknown channel still falls to comm_default 30%', money($c) === 30.0, (string) $c);

// nothing at all
[$r, $c, $n, $est] = pnlExtractRevenue(null, 'www.florencewithlocals.com', 0, $S);
check('no payload and no amount -> zeros, flagged estimated',
    money($r) === 0.0 && money($n) === 0.0 && $est === true);

// --- the settings themselves ----------------------------------------------------------------------
check('comm_airbnb exists and is 20', ($S['comm_airbnb'] ?? null) === 20.0, (string) ($S['comm_airbnb'] ?? 'missing'));
check('comm_headout corrected to 20', $S['comm_headout'] === 20.0, (string) $S['comm_headout']);
check('comm_airbnb is a persisted setting key', in_array('comm_airbnb', pnlSettingKeys(), true));

echo $failures === 0 ? "\nall checks passed\n" : "\n$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
