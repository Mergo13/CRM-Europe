<?php
// scripts/run_mahnung_save.php
// Helper to invoke pages/mahnung_speichern.php from CLI by injecting $_POST via -r include.
// Usage: php scripts/run_mahnung_save.php <RECHNUNGSNUMMER> <STUFE 0-3> <DUE_DAYS>

if (php_sapi_name() !== 'cli') {
    http_response_code(405);
    echo "CLI only";
    exit(1);
}

$rn = $argv[1] ?? '';
$stufe = isset($argv[2]) ? (int)$argv[2] : 0;
$due = isset($argv[3]) ? (int)$argv[3] : 7;
if ($rn === '') {
    fwrite(STDERR, "Missing Rechnungsnummer\n");
    exit(2);
}

$target = realpath(__DIR__ . '/../pages/mahnung_speichern.php');
if (!$target || !is_file($target)) {
    fwrite(STDERR, "mahnung_speichern.php not found\n");
    exit(3);
}

// Build inline PHP to set $_POST and include the target script
$post = var_export(['rechnungsnummer'=>$rn, 'stufe'=>$stufe, 'due_days'=>$due, 'auto_cron'=>'1', 'send_now'=>'0'], true);
$code = '$_POST = ' . $post . '; include ' . var_export($target, true) . ';';

$cmd = escapeshellcmd(PHP_BINARY) . ' -d detect_unicode=0 -r ' . escapeshellarg($code);
exec($cmd, $out, $code);

echo implode("\n", (array)$out);
exit($code);
