<?php
/**
 * QuickVision Common Functions
 * Общие функции используемые в разных частях API
 */

if (!defined('API_ACCESS')) {
    die('Direct access not permitted');
}

/**
 * Отправка сообщения в Telegram
 * 
 * @param int $chat_id ID чата
 * @param string $text Текст сообщения
 * @param string $bot_token Токен бота (по умолчанию SENDER_BOT_TOKEN)
 * @param array $keyboard Inline клавиатура (опционально)
 * @param string $parse_mode Режим парсинга (Markdown, HTML)
 * @return bool Успешность отправки
 */
function sendTelegramMessage($chat_id, $text, $bot_token = null, $keyboard = null, $parse_mode = 'Markdown') {
    if (!$bot_token) {
        $bot_token = SENDER_BOT_TOKEN;
    }
    
    $url = "https://api.telegram.org/bot{$bot_token}/sendMessage";
    
    $payload = [
        'chat_id' => $chat_id,
        'text' => mb_substr($text, 0, 4096), // Telegram лимит
        'parse_mode' => $parse_mode
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
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    if ($curl_error) {
        log_message('Telegram send error', 'error', [
            'error' => $curl_error,
            'chat_id' => $chat_id
        ]);
        return false;
    }
    
    if ($http_code === 200) {
        $json = json_decode($response, true);
        return $json['ok'] ?? false;
    }
    
    log_message('Telegram API error', 'error', [
        'http_code' => $http_code,
        'response' => $response,
        'chat_id' => $chat_id
    ]);
    
    return false;
}

/**
 * Отправка фото в Telegram
 * 
 * @param int $chat_id ID чата
 * @param string $photo URL или file_id фото
 * @param string $caption Подпись к фото
 * @param string $bot_token Токен бота
 * @return bool
 */
function sendTelegramPhoto($chat_id, $photo, $caption = null, $bot_token = null) {
    if (!$bot_token) {
        $bot_token = MAIN_BOT_TOKEN;
    }
    
    $url = "https://api.telegram.org/bot{$bot_token}/sendPhoto";
    
    $payload = [
        'chat_id' => $chat_id,
        'photo' => $photo
    ];
    
    if ($caption) {
        $payload['caption'] = $caption;
        $payload['parse_mode'] = 'Markdown';
    }
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 15
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $http_code === 200;
}

/**
 * Генерация уникального кода активации
 * 
 * @param int $length Длина кода (по умолчанию 16)
 * @param bool $formatted Форматировать с дефисами (XXXX-XXXX-XXXX-XXXX)
 * @return string
 */
function generateActivationCode($length = 16, $formatted = true) {
    // Используем только легко различимые символы (без 0, O, I, 1, l)
    $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    $max = strlen($characters) - 1;
    
    for ($i = 0; $i < $length; $i++) {
        $code .= $characters[random_int(0, $max)];
    }
    
    if ($formatted) {
        // Форматируем как XXXX-XXXX-XXXX-XXXX
        return implode('-', str_split($code, 4));
    }
    
    return $code;
}

/**
 * Проверка уникальности кода активации
 * 
 * @param string $code Код для проверки
 * @param PDO $pdo PDO соединение
 * @return bool true если код уникален
 */
function isActivationCodeUnique($code, $pdo) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM activations WHERE code = ?");
    $stmt->execute([$code]);
    $result = $stmt->fetch();
    return $result['count'] == 0;
}

/**
 * Генерация уникального кода активации с проверкой БД
 * 
 * @param PDO $pdo
 * @return string
 */
function generateUniqueActivationCode($pdo) {
    $max_attempts = 10;
    $attempt = 0;
    
    do {
        $code = generateActivationCode();
        $attempt++;
        
        if ($attempt >= $max_attempts) {
            throw new Exception("Failed to generate unique activation code");
        }
    } while (!isActivationCodeUnique($code, $pdo));
    
    return $code;
}

/**
 * Форматирование времени в читаемый вид
 * 
 * @param string $datetime Дата и время
 * @param string $format Формат (по умолчанию 'd.m.Y H:i')
 * @return string
 */
function formatDateTime($datetime, $format = 'd.m.Y H:i') {
    if (!$datetime) {
        return '-';
    }
    
    $timestamp = is_numeric($datetime) ? $datetime : strtotime($datetime);
    return date($format, $timestamp);
}

/**
 * Получение времени до окончания подписки
 * 
 * @param string $expires_at Дата окончания
 * @return string Читаемое время
 */
function getTimeUntilExpiry($expires_at) {
    if (!$expires_at) {
        return 'Не активирована';
    }
    
    $now = new DateTime();
    $expires = new DateTime($expires_at);
    
    if ($expires < $now) {
        return 'Истекла';
    }
    
    $diff = $now->diff($expires);
    
    $parts = [];
    
    if ($diff->d > 0) {
        $parts[] = $diff->d . ' ' . declension($diff->d, ['день', 'дня', 'дней']);
    }
    
    if ($diff->h > 0) {
        $parts[] = $diff->h . ' ' . declension($diff->h, ['час', 'часа', 'часов']);
    }
    
    if (empty($parts) && $diff->i > 0) {
        $parts[] = $diff->i . ' ' . declension($diff->i, ['минута', 'минуты', 'минут']);
    }
    
    return implode(' ', $parts);
}

/**
 * Склонение русских слов по числительным
 * 
 * @param int $number Число
 * @param array $forms Массив форм [1, 2, 5] (час, часа, часов)
 * @return string
 */
function declension($number, $forms) {
    $cases = [2, 0, 1, 1, 1, 2];
    return $forms[($number % 100 > 4 && $number % 100 < 20) ? 2 : $cases[min($number % 10, 5)]];
}

/**
 * Валидация email адреса
 * 
 * @param string $email
 * @return bool
 */
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Очистка и валидация username
 * 
 * @param string $username
 * @return string|null
 */
function sanitizeUsername($username) {
    if (!$username) {
        return null;
    }
    
    // Убираем @ если есть
    $username = ltrim($username, '@');
    
    // Оставляем только буквы, цифры и подчеркивания
    $username = preg_replace('/[^a-zA-Z0-9_]/', '', $username);
    
    return $username ?: null;
}

/**
 * Проверка валидности chat_id Telegram
 * 
 * @param mixed $chat_id
 * @return bool
 */
function isValidChatId($chat_id) {
    return is_numeric($chat_id) && $chat_id > 0;
}

/**
 * Получение информации о размере файла в читаемом формате
 * 
 * @param int $bytes Размер в байтах
 * @param int $precision Точность
 * @return string
 */
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    
    $bytes /= (1 << (10 * $pow));
    
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Извлечение компактных ответов из текста AI
 * 
 * @param string $text Полный ответ от AI
 * @return string Компактный ответ
 */
function extractCompactAnswer($text) {
    // Паттерн 1: Номерованные ответы 1) A 2) B
    preg_match_all('/(\d{1,2})\s*[\)\.\-:]\s*([A-D])\b/i', $text, $matches);
    
    if (!empty($matches[1]) && !empty($matches[2])) {
        $answers = [];
        $seen = [];
        
        foreach ($matches[1] as $idx => $qnum) {
            if (!in_array($qnum, $seen)) {
                $answers[] = "{$qnum}:" . strtoupper($matches[2][$idx]);
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
 * Валидация base64 изображения
 * 
 * @param string $base64 Base64 строка
 * @return bool
 */
function isValidBase64Image($base64) {
    // Убираем префикс data:image если есть
    $base64 = preg_replace('/^data:image\/\w+;base64,/', '', $base64);
    
    // Проверяем валидность base64
    if (!base64_decode($base64, true)) {
        return false;
    }
    
    // Проверяем размер (не более 10MB)
    $size = strlen(base64_decode($base64));
    if ($size > 10 * 1024 * 1024) {
        return false;
    }
    
    return true;
}

/**
 * Получение хэша файла
 * 
 * @param string $data Данные файла
 * @param string $algorithm Алгоритм (sha256, md5)
 * @return string
 */
function getFileHash($data, $algorithm = 'sha256') {
    return hash($algorithm, $data);
}

/**
 * Проверка rate limit для пользователя
 * 
 * @param int $user_id ID пользователя
 * @param int $max_requests Максимум запросов
 * @param int $period_seconds Период в секундах
 * @param PDO $pdo
 * @return bool true если лимит не превышен
 */
function checkUserRateLimit($user_id, $max_requests, $period_seconds, $pdo) {
    $since = date('Y-m-d H:i:s', time() - $period_seconds);
    
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM activity_logs 
        WHERE user_id = ? 
          AND action = 'screenshot_request'
          AND created_at > ?
    ");
    $stmt->execute([$user_id, $since]);
    $result = $stmt->fetch();
    
    return $result['count'] < $max_requests;
}

/**
 * Получение настройки из БД
 * 
 * @param string $key Ключ настройки
 * @param mixed $default Значение по умолчанию
 * @param PDO $pdo
 * @return mixed
 */
function getSetting($key, $default = null, $pdo) {
    $stmt = $pdo->prepare("SELECT value FROM settings WHERE `key` = ? LIMIT 1");
    $stmt->execute([$key]);
    $result = $stmt->fetch();
    
    return $result ? $result['value'] : $default;
}

/**
 * Обновление настройки в БД
 * 
 * @param string $key Ключ
 * @param mixed $value Значение
 * @param PDO $pdo
 * @return bool
 */
function updateSetting($key, $value, $pdo) {
    $stmt = $pdo->prepare("
        INSERT INTO settings (`key`, value) 
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE value = ?
    ");
    
    return $stmt->execute([$key, $value, $value]);
}

/**
 * Отправка уведомления администратору
 * 
 * @param string $message Сообщение
 * @param array $context Контекст (будет добавлен к сообщению)
 * @return bool
 */
function notifyAdmin($message, $context = []) {
    // ID админского чата (можно настроить в settings)
    $admin_chat_id = getSetting('admin_chat_id', null, $GLOBALS['pdo']);
    
    if (!$admin_chat_id) {
        return false;
    }
    
    $full_message = "🔔 *Уведомление администратора*\n\n";
    $full_message .= $message . "\n\n";
    
    if (!empty($context)) {
        $full_message .= "*Детали:*\n";
        foreach ($context as $key => $value) {
            $full_message .= "• {$key}: " . json_encode($value, JSON_UNESCAPED_UNICODE) . "\n";
        }
    }
    
    $full_message .= "\n_Время: " . date('d.m.Y H:i:s') . "_";
    
    return sendTelegramMessage($admin_chat_id, $full_message, MAIN_BOT_TOKEN);
}

/**
 * Безопасное удаление временного файла
 * 
 * @param string $filepath Путь к файлу
 * @return bool
 */
function safeUnlink($filepath) {
    if (file_exists($filepath)) {
        try {
            return unlink($filepath);
        } catch (Exception $e) {
            log_message('File delete error', 'error', [
                'file' => $filepath,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    return true;
}