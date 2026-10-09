<?php
/**
 * bokun_cron_near.php — step 4.11 safety net: CLI-only sync of TODAY + TOMORROW (Europe/Rome),
 * run by hPanel cron every 5 minutes, offset from the 15-minute bokun_cron.php:
 *
 *     2,7,12,17,22,27,32,37,42,47,52,57 * * * * /opt/alt/php82/usr/bin/php /home/u803853690/domains/deetech.cc/public_html/withlocals/api/bokun_cron_near.php >> /home/u803853690/logs/bokun_cron_near.log 2>&1
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

exit($ok ? 0 : 1);
