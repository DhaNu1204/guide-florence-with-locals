<?php
/**
 * bokun_cron.php — CLI-only entry point for automated Bokun booking sync.
 *
 * Purpose (IMPROVEMENT_TASKS Task 2): keep bookings in sync even when nobody
 * has the web app open. Hostinger cron runs this every 15 minutes:
 *
 *     0,15,30,45 * * * * /opt/alt/php82/usr/bin/php /home/u803853690/domains/deetech.cc/public_html/withlocals/api/bokun_cron.php
 *
 * hPanel ignores a `>> file` redirect (it keeps only the last run's output in ~/.logs/cronjob_<id>),
 * so since step 4.11d the script appends its own line to <FWL_LOG_DIR>/bokun_cron[-<env>].log
 * (~/logs, outside the web root).
 *
 * (Schedule above = every 15 minutes. Written as 0,15,30,45 rather than the
 *  usual star-slash-15 form, because that form contains the block-comment
 *  terminator and would close this docblock early and break parsing.)
 *
 * Security: this script refuses to run over HTTP. It only executes under the
 * PHP CLI SAPI, so it is never reachable from the public web. The HTTP API in
 * bokun_sync.php remains fully authenticated; including it here under CLI skips
 * that file's auth + routing (guarded by php_sapi_name() !== 'cli').
 */

// Hard refuse any non-CLI (web) access.
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Forbidden: bokun_cron.php is CLI-only']);
    exit(1);
}

// Pull in the sync engine. Under CLI this defines syncBookings() and helpers
// without triggering the web auth/routing block at the bottom of the file.
require_once __DIR__ . '/bokun_sync.php';

if (!function_exists('syncBookings')) {
    fwrite(STDERR, '[' . date('c') . "] bokun_cron: syncBookings() not available — aborting\n");
    exit(1);
}

// Run the standard incremental sync window (syncBookings defaults to
// past PAST_DAYS_BUFFER days through next DEFAULT_SYNC_DAYS days).
$result = syncBookings(null, null, 'auto', 'cron');

$ok = is_array($result) && !isset($result['error']);
$line = '[' . date('c') . '] bokun_cron: ' . json_encode($result);
fwrite($ok ? STDOUT : STDERR, $line . "\n");
// step 4.11d: the run history outside the web root (see the docblock)
if (!empty($GLOBALS['fwlLogDir'])) {
    @file_put_contents($GLOBALS['fwlLogDir'] . '/bokun_cron' . ($GLOBALS['environment'] === 'production' ? '' : '-' . $GLOBALS['environment']) . '.log',
        $line . "\n", FILE_APPEND | LOCK_EX);
}

exit($ok ? 0 : 1);
