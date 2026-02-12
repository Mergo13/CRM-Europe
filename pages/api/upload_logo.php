<?php
// pages/api/upload_logo.php
// Securely handle company logo uploads. Always stores into /assets and returns the web path.
// Allowed types: PNG, JPG/JPEG, SVG. Max size: 2 MB.

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new RuntimeException('Ungültige Anfragemethode.');
    }

    if (!isset($_FILES['logo']) || !is_array($_FILES['logo'])) {
        throw new RuntimeException('Keine Datei übermittelt.');
    }

    $file = $_FILES['logo'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $errMap = [
            UPLOAD_ERR_INI_SIZE => 'Datei überschreitet die Servergrößenbeschränkung.',
            UPLOAD_ERR_FORM_SIZE => 'Datei ist zu groß.',
            UPLOAD_ERR_PARTIAL => 'Datei wurde nur teilweise hochgeladen.',
            UPLOAD_ERR_NO_FILE => 'Keine Datei ausgewählt.',
            UPLOAD_ERR_NO_TMP_DIR => 'Temporärer Ordner fehlt.',
            UPLOAD_ERR_CANT_WRITE => 'Fehler beim Speichern der Datei.',
            UPLOAD_ERR_EXTENSION => 'Upload durch Erweiterung gestoppt.',
        ];
        $code = (int)$file['error'];
        $msg = $errMap[$code] ?? ('Upload-Fehler (Code ' . $code . ').');
        throw new RuntimeException($msg);
    }

    $maxBytes = 2 * 1024 * 1024; // 2 MB
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) {
        throw new RuntimeException('Leere Datei.');
    }
    if ($size > $maxBytes) {
        throw new RuntimeException('Die Datei ist zu groß (max. 2 MB).');
    }

    $origName = (string)($file['name'] ?? 'logo');
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

    $allowedExt = ['png','jpg','jpeg','svg'];
    if (!in_array($ext, $allowedExt, true)) {
        throw new RuntimeException('Nur PNG, JPG oder SVG sind erlaubt.');
    }

    // Detect MIME if possible
    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
    $mime = $finfo ? (string)finfo_file($finfo, $file['tmp_name']) : '';
    if ($finfo) { finfo_close($finfo); }

    $allowedMime = ['image/png','image/jpeg','image/svg+xml'];
    if ($mime && !in_array($mime, $allowedMime, true)) {
        // Some servers may return text/plain for svg; allow by extension check as fallback
        if (!($ext === 'svg' && str_contains($mime, 'text'))) {
            throw new RuntimeException('Ungültiger Dateityp: ' . $mime);
        }
    }

    // Additional basic SVG sanity check to reduce risk of scripts
    if ($ext === 'svg') {
        $svgContent = (string)file_get_contents($file['tmp_name']);
        // Block if <script ...> present
        if (preg_match('~<\s*script\b~i', $svgContent)) {
            throw new RuntimeException('Unsicherer SVG-Inhalt (Skripte sind nicht erlaubt).');
        }
    }

    // Sanitize filename (keep base, no path)
    $base = pathinfo($origName, PATHINFO_FILENAME);
    $base = strtolower(trim($base));
    $base = preg_replace('~[^a-z0-9-_]+~', '-', $base ?? '') ?? 'logo';
    $base = trim($base, '-_');
    if ($base === '') { $base = 'logo'; }

    // Ensure destination directory is /assets
    $assetsDir = realpath(__DIR__ . '/../../assets');
    if ($assetsDir === false) {
        // attempt to create if missing
        $assetsDir = __DIR__ . '/../../assets';
        if (!is_dir($assetsDir)) {
            if (!@mkdir($assetsDir, 0775, true)) {
                throw new RuntimeException('Zielverzeichnis /assets konnte nicht erstellt werden.');
            }
        }
        $assetsDir = realpath($assetsDir);
    }
    if ($assetsDir === false) {
        throw new RuntimeException('Zielverzeichnis /assets nicht verfügbar.');
    }

    // Build target filename and avoid collisions
    $filename = $base . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
    $destPath = $assetsDir . DIRECTORY_SEPARATOR . $filename;

    if (file_exists($destPath)) {
        $ts = date('Ymd_His');
        $filename = $base . '_' . $ts . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
        $destPath = $assetsDir . DIRECTORY_SEPARATOR . $filename;
        // still collision safety
        $i = 2;
        while (file_exists($destPath) && $i < 50) {
            $filename = $base . '_' . $ts . '_' . $i . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
            $destPath = $assetsDir . DIRECTORY_SEPARATOR . $filename;
            $i++;
        }
    }

    // Move uploaded file
    if (!is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Ungültige Upload-Quelle.');
    }

    if (!@move_uploaded_file($file['tmp_name'], $destPath)) {
        throw new RuntimeException('Konnte Datei nicht nach /assets verschieben.');
    }

    // Build web path (always /assets/{filename})
    $webPath = '/assets/' . $filename;

    echo json_encode([
        'success' => true,
        'web' => $webPath,
        'filename' => $filename,
    ], JSON_UNESCAPED_UNICODE);
    exit;

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}
