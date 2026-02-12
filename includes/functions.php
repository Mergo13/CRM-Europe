<?php
// includes/functions.php

/**
 * Returns the currently logged-in user data as an associative array.
 */
function current_user(PDO $pdo): ?array {
    if (!isset($_SESSION)) {
        session_start();
    }

    // If user not logged in, return null
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    // Fetch user from DB
    $stmt = $pdo->prepare('SELECT id, username, email, role FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    return $user ?: null;
}
