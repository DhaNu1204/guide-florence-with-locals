<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only check (step 4.10b): never deployed
/**
 * Step 4.10b unit tests for the home-screen app review (no database):
 *   php tools/pwa_load_review_check.php
 * The rows are the real 1 Oct 2026 home-screen app loads (client_perf ids 124, 126, 128, 144).
 */
require_once __DIR__ . '/pwa_load_review_lib.php';

$failures = 0;
function check($label, $ok, $detail = '') {
    global $failures;
    if (!$ok) { $failures++; }
    printf("%s  %s%s\n", $ok ? 'ok  ' : 'FAIL', $label, $detail !== '' ? "  ($detail)" : '');
}

$row = function (array $over) {
    return $over + ['rome_day' => '2026-10-01', 'verify_status' => 'ok', 'chunk_status' => 'ok', 'list_status' => 'ok',
                    'timeouts' => 0, 'shell_fallback' => 0, 'probe_status' => null];
};
$r124 = $row(['id' => 124]);
$r126 = $row(['id' => 126, 'list_status' => 'failed', 'timeouts' => 10]); // 12:27, before 4.10b: no check
$r128 = $row(['id' => 128, 'shell_fallback' => 1]);
$r144 = $row(['id' => 144]);

check('a normal load is not a failure', !pwaIsFailure($r124));
check('12:27 (list failed, 10 timeouts) is a full stall', pwaIsFullStall($r126) && pwaIsFailure($r126));
check('a cached start that then loaded is a failure but not a stall', pwaIsFailure($r128) && !pwaIsFullStall($r128));
check('slow but recovered (timeouts, list ok) is not a full stall', !pwaIsFullStall($row(['timeouts' => 12])));
check('list pending at the deadline is a full stall', pwaIsFullStall($row(['list_status' => 'pending'])));
check('auth check never answered is a full stall', pwaIsFullStall($row(['verify_status' => 'pending', 'list_status' => 'none'])));
check('a server error with no timeout is a failure, not a stall', pwaIsFailure($row(['list_status' => 'failed'])) && !pwaIsFullStall($row(['list_status' => 'failed'])));

check('verdict ok', pwaCheckVerdict('ok') === 'server reachable');
check('verdict timeout', strpos(pwaCheckVerdict('timeout'), 'NOT reachable') !== false);
check('verdict for rows before 4.10b', pwaCheckVerdict(null) === 'no check recorded');
check('verdict notrun / running', strpos(pwaCheckVerdict('notrun'), 'no result') === 0 && strpos(pwaCheckVerdict('running'), 'no result') === 0);

$days = ['2026-09-30', '2026-10-01', '2026-10-02'];
$rev = pwaReview([$r124, $r126, $r128, $r144], $days);
check('every day of the period is listed, even without loads', array_keys($rev['byDay']) === $days && $rev['byDay']['2026-10-02']['loads'] === 0);
check('1 Oct: 4 loads, 2 failures, 1 full stall', $rev['byDay']['2026-10-01']['loads'] === 4 && $rev['byDay']['2026-10-01']['failures'] === 2
    && $rev['byDay']['2026-10-01']['full_stalls'] === 1);
check('1 Oct: the stall had no check -> not counted as reachable', $rev['reachable_stalls'] === 0 && !$rev['recommend_cdn_test']);
check('no recommendation sentence below the threshold', strpos(pwaRecommendation($rev), 'no CDN test needed') !== false);

$one = $row(['list_status' => 'failed', 'timeouts' => 4, 'probe_status' => 'ok']);
$rev = pwaReview([$one], $days);
check('one reachable full stall: not yet', $rev['reachable_stalls'] === 1 && !$rev['recommend_cdn_test']);
$rev = pwaReview([$one, $row(['rome_day' => '2026-10-02', 'list_status' => 'pending', 'probe_status' => 'ok'])], $days);
check('two reachable full stalls: recommend the CDN test', $rev['reachable_stalls'] === 2 && $rev['recommend_cdn_test']);
check('the sentence names the CDN switch', strpos(pwaRecommendation($rev), 'Switch the Hostinger CDN off') !== false);
$rev = pwaReview([$one, $row(['list_status' => 'failed', 'timeouts' => 3, 'probe_status' => 'timeout'])], $days);
check('a stall where the server was NOT reachable does not count', $rev['reachable_stalls'] === 1 && !$rev['recommend_cdn_test']);
$rev = pwaReview([$one, $row(['timeouts' => 5, 'probe_status' => 'ok'])], $days);
check('a recovered load with a good check does not count', $rev['reachable_stalls'] === 1);

echo $failures ? "\n$failures FAILED\n" : "\nall checks passed\n";
exit($failures ? 1 : 0);
