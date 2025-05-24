<?php
// config/session.php
function startApplicationSession() {
    if (session_status() === PHP_SESSION_NONE) {
        $isSecure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
                   (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        
        $sessionOptions = [
            'name' => 'AMEZPRICE_SESSID',
            'cookie_lifetime' => 86400,
            'cookie_path' => '/',
            'cookie_domain' => '', // Leave empty for compatibility
            'cookie_secure' => $isSecure,
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
            'use_strict_mode' => true
        ];
        
        session_start($sessionOptions);
        
        // Log session initialization
        file_put_contents(__DIR__ . '/../logs/auth.log', "[" . date('Y-m-d H:i:s') . "] Session started: " . session_id() . ", Secure: " . ($isSecure ? 'yes' : 'no') . "\n", FILE_APPEND);
        
        // Only regenerate session ID for completely new sessions (not authenticated ones)
        // Check if user is NOT authenticated and session is not initialized
        if (!isset($_SESSION['initialized']) && 
            !isset($_SESSION['authenticated']) && 
            !isset($_SESSION['admin_id']) && 
            !isset($_SESSION['user_id'])) {
            session_regenerate_id(true);
            $_SESSION['initialized'] = true;
            file_put_contents(__DIR__ . '/../logs/auth.log', "[" . date('Y-m-d H:i:s') . "] Session ID regenerated: " . session_id() . "\n", FILE_APPEND);
        }
    }
}
?>