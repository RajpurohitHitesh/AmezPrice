<?php
require_once '../config/database.php';
require_once '../config/telegram.php';
require_once 'marketplaces/amazon.php';
require_once 'marketplaces/flipkart.php';

// Define constant for repeated query
const USER_TRACKER_QUERY = "SELECT user_id FROM user_products WHERE product_asin = ?";

$stmt = $pdo->query("SELECT * FROM products");
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($products as $product) {
    $fetchFunction = $product['merchant'] === 'amazon' ? 'fetchAmazonProduct' : 'fetchFlipkartProduct';
    $result = $fetchFunction($product['asin']);

    if ($result['status'] === 'success') {
        // Update product details
        $stmt = $pdo->prepare("
            UPDATE products 
            SET 
                current_price = ?,
                highest_price = GREATEST(highest_price, ?),
                lowest_price = LEAST(lowest_price, ?),
                stock_status = ?,
                stock_quantity = ?,
                rating = ?,
                rating_count = ?,
                price_history = JSON_SET(price_history, ?, ?),
                last_updated = NOW()
            WHERE asin = ?
        ");
        $stmt->execute([
            $result['current_price'],
            $result['current_price'],
            $result['current_price'] ?: $result['current_price'],
            $result['stock_status'],
            $result['stock_quantity'],
            $result['rating'],
            $result['rating_count'],
            '$."' . date('Y-m-d') . '"',
            $result['current_price'],
            $product['asin']
        ]);

        // Check for price change
        if ($result['current_price'] != $product['current_price']) {
            $previousPrice = $product['current_price'];
            $currentPrice = $result['current_price'];
            $trackerStmt = $pdo->prepare(USER_TRACKER_QUERY);
            $trackerStmt->execute([$product['asin']]);
            $userIds = $trackerStmt->fetchAll(PDO::FETCH_COLUMN);

            $trackerCountStmt = $pdo->prepare("SELECT COUNT(*) FROM user_products WHERE product_asin = ?");
            $trackerCountStmt->execute([$product['asin']]);
            $trackerCount = $trackerCountStmt->fetchColumn();

            $payload = [
                'user_ids' => $userIds,
                'asin' => $product['asin'],
                'message_type' => $currentPrice < $previousPrice ? 'price_drop' : 'price_increase',
                'details' => [
                    'previous_price' => $previousPrice,
                    'current_price' => $currentPrice,
                    'tracker_count' => $trackerCount
                ]
            ];

            $ch = curl_init('https://amezprice.com/api/notify.php');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-API-Key: ' . $telegramConfig['api_key']]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_exec($ch);
            curl_close($ch);

            // Check for price threshold alerts
            $thresholdStmt = $pdo->prepare("
                SELECT user_id, price_threshold 
                FROM user_products 
                WHERE product_asin = ? AND price_threshold IS NOT NULL AND price_threshold >= ?
            ");
            $thresholdStmt->execute([$product['asin'], $currentPrice]);
            $thresholdUsers = $thresholdStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($thresholdUsers as $user) {
                $payload = [
                    'user_ids' => [$user['user_id']],
                    'asin' => $product['asin'],
                    'message_type' => 'price_drop',
                    'details' => [
                        'previous_price' => $previousPrice,
                        'current_price' => $currentPrice,
                        'tracker_count' => $trackerCount
                    ]
                ];

                $ch = curl_init('https://amezprice.com/api/notify.php');
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-API-Key: ' . $telegramConfig['api_key']]);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_exec($ch);
                curl_close($ch);

                // Clear threshold
                $clearStmt = $pdo->prepare("UPDATE user_products SET price_threshold = NULL WHERE user_id = ? AND product_asin = ?");
                $clearStmt->execute([$user['user_id'], $product['asin']]);
            }
        }

        // Check stock status changes
        if ($result['stock_status'] === 'out_of_stock' && $product['stock_status'] === 'in_stock') {
            $trackerStmt = $pdo->prepare(USER_TRACKER_QUERY);
            $trackerStmt->execute([$product['asin']]);
            $userIds = $trackerStmt->fetchAll(PDO::FETCH_COLUMN);

            $trackerCountStmt = $pdo->prepare("SELECT COUNT(*) FROM user_products WHERE product_asin = ?");
            $trackerCountStmt->execute([$product['asin']]);
            $trackerCount = $trackerCountStmt->fetchColumn();

            $payload = [
                'user_ids' => $userIds,
                'asin' => $product['asin'],
                'message_type' => 'out_of_stock',
                'details' => [
                    'previous_price' => $product['current_price'],
                    'current_price' => $result['current_price'],
                    'tracker_count' => $trackerCount
                ]
            ];

            $ch = curl_init('https://amezprice.com/api/notify.php');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-API-Key: ' . $telegramConfig['api_key']]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_exec($ch);
            curl_close($ch);

            $stmt = $pdo->prepare("UPDATE products SET out_of_stock_since = NOW() WHERE asin = ?");
            $stmt->execute([$product['asin']]);
        } elseif ($result['stock_status'] === 'in_stock' && $product['stock_status'] === 'out_of_stock') {
            $trackerStmt = $pdo->prepare(USER_TRACKER_QUERY);
            $trackerStmt->execute([$product['asin']]);
            $userIds = $trackerStmt->fetchAll(PDO::FETCH_COLUMN);

            $trackerCountStmt = $pdo->prepare("SELECT COUNT(*) FROM user_products WHERE product_asin = ?");
            $trackerCountStmt->execute([$product['asin']]);
            $trackerCount = $trackerCountStmt->fetchColumn();

            $payload = [
                'user_ids' => $userIds,
                'asin' => $product['asin'],
                'message_type' => 'in_stock',
                'details' => [
                    'previous_price' => $product['current_price'],
                    'current_price' => $result['current_price'],
                    'tracker_count' => $trackerCount
                ]
            ];

            $ch = curl_init('https://amezprice.com/api/notify.php');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-API-Key: ' . $telegramConfig['api_key']]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_exec($ch);
            curl_close($ch);

            $stmt = $pdo->prepare("UPDATE products SET out_of_stock_since = NULL WHERE asin = ?");
            $stmt->execute([$product['asin']]);
        } elseif ($result['stock_quantity'] <= 7 && $product['stock_quantity'] > 7) {
            $trackerStmt = $pdo->prepare(USER_TRACKER_QUERY);
            $trackerStmt->execute([$product['asin']]);
            $userIds = $trackerStmt->fetchAll(PDO::FETCH_COLUMN);

            $trackerCountStmt = $pdo->prepare("SELECT COUNT(*) FROM user_products WHERE product_asin = ?");
            $trackerCountStmt->execute([$product['asin']]);
            $trackerCount = $trackerCountStmt->fetchColumn();

            $payload = [
                'user_ids' => $userIds,
                'asin' => $product['asin'],
                'message_type' => 'low_stock',
                'details' => [
                    'quantity' => $result['stock_quantity'],
                    'previous_price' => $product['current_price'],
                    'current_price' => $result['current_price'],
                    'tracker_count' => $trackerCount
                ]
            ];

            $ch = curl_init('https://amezprice.com/api/notify.php');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-API-Key: ' . $telegramConfig['api_key']]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_exec($ch);
            curl_close($ch);
        }
    }
}
?>