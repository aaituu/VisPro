<?php
/**
 * QuickVision Extend Subscription
 * Продление подписки пользователя (вручную или через оплату)
 */

define('API_ACCESS', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/functions.php';

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
if (empty($data['user_id'])) {
    json_error('User ID is required');
}

if (empty($data['hours']) || !is_numeric($data['hours']) || $data['hours'] <= 0) {
    json_error('Valid hours amount is required');
}

$user_id = (int)$data['user_id'];
$hours = (int)$data['hours'];
$payment_id = $data['payment_id'] ?? null;
$admin_action = $data['admin_action'] ?? false;
$reason = $data['reason'] ?? 'subscription_extension';

// Проверка безопасности для админских действий
if ($admin_action) {
    // Проверяем наличие admin токена или сессии
    session_start();
    if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
        // Или проверяем API ключ
        $admin_key = $_SERVER['HTTP_X_ADMIN_KEY'] ?? '';
        if ($admin_key !== API_SECRET_KEY) {
            json_error('Admin authentication required', 401);
        }
    }
}

// Логирование
log_message('Subscription extension requested', 'info', [
    'user_id' => $user_id,
    'hours' => $hours,
    'payment_id' => $payment_id,
    'admin_action' => $admin_action,
    'ip' => get_client_ip()
]);

// Проверяем существование пользователя
$user = $db->getUserById($user_id);

if (!$user) {
    log_message('User not found for extension', 'error', ['user_id' => $user_id]);
    json_error('User not found', 404);
}

// Проверяем статус (не продлеваем заблокированным)
if ($user['status'] === 'blocked' && !$admin_action) {
    log_message('Extension attempt for blocked user', 'warning', [
        'user_id' => $user_id
    ]);
    json_error('Cannot extend subscription for blocked user', 403);
}

// Получаем текущую дату истечения
$current_expires = $user['expires_at'];
$now = new DateTime();
$old_expires = $current_expires ? new DateTime($current_expires) : null;

// Вычисляем новую дату истечения
if (!$old_expires || $old_expires < $now) {
    // Если подписка уже истекла или отсутствует - начинаем с текущего момента
    $new_expires = clone $now;
    $new_expires->modify("+{$hours} hours");
} else {
    // Иначе добавляем к существующей дате
    $new_expires = clone $old_expires;
    $new_expires->modify("+{$hours} hours");
}

// Обновляем в базе
try {
    $success = $db->extendSubscription($user_id, $hours);
    
    if (!$success) {
        throw new Exception('Database update failed');
    }
    
    // Если был заблокирован и это админское действие - разблокируем
    if ($admin_action && $user['status'] === 'blocked') {
        $db->unblockUser($user_id);
    }
    
    // Логируем действие
    $db->logActivity($user_id, 'subscription_extended', [
        'hours_added' => $hours,
        'old_expires_at' => $current_expires,
        'new_expires_at' => $new_expires->format('Y-m-d H:i:s'),
        'payment_id' => $payment_id,
        'admin_action' => $admin_action,
        'reason' => $reason
    ]);
    
    log_message('Subscription extended', 'info', [
        'user_id' => $user_id,
        'hours' => $hours,
        'new_expires' => $new_expires->format('Y-m-d H:i:s')
    ]);
    
    // Отправляем уведомление пользователю
    $send_notification = $data['send_notification'] ?? true;
    $notification_sent = false;
    
    if ($send_notification && $user['telegram_chat_id']) {
        $message = "✅ *Подписка продлена!*\n\n";
        $message .= "⏰ Добавлено: *{$hours} " . declension($hours, ['час', 'часа', 'часов']) . "*\n";
        $message .= "📅 Активна до: *" . $new_expires->format('d.m.Y H:i') . "*\n\n";
        
        $diff = $now->diff($new_expires);
        $time_left = [];
        
        if ($diff->d > 0) {
            $time_left[] = $diff->d . ' ' . declension($diff->d, ['день', 'дня', 'дней']);
        }
        if ($diff->h > 0) {
            $time_left[] = $diff->h . ' ' . declension($diff->h, ['час', 'часа', 'часов']);
        }
        
        if (!empty($time_left)) {
            $message .= "⏳ Осталось: *" . implode(' ', $time_left) . "*\n\n";
        }
        
        $message .= "Спасибо за использование QuickVision! 🚀";
        
        $notification_sent = sendTelegramMessage(
            $user['telegram_chat_id'],
            $message,
            MAIN_BOT_TOKEN
        );
    }
    
    // Получаем обновленные данные пользователя
    $updated_user = $db->getUserById($user_id);
    
    // Успешный ответ
    json_success([
        'user_id' => $user_id,
        'username' => $user['username'],
        'hours_added' => $hours,
        'old_expires_at' => $current_expires,
        'new_expires_at' => $new_expires->format('Y-m-d H:i:s'),
        'total_hours_purchased' => (int)$updated_user['hours_purchased'],
        'status' => $updated_user['status'],
        'notification_sent' => $notification_sent,
        'time_left' => getTimeUntilExpiry($new_expires->format('Y-m-d H:i:s'))
    ], 'Subscription extended successfully');
    
} catch (Exception $e) {
    log_message('Database error extending subscription', 'error', [
        'user_id' => $user_id,
        'hours' => $hours,
        'error' => $e->getMessage()
    ]);
    
    json_error('Database error: ' . $e->getMessage(), 500);
}