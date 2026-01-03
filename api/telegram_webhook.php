<?php
/**
 * QuickVision Telegram Bot Webhook
 * Основной бот для регистрации, оплаты и управления
 */

define('API_ACCESS', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db_connect.php';

// Получаем обновление от Telegram
$content = file_get_contents('php://input');
$update = json_decode($content, true);

if (!$update) {
    exit('No update');
}

log_message('Telegram webhook received', 'info', ['update_id' => $update['update_id'] ?? 'unknown']);

// Обработка сообщения
if (isset($update['message'])) {
    $message = $update['message'];
    $chat_id = $message['chat']['id'];
    $text = $message['text'] ?? '';
    $user_data = $message['from'];
    
    // Получаем или создаем пользователя
    $user = $db->getUserByChatId($chat_id);
    
    if (!$user) {
        // Создаем нового пользователя
        $user_id = $db->createUser(
            $chat_id,
            $user_data['username'] ?? null,
            $user_data['first_name'] ?? 'User',
            $user_data['last_name'] ?? null
        );
        
        $user = $db->getUserById($user_id);
        
        log_message('New user registered', 'info', [
            'user_id' => $user_id,
            'chat_id' => $chat_id,
            'username' => $user_data['username'] ?? null
        ]);
    }
    
    $user_id = $user['id'];
    
    // Логируем активность
    $db->logActivity($user_id, 'telegram_message', [
        'text' => mb_substr($text, 0, 100)
    ]);
    
    // Обработка команд
    if (strpos($text, '/') === 0) {
        handleCommand($text, $chat_id, $user, $db);
    } else {
        // Обработка обычного текста (например, ввод промокода)
        handleText($text, $chat_id, $user, $db);
    }
}

// Обработка callback query (кнопки)
if (isset($update['callback_query'])) {
    $callback = $update['callback_query'];
    $chat_id = $callback['message']['chat']['id'];
    $data = $callback['data'];
    $callback_id = $callback['id'];
    
    handleCallback($data, $chat_id, $callback_id, $db);
}

exit('OK');

// ================================================
// ОБРАБОТЧИКИ КОМАНД
// ================================================

/**
 * Обработка команд бота
 */
function handleCommand($text, $chat_id, $user, $db) {
    $command = strtolower(explode(' ', $text)[0]);
    
    switch ($command) {
        case '/start':
            commandStart($chat_id, $user);
            break;
            
        case '/buy':
            commandBuy($chat_id, $user);
            break;
            
        case '/status':
            commandStatus($chat_id, $user, $db);
            break;
            
        case '/help':
            commandHelp($chat_id);
            break;
            
        case '/support':
            commandSupport($chat_id);
            break;
            
        default:
            sendMessage($chat_id, "Неизвестная команда. Используйте /help для списка команд.");
    }
}

/**
 * /start - Приветствие
 */
function commandStart($chat_id, $user) {
    $name = $user['first_name'] ?: 'пользователь';
    
    $message = "👋 Привет, {$name}!\n\n";
    $message .= "🚀 *QuickVision* - ваш AI ассистент для тестов!\n\n";
    $message .= "📸 Как это работает:\n";
    $message .= "1️⃣ Купите подписку /buy\n";
    $message .= "2️⃣ Получите код активации\n";
    $message .= "3️⃣ Скачайте приложение\n";
    $message .= "4️⃣ Нажимайте Ctrl+Shift+X и получайте ответы!\n\n";
    $message .= "💡 Команды:\n";
    $message .= "/buy - Купить подписку\n";
    $message .= "/status - Проверить статус\n";
    $message .= "/help - Помощь\n";
    $message .= "/support - Поддержка\n";
    
    sendMessage($chat_id, $message);
}

/**
 * /buy - Покупка подписки
 */
function commandBuy($chat_id, $user) {
    global $PRICES;
    
    $message = "💳 *Выберите тариф:*\n\n";
    
    $keyboard = [
        'inline_keyboard' => []
    ];
    
    foreach ($PRICES as $hours => $price) {
        $message .= "⏰ *{$hours} " . declension($hours, ['час', 'часа', 'часов']) . "* - {$price} ₸\n";
        
        $keyboard['inline_keyboard'][] = [
            [
                'text' => "{$hours}ч - {$price}₸",
                'callback_data' => "buy:{$hours}"
            ]
        ];
    }
    
    $message .= "\n💵 Оплата через Kaspi QR";
    
    sendMessage($chat_id, $message, $keyboard);
}

/**
 * /status - Статус подписки
 */
function commandStatus($chat_id, $user, $db) {
    $is_active = $db->isSubscriptionActive($user['id']);
    $stats = $db->getUserStats($user['id']);
    
    $message = "📊 *Ваш статус:*\n\n";
    $message .= "👤 ID: {$user['id']}\n";
    $message .= "📱 Username: @" . ($user['username'] ?: 'не указан') . "\n\n";
    
    if ($is_active) {
        $expires = new DateTime($user['expires_at']);
        $now = new DateTime();
        $diff = $now->diff($expires);
        
        $message .= "✅ *Подписка активна*\n";
        $message .= "⏰ До: " . $expires->format('d.m.Y H:i') . "\n";
        $message .= "⏳ Осталось: {$diff->days} дн. {$diff->h} ч. {$diff->i} мин.\n\n";
    } else {
        $message .= "❌ *Подписка не активна*\n\n";
    }
    
    $message .= "📈 *Статистика:*\n";
    $message .= "📸 Скриншотов: " . ($stats['total_screenshots'] ?? 0) . "\n";
    $message .= "💰 Платежей: " . ($stats['total_payments'] ?? 0) . "\n";
    $message .= "⏱ Куплено часов: " . ($user['hours_purchased'] ?? 0) . "\n";
    
    if (!$is_active) {
        $message .= "\n➡️ Продлить: /buy";
    }
    
    sendMessage($chat_id, $message);
}

/**
 * /help - Справка
 */
function commandHelp($chat_id) {
    $message = "ℹ️ *Справка QuickVision*\n\n";
    $message .= "*Как пользоваться:*\n";
    $message .= "1. Купите подписку через /buy\n";
    $message .= "2. Получите код активации и ссылку на скачивание\n";
    $message .= "3. Запустите программу и введите код\n";
    $message .= "4. Нажмите Ctrl+Shift+X на любом экране теста\n";
    $message .= "5. Получите ответы в этом чате!\n\n";
    $message .= "*Команды:*\n";
    $message .= "/buy - Купить/продлить подписку\n";
    $message .= "/status - Проверить статус\n";
    $message .= "/support - Связаться с поддержкой\n\n";
    $message .= "*Технические требования:*\n";
    $message .= "• Windows 10/11, macOS, Linux\n";
    $message .= "• Интернет соединение\n";
    $message .= "• Python 3.8+ (если запускаете из исходников)\n";
    
    sendMessage($chat_id, $message);
}

/**
 * /support - Поддержка
 */
function commandSupport($chat_id) {
    $message = "🆘 *Поддержка*\n\n";
    $message .= "По всем вопросам:\n";
    $message .= "📧 Email: support@tamada-games.lol\n";
    $message .= "💬 Telegram: @tamada_support\n\n";
    $message .= "⏰ Время работы: 9:00 - 21:00 (GMT+6)\n\n";
    $message .= "Опишите вашу проблему, и мы поможем!";
    
    sendMessage($chat_id, $message);
}

/**
 * Обработка текста (не команды)
 */
function handleText($text, $chat_id, $user, $db) {
    // Можно добавить логику обработки промокодов и т.д.
    sendMessage($chat_id, "Используйте команды для управления: /help");
}

// ================================================
// ОБРАБОТКА CALLBACK КНОПОК
// ================================================

/**
 * Обработка нажатий на кнопки
 */
function handleCallback($data, $chat_id, $callback_id, $db) {
    global $PRICES;
    
    // Ответ на callback (убирает "часики" на кнопке)
    answerCallback($callback_id);
    
    $parts = explode(':', $data);
    $action = $parts[0];
    
    if ($action === 'buy') {
        $hours = (int)$parts[1];
        $price = $PRICES[$hours] ?? 0;
        
        if ($price <= 0) {
            sendMessage($chat_id, "❌ Неверный тариф");
            return;
        }
        
        $user = $db->getUserByChatId($chat_id);
        
        // Создаем платеж
        $payment_id = $db->createPayment($user['id'], $price, $hours, 'kaspi');
        
        // Генерируем Kaspi QR (здесь нужна интеграция с Kaspi API)
        // Для примера просто показываем инструкцию
        
        $message = "💳 *Оплата {$hours}ч - {$price}₸*\n\n";
        $message .= "📱 *Инструкция:*\n";
        $message .= "1. Откройте Kaspi.kz\n";
        $message .= "2. Перейдите в 'Платежи'\n";
        $message .= "3. Выберите 'По QR коду'\n";
        $message .= "4. Отсканируйте QR код ниже\n";
        $message .= "5. Подтвердите оплату\n\n";
        $message .= "💰 Сумма: *{$price} ₸*\n";
        $message .= "🆔 ID платежа: #{$payment_id}\n\n";
        $message .= "После оплаты код активации придет автоматически!\n\n";
        $message .= "⚠️ Если оплата не прошла, напишите /support";
        
        sendMessage($chat_id, $message);
        
        // TODO: Здесь должна быть генерация реального QR кода Kaspi
        // И отправка изображения через sendPhoto
        
        // Логируем
        $db->logActivity($user['id'], 'payment_initiated', [
            'payment_id' => $payment_id,
            'hours' => $hours,
            'amount' => $price
        ]);
    }
}

// ================================================
// TELEGRAM API ФУНКЦИИ
// ================================================

/**
 * Отправка сообщения
 */
function sendMessage($chat_id, $text, $keyboard = null) {
    $url = "https://api.telegram.org/bot" . MAIN_BOT_TOKEN . "/sendMessage";
    
    $payload = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'Markdown'
    ];
    
    if ($keyboard) {
        $payload['reply_markup'] = json_encode($keyboard);
    }
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 5
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return $response;
}

/**
 * Ответ на callback query
 */
function answerCallback($callback_id, $text = null) {
    $url = "https://api.telegram.org/bot" . MAIN_BOT_TOKEN . "/answerCallbackQuery";
    
    $payload = ['callback_query_id' => $callback_id];
    if ($text) {
        $payload['text'] = $text;
    }
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 3
    ]);
    
    curl_exec($ch);
    curl_close($ch);
}

/**
 * Склонение слов
 */
function declension($number, $forms) {
    $cases = [2, 0, 1, 1, 1, 2];
    return $forms[($number % 100 > 4 && $number % 100 < 20) ? 2 : $cases[min($number % 10, 5)]];
}