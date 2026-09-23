<?php
/**
 * participant_sheet.php - step 6.8: turns one departure's data into the printed sheet.
 *
 * Kept apart from participants.php on purpose. The endpoint needs a database and a session;
 * this file needs neither, so tools/participants_check.php can render a real PDF from fixture
 * data on every run. That check is what catches the kind of mistake that only shows up at
 * runtime - a property that collides with one of FPDF's own, a font that will not load -
 * instead of a 500 on staging.
 *
 * PDF engine: FPDF 1.86 (vendored in lib/fpdf, public domain, zero dependencies).
 */

require_once __DIR__ . '/participant_helpers.php';

if (!defined('FPDF_FONTPATH')) { define('FPDF_FONTPATH', __DIR__ . '/lib/fpdf/font/'); }
require_once __DIR__ . '/lib/fpdf/fpdf.php';

// ---------------------------------------------------------------------------------------
// The sheet. His own layout (reference B): company heading, museum + "Participants List",
// a one-line summary from reference A, the guide block, the bordered table, the total row,
// and the blank "Tickets used" box he writes on by hand.
// ---------------------------------------------------------------------------------------
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
    // NOT $w: FPDF already owns $w (the page width) and a redeclaration is a fatal.
    private $colw = [10, 32, 58, 12, 26, 30, 6, 6, 6];

    function tableHead()
    {
        $this->SetFont('Helvetica', 'B', 8);
        $this->SetFillColor(235, 235, 235);
        $h = ['No.', 'Booking ref.', 'Participants', 'Pax', 'Channel', 'Agency', 'AD', 'CH', 'IN'];
        foreach ($h as $i => $label) {
            $this->Cell($this->colw[$i], 7, $label, 1, 0, 'C', true);
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
        $lines = max(1, count($this->splitLines($namesTxt, $this->colw[2] - 2)));
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
                $this->MultiCell($this->colw[$idx], 5, $namesTxt, 1, $c[1]);
                $this->SetXY($x + array_sum(array_slice($this->colw, 0, $idx + 1)), $y);
                continue;
            }
            $this->Cell($this->colw[$idx], $h, $c[0], 1, 0, $c[1]);
        }
        $this->SetXY($x, $y + $h);
    }

    protected function splitLines($txt, $width)
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


/**
 * $data is exactly what participants.php assembles (and what ?format=json returns).
 * Returns the PDF as a string; writes nothing and prints nothing.
 */
function participantsRenderPdf(array $data)
{
    $rows           = $data['bookings'];
    $totalPax       = (int) $data['total_pax'];
    $totalAd        = (int) $data['total_adults'];
    $totalCh        = (int) $data['total_children'];
    $totalInf       = (int) $data['total_infants'];
    $cancelledCount = (int) $data['cancelled_bookings'];

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

    return $pdf->Output('S');
}


// ---------------------------------------------------------------------------------------
// Step 6.11: the DAY sheet - every ticket booking for one museum on one date, sorted by time.
// The owner's own example (made by hand from the 6.8 sheet): same heading, same box, no guide
// block (tickets have no guide), a Time column, and the customer's reference large with our
// Bokun code small underneath. Several products on one day -> one headed section each, with a
// subtotal; a single-product day looks exactly like his example.
// ---------------------------------------------------------------------------------------
class DaySheet extends ParticipantSheet
{
    // NOT $w / $colw-like FPDF names: see the note on ParticipantSheet::$colw.
    private $daycolw = [9, 13, 34, 62, 11, 30, 9, 9, 9];   // 186mm

    function Header()
    {
        $d = $this->sheet;
        $this->SetFont('Helvetica', 'B', 15);
        $this->Cell(0, 8, participantsText('FLORENCE WITH LOCALS S.R.L.'), 0, 1, 'C');
        $this->SetFont('Helvetica', '', 12);
        $this->Cell(0, 6, participantsText($d['museum'] . ' - Participants List'), 0, 1, 'C');
        $this->SetFont('Helvetica', '', 9);
        $this->Cell(0, 5, participantsText($d['title_line']), 0, 1, 'C');
        $this->Ln(1);
        $this->SetDrawColor(0, 0, 0);
        $this->Line(12, $this->GetY(), 198, $this->GetY());
        $this->Ln(3);

        $this->SetFont('Helvetica', 'B', 9);
        $this->Cell(12, 5, 'Date:', 0, 0);
        $this->SetFont('Helvetica', '', 9);
        $dd = date_create_from_format('Y-m-d', $d['date']);
        $this->Cell(40, 5, $dd ? $dd->format('d.m.Y') : $d['date'], 0, 0);
        $this->SetFont('Helvetica', 'B', 9);
        $this->Cell(34, 5, 'Total participants:', 0, 0);
        $this->SetFont('Helvetica', '', 9);
        $this->Cell(0, 5, (string) $d['total_pax'], 0, 1);
        $this->Ln(2);
        $this->tableHead();
    }

    function tableHead()
    {
        $this->SetFont('Helvetica', 'B', 8);
        $this->SetFillColor(235, 235, 235);
        $h = ['No.', 'Time', 'Booking ref.', 'Participants', 'Pax', 'Channel', 'AD', 'CH', 'IN'];
        foreach ($h as $i => $label) {
            $this->Cell($this->daycolw[$i], 7, $label, 1, 0, 'C', true);
        }
        $this->Ln();
        $this->SetFont('Helvetica', '', 8);
    }

    function Footer()
    {
        $this->SetY(-16);
        $this->SetFont('Helvetica', 'I', 7);
        $this->Cell(0, 4, participantsText($this->sheet['title_line']), 0, 1, 'C');
        $this->Cell(0, 4, 'Page ' . $this->PageNo() . '/{nb}   -   one row per booking, in time order; cancelled bookings are not listed', 0, 0, 'C');
    }

    // A long value (www.florencewithlocals.com) is printed smaller rather than over the next column.
    private function fitCell($w, $h, $txt, $size)
    {
        $this->SetFontSize($size);
        while ($size > 5.5 && $this->GetStringWidth($txt) > $w - 2) {
            $size -= 0.5;
            $this->SetFontSize($size);
        }
        $this->Cell($w, $h, $txt, 0, 0, 'L');
        $this->SetFontSize(8);
    }

    private function room($h)
    {
        if ($this->GetY() + $h > 265) { $this->AddPage(); }
    }

    function sectionHeading($s)
    {
        $this->room(13);
        $this->SetFont('Helvetica', 'B', 8);
        $this->SetFillColor(248, 244, 236);
        $this->Cell(array_sum($this->daycolw), 6,
            participantsText($s['product'] . ' - ' . $s['pax'] . ' pax'), 1, 1, 'L', true);
        $this->SetFont('Helvetica', '', 8);
    }

    // A totals row: label across No..Participants, then Pax, blank Channel, AD/CH/IN.
    function sumRow($label, $t)
    {
        $c = $this->daycolw;
        $this->room(7);
        $this->SetFont('Helvetica', 'B', 8);
        $this->Cell($c[0] + $c[1] + $c[2] + $c[3], 7, participantsText($label), 1, 0, 'R');
        $this->Cell($c[4], 7, (string) $t['pax'], 1, 0, 'C');
        $this->Cell($c[5], 7, '', 1, 0);
        $this->Cell($c[6], 7, $t['adults'] ? (string) $t['adults'] : '', 1, 0, 'C');
        $this->Cell($c[7], 7, $t['children'] ? (string) $t['children'] : '', 1, 0, 'C');
        $this->Cell($c[8], 7, $t['infants'] ? (string) $t['infants'] : '', 1, 1, 'C');
        $this->SetFont('Helvetica', '', 8);
    }

    function dayRow($i, $b)
    {
        $c = $this->daycolw;
        $names = count($b['names']) > 0 ? implode(', ', $b['names']) : '(no name given)';
        $namesTxt = participantsText($names);
        $lines = max(1, count($this->splitLines($namesTxt, $c[3] - 2)));
        $small = participantsText($b['reference_small']);
        $h = max(5 * $lines, $small !== '' ? 9 : 6);
        $this->room($h);

        $x0 = $this->GetX(); $y = $this->GetY();
        $x = $x0;
        foreach ($c as $w) { $this->Rect($x, $y, $w, $h); $x += $w; }

        $x = $x0;
        $this->SetXY($x, $y); $this->Cell($c[0], $h, (string) $i, 0, 0, 'C');          $x += $c[0];
        $this->SetXY($x, $y); $this->Cell($c[1], $h, $b['time'], 0, 0, 'C');            $x += $c[1];

        // Booking ref: what the customer holds, large; our Bokun code small underneath.
        $ref = participantsText($b['reference']);
        if ($small !== '') {
            $this->SetFont('Helvetica', 'B', 9);
            $this->SetXY($x, $y + 0.8); $this->Cell($c[2], 4.4, $ref, 0, 0, 'L');
            $this->SetFont('Helvetica', '', 6.5);
            $this->SetXY($x, $y + 5.1); $this->Cell($c[2], 3, $small, 0, 0, 'L');
        } else {
            $this->SetFont('Helvetica', 'B', 9);
            $this->SetXY($x, $y); $this->Cell($c[2], $h, $ref, 0, 0, 'L');
        }
        $this->SetFont('Helvetica', '', 8);
        $x += $c[2];

        $this->SetXY($x, $y + ($h - 5 * $lines) / 2);
        $this->MultiCell($c[3], 5, $namesTxt, 0, 'L');                                   $x += $c[3];
        $this->SetXY($x, $y); $this->Cell($c[4], $h, (string) $b['pax'], 0, 0, 'C');     $x += $c[4];
        $this->SetXY($x, $y); $this->fitCell($c[5], $h, participantsText($b['channel']), 8); $x += $c[5];
        foreach (['adults', 'children', 'infants'] as $k => $key) {
            $this->SetXY($x, $y);
            $this->Cell($c[6 + $k], $h, $b[$key] ? (string) $b[$key] : '', 0, 0, 'C');
            $x += $c[6 + $k];
        }
        $this->SetXY($x0, $y + $h);
    }
}

/**
 * $data is what participantsDayBuild() returns (and what ?museum=&date=&format=json returns).
 */
function participantsRenderDayPdf(array $data)
{
    $sections  = $data['sections'];
    $cancelled = (int) $data['cancelled_bookings'];
    $cancelNote = $cancelled > 0
        ? $cancelled . ' cancelled booking' . ($cancelled === 1 ? '' : 's') . ' excluded.'
        : '';

    $pdf = new DaySheet('P', 'mm', 'A4');
    $pdf->sheet = $data;
    $pdf->AliasNbPages();
    $pdf->SetMargins(12, 12, 12);
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();

    if (count($sections) === 0) {
        $pdf->SetFont('Helvetica', '', 11);
        $pdf->Ln(6);
        $pdf->Cell(0, 8, participantsText('No ' . $data['museum'] . ' ticket bookings on this day.'), 0, 1, 'C');
        if ($cancelNote !== '') {
            $pdf->SetFont('Helvetica', '', 9);
            $pdf->Cell(0, 6, participantsText($cancelNote), 0, 1, 'C');
        }
    } else {
        $several = count($sections) > 1;
        $i = 1;
        foreach ($sections as $s) {
            if ($several) { $pdf->sectionHeading($s); }
            foreach ($s['bookings'] as $b) { $pdf->dayRow($i++, $b); }
            if ($several) { $pdf->sumRow('Subtotal', $s); }
        }
        $pdf->sumRow('Total', [
            'pax' => (int) $data['total_pax'], 'adults' => (int) $data['total_adults'],
            'children' => (int) $data['total_children'], 'infants' => (int) $data['total_infants'],
        ]);
        $pdf->SetFont('Helvetica', '', 7);
        $pdf->Ln(1);
        $pdf->Cell(0, 4, participantsText('AD = adults, CH = children, IN = infants.'
            . ($cancelNote !== '' ? '  ' . $cancelNote : '')), 0, 1);
    }

    // The blank box he writes the used tickets into - kept whole, never split over a page.
    if ($pdf->GetY() + 36 > 280) { $pdf->AddPage(); }
    $pdf->Ln(4);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(0, 6, participantsText('Tickets used:'), 0, 1);
    $pdf->Rect(12, $pdf->GetY(), 186, 26);

    return $pdf->Output('S');
}
