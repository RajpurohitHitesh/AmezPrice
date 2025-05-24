<?php
require_once 'config/database.php';
require_once 'config/category.php';
session_start();

// Fetch categories
$categories = include 'config/category.php';

// Fetch AI-powered recommendations for logged-in users
$recommendedProducts = [];
if (isset($_SESSION['user_id'])) {
    $userId = $_SESSION['user_id'];
    $stmt = $pdo->prepare("
        SELECT p.*, COUNT(up.id) as tracker_count
        FROM products p 
        JOIN user_behavior ub ON p.asin = ub.asin 
        WHERE ub.user_id = ? AND p.current_price <= p.highest_price * 0.7 AND p.rating >= 3.5
        GROUP BY p.asin
        ORDER BY (p.highest_price - p.current_price) / p.highest_price DESC 
        LIMIT 12
    ");
    $stmt->execute([$userId]);
    $recommendedProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="AmezPrice - Track prices for Amazon and Flipkart products, get AI-powered deal recommendations, and save on your purchases.">
    <meta name="keywords" content="price tracking, Amazon deals, Flipkart deals, AI recommendations, online shopping">
    <meta property="og:title" content="AmezPrice - Price Tracking and Deals">
    <meta property="og:description" content="Track prices and get the best deals on Amazon and Flipkart with AI-powered recommendations.">
    <meta property="og:image" content="/assets/images/logos/website-logo.png">
    <meta property="og:url" content="https://amezprice.com">
    <title>AmezPrice - Price Tracking</title>
    <link rel="stylesheet" href="/assets/css/main.css">
    <link rel="stylesheet" href="/assets/css/search.css">
    <?php include '../include/header.php'; ?>
</head>
<body>
    <?php include 'include/navbar.php'; ?>
    <main class="container">
        <?php include 'search/template.php'; ?>
        <?php if (!empty($recommendedProducts)): ?>
            <section class="recommended-deals">
                <h2>Recommended Deals</h2>
                <div class="product-grid">
                    <?php foreach ($recommendedProducts as $product):
                        $discount = round(($product['highest_price'] - $product['current_price']) / $product['highest_price'] * 100);
                    ?>
                        <article class="product-card">
                            <img src="<?php echo htmlspecialchars($product['image_path']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>">
                            <img src="/assets/images/logos/<?php echo htmlspecialchars($product['merchant']); ?>.svg" alt="<?php echo htmlspecialchars($product['merchant']); ?> Logo">
                            <h3><?php echo htmlspecialchars($product['name']); ?></h3>
                            <p class="price">
                                ₹<?php echo number_format($product['current_price'], 0, '.', ','); ?>
                                <s>₹<?php echo number_format($product['highest_price'], 0, '.', ','); ?></s>
                            </p>
                            <p class="trackers">🔥 <?php echo $product['tracker_count']; ?> users tracking</p>
                            <span class="discount-badge"><?php echo $discount; ?>% Off</span>
                            <a href="<?php echo htmlspecialchars($product['affiliate_link']); ?>" class="btn btn-primary" target="_blank" aria-label="Buy <?php echo htmlspecialchars($product['name']); ?> now">Buy Now</a>
                            <a href="<?php echo htmlspecialchars($product['website_url']); ?>" class="btn btn-secondary" aria-label="View price history for <?php echo htmlspecialchars($product['name']); ?>"><i class="fas fa-chart-line"></i> Price History</a>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
        <?php foreach ($categories as $entry): ?>
            <?php if (!empty($entry['category']) && !empty($entry['platform'])): ?>
                <section class="product-box">
                    <div class="category-header">
                        <a href="/hotdeals?category=<?php echo urlencode($entry['category']); ?>" class="category-link"><?php echo htmlspecialchars($entry['category']); ?></a>
                        <h2><?php echo htmlspecialchars($entry['heading']); ?></h2>
                    </div>
                    <div class="product-carousel">
                        <?php
                        $stmt = $pdo->prepare("
                            SELECT p.*, COUNT(up.id) as tracker_count 
                            FROM products p 
                            LEFT JOIN user_products up ON p.asin = up.product_asin 
                            WHERE p.category = ? AND p.merchant = ? 
                            GROUP BY p.asin
                            ORDER BY (p.highest_price - p.current_price) / p.highest_price DESC 
                            LIMIT 10
                        ");
                        $stmt->execute([$entry['category'], $entry['platform']]);
                        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($products as $product):
                            $discount = round(($product['highest_price'] - $product['current_price']) / $product['highest_price'] * 100);
                        ?>
                            <article class="product-card">
                                <img src="<?php echo htmlspecialchars($product['image_path']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>">
                                <img src="/assets/images/logos/<?php echo htmlspecialchars($product['merchant']); ?>.svg" alt="<?php echo htmlspecialchars($product['merchant']); ?> Logo">
                                <h3><?php echo htmlspecialchars($product['name']); ?></h3>
                                <p class="price">
                                    ₹<?php echo number_format($product['current_price'], 0, '.', ','); ?>
                                    <s>₹<?php echo number_format($product['highest_price'], 0, '.', ','); ?></s>
                                </p>
                                <p class="trackers">🔥 <?php echo $product['tracker_count']; ?> users tracking</p>
                                <span class="discount-badge"><?php echo $discount; ?>% Off</span>
                                <a href="<?php echo htmlspecialchars($product['affiliate_link']); ?>" class="btn btn-primary" target="_blank" aria-label="Buy <?php echo htmlspecialchars($product['name']); ?> now">Buy Now</a>
                                <a href="<?php echo htmlspecialchars($product['website_url']); ?>" class="btn btn-secondary" aria-label="View price history for <?php echo htmlspecialchars($product['name']); ?>"><i class="fas fa-chart-line"></i> Price History</a>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <button class="carousel-arrow left" aria-label="Previous products"><i class="fas fa-chevron-left"></i></button>
                    <button class="carousel-arrow right" aria-label="Next products"><i class="fas fa-chevron-right"></i></button>
                </section>
            <?php endif; ?>
        <?php endforeach; ?>
    </main>
    <?php include 'include/footer.php'; ?>
    <script src="/assets/js/main.js"></script>
    <script src="/assets/js/search.js"></script>
</body>
</html>