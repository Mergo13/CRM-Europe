<?php
// pages/api/search.php - simple demo search stub
header('Content-Type: application/json');
$q = $_GET['q'] ?? '';
if ($q === '') {
  echo json_encode(['results'=>[]]);
  exit;
}
// demo results
$results = [
  ['title'=>"Rechnung 95 — Muster", 'url'=>'/pages/rechnung.php?id=95'],
  ['title'=>"Angebot 34 — ACME", 'url'=>'/pages/angebot.php?id=34']
];
echo json_encode(['results'=>$results]);
