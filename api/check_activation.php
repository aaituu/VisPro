<?php
/**
 * QuickVision Activation Check
 * Проверяет код активации от клиента
 */

define('API_ACCESS', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db_connect.php';

// Только POST запросы
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

// Получаем данные
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    json_error('Invalid JSON data');
}

// Валидация
if (empty($data['activation_code'])) {
    json_error('Activation code is required');
}

$activation_code = trim($data['activation_code']);
$device_info = $data['device_info'] ?? null;

// Логирование
log_message('Activation check', 'info', [
    'code' => $activation_code,
    'ip' => get_client_ip()
]);

// Проверяем код в базе
$activation = $db->checkActivation($activation_code);

if (!$activation) {
    log_message('Invalid activation code', 'warning', [
        'code' => $activation_code,
        'ip' => get_client_ip()
    ]);
    
    json_error('Invalid activation code. Please check and try again.', 404);
}

$user_id = $activation['user_id'];

// Проверяем, не использован ли код
if ($activation['is_used'] == 1) {
    log_message('Activation code already used', 'info', [
        'code' => $activation_code,
        'user_id' => $user_id
    ]);
    
    // Если уже использован этим же IP - разрешаем (переустановка программы)
    if ($activation['ip_address'] === get_client_ip()) {
        // Проверяем статус пользователя
        $user = $db->getUserById($user_id);
        
        if (!$user) {
            json_error('User not found', 404);
        }
        
        if ($user['status'] === 'blocked') {
            json_error('Account blocked. Contact support.', 403);
        }
        
        // Проверяем подписку
        $is_active = $db->isSubscriptionActive($user_id);
        
        json_success([
            'user_id' => $user_id,
            'chat_id' => $user['telegram_chat_id'],
            'username' => $user['username'],
            'subscription_active' => $is_active,
            'expires_at' => $user['expires_at'],
            'status' => $user['status'],
            'message' => 'Activation restored'
        ], 'Activation successful');
    }
    
    json_error('This activation code has already been used on another device.', 409);
}

// Отмечаем код как использованный
$db->markActivationUsed(
    $activation_code, 
    get_client_ip(), 
    $device_info
);

// Логируем активность
$db->logActivity($user_id, 'activation_success', [
    'activation_code' => $activation_code,
    'device_info' => $device_info
]);

// Получаем данные пользователя
$user = $db->getUserById($user_id);

if (!$user) {
    json_error('User not found', 404);
}

// Проверяем статус
if ($user['status'] === 'blocked') {
    log_message('Blocked user attempted activation', 'warning', [
        'user_id' => $user_id,
        'code' => $activation_code
    ]);
    
    json_error('Your account has been blocked. Contact support.', 403);
}

// Проверяем подписку
$is_active = $db->isSubscriptionActive($user_id);

if (!$is_active) {
    log_message('Activation with expired subscription', 'info', [
        'user_id' => $user_id
    ]);
}

// Отправляем уведомление в Telegram
$message = "🎉 *Активация успешна!*\n\n";
$message .= "Приложение подключено к вашему аккаунту.\n";
$message .= "Используйте горячую клавишу *Ctrl+Shift+X* для отправки скриншота.\n\n";

if ($is_active) {
    $expires = new DateTime($user['expires_at']);
    $now = new DateTime();
    $diff = $now->diff($expires);
    
    $message .= "⏰ Подписка активна до: " . $expires->format('d.m.Y H:i') . "\n";
    $message .= "⏳ Осталось: {$diff->days} дн. {$diff->h} ч.\n";
} else {
    $message .= "⚠️ *Подписка не активна*\n";
    $message .= "Для использования купите подписку: /buy\n";
}

sendTelegramMessage(
    $user['telegram_chat_id'],
    $message,
    MAIN_BOT_TOKEN
);

log_message('Activation successful', 'info', [
    'user_id' => $user_id,
    'username' => $user['username']
]);

// Успешный ответ
json_success([
    'user_id' => $user_id,
    'chat_id' => $user['telegram_chat_id'],
    'username' => $user['username'],
    'subscription_active' => $is_active,
    'expires_at' => $user['expires_at'],
    'hours_purchased' => $user['hours_purchased'],
    'status' => $user['status']
], 'Activation successful');

/**
 * Отправка сообщения в Telegram
 */
function sendTelegramMessage($chat_id, $text, $bot_token) {
    $url = "https://api.telegram.org/bot{$bot_token}/sendMessage";
    
    $payload = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'Markdown'
    ];
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 5
    ]);
    
    curl_exec($ch);
    curl_close($ch);
}