<?php
require_once '../config/database.php';
require_once '../config/globals.php';
require_once '../push_notification/web-push.php';

$pdo->beginTransaction();
try {
    // Delete old keys
    $stmt = $pdo->prepare("DELETE FROM vapid_keys WHERE created_at < NOW() - INTERVAL 1 MONTH");
    $stmt->execute();
    $deletedCount = $stmt->rowCount();

    // Generate new VAPID keys
    $vapid = generateVapidKeys();

    $pdo->commit();
    file_put_contents('../logs/cron.log', "[" . date('Y-m-d H:i:s') . "] VAPID keys updated: Deleted $deletedCount old keys\n", FILE_APPEND);
} catch (Exception $e) {
    $pdo->rollBack();
    file_put_contents('../logs/cron.log', "[" . date('Y-m-d H:i:s') . "] VAPID update failed: " . $e->getMessage() . "\n", FILE_APPEND);
}
?>