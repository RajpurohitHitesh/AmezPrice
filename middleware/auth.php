<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';

function verifyAuthentication($requiredRole = null) {
    startApplicationSession();
    
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

    // Check basic authentication flag first
    if (isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true) {
        
        // Verify JWT if present, but don't fail authentication if missing
        $jwt = $_SESSION['jwt'] ?? '';
        if ($jwt && strpos($jwt, '.') !== false) {
            $parts = explode('.', $jwt);
            if (count($parts) === 3) {
                list($header, $payload, $signature) = $parts;
                $decodedPayload = json_decode(base64_decode($payload), true);
                
                // Check token expiration
                if ($decodedPayload && isset($decodedPayload['exp']) && $decodedPayload['exp'] < time()) {
                    session_destroy();
                    return false;
                }
                
                // Verify signature
                global $securityConfig;
                $expectedSignature = base64_encode(hash_hmac('sha256', 
                    "$header.$payload", 
                    $securityConfig['jwt']['secret'], 
                    true
                ));
                
                if ($signature !== $expectedSignature) {
                    // JWT invalid, but don't destroy session - just log it
                    file_put_contents(__DIR__ . '/../logs/auth.log', "[" . date('Y-m-d H:i:s') . "] JWT signature mismatch but session valid\n", FILE_APPEND);
                }
            }
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