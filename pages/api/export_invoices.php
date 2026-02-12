<?php
// pages/api/export_invoices.php - simple CSV export stub for ids param
$ids = $_GET['ids'] ?? '';
$idsArr = array_filter(array_map('trim', explode(',', $ids)));
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="invoices_export.csv"');
$out = fopen('php://output','w');
fputcsv($out, ['id','number','amount','status']);
foreach ($idsArr as $id) {
    fputcsv($out, [$id, "2025-{$id}", rand(100,2000), 'bezahlt']);
}
fclose($out);
