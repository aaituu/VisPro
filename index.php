<?php
/**
 * QuickVision - Landing Page
 */
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QuickVision - AI ассистент для тестов</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .container {
            background: white;
            border-radius: 20px;
            padding: 60px 40px;
            max-width: 600px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            text-align: center;
        }
        
        .logo {
            font-size: 64px;
            margin-bottom: 20px;
        }
        
        h1 {
            color: #333;
            font-size: 36px;
            margin-bottom: 10px;
        }
        
        .subtitle {
            color: #666;
            font-size: 18px;
            margin-bottom: 40px;
        }
        
        .features {
            text-align: left;
            margin: 40px 0;
        }
        
        .feature {
            display: flex;
            align-items: center;
            margin: 15px 0;
            font-size: 16px;
            color: #555;
        }
        
        .feature-icon {
            font-size: 24px;
            margin-right: 15px;
            min-width: 30px;
        }
        
        .buttons {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            justify-content: center;
            margin-top: 40px;
        }
        
        .btn {
            display: inline-block;
            padding: 15px 30px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: bold;
            font-size: 16px;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-secondary {
            background: #f0f0f0;
            color: #333;
        }
        
        .status {
            margin-top: 40px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 10px;
        }
        
        .status-item {
            display: inline-block;
            margin: 0 15px;
            color: #666;
        }
        
        .status-value {
            color: #667eea;
            font-weight: bold;
            font-size: 18px;
        }
        
        .admin-link {
            margin-top: 30px;
            padding-top: 30px;
            border-top: 1px solid #eee;
        }
        
        .admin-link a {
            color: #667eea;
            text-decoration: none;
            font-size: 14px;
        }
        
        .admin-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">🚀</div>
        <h1>QuickVision</h1>
        <p class="subtitle">AI ассистент для тестов</p>
        
        <div class="features">
            <div class="feature">
                <span class="feature-icon">📸</span>
                <span>Скриншот по горячей клавише</span>
            </div>
            <div class="feature">
                <span class="feature-icon">🤖</span>
                <span>AI обработка изображения</span>
            </div>
            <div class="feature">
                <span class="feature-icon">💬</span>
                <span>Ответы в Telegram</span>
            </div>
            <div class="feature">
                <span class="feature-icon">⚡</span>
                <span>Моментальная работа</span>
            </div>
        </div>
        
        <div class="buttons">
            <a href="https://t.me/OdaMainbot" class="btn btn-primary" target="_blank">
                📱 Открыть бота
            </a>
            <a href="/download/main.exe" class="btn btn-secondary">
                📥 Скачать приложение
            </a>
        </div>
        
        <?php
        // Показываем статистику если есть доступ к БД
        try {
            require_once __DIR__ . '/api/config.php';
            require_once __DIR__ . '/api/db_connect.php';
            
            $stats_query = $pdo->query("
                SELECT 
                    COUNT(*) as total_users,
                    SUM(CASE WHEN status = 'active' AND (expires_at IS NULL OR expires_at > NOW()) THEN 1 ELSE 0 END) as active_users
                FROM users
            ");
            $stats = $stats_query->fetch();
            
            if ($stats) {
                echo '<div class="status">';
                echo '<div class="status-item">';
                echo '<div class="status-value">' . $stats['total_users'] . '</div>';
                echo '<div>Пользователей</div>';
                echo '</div>';
                echo '<div class="status-item">';
                echo '<div class="status-value">' . $stats['active_users'] . '</div>';
                echo '<div>Активных</div>';
                echo '</div>';
                echo '</div>';
            }
        } catch (Exception $e) {
            // Если БД недоступна - не показываем статистику
        }
        ?>
        
        <div class="admin-link">
            <a href="/admin/login.php">🔐 Вход для администратора</a>
        </div>
    </div>
</body>
</html>