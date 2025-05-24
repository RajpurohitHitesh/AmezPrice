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

// Add logging function
function logNotify($message, $data = []) {
    try {
        $logFile = __DIR__ . '/../logs/hotdeals_bot.log';
        $timestamp = date('Y-m-d H:i:s');
        $logData = array_merge([
            'timestamp' => $timestamp,
            'message' => $message
        ], $data);
        file_put_contents($logFile, json_encode($logData) . "\n", FILE_APPEND);
    } catch (Exception $e) {
        error_log("Notify logging error: " . $e->getMessage());
    }
}

/**
 * Format currency amount
 */
function formatPrice($amount) {
    return number_format($amount, 0, '.', ',');
}

try {
    // Validate request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Method not allowed', 405);
    }

    // Read and validate input
    $rawInput = file_get_contents('php://input');
    if (empty($rawInput)) {
        throw new Exception('Empty request body', 400);
    }

    $input = json_decode($rawInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON payload: ' . json_last_error_msg(), 400);
    }

    // Log the request
    logNotify('Notification request received', [
        'input' => $input
    ]);

    // Validate required fields
    if (empty($input['category']) || empty($input['deal_info'])) {
        throw new Exception('Missing required fields', 400);
    }

    // Get users for this category
    $stmt = $pdo->prepare("
        SELECT DISTINCT u.telegram_id, uc.price_range, uc.merchant
        FROM hotdealsbot_users u
        JOIN hotdealsbot_user_categories uc ON u.telegram_id = uc.user_id
        WHERE uc.category = ? AND uc.active = 1
    ");
    $stmt->execute([$input['category']]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($users)) {
        throw new Exception('No users found for this category', 404);
    }

    $stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT user_id) as tracker_count
    FROM hotdealsbot_user_categories
    WHERE category = ? AND active = 1
    ");
    $stmt->execute([$input['category']]);
    $trackerInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    $trackerCount = $trackerInfo['tracker_count'] ?? 0;

    // Prepare deal message
    $dealInfo = $input['deal_info'];
    $merchant = strtolower($dealInfo['merchant']);
    $currentPrice = $dealInfo['current_price'];
    $originalPrice = $dealInfo['original_price'];
    $discount = $originalPrice - $currentPrice;
    $discountPercent = round(($discount / $originalPrice) * 100);

    $message = sprintf(
    "🎉 **Hot deal for you!** 🎉\n\n" .
    "[%s](%s)\n\n" .
    "Original: ₹%s\n" .
    "Deal Price: ₹%s\n" .
    "You Save: ₹%s (%d%%)\n\n" .
    "Platform: %s\n" .
    "Category: %s\n" .
    "🔥 %d people tracking\n\n" .  // Added tracker count here
    "⌚ Valid: %s",
    $dealInfo['title'],
    $dealInfo['url'],
    formatPrice($originalPrice),
    formatPrice($currentPrice),
    formatPrice($discount),
    $discountPercent,
    ucfirst($merchant),
    ucfirst($input['category']),
    $trackerCount,  // Add tracker count here
    $dealInfo['valid_till'] ?? 'Limited time only'
);

    // Prepare buttons
    $buttons = [
        [
            ['text' => '🛒 Buy Now', 'url' => $dealInfo['url']],
            ['text' => '🔴 Stop Alerts', 'callback_data' => 'stop_' . $input['category']]
        ]
    ];

    // Initialize metrics
    $metrics = [
        'total_users' => count($users),
        'notifications_sent' => 0,
        'notifications_failed' => 0,
        'start_time' => microtime(true)
    ];

    // Send notifications
    foreach ($users as $user) {
        // Skip if merchant doesn't match
        if ($user['merchant'] !== 'both' && $user['merchant'] !== $merchant) {
            continue;
        }

        // Skip if price is above user's range
        if ($user['price_range'] && $currentPrice > $user['price_range']) {
            continue;
        }

        try {
            $sent = sendHotDealsMessage($user['telegram_id'], $message, ['inline_keyboard' => $buttons]);
            
            if ($sent) {
                $metrics['notifications_sent']++;
            } else {
                $metrics['notifications_failed']++;
            }

            // Add small delay between messages
            usleep(50000); // 50ms delay

        } catch (Exception $e) {
            $metrics['notifications_failed']++;
            logNotify('Failed to send notification', [
                'user_id' => $user['telegram_id'],
                'error' => $e->getMessage()
            ]);
        }
    }

    // Calculate metrics
    $metrics['duration'] = round(microtime(true) - $metrics['start_time'], 2);
    $metrics['rate'] = round($metrics['notifications_sent'] / $metrics['duration'], 2);

    // Log success
    logNotify('Notifications completed', [
        'metrics' => $metrics
    ]);

    // Return success response
    echo json_encode([
        'status' => 'success',
        'message' => 'Notifications processed',
        'metrics' => $metrics,
        'timestamp' => date('Y-m-d H:i:s')
    ]);

} catch (Exception $e) {
    // Log error
    logNotify('Error occurred', [
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