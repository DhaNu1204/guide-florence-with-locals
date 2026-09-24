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
require_once $apiDir . '/tour_classification.php';
require_once __DIR__ . '/group_language_migrate_lib.php'; // step 6.12

const TEST_DATE    = '2030-02-11';
const TEST_DATE2   = '2030-02-12';
const TEST_DATE3   = '2030-02-13'; // step 6.12 language scenarios
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
    $dates = "('" . TEST_DATE . "','" . TEST_DATE2 . "','" . TEST_DATE3 . "')";
    $conn->query("DELETE FROM pnl_tour_costs WHERE date IN $dates");
    $conn->query("DELETE FROM payments WHERE tour_id IN (SELECT id FROM tours WHERE external_id LIKE '" . TEST_PREFIX . "%')");
    $conn->query("UPDATE tours SET group_id = NULL WHERE external_id LIKE '" . TEST_PREFIX . "%'");
    $conn->query("DELETE FROM tour_groups WHERE group_date IN $dates");
    $conn->query("DELETE FROM tours WHERE external_id LIKE '" . TEST_PREFIX . "%'");
    $conn->query("DELETE FROM guides WHERE email LIKE 'fwl-t612-%@example.invalid'");
    $conn->query("DELETE FROM products WHERE bokun_product_id = " . TEST_PRODUCT); // step 6.13 digest check (fake product)
}

// Step 6.12: bookings carry a language (a departure is one language); English unless a test says otherwise.
function addTour($conn, $n, $pax, $time = '09:00:00', $date = TEST_DATE, $title = 'Uffizi Gallery Test Tour', $language = 'English') {
    $ext = TEST_PREFIX . $n;
    $stmt = $conn->prepare("INSERT INTO tours (external_id, bokun_booking_id, title, date, time, participants,
                                               product_id, is_private, cancelled, paid, language, created_at, updated_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 0, ?, NOW(), NOW())");
    $bid = 'T37' . $n;
    $prod = TEST_PRODUCT;
    $stmt->bind_param('sssssiis', $ext, $bid, $title, $date, $time, $pax, $prod, $language);
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
    (groupRow($conn, $g1)['bucket_key'] ?? null) === TEST_PRODUCT . '|' . TEST_DATE . '|09:00|English',
    var_export(groupRow($conn, $g1)['bucket_key'] ?? null, true));

// a P&L override on that departure, as the owner would set it
$conn->query("INSERT INTO pnl_tour_costs (tour_unit, date, bucket_key, ticket_cost)
              VALUES ('g$g1', '" . TEST_DATE . "', '" . TEST_PRODUCT . '|' . TEST_DATE . "|09:00|English', 42.00)");

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
    (groupRow($conn, $g2)['bucket_key'] ?? null) === TEST_PRODUCT . '|' . TEST_DATE2 . '|15:45|English');
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

// ================= step 6.12: a departure is one language =================================
$D3 = TEST_DATE3;
$regroup3 = function () use ($conn) { return autoGroupAfterSync($conn, TEST_DATE3, TEST_DATE3); };
$migrate3 = function ($apply = true) use ($conn) { return groupLanguageMigrate($conn, $apply, TEST_DATE3); };
$guideOf = function ($tid) use ($conn) {
    $v = $conn->query("SELECT guide_id FROM tours WHERE id = " . (int) $tid)->fetch_assoc()['guide_id'];
    return $v === null ? null : (int) $v;
};
$conn->query("INSERT INTO guides (name, email, languages) VALUES ('FWL-T612 English guide', 'fwl-t612-en@example.invalid', 'English')");
$enGuide = (int) $conn->insert_id;
$conn->query("INSERT INTO guides (name, email, languages) VALUES ('FWL-T612 other guide', 'fwl-t612-xx@example.invalid', 'German')");
$otherGuide = (int) $conn->insert_id;
// an auto group as the pre-6.12 code built it: one departure, old key, any languages
$legacyAutoGroup = function ($time, array $tourIds, $guideId = null) use ($conn) {
    $key = TEST_PRODUCT . '|' . TEST_DATE3 . '|' . substr($time, 0, 5);
    $g = $guideId ? (int) $guideId : 'NULL';
    $conn->query("INSERT INTO tour_groups (group_date, group_time, display_name, total_pax, is_manual_merge, bucket_key, guide_id)
                  VALUES ('" . TEST_DATE3 . "', '$time', 'Uffizi Gallery Test Tour', 0, 0, '$key', $g)");
    $id = (int) $conn->insert_id;
    $conn->query("UPDATE tours SET group_id = $id" . ($guideId ? ", guide_id = $g" : '') . " WHERE id IN (" . implode(',', $tourIds) . ")");
    return $id;
};

// --- 11. same product, same time, English + Italian -> two groups ---------------------------
$en1 = addTour($conn, 'l1', 2, '10:00:00', $D3, 'Uffizi Gallery Test Tour', 'English');
$en2 = addTour($conn, 'l2', 2, '10:00:00', $D3, 'Uffizi Gallery Test Tour', 'English');
$it1 = addTour($conn, 'l3', 2, '10:00:00', $D3, 'Uffizi Gallery Test Tour', 'Italian');
$it2 = addTour($conn, 'l4', 2, '10:00:00', $D3, 'Uffizi Gallery Test Tour', 'Italian');
$regroup3();
$gEn = groupOf($conn, $en1); $gIt = groupOf($conn, $it1);
check('6.12 English + Italian at the same product and time -> two groups',
    $gEn !== null && $gIt !== null && $gEn !== $gIt && groupOf($conn, $en2) === $gEn && groupOf($conn, $it2) === $gIt,
    "en=$gEn it=$gIt");
check('... each group carries its language in the key',
    groupRow($conn, $gEn)['bucket_key'] === TEST_PRODUCT . "|$D3|10:00|English" && groupRow($conn, $gIt)['bucket_key'] === TEST_PRODUCT . "|$D3|10:00|Italian");

// --- 12. three English bookings -> one group ------------------------------------------------
$t1 = addTour($conn, 'm1', 2, '11:00:00', $D3); $t2 = addTour($conn, 'm2', 2, '11:00:00', $D3); $t3 = addTour($conn, 'm3', 3, '11:00:00', $D3);
$regroup3();
$g11 = groupOf($conn, $t1);
check('6.12 three English bookings -> one group', $g11 !== null && groupOf($conn, $t2) === $g11 && groupOf($conn, $t3) === $g11 && memberCount($conn, $g11) === 3);

// --- 13. a booking with no language ---------------------------------------------------------
$u1 = addTour($conn, 'n1', 2, '12:00:00', $D3, 'Uffizi Gallery Test Tour', 'English');
$u2 = addTour($conn, 'n2', 2, '12:00:00', $D3, 'Uffizi Gallery Test Tour', 'English');
$un = addTour($conn, 'n3', 1, '12:00:00', $D3, 'Uffizi Gallery Test Tour', null);
$regroup3();
$g12 = groupOf($conn, $u1);
check('6.12 a no-language booking joins when exactly one language group exists', $g12 !== null && groupOf($conn, $un) === $g12, 'unknown in ' . var_export(groupOf($conn, $un), true));
addTour($conn, 'n4', 2, '12:00:00', $D3, 'Uffizi Gallery Test Tour', 'Italian');
addTour($conn, 'n5', 2, '12:00:00', $D3, 'Uffizi Gallery Test Tour', 'Italian');
$regroup3();
check('... and stays on its own once there are two', groupOf($conn, $un) === null && groupOf($conn, $u1) === $g12 && groupOf($conn, $u2) === $g12,
    'unknown in ' . var_export(groupOf($conn, $un), true));

// --- 14. a manual mixed merge is left alone ------------------------------------------------
$mx1 = addTour($conn, 'o1', 2, '13:00:00', $D3, 'Uffizi Gallery Test Tour', 'English');
$mx2 = addTour($conn, 'o2', 2, '13:00:00', $D3, 'Uffizi Gallery Test Tour', 'Italian');
$conn->query("INSERT INTO tour_groups (group_date, group_time, display_name, total_pax, is_manual_merge) VALUES ('$D3', '13:00:00', 'Manual mixed', 4, 1)");
$man = (int) $conn->insert_id;
$conn->query("UPDATE tours SET group_id = $man WHERE id IN ($mx1, $mx2)");
$beforeRow = groupRow($conn, $man);
$regroup3();
$migrate3();
check('6.12 a manual mixed merge is left alone by the sync and the migration',
    groupRow($conn, $man) == $beforeRow && groupOf($conn, $mx1) === $man && groupOf($conn, $mx2) === $man);

// --- 15. a single-language group keeps its id through the key rewrite ----------------------
$conn->query("UPDATE tour_groups SET bucket_key = '" . TEST_PRODUCT . "|$D3|11:00' WHERE id = $g11"); // as before 6.12
$m15 = $migrate3();
check('6.12 a single-language group keeps its id through the key rewrite',
    groupOf($conn, $t1) === $g11 && groupRow($conn, $g11)['bucket_key'] === TEST_PRODUCT . "|$D3|11:00|English" && $m15[0]['rewritten'] === 1,
    'rewritten=' . $m15[0]['rewritten']);
$r15 = $regroup3();
check('... and the sync after it writes nothing', (int) $r15['rows_written'] === 0, 'rows_written=' . $r15['rows_written']);

// --- 16. a mixed auto group with a payment is not split ------------------------------------
$p1 = addTour($conn, 'p1', 2, '14:00:00', $D3, 'Uffizi Gallery Test Tour', 'English');
$p2 = addTour($conn, 'p2', 2, '14:00:00', $D3, 'Uffizi Gallery Test Tour', 'English');
$p3 = addTour($conn, 'p3', 2, '14:00:00', $D3, 'Uffizi Gallery Test Tour', 'Italian');
$p4 = addTour($conn, 'p4', 2, '14:00:00', $D3, 'Uffizi Gallery Test Tour', 'Italian');
$gPay = $legacyAutoGroup('14:00:00', [$p1, $p2, $p3, $p4], $enGuide);
$conn->query("INSERT INTO payments (tour_id, guide_id, amount, payment_method, payment_date) VALUES ($p1, $enGuide, 50.00, 'cash', '$D3')");
$r16 = $regroup3();
check('6.12 the sync leaves a mixed auto group exactly as it is', memberCount($conn, $gPay) === 4 && (int) $r16['groups_frozen_mixed_language'] === 1,
    'members=' . memberCount($conn, $gPay) . ' frozen=' . $r16['groups_frozen_mixed_language']);
$m16 = $migrate3();
check('6.12 a mixed group with a payment is not split (listed instead)',
    memberCount($conn, $gPay) === 4 && $m16[0]['held'] === 1 && (bool) preg_grep('/HELD: payment recorded.*g' . $gPay . ' /', $m16[1]),
    implode(' | ', $m16[1]));

// --- 17. a mixed auto group with an English guide is split; the guide stays with English -----
$conn->query("DELETE FROM payments WHERE tour_id = $p1");
$m17 = $migrate3();
$gItNew = groupOf($conn, $p3);
check('6.12 split: the guide\'s language keeps the id, the other language is a new group',
    groupOf($conn, $p1) === $gPay && groupOf($conn, $p2) === $gPay && $gItNew !== null && $gItNew !== $gPay && groupOf($conn, $p4) === $gItNew,
    'en=' . var_export(groupOf($conn, $p1), true) . ' it=' . var_export($gItNew, true) . ' split=' . $m17[0]['split']);
check('... the Italian bookings no longer carry the English guide', $guideOf($p3) === null && $guideOf($p4) === null
    && (int) groupRow($conn, $gPay)['guide_id'] === $enGuide && $guideOf($p1) === $enGuide);
check('... keys and PAX follow', groupRow($conn, $gPay)['bucket_key'] === TEST_PRODUCT . "|$D3|14:00|English"
    && $gItNew && groupRow($conn, $gItNew)['bucket_key'] === TEST_PRODUCT . "|$D3|14:00|Italian" && (int) groupRow($conn, $gPay)['total_pax'] === 4);
$r17 = $regroup3();
check('... and a sync right after creates no churn', (int) $r17['rows_written'] === 0 && groupOf($conn, $p1) === $gPay && groupOf($conn, $p3) === $gItNew,
    'rows_written=' . $r17['rows_written']);

// --- 18. guide propagation is still fill-only, and only within the language ----------------
$conn->query("UPDATE tours SET guide_id = $otherGuide WHERE id = $p2"); // a member with a different guide
$p5 = addTour($conn, 'p5', 1, '14:00:00', $D3, 'Uffizi Gallery Test Tour', 'English');
$regroup3();
check('6.12 a new English booking joins the English group and inherits its guide', groupOf($conn, $p5) === $gPay && $guideOf($p5) === $enGuide);
check('... a member carrying a different guide is not overwritten', $guideOf($p2) === $otherGuide);
check('... the Italian group did not pick up the English guide', $guideOf($p3) === null && $gItNew && groupRow($conn, $gItNew)['guide_id'] === null);

// --- 19. 1 Spanish + 1 English, no guide, no payment: no language forms a group -> dissolved --
$q1 = addTour($conn, 'q1', 4, '16:15:00', $D3, 'Uffizi Gallery Test Tour', 'Spanish');
$q2 = addTour($conn, 'q2', 2, '16:15:00', $D3, 'Uffizi Gallery Test Tour', 'English');
$gQ = $legacyAutoGroup('16:15:00', [$q1, $q2]);
$m19 = $migrate3();
check('6.12 a 1+1 mixed group without guide or payment is dissolved', !groupExists($conn, $gQ) && groupOf($conn, $q1) === null && groupOf($conn, $q2) === null
    && $m19[0]['dissolved'] === 1, implode(' | ', $m19[1]));
$r19 = $regroup3();
check('... and the sync leaves both bookings on their own', groupOf($conn, $q1) === null && groupOf($conn, $q2) === null && (int) $r19['rows_written'] === 0,
    'rows_written=' . $r19['rows_written']);

// ================= step 6.13: a note on a group ===========================================
$noteOf = function ($table, $id) use ($conn) {
    $r = $conn->query("SELECT notes FROM $table WHERE id = " . (int) $id)->fetch_assoc();
    return $r ? $r['notes'] : false;
};
$setNote = function ($table, $id, $text) use ($conn) {
    $s = $conn->prepare("UPDATE $table SET notes = ? WHERE id = ?");
    $s->bind_param('si', $text, $id);
    $s->execute();
    $s->close();
};

// --- 20. a sync never writes or clears a group note (byte-identical) ----------------------
$NOTE = "Meet at Loggia dei Lanzi 9:15 \xE2\x80\x94 caf\xC3\xA9 apr\xC3\xA8s\none guest uses a wheelchair";
$setNote('tour_groups', $gEn, $NOTE);
$setNote('tours', $en2, 'Vegetarian');
$r20a = $regroup3();
$r20b = $regroup3();
check('6.13 two syncs leave a group note byte-identical', $noteOf('tour_groups', $gEn) === $NOTE,
    bin2hex(substr((string) $noteOf('tour_groups', $gEn), 0, 12)));
check('... and write nothing', (int) $r20b['rows_written'] === 0, 'rows_written=' . $r20b['rows_written']);

// --- 21. the guide's evening digest does not change when a group has a note --------------
require_once $apiDir . '/guide_digest.php';
$conn->query("INSERT IGNORE INTO products (bokun_product_id, title, product_type) VALUES (" . TEST_PRODUCT . ", 'FWL test product', 'tour')");
$setNote('tour_groups', $gPay, null);
$dBefore = collectDigestDepartures($conn, TEST_DATE3);
$vBefore = isset($dBefore[$enGuide]) ? buildDigestVariables($dBefore[$enGuide]['guide_name'], TEST_DATE3, $dBefore[$enGuide]['departures']) : null;
$setNote('tour_groups', $gPay, 'INTERNAL: never to a guide');
$dAfter = collectDigestDepartures($conn, TEST_DATE3);
$vAfter = isset($dAfter[$enGuide]) ? buildDigestVariables($dAfter[$enGuide]['guide_name'], TEST_DATE3, $dAfter[$enGuide]['departures']) : null;
check('6.13 the digest has the noted departure to compare (guide assigned)', $vBefore !== null && count($dBefore[$enGuide]['departures']) >= 1);
check('6.13 the guide digest is byte-identical with and without the group note',
    json_encode($dBefore) === json_encode($dAfter) && json_encode($vBefore) === json_encode($vAfter)
    && strpos(json_encode($vAfter), 'INTERNAL') === false);

// --- 22. the sync dissolves a noted group (a cancellation leaves one booking) --------------
$conn->query("UPDATE tours SET cancelled = 1 WHERE id = $en1");
$regroup3();
check('6.13 sync dissolve: the group is gone', !groupExists($conn, $gEn) && groupOf($conn, $en2) === null);
check('... the remaining booking keeps its own note and gets the group note appended',
    $noteOf('tours', $en2) === "Vegetarian\n[Group note] " . $NOTE, var_export($noteOf('tours', $en2), true));
check('... the cancelled booking does not need it (a live one got it)', $noteOf('tours', $en1) === null);
$regroup3();
check('... and a later sync does not add it twice', substr_count((string) $noteOf('tours', $en2), '[Group note]') === 1);

// --- 23. a 6.12 language split keeps the note on both groups -------------------------------
$s1 = addTour($conn, 's1', 2, '17:00:00', $D3, 'Uffizi Gallery Test Tour', 'English');
$s2 = addTour($conn, 's2', 2, '17:00:00', $D3, 'Uffizi Gallery Test Tour', 'English');
$s3 = addTour($conn, 's3', 1, '17:00:00', $D3, 'Uffizi Gallery Test Tour', 'Italian');
$s4 = addTour($conn, 's4', 1, '17:00:00', $D3, 'Uffizi Gallery Test Tour', 'Italian');
$gS = $legacyAutoGroup('17:00:00', [$s1, $s2, $s3, $s4]);
$setNote('tour_groups', $gS, 'Meet at the Loggia');
$migrate3();
$gS2 = groupOf($conn, $s3);
check('6.13 language split: the group keeping the id keeps the note', groupOf($conn, $s1) === $gS && $noteOf('tour_groups', $gS) === 'Meet at the Loggia');
check('... the new group gets a copy', $gS2 !== null && $gS2 !== $gS && $noteOf('tour_groups', $gS2) === 'Meet at the Loggia');
check('... and the bookings that moved do not get a second copy on their own note', $noteOf('tours', $s3) === null);
$conn->query("DELETE FROM products WHERE bokun_product_id = " . TEST_PRODUCT);

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
