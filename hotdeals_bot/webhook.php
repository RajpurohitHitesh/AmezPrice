<?php
require_once '../config/database.php';
require_once '../config/telegram.php';
require_once 'hotdealsbot.php';

// Enable error reporting for development
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Add logging function
function logWebhook($message, $data = []) {
    try {
        $logFile = __DIR__ . '/../logs/hotdeals_bot.log';
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

    // Validate and parse input
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        throw new Exception('Invalid input format', 400);
    }

    // Handle both message and callback_query
    if (isset($input['callback_query'])) {
        $chatId = $input['callback_query']['message']['chat']['id'];
        $message = '';
        $user = $input['callback_query']['from'];
        
        logWebhook('Processing callback query', [
            'callback_data' => $input['callback_query']['data'],
            'user' => $user
        ]);
    } else if (isset($input['message'])) {
        $chatId = $input['message']['chat']['id'];
        $message = $input['message']['text'] ?? '';
        $user = $input['message']['from'];
        
        logWebhook('Processing message', [
            'chatId' => $chatId,
            'message' => $message,
            'user' => $user
        ]);
    } else {
        throw new Exception('Invalid update type', 400);
    }

    // Store user in database with better error handling
    try {
    // Store user in hotdealsbot_users table only
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        INSERT INTO hotdealsbot_users (
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

} catch (PDOException $e) {
    $pdo->rollBack();
    logWebhook('Database error', [
        'error' => $e->getMessage()
    ]);

}

    // Handle the message or callback
    handleHotDealsMessage($chatId, $message, $user, $input);
    
    // Answer callback query if needed
    if (isset($input['callback_query'])) {
        $callbackQueryId = $input['callback_query']['id'];
        $url = "https://api.telegram.org/bot{$telegramConfig['hotdealsbot_token']}/answerCallbackQuery";
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'callback_query_id' => $callbackQueryId
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        
        curl_exec($ch);
        curl_close($ch);
    }
    
    logWebhook('Request handled successfully');

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
        'code' => $statusCode,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}
?>