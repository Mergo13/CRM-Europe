<?php
// pages/api/migrate_tax_fields.php — Idempotent migration to add VAT/VIES and tax columns
// Run once manually: /pages/api/migrate_tax_fields.php

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/../../config/db.php';

$pdo = $GLOBALS['pdo'] ?? (isset($pdo) ? $pdo : null);
if (!($pdo instanceof PDO)) {
    http_response_code(500);
    echo "No DB connection\n";
    exit;
}

function col_exists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return false; }
}

function add_col(PDO $pdo, string $table, string $ddl): void {
    try { $pdo->exec("ALTER TABLE `$table` ADD COLUMN $ddl"); } catch (Throwable $e) {}
}

try {
    // Clients: cache of VIES
    if (col_exists($pdo, 'clients', 'id')) {
        if (!col_exists($pdo, 'clients', 'country_code')) add_col($pdo, 'clients', "country_code VARCHAR(2) NULL AFTER ort");
        if (!col_exists($pdo, 'clients', 'vat_id')) add_col($pdo, 'clients', "vat_id VARCHAR(32) NULL AFTER country_code");
        if (!col_exists($pdo, 'clients', 'vat_validation_status')) add_col($pdo, 'clients', "vat_validation_status VARCHAR(16) NULL AFTER vat_id");
        if (!col_exists($pdo, 'clients', 'vat_validated_at')) add_col($pdo, 'clients', "vat_validated_at DATETIME NULL AFTER vat_validation_status");
        if (!col_exists($pdo, 'clients', 'vat_name')) add_col($pdo, 'clients', "vat_name VARCHAR(255) NULL AFTER vat_validated_at");
        if (!col_exists($pdo, 'clients', 'vat_address')) add_col($pdo, 'clients', "vat_address VARCHAR(255) NULL AFTER vat_name");
    }

    // Documents: totals + tax mode
    foreach (['rechnungen','angebote'] as $tbl) {
        if (col_exists($pdo, $tbl, 'id')) {
            if (!col_exists($pdo, $tbl, 'tax_mode')) add_col($pdo, $tbl, "tax_mode VARCHAR(32) NULL AFTER status");
            if (!col_exists($pdo, $tbl, 'tax_mode_source')) add_col($pdo, $tbl, "tax_mode_source VARCHAR(16) NULL AFTER tax_mode");
            if (!col_exists($pdo, $tbl, 'net_total')) add_col($pdo, $tbl, "net_total DECIMAL(10,2) NULL AFTER pdf_path");
            if (!col_exists($pdo, $tbl, 'vat_total')) add_col($pdo, $tbl, "vat_total DECIMAL(10,2) NULL AFTER net_total");
            if (!col_exists($pdo, $tbl, 'gross_total')) add_col($pdo, $tbl, "gross_total DECIMAL(10,2) NULL AFTER vat_total");
            if (!col_exists($pdo, $tbl, 'legal_text_key')) add_col($pdo, $tbl, "legal_text_key VARCHAR(64) NULL AFTER gross_total");
        }
    }

    echo "Migration done.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "Migration error: " . $e->getMessage() . "\n";
}
