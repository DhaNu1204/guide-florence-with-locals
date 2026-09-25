<?php
/**
 * participants.php - step 6.8: the printable participant list for ONE departure.
 *
 *   GET participants.php?unit=g1508270            -> application/pdf
 *   GET participants.php?unit=g1508270&format=json -> the same data, for tests and debugging
 *
 * Any logged-in user may print one: the owner takes it to the museum door and so does the
 * guide, and this app's two roles are admin and viewer. Nothing is written, ever.
 *
 * PDF engine: FPDF 1.86 (vendored in lib/fpdf, public domain, zero dependencies). Chosen
 * after checking it on the host: it runs on this Hostinger PHP 8.2.33, needs no composer and
 * no C extension, and every file it needs is a .php file so the existing deploy allowlist
 * ships it unchanged. It also embeds no font, so a sheet is ~2 KB - it downloads instantly on
 * a phone in the street, which is exactly where this gets used. The cost of that choice is
 * CP1252 text; see participantsText() for what happens to a name outside it.
 */

require_once 'config.php';
require_once 'Middleware.php';
require_once __DIR__ . '/tour_classification.php';   // computePaxBreakdown()
require_once __DIR__ . '/radio_helpers.php';         // radioMuseumForTitle() - same mapping as 6.3
require_once __DIR__ . '/participant_helpers.php';
require_once __DIR__ . '/rate_helpers.php';          // step 6.14: rateIsVasari()

Middleware::requireAuth($conn);
autoRateLimit('participants');

// ---------------------------------------------------------------------------------------
// Step 6.11: the DAY sheet for one museum's ticket bookings (Priority Tickets tabs).
//   GET participants.php?museum=Accademia&date=2026-09-23[&format=json]
// Same access as the rest of this file and as Priority Tickets itself: any logged-in user.
// ---------------------------------------------------------------------------------------
if (isset($_GET['museum'])) {
    $museum = participantsDayMuseum($_GET['museum']);
    $date = (string) ($_GET['date'] ?? '');
    $dt = date_create_from_format('!Y-m-d', $date);
    if ($museum === null || !$dt || $dt->format('Y-m-d') !== $date) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'A museum (Uffizi or Accademia) and a date (YYYY-MM-DD) are required']);
        exit();
    }
    // Ticket products only (the products table decides tour vs ticket, as on Priority Tickets);
    // the museum is then read from each booking's own title in participantsDayBuild().
    $stmt = $conn->prepare("SELECT t.* FROM tours t
                              JOIN products pr ON pr.bokun_product_id = t.product_id
                             WHERE pr.product_type = 'ticket' AND t.date = ?
                             ORDER BY t.time, t.id");
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $res = $stmt->get_result();
    $dayRows = [];
    while ($r = $res->fetch_assoc()) { $dayRows[] = $r; }
    $stmt->close();

    $data = participantsDayBuild($dayRows, $museum, $date, 'computePaxBreakdown');

    if (($_GET['format'] ?? '') === 'json') {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'data' => $data]);
        exit();
    }
    require_once __DIR__ . '/participant_sheet.php';
    $out = participantsRenderDayPdf($data);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $data['filename'] . '"');
    header('Content-Length: ' . strlen($out));
    header('Cache-Control: no-store');
    echo $out;
    exit();
}

$unit =participantsParseUnit($_GET['unit'] ?? '');
if ($unit === null) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'A departure is required, e.g. unit=g123 or unit=t456']);
    exit();
}

// ---------------------------------------------------------------------------------------
// The departure and its live bookings. Cancelled bookings are excluded here, which is why
// the sheet's total can be lower than the number of bookings Bokun holds for the slot.
// ---------------------------------------------------------------------------------------
if ($unit['type'] === 'g') {
    $sql = "SELECT t.*, g.name AS guide_name, g.phone AS guide_phone,
                   tg.display_name AS group_display_name, tg.group_time, tg.notes AS group_notes
              FROM tours t
              LEFT JOIN guides g ON g.id = COALESCE((SELECT tg2.guide_id FROM tour_groups tg2 WHERE tg2.id = t.group_id), t.guide_id)
              LEFT JOIN tour_groups tg ON tg.id = t.group_id
             WHERE t.group_id = ?
             ORDER BY t.id";
} else {
    $sql = "SELECT t.*, g.name AS guide_name, g.phone AS guide_phone,
                   NULL AS group_display_name, NULL AS group_time, NULL AS group_notes
              FROM tours t
              LEFT JOIN guides g ON g.id = t.guide_id
             WHERE t.id = ?";
}
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $unit['id']);
$stmt->execute();
$res = $stmt->get_result();
$all = [];
while ($r = $res->fetch_assoc()) { $all[] = $r; }
$stmt->close();

if (count($all) === 0) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Departure not found']);
    exit();
}

$first = $all[0];
$departureDate = substr((string) $first['date'], 0, 10);
$departureTime = substr((string) ($first['group_time'] ?: $first['time']), 0, 5);
$product = trim((string) ($first['group_display_name'] ?: $first['title']));
// radioMuseumForTitle() (step 6.3) returns ['museum','confident','why'] - take the heading.
// Its catch-all section 'Altro' means "this title names no museum": there is no museum door to
// stand at, so the sheet falls back to the product name rather than printing an Italian word
// that means nothing on an English participant list.
$museumInfo = function_exists('radioMuseumForTitle') ? radioMuseumForTitle($first['title']) : null;
$museum = (is_array($museumInfo) && isset($museumInfo['museum']) && $museumInfo['museum'] !== RADIO_OTHER_SECTION)
    ? (string) $museumInfo['museum'] : '';
$guideName = $first['guide_name'] ?: '';
$guidePhone = $first['guide_phone'] ?: '';

$rows = [];
$totalPax = 0; $totalAd = 0; $totalCh = 0; $totalInf = 0;
$cancelledCount = 0;
$vasariPax = 0;   // step 6.14: live bookings only, like every other total on the sheet
$languages = [];
foreach ($all as $r) {
    if ((int) $r['cancelled'] === 1) { $cancelledCount++; continue; }
    $bokun = null;
    if (!empty($r['bokun_data'])) { $bokun = json_decode($r['bokun_data'], true); }
    $pax = computePaxBreakdown($r['bokun_data'] ?? null, (int) $r['participants']);
    $n = $pax['adults'] + $pax['children'] + $pax['infants'];
    $totalPax += $n; $totalAd += $pax['adults']; $totalCh += $pax['children']; $totalInf += $pax['infants'];
    if (!empty($r['language'])) { $languages[$r['language']] = true; }
    $vasari = rateIsVasari($r['rate_title'] ?? null);
    if ($vasari) { $vasariPax += $n; }
    $rows[] = [
        'reference' => participantsReference($r, $bokun),
        'names'     => participantsNames($r),
        'pax'       => $n,
        'adults'    => $pax['adults'],
        'children'  => $pax['children'],
        'infants'   => $pax['infants'],
        'channel'   => (string) ($r['booking_channel'] ?: 'Direct'),
        'agency'    => participantsAgency($bokun),
        'manual'    => (isset($r['source']) && $r['source'] === 'manual'),
        'vasari'    => $vasari,
    ];
}
$language = implode(', ', array_keys($languages));

$data = [
    'unit' => ($unit['type'] . $unit['id']),
    'date' => $departureDate, 'time' => $departureTime,
    'product' => $product, 'museum' => $museum, 'language' => $language,
    'guide_name' => $guideName, 'guide_phone' => $guidePhone,
    // Step 6.13: the group's own note (internal; printed only here). '' = no Note block.
    'group_note' => trim(str_replace(["\r\n", "\r"], "\n", (string) ($first['group_notes'] ?? ''))),
    'title_line' => participantsTitleLine($product, $totalPax, $departureTime, $language, $departureDate),
    'bookings' => $rows,
    'total_pax' => $totalPax, 'total_adults' => $totalAd,
    'total_children' => $totalCh, 'total_infants' => $totalInf,
    'vasari_pax' => $vasariPax,   // step 6.14: 0 = no Vasari line on the sheet
    'cancelled_bookings' => $cancelledCount,
    'filename' => participantsFilename($museum ?: $product, $departureDate, $departureTime),
];

if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $data]);
    exit();
}

require_once __DIR__ . '/participant_sheet.php';

$out = participantsRenderPdf($data);
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $data['filename'] . '"');
header('Content-Length: ' . strlen($out));
header('Cache-Control: no-store');
echo $out;
