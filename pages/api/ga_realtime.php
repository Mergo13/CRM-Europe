<?php
// ga_realtime.php — safe JSON endpoint for dashboard GA Live widget
// Replace the placeholder fetch-with-GA logic with your actual implementation.
// Important: make sure this file is saved WITHOUT a UTF-8 BOM and no output (whitespace) before <?php

// Basic configuration
date_default_timezone_set('Europe/Vienna');
error_reporting(0); // do not emit warnings/notices into JSON output in production

// Optional: enable CORS for testing (adjust or remove in production)
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');
$GLOBALS['PDF_UNICODE'] = true;
// Start output buffering to catch / remove accidental output (warnings, BOM, etc.)
ob_start();

try {
    // ---------- Example: read a cached file (optional) ----------
    $cacheFile = sys_get_temp_dir() . '/ga_realtime_cache.json';
    $cacheTtl = 10; // seconds

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTtl)) {
        $payload = json_decode(file_get_contents($cacheFile), true);
        if (is_array($payload)) {
            ob_end_clean();
            echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_NUMERIC_CHECK);
            exit;
        }
    }

    // ---------- TODO: Replace this block with your real GA realtime fetching code ----------
    // For testing, here's sample data shaped the dashboard expects.
    $payload = [
        'totalActive' => 0,
        'pageviewsPerMinute' => 0,
        'pages' => [],
        'timestamp' => gmdate('c')
    ];

    // ---------- Save to cache (best-effort) ----------
    @file_put_contents($cacheFile, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_NUMERIC_CHECK));

    // Clean any buffered output (warnings / stray content) and output clean JSON
    ob_end_clean();
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_NUMERIC_CHECK);
    exit;

} catch (Throwable $e) {
    ob_end_clean();
    $err = [
        'error' => 'internal',
        'message' => 'Failed to fetch GA data',
        'timestamp' => gmdate('c')
    ];
    http_response_code(500);
    echo json_encode($err, JSON_UNESCAPED_SLASHES);
    exit;
}
