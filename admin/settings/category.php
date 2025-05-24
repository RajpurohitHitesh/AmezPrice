<?php
require_once '../../config/database.php';
require_once '../../config/security.php';
require_once '../../config/category.php';
require_once '../../config/globals.php';
require_once '../../middleware/csrf.php';
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

$stmt = $pdo->prepare("SELECT DISTINCT category FROM products WHERE category IS NOT NULL");
$stmt->execute();
$validCategories = $stmt->fetchAll(PDO::FETCH_COLUMN);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $categories = $input['categories'] ?? [];

    if (count($categories) > 15) {
        echo json_encode(['status' => 'error', 'message' => 'Maximum 15 categories allowed']);
        exit;
    }

    if (count($categories) < 3) {
        echo json_encode(['status' => 'error', 'message' => 'Minimum 3 categories required']);
        exit;
    }

    $newConfig = [];
    foreach ($categories as $cat) {
        if (empty($cat['heading']) || !in_array($cat['category'], $validCategories) || !in_array($cat['platform'], ['Amazon', 'Flipkart'])) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid category data']);
            exit;
        }
        $newConfig[] = [
            'heading' => $cat['heading'],
            'category' => $cat['category'],
            'platform' => $cat['platform']
        ];
    }

    file_put_contents('../../config/category.php', "<?php\nreturn " . var_export($newConfig, true) . ";\n?>");
    file_put_contents('ADMIN_LOG_PATH', "[" . date('Y-m-d H:i:s') . "] Category settings updated by admin ID {$_SESSION['admin_id']}\n", FILE_APPEND);
    echo json_encode(['status' => 'success', 'message' => 'Categories updated']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include '../include/header.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Category Settings - AmezPrice</title>
    <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body>
    <?php include '../../include/navbar.php'; ?>
    <div class="admin-container">
        <?php include '../../include/admin_sidebar.php'; ?>
        <div class="admin-content">
            <div class="settings-submenu">
                <a href="/admin/settings/api_ui.php">API & UI</a>
                <a href="/admin/settings/category.php" class="active">Category</a>
                <a href="/admin/settings/telegram.php">Telegram</a>
                <a href="/admin/settings/social_security.php">Social & Security</a>
                <a href="/admin/settings/mail.php">Mail</a>
            </div>
            <h1>Category Settings</h1>
            <div class="card">
                <form id="category-form">
                    <div id="category-rows">
                        <?php foreach ($categoryConfig as $index => $cat): ?>
                            <div class="category-row" data-index="<?php echo $index; ?>" style="display: flex; gap: 16px; margin-bottom: 16px;">
                                <input type="text" name="heading[]" value="<?php echo htmlspecialchars($cat['heading']); ?>" placeholder="Heading" required <?php echo $index < 3 ? 'readonly' : ''; ?>>
                                <select name="category[]" required <?php echo $index < 3 ? 'disabled' : ''; ?>>
                                    <?php foreach ($validCategories as $validCat): ?>
                                        <option value="<?php echo htmlspecialchars($validCat); ?>" <?php echo $cat['category'] === $validCat ? 'selected' : ''; ?>><?php echo htmlspecialchars($validCat); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="platform[]" required <?php echo $index < 3 ? 'disabled' : ''; ?>>
                                    <option value="Amazon" <?php echo $cat['platform'] === 'Amazon' ? 'selected' : ''; ?>>Amazon</option>
                                    <option value="Flipkart" <?php echo $cat['platform'] === 'Flipkart' ? 'selected' : ''; ?>>Flipkart</option>
                                </select>
                                <?php if ($index >= 3): ?>
                                    <button type="button" class="btn btn-secondary remove-row"><i class="fas fa-trash"></i></button>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" id="add-row" class="btn btn-secondary" style="margin-bottom: 16px;"><i class="fas fa-plus"></i> Add Category</button>
                    <button type="submit" class="btn btn-primary">Save Categories</button>
                </form>
            </div>
        </div>
    </div>
    <?php include '../../include/footer.php'; ?>
    <div id="success-popup" class="popup" style="display: none;">
        <i class="fas fa-times popup-close" onclick="hidePopup('success-popup')"></i>
        <div class="popup-content"></div>
    </div>
    <div id="error-popup" class="popup" style="display: none;">
        <i class="fas fa-times popup-close" onclick="hidePopup('error-popup')"></i>
        <div class="popup-content"></div>
    </div>
    <div class="popup-overlay" style="display: none;"></div>
    <script src="/assets/js/admin.js"></script>
    <script>
        const categoryRows = document.getElementById('category-rows');
        const addRowButton = document.getElementById('add-row');
        const maxRows = 15;
        let rowCount = <?php echo count($categoryConfig); ?>;

        function updateAddButton() {
            addRowButton.style.display = rowCount >= maxRows ? 'none' : 'block';
        }

        addRowButton.addEventListener('click', () => {
            if (rowCount >= maxRows) return;

            const newRow = document.createElement('div');
            newRow.className = 'category-row';
            newRow.dataset.index = rowCount;
            newRow.innerHTML = `
                <input type="text" name="heading[]" placeholder="Heading" required>
                <select name="category[]" required>
                    <?php foreach ($validCategories as $validCat): ?>
                        <option value="<?php echo htmlspecialchars($validCat); ?>"><?php echo htmlspecialchars($validCat); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="platform[]" required>
                    <option value="Amazon">Amazon</option>
                    <option value="Flipkart">Flipkart</option>
                </select>
                <button type="button" class="btn btn-secondary remove-row"><i class="fas fa-trash"></i></button>
            `;
            categoryRows.appendChild(newRow);
            rowCount++;
            updateAddButton();
        });

        categoryRows.addEventListener('click', (e) => {
            if (e.target.classList.contains('remove-row') || e.target.parentElement.classList.contains('remove-row')) {
                const row = e.target.closest('.category-row');
                row.remove();
                rowCount--;
                updateAddButton();
            }
        });

        document.getElementById('category-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            const categories = [];
            const headings = formData.getAll('heading[]');
            const cats = formData.getAll('category[]');
            const platforms = formData.getAll('platform[]');

            for (let i = 0; i < headings.length; i++) {
                categories.push({
                    heading: headings[i],
                    category: cats[i],
                    platform: platforms[i]
                });
            }

            const response = await fetch('/admin/settings/category.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify({ categories })
            });
            const result = await response.json();

            if (result.status === 'success') {
                showPopup('success-popup', `<h3>Success</h3><p>${result.message}</p>`);
            } else {
                showPopup('error-popup', `<h3>Error</h3><p>${result.message}</p>`);
            }
        });

        updateAddButton();
    </script>
</body>
</html>