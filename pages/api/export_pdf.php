<?php
// pages/api/export_pdf.php
// Generic PDF export for list pages (rechnungen, angeboten, lieferscheine, mahnungen, clients)
// Usage: /pages/api/export_pdf.php?type=rechnungen&q=...&status=...&from=YYYY-MM-DD&to=YYYY-MM-DD&sort=...&dir=asc|desc

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Mpdf\Mpdf;
use Mpdf\Config\FontVariables;
use Mpdf\Config\ConfigVariables;

// Helpers
function esc($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function param(string $key, $default = '') { return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default; }

$type   = strtolower(param('type'));
$q      = param('q');
$status = param('status');
$from   = param('from'); // YYYY-MM-DD
$to     = param('to');
$sort   = param('sort');
$dir    = strtolower(param('dir', 'desc')) === 'asc' ? 'ASC' : 'DESC';
$limit  = max(1, min(5000, (int)($_GET['limit'] ?? 1000)));

$allowed = [
    'rechnungen'   => ['table' => 'rechnungen',   'title' => 'Rechnungen',   'date_col' => 'datum'],
    'angeboten'    => ['table' => 'angeboten',    'title' => 'Angebote',     'date_col' => 'valid_until'],
    'lieferscheine'=> ['table' => 'lieferscheine','title' => 'Lieferscheine','date_col' => 'datum'],
    'mahnungen'    => ['table' => 'mahnungen',    'title' => 'Mahnungen',    'date_col' => 'datum'],
    'clients'      => ['table' => 'clients',      'title' => 'Kunden',       'date_col' => 'created_at'],
];

if (!isset($allowed[$type])) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'invalid_type', 'allowed' => array_keys($allowed)], JSON_UNESCAPED_UNICODE);
    exit;
}

$cfg = $allowed[$type];
$table = $cfg['table'];
$title = $cfg['title'];
$dateCol = $cfg['date_col'];

// Introspect columns so export works with current schema
$colsStmt = $pdo->prepare("SHOW COLUMNS FROM `$table`");
$colsStmt->execute();
$cols = $colsStmt->fetchAll(PDO::FETCH_ASSOC);
$colNames = array_map(fn($c) => $c['Field'], $cols);

$where = [];
$params = [];

if ($q !== '') {
    $likeParts = [];
    $searchable = ['nummer','rechnungsnummer','kunde','name','firma','beschreibung','betreff','email'];
    foreach ($searchable as $cand) { if (in_array($cand, $colNames, true)) { $likeParts[] = "`$cand` LIKE :q"; } }
    if ($likeParts) { $where[] = '(' . implode(' OR ', $likeParts) . ')'; $params[':q'] = "%$q%"; }
}

if ($status !== '' && in_array('status', $colNames, true)) { $where[] = '`status` = :status'; $params[':status'] = $status; }

$validDateCol = in_array($dateCol, $colNames, true) ? $dateCol : (in_array('created_at', $colNames, true) ? 'created_at' : null);
if ($validDateCol) {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = "`$validDateCol` >= :from"; $params[':from'] = $from; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $where[] = "`$validDateCol` <= :to";   $params[':to'] = $to; }
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$allowedSort = array_fill_keys($colNames, true);
$sortCol = ($sort && isset($allowedSort[$sort])) ? $sort : ($validDateCol ?: $colNames[0]);

$selectCols = implode(', ', array_map(fn($c) => "`$c`", $colNames));
$sql = "SELECT $selectCols FROM `$table` $whereSql ORDER BY `$sortCol` $dir LIMIT :limit";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Build HTML for PDF
$today = date('d.m.Y H:i');
$subtitle = [];
if ($q !== '') $subtitle[] = 'Suche: ' . esc($q);
if ($status !== '') $subtitle[] = 'Status: ' . esc($status);
if ($from !== '') $subtitle[] = 'Von: ' . esc($from);
if ($to   !== '') $subtitle[] = 'Bis: ' . esc($to);
$subtitleText = $subtitle ? ('<div class="sub">' . implode(' · ', $subtitle) . '</div>') : '';

$style = '
    <style>
        body { font-family: DejaVu Sans, sans-serif; }
        .header { display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; }
        .title { font-size:20px; font-weight:700; color:#222; }
        .sub   { font-size:11px; color:#666; margin-top:2px; }
        table { width:100%; border-collapse:collapse; }
        th, td { border: 0.5px solid #ddd; padding:6px 8px; font-size:11px; }
        th { background:#f1f5f9; text-align:left; }
        tfoot td { font-weight:700; }
        .badge { display:inline-block; padding:2px 6px; border-radius:6px; font-size:10px; color:#fff; }
        .badge-green { background:#16a34a; }
        .badge-amber { background:#d97706; }
        .badge-gray { background:#6b7280; }
        .right { text-align:right; }
    </style>';

// Choose columns to show (up to ~8 for readability)
$preferred = ['nummer','rechnungsnummer','kunde','name','firma','datum','valid_until','status','betrag','summe','total','email'];
$displayCols = [];
foreach ($preferred as $p) { if (in_array($p, $colNames, true)) $displayCols[] = $p; }
if (!$displayCols) { $displayCols = array_slice($colNames, 0, min(8, count($colNames))); }

// Compute totals when numeric columns exist
$totals = [];
foreach (['betrag','summe','total','gesamt','netto','brutto','open_amount','paid_amount'] as $nc) {
    if (in_array($nc, $displayCols, true)) {
        $totals[$nc] = array_sum(array_map(fn($r) => (float)($r[$nc] ?? 0), $rows));
    }
}

$thead = '<tr>' . implode('', array_map(fn($c) => '<th>' . esc(ucfirst(str_replace('_',' ', $c))) . '</th>', $displayCols)) . '</tr>';
$tbody = '';
foreach ($rows as $r) {
    $cells = [];
    foreach ($displayCols as $c) {
        $val = $r[$c] ?? '';
        if ($c === 'status') {
            $cls = in_array(strtolower((string)$val), ['bezahlt','accepted','paid']) ? 'badge-green' : (in_array(strtolower((string)$val), ['offen','open','pending']) ? 'badge-amber' : 'badge-gray');
            $cells[] = '<td><span class="badge ' . $cls . '">' . esc((string)$val) . '</span></td>';
        } elseif (preg_match('/(betrag|summe|total|gesamt|netto|brutto|amount)$/i', $c)) {
            $cells[] = '<td class="right">' . number_format((float)$val, 2, ',', '.') . '</td>';
        } elseif (preg_match('/(datum|date|created_at|valid_until)$/i', $c) && $val) {
            $cells[] = '<td>' . esc(date('d.m.Y', strtotime((string)$val))) . '</td>';
        } else {
            $cells[] = '<td>' . esc((string)$val) . '</td>';
        }
    }
    $tbody .= '<tr>' . implode('', $cells) . '</tr>';
}

$tfoot = '';
if ($totals) {
    $footCells = [];
    foreach ($displayCols as $c) {
        if (isset($totals[$c])) { $footCells[] = '<td class="right">' . number_format($totals[$c], 2, ',', '.') . '</td>'; }
        else { $footCells[] = '<td></td>'; }
    }
    $tfoot = '<tfoot><tr>' . implode('', $footCells) . '</tr></tfoot>';
}

$html = $style.
    '<div class="header">'
    . '<div><div class="title">' . esc($title) . ' — Export</div>' . $subtitleText . '</div>'
    . '<div style="font-size:11px; color:#666;">Erstellt: ' . esc($today) . '</div>'
    . '</div>'
    . '<table><thead>' . $thead . '</thead><tbody>' . $tbody . '</tbody>' . $tfoot . '</table>';

// Configure mPDF with DejaVuSans available in project root
$defaultConfig = (new ConfigVariables())->getDefaults();
$fontDirs = $defaultConfig['fontDir'];
$defaultFontConfig = (new FontVariables())->getDefaults();
$fontData = $defaultFontConfig['fontdata'];

$mpdf = new Mpdf([
    'format' => 'A4',
    'margin_left' => 10,
    'margin_right' => 10,
    'margin_top' => 15,
    'margin_bottom' => 15,
    'fontDir' => array_merge($fontDirs, [realpath(__DIR__ . '/../../') ?: __DIR__ . '/../../']),
    'fontdata' => $fontData + [
        'dejavusans' => [
            'R' => 'DejaVuSans.php',
            'useOTL' => 0xFF,
            'useKashida' => 75,
        ],
    ],
    'default_font' => 'dejavusans',
]);

$filename = $title . '_Export_' . date('Ymd_His') . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . str_replace(' ', '_', $filename) . '"');

$mpdf->SetTitle($title . ' — Export');
$mpdf->WriteHTML($html);
$mpdf->Output($filename, 'I');
exit;
