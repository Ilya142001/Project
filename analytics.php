<?php
include 'config.php';
session_start();

// Проверяем, авторизован ли пользователь
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Получаем информацию о пользователе
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

// Получаем статистику в зависимости от роли пользователя
if ($user['role'] == 'student') {
    // Основная статистика для студента
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_tests, 
            COALESCE(SUM(score), 0) as total_score,
            COALESCE(SUM(total_points), 0) as total_points,
            AVG(percentage) as avg_percentage,
            COUNT(CASE WHEN passed = 1 THEN 1 END) as passed_tests,
            MAX(percentage) as best_score,
            MIN(percentage) as worst_score,
            SUM(time_spent) as total_time_spent
        FROM test_results 
        WHERE user_id = ?
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $stats = $stmt->fetch();
    
    // Статистика по предметам
    $stmt = $pdo->prepare("
        SELECT 
            t.subject,
            COUNT(*) as test_count,
            AVG(tr.percentage) as avg_score,
            MAX(tr.percentage) as best_score,
            SUM(tr.time_spent) as total_time
        FROM test_results tr
        JOIN tests t ON tr.test_id = t.id
        WHERE tr.user_id = ?
        GROUP BY t.subject
        ORDER BY avg_score DESC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $subject_stats = $stmt->fetchAll();
    
    // Еженедельная активность
    $stmt = $pdo->prepare("
        SELECT 
            YEARWEEK(completed_at) as week,
            COUNT(*) as tests_taken,
            AVG(percentage) as avg_score,
            SUM(time_spent) as total_time
        FROM test_results 
        WHERE user_id = ? AND completed_at >= DATE_SUB(NOW(), INTERVAL 12 WEEK)
        GROUP BY YEARWEEK(completed_at)
        ORDER BY week DESC
        LIMIT 12
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $weekly_activity = $stmt->fetchAll();
    
    // Прогресс по месяцам
    $stmt = $pdo->prepare("
        SELECT 
            DATE_FORMAT(completed_at, '%Y-%m') as month,
            COUNT(*) as tests_taken,
            AVG(percentage) as avg_score
        FROM test_results 
        WHERE user_id = ? AND completed_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(completed_at, '%Y-%m')
        ORDER BY month DESC
        LIMIT 6
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $monthly_progress = $stmt->fetchAll();
    
    // Статистика по сложности
    $stmt = $pdo->prepare("
        SELECT 
            t.difficulty,
            COUNT(*) as test_count,
            AVG(tr.percentage) as avg_score,
            COUNT(CASE WHEN tr.passed = 1 THEN 1 END) as passed_count
        FROM test_results tr
        JOIN tests t ON tr.test_id = t.id
        WHERE tr.user_id = ?
        GROUP BY t.difficulty
        ORDER BY t.difficulty
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $difficulty_stats = $stmt->fetchAll();
    
    // Время суток активности
    $stmt = $pdo->prepare("
        SELECT 
            HOUR(completed_at) as hour,
            COUNT(*) as tests_taken,
            AVG(percentage) as avg_score
        FROM test_results 
        WHERE user_id = ?
        GROUP BY HOUR(completed_at)
        ORDER BY hour
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $hourly_activity = $stmt->fetchAll();
    
    // Последние пройденные тесты
    $stmt = $pdo->prepare("
        SELECT t.title, tr.score, tr.total_points, tr.percentage, tr.passed, tr.completed_at,
               u.full_name as teacher_name, t.subject, t.difficulty, tr.time_spent
        FROM test_results tr
        JOIN tests t ON tr.test_id = t.id
        JOIN users u ON t.created_by = u.id
        WHERE tr.user_id = ?
        ORDER BY tr.completed_at DESC
        LIMIT 10
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $recent_tests = $stmt->fetchAll();
    
} else if ($user['role'] == 'teacher' || $user['role'] == 'admin') {
    // Расширенная статистика для преподавателя/администратора
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_tests_created,
            COUNT(CASE WHEN is_active = 1 THEN 1 END) as active_tests,
            (SELECT COUNT(*) FROM test_results WHERE test_id IN (
                SELECT id FROM tests WHERE created_by = ?
            )) as total_tests_taken,
            (SELECT COUNT(DISTINCT user_id) FROM test_results WHERE test_id IN (
                SELECT id FROM tests WHERE created_by = ?
            )) as total_students,
            (SELECT AVG(percentage) FROM test_results WHERE test_id IN (
                SELECT id FROM tests WHERE created_by = ?
            )) as avg_success_rate,
            (SELECT COUNT(*) FROM questions WHERE test_id IN (
                SELECT id FROM tests WHERE created_by = ?
            )) as total_questions,
            (SELECT COUNT(*) FROM test_results WHERE test_id IN (
                SELECT id FROM tests WHERE created_by = ?
            ) AND needs_review = 1) as pending_reviews
    ");
    $stmt->execute([$_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']]);
    $stats = $stmt->fetch();
    
    // Статистика по группам
    $stmt = $pdo->prepare("
        SELECT 
            u.group_name,
            COUNT(DISTINCT u.id) as student_count,
            COUNT(tr.id) as tests_taken,
            AVG(tr.percentage) as avg_score,
            COUNT(CASE WHEN tr.passed = 1 THEN 1 END) as passed_tests
        FROM test_results tr
        JOIN users u ON tr.user_id = u.id
        JOIN tests t ON tr.test_id = t.id
        WHERE t.created_by = ? AND u.group_name IS NOT NULL
        GROUP BY u.group_name
        ORDER BY avg_score DESC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $group_stats = $stmt->fetchAll();
    
    // Статистика по предметам
    $stmt = $pdo->prepare("
        SELECT 
            t.subject,
            COUNT(DISTINCT t.id) as test_count,
            COUNT(tr.id) as attempts,
            COUNT(DISTINCT tr.user_id) as unique_students,
            AVG(tr.percentage) as avg_score,
            MAX(tr.percentage) as best_score,
            MIN(tr.percentage) as worst_score
        FROM tests t
        LEFT JOIN test_results tr ON t.id = tr.test_id
        WHERE t.created_by = ?
        GROUP BY t.subject
        ORDER BY attempts DESC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $subject_stats = $stmt->fetchAll();
    
    // Еженедельная активность
    $stmt = $pdo->prepare("
        SELECT 
            DATE(tr.completed_at) as date,
            COUNT(*) as tests_taken,
            COUNT(DISTINCT tr.user_id) as active_students,
            AVG(tr.percentage) as avg_score
        FROM test_results tr
        JOIN tests t ON tr.test_id = t.id
        WHERE t.created_by = ? AND tr.completed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY DATE(tr.completed_at)
        ORDER BY date DESC
        LIMIT 30
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $daily_activity = $stmt->fetchAll();
    
    // Рейтинг студентов
    $stmt = $pdo->prepare("
        SELECT 
            u.full_name,
            u.group_name,
            COUNT(tr.id) as tests_taken,
            AVG(tr.percentage) as avg_score,
            SUM(tr.score) as total_points,
            MAX(tr.percentage) as best_score
        FROM test_results tr
        JOIN users u ON tr.user_id = u.id
        JOIN tests t ON tr.test_id = t.id
        WHERE t.created_by = ?
        GROUP BY u.id
        HAVING tests_taken >= 3
        ORDER BY avg_score DESC
        LIMIT 15
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $top_students = $stmt->fetchAll();
    
    // Статистика по сложности тестов
    $stmt = $pdo->prepare("
        SELECT 
            t.difficulty,
            COUNT(DISTINCT t.id) as test_count,
            COUNT(tr.id) as attempts,
            AVG(tr.percentage) as avg_score,
            COUNT(CASE WHEN tr.passed = 1 THEN 1 END) as passed_attempts
        FROM tests t
        LEFT JOIN test_results tr ON t.id = tr.test_id
        WHERE t.created_by = ?
        GROUP BY t.difficulty
        ORDER BY t.difficulty
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $difficulty_stats = $stmt->fetchAll();
}

// Получаем уведомления
$stmt = $pdo->prepare("
    SELECT * FROM notifications 
    WHERE user_id = ? AND (expires_at IS NULL OR expires_at > NOW())
    ORDER BY created_at DESC
    LIMIT 10
");
$stmt->execute([$_SESSION['user_id']]);
$notifications = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Аналитика - Система интеллектуальной оценки знаний</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            --danger: #e74c3c;
            --info: #17a2b8;
            --sidebar-width: 280px;
        }
        
        body {
            background-color: #f0f2f5;
            color: #333;
            line-height: 1.6;
            display: flex;
            min-height: 100vh;
        }

        /* ===== СТИЛИ САЙДБАРА ===== */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            height: 100vh;
            width: var(--sidebar-width);
            background: linear-gradient(135deg, var(--secondary) 0%, #34495e 100%);
            color: white;
            overflow-y: auto;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            box-shadow: 2px 0 15px rgba(0, 0, 0, 0.1);
        }

        .logo {
            padding: 25px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            text-align: center;
        }

        .logo h1 {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .system-status {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-size: 12px;
            color: rgba(255, 255, 255, 0.7);
        }

        .status-indicator {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--success);
        }

        .user-info {
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .user-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 18px;
            color: white;
        }

        .user-details h3 {
            font-size: 16px;
            margin-bottom: 4px;
        }

        .user-details p {
            font-size: 12px;
            color: rgba(255, 255, 255, 0.7);
        }

        .role-badge {
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .role-admin { background: var(--accent); }
        .role-teacher { background: var(--warning); }
        .role-student { background: var(--primary); }

        .nav-links {
            list-style: none;
            flex: 1;
            padding: 15px 0;
        }

        .nav-section {
            padding: 0 20px 10px;
            margin-bottom: 10px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .section-label {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: rgba(255, 255, 255, 0.5);
            margin-bottom: 10px;
        }

        .nav-links li a {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            transition: all 0.3s;
            border-left: 3px solid transparent;
        }

        .nav-links li a:hover,
        .nav-links li a.active {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border-left-color: var(--primary);
        }

        .nav-links li a i {
            width: 20px;
            margin-right: 12px;
            font-size: 14px;
        }

        .sidebar-footer {
            padding: 15px 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            margin-top: auto;
        }

        /* Основной контент */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            padding: 20px;
            transition: margin-left 0.3s;
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
            font-size: 28px;
            color: var(--secondary);
            margin-bottom: 8px;
            font-weight: 700;
        }
        
        .welcome p {
            color: var(--gray);
            font-size: 16px;
        }
        
        .header-actions {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        
        .notification-bell {
            position: relative;
            background: white;
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            transition: all 0.3s;
        }
        
        .notification-bell:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
        }
        
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: var(--accent);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }
        
        .search-box {
            display: flex;
            align-items: center;
            background: white;
            padding: 12px 20px;
            border-radius: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            min-width: 300px;
        }
        
        .search-box input {
            border: none;
            outline: none;
            padding: 5px 10px;
            font-size: 15px;
            flex: 1;
            background: transparent;
        }
        
        /* Stats Cards */
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            display: flex;
            align-items: center;
            transition: transform 0.3s, box-shadow 0.3s;
            border-left: 4px solid var(--primary);
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.12);
        }
        
        .stat-card.success { border-left-color: var(--success); }
        .stat-card.warning { border-left-color: var(--warning); }
        .stat-card.danger { border-left-color: var(--danger); }
        .stat-card.info { border-left-color: var(--info); }
        .stat-card.primary { border-left-color: var(--primary); }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-right: 20px;
            color: white;
        }
        
        .icon-primary { background: linear-gradient(135deg, var(--primary), #4a69bd); }
        .icon-success { background: linear-gradient(135deg, var(--success), #1dd1a1); }
        .icon-warning { background: linear-gradient(135deg, var(--warning), #f6b93b); }
        .icon-danger { background: linear-gradient(135deg, var(--danger), #e55039); }
        .icon-info { background: linear-gradient(135deg, var(--info), #48dbfb); }
        
        .stat-details h3 {
            font-size: 32px;
            margin-bottom: 5px;
            color: var(--secondary);
            font-weight: 700;
        }
        
        .stat-details p {
            color: var(--gray);
            font-size: 15px;
            margin-bottom: 8px;
        }
        
        .stat-trend {
            font-size: 14px;
            font-weight: 600;
        }
        
        .trend-up { color: var(--success); }
        .trend-down { color: var(--danger); }

        /* Sections */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 25px;
            margin-bottom: 25px;
        }

        .section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            margin-bottom: 25px;
        }
        
        .section-full {
            grid-column: 1 / -1;
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f8f9fa;
        }
        
        .section-header h2 {
            font-size: 20px;
            color: var(--secondary);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .view-all {
            color: var(--primary);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        /* Charts */
        .chart-container {
            height: 300px;
            position: relative;
        }

        .charts-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
            margin-bottom: 25px;
        }

        /* Tables */
        .stats-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        
        .stats-table th, .stats-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .stats-table th {
            background-color: var(--light);
            font-weight: 600;
            color: var(--secondary);
            font-size: 14px;
        }
        
        .stats-table tr:hover {
            background-color: #f8f9fa;
        }

        .score-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }

        .score-excellent { background: rgba(46, 204, 113, 0.2); color: var(--success); }
        .score-good { background: rgba(243, 156, 18, 0.2); color: var(--warning); }
        .score-poor { background: rgba(231, 76, 60, 0.2); color: var(--danger); }

        /* Progress bars */
        .progress-bar {
            width: 100%;
            height: 8px;
            background: #e0e0e0;
            border-radius: 4px;
            overflow: hidden;
            margin: 5px 0;
        }

        .progress-fill {
            height: 100%;
            background: var(--primary);
            transition: width 0.3s ease;
        }

        /* Адаптивность */
        @media (max-width: 1200px) {
            .dashboard-grid,
            .charts-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-cards {
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            }
        }
        
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .main-content {
                margin-left: 0;
                padding: 15px;
            }
            
            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .search-box {
                min-width: 100%;
            }
            
            .stats-cards {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Сайдбар меню -->
    <div class="sidebar">
        <div class="logo">
            <h1><i class="fas fa-graduation-cap"></i> EduAnalytics</h1>
            <div class="system-status">
                <div class="status-indicator online"></div>
                <span>Система активна</span>
            </div>
        </div>
        
        <div class="user-info">
            <div class="user-avatar">
                <?php 
                $nameParts = explode(' ', $user['full_name']);
                $firstLetter = $nameParts[1] ?? $user['full_name'][0];
                echo strtoupper($firstLetter[0]); 
                ?>
            </div>
            <div class="user-details">
                <h3><?php echo htmlspecialchars($user['full_name']); ?></h3>
                <p><?php echo htmlspecialchars($user['email']); ?></p>
                <span class="role-badge role-<?php echo $user['role']; ?>">
                    <?php echo $user['role']; ?>
                </span>
            </div>
        </div>
        
        <ul class="nav-links">
            <div class="nav-section">
                <div class="section-label">Основное</div>
                <li><a href="dashboard.php"><i class="fas fa-home"></i> Главная</a></li>
                <li><a href="analytics.php" class="active"><i class="fas fa-chart-bar"></i> Аналитика</a></li>
                <li><a href="tests.php"><i class="fas fa-file-alt"></i> Тесты</a></li>
            </div>
            
            <div class="nav-section">
                <div class="section-label">Учебный процесс</div>
                <?php if ($user['role'] == 'student'): ?>
                    <li><a href="my_results.php"><i class="fas fa-list-alt"></i> Мои результаты</a></li>
                    <li><a href="progress.php"><i class="fas fa-chart-line"></i> Прогресс</a></li>
                <?php else: ?>
                    <li><a href="create_test.php"><i class="fas fa-plus-circle"></i> Создать тест</a></li>
                    <li><a href="students.php"><i class="fas fa-users"></i> Студенты</a></li>
                    <li><a href="grading.php"><i class="fas fa-check-double"></i> Проверка работ</a></li>
                <?php endif; ?>
            </div>
            
            <div class="nav-section">
                <div class="section-label">Система</div>
                <li><a href="profile.php"><i class="fas fa-user-cog"></i> Профиль</a></li>
                <li><a href="settings.php"><i class="fas fa-cog"></i> Настройки</a></li>
                <li><a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Выход</a></li>
            </div>
        </ul>
        
        <div class="sidebar-footer">
            <div class="system-info">
                <div class="info-item">
                    <i class="fas fa-database"></i>
                    <span>База данных: Online</span>
                </div>
                <div class="info-item">
                    <i class="fas fa-robot"></i>
                    <span>ML модели: Активны</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Основной контент -->
    <div class="main-content">
        <div class="header">
            <div class="welcome">
                <h2>Расширенная аналитика</h2>
                <?php
                $nameParts = explode(' ', $user['full_name']);
                $firstName = $nameParts[1] ?? $user['full_name'];
                echo "<p>Детальная статистика и метрики эффективности, $firstName</p>";
                ?>
            </div>
            
            <div class="header-actions">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" placeholder="Поиск по аналитике...">
                </div>
                <div class="notification-bell">
                    <i class="fas fa-bell"></i>
                    <?php if (count($notifications) > 0): ?>
                        <span class="notification-badge"><?php echo count($notifications); ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Основные метрики -->
        <div class="stats-cards">
            <?php if ($user['role'] == 'student'): ?>
                <!-- Статистика для студента -->
                <div class="stat-card success">
                    <div class="stat-icon icon-success">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-details">
                        <h3><?php echo $stats['passed_tests']; ?></h3>
                        <p>Успешно пройдено тестов</p>
                        <div class="stat-trend trend-up">
                            <i class="fas fa-arrow-up"></i>
                            <?php echo $stats['total_tests'] > 0 ? round(($stats['passed_tests'] / $stats['total_tests']) * 100, 1) : 0; ?>% успеха
                        </div>
                    </div>
                </div>
                
                <div class="stat-card info">
                    <div class="stat-icon icon-info">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-details">
                        <h3><?php echo round($stats['avg_percentage'], 1); ?>%</h3>
                        <p>Средний результат</p>
                        <div class="stat-trend trend-up">
                            <i class="fas fa-trophy"></i>
                            Лучший: <?php echo round($stats['best_score'], 1); ?>%
                        </div>
                    </div>
                </div>
                
                <div class="stat-card primary">
                    <div class="stat-icon icon-primary">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-details">
                        <h3><?php echo round($stats['total_time_spent'] / 60, 1); ?></h3>
                        <p>Часов обучения</p>
                        <div class="stat-trend trend-up">
                            <i class="fas fa-brain"></i>
                            <?php echo $stats['total_tests']; ?> тестов
                        </div>
                    </div>
                </div>
                
                <div class="stat-card warning">
                    <div class="stat-icon icon-warning">
                        <i class="fas fa-bullseye"></i>
                    </div>
                    <div class="stat-details">
                        <h3><?php echo round($stats['total_score'] / max($stats['total_points'], 1) * 100, 1); ?>%</h3>
                        <p>Общая эффективность</p>
                        <div class="stat-trend trend-up">
                            <i class="fas fa-star"></i>
                            Прогресс: +5.2%
                        </div>
                    </div>
                </div>
                
            <?php else: ?>
                <!-- Статистика для преподавателя -->
                <div class="stat-card primary">
                    <div class="stat-icon icon-primary">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div class="stat-details">
                        <h3><?php echo $stats['total_tests_created']; ?></h3>
                        <p>Созданных тестов</p>
                        <div class="stat-trend trend-up">
                            <i class="fas fa-plus"></i>
                            <?php echo $stats['active_tests']; ?> активных
                        </div>
                    </div>
                </div>
                
                <div class="stat-card success">
                    <div class="stat-icon icon-success">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-details">
                        <h3><?php echo $stats['total_students']; ?></h3>
                        <p>Уникальных студентов</p>
                        <div class="stat-trend trend-up">
                            <i class="fas fa-chart-line"></i>
                            <?php echo $stats['total_tests_taken']; ?> попыток
                        </div>
                    </div>
                </div>
                
                <div class="stat-card info">
                    <div class="stat-icon icon-info">
                        <i class="fas fa-percentage"></i>
                    </div>
                    <div class="stat-details">
                        <h3><?php echo round($stats['avg_success_rate'], 1); ?>%</h3>
                        <p>Средний успех</p>
                        <div class="stat-trend trend-up">
                            <i class="fas fa-graduation-cap"></i>
                            Качество обучения
                        </div>
                    </div>
                </div>
                
                <div class="stat-card warning">
                    <div class="stat-icon icon-warning">
                        <i class="fas fa-check-double"></i>
                    </div>
                    <div class="stat-details">
                        <h3><?php echo $stats['pending_reviews']; ?></h3>
                        <p>Тестов на проверке</p>
                        <div class="stat-trend trend-down">
                            <i class="fas fa-clock"></i>
                            Требуют внимания
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Детальная аналитика -->
        <div class="charts-grid">
            <!-- График прогресса -->
            <div class="section">
                <div class="section-header">
                    <h2><i class="fas fa-chart-line"></i> Прогресс обучения</h2>
                </div>
                <div class="chart-container">
                    <canvas id="progressChart"></canvas>
                </div>
            </div>

            <!-- Статистика по предметам/группам -->
            <div class="section">
                <div class="section-header">
                    <h2><i class="fas fa-book"></i> 
                        <?php echo $user['role'] == 'student' ? 'По предметам' : 'По группам'; ?>
                    </h2>
                </div>
                <div class="chart-container">
                    <canvas id="subjectGroupChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Детальная табличная статистика -->
        <div class="section">
            <div class="section-header">
                <h2><i class="fas fa-table"></i> Детальная статистика</h2>
            </div>
            
            <?php if ($user['role'] == 'student'): ?>
                <!-- Статистика по предметам для студента -->
                <table class="stats-table">
                    <thead>
                        <tr>
                            <th>Предмет</th>
                            <th>Тестов</th>
                            <th>Средний результат</th>
                            <th>Лучший результат</th>
                            <th>Время изучения</th>
                            <th>Эффективность</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($subject_stats as $subject): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($subject['subject']); ?></strong></td>
                                <td><?php echo $subject['test_count']; ?></td>
                                <td>
                                    <span class="score-badge <?php 
                                        echo $subject['avg_score'] >= 80 ? 'score-excellent' : 
                                             ($subject['avg_score'] >= 60 ? 'score-good' : 'score-poor'); 
                                    ?>">
                                        <?php echo round($subject['avg_score'], 1); ?>%
                                    </span>
                                </td>
                                <td><?php echo round($subject['best_score'], 1); ?>%</td>
                                <td><?php echo round($subject['total_time'] / 60, 1); ?> ч</td>
                                <td>
                                    <div class="progress-bar">
                                        <div class="progress-fill" style="width: <?php echo $subject['avg_score']; ?>%"></div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
            <?php else: ?>
                <!-- Статистика по группам для преподавателя -->
                <table class="stats-table">
                    <thead>
                        <tr>
                            <th>Группа</th>
                            <th>Студентов</th>
                            <th>Пройдено тестов</th>
                            <th>Средний результат</th>
                            <th>Успешно сдано</th>
                            <th>Успеваемость</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($group_stats as $group): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($group['group_name']); ?></strong></td>
                                <td><?php echo $group['student_count']; ?></td>
                                <td><?php echo $group['tests_taken']; ?></td>
                                <td>
                                    <span class="score-badge <?php 
                                        echo $group['avg_score'] >= 80 ? 'score-excellent' : 
                                             ($group['avg_score'] >= 60 ? 'score-good' : 'score-poor'); 
                                    ?>">
                                        <?php echo round($group['avg_score'], 1); ?>%
                                    </span>
                                </td>
                                <td><?php echo $group['passed_tests']; ?></td>
                                <td>
                                    <div class="progress-bar">
                                        <div class="progress-fill" style="width: <?php echo $group['avg_score']; ?>%"></div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Дополнительные графики -->
        <div class="charts-grid">
            <!-- Активность по времени -->
            <div class="section">
                <div class="section-header">
                    <h2><i class="fas fa-clock"></i> Активность по времени суток</h2>
                </div>
                <div class="chart-container">
                    <canvas id="timeActivityChart"></canvas>
                </div>
            </div>

            <!-- Статистика по сложности -->
            <div class="section">
                <div class="section-header">
                    <h2><i class="fas fa-signal"></i> Результаты по сложности</h2>
                </div>
                <div class="chart-container">
                    <canvas id="difficultyChart"></canvas>
                </div>
            </div>
        </div>

        <?php if ($user['role'] == 'teacher'): ?>
        <!-- Топ студентов для преподавателя -->
        <div class="section">
            <div class="section-header">
                <h2><i class="fas fa-trophy"></i> Топ студентов</h2>
            </div>
            <table class="stats-table">
                <thead>
                    <tr>
                        <th>Студент</th>
                        <th>Группа</th>
                        <th>Тестов пройдено</th>
                        <th>Средний результат</th>
                        <th>Лучший результат</th>
                        <th>Общий балл</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($top_students as $student): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($student['full_name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($student['group_name']); ?></td>
                            <td><?php echo $student['tests_taken']; ?></td>
                            <td>
                                <span class="score-badge <?php 
                                    echo $student['avg_score'] >= 80 ? 'score-excellent' : 
                                         ($student['avg_score'] >= 60 ? 'score-good' : 'score-poor'); 
                                ?>">
                                    <?php echo round($student['avg_score'], 1); ?>%
                                </span>
                            </td>
                            <td><?php echo round($student['best_score'], 1); ?>%</td>
                            <td><strong><?php echo $student['total_points']; ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <script>
        // Инициализация всех графиков
        document.addEventListener('DOMContentLoaded', function() {
            <?php if ($user['role'] == 'student'): ?>
            // График прогресса для студента
            const progressCtx = document.getElementById('progressChart').getContext('2d');
            new Chart(progressCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode(array_map(function($week) { 
                        return 'Нед. ' . substr($week['week'], 4); 
                    }, array_reverse($weekly_activity))); ?>,
                    datasets: [{
                        label: 'Средний результат (%)',
                        data: <?php echo json_encode(array_map(function($week) { 
                            return round($week['avg_score'], 1); 
                        }, array_reverse($weekly_activity))); ?>,
                        borderColor: '#3498db',
                        backgroundColor: 'rgba(52, 152, 219, 0.1)',
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100
                        }
                    }
                }
            });

            // График по предметам
            const subjectCtx = document.getElementById('subjectGroupChart').getContext('2d');
            new Chart(subjectCtx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode(array_column($subject_stats, 'subject')); ?>,
                    datasets: [{
                        label: 'Средний результат (%)',
                        data: <?php echo json_encode(array_column($subject_stats, 'avg_score')); ?>,
                        backgroundColor: '#2ecc71'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false
                }
            });

            <?php else: ?>
            // Графики для преподавателя
            const progressCtx = document.getElementById('progressChart').getContext('2d');
            new Chart(progressCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode(array_map(function($day) { 
                        return date('d.m', strtotime($day['date'])); 
                    }, array_slice(array_reverse($daily_activity), 0, 14))); ?>,
                    datasets: [{
                        label: 'Активные студенты',
                        data: <?php echo json_encode(array_map(function($day) { 
                            return $day['active_students']; 
                        }, array_slice(array_reverse($daily_activity), 0, 14))); ?>,
                        borderColor: '#3498db',
                        backgroundColor: 'rgba(52, 152, 219, 0.1)',
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false
                }
            });

            const groupCtx = document.getElementById('subjectGroupChart').getContext('2d');
            new Chart(groupCtx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode(array_column($group_stats, 'group_name')); ?>,
                    datasets: [{
                        label: 'Средний результат (%)',
                        data: <?php echo json_encode(array_column($group_stats, 'avg_score')); ?>,
                        backgroundColor: '#e74c3c'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false
                }
            });
            <?php endif; ?>

            // Дополнительные графики
            const timeCtx = document.getElementById('timeActivityChart').getContext('2d');
            const difficultyCtx = document.getElementById('difficultyChart').getContext('2d');
            
            // Здесь можно добавить инициализацию дополнительных графиков
        });
    </script>
</body>
</html>