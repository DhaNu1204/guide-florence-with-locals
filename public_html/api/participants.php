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

Middleware::requireAuth($conn);
autoRateLimit('participants');

$unit = participantsParseUnit($_GET['unit'] ?? '');
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
                   tg.display_name AS group_display_name, tg.group_time
              FROM tours t
              LEFT JOIN guides g ON g.id = COALESCE((SELECT tg2.guide_id FROM tour_groups tg2 WHERE tg2.id = t.group_id), t.guide_id)
              LEFT JOIN tour_groups tg ON tg.id = t.group_id
             WHERE t.group_id = ?
             ORDER BY t.id";
} else {
    $sql = "SELECT t.*, g.name AS guide_name, g.phone AS guide_phone,
                   NULL AS group_display_name, NULL AS group_time
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
$languages = [];
foreach ($all as $r) {
    if ((int) $r['cancelled'] === 1) { $cancelledCount++; continue; }
    $bokun = null;
    if (!empty($r['bokun_data'])) { $bokun = json_decode($r['bokun_data'], true); }
    $pax = computePaxBreakdown($r['bokun_data'] ?? null, (int) $r['participants']);
    $n = $pax['adults'] + $pax['children'] + $pax['infants'];
    $totalPax += $n; $totalAd += $pax['adults']; $totalCh += $pax['children']; $totalInf += $pax['infants'];
    if (!empty($r['language'])) { $languages[$r['language']] = true; }
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
    ];
}
$language = implode(', ', array_keys($languages));

$data = [
    'unit' => ($unit['type'] . $unit['id']),
    'date' => $departureDate, 'time' => $departureTime,
    'product' => $product, 'museum' => $museum, 'language' => $language,
    'guide_name' => $guideName, 'guide_phone' => $guidePhone,
    'title_line' => participantsTitleLine($product, $totalPax, $departureTime, $language, $departureDate),
    'bookings' => $rows,
    'total_pax' => $totalPax, 'total_adults' => $totalAd,
    'total_children' => $totalCh, 'total_infants' => $totalInf,
    'cancelled_bookings' => $cancelledCount,
    'filename' => participantsFilename($museum ?: $product, $departureDate, $departureTime),
];

if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $data]);
    exit();
}

// ---------------------------------------------------------------------------------------
// The sheet. His own layout (reference B): company heading, museum + "Participants List",
// a one-line summary from reference A, the guide block, the bordered table, the total row,
// and the blank "Tickets used" box he writes on by hand.
// ---------------------------------------------------------------------------------------
define('FPDF_FONTPATH', __DIR__ . '/lib/fpdf/font/');
require_once __DIR__ . '/lib/fpdf/fpdf.php';

class ParticipantSheet extends FPDF
{
    public $sheet = [];

    // Repeated at the top of every page, so a second page is still usable on its own.
    function Header()
    {
        $d = $this->sheet;
        $this->SetFont('Helvetica', 'B', 15);
        $this->Cell(0, 8, participantsText('FLORENCE WITH LOCALS S.R.L.'), 0, 1, 'C');
        $this->SetFont('Helvetica', '', 12);
        $subtitle = ($d['museum'] !== '' ? $d['museum'] . ' - ' : '') . 'Participants List';
        $this->Cell(0, 6, participantsText($subtitle), 0, 1, 'C');
        $this->SetFont('Helvetica', '', 9);
        $this->Cell(0, 5, participantsText($d['title_line']), 0, 1, 'C');
        $this->Ln(1);
        $this->SetDrawColor(0, 0, 0);
        $this->Line(12, $this->GetY(), 198, $this->GetY());
        $this->Ln(3);

        // Guide block
        $this->SetFont('Helvetica', 'B', 9);
        $this->Cell(16, 5, 'Guide:', 0, 0);
        $this->SetFont('Helvetica', '', 9);
        if ($d['guide_name'] === '') {
            $this->Cell(0, 5, participantsText('NO GUIDE ASSIGNED'), 0, 1);
        } else {
            $line = $d['guide_name'] . ($d['guide_phone'] !== '' ? '   tel ' . $d['guide_phone'] : '   (no phone on file)');
            $this->Cell(0, 5, participantsText($line), 0, 1);
        }
        $this->SetFont('Helvetica', 'B', 9);
        $this->Cell(16, 5, 'Date:', 0, 0);
        $this->SetFont('Helvetica', '', 9);
        $dd = date_create_from_format('Y-m-d', $d['date']);
        $this->Cell(44, 5, ($dd ? $dd->format('d.m.Y') : $d['date']) . '  ' . $d['time'], 0, 0);
        $this->SetFont('Helvetica', 'B', 9);
        $this->Cell(34, 5, 'Total participants:', 0, 0);
        $this->SetFont('Helvetica', '', 9);
        $this->Cell(0, 5, (string) $d['total_pax'], 0, 1);
        $this->Ln(2);
        $this->tableHead();
    }

    // Column widths add up to 186mm inside a 12mm margin on an A4 page.
    private $w = [10, 32, 58, 12, 26, 30, 6, 6, 6];

    function tableHead()
    {
        $this->SetFont('Helvetica', 'B', 8);
        $this->SetFillColor(235, 235, 235);
        $h = ['No.', 'Booking ref.', 'Participants', 'Pax', 'Channel', 'Agency', 'AD', 'CH', 'IN'];
        foreach ($h as $i => $label) {
            $this->Cell($this->w[$i], 7, $label, 1, 0, 'C', true);
        }
        $this->Ln();
        $this->SetFont('Helvetica', '', 8);
    }

    function Footer()
    {
        $this->SetY(-16);
        $this->SetFont('Helvetica', 'I', 7);
        $this->Cell(0, 4, participantsText($this->sheet['title_line']), 0, 1, 'C');
        $this->Cell(0, 4, 'Page ' . $this->PageNo() . '/{nb}   -   one row per booking; every traveller is listed where the channel gave us the names', 0, 0, 'C');
    }

    function bookingRow($i, $b)
    {
        // A booking with several travellers needs several lines; the row grows to fit.
        $names = count($b['names']) > 0 ? implode(', ', $b['names']) : '(no name given)';
        $namesTxt = participantsText($names);
        $lines = max(1, count($this->splitLines($namesTxt, $this->w[2] - 2)));
        $h = 5 * $lines;

        if ($this->GetY() + $h > 265) { $this->AddPage(); }

        $x = $this->GetX(); $y = $this->GetY();
        $cells = [
            [(string) $i, 'C'],
            [participantsText($b['reference']), 'L'],
            [null, 'L'],                                   // names: MultiCell below
            [(string) $b['pax'], 'C'],
            [participantsText($b['channel']), 'L'],
            [participantsText($b['agency']), 'L'],
            [$b['adults'] ? (string) $b['adults'] : '', 'C'],
            [$b['children'] ? (string) $b['children'] : '', 'C'],
            [$b['infants'] ? (string) $b['infants'] : '', 'C'],
        ];
        foreach ($cells as $idx => $c) {
            if ($c[0] === null) {
                $this->MultiCell($this->w[$idx], 5, $namesTxt, 1, $c[1]);
                $this->SetXY($x + array_sum(array_slice($this->w, 0, $idx + 1)), $y);
                continue;
            }
            $this->Cell($this->w[$idx], $h, $c[0], 1, 0, $c[1]);
        }
        $this->SetXY($x, $y + $h);
    }

    private function splitLines($txt, $width)
    {
        $words = explode(' ', $txt);
        $lines = []; $cur = '';
        foreach ($words as $word) {
            $try = $cur === '' ? $word : $cur . ' ' . $word;
            if ($this->GetStringWidth($try) > $width && $cur !== '') { $lines[] = $cur; $cur = $word; }
            else { $cur = $try; }
        }
        if ($cur !== '') { $lines[] = $cur; }
        return $lines;
    }
}

$pdf = new ParticipantSheet('P', 'mm', 'A4');
$pdf->sheet = $data;
$pdf->AliasNbPages();
$pdf->SetMargins(12, 12, 12);
$pdf->SetAutoPageBreak(false);
$pdf->AddPage();

if (count($rows) === 0) {
    $pdf->SetFont('Helvetica', '', 11);
    $pdf->Ln(6);
    $pdf->Cell(0, 8, participantsText('No participants on this departure.'), 0, 1, 'C');
    $pdf->SetFont('Helvetica', '', 9);
    if ($cancelledCount > 0) {
        $pdf->Cell(0, 6, participantsText($cancelledCount . ' cancelled booking(s) are not shown.'), 0, 1, 'C');
    }
} else {
    $i = 1;
    foreach ($rows as $b) { $pdf->bookingRow($i++, $b); }

    // Total row, as in his own sheet.
    $pdf->SetFont('Helvetica', 'B', 8);
    $pdf->Cell(10 + 32 + 58, 7, participantsText('Total'), 1, 0, 'R');
    $pdf->Cell(12, 7, (string) $totalPax, 1, 0, 'C');
    $pdf->Cell(26 + 30, 7, '', 1, 0);
    $pdf->Cell(6, 7, $totalAd ? (string) $totalAd : '', 1, 0, 'C');
    $pdf->Cell(6, 7, $totalCh ? (string) $totalCh : '', 1, 0, 'C');
    $pdf->Cell(6, 7, $totalInf ? (string) $totalInf : '', 1, 1, 'C');

    $pdf->SetFont('Helvetica', '', 7);
    $pdf->Ln(1);
    $pdf->Cell(0, 4, participantsText('AD = adults, CH = children, IN = infants.'
        . ($cancelledCount > 0 ? '  ' . $cancelledCount . ' cancelled booking(s) excluded.' : '')), 0, 1);
}

// The blank box he writes the used tickets into, as in reference A.
$pdf->Ln(4);
$pdf->SetFont('Helvetica', 'B', 9);
$pdf->Cell(0, 6, participantsText('Tickets used:'), 0, 1);
$pdf->Rect(12, $pdf->GetY(), 186, 26);

$out = $pdf->Output('S');
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $data['filename'] . '"');
header('Content-Length: ' . strlen($out));
header('Cache-Control: no-store');
echo $out;
