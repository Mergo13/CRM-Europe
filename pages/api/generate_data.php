<?php
// pages/api/generate_data.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
session_start();
$root = dirname(__DIR__,1);
require_once $root . '/includes/helpers.php';
require_once $root . '/includes/auth.php';
global $pdo;
if (!check_remember_me($pdo)) { http_response_code(403); echo json_encode(['error'=>'unauthenticated']); exit; }

// Try to produce series from DB; fallback to random sample
$labels = [];
$umsatz = [];
$mahnungen = [];
for ($i=6;$i>=1;$i--) {
    $labels[] = date('M', strtotime("-{$i} months"));
    $umsatz[] = rand(800, 4500);
    $mahnungen[] = rand(0,8);
}
echo json_encode(['labels'=>$labels,'umsatz'=>$umsatz,'mahnungen'=>$mahnungen]);