<?php
include 'config.php';

// Проверяем, авторизован ли пользователь
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

// Получаем информацию о пользователе
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

// Получаем список ML моделей
$stmt = $pdo->prepare("SELECT * FROM ml_models ORDER BY created_at DESC");
$stmt->execute();
$models = $stmt->fetchAll();

// Статистика по моделям
$stmt = $pdo->prepare("
    SELECT COUNT(*) as total_models,
           COUNT(CASE WHEN is_active = TRUE THEN 1 END) as active_models,
           AVG(accuracy) as avg_accuracy
    FROM ml_models
");
$stmt->execute();
$stats = $stmt->fetch();
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ML модели - Система интеллектуальной оценки знаний</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Стили из dashboard.php */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        :root {
            --primary: #3498db;
            --primary-dark: #2980b9;
            --secondary: #2c3e50;
            --accent: #e74c3c;
            --light: #f5f7fa;
            --gray: #7f8c8d;
            --success: #2ecc71;
            --warning: #f39c12;
            --sidebar-width: 250px;
        }
        
        body {
            background-color: #f0f2f5;
            color: #333;
            line-height: 1.6;
            display: flex;
            min-height: 100vh;
        }
        
        /* Sidebar */
        .sidebar {
            width: var(--sidebar-width);
            background: var(--secondary);
            color: white;
            height: 100vh;
            position: fixed;
            padding: 20px 0;
            overflow-y: auto;
        }
        
        .logo {
            padding: 0 20px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            margin-bottom: 20px;
        }
        
        .logo h1 {
            font-size: 22px;
            font-weight: 600;
        }
        
        .user-info {
            padding: 0 20px 20px;
            display: flex;
            align-items: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            margin-bottom: 20px;
        }
        
        .user-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            font-weight: bold;
            margin-right: 15px;
            color: white;
        }
        
        .user-details h3 {
            font-size: 16px;
            margin-bottom: 5px;
        }
        
        .user-details p {
            font-size: 13px;
            color: rgba(255,255,255,0.7);
        }
        
        .role-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
            margin-top: 5px;
        }
        
        .role-admin {
            background: var(--accent);
        }
        
        .role-teacher {
            background: var(--primary);
        }
        
        .role-student {
            background: var(--success);
        }
        
        .nav-links {
            list-style: none;
            padding: 0 15px;
        }
        
        .nav-links li {
            margin-bottom: 5px;
        }
        
        .nav-links a {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            border-radius: 6px;
            transition: all 0.3s;
        }
        
        .nav-links a:hover, .nav-links a.active {
            background: rgba(255,255,255,0.1);
            color: white;
        }
        
        .nav-links i {
            margin-right: 10px;
            font-size: 18px;
            width: 24px;
            text-align: center;
        }
        
        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            padding: 20px;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .welcome h2 {
            font-size: 24px;
            color: var(--secondary);
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .welcome p {
            color: var(--gray);
        }
        
        .search-box {
            display: flex;
            align-items: center;
            background: white;
            padding: 10px 15px;
            border-radius: 30px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .search-box input {
            border: none;
            outline: none;
            padding: 5px 10px;
            font-size: 15px;
            width: 200px;
        }
        
        .search-box i {
            color: var(--gray);
        }
        
        /* Stats */
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
        }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            margin-right: 15px;
            color: white;
        }
        
        .icon-primary {
            background: var(--primary);
        }
        
        .icon-success {
            background: var(--success);
        }
        
        .icon-warning {
            background: var(--warning);
        }
        
        .icon-accent {
            background: var(--accent);
        }
        
        .stat-details h3 {
            font-size: 24px;
            margin-bottom: 5px;
            color: var(--secondary);
        }
        
        .stat-details p {
            color: var(--gray);
            font-size: 14px;
        }
        
        /* Sections */
        .section {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }
        
        .section-header h2 {
            font-size: 18px;
            color: var(--secondary);
        }
        
        .view-all {
            color: var(--primary);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
        }
        
        /* Model Items */
        .model-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .model-item {
            background: var(--light);
            border-radius: 10px;
            padding: 20px;
            border-left: 4px solid var(--primary);
            transition: transform 0.2s;
        }
        
        .model-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .model-item.active {
            border-left-color: var(--success);
        }
        
        .model-item.inactive {
            border-left-color: var(--gray);
        }
        
        .model-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .model-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--secondary);
        }
        
        .model-status {
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .status-active {
            background: rgba(46, 204, 113, 0.2);
            color: var(--success);
        }
        
        .status-inactive {
            background: rgba(127, 140, 141, 0.2);
            color: var(--gray);
        }
        
        .model-meta {
            margin-bottom: 15px;
        }
        
        .model-description {
            color: var(--gray);
            margin-bottom: 15px;
            line-height: 1.5;
        }
        
        .model-details {
            display: flex;
            justify-content: space-between;
            margin-bottom: 15px;
        }
        
        .model-accuracy {
            font-weight: 600;
            color: var(--success);
        }
        
        .model-date {
            color: var(--gray);
            font-size: 14px;
        }
        
        .model-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn {
            padding: 8px 15px;
            border: none;
            border-radius: 5px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
        }
        
        .btn-success {
            background: var(--success);
            color: white;
        }
        
        .btn-success:hover {
            opacity: 0.9;
        }
        
        .btn-danger {
            background: var(--accent);
            color: white;
        }
        
        .btn-danger:hover {
            opacity: 0.9;
        }
        
        /* Table */
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .data-table th, .data-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        .data-table th {
            background-color: var(--light);
            font-weight: 600;
            color: var(--secondary);
        }
        
        .data-table tr:hover {
            background-color: #f9f9f9;
        }
        
        /* Responsive */
        @media (max-width: 992px) {
            .sidebar {
                width: 70px;
                overflow: visible;
            }
            
            .logo h1, .user-details, .nav-links span {
                display: none;
            }
            
            .user-info {
                justify-content: center;
            }
            
            .user-avatar {
                margin-right: 0;
            }
            
            .nav-links a {
                justify-content: center;
            }
            
            .nav-links i {
                margin-right: 0;
            }
            
            .main-content {
                margin-left: 70px;
            }
        }
        
        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .search-box {
                margin-top: 15px;
                width: 100%;
            }
            
            .stats-cards {
                grid-template-columns: 1fr;
            }
            
            .model-list {
                grid-template-columns: 1fr;
            }
            
            .data-table {
                display: block;
                overflow-x: auto;
            }
            
            .model-actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="logo">
            <h1>Оценка знаний AI</h1>
        </div>
        
         <div class="user-info">
    <div class="user-avatar">
        <?php 
        $avatarPath = !empty($user['avatar']) ? $user['avatar'] : '1.jpg';
        
        // Проверяем, существует ли файл
        if (file_exists($avatarPath)) {
            echo '<img src="' . $avatarPath . '" alt="Аватар" style="width: 50px; height: 50px; border-radius: 50%; object-fit: cover;">';
        } else {
            // Если файл не существует, показываем первую букву имени
            $firstName = $user['full_name'];
            // Преобразуем в UTF-8 на случай проблем с кодировкой
            if (function_exists('mb_convert_encoding')) {
                $firstName = mb_convert_encoding($firstName, 'UTF-8', 'auto');
            }
            $firstLetter = mb_substr($firstName, 0, 1, 'UTF-8');
            echo htmlspecialchars(strtoupper($firstLetter), ENT_QUOTES, 'UTF-8');
        }
        ?>
    </div>
    <div class="user-details">
        <h3>Привет, <?php 
            $nameParts = explode(' ', $user['full_name']);
            $firstName = $nameParts[1];
            if (function_exists('mb_convert_encoding')) {
                $firstName = mb_convert_encoding($firstName, 'UTF-8', 'auto');
            }
            echo htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8'); 
        ?></h3>
        <p><?php echo htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8'); ?></p>
        <span class="role-badge role-<?php echo $user['role']; ?>">
            <?php 
            if ($user['role'] == 'admin') echo 'Администратор';
            else if ($user['role'] == 'teacher') echo 'Преподаватель';
            else echo 'Студент';
            ?>
        </span>
    </div>
</div>
        
        <ul class="nav-links">
            <li><a href="dashboard.php"><i class="fas fa-th-large"></i> <span>Главная</span></a></li>
            <li><a href="tests.php"><i class="fas fa-file-alt"></i> <span>Тесты</span></a></li>
            <li><a href="results.php"><i class="fas fa-chart-line"></i> <span>Результаты</span></a></li>
            <?php if ($user['role'] == 'teacher' || $user['role'] == 'admin'): ?>
            <li><a href="students.php"><i class="fas fa-users"></i> <span>Студенты</span></a></li>
            <?php endif; ?>
            <li><a href="ml_models.php" class="active"><i class="fas fa-robot"></i> <span>ML модели</span></a></li>
            <li><a href="settings.php"><i class="fas fa-cog"></i> <span>Настройки</span></a></li>
            <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> <span>Выход</span></a></li>
        </ul>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
        <div class="header">
            <div class="welcome">
                <h2><i class="fas fa-robot"></i> Машинное обучение</h2>
                <p>Управление моделями для интеллектуальной оценки знаний</p>
            </div>
            
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" placeholder="Поиск моделей..." id="searchInput">
            </div>
        </div>
        
        <!-- Статистика -->
        <div class="stats-cards">
            <div class="stat-card">
                <div class="stat-icon icon-primary">
                    <i class="fas fa-robot"></i>
                </div>
                <div class="stat-details">
                    <h3><?php echo $stats['total_models']; ?></h3>
                    <p>Всего моделей</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon icon-success">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-details">
                    <h3><?php echo $stats['active_models']; ?></h3>
                    <p>Активных моделей</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon icon-warning">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="stat-details">
                    <h3><?php echo round($stats['avg_accuracy'] * 100, 1); ?>%</h3>
                    <p>Средняя точность</p>
                </div>
            </div>
        </div>
        
        <!-- Список моделей -->
        <div class="section">
            <div class="section-header">
                <h2>Доступные модели</h2>
                <?php if ($user['role'] == 'admin'): ?>
                <a href="model_add.php" class="view-all">Добавить модель</a>
                <?php endif; ?>
            </div>
            
            <?php if (count($models) > 0): ?>
                <div class="model-list" id="modelsList">
                    <?php foreach ($models as $model): ?>
                        <div class="model-item <?php echo $model['is_active'] ? 'active' : 'inactive'; ?>" data-name="<?php echo strtolower($model['name']); ?>">
                            <div class="model-header">
                                <div class="model-title"><?php echo $model['name']; ?></div>
                                <div class="model-status status-<?php echo $model['is_active'] ? 'active' : 'inactive'; ?>">
                                    <?php echo $model['is_active'] ? 'Активна' : 'Неактивна'; ?>
                                </div>
                            </div>
                            
                            <?php if (!empty($model['description'])): ?>
                            <div class="model-description">
                                <?php echo $model['description']; ?>
                            </div>
                            <?php endif; ?>
                            
                            <div class="model-details">
                                <div class="model-accuracy">
                                    Точность: <?php echo round($model['accuracy'] * 100, 1); ?>%
                                </div>
                                <div class="model-date">
                                    Добавлена: <?php echo date('d.m.Y', strtotime($model['created_at'])); ?>
                                </div>
                            </div>
                            
                            <div class="model-actions">
                                <a href="model_details.php?id=<?php echo $model['id']; ?>" class="btn btn-primary">Подробнее</a>
                                <?php if ($user['role'] == 'admin'): ?>
                                    <?php if ($model['is_active']): ?>
                                        <a href="model_deactivate.php?id=<?php echo $model['id']; ?>" class="btn btn-danger">Деактивировать</a>
                                    <?php else: ?>
                                        <a href="model_activate.php?id=<?php echo $model['id']; ?>" class="btn btn-success">Активировать</a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p>Нет доступных ML моделей.</p>
            <?php endif; ?>
        </div>
        
        <!-- Детальная таблица (для администраторов) -->
        <?php if ($user['role'] == 'admin'): ?>
        <div class="section">
            <div class="section-header">
                <h2>Управление моделями</h2>
            </div>
            
            <?php if (count($models) > 0): ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Название</th>
                            <th>Статус</th>
                            <th>Точность</th>
                            <th>Путь</th>
                            <th>Дата создания</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($models as $model): ?>
                            <tr>
                                <td><?php echo $model['name']; ?></td>
                                <td>
                                    <span class="model-status status-<?php echo $model['is_active'] ? 'active' : 'inactive'; ?>">
                                        <?php echo $model['is_active'] ? 'Активна' : 'Неактивна'; ?>
                                    </span>
                                </td>
                                <td><?php echo round($model['accuracy'] * 100, 1); ?>%</td>
                                <td><?php echo $model['path']; ?></td>
                                <td><?php echo date('d.m.Y H:i', strtotime($model['created_at'])); ?></td>
                                <td>
                                    <a href="model_edit.php?id=<?php echo $model['id']; ?>" class="btn btn-primary">Редактировать</a>
                                    <a href="model_delete.php?id=<?php echo $model['id']; ?>" class="btn btn-danger" onclick="return confirm('Вы уверены?')">Удалить</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>Нет доступных ML моделей.</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <script>
        // Поиск моделей
        document.getElementById('searchInput').addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const modelItems = document.querySelectorAll('.model-item');
            
            modelItems.forEach(item => {
                const modelName = item.getAttribute('data-name');
                if (modelName.includes(searchTerm)) {
                    item.style.display = 'block';
                } else {
                    item.style.display = 'none';
                }
            });
        });
    </script>
</body>
</html>