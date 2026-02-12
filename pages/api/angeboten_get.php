<?php
// pages/api/angeboten_get.php
// Return a single Angebot (offer) as JSON for view/details actions
// Expects: GET id

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = require __DIR__ . '/../../config/db.php';
    if (!($pdo instanceof PDO)) {
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            $pdo = $GLOBALS['pdo'];
        } else {
            throw new RuntimeException('Database not available');
        }
    }

    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => 0, 'error' => 'invalid_id']);
        exit;
    }

    // Build query: angebote + client join
    $sql = "SELECT t.*, c.name AS kunde, c.email AS kunde_email
            FROM angebote t
            LEFT JOIN clients c ON c.id = t.client_id
            WHERE t.id = :id
            LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => 0, 'error' => 'not_found']);
        exit;
    }

    // Provide UI-friendly aliases expected by frontend
    $row['nummer'] = $row['angebotsnummer'] ?? ($row['nummer'] ?? null);
    if (isset($row['gueltig_bis'])) {
        $row['valid_until'] = $row['gueltig_bis'];
    }
    if (isset($row['kunde'])) {
        $row['kunde_name'] = $row['kunde'];
    }
    if (isset($row['kunde_email'])) {
        $row['kunde_email'] = $row['kunde_email'];
    }

    // Prefer pdf_path; also expose a generic file_url key some UIs use
    if (!empty($row['pdf_path'])) {
        $row['file_url'] = $row['pdf_path'];
    }

    echo json_encode([
        'success' => 1,
        'id'      => (int)$row['id'],
        'data'    => $row,
        // Backwards-compatible top-level shortcuts
        'pdf_path'=> $row['pdf_path'] ?? null,
        'file_url'=> $row['file_url'] ?? ($row['pdf_path'] ?? null),
        'nummer'  => $row['nummer'] ?? null,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => 0,
        'error'   => 'server_error',
        'message' => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ]);
}
