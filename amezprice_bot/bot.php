<?php
require_once '../config/database.php';
require_once '../config/telegram.php';

// Add logging function
function logBot($message, $data = []) {
    try {
        $logFile = __DIR__ . '/../logs/amezprice_bot.log';
        $timestamp = date('Y-m-d H:i:s');
        $logData = array_merge([
            'timestamp' => $timestamp,
            'message' => $message
        ], $data);
        file_put_contents($logFile, json_encode($logData) . "\n", FILE_APPEND);
    } catch (Exception $e) {
        error_log("Bot logging error: " . $e->getMessage());
    }
}

/**
 * Send message to Telegram chat
 */
function sendMessage($chatId, $text, $replyMarkup = null) {
    global $telegramConfig;
    
    try {
        logBot('Preparing to send message', [
            'chatId' => $chatId,
            'text' => $text
        ]);

        $url = "https://api.telegram.org/bot{$telegramConfig['amezpricebot_token']}/sendMessage";
        
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'Markdown',
            'disable_web_page_preview' => false
        ];

        if ($replyMarkup) {
            $payload['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE);
        }

        logBot('Sending request to Telegram', [
            'url' => $url,
            'payload' => $payload
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error || $httpCode !== 200) {
            throw new Exception("Failed to send message: $error (HTTP $httpCode)");
        }

        $result = json_decode($response, true);
        if (!$result || !$result['ok']) {
            throw new Exception('Telegram API error: ' . ($result['description'] ?? 'Unknown error'));
        }

        logBot('Message sent successfully', [
            'chatId' => $chatId,
            'response' => $result
        ]);

        return true;

    } catch (Exception $e) {
        logBot('Error sending message', [
            'error' => $e->getMessage(),
            'chatId' => $chatId
        ]);
        return false;
    }
}

/**
 * Handle incoming message
 */
function handleMessage($chatId, $message, $user) {
    global $pdo, $telegramConfig;

    try {
        logBot('Handling message', [
            'chatId' => $chatId,
            'message' => $message,
            'user' => $user
        ]);

        // Handle /start command
        if ($message === '/start') {
            $buttons = array_filter($telegramConfig['buttons']['amezprice'], fn($btn) => $btn['enabled']);
            $replyMarkup = [
                'inline_keyboard' => array_map(fn($btn) => [['text' => $btn['text'], 'url' => $btn['url']]], $buttons)
            ];

            $welcomeMessage = sprintf(
                "🎉 %s Welcome to AmezPrice! Great to see you!\n\n" .
                "I track prices for Amazon and Flipkart products to help you snag the best deals!\n\n" .
                "Just send me a product link to start tracking, or use /deal to explore hot deals, or /price to set price alerts.\n\n" .
                "Click /help to get more info",
                $user['username'] ? "@{$user['username']}" : $user['first_name']
            );

            if (!sendMessage($chatId, $welcomeMessage, $replyMarkup)) {
                logBot('Failed to send welcome message');
            }

            if (!sendMessage($chatId, "Which product would you like to track? Just send the link!")) {
                logBot('Failed to send follow-up message');
            }

            return true;
        }

        // Handle /stop command
        if ($message === '/stop') {
            $stmt = $pdo->prepare("
                SELECT p.asin, p.name 
                FROM user_products up 
                JOIN products p ON up.product_asin = p.asin 
                WHERE up.user_id = ? 
                ORDER BY up.created_at DESC 
                LIMIT 5
            ");
            $stmt->execute([$chatId]);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($products)) {
                return sendMessage($chatId, "You aren't tracking any products yet! Send me a product link to start tracking.");
            }

            $message = "Select a product to stop tracking:\n\n";
            $buttons = [];
            foreach ($products as $index => $product) {
                $message .= ($index + 1) . ". " . substr($product['name'], 0, 100) . "...\n";
                $buttons[] = ['text' => (string)($index + 1), 'callback_data' => "remove_{$product['asin']}"];
            }

            $replyMarkup = ['inline_keyboard' => array_chunk($buttons, 2)];
            return sendMessage($chatId, $message, $replyMarkup);
        }

        // Handle /list command
        if ($message === '/list') {
            $stmt = $pdo->prepare("
                SELECT p.asin, p.name, p.website_url, p.current_price, up.price_threshold 
                FROM user_products up 
                JOIN products p ON up.product_asin = p.asin 
                WHERE up.user_id = ?
                ORDER BY up.created_at DESC
            ");
            $stmt->execute([$chatId]);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($products)) {
                return sendMessage($chatId, "You aren't tracking any products yet! Send me a product link to start tracking.");
            }

            $message = "Your tracked products:\n\n";
            foreach ($products as $index => $product) {
                $message .= sprintf(
                    "%d. [%s](%s)\n" .
                    "   💰 Current: ₹%s\n" .
                    "   🎯 Alert at: %s\n\n",
                    $index + 1,
                    substr($product['name'], 0, 100),
                    $product['website_url'],
                    number_format($product['current_price'], 0, '.', ','),
                    $product['price_threshold'] ? "₹" . number_format($product['price_threshold'], 0, '.', ',') : "Not set"
                );
            }

            return sendMessage($chatId, $message);
        }

        // Handle /help command
        if ($message === '/help') {
            $helpMessage = "Here's how I can help you:\n\n" .
                         "📦 *Track Product*\n" .
                         "Simply send me an Amazon or Flipkart product link\n\n" .
                         "🛑 *Remove Product*\n" .
                         "Use /stop to stop tracking products\n\n" .
                         "📋 *View Tracked Products*\n" .
                         "Use /list to see your tracked products\n\n" .
                         "🔥 *Hot Deals*\n" .
                         "Use /deal to explore today's best deals\n\n" .
                         "⚡ *Price Alerts*\n" .
                         "Use /price to set custom price alerts\n\n" .
                         "Need more help? Visit https://amezprice.com/pages/faq.php";
            
            return sendMessage($chatId, $helpMessage);
        }

        // Handle /deal command
        if ($message === '/deal') {
            $replyMarkup = [
                'inline_keyboard' => [
                    [
                        ['text' => '🛍️ Amazon Deals', 'url' => 'https://amezprice.com/pages/goldbox.php'],
                        ['text' => '🎁 Flipkart Deals', 'url' => 'https://amezprice.com/pages/flipbox.php']
                    ],
                    [
                        ['text' => '📦 Today\'s Deals', 'url' => 'https://amezprice.com/pages/todays-deals.php'],
                    ]
                ]
            ];
            return sendMessage($chatId, "🔥 Today's Hot Deals\n\nFind the best offers on Amazon and Flipkart!\nClick below to explore deals by category:", $replyMarkup);
        }

        // Handle /price command
        if ($message === '/price') {
            $stmt = $pdo->prepare("
                SELECT p.asin, p.name, p.current_price 
                FROM user_products up 
                JOIN products p ON up.product_asin = p.asin 
                WHERE up.user_id = ? 
                ORDER BY up.created_at DESC 
                LIMIT 5
            ");
            $stmt->execute([$chatId]);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($products)) {
                return sendMessage($chatId, "Please add a product to track first! Just send me an Amazon or Flipkart product link.");
            }

            $message = "🚨 Select a product to set price alert:\n\n";
            $buttons = [];
            foreach ($products as $index => $product) {
                $message .= sprintf(
                    "%d. %s\n   Current Price: ₹%s\n\n",
                    $index + 1,
                    substr($product['name'], 0, 100),
                    number_format($product['current_price'], 0, '.', ',')
                );
                $buttons[] = ['text' => (string)($index + 1), 'callback_data' => "price_{$product['asin']}"];
            }

            $replyMarkup = ['inline_keyboard' => array_chunk($buttons, 2)];
            return sendMessage($chatId, $message, $replyMarkup);
        }

        // Handle product URLs
        if (preg_match('/^https?:\/\/(www\.)?(amazon\.in|flipkart\.com)\//', $message)) {
            $ch = curl_init('https://amezprice.com/api/track.php');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode([
                    'user_id' => $chatId,
                    'username' => $user['username'] ?? null,
                    'first_name' => $user['first_name'],
                    'last_name' => $user['last_name'] ?? null,
                    'product_url' => $message
                ]),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-API-Key: ' . $telegramConfig['api_key']
                ],
                CURLOPT_RETURNTRANSFER => true
            ]);

            $response = curl_exec($ch);
            $data = json_decode($response, true);
            curl_close($ch);

            if ($data && $data['status'] === 'success') {
                $replyMarkup = [
                    'inline_keyboard' => [
                        [
                            ['text' => 'Buy Now ✅', 'url' => $data['affiliate_link']],
                            ['text' => 'Stop Tracking 🔴', 'callback_data' => "stop_{$data['asin']}"]
                        ],
                        [
                            ['text' => 'Price History 📈', 'url' => $data['history_url']],
                            ['text' => "Today's Deals 🛍️", 'url' => $telegramConfig['channels']['amezprice']]
                        ]
                    ]
                ];

                $trackingMessage = sprintf(
                    "✅ Now tracking this product!\n\n" .
                    "[%s](%s)\n\n" .
                    "💰 Current Price: ₹%s\n" .
                    "📊 Highest: ₹%s\n" .
                    "📉 Lowest: ₹%s\n\n" .
                    "🔥 %d users tracking this\n" .
                    "⌚ Updated: %s",
                    $data['product_name'],
                    $data['affiliate_link'],
                    number_format($data['current_price'], 0, '.', ','),
                    number_format($data['highest_price'], 0, '.', ','),
                    number_format($data['lowest_price'], 0, '.', ','),
                    $data['tracker_count'],
                    date('d M Y, h:i A')
                );

                sendMessage($chatId, $trackingMessage, $replyMarkup);
                sendMessage($chatId, "I'll notify you when the price drops! Use /list to see all your tracked products 😊");
                return true;
            } else {
                return sendMessage($chatId, "⚠️ " . ($data['message'] ?? "Sorry, couldn't process that link. Please try again or send a different product link."));
            }
        }

        // Handle price threshold inputs
        if (preg_match('/^\d+$/', $message)) {
            $stmt = $pdo->prepare("
                SELECT p.asin, p.name, p.current_price, up.price_threshold 
                FROM user_products up 
                JOIN products p ON up.product_asin = p.asin 
                WHERE up.user_id = ? AND up.price_threshold IS NULL 
                ORDER BY up.updated_at DESC 
                LIMIT 1
            ");
            $stmt->execute([$chatId]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($product) {
                $priceThreshold = (int)$message;
                $minPrice = $product['current_price'] * 0.5;  // Allow up to 50% discount
                $maxPrice = $product['current_price'] * 1.2;  // Allow up to 20% increase

                if ($priceThreshold >= $minPrice && $priceThreshold <= $maxPrice) {
                    $stmt = $pdo->prepare("
                        UPDATE user_products 
                        SET price_threshold = ?, updated_at = NOW() 
                        WHERE user_id = ? AND product_asin = ?
                    ");
                    $stmt->execute([$priceThreshold, $chatId, $product['asin']]);

                    return sendMessage($chatId, sprintf(
                        "✅ Price alert set!\n\n" .
                        "[%s]\n" .
                        "🎯 Alert Price: ₹%s\n" .
                        "💰 Current Price: ₹%s\n\n" .
                        "I'll notify you when the price drops to your target!",
                        $product['name'],
                        number_format($priceThreshold, 0, '.', ','),
                        number_format($product['current_price'], 0, '.', ',')
                    ));
                } else {
                    return sendMessage(
                        $chatId,
                        "⚠️ Please set a reasonable price between:\n" .
                        "Minimum: ₹" . number_format($minPrice, 0, '.', ',') . "\n" .
                        "Maximum: ₹" . number_format($maxPrice, 0, '.', ',') . "\n\n" .
                        "Don't worry, you'll still get notifications for any price drops!"
                    );
                }
            }
        }

        // Default response for unknown input
        return sendMessage(
            $chatId,
            "I can help you track product prices!\n\n" .
            "• Send me an Amazon or Flipkart product link to start tracking\n" .
            "• Use /help to see all commands\n" .
            "• Use /deal to explore today's best deals"
        );

    } catch (Exception $e) {
        logBot('Error in handleMessage', [
            'error' => $e->getMessage(),
            'chatId' => $chatId
        ]);
        
        sendMessage($chatId, "Sorry, something went wrong. Please try again later.");
        return false;
    }
}
?>