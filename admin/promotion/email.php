<?php
require_once '../../config/database.php';
require_once '../../config/mail.php';
require_once '../../config/globals.php';
require_once '../../email/send.php';
require_once '../../config/security.php';
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $subject = $input['subject'] ?? '';
    $message = $input['message'] ?? '';

    if (!$subject || !$message) {
        echo json_encode(['status' => 'error', 'message' => 'Subject and message are required']);
        exit;
    }

    $stmt = $pdo->query("SELECT email FROM email_subscriptions WHERE subscribed = 'yes'");
    $emails = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $successCount = 0;
    foreach ($emails as $email) {
        if (sendEmail($email, $subject, $message, 'offers')) {
            $successCount++;
        }
        sleep(0.1); // Prevent overwhelming the SMTP server
    }

    file_put_contents('../../logs/promotion.log', "[" . date('Y-m-d H:i:s') . "] Email promotion sent to $successCount users\n", FILE_APPEND);
    echo json_encode(['status' => 'success', 'message' => "Promotion sent to $successCount users"]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include '../include/header.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Promotion - AmezPrice</title>
    <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body>
    <?php include '../../include/navbar.php'; ?>
    <div class="admin-container">
        <?php include '../../include/admin_sidebar.php'; ?>
        <div class="admin-content">
            <h1>Email Promotion</h1>
            <div class="card">
                <form id="email-promotion-form">
                    <label for="subject">Subject</label>
                    <input type="text" name="subject" id="subject" required>
                    <label for="message">Message (HTML)</label>
                    <textarea name="message" id="message" required></textarea>
                    <button type="submit" class="btn btn-primary">Send Email Promotion</button>
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
        document.getElementById('email-promotion-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            const data = {
                subject: formData.get('subject'),
                message: formData.get('message')
            };

            const response = await fetch('/admin/promotion/email.php', {
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
        });
    </script>
</body>
</html>