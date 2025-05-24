<?php
require_once '../config/database.php';
require_once '../config/security.php';
require_once '../middleware/csrf.php';
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: " . LOGIN_REDIRECT);
    exit;
}

// Verify JWT
$jwt = $_SESSION['jwt'] ?? '';
if ($jwt) {
    list($header, $payload, $signature) = explode('.', $jwt);
    $decodedPayload = json_decode(base64_decode($payload), true);
    if ($decodedPayload['exp'] < time()) {
        session_destroy();
        header("Location: " . LOGIN_REDIRECT);
        exit;
    }
    $expectedSignature = base64_encode(hash_hmac('sha256', "$header.$payload", $securityConfig['jwt']['secret'], true));
    if ($signature !== $expectedSignature) {
        session_destroy();
        header("Location: " . LOGIN_REDIRECT);
        exit;
    }
}

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 50;
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("
    SELECT p.* 
    FROM products p 
    WHERE p.asin NOT IN (SELECT product_asin FROM user_products) 
    ORDER BY p.name ASC 
    LIMIT ? OFFSET ?
");
$stmt->execute([$perPage, $offset]);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalStmt = $pdo->query("SELECT COUNT(*) FROM products WHERE asin NOT IN (SELECT product_asin FROM user_products)");
$total = $totalStmt->fetchColumn();
$totalPages = ceil($total / $perPage);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include '../include/header.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Non-Tracking Products - AmezPrice</title>
    <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body>
    <?php include '../include/navbar.php'; ?>
    <div class="admin-container">
        <?php include '../include/admin_sidebar.php'; ?>
        <div class="admin-content">
            <h1>Non-Tracking Products</h1>
            <div class="admin-table">
                <table>
                    <thead>
                        <tr>
                            <th>Thumbnail</th>
                            <th class="sortable">Name</th>
                            <th class="sortable">Highest Price</th>
                            <th class="sortable">Lowest Price</th>
                            <th class="sortable">Current Price</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $product): ?>
                            <tr>
                                <td><img src="<?php echo htmlspecialchars($product['image_path']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>"></td>
                                <td><a href="<?php echo htmlspecialchars($product['website_url']); ?>"><?php echo htmlspecialchars($product['name']); ?></a></td>
                                <td>₹<?php echo number_format($product['highest_price'], 0, '.', ','); ?></td>
                                <td>₹<?php echo number_format($product['lowest_price'], 0, '.', ','); ?></td>
                                <td>₹<?php echo number_format($product['current_price'], 0, '.', ','); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="pagination" style="text-align: center; margin-top: 24px;">
                <?php if ($page > 1): ?>
                    <a href="/admin/ntp.php?page=<?php echo $page - 1; ?>" class="btn btn-secondary">Prev</a>
                <?php endif; ?>
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="/admin/ntp.php?page=<?php echo $i; ?>" class="btn <?php echo $i === $page ? 'btn-primary' : 'btn-secondary'; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
                <?php if ($page < $totalPages): ?>
                    <a href="/admin/ntp.php?page=<?php echo $page + 1; ?>" class="btn btn-secondary">Next</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php include '../include/footer.php'; ?>
    <script src="/assets/js/admin.js"></script>
</body>
</html>