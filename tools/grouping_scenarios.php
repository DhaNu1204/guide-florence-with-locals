<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only (step 3.7): never deployed
/**
 * Step 3.7 end-to-end scenarios for autoGroupAfterSync(). Grouping is the riskiest code in the
 * app, so these run against a REAL database - but only on synthetic rows they create and delete
 * themselves: a far-future date and a fake product id that cannot collide with live bookings.
 *
 *   FWL_API_DIR=/path/to/api php tools/grouping_scenarios.php [--keep]
 *
 * Every row this script touches carries external_id LIKE 'FWL-T37-%' or sits on TEST_DATE with
 * TEST_PRODUCT; the cleanup at the end (and on failure) removes exactly those.
 */

$apiDir = getenv('FWL_API_DIR') ?: __DIR__ . '/../public_html/api';
define('BOKUN_SYNC_LIB', true);
require_once $apiDir . '/config.php';
require_once $apiDir . '/bokun_sync.php';

const TEST_DATE    = '2030-02-11';
const TEST_DATE2   = '2030-02-12';
const TEST_PRODUCT = 999000001;
const TEST_PREFIX  = 'FWL-T37-';

$keep = in_array('--keep', array_slice($argv, 1), true);
$failures = 0;

function check($label, $ok, $detail = '') {
    global $failures;
    if (!$ok) { $failures++; }
    printf("%s  %s%s\n", $ok ? 'ok  ' : 'FAIL', $label, $detail !== '' ? "  ($detail)" : '');
}

function cleanup($conn) {
    $conn->query("DELETE FROM pnl_tour_costs WHERE date IN ('" . TEST_DATE . "','" . TEST_DATE2 . "')");
    $conn->query("UPDATE tours SET group_id = NULL WHERE external_id LIKE '" . TEST_PREFIX . "%'");
    $conn->query("DELETE FROM tour_groups WHERE group_date IN ('" . TEST_DATE . "','" . TEST_DATE2 . "')");
    $conn->query("DELETE FROM tours WHERE external_id LIKE '" . TEST_PREFIX . "%'");
}

function addTour($conn, $n, $pax, $time = '09:00:00', $date = TEST_DATE, $title = 'Uffizi Gallery Test Tour') {
    $ext = TEST_PREFIX . $n;
    $stmt = $conn->prepare("INSERT INTO tours (external_id, bokun_booking_id, title, date, time, participants,
                                               product_id, is_private, cancelled, paid, created_at, updated_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 0, NOW(), NOW())");
    $bid = 'T37' . $n;
    $prod = TEST_PRODUCT;
    $stmt->bind_param('sssssii', $ext, $bid, $title, $date, $time, $pax, $prod);
    $stmt->execute();
    $id = $conn->insert_id;
    $stmt->close();
    return $id;
}

function groupOf($conn, $tourId) {
    $r = $conn->query("SELECT group_id FROM tours WHERE id = " . (int) $tourId)->fetch_assoc();
    return $r && $r['group_id'] !== null ? (int) $r['group_id'] : null;
}
function groupExists($conn, $gid) {
    return (int) $conn->query("SELECT COUNT(*) n FROM tour_groups WHERE id = " . (int) $gid)->fetch_assoc()['n'] > 0;
}
function groupRow($conn, $gid) {
    return $conn->query("SELECT * FROM tour_groups WHERE id = " . (int) $gid)->fetch_assoc();
}
function memberCount($conn, $gid) {
    return (int) $conn->query("SELECT COUNT(*) n FROM tours WHERE group_id = " . (int) $gid)->fetch_assoc()['n'];
}
function regroup($conn, $date = TEST_DATE, $end = null) {
    return autoGroupAfterSync($conn, $date, $end ?: TEST_DATE2);
}

echo "=== step 3.7 grouping scenarios (db: " . $conn->query("SELECT DATABASE() d")->fetch_assoc()['d'] . ") ===\n";
cleanup($conn);

try {
// --- 1. a new departure with two bookings becomes one group -------------------------------
$a = addTour($conn, 'a', 2);
$b = addTour($conn, 'b', 3);
$r1 = regroup($conn);
$g1 = groupOf($conn, $a);
check('two bookings of one departure -> one group', $g1 !== null && $g1 === groupOf($conn, $b),
    'created=' . $r1['groups_created'] . ' gid=' . var_export($g1, true));
check('... the group carries the natural key',
    (groupRow($conn, $g1)['bucket_key'] ?? null) === TEST_PRODUCT . '|' . TEST_DATE . '|09:00',
    var_export(groupRow($conn, $g1)['bucket_key'] ?? null, true));

// a P&L override on that departure, as the owner would set it
$conn->query("INSERT INTO pnl_tour_costs (tour_unit, date, bucket_key, ticket_cost)
              VALUES ('g$g1', '" . TEST_DATE . "', '" . TEST_PRODUCT . '|' . TEST_DATE . "|09:00', 42.00)");

// --- 2. the same member set again: same id, nothing written -------------------------------
$r2 = regroup($conn);
check('an unchanged departure keeps its id', groupOf($conn, $a) === $g1, 'now ' . var_export(groupOf($conn, $a), true));
check('... and writes NOTHING (this is what step 3.7 is for)', (int) $r2['rows_written'] === 0,
    'rows_written=' . $r2['rows_written'] . ' created=' . $r2['groups_created'] . ' updated=' . $r2['groups_updated']);
check('... a third run is identical', (function () use ($conn, $g1, $a) {
    $r = regroup($conn);
    return groupOf($conn, $a) === $g1 && (int) $r['rows_written'] === 0;
})());

// --- 3. one booking added: same id, one member added ---------------------------------------
$c = addTour($conn, 'c', 2);
$r3 = regroup($conn);
check('a departure that gains a booking keeps its id', groupOf($conn, $a) === $g1 && groupOf($conn, $c) === $g1,
    'created=' . $r3['groups_created'] . ' moved=' . $r3['tours_moved']);
check('... exactly one tour was moved', (int) $r3['tours_moved'] === 1, 'moved=' . $r3['tours_moved']);
check('... total_pax was updated to 7', (int) groupRow($conn, $g1)['total_pax'] === 7,
    'total_pax=' . groupRow($conn, $g1)['total_pax']);

// --- 4. guide propagation (steps 3.5 / 3.6a) still works ----------------------------------
$guide = $conn->query("SELECT id FROM guides ORDER BY id LIMIT 1")->fetch_assoc();
if ($guide) {
    $gid = (int) $guide['id'];
    $conn->query("UPDATE tour_groups SET guide_id = $gid WHERE id = $g1");
    $conn->query("UPDATE tours SET guide_id = $gid WHERE id IN ($a, $b, $c)");
    $d = addTour($conn, 'd', 1);
    $r4 = regroup($conn);
    $newMemberGuide = $conn->query("SELECT guide_id FROM tours WHERE id = $d")->fetch_assoc()['guide_id'];
    check('a booking joining an assigned departure inherits the guide',
        (int) $newMemberGuide === $gid, 'guide_id=' . var_export($newMemberGuide, true));
    check('... and the departure still has the same id', groupOf($conn, $d) === $g1);
    $conn->query("DELETE FROM tours WHERE id = $d");
} else {
    check('guide propagation (skipped: no guides in this database)', true);
}

// --- 5. one booking removed: same id ------------------------------------------------------
$conn->query("UPDATE tours SET cancelled = 1 WHERE id = $c");
$r5 = regroup($conn);
check('a departure that loses a booking keeps its id', groupOf($conn, $a) === $g1,
    'now ' . var_export(groupOf($conn, $a), true));
check('... the cancelled booking was detached', groupOf($conn, $c) === null);
check('... and only that one row was detached', (int) $r5['tours_detached'] === 1, 'detached=' . $r5['tours_detached']);

// --- 6. the P&L override is still resolvable ----------------------------------------------
$ov = $conn->query("SELECT tour_unit, bucket_key FROM pnl_tour_costs WHERE date = '" . TEST_DATE . "'")->fetch_assoc();
check('the P&L override still points at a group that exists',
    $ov && $ov['tour_unit'] === 'g' . $g1 && groupExists($conn, (int) substr($ov['tour_unit'], 1)),
    $ov ? $ov['tour_unit'] : '(gone)');

// --- 7. every booking gone: the group is deleted ------------------------------------------
$conn->query("UPDATE tours SET cancelled = 1 WHERE id IN ($a, $b)");
$r7 = regroup($conn);
check('a departure with no bookings left is deleted', !groupExists($conn, $g1),
    'deleted=' . $r7['groups_deleted']);
check('... its members are detached', groupOf($conn, $a) === null && groupOf($conn, $b) === null);

// --- 8. a manual merge is never touched ---------------------------------------------------
$conn->query("UPDATE tours SET cancelled = 0 WHERE id IN ($a, $b, $c)");
$conn->query("INSERT INTO tour_groups (group_date, group_time, display_name, total_pax, is_manual_merge)
              VALUES ('" . TEST_DATE . "', '09:00:00', 'Manual merge test', 5, 1)");
$manualId = $conn->insert_id;
$conn->query("UPDATE tours SET group_id = $manualId WHERE id IN ($a, $b)");
$before = $conn->query("SELECT * FROM tour_groups WHERE id = $manualId")->fetch_assoc();
$r8 = regroup($conn);
$after = $conn->query("SELECT * FROM tour_groups WHERE id = $manualId")->fetch_assoc();
check('a manual merge survives auto-grouping untouched',
    $after !== null && $before == $after, $after ? 'row unchanged' : 'ROW DELETED');
check('... its members keep their manual group', groupOf($conn, $a) === $manualId && groupOf($conn, $b) === $manualId);
check('... and the leftover single booking is not grouped', groupOf($conn, $c) === null);

// --- 9. a new bucket gets a new group ------------------------------------------------------
$e = addTour($conn, 'e', 2, '15:45:00', TEST_DATE2);
$f = addTour($conn, 'f', 2, '15:45:00', TEST_DATE2);
$r9 = regroup($conn);
$g2 = groupOf($conn, $e);
check('a new departure gets a new group', $g2 !== null && $g2 === groupOf($conn, $f) && $g2 !== $g1,
    'created=' . $r9['groups_created']);
check('... on a second date, with its own natural key',
    (groupRow($conn, $g2)['bucket_key'] ?? null) === TEST_PRODUCT . '|' . TEST_DATE2 . '|15:45');
$r9b = regroup($conn);
check('... and it too is stable on the next run',
    groupOf($conn, $e) === $g2 && (int) $r9b['rows_written'] === 0, 'rows_written=' . $r9b['rows_written']);

// --- 10. a departure that splits on the PAX cap -------------------------------------------
// 2+2+3 = 7 fills the first group, the cap (9) pushes the next two into a second one:
// sub-groups [e,f,g] and [h,i]. The half that still holds e and f must keep the id.
$conn->query("UPDATE tours SET participants = 2 WHERE id IN ($e, $f)");
$g = addTour($conn, 'g', 3, '15:45:00', TEST_DATE2);
$h = addTour($conn, 'h', 3, '15:45:00', TEST_DATE2);
$i = addTour($conn, 'i', 3, '15:45:00', TEST_DATE2);
$r10 = regroup($conn);
$ids = [];
foreach ([$e, $f, $g, $h, $i] as $t) { $ids[(string) groupOf($conn, $t)] = true; }
check('a departure over the PAX cap splits into two groups', count($ids) === 2,
    'groups=' . count($ids) . ' created=' . $r10['groups_created'] . ' ids=' . implode(',', array_keys($ids)));
check('... the half that kept e and f kept the original id',
    groupOf($conn, $e) === $g2 && groupOf($conn, $f) === $g2 && groupOf($conn, $g) === $g2,
    'e=' . var_export(groupOf($conn, $e), true) . ' g2=' . $g2);
check('... the other half is a new group',
    groupOf($conn, $h) !== null && groupOf($conn, $h) === groupOf($conn, $i) && groupOf($conn, $h) !== $g2);
$r10b = regroup($conn);
check('... and the split is stable too', (int) $r10b['rows_written'] === 0, 'rows_written=' . $r10b['rows_written']);

} catch (Throwable $e) {
    $failures++;
    echo "FAIL  exception: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}

if ($keep) {
    echo "\n(--keep: synthetic rows left in place)\n";
} else {
    cleanup($conn);
    $left = (int) $conn->query("SELECT COUNT(*) n FROM tours WHERE external_id LIKE '" . TEST_PREFIX . "%'")->fetch_assoc()['n'];
    echo "\ncleanup: " . ($left === 0 ? "all synthetic rows removed\n" : "WARNING $left synthetic tour(s) left\n");
}

echo $failures === 0 ? "\nall scenarios passed\n" : "\n$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
