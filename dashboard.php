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
    
   // Учебные материалы (ИСПРАВЛЕННЫЙ ЗАПРОС)
$stmt = $pdo->prepare("
    SELECT m.*, mc.name as category_name, mc.icon as category_icon,
           (SELECT COUNT(*) FROM material_views WHERE material_id = m.id AND user_id = ?) as viewed
    FROM materials m
    LEFT JOIN material_categories mc ON m.category_id = mc.id
    WHERE m.is_active = 1
    ORDER BY m.created_at DESC
    LIMIT 6
");
$stmt->execute([$_SESSION['user_id']]);
$learning_materials = $stmt->fetchAll();
    
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
    SELECT u.full_name, u.avatar, t.title, tr.score, tr.total_points, tr.percentage, tr.completed_at,
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

// Получаем непрочитанные сообщения
$unread_messages_count = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as unread_count 
        FROM messages 
        WHERE receiver_id = ? AND is_read = FALSE
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $result = $stmt->fetch();
    $unread_messages_count = $result ? $result['unread_count'] : 0;
} catch (PDOException $e) {
    error_log("Error counting unread messages: " . $e->getMessage());
    $unread_messages_count = 0;
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
    background: linear-gradient(180deg, var(--secondary) 0%, #2c3e50 100%);
    color: white;
    overflow-y: auto;
    overflow-x: hidden;
    z-index: 1000;
    display: flex;
    flex-direction: column;
    border-right: 1px solid rgba(255, 255, 255, 0.1);
}

.sidebar::-webkit-scrollbar {
    width: 0px;
}

/* Логотип */
.logo {
    padding: 24px 20px;
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
    color: rgba(255, 255, 255, 0.8);
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
    gap: 12px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}

.user-avatar {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    background: var(--primary);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 16px;
    color: white;
    flex-shrink: 0;
}

.user-details h3 {
    font-size: 15px;
    margin-bottom: 4px;
    font-weight: 600;
}

.user-details p {
    font-size: 12px;
    color: rgba(255, 255, 255, 0.8);
    margin-bottom: 6px;
}

.role-badge {
    padding: 3px 8px;
    border-radius: 8px;
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
    padding: 16px 20px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}

.stat-item {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 13px;
    color: rgba(255, 255, 255, 0.9);
    margin-bottom: 8px;
}

.stat-item i {
    width: 16px;
    text-align: center;
    color: var(--primary);
}

/* Навигация */
.nav-links {
    list-style: none;
    flex: 1;
    overflow-y: auto;
    padding: 0;
}

.nav-links::-webkit-scrollbar {
    display: none;
}

.nav-section {
    padding: 16px 20px 8px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    margin-bottom: 8px;
}

.section-label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: rgba(255, 255, 255, 0.6);
}

.nav-links li a {
    display: flex;
    align-items: center;
    padding: 12px 20px;
    color: rgba(255, 255, 255, 0.9);
    text-decoration: none;
    transition: all 0.3s ease;
    border-left: 3px solid transparent;
    font-size: 14px;
    font-weight: 500;
}

.nav-links li a:hover {
    background: rgba(255, 255, 255, 0.08);
    color: white;
    border-left-color: var(--primary);
}

.nav-links li a.active {
    background: rgba(255, 255, 255, 0.12);
    color: white;
    border-left-color: var(--primary);
}

.nav-links li a i {
    width: 18px;
    margin-right: 12px;
    font-size: 14px;
}

/* Футер сайдбара */
.sidebar-footer {
    padding: 16px 20px;
    border-top: 1px solid rgba(255, 255, 255, 0.1);
    margin-top: auto;
}

.system-info {
    margin-bottom: 12px;
}

.info-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 11px;
    color: rgba(255, 255, 255, 0.7);
    margin-bottom: 4px;
}

.quick-actions {
    display: flex;
    gap: 8px;
}

.quick-btn {
    flex: 1;
    background: rgba(255, 255, 255, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    color: white;
    padding: 8px 12px;
    border-radius: 6px;
    font-size: 11px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 4px;
    transition: all 0.3s ease;
}

.quick-btn:hover {
    background: rgba(255, 255, 255, 0.15);
    border-color: rgba(255, 255, 255, 0.3);
}

/* Кнопка выхода */
.logout-btn {
    color: var(--accent) !important;
}

.logout-btn:hover {
    background: rgba(231, 76, 60, 0.1) !important;
    border-left-color: var(--accent) !important;
}

/* Мобильное меню */
.mobile-menu-toggle {
    display: none;
    position: fixed;
    top: 16px;
    left: 16px;
    background: var(--primary);
    color: white;
    width: 40px;
    height: 40px;
    border-radius: 8px;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    z-index: 1001;
    border: none;
}

/* Адаптивность */
@media (max-width: 992px) {
    .mobile-menu-toggle {
        display: flex;
    }
    
    .sidebar {
        transform: translateX(-100%);
        transition: transform 0.3s ease;
    }
    
    .sidebar.mobile-open {
        transform: translateX(0);
    }
}

@media (max-width: 768px) {
    .sidebar {
        width: 280px;
    }
    
    .user-info {
        padding: 16px;
    }
    
    .nav-links li a {
        padding: 10px 16px;
    }
    
    .quick-stats {
        padding: 12px 16px;
    }
}

        /* ===== ОСНОВНОЙ КОНТЕНТ ===== */
.main-content {
    flex: 1;
    margin-left: var(--sidebar-width);
    padding: 24px;
    transition: margin-left 0.3s;
    background: #f8f9fa;
}

.header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 32px;
    padding-bottom: 20px;
    border-bottom: 2px solid #f0f0f0;
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
    font-weight: 500;
}

.header-actions {
    display: flex;
    gap: 12px;
    align-items: center;
}

/* Responsive */
@media (max-width: 768px) {
    .main-content {
        padding: 16px;
    }
    
    .header {
        flex-direction: column;
        align-items: flex-start;
        gap: 16px;
        margin-bottom: 24px;
    }
    
    .header-actions {
        width: 100%;
        justify-content: space-between;
    }
    
    .welcome h2 {
        font-size: 24px;
    }
    
    .welcome p {
        font-size: 14px;
    }
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
    border-radius: 16px;
    padding: 30px;
    display: flex;
    align-items: center;
    transition: all 0.3s ease;
    border: 2px solid #f8f9fa;
    position: relative;
}

.stat-card:hover {
    border-color: var(--primary);
    transform: translateY(-2px);
}

.stat-card.success:hover {
    border-color: var(--success);
}

.stat-card.warning:hover {
    border-color: var(--warning);
}

.stat-card.danger:hover {
    border-color: var(--danger);
}

.stat-card.info:hover {
    border-color: var(--info);
}

.stat-icon {
    width: 64px;
    height: 64px;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    margin-right: 20px;
    color: white;
    flex-shrink: 0;
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

.icon-danger {
    background: var(--danger);
}

.icon-info {
    background: var(--info);
}

.stat-details {
    flex: 1;
}

.stat-details h3 {
    font-size: 32px;
    margin-bottom: 4px;
    color: var(--secondary);
    font-weight: 700;
    line-height: 1;
}

.stat-details p {
    color: var(--gray);
    font-size: 15px;
    margin-bottom: 8px;
    font-weight: 500;
}

.stat-trend {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 13px;
    font-weight: 600;
}

.trend-up {
    color: var(--success);
}

.trend-down {
    color: var(--danger);
}

/* Адаптивность */
@media (max-width: 768px) {
    .stats-cards {
        grid-template-columns: 1fr;
        gap: 16px;
    }
    
    .stat-card {
        padding: 24px;
    }
    
    .stat-icon {
        width: 56px;
        height: 56px;
        font-size: 20px;
        margin-right: 16px;
    }
    
    .stat-details h3 {
        font-size: 28px;
    }
}

@media (max-width: 480px) {
    .stat-card {
        padding: 20px;
    }
    
    .stat-details h3 {
        font-size: 24px;
    }
    
    .stat-details p {
        font-size: 14px;
    }
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
    padding: 24px;
    background: white;
    border-radius: 16px;
    transition: all 0.3s ease;
    cursor: pointer;
    border: 2px solid #f8f9fa;
    display: flex;
    flex-direction: column;
    position: relative;
}

.test-item:hover {
    border-color: var(--primary);
    transform: translateY(-2px);
}

.test-item.success:hover {
    border-color: var(--success);
}

.test-item.warning:hover {
    border-color: var(--warning);
}

.test-item.danger:hover {
    border-color: var(--danger);
}

.test-subject {
    background: rgba(52, 152, 219, 0.1);
    color: var(--primary);
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    display: inline-block;
    margin-bottom: 12px;
    width: fit-content;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.test-title {
    font-size: 17px;
    font-weight: 600;
    margin-bottom: 12px;
    color: var(--secondary);
    line-height: 1.4;
}

.test-meta {
    display: flex;
    justify-content: space-between;
    font-size: 13px;
    color: var(--gray);
    margin-bottom: 8px;
}

.test-meta span {
    display: flex;
    align-items: center;
    gap: 4px;
}

.test-score {
    font-weight: 600;
    font-size: 14px;
    padding: 4px 8px;
    border-radius: 6px;
    background: rgba(0,0,0,0.03);
}

.score-excellent {
    color: var(--success);
    background: rgba(46, 204, 113, 0.1);
}

.score-good {
    color: var(--warning);
    background: rgba(243, 156, 18, 0.1);
}

.score-poor {
    color: var(--danger);
    background: rgba(231, 76, 60, 0.1);
}

.test-actions {
    display: flex;
    justify-content: flex-end;
    margin-top: 16px;
    gap: 8px;
}

.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
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
    border: 2px solid var(--primary);
}

.btn-primary:hover {
    background: transparent;
    color: var(--primary);
    transform: translateY(-1px);
}

.btn-success {
    background: var(--success);
    color: white;
    border: 2px solid var(--success);
}

.btn-success:hover {
    background: transparent;
    color: var(--success);
    transform: translateY(-1px);
}

.btn-warning {
    background: var(--warning);
    color: white;
    border: 2px solid var(--warning);
}

.btn-warning:hover {
    background: transparent;
    color: var(--warning);
    transform: translateY(-1px);
}

/* Status indicators */
.test-item::after {
    content: '';
    position: absolute;
    top: 16px;
    right: 16px;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--gray);
}

.test-item.success::after {
    background: var(--success);
}

.test-item.warning::after {
    background: var(--warning);
}

.test-item.danger::after {
    background: var(--danger);
}

/* Responsive */
@media (max-width: 768px) {
    .test-list {
        grid-template-columns: 1fr;
        gap: 16px;
    }
    
    .test-item {
        padding: 20px;
    }
    
    .test-actions {
        flex-direction: column;
    }
    
    .btn {
        width: 100%;
        justify-content: center;
    }
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
        
        .user-avatar-small {
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
            overflow: hidden;
        }
        
        .user-avatar-small img {
            width: 100%;
            height: 100%;
            object-fit: cover;
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
        
        /* Notifications Panel */
.notifications-panel {
    position: fixed;
    top: 80px;
    right: 20px;
    width: 400px;
    background: white;
    border-radius: 16px;
    z-index: 1000;
    display: none;
    overflow: hidden;
    border: 2px solid #f8f9fa;
    box-shadow: 0 8px 32px rgba(0,0,0,0.1);
}

.notifications-panel.active {
    display: block;
    animation: slideInDown 0.3s ease;
}

@keyframes slideInDown {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.notifications-header {
    padding: 20px 24px;
    border-bottom: 1px solid #f0f0f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: white;
}

.notifications-header h3 {
    margin: 0;
    font-size: 18px;
    font-weight: 600;
    color: var(--secondary);
    display: flex;
    align-items: center;
    gap: 8px;
}

.notifications-header .btn {
    background: var(--primary);
    border: 2px solid var(--primary);
    color: white;
    padding: 6px 12px;
    font-size: 12px;
    border-radius: 6px;
    transition: all 0.3s ease;
}

.notifications-header .btn:hover {
    background: transparent;
    color: var(--primary);
}

.notifications-list {
    max-height: 400px;
    overflow-y: auto;
}

.notifications-list::-webkit-scrollbar {
    width: 4px;
}

.notifications-list::-webkit-scrollbar-track {
    background: #f8f9fa;
}

.notifications-list::-webkit-scrollbar-thumb {
    background: var(--primary);
    border-radius: 2px;
}

.notification-item {
    padding: 16px 20px;
    background: white;
    transition: all 0.3s ease;
    border-bottom: 1px solid #f8f9fa;
    position: relative;
}

.notification-item:last-child {
    border-bottom: none;
}

.notification-item:hover {
    background: #f8f9fa;
}

.notification-item.unread {
    background: #f8f9fa;
    border-left: 3px solid var(--primary);
}

.notification-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 6px;
}

.notification-title {
    font-weight: 600;
    font-size: 14px;
    color: var(--secondary);
    line-height: 1.3;
    flex: 1;
}

.notification-type {
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    flex-shrink: 0;
}

.notification-type.info {
    background: rgba(52, 152, 219, 0.1);
    color: var(--primary);
}

.notification-type.success {
    background: rgba(46, 204, 113, 0.1);
    color: var(--success);
}

.notification-type.warning {
    background: rgba(243, 156, 18, 0.1);
    color: var(--warning);
}

.notification-type.danger {
    background: rgba(231, 76, 60, 0.1);
    color: var(--danger);
}

.notification-message {
    font-size: 13px;
    color: var(--gray);
    line-height: 1.4;
    margin-bottom: 8px;
}

.notification-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 11px;
    color: var(--gray);
}

.notification-time {
    display: flex;
    align-items: center;
    gap: 4px;
}

.notification-actions {
    display: flex;
    gap: 4px;
}

.notification-action {
    background: none;
    border: none;
    color: var(--gray);
    cursor: pointer;
    padding: 4px;
    border-radius: 4px;
    transition: all 0.3s ease;
    font-size: 10px;
}

.notification-action:hover {
    color: var(--primary);
    background: rgba(52, 152, 219, 0.1);
}

.notifications-empty {
    padding: 40px 20px;
    text-align: center;
    color: var(--gray);
}

.notifications-empty i {
    font-size: 32px;
    margin-bottom: 12px;
    opacity: 0.5;
    display: block;
}

.notifications-empty h4 {
    font-size: 14px;
    margin-bottom: 6px;
    color: var(--secondary);
}

.notifications-empty p {
    font-size: 13px;
    opacity: 0.8;
}

/* Notification categories */
.notification-categories {
    display: flex;
    padding: 12px 20px;
    background: #f8f9fa;
    border-bottom: 1px solid #e0e0e0;
    gap: 6px;
}

.notification-category {
    padding: 4px 8px;
    background: white;
    border: 1px solid #e0e0e0;
    border-radius: 12px;
    font-size: 10px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
    color: var(--gray);
}

.notification-category.active,
.notification-category:hover {
    background: var(--primary);
    color: white;
    border-color: var(--primary);
}

/* Responsive design */
@media (max-width: 768px) {
    .notifications-panel {
        width: 95%;
        right: 2.5%;
        top: 70px;
    }
    
    .notifications-header {
        padding: 16px 20px;
    }
    
    .notification-item {
        padding: 14px 16px;
    }
    
    .notification-categories {
        padding: 10px 16px;
    }
}

@media (max-width: 480px) {
    .notifications-panel {
        width: 100%;
        right: 0;
        border-radius: 0;
        top: 0;
        height: 100vh;
        border: none;
    }
    
    .notifications-list {
        max-height: calc(100vh - 120px);
    }
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

        /* Модальные окна для помощи и чата */
.help-modal,
.chat-modal {
    max-width: 500px;
}

.help-content {
    padding: 24px;
    max-height: 60vh;
    overflow-y: auto;
}

.help-section {
    margin-bottom: 24px;
}

.help-section h3 {
    color: var(--secondary);
    margin-bottom: 12px;
    font-size: 18px;
    font-weight: 600;
}

.help-section p {
    color: var(--gray);
    line-height: 1.6;
    margin-bottom: 12px;
    font-size: 14px;
}

.help-section ul {
    list-style-type: none;
    padding-left: 0;
}

.help-section li {
    padding: 10px 0;
    border-bottom: 1px solid #f0f0f0;
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 14px;
}

.help-section li:last-child {
    border-bottom: none;
}

.help-section li i {
    color: var(--primary);
    font-size: 12px;
}

/* FAQ Accordion */
.faq-accordion {
    margin-top: 24px;
}

.faq-item {
    border: 2px solid #f8f9fa;
    border-radius: 12px;
    margin-bottom: 12px;
    overflow: hidden;
    transition: all 0.3s ease;
}

.faq-item:hover {
    border-color: var(--primary);
}

.faq-question {
    padding: 16px 20px;
    background: white;
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-weight: 600;
    color: var(--secondary);
    transition: all 0.3s ease;
    font-size: 15px;
}

.faq-question:hover {
    background: #f8f9fa;
}

.faq-question.active {
    background: var(--primary);
    color: white;
    border-bottom: 1px solid #f0f0f0;
}

.faq-answer {
    padding: 0;
    max-height: 0;
    overflow: hidden;
    transition: all 0.3s ease;
    background: white;
}

.faq-answer.active {
    padding: 20px;
    max-height: 500px;
}

.faq-answer p {
    margin-bottom: 12px;
    line-height: 1.6;
    color: var(--gray);
    font-size: 14px;
}

.faq-toggle {
    transition: transform 0.3s ease;
    font-size: 12px;
}

.faq-question.active .faq-toggle {
    transform: rotate(180deg);
}

/* Scrollbar for help content */
.help-content::-webkit-scrollbar {
    width: 4px;
}

.help-content::-webkit-scrollbar-track {
    background: #f8f9fa;
}

.help-content::-webkit-scrollbar-thumb {
    background: var(--primary);
    border-radius: 2px;
}
        /* Модальные окна для чата */
        .chat-modal {
            max-width: 90%;
            width: 1200px;
            height: 90vh;
            max-height: 800px;
            display: flex;
            flex-direction: column;
        }

        .chat-header {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            padding: 20px;
            border-radius: 15px 15px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .chat-header h2 {
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .chat-container {
            display: flex;
            flex: 1;
            overflow: hidden;
        }

        .chat-sidebar {
            width: 300px;
            background: #f8f9fa;
            border-right: 1px solid #e0e0e0;
            overflow-y: auto;
        }

        .chat-contacts {
            padding: 15px;
        }

        .chat-contact {
            display: flex;
            align-items: center;
            padding: 12px;
            border-radius: 8px;
            cursor: pointer;
            transition: background 0.3s;
            margin-bottom: 8px;
        }

        .chat-contact:hover {
            background: #e9ecef;
        }

        .chat-contact.active {
            background: var(--primary);
            color: white;
        }

        .chat-contact-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            margin-right: 12px;
            overflow: hidden;
        }

        .chat-contact-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .chat-contact-info h4 {
            margin: 0;
            font-size: 14px;
            font-weight: 600;
        }

        .chat-contact-info p {
            margin: 2px 0 0;
            font-size: 12px;
            opacity: 0.7;
        }

        .chat-main {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 15px;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }

        .chat-messages::-webkit-scrollbar {
            display: none;
        }

        .message {
            display: flex;
            gap: 10px;
            max-width: 70%;
        }

        .message.received {
            align-self: flex-start;
        }

        .message.sent {
            align-self: flex-end;
            flex-direction: row-reverse;
        }

        .message-avatar {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 14px;
            flex-shrink: 0;
            overflow: hidden;
        }

        .message-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .message-content {
            padding: 12px 16px;
            border-radius: 18px;
            background: #f1f3f5;
            position: relative;
        }

        .message.sent .message-content {
            background: var(--primary);
            color: white;
        }

        .message-text {
            margin: 0;
            line-height: 1.4;
        }

        .message-time {
            font-size: 11px;
            opacity: 0.7;
            margin-top: 5px;
            text-align: right;
        }

        .chat-input-container {
            padding: 20px;
            border-top: 1px solid #e0e0e0;
            background: white;
            border-radius: 0 0 15px 15px;
        }

        .chat-input {
            display: flex;
            gap: 10px;
            align-items: flex-end;
        }

        .chat-input textarea {
            flex: 1;
            padding: 12px 16px;
            border: 1px solid #ddd;
            border-radius: 20px;
            outline: none;
            resize: none;
            font-family: inherit;
            font-size: 14px;
            line-height: 1.4;
            max-height: 120px;
        }

        .chat-input button {
            background: var(--primary);
            color: white;
            border: none;
            width: 44px;
            height: 44px;
            border-radius: 50%;
            cursor: pointer;
            transition: background 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .chat-input button:hover {
            background: var(--primary-dark);
        }

        .chat-input button:disabled {
            background: var(--gray);
            cursor: not-allowed;
        }

        /* Learning Materials */
.materials-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 20px;
}

.material-card {
    background: white;
    border-radius: 16px;
    transition: all 0.3s ease;
    border: 2px solid #f8f9fa;
    display: flex;
    flex-direction: column;
}

.material-card:hover {
    border-color: var(--primary);
    transform: translateY(-2px);
}

.material-header {
    padding: 20px;
    border-bottom: 1px solid #f8f9fa;
}

.material-type {
    display: inline-block;
    padding: 6px 12px;
    background: var(--primary);
    color: white;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    margin-bottom: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.material-title {
    font-size: 17px;
    font-weight: 600;
    margin-bottom: 8px;
    color: var(--secondary);
    line-height: 1.4;
}

.material-meta {
    display: flex;
    justify-content: space-between;
    font-size: 13px;
    color: var(--gray);
}

.material-body {
    padding: 16px 20px;
    flex: 1;
}

.material-description {
    font-size: 14px;
    color: var(--gray);
    line-height: 1.5;
    margin-bottom: 0;
}

.material-footer {
    padding: 16px 20px;
    background: #f8f9fa;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-top: 1px solid #f0f0f0;
}

.material-actions {
    display: flex;
    gap: 8px;
}

.btn-sm {
    padding: 8px 16px;
    border: none;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.btn-primary {
    background: var(--primary);
    color: white;
    border: 2px solid var(--primary);
}

.btn-primary:hover {
    background: transparent;
    color: var(--primary);
}

.btn-success {
    background: var(--success);
    color: white;
    border: 2px solid var(--success);
}

.btn-success:hover {
    background: transparent;
    color: var(--success);
}

.material-status {
    font-size: 12px;
    padding: 6px 12px;
    border-radius: 20px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-new {
    background: var(--success);
    color: white;
}

.status-viewed {
    background: var(--gray);
    color: white;
}

/* Responsive */
@media (max-width: 768px) {
    .materials-grid {
        grid-template-columns: 1fr;
        gap: 16px;
    }
    
    .material-card {
        border-radius: 12px;
    }
    
    .material-header {
        padding: 16px;
    }
    
    .material-body {
        padding: 12px 16px;
    }
    
    .material-footer {
        padding: 12px 16px;
        flex-direction: column;
        gap: 12px;
        align-items: stretch;
    }
    
    .material-actions {
        justify-content: center;
    }
}

        /* Message Button */
        .message-btn {
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

        .message-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
        }

        .message-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: var(--success);
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

            .chat-modal {
                width: 95%;
                height: 95vh;
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

            .chat-container {
                flex-direction: column;
            }

            .chat-sidebar {
                width: 100%;
                height: 200px;
                border-right: none;
                border-bottom: 1px solid #e0e0e0;
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
                height: 60vh;
            }

            .user-info {
                flex-direction: column;
                text-align: center;
            }

            .quick-actions {
                flex-direction: column;
            }

            .notifications-panel {
                width: 95%;
                right: 2.5%;
            }

            .hero-section {
                padding: 30px 20px;
            }

            .hero-section h1 {
                font-size: 2rem;
            }

            .hero-stats {
                gap: 20px;
            }

            .quick-actions-grid {
                grid-template-columns: 1fr;
            }

            .materials-grid {
                grid-template-columns: 1fr;
            }

            .message {
                max-width: 85%;
            }

            .chat-modal {
                width: 100%;
                height: 100vh;
                max-height: none;
                border-radius: 0;
            }
        }

        @media (max-width: 480px) {
            .header-actions {
                flex-wrap: wrap;
            }
            
            .search-box {
                order: 3;
                margin-top: 10px;
            }
        }

/* Hero Section Styles */
.hero-section {
    background: linear-gradient(135deg, #2980b9, #2c3e50);
    color: white;
    padding: 50px 40px;
    border-radius: 24px;
    margin-bottom: 40px;
    text-align: center;
    position: relative;
    overflow: hidden;
}

.hero-section::before {
    content: '';
    position: absolute;
    top: -80%;
    right: -30%;
    width: 200%;
    height: 200%;
    background: 
        radial-gradient(circle at 70% 30%, rgba(255, 255, 255, 0.1) 0%, transparent 50%),
        radial-gradient(circle at 30% 70%, rgba(255, 255, 255, 0.05) 0%, transparent 50%);
    transform: rotate(12deg);
    animation: float 8s ease-in-out infinite;
}

.hero-section::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 2px;
    background: linear-gradient(90deg, 
        transparent 0%, 
        rgba(255, 255, 255, 0.3) 50%, 
        transparent 100%);
}

@keyframes float {
    0%, 100% { transform: rotate(12deg) translateY(0px); }
    50% { transform: rotate(12deg) translateY(-10px); }
}

.hero-content {
    position: relative;
    z-index: 2;
}

.hero-section h1 {
    font-size: 2.8rem;
    margin-bottom: 20px;
    font-weight: 700;
    line-height: 1.2;
    letter-spacing: -0.5px;
    color: #ffffff;
}

.hero-section p {
    font-size: 1.3rem;
    margin-bottom: 35px;
    opacity: 0.9;
    line-height: 1.6;
    font-weight: 400;
    max-width: 600px;
    margin-left: auto;
    margin-right: auto;
    color: #f8f9fa;
}

.hero-section p strong {
    font-weight: 600;
    color: #ffffff;
}

.hero-stats {
    display: flex;
    justify-content: center;
    gap: 50px;
    margin-top: 40px;
    flex-wrap: wrap;
    position: relative;
}

.hero-stats::before {
    content: '';
    position: absolute;
    top: -20px;
    left: 50%;
    transform: translateX(-50%);
    width: 80%;
    height: 1px;
    background: linear-gradient(90deg, 
        transparent 0%, 
        rgba(255, 255, 255, 0.2) 50%, 
        transparent 100%);
}

.hero-stat {
    text-align: center;
    position: relative;
    padding: 0 20px;
}

.hero-stat::before {
    content: '';
    position: absolute;
    top: 50%;
    left: -10px;
    transform: translateY(-50%);
    width: 1px;
    height: 40px;
    background: rgba(255, 255, 255, 0.2);
}

.hero-stat:first-child::before {
    display: none;
}

.hero-stat-number {
    font-size: 2.4rem;
    font-weight: 700;
    display: block;
    margin-bottom: 8px;
    color: #ffffff;
    line-height: 1;
}

.hero-stat-label {
    font-size: 0.95rem;
    opacity: 0.8;
    font-weight: 500;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    color: #ecf0f1;
}

/* Анимация появления */
.hero-section {
    animation: heroEntrance 0.8s ease-out;
}

@keyframes heroEntrance {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Адаптивность */
@media (max-width: 768px) {
    .hero-section {
        padding: 40px 25px;
        border-radius: 20px;
        margin-bottom: 30px;
    }
    
    .hero-section h1 {
        font-size: 2.2rem;
        margin-bottom: 15px;
    }
    
    .hero-section p {
        font-size: 1.1rem;
        margin-bottom: 25px;
    }
    
    .hero-stats {
        gap: 30px;
        margin-top: 30px;
    }
    
    .hero-stat {
        padding: 0 15px;
    }
    
    .hero-stat-number {
        font-size: 2rem;
    }
    
    .hero-stat-label {
        font-size: 0.85rem;
    }
}

@media (max-width: 480px) {
    .hero-section {
        padding: 30px 20px;
        border-radius: 16px;
        margin-bottom: 25px;
    }
    
    .hero-section h1 {
        font-size: 1.8rem;
    }
    
    .hero-section p {
        font-size: 1rem;
    }
    
    .hero-stats {
        gap: 20px;
        margin-top: 25px;
    }
    
    .hero-stat {
        padding: 0 10px;
    }
    
    .hero-stat-number {
        font-size: 1.7rem;
    }
    
    .hero-stat-label {
        font-size: 0.8rem;
    }
    
    .hero-stat::before {
        height: 30px;
    }
}
/* Анимация появления */
.hero-section {
    animation: heroEntrance 0.8s ease-out;
}

@keyframes heroEntrance {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Адаптивность */
@media (max-width: 768px) {
    .hero-section {
        padding: 40px 25px;
        border-radius: 20px;
        margin-bottom: 30px;
    }
    
    .hero-section h1 {
        font-size: 2.2rem;
        margin-bottom: 15px;
    }
    
    .hero-section p {
        font-size: 1.1rem;
        margin-bottom: 25px;
    }
    
    .hero-stats {
        gap: 30px;
        margin-top: 30px;
    }
    
    .hero-stat {
        padding: 0 15px;
    }
    
    .hero-stat-number {
        font-size: 2rem;
    }
    
    .hero-stat-label {
        font-size: 0.85rem;
    }
}

@media (max-width: 480px) {
    .hero-section {
        padding: 30px 20px;
        border-radius: 16px;
        margin-bottom: 25px;
    }
    
    .hero-section h1 {
        font-size: 1.8rem;
    }
    
    .hero-section p {
        font-size: 1rem;
    }
    
    .hero-stats {
        gap: 20px;
        margin-top: 25px;
    }
    
    .hero-stat {
        padding: 0 10px;
    }
    
    .hero-stat-number {
        font-size: 1.7rem;
    }
    
    .hero-stat-label {
        font-size: 0.8rem;
    }
    
    .hero-stat::before {
        height: 30px;
    }
}

/* Quick Actions Grid */
.quick-actions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.quick-action-card {
    background: white;
    padding: 25px;
    border-radius: 15px;
    text-align: center;
    box-shadow: 0 5px 15px rgba(0,0,0,0.08);
    transition: all 0.3s;
    cursor: pointer;
    border: 1px solid #f0f0f0;
}

.quick-action-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 25px rgba(0,0,0,0.15);
}

.quick-action-icon {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: var(--light);
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 15px;
    font-size: 24px;
    color: var(--primary);
}

.quick-action-card h3 {
    margin-bottom: 10px;
    color: var(--secondary);
    font-size: 1.1rem;
}

.quick-action-card p {
    color: var(--gray);
    font-size: 0.9rem;
    line-height: 1.4;
}
    </style>
</head>
<body>
    <!-- Подключаем меню -->
    <?php include 'sidebar_menu.php'; ?>
    
    <!-- Панель уведомлений -->
    <!-- Панель уведомлений -->
<div class="notifications-panel" id="notificationsPanel">
    <div class="notifications-header">
        <h3><i class="fas fa-bell"></i> Уведомления</h3>
        <button class="btn" onclick="markAllAsRead()">
            <i class="fas fa-check-double"></i> Прочитать все
        </button>
    </div>
    
    <div class="notification-categories">
        <div class="notification-category active" data-category="all">Все</div>
        <div class="notification-category" data-category="system">Система</div>
        <div class="notification-category" data-category="tests">Тесты</div>
        <div class="notification-category" data-category="materials">Материалы</div>
    </div>
    
    <div class="notifications-list">
        <?php if (!empty($notifications)): ?>
            <?php foreach ($notifications as $notification): 
                // Определяем тип уведомления для стилизации
                $type = 'info';
                if (stripos($notification['title'], 'тест') !== false) $type = 'success';
                if (stripos($notification['title'], 'ошибка') !== false) $type = 'danger';
                if (stripos($notification['title'], 'внимание') !== false) $type = 'warning';
            ?>
                <div class="notification-item <?php echo $notification['is_read'] ? '' : 'unread'; ?>" data-type="<?php echo $type; ?>">
                    <div class="notification-header">
                        <div class="notification-title"><?php echo htmlspecialchars($notification['title']); ?></div>
                        <span class="notification-type <?php echo $type; ?>">
                            <?php 
                            switch($type) {
                                case 'success': echo 'Тест'; break;
                                case 'warning': echo 'Важно'; break;
                                case 'danger': echo 'Ошибка'; break;
                                default: echo 'Система'; break;
                            }
                            ?>
                        </span>
                    </div>
                    <div class="notification-message"><?php echo htmlspecialchars($notification['message']); ?></div>
                    <div class="notification-footer">
                        <div class="notification-time">
                            <i class="fas fa-clock"></i>
                            <?php 
                            $timeAgo = time() - strtotime($notification['created_at']);
                            if ($timeAgo < 3600) {
                                echo ceil($timeAgo / 60) . ' мин назад';
                            } elseif ($timeAgo < 86400) {
                                echo ceil($timeAgo / 3600) . ' ч назад';
                            } else {
                                echo date('d.m.Y H:i', strtotime($notification['created_at']));
                            }
                            ?>
                        </div>
                        <div class="notification-actions">
                            <button class="notification-action" onclick="markNotificationAsRead(<?php echo $notification['id']; ?>, this)">
                                <i class="fas fa-check"></i>
                            </button>
                            <button class="notification-action" onclick="deleteNotification(<?php echo $notification['id']; ?>, this)">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="notifications-empty">
                <i class="fas fa-bell-slash"></i>
                <h4>Нет уведомлений</h4>
                <p>Здесь будут появляться важные сообщения системы</p>
            </div>
        <?php endif; ?>
    </div>
</div>

    <!-- Модальное окно помощи -->
    <div class="modal-overlay" id="helpModal">
        <div class="modal-content help-modal">
            <div class="modal-header">
                <h2><i class="fas fa-question-circle"></i> Помощь</h2>
                <button class="modal-close" onclick="closeHelpModal()">&times;</button>
            </div>
            <div class="help-content">
                <div class="help-section">
                    <h3>Как работать с системой</h3>
                    <p>Система интеллектуальной оценки знаний предоставляет следующие возможности:</p>
                    <ul>
                        <li><i class="fas fa-play"></i> Прохождение тестов и оценка знаний</li>
                        <li><i class="fas fa-chart-bar"></i> Просмотр статистики и прогресса</li>
                        <li><i class="fas fa-comments"></i> Общение с преподавателями через чат</li>
                        <li><i class="fas fa-bell"></i> Получение уведомлений о новых тестах</li>
                    </ul>
                </div>
                
                <div class="help-section">
                    <h3>Часто задаваемые вопросы</h3>
                    <div class="faq-accordion">
                        <div class="faq-item">
                            <div class="faq-question">
                                Как начать прохождение теста?
                                <i class="fas fa-chevron-down faq-toggle"></i>
                            </div>
                            <div class="faq-answer">
                                <p>Перейдите в раздел "Доступные тесты" и нажмите кнопку "Начать тест".</p>
                            </div>
                        </div>
                        
                        <div class="faq-item">
                            <div class="faq-question">
                                Где посмотреть результаты?
                                <i class="fas fa-chevron-down faq-toggle"></i>
                            </div>
                            <div class="faq-answer">
                                <p>Все результаты доступны в разделе "Результаты тестов".</p>
                            </div>
                        </div>
                        
                        <div class="faq-item">
                            <div class="faq-question">
                                Как связаться с преподавателем?
                                <i class="fas fa-chevron-down faq-toggle"></i>
                            </div>
                            <div class="faq-answer">
                                <p>Используйте встроенный чат для общения с преподавателями.</p>
                            </div>
                        </div>
                        
                        <div class="faq-item">
                            <div class="faq-question">
                                Что делать если тест не загружается?
                                <i class="fas fa-chevron-down faq-toggle"></i>
                            </div>
                            <div class="faq-answer">
                                <p>Проверьте подключение к интернету и обновите страницу. Если проблема сохраняется, обратитесь в техническую поддержку.</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="help-section">
                    <h3>Техническая поддержка</h3>
                    <p>Если у вас возникли технические проблемы, обратитесь в службу поддержки:</p>
                    <ul>
                        <li><i class="fas fa-envelope"></i> Email: support@system.edu</li>
                        <li><i class="fas fa-phone"></i> Телефон: +7 (XXX) XXX-XX-XX</li>
                        <li><i class="fas fa-clock"></i> Время работы: Пн-Пт, 9:00-18:00</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- Модальное окно чата -->
    <div class="modal-overlay" id="chatModal">
        <div class="modal-content chat-modal">
            <div class="modal-header">
                <h2><i class="fas fa-comments"></i> Чат с преподавателем</h2>
                <button class="modal-close" onclick="closeChatModal()">&times;</button>
            </div>
            <div class="chat-container">
                <div class="chat-sidebar">
                    <div class="chat-contacts">
                        <div class="chat-contact active">
                            <div class="chat-contact-avatar">
                                <i class="fas fa-user-tie"></i>
                            </div>
                            <div class="chat-contact-info">
                                <h4>Иванов А.С.</h4>
                                <p>Математика</p>
                            </div>
                        </div>
                        <div class="chat-contact">
                            <div class="chat-contact-avatar">
                                <i class="fas fa-user-tie"></i>
                            </div>
                            <div class="chat-contact-info">
                                <h4>Петрова М.И.</h4>
                                <p>Физика</p>
                            </div>
                        </div>
                        <div class="chat-contact">
                            <div class="chat-contact-avatar">
                                <i class="fas fa-user-tie"></i>
                            </div>
                            <div class="chat-contact-info">
                                <h4>Сидоров В.П.</h4>
                                <p>Программирование</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="chat-main">
                    <div class="chat-messages" id="chatMessages">
                        <div class="message received">
                            <div class="message-avatar">
                                <i class="fas fa-user-tie"></i>
                            </div>
                            <div class="message-content">
                                <p class="message-text">Добрый день! Есть вопросы по материалу?</p>
                                <div class="message-time">14:30</div>
                            </div>
                        </div>
                        <div class="message sent">
                            <div class="message-avatar">
                                <?php 
                                $firstName = $user['full_name'];
                                if (function_exists('mb_convert_encoding')) {
                                    $firstName = mb_convert_encoding($firstName, 'UTF-8', 'auto');
                                }
                                $firstLetter = mb_substr($firstName, 0, 1, 'UTF-8');
                                echo htmlspecialchars(strtoupper($firstLetter));
                                ?>
                            </div>
                            <div class="message-content">
                                <p class="message-text">Здравствуйте! Да, у меня вопрос по последней теме</p>
                                <div class="message-time">14:32</div>
                            </div>
                        </div>
                        <div class="message received">
                            <div class="message-avatar">
                                <i class="fas fa-user-tie"></i>
                            </div>
                            <div class="message-content">
                                <p class="message-text">Какой именно вопрос у вас возник?</p>
                                <div class="message-time">14:33</div>
                            </div>
                        </div>
                    </div>
                    <div class="chat-input-container">
                        <div class="chat-input">
                            <textarea id="messageInput" placeholder="Введите сообщение..." rows="1"></textarea>
                            <button id="sendMessageBtn">
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Hero Section -->
        <div class="hero-section">
            <div class="hero-content">
                <h1>Добро пожаловать в систему интеллектуальной оценки знаний!</h1>
                <?php
                $nameParts = explode(' ', $user['full_name']);
                $firstName = $nameParts[1] ?? $user['full_name'];
                echo "<p>Рады приветствовать вас снова, <strong>$firstName</strong>! Готовы покорять новые вершины знаний?</p>";
                ?>
                
                <div class="hero-stats">
                    <?php if ($user['role'] == 'student'): ?>
                        <div class="hero-stat">
    <span class="hero-stat-number" data-number="<?php echo $stats['passed_tests']; ?>">
        <?php echo $stats['passed_tests']; ?>
    </span>
    <span class="hero-stat-label">Пройдено тестов</span>
</div>
                        <div class="hero-stat">
                            <span class="hero-stat-number"><?php echo round($stats['avg_percentage'], 1); ?>%</span>
                            <span class="hero-stat-label">Средний результат</span>
                        </div>
                        <div class="hero-stat">
                            <span class="hero-stat-number"><?php echo count($available_tests); ?></span>
                            <span class="hero-stat-label">Доступно тестов</span>
                        </div>
                    <?php else: ?>
                        <div class="hero-stat">
                            <span class="hero-stat-number"><?php echo $stats['total_tests_created']; ?></span>
                            <span class="hero-stat-label">Создано тестов</span>
                        </div>
                        <div class="hero-stat">
                            <span class="hero-stat-number"><?php echo $stats['total_students']; ?></span>
                            <span class="hero-stat-label">Активных студентов</span>
                        </div>
                        <div class="hero-stat">
                            <span class="hero-stat-number"><?php echo round($stats['avg_success_rate'], 1); ?>%</span>
                            <span class="hero-stat-label">Успеваемость</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
 <div class="header">
            <div class="welcome">
                <h2>Ваша учебная панель</h2>
                <p>Здесь вы найдете всю необходимую информацию для эффективного обучения</p>
            </div>
            
            <div class="header-actions">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" id="globalSearch" placeholder="Поиск тестов, материалов, студентов...">
                </div>
                <div class="message-btn" onclick="openChatModal()">
                    <i class="fas fa-comments"></i>
                    <?php if ($unread_messages_count > 0): ?>
                        <span class="message-badge"><?php echo $unread_messages_count; ?></span>
                    <?php endif; ?>
                </div>
                <div class="notification-bell" onclick="toggleNotifications()">
                    <i class="fas fa-bell"></i>
                    <?php if (count($notifications) > 0): ?>
                        <span class="notification-badge"><?php echo count($notifications); ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <!-- Quick Actions -->
        <div class="quick-actions-grid">
            <?php if ($user['role'] == 'student'): ?>
                <div class="quick-action-card" onclick="window.location.href='tests.php'">
                    <div class="quick-action-icon">
                        <i class="fas fa-play-circle"></i>
                    </div>
                    <h3>Начать тест</h3>
                    <p>Приступить к прохождению</p>
                </div>
            <?php else: ?>
                <div class="quick-action-card" onclick="window.location.href='create_test.php'">
                    <div class="quick-action-icon">
                        <i class="fas fa-plus-circle"></i>
                    </div>
                    <h3>Создать тест</h3>
                    <p>Новое тестовое задание</p>
                </div>
            <?php endif; ?>
            <div class="quick-action-card" onclick="openChatModal()">
                <div class="quick-action-icon">
                    <i class="fas fa-comments"></i>
                </div>
                <h3>Чат</h3>
                <p>Общение с преподавателями</p>
            </div>
            <div class="quick-action-card" onclick="window.location.href='profile.php'">
                <div class="quick-action-icon">
                    <i class="fas fa-user-cog"></i>
                </div>
                <h3>Профиль</h3>
                <p>Настройки учетной записи</p>
            </div>
            <div class="quick-action-card" onclick="openHelpModal()">
                <div class="quick-action-icon">
                    <i class="fas fa-question-circle"></i>
                </div>
                <h3>Помощь</h3>
                <p>Руководство пользователя и FAQ</p>
            </div>
            
           
            
            
        </div>
        
       
        
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
                    
                    <!-- Учебные материалы для студентов -->
                    <div class="section">
                        <div class="section-header">
                            <h2><i class="fas fa-book-open"></i> Учебные материалы</h2>
                            <a href="study_materials.php" class="view-all">
                                Все материалы <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                        
                        <div class="materials-grid">
                            <?php if (count($learning_materials) > 0): ?>
                                <?php foreach ($learning_materials as $material): ?>
                                    <div class="material-card">
                                        <div class="material-header">
                                            <span class="material-type"><?php echo htmlspecialchars($material['type'] ?? 'PDF'); ?></span>
                                            <h3 class="material-title"><?php echo htmlspecialchars($material['title']); ?></h3>
                                            <div class="material-meta">
                                                <span><?php echo htmlspecialchars($material['author_name']); ?></span>
                                                <span><?php echo date('d.m.Y', strtotime($material['created_at'])); ?></span>
                                            </div>
                                        </div>
                                        <div class="material-body">
                                            <p class="material-description"><?php echo htmlspecialchars($material['description'] ?? 'Описание отсутствует'); ?></p>
                                        </div>
                                        <div class="material-footer">
                                            <div class="material-actions">
                                                <a href="view_material.php?id=<?php echo $material['id']; ?>" class="btn btn-primary btn-sm">
                                                    <i class="fas fa-eye"></i> Просмотреть
                                                </a>
                                                <a href="download_material.php?id=<?php echo $material['id']; ?>" class="btn btn-success btn-sm">
                                                    <i class="fas fa-download"></i> Скачать
                                                </a>
                                            </div>
                                            <span class="material-status <?php echo $material['is_viewed'] > 0 ? 'status-viewed' : 'status-new'; ?>">
                                                <?php echo $material['is_viewed'] > 0 ? 'Просмотрено' : 'Новое'; ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-book"></i>
                                    <p>Пока нет учебных материалов.</p>
                                    <p>Обратитесь к преподавателю для получения доступа.</p>
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
                                        <div class="user-avatar-small">
                                            <?php if (!empty($activity['avatar']) && file_exists('uploads/avatars/' . $activity['avatar'])): ?>
                                                <img src="uploads/avatars/<?php echo htmlspecialchars($activity['avatar']); ?>" alt="<?php echo htmlspecialchars($activity['full_name']); ?>">
                                            <?php else: ?>
                                                <?php 
                                                $firstName = $activity['full_name'];
                                                if (function_exists('mb_convert_encoding')) {
                                                    $firstName = mb_convert_encoding($firstName, 'UTF-8', 'auto');
                                                }
                                                $firstLetter = mb_substr($firstName, 0, 1, 'UTF-8');
                                                echo htmlspecialchars(strtoupper($firstLetter));
                                                ?>
                                            <?php endif; ?>
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
// Управление уведомлениями
function toggleNotifications() {
    const panel = document.getElementById('notificationsPanel');
    if (panel) {
        panel.classList.toggle('active');
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

function markNotificationAsRead(notificationId, element) {
    fetch('mark_notification_read.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ notification_id: notificationId })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const notificationItem = element.closest('.notification-item');
            notificationItem.classList.remove('unread');
            
            // Обновляем счетчик непрочитанных
            const unreadCount = document.querySelectorAll('.notification-item.unread').length;
            const badge = document.querySelector('.notification-badge');
            if (badge) {
                if (unreadCount > 0) {
                    badge.textContent = unreadCount;
                } else {
                    badge.style.display = 'none';
                }
            }
        }
    })
    .catch(error => {
        console.error('Error:', error);
    });
}

function deleteNotification(notificationId, element) {
    if (confirm('Удалить это уведомление?')) {
        fetch('delete_notification.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ notification_id: notificationId })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                element.closest('.notification-item').remove();
                
                // Если уведомлений не осталось, показываем пустое состояние
                const notificationsList = document.querySelector('.notifications-list');
                if (notificationsList.children.length === 1) { // Только empty state остался
                    const emptyState = notificationsList.querySelector('.notifications-empty');
                    if (!emptyState) {
                        notificationsList.innerHTML = `
                            <div class="notifications-empty">
                                <i class="fas fa-bell-slash"></i>
                                <h4>Нет уведомлений</h4>
                                <p>Здесь будут появляться важные сообщения системы</p>
                            </div>
                        `;
                    }
                }
            }
        })
        .catch(error => {
            console.error('Error:', error);
        });
    }
}

// Фильтрация по категориям
document.addEventListener('DOMContentLoaded', function() {
    const categories = document.querySelectorAll('.notification-category');
    
    categories.forEach(category => {
        category.addEventListener('click', function() {
            const categoryType = this.getAttribute('data-category');
            
            // Обновляем активную категорию
            categories.forEach(c => c.classList.remove('active'));
            this.classList.add('active');
            
            // Фильтруем уведомления
            const notifications = document.querySelectorAll('.notification-item');
            notifications.forEach(notification => {
                if (categoryType === 'all') {
                    notification.style.display = 'flex';
                } else {
                    const notificationType = notification.getAttribute('data-type');
                    if (categoryType === 'system' && notificationType === 'info') {
                        notification.style.display = 'flex';
                    } else if (categoryType === 'tests' && notificationType === 'success') {
                        notification.style.display = 'flex';
                    } else if (categoryType === 'materials' && notificationType === 'warning') {
                        notification.style.display = 'flex';
                    } else {
                        notification.style.display = 'none';
                    }
                }
            });
        });
    });
});

// Модальные окна
function openHelpModal() {
    document.getElementById('helpModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeHelpModal() {
    document.getElementById('helpModal').classList.remove('active');
    document.body.style.overflow = 'auto';
}

function openChatModal() {
    document.getElementById('chatModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeChatModal() {
    document.getElementById('chatModal').classList.remove('active');
    document.body.style.overflow = 'auto';
}

// FAQ Accordion
document.addEventListener('DOMContentLoaded', function() {
    const faqQuestions = document.querySelectorAll('.faq-question');
    
    faqQuestions.forEach(question => {
        question.addEventListener('click', function() {
            const answer = this.nextElementSibling;
            const isActive = this.classList.contains('active');
            
            // Закрываем все ответы
            document.querySelectorAll('.faq-question').forEach(q => {
                q.classList.remove('active');
            });
            document.querySelectorAll('.faq-answer').forEach(a => {
                a.classList.remove('active');
            });
            
            // Открываем текущий ответ, если он был закрыт
            if (!isActive) {
                this.classList.add('active');
                answer.classList.add('active');
            }
        });
    });
});

// Чат функциональность
document.addEventListener('DOMContentLoaded', function() {
    const messageInput = document.getElementById('messageInput');
    const sendMessageBtn = document.getElementById('sendMessageBtn');
    const chatMessages = document.getElementById('chatMessages');
    const chatContacts = document.querySelectorAll('.chat-contact');
    
    // Автоматическое изменение высоты текстового поля
    messageInput.addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = (this.scrollHeight) + 'px';
    });
    
    // Отправка сообщения
    function sendMessage() {
        const message = messageInput.value.trim();
        if (message === '') return;
        
        // Добавляем сообщение в чат
        const messageElement = document.createElement('div');
        messageElement.className = 'message sent';
        messageElement.innerHTML = `
            <div class="message-avatar">
                <?php 
                $firstName = $user['full_name'];
                if (function_exists('mb_convert_encoding')) {
                    $firstName = mb_convert_encoding($firstName, 'UTF-8', 'auto');
                }
                $firstLetter = mb_substr($firstName, 0, 1, 'UTF-8');
                echo htmlspecialchars(strtoupper($firstLetter));
                ?>
            </div>
            <div class="message-content">
                <p class="message-text">${message}</p>
                <div class="message-time">${new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</div>
            </div>
        `;
        
        chatMessages.appendChild(messageElement);
        messageInput.value = '';
        messageInput.style.height = 'auto';
        
        // Прокручиваем к последнему сообщению
        chatMessages.scrollTop = chatMessages.scrollHeight;
        
        // Имитируем ответ преподавателя через 2 секунды
        setTimeout(() => {
            const responses = [
                "Спасибо за вопрос! Я постараюсь ответить как можно скорее.",
                "Интересный вопрос. Давайте разберем его на следующем занятии.",
                "Этот материал мы рассмотрим подробнее в следующей теме.",
                "Вы можете найти дополнительную информацию в учебных материалах."
            ];
            const randomResponse = responses[Math.floor(Math.random() * responses.length)];
            
            const responseElement = document.createElement('div');
            responseElement.className = 'message received';
            responseElement.innerHTML = `
                <div class="message-avatar">
                    <i class="fas fa-user-tie"></i>
                </div>
                <div class="message-content">
                    <p class="message-text">${randomResponse}</p>
                    <div class="message-time">${new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</div>
                </div>
            `;
            
            chatMessages.appendChild(responseElement);
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }, 2000);
    }
    
    // Отправка по кнопке
    sendMessageBtn.addEventListener('click', sendMessage);
    
    // Отправка по Enter (но не Shift+Enter)
    messageInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });
    
    // Переключение контактов
    chatContacts.forEach(contact => {
        contact.addEventListener('click', function() {
            chatContacts.forEach(c => c.classList.remove('active'));
            this.classList.add('active');
            
            // Здесь можно добавить логику загрузки истории сообщений для выбранного контакта
        });
    });
});

// Закрытие модальных окон при клике вне их
document.addEventListener('click', function(e) {
    const helpModal = document.getElementById('helpModal');
    const chatModal = document.getElementById('chatModal');
    const notificationsPanel = document.getElementById('notificationsPanel');
    
    if (helpModal && helpModal.classList.contains('active') && e.target === helpModal) {
        closeHelpModal();
    }
    
    if (chatModal && chatModal.classList.contains('active') && e.target === chatModal) {
        closeChatModal();
    }
    
    if (notificationsPanel && !notificationsPanel.contains(e.target) && 
        !e.target.closest('.notification-bell')) {
        notificationsPanel.classList.remove('active');
    }
});

// Закрытие по ESC
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeHelpModal();
        closeChatModal();
        document.getElementById('notificationsPanel').classList.remove('active');
    }
});

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
            closeTestModal();
        });
    }
    
    if (updatedModal) {
        updatedModal.addEventListener('click', function(e) {
            if (e.target === updatedModal) {
                closeTestModal();
            }
        });
    }
    
    function closeTestModal() {
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
        $year = substr($week['week'], 0, 4);
        $weekNum = substr($week['week'], 4);
        
        // Получаем первый день недели
        $date = new DateTime();
        $date->setISODate($year, $weekNum);
        $startOfWeek = $date->format('d.m');
        
        // Получаем последний день недели
        $date->modify('+6 days');
        $endOfWeek = $date->format('d.m');
        
        $month = $date->format('m');
        $monthNames = [
            '01' => 'января', '02' => 'февраля', '03' => 'марта', '04' => 'апреля',
            '05' => 'мая', '06' => 'июня', '07' => 'июля', '08' => 'августа',
            '09' => 'сентября', '10' => 'октября', '11' => 'ноября', '12' => 'декабря'
        ];
        
        return $startOfWeek . ' - ' . $endOfWeek . ' ' . $monthNames[$month] . ' ' . $year;
    }, array_reverse($weekly_progress))); ?>,
    datasets: [{
        label: 'Средний результат (%)',
        data: <?php echo json_encode(array_map(function($week) { 
            return round($week['avg_score'], 1); 
        }, array_reverse($weekly_progress))); ?>,
        borderColor: '#3498db',
        backgroundColor: 'rgba(52, 152, 219, 0.1)',
        tension: 0.4,
        fill: true,
        pointBackgroundColor: '#3498db',
        pointBorderColor: '#fff',
        pointBorderWidth: 2,
        pointRadius: 5,
        pointHoverRadius: 7
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
    const cards = document.querySelectorAll('.stat-card, .test-item, .section, .quick-action-card, .material-card');
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