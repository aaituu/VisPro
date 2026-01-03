<?php
/**
 * QuickVision Admin Dashboard
 * Управление пользователями
 */

session_start();

define('API_ACCESS', true);
require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/db_connect.php';

// Проверка авторизации
check_admin_access();

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $user_id = (int)$_POST['user_id'];
    
    switch ($action) {
        case 'block':
            $db->blockUser($user_id);
            $message = "Пользователь заблокирован";
            break;
            
        case 'unblock':
            $db->unblockUser($user_id);
            $message = "Пользователь разблокирован";
            break;
            
        case 'delete':
            $db->deleteUser($user_id);
            $message = "Пользователь удален";
            break;
            
        case 'extend':
            $hours = (int)$_POST['hours'];
            $db->extendSubscription($user_id, $hours);
            $message = "Подписка продлена на {$hours} часов";
            break;
    }
    
    header("Location: dashboard.php?msg=" . urlencode($message));
    exit;
}

// Получаем список пользователей
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 50;
$offset = ($page - 1) * $limit;

$users = $db->getAllUsers($limit, $offset);

// Подсчет общего количества
$total_stmt = $pdo->query("SELECT COUNT(*) as total FROM users");
$total_users = $total_stmt->fetch()['total'];
$total_pages = ceil($total_users / $limit);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QuickVision Admin</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f5f5f5;
            padding: 20px;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 30px;
        }
        h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 28px;
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin: 30px 0;
        }
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .stat-card h3 {
            font-size: 14px;
            opacity: 0.9;
            margin-bottom: 10px;
        }
        .stat-card .value {
            font-size: 32px;
            font-weight: bold;
        }
        .message {
            padding: 15px;
            background: #4CAF50;
            color: white;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background: #667eea;
            color: white;
            font-weight: 600;
        }
        tr:hover {
            background: #f9f9f9;
        }
        .status-active { color: #4CAF50; font-weight: bold; }
        .status-expired { color: #FF9800; font-weight: bold; }
        .status-blocked { color: #f44336; font-weight: bold; }
        .btn {
            padding: 8px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 13px;
            margin: 2px;
            transition: all 0.3s;
        }
        .btn-block { background: #ff9800; color: white; }
        .btn-unblock { background: #4CAF50; color: white; }
        .btn-delete { background: #f44336; color: white; }
        .btn-extend { background: #2196F3; color: white; }
        .btn:hover { opacity: 0.8; transform: translateY(-2px); }
        .pagination {
            margin-top: 30px;
            text-align: center;
        }
        .pagination a {
            display: inline-block;
            padding: 10px 15px;
            margin: 0 5px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 5px;
        }
        .pagination a.active { background: #764ba2; }
        .logout {
            float: right;
            color: #f44336;
            text-decoration: none;
            font-weight: bold;
        }
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
        }
        .modal-content {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: white;
            padding: 30px;
            border-radius: 10px;
            min-width: 400px;
        }
        .modal-content h2 { margin-bottom: 20px; }
        .modal-content input {
            width: 100%;
            padding: 10px;
            margin: 10px 0;
            border: 1px solid #ddd;
            border-radius: 5px;
        }
        .modal-actions {
            margin-top: 20px;
            text-align: right;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>
            QuickVision Admin Panel
            <a href="logout.php" class="logout">Выйти</a>
        </h1>
        
        <?php if (isset($_GET['msg'])): ?>
            <div class="message"><?= htmlspecialchars($_GET['msg']) ?></div>
        <?php endif; ?>
        
        <div class="stats">
            <?php
            $stats_query = $pdo->query("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'active' AND (expires_at IS NULL OR expires_at > NOW()) THEN 1 ELSE 0 END) as active,
                    SUM(CASE WHEN status = 'blocked' THEN 1 ELSE 0 END) as blocked,
                    SUM(CASE WHEN expires_at < NOW() THEN 1 ELSE 0 END) as expired
                FROM users
            ");
            $stats = $stats_query->fetch();
            
            $payments_query = $pdo->query("
                SELECT 
                    COUNT(*) as total_payments,
                    SUM(amount) as total_revenue
                FROM payments 
                WHERE status = 'completed'
            ");
            $payments = $payments_query->fetch();
            
            $screenshots_query = $pdo->query("
                SELECT COUNT(*) as total FROM screenshots WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            $screenshots_today = $screenshots_query->fetch()['total'];
            ?>
            
            <div class="stat-card">
                <h3>Всего пользователей</h3>
                <div class="value"><?= $stats['total'] ?></div>
            </div>
            
            <div class="stat-card">
                <h3>Активных</h3>
                <div class="value"><?= $stats['active'] ?></div>
            </div>
            
            <div class="stat-card">
                <h3>Заблокировано</h3>
                <div class="value"><?= $stats['blocked'] ?></div>
            </div>
            
            <div class="stat-card">
                <h3>Скриншотов за 24ч</h3>
                <div class="value"><?= $screenshots_today ?></div>
            </div>
            
            <div class="stat-card">
                <h3>Платежей</h3>
                <div class="value"><?= $payments['total_payments'] ?? 0 ?></div>
            </div>
            
            <div class="stat-card">
                <h3>Выручка</h3>
                <div class="value"><?= number_format($payments['total_revenue'] ?? 0, 0, '', ' ') ?>₸</div>
            </div>
        </div>
        
        <h2>Пользователи (<?= $total_users ?>)</h2>
        
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Username</th>
                    <th>Имя</th>
                    <th>Chat ID</th>
                    <th>Статус</th>
                    <th>Истекает</th>
                    <th>Скриншоты</th>
                    <th>Платежи</th>
                    <th>Последняя активность</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                    <?php
                    $is_active = ($user['status'] === 'active' && 
                                 ($user['expires_at'] === null || strtotime($user['expires_at']) > time()));
                    
                    if ($user['status'] === 'blocked') {
                        $status_class = 'status-blocked';
                        $status_text = 'Заблокирован';
                    } elseif ($is_active) {
                        $status_class = 'status-active';
                        $status_text = 'Активен';
                    } else {
                        $status_class = 'status-expired';
                        $status_text = 'Истек';
                    }
                    ?>
                    <tr>
                        <td><?= $user['id'] ?></td>
                        <td>@<?= htmlspecialchars($user['username'] ?: 'N/A') ?></td>
                        <td><?= htmlspecialchars($user['first_name']) ?></td>
                        <td><?= $user['telegram_chat_id'] ?></td>
                        <td class="<?= $status_class ?>"><?= $status_text ?></td>
                        <td>
                            <?php if ($user['expires_at']): ?>
                                <?= date('d.m.Y H:i', strtotime($user['expires_at'])) ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td><?= $user['screenshot_count'] ?? 0 ?></td>
                        <td><?= $user['payment_count'] ?? 0 ?></td>
                        <td>
                            <?php if ($user['last_activity']): ?>
                                <?= date('d.m H:i', strtotime($user['last_activity'])) ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($user['status'] === 'blocked'): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="unblock">
                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                    <button type="submit" class="btn btn-unblock">Разблокировать</button>
                                </form>
                            <?php else: ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="block">
                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                    <button type="submit" class="btn btn-block">Блокировать</button>
                                </form>
                            <?php endif; ?>
                            
                            <button class="btn btn-extend" onclick="openExtendModal(<?= $user['id'] ?>)">Продлить</button>
                            
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Удалить пользователя?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                <button type="submit" class="btn btn-delete">Удалить</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <a href="?page=<?= $i ?>" class="<?= $i === $page ? 'active' : '' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Модальное окно продления -->
    <div id="extendModal" class="modal">
        <div class="modal-content">
            <h2>Продлить подписку</h2>
            <form method="POST">
                <input type="hidden" name="action" value="extend">
                <input type="hidden" name="user_id" id="extend_user_id">
                <label>Количество часов:</label>
                <input type="number" name="hours" min="1" value="24" required>
                <div class="modal-actions">
                    <button type="button" class="btn" onclick="closeExtendModal()">Отмена</button>
                    <button type="submit" class="btn btn-extend">Продлить</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function openExtendModal(userId) {
            document.getElementById('extend_user_id').value = userId;
            document.getElementById('extendModal').style.display = 'block';
        }
        
        function closeExtendModal() {
            document.getElementById('extendModal').style.display = 'none';
        }
        
        window.onclick = function(event) {
            const modal = document.getElementById('extendModal');
            if (event.target === modal) {
                closeExtendModal();
            }
        }
    </script>
</body>
</html>