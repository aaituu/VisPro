<?php
/**
 * QuickVision Create Activation Code
 * Генерация кодов активации для пользователей
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

$user_id = (int)$data['user_id'];
$force_new = $data['force_new'] ?? false; // Создать новый код даже если есть неиспользованный

// Логирование
log_message('Activation code creation requested', 'info', [
    'user_id' => $user_id,
    'force_new' => $force_new,
    'ip' => get_client_ip()
]);

// Проверяем существование пользователя
$user = $db->getUserById($user_id);

if (!$user) {
    log_message('User not found for activation', 'error', ['user_id' => $user_id]);
    json_error('User not found', 404);
}

// Проверяем статус пользователя
if ($user['status'] === 'blocked') {
    log_message('Activation attempt for blocked user', 'warning', ['user_id' => $user_id]);
    json_error('User is blocked', 403);
}

// Проверяем наличие неиспользованных кодов
if (!$force_new) {
    $stmt = $pdo->prepare("
        SELECT code, created_at 
        FROM activations 
        WHERE user_id = ? 
          AND is_used = 0 
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        log_message('Returning existing activation code', 'info', [
            'user_id' => $user_id,
            'code' => substr($existing['code'], 0, 8) . '...'
        ]);
        
        json_success([
            'activation_code' => $existing['code'],
            'created_at' => $existing['created_at'],
            'is_new' => false,
            'message' => 'Using existing unused activation code'
        ]);
    }
}

// Генерируем новый уникальный код
try {
    $activation_code = generateUniqueActivationCode($pdo);
} catch (Exception $e) {
    log_message('Failed to generate activation code', 'error', [
        'user_id' => $user_id,
        'error' => $e->getMessage()
    ]);
    json_error('Failed to generate activation code. Please try again.', 500);
}

// Сохраняем в базу
try {
    $created = $db->createActivation($user_id, $activation_code);
    
    if (!$created) {
        throw new Exception('Database insert failed');
    }
    
    // Логируем успешное создание
    $db->logActivity($user_id, 'activation_code_created', [
        'code' => substr($activation_code, 0, 8) . '...',
        'force_new' => $force_new
    ]);
    
    log_message('Activation code created', 'info', [
        'user_id' => $user_id,
        'code' => substr($activation_code, 0, 8) . '...'
    ]);
    
    // Отправляем код пользователю в Telegram (опционально)
    $send_telegram = $data['send_telegram'] ?? true;
    $telegram_sent = false;
    
    if ($send_telegram && $user['telegram_chat_id']) {
        $message = "🔑 *Ваш код активации:*\n\n";
        $message .= "`{$activation_code}`\n\n";
        $message .= "📥 [Скачать приложение](" . SITE_URL . "/download)\n\n";
        $message .= "*Инструкция:*\n";
        $message .= "1. Скачайте и запустите приложение\n";
        $message .= "2. Введите код активации\n";
        $message .= "3. Нажимайте Ctrl+Shift+X для отправки скриншотов\n\n";
        $message .= "⚠️ Не передавайте код другим людям!";
        
        $telegram_sent = sendTelegramMessage(
            $user['telegram_chat_id'],
            $message,
            MAIN_BOT_TOKEN
        );
    }
    
    // Успешный ответ
    json_success([
        'activation_code' => $activation_code,
        'user_id' => $user_id,
        'username' => $user['username'],
        'chat_id' => $user['telegram_chat_id'],
        'telegram_sent' => $telegram_sent,
        'is_new' => true,
        'created_at' => date('Y-m-d H:i:s'),
        'download_url' => SITE_URL . '/download'
    ], 'Activation code created successfully');
    
} catch (Exception $e) {
    log_message('Database error creating activation', 'error', [
        'user_id' => $user_id,
        'error' => $e->getMessage()
    ]);
    
    json_error('Database error: ' . $e->getMessage(), 500);
}