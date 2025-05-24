<?php
require_once '../../config/database.php';
require_once '../../config/globals.php';
require_once '../../config/security.php';
require_once '../../middleware/csrf.php';
session_start();

// Debug logging
error_log("Session data on admin logs: " . print_r($_SESSION, true));
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

$logDir = '../../logs/';
$logs = array_diff(scandir($logDir), ['.', '..']);
usort($logs, function($a, $b) use ($logDir) {
    return filemtime($logDir . $b) - filemtime($logDir . $a);
});

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;
$totalPages = ceil(count($logs) / $perPage);
$paginatedLogs = array_slice($logs, $offset, $perPage);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include '../include/header.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logs - AmezPrice</title>
    <link rel="stylesheet" href="/assets/css/admin.css">
    <script src="https://kit.fontawesome.com/<?php echo $kit_id; ?>.js" crossorigin="anonymous"></script>
</head>
<body>
    <?php include '../../include/navbar.php'; ?>
    <div class="admin-container">
        <?php include '../../include/admin_sidebar.php'; ?>
        <div class="admin-content">
            <h1>Logs</h1>
            <div class="card">
                <p>Logs older than 3 months are automatically deleted. You can also manually delete logs below.</p>
                <div class="admin-table">
                    <table>
                        <thead>
                            <tr>
                                <th class="sortable">Log File</th>
                                <th class="sortable">Last Modified</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($paginatedLogs as $log): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($log); ?></td>
                                    <td><?php echo date('Y-m-d H:i:s', filemtime($logDir . $log)); ?></td>
                                    <td>
                                        <button class="btn btn-primary" onclick="viewLog('<?php echo htmlspecialchars($log); ?>')">View</button>
                                        <button class="btn btn-delete" onclick="confirmDeleteLog('<?php echo htmlspecialchars($log); ?>')">Delete</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="pagination" style="text-align: center; margin-top: 24px;">
                    <?php if ($page > 1): ?>
                        <a href="/admin/logs/index.php?page=<?php echo $page - 1; ?>" class="btn btn-secondary">Prev</a>
                    <?php endif; ?>
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <a href="/admin/logs/index.php?page=<?php echo $i; ?>" class="btn <?php echo $i === $page ? 'btn-primary' : 'btn-secondary'; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>
                    <?php if ($page < $totalPages): ?>
                        <a href="/admin/logs/index.php?page=<?php echo $page + 1; ?>" class="btn btn-secondary">Next</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php include '../../include/footer.php'; ?>
    <div id="log-view-popup" class="popup" style="display: none;">
        <i class="fas fa-times popup-close" onclick="hidePopup('log-view-popup')"></i>
        <div class="popup-content" style="max-height: 500px; overflow-y: auto;"></div>
    </div>
    <div id="delete-log-popup" class="popup" style="display: none;">
        <i class="fas fa-times popup-close" onclick="hidePopup('delete-log-popup')"></i>
        <div class="popup-content"></div>
    </div>
    <div class="popup-overlay" style="display: none;"></div>
    <script src="/assets/js/admin.js"></script>
    <script>
        async function viewLog(filename) {
            const response = await fetch('/admin/logs/view.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify({ filename })
            });
            const result = await response.json();

            if (result.status === 'success') {
                showPopup('log-view-popup', `<h3>${filename}</h3><pre>${result.content}</pre>`);
            } else {
                showPopup('error-popup', `<h3>Error</h3><p>${result.message}</p>`);
            }
        }
    </script>
</body>
</html>