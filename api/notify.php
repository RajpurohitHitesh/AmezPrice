<?php
require_once '../config/database.php';
require_once '../config/telegram.php';
require_once '../middleware/csrf.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$userIds = $input['user_ids'] ?? [];
$asin = $input['asin'] ?? '';
$messageType = $input['message_type'] ?? '';
$details = $input['details'] ?? [];

if (empty($userIds) || !$asin || !$messageType || !$details) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid input']);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM products WHERE asin = ?");
$stmt->execute([$asin]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    echo json_encode(['status' => 'error', 'message' => 'Product not found']);
    exit;
}

$botToken = $telegramConfig['amezpricebot_token'];
$baseUrl = "https://api.telegram.org/bot{$botToken}/sendMessage";

foreach ($userIds as $userId) {
    $message = '';
    $buttons = [
        ['text' => 'Buy Now ✅', 'url' => $product['affiliate_link']],
        ['text' => 'Stop Tracking 🔴', 'callback_data' => "stop_{$asin}"],
        ['text' => 'Price History 📈', 'url' => $product['website_url']],
        ['text' => 'Today’s Deals 🛍️', 'url' => $telegramConfig['channels']['amezprice']]
    ];

    switch ($messageType) {
        case 'price_drop':
            $percentage = round(($details['previous_price'] - $details['current_price']) / $details['previous_price'] * 100);
            $message = "⬇️ The product price decreased by ₹" . number_format($details['previous_price'] - $details['current_price'], 0, '.', ',') . " ({$percentage}% off)\n\n"
                . "[{$product['name']}]({$product['affiliate_link']})\n\n"
                . "Previous Price: ₹" . number_format($details['previous_price'], 0, '.', ',') . "\n\n"
                . "**Current Price: ₹" . number_format($details['current_price'], 0, '.', ',') . "**\n\n"
                . "🔥 {$details['tracker_count']} users are tracking this!\n\n"
                . "⌚ Updated at " . date('d M Y, h:i A', strtotime($product['last_updated']));
            break;
        case 'price_increase':
            $percentage = round(($details['current_price'] - $details['previous_price']) / $details['previous_price'] * 100);
            $message = "⬆️ The product price increased by ₹" . number_format($details['current_price'] - $details['previous_price'], 0, '.', ',') . " ({$percentage}% up)\n\n"
                . "[{$product['name']}]({$product['affiliate_link']})\n\n"
                . "Previous Price: ₹" . number_format($details['previous_price'], 0, '.', ',') . "\n\n"
                . "**Current Price: ₹" . number_format($details['current_price'], 0, '.', ',') . "**\n\n"
                . "🔥 {$details['tracker_count']} users are tracking this!\n\n"
                . "⌚ Updated at " . date('d M Y, h:i A', strtotime($product['last_updated']));
            break;
        case 'low_stock':
            $message = "⚠️ The product stock is low. ({$details['quantity']} left)\n\n"
                . "[{$product['name']}]({$product['affiliate_link']})\n\n"
                . "Previous Price: ₹" . number_format($details['previous_price'], 0, '.', ',') . "\n\n"
                . "**Current Price: ₹" . number_format($details['current_price'], 0, '.', ',') . "**\n\n"
                . "🔥 {$details['tracker_count']} users are tracking this!\n\n"
                . "⌚ Updated at " . date('d M Y, h:i A', strtotime($product['last_updated']));
            break;
        case 'out_of_stock':
            $message = "😔 *The product is currently out of stock*\n\n"
                . "[{$product['name']}]({$product['affiliate_link']})\n\n"
                . "Previous Price: ₹" . number_format($details['previous_price'], 0, '.', ',') . "\n\n"
                . "**Currently out of stock**\n\n"
                . "🔥 {$details['tracker_count']} users are tracking this!\n\n"
                . "⌚ Updated at " . date('d M Y, h:i A', strtotime($product['last_updated']));
            break;
        case 'in_stock':
            $message = "🎉 The product is currently in stock\n\n"
                . "[{$product['name']}]({$product['affiliate_link']})\n\n"
                . "*Previously out of stock*\n\n"
                . "**Current Price: ₹" . number_format($details['current_price'], 0, '.', ',') . "**\n\n"
                . "🔥 {$details['tracker_count']} users are tracking this!\n\n"
                . "⌚ Updated at " . date('d M Y, h:i A', strtotime($product['last_updated']));
            break;
        default:
            continue 2;
    }

    $payload = [
        'chat_id' => $userId,
        'text' => $message,
        'parse_mode' => 'Markdown',
        'reply_markup' => json_encode(['inline_keyboard' => array_chunk($buttons, 2)])
    ];

    $ch = curl_init($baseUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}

echo json_encode(['status' => 'success', 'message' => 'Notifications sent']);
?>