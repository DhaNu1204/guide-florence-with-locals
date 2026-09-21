<?php
// Step 6.9 Part A - READ ONLY. Never deployed (tools/ pattern). Runs no write of any kind.
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }
$apiDir = getenv('FWL_API_DIR');
require_once $apiDir . '/config.php';

$today = (new DateTime('now', new DateTimeZone('Europe/Rome')))->format('Y-m-d');
echo "today (Rome) = $today\n\n";

// --- which channel strings exist at all -------------------------------------------------
echo "=== booking_channel values (all time) ===\n";
$r = $conn->query("SELECT booking_channel, COUNT(*) n, SUM(date >= '$today') future FROM tours
                   WHERE source IS NULL OR source <> 'manual' GROUP BY booking_channel ORDER BY n DESC");
while ($row = $r->fetch_assoc()) { printf("%-42s %6d   future %4d\n", $row['booking_channel'], $row['n'], $row['future']); }

// --- item 2: the exposure ----------------------------------------------------------------
$V = "booking_channel LIKE '%Viator%'";
echo "\n=== item 2: Viator exposure ===\n";
$q = function ($sql) use ($conn) { $r = $conn->query($sql); $x = $r->fetch_row(); return $x[0]; };
echo "bookings held (all time, incl cancelled) : " . $q("SELECT COUNT(*) FROM tours WHERE $V") . "\n";
echo "  of which cancelled                     : " . $q("SELECT COUNT(*) FROM tours WHERE $V AND cancelled = 1") . "\n";
echo "future bookings (date >= today, live)    : " . $q("SELECT COUNT(*) FROM tours WHERE $V AND cancelled = 0 AND date >= '$today'") . "\n";
echo "latest departure date                    : " . $q("SELECT MAX(date) FROM tours WHERE $V AND cancelled = 0") . "\n";
echo "future PAX                               : " . $q("SELECT COALESCE(SUM(participants),0) FROM tours WHERE $V AND cancelled = 0 AND date >= '$today'") . "\n";
$unit = "IF(t.group_id IS NOT NULL, CONCAT('g',t.group_id), CONCAT('t',t.id))";
echo "distinct future departures (units)       : " . $q("SELECT COUNT(*) FROM (SELECT $unit u FROM tours t WHERE t.booking_channel LIKE '%Viator%' AND t.cancelled=0 AND t.date >= '$today' GROUP BY u) x") . "\n";
echo "  of those, with a guide assigned        : " . $q("SELECT COUNT(*) FROM (SELECT $unit u, MAX(t.guide_id IS NOT NULL) g FROM tours t WHERE t.booking_channel LIKE '%Viator%' AND t.cancelled=0 AND t.date >= '$today' GROUP BY u HAVING g=1) x") . "\n";
echo "  departures also holding non-Viator bookings: " . $q("SELECT COUNT(*) FROM (SELECT $unit u FROM tours t WHERE t.cancelled=0 AND t.date >= '$today' GROUP BY u HAVING SUM(t.booking_channel LIKE '%Viator%')>0 AND SUM(t.booking_channel NOT LIKE '%Viator%')>0) x") . "\n";

// --- item 3: what identifies the channel in bokun_data -----------------------------------
echo "\n=== item 3: identifiers in bokun_data (future live Viator rows) ===\n";
$r = $conn->query("SELECT id, external_id, bokun_data FROM tours WHERE $V AND cancelled=0 AND date >= '$today' ORDER BY date");
$seen = ['channel' => [], 'seller' => [], 'extref' => [], 'product' => [], 'agent' => []];
$sample = null;
while ($row = $r->fetch_assoc()) {
    $b = json_decode($row['bokun_data'], true);
    if (!is_array($b)) { continue; }
    if ($sample === null) { $sample = $b; }
    $ch = ($b['channel']['id'] ?? '-') . ' | ' . ($b['channel']['title'] ?? '-');
    $se = ($b['seller']['id'] ?? '-') . ' | ' . ($b['seller']['title'] ?? '-');
    $pb = $b['productBookings'][0] ?? [];
    $ex = $pb['externalBookingReference'] ?? ($b['externalBookingReference'] ?? '');
    $pc = $pb['productExternalId'] ?? ($pb['product']['externalId'] ?? ($pb['product']['id'] ?? '-'));
    $ag = ($b['agent']['id'] ?? '-') . ' | ' . ($b['agent']['title'] ?? '-');
    $seen['channel'][$ch] = ($seen['channel'][$ch] ?? 0) + 1;
    $seen['seller'][$se]  = ($seen['seller'][$se] ?? 0) + 1;
    $seen['agent'][$ag]   = ($seen['agent'][$ag] ?? 0) + 1;
    $shape = preg_replace('/\d/', '9', (string) $ex);
    $seen['extref'][$shape . '   e.g. ' . substr((string)$ex, 0, 20)] = ($seen['extref'][$shape . '   e.g. ' . substr((string)$ex, 0, 20)] ?? 0) + 1;
    $seen['product'][(string) $pc] = ($seen['product'][(string) $pc] ?? 0) + 1;
}
foreach ($seen as $k => $vals) {
    echo "-- $k\n";
    arsort($vals);
    $i = 0;
    foreach ($vals as $v => $n) { printf("   %-58s %4d\n", substr($v,0,58), $n); if (++$i >= 8) { echo "   ...\n"; break; } }
}
if ($sample) { echo "\ntop-level keys of one Viator booking: " . implode(', ', array_keys($sample)) . "\n"; }

// --- item 2 list --------------------------------------------------------------------------
echo "\n=== item 2 list: future Viator departures ===\n";
$sql = "SELECT $unit AS u, t.date, LEFT(t.time,5) tm, t.title, SUM(t.participants) pax,
               GROUP_CONCAT(t.external_id ORDER BY t.external_id SEPARATOR ' ') refs,
               MAX(g.name) guide, COUNT(*) bookings
        FROM tours t LEFT JOIN guides g ON g.id = t.guide_id
        WHERE t.booking_channel LIKE '%Viator%' AND t.cancelled = 0 AND t.date >= '$today'
        GROUP BY u, t.date, tm, t.title ORDER BY t.date, tm";
$r = $conn->query($sql);
$n = 0;
while ($row = $r->fetch_assoc()) {
    $n++;
    printf("%-10s %s %s  %-46s %2d pax  %-18s %s\n", $row['u'], $row['date'], $row['tm'],
        substr($row['title'], 0, 46), $row['pax'], $row['guide'] ?: '(no guide)', $row['refs']);
}
echo "rows: $n\n";
