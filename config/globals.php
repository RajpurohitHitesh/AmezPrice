<?php
require 'fontawesome.php';

define('EMAIL_LOG_PATH', __DIR__ . '/../logs/email.log'); // Proper log file path
define('USER_LOG_PATH', __DIR__ . '/../logs/user.log'); // Proper log file path
define('ADMIN_LOG_PATH', __DIR__ . '/../logs/admin.log'); // Already correct

return [
    'fontawesome' => [
        'kit_id' => '6a410e136c' // यहाँ अपना Kit ID डालें
    ]
];

?>