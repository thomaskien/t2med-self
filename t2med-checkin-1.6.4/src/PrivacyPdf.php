<?php
declare(strict_types=1);
namespace Checkin;

/** Adapted from fragebogenpi datenschutz.php: TCPDF, versioned text, optional choices,
 * signature. No upstream GDT entry point is included or executed. */
final class PrivacyPdf
{
    public static function dependencies(): void
    {
        if (!class_exists('TCPDF', false)) {
            $file = '/usr/share/php/tcpdf/tcpdf.php';
            if (!is_readable($file)) { throw new AppError('PRIVACY_PDF_DEPENDENCY', 'php-tcpdf fehlt. Bitte den Installer der neuen Version ausführen.', 503); }
            require_once $file;
        }
    }
    public static function create(Config $config, array $form, array $patient, array $answers, string $id): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) { throw new AppError('PRIVACY_PDF', 'Ungültige Dokumentkennung.', 500); }
        self::dependencies();
        $yaml = $form['yaml']; $signedAt = new \DateTimeImmutable('now', new \DateTimeZone($config->get('app.timezone')));
        $pdf = new class('P', 'mm', 'A4', true, 'UTF-8', false) extends \TCPDF {
            public string $privacyFooter = '';
            public function Header(): void {}
            public function Footer(): void
            {
                $this->SetY(-21); $this->SetFont('dejavusans', '', 7); $this->SetTextColor(85, 85, 85);
                $this->MultiCell(0, 3.5, $this->privacyFooter . "\nSeite " . $this->getAliasNumPage() . ' / ' . $this->getAliasNbPages(), 0, 'C');
            }
        };
        $pdf->privacyFooter = 'Datenschutz | Formularversion: ' . $form['version'] . ' | Check-in ' . VERSION
            . "\nDokument: " . $id . ' | ' . $signedAt->format('d.m.Y H:i:s T');
        $pdf->SetCreator('T2med Check-in ' . VERSION);
        $pdf->SetTitle('Datenschutz | Formularversion: ' . $form['version']);
        $pdf->SetMargins(18, 18, 18); $pdf->SetAutoPageBreak(true, 26); $pdf->AddPage();
        $pdf->SetFont('dejavusans', 'B', 17); $pdf->MultiCell(0, 9, $yaml['meta']['title'], 0, 'L');
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->MultiCell(0, 6, 'Formularversion: ' . $form['version'] . "\nPatient: " . $patient['name'] . "\nPatientennummer: " . $patient['number'], 0, 'L');
        $pdf->Ln(4);
        $warning = trim($yaml['meta']['warning_notice'] ?? '');
        if ($warning !== '') {
            $pdf->SetTextColor(160, 25, 25); $pdf->SetFont('dejavusans', 'B', 10);
            $pdf->MultiCell(0, 6, $warning, 1, 'L'); $pdf->Ln(4); $pdf->SetTextColor(20, 20, 20);
        }
        $sections = [['title' => $yaml['document']['heading'], 'text' => $yaml['document']['intro'] ?? ''], ...$yaml['document']['sections']];
        foreach ($sections as $section) {
            if ($pdf->GetY() > 240) { $pdf->AddPage(); }
            $pdf->SetFont('dejavusans', 'B', 11); $pdf->MultiCell(0, 6, $section['title'], 0, 'L');
            $pdf->SetFont('dejavusans', '', 10); $pdf->MultiCell(0, 5.2, $section['text'], 0, 'L'); $pdf->Ln(3);
        }
        $pdf->SetFont('dejavusans', '', 10);
        $choices = $yaml['consent']['pdf_label'] . ': ' . ($answers['email'] ? 'JA' : 'NEIN')
            . "\n" . $yaml['consent']['sms_pdf_label'] . ': ' . ($answers['sms'] ? 'JA' : 'NEIN')
            . "\nUnterzeichnet am: " . $signedAt->format('d.m.Y H:i:s T');
        // Reserve enough room for custom labels as well as the entire signature.
        $needed = max(20, $pdf->getStringHeight(174, $choices) * 1.4) + 110;
        if ($pdf->GetY() + $needed > 271) { $pdf->AddPage(); }
        $pdf->SetFont('dejavusans', 'B', 11); $pdf->MultiCell(0, 7, 'Kenntnisnahme und optionale Einwilligungen', 0, 'L');
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->MultiCell(0, 6, $choices, 0, 'L');
        $pdf->Ln(3); $pdf->MultiCell(0, 6, 'Unterschrift zur Kenntnisnahme der oben wiedergegebenen Patienteninformation:', 0, 'L');
        // Draw the validated normalized strokes directly into the PDF: no signature image
        // or PDF is written in plaintext to a shared temporary directory.
        $x = 18; $y = $pdf->GetY(); $width = 140; $height = 70;
        $pdf->SetLineStyle(['width' => 0.5, 'cap' => 'round', 'join' => 'round', 'color' => [15, 25, 25]]);
        foreach ($answers['strokes'] as $stroke) {
            for ($i = 1; $i < count($stroke); $i++) {
                $pdf->Line($x + $stroke[$i - 1][0] * $width, $y + $stroke[$i - 1][1] * $height,
                    $x + $stroke[$i][0] * $width, $y + $stroke[$i][1] * $height);
            }
        }
        $pdf->SetY($y + $height + 3); $pdf->SetFont('dejavusans', '', 8);
        $pdf->MultiCell(0, 5, 'Vorlagen-SHA256: ' . $form['hash'], 0, 'L');
        $bytes = $pdf->Output('', 'S');
        if (!is_string($bytes) || !str_starts_with($bytes, '%PDF-') || strlen($bytes) > 8 * 1024 * 1024) {
            throw new AppError('PRIVACY_PDF', 'Datenschutz-PDF konnte nicht erzeugt werden.', 500);
        }
        return $bytes;
    }
}
