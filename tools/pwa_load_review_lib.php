<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only (step 4.10b): never deployed
/**
 * Step 4.10b - the home-screen app section of the weekly usage review (tools/assistant_usage.php),
 * pure functions over client_perf rows so tools/pwa_load_review_check.php can test them offline.
 *
 * A row is the recorder's record of one page load (see public_html/api/client_perf.php).
 *   failure     = the data did not arrive (list / auth check / route chunk failed or still pending),
 *                 a request timed out, or the page started from the cached shell.
 *   full stall  = the data never arrived AND it was a stall (a request timed out, or it was still
 *                 pending) - not an error status the server sent back.
 *   server check after a failure (probe_status): ok = the server was reachable at that moment, so
 *   only the app's path was stuck; timeout / network / offline = the phone could not reach it at all.
 * Two or more full stalls in a week with the server reachable = the next test is the Hostinger CDN.
 */

const PWA_CDN_TEST_THRESHOLD = 2;

function pwaPending($s) { return $s === 'pending'; }
function pwaBad($s) { return $s === 'failed' || $s === 'pending'; }

/** Did the data of this load never arrive? */
function pwaNoData(array $r) {
    return pwaBad($r['list_status'] ?? 'none') || pwaBad($r['verify_status'] ?? 'none') || pwaBad($r['chunk_status'] ?? 'none');
}

function pwaIsFailure(array $r) {
    return pwaNoData($r) || (int) ($r['timeouts'] ?? 0) > 0 || (int) ($r['shell_fallback'] ?? 0) === 1;
}

function pwaIsFullStall(array $r) {
    if (!pwaNoData($r)) return false;
    $stalled = (int) ($r['timeouts'] ?? 0) > 0;
    foreach (['list_status', 'verify_status', 'chunk_status'] as $k) $stalled = $stalled || pwaPending($r[$k] ?? 'none');
    return $stalled;
}

/** The server check in plain words. */
function pwaCheckVerdict($probeStatus) {
    switch ((string) $probeStatus) {
        case 'ok':      return 'server reachable';
        case 'timeout': return 'server NOT reachable (check timed out)';
        case 'network': return 'server NOT reachable (no connection)';
        case 'offline': return 'phone offline';
        case 'http':    return 'server answered with an error';
        case 'running': return 'no result (app closed while checking)';
        case 'notrun':  return 'no result (app closed before a check)';
        default:        return 'no check recorded';
    }
}

/** What kind of failure, in a few words. */
function pwaFailureKind(array $r) {
    if (pwaIsFullStall($r)) return 'full stall';
    if (pwaNoData($r)) return 'failed (error)';
    if ((int) ($r['timeouts'] ?? 0) > 0) return 'slow, recovered';
    return 'cached start, loaded';
}

/**
 * @param array  $rows  client_perf rows of display_mode = 'standalone', each with 'rome_day' (Y-m-d)
 * @param array  $days  every Rome day of the period (Y-m-d), so a day without loads still prints
 * @return array [ byDay => [day => [loads, failures, full_stalls, reachable_stalls]], failures => rows,
 *                 reachable_stalls => int, recommend_cdn_test => bool ]
 */
function pwaReview(array $rows, array $days) {
    $byDay = [];
    foreach ($days as $d) $byDay[$d] = ['loads' => 0, 'failures' => 0, 'full_stalls' => 0, 'reachable_stalls' => 0];
    $failures = [];
    $reachable = 0;
    foreach ($rows as $r) {
        $d = $r['rome_day'];
        if (!isset($byDay[$d])) $byDay[$d] = ['loads' => 0, 'failures' => 0, 'full_stalls' => 0, 'reachable_stalls' => 0];
        $byDay[$d]['loads']++;
        if (!pwaIsFailure($r)) continue;
        $byDay[$d]['failures']++;
        $failures[] = $r;
        if (pwaIsFullStall($r)) {
            $byDay[$d]['full_stalls']++;
            if (($r['probe_status'] ?? null) === 'ok') { $byDay[$d]['reachable_stalls']++; $reachable++; }
        }
    }
    ksort($byDay);
    return [
        'byDay' => $byDay,
        'failures' => $failures,
        'reachable_stalls' => $reachable,
        'recommend_cdn_test' => $reachable >= PWA_CDN_TEST_THRESHOLD,
    ];
}

/** The closing sentence of the section. */
function pwaRecommendation(array $review) {
    $n = $review['reachable_stalls'];
    if ($review['recommend_cdn_test']) {
        return "RECOMMENDED NEXT TEST: $n full stalls this week while the server check got through - the server was up,"
            . " the app's path to it was stuck. Switch the Hostinger CDN off for withlocals.deetech.cc"
            . " (hPanel -> withlocals.deetech.cc -> CDN) for a few days and compare the home-screen app's loads.";
    }
    return "$n full stall(s) this week with the server reachable (the CDN test starts at " . PWA_CDN_TEST_THRESHOLD
        . "): no CDN test needed.";
}
