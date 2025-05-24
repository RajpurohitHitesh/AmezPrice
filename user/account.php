<?php
require_once '../config/database.php';
require_once '../config/security.php';
require_once '../config/globals.php';
require_once '../email/send.php';
require_once '../middleware/csrf.php';
session_start();

// Debug logging
error_log("Session data on user account: " . print_r($_SESSION, true));
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
$stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
$stmt->execute([$userId]);
$userEmail = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT subscribed FROM email_subscriptions WHERE email = ?");
$stmt->execute([$userEmail]);
$subscription = $stmt->fetchColumn() === 'yes';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';

    if ($action === 'toggle_subscription') {
        $subscribed = filter_var($input['subscribed'], FILTER_VALIDATE_BOOLEAN);
        $stmt = $pdo->prepare("
            INSERT INTO email_subscriptions (email, subscribed)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE subscribed = ?
        ");
        $stmt->execute([$userEmail, $subscribed ? 'yes' : 'no', $subscribed ? 'yes' : 'no']);

        file_put_contents('USER_LOG_PATH', "[" . date('Y-m-d H:i:s') . "] Subscription toggled to " . ($subscribed ? 'yes' : 'no') . " for user ID $userId\n", FILE_APPEND);
        echo json_encode(['status' => 'success', 'message' => 'Subscription updated']);
        exit;
    }

    if ($action === 'request_otp') {
        $otp = sprintf("%06d", random_int(0, 999999));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+5 minutes'));

        $stmt = $pdo->prepare("INSERT INTO otps (email, otp, expires_at) VALUES (?, ?, ?)");
        $stmt->execute([$userEmail, $otp, $expiresAt]);

        $subject = "Your Account Deletion Verification Code";
        $message = file_get_contents('../email/templates/otp_email.php');
        $message = str_replace('{{otp}}', $otp, $message);
        sendEmail($userEmail, $subject, $message, 'otp');

        file_put_contents('USER_LOG_PATH', "[" . date('Y-m-d H:i:s') . "] OTP sent to $userEmail for account deletion\n", FILE_APPEND);
        echo json_encode(['status' => 'success', 'message' => 'OTP sent to your email']);
        exit;
    }

    if ($action === 'verify_otp') {
        $otp = $input['otp'] ?? null;
        if (!$otp) {
            echo json_encode(['status' => 'error', 'message' => 'OTP is required']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM otps WHERE email = ? AND otp = ? AND expires_at > NOW()");
        $stmt->execute([$userEmail, $otp]);
        if (!$stmt->fetch()) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid or expired OTP']);
            exit;
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("DELETE FROM user_products WHERE user_id = ?");
            $stmt->execute([$userId]);

            $stmt = $pdo->prepare("DELETE FROM user_requests WHERE user_id = ?");
            $stmt->execute([$userId]);

            $stmt = $pdo->prepare("DELETE FROM user_behavior WHERE user_id = ?");
            $stmt->execute([$userId]);

            $stmt = $pdo->prepare("DELETE FROM email_subscriptions WHERE email = ?");
            $stmt->execute([$userEmail]);

            $stmt = $pdo->prepare("DELETE FROM push_subscriptions WHERE user_id = ?");
            $stmt->execute([$userId]);

            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$userId]);

            $stmt = $pdo->prepare("DELETE FROM otps WHERE email = ?");
            $stmt->execute([$userEmail]);

            $pdo->commit();
            session_destroy();
            file_put_contents('USER_LOG_PATH', "[" . date('Y-m-d H:i:s') . "] User ID $userId deleted account\n", FILE_APPEND);
            echo json_encode(['status' => 'success', 'redirect' => '/auth/login.php']);
        } catch (Exception $e) {
            $pdo->rollBack();
            file_put_contents('USER_LOG_PATH', "[" . date('Y-m-d H:i:s') . "] Error deleting user ID $userId: " . $e->getMessage() . "\n", FILE_APPEND);
            echo json_encode(['status' => 'error', 'message' => 'Failed to delete account']);
        }
        exit;
    }
}

// Handle unsubscribe via GET parameter
if (isset($_GET['unsubscribe']) && $_GET['unsubscribe'] === 'true') {
    $stmt = $pdo->prepare("UPDATE email_subscriptions SET subscribed = 'no' WHERE email = ?");
    $stmt->execute([$userEmail]);
    file_put_contents('USER_LOG_PATH', "[" . date('Y-m-d H:i:s') . "] User ID $userId unsubscribed via link\n", FILE_APPEND);
    $subscription = false;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include '../include/header.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account - AmezPrice</title>
    <link rel="stylesheet" href="/assets/css/user.css">
    <script src="<?php echo fa_kit_url(); ?>" crossorigin="anonymous"></script>
</head>
<body>
    <?php include '../include/navbar.php'; ?>
    <div class="user-container">
        <?php include '../include/user_sidebar.php'; ?>
        <div class="user-content">
            <h1>Account</h1>
            <div class="account-section">
                <div class="card">
                    <h2>Email Subscriptions</h2>
                    <p>Hot deals subscription</p>
                    <div class="toggle <?php echo $subscription ? 'on' : ''; ?>" id="subscription-toggle"></div>
                    <p id="subscription-status"><?php echo $subscription ? 'Unsubscribe' : 'Subscribe'; ?></p>
                    <div class="notes">
                        <p>By enabling this, you will receive hot deals in your email from AmezPrice.</p>
                        <p>You can unsubscribe anytime by clicking the unsubscribe link in the email or by toggling it off here.</p>
                    </div>
                </div>
                <div class="card">
                    <h2>Delete Account</h2>
                    <p>Delete your account and all associated data permanently.</p>
                    <button class="btn btn-delete" onclick="confirmDeleteAccount()">Delete Account</button>
                </div>
            </div>
        </div>
    </div>
    <?php include '../include/footer.php'; ?>
    <div id="delete-account-popup" class="popup" style="display: none;">
        <i class="fas fa-times popup-close" onclick="hidePopup('delete-account-popup')"></i>
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
    <script src="/assets/js/user.js"></script>
    <script>
        document.getElementById('subscription-toggle').addEventListener('click', async () => {
            const isOn = document.getElementById('subscription-toggle').classList.contains('on');
            const data = {
                action: 'toggle_subscription',
                subscribed: !isOn
            };

            const response = await fetch('/user/account.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify(data)
            });
            const result = await response.json();

            if (result.status === 'success') {
                document.getElementById('subscription-toggle').classList.toggle('on');
                document.getElementById('subscription-status').textContent = isOn ? 'Subscribe' : 'Unsubscribe';
            } else {
                showPopup('error-popup', `<h3>Error</h3><p>${result.message}</p>`);
            }
        });
    </script>
</body>
</html>