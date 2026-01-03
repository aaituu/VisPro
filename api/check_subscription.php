<?php
/**
 * QuickVision Check Subscription
 * Проверка активности подписки пользователя
 */

define('API_ACCESS', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/functions.php';

// Разрешаем GET и POST
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'])) {
    json_error('Method not allowed', 405);
}

// Получаем данные
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        json_error('Invalid JSON data');
    }
} else {
    $data = $_GET;
}

// Валидация - должен быть либо user_id либо chat_id либо activation_code
$user_id = $data['user_id'] ?? null;
$chat_id = $data['chat_id'] ?? null;
$activation_code = $data['activation_code'] ?? null;

if (!$user_id && !$chat_id && !$activation_code) {
    json_error('User ID, Chat ID or Activation Code is required');
}

// Получаем пользователя
$user = null;

if ($user_id) {
    $user = $db->getUserById((int)$user_id);
} elseif ($chat_id) {
    $user = $db->getUserByChatId($chat_id);
} elseif ($activation_code) {
    $activation = $db->checkActivation($activation_code);
    if ($activation) {
        $user = $db->getUserById($activation['user_id']);
    }
}

if (!$user) {
    log_message('Subscription check: user not found', 'warning', [
        'user_id' => $user_id,
        'chat_id' => $chat_id,
        'activation_code' => $activation_code ? substr($activation_code, 0, 8) . '...' : null
    ]);
    
    json_error('User not found', 404);
}

$user_id = $user['id'];

// Логируем проверку
$db->logActivity($user_id, 'subscription_check', [
    'requested_by' => [
        'user_id' => $data['user_id'] ?? null,
        'chat_id' => $data['chat_id'] ?? null,
        'activation_code' => $activation_code ? substr($activation_code, 0, 8) . '...' : null
    ]
]);

// Проверяем статус
$status = $user['status'];
$expires_at = $user['expires_at'];
$is_active = false;
$reason = null;

if ($status === 'blocked') {
    $reason = 'User account is blocked';
} elseif ($status === 'expired') {
    $reason = 'Subscription has expired';
} elseif (!$expires_at) {
    $reason = 'Subscription not activated';
} elseif (strtotime($expires_at) < time()) {
    $reason = 'Subscription has expired';
    
    // Обновляем статус в БД если еще не обновлен
    if ($status !== 'expired') {
        $stmt = $pdo->prepare("UPDATE users SET status = 'expired' WHERE id = ?");
        $stmt->execute([$user_id]);
    }
} else {
    $is_active = true;
}

// Получаем дополнительную информацию
$now = new DateTime();
$expires_datetime = $expires_at ? new DateTime($expires_at) : null;

$time_left = null;
$expires_in_seconds = null;

if ($expires_datetime && $expires_datetime > $now) {
    $diff = $now->diff($expires_datetime);
    $time_left = getTimeUntilExpiry($expires_at);
    $expires_in_seconds = $expires_datetime->getTimestamp() - $now->getTimestamp();
}

// Получаем статистику использования
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_screenshots,
        COUNT(CASE WHEN created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) as screenshots_24h,
        COUNT(CASE WHEN created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR) THEN 1 END) as screenshots_1h,
        MAX(created_at) as last_screenshot
    FROM screenshots 
    WHERE user_id = ?
");
$stmt->execute([$user_id]);
$stats = $stmt->fetch();

// Получаем информацию о платежах
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_payments,
        SUM(amount) as total_spent,
        SUM(hours) as total_hours,
        MAX(created_at) as last_payment
    FROM payments 
    WHERE user_id = ? AND status = 'completed'
");
$stmt->execute([$user_id]);
$payment_info = $stmt->fetch();

// Формируем ответ
$response = [
    'user_id' => $user_id,
    'username' => $user['username'],
    'chat_id' => $user['telegram_chat_id'],
    'first_name' => $user['first_name'],
    'subscription' => [
        'is_active' => $is_active,
        'status' => $status,
        'expires_at' => $expires_at,
        'expires_in_seconds' => $expires_in_seconds,
        'time_left' => $time_left,
        'reason' => $reason,
        'hours_purchased' => (int)$user['hours_purchased']
    ],
    'usage_stats' => [
        'total_screenshots' => (int)$stats['total_screenshots'],
        'screenshots_24h' => (int)$stats['screenshots_24h'],
        'screenshots_1h' => (int)$stats['screenshots_1h'],
        'last_screenshot' => $stats['last_screenshot']
    ],
    'payment_info' => [
        'total_payments' => (int)$payment_info['total_payments'],
        'total_spent' => (float)$payment_info['total_spent'],
        'total_hours' => (int)$payment_info['total_hours'],
        'last_payment' => $payment_info['last_payment']
    ],
    'account_info' => [
        'created_at' => $user['created_at'],
        'updated_at' => $user['updated_at']
    ]
];

log_message('Subscription checked', 'info', [
    'user_id' => $user_id,
    'is_active' => $is_active,
    'expires_at' => $expires_at
]);

// Если подписка скоро истекает (< 2 часов) - уведомляем
if ($is_active && $expires_in_seconds && $expires_in_seconds < 7200) {
    $hours_left = round($expires_in_seconds / 3600, 1);
    
    // Проверяем, не отправляли ли мы уже уведомление
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM activity_logs 
        WHERE user_id = ? 
          AND action = 'expiry_warning_sent'
          AND created_at > DATE_SUB(NOW(), INTERVAL 3 HOUR)
    ");
    $stmt->execute([$user_id]);
    $warning_sent = $stmt->fetch()['count'] > 0;
    
    if (!$warning_sent && $user['telegram_chat_id']) {
        $message = "⚠️ *Внимание!*\n\n";
        $message .= "Ваша подписка истекает через *{$hours_left} ч.*\n\n";
        $message .= "Продлите подписку чтобы продолжить использование:\n";
        $message .= "/buy";
        
        sendTelegramMessage($user['telegram_chat_id'], $message, MAIN_BOT_TOKEN);
        
        $db->logActivity($user_id, 'expiry_warning_sent', [
            'expires_in_seconds' => $expires_in_seconds,
            'hours_left' => $hours_left
        ]);
    }
}

json_success($response, $is_active ? 'Subscription is active' : 'Subscription is not active');