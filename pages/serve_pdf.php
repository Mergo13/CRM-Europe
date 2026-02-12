<?php
// file: pages/serve_pdf.php
$baseDir = realpath(__DIR__ . '/../pdf');
if ($baseDir === false) {
    http_response_code(500);
    echo "PDF directory not found.";
    exit;
}
$fn = $_GET['file'] ?? '';
if ($fn === '') {
    http_response_code(400);
    echo "Missing file parameter.";
    exit;
}
// allow only safe filename characters (no directory traversal)
if (preg_match('/[^A-Za-z0-9._-]/', $fn)) {
    http_response_code(400);
    echo "Invalid file name.";
    exit;
}

// Try root pdf dir first
$candidates = [];
$candidates[] = $baseDir . DIRECTORY_SEPARATOR . $fn;
// Also try known subdirectories (e.g., mahnungen)
$knownSubdirs = ['mahnungen', 'rechnungen', 'angebote', 'lieferscheine'];
foreach ($knownSubdirs as $sub) {
    $candidates[] = $baseDir . DIRECTORY_SEPARATOR . $sub . DIRECTORY_SEPARATOR . $fn;
}

$real = false;
foreach ($candidates as $full) {
    $realCandidate = realpath($full);
    if ($realCandidate !== false && strpos($realCandidate, $baseDir) === 0 && is_file($realCandidate) && is_readable($realCandidate)) {
        $real = $realCandidate;
        break;
    }
}

if ($real === false) {
    http_response_code(404);
    echo "File not found.";
    exit;
}

$filesize = filesize($real);
header('Content-Type: application/pdf');
header('Content-Length: ' . $filesize);
header('Content-Disposition: inline; filename="' . basename($real) . '"');
header('Cache-Control: private, max-age=86400');
readfile($real);
exit;
