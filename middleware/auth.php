<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';

function verifyJWT($jwt) {
    global $securityConfig;
    
    if (!$jwt || strpos($jwt, '.') === false) {
        return false;
    }

    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        return false;
    }

    list($header, $payload, $signature) = $parts;
    $decodedPayload = json_decode(base64_decode($payload), true);
    
    // Check expiration
    if (!$decodedPayload || !isset($decodedPayload['exp']) || $decodedPayload['exp'] < time()) {
        return false;
    }
    
    // Verify signature
    $expectedSignature = base64_encode(hash_hmac(
        'sha256', 
        "$header.$payload", 
        $securityConfig['jwt']['secret'], 
        true
    ));
    
    return $signature === $expectedSignature;
}

function verifyAuthentication($requiredRole = null) {
    if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
        return false;
    }

    // Verify JWT
    if (!isset($_SESSION['jwt']) || !verifyJWT($_SESSION['jwt'])) {
        // Invalid or expired JWT
        error_log("JWT verification failed for user: " . ($_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? 'unknown'));
        return false;
    }

    // Role verification
    if ($requiredRole === 'admin' && (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true)) {
        return false;
    }
    
    if ($requiredRole === 'user' && (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true)) {
        return false;
    }

    return true;
}

function requireUserAuth() {
    if (!verifyAuthentication('user')) {
        error_log("User authentication failed, redirecting to login");
        session_destroy();
        header("Location: " . LOGIN_REDIRECT);
        exit;
    }
}

function requireAdminAuth() {
    if (!verifyAuthentication('admin')) {
        error_log("Admin authentication failed, redirecting to login");
        session_destroy();
        header("Location: " . LOGIN_REDIRECT);
        exit;
    }
}