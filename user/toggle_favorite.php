<?php
require_once '../config/database.php';
require_once '../config/security.php';
require_once '../config/globals.php';
require_once '../middleware/csrf.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');
$input = json_decode(file_get_contents('php://input'), true);
$productId = $input['product_id'] ?? null;
$isFavorite = filter_var($input['is_favorite'], FILTER_VALIDATE_BOOLEAN);

if (!$productId) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid product ID']);
    exit;
}

$userId = $_SESSION['user_id'];

if ($isFavorite) {
    // Check favorite limit
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_products WHERE user_id = ? AND is_favorite = 1");
    $stmt->execute([$userId]);
    if ($stmt->fetchColumn() >= 200) {
        echo json_encode(['status' => 'error', 'message' => 'You can only add up to 200 products to your favorites']);
        exit;
    }
}

$stmt = $pdo->prepare("UPDATE user_products SET is_favorite = ? WHERE user_id = ? AND product_asin = ?");
$stmt->execute([$isFavorite ? 1 : 0, $userId, $productId]);

if ($stmt->rowCount() > 0) {
    // Log user behavior
    $stmt = $pdo->prepare("INSERT INTO user_behavior (user_id, asin, is_favorite, interaction_type) VALUES (?, ?, ?, ?)");
    $stmt->execute([$userId, $productId, $isFavorite ? 1 : 0, 'favorite']);

    file_put_contents('USER_LOG_PATH', "[" . date('Y-m-d H:i:s') . "] User ID $userId " . ($isFavorite ? 'added' : 'removed') . " product $productId to/from favorites\n", FILE_APPEND);
    echo json_encode(['status' => 'success', 'message' => 'Favorite updated']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Product not found']);
}
?>