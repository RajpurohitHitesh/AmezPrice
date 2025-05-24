<?php
require_once '../../config/database.php';
require_once '../config/ai_config.php';
require_once '../../../vendor/autoload.php';

use Rubix\ML\Learners\GradientBoost;
use Rubix\ML\Datasets\Labeled;

$stmt = $pdo->query("SELECT asin, price_history, category FROM products");
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

$festivalStmt = $pdo->query("SELECT event_name, event_date, offers_likely FROM festivals WHERE event_date >= CURDATE()");
$festivals = $festivalStmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($products as $product) {
    $history = json_decode($product['price_history'], true);
    if (count($history) < 3) continue;

    $samples = [];
    $labels = [];
    $dates = array_keys($history);
    foreach (array_slice($dates, 0, -1) as $index => $date) {
        $nextPrice = $history[$dates[$index + 1]];
        $samples[] = [
            $history[$date],
            $index / count($history), // Time progression
            in_array($product['category'], ['smartphone', 'television']) ? 1 : 0,
            array_reduce($festivals, fn($carry, $f) => $carry + ($f['offers_likely'] && abs(strtotime($date) - strtotime($f['event_date'])) < 604800 ? 1 : 0), 0)
        ];
        $labels[] = $nextPrice;
    }

    $dataset = new Labeled($samples, $labels);
    $model = new GradientBoost();
    $model->train($dataset);

    $currentSample = [
        end($history),
        1.0,
        in_array($product['category'], ['smartphone', 'television']) ? 1 : 0,
        array_reduce($festivals, fn($carry, $f) => $carry + ($f['offers_likely'] && abs(strtotime(date('Y-m-d')) - strtotime($f['event_date'])) < 604800 ? 1 : 0), 0)
    ];

    $predictions = [];
    for ($i = 1; $i <= 3; $i++) {
        $month = date('Y-m', strtotime("+{$i} month"));
        $predictions[] = [
            'asin' => $product['asin'],
            'predicted_price' => $model->predict([$currentSample])[0],
            'prediction_date' => $month . '-01',
            'period' => date('m-y', strtotime($month))
        ];
    }

    foreach ($predictions as $pred) {
        $stmt = $pdo->prepare("
            INSERT INTO predictions (asin, predicted_price, prediction_date, period)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE predicted_price = ?, prediction_date = ?, period = ?
        ");
        $stmt->execute([
            $pred['asin'],
            $pred['predicted_price'],
            $pred['prediction_date'],
            $pred['period'],
            $pred['predicted_price'],
            $pred['prediction_date'],
            $pred['period']
        ]);
    }
}
?>