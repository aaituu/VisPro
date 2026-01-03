<?php
/**
 * QuickVision Admin User Actions
 * AJAX обработчик действий с пользователями из админки
 */

session_start();

define('API_ACCESS', true);
require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/db_connect.php';
require_once __DIR__ . '/../api/functions.php';

// Проверка авторизации
check_admin_access();

// Только POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

// Получаем данные
$action = $_POST['action'] ?? '';
$user_id = (int)($_POST['user_id'] ?? 0);

if (!$user_id) {
    json_error('User ID is required');
}

// Проверяем существование пользователя
$user = $db->getUserById($user_id);

if (!$user) {
    json_error('User not found', 404);
}

// Логирование админского действия
log_message('Admin action', 'info', [
    'admin_ip' => get_client_ip(),
    'action' => $action,
    'user_id' => $user_id,
    'username' => $user['username']
]);

// Обработка действий
switch ($action) {
    
    // ================================
    // БЛОКИРОВКА ПОЛЬЗОВАТЕЛЯ
    // ================================
    case 'block':
        try {
            $success = $db->blockUser($user_id);
            
            if (!$success) {
                throw new Exception('Database update failed');
            }
            
            // Логируем
            $db->logActivity($user_id, 'user_blocked_by_admin', [
                'admin_ip' => get_client_ip(),
                'blocked_at' => date('Y-m-d H:i:s')
            ]);
            
            // Уведомляем пользователя
            if ($user['telegram_chat_id']) {
                $message = "🚫 *Ваш аккаунт заблокирован*\n\n";
                $message .= "Для выяснения причины свяжитесь с поддержкой:\n";
                $message .= "/support";
                
                sendTelegramMessage(
                    $user['telegram_chat_id'],
                    $message,
                    MAIN_BOT_TOKEN
                );
            }
            
            json_success([
                'user_id' => $user_id,
                'new_status' => 'blocked'
            ], 'User blocked successfully');
            
        } catch (Exception $e) {
            log_message('Block user error', 'error', [
                'user_id' => $user_id,
                'error' => $e->getMessage()
            ]);
            json_error('Failed to block user: ' . $e->getMessage(), 500);
        }
        break;
    
    // ================================
    // РАЗБЛОКИРОВКА ПОЛЬЗОВАТЕЛЯ
    // ================================
    case 'unblock':
        try {
            $success = $db->unblockUser($user_id);
            
            if (!$success) {
                throw new Exception('Database update failed');
            }
            
            // Логируем
            $db->logActivity($user_id, 'user_unblocked_by_admin', [
                'admin_ip' => get_client_ip(),
                'unblocked_at' => date('Y-m-d H:i:s')
            ]);
            
            // Уведомляем пользователя
            if ($user['telegram_chat_id']) {
                $message = "✅ *Ваш аккаунт разблокирован*\n\n";
                $message .= "Вы можете продолжить использование сервиса.\n";
                $message .= "Проверьте статус: /status";
                
                sendTelegramMessage(
                    $user['telegram_chat_id'],
                    $message,
                    MAIN_BOT_TOKEN
                );
            }
            
            json_success([
                'user_id' => $user_id,
                'new_status' => 'active'
            ], 'User unblocked successfully');
            
        } catch (Exception $e) {
            log_message('Unblock user error', 'error', [
                'user_id' => $user_id,
                'error' => $e->getMessage()
            ]);
            json_error('Failed to unblock user: ' . $e->getMessage(), 500);
        }
        break;
    
    // ================================
    // УДАЛЕНИЕ ПОЛЬЗОВАТЕЛЯ
    // ================================
    case 'delete':
        try {
            // Получаем все данные перед удалением для логирования
            $stats = $db->getUserStats($user_id);
            
            $success = $db->deleteUser($user_id);
            
            if (!$success) {
                throw new Exception('Database delete failed');
            }
            
            // Логируем (user_id будет NULL т.к. пользователь удален)
            log_message('User deleted by admin', 'warning', [
                'deleted_user_id' => $user_id,
                'username' => $user['username'],
                'chat_id' => $user['telegram_chat_id'],
                'admin_ip' => get_client_ip(),
                'stats' => $stats
            ]);
            
            // Уведомляем пользователя
            if ($user['telegram_chat_id']) {
                $message = "❌ *Ваш аккаунт удален*\n\n";
                $message .= "Все данные были удалены из системы.\n";
                $message .= "Для возобновления работы начните заново: /start";
                
                sendTelegramMessage(
                    $user['telegram_chat_id'],
                    $message,
                    MAIN_BOT_TOKEN
                );
            }
            
            json_success([
                'user_id' => $user_id,
                'deleted' => true
            ], 'User deleted successfully');
            
        } catch (Exception $e) {
            log_message('Delete user error', 'error', [
                'user_id' => $user_id,
                'error' => $e->getMessage()
            ]);
            json_error('Failed to delete user: ' . $e->getMessage(), 500);
        }
        break;
    
    // ================================
    // ПРОДЛЕНИЕ ПОДПИСКИ
    // ================================
    case 'extend':
        $hours = (int)($_POST['hours'] ?? 0);
        
        if ($hours <= 0) {
            json_error('Valid hours amount is required');
        }
        
        try {
            $success = $db->extendSubscription($user_id, $hours);
            
            if (!$success) {
                throw new Exception('Database update failed');
            }
            
            // Если пользователь был заблокирован - разблокируем
            if ($user['status'] === 'blocked') {
                $db->unblockUser($user_id);
            }
            
            // Логируем
            $db->logActivity($user_id, 'subscription_extended_by_admin', [
                'hours_added' => $hours,
                'admin_ip' => get_client_ip(),
                'extended_at' => date('Y-m-d H:i:s')
            ]);
            
            // Получаем обновленные данные
            $updated_user = $db->getUserById($user_id);
            
            // Уведомляем пользователя
            if ($user['telegram_chat_id']) {
                $expires = new DateTime($updated_user['expires_at']);
                
                $message = "🎉 *Подписка продлена администратором!*\n\n";
                $message .= "⏰ Добавлено: *{$hours} " . declension($hours, ['час', 'часа', 'часов']) . "*\n";
                $message .= "📅 Активна до: *" . $expires->format('d.m.Y H:i') . "*\n\n";
                $message .= "Спасибо за использование QuickVision! 🚀";
                
                sendTelegramMessage(
                    $user['telegram_chat_id'],
                    $message,
                    MAIN_BOT_TOKEN
                );
            }
            
            json_success([
                'user_id' => $user_id,
                'hours_added' => $hours,
                'new_expires_at' => $updated_user['expires_at'],
                'total_hours' => $updated_user['hours_purchased'],
                'new_status' => $updated_user['status']
            ], "Subscription extended by {$hours} hours");
            
        } catch (Exception $e) {
            log_message('Extend subscription error', 'error', [
                'user_id' => $user_id,
                'hours' => $hours,
                'error' => $e->getMessage()
            ]);
            json_error('Failed to extend subscription: ' . $e->getMessage(), 500);
        }
        break;
    
    // ================================
    // СОЗДАНИЕ НОВОГО КОДА АКТИВАЦИИ
    // ================================
    case 'generate_code':
        try {
            $activation_code = generateUniqueActivationCode($pdo);
            
            $success = $db->createActivation($user_id, $activation_code);
            
            if (!$success) {
                throw new Exception('Failed to create activation code');
            }
            
            // Логируем
            $db->logActivity($user_id, 'activation_code_generated_by_admin', [
                'code' => substr($activation_code, 0, 8) . '...',
                'admin_ip' => get_client_ip()
            ]);
            
            // Отправляем код пользователю
            if ($user['telegram_chat_id']) {
                $message = "🔑 *Новый код активации:*\n\n";
                $message .= "`{$activation_code}`\n\n";
                $message .= "Используйте этот код для активации приложения.";
                
                sendTelegramMessage(
                    $user['telegram_chat_id'],
                    $message,
                    MAIN_BOT_TOKEN
                );
            }
            
            json_success([
                'user_id' => $user_id,
                'activation_code' => $activation_code,
                'created_at' => date('Y-m-d H:i:s')
            ], 'Activation code generated');
            
        } catch (Exception $e) {
            log_message('Generate code error', 'error', [
                'user_id' => $user_id,
                'error' => $e->getMessage()
            ]);
            json_error('Failed to generate code: ' . $e->getMessage(), 500);
        }
        break;
    
    // ================================
    // ПОЛУЧЕНИЕ ДЕТАЛЬНОЙ ИНФОРМАЦИИ
    // ================================
    case 'get_details':
        try {
            $stats = $db->getUserStats($user_id);
            
            // Получаем коды активации
            $stmt = $pdo->prepare("
                SELECT code, is_used, used_at, created_at 
                FROM activations 
                WHERE user_id = ? 
                ORDER BY created_at DESC 
                LIMIT 5
            ");
            $stmt->execute([$user_id]);
            $activations = $stmt->fetchAll();
            
            // Получаем последние скриншоты
            $stmt = $pdo->prepare("
                SELECT created_at, file_size, response_time_ms, success 
                FROM screenshots 
                WHERE user_id = ? 
                ORDER BY created_at DESC 
                LIMIT 10
            ");
            $stmt->execute([$user_id]);
            $screenshots = $stmt->fetchAll();
            
            // Получаем платежи
            $stmt = $pdo->prepare("
                SELECT amount, hours, status, created_at, completed_at 
                FROM payments 
                WHERE user_id = ? 
                ORDER BY created_at DESC 
                LIMIT 10
            ");
            $stmt->execute([$user_id]);
            $payments = $stmt->fetchAll();
            
            json_success([
                'user' => $user,
                'stats' => $stats,
                'activations' => $activations,
                'recent_screenshots' => $screenshots,
                'payments' => $payments
            ]);
            
        } catch (Exception $e) {
            json_error('Failed to get details: ' . $e->getMessage(), 500);
        }
        break;
    
    // ================================
    // СБРОС ВСЕХ КОДОВ АКТИВАЦИИ
    // ================================
    case 'reset_codes':
        try {
            $stmt = $pdo->prepare("DELETE FROM activations WHERE user_id = ?");
            $success = $stmt->execute([$user_id]);
            
            if (!$success) {
                throw new Exception('Failed to reset codes');
            }
            
            $db->logActivity($user_id, 'activation_codes_reset_by_admin', [
                'admin_ip' => get_client_ip()
            ]);
            
            json_success([
                'user_id' => $user_id,
                'codes_deleted' => $stmt->rowCount()
            ], 'Activation codes reset');
            
        } catch (Exception $e) {
            json_error('Failed to reset codes: ' . $e->getMessage(), 500);
        }
        break;
    
    // ================================
    // НЕИЗВЕСТНОЕ ДЕЙСТВИЕ
    // ================================
    default:
        log_message('Unknown admin action', 'warning', [
            'action' => $action,
            'user_id' => $user_id,
            'admin_ip' => get_client_ip()
        ]);
        json_error('Unknown action: ' . $action, 400);
}