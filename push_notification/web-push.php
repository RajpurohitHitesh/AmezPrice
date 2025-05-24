<?php
require_once '../config/database.php';
require_once '../config/globals.php';
require_once '../vendor/autoload.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

class WebPushService {
    private $webPush;

    public function __construct($options) {
        $this->webPush = new WebPush($options, [], 20, [
            \GuzzleHttp\RequestOptions::ALLOW_REDIRECTS => false
        ]);
        $this->webPush->setAutomaticPadding(true);
    }

    public function sendNotification($subscription, $payload, $options = []) {
        try {
            $sub = Subscription::create(json_decode($subscription, true));
            $defaultOptions = [
                'TTL' => 2419200, // 28 days default
                'urgency' => 'normal',
                'topic' => 'price-update'
            ];
            $mergedOptions = array_merge($defaultOptions, $options);

            $report = $this->webPush->sendOneNotification($sub, json_encode($payload), $mergedOptions);

            if ($report->isSuccess()) {
                return true;
            } else {
                file_put_contents('../logs/push_errors.log', "[" . date('Y-m-d H:i:s') . "] Push failed for {$report->getEndpoint()}: {$report->getReason()}\n", FILE_APPEND);
                return false;
            }
        } catch (Exception $e) {
            file_put_contents('../logs/push_errors.log', "[" . date('Y-m-d H:i:s') . "] Push error: " . $e->getMessage() . "\n", FILE_APPEND);
            return false;
        }
    }

    public function sendBatchNotifications($subscriptions, $payload, $options = []) {
        try {
            foreach ($subscriptions as $subscription) {
                $sub = Subscription::create(json_decode($subscription, true));
                $defaultOptions = [
                    'TTL' => 2419200,
                    'urgency' => 'normal',
                    'topic' => 'price-update'
                ];
                $mergedOptions = array_merge($defaultOptions, $options);

                $this->webPush->queueNotification($sub, json_encode($payload), $mergedOptions);
            }

            $reports = $this->webPush->flush();
            $success = true;

            foreach ($reports as $report) {
                if (!$report->isSuccess()) {
                    file_put_contents('../logs/push_errors.log', "[" . date('Y-m-d H:i:s') . "] Batch push failed for {$report->getEndpoint()}: {$report->getReason()}\n", FILE_APPEND);
                    $success = false;
                }
            }

            return $success;
        } catch (Exception $e) {
            file_put_contents('../logs/push_errors.log', "[" . date('Y-m-d H:i:s') . "] Batch push error: " . $e->getMessage() . "\n", FILE_APPEND);
            return false;
        }
    }

    public static function generateVapidKeys() {
        global $pdo;

        $keys = \Minishlink\WebPush\VAPID::createVapidKeys();
        $stmt = $pdo->prepare("INSERT INTO vapid_keys (public_key, private_key) VALUES (?, ?)");
        $stmt->execute([$keys['publicKey'], $keys['privateKey']]);

        return $keys;
    }
}
?>