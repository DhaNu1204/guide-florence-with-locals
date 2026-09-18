<?php
/**
 * guide_digest_cron.php - step 3.10: CLI-only entry point for the evening guide digest.
 *
 * Hostinger cron runs this once a day at 21:30 Europe/Rome; it sends one WhatsApp per
 * guide listing that guide's departures for TOMORROW. Nothing reaches a real guide while
 * DIGEST_LIVE is false (the default) - see guide_digest.php.
 *
 *   0,15,30,45 style entry is NOT wanted here: exactly one run per evening.
 *
 * Options:
 *   --date=YYYY-MM-DD   digest for that date instead of tomorrow (re-runs are safe:
 *                       a guide already marked 'sent' for that date is never re-sent)
 *   --to=+39...         send to this number only (the approval test); implies one guide
 *   --guide=<id>        restrict to one guide (used together with --to)
 *   --dry               force a dry run for this invocation (no Twilio call at all)
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Forbidden: guide_digest_cron.php is CLI-only']);
    exit(1);
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/guide_digest.php';

$opts = [];
$date = null;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--date=(\d{4}-\d{2}-\d{2})$/', $arg, $m)) {
        $date = $m[1];
    } elseif (preg_match('/^--to=(.+)$/', $arg, $m)) {
        $opts['force_to'] = $m[1];
    } elseif (preg_match('/^--guide=(\d+)$/', $arg, $m)) {
        $opts['only_guide_id'] = (int) $m[1];
    } elseif ($arg === '--dry') {
        putenv('TWILIO_DRY_RUN=true');
        $_ENV['TWILIO_DRY_RUN'] = 'true';
    }
}

if ($date === null) {
    $date = (new DateTimeImmutable('now', new DateTimeZone('Europe/Rome')))
        ->add(new DateInterval('P1D'))->format('Y-m-d');
}

$started = microtime(true);
$stats = sendGuideDigests($conn, $date, $opts);
$stats['date'] = $date;
$stats['seconds'] = round(microtime(true) - $started, 2);

$line = '[' . date('c') . '] guide_digest_cron: ' . json_encode($stats);
fwrite(STDOUT, $line . "\n");
error_log('guide digest: ' . json_encode($stats));

exit(isset($stats['skipped']) ? 1 : 0);
