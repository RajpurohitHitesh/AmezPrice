<?php
require_once '../config/database.php';
require_once '../middleware/csrf.php';
session_start();

// Initialize variables
$category = filter_input(INPUT_GET, 'category', FILTER_SANITIZE_STRING) ?? '';
$sortCriteria = filter_input(INPUT_GET, 'sort_criteria', FILTER_SANITIZE_STRING) ?? 'discount';
$sortDirection = filter_input(INPUT_GET, 'sort_direction', FILTER_SANITIZE_STRING) ?? 'desc';
$page = max(1, filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?? 1);
$perPage = 40;
$offset = ($page - 1) * $perPage;

// Fetch categories
$categoriesStmt = $pdo->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL ORDER BY category ASC");
$categories = $categoriesStmt->fetchAll(PDO::FETCH_COLUMN);

// Build query
$query = "
    SELECT p.*, COUNT(up.id) as tracking_count 
    FROM products p 
    LEFT JOIN user_products up ON p.asin = up.product_asin 
    WHERE 1=1
";
$params = [];

if ($category) {
    $query .= " AND p.category = ?";
    $params[] = $category;
}

// Sorting logic
$orderBy = '';
switch ($sortCriteria) {
    case 'price':
        $orderBy = "p.current_price";
        break;
    case 'rating':
        $orderBy = "p.rating";
        break;
    case 'popularity':
        $orderBy = "tracking_count";
        break;
    default:
        $orderBy = "(p.highest_price - p.current_price) / p.highest_price";
}

$direction = ($sortDirection === 'asc' || $sortCriteria === 'popularity') ? 'ASC' : 'DESC';
$query .= " GROUP BY p.asin ORDER BY $orderBy $direction";

// Add pagination
$query .= " LIMIT ? OFFSET ?";
$params[] = $perPage;
$params[] = $offset;

// Execute query
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count total products
$totalQuery = "SELECT COUNT(DISTINCT p.asin) FROM products p WHERE 1=1";
$totalParams = [];
if ($category) {
    $totalQuery .= " AND p.category = ?";
    $totalParams[] = $category;
}
$totalStmt = $pdo->prepare($totalQuery);
$totalStmt->execute($totalParams);
$total = $totalStmt->fetchColumn();
$totalPages = ceil($total / $perPage);

// SEO meta tags
$metaTitle = $category ? "Best Deals on $category - AmezPrice" : "Today’s Best Deals - AmezPrice";
$metaDescription = $category ? "Discover the best deals on $category at AmezPrice. Save big with our curated discounts!" : "Explore today’s top deals on Amazon and Flipkart at AmezPrice. Find the best discounts now!";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include '../include/header.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($metaTitle); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($metaDescription); ?>">
    <meta name="robots" content="index, follow">
    <link rel="stylesheet" href="/assets/css/main.css">
    <script src="<?php echo fa_kit_url(); ?>" crossorigin="anonymous"></script>
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "ItemList",
        "itemListElement": [
            <?php foreach ($products as $index => $product): ?>
                {
                    "@type": "Product",
                    "name": "<?php echo htmlspecialchars($product['name']); ?>",
                    "image": "<?php echo htmlspecialchars($product['image_path']); ?>",
                    "url": "<?php echo htmlspecialchars($product['website_url']); ?>",
                    "offers": {
                        "@type": "Offer",
                        "price": "<?php echo $product['current_price']; ?>",
                        "priceCurrency": "INR",
                        "availability": "<?php echo $product['stock_status'] === 'in_stock' ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock'; ?>"
                    }
                }<?php echo $index < count($products) - 1 ? ',' : ''; ?>
            <?php endforeach; ?>
        ]
    }
    </script>
</head>
<body>
    <?php include '../include/navbar.php'; ?>
    <main class="container">
        <h1 class="deals-title">Today’s Deals</h1>
        <div class="filter-section">
            <div class="card filter-card">
                <form id="deal-filters" aria-label="Filter and sort deals">
                    <div class="category-select">
                        <label for="category">Select Category</label>
                        <select id="category" name="category" required aria-label="Choose a category">
                            <option value="">Select your favorite category</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $category === $cat ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-primary find-btn" aria-label="Find deals for selected category">
                            <i class="fas fa-search"></i> Find
                        </button>
                    </div>
                    <div class="sort-options">
                        <div class="sort-direction">
                            <label for="sort_direction">Sort Direction</label>
                            <select id="sort_direction" name="sort_direction" onchange="applySort()" aria-label="Sort direction">
                                <option value="desc" <?php echo $sortDirection === 'desc' ? 'selected' : ''; ?>>High to Low</option>
                                <option value="asc" <?php echo $sortDirection === 'asc' ? 'selected' : ''; ?>>Low to High</option>
                                <option value="popularity" <?php echo $sortDirection === 'popularity' ? 'selected' : ''; ?>>Popularity</option>
                            </select>
                        </div>
                        <div class="sort-criteria">
                            <label for="sort_criteria">Sort By</label>
                            <select id="sort_criteria" name="sort_criteria" onchange="applySort()" aria-label="Sort criteria">
                                <option value="discount" <?php echo $sortCriteria === 'discount' ? 'selected' : ''; ?>>Discount</option>
                                <option value="price" <?php echo $sortCriteria === 'price' ? 'selected' : ''; ?>>Price</option>
                                <option value="rating" <?php echo $sortCriteria === 'rating' ? 'selected' : ''; ?>>Rating</option>
                            </select>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <div class="product-grid" id="product-grid" aria-live="polite">
            <?php if (empty($products)): ?>
                <p class="no-deals">No deals found for this category.</p>
            <?php else: ?>
                <?php foreach ($products as $product): 
                    $discount = round(($product['highest_price'] - $product['current_price']) / $product['highest_price'] * 100);
                ?>
                    <div class="product-card" role="article" aria-label="<?php echo htmlspecialchars($product['name']); ?>">
                        <img src="<?php echo htmlspecialchars($product['image_path']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>" loading="lazy">
                        <img class="merchant-logo" src="/assets/images/logos/<?php echo htmlspecialchars($product['merchant']); ?>.svg" alt="<?php echo htmlspecialchars($product['merchant']); ?>">
                        <h3><?php echo htmlspecialchars(substr($product['name'], 0, 50)) . (strlen($product['name']) > 50 ? '...' : ''); ?></h3>
                        <p class="product-price">
                            ₹<?php echo number_format($product['current_price'], 0, '.', ','); ?>
                            <s>₹<?php echo number_format($product['highest_price'], 0, '.', ','); ?></s>
                        </p>
                        <p class="tracking-count" aria-label="<?php echo $product['tracking_count']; ?> users tracking">
                            🔥 <?php echo $product['tracking_count']; ?> users tracking
                        </p>
                        <span class="discount-badge"><?php echo $discount; ?>% Off</span>
                        <div class="product-actions">
                            <a href="<?php echo htmlspecialchars($product['affiliate_link']); ?>" class="btn btn-primary" target="_blank" aria-label="Buy <?php echo htmlspecialchars($product['name']); ?> now">Buy Now</a>
                            <a href="<?php echo htmlspecialchars($product['website_url']); ?>" class="btn btn-secondary" aria-label="View price history for <?php echo htmlspecialchars($product['name']); ?>">
                                <i class="fas fa-chart-line"></i> Price History
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div class="pagination" aria-label="Pagination">
            <?php if ($page > 1): ?>
                <a href="/hotdeals?<?php echo http_build_query(['category' => $category, 'sort_criteria' => $sortCriteria, 'sort_direction' => $sortDirection, 'page' => $page - 1]); ?>" class="btn btn-secondary" aria-label="Previous page">Prev</a>
            <?php endif; ?>
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="/hotdeals?<?php echo http_build_query(['category' => $category, 'sort_criteria' => $sortCriteria, 'sort_direction' => $sortDirection, 'page' => $i]); ?>" class="btn <?php echo $i === $page ? 'btn-primary' : 'btn-secondary'; ?>" aria-label="Page <?php echo $i; ?>"><?php echo $i; ?></a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
                <a href="/hotdeals?<?php echo http_build_query(['category' => $category, 'sort_criteria' => $sortCriteria, 'sort_direction' => $sortDirection, 'page' => $page + 1]); ?>" class="btn btn-secondary" aria-label="Next page">Next</a>
            <?php endif; ?>
        </div>
    </main>
    <?php include '../include/footer.php'; ?>
    <script src="/assets/js/main.js"></script>
    <script>
        function applySort() {
            const params = new URLSearchParams({
                category: document.getElementById('category').value,
                sort_criteria: document.getElementById('sort_criteria').value,
                sort_direction: document.getElementById('sort_direction').value,
                page: 1
            });

            fetch('/hotdeals?' + params.toString(), {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content
                }
            })
            .then(response => response.text())
            .then(html => {
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                const newGrid = doc.querySelector('#product-grid');
                const newPagination = doc.querySelector('.pagination');
                document.getElementById('product-grid').innerHTML = newGrid.innerHTML;
                document.querySelector('.pagination').innerHTML = newPagination.innerHTML;
                document.getElementById('product-grid').style.opacity = 1;
            })
            .catch(error => {
                console.error('Error fetching sorted deals:', error);
            });
        }

        // Skeleton loading
        document.addEventListener('DOMContentLoaded', () => {
            const grid = document.getElementById('product-grid');
            grid.style.opacity = 0;
            grid.innerHTML = `
                ${Array(8).fill().map(() => `
                    <div class="product-card skeleton">
                        <div class="skeleton-image"></div>
                        <div class="skeleton-title"></div>
                        <div class="skeleton-price"></div>
                        <div class="skeleton-tracking"></div>
                    </div>
                `).join('')}
            `;
            setTimeout(() => {
                grid.style.transition = 'opacity 0.3s';
                grid.style.opacity = 1;
                // Trigger initial fetch to replace skeleton
                if (document.getElementById('category').value) {
                    applySort();
                }
            }, 500);
        });

        // Keyboard navigation
        document.querySelectorAll('.btn, select').forEach(element => {
            element.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    if (element.tagName === 'SELECT') {
                        applySort();
                    } else {
                        element.click();
                    }
                }
            });
        });

        // Form submission for category selection
        document.getElementById('deal-filters').addEventListener('submit', (e) => {
            e.preventDefault();
            const category = document.getElementById('category').value;
            if (category) {
                window.location.href = '/hotdeals?' + new URLSearchParams({
                    category: category,
                    sort_criteria: document.getElementById('sort_criteria').value,
                    sort_direction: document.getElementById('sort_direction').value,
                    page: 1
                }).toString();
            }
        });
    </script>
</body>
</html>