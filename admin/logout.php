<?php
/**
 * QuickVision Admin Logout
 * Выход из админской панели
 */

session_start();

define('API_ACCESS', true);
require_once __DIR__ . '/../api/config.php';

// Логирование выхода
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in']) {
    log_message('Admin logged out', 'info', [
        'ip' => get_client_ip(),
        'session_duration' => isset($_SESSION['admin_login_time']) 
            ? (time() - $_SESSION['admin_login_time']) . ' seconds' 
            : 'unknown'
    ]);
}

// Уничтожаем все данные сессии
$_SESSION = [];

// Удаляем cookie сессии если есть
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), 
        '', 
        time() - 42000,
        $params["path"], 
        $params["domain"],
        $params["secure"], 
        $params["httponly"]
    );
}

// Уничтожаем сессию
session_destroy();

// Редирект на страницу входа
header('Location: login.php?logged_out=1');
exit;