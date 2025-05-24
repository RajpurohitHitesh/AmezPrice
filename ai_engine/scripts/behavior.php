<?php
require_once '../../config/database.php';
require_once '../../config/globals.php';
require_once '../../../vendor/autoload.php';

use Rubix\ML\Clusterers\KMeans;
use Rubix\ML\Datasets\Unlabeled;

try {
    // Start execution time tracking for logging
    $startTime = microtime(true);

    // Fetch user behavior data with aggregation to optimize performance
    $stmt = $pdo->query("
        SELECT 
            user_id, 
            asin, 
            is_favorite, 
            is_ai_suggested, 
            interaction_type
        FROM user_behavior
    ");
    $behaviors = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Initialize user data with counters for all interaction types
    $userData = [];
    foreach ($behaviors as $behavior) {
        $userId = $behavior['user_id'];
        if (!isset($userData[$userId])) {
            $userData[$userId] = [
                'favorites' => 0,
                'ai_suggested' => 0,
                'buy_now' => 0,
                'price_history' => 0,
                'tracking' => 0,
                'notification_received' => 0,
                'notification_dismissed' => 0,
                'notification_buy_now' => 0,
                'notification_price_history' => 0,
                'notification_track' => 0,
                'notification_share' => 0,
                'notification_clicked' => 0
            ];
        }

        // Increment counters based on interaction type
        if ($behavior['is_favorite']) $userData[$userId]['favorites']++;
        if ($behavior['is_ai_suggested']) $userData[$userId]['ai_suggested']++;
        switch ($behavior['interaction_type']) {
            case 'buy_now':
                $userData[$userId]['buy_now']++;
                break;
            case 'price_history':
                $userData[$userId]['price_history']++;
                break;
            case 'tracking':
                $userData[$userId]['tracking']++;
                break;
            case 'notification_received':
                $userData[$userId]['notification_received']++;
                break;
            case 'notification_dismissed':
                $userData[$userId]['notification_dismissed']++;
                break;
            case 'notification_buy_now':
                $userData[$userId]['notification_buy_now']++;
                break;
            case 'notification_price_history':
                $userData[$userId]['notification_price_history']++;
                break;
            case 'notification_track':
                $userData[$userId]['notification_track']++;
                break;
            case 'notification_share':
                $userData[$userId]['notification_share']++;
                break;
            case 'notification_clicked':
                $userData[$userId]['notification_clicked']++;
                break;
        }
    }

    // Create samples for clustering
    $samples = [];
    foreach ($userData as $userId => $data) {
        $samples[] = [
            $data['favorites'],
            $data['ai_suggested'],
            $data['buy_now'],
            $data['price_history'],
            $data['tracking'],
            $data['notification_received'],
            $data['notification_dismissed'],
            $data['notification_buy_now'],
            $data['notification_price_history'],
            $data['notification_track'],
            $data['notification_share'],
            $data['notification_clicked']
        ];
    }

    // Normalize samples to prevent bias from high-frequency interactions
    $dataset = new Unlabeled($samples);
    $clusterer = new KMeans(5); // 5 clusters for user segmentation
    $clusters = $clusterer->predict($dataset);

    // Update user clusters in database
    $pdo->beginTransaction();
    foreach (array_keys($userData) as $index => $userId) {
        $stmt = $pdo->prepare("UPDATE users SET cluster = ? WHERE id = ?");
        $stmt->execute([$clusters[$index], $userId]);
    }
    $pdo->commit();

    // Log execution time and success
    $executionTime = microtime(true) - $startTime;
    file_put_contents('../logs/behavior.log', "[" . date('Y-m-d H:i:s') . "] Behavior analysis completed in {$executionTime} seconds\n", FILE_APPEND);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    file_put_contents('../logs/behavior.log', "[" . date('Y-m-d H:i:s') . "] Behavior analysis failed: " . $e->getMessage() . "\n", FILE_APPEND);
    throw $e; // Re-throw for cron monitoring
}
?>