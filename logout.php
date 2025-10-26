<?php
require_once __DIR__ . '/root/config.php';
require_once __DIR__ . '/root/utils.php';

// Eliminar cookie auth
setcookie('auth', '', time() - 3600, '/', '', false, true);

// Destruir sesión si existe
if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

header("Location: {$_URL_}/login.php");
exit;
?>