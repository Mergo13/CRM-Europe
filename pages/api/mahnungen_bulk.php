<?php
// pages/api/mahnungen_bulk.php
// Bulk-create Mahnungen (or Zahlungserinnerungen) for selected Rechnungen.
// Accepts JSON: { rechnung_ids: [1,2,...], stufe?: 0|1|2|3, send_now?: 0|1 }
// For each id, will resolve rechnungsnummer and POST to pages/mahnung_speichern.php.

require_once __DIR__ . '/../../config/db.php';
header('Content-Type: application/json; charset=utf-8');
if (isset($GLOBALS['pdo'])) { $pdo = $GLOBALS['pdo']; }
// Increase script time limit to accommodate multiple items, but keep it bounded
@set_time_limit(120);

try {
    $raw = file_get_contents('php://input');
    $data = [];
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if ($raw && stripos($ct, 'application/json') !== false) {
        $data = json_decode($raw, true) ?: [];
    }
    // Also support form submission
    if (empty($data)) { $data = $_POST; }

    $action = isset($data['action']) ? (string)$data['action'] : '';

    $ids = $data['rechnung_ids'] ?? $data['ids'] ?? [];
    if (!is_array($ids)) {
        if (is_string($ids)) $ids = array_filter(array_map('trim', explode(',', $ids)), 'strlen'); else $ids = [];
    }
    $ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));

    if (!$ids) { http_response_code(400); echo json_encode(['success'=>false,'error'=>'missing_ids']); exit; }

    // ✅ NEW: delete selected Mahnungen
    if ($action === 'delete') {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("DELETE FROM mahnungen WHERE id IN ($placeholders)");
        $stmt->execute($ids);

        $deleted = (int)$stmt->rowCount();
        echo json_encode([
            'success' => true,
            'action' => 'delete',
            'requested' => count($ids),
            'deleted' => $deleted,
        ]);
        exit;
    }

    $stufe = isset($data['stufe']) ? (int)$data['stufe'] : 0; // default Zahlungserinnerung
    $send_now = !empty($data['send_now']) ? '1' : '0';

    // Fetch rechnungsnummern
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, rechnungsnummer FROM rechnungen WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $byId = [];
    foreach ($rows as $r) { $byId[(int)$r['id']] = $r['rechnungsnummer']; }

    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $baseUrl = $host ? ($scheme . '://' . $host) : '';
    $endpoint = $baseUrl ? ($baseUrl . '/pages/mahnung_speichern.php') : null;
    // Reduce network wait globally for this request
    @ini_set('default_socket_timeout', '5');

    $results = [];
    foreach ($ids as $id) {
        $rn = $byId[$id] ?? null;
        if (!$rn) { $results[] = ['id'=>$id,'ok'=>false,'error'=>'no_rechnungsnummer']; continue; }
        $post = http_build_query(['rechnungsnummer'=>$rn, 'stufe'=>$stufe, 'due_days'=>7, 'send_now'=>$send_now]);
        $ok = false; $resp = null;

        // Prefer fast local CLI to avoid web server round-trips/timeouts
        $cmd = escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../scripts/run_mahnung_save.php') . ' ' . escapeshellarg($rn) . ' ' . (int)$stufe . ' 7';
        @exec($cmd, $outLines, $code);
        if (isset($outLines) && is_array($outLines) && count($outLines) > 1000) { $outLines = array_slice($outLines, -1000); }
        $ok = ($code === 0);
        $resp = implode("\n", (array)$outLines);

        // Fallback to HTTP with a short timeout if CLI failed
        if (!$ok && $endpoint) {
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                    'content' => $post,
                    'timeout' => 5
                ]
            ]);
            $respHttp = @file_get_contents($endpoint, false, $ctx);
            $ok = ($respHttp !== false);
            if ($ok) { $resp = $respHttp; }
        }

        $results[] = ['id'=>$id,'ok'=>$ok,'response'=>$resp];
    }

    $okCount = count(array_filter($results, fn($r) => !empty($r['ok'])));
    echo json_encode(['success'=> true, 'processed'=> count($results), 'ok'=> $okCount, 'results'=>$results]);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>'server','message'=>$e->getMessage()]);
    exit;
}