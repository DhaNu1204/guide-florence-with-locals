<?php
// Step 6.18 - set the Viator account of ONE hand-entered booking, once. CLI only, never deployed.
//   FWL_API_DIR=<api dir> php viator_account_set.php --id=7804 --account=current [--apply]
// Refuses any row that has a Viator product code: for those Bokun's code decides, and the sync
// would correct a hand-set value anyway. Neither the sync nor viatorAccountRelabel() writes a row
// without a product code, so a value set here stays.
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }
$apiDir = getenv('FWL_API_DIR') ?: __DIR__ . '/../public_html/api';
require_once $apiDir . '/config.php';
require_once $apiDir . '/viator_helpers.php';

$opt = getopt('', ['id:', 'account:', 'apply']);
$id = (int) ($opt['id'] ?? 0);
$account = (string) ($opt['account'] ?? '');
$apply = isset($opt['apply']);
if ($id <= 0 || !in_array($account, [VIATOR_ACCOUNT_LEGACY, VIATOR_ACCOUNT_CURRENT], true)) {
    fwrite(STDERR, "usage: --id=<tour id> --account=legacy|current [--apply]\n"); exit(2);
}

$read = function () use ($conn, $id) {
    $st = $conn->prepare("SELECT id, source, external_id, date, time, booking_channel, viator_account, updated_at, bokun_data FROM tours WHERE id = ?");
    $st->bind_param('i', $id);
    $st->execute();
    return $st->get_result()->fetch_assoc();
};
$show = function ($label, $r) {
    printf("%s t%d source=%s ext=%s %s %s channel=%s stored=%s shown=%s updated_at=%s\n", $label, $r['id'],
        $r['source'] ?? 'NULL', $r['external_id'] ?? 'NULL', $r['date'], substr($r['time'], 0, 5), $r['booking_channel'],
        $r['viator_account'] ?? 'NULL', viatorAccountForDisplay($r['booking_channel'], $r['viator_account']) ?? 'none', $r['updated_at']);
};

$r = $read();
if (!$r) { fwrite(STDERR, "t$id not found\n"); exit(1); }
$show('BEFORE', $r);
if (!viatorIsViatorChannel($r['booking_channel'])) { fwrite(STDERR, "refusing: t$id is not a Viator booking\n"); exit(1); }
if (viatorProductCodes($r['bokun_data'])) { fwrite(STDERR, "refusing: t$id has a Viator product code - the sync decides its account\n"); exit(1); }

if (!$apply) { echo "dry run: would set viator_account = '$account'\n"; exit(0); }
$st = $conn->prepare("UPDATE tours SET viator_account = ?, updated_at = updated_at WHERE id = ?");
$st->bind_param('si', $account, $id);
$st->execute();
echo "rows changed: {$st->affected_rows}\n";
$show('AFTER ', $read());
