<?php
// Step 6.9 item 1 - apply and report the old-Viator-account label. CLI only, never deployed.
//   FWL_API_DIR=<api dir> php tools/viator_label.php
// The only write is the one-time column creation + stamp inside ensureViatorAccountColumn().
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }
$apiDir = getenv('FWL_API_DIR') ?: __DIR__ . '/../public_html/api';
require_once $apiDir . '/config.php';
require_once $apiDir . '/viator_helpers.php';

$before = $conn->query("SHOW COLUMNS FROM tours LIKE 'viator_account'");
$existed = $before && $before->num_rows > 0;
echo "column existed before this run : " . ($existed ? 'yes' : 'no') . "\n";

ensureViatorAccountColumn($conn);
ensureViatorSwitchTable($conn);

$today = (new DateTime('now', new DateTimeZone('Europe/Rome')))->format('Y-m-d');
$one = function ($sql) use ($conn) { $r = $conn->query($sql); $x = $r ? $r->fetch_row() : null; return $x ? $x[0] : '?'; };

echo "\n-- labels now on tours --\n";
$r = $conn->query("SELECT COALESCE(viator_account,'(null)') a, COUNT(*) n FROM tours GROUP BY a ORDER BY n DESC");
while ($x = $r->fetch_assoc()) { printf("   %-10s %6d\n", $x['a'], $x['n']); }

echo "\n-- the check that matters --\n";
printf("   Viator bookings in tours              : %s\n", $one("SELECT COUNT(*) FROM tours WHERE booking_channel LIKE '%Viator%' OR booking_channel LIKE '%Tripadvisor%'"));
printf("   of those, labelled 'legacy'           : %s\n", $one("SELECT COUNT(*) FROM tours WHERE (booking_channel LIKE '%Viator%' OR booking_channel LIKE '%Tripadvisor%') AND viator_account = 'legacy'"));
printf("   Viator bookings with NO label (must be 0): %s\n", $one("SELECT COUNT(*) FROM tours WHERE (booking_channel LIKE '%Viator%' OR booking_channel LIKE '%Tripadvisor%') AND viator_account IS NULL"));
printf("   non-Viator rows carrying a label (must be 0): %s\n", $one("SELECT COUNT(*) FROM tours WHERE viator_account IS NOT NULL AND booking_channel NOT LIKE '%Viator%' AND booking_channel NOT LIKE '%Tripadvisor%'"));

echo "\n-- what he still has to honour --\n";
printf("   live future legacy bookings           : %s\n", $one("SELECT COUNT(*) FROM tours WHERE viator_account='legacy' AND cancelled=0 AND date >= '$today'"));
printf("   live future legacy PAX                : %s\n", $one("SELECT COALESCE(SUM(participants),0) FROM tours WHERE viator_account='legacy' AND cancelled=0 AND date >= '$today'"));
printf("   latest legacy departure               : %s\n", $one("SELECT MAX(date) FROM tours WHERE viator_account='legacy' AND cancelled=0"));

$cut = viatorCutoverAt($conn);
echo "\ncutover recorded: " . ($cut ?: 'none yet - every new Viator booking is still labelled legacy') . "\n";
