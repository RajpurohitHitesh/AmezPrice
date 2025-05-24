<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';

function verifyAuthentication($requiredRole = null) {
    session_start();
    
    // Determine current role
    $currentRole = null;
    if (isset($_SESSION['admin_id'])) {
        $currentRole = 'admin';
    } elseif (isset($_SESSION['user_id'])) {
        $currentRole = 'user';
    }

    // If no session exists, authentication fails
    if (!$currentRole) {
        return false;
    }

    // If a specific role is required, verify it
    if ($requiredRole && $currentRole !== $requiredRole) {
        return false;
    }

    // Verify JWT
    $jwt = $_SESSION['jwt'] ?? '';
    if ($jwt) {
        list($header, $payload, $signature) = explode('.', $jwt);
        $decodedPayload = json_decode(base64_decode($payload), true);
        
        // Check token expiration
        if ($decodedPayload['exp'] < time()) {
            session_destroy();
            return false;
        }
        
        // Verify signature
        $expectedSignature = base64_encode(hash_hmac('sha256', 
            "$header.$payload", 
            $securityConfig['jwt']['secret'], 
            true
        ));
        
        if ($signature !== $expectedSignature) {
            session_destroy();
            return false;
        }
        
        return true;
    }
    
    return false;
}

function requireUserAuth() {
    if (!verifyAuthentication('user')) {
        header("Location: " . LOGIN_REDIRECT);
        exit;
    }
}

function requireAdminAuth() {
    if (!verifyAuthentication('admin')) {
        header("Location: " . LOGIN_REDIRECT);
        exit;
    }
}

function getCurrentUserId() {
    return $_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? null;
}

function isLoggedIn() {
    return verifyAuthentication();
}

function getUserRole() {
    if (isset($_SESSION['admin_id'])) return 'admin';
    if (isset($_SESSION['user_id'])) return 'user';
    return null;
}
?>