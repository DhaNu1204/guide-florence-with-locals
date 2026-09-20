<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only check (step 6.2): never deployed
/**
 * Step 6.2 unit tests for the merged-costing fold (no database):
 *   php tools/pnl_links_check.php
 * Exit code 0 = all assertions hold.
 */

require_once __DIR__ . '/../public_html/api/tour_classification.php';

// pnlGuideRateForCategory() / pnlPrivateGuideRate() live in pnl.php, which routes on include.
// Define the same two lookups here from the settings array the merge function is handed.
if (!function_exists('pnlGuideRateForCategory')) {
    function pnlGuideRateForCategory($category, $settings) {
        $map = ['Combo' => 'guide_rate_combo', 'Uffizi' => 'guide_rate_uffizi',
                'Accademia' => 'guide_rate_accademia', 'Pitti' => 'guide_rate_pitti'];
        $key = isset($map[$category]) ? $map[$category] : 'guide_rate_other';
        return isset($settings[$key]) ? $settings[$key] : 0;
    }
}
if (!function_exists('pnlPrivateGuideRate')) {
    function pnlPrivateGuideRate($category, $settings) {
        $map = ['Combo' => 'guide_rate_private_combo', 'Uffizi' => 'guide_rate_private_uffizi',
                'Accademia' => 'guide_rate_private_accademia', 'Pitti' => 'guide_rate_private_pitti'];
        $key = isset($map[$category]) ? $map[$category] : 'guide_rate_private_other';
        return isset($settings[$key]) ? $settings[$key] : 0;
    }
}
require_once __DIR__ . '/../public_html/api/pnl_links.php';

$failures = 0;
function check($label, $ok, $detail = '') {
    global $failures;
    if (!$ok) { $failures++; }
    printf("%s  %s%s\n", $ok ? 'ok  ' : 'FAIL', $label, $detail !== '' ? "  ($detail)" : '');
}

$SETTINGS = [
    'guide_rate_combo' => 210.0, 'guide_rate_uffizi' => 120.0, 'guide_rate_accademia' => 90.0,
    'guide_rate_pitti' => 120.0, 'guide_rate_other' => 120.0,
    'guide_rate_private_combo' => 240.0, 'guide_rate_private_uffizi' => 120.0,
    'guide_rate_private_accademia' => 90.0, 'guide_rate_private_pitti' => 120.0,
    'guide_rate_private_other' => 120.0,
    'outsource_fee' => 40.0, 'radio_per_person' => 2.0, 'gelato_per_person' => 3.0,
];

function row($unit, $category, $opts = []) {
    $d = array_merge([
        'date' => '2026-10-01', 'time' => '09:00', 'title' => $category . ' tour',
        'is_group' => false, 'is_ticket' => false, 'is_private' => false, 'guide_name' => 'Anna',
        'channels' => ['GetYourGuide'], 'bookings' => 1, 'cancelled' => 0,
        'pax' => ['adults' => 2, 'children' => 0, 'infants' => 0, 'total' => 2],
        'net' => 100.0, 'retail' => 120.0, 'commission' => 20.0,
        'ticket_cost' => 30.0, 'guide_cost' => 120.0, 'radio_cost' => 4.0,
        'gelato_cost' => 0.0, 'staff_cost' => 0.0, 'other_cost' => 0.0,
        'outsourced' => false, 'overridden' => [], 'notes' => null,
    ], $opts);
    $costs = ['ticket_cost' => $d['ticket_cost'], 'guide_cost' => $d['guide_cost'],
              'radio_cost' => $d['radio_cost'], 'gelato_cost' => $d['gelato_cost'],
              'staff_cost' => $d['staff_cost'], 'other_cost' => $d['other_cost']];
    return [
        'unit' => $unit, 'date' => $d['date'], 'time' => $d['time'], 'title' => $d['title'],
        'category' => $category, 'is_group' => $d['is_group'], 'is_ticket' => $d['is_ticket'],
        'is_private' => $d['is_private'], 'guide_name' => $d['guide_name'],
        'channels' => $d['channels'], 'bookings' => $d['bookings'], 'cancelled' => $d['cancelled'],
        'pax' => $d['pax'],
        'revenue' => ['retail' => $d['retail'], 'commission' => $d['commission'],
                      'net' => $d['net'], 'estimated' => false, 'overridden' => false],
        'costs' => array_merge($costs, [
            'total' => round(array_sum($costs), 2), 'auto' => $costs, 'overridden' => $d['overridden'],
        ]),
        'outsourced' => $d['outsourced'],
        'profit' => round($d['net'] - array_sum($costs), 2),
        'notes' => $d['notes'],
    ];
}
$links = function ($units, $key = 'm1', $date = '2026-10-01') {
    $out = [];
    foreach ($units as $u) { $out[$u] = ['link_key' => $key, 'link_date' => $date]; }
    return $out;
};

// --- the headline case: two products, one guide ---------------------------------------------
$uffizi = row('g10', 'Uffizi', ['ticket_cost' => 50.0, 'guide_cost' => 120.0, 'net' => 200.0,
                                'pax' => ['adults' => 4, 'children' => 0, 'infants' => 0, 'total' => 4],
                                'radio_cost' => 8.0, 'title' => 'Uffizi Gallery Tour']);
$combo  = row('g11', 'Combo',  ['ticket_cost' => 90.0, 'guide_cost' => 210.0, 'net' => 300.0, 'time' => '09:15',
                                'pax' => ['adults' => 4, 'children' => 0, 'infants' => 0, 'total' => 4],
                                'radio_cost' => 8.0, 'title' => 'Uffizi & Accademia Walking Tour']);
$before = [$uffizi, $combo];
$merged = pnlLinkCombineRows($before, $links(['g10', 'g11']), $SETTINGS);

check('two linked units become ONE row', count($merged) === 1, count($merged) . ' rows');
$m = $merged[0];
check('... the guide is costed ONCE, at the highest rate (210, not 330)',
    $m['costs']['guide_cost'] === 210.0, 'guide_cost=' . $m['costs']['guide_cost']);
check('... and the rule that won is stated',
    strpos($m['merged']['guide_rule'], 'highest category rate') === 0, $m['merged']['guide_rule']);
check('... tickets are summed, not merged', $m['costs']['ticket_cost'] === 140.0, $m['costs']['ticket_cost']);
check('... radios are summed', $m['costs']['radio_cost'] === 16.0, $m['costs']['radio_cost']);
check('... revenue is summed', $m['revenue']['net'] === 500.0, $m['revenue']['net']);
check('... PAX is summed', $m['pax']['total'] === 8, $m['pax']['total']);
check('... the per-product breakdown is kept',
    count($m['merged']['members']) === 2
    && $m['merged']['members'][0]['unit'] === 'g10' && $m['merged']['members'][0]['ticket_cost'] === 50.0
    && $m['merged']['members'][1]['guide_cost_alone'] === 210.0);
check('... the row is marked merged and carries both units',
    $m['unit'] === 'm1' && $m['merged']['units'] === ['g10', 'g11']);
check('... it takes the earliest time', $m['time'] === '09:00', $m['time']);

// the day's cost drops by exactly one guide fee, revenue and tickets unchanged
$costBefore = array_sum(array_map(function ($r) { return $r['costs']['total']; }, $before));
$revBefore  = array_sum(array_map(function ($r) { return $r['revenue']['net']; }, $before));
$tikBefore  = array_sum(array_map(function ($r) { return $r['costs']['ticket_cost']; }, $before));
check('the day total falls by exactly one guide fee (120)',
    round($costBefore - $m['costs']['total'], 2) === 120.0, 'before ' . $costBefore . ' after ' . $m['costs']['total']);
check('... revenue is untouched', $revBefore === $m['revenue']['net']);
check('... ticket cost is untouched', $tikBefore === $m['costs']['ticket_cost']);

// --- unlinking restores the rows exactly -----------------------------------------------------
$restored = pnlLinkCombineRows($before, [], $SETTINGS);
check('with the link gone both rows come back exactly as they were', $restored == $before);

// --- a unit that is not linked is never touched ----------------------------------------------
$other = row('t99', 'Accademia', ['time' => '14:00']);
$mixedDay = pnlLinkCombineRows([$uffizi, $combo, $other], $links(['g10', 'g11']), $SETTINGS);
check('an unlinked departure on the same day is untouched',
    count($mixedDay) === 2 && $mixedDay[1] == $other);

// --- the members' own overrides still count ---------------------------------------------------
$ovUffizi = row('g10', 'Uffizi', ['ticket_cost' => 55.5, 'guide_cost' => 120.0, 'net' => 200.0,
                                  'overridden' => ['ticket_cost']]);
$withOv = pnlLinkCombineRows([$ovUffizi, $combo], $links(['g10', 'g11']), $SETTINGS)[0];
check('an override on one member still counts in the merged row',
    $withOv['costs']['ticket_cost'] === 145.5 && in_array('ticket_cost', $withOv['costs']['overridden'], true),
    $withOv['costs']['ticket_cost']);

// --- an override on the merged row itself wins -------------------------------------------------
$linkOv = ['ticket_cost' => null, 'guide_cost' => 150.0, 'radio_cost' => null, 'gelato_cost' => null,
           'staff_cost' => null, 'other_cost' => null, 'revenue_override' => null, 'outsourced' => 0,
           'notes' => 'one guide, agreed rate'];
$withLinkOv = pnlLinkCombineRows($before, $links(['g10', 'g11']), $SETTINGS, ['m1' => $linkOv])[0];
check('an override on the merged row wins over the computed fee',
    $withLinkOv['costs']['guide_cost'] === 150.0, $withLinkOv['costs']['guide_cost']);
check('... and its notes come through', $withLinkOv['notes'] === 'one guide, agreed rate');

// --- disagreeing members: outsourced wins, and the fee is charged once -------------------------
$outs = row('g12', 'Uffizi', ['outsourced' => true, 'guide_cost' => 0.0, 'other_cost' => 40.0]);
$mOut = pnlLinkCombineRows([$outs, $combo], $links(['g12', 'g11']), $SETTINGS)[0];
check('if one member is outsourced there is no guide fee',
    $mOut['costs']['guide_cost'] === 0.0 && $mOut['outsourced'] === true);
check('... and exactly ONE handling fee', $mOut['costs']['other_cost'] === 40.0, $mOut['costs']['other_cost']);
check('... and the reason is stated', strpos($mOut['merged']['guide_rule'], 'outsourced') === 0, $mOut['merged']['guide_rule']);

// --- a private member pushes the merged unit to the private rate --------------------------------
$priv = row('g13', 'Combo', ['is_private' => true, 'guide_cost' => 240.0]);
$mPriv = pnlLinkCombineRows([$priv, $uffizi], $links(['g13', 'g10']), $SETTINGS)[0];
check('a private member makes it a private rate (240)',
    $mPriv['costs']['guide_cost'] === 240.0 && $mPriv['is_private'] === true, $mPriv['costs']['guide_cost']);

// --- never cheaper than one of the members alone -------------------------------------------------
$expensive = row('g14', 'Uffizi', ['guide_cost' => 300.0, 'overridden' => ['guide_cost']]);
$mExp = pnlLinkCombineRows([$expensive, $uffizi], $links(['g14', 'g10']), $SETTINGS)[0];
check('the merged fee is never lower than the dearest member on its own',
    $mExp['costs']['guide_cost'] === 300.0, $mExp['costs']['guide_cost']);
check('... and says so', strpos($mExp['merged']['guide_rule'], 'highest single departure') === 0, $mExp['merged']['guide_rule']);

// --- a link whose second member vanished is not a merge ------------------------------------------
$lonely = pnlLinkCombineRows([$uffizi], $links(['g10', 'g11']), $SETTINGS);
check('a link with only one surviving departure stays a normal row',
    count($lonely) === 1 && !isset($lonely[0]['merged']) && $lonely[0] == $uffizi);

// --- three units in one link ---------------------------------------------------------------------
$third = row('t77', 'Accademia', ['guide_cost' => 90.0, 'ticket_cost' => 20.0, 'net' => 80.0, 'time' => '09:30']);
$m3 = pnlLinkCombineRows([$uffizi, $combo, $third], $links(['g10', 'g11', 't77']), $SETTINGS)[0];
check('three departures merge into one row with one guide fee',
    count($m3['merged']['members']) === 3 && $m3['costs']['guide_cost'] === 210.0
    && $m3['costs']['ticket_cost'] === 160.0 && $m3['revenue']['net'] === 580.0,
    'guide=' . $m3['costs']['guide_cost'] . ' tickets=' . $m3['costs']['ticket_cost']);

echo $failures === 0 ? "\nall checks passed\n" : "\n$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
