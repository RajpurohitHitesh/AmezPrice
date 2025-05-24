<?php
require_once '../config/database.php';
require_once '../config/marketplaces.php';
require_once '../api/marketplaces/amazon.php';
require_once '../api/marketplaces/flipkart.php';
require_once '../middleware/csrf.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$productUrl = filter_var($input['url'] ?? '', FILTER_VALIDATE_URL);

if (!$productUrl) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid URL']);
    exit;
}

$merchant = '';
$asin = '';
if (preg_match('/amazon\./', $productUrl)) {
    $merchant = 'amazon';
    preg_match('/\/dp\/([A-Z0-9]+)/', $productUrl, $matches);
    $asin = $matches[1] ?? '';
} elseif (preg_match('/flipkart\./', $productUrl)) {
    $merchant = 'flipkart';
    preg_match('/pid=([A-Z0-9]+)/', $productUrl, $matches);
    $asin = $matches[1] ?? '';
}

if (!$merchant || !$asin) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid product URL']);
    exit;
}

if ($marketplaces[$merchant] !== 'active') {
    echo json_encode(['status' => 'error', 'message' => "This website ({$merchant}) service is temporarily unavailable"]);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM products WHERE asin = ? AND merchant = ?");
$stmt->execute([$asin, $merchant]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if ($product) {
    if ($product['current_price'] != $result['current_price']) {
        $stmt = $pdo->prepare("
            UPDATE products 
            SET 
                current_price = ?,
                highest_price = GREATEST(highest_price, ?),
                lowest_price = LEAST(lowest_price, ?),
                price_history = JSON_SET(price_history, ?, ?),
                last_updated = NOW()
            WHERE asin = ?
        ");
        $stmt->execute([
            $result['current_price'],
            $result['current_price'],
            $result['current_price'] ?: $result['current_price'],
            '$."' . date('Y-m-d') . '"',
            $result['current_price'],
            $asin
        ]);
    }

    echo json_encode([
        'status' => 'success',
        'name' => $product['name'],
        'current_price' => $product['current_price'],
        'image_path' => $product['image_path'],
        'website_url' => $product['website_url']
    ]);
    exit;
}

$fetchFunction = $merchant === 'amazon' ? 'fetchAmazonProduct' : 'fetchFlipkartProduct';
$result = $fetchFunction($asin);

if ($result['status'] !== 'success') {
    echo json_encode(['status' => 'error', 'message' => $result['message']]);
    exit;
}

$stmt = $pdo->prepare("
    INSERT INTO products (asin, merchant, name, current_price, highest_price, lowest_price, website_url, affiliate_link, image_path, stock_status, stock_quantity, rating, rating_count, price_history)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");
$stmt->execute([
    $asin,
    $merchant,
    $result['name'],
    $result['current_price'],
    $result['current_price'],
    $result['current_price'],
    "https://amezprice.com/history/{$merchant}/pid={$asin}",
    $result['affiliate_link'],
    $result['image_path'],
    $result['stock_status'],
    $result['stock_quantity'],
    $result['rating'],
    $result['rating_count'],
    json_encode([date('Y-m-d') => $result['current_price']])
]);

echo json_encode([
    'status' => 'success',
    'name' => $result['name'],
    'current_price' => $result['current_price'],
    'image_path' => $result['image_path'],
    'website_url' => "https://amezprice.com/history/{$merchant}/pid={$asin}"
]);
?>