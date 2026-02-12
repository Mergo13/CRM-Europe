<?php

declare(strict_types=1);

/* =================================================
   PDF PATH CONFIGURATION
================================================= */

define('PDF_BASE_FS', realpath(__DIR__ . '/../') . '/pdf');
define('PDF_BASE_WEB', '/pdf');

$pdfPatterns = [
    'rechnung'     => ['dir' => 'rechnungen',    'file' => 'Rechnung_%s.pdf'],
    'angebot'      => ['dir' => 'angebote',      'file' => 'Angebot_%s.pdf'],
    'lieferschein' => ['dir' => 'lieferscheine', 'file' => 'Lieferschein_%s.pdf'],
    'mahnung'      => ['dir' => 'mahnungen',     'file' => 'Mahnung_%s.pdf'],
];

/**
 * Build absolute + web paths for PDF documents
 */
function pdf_paths(string $type, $id, string $extra = '', ?string $documentDate = null): array
{
    global $pdfPatterns;

    $typeKey = strtolower($type);
    $cfg = $pdfPatterns[$typeKey]
        ?? ['dir' => ucfirst($typeKey), 'file' => ucfirst($typeKey) . '_%s.pdf'];

    $timestamp = $documentDate ? strtotime($documentDate) : time();
    $year  = date('Y', $timestamp);
    $month = date('m', $timestamp);

    $subfolder = ($typeKey === 'mahnung' && $extra !== '')
        ? '/' . trim($extra, '/')
        : '';

    $relDir = '/' . $cfg['dir'] . '/' . $year . '/' . $month . $subfolder;

    $absDir = rtrim(PDF_BASE_FS, '/') . $relDir;
    if (!is_dir($absDir)) {
        mkdir($absDir, 0775, true);
    }

    $fileName = sprintf($cfg['file'], $id);

    return [
        'file' => $absDir . '/' . $fileName,
        'web'  => rtrim(PDF_BASE_WEB, '/') . $relDir . '/' . $fileName,
    ];
}

/* =================================================
   GLOBAL PAGE URL HELPER
================================================= */

if (!function_exists('page_url')) {
function page_url(string $file): string
{
    return '/pages/' . ltrim($file, '/');
}
}

/* =================================================
   EPC / SEPA QR HELPERS
================================================= */

/**
 * Normalize creditor name for EPC / SEPA QR usage.
 *
 * This guarantees that once saved,
 * QR codes can always be generated without extra checks.
 *
 * Rules applied:
 * - Replace umlauts
 * - Remove forbidden EPC characters
 * - Collapse whitespace
 * - Enforce safe length
 */
if (!function_exists('epc_normalize_creditor_name')) {
    function epc_normalize_creditor_name(string $name): string
    {
        $name = trim($name);

        // Replace German umlauts (EPC-safe)
        $map = [
            'Ä' => 'AE', 'Ö' => 'OE', 'Ü' => 'UE',
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue',
            'ß' => 'ss',
        ];
        $name = strtr($name, $map);

        // Remove all forbidden EPC characters
        $name = preg_replace('/[^A-Za-z0-9 .\-]/', '', $name);

        // Collapse whitespace
        $name = preg_replace('/\s+/', ' ', $name);

        // EPC-safe max length (recommended ≤ 70)
        return substr($name, 0, 70);
    }
}
