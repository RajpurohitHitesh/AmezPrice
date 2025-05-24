<?php
require_once '../../config/database.php';

function stats_standard_deviation($arr) {
    $num_of_elements = count($arr);
    if ($num_of_elements == 0) return 0;
    $mean = array_sum($arr) / $num_of_elements;
    $variance = array_sum(array_map(fn($x) => pow($x - $mean, 2), $arr)) / $num_of_elements;
    return sqrt($variance);
}

$stmt = $pdo->query("SELECT asin, price_history FROM products");
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($products as $product) {
    $history = json_decode($product['price_history'], true);
    $dates = array_keys($history);
    $prices = array_values($history);

    $patterns = [];
    for ($i = 1; $i < count($prices); $i++) {
        if ($prices[$i] < $prices[$i - 1]) {
            $drop = ($prices[$i - 1] - $prices[$i]) / $prices[$i - 1] * 100;
            if ($drop >= 8) {
                $day = date('l', strtotime($dates[$i]));
                $patterns[$day][] = $drop;
            }
        }
    }

    foreach ($patterns as $day => $drops) {
        if (count($drops) >= 3 && stats_standard_deviation($drops) < 2) {
            $avgDrop = array_sum($drops) / count($drops);
            $description = "This product drops " . round($avgDrop) . "% every $day at 6PM!";
            $stmt = $pdo->prepare("
                INSERT INTO patterns (asin, pattern_description, confidence)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE pattern_description = ?, confidence = ?
            ");
            $stmt->execute([
                $product['asin'],
                $description,
                0.9,
                $description,
                0.9
            ]);
        }
    }
}
?>