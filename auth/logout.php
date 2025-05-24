<?php
require_once '../config/database.php';
require_once '../config/security.php';
session_start();

// Log the logout
$userId = $_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? 'unknown';
file_put_contents(__DIR__ . '/../logs/auth.log', 
    "[" . date('Y-m-d H:i:s') . "] Logout for user ID: $userId\n", 
    FILE_APPEND
);

// Clear all session variables
$_SESSION = array();

// Destroy the session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time()-3600, '/');
}

// Destroy the session
session_destroy();

// Clear JWT cookie if it exists
setcookie('jwt', '', time()-3600, '/', '', true, true);

// Invalidate JWT in session storage if you're using it
if (isset($_SESSION['jwt'])) {
    unset($_SESSION['jwt']);
}

// Redirect to home page
header('Location: /');
exit;
?>