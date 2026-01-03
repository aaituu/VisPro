<?php
/**
 * QuickVision Database Connection
 * PDO соединение с MySQL
 */

define('API_ACCESS', true);
require_once __DIR__ . '/config.php';

try {
    $dsn = sprintf(
        "mysql:host=%s;dbname=%s;charset=%s",
        DB_HOST,
        DB_NAME,
        DB_CHARSET
    );
    
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET,
        PDO::ATTR_PERSISTENT         => false
    ];
    
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    
    // Проверка соединения
    $pdo->query('SELECT 1');
    
} catch (PDOException $e) {
    log_message('Database connection failed: ' . $e->getMessage(), 'error');
    
    // В продакшене не показываем детали ошибки
    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        json_error('Database connection failed: ' . $e->getMessage(), 500);
    } else {
        json_error('Database unavailable. Please try again later.', 503);
    }
}

/**
 * Класс для работы с базой данных
 */
class Database {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Получить пользователя по chat_id
     */
    public function getUserByChatId($chat_id) {
        $stmt = $this->pdo->prepare("
            SELECT * FROM users 
            WHERE telegram_chat_id = ? 
            LIMIT 1
        ");
        $stmt->execute([$chat_id]);
        return $stmt->fetch();
    }
    
    /**
     * Получить пользователя по ID
     */
    public function getUserById($user_id) {
        $stmt = $this->pdo->prepare("
            SELECT * FROM users 
            WHERE id = ? 
            LIMIT 1
        ");
        $stmt->execute([$user_id]);
        return $stmt->fetch();
    }
    
    /**
     * Создать нового пользователя
     */
    public function createUser($chat_id, $username, $first_name, $last_name = null) {
        $stmt = $this->pdo->prepare("
            INSERT INTO users (telegram_chat_id, username, first_name, last_name)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$chat_id, $username, $first_name, $last_name]);
        return $this->pdo->lastInsertId();
    }
    
    /**
     * Обновить время подписки
     */
    public function extendSubscription($user_id, $hours) {
        $stmt = $this->pdo->prepare("
            SELECT expires_at FROM users WHERE id = ?
        ");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        
        $current_expires = $user['expires_at'];
        $now = new DateTime();
        
        // Если подписка уже истекла или не существует, начинаем с текущего времени
        if (!$current_expires || strtotime($current_expires) < time()) {
            $new_expires = $now->modify("+{$hours} hours");
        } else {
            // Иначе добавляем к существующей дате
            $new_expires = new DateTime($current_expires);
            $new_expires->modify("+{$hours} hours");
        }
        
        $stmt = $this->pdo->prepare("
            UPDATE users 
            SET expires_at = ?,
                hours_purchased = hours_purchased + ?,
                status = 'active',
                updated_at = NOW()
            WHERE id = ?
        ");
        
        return $stmt->execute([
            $new_expires->format('Y-m-d H:i:s'),
            $hours,
            $user_id
        ]);
    }
    
    /**
     * Создать код активации
     */
    public function createActivation($user_id, $code) {
        $stmt = $this->pdo->prepare("
            INSERT INTO activations (code, user_id)
            VALUES (?, ?)
        ");
        return $stmt->execute([$code, $user_id]);
    }
    
    /**
     * Проверить код активации
     */
    public function checkActivation($code) {
        $stmt = $this->pdo->prepare("
            SELECT a.*, u.* 
            FROM activations a
            JOIN users u ON a.user_id = u.id
            WHERE a.code = ?
            LIMIT 1
        ");
        $stmt->execute([$code]);
        return $stmt->fetch();
    }
    
    /**
     * Отметить код как использованный
     */
    public function markActivationUsed($code, $ip = null, $device_info = null) {
        $stmt = $this->pdo->prepare("
            UPDATE activations 
            SET is_used = 1,
                used_at = NOW(),
                ip_address = ?,
                device_info = ?
            WHERE code = ?
        ");
        return $stmt->execute([$ip, $device_info, $code]);
    }
    
    /**
     * Проверить активность подписки
     */
    public function isSubscriptionActive($user_id) {
        $stmt = $this->pdo->prepare("
            SELECT 
                status,
                expires_at,
                (expires_at IS NULL OR expires_at > NOW()) as is_active
            FROM users 
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$user_id]);
        $result = $stmt->fetch();
        
        return $result && 
               $result['status'] === 'active' && 
               $result['is_active'];
    }
    
    /**
     * Сохранить скриншот
     */
    public function saveScreenshot($data) {
        $stmt = $this->pdo->prepare("
            INSERT INTO screenshots (
                user_id, 
                activation_code,
                file_hash,
                file_size,
                groq_prompt,
                groq_response,
                extracted_answer,
                response_time_ms,
                success,
                error_message
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        return $stmt->execute([
            $data['user_id'],
            $data['activation_code'] ?? null,
            $data['file_hash'] ?? null,
            $data['file_size'] ?? null,
            $data['groq_prompt'] ?? null,
            $data['groq_response'] ?? null,
            $data['extracted_answer'] ?? null,
            $data['response_time_ms'] ?? null,
            $data['success'] ?? 1,
            $data['error_message'] ?? null
        ]);
    }
    
    /**
     * Создать платеж
     */
    public function createPayment($user_id, $amount, $hours, $method = 'kaspi') {
        $stmt = $this->pdo->prepare("
            INSERT INTO payments (user_id, amount, hours, payment_method)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$user_id, $amount, $hours, $method]);
        return $this->pdo->lastInsertId();
    }
    
    /**
     * Обновить статус платежа
     */
    public function updatePaymentStatus($payment_id, $status, $transaction_id = null) {
        $stmt = $this->pdo->prepare("
            UPDATE payments 
            SET status = ?,
                transaction_id = ?,
                completed_at = CASE WHEN ? = 'completed' THEN NOW() ELSE completed_at END
            WHERE id = ?
        ");
        return $stmt->execute([$status, $transaction_id, $status, $payment_id]);
    }
    
    /**
     * Логирование активности
     */
    public function logActivity($user_id, $action, $details = null, $ip = null) {
        $stmt = $this->pdo->prepare("
            INSERT INTO activity_logs (user_id, action, details, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        return $stmt->execute([
            $user_id,
            $action,
            is_array($details) ? json_encode($details, JSON_UNESCAPED_UNICODE) : $details,
            $ip ?? get_client_ip(),
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    }
    
    /**
     * Получить настройку
     */
    public function getSetting($key, $default = null) {
        $stmt = $this->pdo->prepare("
            SELECT value FROM settings WHERE `key` = ? LIMIT 1
        ");
        $stmt->execute([$key]);
        $result = $stmt->fetch();
        return $result ? $result['value'] : $default;
    }
    
    /**
     * Блокировать пользователя
     */
    public function blockUser($user_id) {
        $stmt = $this->pdo->prepare("
            UPDATE users SET status = 'blocked' WHERE id = ?
        ");
        return $stmt->execute([$user_id]);
    }
    
    /**
     * Разблокировать пользователя
     */
    public function unblockUser($user_id) {
        $stmt = $this->pdo->prepare("
            UPDATE users SET status = 'active' WHERE id = ?
        ");
        return $stmt->execute([$user_id]);
    }
    
    /**
     * Удалить пользователя
     */
    public function deleteUser($user_id) {
        $stmt = $this->pdo->prepare("DELETE FROM users WHERE id = ?");
        return $stmt->execute([$user_id]);
    }
    
    /**
     * Получить статистику пользователя
     */
    public function getUserStats($user_id) {
        $stmt = $this->pdo->prepare("
            SELECT * FROM user_stats WHERE id = ?
        ");
        $stmt->execute([$user_id]);
        return $stmt->fetch();
    }
    
    /**
     * Получить всех пользователей для админки
     */
    public function getAllUsers($limit = 100, $offset = 0) {
        $stmt = $this->pdo->prepare("
            SELECT 
                u.*,
                COUNT(DISTINCT s.id) as screenshot_count,
                COUNT(DISTINCT p.id) as payment_count,
                MAX(s.created_at) as last_activity
            FROM users u
            LEFT JOIN screenshots s ON u.id = s.user_id
            LEFT JOIN payments p ON u.id = p.user_id AND p.status = 'completed'
            GROUP BY u.id
            ORDER BY u.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);
        return $stmt->fetchAll();
    }
}

// Создаем глобальный экземпляр
$db = new Database($pdo);