<?php
require_once '../../config/database.php';
require_once '../../config/globals.php';
require_once '../config/ai_config.php';
require_once '../../../vendor/autoload.php';

use Rubix\ML\Learners\GradientBoost;
use Rubix\ML\Datasets\Labeled;
use Rubix\ML\CrossValidation\KFold;

$config = include '../config/ai_config.php';
$stmt = $pdo->query("SELECT asin, price_history, category FROM products LIMIT {$config['batch_size']}");
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

$festivalStmt = $pdo->query("SELECT event_name, event_date, offers_likely FROM festivals WHERE event_date >= CURDATE()");
$festivals = $festivalStmt->fetchAll(PDO::FETCH_ASSOC);

$bestModel = null;
$bestScore = 0;

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
            $index / count($history),
            in_array($product['category'], ['smartphone', 'television']) ? 1 : 0,
            array_reduce($festivals, fn($carry, $f) => $carry + ($f['offers_likely'] && abs(strtotime($date) - strtotime($f['event_date'])) < 604800 ? 1 : 0), 0)
        ];
        $labels[] = $nextPrice;
    }

    $dataset = new Labeled($samples, $labels);
    $model = new GradientBoost([
        'max_depth' => 5,
        'learning_rate' => 0.1,
        'n_estimators' => 100
    ]);

    $validator = new KFold(5);
    $score = $validator->test($model, $dataset, 'mean_absolute_error');

    if ($score > $bestScore + $config['accuracy_threshold']) {
        $bestModel = $model;
        $bestScore = $score;
    }
}

if ($bestModel) {
    $bestModel->save("../models/price_model_{$config['model_version']}.ser");
    file_put_contents("../models/params_{$config['model_version']}.json", json_encode([
        'max_depth' => 5,
        'learning_rate' => 0.1,
        'n_estimators' => 100
    ]));
    file_put_contents('../logs/training.log', "[" . date('Y-m-d H:i:s') . "] Updated model {$config['model_version']}, accuracy +{$bestScore}\n", FILE_APPEND);
}
?>