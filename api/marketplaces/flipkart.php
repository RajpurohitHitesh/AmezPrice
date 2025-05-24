<?php
require_once '../../config/database.php';
require_once '../../config/flipkart.php';
require_once '../../config/globals.php';

function fetchFlipkartProduct($id) {
    global $pdo, $flipkartConfig;

    if ($flipkartConfig['api_status'] !== 'active') {
        return ['status' => 'error', 'message' => 'Flipkart API is temporarily unavailable'];
    }

    try {
        $url = "https://affiliate-api.flipkart.net/products/{$id}";
        $headers = [
            "Fk-Affiliate-Id: {$flipkartConfig['affiliate_id']}",
            "Fk-Affiliate-Token: {$flipkartConfig['token']}"
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            $price = (float)($data['productBaseInfo']['productAttributes']['sellingPrice']['amount'] ?? 0);
            $imageUrl = (string)($data['productBaseInfo']['productAttributes']['imageUrls']['400x400'] ?? '');
            $stockStatus = $data['productBaseInfo']['productAttributes']['inStock'] ? 'in_stock' : 'out_of_stock';
            $stockQuantity = $stockStatus === 'in_stock' ? (int)($data['productBaseInfo']['productAttributes']['maximumPurchaseQuantity'] ?? 10) : 0;
            $rating = (float)($data['productBaseInfo']['productAttributes']['rating'] ?? 0);
            $ratingCount = (int)($data['productBaseInfo']['productAttributes']['ratingCount'] ?? 0);

            // Download and convert image to WebP
            $imagePath = "/assets/images/products/{$id}.webp";
            if ($imageUrl && !file_exists($_SERVER['DOCUMENT_ROOT'] . $imagePath)) {
                $tempPath = "/assets/images/products/temp/{$id}.jpg";
                file_put_contents($_SERVER['DOCUMENT_ROOT'] . $tempPath, file_get_contents($imageUrl));
                $image = imagecreatefromjpeg($_SERVER['DOCUMENT_ROOT'] . $tempPath);
                imagewebp($image, $_SERVER['DOCUMENT_ROOT'] . $imagePath, 75);
                imagedestroy($image);
                file_put_contents('../../logs/images.log', "[" . date('Y-m-d H:i:s') . "] Cached image for ID $id\n", FILE_APPEND);
            }

            return [
                'status' => 'success',
                'name' => (string)$data['productBaseInfo']['productAttributes']['title'],
                'current_price' => $price,
                'affiliate_link' => (string)$data['productBaseInfo']['productAttributes']['productUrl'],
                'image_path' => $imagePath,
                'stock_status' => $stockStatus,
                'stock_quantity' => $stockQuantity,
                'rating' => $rating,
                'rating_count' => $ratingCount
            ];
        } else {
            throw new Exception("HTTP $httpCode");
        }
    } catch (Exception $e) {
        file_put_contents('../../logs/flipkart_errors.log', "[" . date('Y-m-d H:i:s') . "] Error fetching ID $id: " . $e->getMessage() . "\n", FILE_APPEND);
        return ['status' => 'error', 'message' => 'Failed to fetch product data'];
    }
}
?>