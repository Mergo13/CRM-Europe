<?php
// pages/convert_angebot_to_rechnung.php — Convert Angebot(e) to Rechnung(en) and optionally generate/open invoice PDFs
// Supports:
// - GET ?id=123[&open_pdf=1]: convert single Angebot, generate invoice PDF, and serve it inline when open_pdf=1; otherwise show a small HTML message
// - POST JSON: { ids: [1,2,...], open_pdf: 0|1, generate_pdf: 0|1 } → returns JSON summary

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers.php';
// Ensure FPDF can find DejaVuSans font definition located at project root
if (!defined('FPDF_FONTPATH')) {
    define('FPDF_FONTPATH', __DIR__ . '/../'); // DejaVuSans.php and .z are in project root
}
require_once __DIR__ . '/../vendor/setasign/fpdf/fpdf.php';

// Ensure PDO
$pdo = $GLOBALS['pdo'] ?? (isset($pdo) ? $pdo : null);
if (!($pdo instanceof PDO)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'db_bootstrap']);
    exit;
}

function json_response(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Helper: return the standard generator URL for invoice PDF (consistent design)
function generate_invoice_pdf(PDO $pdo, int $rechnungId): array {
    // Ensure invoice exists
    $stmt = $pdo->prepare('SELECT id, datum FROM rechnungen WHERE id = ?');
    $stmt->execute([$rechnungId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r) { throw new RuntimeException('invoice_not_found'); }

    // We delegate PDF creation/serving to the shared generator to keep header/footer/body consistent
    $generatorUrl = '/pages/rechnung_pdf.php?id=' . urlencode((string)$rechnungId) . '&force=1';

    // Optionally prefill pdf_path to legacy field with the canonical web path
    try {
        $webPath = pdf_web_path('rechnung', $rechnungId, '', (string)($r['datum'] ?? date('Y-m-d')));
        $st = $pdo->prepare('UPDATE rechnungen SET pdf_path = ? WHERE id = ?');
        $st->execute([$webPath, $rechnungId]);
    } catch (Throwable $e) {}

    // We don't generate the file here; return [null, generatorUrl]
    return [null, $generatorUrl];
}

// Parse inputs: support GET single, or POST (JSON or form) bulk
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$ct = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';

$ids = [];
$openPdf = false;
$generatePdf = false;

if ($method === 'POST') {
    if (stripos($ct, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $json = json_decode($raw, true) ?: [];
        if (isset($json['ids']) && is_array($json['ids'])) {
            $ids = array_values(array_filter(array_map('intval', $json['ids']), fn($v)=>$v>0));
        }
        if (isset($json['id']) && is_numeric($json['id'])) { $ids[] = (int)$json['id']; }
        $openPdf = !empty($json['open_pdf']);
        $generatePdf = !empty($json['generate_pdf']) || $openPdf;
    } else {
        // form-encoded
        if (!empty($_POST['ids'])) {
            $ids = is_array($_POST['ids']) ? array_map('intval', $_POST['ids']) : array_map('intval', explode(',', (string)$_POST['ids']));
            $ids = array_values(array_filter($ids, fn($v)=>$v>0));
        } elseif (!empty($_POST['id']) && is_numeric($_POST['id'])) {
            $ids = [(int)$_POST['id']];
        }
        $openPdf = !empty($_POST['open_pdf']);
        $generatePdf = !empty($_POST['generate_pdf']) || $openPdf;
    }
} else {
    // GET
    if (isset($_GET['id']) && is_numeric($_GET['id'])) { $ids = [(int)$_GET['id']]; }
    $openPdf = !empty($_GET['open_pdf']);
    $generatePdf = !empty($_GET['generate_pdf']) || $openPdf;
}

if (empty($ids)) {
    if ($method === 'POST') { json_response(['success'=>false,'error'=>'missing_ids'], 400); }
    http_response_code(400); echo 'Missing Angebot id'; exit;
}

// Convert each angebot
$results = [];
try {
    foreach ($ids as $angebot_id) {
        // Load angebot
        $stmt = $pdo->prepare('SELECT * FROM angebote WHERE id = ?');
        $stmt->execute([$angebot_id]);
        $angebot = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$angebot) {
            $results[] = ['angebot_id'=>$angebot_id, 'success'=>false, 'error'=>'angebot_not_found'];
            continue;
        }

        // If already accepted, we still allow creating an invoice if missing (idempotent-ish)
        $client_id = (int)$angebot['client_id'];
        $datum = $angebot['datum'] ?: date('Y-m-d');
        $betrag = (float)($angebot['betrag'] ?? 0);
        $faelligkeit = date('Y-m-d', strtotime($datum . ' +14 days'));

        // Create invoice number: YEAR + 4-digit counter
        $year = date('Y', strtotime($datum));
        $stmt = $pdo->prepare('SELECT COUNT(*) as cnt FROM rechnungen WHERE YEAR(datum)=?');
        $stmt->execute([$year]);
        $cnt = (int)$stmt->fetchColumn() + 1;
        $rechnungsnummer = $year . str_pad((string)$cnt, 4, '0', STR_PAD_LEFT);

        // Insert invoice header
        $stmt = $pdo->prepare('INSERT INTO rechnungen (client_id, rechnungsnummer, datum, betrag, faelligkeit, status, mahn_stufe, pdf_path) VALUES (?,?,?,?,?,' . "'offen'" . ',0,?)');
        $stmt->execute([$client_id, $rechnungsnummer, $datum, number_format($betrag, 2, '.', ''), $faelligkeit, $angebot['pdf_path'] ?? '']);
        $rechnung_id = (int)$pdo->lastInsertId();
        // Normalize totals: write header total into gesamt and mirror betrag for compatibility (no schema changes)
        try {
            $u = $pdo->prepare('UPDATE rechnungen SET gesamt = ?, betrag = ? WHERE id = ?');
            $u->execute([number_format($betrag, 2, '.', ''), number_format($betrag, 2, '.', ''), $rechnung_id]);
        } catch (Throwable $e) {
            // If gesamt column missing, just ensure betrag is set
            try { $pdo->prepare('UPDATE rechnungen SET betrag = ? WHERE id = ?')->execute([number_format($betrag, 2, '.', ''), $rechnung_id]); } catch (Throwable $e2) {}
        }

        // Mark angebot accepted
        try {
            $stmt = $pdo->prepare("UPDATE angebote SET status='angenommen' WHERE id=?");
            $stmt->execute([$angebot_id]);
        } catch (Throwable $e) {}

        $entry = [
            'angebot_id' => $angebot_id,
            'rechnung_id' => $rechnung_id,
            'rechnungsnummer' => $rechnungsnummer,
            'pdf_url' => null,
        ];

        if ($generatePdf) {
            try {
                [$filePath, $webPath] = generate_invoice_pdf($pdo, $rechnung_id);
                $entry['pdf_url'] = $webPath;
            } catch (Throwable $e) {
                $entry['pdf_error'] = 'pdf_failed';
            }
        }

        $results[] = ['success'=>true] + $entry;
    }
} catch (Throwable $e) {
    if ($method === 'POST') { json_response(['success'=>false, 'error'=>'convert_failed', 'message'=>$e->getMessage()], 500); }
    http_response_code(500);
    echo 'Conversion failed';
    exit;
}

// Single GET with open_pdf → redirect to the standard generator for consistent PDF layout
if ($method === 'GET' && count($results) === 1 && $openPdf) {
    $rid = (int)($results[0]['rechnung_id'] ?? 0);
    if ($rid > 0) {
        $pdfUrl = '/pages/rechnung_pdf.php?id=' . urlencode((string)$rid) . '&force=1';
        header('Location: ' . $pdfUrl);
        exit;
    }
}

// For GET without open_pdf, show a simple HTML confirmation
if ($method === 'GET') {
    $first = $results[0] ?? [];
    $msg = 'Konvertierung abgeschlossen.';
    if (!empty($first['rechnungsnummer'])) {
        $msg = 'Angebot #' . htmlspecialchars((string)($first['angebot_id'] ?? ''), ENT_QUOTES) . ' → Rechnung #' . htmlspecialchars((string)$first['rechnungsnummer'], ENT_QUOTES);
        if (!empty($first['pdf_url'])) {
            $msg .= ' — <a href="' . htmlspecialchars((string)$first['pdf_url'], ENT_QUOTES) . '" target="_blank">PDF öffnen</a>';
        }
    }
    echo '<!doctype html><meta charset="utf-8"><title>Konvertiert</title><div style="padding:16px;font-family:sans-serif">' . $msg . '</div>';
    exit;
}

// Otherwise, return JSON
json_response(['success'=>true, 'converted'=>$results]);
