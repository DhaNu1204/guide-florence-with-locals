<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only (step 6.17): never deployed
/**
 * Step 6.17 - the pure "Counts as" rules (no DB): default billing product, when it applies, and the
 * Guide Tour Report using the same product.   php tools/billing_product_check.php
 */
$apiDir = getenv('FWL_API_DIR') ?: __DIR__ . '/../public_html/api';
require_once $apiDir . '/lib/billing_product.php';
require_once $apiDir . '/lib/guide_report_core.php';

$pass = 0; $fail = 0;
function ok($label, $cond, $got = '') {
    global $pass, $fail;
    if ($cond) { $pass++; echo "ok    $label\n"; } else { $fail++; echo "FAIL  $label  (" . json_encode($got) . ")\n"; }
}
$s = pnlDefaultSettings();
$COMBO = ['product_id' => 962885, 'title' => 'Uffizi & Accademia Walking Tour with Gelato & Art Historian', 'duration_minutes' => 240];
$UFF = ['product_id' => 1130528, 'title' => 'Uffizi Gallery Guided Tour with Optional Vasari Corridor Visit', 'duration_minutes' => 120];
$UFF2 = ['product_id' => 961801, 'title' => 'Uffizi Gallery Small Group Guided Tour with Tickets', 'duration_minutes' => 90];
$ACC = ['product_id' => 555, 'title' => 'Accademia Gallery David Tour', 'duration_minutes' => 60];
$OTH = ['product_id' => 777, 'title' => 'Florence Food Walk', 'duration_minutes' => 180];

ok('Combo + Uffizi (Uffizi first) -> Combo', billingDefaultProductId([$UFF, $COMBO], $s) === 962885);
ok('Uffizi + Combo + Uffizi -> Combo', billingDefaultProductId([$UFF2, $COMBO, $UFF], $s) === 962885);
ok('two Uffizi products (same type) -> none', billingDefaultProductId([$UFF, $UFF2], $s) === null);
ok('single product -> none', billingDefaultProductId([$COMBO, $COMBO], $s) === null);
$t = $s; $t['guide_rate_accademia'] = $t['guide_rate_uffizi'];
ok('equal rates -> longest duration (Uffizi 120 > Accademia 60)', billingDefaultProductId([$ACC, $UFF], $t) === 1130528);
$t['guide_rate_other'] = $t['guide_rate_uffizi'];
ok('equal rates -> longest duration (Other 180)', billingDefaultProductId([$UFF, $OTH], $t) === 777);

ok('applies: mixed and the product is a member', billingMemberIndex([$UFF, $COMBO], 962885) === 1);
ok('not set -> null', billingMemberIndex([$UFF, $COMBO], null) === null);
ok('product no longer a live member -> null', billingMemberIndex([$UFF, $UFF2], 962885) === null);
ok('members no longer mix types -> null', billingMemberIndex([$COMBO, $COMBO], 962885) === null);

$titles = [$UFF['title'], $COMBO['title']];
$pids = [1130528, 962885];
$r = billingApplyToComposition(buildComposition($titles), $titles, $pids, 1130528);
ok('report: counts as Uffizi -> Uffizi, Uffizi title', $r[0] === 'Uffizi' && $titles[$r[3]] === $UFF['title'], $r);
$r = billingApplyToComposition(buildComposition($titles), $titles, $pids, 962885);
ok('report: counts as Combo -> Combo, label kept', $r[0] === 'Combo' && $r[2] === '1 Combo + 1 Uffizi booking', $r);
$r = billingApplyToComposition(buildComposition($titles), $titles, $pids, null);
ok('report: no billing product -> the 6.15 rule (Combo)', $r[0] === 'Combo', $r);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
