<?php
require_once '../config/database.php';
require_once '../config/globals.php';

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("
        DELETE FROM hotdealsbot_user_categories 
        WHERE user_id NOT IN (SELECT id FROM hotdealsbot_users)
    ");
    $stmt->execute();
    $deletedCount = $stmt->rowCount();

    $pdo->commit();
    file_put_contents('../logs/cleanup.log', "[" . date('Y-m-d H:i:s') . "] HotDeals cleanup cron executed: Removed $deletedCount categories\n", FILE_APPEND);
} catch (Exception $e) {
    $pdo->rollBack();
    file_put_contents('../logs/cleanup.log', "[" . date('Y-m-d H:i:s') . "] HotDeals cleanup cron failed: " . $e->getMessage() . "\n", FILE_APPEND);
}
?>