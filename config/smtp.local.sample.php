<?php
// config/smtp.local.sample.php
// Copy to config/smtp.local.php and fill in your real credentials.
// This file is intended to be git-ignored. It returns a plain PHP array.

return [
    // SMTP server hostname
    'host' => 'smtp.world4you.com',

    // Authentication
    'user' => 'office@vision-lt.at',
    'pass' => '13Mergim@',

    // Encryption: 'tls' (STARTTLS) or 'ssl' (SMTPS)
    'secure' => 'tls',

    // Port: 587 for STARTTLS, 465 for SMTPS (SSL)
    'port' => 587,

    // Optional default From address and name used by the app
    'from' => [
        'address' => 'office@vision-lt.at',
        'name'    => 'VISION L&T'
    ],
];
