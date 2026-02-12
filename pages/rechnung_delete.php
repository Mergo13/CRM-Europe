<?php

// pages/rechnung_delete.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require '../config/db.php';
global $pdo;
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['id'])) {
    echo json_encode(['success' => 0, 'error' => 'Invalid request']);
    exit;
}

$id = (int)$_POST['id'];

try {
    // optional: delete invoice positions first
    $pdo->prepare("DELETE FROM rechnungs_positionen WHERE rechnung_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM rechnungen WHERE id = ?")->execute([$id]);

    // delete PDF if exists
    $pdfPath = __DIR__ . '/../pdf/rechnung_' . $id . '.pdf';
    if (file_exists($pdfPath)) unlink($pdfPath);

    echo json_encode(['success' => 1]);
} catch (Exception $e) {
    echo json_encode(['success' => 0, 'error' => $e->getMessage()]);
}
