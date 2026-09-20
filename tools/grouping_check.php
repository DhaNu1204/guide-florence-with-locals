<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only check (step 3.7): never deployed
/**
 * Step 3.7 unit tests for the pure parts of the grouping engine (no database):
 *   php tools/grouping_check.php
 * Exit code 0 = all assertions hold.
 */

require_once __DIR__ . '/../public_html/api/group_helpers.php';

$failures = 0;
function check($label, $ok, $detail = '') {
    global $failures;
    if (!$ok) { $failures++; }
    printf("%s  %s%s\n", $ok ? 'ok  ' : 'FAIL', $label, $detail !== '' ? "  ($detail)" : '');
}
function tour($id, $product, $date, $time, $pax) {
    return ['id' => $id, 'product_id' => $product, 'date' => $date, 'time' => $time, 'participants' => $pax, 'title' => 'T'];
}

// --- the natural key ----------------------------------------------------------------------
check("bucket key shape", groupBucketKey(961801, '2026-07-09', '09:00:00') === '961801|2026-07-09|09:00',
    groupBucketKey(961801, '2026-07-09', '09:00:00'));
check("time is normalised: '9:00' == '09:00:00'",
    groupBucketKey(1, '2026-07-09', '9:00') === groupBucketKey(1, '2026-07-09', '09:00:00'));
check("a datetime date is trimmed to the day",
    groupBucketKey(1, '2026-07-09 00:00:00', '09:00') === '1|2026-07-09|09:00');
check("different time -> different bucket",
    groupBucketKey(1, '2026-07-09', '09:00') !== groupBucketKey(1, '2026-07-09', '09:30'));
check("different product -> different bucket",
    groupBucketKey(1, '2026-07-09', '09:00') !== groupBucketKey(2, '2026-07-09', '09:00'));
check("normalizeGroupTime", normalizeGroupTime('7:5') === '07:05', normalizeGroupTime('7:5'));

// --- bucketing ----------------------------------------------------------------------------
$tours = [
    tour(1, 961801, '2026-07-09', '09:00:00', 2),
    tour(2, 961801, '2026-07-09', '09:00', 3),
    tour(3, 961801, '2026-07-09', '14:30:00', 4),
    tour(4, 962885, '2026-07-09', '09:00:00', 2),
];
$buckets = buildGroupBuckets($tours);
check('4 tours -> 3 buckets', count($buckets) === 3, implode(' / ', array_keys($buckets)));
check('... the two 09:00 bookings of one product share a bucket',
    count($buckets['961801|2026-07-09|09:00']) === 2);
check('... a different product at the same time is its own bucket',
    count($buckets['962885|2026-07-09|09:00']) === 1);

// --- the PAX split ------------------------------------------------------------------------
$big = [tour(1, 1, '2026-07-09', '09:00', 4), tour(2, 1, '2026-07-09', '09:00', 4), tour(3, 1, '2026-07-09', '09:00', 4)];
$split = splitBucketByPax($big, 9);
check('12 PAX with a cap of 9 -> 2 sub-groups', count($split) === 2, count($split) . ' sub-groups');
check('... filled in order: 8 then 4',
    array_sum(array_column($split[0], 'participants')) === 8 && array_sum(array_column($split[1], 'participants')) === 4);
check('under the cap -> a single sub-group', count(splitBucketByPax($big, 20)) === 1);
$huge = [tour(1, 1, '2026-07-09', '09:00', 12), tour(2, 1, '2026-07-09', '09:00', 1)];
check('one booking larger than the cap is not dropped',
    count(splitBucketByPax($huge, 9)) === 2 && count($split[0]) >= 1);

// --- identity matching: the whole point of step 3.7 ----------------------------------------
// same member set as the database already has -> the same group row is reused
$desired = [[10, 11], [20, 21]];
$current = [10 => 5, 11 => 5, 20 => 6, 21 => 6];
check('unchanged member sets keep their group ids',
    matchDesiredToExistingGroups($desired, $current) === [5, 6]);

// one booking added to an existing departure -> still the same id
check('a departure that gains a booking keeps its id',
    matchDesiredToExistingGroups([[10, 11, 12]], [10 => 5, 11 => 5]) === [5]);

// one booking removed -> still the same id
check('a departure that loses a booking keeps its id',
    matchDesiredToExistingGroups([[10]], [10 => 5, 11 => 5]) === [5]);

// a brand-new departure has no members in any group -> new row
check('a new departure gets no id (caller inserts)',
    matchDesiredToExistingGroups([[30, 31]], [10 => 5]) === [null]);

// two desired groups, one existing row: the better overlap wins, the other is new
check('a split departure keeps the id on the bigger half',
    matchDesiredToExistingGroups([[10, 11, 12], [13]], [10 => 5, 11 => 5, 12 => 5, 13 => 5]) === [5, null]);

// a group row is never claimed twice
$res = matchDesiredToExistingGroups([[10], [11]], [10 => 5, 11 => 5]);
check('a group row is claimed at most once', $res[0] === 5 && $res[1] === null, json_encode($res));

// deterministic: equal overlap -> the lower id, so two runs decide the same way
check('ties go to the lower group id',
    matchDesiredToExistingGroups([[10, 20]], [10 => 9, 20 => 4]) === [4]);
check('... and the result is stable when the input order repeats',
    matchDesiredToExistingGroups([[10, 20]], [10 => 9, 20 => 4]) === matchDesiredToExistingGroups([[10, 20]], [20 => 4, 10 => 9]));

// nothing in the database yet
check('an empty database means every departure is new',
    matchDesiredToExistingGroups([[1, 2], [3, 4]], []) === [null, null]);
check('no desired departures -> no matches', matchDesiredToExistingGroups([], [1 => 2]) === []);

echo $failures === 0 ? "\nall checks passed\n" : "\n$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
