<?php
require_once '../config/database.php';
require_once '../config/telegram.php';
require_once 'bot.php';

// Enable error reporting for development
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Add logging function
function logWebhook($message, $data = []) {
    try {
        $logFile = __DIR__ . '/../logs/amezprice_bot.log';
        $timestamp = date('Y-m-d H:i:s');
        $logData = array_merge([
            'timestamp' => $timestamp,
            'message' => $message
        ], $data);
        file_put_contents($logFile, json_encode($logData) . "\n", FILE_APPEND);
    } catch (Exception $e) {
        error_log("Logging error: " . $e->getMessage());
    }
}

try {
    // Log incoming request
    logWebhook('Webhook received', [
        'headers' => getallheaders(),
        'input' => file_get_contents('php://input')
    ]);

    // Read raw input
    $rawInput = file_get_contents('php://input');
    if (empty($rawInput)) {
        throw new Exception('Empty request body', 400);
    }

    // Parse and validate JSON
    $input = json_decode($rawInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON: ' . json_last_error_msg(), 400);
    }

    logWebhook('Input parsed', ['data' => $input]);

    // Extract and validate message data
    $chatId = $input['message']['chat']['id'] ?? null;
    $message = $input['message']['text'] ?? '';
    $user = $input['message']['from'] ?? null;

    if (!$chatId || !$user || !isset($user['id'])) {
        logWebhook('Missing required fields', [
            'chatId' => $chatId,
            'user' => $user
        ]);
        throw new Exception('Missing required fields', 400);
    }

    // Log the extracted data
    logWebhook('Processing message', [
        'chatId' => $chatId,
        'message' => $message,
        'user' => $user
    ]);

    // Store or update user in database with enhanced validation
    try {

    if (!empty($user['id']) && 
        !empty($user['first_name']) && 
        $user['id'] != '123456789' && // Prevent test IDs
        strlen($user['id']) > 6) {     // Valid Telegram IDs are large numbers
        
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO users (
                telegram_id,
                first_name,
                last_name,
                username,
                language_code,
                last_interaction,
                created_at
            ) VALUES (
                ?, ?, ?, ?, ?,
                NOW(),
                NOW()
            ) ON DUPLICATE KEY UPDATE 
                first_name = VALUES(first_name),
                last_name = VALUES(last_name),
                username = VALUES(username),
                language_code = VALUES(language_code),
                last_interaction = NOW(),
                update_count = update_count + 1
        ");

        $stmt->execute([
            $user['id'],
            $user['first_name'],
            $user['last_name'] ?? null,
            $user['username'] ?? null,
            $user['language_code'] ?? null
        ]);

        $pdo->commit();

        logWebhook('User stored/updated in database', [
            'user_id' => $user['id']
        ]);
    } else {
        logWebhook('Invalid user data, skipping database insertion', [
            'user' => $user
        ]);
    }
} catch (PDOException $e) {
    $pdo->rollBack();
    logWebhook('Database error', [
        'error' => $e->getMessage()
    ]);

}

    // Handle the message
    $result = handleMessage($chatId, $message, $user);
    
    logWebhook('Message handled', [
        'result' => $result
    ]);

    // Return success response
    echo json_encode([
        'status' => 'success',
        'timestamp' => date('Y-m-d H:i:s')
    ]);

} catch (Exception $e) {
    logWebhook('Error in webhook', [
        'error' => $e->getMessage(),
        'code' => $e->getCode()
    ]);
    
    $statusCode = $e->getCode() ?: 500;
    http_response_code($statusCode);
    
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'code' => $statusCode
    ]);
}
?>