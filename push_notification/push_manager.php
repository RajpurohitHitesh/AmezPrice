<?php
require_once '../config/database.php';
require_once 'web-push.php';

function sendPushNotification($subscription, $data) {
    global $pdo;

    $stmt = $pdo->prepare("SELECT public_key, private_key FROM vapid_keys ORDER BY created_at DESC LIMIT 1");
    $stmt->execute();
    $vapid = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$vapid) {
        return ['status' => 'error', 'message' => 'VAPID keys not found'];
    }

    $webPush = new WebPushService([
        'VAPID' => [
            'subject' => 'mailto:support@amezprice.com',
            'publicKey' => $vapid['public_key'],
            'privateKey' => $vapid['private_key']
        ]
    ]);

    $payload = [
        'title' => $data['title'],
        'message' => $data['message'],
        'previous_price' => $data['previous_price'],
        'current_price' => $data['current_price'],
        'tracker_count' => $data['tracker_count'],
        'image_path' => $data['image_path'],
        'affiliate_link' => $data['affiliate_link'],
        'history_url' => $data['history_url'],
        'category' => $data['category'],
        'product_id' => $data['product_id'],
        'urgency' => $data['urgency'] ?? 'normal',
        'timestamp' => time() * 1000
    ];

    $options = [
        'TTL' => $data['ttl'] ?? 2419200,
        'urgency' => $data['urgency'] ?? 'normal'
    ];

    if ($webPush->sendNotification($subscription, $payload, $options)) {
        return ['status' => 'success', 'message' => 'Notification sent'];
    } else {
        return ['status' => 'error', 'message' => 'Failed to send notification'];
    }
}

function sendPriceDropNotification($userId, $product) {
    global $pdo;

    $stmt = $pdo->prepare("SELECT subscription FROM push_subscriptions WHERE user_id = ? AND product_id = ?");
    $stmt->execute([$userId, $product['asin']]);
    $subscription = $stmt->fetchColumn();

    if ($subscription) {
        $trackerCountStmt = $pdo->prepare("SELECT COUNT(*) FROM user_products WHERE product_asin = ?");
        $trackerCountStmt->execute([$product['asin']]);
        $trackerCount = $trackerCountStmt->fetchColumn();

        $data = [
            'title' => $product['name'],
            'message' => "Price dropped by ₹" . number_format($product['previous_price'] - $product['current_price'], 0, '.', ','),
            'previous_price' => $product['previous_price'],
            'current_price' => $product['current_price'],
            'tracker_count' => $trackerCount,
            'image_path' => $product['image_path'],
            'affiliate_link' => $product['affiliate_link'],
            'history_url' => "https://amezprice.com/history/{$product['merchant']}/pid={$product['asin']}",
            'category' => $product['category'],
            'product_id' => $product['asin'],
            'urgency' => $product['is_flash_deal'] ? 'high' : 'normal',
            'ttl' => $product['is_flash_deal'] ? 300 : 2419200
        ];

        return sendPushNotification($subscription, $data);
    }

    return ['status' => 'error', 'message' => 'No subscription found'];
}

function sendBatchPriceDropNotifications($userId, $products) {
    global $pdo;

    $stmt = $pdo->prepare("SELECT subscription FROM push_subscriptions WHERE user_id = ?");
    $stmt->execute([$userId]);
    $subscriptions = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($subscriptions)) {
        return ['status' => 'error', 'message' => 'No subscriptions found'];
    }

    $webPush = new WebPushService([
        'VAPID' => [
            'subject' => 'mailto:support@amezprice.com',
            'publicKey' => $vapid['public_key'],
            'privateKey' => $vapid['private_key']
        ]
    ]);

    $payload = [
        'title' => 'Multiple Deals Alert!',
        'message' => 'New price drops for your tracked products!',
        'deals' => array_map(function($product) use ($trackerCountStmt) {
            $trackerCountStmt->execute([$product['asin']]);
            return [
                'name' => $product['name'],
                'previous_price' => $product['previous_price'],
                'current_price' => $product['current_price'],
                'tracker_count' => $trackerCountStmt->fetchColumn(),
                'image_path' => $product['image_path'],
                'affiliate_link' => $product['affiliate_link'],
                'history_url' => "https://amezprice.com/history/{$product['merchant']}/pid={$product['asin']}",
                'category' => $product['category'],
                'product_id' => $product['asin'],
                'urgency' => $product['is_flash_deal'] ? 'high' : 'normal',
                'timestamp' => time() * 1000
            ];
        }, $products),
        'urgency' => 'normal',
        'timestamp' => time() * 1000
    ];

    if ($webPush->sendBatchNotifications($subscriptions, $payload)) {
        return ['status' => 'success', 'message' => 'Batch notifications sent'];
    } else {
        return ['status' => 'error', 'message' => 'Failed to send batch notifications'];
    }
}
?>