<?php
// Step 6.9 Part A item 4 - export every Viator booking before the account switch.
// Reads the database; the only writes are the two files it creates in ~/backups (mode 600).
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }
require_once getenv('FWL_API_DIR') . '/config.php';

$dir = getenv('FWL_BACKUP_DIR') ?: '/home/u803853690/backups';
$stamp = (new DateTime('now', new DateTimeZone('Europe/Rome')))->format('Ymd_His');
$env = getenv('FWL_LABEL') ?: 'production';
$jsonl = "$dir/viator_bookings_{$env}_{$stamp}.jsonl";
$csv   = "$dir/viator_future_departures_{$env}_{$stamp}.csv";
$today = (new DateTime('now', new DateTimeZone('Europe/Rome')))->format('Y-m-d');

// --- every Viator row, every column, complete bokun_data -------------------------------
$fh = fopen($jsonl, 'w');
if (!$fh) { fwrite(STDERR, "cannot write $jsonl\n"); exit(1); }
chmod($jsonl, 0600);
fwrite($fh, json_encode([
    '_meta' => 'Florence with Locals - full export of every Viator booking taken before the Viator account switch',
    '_taken_at' => date('c'), '_environment' => $env, '_database' => $GLOBALS['db_name'] ?? null,
    '_filter' => "booking_channel LIKE '%Viator%'", '_note' => 'one JSON object per tours row per line after this header',
]) . "\n");
$r = $conn->query("SELECT * FROM tours WHERE booking_channel LIKE '%Viator%' ORDER BY date, time, id");
$rows = 0; $future = 0;
while ($row = $r->fetch_assoc()) {
    // bokun_data is stored as a JSON string; inline it as real JSON so the file is self-describing
    if (!empty($row['bokun_data'])) {
        $d = json_decode($row['bokun_data'], true);
        if (is_array($d)) { $row['bokun_data'] = $d; }
    }
    fwrite($fh, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    $rows++;
    if ($row['cancelled'] == 0 && $row['date'] >= $today) { $future++; }
}
fclose($fh);

// --- the paper fallback: future departures, one line each -------------------------------
$unit = "IF(t.group_id IS NOT NULL, CONCAT('g',t.group_id), CONCAT('t',t.id))";
$fh2 = fopen($csv, 'w');
chmod($csv, 0600);
fputcsv($fh2, ['date', 'time', 'departure', 'product', 'pax', 'bookings', 'guide', 'guide_phone', 'booking_refs', 'viator_refs', 'lead_names']);
$sql = "SELECT $unit AS u, t.date, LEFT(t.time,5) tm, t.title, SUM(t.participants) pax, COUNT(*) bk,
               MAX(g.name) guide, MAX(g.phone) phone,
               GROUP_CONCAT(t.external_id ORDER BY t.external_id SEPARATOR ' | ') refs,
               GROUP_CONCAT(t.customer_name ORDER BY t.external_id SEPARATOR ' | ') names,
               GROUP_CONCAT(t.bokun_data ORDER BY t.external_id SEPARATOR '\n') blobs
        FROM tours t LEFT JOIN guides g ON g.id = t.guide_id
        WHERE t.booking_channel LIKE '%Viator%' AND t.cancelled = 0 AND t.date >= '$today'
        GROUP BY u, t.date, tm, t.title ORDER BY t.date, tm";
$r2 = $conn->query($sql);
$lines = 0;
while ($row = $r2->fetch_assoc()) {
    $viator = [];
    foreach (explode("\n", (string) $row['blobs']) as $blob) {
        $d = json_decode($blob, true);
        if (is_array($d) && !empty($d['externalBookingReference'])) { $viator[] = $d['externalBookingReference']; }
    }
    fputcsv($fh2, [$row['date'], $row['tm'], $row['u'], $row['title'], $row['pax'], $row['bk'],
        $row['guide'] ?: '', $row['phone'] ?: '', $row['refs'], implode(' | ', $viator), $row['names']]);
    $lines++;
}
fclose($fh2);

printf("%s\n  rows=%d (future live=%d)  size=%s  mode=%s\n", $jsonl, $rows, $future,
    number_format(filesize($jsonl)) . ' bytes', substr(sprintf('%o', fileperms($jsonl)), -4));
printf("%s\n  departures=%d  size=%s  mode=%s\n", $csv, $lines,
    number_format(filesize($csv)) . ' bytes', substr(sprintf('%o', fileperms($csv)), -4));
