<?php
require_once '../config/database.php';
require_once '../config/telegram.php';
require_once 'marketplaces/amazon.php';
require_once 'marketplaces/flipkart.php';
require_once '../middleware/csrf.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$userId = $input['user_id'] ?? null;
$productUrl = filter_var($input['product_url'] ?? '', FILTER_VALIDATE_URL);
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';

if ($apiKey !== $telegramConfig['api_key']) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid API key']);
    exit;
}

if (!$userId || !$productUrl) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid input']);
    exit;
}

// Rate limiting for Telegram users
$stmt = $pdo->prepare("SELECT COUNT(*) FROM user_requests WHERE user_id = ? AND created_at > NOW() - INTERVAL 1 HOUR");
$stmt->execute([$userId]);
if ($stmt->fetchColumn() >= 5) {
    echo json_encode(['status' => 'error', 'message' => 'You’ve reached your limit of 5 products per hour. Try again later!']);
    exit;
}

// Check total tracked products
$stmt = $pdo->prepare("SELECT COUNT(*) FROM user_products WHERE user_id = ?");
$stmt->execute([$userId]);
if ($stmt->fetchColumn() >= 50) {
    echo json_encode(['status' => 'error', 'message' => 'You can only track up to 50 products.']);
    exit;
}

// Extract ASIN/ID
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

// Check if product exists
$stmt = $pdo->prepare("SELECT * FROM products WHERE asin = ? AND merchant = ?");
$stmt->execute([$asin, $merchant]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
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

    $product = [
        'asin' => $asin,
        'merchant' => $merchant,
        'name' => $result['name'],
        'current_price' => $result['current_price'],
        'website_url' => "https://amezprice.com/history/{$merchant}/pid={$asin}",
        'affiliate_link' => $result['affiliate_link']
    ];
}

// Link product to user
$stmt = $pdo->prepare("
    INSERT INTO user_products (user_id, product_asin, product_url, price_history_url)
    VALUES (?, ?, ?, ?)
");
$stmt->execute([
    $userId,
    $asin,
    $productUrl,
    $product['website_url']
]);

// Log request
$stmt = $pdo->prepare("INSERT INTO user_requests (user_id, asin) VALUES (?, ?)");
$stmt->execute([$userId, $asin]);

// Count trackers
$stmt = $pdo->prepare("SELECT COUNT(*) FROM user_products WHERE product_asin = ?");
$stmt->execute([$asin]);
$trackerCount = $stmt->fetchColumn();

echo json_encode([
    'status' => 'success',
    'product_name' => $product['name'],
    'current_price' => $product['current_price'],
    'history_url' => $product['website_url'],
    'affiliate_link' => $product['affiliate_link'],
    'tracker_count' => $trackerCount
]);
?>