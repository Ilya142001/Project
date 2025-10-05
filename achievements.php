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

// Получаем статистику пользователя для ачивок
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_tests,
        AVG(percentage) as avg_score,
        COUNT(CASE WHEN passed = 1 THEN 1 END) as passed_tests,
        MAX(percentage) as best_score,
        COUNT(CASE WHEN percentage >= 90 THEN 1 END) as excellent_tests,
        COUNT(DISTINCT DATE(completed_at)) as active_days,
        COUNT(DISTINCT t.subject) as unique_subjects
    FROM test_results tr
    JOIN tests t ON tr.test_id = t.id
    WHERE tr.user_id = ?
");
$stmt->execute([$_SESSION['user_id']]);
$user_stats = $stmt->fetch();

// Функция для проверки ачивок
function checkAchievement($achievement_key, $user_stats) {
    switch ($achievement_key) {
        case 'first_test':
            return $user_stats['total_tests'] >= 1;
        case 'five_tests':
            return $user_stats['total_tests'] >= 5;
        case 'ten_tests':
            return $user_stats['total_tests'] >= 10;
        case 'twenty_tests':
            return $user_stats['total_tests'] >= 20;
        case 'first_success':
            return $user_stats['passed_tests'] >= 1;
        case 'high_achiever':
            return $user_stats['excellent_tests'] >= 1;
        case 'perfectionist':
            return $user_stats['best_score'] == 100;
        case 'consistent_learner':
            return $user_stats['avg_score'] >= 80;
        case 'week_warrior':
            return $user_stats['active_days'] >= 5;
        case 'month_warrior':
            return $user_stats['active_days'] >= 15;
        case 'versatile_learner':
            return $user_stats['unique_subjects'] >= 3;
        case 'subject_master':
            return $user_stats['unique_subjects'] >= 5;
        case 'quick_learner':
        case 'marathon_runner':
            return false; // Для специальных ачивок
        default:
            return false;
    }
}

// Определяем все возможные ачивки
$all_achievements = [
    // Ачивки за количество тестов
    'first_test' => [
        'title' => 'Первый шаг',
        'description' => 'Пройдите ваш первый тест',
        'icon' => 'fas fa-baby',
        'color' => 'primary',
        'points' => 10,
        'category' => 'progress'
    ],
    'five_tests' => [
        'title' => 'Набираем обороты',
        'description' => 'Пройдите 5 тестов',
        'icon' => 'fas fa-walking',
        'color' => 'info',
        'points' => 20,
        'category' => 'progress'
    ],
    'ten_tests' => [
        'title' => 'Опытный студент',
        'description' => 'Пройдите 10 тестов',
        'icon' => 'fas fa-running',
        'color' => 'success',
        'points' => 50,
        'category' => 'progress'
    ],
    'twenty_tests' => [
        'title' => 'Ветеран обучения',
        'description' => 'Пройдите 20 тестов',
        'icon' => 'fas fa-rocket',
        'color' => 'warning',
        'points' => 100,
        'category' => 'progress'
    ],
    
    // Ачивки за успеваемость
    'first_success' => [
        'title' => 'Первая победа',
        'description' => 'Успешно сдайте первый тест',
        'icon' => 'fas fa-trophy',
        'color' => 'success',
        'points' => 15,
        'category' => 'performance'
    ],
    'high_achiever' => [
        'title' => 'Высокий результат',
        'description' => 'Получите 90% или выше в любом тесте',
        'icon' => 'fas fa-star',
        'color' => 'warning',
        'points' => 25,
        'category' => 'performance'
    ],
    'perfectionist' => [
        'title' => 'Перфекционист',
        'description' => 'Получите 100% в любом тесте',
        'icon' => 'fas fa-crown',
        'color' => 'danger',
        'points' => 50,
        'category' => 'performance'
    ],
    'consistent_learner' => [
        'title' => 'Последовательный ученик',
        'description' => 'Средний результат выше 80%',
        'icon' => 'fas fa-chart-line',
        'color' => 'info',
        'points' => 30,
        'category' => 'performance'
    ],
    
    // Ачивки за активность
    'week_warrior' => [
        'title' => 'Воин недели',
        'description' => 'Пройдите тесты в 5 разных дней',
        'icon' => 'fas fa-calendar-week',
        'color' => 'primary',
        'points' => 20,
        'category' => 'activity'
    ],
    'month_warrior' => [
        'title' => 'Воин месяца',
        'description' => 'Пройдите тесты в 15 разных дней',
        'icon' => 'fas fa-calendar-alt',
        'color' => 'success',
        'points' => 50,
        'category' => 'activity'
    ],
    'versatile_learner' => [
        'title' => 'Разносторонний ученик',
        'description' => 'Пройдите тесты по 3 разным предметам',
        'icon' => 'fas fa-book-open',
        'color' => 'info',
        'points' => 25,
        'category' => 'activity'
    ],
    'subject_master' => [
        'title' => 'Мастер предметов',
        'description' => 'Пройдите тесты по 5 разным предметам',
        'icon' => 'fas fa-graduation-cap',
        'color' => 'warning',
        'points' => 75,
        'category' => 'activity'
    ],
    
    // Специальные ачивки
    'quick_learner' => [
        'title' => 'Быстрый ученик',
        'description' => 'Сдайте 3 теста подряд успешно',
        'icon' => 'fas fa-bolt',
        'color' => 'danger',
        'points' => 40,
        'category' => 'special'
    ],
    'marathon_runner' => [
        'title' => 'Марафонец',
        'description' => 'Пройдите тесты 5 дней подряд',
        'icon' => 'fas fa-fire',
        'color' => 'danger',
        'points' => 60,
        'category' => 'special'
    ]
];

// Проверяем полученные ачивки
$earned_achievements = [];
$total_points = 0;
$progress_achievements = [];
$performance_achievements = [];
$activity_achievements = [];
$special_achievements = [];

foreach ($all_achievements as $key => $achievement) {
    $is_earned = checkAchievement($key, $user_stats);
    $achievement_data = [
        'key' => $key,
        'title' => $achievement['title'],
        'description' => $achievement['description'],
        'icon' => $achievement['icon'],
        'color' => $achievement['color'],
        'points' => $achievement['points'],
        'earned' => $is_earned,
        'progress' => $is_earned ? 100 : 0
    ];
    
    if ($is_earned) {
        $earned_achievements[] = $achievement_data;
        $total_points += $achievement['points'];
    }
    
    // Группируем по категориям
    switch ($achievement['category']) {
        case 'progress':
            $progress_achievements[] = $achievement_data;
            break;
        case 'performance':
            $performance_achievements[] = $achievement_data;
            break;
        case 'activity':
            $activity_achievements[] = $achievement_data;
            break;
        case 'special':
            $special_achievements[] = $achievement_data;
            break;
    }
}

// Получаем уровень пользователя на основе очков
$user_level = floor($total_points / 100) + 1;
$next_level_points = $user_level * 100;
$current_level_points = $total_points % 100;
$level_progress = ($current_level_points / 100) * 100;

// Получаем уведомления
$stmt = $pdo->prepare("
    SELECT * FROM notifications 
    WHERE user_id = ? AND (expires_at IS NULL OR expires_at > NOW())
    ORDER BY created_at DESC
    LIMIT 5
");
$stmt->execute([$_SESSION['user_id']]);
$notifications = $stmt->fetchAll();

// Помечаем уведомления как прочитанные
if (!empty($notifications)) {
    $stmt = $pdo->prepare("
        UPDATE notifications SET is_read = 1 
        WHERE user_id = ? AND is_read = 0
    ");
    $stmt->execute([$_SESSION['user_id']]);
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Мои достижения - Система интеллектуальной оценки знаний</title>
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
            overflow-x: hidden;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            box-shadow: 2px 0 15px rgba(0, 0, 0, 0.1);
            scrollbar-width: none;
        }

        .sidebar::-webkit-scrollbar {
            width: 0px;
            background: transparent;
        }

        /* Логотип */
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

        .logo h1 i {
            color: var(--primary);
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

        /* Информация о пользователе */
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
            overflow: hidden;
        }

        .user-details h3 {
            font-size: 16px;
            margin-bottom: 4px;
        }

        .user-details p {
            font-size: 12px;
            color: rgba(255, 255, 255, 0.7);
            margin-bottom: 6px;
        }

        .role-badge {
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .role-admin {
            background: var(--accent);
        }

        .role-teacher {
            background: var(--warning);
        }

        .role-student {
            background: var(--primary);
        }

        /* Быстрая статистика */
        .quick-stats {
            padding: 15px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .stat-item {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            color: rgba(255, 255, 255, 0.8);
            margin-bottom: 8px;
        }

        .stat-item i {
            width: 16px;
            text-align: center;
        }

        /* Навигация */
        .nav-links {
            list-style: none;
            flex: 1;
            overflow-y: auto;
            scrollbar-width: none;
        }

        .nav-links::-webkit-scrollbar {
            display: none;
        }

        .nav-section {
            padding: 15px 20px 5px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            margin-bottom: 10px;
        }

        .section-label {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: rgba(255, 255, 255, 0.5);
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

        .nav-links li a:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border-left-color: var(--primary);
        }

        .nav-links li a.active {
            background: rgba(255, 255, 255, 0.15);
            color: white;
            border-left-color: var(--primary);
        }

        .nav-links li a i {
            width: 20px;
            margin-right: 12px;
            font-size: 14px;
        }

        /* Футер сайдбара */
        .sidebar-footer {
            padding: 15px 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            margin-top: auto;
        }

        .system-info {
            margin-bottom: 15px;
        }

        .info-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: rgba(255, 255, 255, 0.7);
            margin-bottom: 5px;
        }

        .quick-actions {
            display: flex;
            gap: 10px;
        }

        .quick-btn {
            flex: 1;
            background: rgba(255, 255, 255, 0.1);
            border: none;
            color: white;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: background 0.3s;
        }

        .quick-btn:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        /* Кнопка выхода */
        .logout-btn {
            color: var(--accent) !important;
        }

        .logout-btn:hover {
            background: rgba(231, 76, 60, 0.1) !important;
        }

        /* Мобильное меню */
        .mobile-menu-toggle {
            display: none;
            position: fixed;
            top: 20px;
            left: 20px;
            background: var(--primary);
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 1001;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }

        /* ===== ОСНОВНОЙ КОНТЕНТ ===== */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            padding: 20px;
            transition: margin-left 0.3s;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .header h1 {
            font-size: 32px;
            color: var(--secondary);
            font-weight: 700;
        }
        
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--gray);
            font-size: 14px;
        }
        
        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
        }
        
        .breadcrumb i {
            font-size: 12px;
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
        
        .search-box i {
            color: var(--gray);
        }

        /* Stats Overview */
        .stats-overview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
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
        
        .stat-card.success {
            border-left-color: var(--success);
        }
        
        .stat-card.warning {
            border-left-color: var(--warning);
        }
        
        .stat-card.danger {
            border-left-color: var(--danger);
        }
        
        .stat-card.info {
            border-left-color: var(--info);
        }
        
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
        
        .icon-primary {
            background: linear-gradient(135deg, var(--primary), #4a69bd);
        }
        
        .icon-success {
            background: linear-gradient(135deg, var(--success), #1dd1a1);
        }
        
        .icon-warning {
            background: linear-gradient(135deg, var(--warning), #f6b93b);
        }
        
        .icon-danger {
            background: linear-gradient(135deg, var(--danger), #e55039);
        }
        
        .icon-info {
            background: linear-gradient(135deg, var(--info), #48dbfb);
        }
        
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

        /* Level Progress */
        .level-section {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            margin-bottom: 30px;
            text-align: center;
        }
        
        .level-header {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 20px;
            margin-bottom: 25px;
        }
        
        .level-badge {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, #f39c12, #e67e22);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: 700;
            color: white;
            box-shadow: 0 5px 15px rgba(243, 156, 18, 0.4);
        }
        
        .level-info h2 {
            font-size: 28px;
            color: var(--secondary);
            margin-bottom: 5px;
        }
        
        .level-info p {
            color: var(--gray);
            font-size: 16px;
        }
        
        .progress-container {
            margin: 20px 0;
        }
        
        .progress-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-weight: 600;
            color: var(--secondary);
        }
        
        .progress-bar {
            height: 12px;
            background: #e0e0e0;
            border-radius: 6px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
            border-radius: 6px;
            transition: width 0.5s ease;
        }
        
        .points-info {
            display: flex;
            justify-content: space-around;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
        }
        
        .point-item {
            text-align: center;
        }
        
        .point-value {
            font-size: 24px;
            font-weight: 700;
            color: var(--primary);
        }
        
        .point-label {
            font-size: 14px;
            color: var(--gray);
        }

        /* Achievements Grid */
        .achievements-section {
            margin-bottom: 30px;
        }
        
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
        
        .section-header h2 i {
            color: var(--primary);
        }
        
        .achievements-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .achievement-card {
            background: var(--light);
            border-radius: 12px;
            padding: 20px;
            transition: all 0.3s;
            border: 2px solid transparent;
            position: relative;
            overflow: hidden;
        }
        
        .achievement-card.earned {
            background: white;
            border-color: var(--success);
            box-shadow: 0 5px 15px rgba(46, 204, 113, 0.2);
        }
        
        .achievement-card.locked {
            opacity: 0.7;
            background: #f8f9fa;
        }
        
        .achievement-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
        }
        
        .achievement-card.earned::before {
            background: linear-gradient(90deg, var(--success), #27ae60);
        }
        
        .achievement-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .achievement-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: white;
        }
        
        .icon-primary { background: var(--primary); }
        .icon-success { background: var(--success); }
        .icon-warning { background: var(--warning); }
        .icon-danger { background: var(--danger); }
        .icon-info { background: var(--info); }
        
        .achievement-info {
            flex: 1;
        }
        
        .achievement-title {
            font-weight: 600;
            color: var(--secondary);
            margin-bottom: 5px;
        }
        
        .achievement-description {
            color: var(--gray);
            font-size: 14px;
            margin-bottom: 8px;
        }
        
        .achievement-points {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: rgba(52, 152, 219, 0.1);
            color: var(--primary);
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .achievement-progress {
            margin-top: 15px;
        }
        
        .progress-text {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            color: var(--gray);
            margin-bottom: 5px;
        }
        
        .progress-bar-small {
            height: 6px;
            background: #e0e0e0;
            border-radius: 3px;
            overflow: hidden;
        }
        
        .progress-fill-small {
            height: 100%;
            background: var(--primary);
            border-radius: 3px;
            transition: width 0.5s ease;
        }
        
        .locked-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.9);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 10px;
            opacity: 0;
            transition: opacity 0.3s;
        }
        
        .achievement-card.locked:hover .locked-overlay {
            opacity: 1;
        }
        
        .lock-icon {
            font-size: 24px;
            color: var(--gray);
        }

        /* Empty States */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--gray);
            width: 100%;
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
            display: block;
        }
        
        .empty-state p {
            font-size: 16px;
            margin-bottom: 15px;
        }

        /* Back Button */
        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: var(--primary);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s;
            margin-bottom: 20px;
        }
        
        .back-button:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        /* Адаптивность */
        @media (max-width: 992px) {
            .mobile-menu-toggle {
                display: flex;
            }
            
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s;
            }
            
            .sidebar.mobile-open {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }
            
            .achievements-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .main-content {
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
            
            .stats-overview {
                grid-template-columns: 1fr;
            }
            
            .header-actions {
                width: 100%;
                justify-content: space-between;
            }
            
            .level-header {
                flex-direction: column;
                text-align: center;
            }
            
            .points-info {
                flex-direction: column;
                gap: 15px;
            }
        }
    </style>
</head>
<body>
    <!-- Мобильное меню -->
    <div class="mobile-menu-toggle" id="mobileMenuToggle">
        <i class="fas fa-bars"></i>
    </div>

    <!-- Сайдбар -->
    <div class="sidebar" id="sidebar">
        <div class="logo">
            <h1><i class="fas fa-graduation-cap"></i> EduAI System</h1>
            <div class="system-status">
                <div class="status-indicator online"></div>
                <span>Система активна</span>
            </div>
        </div>

        <div class="user-info">
            <div class="user-avatar">
                <?php 
                $firstName = $user['full_name'];
                if (function_exists('mb_convert_encoding')) {
                    $firstName = mb_convert_encoding($firstName, 'UTF-8', 'auto');
                }
                $firstLetter = mb_substr($firstName, 0, 1, 'UTF-8');
                echo htmlspecialchars(strtoupper($firstLetter));
                ?>
            </div>
            <div class="user-details">
                <h3><?php echo htmlspecialchars($user['full_name']); ?></h3>
                <p><?php echo htmlspecialchars($user['email']); ?></p>
                <span class="role-badge role-<?php echo $user['role']; ?>">
                    <?php echo $user['role'] == 'teacher' ? 'Преподаватель' : 
                           ($user['role'] == 'admin' ? 'Администратор' : 'Студент'); ?>
                </span>
            </div>
        </div>

        <div class="quick-stats">
            <div class="stat-item">
                <i class="fas fa-trophy"></i>
                <span>Достижения: <?php echo count($earned_achievements); ?>/<?php echo count($all_achievements); ?></span>
            </div>
            <div class="stat-item">
                <i class="fas fa-star"></i>
                <span>Очки: <?php echo $total_points; ?></span>
            </div>
            <div class="stat-item">
                <i class="fas fa-level-up-alt"></i>
                <span>Уровень: <?php echo $user_level; ?></span>
            </div>
        </div>

        <ul class="nav-links">
            <div class="nav-section">
                <div class="section-label">Основное</div>
            </div>
            <li><a href="dashboard.php"><i class="fas fa-home"></i> Главная</a></li>
            <li><a href="tests.php"><i class="fas fa-file-alt"></i> Тесты</a></li>
            <li><a href="results.php"><i class="fas fa-chart-bar"></i> Мои результаты</a></li>
            <li><a href="progress.php"><i class="fas fa-chart-line"></i> Мой прогресс</a></li>
            <li><a href="achievements.php" class="active"><i class="fas fa-trophy"></i> Мои достижения</a></li>
            
            <?php if ($user['role'] == 'teacher' || $user['role'] == 'admin'): ?>
            <div class="nav-section">
                <div class="section-label">Преподавание</div>
            </div>
            <li><a href="create_test.php"><i class="fas fa-plus-circle"></i> Создать тест</a></li>
            <li><a href="my_tests.php"><i class="fas fa-list"></i> Мои тесты</a></li>
            <li><a href="grading.php"><i class="fas fa-check-double"></i> Проверка работ</a></li>
            <?php endif; ?>

            <div class="nav-section">
                <div class="section-label">Аналитика</div>
            </div>
            <li><a href="analytics.php"><i class="fas fa-chart-line"></i> Статистика</a></li>

            <div class="nav-section">
                <div class="section-label">Система</div>
            </div>
            <li><a href="profile.php"><i class="fas fa-user-cog"></i> Профиль</a></li>
            <li><a href="settings.php"><i class="fas fa-cog"></i> Настройки</a></li>
            <li><a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Выход</a></li>
        </ul>

        <div class="sidebar-footer">
            <div class="system-info">
                <div class="info-item">
                    <i class="fas fa-database"></i>
                    <span>База данных: Активна</span>
                </div>
                <div class="info-item">
                    <i class="fas fa-robot"></i>
                    <span>AI Модели: Загружены</span>
                </div>
            </div>
            <div class="quick-actions">
                <button class="quick-btn" onclick="openHelp()">
                    <i class="fas fa-question-circle"></i> Помощь
                </button>
                <button class="quick-btn" onclick="openFeedback()">
                    <i class="fas fa-comment"></i> Отзыв
                </button>
            </div>
        </div>
    </div>

    <!-- Основной контент -->
    <div class="main-content">
        <div class="container">
            <!-- Хлебные крошки -->
            <a href="dashboard.php" class="back-button">
                <i class="fas fa-arrow-left"></i>
                Назад к панели
            </a>

            <div class="header">
                <h1><i class="fas fa-trophy"></i> Мои достижения</h1>
                <div class="header-actions">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="achievementSearch" placeholder="Поиск достижений...">
                    </div>
                    <div class="notification-bell" onclick="toggleNotifications()">
                        <i class="fas fa-bell"></i>
                        <?php if (count($notifications) > 0): ?>
                            <span class="notification-badge"><?php echo count($notifications); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Общая статистика -->
            <div class="stats-overview">
                <div class="stat-card success">
                    <div class="stat-icon icon-success">
                        <i class="fas fa-trophy"></i>
                    </div>
                    <div class="stat-details">
                        <h3><?php echo count($earned_achievements); ?>/<?php echo count($all_achievements); ?></h3>
                        <p>Получено достижений</p>
                        <div class="stat-trend">
                            <?php echo round((count($earned_achievements) / count($all_achievements)) * 100, 1); ?>% выполнено
                        </div>
                    </div>
                </div>
                
                <div class="stat-card info">
                    <div class="stat-icon icon-info">
                        <i class="fas fa-star"></i>
                    </div>
                    <div class="stat-details">
                        <h3><?php echo $total_points; ?></h3>
                        <p>Всего очков</p>
                        <div class="stat-trend">
                            Уровень <?php echo $user_level; ?>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card primary">
                    <div class="stat-icon icon-primary">
                        <i class="fas fa-medal"></i>
                    </div>
                    <div class="stat-details">
                        <h3><?php echo $user_stats['excellent_tests']; ?></h3>
                        <p>Отличных результатов</p>
                        <div class="stat-trend">
                            Лучший: <?php echo $user_stats['best_score'] ? round($user_stats['best_score'], 1) : 0; ?>%
                        </div>
                    </div>
                </div>
                
                <div class="stat-card warning">
                    <div class="stat-icon icon-warning">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <div class="stat-details">
                        <h3><?php echo $user_stats['active_days']; ?></h3>
                        <p>Активных дней</p>
                        <div class="stat-trend">
                            <?php echo $user_stats['unique_subjects']; ?> предметов
                        </div>
                    </div>
                </div>
            </div>

            <!-- Уровень и прогресс -->
            <div class="level-section">
                <div class="level-header">
                    <div class="level-badge">
                        <?php echo $user_level; ?>
                    </div>
                    <div class="level-info">
                        <h2>Уровень <?php echo $user_level; ?></h2>
                        <p>Продолжайте учиться, чтобы достичь новых высот!</p>
                    </div>
                </div>
                
                <div class="progress-container">
                    <div class="progress-label">
                        <span>Прогресс до уровня <?php echo $user_level + 1; ?></span>
                        <span><?php echo $current_level_points; ?>/100 очков</span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?php echo $level_progress; ?>%"></div>
                    </div>
                </div>
                
                <div class="points-info">
                    <div class="point-item">
                        <div class="point-value"><?php echo $total_points; ?></div>
                        <div class="point-label">Всего очков</div>
                    </div>
                    <div class="point-item">
                        <div class="point-value"><?php echo $next_level_points - $total_points; ?></div>
                        <div class="point-label">До след. уровня</div>
                    </div>
                    <div class="point-item">
                        <div class="point-value"><?php echo count($earned_achievements); ?></div>
                        <div class="point-label">Достижения</div>
                    </div>
                </div>
            </div>

            <!-- Достижения по категориям -->
            <div class="achievements-section">
                <!-- Прогресс обучения -->
                <div class="section">
                    <div class="section-header">
                        <h2><i class="fas fa-chart-line"></i> Прогресс обучения</h2>
                        <span><?php echo count(array_filter($progress_achievements, function($a) { return $a['earned']; })); ?>/<?php echo count($progress_achievements); ?> получено</span>
                    </div>
                    <div class="achievements-grid">
                        <?php foreach ($progress_achievements as $achievement): ?>
                            <div class="achievement-card <?php echo $achievement['earned'] ? 'earned' : 'locked'; ?>">
                                <div class="achievement-header">
                                    <div class="achievement-icon icon-<?php echo $achievement['color']; ?>">
                                        <i class="<?php echo $achievement['icon']; ?>"></i>
                                    </div>
                                    <div class="achievement-info">
                                        <div class="achievement-title"><?php echo $achievement['title']; ?></div>
                                        <div class="achievement-description"><?php echo $achievement['description']; ?></div>
                                        <div class="achievement-points">
                                            <i class="fas fa-star"></i>
                                            <?php echo $achievement['points']; ?> очков
                                        </div>
                                    </div>
                                </div>
                                <?php if (!$achievement['earned']): ?>
                                    <div class="locked-overlay">
                                        <i class="fas fa-lock lock-icon"></i>
                                        <span>Достижение заблокировано</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Успеваемость -->
                <div class="section">
                    <div class="section-header">
                        <h2><i class="fas fa-graduation-cap"></i> Успеваемость</h2>
                        <span><?php echo count(array_filter($performance_achievements, function($a) { return $a['earned']; })); ?>/<?php echo count($performance_achievements); ?> получено</span>
                    </div>
                    <div class="achievements-grid">
                        <?php foreach ($performance_achievements as $achievement): ?>
                            <div class="achievement-card <?php echo $achievement['earned'] ? 'earned' : 'locked'; ?>">
                                <div class="achievement-header">
                                    <div class="achievement-icon icon-<?php echo $achievement['color']; ?>">
                                        <i class="<?php echo $achievement['icon']; ?>"></i>
                                    </div>
                                    <div class="achievement-info">
                                        <div class="achievement-title"><?php echo $achievement['title']; ?></div>
                                        <div class="achievement-description"><?php echo $achievement['description']; ?></div>
                                        <div class="achievement-points">
                                            <i class="fas fa-star"></i>
                                            <?php echo $achievement['points']; ?> очков
                                        </div>
                                    </div>
                                </div>
                                <?php if (!$achievement['earned']): ?>
                                    <div class="locked-overlay">
                                        <i class="fas fa-lock lock-icon"></i>
                                        <span>Достижение заблокировано</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Активность -->
                <div class="section">
                    <div class="section-header">
                        <h2><i class="fas fa-running"></i> Активность</h2>
                        <span><?php echo count(array_filter($activity_achievements, function($a) { return $a['earned']; })); ?>/<?php echo count($activity_achievements); ?> получено</span>
                    </div>
                    <div class="achievements-grid">
                        <?php foreach ($activity_achievements as $achievement): ?>
                            <div class="achievement-card <?php echo $achievement['earned'] ? 'earned' : 'locked'; ?>">
                                <div class="achievement-header">
                                    <div class="achievement-icon icon-<?php echo $achievement['color']; ?>">
                                        <i class="<?php echo $achievement['icon']; ?>"></i>
                                    </div>
                                    <div class="achievement-info">
                                        <div class="achievement-title"><?php echo $achievement['title']; ?></div>
                                        <div class="achievement-description"><?php echo $achievement['description']; ?></div>
                                        <div class="achievement-points">
                                            <i class="fas fa-star"></i>
                                            <?php echo $achievement['points']; ?> очков
                                        </div>
                                    </div>
                                </div>
                                <?php if (!$achievement['earned']): ?>
                                    <div class="locked-overlay">
                                        <i class="fas fa-lock lock-icon"></i>
                                        <span>Достижение заблокировано</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Специальные -->
                <div class="section">
                    <div class="section-header">
                        <h2><i class="fas fa-fire"></i> Специальные достижения</h2>
                        <span><?php echo count(array_filter($special_achievements, function($a) { return $a['earned']; })); ?>/<?php echo count($special_achievements); ?> получено</span>
                    </div>
                    <div class="achievements-grid">
                        <?php foreach ($special_achievements as $achievement): ?>
                            <div class="achievement-card <?php echo $achievement['earned'] ? 'earned' : 'locked'; ?>">
                                <div class="achievement-header">
                                    <div class="achievement-icon icon-<?php echo $achievement['color']; ?>">
                                        <i class="<?php echo $achievement['icon']; ?>"></i>
                                    </div>
                                    <div class="achievement-info">
                                        <div class="achievement-title"><?php echo $achievement['title']; ?></div>
                                        <div class="achievement-description"><?php echo $achievement['description']; ?></div>
                                        <div class="achievement-points">
                                            <i class="fas fa-star"></i>
                                            <?php echo $achievement['points']; ?> очков
                                        </div>
                                    </div>
                                </div>
                                <?php if (!$achievement['earned']): ?>
                                    <div class="locked-overlay">
                                        <i class="fas fa-lock lock-icon"></i>
                                        <span>Достижение заблокировано</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Управление мобильным меню
        document.getElementById('mobileMenuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('mobile-open');
        });

        // Управление уведомлениями
        function toggleNotifications() {
            alert('Функция уведомлений будет реализована позже');
        }

        // Поиск достижений
        document.getElementById('achievementSearch').addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const achievementCards = document.querySelectorAll('.achievement-card');
            
            achievementCards.forEach(card => {
                const title = card.querySelector('.achievement-title').textContent.toLowerCase();
                const description = card.querySelector('.achievement-description').textContent.toLowerCase();
                
                if (title.includes(searchTerm) || description.includes(searchTerm)) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        });

        // Анимация прогресс-баров
        document.addEventListener('DOMContentLoaded', function() {
            const progressBars = document.querySelectorAll('.progress-fill');
            progressBars.forEach(bar => {
                const width = bar.style.width;
                bar.style.width = '0';
                setTimeout(() => {
                    bar.style.width = width;
                }, 500);
            });
        });

        function openHelp() {
            alert('Раздел помощи будет реализован позже');
        }

        function openFeedback() {
            alert('Форма обратной связи будет реализована позже');
        }
    </script>
</body>
</html>