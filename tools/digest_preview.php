<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only (step 3.10): never deployed
/**
 * Step 3.10 preview - shows exactly what each guide WOULD receive. Sends nothing, ever:
 * this file contains no Twilio call at all.
 *
 *   php tools/digest_preview.php --date=YYYY-MM-DD
 *   FWL_API_DIR=/path/to/api php tools/digest_preview.php --date=...   (running the copy on the server)
 *
 * Without --date it previews tomorrow (Europe/Rome).
 */

$apiDir = getenv('FWL_API_DIR') ?: __DIR__ . '/../public_html/api';
require_once $apiDir . '/config.php';
require_once $apiDir . '/guide_digest.php';

$date = null;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--date=(\d{4}-\d{2}-\d{2})$/', $arg, $m)) {
        $date = $m[1];
    }
}
if ($date === null) {
    $date = (new DateTimeImmutable('now', new DateTimeZone('Europe/Rome')))
        ->add(new DateInterval('P1D'))->format('Y-m-d');
}

$cfg = digestConfig();
$byGuide = collectDigestDepartures($conn, $date);

echo "=== GUIDE DIGEST PREVIEW (nothing is sent) ===\n";
echo 'date: ' . $date . '  (' . digestItalianDate($date) . ")\n";
echo 'guides with departures: ' . count($byGuide) . "\n";
echo 'DIGEST_LIVE: ' . ($cfg['live'] ? 'true' : 'false')
   . ' | TWILIO_DRY_RUN: ' . ($cfg['dry_run'] ? 'true' : 'false')
   . ' | digest template configured: ' . ($cfg['content_sid'] !== '' ? 'yes' : 'NO (TWILIO_DIGEST_CONTENT_SID missing)')
   . "\n";

if (count($byGuide) === 0) {
    echo "\n(no guide has a departure on that date - no message would be sent)\n";
    exit(0);
}

// Stable, readable order: by guide name.
uasort($byGuide, function ($a, $b) { return strcasecmp($a['guide_name'], $b['guide_name']); });

$totalDepartures = 0;
$totalBookings = 0;
foreach ($byGuide as $g) {
    $vars = buildDigestVariables($g['guide_name'], $date, $g['departures'], $cfg['line_separator']);
    $to = normalizeWhatsapp($g['guide_phone']);
    $departures = count($g['departures']);
    $bookings = array_sum(array_column($g['departures'], 'bookings'));
    $totalDepartures += $departures;
    $totalBookings += $bookings;

    echo "\n" . str_repeat('-', 66) . "\n";
    echo 'GUIDE: ' . $g['guide_name'] . '  (id ' . $g['guide_id'] . ')'
       . '  phone ' . maskPhone($g['guide_phone'])
       . ($to === null ? '  *** UNUSABLE NUMBER - would be recorded failed, not sent ***' : '')
       . "\n";
    echo 'departures: ' . $departures . '  (from ' . $bookings . " booking(s))\n";
    echo "message:\n";
    foreach (explode("\n", renderDigestBody($vars)) as $line) {
        echo '  | ' . $line . "\n";
    }
    echo 'chars: ' . strlen(renderDigestBody($vars)) . "\n";
}

echo "\n" . str_repeat('=', 66) . "\n";
echo 'TOTAL: ' . count($byGuide) . ' message(s), ' . $totalDepartures . ' departure(s), '
   . $totalBookings . " booking(s)\n";
echo "Old behaviour would have sent one message per departure: " . $totalDepartures . " message(s).\n";
