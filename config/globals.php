<?php

define('EMAIL_LOG_PATH', __DIR__ . '/../logs/email.log'); // Proper log file path
define('USER_LOG_PATH', __DIR__ . '/../logs/user.log'); // Proper log file path
define('ADMIN_LOG_PATH', __DIR__ . '/../logs/admin.log'); // Already correct

// FontAwesome configuration
function getFontAwesomeConfig() {
    static $config = null;
    if ($config === null) {
        $config = include __DIR__ . '/fontawesome.php';
    }
    return $config;
}

function getFontAwesomeKitUrl() {
    $config = getFontAwesomeConfig();
    return "https://kit.fontawesome.com/{$config['kit_id']}.js";
}

?>