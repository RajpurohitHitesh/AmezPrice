<?php
require_once '../../config/database.php';
require_once '../../config/security.php';
require_once '../../middleware/csrf.php';
session_start();

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');
$input = json_decode(file_get_contents('php://input'), true);
$filename = $input['filename'] ?? '';

$logPath = "../../logs/$filename";
if (!file_exists($logPath) || !is_readable($logPath)) {
    echo json_encode(['status' => 'error', 'message' => 'Log file not found or inaccessible']);
    exit;
}

$content = file_get_contents($logPath);
echo json_encode(['status' => 'success', 'content' => htmlspecialchars($content)]);
?>