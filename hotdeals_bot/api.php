<?php
require_once '../config/database.php';
require_once '../config/telegram.php';
require_once '../middleware/csrf.php';
require_once 'hotdealsbot.php';

// Enable error reporting for development
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Set response headers
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Add logging function
function logApi($message, $data = []) {
    try {
        $logFile = __DIR__ . '/../logs/hotdeals_bot.log';
        $timestamp = date('Y-m-d H:i:s');
        $logData = array_merge([
            'timestamp' => $timestamp,
            'message' => $message
        ], $data);
        file_put_contents($logFile, json_encode($logData) . "\n", FILE_APPEND);
    } catch (Exception $e) {
        error_log("API logging error: " . $e->getMessage());
    }
}

try {
    // Validate request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Method not allowed', 405);
    }

    // Rate limiting setup
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

    // Validate API key
    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (empty($apiKey) || !hash_equals($telegramConfig['api_key'], $apiKey)) {
        throw new Exception('Invalid API key', 401);
    }

    // Log the request
    logApi('API request received', [
        'input' => $input,
        'ip' => $clientIP
    ]);

    // Validate user_id
    $userId = filter_var($input['user_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$userId) {
        throw new Exception('Invalid user ID', 400);
    }

    // Verify user exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM hotdealsbot_users WHERE telegram_id = ?");
    $stmt->execute([$userId]);
    if (!$stmt->fetchColumn()) {
        throw new Exception('User not found', 404);
    }

    // Process based on category action
    $categoryId = $input['category_id'] ?? null;
    $action = $input['action'] ?? '';

    // Handle category actions
    switch ($action) {
        case 'add':
            if (empty($input['category'])) {
                throw new Exception('Category is required', 400);
            }

            // Check category limit
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM hotdealsbot_user_categories WHERE user_id = ?");
            $stmt->execute([$userId]);
            if ($stmt->fetchColumn() >= 5) {
                throw new Exception('Maximum 5 categories allowed', 400);
            }

            // Insert new category
            $stmt = $pdo->prepare("
                INSERT INTO hotdealsbot_user_categories (
                    user_id, 
                    category, 
                    merchant,
                    price_range,
                    created_at
                ) VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $userId,
                $input['category'],
                $input['merchant'] ?? 'both',
                $input['price_range'] ?? null
            ]);

            $response = [
                'status' => 'success',
                'message' => 'Category added successfully',
                'category_id' => $pdo->lastInsertId()
            ];
            break;

        case 'remove':
            if (!$categoryId) {
                throw new Exception('Category ID is required', 400);
            }

            // Remove category
            $stmt = $pdo->prepare("DELETE FROM hotdealsbot_user_categories WHERE id = ? AND user_id = ?");
            $stmt->execute([$categoryId, $userId]);

            if ($stmt->rowCount() === 0) {
                throw new Exception('Category not found', 404);
            }

            $response = [
                'status' => 'success',
                'message' => 'Category removed successfully'
            ];
            break;

        case 'update':
            if (!$categoryId) {
                throw new Exception('Category ID is required', 400);
            }

            // Update category
            $updateFields = [];
            $params = [$userId, $categoryId];

            if (isset($input['merchant'])) {
                $updateFields[] = 'merchant = ?';
                $params = array_merge([$input['merchant']], $params);
            }

            if (isset($input['price_range'])) {
                $updateFields[] = 'price_range = ?';
                $params = array_merge([$input['price_range']], $params);
            }

            if (empty($updateFields)) {
                throw new Exception('No updates provided', 400);
            }

            $sql = "UPDATE hotdealsbot_user_categories SET " . 
                   implode(', ', $updateFields) . 
                   " WHERE user_id = ? AND id = ?";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            if ($stmt->rowCount() === 0) {
                throw new Exception('Category not found', 404);
            }

            $response = [
                'status' => 'success',
                'message' => 'Category updated successfully'
            ];
            break;

        default:
            throw new Exception('Invalid action', 400);
    }

    // Log successful response
    logApi('API request successful', [
        'action' => $action,
        'user_id' => $userId,
        'response' => $response
    ]);

    // Return success response
    echo json_encode(array_merge($response, [
        'timestamp' => date('Y-m-d H:i:s')
    ]));

} catch (Exception $e) {
    // Log error
    logApi('API error occurred', [
        'error' => $e->getMessage(),
        'code' => $e->getCode()
    ]);
    
    // Send error response
    $statusCode = $e->getCode() ?: 500;
    http_response_code($statusCode);
    
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'code' => $statusCode,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}
?>