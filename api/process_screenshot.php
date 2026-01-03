<?php
/**
 * QuickVision Screenshot Processor
 * Принимает скриншот от клиента, отправляет в Groq, возвращает ответ в Telegram
 */

define('API_ACCESS', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db_connect.php';

// Только POST запросы
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

// Логирование запроса
log_message('Screenshot request received', 'info', [
    'ip' => get_client_ip(),
    'content_length' => $_SERVER['CONTENT_LENGTH'] ?? 0
]);

// Получаем данные
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    log_message('Invalid JSON', 'error');
    json_error('Invalid JSON data');
}

// Валидация обязательных полей
if (empty($data['activation_code'])) {
    json_error('Activation code is required');
}

if (empty($data['screenshot'])) {
    json_error('Screenshot data is required');
}

$activation_code = $data['activation_code'];
$screenshot_base64 = $data['screenshot'];

// Проверяем код активации
$activation = $db->checkActivation($activation_code);

if (!$activation) {
    log_message('Invalid activation code', 'warning', ['code' => $activation_code]);
    json_error('Invalid activation code', 401);
}

$user_id = $activation['user_id'];
$chat_id = $activation['telegram_chat_id'];

// Логируем активность
$db->logActivity($user_id, 'screenshot_request', [
    'activation_code' => $activation_code
]);

// Проверяем статус пользователя
if ($activation['status'] === 'blocked') {
    log_message('User blocked', 'warning', ['user_id' => $user_id]);
    json_error('Your account has been blocked. Contact support.', 403);
}

// Проверяем подписку
if (!$db->isSubscriptionActive($user_id)) {
    log_message('Subscription expired', 'info', ['user_id' => $user_id]);
    
    // Отправляем уведомление в Telegram
    sendTelegramMessage(
        $chat_id,
        "⚠️ *Ваша подписка истекла*\n\nДля продолжения работы продлите подписку: /buy",
        SENDER_BOT_TOKEN
    );
    
    json_error('Subscription expired. Please renew.', 402);
}

// Rate limiting
if (!check_rate_limit($user_id, MAX_REQUESTS_PER_MINUTE)) {
    log_message('Rate limit exceeded', 'warning', ['user_id' => $user_id]);
    json_error('Too many requests. Please wait.', 429);
}

// Декодируем base64 (если нужно убрать префикс data:image)
if (strpos($screenshot_base64, 'data:image') === 0) {
    $screenshot_base64 = preg_replace('/^data:image\/\w+;base64,/', '', $screenshot_base64);
}

// Валидация base64
if (!base64_decode($screenshot_base64, true)) {
    json_error('Invalid base64 screenshot data');
}

// Подсчет размера
$file_size = strlen(base64_decode($screenshot_base64));
$file_hash = hash('sha256', base64_decode($screenshot_base64));

log_message('Processing screenshot', 'info', [
    'user_id' => $user_id,
    'file_size' => $file_size,
    'file_hash' => substr($file_hash, 0, 16)
]);

// Вызов Groq API
$start_time = microtime(true);

try {
    $groq_response = callGroqVisionAPI($screenshot_base64);
    $response_time_ms = round((microtime(true) - $start_time) * 1000);
    
    log_message('Groq API response received', 'info', [
        'user_id' => $user_id,
        'response_time_ms' => $response_time_ms,
        'response_length' => strlen($groq_response)
    ]);
    
} catch (Exception $e) {
    log_message('Groq API error', 'error', [
        'user_id' => $user_id,
        'error' => $e->getMessage()
    ]);
    
    // Сохраняем ошибку в БД
    $db->saveScreenshot([
        'user_id' => $user_id,
        'activation_code' => $activation_code,
        'file_hash' => $file_hash,
        'file_size' => $file_size,
        'groq_prompt' => GROQ_PROMPT,
        'success' => 0,
        'error_message' => $e->getMessage()
    ]);
    
    json_error('AI processing failed: ' . $e->getMessage(), 500);
}

// Извлекаем компактные ответы
$extracted_answer = extractCompactAnswer($groq_response);

log_message('Answer extracted', 'info', [
    'user_id' => $user_id,
    'extracted' => $extracted_answer
]);

// Сохраняем в БД
$db->saveScreenshot([
    'user_id' => $user_id,
    'activation_code' => $activation_code,
    'file_hash' => $file_hash,
    'file_size' => $file_size,
    'groq_prompt' => GROQ_PROMPT,
    'groq_response' => $groq_response,
    'extracted_answer' => $extracted_answer,
    'response_time_ms' => $response_time_ms,
    'success' => 1
]);

// Отправляем в Telegram
$telegram_sent = sendTelegramMessage(
    $chat_id,
    "✅ *Ответы:*\n\n`{$extracted_answer}`",
    SENDER_BOT_TOKEN
);

if (!$telegram_sent) {
    log_message('Telegram send failed', 'error', [
        'user_id' => $user_id,
        'chat_id' => $chat_id
    ]);
}

// Успешный ответ
json_success([
    'answer' => $extracted_answer,
    'full_response' => $groq_response,
    'response_time_ms' => $response_time_ms,
    'telegram_sent' => $telegram_sent
]);

// ================================================
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
// ================================================

/**
 * Вызов Groq Vision API
 */
function callGroqVisionAPI($base64_image) {
    $data_url = "data:image/png;base64,{$base64_image}";
    
    $payload = [
        'model' => GROQ_MODEL,
        'input' => [
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'input_text',
                        'text' => GROQ_PROMPT
                    ],
                    [
                        'type' => 'input_image',
                        'detail' => 'auto',
                        'image_url' => $data_url
                    ]
                ]
            ]
        ]
    ];
    
    $ch = curl_init(GROQ_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . GROQ_API_KEY,
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => GROQ_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 10
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    if ($curl_error) {
        throw new Exception("Network error: {$curl_error}");
    }
    
    if ($http_code !== 200) {
        throw new Exception("Groq API error: HTTP {$http_code} - {$response}");
    }
    
    $json = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON response from Groq");
    }
    
    // Извлекаем текст из ответа Groq
    $result = null;
    
    // Формат: output array с message объектами
    if (isset($json['output']) && is_array($json['output'])) {
        foreach ($json['output'] as $output_item) {
            if (isset($output_item['type']) && $output_item['type'] === 'message') {
                $content = $output_item['content'] ?? [];
                foreach ($content as $content_item) {
                    if (isset($content_item['type']) && $content_item['type'] === 'output_text') {
                        $result = $content_item['text'] ?? '';
                        break 2;
                    }
                }
            }
        }
    }
    
    // Запасной вариант
    if (!$result && isset($json['choices'][0]['message']['content'])) {
        $result = $json['choices'][0]['message']['content'];
    }
    
    if (!$result) {
        throw new Exception("No text content in Groq response");
    }
    
    return trim($result);
}

/**
 * Извлечение компактного ответа
 */
function extractCompactAnswer($text) {
    // Паттерн 1: 1) A 2) B 3) C
    preg_match_all('/(\d{1,2})\s*[\)\.\-:]\s*([A-D])\b/i', $text, $matches);
    
    if (!empty($matches[1]) && !empty($matches[2])) {
        $answers = [];
        $seen = [];
        
        foreach ($matches[1] as $idx => $qnum) {
            if (!in_array($qnum, $seen)) {
                $answers[] = "{$qnum}:{$matches[2][$idx]}";
                $seen[] = $qnum;
            }
        }
        
        if (!empty($answers)) {
            return implode(' ', array_slice($answers, 0, 15));
        }
    }
    
    // Паттерн 2: Answer: A, Answer: B
    preg_match_all('/answer\s*[:\-]?\s*([A-D])\b/i', $text, $matches);
    
    if (!empty($matches[1])) {
        $answers = array_unique($matches[1]);
        $formatted = [];
        foreach (array_slice($answers, 0, 15) as $idx => $ans) {
            $formatted[] = ($idx + 1) . ":" . strtoupper($ans);
        }
        return implode(' ', $formatted);
    }
    
    // Паттерн 3: Просто буквы A) B) C)
    preg_match_all('/\b([A-D])\)/i', $text, $matches);
    
    if (!empty($matches[1])) {
        $answers = array_values(array_unique($matches[1]));
        $formatted = [];
        foreach (array_slice($answers, 0, 15) as $idx => $ans) {
            $formatted[] = ($idx + 1) . ":" . strtoupper($ans);
        }
        return implode(' ', $formatted);
    }
    
    // Если ничего не нашли, возвращаем первые 200 символов
    return mb_substr($text, 0, 200) . (mb_strlen($text) > 200 ? '...' : '');
}

/**
 * Отправка сообщения в Telegram
 */
function sendTelegramMessage($chat_id, $text, $bot_token = null) {
    if (!$bot_token) {
        $bot_token = SENDER_BOT_TOKEN;
    }
    
    $url = "https://api.telegram.org/bot{$bot_token}/sendMessage";
    
    $payload = [
        'chat_id' => $chat_id,
        'text' => mb_substr($text, 0, 4096), // Telegram лимит
        'parse_mode' => 'Markdown'
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
    
    log_message('Telegram API error', 'error', [
        'http_code' => $http_code,
        'response' => $response
    ]);
    
    return false;
}