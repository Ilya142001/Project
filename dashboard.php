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

// Получаем статистику в зависимости от роли пользователя
if ($user['role'] == 'student') {
    // Статистика для студента
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total_tests, 
               COALESCE(SUM(score), 0) as total_score,
               COALESCE(SUM(total_points), 0) as total_points
        FROM test_results 
        WHERE user_id = ?
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $stats = $stmt->fetch();
    
    // Последние пройденные тесты
    $stmt = $pdo->prepare("
        SELECT t.title, tr.score, tr.total_points, tr.completed_at 
        FROM test_results tr
        JOIN tests t ON tr.test_id = t.id
        WHERE tr.user_id = ?
        ORDER BY tr.completed_at DESC
        LIMIT 5
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $recent_tests = $stmt->fetchAll();
    
    // Доступные тесты
    $stmt = $pdo->prepare("
        SELECT t.*, u.full_name as creator_name
        FROM tests t
        JOIN users u ON t.created_by = u.id
        WHERE t.id NOT IN (
            SELECT test_id FROM test_results WHERE user_id = ?
        )
        ORDER BY t.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $available_tests = $stmt->fetchAll();
    
} else if ($user['role'] == 'teacher' || $user['role'] == 'admin') {
    // Статистика для преподавателя/администратора
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total_tests_created,
               (SELECT COUNT(*) FROM test_results WHERE test_id IN (
                   SELECT id FROM tests WHERE created_by = ?
               )) as total_tests_taken,
               (SELECT COUNT(DISTINCT user_id) FROM test_results WHERE test_id IN (
                   SELECT id FROM tests WHERE created_by = ?
               )) as total_students
        FROM tests
        WHERE created_by = ?
    ");
    $stmt->execute([$_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']]);
    $stats = $stmt->fetch();
    
    // Последние созданные тесты
    $stmt = $pdo->prepare("
        SELECT t.*, COUNT(tr.id) as attempts
        FROM tests t
        LEFT JOIN test_results tr ON t.id = tr.test_id
        WHERE t.created_by = ?
        GROUP BY t.id
        ORDER BY t.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $recent_tests = $stmt->fetchAll();
    
    // Активные ML-модели
    $stmt = $pdo->prepare("
        SELECT * FROM ml_models 
        WHERE is_active = TRUE
        ORDER BY created_at DESC
        LIMIT 3
    ");
    $stmt->execute();
    $ml_models = $stmt->fetchAll();
    
    // Последняя активность студентов
    $stmt = $pdo->prepare("
        SELECT u.full_name, t.title, tr.score, tr.total_points, tr.completed_at
        FROM test_results tr
        JOIN users u ON tr.user_id = u.id
        JOIN tests t ON tr.test_id = t.id
        WHERE t.created_by = ?
        ORDER BY tr.completed_at DESC
        LIMIT 5
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $student_activity = $stmt->fetchAll();
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Система интеллектуальной оценки знаний</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
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
        
        /* Stats Cards */
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
        
        /* Test Items */
        .test-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 15px;
        }
        
        .test-item {
            padding: 15px;
            background: var(--light);
            border-radius: 8px;
            transition: transform 0.2s;
        }
        
        .test-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .test-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 10px;
            color: var(--secondary);
        }
        
        .test-meta {
            display: flex;
            justify-content: space-between;
            font-size: 13px;
            color: var(--gray);
            margin-bottom: 10px;
        }
        
        .test-score {
            font-weight: 600;
            color: var(--success);
        }
        
        .test-actions {
            display: flex;
            justify-content: flex-end;
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
        
        /* ML Models */
        .model-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 15px;
        }
        
        .model-item {
            padding: 15px;
            background: var(--light);
            border-radius: 8px;
            border-left: 4px solid var(--primary);
        }
        
        .model-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 10px;
            color: var(--secondary);
        }
        
        .model-meta {
            font-size: 13px;
            color: var(--gray);
            margin-bottom: 10px;
        }
        
        .model-accuracy {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
            background: rgba(46, 204, 113, 0.2);
            color: var(--success);
        }
        
        /* Activity Table */
        .activity-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .activity-table th, .activity-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        .activity-table th {
            background-color: var(--light);
            font-weight: 600;
            color: var(--secondary);
        }
        
        .activity-table tr:hover {
            background-color: #f9f9f9;
        }
        .user-avatar img {
    width: 100%;
    height: 100%;
    border-radius: 50%;
    object-fit: cover;
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
            
            .test-list, .model-list {
                grid-template-columns: 1fr;
            }
            
            .activity-table {
                display: block;
                overflow-x: auto;
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
            <li><a href="dashboard.php" class="active"><i class="fas fa-th-large"></i> <span>Главная</span></a></li>
            <li><a href="tests.php"><i class="fas fa-file-alt"></i> <span>Тесты</span></a></li>
            <li><a href="results.php"><i class="fas fa-chart-line"></i> <span>Результаты</span></a></li>
            <?php if ($user['role'] == 'teacher' || $user['role'] == 'admin'): ?>
            <li><a href="students.php"><i class="fas fa-users"></i> <span>Студенты</span></a></li>
            <?php endif; ?>
            <li><a href="ml_models.php"><i class="fas fa-robot"></i> <span>ML модели</span></a></li>
            <li><a href="settings.php"><i class="fas fa-cog"></i> <span>Настройки</span></a></li>
            <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> <span>Выход</span></a></li>
        </ul>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
        <div class="header">
            <div class="welcome">
                <h2>Система интеллектуальной оценки знаний</h2>
                <p>Добро пожаловать, <?php echo $user['full_name']; ?>. Вот ваша статистика.</p>
            </div>
            
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" placeholder="Поиск...">
            </div>
        </div>
        
        <div class="stats-cards">
            <?php if ($user['role'] == 'student'): ?>
                <div class="stat-card">
                    <div class="stat-icon icon-primary">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div class="stat-details">
                        <h3><?php echo $stats['total_tests']; ?></h3>
                        <p>Пройденных тестов</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon icon-success">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-details">
                        <h3><?php echo $stats['total_score'] . '/' . $stats['total_points']; ?></h3>
                        <p>Общий балл</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon icon-warning">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-details">
                        <h3><?php echo $stats['total_points'] > 0 ? round(($stats['total_score'] / $stats['total_points']) * 100, 1) : 0; ?>%</h3>
                        <p>Средний результат</p>
                    </div>
                </div>
                
            <?php else: ?>
                <div class="stat-card">
                    <div class="stat-icon icon-primary">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div class="stat-details">
                        <h3><?php echo $stats['total_tests_created']; ?></h3>
                        <p>Созданных тестов</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon icon-success">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-details">
                        <h3><?php echo $stats['total_tests_taken']; ?></h3>
                        <p>Пройденных попыток</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon icon-warning">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-details">
                        <h3><?php echo $stats['total_students']; ?></h3>
                        <p>Уникальных студентов</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon icon-accent">
                        <i class="fas fa-robot"></i>
                    </div>
                    <div class="stat-details">
                        <h3><?php echo count($ml_models); ?></h3>
                        <p>Активных ML моделей</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <?php if ($user['role'] == 'student'): ?>
            <!-- Секция для студентов -->
            <div class="section">
                <div class="section-header">
                    <h2>Доступные тесты</h2>
                    <a href="tests.php" class="view-all">Все тесты</a>
                </div>
                
                <div class="test-list">
                    <?php if (count($available_tests) > 0): ?>
                        <?php foreach ($available_tests as $test): ?>
                            <div class="test-item">
                                <div class="test-title"><?php echo $test['title']; ?></div>
                                <div class="test-meta">
                                    <span>Автор: <?php echo $test['creator_name']; ?></span>
                                </div>
                                <div class="test-actions">
                                    <a href="take_test.php?id=<?php echo $test['id']; ?>" class="btn btn-primary">Пройти тест</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p>Нет доступных тестов для прохождения.</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="section">
                <div class="section-header">
                    <h2>Недавно пройденные тесты</h2>
                    <a href="results.php" class="view-all">Вся история</a>
                </div>
                
                <div class="test-list">
                    <?php if (count($recent_tests) > 0): ?>
                        <?php foreach ($recent_tests as $test): ?>
                            <div class="test-item">
                                <div class="test-title"><?php echo $test['title']; ?></div>
                                <div class="test-meta">
                                    <span>Дата: <?php echo date('d.m.Y', strtotime($test['completed_at'])); ?></span>
                                    <span class="test-score"><?php echo $test['score']; ?>/<?php echo $test['total_points']; ?></span>
                                </div>
                                <div class="test-actions">
                                    <a href="test_result.php?id=<?php echo $test['id']; ?>" class="btn btn-success">Подробнее</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p>Вы еще не прошли ни одного теста.</p>
                    <?php endif; ?>
                </div>
            </div>
            
        <?php else: ?>
            <!-- Секция для преподавателей/администраторов -->
            <div class="section">
                <div class="section-header">
                    <h2>Мои тесты</h2>
                    <a href="tests.php" class="view-all">Все тесты</a>
                </div>
                
                <div class="test-list">
                    <?php if (count($recent_tests) > 0): ?>
                        <?php foreach ($recent_tests as $test): ?>
                            <div class="test-item">
                                <div class="test-title"><?php echo $test['title']; ?></div>
                                <div class="test-meta">
                                    <span>Попыток: <?php echo $test['attempts']; ?></span>
                                    <span>Дата: <?php echo date('d.m.Y', strtotime($test['created_at'])); ?></span>
                                </div>
                                <div class="test-actions">
                                    <a href="test_edit.php?id=<?php echo $test['id']; ?>" class="btn btn-primary">Управление</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p>Вы еще не создали ни одного теста.</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="section">
                <div class="section-header">
                    <h2>Активные ML модели</h2>
                    <a href="ml_models.php" class="view-all">Все модели</a>
                </div>
                
                <div class="model-list">
                    <?php if (count($ml_models) > 0): ?>
                        <?php foreach ($ml_models as $model): ?>
                            <div class="model-item">
                                <div class="model-title"><?php echo $model['name']; ?></div>
                                <div class="model-meta">
                                    <?php echo $model['description']; ?>
                                </div>
                                <div class="model-meta">
                                    Точность: <span class="model-accuracy"><?php echo round($model['accuracy'] * 100, 1); ?>%</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p>Нет активных ML моделей.</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="section">
                <div class="section-header">
                    <h2>Последняя активность студентов</h2>
                    <a href="students.php" class="view-all">Все студенты</a>
                </div>
                
                <table class="activity-table">
                    <thead>
                        <tr>
                            <th>Студент</th>
                            <th>Тест</th>
                            <th>Результат</th>
                            <th>Дата</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($student_activity) > 0): ?>
                            <?php foreach ($student_activity as $activity): ?>
                                <tr>
                                    <td><?php echo $activity['full_name']; ?></td>
                                    <td><?php echo $activity['title']; ?></td>
                                    <td><?php echo $activity['score']; ?>/<?php echo $activity['total_points']; ?></td>
                                    <td><?php echo date('d.m.Y H:i', strtotime($activity['completed_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" style="text-align: center;">Нет данных об активности</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Простая интерактивность
        document.querySelectorAll('.test-item').forEach(item => {
            item.addEventListener('click', function(e) {
                if (!e.target.classList.contains('btn')) {
                    this.querySelector('a.btn').click();
                }
            });
        });
    </script>
</body>
</html>