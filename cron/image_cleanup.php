<?php
require_once '../config/globals.php';

$tempDir = '../assets/images/products/temp/';
$files = glob($tempDir . '*.{jpg,png,webp}', GLOB_BRACE);

try {
    $deletedCount = 0;
    foreach ($files as $file) {
        if (filemtime($file) < time() - 48 * 3600) {
            if (unlink($file)) {
                $deletedCount++;
                file_put_contents('../logs/images.log', "[" . date('Y-m-d H:i:s') . "] Deleted temp image: $file\n", FILE_APPEND);
            } else {
                throw new Exception("Failed to delete temp image: $file");
            }
        }
    }
    file_put_contents('../logs/cron.log', "[" . date('Y-m-d H:i:s') . "] Image cleanup cron executed: Deleted $deletedCount images\n", FILE_APPEND);
} catch (Exception $e) {
    file_put_contents('../logs/cron.log', "[" . date('Y-m-d H:i:s') . "] Image cleanup cron failed: " . $e->getMessage() . "\n", FILE_APPEND);
}
?>