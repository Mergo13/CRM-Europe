<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| PDF BRANDING – MODERN & UTF-8 SAFE
| Requires:
|  - FPDF
|  - DejaVuSans.ttf
|  - DejaVuSans-Bold.ttf
|--------------------------------------------------------------------------
*/

/* -------------------------------------------------------------
   UTF-8 HANDLING
------------------------------------------------------------- */
if (!function_exists('pdf_branding_text')) {
    function pdf_branding_text(string $text): string
    {
        // Normalize input to UTF-8 first (DB data is often Win-1252/Latin1)
        if (function_exists('mb_check_encoding') && !mb_check_encoding($text, 'UTF-8')) {
            // Assume Windows-1252 if it's not UTF-8
            $fixed = @iconv('Windows-1252', 'UTF-8//IGNORE', $text);
            if ($fixed !== false && $fixed !== null && $fixed !== '') {
                $text = $fixed;
            }
        }

        // For classic FPDF output: convert UTF-8 -> Windows-1252
        // (German umlauts are representable in CP1252, so they should survive)
        $out = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);

        if ($out === false || $out === null) {
            // Fallback: keep as much as possible
            $out = @iconv('UTF-8', 'Windows-1252//IGNORE', $text);
        }

        if ($out === false || $out === null) {
            $out = preg_replace('/[^\x20-\x7E]/', '?', $text) ?? '';
        }

        return $out;
    }
}

/* -------------------------------------------------------------
   COMPANY DATA
------------------------------------------------------------- */
if (!function_exists('pdf_branding_company')) {
    function pdf_branding_company(PDO $pdo): array
    {
        try {
            $pdo->exec(
                "INSERT INTO settings_company (id, company_name)
                 VALUES (1, 'Ihre Firma')
                 ON DUPLICATE KEY UPDATE company_name = company_name"
            );
        } catch (Throwable $e) {}

        try {
            $stmt = $pdo->query("SELECT * FROM settings_company WHERE id = 1");
            $row  = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : [];
        } catch (Throwable $e) {
            $row = [];
        }

        // Load optional extras (e.g., bank_name) from data/company_extra.json if not present in DB
        $bankName = trim($row['bank_name'] ?? '');
        try {
            if ($bankName === '') {
                $extraPath = __DIR__ . '/../data/company_extra.json';
                if (is_file($extraPath) && is_readable($extraPath)) {
                    $extra = json_decode((string)file_get_contents($extraPath), true) ?: [];
                    if (!empty($extra['bank_name'])) {
                        $bankName = trim((string)$extra['bank_name']);
                    }
                }
            }
        } catch (Throwable $e) { /* ignore */ }

        return [
            'company_name'  => trim($row['company_name'] ?? 'Ihre Firma'),
            'address_line1' => trim($row['address_line1'] ?? ''),
            'address_line2' => trim($row['address_line2'] ?? ''),
            'email'         => trim($row['email'] ?? ''),
            'website'       => trim($row['website'] ?? ''),
            'vat'           => trim($row['vat'] ?? ''),
            'iban'          => trim($row['iban'] ?? ''),
            'bic'           => trim($row['bic'] ?? ''),
            'bank_name'     => $bankName,
            'logo_path'     => trim($row['logo_path'] ?? ''),
        ];
    }
}

/* -------------------------------------------------------------
   HEADER (MINIMAL & MODERN)
------------------------------------------------------------- */
if (!function_exists('pdf_branding_header')) {
    function pdf_branding_header($pdf, PDO $pdo, array $opts = []): void
    {
        $using = $opts['using_dejavu'] ?? true;
        $font  = $opts['font'] ?? 'DejaVu';

        $c = pdf_branding_company($pdo);

        $left = 10;
        $top  = 10;

        /* ---------- LOGO ---------- */
        $logoOffset = 0;
        if ($c['logo_path']) {
            $path = $c['logo_path'][0] === '/'
                ? ($_SERVER['DOCUMENT_ROOT'] ?? '') . $c['logo_path']
                : realpath(__DIR__ . '/../' . ltrim($c['logo_path'], '/'));

            if ($path && is_file($path)) {
                // Detect actual image type to avoid FPDF fatal errors when extension is wrong
                $typeHint = '';
                try {
                    $info = @getimagesize($path);
                    if (is_array($info) && !empty($info['mime'])) {
                        $mime = strtolower((string)$info['mime']);
                        if ($mime === 'image/jpeg' || $mime === 'image/jpg') {
                            $typeHint = 'JPG';
                        } elseif ($mime === 'image/png') {
                            $typeHint = 'PNG';
                        }
                    }
                } catch (\Throwable $e) {
                    // ignore and let FPDF try by extension
                }

                // As a fallback, infer from extension if no MIME was detected
                if ($typeHint === '') {
                    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                    if (in_array($ext, ['jpg','jpeg'], true)) {
                        $typeHint = 'JPG';
                    } elseif ($ext === 'png') {
                        $typeHint = 'PNG';
                    }
                }

                // Try to place the image; on error, skip logo instead of crashing
                try {
                    if ($typeHint) {
                        $pdf->Image($path, $left, $top, 22, 0, $typeHint);
                    } else {
                        $pdf->Image($path, $left, $top, 22);
                    }
                    $logoOffset = 28;
                } catch (\Throwable $e) {
                    // Skip invalid logo file gracefully
                    $logoOffset = 0;
                }
            }
        }

        /* ---------- COMPANY NAME ---------- */
        $pdf->SetXY($left + $logoOffset, $top);
        $pdf->SetFont($font, 'B', 16);
        $pdf->Cell(
            0,
            8,
            pdf_branding_text($c['company_name']),
            0,
            1
        );

        /* ---------- META ---------- */
        $pdf->SetFont($font, '', 9);
        $pdf->SetX($left + $logoOffset);

        $lines = array_filter([
            $c['address_line1'],
            $c['address_line2'],
            $c['website'],
            $c['email'],
        ]);

        foreach ($lines as $line) {
            $pdf->Cell(0, 5, pdf_branding_text($line), 0, 1);
            $pdf->SetX($left + $logoOffset);
        }

        /* ---------- DIVIDER ---------- */
        $pdf->Ln(3);
        $pdf->SetDrawColor(220, 220, 220);
        $pdf->SetLineWidth(0.3);
        $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
        $pdf->Ln(6);
    }
}

/* -------------------------------------------------------------
   FOOTER (CLEAN & PROFESSIONAL)
------------------------------------------------------------- */
if (!function_exists('pdf_branding_footer')) {
    function pdf_branding_footer($pdf, PDO $pdo, array $opts = []): void
    {
        $using = $opts['using_dejavu'] ?? true;
        $font  = $opts['font'] ?? 'DejaVu';

        $c = pdf_branding_company($pdo);

        $pdf->SetY(-28);

        /* ---------- DIVIDER ---------- */
        $pdf->SetDrawColor(220, 220, 220);
        $pdf->SetLineWidth(0.3);
        $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
        $pdf->Ln(3);

        /* ---------- CONTACT ---------- */
        $pdf->SetFont($font, '', 8);
        $contact = implode(' · ', array_filter([
            $c['company_name'] ?? null,
            !empty($c['vat']) ? (string)$c['vat'] : null,
            $c['email'] ?? null,
            $c['website'] ?? null,
            $c['tel'] ?? null,
        ], static fn($v) => $v !== null && $v !== ''));


        if ($contact) {
            $pdf->Cell(0, 4, pdf_branding_text($contact), 0, 1, 'C');
        }

        /* ---------- BANK ---------- */
        $bank = implode(' · ', array_filter([
            $c['bank_name'] ? ' ' . $c['bank_name'] : null,
            $c['iban'] ? 'IBAN ' . $c['iban'] : null,
            $c['bic'] ? 'BIC ' . $c['bic'] : null,
        ]));

        if ($bank) {
            $pdf->SetFont($font, '', 9);
            $pdf->Cell(0, 5, pdf_branding_text($bank), 0, 1, 'C');
        }
    }
}
