<?php
// pages/api/smtp_diagnostics.php
// Lightweight SMTP connectivity/authentication diagnostics.
// Returns JSON with details. Enable by setting APP_DEBUG=1 (environment variable) or pass ?key=... if DIAG_KEY env set.

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function allow_diag(): bool {
    $debug = getenv('APP_DEBUG') === '1';
    $envKey = getenv('DIAG_KEY');
    if ($debug) return true;
    if ($envKey && isset($_GET['key']) && hash_equals($envKey, (string)$_GET['key'])) return true;
    return false;
}

if (!allow_diag()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'forbidden', 'hint' => 'Enable APP_DEBUG=1 or provide ?key=...']);
    exit;
}

try {
    $smtpPath = __DIR__ . '/../../config/smtp.php';
    if (!file_exists($smtpPath)) {
        throw new RuntimeException('smtp_config_missing');
    }
    require_once $smtpPath; // defines getMailer()
    if (!function_exists('getMailer')) {
        throw new RuntimeException('getMailer_missing');
    }

    $mail = getMailer();

    // Capture SMTP debug transcript
    $transcript = '';
    $mail->SMTPDebug = 2;
    $mail->Debugoutput = function($str, $level) use (&$transcript) {
        $transcript .= '[' . $level . '] ' . $str . "\n";
    };

    $ok = false;
    $error = null;
    try {
        // Try to connect and authenticate; PHPMailer will authenticate on send(), but we can do it explicitly
        if (method_exists($mail, 'smtpConnect')) {
            $ok = $mail->smtpConnect();
            if (!$ok) {
                $error = 'smtp_connect_failed';
            }
        } else {
            // Fallback: attempt to send a NOOP by sending an empty message to trigger connect.
            $ok = $mail->preSend();
        }
    } catch (Throwable $e) {
        $ok = false;
        $error = $e->getMessage();
    } finally {
        if (method_exists($mail, 'smtpClose')) {
            $mail->smtpClose();
        }
    }

    echo json_encode([
        'success' => (bool)$ok,
        'error'   => $ok ? null : ($error ?: 'unknown_error'),
        'details' => [
            'host'   => $mail->Host,
            'port'   => $mail->Port,
            'secure' => $mail->SMTPSecure,
            'from'   => $mail->From,
            'fromName' => $mail->FromName,
        ],
        'transcript' => $transcript,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server', 'detail' => $e->getMessage()]);
}
