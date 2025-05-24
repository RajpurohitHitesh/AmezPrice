<?php
require_once '../../config/database.php';
require_once '../../config/security.php';
require_once '../../config/globals.php';
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
if (!file_exists($logPath)) {
    echo json_encode(['status' => 'error', 'message' => 'Log file not found']);
    exit;
}

if (unlink($logPath)) {
    file_put_contents('ADMIN_LOG_PATH', "[" . date('Y-m-d H:i:s') . "] Log file $filename deleted by admin ID {$_SESSION['admin_id']}\n", FILE_APPEND);
    echo json_encode(['status' => 'success', 'message' => 'Log file deleted']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Failed to delete log file']);
}
?>