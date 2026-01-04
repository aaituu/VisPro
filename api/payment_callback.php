<?php
/**
 * QuickVision Payment Callback
 * Обработка уведомлений от Kaspi о статусе оплаты
 */

define('API_ACCESS', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/functions.php';

// Получаем данные от платежной системы
$content = file_get_contents('php://input');
$data = json_decode($content, true);

log_message('Payment callback received', 'info', [
    'raw_data' => $content,
    'parsed_data' => $data,
    'ip' => get_client_ip()
]);

// ==============================================
// ПРОВЕРКА ПОДПИСИ (БЕЗОПАСНОСТЬ)
// ==============================================

/**
 * ВАЖНО: Добавьте проверку подписи от Kaspi
 * Это защитит от поддельных запросов
 */
function verifyKaspiSignature($data, $signature) {
    // Получите secret key из Kaspi панели
    $secret_key = 'ВАШ_KASPI_SECRET_KEY'; // ← ИЗМЕНИТЬ
    
    // Формируем строку для подписи (зависит от Kaspi API)
    $sign_string = implode('|', [
        $data['order_id'] ?? '',
        $data['amount'] ?? '',
        $data['status'] ?? '',
        $secret_key
    ]);
    
    // Вычисляем подпись
    $calculated_signature = hash('sha256', $sign_string);
    
    return hash_equals($calculated_signature, $signature);
}

// Проверяем подпись если есть
if (isset($data['signature'])) {
    if (!verifyKaspiSignature($data, $data['signature'])) {
        log_message('Invalid payment signature', 'error', [
            'data' => $data
        ]);
        
        http_response_code(403);
        echo json_encode(['error' => 'Invalid signature']);
        exit;
    }
}

// ==============================================
// ОБРАБОТКА ПЛАТЕЖА
// ==============================================

// Извлекаем данные (формат зависит от Kaspi API)
$payment_id = $data['order_id'] ?? $data['payment_id'] ?? null;
$status = strtolower($data['status'] ?? '');
$transaction_id = $data['transaction_id'] ?? $data['txn_id'] ?? null;
$amount = $data['amount'] ?? null;

if (!$payment_id) {
    log_message('Missing payment_id in callback', 'error', ['data' => $data]);
    http_response_code(400);
    echo json_encode(['error' => 'Missing payment_id']);
    exit;
}

// Получаем платеж из БД
$stmt = $pdo->prepare("SELECT * FROM payments WHERE id = ?");
$stmt->execute([$payment_id]);
$payment = $stmt->fetch();

if (!$payment) {
    log_message('Payment not found', 'error', [
        'payment_id' => $payment_id
    ]);
    
    http_response_code(404);
    echo json_encode(['error' => 'Payment not found']);
    exit;
}

// Проверяем сумму
if ($amount && abs($payment['amount'] - $amount) > 0.01) {
    log_message('Amount mismatch', 'error', [
        'payment_id' => $payment_id,
        'expected' => $payment['amount'],
        'received' => $amount
    ]);
    
    http_response_code(400);
    echo json_encode(['error' => 'Amount mismatch']);
    exit;
}

// ==============================================
// ОБРАБОТКА СТАТУСА
// ==============================================

switch ($status) {
    // -----------------------------------------
    // УСПЕШНАЯ ОПЛАТА
    // -----------------------------------------
    case 'completed':
    case 'success':
    case 'paid':
        
        // Проверяем, не был ли уже обработан
        if ($payment['status'] === 'completed') {
            log_message('Payment already processed', 'info', [
                'payment_id' => $payment_id
            ]);
            
            http_response_code(200);
            echo json_encode(['status' => 'already_processed']);
            exit;
        }
        
        try {
            // Начинаем транзакцию
            $pdo->beginTransaction();
            
            // 1. Обновляем статус платежа
            $db->updatePaymentStatus($payment_id, 'completed', $transaction_id);
            
            // 2. Продлеваем подписку
            $db->extendSubscription($payment['user_id'], $payment['hours']);
            
            // 3. Генерируем код активации
            $activation_code = generateUniqueActivationCode($pdo);
            $db->createActivation($payment['user_id'], $activation_code);
            
            // Коммитим транзакцию
            $pdo->commit();
            
            // 4. Получаем пользователя
            $user = $db->getUserById($payment['user_id']);
            
            if (!$user) {
                throw new Exception('User not found');
            }
            
            // 5. Отправляем в Telegram
            $hours_text = declension($payment['hours'], ['час', 'часа', 'часов']);
            $expires = new DateTime($user['expires_at']);
            
            $message = "✅ *Оплата успешна!*\n\n";
            $message .= "💰 Сумма: *{$payment['amount']} ₸*\n";
            $message .= "⏰ Часов: *{$payment['hours']} {$hours_text}*\n";
            $message .= "📅 Активна до: *" . $expires->format('d.m.Y H:i') . "*\n\n";
            $message .= "🔑 *Твой код активации:*\n";
            $message .= "`{$activation_code}`\n\n";
            $message .= "📥 [Скачать приложение](" . SITE_URL . "/download)\n\n";
            $message .= "*Инструкция:*\n";
            $message .= "1. Скачай приложение по ссылке выше\n";
            $message .= "2. Запусти и введи код активации\n";
            $message .= "3. Нажимай Ctrl+Shift+X на любом тесте\n";
            $message .= "4. Получай ответы в этом чате!\n\n";
            $message .= "⚠️ Не передавай код другим людям!\n\n";
            $message .= "Спасибо что выбрал QuickVision! 🚀";
            
            $telegram_sent = sendTelegramMessage(
                $user['telegram_chat_id'],
                $message,
                MAIN_BOT_TOKEN
            );
            
            // 6. Логируем успех
            log_message('Payment processed successfully', 'info', [
                'payment_id' => $payment_id,
                'user_id' => $user['id'],
                'hours' => $payment['hours'],
                'activation_code' => substr($activation_code, 0, 8) . '...',
                'telegram_sent' => $telegram_sent
            ]);
            
            $db->logActivity($payment['user_id'], 'payment_completed', [
                'payment_id' => $payment_id,
                'amount' => $payment['amount'],
                'hours' => $payment['hours'],
                'transaction_id' => $transaction_id,
                'activation_code' => substr($activation_code, 0, 8) . '...'
            ]);
            
            // Успешный ответ
            http_response_code(200);
            echo json_encode([
                'status' => 'success',
                'payment_id' => $payment_id,
                'activation_code' => $activation_code
            ]);
            
        } catch (Exception $e) {
            // Откатываем транзакцию
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            
            log_message('Payment processing failed', 'error', [
                'payment_id' => $payment_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            http_response_code(500);
            echo json_encode([
                'error' => 'Processing failed',
                'message' => $e->getMessage()
            ]);
        }
        
        break;
    
    // -----------------------------------------
    // ОЖИДАНИЕ ОПЛАТЫ
    // -----------------------------------------
    case 'pending':
    case 'processing':
        
        $db->updatePaymentStatus($payment_id, 'pending', $transaction_id);
        
        log_message('Payment pending', 'info', [
            'payment_id' => $payment_id
        ]);
        
        http_response_code(200);
        echo json_encode(['status' => 'pending']);
        
        break;
    
    // -----------------------------------------
    // ОТМЕНА/ОШИБКА
    // -----------------------------------------
    case 'failed':
    case 'cancelled':
    case 'error':
        
        $db->updatePaymentStatus($payment_id, 'failed', $transaction_id);
        
        $user = $db->getUserById($payment['user_id']);
        
        if ($user && $user['telegram_chat_id']) {
            $message = "❌ *Оплата не прошла*\n\n";
            $message .= "🆔 Платёж: #{$payment_id}\n";
            $message .= "💰 Сумма: {$payment['amount']} ₸\n\n";
            $message .= "Попробуй ещё раз: /buy\n\n";
            $message .= "Проблемы? Пиши /support";
            
            sendTelegramMessage(
                $user['telegram_chat_id'],
                $message,
                MAIN_BOT_TOKEN
            );
        }
        
        log_message('Payment failed', 'warning', [
            'payment_id' => $payment_id,
            'status' => $status
        ]);
        
        http_response_code(200);
        echo json_encode(['status' => 'failed']);
        
        break;
    
    // -----------------------------------------
    // ВОЗВРАТ
    // -----------------------------------------
    case 'refunded':
        
        $db->updatePaymentStatus($payment_id, 'refunded', $transaction_id);
        
        // Блокируем пользователя или удаляем подписку
        // (зависит от вашей бизнес-логики)
        
        log_message('Payment refunded', 'warning', [
            'payment_id' => $payment_id
        ]);
        
        http_response_code(200);
        echo json_encode(['status' => 'refunded']);
        
        break;
    
    // -----------------------------------------
    // НЕИЗВЕСТНЫЙ СТАТУС
    // -----------------------------------------
    default:
        
        log_message('Unknown payment status', 'warning', [
            'payment_id' => $payment_id,
            'status' => $status,
            'data' => $data
        ]);
        
        http_response_code(400);
        echo json_encode([
            'error' => 'Unknown status',
            'status' => $status
        ]);
}

// ==============================================
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
// ==============================================

/**
 * Отправка сообщения в Telegram
 */
function sendTelegramMessage($chat_id, $text, $bot_token) {
    $url = "https://api.telegram.org/bot{$bot_token}/sendMessage";
    
    $payload = [
        'chat_id' => $chat_id,
        'text' => mb_substr($text, 0, 4096),
        'parse_mode' => 'Markdown',
        'disable_web_page_preview' => true
    ];
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 10
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 200) {
        $json = json_decode($response, true);
        return $json['ok'] ?? false;
    }
    
    return false;
}