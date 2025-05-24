<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/amazon.php';
require_once __DIR__ . '/../../config/globals.php';

use TheWirecutter\PAAPI5\AmazonAPI;
use TheWirecutter\PAAPI5\Operations\GetItems;
use TheWirecutter\PAAPI5\Configuration;
use TheWirecutter\PAAPI5\Operations\SearchItems;

function fetchAmazonProduct($asin) {
    global $pdo, $amazonConfig;

    if ($amazonConfig['api_status'] !== 'active') {
        return ['status' => 'error', 'message' => 'Amazon API is temporarily unavailable'];
    }

    require_once __DIR__ . '/../../vendor/autoload.php';

    try {
        $config = new Configuration();
        $config->setAccessKey($amazonConfig['access_key'])
               ->setSecretKey($amazonConfig['secret_key'])
               ->setPartnerTag($amazonConfig['associate_tag'])
               ->setMarketplace('www.amazon.in');

        $amazonAPI = new AmazonAPI($config);
        $getItems = new GetItems();
        $getItems->setItemIds([$asin])
                 ->setResources([
                     'ItemInfo.Title',
                     'Offers.Listings.Price',
                     'Images.Primary.Medium',
                     'Offers.Listings.Availability',
                     'CustomerReviews.StarRating',
                     'CustomerReviews.ReviewCount'
                 ]);

        $response = $amazonAPI->getItems($getItems);

        if (isset($response->ItemsResult->Items[0])) {
            $item = $response->ItemsResult->Items[0];
            $price = isset($item->Offers->Listings[0]->Price->Amount) ? (float)$item->Offers->Listings[0]->Price->Amount : 0;
            $imageUrl = isset($item->Images->Primary->Medium->URL) ? (string)$item->Images->Primary->Medium->URL : '';
            $stockStatus = isset($item->Offers->Listings[0]->Availability->Type) && $item->Offers->Listings[0]->Availability->Type === 'Now' ? 'in_stock' : 'out_of_stock';
            $stockQuantity = $stockStatus === 'in_stock' ? (int)($item->Offers->Listings[0]->Availability->MaxOrderQuantity ?? 10) : 0;
            $rating = isset($item->CustomerReviews->StarRating->Value) ? (float)$item->CustomerReviews->StarRating->Value : 0;
            $ratingCount = isset($item->CustomerReviews->ReviewCount) ? (int)$item->CustomerReviews->ReviewCount : 0;

            // Download and convert image to WebP
            $imagePath = "/assets/images/products/{$asin}.webp";
            if ($imageUrl && !file_exists($_SERVER['DOCUMENT_ROOT'] . $imagePath)) {
                $tempPath = "/assets/images/products/temp/{$asin}.jpg";
                file_put_contents($_SERVER['DOCUMENT_ROOT'] . $tempPath, file_get_contents($imageUrl));
                $image = imagecreatefromjpeg($_SERVER['DOCUMENT_ROOT'] . $tempPath);
                imagewebp($image, $_SERVER['DOCUMENT_ROOT'] . $imagePath, 75);
                imagedestroy($image);
                file_put_contents('../../logs/images.log', "[" . date('Y-m-d H:i:s') . "] Cached image for ASIN $asin\n", FILE_APPEND);
            }

            return [
                'status' => 'success',
                'name' => isset($item->ItemInfo->Title->DisplayValue) ? (string)$item->ItemInfo->Title->DisplayValue : '',
                'current_price' => $price,
                'affiliate_link' => isset($item->DetailPageURL) ? (string)$item->DetailPageURL : '',
                'image_path' => $imagePath,
                'stock_status' => $stockStatus,
                'stock_quantity' => $stockQuantity,
                'rating' => $rating,
                'rating_count' => $ratingCount
            ];
        } else {
            throw new Exception('Invalid response or item not found');
        }
    } catch (Exception $e) {
        file_put_contents('../../logs/amazon_errors.log', "[" . date('Y-m-d H:i:s') . "] Error fetching ASIN $asin: " . $e->getMessage() . "\n", FILE_APPEND);
        return ['status' => 'error', 'message' => 'Failed to fetch product data'];
    }
}

function fetchAmazonDeals() {
    global $pdo, $amazonConfig;

    if ($amazonConfig['api_status'] !== 'active') {
        return [];
    }

    require_once __DIR__ . '/../../vendor/autoload.php';

    try {
        $config = new Configuration();
        $config->setAccessKey($amazonConfig['access_key'])
               ->setSecretKey($amazonConfig['secret_key'])
               ->setPartnerTag($amazonConfig['associate_tag'])
               ->setMarketplace('www.amazon.in');

        $amazonAPI = new AmazonAPI($config);
        $searchItems = new SearchItems();
        $searchItems->setKeywords('deal of the day')
                    ->setResources([
                        'ItemInfo.Title',
                        'Offers.Listings.Price',
                        'Offers.Summaries.HighestPrice',
                        'Images.Primary.Medium',
                        'Offers.Listings.Availability'
                    ])
                    ->setItemCount(50);

        $response = $amazonAPI->searchItems($searchItems);

        $deals = [];
        if (isset($response->SearchResult->Items)) {
            foreach ($response->SearchResult->Items as $item) {
                $price = isset($item->Offers->Listings[0]->Price->Amount) ? (float)$item->Offers->Listings[0]->Price->Amount : 0;
                $highestPrice = isset($item->Offers->Summaries[0]->HighestPrice->Amount) ? (float)$item->Offers->Summaries[0]->HighestPrice->Amount : $price;
                $discountPercentage = $highestPrice > 0 ? round(($highestPrice - $price) / $highestPrice * 100, 2) : 0;

                if ($discountPercentage < 10) {
                    continue; // Skip items with less than 10% discount
                }

                $deals[] = [
                    'asin' => $item->ASIN,
                    'name' => isset($item->ItemInfo->Title->DisplayValue) ? (string)$item->ItemInfo->Title->DisplayValue : '',
                    'current_price' => $price,
                    'discount_percentage' => $discountPercentage,
                    'affiliate_link' => isset($item->DetailPageURL) ? (string)$item->DetailPageURL : '',
                    'image_url' => isset($item->Images->Primary->Medium->URL) ? (string)$item->Images->Primary->Medium->URL : ''
                ];
            }
        }

        return $deals;
    } catch (Exception $e) {
        file_put_contents('../../logs/amazon_errors.log', "[" . date('Y-m-d H:i:s') . "] Error fetching deals: " . $e->getMessage() . "\n", FILE_APPEND);
        return [];
    }
}