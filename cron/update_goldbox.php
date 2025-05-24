<?php
require_once '../config/database.php';
require_once '../config/globals.php';
require_once '../api/marketplaces/amazon.php';

try {
    $deals = fetchAmazonDeals();
    if (empty($deals)) {
        throw new Exception('No deals fetched from Amazon');
    }

    $pdo->beginTransaction();
    $stmt = $pdo->query("TRUNCATE TABLE goldbox_products");

    foreach ($deals as $deal) {
        if (!isset($deal['asin'], $deal['name'], $deal['current_price'], $deal['discount_percentage'], $deal['affiliate_link'], $deal['image_url'])) {
            continue;
        }

        $stmt = $pdo->prepare("
            INSERT INTO goldbox_products (asin, name, current_price, discount_percentage, affiliate_link, image_url, last_updated)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $deal['asin'],
            $deal['name'],
            $deal['current_price'],
            $deal['discount_percentage'],
            $deal['affiliate_link'],
            $deal['image_url']
        ]);
    }

    $pdo->commit();
    file_put_contents('../logs/cron.log', "[" . date('Y-m-d H:i:s') . "] Goldbox update cron executed successfully\n", FILE_APPEND);
} catch (Exception $e) {
    $pdo->rollBack();
    file_put_contents('../logs/cron.log', "[" . date('Y-m-d H:i:s') . "] Goldbox update cron failed: " . $e->getMessage() . "\n", FILE_APPEND);
}
?>