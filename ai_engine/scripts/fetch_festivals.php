<?php
require_once '../../config/database.php';
require_once '../../config/globals.php';
require_once '../../config/google.php';

$apiKey = $googleConfig['calendar_api_key'];
$calendarId = 'holiday@group.v.calendar.google.com';
$year = date('Y');
$startDate = "$year-01-01T00:00:00Z";
$endDate = "$year-12-31T23:59:59Z";

try {
    $url = "https://www.googleapis.com/calendar/v3/calendars/" . urlencode($calendarId) . "/events?key=$apiKey&timeMin=$startDate&timeMax=$endDate";
    $response = file_get_contents($url);
    $data = json_decode($response, true);

    if (!isset($data['items'])) {
        throw new Exception('Invalid response from Google Calendar API');
    }

    foreach ($data['items'] as $event) {
        $eventName = $event['summary'];
        $eventDate = $event['start']['date'];
        $eventType = strpos($eventName, 'Sale') !== false ? 'sale' : 'festival';

        // Check historical discounts for offers_likely
        $stmt = $pdo->query("SELECT price_history FROM products WHERE category IN ('smartphone', 'television')");
        $histories = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $offersLikely = false;
        foreach ($histories as $history) {
            $prices = json_decode($history['price_history'], true);
            $eventPrices = array_filter($prices, fn($date) => abs(strtotime($date) - strtotime($eventDate)) < 604800, ARRAY_FILTER_USE_KEY);
            if ($eventPrices) {
                $avgDrop = array_reduce($eventPrices, fn($carry, $price) => $carry + ($price < $prices[array_key_last($prices)] ? ($prices[array_key_last($prices)] - $price) / $prices[array_key_last($prices)] * 100 : 0), 0) / count($eventPrices);
                if ($avgDrop > 10) {
                    $offersLikely = true;
                    break;
                }
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO festivals (event_name, event_date, event_type, offers_likely)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE event_name = ?, event_type = ?, offers_likely = ?
        ");
        $stmt->execute([
            $eventName,
            $eventDate,
            $eventType,
            $offersLikely,
            $eventName,
            $eventType,
            $offersLikely
        ]);

        file_put_contents('../logs/festivals.log', "[" . date('Y-m-d H:i:s') . "] Fetched $eventName for $eventDate: offers_likely=" . ($offersLikely ? 'true' : 'false') . "\n", FILE_APPEND);
    }
} catch (Exception $e) {
    file_put_contents('../logs/festivals.log', "[" . date('Y-m-d H:i:s') . "] Error fetching festivals: " . $e->getMessage() . "\n", FILE_APPEND);
}

// Update static fallback
file_put_contents('../data/festivals.json', json_encode($data['items']));
?>