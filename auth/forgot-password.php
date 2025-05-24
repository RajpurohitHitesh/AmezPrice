<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/globals.php';
require_once __DIR__ . '/../email/send.php';
require_once __DIR__ . '/../middleware/csrf.php';
require_once __DIR__ . '/../config/session.php';
startApplicationSession();

// Log form rendering
file_put_contents(__DIR__ . '/../logs/auth.log', "[" . date('Y-m-d H:i:s') . "] Forgot password form rendered, Session ID: " . session_id() . "\n", FILE_APPEND);

// Prevent raw JSON display for GET requests
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Render form (already below)
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Debug logging
    file_put_contents(__DIR__ . '/../logs/auth.log', 
        "[" . date('Y-m-d H:i:s') . "] POST request received for forgot password\n" .
        "Content-Type: " . ($_SERVER['CONTENT_TYPE'] ?? 'not set') . "\n" .
        "Accept: " . ($_SERVER['HTTP_ACCEPT'] ?? 'not set') . "\n", 
        FILE_APPEND);
    
    // Ensure JSON response
    header('Content-Type: application/json');
    
    try {
        $rawInput = file_get_contents('php://input');
        file_put_contents(__DIR__ . '/../logs/auth.log', 
            "[" . date('Y-m-d H:i:s') . "] Raw input: " . $rawInput . "\n", 
            FILE_APPEND);
        
        $input = json_decode($rawInput, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON input: ' . json_last_error_msg());
        }
        
        $email = trim($input['email'] ?? '');
        $otp = $input['otp'] ?? null;
        $newPassword = $input['new_password'] ?? '';
        $confirmPassword = $input['confirm_password'] ?? '';

        file_put_contents(__DIR__ . '/../logs/auth.log', 
            "[" . date('Y-m-d H:i:s') . "] Parsed data: email=$email, otp=" . ($otp ? 'provided' : 'empty') . ", new_password=" . ($newPassword ? 'provided' : 'empty') . "\n", 
            FILE_APPEND);

        if (!$email) {
            echo json_encode(['status' => 'error', 'message' => 'Email is required']);
            exit;
        }

        // Check admin or user
        $stmt = $pdo->prepare("SELECT * FROM admins WHERE email = ?");
        $stmt->execute([$email]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$admin && !$user) {
            echo json_encode(['status' => 'error', 'message' => 'Email not found']);
            exit;
        }

        $account = $admin ?: $user;
        $table = $admin ? 'admins' : 'users';

        if (!$otp && !$newPassword) {
            // Generate OTP
            $otp = sprintf("%06d", random_int(0, 999999));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+5 minutes'));
            
            // Add rate limiting
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM otps WHERE email = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
            $stmt->execute([$email]);
            if ($stmt->fetchColumn() > 5) {
                echo json_encode(['status' => 'error', 'message' => 'Too many OTP requests. Please try again later.']);
                exit;
            }

            $stmt = $pdo->prepare("INSERT INTO otps (email, otp, expires_at) VALUES (?, ?, ?)");
            $stmt->execute([$email, $otp, $expiresAt]);

            $subject = "Your Password Reset Verification Code";
            $message = file_get_contents(__DIR__ . '/../email/templates/otp_email.php');
            $message = str_replace('{{otp}}', $otp, $message);
            sendEmail($email, $subject, $message, 'otp');

            file_put_contents(__DIR__ . '/../logs/auth.log', "[" . date('Y-m-d H:i:s') . "] OTP sent to $email for password reset\n", FILE_APPEND);
            echo json_encode(['status' => 'success', 'message' => 'OTP sent to your email']);
            exit;
        }

        if ($otp && !$newPassword) {
            // Verify OTP
            $stmt = $pdo->prepare("SELECT * FROM otps WHERE email = ? AND otp = ? AND expires_at > NOW()");
            $stmt->execute([$email, $otp]);
            if (!$stmt->fetch()) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid or expired OTP']);
                exit;
            }

            echo json_encode(['status' => 'success', 'message' => 'OTP verified, please enter new password']);
            exit;
        }

        if ($otp && $newPassword && $confirmPassword) {
            // Verify OTP again
            $stmt = $pdo->prepare("SELECT * FROM otps WHERE email = ? AND otp = ? AND expires_at > NOW()");
            $stmt->execute([$email, $otp]);
            if (!$stmt->fetch()) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid or expired OTP']);
                exit;
            }

            if ($newPassword !== $confirmPassword) {
                echo json_encode(['status' => 'error', 'message' => 'Passwords do not match']);
                exit;
            }

            if (strlen($newPassword) < 8 || !preg_match('/[A-Z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword) || !preg_match('/[!@#$%^&*]/', $newPassword)) {
                echo json_encode(['status' => 'error', 'message' => 'Password must be at least 8 characters, include an uppercase letter, a number, and a special character']);
                exit;
            }

            // Update password
            $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("UPDATE $table SET password = ? WHERE email = ?");
            $stmt->execute([$hashedPassword, $email]);

            $stmt = $pdo->prepare("DELETE FROM otps WHERE email = ?");
            $stmt->execute([$email]);

            file_put_contents(__DIR__ . '/../logs/auth.log', "[" . date('Y-m-d H:i:s') . "] Password reset successful for $email\n", FILE_APPEND);
            echo json_encode(['status' => 'success', 'redirect' => '/auth/login.php']);
            exit;
        }
        
    } catch (Exception $e) {
        file_put_contents(__DIR__ . '/../logs/auth.log', 
            "[" . date('Y-m-d H:i:s') . "] Exception in forgot password: " . $e->getMessage() . "\n", 
            FILE_APPEND);
        echo json_encode(['status' => 'error', 'message' => 'Server error: ' . $e->getMessage()]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/../include/header.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - AmezPrice</title>
    <link rel="stylesheet" href="/assets/css/main.css">
</head>
<body>
    <?php include __DIR__ . '/../include/navbar.php'; ?>
    <main class="container">
        <div class="auth-card">
            <h2>Forgot Password</h2>
            <form id="forgot-password-form" method="post" action="" onsubmit="return false;">
                <input type="email" name="email" placeholder="Enter your email" required aria-label="Email">
                <button type="submit" class="btn btn-primary">Send OTP</button>
            </form>
            <form id="otp-form" style="display: none;" onsubmit="return false;">
                <input type="text" name="otp" placeholder="Enter OTP" required aria-label="OTP">
                <input type="password" name="new_password" placeholder="Enter new password" required aria-label="New Password">
                <input type="password" name="confirm_password" placeholder="Confirm new password" required aria-label="Confirm Password">
                <button type="submit" class="btn btn-primary">Save Password</button>
            </form>
            <div class="auth-links">
                <a href="/auth/login.php">Back to Login</a>
            </div>
            <noscript>
                <p style="color: red;">JavaScript is disabled. Please enable JavaScript to use the forgot password form.</p>
            </noscript>
        </div>
    </main>
    <?php include __DIR__ . '/../include/footer.php'; ?>
    <div id="error-popup" class="popup" style="display: none;">
        <i class="fas fa-times popup-close" aria-label="Close Error Popup"></i>
        <div class="popup-content"></div>
    </div>
    <div class="popup-overlay" style="display: none;"></div>
    <script src="../assets/js/main.js?v=<?php echo time(); ?>"></script>
    <script>
        // Debug script loading
        console.log('Forgot password page script loaded at:', new Date().toISOString());
        fetch('../assets/js/main.js?v=<?php echo time(); ?>')
            .then(response => {
                if (!response.ok) {
                    throw new Error('Failed to load main.js: ' + response.status);
                }
                console.log('main.js fetched successfully');
            })
            .catch(error => {
                console.error('Error loading main.js:', error);
                document.body.insertAdjacentHTML('beforeend', '<p style="color: red; text-align: center;">Error: JavaScript not loaded (' + error.message + '). Please check browser settings or server path.</p>');
            });
        if (typeof Auth === 'undefined') {
            console.error('Auth module not loaded. Check main.js path or browser settings.');
            document.body.insertAdjacentHTML('beforeend', '<p style="color: red; text-align: center;">Error: JavaScript not loaded. Please enable JavaScript or check browser settings.</p>');
        } else {
            console.log('Auth module available');
        }
    </script>
</body>
</html>