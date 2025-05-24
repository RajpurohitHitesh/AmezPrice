<?php
require_once '../config/database.php';
require_once '../config/marketplaces.php';
require_once '../config/fontawesome.php';
require_once '../middleware/csrf.php';
session_start();

if ($marketplaces['flipkart'] !== 'active') {
    header('Location: /');
    exit;
}

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 32;
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("SELECT * FROM flipbox_products ORDER BY discount_percentage DESC LIMIT ? OFFSET ?");
$stmt->execute([$perPage, $offset]);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalStmt = $pdo->query("SELECT COUNT(*) FROM flipbox_products");
$total = $totalStmt->fetchColumn();
$totalPages = ceil($total / $perPage);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include '../include/header.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Discover the best Flipkart Flipbox deals with up to <?php echo max(array_column($products, 'discount_percentage') ?: [0]); ?>% off. Page <?php echo $page; ?> of exclusive offers.">
    <meta name="keywords" content="Flipkart Flipbox, deals, discounts, AmezPrice, shopping">
    <title>Flipkart Flipbox Deals - Page <?php echo $page; ?> - AmezPrice</title>
    <link rel="stylesheet" href="/assets/css/main.css">
</head>
<body>
    <?php include '../include/navbar.php'; ?>
    <main class="container">
        <h1 class="deals-title">Flipkart Flipbox Deals</h1>
        <?php if (empty($products)): ?>
            <div class="no-deals">
                <i class="fas fa-exclamation-circle" aria-hidden="true"></i>
                <p>No Flipbox deals available at the moment. Check back later!</p>
            </div>
        <?php else: ?>
            <div class="product-grid">
                <?php foreach ($products as $product): ?>
                    <div class="product-card" aria-label="Product: <?php echo htmlspecialchars($product['name']); ?>">
                        <img src="<?php echo htmlspecialchars($product['image_url']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>" loading="lazy">
                        <h3><?php echo htmlspecialchars($product['name']); ?></h3>
                        <p>₹<?php echo number_format($product['current_price'], 0, '.', ','); ?></p>
                        <a href="<?php echo htmlspecialchars($product['affiliate_link']); ?>" class="btn btn-primary" target="_blank" aria-label="Buy <?php echo htmlspecialchars($product['name']); ?>">Buy Now</a>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="loading-spinner">
                <i class="fas fa-spinner" aria-hidden="true"></i>
            </div>
            <div class="pagination" role="navigation" aria-label="Pagination">
                <?php if ($page > 1): ?>
                    <a href="/flipbox?page=<?php echo $page - 1; ?>" class="btn btn-secondary" data-page="<?php echo $page - 1; ?>" aria-label="Previous page">Prev</a>
                <?php endif; ?>
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="/flipbox?page=<?php echo $i; ?>" class="btn <?php echo $i === $page ? 'btn-primary' : 'btn-secondary'; ?>" data-page="<?php echo $i; ?>" aria-label="Page <?php echo $i; ?>" <?php echo $i === $page ? 'aria-current="page"' : ''; ?>><?php echo $i; ?></a>
                <?php endfor; ?>
                <?php if ($page < $totalPages): ?>
                    <a href="/flipbox?page=<?php echo $page + 1; ?>" class="btn btn-secondary" data-page="<?php echo $page + 1; ?>" aria-label="Next page">Next</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </main>
    <?php include '../include/footer.php'; ?>
    <script src="/assets/js/main.js"></script>
</body>
</html>