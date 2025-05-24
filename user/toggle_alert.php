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
$type = $input['type'] ?? null;
$enabled = filter_var($input['enabled'], FILTER_VALIDATE_BOOLEAN);

if (!$productId || !in_array($type, ['email', 'push'])) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid input']);
    exit;
}

$userId = $_SESSION['user_id'];
$column = $type === 'email' ? 'email_alert' : 'push_alert';

$stmt = $pdo->prepare("UPDATE user_products SET $column = ? WHERE user_id = ? AND product_asin = ?");
$stmt->execute([$enabled ? 1 : 0, $userId, $productId]);

if ($stmt->rowCount() > 0) {
    file_put_contents('USER_LOG_PATH', "[" . date('Y-m-d H:i:s') . "] User ID $userId " . ($enabled ? 'enabled' : 'disabled') . " $type alert for product $productId\n", FILE_APPEND);
    echo json_encode(['status' => 'success', 'message' => 'Alert updated']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Product not found']);
}
?>