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
    // Статистика для студента
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_tests, 
            COALESCE(SUM(score), 0) as total_score,
            COALESCE(SUM(total_points), 0) as total_points,
            AVG(percentage) as avg_percentage,
            COUNT(CASE WHEN passed = 1 THEN 1 END) as passed_tests,
            MAX(percentage) as best_score
        FROM test_results 
        WHERE user_id = ?
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $stats = $stmt->fetch();
    
    // Прогресс по неделям
    $stmt = $pdo->prepare("
        SELECT 
            YEARWEEK(completed_at) as week,
            AVG(percentage) as avg_score,
            COUNT(*) as tests_count
        FROM test_results 
        WHERE user_id = ? AND completed_at >= DATE_SUB(NOW(), INTERVAL 8 WEEK)
        GROUP BY YEARWEEK(completed_at)
        ORDER BY week DESC
        LIMIT 8
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $weekly_progress = $stmt->fetchAll();
    
    // Последние пройденные тесты
    $stmt = $pdo->prepare("
        SELECT t.title, tr.score, tr.total_points, tr.percentage, tr.passed, tr.completed_at,
               u.full_name as teacher_name, t.subject
        FROM test_results tr
        JOIN tests t ON tr.test_id = t.id
        JOIN users u ON t.created_by = u.id
        WHERE tr.user_id = ?
        ORDER BY tr.completed_at DESC
        LIMIT 5
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $recent_tests = $stmt->fetchAll();
    
    // Доступные тесты
    $stmt = $pdo->prepare("
        SELECT t.*, u.full_name as creator_name, t.subject,
               (SELECT COUNT(*) FROM test_results WHERE test_id = t.id) as total_attempts,
               (SELECT AVG(percentage) FROM test_results WHERE test_id = t.id) as avg_success
        FROM tests t
        JOIN users u ON t.created_by = u.id
        WHERE t.id NOT IN (
            SELECT test_id FROM test_results WHERE user_id = ?
        ) AND t.is_active = 1
        ORDER BY t.created_at DESC
        LIMIT 6
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $available_tests = $stmt->fetchAll();
    
    // Предстоящие дедлайны
    $stmt = $pdo->prepare("
        SELECT t.*, u.full_name as creator_name,
               DATE_ADD(t.created_at, INTERVAL t.time_limit DAY) as deadline
        FROM tests t
        JOIN users u ON t.created_by = u.id
        WHERE t.id NOT IN (
            SELECT test_id FROM test_results WHERE user_id = ?
        ) AND t.is_active = 1
        AND DATE_ADD(t.created_at, INTERVAL t.time_limit DAY) >= NOW()
        ORDER BY deadline ASC
        LIMIT 5
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $upcoming_deadlines = $stmt->fetchAll();
    
} else if ($user['role'] == 'teacher' || $user['role'] == 'admin') {
    // Статистика для преподавателя/администратора
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_tests_created,
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
            )) as total_questions
        FROM tests
        WHERE created_by = ?
    ");
    $stmt->execute([$_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']]);
    $stats = $stmt->fetch();
    
    // Активность студентов за последнюю неделю
    $stmt = $pdo->prepare("
        SELECT 
            DATE(tr.completed_at) as date,
            COUNT(*) as tests_taken,
            COUNT(DISTINCT tr.user_id) as active_students,
            AVG(tr.percentage) as avg_score
        FROM test_results tr
        JOIN tests t ON tr.test_id = t.id
        WHERE t.created_by = ? AND tr.completed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY DATE(tr.completed_at)
        ORDER BY date DESC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $weekly_activity = $stmt->fetchAll();
    
    // Последние созданные тесты
    $stmt = $pdo->prepare("
        SELECT t.*, 
               COUNT(tr.id) as attempts,
               AVG(tr.percentage) as avg_score,
               COUNT(DISTINCT tr.user_id) as unique_students
        FROM tests t
        LEFT JOIN test_results tr ON t.id = tr.test_id
        WHERE t.created_by = ?
        GROUP BY t.id
        ORDER BY t.created_at DESC
        LIMIT 6
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $recent_tests = $stmt->fetchAll();
    
    // Активные ML-модели
    $stmt = $pdo->prepare("
        SELECT * FROM ml_models 
        WHERE is_active = TRUE
        ORDER BY accuracy DESC, created_at DESC
        LIMIT 4
    ");
    $stmt->execute();
    $ml_models = $stmt->fetchAll();
    
    // Последняя активность студентов
    $stmt = $pdo->prepare("
        SELECT u.full_name, t.title, tr.score, tr.total_points, tr.percentage, tr.completed_at,
               u.group_name
        FROM test_results tr
        JOIN users u ON tr.user_id = u.id
        JOIN tests t ON tr.test_id = t.id
        WHERE t.created_by = ?
        ORDER BY tr.completed_at DESC
        LIMIT 8
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $student_activity = $stmt->fetchAll();
    
    // Тесты требующие проверки
    $stmt = $pdo->prepare("
        SELECT tr.id, u.full_name, t.title, tr.completed_at,
               (SELECT COUNT(*) FROM user_answers ua 
                WHERE ua.result_id = tr.id AND ua.question_id IN (
                    SELECT id FROM questions WHERE question_type = 'text'
                )) as text_answers_count
        FROM test_results tr
        JOIN users u ON tr.user_id = u.id
        JOIN tests t ON tr.test_id = t.id
        WHERE t.created_by = ? AND tr.needs_review = 1
        ORDER BY tr.completed_at DESC
        LIMIT 5
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $tests_to_review = $stmt->fetchAll();
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
    <title>Главная панель - Система интеллектуальной оценки знаний</title>
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
            overflow-x: hidden;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            box-shadow: 2px 0 15px rgba(0, 0, 0, 0.1);
            
            /* Скрываем скроллбар но оставляем функциональность */
            scrollbar-width: none; /* Firefox */
            -ms-overflow-style: none; /* IE и Edge */
        }

        /* Скрываем скроллбар для Webkit браузеров (Chrome, Safari, Opera) */
        .sidebar::-webkit-scrollbar {
            width: 0px;
            background: transparent;
        }

        .sidebar::-webkit-scrollbar-track {
            background: transparent;
        }

        .sidebar::-webkit-scrollbar-thumb {
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

        .status-indicator.online {
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

        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
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
            
            /* Скрываем скроллбар в навигации */
            scrollbar-width: none;
            -ms-overflow-style: none;
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

        .nav-badge {
            background: var(--accent);
            color: white;
            border-radius: 10px;
            padding: 2px 8px;
            font-size: 11px;
            font-weight: 600;
            margin-left: auto;
        }

        /* Подменю */
        .has-submenu {
            position: relative;
        }

        .submenu {
            display: none;
            list-style: none;
            background: rgba(0, 0, 0, 0.2);
            border-radius: 0 0 8px 8px;
            margin: 0;
        }

        .has-submenu.active .submenu {
            display: block;
        }

        .submenu a {
            padding: 10px 20px 10px 50px !important;
            font-size: 14px;
        }

        .dropdown-arrow {
            margin-left: auto;
            transition: transform 0.3s;
            font-size: 12px;
        }

        .has-submenu.active .dropdown-arrow {
            transform: rotate(180deg);
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
        
        .search-box i {
            color: var(--gray);
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

        .stat-card.primary {
            border-left-color: var(--primary);
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
        
        .stat-trend {
            font-size: 14px;
            font-weight: 600;
        }
        
        .trend-up {
            color: var(--success);
        }
        
        .trend-down {
            color: var(--danger);
        }
        
        /* Sections */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 25px;
            margin-bottom: 25px;
        }

        .main-content-column {
            display: flex;
            flex-direction: column;
            gap: 25px;
        }

        .sidebar-column {
            display: flex;
            flex-direction: column;
            gap: 25px;
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
        
        .section-header h2 i {
            color: var(--primary);
        }
        
        .view-all {
            color: var(--primary);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: color 0.3s;
        }
        
        .view-all:hover {
            color: var(--primary-dark);
        }
        
        /* Test Items */
        .test-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .test-item {
            padding: 20px;
            background: var(--light);
            border-radius: 12px;
            transition: all 0.3s;
            cursor: pointer;
            border-left: 4px solid var(--primary);
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        
        .test-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            transform: scaleX(0);
            transition: transform 0.3s;
        }
        
        .test-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }
        
        .test-item:hover::before {
            transform: scaleX(1);
        }
        
        .test-item.success {
            border-left-color: var(--success);
        }
        
        .test-item.warning {
            border-left-color: var(--warning);
        }
        
        .test-item.danger {
            border-left-color: var(--danger);
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
            margin-bottom: 12px;
        }
        
        .test-score {
            font-weight: 600;
            font-size: 14px;
        }
        
        .score-excellent {
            color: var(--success);
        }
        
        .score-good {
            color: var(--warning);
        }
        
        .score-poor {
            color: var(--danger);
        }
        
        .test-subject {
            background: rgba(52, 152, 219, 0.1);
            color: var(--primary);
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
            margin-bottom: 10px;
        }
        
        .test-actions {
            display: flex;
            justify-content: flex-end;
            margin-top: 15px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-align: center;
            justify-content: center;
        }
        
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }
        
        .btn-success {
            background: var(--success);
            color: white;
        }
        
        .btn-success:hover {
            background: #27ae60;
            transform: translateY(-2px);
        }
        
        .btn-warning {
            background: var(--warning);
            color: white;
        }
        
        .btn-warning:hover {
            background: #e67e22;
            transform: translateY(-2px);
        }
        
        /* ML Models */
        .model-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
        }
        
        .model-item {
            padding: 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 12px;
            color: white;
            position: relative;
            overflow: hidden;
        }
        
        .model-item::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 100%;
            background: rgba(255,255,255,0.1);
            transform: rotate(30deg);
        }
        
        .model-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 10px;
            position: relative;
            z-index: 1;
        }
        
        .model-meta {
            font-size: 13px;
            margin-bottom: 10px;
            opacity: 0.9;
            position: relative;
            z-index: 1;
        }
        
        .model-accuracy {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 600;
            background: rgba(255,255,255,0.2);
            color: white;
            position: relative;
            z-index: 1;
        }
        
        /* Activity Table */
        .activity-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .activity-table th, .activity-table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .activity-table th {
            background-color: var(--light);
            font-weight: 600;
            color: var(--secondary);
            font-size: 14px;
        }
        
        .activity-table tr:hover {
            background-color: #f8f9fa;
        }
        
        .user-avatar {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 14px;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        /* Progress Chart */
        .chart-container {
            height: 300px;
            position: relative;
        }
        
        /* Notifications */
        .notifications-list {
            max-height: 400px;
            overflow-y: auto;
            
            /* Скрываем скроллбар в уведомлениях */
            scrollbar-width: none;
            -ms-overflow-style: none;
        }

        .notifications-list::-webkit-scrollbar {
            display: none;
        }
        
        .notification-item {
            padding: 15px;
            border-left: 3px solid var(--primary);
            background: #f8f9fa;
            margin-bottom: 10px;
            border-radius: 8px;
            transition: all 0.3s;
        }
        
        .notification-item:hover {
            background: #e9ecef;
            transform: translateX(5px);
        }
        
        .notification-item.unread {
            background: white;
            border-left-color: var(--accent);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .notification-title {
            font-weight: 600;
            margin-bottom: 5px;
            color: var(--secondary);
        }
        
        .notification-message {
            font-size: 14px;
            color: var(--gray);
            margin-bottom: 8px;
        }
        
        .notification-time {
            font-size: 12px;
            color: var(--gray);
            display: flex;
            align-items: center;
            gap: 5px;
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

        /* ===== МОДАЛЬНЫЕ ОКНА ===== */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 1000;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .modal-overlay.active {
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 1;
        }

        .modal-content {
            background: white;
            border-radius: 15px;
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow: hidden;
            transform: scale(0.9);
            transition: transform 0.3s ease;
        }

        .modal-overlay.active .modal-content {
            transform: scale(1);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 25px;
            border-bottom: 1px solid #e0e0e0;
            background: var(--light);
        }

        .modal-header h2 {
            margin: 0;
            color: var(--secondary);
            font-size: 20px;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--gray);
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s;
        }

        .modal-close:hover {
            background: rgba(0, 0, 0, 0.1);
            color: var(--danger);
        }

        .modal-body {
            padding: 0;
            height: 60vh;
        }

        .modal-body iframe {
            width: 100%;
            height: 100%;
            border: none;
        }

        /* Модальные окна для AI ассистента и поиска */
        .assistant-modal,
        .search-modal {
            max-width: 500px;
        }

        .assistant-chat {
            height: 400px;
            display: flex;
            flex-direction: column;
        }

        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            
            /* Скрываем скроллбар в чате */
            scrollbar-width: none;
            -ms-overflow-style: none;
        }

        .chat-messages::-webkit-scrollbar {
            display: none;
        }

        .message {
            display: flex;
            margin-bottom: 15px;
            gap: 10px;
        }

        .user-message {
            justify-content: flex-end;
        }

        .message-avatar {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 12px;
        }

        .message-content {
            max-width: 70%;
            padding: 10px 15px;
            border-radius: 15px;
            background: var(--light);
        }

        .user-message .message-content {
            background: var(--primary);
            color: white;
        }

        .chat-input {
            display: flex;
            padding: 15px;
            border-top: 1px solid #e0e0e0;
            gap: 10px;
        }

        .chat-input input {
            flex: 1;
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 20px;
            outline: none;
        }

        .chat-input button {
            background: var(--primary);
            color: white;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            cursor: pointer;
        }

        .search-container {
            padding: 20px;
        }

        .search-container input {
            width: 100%;
            padding: 12px 20px;
            border: 1px solid #ddd;
            border-radius: 25px;
            outline: none;
            font-size: 16px;
        }

        .search-results {
            margin-top: 15px;
            max-height: 300px;
            overflow-y: auto;
            
            /* Скрываем скроллбар в результатах поиска */
            scrollbar-width: none;
            -ms-overflow-style: none;
        }

        .search-results::-webkit-scrollbar {
            display: none;
        }

        .search-result-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px;
            border-bottom: 1px solid #f0f0f0;
            cursor: pointer;
        }

        .search-result-item:hover {
            background: var(--light);
        }

        .search-result-item i {
            color: var(--primary);
        }

        /* ===== АНИМАЦИИ ===== */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .stat-card, .test-item, .section {
            animation: fadeInUp 0.6s ease forwards;
        }

        /* ===== АДАПТИВНОСТЬ ===== */
        @media (max-width: 1200px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-cards {
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            }

            .sidebar-column {
                order: -1;
            }
        }
        
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
            
            .test-list {
                grid-template-columns: 1fr;
            }
            
            .model-list {
                grid-template-columns: 1fr;
            }
            
            .stats-cards {
                grid-template-columns: 1fr;
            }
            
            .header-actions {
                width: 100%;
                justify-content: space-between;
            }

            .modal-content {
                width: 95%;
                margin: 20px;
            }
            
            .modal-body {
                height: 50vh;
            }

            .user-info {
                flex-direction: column;
                text-align: center;
            }

            .quick-actions {
                flex-direction: column;
            }
        }
</style>
</head>
<body>
    <!-- Подключаем меню -->
    <?php include 'sidebar_menu.php'; ?>
    
    <!-- Main Content -->
    <div class="main-content">
        <div class="header">
            <div class="welcome">
                <h2>Добро пожаловать в систему интеллектуальной оценки знаний!</h2>
                <?php
                $nameParts = explode(' ', $user['full_name']);
                $firstName = $nameParts[1] ?? $user['full_name'];
                echo "<p>Рады видеть вас снова, $firstName! Готовы к новым достижениям?</p>";
                ?>
            </div>
            
            <div class="header-actions">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" id="globalSearch" placeholder="Поиск тестов, материалов, студентов...">
                </div>
                <div class="notification-bell" onclick="toggleNotifications()">
                    <i class="fas fa-bell"></i>
                    <?php if (count($notifications) > 0): ?>
                        <span class="notification-badge"><?php echo count($notifications); ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
       <!-- Уведомления -->
<?php if (!empty($notifications)): ?>
<div class="section" id="notificationsPanel" style="display: none;">
    <div class="section-header">
        <h2><i class="fas fa-bell"></i> Все уведомления</h2>
        <button class="btn btn-primary" onclick="markAllAsRead()">Отметить все как прочитанные</button>
    </div>
    <div class="notifications-list">
        <?php foreach ($notifications as $notification): ?>
            <div class="notification-item <?php echo $notification['is_read'] ? '' : 'unread'; ?>">
                <div class="notification-title"><?php echo htmlspecialchars($notification['title']); ?></div>
                <div class="notification-message"><?php echo htmlspecialchars($notification['message']); ?></div>
                <div class="notification-time">
                    <i class="fas fa-clock"></i>
                    <?php echo date('d.m.Y H:i', strtotime($notification['created_at'])); ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
        
        <!-- Статистические карточки -->
        <div class="stats-cards">
            <?php if ($user['role'] == 'student'): ?>
                <div class="stat-card success">
                    <div class="stat-icon icon-success">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-details">
                        <h3><?php echo $stats['passed_tests']; ?></h3>
                        <p>Успешно пройденных тестов</p>
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
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div class="stat-details">
                        <h3><?php echo $stats['total_tests']; ?></h3>
                        <p>Всего пройденных тестов</p>
                        <div class="stat-trend trend-up">
                            <i class="fas fa-calendar"></i>
                            Активность: <?php echo count($weekly_progress); ?> недели
                        </div>
                    </div>
                </div>
                
                <div class="stat-card warning">
                    <div class="stat-icon icon-warning">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-details">
                        <h3><?php echo count($upcoming_deadlines); ?></h3>
                        <p>Предстоящих дедлайнов</p>
                        <div class="stat-trend trend-down">
                            <i class="fas fa-exclamation-circle"></i>
                            Ближайший: <?php echo !empty($upcoming_deadlines) ? date('d.m', strtotime($upcoming_deadlines[0]['deadline'])) : 'нет'; ?>
                        </div>
                    </div>
                </div>
                
            <?php else: ?>
                <div class="stat-card primary">
                    <div class="stat-icon icon-primary">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div class="stat-details">
                        <h3><?php echo $stats['total_tests_created']; ?></h3>
                        <p>Созданных тестов</p>
                        <div class="stat-trend trend-up">
                            <i class="fas fa-plus"></i>
                            <?php echo $stats['total_questions']; ?> вопросов
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
                        <h3><?php echo count($tests_to_review); ?></h3>
                        <p>Тестов на проверке</p>
                        <div class="stat-trend trend-down">
                            <i class="fas fa-clock"></i>
                            Требуют внимания
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="dashboard-grid">
            <!-- Основной контент -->
            <div class="main-content-column">
                <?php if ($user['role'] == 'student'): ?>
                    <!-- Секция для студентов -->
                    <div class="section">
                        <div class="section-header">
                            <h2><i class="fas fa-rocket"></i> Доступные тесты</h2>
                            <a href="tests.php" class="view-all">
                                Все тесты <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                        
                        <div class="test-list">
                            <?php if (count($available_tests) > 0): ?>
                                <?php foreach ($available_tests as $test): ?>
                                    <div class="test-item">
                                        <div class="test-subject"><?php echo htmlspecialchars($test['subject']); ?></div>
                                        <div class="test-title"><?php echo htmlspecialchars($test['title']); ?></div>
                                        <div class="test-meta">
                                            <span>Автор: <?php echo htmlspecialchars($test['creator_name']); ?></span>
                                            <span><?php echo $test['time_limit']; ?> мин</span>
                                        </div>
                                        <div class="test-meta">
                                            <span>Попыток: <?php echo $test['total_attempts']; ?></span>
                                            <span>Успех: <?php echo round($test['avg_success'], 1); ?>%</span>
                                        </div>
                                        <div class="test-actions">
                                            <a href="take_test.php?id=<?php echo $test['id']; ?>" class="btn btn-primary">
                                                <i class="fas fa-play"></i> Начать тест
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-inbox"></i>
                                    <p>Пока нет доступных тестов. Обратитесь к преподавателю.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="section">
                        <div class="section-header">
                            <h2><i class="fas fa-history"></i> Недавно пройденные тесты</h2>
                            <a href="results.php" class="view-all">
                                Вся история <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                        
                        <div class="test-list">
                            <?php if (count($recent_tests) > 0): ?>
                                <?php foreach ($recent_tests as $test): ?>
                                    <div class="test-item <?php echo $test['passed'] ? 'success' : 'danger'; ?>">
                                        <div class="test-subject"><?php echo htmlspecialchars($test['subject']); ?></div>
                                        <div class="test-title"><?php echo htmlspecialchars($test['title']); ?></div>
                                        <div class="test-meta">
                                            <span>Преподаватель: <?php echo htmlspecialchars($test['teacher_name']); ?></span>
                                            <span><?php echo date('d.m.Y', strtotime($test['completed_at'])); ?></span>
                                        </div>
                                        <div class="test-meta">
                                            <span>Результат:</span>
                                            <span class="test-score <?php 
                                                echo $test['percentage'] >= 80 ? 'score-excellent' : 
                                                     ($test['percentage'] >= 60 ? 'score-good' : 'score-poor'); 
                                            ?>">
                                                <?php echo $test['score']; ?>/<?php echo $test['total_points']; ?> 
                                                (<?php echo round($test['percentage'], 1); ?>%)
                                            </span>
                                        </div>
                                        <div class="test-actions">
                                            <a href="test_result.php?id=<?php echo $test['id']; ?>" class="btn btn-success">
                                                <i class="fas fa-chart-bar"></i> Подробнее
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-clipboard-list"></i>
                                    <p>Вы еще не прошли ни одного теста.</p>
                                    <a href="tests.php" class="btn btn-primary" style="margin-top: 15px;">
                                        <i class="fas fa-play"></i> Найти тесты
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                <?php else: ?>
                    <!-- Секция для преподавателей/администраторов -->
                    <div class="section">
                        <div class="section-header">
                            <h2><i class="fas fa-file-alt"></i> Мои тесты</h2>
                            <a href="tests.php" class="view-all">
                                Все тесты <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                        
                        <div class="test-list">
                            <?php if (count($recent_tests) > 0): ?>
                                <?php foreach ($recent_tests as $test): ?>
                                    <div class="test-item">
                                        <div class="test-title"><?php echo htmlspecialchars($test['title']); ?></div>
                                        <div class="test-meta">
                                            <span>Создан: <?php echo date('d.m.Y', strtotime($test['created_at'])); ?></span>
                                            <span>Вопросов: <?php echo $test['question_count'] ?? 'N/A'; ?></span>
                                        </div>
                                        <div class="test-meta">
                                            <span>Попыток: <?php echo $test['attempts']; ?></span>
                                            <span>Студентов: <?php echo $test['unique_students']; ?></span>
                                        </div>
                                        <div class="test-meta">
                                            <span>Средний результат: </span>
                                            <span class="test-score <?php 
                                                echo $test['avg_score'] >= 80 ? 'score-excellent' : 
                                                     ($test['avg_score'] >= 60 ? 'score-good' : 'score-poor'); 
                                            ?>">
                                                <?php echo round($test['avg_score'], 1); ?>%
                                            </span>
                                        </div>
                                        <div class="test-actions">
                                            <button class="btn btn-primary manage-test-btn" data-test-id="<?php echo $test['id']; ?>">
                                                <i class="fas fa-cog"></i> Управление
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-file-alt"></i>
                                    <p>Вы еще не создали ни одного теста.</p>
                                    <a href="create_test.php" class="btn btn-primary" style="margin-top: 15px;">
                                        <i class="fas fa-plus"></i> Создать первый тест
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="section">
                        <div class="section-header">
                            <h2><i class="fas fa-chart-bar"></i> Активность студентов</h2>
                            <a href="analytics.php" class="view-all">
                                Подробная аналитика <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                        
                        <div class="chart-container">
                            <canvas id="activityChart"></canvas>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Боковая панель -->
            <div class="sidebar-column">
                <?php if ($user['role'] == 'student'): ?>
                    <!-- Для студентов -->
                    <div class="section">
                        <div class="section-header">
                            <h2><i class="fas fa-clock"></i> Ближайшие дедлайны</h2>
                        </div>
                        <?php if (count($upcoming_deadlines) > 0): ?>
                            <?php foreach ($upcoming_deadlines as $deadline): ?>
                                <div class="test-item warning">
                                    <div class="test-title"><?php echo htmlspecialchars($deadline['title']); ?></div>
                                    <div class="test-meta">
                                        <span>До: <?php echo date('d.m.Y H:i', strtotime($deadline['deadline'])); ?></span>
                                    </div>
                                    <div class="test-actions">
                                        <a href="take_test.php?id=<?php echo $deadline['id']; ?>" class="btn btn-warning">
                                            <i class="fas fa-play"></i> Пройти
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-check-circle"></i>
                                <p>Нет предстоящих дедлайнов</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="section">
                        <div class="section-header">
                            <h2><i class="fas fa-trophy"></i> Ваш прогресс</h2>
                        </div>
                        <div class="chart-container">
                            <canvas id="progressChart"></canvas>
                        </div>
                    </div>
                    
                <?php else: ?>
                    <!-- Для преподавателей -->
                    <div class="section">
                        <div class="section-header">
                            <h2><i class="fas fa-robot"></i> Активные ML модели</h2>
                            <a href="ml_models.php" class="view-all">
                                Все модели <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                        
                        <div class="model-list">
                            <?php if (count($ml_models) > 0): ?>
                                <?php foreach ($ml_models as $model): ?>
                                    <div class="model-item">
                                        <div class="model-title"><?php echo htmlspecialchars($model['name']); ?></div>
                                        <div class="model-meta"><?php echo htmlspecialchars($model['description']); ?></div>
                                        <div class="model-accuracy">
                                            Точность: <?php echo round($model['accuracy'] * 100, 1); ?>%
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-robot"></i>
                                    <p>Нет активных ML моделей</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="section">
                        <div class="section-header">
                            <h2><i class="fas fa-check-double"></i> Тесты на проверке</h2>
                        </div>
                        <?php if (count($tests_to_review) > 0): ?>
                            <?php foreach ($tests_to_review as $review): ?>
                                <div class="test-item danger">
                                    <div class="test-title"><?php echo htmlspecialchars($review['title']); ?></div>
                                    <div class="test-meta">
                                        <span>Студент: <?php echo htmlspecialchars($review['full_name']); ?></span>
                                    </div>
                                    <div class="test-meta">
                                        <span>Текстовых ответов: <?php echo $review['text_answers_count']; ?></span>
                                    </div>
                                    <div class="test-actions">
                                        <a href="grading.php?result_id=<?php echo $review['id']; ?>" class="btn btn-warning">
                                            <i class="fas fa-check"></i> Проверить
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-check-circle"></i>
                                <p>Нет тестов на проверке</p>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <!-- Общая секция для всех -->
                <div class="section">
                    <div class="section-header">
                        <h2><i class="fas fa-bullhorn"></i> Последние уведомления</h2>
                    </div>
                    <div class="notifications-list">
                        <?php if (!empty($notifications)): ?>
                            <?php foreach (array_slice($notifications, 0, 3) as $notification): ?>
                                <div class="notification-item <?php echo $notification['is_read'] ? '' : 'unread'; ?>">
                                    <div class="notification-title"><?php echo htmlspecialchars($notification['title']); ?></div>
                                    <div class="notification-message"><?php echo htmlspecialchars($notification['message']); ?></div>
                                    <div class="notification-time">
                                        <i class="fas fa-clock"></i>
                                        <?php echo date('d.m.Y H:i', strtotime($notification['created_at'])); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-bell-slash"></i>
                                <p>Нет новых уведомлений</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Дополнительные секции -->
        <?php if ($user['role'] == 'teacher' || $user['role'] == 'admin'): ?>
        <div class="section section-full">
            <div class="section-header">
                <h2><i class="fas fa-users"></i> Последняя активность студентов</h2>
                <a href="students.php" class="view-all">
                    Все студенты <i class="fas fa-arrow-right"></i>
                </a>
            </div>
            
            <table class="activity-table">
                <thead>
                    <tr>
                        <th>Студент</th>
                        <th>Тест</th>
                        <th>Результат</th>
                        <th>Группа</th>
                        <th>Дата</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($student_activity) > 0): ?>
                        <?php foreach ($student_activity as $activity): ?>
                            <tr>
                                <td>
                                    <div class="user-info">
                                        <div class="user-avatar">
                                            <?php 
                                            $firstName = $activity['full_name'];
                                            if (function_exists('mb_convert_encoding')) {
                                                $firstName = mb_convert_encoding($firstName, 'UTF-8', 'auto');
                                            }
                                            $firstLetter = mb_substr($firstName, 0, 1, 'UTF-8');
                                            echo htmlspecialchars(strtoupper($firstLetter));
                                            ?>
                                        </div>
                                        <?php echo htmlspecialchars($activity['full_name']); ?>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($activity['title']); ?></td>
                                <td>
                                    <span class="test-score <?php 
                                        echo $activity['percentage'] >= 80 ? 'score-excellent' : 
                                             ($activity['percentage'] >= 60 ? 'score-good' : 'score-poor'); 
                                    ?>">
                                        <?php echo $activity['score']; ?>/<?php echo $activity['total_points']; ?> 
                                        (<?php echo round($activity['percentage'], 1); ?>%)
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($activity['group_name'] ?? 'Не указана'); ?></td>
                                <td><?php echo date('d.m.Y H:i', strtotime($activity['completed_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 40px;">
                                <div class="empty-state">
                                    <i class="fas fa-users"></i>
                                    <p>Нет данных об активности студентов</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Модальное окно для управления тестом -->
    <div class="modal-overlay" id="testModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Управление тестом</h2>
                <button class="modal-close" id="modalClose">&times;</button>
            </div>
            <div class="modal-body">
                <iframe id="testIframe" src="" frameborder="0"></iframe>
            </div>
        </div>
    </div>

    <script>
// Управление уведомлениями
function toggleNotifications() {
    const panel = document.getElementById('notificationsPanel');
    if (panel) {
        panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
    }
}

function markAllAsRead() {
    fetch('mark_notifications_read.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            document.querySelectorAll('.notification-item.unread').forEach(item => {
                item.classList.remove('unread');
            });
            const badge = document.querySelector('.notification-badge');
            if (badge) {
                badge.style.display = 'none';
            }
        }
    })
    .catch(error => {
        console.error('Error:', error);
    });
}

// Управление модальным окном для преподавателей
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('testModal');
    const modalClose = document.getElementById('modalClose');
    const testIframe = document.getElementById('testIframe');
    const manageTestBtns = document.querySelectorAll('.manage-test-btn');
    
    // Создаем модальное окно, если его нет
    if (!modal) {
        const modalHTML = `
            <div class="modal-overlay" id="testModal">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2>Управление тестом</h2>
                        <button class="modal-close" id="modalClose">&times;</button>
                    </div>
                    <div class="modal-body">
                        <iframe id="testIframe" src="" frameborder="0"></iframe>
                    </div>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modalHTML);
    }
    
    // Обновляем ссылки после создания модального окна
    const updatedModal = document.getElementById('testModal');
    const updatedModalClose = document.getElementById('modalClose');
    const updatedTestIframe = document.getElementById('testIframe');
    
    if (manageTestBtns.length > 0) {
        manageTestBtns.forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const testId = this.getAttribute('data-test-id');
                if (updatedTestIframe) {
                    updatedTestIframe.src = `test_edit.php?id=${testId}`;
                }
                if (updatedModal) {
                    updatedModal.classList.add('active');
                    document.body.style.overflow = 'hidden';
                }
            });
        });
    }
    
    if (updatedModalClose) {
        updatedModalClose.addEventListener('click', function() {
            closeModal();
        });
    }
    
    if (updatedModal) {
        updatedModal.addEventListener('click', function(e) {
            if (e.target === updatedModal) {
                closeModal();
            }
        });
    }
    
    function closeModal() {
        const modal = document.getElementById('testModal');
        if (modal) {
            modal.classList.remove('active');
            document.body.style.overflow = 'auto';
        }
        const iframe = document.getElementById('testIframe');
        if (iframe) {
            setTimeout(() => {
                iframe.src = '';
            }, 300);
        }
    }
    
    // Закрытие по ESC
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeModal();
        }
    });
});

// Инициализация графиков
document.addEventListener('DOMContentLoaded', function() {
    <?php if ($user['role'] == 'student' && !empty($weekly_progress)): ?>
    // График прогресса для студента
    const progressCtx = document.getElementById('progressChart').getContext('2d');
    const progressChart = new Chart(progressCtx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode(array_map(function($week) { 
                return 'Неделя ' . substr($week['week'], 4); 
            }, array_reverse($weekly_progress))); ?>,
            datasets: [{
                label: 'Средний результат (%)',
                data: <?php echo json_encode(array_map(function($week) { 
                    return round($week['avg_score'], 1); 
                }, array_reverse($weekly_progress))); ?>,
                borderColor: '#3498db',
                backgroundColor: 'rgba(52, 152, 219, 0.1)',
                tension: 0.4,
                fill: true
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100,
                    ticks: {
                        callback: function(value) {
                            return value + '%';
                        }
                    }
                }
            }
        }
    });
    <?php elseif (($user['role'] == 'teacher' || $user['role'] == 'admin') && !empty($weekly_activity)): ?>
    // График активности для преподавателя
    const activityCtx = document.getElementById('activityChart').getContext('2d');
    const activityChart = new Chart(activityCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_map(function($day) { 
                return date('d.m', strtotime($day['date'])); 
            }, array_reverse($weekly_activity))); ?>,
            datasets: [
                {
                    label: 'Активные студенты',
                    data: <?php echo json_encode(array_map(function($day) { 
                        return $day['active_students']; 
                    }, array_reverse($weekly_activity))); ?>,
                    backgroundColor: 'rgba(52, 152, 219, 0.8)',
                    yAxisID: 'y'
                },
                {
                    label: 'Средний результат (%)',
                    data: <?php echo json_encode(array_map(function($day) { 
                        return round($day['avg_score'], 1); 
                    }, array_reverse($weekly_activity))); ?>,
                    borderColor: '#2ecc71',
                    backgroundColor: 'rgba(46, 204, 113, 0.2)',
                    type: 'line',
                    yAxisID: 'y1'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    title: {
                        display: true,
                        text: 'Студенты'
                    }
                },
                y1: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    max: 100,
                    title: {
                        display: true,
                        text: 'Результат (%)'
                    },
                    grid: {
                        drawOnChartArea: false
                    },
                    ticks: {
                        callback: function(value) {
                            return value + '%';
                        }
                    }
                }
            }
        }
    });
    <?php endif; ?>
});

// Глобальный поиск
document.getElementById('globalSearch').addEventListener('input', function() {
    const query = this.value.trim();
    if (query.length > 2) {
        // Здесь будет реализация поиска
        console.log('Поиск:', query);
    }
});

// Анимация при загрузке
document.addEventListener('DOMContentLoaded', function() {
    // Анимация появления элементов
    const cards = document.querySelectorAll('.stat-card, .test-item, .section');
    cards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(20px)';
        setTimeout(() => {
            card.style.transition = 'all 0.6s ease';
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        }, index * 100);
    });
});
</script>
</body>
</html>