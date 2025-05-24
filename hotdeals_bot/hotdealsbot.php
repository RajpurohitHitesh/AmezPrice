<?php
require_once '../config/database.php';
require_once '../config/telegram.php';


// Add logging function
function logBot($message, $data = []) {
    try {
        $logFile = __DIR__ . '/../logs/hotdeals_bot.log';
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

function sendHotDealsMessage($chatId, $text, $replyMarkup = null) {
    global $telegramConfig;
    $url = "https://api.telegram.org/bot{$telegramConfig['hotdealsbot_token']}/sendMessage";
    $payload = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'Markdown',
        'reply_markup' => $replyMarkup ? json_encode($replyMarkup) : null
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error || $httpCode !== 200) {
        error_log("Telegram API error: $error (HTTP $httpCode)");
        return false;
    }

    return true;
}

function validatePriceRange($category, $price, $pdo) {
    $stmt = $pdo->prepare("SELECT AVG(current_price) as avg_price FROM products WHERE category = ?");
    $stmt->execute([$category]);
    $avgPrice = $stmt->fetchColumn();
    
    $minPrice = $avgPrice * 0.5;
    $maxPrice = $avgPrice * 1.5;
    
    return $price >= $minPrice && $price <= $maxPrice;
}

function handleHotDealsMessage($chatId, $message, $user, $input = null) {
    global $pdo, $telegramConfig;

    // Handle /start command
    if ($message === '/start') {
        $buttons = array_filter($telegramConfig['buttons']['hotdeals'], fn($btn) => $btn['enabled']);
        $replyMarkup = [
            'inline_keyboard' => array_map(fn($btn) => [['text' => $btn['text'], 'url' => $btn['url']]], $buttons)
        ];

        $welcomeMessage = sprintf(
            "🎉 %s Welcome to Hot Deals Bot! Great to see you!\n\n" .
            "I help you discover amazing deals from Amazon and Flipkart.\n\n" .
            "Available commands:\n" .
            "• /startalert - Start getting deal alerts\n" .
            "• /stopalert - Stop receiving alerts\n" .
            "• /help - Show all commands\n\n" .
            "You can track deals in these categories:\n" .
            "• Smartphones 📱\n" .
            "• Laptops 💻\n" .
            "• Televisions 📺\n" .
            "• Headphones 🎧\n" .
            "• Smartwatches ⌚\n\n" .
            "Use /startalert to begin!",
            $user['username'] ? "@{$user['username']}" : $user['first_name']
        );

        sendHotDealsMessage($chatId, $welcomeMessage, $replyMarkup);

        // Send quick guide
        $guideMessage = 
            "Quick guide to get started:\n\n" .
            "1️⃣ Use /startalert\n" .
            "2️⃣ Choose your favorite categories\n" .
            "3️⃣ Set price range (optional)\n" .
            "4️⃣ Get deal alerts automatically!\n\n" .
            "Ready? Type /startalert now!";

        sendHotDealsMessage($chatId, $guideMessage);
        return;
    }

    // Handle /help command
    if ($message === '/help') {
        $helpMessage = "🌟 *Hot Deals Bot Help Guide* 🌟\n\n" .
                      "Welcome to your deal-finding assistant! Here's how to use me:\n\n" .
                      "📝 *Main Commands*\n" .
                      "• /start - Start the bot\n" .
                      "• /startalert - Set up new deal alerts\n" .
                      "• /stopalert - Stop receiving alerts\n" .
                      "• /help - Show this help message\n\n" .
                      "🛍️ *Available Categories*\n" .
                      "• Smartphones 📱\n" .
                      "• Laptops 💻\n" .
                      "• Televisions 📺\n" .
                      "• Headphones 🎧\n" .
                      "• Smartwatches ⌚\n\n" .
                      "💡 *Tips*\n" .
                      "• You can track up to 5 categories\n" .
                      "• Set price limits for better deals\n" .
                      "• Choose Amazon, Flipkart or both\n" .
                      "• Get instant notifications for deals\n\n" .
                      "🔄 *How It Works*\n" .
                      "1. Use /startalert to begin\n" .
                      "2. Choose your categories\n" .
                      "3. Set optional price limits\n" .
                      "4. Get deal notifications!\n\n" .
                      "❓ *Need More Help?*\n" .
                      "Visit: https://amezprice.com/help\n" .
                      "Contact: @AmezPriceSupport\n\n" .
                      "Happy Deal Hunting! 🎯";

        sendHotDealsMessage($chatId, $helpMessage);

        // Send quick action buttons
        $actionButtons = [
            'inline_keyboard' => [
                [
                    ['text' => '🚀 Start Alerts', 'callback_data' => 'command_startalert'],
                    ['text' => '🛑 Stop Alerts', 'callback_data' => 'command_stopalert']
                ],
                [
                    ['text' => '🛍️ Visit AmezPrice', 'url' => 'https://amezprice.com'],
                    ['text' => '📱 Join Channel', 'url' => $telegramConfig['channels']['hotdeals']]
                ]
            ]
        ];

        sendHotDealsMessage($chatId, "Quick Actions:", $actionButtons);
        return;
    }

    if ($message === '/startalert') {
        $buttons = array_filter($telegramConfig['buttons']['hotdeals'], fn($btn) => $btn['enabled']);
        $replyMarkup = [
            'inline_keyboard' => array_map(fn($btn) => [['text' => $btn['text'], 'url' => $btn['url']]], $buttons)
        ];

        sendHotDealsMessage($chatId, "🎉 Welcome to AmezPrice Hot Deals!\n\n" .
                                    "I'm your go-to bot for finding the hottest deals on Amazon and Flipkart, delivering alerts for your favorite categories.\n\n" .
                                    "Choose a category to get started, and I'll send you the best offers tailored to your preferences.\n\n" .
                                    "Available categories:\n" .
                                    "• Smartphones 📱\n" .
                                    "• Laptops 💻\n" .
                                    "• Televisions 📺\n" .
                                    "• Headphones 🎧\n" .
                                    "• Smartwatches ⌚", $replyMarkup);
        sendHotDealsMessage($chatId, "Which categories would you like deal alerts for? Just type them and send!");
        return;
    }

    if ($message === '/stopalert') {
        $stmt = $pdo->prepare("SELECT id, category, merchant FROM hotdealsbot_user_categories WHERE user_id = ?");
        $stmt->execute([$chatId]);
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($categories) === 0) {
            sendHotDealsMessage($chatId, "You don't have any active alerts! Use /startalert to begin.");
            return;
        }

        if (count($categories) === 1) {
            $stmt = $pdo->prepare("DELETE FROM hotdealsbot_user_categories WHERE user_id = ?");
            $stmt->execute([$chatId]);
            sendHotDealsMessage($chatId, "All deal alerts stopped! Use /startalert to set new alerts.");
            return;
        }

        $message = "Select a category to stop alerts:\n\n";
        $buttons = [];
        foreach ($categories as $index => $category) {
            $message .= ($index + 1) . ". {$category['category']} ({$category['merchant']})\n";
            $buttons[] = ['text' => (string)($index + 1), 'callback_data' => "stop_category_{$category['id']}"];
        }

        $replyMarkup = ['inline_keyboard' => array_chunk($buttons, 2)];
        sendHotDealsMessage($chatId, $message, $replyMarkup);
        return;
    }

    // Category selection
    if (!empty($message) && !preg_match('/^\d+$/', $message)) {
        $stmt = $pdo->prepare("SELECT DISTINCT category FROM products WHERE category IS NOT NULL");
        $stmt->execute();
        $validCategories = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Simple category mapping
        $category = strtolower($message);
        if (in_array($category, ['mobile', 'phone'])) $category = 'smartphone';
        if (in_array($category, ['tv', 'television'])) $category = 'television';

        if (!in_array($category, $validCategories)) {
            sendHotDealsMessage($chatId, "Sorry, that category isn't available. Choose from: " . implode(', ', $validCategories));
            return;
        }

        // Check category limit
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM hotdealsbot_user_categories WHERE user_id = ?");
        $stmt->execute([$chatId]);
        if ($stmt->fetchColumn() >= 5) {
            sendHotDealsMessage($chatId, "You can only track 5 categories. Remove some using /stopalert first!");
            return;
        }

        sendHotDealsMessage($chatId, "Great choice! Select deal alerts from:", [
            'inline_keyboard' => [
                [
                    ['text' => 'Amazon 🛍️', 'callback_data' => "merchant_{$category}_amazon"],
                    ['text' => 'Flipkart 🛒', 'callback_data' => "merchant_{$category}_flipkart"]
                ],
                [
                    ['text' => 'Both Platforms 🔥', 'callback_data' => "merchant_{$category}_both"]
                ]
            ]
        ]);
        return;
    }

    // Handle price input
    if (preg_match('/^\d+$/', $message)) {
        $stmt = $pdo->prepare("
            SELECT id, category, merchant 
            FROM hotdealsbot_user_categories 
            WHERE user_id = ? AND price_range IS NULL 
            ORDER BY created_at DESC 
            LIMIT 1"
        );
        $stmt->execute([$chatId]);
        $lastCategory = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$lastCategory) {
            sendHotDealsMessage($chatId, "Please select a category first using /startalert");
            return;
        }

        $price = (float)$message;
        if (!validatePriceRange($lastCategory['category'], $price, $pdo)) {
            sendHotDealsMessage($chatId, "Please enter a reasonable price for {$lastCategory['category']} products!");
            return;
        }

        $stmt = $pdo->prepare("UPDATE hotdealsbot_user_categories SET price_range = ? WHERE id = ?");
        $stmt->execute([$price, $lastCategory['id']]);

        sendHotDealsMessage(
            $chatId, 
            "✅ Price alert set for {$lastCategory['category']} deals up to ₹" . number_format($price, 0, '.', ',') . "!\n" .
            "You'll get notifications when great deals are available."
        );
        return;
    }

    // Handle callback queries
    if (isset($input['callback_query'])) {
        $callback = $input['callback_query'];
        $data = $callback['data'];

        if (strpos($data, 'command_') === 0) {
            $command = substr($data, 8); // Remove 'command_' prefix
            switch ($command) {
                case 'startalert':
                    handleHotDealsMessage($chatId, '/startalert', $user, null);
                    return;
                case 'stopalert':
                    handleHotDealsMessage($chatId, '/stopalert', $user, null);
                    return;
            }
        }

        if (preg_match('/^merchant_(.+)_(.+)$/', $data, $matches)) {
            $category = $matches[1];
            $merchant = $matches[2];

            if ($merchant !== 'amazon' && $merchant !== 'flipkart' && $merchant !== 'both') {
                sendHotDealsMessage($chatId, "Invalid merchant selection.");
                return;
            }

            $stmt = $pdo->prepare("
                INSERT INTO hotdealsbot_user_categories (user_id, category, merchant)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$chatId, $category, $merchant]);

            sendHotDealsMessage($chatId, "Would you like to set a price range for this category?", [
                'inline_keyboard' => [
                    [
                        ['text' => 'Yes 💰', 'callback_data' => "price_range_{$category}_{$merchant}"],
                        ['text' => 'No, All Deals 🎯', 'callback_data' => "no_price_{$category}_{$merchant}"]
                    ]
                ]
            ]);
            return;
        }

        if (preg_match('/^price_range_(.+)_(.+)$/', $data, $matches)) {
            sendHotDealsMessage($chatId, "Please send the maximum price you're willing to pay.");
            return;
        }

        if (preg_match('/^no_price_(.+)_(.+)$/', $data, $matches)) {
            $category = $matches[1];
            sendHotDealsMessage($chatId, "🎉 Perfect! You'll get all deal alerts for *{$category}*!");
            return;
        }

        if (preg_match('/^stop_category_(\d+)$/', $data, $matches)) {
            $categoryId = $matches[1];
            $stmt = $pdo->prepare("SELECT category, merchant FROM hotdealsbot_user_categories WHERE id = ? AND user_id = ?");
            $stmt->execute([$categoryId, $chatId]);
            $category = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($category) {
                sendHotDealsMessage($chatId, "Are you sure you want to stop *{$category['category']}* deals?", [
                    'inline_keyboard' => [
                        [
                            ['text' => 'Yes ✋', 'callback_data' => "confirm_stop_{$categoryId}"],
                            ['text' => 'No ▶️', 'callback_data' => "cancel_stop"]
                        ]
                    ]
                ]);
            }
            return;
        }

        if (preg_match('/^confirm_stop_(\d+)$/', $data, $matches)) {
            $categoryId = $matches[1];
            $stmt = $pdo->prepare("DELETE FROM hotdealsbot_user_categories WHERE id = ? AND user_id = ?");
            $stmt->execute([$categoryId, $chatId]);
            sendHotDealsMessage($chatId, "✅ Deal alerts stopped for this category!");
            return;
        }

        if ($data === 'cancel_stop') {
            sendHotDealsMessage($chatId, "👍 Great! Your deal alerts will continue as before.");
            return;
        }
    }

    // Default response
    sendHotDealsMessage($chatId, 
        "Welcome to Hot Deals Bot! 🎉\n\n" .
        "Available commands:\n" .
        "• /start - Start the bot\n" .
        "• /startalert - Set up deal alerts\n" .
        "• /stopalert - Stop alerts\n" .
        "• /help - Show all commands\n\n" .
        "Or just send a category name to get started!"
    );
}
?>