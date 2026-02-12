<?php
// pages/api/files.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
session_start();
$root = dirname(__DIR__,1);
require_once $root . '/includes/helpers.php';
require_once $root . '/includes/auth.php';
global $pdo;
if (!check_remember_me($pdo)) { http_response_code(403); echo json_encode(['error'=>'unauthenticated']); exit; }

$folders = ['pdfs','data','files'];
$files = [];
foreach ($folders as $f) {
    $dir = realpath($root . DIRECTORY_SEPARATOR . $f);
    if (!$dir || !is_dir($dir)) continue;
    foreach (scandir($dir) as $fn) {
        if ($fn === '.' || $fn === '..') continue;
        $full = $dir . DIRECTORY_SEPARATOR . $fn;
        if (!is_file($full)) continue;
        $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf','csv','txt','xlsx','docx'])) continue;
        $files[] = [
            'name' => $fn,
            'size' => filesize($full),
            'mtime' => date('c', filemtime($full)),
            'url'  => '/'.$f.'/'.$fn
        ];
    }
}
echo json_encode($files);
