<?php
/**
 * QuickVision API Configuration
 * Все настройки системы
 */

// Запрет прямого доступа
if (!defined('API_ACCESS')) {
    die('Direct access not permitted');
}

// ================================================
// БАЗА ДАННЫХ
// ================================================
define('DB_HOST', 'localhost');
define('DB_NAME', 'tamadaga_db');
define('DB_USER', 'tamadaga_admin');
define('DB_PASS', 'alibali1234');
define('DB_CHARSET', 'utf8mb4');

// ================================================
// САЙТ И API
// ================================================
define('SITE_URL', 'https://tamada-games.lol');
define('API_URL', SITE_URL . '/api');

// ================================================
// TELEGRAM БОТЫ
// ================================================
// Основной бот (регистрация, оплата, управление)
define('MAIN_BOT_TOKEN', '8348623948:AAGCHSV1F8sewr-BFGEVo06_CrFN1yvGD38');

// Бот-отправитель (только отправка ответов)
define('SENDER_BOT_TOKEN', '8558787960:AAFGC7VT7-IyWRgVrgk2NpsUR7ZuYjHVE6Q');

// ================================================
// GROQ API
// ================================================
define('GROQ_API_KEY', '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');
define('GROQ_API_URL', 'https://api.groq.com/openai/v1/responses');
define('GROQ_MODEL', 'meta-llama/llama-4-scout-17b-16e-instruct');
define('GROQ_TIMEOUT', 60); // секунды

// Промпт для Groq
define('GROQ_PROMPT', "You are an assistant that sees an image of a test and provides only answers without explanations. If the question is matching, give the answers as pairs like Python - print, Java - System.out.print. If the question is single-choice, give only the answer like 1) A. If the question has multiple correct options, list all of them like 1) A B C. If the question is drag & drop / ordering, give answers in order like 1) Python 2) Java 3) C++. Do not write any explanations or reasoning. Work directly from the image and extract the question text automatically. No explanations, no extra text.");

// ================================================
// ТАРИФЫ (в тенге)
// ================================================
define('PRICE_1H', 100);
define('PRICE_3H', 200);
define('PRICE_12H', 300);
define('PRICE_24H', 4000);

// Соответствие часов и цен
$PRICES = [
    1 => PRICE_1H,
    3 => PRICE_3H,
    12 => PRICE_12H,
    24 => PRICE_24H
];

// ================================================
// БЕЗОПАСНОСТЬ
// ================================================
// Пароль админки
define('ADMIN_PASSWORD', 'alibali2436');

// Разрешенные IP для админки (пусто = все)
$ADMIN_ALLOWED_IPS = [];

// Секретный ключ для подписи запросов
define('API_SECRET_KEY', 'admin1234567890securekey');

// Rate limiting
define('MAX_REQUESTS_PER_MINUTE', 10);
define('MAX_SCREENSHOTS_PER_HOUR', 50);

// ================================================
// ПУТИ К ФАЙЛАМ
// ================================================
define('ROOT_PATH', dirname(__DIR__));
define('LOGS_PATH', ROOT_PATH . '/logs');
define('TEMP_PATH', ROOT_PATH . '/temp');

// Создание директорий если не существуют
if (!file_exists(LOGS_PATH)) {
    mkdir(LOGS_PATH, 0755, true);
}
if (!file_exists(TEMP_PATH)) {
    mkdir(TEMP_PATH, 0755, true);
}

// ================================================
// НАСТРОЙКИ PHP
// ================================================
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', LOGS_PATH . '/php_errors.log');

// Таймзона
date_default_timezone_set('Asia/Almaty');

// Лимиты
ini_set('memory_limit', '256M');
ini_set('max_execution_time', '90');
ini_set('upload_max_filesize', '10M');
ini_set('post_max_size', '10M');

// ================================================
// ЗАГОЛОВКИ
// ================================================
header('Content-Type: application/json; charset=utf-8');
header('X-Powered-By: QuickVision/1.0');

// CORS (если нужно)
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400');
}

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    }
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
        header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
    }
    exit(0);
}

// ================================================
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
// ================================================

/**
 * Получить IP адрес клиента
 */
function get_client_ip() {
    $ip_keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 
                'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 
                'REMOTE_ADDR'];
    
    foreach ($ip_keys as $key) {
        if (array_key_exists($key, $_SERVER)) {
            foreach (explode(',', $_SERVER[$key]) as $ip) {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, 
                    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
}

/**
 * Логирование
 */
function log_message($message, $type = 'info', $context = []) {
    $log_file = LOGS_PATH . '/' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $ip = get_client_ip();
    
    $log_entry = sprintf(
        "[%s] [%s] [%s] %s %s\n",
        $timestamp,
        strtoupper($type),
        $ip,
        $message,
        !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : ''
    );
    
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

/**
 * JSON ответ
 */
function json_response($data, $status_code = 200) {
    http_response_code($status_code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Ошибка JSON
 */
function json_error($message, $code = 400, $details = []) {
    json_response([
        'success' => false,
        'error' => $message,
        'details' => $details,
        'timestamp' => time()
    ], $code);
}

/**
 * Успех JSON
 */
function json_success($data = [], $message = 'Success') {
    json_response([
        'success' => true,
        'message' => $message,
        'data' => $data,
        'timestamp' => time()
    ]);
}

/**
 * Генерация случайного кода
 */
function generate_activation_code($length = 16) {
    $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    $max = strlen($characters) - 1;
    
    for ($i = 0; $i < $length; $i++) {
        $code .= $characters[random_int(0, $max)];
    }
    
    // Форматируем как XXXX-XXXX-XXXX-XXXX
    return implode('-', str_split($code, 4));
}

/**
 * Проверка rate limit
 */
function check_rate_limit($user_id, $max_requests = MAX_REQUESTS_PER_MINUTE) {
    global $pdo;
    
    $since = date('Y-m-d H:i:s', strtotime('-1 minute'));
    
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM activity_logs 
        WHERE user_id = ? 
          AND action = 'screenshot_request'
          AND created_at > ?
    ");
    $stmt->execute([$user_id, $since]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $result['count'] < $max_requests;
}

/**
 * Проверка админских прав
 */
function check_admin_access() {
    global $ADMIN_ALLOWED_IPS;
    
    if (!empty($ADMIN_ALLOWED_IPS)) {
        $ip = get_client_ip();
        if (!in_array($ip, $ADMIN_ALLOWED_IPS)) {
            json_error('Access denied', 403);
        }
    }
    
    session_start();
    if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
        header('Location: login.php');
        exit;
    }
}

// ================================================
// АВТОЗАГРУЗКА
// ================================================
spl_autoload_register(function ($class) {
    $file = ROOT_PATH . '/api/' . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});