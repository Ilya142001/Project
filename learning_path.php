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

// Проверяем, что пользователь - студент
if ($user['role'] != 'student') {
    header("Location: index.php");
    exit;
}

// Проверяем существование таблицы learning_paths
$table_exists = false;
try {
    $stmt = $pdo->query("SELECT 1 FROM learning_paths LIMIT 1");
    $table_exists = true;
} catch (PDOException $e) {
    $table_exists = false;
}

// Получаем траекторию обучения студента
$learning_path = [];
if ($table_exists) {
    try {
        $stmt = $pdo->prepare("
            SELECT lp.*, t.title as topic_title, t.description as topic_description, 
                   t.difficulty_level, t.estimated_time, t.subject,
                   (SELECT COUNT(*) FROM questions WHERE topic_id = lp.topic_id) as total_questions,
                   (SELECT COUNT(*) FROM test_results tr 
                    JOIN tests ts ON tr.test_id = ts.id 
                    WHERE ts.topic_id = lp.topic_id AND tr.user_id = ? AND tr.passed = 1) as completed_tests
            FROM learning_paths lp
            JOIN topics t ON lp.topic_id = t.id
            WHERE lp.user_id = ?
            ORDER BY lp.order_index ASC
        ");
        $stmt->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
        $learning_path = $stmt->fetchAll();
    } catch (PDOException $e) {
        $learning_path = [];
    }
}

// Получаем рекомендации на основе слабых мест
$recommendations = [];
if ($table_exists) {
    try {
        $stmt = $pdo->prepare("
            SELECT t.*, 
                   COUNT(q.id) as question_count,
                   (SELECT COUNT(*) FROM user_answers ua 
                    JOIN questions q2 ON ua.question_id = q2.id 
                    WHERE q2.topic_id = t.id AND ua.user_id = ? AND ua.is_correct = 0) as incorrect_answers
            FROM topics t
            LEFT JOIN questions q ON t.id = q.topic_id
            WHERE t.id NOT IN (SELECT topic_id FROM learning_paths WHERE user_id = ?)
            GROUP BY t.id
            HAVING incorrect_answers > 0 OR incorrect_answers IS NULL
            ORDER BY incorrect_answers DESC
            LIMIT 3
        ");
        $stmt->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
        $recommendations = $stmt->fetchAll();
    } catch (PDOException $e) {
        $recommendations = [];
    }
}

// Получаем общую статистику прогресса
$progress_stats = [
    'total_topics' => 0,
    'completed_topics' => 0,
    'overall_progress' => 0
];

if ($table_exists) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_topics,
                SUM(CASE WHEN lp.completed = 1 THEN 1 ELSE 0 END) as completed_topics,
                AVG(CASE WHEN lp.completed = 1 THEN 100 ELSE lp.progress END) as overall_progress
            FROM learning_paths lp
            WHERE lp.user_id = ?
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $stats_result = $stmt->fetch();
        if ($stats_result) {
            $progress_stats = $stats_result;
        }
    } catch (PDOException $e) {
        // Оставляем значения по умолчанию
    }
}

// Получаем уведомления
$notifications = [];
try {
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
} catch (PDOException $e) {
    $notifications = [];
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Индивидуальная траектория обучения - Система интеллектуальной оценки знаний</title>
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
        
        /* Learning Path Items */
        .path-list {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        
        .path-item {
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
        
        .path-item::before {
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
        
        .path-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }
        
        .path-item:hover::before {
            transform: scaleX(1);
        }
        
        .path-item.completed {
            border-left-color: var(--success);
        }
        
        .path-item.current {
            border-left-color: var(--warning);
            background: rgba(243, 156, 18, 0.05);
        }
        
        .path-item.upcoming {
            border-left-color: var(--gray);
            opacity: 0.7;
        }
        
        .path-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        
        .path-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 5px;
            color: var(--secondary);
        }
        
        .path-subject {
            background: rgba(52, 152, 219, 0.1);
            color: var(--primary);
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
            margin-bottom: 10px;
        }
        
        .path-meta {
            display: flex;
            gap: 15px;
            font-size: 13px;
            color: var(--gray);
            margin-bottom: 12px;
        }
        
        .path-description {
            margin-bottom: 15px;
            color: var(--gray);
            line-height: 1.5;
        }
        
        .path-progress {
            margin-bottom: 15px;
        }
        
        .progress-bar {
            height: 8px;
            background: #e0e0e0;
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 5px;
        }
        
        .progress-fill {
            height: 100%;
            background: var(--primary);
            border-radius: 4px;
            transition: width 0.5s ease;
        }
        
        .progress-text {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            color: var(--gray);
        }
        
        .path-actions {
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
        
        .btn-disabled {
            background: var(--gray);
            color: white;
            cursor: not-allowed;
        }
        
        .btn-disabled:hover {
            transform: none;
        }
        
        /* Recommendations */
        .recommendation-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
        }
        
        .recommendation-item {
            padding: 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 12px;
            color: white;
            position: relative;
            overflow: hidden;
        }
        
        .recommendation-item::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 100%;
            background: rgba(255,255,255,0.1);
            transform: rotate(30deg);
        }
        
        .recommendation-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 10px;
            position: relative;
            z-index: 1;
        }
        
        .recommendation-meta {
            font-size: 13px;
            margin-bottom: 10px;
            opacity: 0.9;
            position: relative;
            z-index: 1;
        }
        
        .recommendation-actions {
            position: relative;
            z-index: 1;
            margin-top: 15px;
        }
        
        /* Difficulty Badges */
        .difficulty-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .difficulty-beginner {
            background: rgba(46, 204, 113, 0.2);
            color: var(--success);
        }
        
        .difficulty-intermediate {
            background: rgba(243, 156, 18, 0.2);
            color: var(--warning);
        }
        
        .difficulty-advanced {
            background: rgba(231, 76, 60, 0.2);
            color: var(--danger);
        }
        
        /* Status Indicators */
        .status-indicator {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 12px;
            font-weight: 500;
            padding: 4px 10px;
            border-radius: 12px;
            background: rgba(52, 152, 219, 0.1);
            color: var(--primary);
        }
        
        .status-completed {
            background: rgba(46, 204, 113, 0.1);
            color: var(--success);
        }
        
        .status-current {
            background: rgba(243, 156, 18, 0.1);
            color: var(--warning);
        }
        
        .status-upcoming {
            background: rgba(149, 165, 166, 0.1);
            color: var(--gray);
        }
        
        /* Chart Container */
        .chart-container {
            height: 300px;
            position: relative;
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

        /* Notifications */
        .notification-item {
            padding: 15px;
            border-left: 4px solid var(--primary);
            background: var(--light);
            margin-bottom: 10px;
            border-radius: 8px;
        }

        .notification-item.unread {
            border-left-color: var(--accent);
            background: rgba(231, 76, 60, 0.05);
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
            
            .path-header {
                flex-direction: column;
                gap: 10px;
            }
            
            .path-meta {
                flex-wrap: wrap;
            }
            
            .stats-cards {
                grid-template-columns: 1fr;
            }
            
            .header-actions {
                width: 100%;
                justify-content: flex-end;
            }

            .recommendation-list {
                grid-template-columns: 1fr;
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
                <h2>Индивидуальная траектория обучения</h2>
                <?php
                $nameParts = explode(' ', $user['full_name']);
                $firstName = $nameParts[1] ?? $user['full_name'];
                echo "<p>Персональный план обучения для $firstName, основанный на ваших результатах</p>";
                ?>
            </div>
            
            <div class="header-actions">
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
            <div class="stat-card success">
                <div class="stat-icon icon-success">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-details">
                    <h3><?php echo $progress_stats['completed_topics']; ?></h3>
                    <p>Завершенных тем</p>
                    <div class="stat-trend trend-up">
                        <i class="fas fa-arrow-up"></i>
                        <?php echo $progress_stats['total_topics'] > 0 ? round(($progress_stats['completed_topics'] / $progress_stats['total_topics']) * 100, 1) : 0; ?>% выполнено
                    </div>
                </div>
            </div>
            
            <div class="stat-card info">
                <div class="stat-icon icon-info">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="stat-details">
                    <h3><?php echo round($progress_stats['overall_progress'], 1); ?>%</h3>
                    <p>Общий прогресс</p>
                    <div class="stat-trend trend-up">
                        <i class="fas fa-trophy"></i>
                        Продолжайте в том же духе!
                    </div>
                </div>
            </div>
            
            <div class="stat-card primary">
                <div class="stat-icon icon-primary">
                    <i class="fas fa-book"></i>
                </div>
                <div class="stat-details">
                    <h3><?php echo $progress_stats['total_topics']; ?></h3>
                    <p>Тем в траектории</p>
                    <div class="stat-trend trend-up">
                        <i class="fas fa-road"></i>
                        Индивидуальный маршрут
                    </div>
                </div>
            </div>
            
            <div class="stat-card warning">
                <div class="stat-icon icon-warning">
                    <i class="fas fa-lightbulb"></i>
                </div>
                <div class="stat-details">
                    <h3><?php echo count($recommendations); ?></h3>
                    <p>Рекомендаций</p>
                    <div class="stat-trend trend-down">
                        <i class="fas fa-exclamation-circle"></i>
                        Улучшите слабые места
                    </div>
                </div>
            </div>
        </div>
        
        <div class="dashboard-grid">
            <!-- Основной контент -->
            <div class="main-content-column">
                <div class="section">
                    <div class="section-header">
                        <h2><i class="fas fa-road"></i> Ваша траектория обучения</h2>
                        <a href="#" class="view-all">
                            Смотреть все <i class="fas fa-chevron-right"></i>
                        </a>
                    </div>
                    
                    <div class="path-list">
                        <?php if (empty($learning_path)): ?>
                            <div class="empty-state">
                                <i class="fas fa-road"></i>
                                <p>Траектория обучения еще не сформирована</p>
                                <p class="text-muted">Обратитесь к преподавателю для настройки индивидуального плана</p>
                                <button class="btn btn-primary mt-3">
                                    <i class="fas fa-sync-alt"></i> Запросить траекторию
                                </button>
                            </div>
                        <?php else: ?>
                            <?php foreach ($learning_path as $index => $topic): ?>
                                <?php
                                $status_class = '';
                                $status_text = '';
                                $status_icon = '';
                                
                                if ($topic['completed']) {
                                    $status_class = 'completed';
                                    $status_text = 'Завершено';
                                    $status_icon = 'check-circle';
                                } elseif ($index === 0 || $learning_path[$index-1]['completed']) {
                                    $status_class = 'current';
                                    $status_text = 'Текущая';
                                    $status_icon = 'play-circle';
                                } else {
                                    $status_class = 'upcoming';
                                    $status_text = 'Предстоит';
                                    $status_icon = 'clock';
                                }
                                
                                // Определяем уровень сложности
                                $difficulty_class = '';
                                $difficulty_text = '';
                                switch ($topic['difficulty_level']) {
                                    case 'beginner':
                                        $difficulty_class = 'difficulty-beginner';
                                        $difficulty_text = 'Начальный';
                                        break;
                                    case 'intermediate':
                                        $difficulty_class = 'difficulty-intermediate';
                                        $difficulty_text = 'Средний';
                                        break;
                                    case 'advanced':
                                        $difficulty_class = 'difficulty-advanced';
                                        $difficulty_text = 'Продвинутый';
                                        break;
                                    default:
                                        $difficulty_class = 'difficulty-beginner';
                                        $difficulty_text = 'Начальный';
                                }
                                
                                // Рассчитываем прогресс
                                $progress = 0;
                                if ($topic['total_questions'] > 0) {
                                    $progress = min(100, ($topic['completed_tests'] / $topic['total_questions']) * 100);
                                }
                                ?>
                                
                                <div class="path-item <?php echo $status_class; ?>">
                                    <div class="path-header">
                                        <div>
                                            <div class="path-title"><?php echo htmlspecialchars($topic['topic_title']); ?></div>
                                            <span class="path-subject"><?php echo htmlspecialchars($topic['subject']); ?></span>
                                        </div>
                                        <span class="status-indicator status-<?php echo $status_class; ?>">
                                            <i class="fas fa-<?php echo $status_icon; ?>"></i>
                                            <?php echo $status_text; ?>
                                        </span>
                                    </div>
                                    
                                    <div class="path-meta">
                                        <span><i class="fas fa-signal"></i> 
                                            <span class="difficulty-badge <?php echo $difficulty_class; ?>">
                                                <?php echo $difficulty_text; ?>
                                            </span>
                                        </span>
                                        <span><i class="fas fa-clock"></i> <?php echo $topic['estimated_time']; ?> мин</span>
                                        <span><i class="fas fa-question-circle"></i> <?php echo $topic['total_questions']; ?> вопросов</span>
                                        <span><i class="fas fa-tasks"></i> <?php echo $topic['completed_tests']; ?> выполнено</span>
                                    </div>
                                    
                                    <div class="path-description">
                                        <?php echo htmlspecialchars($topic['topic_description']); ?>
                                    </div>
                                    
                                    <div class="path-progress">
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width: <?php echo $progress; ?>%"></div>
                                        </div>
                                        <div class="progress-text">
                                            <span>Прогресс</span>
                                            <span><?php echo round($progress); ?>%</span>
                                        </div>
                                    </div>
                                    
                                    <div class="path-actions">
                                        <?php if ($status_class === 'current' || $status_class === 'completed'): ?>
                                            <a href="topic.php?id=<?php echo $topic['topic_id']; ?>" class="btn btn-primary">
                                                <i class="fas fa-play"></i> 
                                                <?php echo $status_class === 'completed' ? 'Повторить' : 'Продолжить'; ?>
                                            </a>
                                        <?php else: ?>
                                            <button class="btn btn-disabled" disabled>
                                                <i class="fas fa-lock"></i> Доступно позже
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- График прогресса -->
                <div class="section">
                    <div class="section-header">
                        <h2><i class="fas fa-chart-line"></i> Прогресс обучения</h2>
                        <span class="view-all">За все время</span>
                    </div>
                    
                    <div class="chart-container">
                        <canvas id="progressChart"></canvas>
                    </div>
                </div>
            </div>
            
            <!-- Боковая панель -->
            <div class="sidebar-column">
                <!-- Рекомендации -->
                <div class="section">
                    <div class="section-header">
                        <h2><i class="fas fa-lightbulb"></i> Рекомендации</h2>
                        <a href="#" class="view-all">
                            Все <i class="fas fa-chevron-right"></i>
                        </a>
                    </div>
                    
                    <div class="recommendation-list">
                        <?php if (empty($recommendations)): ?>
                            <div class="empty-state">
                                <i class="fas fa-check-circle"></i>
                                <p>Отличная работа!</p>
                                <p class="text-muted">Все рекомендации выполнены</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($recommendations as $rec): ?>
                                <?php
                                $difficulty_class = '';
                                $difficulty_text = '';
                                switch ($rec['difficulty_level']) {
                                    case 'beginner':
                                        $difficulty_class = 'difficulty-beginner';
                                        $difficulty_text = 'Начальный';
                                        break;
                                    case 'intermediate':
                                        $difficulty_class = 'difficulty-intermediate';
                                        $difficulty_text = 'Средний';
                                        break;
                                    case 'advanced':
                                        $difficulty_class = 'difficulty-advanced';
                                        $difficulty_text = 'Продвинутый';
                                        break;
                                }
                                ?>
                                <div class="recommendation-item">
                                    <div class="recommendation-title"><?php echo htmlspecialchars($rec['title']); ?></div>
                                    <div class="recommendation-meta">
                                        <span class="difficulty-badge <?php echo $difficulty_class; ?>">
                                            <?php echo $difficulty_text; ?>
                                        </span>
                                        <span> • <?php echo $rec['question_count']; ?> вопросов</span>
                                    </div>
                                    <div class="recommendation-actions">
                                        <a href="topic.php?id=<?php echo $rec['id']; ?>" class="btn btn-warning">
                                            <i class="fas fa-brain"></i> Улучшить
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Предстоящие дедлайны -->
                <div class="section">
                    <div class="section-header">
                        <h2><i class="fas fa-calendar-alt"></i> Ближайшие дедлайны</h2>
                    </div>
                    
                    <div class="empty-state">
                        <i class="fas fa-calendar-check"></i>
                        <p>Нет предстоящих дедлайнов</p>
                        <p class="text-muted">Следите за обновлениями</p>
                    </div>
                </div>
                
                <!-- Быстрый доступ -->
                <div class="section">
                    <div class="section-header">
                        <h2><i class="fas fa-bolt"></i> Быстрый доступ</h2>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                        <a href="tests.php" class="btn btn-primary" style="justify-content: center;">
                            <i class="fas fa-play"></i> Тесты
                        </a>
                        <a href="results.php" class="btn btn-success" style="justify-content: center;">
                            <i class="fas fa-chart-bar"></i> Результаты
                        </a>
                        <a href="materials.php" class="btn btn-info" style="justify-content: center;">
                            <i class="fas fa-book"></i> Материалы
                        </a>
                        <a href="help.php" class="btn btn-warning" style="justify-content: center;">
                            <i class="fas fa-question-circle"></i> Помощь
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Функция для переключения уведомлений
        function toggleNotifications() {
            const panel = document.getElementById('notificationsPanel');
            if (panel.style.display === 'none' || panel.style.display === '') {
                panel.style.display = 'block';
            } else {
                panel.style.display = 'none';
            }
        }
        
        // Функция для отметки всех уведомлений как прочитанных
        function markAllAsRead() {
            // В реальном приложении здесь был бы AJAX запрос
            document.querySelectorAll('.notification-item.unread').forEach(item => {
                item.classList.remove('unread');
            });
            document.querySelector('.notification-badge').style.display = 'none';
        }
        
        // Инициализация графика прогресса
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('progressChart').getContext('2d');
            
            // Пример данных для графика
            const progressChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: ['Неделя 1', 'Неделя 2', 'Неделя 3', 'Неделя 4', 'Неделя 5', 'Неделя 6'],
                    datasets: [{
                        label: 'Прогресс обучения (%)',
                        data: [10, 25, 45, 60, 75, 85],
                        borderColor: '#3498db',
                        backgroundColor: 'rgba(52, 152, 219, 0.1)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
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
                    },
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top'
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false
                        }
                    }
                }
            });
        });
        
        // Анимация появления элементов при загрузке
        document.addEventListener('DOMContentLoaded', function() {
            const items = document.querySelectorAll('.path-item, .stat-card, .recommendation-item');
            items.forEach((item, index) => {
                item.style.opacity = '0';
                item.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    item.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    item.style.opacity = '1';
                    item.style.transform = 'translateY(0)';
                }, index * 100);
            });
        });
        
        // Мобильное меню
        document.addEventListener('DOMContentLoaded', function() {
            const mobileToggle = document.querySelector('.mobile-menu-toggle');
            const sidebar = document.querySelector('.sidebar');
            
            if (mobileToggle && sidebar) {
                mobileToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('mobile-open');
                });
            }
            
            // Закрытие меню при клике вне его
            document.addEventListener('click', function(e) {
                if (!sidebar.contains(e.target) && !mobileToggle.contains(e.target)) {
                    sidebar.classList.remove('mobile-open');
                }
            });
        });
    </script>
</body>
</html>