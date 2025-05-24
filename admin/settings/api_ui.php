<?php
require_once '../../config/database.php';
require_once '../../config/security.php';
require_once '../../config/amazon.php';
require_once '../../config/flipkart.php';
require_once '../../config/marketplaces.php';
require_once '../../config/globals.php';
require_once '../../middleware/csrf.php';
session_start();

// Debug logging
error_log("Session data on admin api settings: " . print_r($_SESSION, true));
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $amazon = $input['amazon'] ?? [];
    $flipkart = $input['flipkart'] ?? [];
    $marketplaces = $input['marketplaces'] ?? [];

    // Update Amazon config
    $amazonConfig = [
        'access_key' => $amazon['access_key'] ?? $amazonConfig['access_key'],
        'secret_key' => $amazon['secret_key'] ?? $amazonConfig['secret_key'],
        'associate_tag' => $amazon['associate_tag'] ?? $amazonConfig['associate_tag'],
        'region' => $amazon['region'] ?? $amazonConfig['region'],
        'api_status' => $amazon['api_status'] ?? $amazonConfig['api_status']
    ];
    file_put_contents('../../config/amazon.php', "<?php\nreturn " . var_export($amazonConfig, true) . ";\n?>");

    // Update Flipkart config
    $flipkartConfig = [
        'affiliate_id' => $flipkart['affiliate_id'] ?? $flipkartConfig['affiliate_id'],
        'token' => $flipkart['token'] ?? $flipkartConfig['token'],
        'api_status' => $flipkart['api_status'] ?? $flipkartConfig['api_status']
    ];
    file_put_contents('../../config/flipkart.php', "<?php\nreturn " . var_export($flipkartConfig, true) . ";\n?>");

    // Update Marketplaces config
    $marketplacesConfig = [
        'amazon' => $marketplaces['amazon'] ?? $marketplacesConfig['amazon'],
        'flipkart' => $marketplaces['flipkart'] ?? $marketplacesConfig['flipkart']
    ];
    file_put_contents('../../config/marketplaces.php', "<?php\nreturn " . var_export($marketplacesConfig, true) . ";\n?>");

    file_put_contents('ADMIN_LOG_PATH', "[" . date('Y-m-d H:i:s') . "] API & UI settings updated by admin ID {$_SESSION['admin_id']}\n", FILE_APPEND);
    echo json_encode(['status' => 'success', 'message' => 'Settings updated']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include '../include/header.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API & UI Settings - AmezPrice</title>
    <link rel="stylesheet" href="/assets/css/admin.css">
    <script src="<?php echo fa_kit_url(); ?>" crossorigin="anonymous"></script>
</head>
<body>
    <?php include '../../include/navbar.php'; ?>
    <div class="admin-container">
        <?php include '../../include/admin_sidebar.php'; ?>
        <div class="admin-content">
            <div class="settings-submenu">
                <a href="/admin/settings/api_ui.php" class="active">API & UI</a>
                <a href="/admin/settings/category.php">Category</a>
                <a href="/admin/settings/telegram.php">Telegram</a>
                <a href="/admin/settings/social_security.php">Social & Security</a>
                <a href="/admin/settings/mail.php">Mail</a>
            </div>
            <h1>API & UI Settings</h1>
            <div class="card" style="display: flex; gap: 24px;">
                <div style="flex: 1;">
                    <h2>Flipkart API</h2>
                    <form id="flipkart-form">
                        <label for="flipkart_affiliate_id">Affiliate ID</label>
                        <input type="text" name="affiliate_id" value="<?php echo htmlspecialchars($flipkartConfig['affiliate_id']); ?>" required>
                        <label for="flipkart_token">Token</label>
                        <input type="text" name="token" value="<?php echo htmlspecialchars($flipkartConfig['token']); ?>" required>
                        <label for="flipkart_api_status">API Status</label>
                        <select name="api_status">
                            <option value="active" <?php echo $flipkartConfig['api_status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $flipkartConfig['api_status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                        <button type="submit" class="btn btn-primary">Save Flipkart</button>
                    </form>
                </div>
                <div style="flex: 1;">
                    <h2>Marketplaces UI</h2>
                    <form id="marketplaces-form">
                        <label for="marketplace_amazon">Amazon UI</label>
                        <select name="amazon">
                            <option value="active" <?php echo $marketplacesConfig['amazon'] === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $marketplacesConfig['amazon'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                        <label for="marketplace_flipkart">Flipkart UI</label>
                        <select name="flipkart">
                            <option value="active" <?php echo $marketplacesConfig['flipkart'] === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $marketplacesConfig['flipkart'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                        <button type="submit" class="btn btn-primary">Save Marketplaces</button>
                    </form>
                </div>
                <div style="flex: 1;">
                    <h2>Amazon API</h2>
                    <form id="amazon-form">
                        <label for="amazon_access_key">Access Key</label>
                        <input type="text" name="access_key" value="<?php echo htmlspecialchars($amazonConfig['access_key']); ?>" required>
                        <label for="amazon_secret_key">Secret Key</label>
                        <input type="text" name="secret_key" value="<?php echo htmlspecialchars($amazonConfig['secret_key']); ?>" required>
                        <label for="amazon_associate_tag">Associate Tag</label>
                        <input type="text" name="associate_tag" value="<?php echo htmlspecialchars($amazonConfig['associate_tag']); ?>" required>
                        <label for="amazon_region">Region</label>
                        <input type="text" name="region" value="<?php echo htmlspecialchars($amazonConfig['region']); ?>" required>
                        <label for="amazon_api_status">API Status</label>
                        <select name="api_status">
                            <option value="active" <?php echo $amazonConfig['api_status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $amazonConfig['api_status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                        <button type="submit" class="btn btn-primary">Save Amazon</button>
                    </form>
                </div>
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
        async function saveSettings(formId, section) {
            const form = document.getElementById(formId);
            const formData = new FormData(form);
            const data = { [section]: Object.fromEntries(formData) };

            const response = await fetch('/admin/settings/api_ui.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify(data)
            });
            const result = await response.json();

            if (result.status === 'success') {
                showPopup('success-popup', `<h3>Success</h3><p>${result.message}</p>`);
            } else {
                showPopup('error-popup', `<h3>Error</h3><p>${result.message}</p>`);
            }
        }

        document.getElementById('amazon-form').addEventListener('submit', (e) => {
            e.preventDefault();
            saveSettings('amazon-form', 'amazon');
        });

        document.getElementById('flipkart-form').addEventListener('submit', (e) => {
            e.preventDefault();
            saveSettings('flipkart-form', 'flipkart');
        });

        document.getElementById('marketplaces-form').addEventListener('submit', (e) => {
            e.preventDefault();
            saveSettings('marketplaces-form', 'marketplaces');
        });
    </script>
</body>
</html>