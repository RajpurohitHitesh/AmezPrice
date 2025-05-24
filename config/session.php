<?php
// config/session.php
function startApplicationSession() {
    if (session_status() === PHP_SESSION_NONE) {
        $isSecure = isset($_SERVER['HTTPS']) || $_SERVER['SERVER_NAME'] === 'www.amezprice.com' || $_SERVER['SERVER_NAME'] === 'localhost';
        $sessionOptions = [
        'name' => 'AMEZPRICE_SESSID',
        'cookie_lifetime' => 86400,
        'cookie_path' => '/',
        'cookie_domain' => $_SERVER['HTTP_HOST'] ?? '',
        'cookie_secure' => true,
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true
        ];
        session_start($sessionOptions);
        // Log session initialization
        file_put_contents(__DIR__ . '/../logs/auth.log', "[" . date('Y-m-d H:i:s') . "] Session started: " . session_id() . ", Secure: " . ($isSecure ? 'yes' : 'no') . "\n", FILE_APPEND);
        // Regenerate session ID to prevent fixation
        if (!isset($_SESSION['initialized'])) {
            session_regenerate_id(true);
            $_SESSION['initialized'] = true;
            file_put_contents(__DIR__ . '/../logs/auth.log', "[" . date('Y-m-d H:i:s') . "] Session ID regenerated: " . session_id() . "\n", FILE_APPEND);
        }
    }
}
?>