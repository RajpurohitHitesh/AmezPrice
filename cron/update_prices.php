<?php
require_once '../config/database.php';
require_once '../config/globals.php';
require_once '../api/marketplaces/amazon_fetch.php';
require_once '../api/marketplaces/flipkart_fetch.php';
require_once '../api/price_monitor.php';

try {
    // amazon_fetch.php, flipkart_fetch.php, and price_monitor.php contain inline logic that executes when included
    file_put_contents('../logs/cron.log', "[" . date('Y-m-d H:i:s') . "] Price update cron executed successfully\n", FILE_APPEND);
} catch (Exception $e) {
    file_put_contents('../logs/cron.log', "[" . date('Y-m-d H:i:s') . "] Price update cron failed: " . $e->getMessage() . "\n", FILE_APPEND);
}
?>