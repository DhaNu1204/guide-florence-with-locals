<?php
/**
 * bokun_cron_near.php — step 4.11 safety net: CLI-only sync of TODAY + TOMORROW (Europe/Rome),
 * run by hPanel cron every 5 minutes (job set up by the owner 2026-10-09 as star-slash-5,
 * written out here because that form would close this docblock):
 *
 *     0,5,10,15,20,25,30,35,40,45,50,55 * * * * /opt/alt/php82/usr/bin/php /home/u803853690/domains/deetech.cc/public_html/withlocals/api/bokun_cron_near.php
 *
 * hPanel ignores a `>> file` redirect: it keeps only the LAST run's output in ~/.logs/cronjob_<id>.
 * So (step 4.11d) the script appends its own line to <FWL_LOG_DIR>/bokun_cron_near[-<env>].log (~/logs).
 *
 * Why: Bokun calls the webhook before its booking-search returns a new GYG / website booking
 * (triage 2026-10-09), and the 15-minute cron then left a last-minute booking off the Tours page
 * for up to 15 minutes. A 2-day window is 2 Bokun requests and ~1-2 s.
 *
 * Overlap: syncBookings() takes the shared sync lock (step 4.11), so this never runs at the same
 * time as the 15-minute cron, the webhook or a manual Sync Now; it waits up to 30 s and is
 * logged as 'busy' in sync_logs if it still cannot run.
 *
 * Each run prints one line: duration, Bokun request count, bookings found / created / updated.
 */

// Hard refuse any non-CLI (web) access.
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Forbidden: bokun_cron_near.php is CLI-only']);
    exit(1);
}

require_once __DIR__ . '/bokun_sync.php';

$today = date('Y-m-d');                          // config.php sets Europe/Rome
$tomorrow = date('Y-m-d', strtotime('+1 day'));

$t0 = microtime(true);
$result = syncBookings($today, $tomorrow, 'near', 'cron-near');
$wall = round(microtime(true) - $t0, 2);

$ok = is_array($result) && !isset($result['error']);
$line = sprintf(
    '[%s] bokun_cron_near %s..%s: %s wall=%ss sync=%ss bokun_requests=%s found=%s created=%s updated=%s',
    date('c'), $today, $tomorrow,
    $ok ? 'ok' : ('error=' . (is_array($result) ? ($result['error'] ?? '?') : '?')),
    $wall,
    $result['duration_seconds'] ?? '-',
    $result['bokun_requests'] ?? '-',
    $result['total_bookings'] ?? '-',
    $result['created_count'] ?? '-',
    $result['updated_count'] ?? '-'
);
fwrite($ok ? STDOUT : STDERR, $line . "\n");
// step 4.11d: the run history (see the docblock - hPanel keeps only the last run)
if (!empty($GLOBALS['fwlLogDir'])) {
    @file_put_contents($GLOBALS['fwlLogDir'] . '/bokun_cron_near' . ($GLOBALS['environment'] === 'production' ? '' : '-' . $GLOBALS['environment']) . '.log',
        $line . "\n", FILE_APPEND | LOCK_EX);
}

exit($ok ? 0 : 1);
