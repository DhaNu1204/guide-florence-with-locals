<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only check (step 1.4): never deployed
/**
 * Step 1.4 local check for the pure webhook helpers (no database needed):
 *   php tools/webhook_helpers_check.php
 * Exit code 0 = all assertions hold.
 */
require_once __DIR__ . '/../public_html/api/webhook_helpers.php';

$failures = 0;
function check($label, $ok, $detail = '') {
    global $failures;
    if (!$ok) { $failures++; }
    printf("%s  %s%s\n", $ok ? 'ok  ' : 'FAIL', $label, $detail !== '' ? "  ($detail)" : '');
}

// --- dates: dedupe, sort, cap ------------------------------------------------
$fifty = [];
for ($i = 0; $i < 50; $i++) { $fifty[] = date('Y-m-d', strtotime("2026-10-01 +{$i} days")); }
shuffle($fifty);
$r = webhookCapDates(array_merge($fifty, $fifty));
check('50 distinct dates (each twice) -> found 50', $r['found'] === 50, 'found=' . $r['found']);
check('... processed 3', count($r['processed']) === 3, 'processed=' . count($r['processed']));
check('... the 3 earliest, sorted', $r['processed'] === ['2026-10-01', '2026-10-02', '2026-10-03'], implode(',', $r['processed']));
$r = webhookCapDates(['2026-09-20']);
check('1 date -> found 1 processed 1', $r['found'] === 1 && $r['processed'] === ['2026-09-20']);
$r = webhookCapDates([]);
check('no dates -> found 0 processed 0', $r['found'] === 0 && $r['processed'] === []);
$r = webhookCapDates([null, 'x', 'x', 5]);
check('non-strings ignored, duplicates collapsed', $r['found'] === 1 && $r['processed'] === ['x']);

// --- payload: <= 64 KB stored as before, > 64 KB cut + marker, always valid JSON ---
$small = json_encode(['bookingId' => 1, 'activityBookings' => [['date' => '2026-09-20']]]);
check('small payload stored as the decoded event', webhookStorablePayload($small, json_decode($small, true)) === $small);
$big = json_encode(['bookingId' => 2, 'blob' => str_repeat('x', 200 * 1024)]);
check('200 KB body is above the limit', strlen($big) > WEBHOOK_MAX_PAYLOAD_BYTES, strlen($big) . ' bytes');
$stored = webhookStorablePayload($big, json_decode($big, true));
$wrapper = json_decode($stored, true);
check('stored value is valid JSON', is_array($wrapper));
check('wrapper flags truncation', ($wrapper['_truncated'] ?? false) === true);
check('stored raw part <= 65536 + marker', strlen($wrapper['payload']) <= WEBHOOK_MAX_PAYLOAD_BYTES + strlen(WEBHOOK_TRUNCATED_MARKER), strlen($wrapper['payload']) . ' bytes');
check('marker appended', substr($wrapper['payload'], -strlen(WEBHOOK_TRUNCATED_MARKER)) === WEBHOOK_TRUNCATED_MARKER);
check('original size recorded', ($wrapper['_original_bytes'] ?? 0) === strlen($big));
$utf8 = json_encode(['s' => str_repeat("\xC3\xA9", 40000)]); // é x 40000 = 80000 bytes, cut lands mid-character
$storedUtf8 = json_decode(webhookStorablePayload($utf8, json_decode($utf8, true)), true);
check('cut never splits a UTF-8 character (still valid JSON)', is_array($storedUtf8) && mb_check_encoding($storedUtf8['payload'], 'UTF-8'));
check('invalid JSON body below the limit stores "null"', webhookStorablePayload('not json', null) === 'null');

// --- key comparison ------------------------------------------------------------
check('right key matches', webhookKeyMatches('abc123', 'abc123') === true);
check('wrong key fails', webhookKeyMatches('abc124', 'abc123') === false);
check('missing key fails', webhookKeyMatches(null, 'abc123') === false);
check('empty secret never matches (even an empty key)', webhookKeyMatches('', '') === false);
check('array key (?key[]=) fails instead of throwing', webhookKeyMatches(['x'], 'abc123') === false);

echo $failures === 0 ? "ALL OK\n" : "$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
