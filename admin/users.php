<?php
require_once '../config/database.php';
require_once '../config/security.php';
require_once '../middleware/csrf.php';
session_start();

// Debug logging
error_log("Session data on admin users: " . print_r($_SESSION, true));
error_log("JWT token present: " . (isset($_SESSION['jwt']) ? 'yes' : 'no'));

if (!isset($_SESSION['admin_id'])) {
    error_log("No admin_id in session, redirecting to login");
    header("Location: " . LOGIN_REDIRECT);
    exit;
}

// Verify JWT
$jwt = $_SESSION['jwt'] ?? '';
if ($jwt) {
    list($header, $payload, $signature) = explode('.', $jwt);
    $decodedPayload = json_decode(base64_decode($payload), true);
    if ($decodedPayload['exp'] < time()) {
        error_log("JWT token expired for admin: " . $_SESSION['admin_id']);
        session_destroy();
        header("Location: " . LOGIN_REDIRECT);
        exit;
    }
    $expectedSignature = base64_encode(hash_hmac('sha256', "$header.$payload", $securityConfig['jwt']['secret'], true));
    if ($signature !== $expectedSignature) {
        error_log("Invalid JWT signature for admin: " . $_SESSION['admin_id']);
        session_destroy();
        header("Location: " . LOGIN_REDIRECT);
        exit;
    }
}

$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = isset($_GET['per_page']) ? max(10, min((int)$_GET['per_page'], 100)) : 50;
$offset = ($page - 1) * $perPage;

if ($userId) {
    // User details view
    $stmt = $pdo->prepare("SELECT first_name, last_name, username, email, telegram_id FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        header('Location: /admin/users.php');
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT p.asin, p.name, p.current_price, p.rating, up.email_alert, up.push_alert
        FROM user_products up
        JOIN products p ON up.product_asin = p.asin
        WHERE up.user_id = ?
        ORDER BY p.name ASC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$userId, $perPage, $offset]);
    $favorites = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM user_products WHERE user_id = ?");
    $totalStmt->execute([$userId]);
    $total = $totalStmt->fetchColumn();
    $totalPages = ceil($total / $perPage);
} else {
    // Users list view
    $stmt = $pdo->prepare("
        SELECT id, first_name, last_name, username, email, telegram_id 
        FROM users 
        ORDER BY username ASC 
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$perPage, $offset]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalStmt = $pdo->query("SELECT COUNT(*) FROM users");
    $total = $totalStmt->fetchColumn();
    $totalPages = ceil($total / $perPage);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include '../include/header.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users - AmezPrice</title>
    <link rel="stylesheet" href="/assets/css/admin.css">
    <script src="https://kit.fontawesome.com/<?php echo $kit_id; ?>.js" crossorigin="anonymous"></script>
</head>
<body>
    <?php include '../include/navbar.php'; ?>
    <div class="admin-container">
        <?php include '../include/admin_sidebar.php'; ?>
        <div class="admin-content">
            <?php if ($userId): ?>
                <div style="display: flex; align-items: center; margin-bottom: 24px;">
                    <a href="/admin/users.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
                    <h1 style="margin-left: 16px;"><?php echo htmlspecialchars($user['first_name']); ?></h1>
                </div>
                <div class="card">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <p><strong>Name:</strong> <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></p>
                        <button class="btn btn-delete" onclick="confirmDeleteUser(<?php echo $userId; ?>, '<?php echo htmlspecialchars($user['email']); ?>')">Delete</button>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <p><strong>Username:</strong> <?php echo htmlspecialchars($user['username'] ?: 'N/A'); ?></p>
                        <p><strong>Email:</strong> <?php echo htmlspecialchars($user['email'] ?: 'N/A'); ?></p>
                    </div>
                    <p><strong>Telegram ID:</strong> <?php echo htmlspecialchars($user['telegram_id'] ?: 'N/A'); ?></p>
                </div>
                <h2>Favorite Products</h2>
                <div class="admin-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Thumbnail</th>
                                <th class="sortable">Name</th>
                                <th class="sortable">Current Price</th>
                                <th class="sortable">Rating</th>
                                <th>Email Alert</th>
                                <th>Push Alert</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($favorites as $product): ?>
                                <tr>
                                    <td><img src="<?php echo htmlspecialchars($product['image_path']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>"></td>
                                    <td><a href="<?php echo htmlspecialchars("https://amezprice.com/history/{$product['merchant']}/pid={$product['asin']}"); ?>"><?php echo htmlspecialchars($product['name']); ?></a></td>
                                    <td>₹<?php echo number_format($product['current_price'], 0, '.', ','); ?></td>
                                    <td><?php echo htmlspecialchars($product['rating'] ?: 'N/A'); ?></td>
                                    <td><?php echo $product['email_alert'] ? 'On' : 'Off'; ?></td>
                                    <td><?php echo $product['push_alert'] ? 'On' : 'Off'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="pagination" style="text-align: center; margin-top: 24px;">
                    <?php if ($page > 1): ?>
                        <a href="/admin/users.php?user_id=<?php echo $userId; ?>&page=<?php echo $page - 1; ?>" class="btn btn-secondary">Prev</a>
                    <?php endif; ?>
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <a href="/admin/users.php?user_id=<?php echo $userId; ?>&page=<?php echo $i; ?>" class="btn <?php echo $i === $page ? 'btn-primary' : 'btn-secondary'; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>
                    <?php if ($page < $totalPages): ?>
                        <a href="/admin/users.php?user_id=<?php echo $userId; ?>&page=<?php echo $page + 1; ?>" class="btn btn-secondary">Next</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <h1>Users</h1>
                <div class="admin-table">
                    <table>
                        <thead>
                            <tr>
                                <th class="sortable">First Name</th>
                                <th class="sortable">Last Name</th>
                                <th class="sortable">Username</th>
                                <th class="sortable">Email</th>
                                <th class="sortable">Telegram ID</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td><a href="/admin/users.php?user_id=<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['first_name']); ?></a></td>
                                    <td><?php echo htmlspecialchars($user['last_name'] ?: 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($user['username'] ?: 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($user['email'] ?: 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($user['telegram_id'] ?: 'N/A'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="pagination" style="text-align: center; margin-top: 24px;">
                    <?php if ($page > 1): ?>
                        <a href="/admin/users.php?page=<?php echo $page - 1; ?>" class="btn btn-secondary">Prev</a>
                    <?php endif; ?>
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <a href="/admin/users.php?page=<?php echo $i; ?>" class="btn <?php echo $i === $page ? 'btn-primary' : 'btn-secondary'; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>
                    <?php if ($page < $totalPages): ?>
                        <a href="/admin/users.php?page=<?php echo $page + 1; ?>" class="btn btn-secondary">Next</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php include '../include/footer.php'; ?>
    <div id="delete-user-popup" class="popup" style="display: none;">
        <i class="fas fa-times popup-close" onclick="hidePopup('delete-user-popup')"></i>
        <div class="popup-content"></div>
    </div>
    <div id="otp-popup" class="popup" style="display: none;">
        <i class="fas fa-times popup-close" onclick="hidePopup('otp-popup')"></i>
        <div class="popup-content"></div>
    </div>
    <div id="error-popup" class="popup" style="display: none;">
        <i class="fas fa-times popup-close" onclick="hidePopup('error-popup')"></i>
        <div class="popup-content"></div>
    </div>
    <div class="popup-overlay" style="display: none;"></div>
    <script src="/assets/js/admin.js"></script>
</body>
</html>