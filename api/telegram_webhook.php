<?php
/**
 * QuickVision Telegram Bot - Full Implementation
 * Регистрация, оплата через Kaspi QR, выдача кодов
 */

define('API_ACCESS', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/functions.php';

// Получаем обновление от Telegram
$content = file_get_contents('php://input');
$update = json_decode($content, true);

if (!$update) {
    exit('No update');
}

log_message('Telegram webhook received', 'info', [
    'update_id' => $update['update_id'] ?? 'unknown'
]);

// ==============================================
// ОБРАБОТКА СООБЩЕНИЙ
// ==============================================
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
        handleText($text, $chat_id, $user, $db);
    }
}

// ==============================================
// ОБРАБОТКА CALLBACK (КНОПКИ)
// ==============================================
if (isset($update['callback_query'])) {
    $callback = $update['callback_query'];
    $chat_id = $callback['message']['chat']['id'];
    $data = $callback['data'];
    $callback_id = $callback['id'];
    
    handleCallback($data, $chat_id, $callback_id, $db);
}

exit('OK');

// ==============================================
// ФУНКЦИИ КОМАНД
// ==============================================

/**
 * Обработка команд бота
 */
function handleCommand($text, $chat_id, $user, $db) {
    $command = strtolower(trim(explode(' ', $text)[0]));
    
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
            
        case '/code':
        case '/mycode':
            commandGetCode($chat_id, $user, $db);
            break;
            
        case '/help':
            commandHelp($chat_id);
            break;
            
        case '/support':
            commandSupport($chat_id);
            break;
            
        default:
            sendMessage($chat_id, "❓ Неизвестная команда\n\nИспользуйте /help для списка команд");
    }
}

/**
 * /start - Приветствие
 */
function commandStart($chat_id, $user) {
    $name = $user['first_name'] ?: 'пользователь';
    
    $message = "👋 Привет, *{$name}*!\n\n";
    $message .= "🚀 *QuickVision* - твой AI ассистент для тестов!\n\n";
    $message .= "📸 *Как это работает:*\n";
    $message .= "1️⃣ Купи подписку — /buy\n";
    $message .= "2️⃣ Получи код активации\n";
    $message .= "3️⃣ Скачай приложение\n";
    $message .= "4️⃣ Запусти и введи код\n";
    $message .= "5️⃣ Нажимай Ctrl+Shift+X и получай ответы!\n\n";
    $message .= "💡 *Команды:*\n";
    $message .= "/buy — Купить подписку\n";
    $message .= "/status — Мой статус\n";
    $message .= "/code — Показать код\n";
    $message .= "/help — Помощь\n";
    $message .= "/support — Поддержка\n";
    
    sendMessage($chat_id, $message);
}

/**
 * /buy - Покупка подписки
 */
function commandBuy($chat_id, $user) {
    global $PRICES;
    
    $message = "💳 *Выбери тариф:*\n\n";
    
    $keyboard = [
        'inline_keyboard' => []
    ];
    
    foreach ($PRICES as $hours => $price) {
        $hours_text = declension($hours, ['час', 'часа', 'часов']);
        $message .= "⏰ *{$hours} {$hours_text}* — {$price} ₸\n";
        
        $keyboard['inline_keyboard'][] = [
            [
                'text' => "🕐 {$hours}ч — {$price}₸",
                'callback_data' => "buy:{$hours}"
            ]
        ];
    }
    
    $message .= "\n💵 Оплата через Kaspi QR\n";
    $message .= "⚡️ Код активации приходит сразу после оплаты";
    
    sendMessage($chat_id, $message, $keyboard);
}

/**
 * /status - Статус подписки
 */
function commandStatus($chat_id, $user, $db) {
    $is_active = $db->isSubscriptionActive($user['id']);
    $stats = $db->getUserStats($user['id']);
    
    $message = "📊 *Твой статус:*\n\n";
    $message .= "👤 ID: `{$user['id']}`\n";
    $message .= "📱 Username: " . ($user['username'] ? "@{$user['username']}" : '_не указан_') . "\n\n";
    
    if ($is_active) {
        $expires = new DateTime($user['expires_at']);
        $now = new DateTime();
        $diff = $now->diff($expires);
        
        $message .= "✅ *Подписка активна*\n";
        $message .= "📅 До: `" . $expires->format('d.m.Y H:i') . "`\n";
        
        $time_parts = [];
        if ($diff->d > 0) {
            $time_parts[] = $diff->d . ' ' . declension($diff->d, ['день', 'дня', 'дней']);
        }
        if ($diff->h > 0) {
            $time_parts[] = $diff->h . ' ' . declension($diff->h, ['час', 'часа', 'часов']);
        }
        if (empty($time_parts) && $diff->i > 0) {
            $time_parts[] = $diff->i . ' ' . declension($diff->i, ['минута', 'минуты', 'минут']);
        }
        
        $message .= "⏳ Осталось: *" . implode(' ', $time_parts) . "*\n\n";
    } else {
        $message .= "❌ *Подписка не активна*\n\n";
    }
    
    $message .= "📈 *Статистика:*\n";
    $message .= "📸 Скриншотов: " . ($stats['total_screenshots'] ?? 0) . "\n";
    $message .= "💰 Платежей: " . ($stats['total_payments'] ?? 0) . "\n";
    $message .= "⏱ Куплено часов: " . ($user['hours_purchased'] ?? 0) . "\n";
    
    if (!$is_active) {
        $message .= "\n➡️ Продлить: /buy";
    } else {
        $message .= "\n📲 Получить код: /code";
    }
    
    sendMessage($chat_id, $message);
}

/**
 * /code - Показать код активации
 */
function commandGetCode($chat_id, $user, $db) {
    global $pdo;
    
    // Ищем неиспользованный код
    $stmt = $pdo->prepare("
        SELECT code, created_at 
        FROM activations 
        WHERE user_id = ? AND is_used = 0 
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $stmt->execute([$user['id']]);
    $activation = $stmt->fetch();
    
    if ($activation) {
        $code = $activation['code'];
        $created = date('d.m.Y H:i', strtotime($activation['created_at']));
        
        $message = "🔑 *Твой код активации:*\n\n";
        $message .= "`{$code}`\n\n";
        $message .= "📅 Создан: {$created}\n\n";
        $message .= "📥 [Скачать приложение](" . SITE_URL . "/download)\n\n";
        $message .= "*Как использовать:*\n";
        $message .= "1. Скачай приложение\n";
        $message .= "2. Запусти и введи этот код\n";
        $message .= "3. Нажимай Ctrl+Shift+X\n\n";
        $message .= "⚠️ Не передавай код другим!";
        
        sendMessage($chat_id, $message);
    } else {
        // Нет кода - создаем новый
        try {
            $code = generateUniqueActivationCode($pdo);
            $db->createActivation($user['id'], $code);
            
            $message = "🔑 *Твой новый код активации:*\n\n";
            $message .= "`{$code}`\n\n";
            $message .= "📥 [Скачать приложение](" . SITE_URL . "/download)\n\n";
            $message .= "Следуй инструкциям для активации!";
            
            sendMessage($chat_id, $message);
            
        } catch (Exception $e) {
            sendMessage($chat_id, "❌ Ошибка создания кода. Попробуй позже или пиши /support");
            log_message('Code generation failed', 'error', [
                'user_id' => $user['id'],
                'error' => $e->getMessage()
            ]);
        }
    }
}

/**
 * /help - Справка
 */
function commandHelp($chat_id) {
    $message = "ℹ️ *Справка QuickVision*\n\n";
    $message .= "*Как пользоваться:*\n";
    $message .= "1. Купи подписку через /buy\n";
    $message .= "2. Оплати через Kaspi QR\n";
    $message .= "3. Получи код активации\n";
    $message .= "4. Скачай и запусти приложение\n";
    $message .= "5. Введи код активации\n";
    $message .= "6. Нажимай Ctrl+Shift+X на тесте\n";
    $message .= "7. Получай ответы в этом чате!\n\n";
    
    $message .= "*Команды:*\n";
    $message .= "/buy — Купить подписку\n";
    $message .= "/status — Проверить статус\n";
    $message .= "/code — Показать код\n";
    $message .= "/support — Связаться с поддержкой\n\n";
    
    $message .= "*Системные требования:*\n";
    $message .= "• Windows 10/11, macOS, Linux\n";
    $message .= "• Интернет соединение\n";
    $message .= "• Python 3.8+ (автоматически в EXE)\n\n";
    
    $message .= "*Вопросы?*\n";
    $message .= "Пиши /support";
    
    sendMessage($chat_id, $message);
}

/**
 * /support - Поддержка
 */
function commandSupport($chat_id) {
    $message = "🆘 *Поддержка QuickVision*\n\n";
    $message .= "📧 Email: support@tamada-games.lol\n";
    $message .= "💬 Telegram: @tamada_support\n\n";
    $message .= "⏰ Работаем: 9:00 - 21:00 (GMT+6)\n\n";
    $message .= "Опиши свою проблему и мы поможем!";
    
    sendMessage($chat_id, $message);
}

/**
 * Обработка обычного текста
 */
function handleText($text, $chat_id, $user, $db) {
    // Можно добавить обработку промокодов
    sendMessage($chat_id, "Используй команды для управления.\nСписок команд: /help");
}

// ==============================================
// ОБРАБОТКА CALLBACK (КНОПКИ)
// ==============================================

/**
 * Обработка нажатий на inline кнопки
 */
function handleCallback($data, $chat_id, $callback_id, $db) {
    global $PRICES, $pdo;
    
    // Ответ на callback
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
        
        // Создаем платеж в БД
        $payment_id = $db->createPayment($user['id'], $price, $hours, 'kaspi');
        
        // Генерируем Kaspi QR
        $kaspi_qr_data = generateKaspiQR($payment_id, $price, $user);
        
        $hours_text = declension($hours, ['час', 'часа', 'часов']);
        
        $message = "💳 *Оплата: {$hours} {$hours_text}*\n";
        $message .= "💰 Сумма: *{$price} ₸*\n\n";
        $message .= "📱 *Как оплатить:*\n";
        $message .= "1. Открой Kaspi.kz\n";
        $message .= "2. Выбери 'Платежи'\n";
        $message .= "3. Нажми 'По QR-коду'\n";
        $message .= "4. Отсканируй QR ниже\n";
        $message .= "5. Подтверди оплату\n\n";
        $message .= "🆔 Платёж: `#{$payment_id}`\n\n";
        $message .= "⚡️ После оплаты код активации придёт автоматически!\n\n";
        $message .= "❓ Проблемы с оплатой? /support";
        
        // Отправляем сообщение
        sendMessage($chat_id, $message);
        
        // Отправляем QR код как фото
        if ($kaspi_qr_data['qr_image_path']) {
            sendPhoto($chat_id, $kaspi_qr_data['qr_image_path'], "Отсканируй этот QR в Kaspi.kz");
        }
        
        // Логируем
        $db->logActivity($user['id'], 'payment_initiated', [
            'payment_id' => $payment_id,
            'hours' => $hours,
            'amount' => $price
        ]);
    }
}

// ==============================================
// KASPI QR ГЕНЕРАЦИЯ
// ==============================================

/**
 * Генерация Kaspi QR кода
 * 
 * ВАЖНО: Это упрощенная версия!
 * Для реальной интеграции нужен Kaspi API
 */
function generateKaspiQR($payment_id, $amount, $user) {
    // ========================================
    // ЗАМЕНИТЕ ЭТО НА РЕАЛЬНЫЙ KASPI API
    // ========================================
    
    // Временная реализация - генерация простого QR
    $qr_data = [
        'merchant_id' => 'YOUR_KASPI_MERCHANT_ID', // ← ЗАМЕНИТЬ
        'amount' => $amount,
        'currency' => 'KZT',
        'order_id' => $payment_id,
        'description' => "QuickVision подписка",
        'callback_url' => SITE_URL . '/api/payment_callback.php'
    ];
    
    // Генерируем QR код (используйте библиотеку или API)
    $qr_image_path = generateQRCodeImage($qr_data, $payment_id);
    
    return [
        'qr_data' => $qr_data,
        'qr_image_path' => $qr_image_path
    ];
}

/**
 * Генерация изображения QR кода
 */
function generateQRCodeImage($data, $payment_id) {
    // Используйте библиотеку для генерации QR
    // Например: phpqrcode или API вроде goqr.me
    
    $qr_text = json_encode($data);
    
    // Пример с использованием внешнего API
    $qr_url = "https://api.qrserver.com/v1/create-qr-code/?" . http_build_query([
        'size' => '300x300',
        'data' => $qr_text
    ]);
    
    // Сохраняем QR во временную папку
    $temp_path = TEMP_PATH . "/qr_{$payment_id}.png";
    
    try {
        $qr_image = file_get_contents($qr_url);
        if ($qr_image) {
            file_put_contents($temp_path, $qr_image);
            return $temp_path;
        }
    } catch (Exception $e) {
        log_message('QR generation failed', 'error', [
            'payment_id' => $payment_id,
            'error' => $e->getMessage()
        ]);
    }
    
    return null;
}

// ==============================================
// TELEGRAM API ФУНКЦИИ
// ==============================================

/**
 * Отправка сообщения
 */
function sendMessage($chat_id, $text, $keyboard = null) {
    $url = "https://api.telegram.org/bot" . MAIN_BOT_TOKEN . "/sendMessage";
    
    $payload = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'Markdown',
        'disable_web_page_preview' => true
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
        CURLOPT_TIMEOUT => 10
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return $response;
}

/**
 * Отправка фото
 */
function sendPhoto($chat_id, $photo_path, $caption = null) {
    $url = "https://api.telegram.org/bot" . MAIN_BOT_TOKEN . "/sendPhoto";
    
    $post_fields = [
        'chat_id' => $chat_id,
        'photo' => new CURLFile($photo_path)
    ];
    
    if ($caption) {
        $post_fields['caption'] = $caption;
    }
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $post_fields,
        CURLOPT_TIMEOUT => 15
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return $response;
}

/**
 * Ответ на callback query
 */
function answerCallback($callback_id, $text = null, $show_alert = false) {
    $url = "https://api.telegram.org/bot" . MAIN_BOT_TOKEN . "/answerCallbackQuery";
    
    $payload = [
        'callback_query_id' => $callback_id,
        'show_alert' => $show_alert
    ];
    
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