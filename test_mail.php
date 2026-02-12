<?php

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/config/smtp.php';

use Dotenv\Dotenv;

Dotenv::createImmutable(__DIR__)->load();

$mail = getMailer();
$mail->addAddress('kunde@example.com');
$mail->Subject = 'Mailpit Test';
$mail->Body = "Hallo,\n\nMailpit funktioniert.\n\nLG";
$mail->send();

echo "Mail sent\n";
