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

if (!$productId) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid product ID']);
    exit;
}

$userId = $_SESSION['user_id'];
$stmt = $pdo->prepare("DELETE FROM user_products WHERE user_id = ? AND product_asin = ?");
$stmt->execute([$userId, $productId]);

if ($stmt->rowCount() > 0) {
    file_put_contents('USER_LOG_PATH', "[" . date('Y-m-d H:i:s') . "] User ID $userId removed product $productId\n", FILE_APPEND);
    echo json_encode(['status' => 'success', 'message' => 'Product removed']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Product not found']);
}
?>