<?php
include 'config.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

// Получаем период для фильтрации
$period = $_GET['period'] ?? 'month'; // week, month, year, all

// Функция для расчета даты начала периода
function getStartDate($period) {
    switch ($period) {
        case 'week': return date('Y-m-d', strtotime('-1 week'));
        case 'month': return date('Y-m-d', strtotime('-1 month'));
        case 'year': return date('Y-m-d', strtotime('-1 year'));
        default: return '2020-01-01'; // all time
    }
}

$start_date = getStartDate($period);

if ($user['role'] == 'student') {
    // Основная статистика для студента с фильтром по периоду
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
        WHERE user_id = ? AND completed_at >= ?
    ");
    $stmt->execute([$_SESSION['user_id'], $start_date]);
    $stats = $stmt->fetch();
    
    // Сравнение с предыдущим периодом
    $prev_period = $_GET['prev_period'] ?? 'prev_month';
    $prev_start_date = getStartDate($prev_period);
    
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as prev_total_tests,
            AVG(percentage) as prev_avg_percentage,
            COUNT(CASE WHEN passed = 1 THEN 1 END) as prev_passed_tests
        FROM test_results 
        WHERE user_id = ? AND completed_at >= ? AND completed_at < ?
    ");
    $stmt->execute([$_SESSION['user_id'], $prev_start_date, $start_date]);
    $prev_stats = $stmt->fetch();
    
    // Статистика по тестам
    $stmt = $pdo->prepare("
        SELECT 
            t.title as test_name,
            COUNT(*) as test_count,
            AVG(tr.percentage) as avg_score,
            MAX(tr.percentage) as best_score,
            SUM(tr.time_spent) as total_time
        FROM test_results tr
        JOIN tests t ON tr.test_id = t.id
        WHERE tr.user_id = ? AND tr.completed_at >= ?
        GROUP BY t.id, t.title
        ORDER BY avg_score DESC
    ");
    $stmt->execute([$_SESSION['user_id'], $start_date]);
    $test_stats = $stmt->fetchAll();
    
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
    
    // Убрана статистика по категориям, так как таблицы categories нет
    
} else if ($user['role'] == 'teacher' || $user['role'] == 'admin') {
    // Расширенная статистика для преподавателя/администратора
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_tests_created,
            COUNT(CASE WHEN is_active = 1 THEN 1 END) as active_tests,
            (SELECT COUNT(*) FROM test_results WHERE test_id IN (
                SELECT id FROM tests WHERE created_by = ?
            ) AND completed_at >= ?) as total_tests_taken,
            (SELECT COUNT(DISTINCT user_id) FROM test_results WHERE test_id IN (
                SELECT id FROM tests WHERE created_by = ?
            ) AND completed_at >= ?) as total_students,
            (SELECT AVG(percentage) FROM test_results WHERE test_id IN (
                SELECT id FROM tests WHERE created_by = ?
            ) AND completed_at >= ?) as avg_success_rate,
            (SELECT COUNT(*) FROM questions WHERE test_id IN (
                SELECT id FROM tests WHERE created_by = ?
            )) as total_questions,
            (SELECT COUNT(*) FROM test_results WHERE test_id IN (
                SELECT id FROM tests WHERE created_by = ?
            ) AND needs_review = 1) as pending_reviews
    ");
    $stmt->execute([
        $_SESSION['user_id'], $start_date,
        $_SESSION['user_id'], $start_date,
        $_SESSION['user_id'], $start_date,
        $_SESSION['user_id'],
        $_SESSION['user_id']
    ]);
    $stats = $stmt->fetch();
    
    // Статистика по тестам
    $stmt = $pdo->prepare("
        SELECT 
            t.title as test_name,
            t.id as test_id,
            COUNT(tr.id) as attempts,
            COUNT(DISTINCT tr.user_id) as unique_students,
            AVG(tr.percentage) as avg_score,
            MAX(tr.percentage) as best_score,
            MIN(tr.percentage) as worst_score,
            COUNT(CASE WHEN tr.passed = 1 THEN 1 END) as passed_attempts
        FROM tests t
        LEFT JOIN test_results tr ON t.id = tr.test_id AND tr.completed_at >= ?
        WHERE t.created_by = ?
        GROUP BY t.id, t.title
        ORDER BY attempts DESC
    ");
    $stmt->execute([$start_date, $_SESSION['user_id']]);
    $test_stats = $stmt->fetchAll();
    
    // Ежедневная активность
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
        WHERE t.created_by = ? AND tr.completed_at >= ?
        GROUP BY u.id, u.full_name, u.group_name
        HAVING tests_taken >= 1
        ORDER BY avg_score DESC
        LIMIT 15
    ");
    $stmt->execute([$_SESSION['user_id'], $start_date]);
    $top_students = $stmt->fetchAll();
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
            position: relative;
            overflow: hidden;
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
            z-index: 1;
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
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .trend-up { color: var(--success); }
        .trend-down { color: var(--danger); }
        
        .stat-comparison {
            position: absolute;
            top: 15px;
            right: 15px;
            font-size: 12px;
            padding: 4px 8px;
            border-radius: 12px;
            background: rgba(0,0,0,0.05);
        }

        /* Period Filter */
        .period-filter {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            background: white;
            padding: 15px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        
        .period-btn {
            padding: 8px 16px;
            border: none;
            background: #f8f9fa;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 14px;
        }
        
        .period-btn.active {
            background: var(--primary);
            color: white;
        }
        
        .period-btn:hover:not(.active) {
            background: #e9ecef;
        }

        /* Sections */
        .section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            margin-bottom: 25px;
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

        /* Tabs */
        .tabs {
            display: flex;
            border-bottom: 1px solid #e0e0e0;
            margin-bottom: 20px;
        }
        
        .tab {
            padding: 12px 20px;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
        }
        
        .tab.active {
            border-bottom-color: var(--primary);
            color: var(--primary);
            font-weight: 600;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }

        /* Адаптивность */
        @media (max-width: 1200px) {
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
            
            .charts-grid {
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
        
        <!-- Фильтр по периоду -->
        <div class="period-filter">
            <button class="period-btn <?php echo $period == 'week' ? 'active' : ''; ?>" data-period="week">Неделя</button>
            <button class="period-btn <?php echo $period == 'month' ? 'active' : ''; ?>" data-period="month">Месяц</button>
            <button class="period-btn <?php echo $period == 'year' ? 'active' : ''; ?>" data-period="year">Год</button>
            <button class="period-btn <?php echo $period == 'all' ? 'active' : ''; ?>" data-period="all">Все время</button>
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
                        <h3><?php echo $stats['passed_tests'] ?? 0; ?></h3>
                        <p>Успешно пройдено тестов</p>
                        <div class="stat-trend trend-up">
                            <i class="fas fa-arrow-up"></i>
                            <?php 
                            $successRate = ($stats['total_tests'] ?? 0) > 0 ? 
                                round((($stats['passed_tests'] ?? 0) / ($stats['total_tests'] ?? 1)) * 100, 1) : 0; 
                            echo $successRate; ?>% успеха
                        </div>
                    </div>
                    <?php if (isset($prev_stats['prev_passed_tests'])): ?>
                    <div class="stat-comparison <?php echo ($stats['passed_tests'] ?? 0) >= ($prev_stats['prev_passed_tests'] ?? 0) ? 'trend-up' : 'trend-down'; ?>">
                        <?php 
                        $diff = ($stats['passed_tests'] ?? 0) - ($prev_stats['prev_passed_tests'] ?? 0);
                        echo ($diff >= 0 ? '+' : '') . $diff;
                        ?>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="stat-card info">
                    <div class="stat-icon icon-info">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-details">
                        <h3><?php echo round($stats['avg_percentage'] ?? 0, 1); ?>%</h3>
                        <p>Средний результат</p>
                        <div class="stat-trend trend-up">
                            <i class="fas fa-trophy"></i>
                            Лучший: <?php echo round($stats['best_score'] ?? 0, 1); ?>%
                        </div>
                    </div>
                    <?php if (isset($prev_stats['prev_avg_percentage'])): ?>
                    <div class="stat-comparison <?php echo ($stats['avg_percentage'] ?? 0) >= ($prev_stats['prev_avg_percentage'] ?? 0) ? 'trend-up' : 'trend-down'; ?>">
                        <?php 
                        $diff = round(($stats['avg_percentage'] ?? 0) - ($prev_stats['prev_avg_percentage'] ?? 0), 1);
                        echo ($diff >= 0 ? '+' : '') . $diff . '%';
                        ?>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="stat-card primary">
                    <div class="stat-icon icon-primary">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-details">
                        <h3><?php echo round(($stats['total_time_spent'] ?? 0) / 60, 1); ?></h3>
                        <p>Часов обучения</p>
                        <div class="stat-trend trend-up">
                            <i class="fas fa-brain"></i>
                            <?php echo $stats['total_tests'] ?? 0; ?> тестов
                        </div>
                    </div>
                </div>
                
                <div class="stat-card warning">
                    <div class="stat-icon icon-warning">
                        <i class="fas fa-bullseye"></i>
                    </div>
                    <div class="stat-details">
                        <h3><?php echo round(($stats['total_score'] ?? 0) / max(($stats['total_points'] ?? 1), 1) * 100, 1); ?>%</h3>
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
                        <h3><?php echo $stats['total_tests_created'] ?? 0; ?></h3>
                        <p>Созданных тестов</p>
                        <div class="stat-trend trend-up">
                            <i class="fas fa-plus"></i>
                            <?php echo $stats['active_tests'] ?? 0; ?> активных
                        </div>
                    </div>
                </div>
                
                <div class="stat-card success">
                    <div class="stat-icon icon-success">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-details">
                        <h3><?php echo $stats['total_students'] ?? 0; ?></h3>
                        <p>Уникальных студентов</p>
                        <div class="stat-trend trend-up">
                            <i class="fas fa-chart-line"></i>
                            <?php echo $stats['total_tests_taken'] ?? 0; ?> попыток
                        </div>
                    </div>
                </div>
                
                <div class="stat-card info">
                    <div class="stat-icon icon-info">
                        <i class="fas fa-percentage"></i>
                    </div>
                    <div class="stat-details">
                        <h3><?php echo round($stats['avg_success_rate'] ?? 0, 1); ?>%</h3>
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
                        <h3><?php echo $stats['pending_reviews'] ?? 0; ?></h3>
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

            <!-- Статистика по тестам -->
            <div class="section">
                <div class="section-header">
                    <h2><i class="fas fa-book"></i>Статистика по тестам</h2>
                </div>
                <div class="chart-container">
                    <canvas id="testStatsChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Детальная табличная статистика -->
        <div class="section">
            <div class="tabs">
                <div class="tab active" data-tab="tests">Статистика по тестам</div>
                <?php if ($user['role'] == 'student'): ?>
                <div class="tab" data-tab="recent">Последние тесты</div>
                <?php endif; ?>
            </div>
            
            <div class="tab-content active" id="tests-tab">
                <?php if ($user['role'] == 'student'): ?>
                    <!-- Статистика по тестам для студента -->
                    <?php if (!empty($test_stats)): ?>
                    <table class="stats-table">
                        <thead>
                            <tr>
                                <th>Тест</th>
                                <th>Попыток</th>
                                <th>Средний результат</th>
                                <th>Лучший результат</th>
                                <th>Время изучения</th>
                                <th>Эффективность</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($test_stats as $test): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($test['test_name']); ?></strong></td>
                                    <td><?php echo $test['test_count'] ?? 0; ?></td>
                                    <td>
                                        <span class="score-badge <?php 
                                            $avgScore = $test['avg_score'] ?? 0;
                                            if ($avgScore >= 80) {
                                                echo 'score-excellent';
                                            } elseif ($avgScore >= 60) {
                                                echo 'score-good';
                                            } else {
                                                echo 'score-poor';
                                            }
                                        ?>">
                                            <?php echo round($avgScore, 1); ?>%
                                        </span>
                                    </td>
                                    <td><?php echo round($test['best_score'] ?? 0, 1); ?>%</td>
                                    <td><?php echo round(($test['total_time'] ?? 0) / 60, 1); ?> ч</td>
                                    <td>
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width: <?php echo $avgScore; ?>%"></div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                        <p style="text-align: center; padding: 20px; color: var(--gray);">
                            <i class="fas fa-info-circle"></i> Нет данных для отображения
                        </p>
                    <?php endif; ?>
                    
                <?php else: ?>
                    <!-- Статистика по тестам для преподавателя -->
                    <?php if (!empty($test_stats)): ?>
                    <table class="stats-table">
                        <thead>
                            <tr>
                                <th>Тест</th>
                                <th>Попыток</th>
                                <th>Уникальных студентов</th>
                                <th>Средний результат</th>
                                <th>Лучший результат</th>
                                <th>Успеваемость</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($test_stats as $test): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($test['test_name']); ?></strong></td>
                                    <td><?php echo $test['attempts'] ?? 0; ?></td>
                                    <td><?php echo $test['unique_students'] ?? 0; ?></td>
                                    <td>
                                        <span class="score-badge <?php 
                                            $avgScore = $test['avg_score'] ?? 0;
                                            if ($avgScore >= 80) {
                                                echo 'score-excellent';
                                            } elseif ($avgScore >= 60) {
                                                echo 'score-good';
                                            } else {
                                                echo 'score-poor';
                                            }
                                        ?>">
                                            <?php echo round($avgScore, 1); ?>%
                                        </span>
                                    </td>
                                    <td><?php echo round($test['best_score'] ?? 0, 1); ?>%</td>
                                    <td>
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width: <?php echo $avgScore; ?>%"></div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                        <p style="text-align: center; padding: 20px; color: var(--gray);">
                            <i class="fas fa-info-circle"></i> Нет данных по тестам для отображения
                        </p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            
            <?php if ($user['role'] == 'student'): ?>
            <div class="tab-content" id="recent-tab">
                <!-- Последние пройденные тесты для студента -->
                <?php
                $stmt = $pdo->prepare("
                    SELECT t.title, tr.score, tr.total_points, tr.percentage, tr.passed, tr.completed_at,
                           u.full_name as teacher_name, tr.time_spent
                    FROM test_results tr
                    JOIN tests t ON tr.test_id = t.id
                    JOIN users u ON t.created_by = u.id
                    WHERE tr.user_id = ?
                    ORDER BY tr.completed_at DESC
                    LIMIT 10
                ");
                $stmt->execute([$_SESSION['user_id']]);
                $recent_tests = $stmt->fetchAll();
                ?>
                
                <?php if (!empty($recent_tests)): ?>
                <table class="stats-table">
                    <thead>
                        <tr>
                            <th>Тест</th>
                            <th>Преподаватель</th>
                            <th>Результат</th>
                            <th>Баллы</th>
                            <th>Время</th>
                            <th>Дата</th>
                            <th>Статус</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_tests as $test): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($test['title']); ?></strong></td>
                                <td><?php echo htmlspecialchars($test['teacher_name']); ?></td>
                                <td>
                                    <span class="score-badge <?php 
                                        $percentage = $test['percentage'] ?? 0;
                                        if ($percentage >= 80) {
                                            echo 'score-excellent';
                                        } elseif ($percentage >= 60) {
                                            echo 'score-good';
                                        } else {
                                            echo 'score-poor';
                                        }
                                    ?>">
                                        <?php echo round($percentage, 1); ?>%
                                    </span>
                                </td>
                                <td><?php echo $test['score'] ?? 0; ?> / <?php echo $test['total_points'] ?? 0; ?></td>
                                <td><?php echo round(($test['time_spent'] ?? 0) / 60, 1); ?> мин</td>
                                <td><?php echo date('d.m.Y H:i', strtotime($test['completed_at'])); ?></td>
                                <td>
                                    <span class="score-badge <?php echo $test['passed'] ? 'score-excellent' : 'score-poor'; ?>">
                                        <?php echo $test['passed'] ? 'Сдан' : 'Не сдан'; ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <p style="text-align: center; padding: 20px; color: var(--gray);">
                        <i class="fas fa-info-circle"></i> Нет данных о пройденных тестах
                    </p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($user['role'] == 'teacher' && !empty($top_students)): ?>
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
                            <td><?php echo htmlspecialchars($student['group_name'] ?? 'Без группы'); ?></td>
                            <td><?php echo $student['tests_taken']; ?></td>
                            <td>
                                <span class="score-badge <?php 
                                    $avgScore = $student['avg_score'] ?? 0;
                                    if ($avgScore >= 80) {
                                        echo 'score-excellent';
                                    } elseif ($avgScore >= 60) {
                                        echo 'score-good';
                                    } else {
                                        echo 'score-poor';
                                    }
                                ?>">
                                    <?php echo round($avgScore, 1); ?>%
                                </span>
                            </td>
                            <td><?php echo round($student['best_score'] ?? 0, 1); ?>%</td>
                            <td><strong><?php echo $student['total_points'] ?? 0; ?></strong></td>
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
            const progressCtx = document.getElementById('progressChart');
            <?php if (!empty($weekly_activity)): ?>
            if (progressCtx) {
                new Chart(progressCtx, {
                    type: 'line',
                    data: {
                        labels: <?php echo json_encode(array_map(function($week) { 
                            return 'Нед. ' . substr($week['week'], 4); 
                        }, array_reverse($weekly_activity))); ?>,
                        datasets: [{
                            label: 'Средний результат (%)',
                            data: <?php echo json_encode(array_map(function($week) { 
                                return round($week['avg_score'] ?? 0, 1); 
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
            }
            <?php endif; ?>

            // График по тестам для студента
            const testCtx = document.getElementById('testStatsChart');
            <?php if (!empty($test_stats)): ?>
            if (testCtx) {
                new Chart(testCtx, {
                    type: 'bar',
                    data: {
                        labels: <?php echo json_encode(array_map(function($test) { 
                            return $test['test_name']; 
                        }, array_slice($test_stats, 0, 5))); ?>,
                        datasets: [{
                            label: 'Средний результат (%)',
                            data: <?php echo json_encode(array_map(function($test) { 
                                return $test['avg_score'] ?? 0; 
                            }, array_slice($test_stats, 0, 5))); ?>,
                            backgroundColor: '#2ecc71'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false
                    }
                });
            }
            <?php endif; ?>

            <?php else: ?>
            

            const testCtx = document.getElementById('testStatsChart');
            <?php if (!empty($test_stats)): ?>
            if (testCtx) {
                new Chart(testCtx, {
                    type: 'bar',
                    data: {
                        labels: <?php echo json_encode(array_map(function($test) { 
                            return $test['test_name']; 
                        }, array_slice($test_stats, 0, 5))); ?>,
                        datasets: [{
                            label: 'Количество попыток',
                            data: <?php echo json_encode(array_map(function($test) { 
                                return $test['attempts'] ?? 0; 
                            }, array_slice($test_stats, 0, 5))); ?>,
                            backgroundColor: '#e74c3c'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false
                    }
                });
            }
            <?php endif; ?>
            <?php endif; ?>
            
            // Табы
            const tabs = document.querySelectorAll('.tab');
            tabs.forEach(function(tab) {
                tab.addEventListener('click', function() {
                    // Убираем активный класс у всех табов и контента
                    document.querySelectorAll('.tab').forEach(function(t) {
                        t.classList.remove('active');
                    });
                    document.querySelectorAll('.tab-content').forEach(function(c) {
                        c.classList.remove('active');
                    });
                    
                    // Добавляем активный класс к выбранному табу
                    tab.classList.add('active');
                    
                    // Показываем соответствующий контент
                    const tabId = tab.getAttribute('data-tab');
                    document.getElementById(tabId + '-tab').classList.add('active');
                });
            });
            
            // Фильтр по периоду
            const periodButtons = document.querySelectorAll('.period-btn');
            periodButtons.forEach(function(button) {
                button.addEventListener('click', function() {
                    const period = button.getAttribute('data-period');
                    // Обновляем URL с параметром периода
                    const url = new URL(window.location);
                    url.searchParams.set('period', period);
                    window.location.href = url.toString();
                });
            });
        });
    </script>
</body>
</html>