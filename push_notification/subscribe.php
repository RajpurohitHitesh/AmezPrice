<?php
require_once '../config/database.php';
require_once '../middleware/csrf.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$subscription = $input['subscription'] ?? null;
$productId = $input['product_id'] ?? null;
$userId = $_SESSION['user_id'] ?? null;

if (!$userId || !$subscription) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid input']);
    exit;
}

$stmt = $pdo->prepare("
    INSERT INTO push_subscriptions (user_id, subscription, product_id)
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE subscription = ?, product_id = ?
");
$stmt->execute([
    $userId,
    json_encode($subscription),
    $productId,
    json_encode($subscription),
    $productId
]);

echo json_encode(['status' => 'success', 'message' => 'Subscription saved']);
?>