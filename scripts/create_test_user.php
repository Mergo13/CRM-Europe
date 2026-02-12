<?php
// scripts/create_test_user.php
global $pdo;
require_once __DIR__ . '../includes/init.php'; // adjust path if you placed scripts elsewhere

if (!has_pdo()) {
    echo "No PDO available\n";
    exit(1);
}

$username = 'tester';
$email = 'tester@example.com';

try {
    // Insert or ignore if already exists
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO users (username, email) VALUES (?, ?)');
    $stmt->execute([$username, $email]);

    // fetch id
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    $id = $row['id'] ?? null;

    echo "User created/ensured: id={$id}, username={$username}\n";
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
