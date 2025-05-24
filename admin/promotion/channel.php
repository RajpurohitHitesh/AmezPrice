<?php
require_once '../../config/database.php';
require_once '../../config/telegram.php';
require_once '../../config/security.php';
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $channel = $input['channel'] ?? '';
    $message = $input['message'] ?? '';
    $image = $input['image'] ?? null;

    if (!in_array($channel, ['amezprice', 'hotdeals', 'updates']) || !$message) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid channel or message']);
        exit;
    }

    $botToken = $telegramConfig['amezpricebot_token'];
    $chatId = $telegramConfig['channels'][$channel];
    $url = "https://api.telegram.org/bot{$botToken}/sendPhoto";

    $tempImagePath = null;
    if ($image) {
        $imageData = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $image));
        $tempImagePath = "../../assets/images/promotion/" . time() . "_promo.jpg";
        file_put_contents($tempImagePath, $imageData);
        file_put_contents('../../logs/promotion.log', "[" . date('Y-m-d H:i:s') . "] Promotion image saved: $tempImagePath\n", FILE_APPEND);
    }

    $payload = [
        'chat_id' => $chatId,
        'caption' => $message,
        'parse_mode' => 'Markdown'
    ];

    $ch = curl_init($url);
    if ($tempImagePath) {
        $payload['photo'] = new CURLFile($tempImagePath);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    } else {
        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
    }

    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $result = json_decode($response, true);
    curl_close($ch);

    if ($tempImagePath) {
        unlink($tempImagePath);
    }

    if ($result['ok']) {
        file_put_contents('../../logs/promotion.log', "[" . date('Y-m-d H:i:s') . "] Promotion sent to $channel\n", FILE_APPEND);
        echo json_encode(['status' => 'success', 'message' => 'Promotion sent']);
    } else {
        file_put_contents('../../logs/promotion.log', "[" . date('Y-m-d H:i:s') . "] Failed to send promotion to $channel: " . $response . "\n", FILE_APPEND);
        echo json_encode(['status' => 'error', 'message' => 'Failed to send promotion']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include '../include/header.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Channel Promotion - AmezPrice</title>
    <link rel="stylesheet" href="/assets/css/admin.css">
    <script src="https://kit.fontawesome.com/6a410e136c.js" crossorigin="anonymous"></script>
</head>
<body>
    <?php include '../../include/navbar.php'; ?>
    <div class="admin-container">
        <?php include '../../include/admin_sidebar.php'; ?>
        <div class="admin-content">
            <h1>Channel Promotion</h1>
            <div class="card">
                <form id="promotion-form">
                    <label for="channel">Select Channel</label>
                    <select name="channel" id="channel" required>
                        <option value="amezprice">AmezPrice</option>
                        <option value="hotdeals">HotDeals</option>
                        <option value="updates">Updates</option>
                    </select>
                    <label for="message">Message</label>
                    <textarea name="message" id="message" required></textarea>
                    <label for="image">Image (Optional)</label>
                    <input type="file" name="image" id="image" accept="image/*">
                    <button type="submit" class="btn btn-primary">Send Promotion</button>
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
        document.getElementById('promotion-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            const data = {
                channel: formData.get('channel'),
                message: formData.get('message')
            };

            const imageFile = formData.get('image');
            if (imageFile && imageFile.size > 0) {
                const reader = new FileReader();
                reader.onload = async () => {
                    data.image = reader.result;
                    await sendPromotion(data);
                };
                reader.readAsDataURL(imageFile);
            } else {
                await sendPromotion(data);
            }
        });

        async function sendPromotion(data) {
            const response = await fetch('/admin/promotion/channel.php', {
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
    </script>
</body>
</html>