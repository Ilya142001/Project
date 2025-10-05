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

// Обработка параметров фильтрации
$filter_subject = isset($_GET['subject']) ? $_GET['subject'] : '';
$filter_date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$filter_date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$filter_passed = isset($_GET['passed']) ? $_GET['passed'] : '';

// Базовый запрос для результатов
$query = "
    SELECT tr.*, t.title, t.subject, u.full_name as teacher_name, 
           t.time_limit, t.created_by, t.description
    FROM test_results tr
    JOIN tests t ON tr.test_id = t.id
    JOIN users u ON t.created_by = u.id
    WHERE tr.user_id = ?
";

$params = [$_SESSION['user_id']];

// Добавляем фильтры
if (!empty($filter_subject)) {
    $query .= " AND t.subject = ?";
    $params[] = $filter_subject;
}

if (!empty($filter_date_from)) {
    $query .= " AND DATE(tr.completed_at) >= ?";
    $params[] = $filter_date_from;
}

if (!empty($filter_date_to)) {
    $query .= " AND DATE(tr.completed_at) <= ?";
    $params[] = $filter_date_to;
}

if (!empty($filter_passed)) {
    if ($filter_passed == 'passed') {
        $query .= " AND tr.passed = 1";
    } elseif ($filter_passed == 'failed') {
        $query .= " AND tr.passed = 0";
    }
}

// Сортировка
$query .= " ORDER BY tr.completed_at DESC";

// Получаем результаты
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$results = $stmt->fetchAll();

// Получаем список предметов для фильтра
$stmt = $pdo->prepare("SELECT DISTINCT subject FROM tests WHERE is_active = 1 ORDER BY subject");
$stmt->execute();
$subjects = $stmt->fetchAll();

// Статистика для заголовка
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_tests,
        AVG(percentage) as avg_score,
        COUNT(CASE WHEN passed = 1 THEN 1 END) as passed_tests,
        MAX(percentage) as best_score
    FROM test_results 
    WHERE user_id = ?
");
$stmt->execute([$_SESSION['user_id']]);
$stats = $stmt->fetch();

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
    <title>Мои результаты - Система интеллектуальной оценки знаний</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Все предыдущие стили остаются без изменений */
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
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
        
        /* Filters */
        .filters-section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            margin-bottom: 25px;
        }
        
        .filters-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .filters-header h2 {
            font-size: 20px;
            color: var(--secondary);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .filter-group label {
            font-weight: 600;
            color: var(--secondary);
            font-size: 14px;
        }
        
        .filter-group select,
        .filter-group input {
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        .filter-group select:focus,
        .filter-group input:focus {
            border-color: var(--primary);
            outline: none;
        }
        
        .filter-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }
        
        .btn {
            padding: 12px 25px;
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
        }
        
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
        }
        
        .btn-outline {
            background: transparent;
            color: var(--primary);
            border: 2px solid var(--primary);
        }
        
        .btn-outline:hover {
            background: var(--primary);
            color: white;
        }
        
        /* Results Table */
        .results-section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
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
        
        .results-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .results-table th {
            background: var(--light);
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: var(--secondary);
            border-bottom: 2px solid #e0e0e0;
        }
        
        .results-table td {
            padding: 15px;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .results-table tr:hover {
            background: #f8f9fa;
        }
        
        .test-title {
            font-weight: 600;
            color: var(--secondary);
        }
        
        .test-subject {
            background: rgba(52, 152, 219, 0.1);
            color: var(--primary);
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .score-excellent {
            color: var(--success);
            font-weight: 600;
        }
        
        .score-good {
            color: var(--warning);
            font-weight: 600;
        }
        
        .score-poor {
            color: var(--danger);
            font-weight: 600;
        }
        
        .status-passed {
            background: var(--success);
            color: white;
            padding: 6px 12px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-failed {
            background: var(--danger);
            color: white;
            padding: 6px 12px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .action-btn {
            padding: 8px 15px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            transition: background 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .action-btn:hover {
            background: var(--primary-dark);
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--gray);
        }
        
        .empty-state i {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .empty-state h3 {
            font-size: 20px;
            margin-bottom: 10px;
            color: var(--secondary);
        }
        
        .empty-state p {
            font-size: 16px;
            margin-bottom: 20px;
        }

        /* ===== МОДАЛЬНОЕ ОКНО ДЛЯ ПОДРОБНОСТЕЙ ===== */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 2000;
            opacity: 0;
            transition: opacity 0.3s ease;
            align-items: center;
            justify-content: center;
        }

        .modal-overlay.active {
            display: flex;
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
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
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
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--gray);
            width: 40px;
            height: 40px;
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
            padding: 25px;
            max-height: calc(90vh - 80px);
            overflow-y: auto;
            scrollbar-width: thin;
        }

        .modal-body::-webkit-scrollbar {
            width: 6px;
        }

        .modal-body::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 3px;
        }

        .modal-body::-webkit-scrollbar-thumb {
            background: var(--primary);
            border-radius: 3px;
        }

        /* Стили для контента модального окна */
        .result-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 25px;
        }

        .detail-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            border-left: 4px solid var(--primary);
        }

        .detail-section.success {
            border-left-color: var(--success);
        }

        .detail-section.warning {
            border-left-color: var(--warning);
        }

        .detail-section.danger {
            border-left-color: var(--danger);
        }

        .detail-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--secondary);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .detail-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e9ecef;
        }

        .detail-item:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }

        .detail-label {
            color: var(--gray);
            font-weight: 500;
        }

        .detail-value {
            font-weight: 600;
            color: var(--secondary);
        }

        .detail-value.success {
            color: var(--success);
        }

        .detail-value.warning {
            color: var(--warning);
        }

        .detail-value.danger {
            color: var(--danger);
        }

        .progress-container {
            margin: 20px 0;
        }

        .progress-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 14px;
        }

        .progress-bar {
            height: 10px;
            background: #e9ecef;
            border-radius: 5px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
            border-radius: 5px;
            transition: width 0.5s ease;
        }

        .progress-fill.success {
            background: linear-gradient(90deg, var(--success), #27ae60);
        }

        .progress-fill.warning {
            background: linear-gradient(90deg, var(--warning), #e67e22);
        }

        .progress-fill.danger {
            background: linear-gradient(90deg, var(--danger), #c0392b);
        }

        .test-description {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-top: 20px;
        }

        .test-description h4 {
            color: var(--secondary);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .test-description p {
            color: var(--gray);
            line-height: 1.6;
        }

        .modal-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #e9ecef;
        }

        .btn-sm {
            padding: 10px 20px;
            font-size: 14px;
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
            
            .stats-cards {
                grid-template-columns: 1fr;
            }
            
            .filters-grid {
                grid-template-columns: 1fr;
            }
            
            .results-table {
                display: block;
                overflow-x: auto;
            }
            
            .section-header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
            
            .header-actions {
                width: 100%;
                justify-content: space-between;
            }

            .modal-content {
                width: 95%;
                margin: 20px;
            }

            .result-details {
                grid-template-columns: 1fr;
                gap: 20px;
            }

            .modal-actions {
                flex-direction: column;
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
                <i class="fas fa-file-alt"></i>
                <span>Тестов пройдено: <?php echo $stats['total_tests']; ?></span>
            </div>
            <div class="stat-item">
                <i class="fas fa-percentage"></i>
                <span>Успеваемость: <?php echo round($stats['avg_score'], 1); ?>%</span>
            </div>
        </div>

        <ul class="nav-links">
            <div class="nav-section">
                <div class="section-label">Основное</div>
            </div>
            <li><a href="dashboard.php"><i class="fas fa-home"></i> Главная</a></li>
            <li><a href="tests.php"><i class="fas fa-file-alt"></i> Тесты</a></li>
            <li><a href="results.php" class="active"><i class="fas fa-chart-bar"></i> Мои результаты</a></li>
            
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
            <li><a href="progress.php"><i class="fas fa-trophy"></i> Прогресс</a></li>

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
        <div class="header">
            <div class="welcome">
                <h2>Мои результаты тестирования</h2>
                <p>Просмотр истории пройденных тестов и статистики</p>
            </div>
            
            <div class="header-actions">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" id="tableSearch" placeholder="Поиск по тестам...">
                </div>
                <div class="notification-bell" onclick="toggleNotifications()">
                    <i class="fas fa-bell"></i>
                    <?php if (count($notifications) > 0): ?>
                        <span class="notification-badge"><?php echo count($notifications); ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Статистика -->
        <div class="stats-cards">
            <div class="stat-card">
                <div class="stat-icon icon-primary">
                    <i class="fas fa-file-alt"></i>
                </div>
                <div class="stat-details">
                    <h3><?php echo $stats['total_tests']; ?></h3>
                    <p>Всего тестов пройдено</p>
                </div>
            </div>
            
            <div class="stat-card info">
                <div class="stat-icon icon-info">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="stat-details">
                    <h3><?php echo round($stats['avg_score'], 1); ?>%</h3>
                    <p>Средний результат</p>
                </div>
            </div>
            
            <div class="stat-card success">
                <div class="stat-icon icon-success">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-details">
                    <h3><?php echo $stats['passed_tests']; ?></h3>
                    <p>Успешно сдано</p>
                </div>
            </div>
            
            <div class="stat-card warning">
                <div class="stat-icon icon-warning">
                    <i class="fas fa-trophy"></i>
                </div>
                <div class="stat-details">
                    <h3><?php echo round($stats['best_score'], 1); ?>%</h3>
                    <p>Лучший результат</p>
                </div>
            </div>
        </div>
        
        <!-- Фильтры -->
        <div class="filters-section">
            <div class="filters-header">
                <h2><i class="fas fa-filter"></i> Фильтры результатов</h2>
            </div>
            
            <form method="GET" action="results.php">
                <div class="filters-grid">
                    <div class="filter-group">
                        <label for="subject">Предмет:</label>
                        <select id="subject" name="subject">
                            <option value="">Все предметы</option>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?php echo htmlspecialchars($subject['subject']); ?>" 
                                    <?php echo $filter_subject == $subject['subject'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($subject['subject']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="date_from">Дата с:</label>
                        <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars($filter_date_from); ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label for="date_to">Дата по:</label>
                        <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars($filter_date_to); ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label for="passed">Статус:</label>
                        <select id="passed" name="passed">
                            <option value="">Все результаты</option>
                            <option value="passed" <?php echo $filter_passed == 'passed' ? 'selected' : ''; ?>>Сдано</option>
                            <option value="failed" <?php echo $filter_passed == 'failed' ? 'selected' : ''; ?>>Не сдано</option>
                        </select>
                    </div>
                </div>
                
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Применить фильтры
                    </button>
                    <a href="results.php" class="btn btn-outline">
                        <i class="fas fa-times"></i> Сбросить
                    </a>
                </div>
            </form>
        </div>
        
        <!-- Таблица результатов -->
        <div class="results-section">
            <div class="section-header">
                <h2><i class="fas fa-list-alt"></i> История тестирования</h2>
                <span>Найдено результатов: <?php echo count($results); ?></span>
            </div>
            
            <?php if (count($results) > 0): ?>
                <table class="results-table">
                    <thead>
                        <tr>
                            <th>Тест</th>
                            <th>Предмет</th>
                            <th>Преподаватель</th>
                            <th>Результат</th>
                            <th>Статус</th>
                            <th>Дата</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $result): ?>
                            <tr>
                                <td>
                                    <div class="test-title"><?php echo htmlspecialchars($result['title']); ?></div>
                                </td>
                                <td>
                                    <span class="test-subject"><?php echo htmlspecialchars($result['subject']); ?></span>
                                </td>
                                <td><?php echo htmlspecialchars($result['teacher_name']); ?></td>
                                <td>
                                    <span class="<?php 
                                        echo $result['percentage'] >= 80 ? 'score-excellent' : 
                                             ($result['percentage'] >= 60 ? 'score-good' : 'score-poor'); 
                                    ?>">
                                        <?php echo $result['score']; ?>/<?php echo $result['total_points']; ?> 
                                        (<?php echo round($result['percentage'], 1); ?>%)
                                    </span>
                                </td>
                                <td>
                                    <?php if ($result['passed']): ?>
                                        <span class="status-passed">Сдан</span>
                                    <?php else: ?>
                                        <span class="status-failed">Не сдан</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('d.m.Y H:i', strtotime($result['completed_at'])); ?></td>
                                <td>
                                    <button class="action-btn view-details-btn" 
                                            data-result-id="<?php echo $result['id']; ?>"
                                            data-test-title="<?php echo htmlspecialchars($result['title']); ?>"
                                            data-test-subject="<?php echo htmlspecialchars($result['subject']); ?>"
                                            data-teacher-name="<?php echo htmlspecialchars($result['teacher_name']); ?>"
                                            data-score="<?php echo $result['score']; ?>"
                                            data-total-points="<?php echo $result['total_points']; ?>"
                                            data-percentage="<?php echo $result['percentage']; ?>"
                                            data-passed="<?php echo $result['passed']; ?>"
                                            data-completed-at="<?php echo date('d.m.Y H:i', strtotime($result['completed_at'])); ?>"
                                            data-time-limit="<?php echo $result['time_limit']; ?>"
                                            data-description="<?php echo htmlspecialchars($result['description'] ?? 'Описание отсутствует'); ?>">
                                        <i class="fas fa-eye"></i> Подробнее
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-clipboard-list"></i>
                    <h3>Результаты не найдены</h3>
                    <p>Вы еще не прошли ни одного теста или результаты не соответствуют выбранным фильтрам</p>
                    <a href="tests.php" class="btn btn-primary" style="margin-top: 15px;">
                        <i class="fas fa-play"></i> Пройти тесты
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Модальное окно для подробной информации -->
    <div class="modal-overlay" id="resultModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-chart-bar"></i> Детали результата теста</h2>
                <button class="modal-close" id="modalClose">&times;</button>
            </div>
            <div class="modal-body">
                <div class="result-details">
                    <div class="detail-section">
                        <div class="detail-title">
                            <i class="fas fa-info-circle"></i>
                            Основная информация
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Название теста:</span>
                            <span class="detail-value" id="modalTestTitle">-</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Предмет:</span>
                            <span class="detail-value" id="modalTestSubject">-</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Преподаватель:</span>
                            <span class="detail-value" id="modalTeacherName">-</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Дата прохождения:</span>
                            <span class="detail-value" id="modalCompletedAt">-</span>
                        </div>
                    </div>

                    <div class="detail-section" id="modalResultSection">
                        <div class="detail-title">
                            <i class="fas fa-chart-line"></i>
                            Результаты
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Набрано баллов:</span>
                            <span class="detail-value" id="modalScore">-</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Максимум баллов:</span>
                            <span class="detail-value" id="modalTotalPoints">-</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Процент выполнения:</span>
                            <span class="detail-value" id="modalPercentage">-</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Статус:</span>
                            <span class="detail-value" id="modalStatus">-</span>
                        </div>
                        
                        <div class="progress-container">
                            <div class="progress-label">
                                <span>Прогресс выполнения:</span>
                                <span id="modalProgressText">0%</span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill" id="modalProgressFill" style="width: 0%"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="test-description">
                    <h4><i class="fas fa-file-alt"></i> Описание теста</h4>
                    <p id="modalDescription">Загрузка описания...</p>
                </div>

                <div class="modal-actions">
                    <button class="btn btn-outline btn-sm" onclick="closeModal()">
                        <i class="fas fa-times"></i> Закрыть
                    </button>
                    <button class="btn btn-primary btn-sm" id="modalRetryBtn" style="display: none;">
                        <i class="fas fa-redo"></i> Пройти заново
                    </button>
                    <button class="btn btn-success btn-sm" id="modalAnalyzeBtn">
                        <i class="fas fa-chart-pie"></i> Анализ ошибок
                    </button>
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

        // Поиск по таблице
        document.getElementById('tableSearch').addEventListener('input', function() {
            const filter = this.value.toLowerCase();
            const rows = document.querySelectorAll('.results-table tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(filter) ? '' : 'none';
            });
        });

        // Модальное окно для подробной информации
        const modal = document.getElementById('resultModal');
        const modalClose = document.getElementById('modalClose');
        const viewDetailBtns = document.querySelectorAll('.view-details-btn');

        viewDetailBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                const resultId = this.getAttribute('data-result-id');
                const testTitle = this.getAttribute('data-test-title');
                const testSubject = this.getAttribute('data-test-subject');
                const teacherName = this.getAttribute('data-teacher-name');
                const score = this.getAttribute('data-score');
                const totalPoints = this.getAttribute('data-total-points');
                const percentage = this.getAttribute('data-percentage');
                const passed = this.getAttribute('data-passed') === '1';
                const completedAt = this.getAttribute('data-completed-at');
                const timeLimit = this.getAttribute('data-time-limit');
                const description = this.getAttribute('data-description');

                // Заполняем модальное окно данными
                document.getElementById('modalTestTitle').textContent = testTitle;
                document.getElementById('modalTestSubject').textContent = testSubject;
                document.getElementById('modalTeacherName').textContent = teacherName;
                document.getElementById('modalCompletedAt').textContent = completedAt;
                document.getElementById('modalScore').textContent = `${score} баллов`;
                document.getElementById('modalTotalPoints').textContent = `${totalPoints} баллов`;
                document.getElementById('modalPercentage').textContent = `${percentage}%`;
                
                // Устанавливаем статус
                const statusElement = document.getElementById('modalStatus');
                if (passed) {
                    statusElement.textContent = 'Тест сдан';
                    statusElement.className = 'detail-value success';
                    document.getElementById('modalResultSection').classList.add('success');
                    document.getElementById('modalProgressFill').classList.add('success');
                } else {
                    statusElement.textContent = 'Тест не сдан';
                    statusElement.className = 'detail-value danger';
                    document.getElementById('modalResultSection').classList.add('danger');
                    document.getElementById('modalProgressFill').classList.add('danger');
                }

                // Обновляем прогресс-бар
                const progressFill = document.getElementById('modalProgressFill');
                const progressText = document.getElementById('modalProgressText');
                progressFill.style.width = `${percentage}%`;
                progressText.textContent = `${percentage}%`;

                // Устанавливаем описание
                document.getElementById('modalDescription').textContent = description;

                // Показываем/скрываем кнопку пересдачи
                const retryBtn = document.getElementById('modalRetryBtn');
                retryBtn.style.display = !passed ? 'inline-flex' : 'none';
                retryBtn.onclick = function() {
                    alert(`Запуск пересдачи теста: ${testTitle}`);
                    closeModal();
                };

                // Настройка кнопки анализа
                document.getElementById('modalAnalyzeBtn').onclick = function() {
                    alert(`Запуск анализа ошибок для теста: ${testTitle}`);
                    closeModal();
                };

                // Показываем модальное окно
                openModal();
            });
        });

        function openModal() {
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeModal() {
            modal.classList.remove('active');
            document.body.style.overflow = 'auto';
            
            // Сбрасываем классы стилей
            const resultSection = document.getElementById('modalResultSection');
            resultSection.className = 'detail-section';
            
            const progressFill = document.getElementById('modalProgressFill');
            progressFill.className = 'progress-fill';
        }

        modalClose.addEventListener('click', closeModal);

        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeModal();
            }
        });

        // Закрытие по ESC
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });

        // Анимация появления элементов
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.stat-card, .filters-section, .results-section');
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

        // Подтверждение сброса фильтров
        document.querySelector('.btn-outline').addEventListener('click', function(e) {
            if (!confirm('Вы уверены, что хотите сбросить все фильтры?')) {
                e.preventDefault();
            }
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