<?php
define('API_ACCESS', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db_connect.php';

// Получаем данные от платежной системы
$input = file_get_contents('php://input');
$data = json_decode($input, true);

log_message('Payment callback received', 'info', ['data' => $data]);

// Здесь должна быть реальная обработка callback от Kaspi
// Это заглушка для примера

if (isset($data['payment_id']) && isset($data['status'])) {
    $payment_id = $data['payment_id'];
    $status = $data['status'];
    $transaction_id = $data['transaction_id'] ?? null;
    
    if ($status === 'completed') {
        // Получаем данные платежа
        $stmt = $pdo->prepare("SELECT * FROM payments WHERE id = ?");
        $stmt->execute([$payment_id]);
        $payment = $stmt->fetch();
        
        if ($payment) {
            // Обновляем статус платежа
            $db->updatePaymentStatus($payment_id, 'completed', $transaction_id);
            
            // Продлеваем подписку
            $db->extendSubscription($payment['user_id'], $payment['hours']);
            
            // Генерируем код активации
            $activation_code = generate_activation_code();
            $db->createActivation($payment['user_id'], $activation_code);
            
            // Получаем пользователя
            $user = $db->getUserById($payment['user_id']);
            
            // Отправляем в Telegram
            $message = "✅ *Оплата успешна!*\n\n";
            $message .= "💰 Сумма: {$payment['amount']}₸\n";
            $message .= "⏰ Часов: {$payment['hours']}\n\n";
            $message .= "🔑 *Код активации:*\n`{$activation_code}`\n\n";
            $message .= "📥 [Скачать приложение](" . SITE_URL . "/download)\n\n";
            $message .= "Инструкция:\n";
            $message .= "1. Скачайте приложение\n";
            $message .= "2. Запустите и введите код\n";
            $message .= "3. Нажимайте Ctrl+Shift+X\n";
            
            sendTelegramMessage($user['telegram_chat_id'], $message, MAIN_BOT_TOKEN);
            
            log_message('Payment processed', 'info', [
                'payment_id' => $payment_id,
                'user_id' => $payment['user_id']
            ]);
        }
    }
}

function sendTelegramMessage($chat_id, $text, $bot_token) {
    $url = "https://api.telegram.org/bot{$bot_token}/sendMessage";
    $payload = ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'Markdown'];
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

echo json_encode(['success' => true]);
