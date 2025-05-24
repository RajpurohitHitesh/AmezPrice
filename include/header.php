<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../middleware/csrf.php';
require_once __DIR__ . '/../config/globals.php';
startApplicationSession();

$csrfToken = generateCsrfToken();

?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?php echo htmlspecialchars($csrfToken); ?>">
<link rel="icon" type="image/x-icon" href="/assets/images/icons/favicon.ico">
<link rel="apple-touch-icon" sizes="180x180" href="/assets/images/icons/apple-touch-icon.png">
<script src="https://kit.fontawesome.com/cb8a78a62d.js" crossorigin="anonymous"></script>