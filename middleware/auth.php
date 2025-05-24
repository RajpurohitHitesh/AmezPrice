<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/session.php';

/**
 * Verify JWT token's validity
 * @param string $jwt The JWT token to verify
 * @return bool True if valid, false otherwise
 */
function verifyJWT($jwt) {
    global $securityConfig;
    
    // Basic JWT format validation
    if (!$jwt || strpos($jwt, '.') === false) {
        error_log("[" . date('Y-m-d H:i:s') . "] JWT verification failed: Invalid format");
        return false;
    }

    // Split JWT parts
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        error_log("[" . date('Y-m-d H:i:s') . "] JWT verification failed: Invalid number of segments");
        return false;
    }

    list($header, $payload, $signature) = $parts;
    
    try {
        // Decode payload
        $decodedPayload = json_decode(base64_decode($payload), true);
        
        // Check if payload was successfully decoded
        if (!$decodedPayload) {
            error_log("[" . date('Y-m-d H:i:s') . "] JWT verification failed: Invalid payload");
            return false;
        }
        
        // Check expiration
        if (!isset($decodedPayload['exp']) || $decodedPayload['exp'] < time()) {
            error_log("[" . date('Y-m-d H:i:s') . "] JWT verification failed: Token expired");
            return false;
        }

        // Check issued at time
        if (!isset($decodedPayload['iat']) || $decodedPayload['iat'] > time()) {
            error_log("[" . date('Y-m-d H:i:s') . "] JWT verification failed: Invalid issue time");
            return false;
        }

        // Check required claims
        $requiredClaims = ['user_id', 'email', 'username', 'is_admin'];
        foreach ($requiredClaims as $claim) {
            if (!isset($decodedPayload[$claim])) {
                error_log("[" . date('Y-m-d H:i:s') . "] JWT verification failed: Missing claim - $claim");
                return false;
            }
        }
        
        // Verify signature
        $expectedSignature = base64_encode(hash_hmac(
            'sha256', 
            "$header.$payload", 
            $securityConfig['jwt']['secret'], 
            true
        ));
        
        if (!hash_equals($signature, $expectedSignature)) {
            error_log("[" . date('Y-m-d H:i:s') . "] JWT verification failed: Invalid signature");
            return false;
        }
        
        return true;
    } catch (Exception $e) {
        error_log("[" . date('Y-m-d H:i:s') . "] JWT verification error: " . $e->getMessage());
        return false;
    }
}

/**
 * Verify user authentication and authorization
 * @param string|null $requiredRole The required role (admin/user)
 * @return bool True if authenticated and authorized, false otherwise
 */
function verifyAuthentication($requiredRole = null) {
    // Check session existence and initialization
    if (!isset($_SESSION['initialized']) || $_SESSION['initialized'] !== 1) {
        error_log("[" . date('Y-m-d H:i:s') . "] Session not initialized");
        return false;
    }

    // Check authentication flag
    if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
        error_log("[" . date('Y-m-d H:i:s') . "] Session not authenticated");
        return false;
    }

    // Verify session contains required data
    $requiredSessionKeys = ['user_type', 'email', 'username'];
    foreach ($requiredSessionKeys as $key) {
        if (!isset($_SESSION[$key]) || empty($_SESSION[$key])) {
            error_log("[" . date('Y-m-d H:i:s') . "] Missing session key: $key");
            return false;
        }
    }

    // Check user ID based on type
    if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
        error_log("[" . date('Y-m-d H:i:s') . "] No user or admin ID in session");
        return false;
    }

    // Verify JWT existence and validity
    if (!isset($_SESSION['jwt'])) {
        error_log("[" . date('Y-m-d H:i:s') . "] No JWT found in session");
        return false;
    }

    if (!verifyJWT($_SESSION['jwt'])) {
        error_log("[" . date('Y-m-d H:i:s') . "] JWT verification failed for user: " . 
            ($_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? 'unknown'));
        return false;
    }

    // Role-based authorization
    if ($requiredRole === 'admin') {
        if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
            error_log("[" . date('Y-m-d H:i:s') . "] Admin access denied: User is not admin");
            return false;
        }
    } elseif ($requiredRole === 'user') {
        if (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true) {
            error_log("[" . date('Y-m-d H:i:s') . "] User access denied: User is admin");
            return false;
        }
    }

    return true;
}

/**
 * Require user authentication
 * Redirects to login if not authenticated
 */
function requireUserAuth() {
    if (!verifyAuthentication('user')) {
        error_log("[" . date('Y-m-d H:i:s') . "] User authentication failed, redirecting to login");
        
        // Clean up session
        session_unset();
        session_destroy();
        
        // Clear session cookie
        if (isset($_COOKIE[session_name()])) {
            setcookie(session_name(), '', [
                'expires' => time() - 3600,
                'path' => '/',
                'domain' => '',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        }
        
        header("Location: " . LOGIN_REDIRECT);
        exit;
    }
}

/**
 * Require admin authentication
 * Redirects to login if not authenticated
 */
function requireAdminAuth() {
    if (!verifyAuthentication('admin')) {
        error_log("[" . date('Y-m-d H:i:s') . "] Admin authentication failed, redirecting to login");
        
        // Clean up session
        session_unset();
        session_destroy();
        
        // Clear session cookie
        if (isset($_COOKIE[session_name()])) {
            setcookie(session_name(), '', [
                'expires' => time() - 3600,
                'path' => '/',
                'domain' => '',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        }
        
        header("Location: " . LOGIN_REDIRECT);
        exit;
    }
}

/**
 * Get current authenticated user's data
 * @return array|null User data or null if not authenticated
 */
function getCurrentUser() {
    if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
        return null;
    }

    return [
        'id' => $_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? null,
        'username' => $_SESSION['username'] ?? null,
        'email' => $_SESSION['email'] ?? null,
        'type' => $_SESSION['user_type'] ?? null,
        'is_admin' => $_SESSION['is_admin'] ?? false
    ];
}