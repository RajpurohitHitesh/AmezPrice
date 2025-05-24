<?php
require_once '../config/database.php';
require_once '../config/telegram.php';
require_once '../middleware/csrf.php';

// Enable error reporting for development
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Set response headers
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

/**
 * Make an HTTP request with error handling
 * @param string $url
 * @param array $payload
 * @param array $headers
 * @return array
 */
function makeRequest($url, $payload, $headers = []) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => array_merge([
            'Content-Type: application/json'
        ], $headers)
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error || $httpCode >= 400) {
        throw new Exception("Request failed: $error (HTTP $httpCode)");
    }

    $data = json_decode($response, true);
    if (!$data || json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON response');
    }

    return $data;
}

/**
 * Log API activity
 * @param string $action
 * @param array $data
 * @return void
 */
function logApiActivity($action, $data) {
    $logData = [
        'timestamp' => date('Y-m-d H:i:s'),
        'action' => $action,
        'user_id' => $data['user_id'] ?? null,
        'ip' => $_SERVER['REMOTE_ADDR'],
        'status' => $data['status'] ?? 'pending'
    ];
    error_log(json_encode($logData));
}

try {
    // Validate request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Method not allowed', 405);
    }

    // Rate limiting
    $clientIP = $_SERVER['REMOTE_ADDR'];
    $rateLimit = 60; // requests per minute
    $rateLimitKey = "rate_limit:$clientIP";
    
    // Read and validate input
    $rawInput = file_get_contents('php://input');
    if (empty($rawInput)) {
        throw new Exception('Empty request body', 400);
    }

    $input = json_decode($rawInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON payload: ' . json_last_error_msg(), 400);
    }

    // Validate API key with secure comparison
    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (empty($apiKey) || !hash_equals($telegramConfig['api_key'], $apiKey)) {
        throw new Exception('Invalid API key', 401);
    }

    // Validate required fields
    $action = $input['action'] ?? '';
    $userId = filter_var($input['user_id'] ?? null, FILTER_VALIDATE_INT);
    
    if (!$userId) {
        throw new Exception('Invalid user ID', 400);
    }

    if (!in_array($action, ['track', 'remove'])) {
        throw new Exception('Invalid action', 400);
    }

    // Log start of request
    logApiActivity($action, [
        'user_id' => $userId,
        'status' => 'started'
    ]);

    // Process different actions
    switch ($action) {
        case 'track':
            // Validate product URL
            if (empty($input['product_url'])) {
                throw new Exception('Product URL is required', 400);
            }

            if (!filter_var($input['product_url'], FILTER_VALIDATE_URL)) {
                throw new Exception('Invalid product URL', 400);
            }

            // Validate the URL is from supported websites
            if (!preg_match('/^https?:\/\/(www\.)?(amazon\.in|flipkart\.com)/', $input['product_url'])) {
                throw new Exception('Only Amazon India and Flipkart URLs are supported', 400);
            }

            // Prepare tracking payload
            $trackPayload = [
                'user_id' => $userId,
                'username' => $input['username'] ?? null,
                'first_name' => $input['first_name'] ?? '',
                'last_name' => $input['last_name'] ?? null,
                'product_url' => $input['product_url'],
                'source' => 'telegram_bot',
                'timestamp' => date('Y-m-d H:i:s'),
                'notification_preferences' => [
                    'price_drop' => true,
                    'low_stock' => true,
                    'back_in_stock' => true
                ]
            ];

            // Make API request with retry mechanism
            $maxRetries = 3;
            $retryCount = 0;
            $lastError = null;

            while ($retryCount < $maxRetries) {
                try {
                    $response = makeRequest(
                        'https://amezprice.com/api/track.php',
                        $trackPayload,
                        ['X-API-Key: ' . $telegramConfig['api_key']]
                    );

                    // Add tracking metrics
                    $response['tracking_stats'] = [
                        'total_users' => $response['tracker_count'] ?? 0,
                        'price_change_24h' => $response['price_change_24h'] ?? 0,
                        'tracking_since' => $response['first_tracked'] ?? date('Y-m-d H:i:s'),
                        'current_price' => $response['current_price'] ?? 0,
                        'highest_price' => $response['highest_price'] ?? 0,
                        'lowest_price' => $response['lowest_price'] ?? 0
                    ];

                    // Log successful tracking
                    logApiActivity('track_success', [
                        'user_id' => $userId,
                        'product_id' => $response['asin'] ?? null,
                        'status' => 'completed'
                    ]);

                    echo json_encode($response);
                    break;

                } catch (Exception $e) {
                    $lastError = $e;
                    $retryCount++;
                    if ($retryCount < $maxRetries) {
                        sleep(1); // Wait before retrying
                        continue;
                    }
                    throw $e;
                }
            }
            break;

        case 'remove':
            // Validate ASIN
            $asin = $input['asin'] ?? '';
            if (empty($asin) || !preg_match('/^[A-Z0-9]{10}$/', $asin)) {
                throw new Exception('Invalid ASIN', 400);
            }

            // Verify if user is actually tracking this product
            $stmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM user_products 
                WHERE user_id = ? AND product_asin = ?
            ");
            $stmt->execute([$userId, $asin]);
            if (!$stmt->fetchColumn()) {
                throw new Exception('Product not found in your tracking list', 404);
            }

            // Prepare removal payload
            $removePayload = [
                'user_id' => $userId,
                'asin' => $asin,
                'source' => 'telegram_bot',
                'timestamp' => date('Y-m-d H:i:s'),
                'reason' => $input['reason'] ?? 'user_request'
            ];

            // Make API request with retry mechanism
            $maxRetries = 3;
            $retryCount = 0;
            $lastError = null;

            while ($retryCount < $maxRetries) {
                try {
                    $response = makeRequest(
                        'https://amezprice.com/api/remove.php',
                        $removePayload,
                        ['X-API-Key: ' . $telegramConfig['api_key']]
                    );

                    // Update local database
                    $stmt = $pdo->prepare("
                        DELETE FROM user_products 
                        WHERE user_id = ? AND product_asin = ?
                    ");
                    $stmt->execute([$userId, $asin]);

                    // Log successful removal
                    logApiActivity('remove_success', [
                        'user_id' => $userId,
                        'product_id' => $asin,
                        'status' => 'completed'
                    ]);

                    // Add removal confirmation
                    $response['removal_confirmation'] = [
                        'timestamp' => date('Y-m-d H:i:s'),
                        'success' => true
                    ];

                    echo json_encode($response);
                    break;

                } catch (Exception $e) {
                    $lastError = $e;
                    $retryCount++;
                    if ($retryCount < $maxRetries) {
                        sleep(1); // Wait before retrying
                        continue;
                    }
                    throw $e;
                }
            }
            break;
    }

} catch (Exception $e) {
    // Log the error
    error_log("API error: {$e->getMessage()}");
    logApiActivity('error', [
        'error_message' => $e->getMessage(),
        'status' => 'failed'
    ]);
    
    // Send appropriate HTTP status code
    $statusCode = $e->getCode() ?: 500;
    http_response_code($statusCode);
    
    // Return error response
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'code' => $statusCode,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}
?>