<?php

namespace App\Services;

use App\Domain\Rules;
use App\Support\Storage;
use FPDF;

/** Dark, client-ready retrospective PDF with the workspace logo at the top. */
final class RetroPdf
{
    private static function t(string $s): string
    {
        // FPDF core fonts are Latin-1.
        $s = strtr($s, ['—' => '-', '–' => '-', '’' => "'", '‘' => "'", '“' => '"', '”' => '"', '·' => '|', '…' => '...']);

        return (string) iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $s);
    }

    public static function render(object $t, array $r): string
    {
        $pdf = new FPDF('P', 'pt', 'A4');
        $pdf->SetMargins(56, 56, 56);
        $pdf->SetAutoPageBreak(false);
        $pdf->AddPage();
        $W = $pdf->GetPageWidth();
        $H = $pdf->GetPageHeight();
        $pdf->SetFillColor(11, 11, 15);
        $pdf->Rect(0, 0, $W, $H, 'F');
        $y = 56;
        $logo = $t->logo_url && str_starts_with($t->logo_url, '/api/files/') ? Storage::get(substr($t->logo_url, strlen('/api/files/'))) : null;
        if ($logo) {
            $ext = str_contains($logo['contentType'], 'png') ? 'png' : 'jpg';
            $tmp = tempnam(sys_get_temp_dir(), 'logo').".{$ext}";
            file_put_contents($tmp, $logo['data']);
            try {
                $pdf->Image($tmp, 56, 48, 0, 44, strtoupper($ext));
                $y = 104;
            } catch (\Throwable) {
            } finally {
                @unlink($tmp);
            }
        }
        $pdf->SetTextColor(232, 232, 240);
        $pdf->SetFont('Helvetica', 'B', 22);
        $pdf->SetXY(56, $y);
        $pdf->Cell(0, 26, self::t($t->business_name), 0, 1);
        $pdf->SetTextColor(136, 136, 168);
        $pdf->SetFont('Helvetica', '', 11);
        $pdf->SetX(56);
        $pdf->Cell(0, 16, 'Campaign Retrospective', 0, 1);
        $pdf->Ln(18);
        $pdf->SetTextColor(232, 232, 240);
        $pdf->SetFont('Helvetica', 'B', 18);
        $pdf->Cell(0, 22, self::t($r['campaignName']), 0, 1);
        $pdf->SetTextColor(136, 136, 168);
        $pdf->SetFont('Helvetica', '', 11);
        $pdf->Cell(0, 16, self::t("{$r['startDate']} – {$r['endDate']} · {$r['daysActive']} days active"), 0, 1);
        $tiles = [
            ['Total leads', (string) ($r['totalLeads'] ?? 0)], ['Avg speed-to-lead', Rules::formatDuration($r['avgResponseTimeMs'])],
            ['Acknowledgment rate', $r['acknowledgmentRate'] === null ? '—' : round($r['acknowledgmentRate'] * 100).'%'],
        ];
        if ($r['cpl'] !== null) {
            $tiles[] = ['Cost per lead', '$'.rtrim(rtrim(number_format($r['cpl'], 2), '0'), '.')];
        }
        $y = $pdf->GetY() + 24;
        foreach ($tiles as $i => [$k, $v]) {
            $x = 56 + $i * 124;
            $pdf->SetFillColor(17, 17, 24);
            $pdf->Rect($x, $y, 116, 70, 'F');
            $pdf->SetTextColor(85, 85, 122);
            $pdf->SetFont('Helvetica', '', 8);
            $pdf->SetXY($x + 12, $y + 12);
            $pdf->Cell(96, 10, self::t(strtoupper($k)));
            $pdf->SetTextColor(232, 232, 240);
            $pdf->SetFont('Helvetica', 'B', 16);
            $pdf->SetXY($x + 12, $y + 32);
            $pdf->Cell(96, 20, self::t($v));
        }
        $pdf->SetXY(56, $y + 96);
        $pdf->SetTextColor(136, 136, 168);
        $pdf->SetFont('Helvetica', '', 10);
        foreach (['bestPage' => 'Best page', 'worstPage' => 'Weakest page'] as $key => $label) {
            if ($p = $r[$key]) {
                $pdf->Cell(0, 14, self::t("{$label}: {$p['name']}".($p['conversionRate'] !== null ? ' · '.number_format($p['conversionRate'] * 100, 1).'% conversion' : '')), 0, 1);
            }
        }
        $pdf->Ln(18);
        $pdf->SetTextColor(85, 85, 122);
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->Cell(0, 10, 'AI OBSERVATION', 0, 1);
        $pdf->Ln(6);
        $pdf->SetTextColor(232, 232, 240);
        $pdf->SetFont('Helvetica', 'I', 12);
        $pdf->MultiCell(0, 18, self::t((string) $r['aiObservation']));
        $pdf->SetTextColor(85, 85, 122);
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->SetXY(56, $H - 72);
        $pdf->Cell($W - 112, 10, self::t('Powered by '.Platform::get()['branding']['productName']), 0, 0, 'C');

        return $pdf->Output('S');
    }
}
