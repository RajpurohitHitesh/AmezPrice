<?php
require_once '../../config/database.php';
require_once '../../config/flipkart.php';
require_once 'flipkart.php';

$maxRetries = 3;
$retryDelay = 5;

$stmt = $pdo->prepare("SELECT asin FROM products WHERE merchant = 'flipkart' AND (last_updated < NOW() - INTERVAL 24 HOUR OR last_updated IS NULL) LIMIT 20");
$stmt->execute();
$ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

$results = [];
$ch = [];
$mh = curl_multi_init();

foreach ($ids as $index => $id) {
    $ch[$index] = curl_init("https://affiliate-api.flipkart.net/products/{$id}");
    curl_setopt($ch[$index], CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch[$index], CURLOPT_HTTPHEADER, [
        "Fk-Affiliate-Id: {$flipkartConfig['affiliate_id']}",
        "Fk-Affiliate-Token: {$flipkartConfig['token']}"
    ]);
    curl_multi_add_handle($mh, $ch[$index]);
}

$running = null;
do {
    curl_multi_exec($mh, $running);
} while ($running);

foreach ($ids as $index => $id) {
    $response = curl_multi_getcontent($ch[$index]);
    $httpCode = curl_getinfo($ch[$index], CURLINFO_HTTP_CODE);
    curl_multi_remove_handle($mh, $ch[$index]);
    curl_close($ch[$index]);

    if ($httpCode === 200) {
        $results[$id] = fetchFlipkartProduct($id);
    } elseif ($httpCode === 429 || $httpCode === 500) {
        $attempt = 0;
        while ($attempt < $maxRetries) {
            sleep($retryDelay);
            $result = fetchFlipkartProduct($id);
            if ($result['status'] === 'success') {
                $results[$id] = $result;
                break;
            }
            $attempt++;
        }
    }
}

curl_multi_close($mh);

foreach ($results as $id => $result) {
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
            $id
        ]);
    }
}

sleep(1); // Respect Flipkart's 20 TPS limit
?>