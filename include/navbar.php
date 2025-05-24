<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/marketplaces.php';
require_once __DIR__ . '/../config/social.php';
require_once __DIR__ . '/../config/session.php';
startApplicationSession();

$goldboxStmt = $pdo->query("SELECT COUNT(*) as count, MAX(last_updated) as last_updated FROM goldbox_products");
$goldboxData = $goldboxStmt->fetch(PDO::FETCH_ASSOC);
$showGoldbox = $marketplaces['amazon'] === 'active' && $goldboxData['count'] > 0 && strtotime($goldboxData['last_updated']) > strtotime('-24 hours');

$flipboxStmt = $pdo->query("SELECT COUNT(*) as count, MAX(last_updated) as last_updated FROM flipbox_products");
$flipboxData = $flipboxStmt->fetch(PDO::FETCH_ASSOC);
$showFlipbox = $marketplaces['flipkart'] === 'active' && $flipboxData['count'] > 0 && strtotime($flipboxData['last_updated']) > strtotime('-24 hours');
?>
<nav class="navbar">
    <div class="navbar-right">
    <?php if(isset($_SESSION['user_id']) || isset($_SESSION['admin_id'])): ?>
        <?php if(isset($_SESSION['admin_id'])): ?>
            <a href="/admin/dashboard.php" class="btn dashboard-btn" aria-label="Admin Dashboard">
                <i class="fas fa-tachometer-alt"></i> Admin Dashboard
            </a>
        <?php else: ?>
            <a href="/user/dashboard.php" class="btn dashboard-btn" aria-label="Dashboard">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
        <?php endif; ?>
    <?php else: ?>
        <a href="/auth/login.php" class="btn login-btn" aria-label="Login">
            <i class="fas fa-sign-in-alt"></i> Login
        </a>
    <?php endif; ?>
</div>
    <div class="navbar-center">
        <a href="/" class="navbar-logo"><img src="/assets/images/logos/website-logo.png" alt="AmezPrice Logo"></a>
    </div>
    <div class="navbar-left">
        <a href="/pages/todays-deals.php" class="btn" aria-label="Today's Deals"><i class="fas fa-fire"></i> Today’s Deals</a>
        <?php if ($showGoldbox): ?>
            <a href="/pages/goldbox.php" class="btn" aria-label="Goldbox Deals"><i class="fas fa-star"></i> Goldbox</a>
        <?php endif; ?>
        <?php if ($showFlipbox): ?>
            <a href="/pages/flipbox.php" class="btn" aria-label="Flipbox Deals"><i class="fas fa-gift"></i> Flipbox</a>
        <?php endif; ?>
    </div>
    <i class="fas fa-bars navbar-menu" aria-label="Toggle menu"></i>
</nav>
<script>
// Simple HTML sanitizer
window.sanitizeHtml = function(html, config) {
    const div = document.createElement('div');
    div.textContent = html;
    return div.innerHTML;
};
</script>