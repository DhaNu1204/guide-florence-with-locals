<?php
// Step 6.18 step 0 - READ ONLY. Never deployed (tools/ pattern). Runs no write of any kind.
// Which field in bokun_data tells the old Viator account from the new one?
//   FWL_API_DIR=<api dir> php viator_account_probe.php [--target=1456081981] [--new=5686765] [--old=426324]
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }
require_once getenv('FWL_API_DIR') . '/config.php';

$opt    = getopt('', ['target::', 'new::', 'old::']);
$target = $opt['target'] ?? '1456081981';
$NEW    = $opt['new'] ?? '5686765';
$OLD    = $opt['old'] ?? '426324';
$today  = (new DateTime('now', new DateTimeZone('Europe/Rome')))->format('Y-m-d');
echo "today (Rome) = $today   target = $target   new marker = $NEW   old marker = $OLD\n";

// Personal data never printed.
$PII = '/(firstName|lastName|email|phone|address|answers|name$|passport|nationality|dateOfBirth|customer\.|contactDetails|notes?$|comment)/i';

function flat($v, $p = '', &$out = []) {
    if (is_array($v)) { foreach ($v as $k => $x) { flat($x, $p === '' ? (string) $k : "$p.$k", $out); } }
    else { $out[$p] = is_bool($v) ? ($v ? 'true' : 'false') : (string) $v; }
    return $out;
}
function find($flat, $needle) {
    $hit = [];
    foreach ($flat as $p => $v) { if ($needle !== '' && strpos($v, $needle) !== false) { $hit[$p] = $v; } }
    return $hit;
}
$V = "(booking_channel LIKE '%Viator%' OR booking_channel LIKE '%Tripadvisor%')";

// ---- 1. the target booking --------------------------------------------------------------
$st = $conn->prepare("SELECT id, external_id, bokun_confirmation_code, date, time, booking_channel, viator_account,
                             product_id, created_at, bokun_data FROM tours
                      WHERE external_id LIKE CONCAT('%', ?, '%') OR bokun_data LIKE CONCAT('%', ?, '%') LIMIT 3");
$st->bind_param('ss', $target, $target);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
echo "\n=== 1. target rows: " . count($rows) . " ===\n";
if (!$rows) { echo "NOT FOUND\n"; }
$targetFlat = null;
foreach ($rows as $r) {
    printf("t%d external_id=%s conf=%s %s %s channel=%s viator_account=%s product_id=%s created_at=%s\n",
        $r['id'], $r['external_id'], $r['bokun_confirmation_code'], $r['date'], $r['time'], $r['booking_channel'],
        $r['viator_account'] ?? 'NULL', $r['product_id'], $r['created_at']);
    $targetFlat = flat(json_decode($r['bokun_data'], true) ?: []);
    echo "  paths containing '$NEW':\n";
    foreach (find($targetFlat, $NEW) as $p => $v) { echo "    $p = $v\n"; }
    echo "  paths containing '$OLD':\n";
    foreach (find($targetFlat, $OLD) as $p => $v) { echo "    $p = $v\n"; }
}

// ---- 2. three old-account bookings (oldest-created live future Viator rows) ---------------
$old = $conn->query("SELECT id, external_id, date, time, viator_account, bokun_data FROM tours
                     WHERE $V AND viator_account = 'legacy' AND cancelled = 0 AND date >= '$today'
                     ORDER BY JSON_EXTRACT(bokun_data, '$.creationDate') ASC LIMIT 3")->fetch_all(MYSQLI_ASSOC);
$cols = [];
if ($targetFlat !== null) { $cols['TARGET'] = $targetFlat; }
foreach ($old as $o) {
    $cols['t' . $o['id']] = flat(json_decode($o['bokun_data'], true) ?: []);
    echo "\nold sample t{$o['id']} {$o['external_id']} {$o['date']} {$o['time']} paths containing '$OLD':\n";
    foreach (find($cols['t' . $o['id']], $OLD) as $p => $v) { echo "    $p = $v\n"; }
}

// Every identifying-looking leaf, side by side.
echo "\n=== 2. identifying fields side by side (" . implode(' | ', array_keys($cols)) . ") ===\n";
$KEY = '/(channel|seller|agent|vendor|supplier|external|productCode|affiliate|partner|marketplace|contract|account|source|reseller|bookingType|productId|product\.id|activity\.id|rateId|confirmationCode|resold|owner)/i';
$paths = [];
foreach ($cols as $f) { foreach ($f as $p => $_) { if (preg_match($KEY, $p) && !preg_match($PII, $p)) { $paths[$p] = 1; } } }
ksort($paths);
foreach (array_keys($paths) as $p) {
    $vals = [];
    foreach ($cols as $f) { $vals[] = isset($f[$p]) ? substr($f[$p], 0, 28) : '-'; }
    $diff = count(array_unique($vals)) > 1 ? '*' : ' ';
    printf("%s %-62s %s\n", $diff, substr($p, 0, 62), implode(' | ', $vals));
}

// ---- 3. counts by account, using the product code marker --------------------------------
// Classify on productBookings[*].productExternalId first; fall back to any marker in the payload.
echo "\n=== 3. counts (all Viator rows, incl. cancelled; live = cancelled 0) ===\n";
$res = $conn->query("SELECT id, external_id, date, time, cancelled, viator_account, title, bokun_data FROM tours WHERE $V");
$tally = [];
$unknown = [];
$shapes = [];
while ($r = $res->fetch_assoc()) {
    $d = json_decode($r['bokun_data'], true) ?: [];
    $codes = [];
    foreach (($d['productBookings'] ?? []) as $pb) {
        foreach (['productExternalId', 'externalProductCode', 'productCode'] as $k) {
            if (!empty($pb[$k])) { $codes[] = (string) $pb[$k]; }
        }
        if (!empty($pb['product']['externalId'])) { $codes[] = (string) $pb['product']['externalId']; }
    }
    $codes = array_values(array_unique($codes));
    foreach ($codes as $c) { $s = preg_replace('/\d/', '9', $c); $shapes[$s] = ($shapes[$s] ?? 0) + 1; }
    $joined = implode(',', $codes);
    if (strpos($joined, $NEW) !== false && strpos($joined, $OLD) === false)      { $acct = 'new'; }
    elseif (strpos($joined, $OLD) !== false && strpos($joined, $NEW) === false)  { $acct = 'old'; }
    else {
        $raw = $r['bokun_data'] ?? '';
        $acct = (strpos($raw, $NEW) !== false) ? 'new?raw' : ((strpos($raw, $OLD) !== false) ? 'old?raw' : 'unknown');
    }
    $when = $r['date'] >= $today ? 'future' : 'past';
    $live = $r['cancelled'] ? 'cancelled' : 'live';
    $lbl  = $r['viator_account'] ?? 'NULL';
    $tally["$acct|$when|$live|label=$lbl"] = ($tally["$acct|$when|$live|label=$lbl"] ?? 0) + 1;
    if ($acct !== 'new' && $acct !== 'old') {
        $unknown[] = sprintf("t%d %s %s %s %s codes=[%s] %s", $r['id'], $r['external_id'], $r['date'], $r['time'],
                             $live, $joined, substr($r['title'], 0, 50));
    }
}
ksort($tally);
foreach ($tally as $k => $n) { printf("  %-50s %6d\n", $k, $n); }
echo "\nproduct code shapes:\n";
arsort($shapes);
foreach ($shapes as $s => $n) { printf("  %-20s %6d\n", $s, $n); }
echo "\nnot classified by the product code: " . count($unknown) . "\n";
foreach (array_slice($unknown, 0, 40) as $u) { echo "  $u\n"; }

// ---- 4. hand-entered Viator bookings (step 6.18 follow-up) --------------------------------
// Hand-entered = no Bokun payload: source 'manual' (step 6.4 form) or no external_id / bokun_data.
$H = "$V AND (source = 'manual' OR external_id IS NULL OR external_id = '' OR bokun_data IS NULL OR bokun_data = '')";
echo "\n=== 4. hand-entered Viator bookings ===\n";
$r = $conn->query("SELECT COUNT(*) n, SUM(cancelled = 0) live, SUM(date >= '$today') future,
                          SUM(date >= '$today' AND cancelled = 0) future_live FROM tours WHERE $H")->fetch_assoc();
echo "total {$r['n']}  live {$r['live']}  future {$r['future']}  future live {$r['future_live']}\n";
$res = $conn->query("SELECT id, source, external_id, date, time, cancelled, booking_channel, viator_account, product_id,
                            participants, created_at, updated_at, title, notes, payment_notes FROM tours WHERE $H ORDER BY date");
while ($x = $res->fetch_assoc()) {
    // Never print free text; only Viator-looking references found in it.
    preg_match_all('/\b(BR-\d{6,}|VIA-\d{5,}|\d{10})\b/', ($x['notes'] ?? '') . ' ' . ($x['payment_notes'] ?? ''), $m);
    printf("t%d source=%s ext=%s %s %s %s channel=%s label=%s product_id=%s pax=%s created=%s updated=%s refs=[%s] %s\n",
        $x['id'], $x['source'] ?? 'NULL', $x['external_id'] ?? 'NULL', $x['date'], substr($x['time'], 0, 5),
        $x['cancelled'] ? 'cancelled' : 'live', $x['booking_channel'], $x['viator_account'] ?? 'NULL',
        $x['product_id'] ?? 'NULL', $x['participants'], $x['created_at'], $x['updated_at'],
        implode(',', array_unique($m[0])), substr($x['title'], 0, 60));
    // Same departure from the sync, if any: which account do those carry?
    $st = $conn->prepare("SELECT id, external_id, time, participants, cancelled, bokun_data FROM tours
                          WHERE date = ? AND id <> ? AND $V AND external_id LIKE 'VIA-%' ORDER BY time");
    $st->bind_param('si', $x['date'], $x['id']);
    $st->execute();
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $s) {
        $d = json_decode($s['bokun_data'], true) ?: [];
        printf("    same day synced Viator: t%d %s %s pax=%s %s code=%s\n", $s['id'], $s['external_id'], substr($s['time'], 0, 5),
            $s['participants'], $s['cancelled'] ? 'cancelled' : 'live', $d['productBookings'][0]['productExternalId'] ?? '-');
    }
    // Any synced booking whose Viator reference appears in the notes?
    foreach (array_unique($m[0]) as $ref) {
        $num = preg_replace('/\D/', '', $ref);
        $st = $conn->prepare("SELECT id, external_id, date, bokun_data FROM tours WHERE id <> ? AND (external_id LIKE CONCAT('%', ?, '%') OR bokun_data LIKE CONCAT('%', ?, '%')) LIMIT 3");
        $st->bind_param('iss', $x['id'], $num, $num);
        $st->execute();
        foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $s) {
            $d = json_decode($s['bokun_data'], true) ?: [];
            printf("    ref %s matches t%d %s %s code=%s\n", $ref, $s['id'], $s['external_id'], $s['date'], $d['productBookings'][0]['productExternalId'] ?? '-');
        }
    }
}

// ---- 5. ids of new-account bookings --------------------------------------------------------
echo "\n=== 5. bookings carrying the new supplier number in productExternalId ===\n";
$res = $conn->query("SELECT id, external_id, date, time, cancelled, viator_account, created_at, product_id, title,
                            FROM_UNIXTIME(JSON_EXTRACT(bokun_data, '$.creationDate') / 1000) bokun_created,
                            JSON_UNQUOTE(JSON_EXTRACT(bokun_data, '$.productBookings[0].productExternalId')) code
                     FROM tours WHERE $V AND JSON_UNQUOTE(JSON_EXTRACT(bokun_data, '$.productBookings[0].productExternalId')) LIKE '$NEW%' ORDER BY date, time");
while ($x = $res->fetch_assoc()) {
    printf("  t%d %s %s %s %s label=%s code=%s product_id=%s saved=%s bokun_created=%s %s\n", $x['id'], $x['external_id'],
        $x['date'], substr($x['time'], 0, 5), $x['cancelled'] ? 'cancelled' : 'live', $x['viator_account'] ?? 'NULL',
        $x['code'], $x['product_id'], $x['created_at'], $x['bokun_created'], substr($x['title'], 0, 60));
}
