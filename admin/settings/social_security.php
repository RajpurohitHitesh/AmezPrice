<?php
require_once '../../config/database.php';
require_once '../../config/security.php';
require_once '../../config/social.php';
require_once '../../config/globals.php';
require_once '../../config/fontawesome.php';
require_once '../../middleware/csrf.php';
session_start();

// Debug logging
error_log("Session data on admin social settings: " . print_r($_SESSION, true));
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
    $social = $input['social'] ?? [];
    $security = $input['security'] ?? [];

    // Update Social config
    $socialConfig = [
        'instagram' => $social['instagram'] ?? $socialConfig['instagram'],
        'twitter' => $social['twitter'] ?? $socialConfig['twitter'],
        'telegram' => $social['telegram'] ?? $socialConfig['telegram'],
        'facebook' => $social['facebook'] ?? $socialConfig['facebook'],
    ];
    file_put_contents('../../config/social.php', "<?php\nreturn " . var_export($socialConfig, true) . ";\n?>");

    // Update FontAwesome config
    $fontawesomeConfig = [
        'kit_id' => $security['fontawesome_kit_id'] ?? $fontawesomeConfig['kit_id']
    ];
    file_put_contents('../../config/fontawesome.php', "<?php\nreturn " . var_export($fontawesomeConfig, true) . ";\n?>");

    // Update Security config (JWT only)
    $securityConfig['jwt']['secret'] = $security['jwt_secret'] ?? $securityConfig['jwt']['secret'];
    file_put_contents('../../config/security.php', "<?php\nreturn " . var_export($securityConfig, true) . ";\n?>");

    file_put_contents('ADMIN_LOG_PATH', "[" . date('Y-m-d H:i:s') . "] Social & Security settings updated by admin ID {$_SESSION['admin_id']}\n", FILE_APPEND);
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
    <title>Social & Security Settings - AmezPrice</title>
    <link rel="stylesheet" href="/assets/css/admin.css">
    <script src="https://kit.fontawesome.com/<?php echo $kit_id; ?>.js" crossorigin="anonymous"></script>
</head>
<body>
    <?php include '../../include/navbar.php'; ?>
    <div class="admin-container">
        <?php include '../../include/admin_sidebar.php'; ?>
        <div class="admin-content">
            <div class="settings-submenu">
                <a href="/admin/settings/api_ui.php">API & UI</a>
                <a href="/admin/settings/category.php">Category</a>
                <a href="/admin/settings/telegram.php">Telegram</a>
                <a href="/admin/settings/social_security.php" class="active">Social & Security</a>
                <a href="/admin/settings/mail.php">Mail</a>
            </div>
            <h1>Social & Security Settings</h1>
            <div class="card" style="display: flex; gap: 24px;">
                <div style="flex: 1;">
                    <h2>Social Media</h2>
                    <form id="social-form">
                        <label for="instagram">Instagram URL</label>
                        <input type="text" name="instagram" value="<?php echo htmlspecialchars($socialConfig['instagram']); ?>">
                        <label for="twitter">Twitter URL</label>
                        <input type="text" name="twitter" value="<?php echo htmlspecialchars($socialConfig['twitter']); ?>">
                        <label for="telegram">Telegram URL</label>
                        <input type="text" name="telegram" value="<?php echo htmlspecialchars($socialConfig['telegram']); ?>">
                        <label for="facebook">Facebook URL</label>
                        <input type="text" name="facebook" value="<?php echo htmlspecialchars($socialConfig['facebook']); ?>">
                        <button type="submit" class="btn btn-primary">Save Social</button>
                    </form>
                </div>
                <div style="flex: 1;">
                    <h2>Security</h2>
                    <form id="security-form">
                        <label for="jwt_secret">JWT Secret</label>
                        <input type="text" name="jwt_secret" value="<?php echo htmlspecialchars($securityConfig['jwt']['secret']); ?>" required>
                        <label for="fontawesome_kit_id">FontAwesome Kit ID</label>
                        <input type="text" name="fontawesome_kit_id" value="<?php echo htmlspecialchars($fontawesomeConfig['kit_id']); ?>" required>
                        <button type="submit" class="btn btn-primary">Save Security</button>
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

            const response = await fetch('/admin/settings/social_security.php', {
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

        document.getElementById('social-form').addEventListener('submit', (e) => {
            e.preventDefault();
            saveSettings('social-form', 'social');
        });

        document.getElementById('security-form').addEventListener('submit', (e) => {
            e.preventDefault();
            saveSettings('security-form', 'security');
        });
    </script>
</body>
</html>