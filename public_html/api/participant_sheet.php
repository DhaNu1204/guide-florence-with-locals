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
