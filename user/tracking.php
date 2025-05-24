<?php
require_once '../config/database.php';
require_once '../config/security.php';
require_once '../middleware/csrf.php';
session_start();

// Debug logging
error_log("Session data on user tracking: " . print_r($_SESSION, true));
error_log("JWT token present: " . (isset($_SESSION['jwt']) ? 'yes' : 'no'));

if (!isset($_SESSION['user_id'])) {
    error_log("No user_id in session, redirecting to login");
    header("Location: " . LOGIN_REDIRECT);
    exit;
}

// Verify JWT
$jwt = $_SESSION['jwt'] ?? '';
if ($jwt) {
    list($header, $payload, $signature) = explode('.', $jwt);
    $decodedPayload = json_decode(base64_decode($payload), true);
    if ($decodedPayload['exp'] < time()) {
        error_log("JWT token expired for user: " . $_SESSION['user_id']);
        session_destroy();
        header("Location: " . LOGIN_REDIRECT);
        exit;
    }
    $expectedSignature = base64_encode(hash_hmac('sha256', "$header.$payload", $securityConfig['jwt']['secret'], true));
    if ($signature !== $expectedSignature) {
        error_log("Invalid JWT signature for user: " . $_SESSION['user_id']);
        session_destroy();
        header("Location: " . LOGIN_REDIRECT);
        exit;
    }
}

$userId = $_SESSION['user_id'];
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 50;
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("
    SELECT p.asin, p.name, p.current_price, p.image_path, p.website_url, p.affiliate_link, up.is_favorite, up.email_alert, up.push_alert
    FROM user_products up
    JOIN products p ON up.product_asin = p.asin
    WHERE up.user_id = ?
    ORDER BY p.name ASC
    LIMIT ? OFFSET ?
");
$stmt->execute([$userId, $perPage, $offset]);
$tracking = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM user_products WHERE user_id = ?");
$totalStmt->execute([$userId]);
$total = $totalStmt->fetchColumn();
$totalPages = ceil($total / $perPage);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include '../include/header.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tracking - AmezPrice</title>
    <link rel="stylesheet" href="/assets/css/user.css">
</head>
<body>
    <?php include '../include/navbar.php'; ?>
    <div class="user-container">
        <?php include '../include/user_sidebar.php'; ?>
        <div class="user-content">
            <h1>Tracking</h1>
            <div class="user-table">
                <table>
                    <thead>
                        <tr>
                            <th>Thumbnail</th>
                            <th class="sortable">Name</th>
                            <th class="sortable">Price</th>
                            <th>Email Alert</th>
                            <th>Push Alert</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tracking as $product): ?>
                            <tr>
                                <td><img src="<?php echo htmlspecialchars($product['image_path']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>"></td>
                                <td class="product-name"><a href="<?php echo htmlspecialchars($product['website_url']); ?>"><?php echo htmlspecialchars($product['name']); ?></a></td>
                                <td>₹<?php echo number_format($product['current_price'], 0, '.', ','); ?></td>
                                <td>
                                    <div class="toggle <?php echo $product['email_alert'] ? 'on' : ''; ?>" data-product-id="<?php echo htmlspecialchars($product['asin']); ?>" data-type="email"></div>
                                </td>
                                <td>
                                    <div class="toggle <?php echo $product['push_alert'] ? 'on' : ''; ?>" data-product-id="<?php echo htmlspecialchars($product['asin']); ?>" data-type="push"></div>
                                </td>
                                <td>
                                    <i class="fas fa-heart <?php echo $product['is_favorite'] ? 'favorite' : ''; ?>" style="color: <?php echo $product['is_favorite'] ? '#ff0000' : '#ccc'; ?>; cursor: pointer;" onclick="toggleFavorite('<?php echo htmlspecialchars($product['asin']); ?>', <?php echo $product['is_favorite'] ? 'true' : 'false'; ?>)"></i>
                                    <i class="fas fa-trash btn-remove" onclick="confirmDeleteProduct('<?php echo htmlspecialchars($product['asin']); ?>')"></i>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="pagination" style="text-align: center; margin-top: 24px;">
                <?php if ($page > 1): ?>
                    <a href="/user/tracking.php?page=<?php echo $page - 1; ?>" class="btn btn-secondary">Prev</a>
                <?php endif; ?>
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="/user/tracking.php?page=<?php echo $i; ?>" class="btn <?php echo $i === $page ? 'btn-primary' : 'btn-secondary'; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
                <?php if ($page < $totalPages): ?>
                    <a href="/user/tracking.php?page=<?php echo $page + 1; ?>" class="btn btn-secondary">Next</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php include '../include/footer.php'; ?>
    <div id="favorite-popup" class="popup" style="display: none;">
        <i class="fas fa-times popup-close" onclick="hidePopup('favorite-popup')"></i>
        <div class="popup-content"></div>
    </div>
    <div id="delete-product-popup" class="popup" style="display: none;">
        <i class="fas fa-times popup-close" onclick="hidePopup('delete-product-popup')"></i>
        <div class="popup-content"></div>
    </div>
    <div id="permission-popup" class="popup" style="display: none;">
        <i class="fas fa-times popup-close" onclick="hidePopup('permission-popup')"></i>
        <div class="popup-content"></div>
    </div>
    <div class="popup-overlay" style="display: none;"></div>
    <script src="/assets/js/user.js"></script>
</body>
</html>