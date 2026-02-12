<?php
// pages/base_template.php — Shared PDF base for Angebot/Rechnung/Mahnung
// Provides a unified FPDF subclass with header, footer, fonts, margins, and page numbering.
// Also exposes a helper to render the client address block consistently.

declare(strict_types=0);

require_once __DIR__ . '/../vendor/setasign/fpdf/fpdf.php';
require_once __DIR__ . '/../includes/pdf_branding.php';

class BaseTemplatePdf extends FPDF
{
    /** @var PDO */
    public PDO $pdo;
    /** @var string */
    public string $fontFamily = 'DejaVu';

    public function __construct(PDO $pdo, string $orientation = 'P', string $unit = 'mm', string $size = 'A4')
    {
        parent::__construct($orientation, $unit, $size);
        $this->pdo = $pdo;
        // Ensure page numbers can use {nb}
        $this->AliasNbPages();
        // Margins and auto page break
        $this->SetMargins(10, 10, 10);
        $this->SetAutoPageBreak(true, 30);
        // Fonts (DejaVu installed in project root via DejaVuSans.php)
        $this->AddFont('DejaVu', '', 'DejaVuSans.php');
        $this->AddFont('DejaVu', 'B', 'DejaVuSans-Bold.php');
    }

    public function Header(): void
    {
        pdf_branding_header($this, $this->pdo, [
            'using_dejavu' => true,
            'font' => $this->fontFamily,
        ]);
    }

    public function Footer(): void
    {
        pdf_branding_footer($this, $this->pdo, [
            'using_dejavu' => true,
            'font' => $this->fontFamily,
        ]);
        // Page numbering centered at the very bottom
        $this->SetY(-10);
        $this->SetFont($this->fontFamily, '', 8);
        $this->Cell(0, 6, pdf_branding_text('Seite ' . $this->PageNo() . '/{nb}'), 0, 0, 'C');
    }
}

/**
 * Create a standard-configured PDF instance and first page.
 */
function pdf_base_create(PDO $pdo, array $opts = []): BaseTemplatePdf
{
    $pdf = new BaseTemplatePdf($pdo, $opts['orientation'] ?? 'P', $opts['unit'] ?? 'mm', $opts['size'] ?? 'A4');
    if (!empty($opts['title'])) {
        $pdf->SetTitle((string)$opts['title']);
    }
    $pdf->AddPage();
    return $pdf;
}

/**
 * Render a standardized client address block at the current Y position.
 * Expects keys: firma/name, adresse, plz, ort in the provided array.
 */
function pdf_render_client_address(BaseTemplatePdf $pdf, array $client): void
{
    $pdf->SetDrawColor(220,220,220);
    $pdf->SetFillColor(246,246,247);
    $boxX = 12; $boxW = 130; $boxH = 30; $yNow = $pdf->GetY();
    $pdf->Rect($boxX, $yNow, $boxW, $boxH, 'DF');
    $pdf->SetXY($boxX + 2, $yNow + 3);
    $pdf->SetFont('DejaVu','B',11);
    $nameLine = trim(($client['firma'] ?? '') . ' ' . ($client['name'] ?? ''));
    if ($nameLine !== '') { $pdf->Cell($boxW - 4, 5, pdf_branding_text($nameLine), 0, 1, 'L'); }
    $pdf->SetFont('DejaVu','',10);
    if (!empty($client['adresse'])) { $pdf->Cell($boxW - 4, 5, pdf_branding_text((string)$client['adresse']), 0, 1, 'L'); }
    $loc = trim(($client['plz'] ?? '') . ' ' . ($client['ort'] ?? ''));
    if ($loc !== '') { $pdf->Cell($boxW - 4, 5, pdf_branding_text($loc), 0, 1, 'L'); }
    $pdf->SetY($yNow + $boxH + 8);
}
