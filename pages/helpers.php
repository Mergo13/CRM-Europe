<?php


// Single source of truth for PDF paths

// Set this to true from your PDF bootstrap when you use Unicode TTF fonts (e.g. DejaVuSans with Unicode=true).
$GLOBALS['PDF_UNICODE'] = $GLOBALS['PDF_UNICODE'] ?? false;

if (!function_exists('enc')) {
    /**
     * Encode text for PDF output.
     * - If PDF_UNICODE=true: return UTF-8 unchanged (for Unicode TTF fonts).
     * - Else: convert UTF-8 to Windows-1252 for classic FPDF core/single-byte fonts.
     *   Use //IGNORE to preserve German umlauts and € without transliteration artefacts.
     */
    function enc($s): string
    {
        if ($s === null) {
            return '';
        }

        $str = (string)$s;

        if (!empty($GLOBALS['PDF_UNICODE'])) {
            return $str; // UTF-8 for Unicode fonts
        }

        // Prefer centralized branding helper if available for consistent behavior
        if (function_exists('pdf_branding_text')) {
            return pdf_branding_text($str);
        }

        // Single-byte fallback (keeps German umlauts, €)
        $converted = @iconv('UTF-8', 'Windows-1252//IGNORE', $str);
        if ($converted === false || $converted === null || $converted === '') {
            $converted = @mb_convert_encoding($str, 'ISO-8859-1', 'UTF-8');
            if ($converted === false || $converted === null) {
                $converted = preg_replace('/[^\x20-\x7E]/', '?', $str) ?? '';
            }
        }
        return $converted;
    }
}

/**
 * Returns the absolute file system path for a PDF.
 *
 * Example: prefix 'rechnung', id 15, date 2025-11-23
 * => /.../pdf/rechnungen/2025/11/rechnungen_15.pdf
 */
function pdf_file_path(string $prefix, int|string $id, string $extra = '', string $documentDate = ''): string
{
    $year  = $documentDate ? date('Y', strtotime($documentDate)) : date('Y');
    $month = $documentDate ? date('m', strtotime($documentDate)) : date('m');

    // Determine category folder (plural) from prefix
    $prefixLower = strtolower($prefix);
    $folderMap = [
        'rechnung'      => 'rechnungen',
        'rechnungen'    => 'rechnungen',
        'angebot'       => 'angebote',
        'angebote'      => 'angebote',
        'mahnung'       => 'mahnungen',
        'mahnungen'     => 'mahnungen',
        'lieferschein'  => 'lieferscheine',
        'lieferscheine' => 'lieferscheine',
    ];
    $catFolder = $folderMap[$prefixLower] ?? ($prefixLower . (str_ends_with($prefixLower, 'en') ? '' : 'en'));

    $subfolder = '';
    if ($catFolder === 'mahnungen' && $extra !== '') {
        $subfolder = '/' . $extra;
    }

    $dir = __DIR__ . "/../pdf/{$catFolder}/{$year}/{$month}{$subfolder}";
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    // Use singular file prefixes
    $prefixMap = [
        'rechnungen'   => 'rechnung',
        'angebote'     => 'angebot',
        'mahnungen'    => 'mahnung',
        'lieferscheine'=> 'lieferschein',
    ];
    $filePrefix = $prefixMap[$catFolder] ?? rtrim($catFolder, 'en');
    $file = "{$filePrefix}_{$id}.pdf";
    return "{$dir}/{$file}";
}

/**
 * Returns the web path for a PDF file, matching pdf_file_path().
 *
 * Example: /pdf/rechnungen/2025/11/rechnungen_15.pdf
 */
function pdf_web_path(string $prefix, int|string $id, string $extra = '', string $documentDate = ''): string
{
    $year  = $documentDate ? date('Y', strtotime($documentDate)) : date('Y');
    $month = $documentDate ? date('m', strtotime($documentDate)) : date('m');

    // Determine category folder (plural) from prefix — must mirror pdf_file_path()
    $prefixLower = strtolower($prefix);
    $folderMap = [
        'rechnung'      => 'rechnungen',
        'rechnungen'    => 'rechnungen',
        'angebot'       => 'angebote',
        'angebote'      => 'angebote',
        'mahnung'       => 'mahnungen',
        'mahnungen'     => 'mahnungen',
        'lieferschein'  => 'lieferscheine',
        'lieferscheine' => 'lieferscheine',
    ];
    $catFolder = $folderMap[$prefixLower] ?? ($prefixLower . (str_ends_with($prefixLower, 'en') ? '' : 'en'));

    $subfolder = '';
    if ($catFolder === 'mahnungen' && $extra !== '') {
        $subfolder = '/' . $extra;
    }

    // Use singular file prefixes to match pdf_file_path()
    $prefixMap = [
        'rechnungen'   => 'rechnung',
        'angebote'     => 'angebot',
        'mahnungen'    => 'mahnung',
        'lieferscheine'=> 'lieferschein',
    ];
    $filePrefix = $prefixMap[$catFolder] ?? rtrim($catFolder, 'en');
    $file = "{$filePrefix}_{$id}.pdf";
    return "/pdf/{$catFolder}/{$year}/{$month}{$subfolder}/{$file}";
}

/**
 * Renders a PDF preview/download button for a document.
 */
function renderPdfButton(string $prefix, int|string $id, string $extra = '', string $documentDate = ''): string
{
    // Always route to the generator endpoints to avoid stale static PDFs
    $generators = [
        'rechnung'    => '/pages/rechnung_pdf.php?id=',
        'angebot'     => '/pages/angebot_pdf.php?id=',
        'mahnung'     => '/pages/mahnung_pdf.php?id=',
        'lieferschein'=> '/pages/lieferschein_pdf.php?id=',
    ];

    $genUrl = isset($generators[$prefix]) ? ($generators[$prefix] . $id) : '';

    if ($genUrl !== '') {
        $btn  = '<td class="text-center">';
        $btn .= '<button type="button" class="btn btn-sm btn-outline-primary" ';
        $btn .= 'onclick="openPdfPreview(\'' . htmlspecialchars($genUrl, ENT_QUOTES) . '\')">';
        $btn .= '<i class="bi bi-file-earmark-pdf"></i>';
        $btn .= '</button>';
        $btn .= '</td>';
        return $btn;
    }

    return '<td class="text-center"><span class="text-muted">—</span></td>';
}

// ---------------- Shared line-item formatting and mapping ----------------
if (!function_exists('format_currency_eur')) {
    function format_currency_eur(float $amount): string {
        return number_format($amount, 2, ',', '.') . ' €';
    }
}

if (!function_exists('build_item_row')) {
    /**
     * Normalizes a DB row for items into a display-ready array.
     * Distinguishes manual vs registered items strictly:
     * - Manual (produkt_id NULL): name = rp.beschreibung, description = ''
     * - Registered: name = p.name, description = p.beschreibung
     */
    function build_item_row(array $row): array {
        $produktId = $row['produkt_id'] ?? null;
        $isManual = ($produktId === null || $produktId === '' || (is_numeric($produktId) && (int)$produktId === 0));

        if ($isManual) {
            $name = $row['manual_beschreibung'] ?? ($row['beschreibung'] ?? '');
            $desc = '';
        } else {
            $name = $row['produkt_name'] ?? ($row['name'] ?? '');
            $desc = $row['produkt_beschreibung'] ?? ($row['beschreibung'] ?? '');
        }

        $qty  = isset($row['menge']) ? (float)$row['menge'] : (isset($row['qty']) ? (float)$row['qty'] : 1.0);
        $unit = isset($row['einzelpreis']) ? (float)$row['einzelpreis'] : (isset($row['price']) ? (float)$row['price'] : 0.0);
        $total= isset($row['gesamt']) ? (float)$row['gesamt'] : ($qty * $unit);
        $vat  = isset($row['mwst']) ? (float)$row['mwst'] : (isset($row['vat']) ? (float)$row['vat'] : null);
        return [
            'name' => (string)$name,
            'description' => (string)$desc,
            'qty' => $qty,
            'unit_price' => $unit,
            'total' => $total,
            'vat' => $vat,
            'qty_str' => number_format($qty, 2, ',', '.'),
            'unit_str' => format_currency_eur($unit),
            'total_str'=> format_currency_eur($total),
        ];
    }
}

if (!function_exists('fetch_rechnung_items')) {
    function fetch_rechnung_items(PDO $pdo, int $rechnung_id): array {
        try {
            $stmt = $pdo->prepare('SELECT rp.produkt_id, rp.beschreibung AS manual_beschreibung, p.name AS produkt_name, p.beschreibung AS produkt_beschreibung, rp.menge, rp.einzelpreis, rp.gesamt, p.mwst FROM rechnungs_positionen rp LEFT JOIN produkte p ON rp.produkt_id = p.id WHERE rp.rechnung_id = ? ORDER BY rp.id');
            $stmt->execute([$rechnung_id]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            // Fallback for older schema without rp.beschreibung
            $stmt = $pdo->prepare('SELECT rp.produkt_id, p.name AS produkt_name, p.beschreibung AS produkt_beschreibung, rp.menge, rp.einzelpreis, rp.gesamt, p.mwst FROM rechnungs_positionen rp LEFT JOIN produkte p ON rp.produkt_id = p.id WHERE rp.rechnung_id = ? ORDER BY rp.id');
            $stmt->execute([$rechnung_id]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        return array_map('build_item_row', $rows);
    }
}

if (!function_exists('fetch_angebot_items')) {
    function fetch_angebot_items(PDO $pdo, int $angebot_id): array {
        try {
            $stmt = $pdo->prepare('SELECT ap.produkt_id, ap.beschreibung AS manual_beschreibung, p.name AS produkt_name, p.beschreibung AS produkt_beschreibung, ap.menge, ap.einzelpreis, ap.gesamt, p.mwst FROM angebot_positionen ap LEFT JOIN produkte p ON ap.produkt_id = p.id WHERE ap.angebot_id = ? ORDER BY ap.id');
            $stmt->execute([$angebot_id]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            // Fallback for older schema without beschreibung column
            $stmt = $pdo->prepare('SELECT ap.produkt_id, p.name AS produkt_name, p.beschreibung AS produkt_beschreibung, ap.menge, ap.einzelpreis, ap.gesamt, p.mwst FROM angebot_positionen ap LEFT JOIN produkte p ON ap.produkt_id = p.id WHERE ap.angebot_id = ? ORDER BY ap.id');
            $stmt->execute([$angebot_id]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        return array_map('build_item_row', $rows);
    }
}

if (!function_exists('fetch_lieferschein_items')) {
    function fetch_lieferschein_items(PDO $pdo, int $lieferschein_id): array {
        // Lieferschein-Positionen enthalten keine Preise; nur Artikelname und Menge werden geladen.
        // WICHTIG: Gib produkt_id und aliasierte Felder zurück, damit build_item_row korrekt mappt.
        $stmt = $pdo->prepare('SELECT lp.produkt_id, p.name AS produkt_name, p.beschreibung AS produkt_beschreibung, lp.menge FROM lieferschein_positionen lp LEFT JOIN produkte p ON lp.produkt_id = p.id WHERE lp.lieferschein_id = ? ORDER BY lp.id');
        $stmt->execute([$lieferschein_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map('build_item_row', $rows);
    }
}


// ---------------- PDF table helpers (shared) ----------------
if (!function_exists('fitTextToWidth')) {
    /**
     * Truncate text so that its printed width does not exceed $maxWidth.
     * Appends ellipsis … when truncated. Assumes current font is set.
     */
    function fitTextToWidth($pdf, string $text, float $maxWidth): string {
        $t = enc($text);
        if ($maxWidth <= 0) return $t;
        $w = $pdf->GetStringWidth($t);
        if ($w <= $maxWidth) return $t;
        $ellipsis = enc('…');
        $ew = $pdf->GetStringWidth($ellipsis);
        $limit = max(0.0, $maxWidth - $ew);
        $out = '';
        $len = strlen($t);
        for ($i = 0; $i < $len; $i++) {
            $ch = $t[$i];
            if ($pdf->GetStringWidth($out . $ch) > $limit) break;
            $out .= $ch;
        }
        return rtrim($out) . $ellipsis;
    }
}

if (!function_exists('pdf_table_NbLines')) {
    /** Rough estimate of number of lines a MultiCell($w, $lineH, $text) would use */
    function pdf_table_NbLines($pdf, float $w, string $text): int {
        $t = enc($text);
        if ($t === '') return 1;
        $max = max(1.0, $w);
        $lines = 1; $lineW = 0.0;
        $len = strlen($t);
        for ($i = 0; $i < $len; $i++) {
            $c = $t[$i];
            if ($c === "\n") { $lines++; $lineW = 0.0; continue; }
            $cw = $pdf->GetStringWidth($c);
            if ($lineW + $cw > $max) { $lines++; $lineW = $cw; }
            else { $lineW += $cw; }
        }
        return max(1, $lines);
    }
}

if (!function_exists('pdf_table_Row')) {
    /**
     * Draw a row with aligned borders using Rect for each cell.
     * - $data: array of strings (cell texts)
     * - $widths: array of numbers (cell widths)
     * - $aligns: array of align chars ('L','C','R')
     * - Only the first cell wraps by default; others are single-line centered vertically.
     */
    function pdf_table_Row($pdf, array $data, array $widths, array $aligns, float $lineH = 5.0, ?float $xStart = null, bool $fill = false, array $fillColor = [250,250,250], bool $drawBorders = true, float $pad = 2.0, array $wrapCols = [0]): float {
        // Reset state to avoid leakage across rows
        if (method_exists($pdf, 'SetTextColor')) { $pdf->SetTextColor(0,0,0); }
        if (method_exists($pdf, 'SetFont')) { $pdf->SetFont('DejaVu','',10); }
        $x0 = $xStart !== null ? $xStart : $pdf->GetX();
        $y0 = $pdf->GetY();
        $n = count($data);
        $heights = [];
        $maxH = $lineH;
        for ($i = 0; $i < $n; $i++) {
            $w = $widths[$i] ?? 0;
            $txt = (string)($data[$i] ?? '');
            $nb = in_array($i, $wrapCols, true) ? pdf_table_NbLines($pdf, max(0.1, $w - 2*$pad), $txt) : 1;
            $h = max($lineH, $nb * $lineH);
            $heights[$i] = $h;
            if ($h > $maxH) $maxH = $h;
        }
        // Draw cells
        $x = $x0; $y = $y0;
        if ($fill) { $pdf->SetFillColor($fillColor[0], $fillColor[1], $fillColor[2]); }
        for ($i = 0; $i < $n; $i++) {
            $w = $widths[$i] ?? 0;
            $txt = enc((string)($data[$i] ?? ''));
            // Background and border
            if ($fill) { $pdf->Rect($x, $y, $w, $maxH, 'F'); }
            if ($drawBorders) { $pdf->Rect($x, $y, $w, $maxH); }
            // Text
            if (in_array($i, $wrapCols, true)) {
                $vOffset = max(0.0, ($maxH - $heights[$i]) / 2);
                $pdf->SetXY($x + $pad, $y + $vOffset);
                $pdf->MultiCell($w - 2*$pad, $lineH, $txt, 0, $aligns[$i] ?? 'L');
                // restore X after MultiCell
                $pdf->SetXY($x + $w, $y);
            } else {
                $pdf->SetXY($x + $pad, $y + ($maxH/2) - ($lineH/2));
                $pdf->Cell($w - 2*$pad, $lineH, $txt, 0, 0, $aligns[$i] ?? 'L');
            }
            $x += $w;
        }
        // Move cursor to next row start
        $pdf->SetXY($x0, $y0 + $maxH);
        return $maxH; // height drawn
    }
}

if (!function_exists('drawRowFixedHeight')) {
    /**
     * Draw a fixed-height row by truncating each cell text to fit (no wrapping).
     * $cols: array of [width, text, align]
     */
    function drawRowFixedHeight($pdf, float $x, float $y, array $cols, float $rowH, bool $border = true, float $pad = 2.0, ?array $fillColor = null): void {
        if ($fillColor) { $pdf->SetFillColor($fillColor[0], $fillColor[1], $fillColor[2]); }
        $cx = $x;
        foreach ($cols as $col) {
            $w = (float)$col[0];
            $text = (string)$col[1];
            $align = $col[2] ?? 'L';
            $txt = fitTextToWidth($pdf, $text, max(0.1, $w - 2*$pad));
            if ($fillColor) { $pdf->Rect($cx, $y, $w, $rowH, 'F'); }
            if ($border) { $pdf->Rect($cx, $y, $w, $rowH); }
            $fontSizePt = method_exists($pdf, 'GetFontSize') ? (float)$pdf->GetFontSize() : 10.0;
            $pdf->SetXY($cx + $pad, $y + ($rowH/2) - (($fontSizePt * 0.3528) / 2));
            $pdf->Cell($w - 2*$pad, $rowH, enc($txt), 0, 0, $align);
            $cx += $w;
        }
        $pdf->SetXY($x, $y + $rowH);
    }
}

if (!function_exists('pdf_table_layout')) {
    /** Standardized widths/heights per document type */
    function pdf_table_layout(string $type): array {
        $type = strtolower($type);
        switch ($type) {
            case 'rechnung':
            case 'angebot':
                return [
                    'widths' => [92.0, 26.0, 36.0, 36.0],
                    'headerH' => 9.0,
                    'rowH' => 8.0,
                ];
            case 'lieferschein':
                return [
                    'widths' => [140.0, 40.0],
                    'headerH' => 9.0,
                    'rowH' => 7.0,
                ];
            case 'mahnung_totals':
                return [
                    'widths' => [60.0, 30.0], // labels + values within a 90mm box
                    'headerH' => 9.0,
                    'rowH' => 7.0,
                ];
            default:
                return [
                    'widths' => [],
                    'headerH' => 9.0,
                    'rowH' => 8.0,
                ];
        }
    }
}


if (!function_exists('recalc_angebot_total')) {
    /**
     * Recalculate and persist angebote.betrag based on SUM(menge*einzelpreis)
     * from angebot_positionen for the given Angebot (offer) ID.
     * Returns the calculated sum as float.
     */
    function recalc_angebot_total(PDO $pdo, int $angebotId): float
    {
        try {
            $stmt = $pdo->prepare('SELECT COALESCE(SUM(menge * einzelpreis), 0) AS summe FROM angebot_positionen WHERE angebot_id = ?');
            $stmt->execute([$angebotId]);
            $sum = (float)$stmt->fetchColumn();

            // Persist to angebote.betrag (legacy net amount field)
            $upd = $pdo->prepare('UPDATE angebote SET betrag = ? WHERE id = ?');
            $upd->execute([number_format($sum, 2, '.', ''), $angebotId]);

            return $sum;
        } catch (Throwable $e) {
            // Best-effort: do not throw in legacy contexts; just return 0
            try {
                return isset($sum) ? (float)$sum : 0.0;
            } catch (Throwable $e2) {
                return 0.0;
            }
        }
    }
}
