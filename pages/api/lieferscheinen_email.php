<?php
// Shim: route to unified sender using PHPMailer via config/smtp.php
declare(strict_types=1);

// Force doc_type for both form and JSON requests
if (!isset($_POST['doc_type']) && !isset($_GET['doc_type'])) {
    $_POST['doc_type'] = 'lieferschein';
}

require __DIR__ . '/send_document_email.php';
