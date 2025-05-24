<?php
require_once '../../config/database.php';
require_once '../../../vendor/autoload.php';

use Rubix\ML\Datasets\Labeled;

$stmt = $pdo->query("SELECT p.asin, p.name, p.current_price, p.highest_price, p.rating, p.category, ub.user_id, ub.is_ai_suggested 
                     FROM products p 
                     JOIN user_behavior ub ON p.asin = ub.asin 
                     WHERE p.current_price <= p.highest_price * 0.7 AND p.rating >= 3.5");
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($products as $product) {
    if ($product['is_ai_suggested']) continue;

    $stmt = $pdo->prepare("SELECT user_id FROM users WHERE cluster = (SELECT cluster FROM users WHERE id = ?)");
    $stmt->execute([$product['user_id']]);
    $similarUsers = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($similarUsers as $userId) {
        $stmt = $pdo->prepare("
            INSERT INTO user_behavior (user_id, asin, is_ai_suggested, interaction_type)
            VALUES (?, ?, TRUE, 'recommended')
        ");
        $stmt->execute([$userId, $product['asin']]);

        // Send deal via Telegram
        $payload = [
            'chat_id' => $userId,
            'text' => "🎉 **Hot deal for you!** 🎉\n\n"
                    . "[{$product['name']}]({$product['affiliate_link']})\n\n"
                    . "Highest Price: ₹" . number_format($product['highest_price'], 0, '.', ',') . "\n\n"
                    . "**Current Price: ₹" . number_format($product['current_price'], 0, '.', ',') . "**\n\n"
                    . round(($product['highest_price'] - $product['current_price']) / $product['highest_price'] * 100) . "% off\n\n"
                    . "🔥 {$trackerCount} users are tracking this!\n\n"
                    . "🔔 Updated at " . date('d M Y, h:i A'),
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [
                        ['text' => 'Buy Now', 'url' => $product['affiliate_link']],
                        ['text' => 'Price History', 'url' => "https://amezprice.com/history/{$product['merchant']}/pid={$product['asin']}"],
                        ['text' => 'Set price alert for this!', 'url' => 'https://t.me/AmezPriceBot?start=alert_' . $product['asin']]
                    ]
                ]
            ])
        ];

        $ch = curl_init("https://api.telegram.org/bot{$telegramConfig['hotdealsbot_token']}/sendMessage");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_exec($ch);
        curl_close($ch);
    }
}
?>