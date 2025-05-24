<?php
require_once '../config/database.php';
require_once '../config/telegram.php';
require_once '../middleware/csrf.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$userId = $input['user_id'] ?? null;
$asin = $input['asin'] ?? '';
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';

if ($apiKey !== $telegramConfig['api_key']) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid API key']);
    exit;
}

if (!$userId || !$asin) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid input']);
    exit;
}

$stmt = $pdo->prepare("DELETE FROM user_products WHERE user_id = ? AND product_asin = ?");
$stmt->execute([$userId, $asin]);

if ($stmt->rowCount() > 0) {
    echo json_encode(['status' => 'success', 'message' => 'Product removed']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Product not found']);
}
?>