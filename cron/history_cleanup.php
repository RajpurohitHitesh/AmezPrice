<?php
require_once '../config/database.php';
require_once '../config/globals.php';

$pdo->beginTransaction();
try {
    $stmt = $pdo->query("SELECT asin, price_history FROM products");
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($products as $product) {
        $history = json_decode($product['price_history'], true);
        if (empty($history)) continue;

        $newHistory = [];
        $monthlyData = [];

        foreach ($history as $date => $price) {
            $month = substr($date, 0, 7);
            if (!isset($monthlyData[$month])) {
                $monthlyData[$month] = ['highest' => $price, 'lowest' => $price];
            } else {
                $monthlyData[$month]['highest'] = max($monthlyData[$month]['highest'], $price);
                $monthlyData[$month]['lowest'] = min($monthlyData[$month]['lowest'], $price);
            }
        }

        foreach ($monthlyData as $month => $data) {
            $newHistory[$month] = ['highest' => $data['highest'], 'lowest' => $data['lowest']];
        }

        // Keep only last 24 months
        $cutoff = date('Y-m', strtotime('-24 months'));
        $newHistory = array_filter($newHistory, function($month) use ($cutoff) {
            return $month >= $cutoff;
        }, ARRAY_FILTER_USE_KEY);

        $stmt = $pdo->prepare("UPDATE products SET price_history = ? WHERE asin = ?");
        $stmt->execute([json_encode($newHistory), $product['asin']]);
    }

    $pdo->commit();
    file_put_contents('../logs/history_cleanup.log', "[" . date('Y-m-d H:i:s') . "] History cleanup cron executed\n", FILE_APPEND);
} catch (Exception $e) {
    $pdo->rollBack();
    file_put_contents('../logs/history_cleanup.log', "[" . date('Y-m-d H:i:s') . "] History cleanup cron failed: " . $e->getMessage() . "\n", FILE_APPEND);
}
?>