<?php
require_once '../../config/database.php';
require_once '../../config/amazon.php';
require_once 'amazon.php';

$maxRetries = 3;
$retryDelay = 5;

$stmt = $pdo->prepare("SELECT asin FROM products WHERE merchant = 'amazon' AND (last_updated < NOW() - INTERVAL 24 HOUR OR last_updated IS NULL) LIMIT 10");
$stmt->execute();
$asins = $stmt->fetchAll(PDO::FETCH_COLUMN);

foreach ($asins as $asin) {
    $attempt = 0;
    while ($attempt < $maxRetries) {
        $result = fetchAmazonProduct($asin);
        if ($result['status'] === 'success') {
            $stmt = $pdo->prepare("
                UPDATE products 
                SET 
                    name = ?,
                    current_price = ?,
                    highest_price = GREATEST(highest_price, ?),
                    lowest_price = LEAST(lowest_price, ?),
                    affiliate_link = ?,
                    image_path = ?,
                    stock_status = ?,
                    stock_quantity = ?,
                    rating = ?,
                    rating_count = ?,
                    price_history = JSON_SET(price_history, ?, ?),
                    last_updated = NOW()
                WHERE asin = ?
            ");
            $stmt->execute([
                $result['name'],
                $result['current_price'],
                $result['current_price'],
                $result['current_price'] ?: $result['current_price'],
                $result['affiliate_link'],
                $result['image_path'],
                $result['stock_status'],
                $result['stock_quantity'],
                $result['rating'],
                $result['rating_count'],
                '$."' . date('Y-m-d') . '"',
                $result['current_price'],
                $asin
            ]);
            break;
        } elseif ($result['message'] === 'Too Many Requests' || $result['message'] === 'Service Unavailable') {
            $attempt++;
            sleep($retryDelay);
        } else {
            break;
        }
    }
    sleep(1); // Respect Amazon's 1 TPS limit
}
?>