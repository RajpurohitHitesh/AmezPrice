<?php
require_once '../config/database.php';
require_once '../config/globals.php';
require_once '../middleware/csrf.php';
session_start();

$merchant = $_GET['merchant'] ?? '';
$pid = $_GET['pid'] ?? '';

if (!in_array($merchant, ['amazon', 'flipkart']) || empty($pid)) {
    header('Location: /');
    exit;
}

try {
    // Fetch product details
    $stmt = $pdo->prepare("SELECT * FROM products WHERE asin = ? AND merchant = ?");
    $stmt->execute([$pid, $merchant]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        header('Location: /');
        exit;
    }

    // Check if product is favorited
    $isFavorite = false;
    if (isset($_SESSION['user_id'])) {
        $stmt = $pdo->prepare("SELECT is_favorite FROM user_products WHERE user_id = ? AND product_asin = ?");
        $stmt->execute([$_SESSION['user_id'], $pid]);
        $isFavorite = $stmt->fetchColumn();
    }

    // Aggregate monthly price history
    $rawHistory = json_decode($product['price_history'], true);
    $priceHistory = [];
    foreach ($rawHistory as $date => $price) {
        $month = substr($date, 0, 7);
        if (!isset($priceHistory[$month])) {
            $priceHistory[$month] = ['highest' => $price, 'lowest' => $price];
        } else {
            $priceHistory[$month]['highest'] = max($priceHistory[$month]['highest'], $price);
            $priceHistory[$month]['lowest'] = min($priceHistory[$month]['lowest'], $price);
        }
    }

    // Calculate discount percentage
    $discountPercent = ($product['highest_price'] - $product['current_price']) / max($product['highest_price'] - $product['lowest_price'], 1) * 100;

    // Buy suggestion logic
    $buySuggestion = '';
    $buyIcon = '';
    $buyColor = '';
    if ($discountPercent <= 20) {
        $buySuggestion = 'Do not buy this product now. The current price is high compared to its historical prices.';
        $buyIcon = 'fa-thumbs-down';
        $buyColor = '#ff0000';
    } elseif ($discountPercent <= 60) {
        $buySuggestion = 'You can buy or maybe wait. The current price is within the normal range.';
        $buyIcon = 'fa-thumbs-up';
        $buyColor = 'linear-gradient(#ffcc00, #ff6600)';
    } else {
        $buySuggestion = 'You can buy this product now. The current price is low compared to its historical prices.';
        $buyIcon = 'fa-hands-clapping';
        $buyColor = '#00cc00';
    }

    // Fetch price predictions
    $predictionsStmt = $pdo->prepare("SELECT predicted_price, period FROM predictions WHERE asin = ? AND prediction_date >= CURDATE() LIMIT 4");
    $predictionsStmt->execute([$pid]);
    $predictions = $predictionsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch price drop pattern
    $patternStmt = $pdo->prepare("SELECT pattern_description FROM patterns WHERE asin = ? ORDER BY created_at DESC LIMIT 1");
    $patternStmt->execute([$pid]);
    $pattern = $patternStmt->fetchColumn() ?: 'No Price Drop Pattern Detected';

    // Fetch related deals
    $relatedStmt = $pdo->prepare("
        SELECT p.*, COUNT(up.id) as tracker_count
        FROM products p
        LEFT JOIN user_products up ON p.asin = up.product_asin
        WHERE p.category = ? AND p.asin != ? AND p.merchant = ?
        GROUP BY p.asin
        ORDER BY (p.highest_price - p.current_price) / p.highest_price DESC
        LIMIT 8
    ");
    $relatedStmt->execute([$product['category'], $pid, $merchant]);
    $relatedDeals = $relatedStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch recommended deals
    $recommendedDeals = [];
    if (isset($_SESSION['user_id'])) {
        $stmt = $pdo->prepare("
            SELECT p.*, COUNT(up.id) as tracker_count
            FROM products p
            JOIN user_behavior ub ON p.asin = ub.asin
            WHERE ub.user_id = ? AND p.current_price <= p.highest_price * 0.7 AND p.rating >= 3.5
            GROUP BY p.asin
            ORDER BY (p.highest_price - p.current_price) / p.highest_price DESC
            LIMIT 8
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $recommendedDeals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->prepare("
            SELECT p.*, COUNT(up.id) as tracker_count
            FROM products p
            LEFT JOIN user_products up ON p.asin = up.product_asin
            WHERE p.current_price <= p.highest_price * 0.7 AND p.rating >= 3.5
            GROUP BY p.asin
            ORDER BY (p.highest_price - p.current_price) / p.highest_price DESC
            LIMIT 8
        ");
        $stmt->execute();
        $recommendedDeals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    file_put_contents('../logs/database.log', "[" . date('Y-m-d H:i:s') . "] Database error in history.php: " . $e->getMessage() . "\n", FILE_APPEND);
    echo "<script>alert('An error occurred while loading the product data. Please try again later.');</script>";
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include '../include/header.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Track the price history of <?php echo htmlspecialchars($product['name']); ?> on AmezPrice. View trends, predictions, and get the best deals on <?php echo htmlspecialchars($merchant); ?>.">
    <meta name="keywords" content="price history, <?php echo htmlspecialchars($product['name']); ?>, AmezPrice, <?php echo htmlspecialchars($merchant); ?>, deals, price tracking">
    <meta property="og:title" content="<?php echo htmlspecialchars($product['name']); ?> - Price History">
    <meta property="og:description" content="Track the price history of <?php echo htmlspecialchars($product['name']); ?> on AmezPrice. View trends, predictions, and get the best deals on <?php echo htmlspecialchars($merchant); ?>.">
    <meta property="og:image" content="<?php echo htmlspecialchars($product['image_path']); ?>">
    <meta property="og:url" content="<?php echo htmlspecialchars($product['website_url']); ?>">
    <meta name="twitter:card" content="summary_large_image">
    <title><?php echo htmlspecialchars($product['name']); ?> - Price History | AmezPrice</title>
    <link rel="stylesheet" href="/assets/css/main.css">
    <script src="https://kit.fontawesome.com/6a410e136c.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <?php include '../include/navbar.php'; ?>
    <main class="container">
        <?php include '../search/template.php'; ?>
        <section class="card history-section" style="max-width: 60%; margin: 0 auto;" role="region" aria-labelledby="price-history-result">
            <p id="price-history-result" style="color: #999; font-size: 14px;">PRICE HISTORY RESULT</p>
            <div class="product-header">
                <h2><?php echo htmlspecialchars($product['name']); ?></h2>
                <?php if (isset($_SESSION['user_id'])): ?>
                    <i class="fas fa-heart" role="button" aria-label="<?php echo $isFavorite ? 'Remove from favorites' : 'Add to favorites'; ?>" style="color: <?php echo $isFavorite ? '#ff0000' : '#ccc'; ?>; font-size: 20px; cursor: pointer;" tabindex="0" onkeydown="if(event.key === 'Enter') toggleFavorite('<?php echo $pid; ?>', <?php echo $isFavorite ? 'true' : 'false'; ?>)();" onclick="toggleFavorite('<?php echo $pid; ?>', <?php echo $isFavorite ? 'true' : 'false'; ?>)"></i>
                <?php else: ?>
                    <i class="fas fa-heart" role="button" aria-label="Add to favorites (requires login)" style="color: #ccc; font-size: 20px; cursor: pointer;" tabindex="0" onkeydown="if(event.key === 'Enter') showPopup('login-popup', '<h3>Please Log In</h3><p>Please log in to add this product to your favorites list.</p>');" onclick="showPopup('login-popup', '<h3>Please Log In</h3><p>Please log in to add this product to your favorites list.</p>')"></i>
                <?php endif; ?>
            </div>
            <p style="color: #00cc00; font-size: 18px;">₹<?php echo number_format($product['current_price'], 0, '.', ','); ?> <s style="color: #999; font-size: 14px;">₹<?php echo number_format($product['highest_price'], 0, '.', ','); ?></s></p>
            <div class="product-details" style="display: flex; gap: 24px;">
                <div class="product-image" style="width: 35%;">
                    <img src="<?php echo htmlspecialchars($product['image_path']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>" loading="lazy" style="width: 300px; height: 300px; object-fit: cover; border-radius: 8px;">
                </div>
                <div class="product-info" style="width: 65%; display: flex; flex-direction: column; gap: 16px;">
                    <div class="card">
                        <span style="color: #ff0000;">Highest Price: ₹<?php echo number_format($product['highest_price'], 0, '.', ','); ?></span>
                        <span style="color: #00cc00;">Lowest Price: ₹<?php echo number_format($product['lowest_price'], 0, '.', ','); ?></span>
                    </div>
                    <div class="card" style="text-align: center;">
                        <h3 style="font-size: 18px;">Is it a right time to buy?</h3>
                        <p style="color: <?php echo $buyColor; ?>;"><i class="fas <?php echo $buyIcon; ?>" aria-hidden="true"></i> <?php echo $buySuggestion; ?></p>
                    </div>
                    <div class="card">
                        <a href="<?php echo htmlspecialchars($product['affiliate_link']); ?>" class="btn btn-primary" target="_blank" aria-label="Buy <?php echo htmlspecialchars($product['name']); ?> now" onclick="trackInteraction('buy_now', '<?php echo $pid; ?>')">Buy Now</a>
                    </div>
                </div>
            </div>
            <div class="card">
                <h3 style="font-size: 16px; text-align: center; color: #999;">Price History Graph</h3>
                <canvas id="priceChart" role="img" aria-label="Price history graph showing highest and lowest prices over time"></canvas>
            </div>
            <div class="card">
                <h3 style="font-size: 16px;">Future Price Prediction Powered by Advanced AI & Machine Learning</h3>
                <div style="display: flex; gap: 16px;">
                    <?php foreach ($predictions as $pred): ?>
                        <div style="flex: 1; text-align: center;">
                            <p><?php echo htmlspecialchars($pred['period']); ?></p>
                            <p style="color: <?php echo $pred['predicted_price'] > $product['current_price'] ? '#ff0000' : '#00cc00'; ?>">
                                ₹<?php echo number_format($pred['predicted_price'], 0, '.', ','); ?>
                            </p>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p style="text-align: center; color: <?php echo $predictions[0]['predicted_price'] > $product['current_price'] ? '#ff0000' : ($predictions[0]['predicted_price'] < $product['current_price'] ? '#00cc00' : '#ffcc00'); ?>">
                    Price will <?php echo $predictions[0]['predicted_price'] > $product['current_price'] ? 'increase' : ($predictions[0]['predicted_price'] < $product['current_price'] ? 'decrease' : 'remain stable'); ?>
                    <i class="fas <?php echo $predictions[0]['predicted_price'] > $product['current_price'] ? 'fa-thumbs-down' : ($predictions[0]['predicted_price'] < $product['current_price'] ? 'fa-hands-clapping' : 'fa-thumbs-up'); ?>" aria-hidden="true"></i>
                </p>
            </div>
            <div class="card">
                <h3 style="font-size: 16px;">Price Drop Pattern</h3>
                <p style="color: <?php echo $pattern === 'No Price Drop Pattern Detected' ? '#999' : '#1E293B'; ?>; background: linear-gradient(#e0e0e0, #ffffff); padding: 16px;">
                    <?php echo htmlspecialchars($pattern); ?>
                </p>
            </div>
            <div class="card">
                <h3 style="font-size: 16px;">Price History Table</h3>
                <table class="user-table" role="grid">
                    <thead>
                        <tr>
                            <th class="sortable" scope="col">Month/Year</th>
                            <th class="sortable" scope="col">Highest Price</th>
                            <th scope="col">Current Price</th>
                            <th scope="col">% Drop</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $months = array_slice(array_reverse(array_keys($priceHistory)), 0, 12);
                        foreach ($months as $month):
                            $highest = $priceHistory[$month]['highest'];
                            $current = $month === date('Y-m') ? $product['current_price'] : null;
                            $drop = $current ? round(($highest - $current) / $highest * 100) : null;
                        ?>
                            <tr>
                                <td><?php echo date('F Y', strtotime($month)); ?></td>
                                <td>₹<?php echo number_format($highest, 0, '.', ','); ?></td>
                                <td><?php echo $current ? '₹' . number_format($current, 0, '.', ',') : '-'; ?></td>
                                <td><?php echo $drop ? $drop . '%' : '-'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card">
                <h3 style="font-size: 16px;">Additional Product Info</h3>
                <p>Brand: <?php echo htmlspecialchars($product['brand'] ?? 'N/A'); ?></p>
                <p>Rating: <?php echo htmlspecialchars($product['rating'] ?? 'N/A'); ?></p>
                <p>Rating Count: <?php echo htmlspecialchars($product['rating_count'] ?? 'N/A'); ?></p>
                <p>Category: <?php echo htmlspecialchars($product['category'] ?? 'N/A'); ?></p>
            </div>
            <div class="card" style="font-style: italic; color: #666; font-size: 12px; border: 1px solid #E5E7EB; text-align: center;">
                <p>Disclaimer: The price history shown here is designed for user convenience to explore past pricing trends and does not predict future prices. The most recent product price is available above for your reference. Our AI-powered future price predictions are a unique feature of AmezPrice, independent of Amazon, and provided without any guarantee of accuracy, serving solely as an informational tool.</p>
            </div>
        </section>
        <section class="related-deals" style="max-width: 100%;">
            <h2>Related Deals</h2>
            <div class="product-grid">
                <?php foreach ($relatedDeals as $deal):
                    $discount = round(($deal['highest_price'] - $deal['current_price']) / $deal['highest_price'] * 100);
                ?>
                    <article class="product-card">
                        <img src="<?php echo htmlspecialchars($deal['image_path']); ?>" alt="<?php echo htmlspecialchars($deal['name']); ?>" loading="lazy">
                        <img src="/assets/images/logos/<?php echo htmlspecialchars($deal['merchant']); ?>.svg" alt="<?php echo htmlspecialchars($deal['merchant']); ?> Logo">
                        <h3><?php echo htmlspecialchars($deal['name']); ?></h3>
                        <p class="price">
                            ₹<?php echo number_format($deal['current_price'], 0, '.', ','); ?>
                            <s>₹<?php echo number_format($deal['highest_price'], 0, '.', ','); ?></s>
                        </p>
                        <p class="trackers">🔥 <?php echo $deal['tracker_count']; ?> users tracking</p>
                        <span class="discount-badge"><?php echo $discount; ?>% Off</span>
                        <a href="<?php echo htmlspecialchars($deal['affiliate_link']); ?>" class="btn btn-primary" target="_blank" aria-label="Buy <?php echo htmlspecialchars($deal['name']); ?> now" onclick="trackInteraction('buy_now', '<?php echo $deal['asin']; ?>')">Buy Now</a>
                        <a href="<?php echo htmlspecialchars($deal['website_url']); ?>" class="btn btn-secondary" aria-label="View price history for <?php echo htmlspecialchars($deal['name']); ?>"><i class="fas fa-chart-line"></i> Price History</a>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
        <section class="recommended-deals" style="max-width: 100%;">
            <h2>Recommended Deals</h2>
            <div class="product-grid">
                <?php foreach ($recommendedDeals as $deal):
                    $discount = round(($deal['highest_price'] - $deal['current_price']) / $deal['highest_price'] * 100);
                ?>
                    <article class="product-card">
                        <img src="<?php echo htmlspecialchars($deal['image_path']); ?>" alt="<?php echo htmlspecialchars($deal['name']); ?>" loading="lazy">
                        <img src="/assets/images/logos/<?php echo htmlspecialchars($deal['merchant']); ?>.svg" alt="<?php echo htmlspecialchars($deal['merchant']); ?> Logo">
                        <h3><?php echo htmlspecialchars($deal['name']); ?></h3>
                        <p class="price">
                            ₹<?php echo number_format($deal['current_price'], 0, '.', ','); ?>
                            <s>₹<?php echo number_format($deal['highest_price'], 0, '.', ','); ?></s>
                        </p>
                        <p class="trackers">🔥 <?php echo $deal['tracker_count']; ?> users tracking</p>
                        <span class="discount-badge"><?php echo $discount; ?>% Off</span>
                        <a href="<?php echo htmlspecialchars($deal['affiliate_link']); ?>" class="btn btn-primary" target="_blank" aria-label="Buy <?php echo htmlspecialchars($deal['name']); ?> now" onclick="trackInteraction('buy_now', '<?php echo $deal['asin']; ?>')">Buy Now</a>
                        <a href="<?php echo htmlspecialchars($deal['website_url']); ?>" class="btn btn-secondary" aria-label="View price history for <?php echo htmlspecialchars($deal['name']); ?>"><i class="fas fa-chart-line"></i> Price History</a>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    </main>
    <?php include '../include/footer.php'; ?>
    <div id="login-popup" class="popup" style="display: none;">
        <i class="fas fa-times popup-close" onclick="hidePopup('login-popup')"></i>
        <div class="popup-content"></div>
    </div>
    <div id="favorite-popup" class="popup" style="display: none;">
        <i class="fas fa-times popup-close" onclick="hidePopup('favorite-popup')"></i>
        <div class="popup-content"></div>
    </div>
    <div class="popup-overlay" style="display: none;"></div>
    <script src="/assets/js/main.js"></script>
    <script>
        const ctx = document.getElementById('priceChart').getContext('2d');
        const chart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_keys($priceHistory)); ?>,
                datasets: [
                    {
                        label: 'Highest Price',
                        data: <?php echo json_encode(array_column($priceHistory, 'highest')); ?>,
                        borderColor: '#ff0000',
                        fill: false
                    },
                    {
                        label: 'Lowest Price',
                        data: <?php echo json_encode(array_column($priceHistory, 'lowest')); ?>,
                        borderColor: '#00cc00',
                        fill: false
                    }
                ]
            },
            options: {
                scales: {
                    y: { 
                        beginAtZero: false, 
                        title: { display: true, text: 'Price (₹)' },
                        ticks: { callback: value => '₹' + value.toLocaleString('en-IN') }
                    },
                    x: { title: { display: true, text: 'Month/Year' } }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: context => `${context.dataset.label}: ₹${context.parsed.y.toLocaleString('en-IN')}`
                        }
                    }
                }
            }
        });

        async function toggleFavorite(productId, isFavorite) {
            const response = await fetch('/user/toggle_favorite.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({ product_id: productId, is_favorite: !isFavorite })
            });
            const result = await response.json();

            if (result.status === 'success') {
                const heart = document.querySelector(`.fa-heart[onclick*="${productId}"]`);
                heart.style.color = isFavorite ? '#ccc' : '#ff0000';
                heart.setAttribute('onclick', `toggleFavorite('${productId}', ${!isFavorite})`);
                heart.setAttribute('aria-label', isFavorite ? 'Add to favorites' : 'Remove from favorites');
                showPopup('favorite-popup', `<h3>Success</h3><p>Product ${isFavorite ? 'removed from' : 'added to'} favorites!</p>`);
            } else {
                showPopup('favorite-popup', `<h3>Error</h3><p>${result.message}</p>`);
            }
        }

        async function trackInteraction(type, productId) {
            await fetch('/user/track_interaction.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({ type, product_id: productId })
            });
        }
    </script>
</body>
</html>