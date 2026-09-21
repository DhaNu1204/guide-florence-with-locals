<?php
/**
 * viator_legacy_export.php - step 6.9 item 5: the owner's paper fallback.
 *
 *   GET viator_legacy_export.php   -> text/csv, one line per departure still to honour
 *
 * Every departure that still has to be run on the Viator account he is retiring, with Viator's
 * OWN reference number on each line - which is what matters if Viator's portal stops showing
 * him the old bookings. Generated live, so it is never a stale copy of a file on the server.
 *
 * Admin only and read-only: it writes nothing and it exposes nothing that the Tours page does
 * not already show the same person.
 */

require_once 'config.php';
require_once 'Middleware.php';
require_once __DIR__ . '/viator_helpers.php';

Middleware::requireAuth($conn);
Middleware::requireRole($conn, 'admin');
autoRateLimit('viator_legacy_export');

ensureViatorAccountColumn($conn);

$today  = (new DateTime('now', new DateTimeZone('Europe/Rome')))->format('Y-m-d');
$stamp  = (new DateTime('now', new DateTimeZone('Europe/Rome')))->format('Ymd');
$legacy = VIATOR_ACCOUNT_LEGACY;

$unit = "IF(t.group_id IS NOT NULL, CONCAT('g',t.group_id), CONCAT('t',t.id))";
$sql = "SELECT $unit AS u, t.date, LEFT(t.time,5) tm, t.title,
               SUM(t.participants) pax, COUNT(*) bk,
               MAX(g.name) guide, MAX(g.phone) phone,
               GROUP_CONCAT(t.external_id ORDER BY t.external_id SEPARATOR ' | ') refs,
               GROUP_CONCAT(COALESCE(t.customer_name,'') ORDER BY t.external_id SEPARATOR ' | ') names,
               GROUP_CONCAT(t.bokun_data ORDER BY t.external_id SEPARATOR '\\n') blobs
          FROM tours t
          LEFT JOIN guides g ON g.id = t.guide_id
         WHERE t.viator_account = ? AND t.cancelled = 0 AND t.date >= ?
      GROUP BY u, t.date, tm, t.title
      ORDER BY t.date, tm";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ss', $legacy, $today);
$stmt->execute();
$res = $stmt->get_result();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="viator-old-account-departures-' . $stamp . '.csv"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM: Excel on Windows otherwise mangles accented names
fputcsv($out, ['date', 'time', 'departure', 'product', 'pax', 'bookings',
               'guide', 'guide_phone', 'booking_refs', 'viator_refs', 'lead_names']);
$rows = 0;
while ($row = $res->fetch_assoc()) {
    $viator = [];
    foreach (explode("\n", (string) $row['blobs']) as $blob) {
        $d = json_decode($blob, true);
        if (is_array($d) && !empty($d['externalBookingReference'])) { $viator[] = $d['externalBookingReference']; }
    }
    fputcsv($out, [$row['date'], $row['tm'], $row['u'], $row['title'], $row['pax'], $row['bk'],
                   $row['guide'] ?: '', $row['phone'] ?: '', $row['refs'],
                   implode(' | ', $viator), $row['names']]);
    $rows++;
}
// A line he can read rather than an empty file, if the day ever comes that none are left.
if ($rows === 0) {
    fputcsv($out, ['', '', '', 'No departures left to honour on the old Viator account.', '', '', '', '', '', '', '']);
}
fclose($out);
$stmt->close();
