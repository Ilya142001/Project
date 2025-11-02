<?php
// progress.php (исправленная версия)
session_start();

try {
    $pdo = new PDO("mysql:host=127.0.0.1;dbname=knowledge_assessment;charset=utf8mb4", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Ошибка подключения к базе данных: " . $e->getMessage());
}

// Получаем статистику пользователей
$users_stats = $pdo->query("
    SELECT 
        u.id,
        u.full_name,
        u.email,
        u.role,
        u.avatar,
        u.group_name,
        u.tests_taken as tests_completed,
        u.last_activity,
        COUNT(DISTINCT tr.id) as total_tests,
        COALESCE(SUM(tr.passed), 0) as passed_tests,
        COALESCE(AVG(tr.percentage), 0) as avg_score
    FROM users u
    LEFT JOIN test_results tr ON u.id = tr.user_id
    GROUP BY u.id
    ORDER BY u.role, u.full_name
")->fetchAll(PDO::FETCH_ASSOC);

// Получаем статистику тестов
$tests_stats = $pdo->query("
    SELECT 
        t.id,
        t.title,
        t.description,
        t.time_limit,
        t.created_at,
        u.full_name as author,
        COUNT(DISTINCT tr.id) as attempts,
        COUNT(DISTINCT tr.user_id) as unique_users,
        COALESCE(AVG(tr.percentage), 0) as avg_score,
        COALESCE(SUM(tr.passed), 0) as passed_count
    FROM tests t
    LEFT JOIN users u ON t.created_by = u.id
    LEFT JOIN test_results tr ON t.id = tr.test_id
    WHERE t.is_active = 1
    GROUP BY t.id
    ORDER BY attempts DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Получаем статистику ML моделей
$ml_stats = $pdo->query("
    SELECT 
        m.id,
        m.name,
        m.description,
        m.accuracy,
        m.is_active,
        m.created_at,
        COUNT(DISTINCT mta.test_id) as assigned_tests,
        COUNT(DISTINCT mp.id) as predictions_count
    FROM ml_models m
    LEFT JOIN model_test_assignments mta ON m.id = mta.model_id
    LEFT JOIN model_predictions mp ON m.id = mp.model_id
    GROUP BY m.id
    ORDER BY m.is_active DESC, m.accuracy DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Общая статистика системы
$system_stats = $pdo->query("
    SELECT 
        COUNT(DISTINCT u.id) as total_users,
        COUNT(DISTINCT t.id) as total_tests,
        COUNT(DISTINCT tr.id) as total_attempts,
        COUNT(DISTINCT m.id) as total_models,
        COALESCE(AVG(tr.percentage), 0) as system_avg_score
    FROM users u
    CROSS JOIN tests t
    LEFT JOIN test_results tr ON t.id = tr.test_id
    CROSS JOIN ml_models m
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

// Подсчет дополнительной статистики
$student_count = 0;
$teacher_count = 0;
$active_models_count = 0;
$total_attempts_count = 0;

foreach ($users_stats as $user) {
    if ($user['role'] === 'student') {
        $student_count++;
    } elseif ($user['role'] === 'teacher') {
        $teacher_count++;
    }
}

foreach ($ml_stats as $model) {
    if ($model['is_active']) {
        $active_models_count++;
    }
}

foreach ($tests_stats as $test) {
    $total_attempts_count += $test['attempts'];
}

// Функции для модальных окон
function getUserDetails($pdo, $user_id) {
    $stmt = $pdo->prepare("
        SELECT 
            u.*,
            COUNT(DISTINCT tr.id) as total_attempts,
            COUNT(DISTINCT tr.test_id) as unique_tests,
            COALESCE(SUM(tr.passed), 0) as passed_tests,
            COALESCE(AVG(tr.percentage), 0) as avg_score,
            MIN(tr.completed_at) as first_test,
            MAX(tr.completed_at) as last_test
        FROM users u
        LEFT JOIN test_results tr ON u.id = tr.user_id
        WHERE u.id = ?
        GROUP BY u.id
    ");
    $stmt->execute([$user_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getUserTestHistory($pdo, $user_id) {
    $stmt = $pdo->prepare("
        SELECT 
            tr.*,
            t.title as test_title,
            t.time_limit,
            TIMESTAMPDIFF(MINUTE, tr.start_time, tr.end_time) as time_taken
        FROM test_results tr
        JOIN tests t ON tr.test_id = t.id
        WHERE tr.user_id = ?
        ORDER BY tr.completed_at DESC
        LIMIT 10
    ");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getTestDetails($pdo, $test_id) {
    $stmt = $pdo->prepare("
        SELECT 
            t.*,
            u.full_name as author_name,
            u.email as author_email,
            COUNT(DISTINCT q.id) as question_count,
            SUM(q.points) as total_points
        FROM tests t
        LEFT JOIN users u ON t.created_by = u.id
        LEFT JOIN questions q ON t.id = q.test_id
        WHERE t.id = ?
        GROUP BY t.id
    ");
    $stmt->execute([$test_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getTestAttempts($pdo, $test_id) {
    $stmt = $pdo->prepare("
        SELECT 
            tr.*,
            u.full_name as user_name,
            u.email as user_email,
            TIMESTAMPDIFF(SECOND, tr.start_time, tr.end_time) as duration_seconds
        FROM test_results tr
        JOIN users u ON tr.user_id = u.id
        WHERE tr.test_id = ?
        ORDER BY tr.completed_at DESC
    ");
    $stmt->execute([$test_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getModelHistory($pdo, $model_id) {
    $stmt = $pdo->prepare("
        SELECT 
            mh.*,
            u.full_name as changed_by_name,
            t.title as test_title
        FROM model_history mh
        LEFT JOIN users u ON mh.changed_by = u.id
        LEFT JOIN tests t ON mh.new_value = t.id OR mh.old_value = t.id
        WHERE mh.model_id = ?
        ORDER BY mh.changed_at DESC
        LIMIT 20
    ");
    $stmt->execute([$model_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Обработка AJAX запросов для модальных окон
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_GET['action']) {
            case 'get_user_details':
                if (isset($_GET['user_id'])) {
                    $user_details = getUserDetails($pdo, $_GET['user_id']);
                    $test_history = getUserTestHistory($pdo, $_GET['user_id']);
                    
                    // Исправление ошибки: проверяем, что avg_score является числом
                    if (isset($user_details['avg_score'])) {
                        $user_details['avg_score'] = floatval($user_details['avg_score']);
                    } else {
                        $user_details['avg_score'] = 0;
                    }
                    
                    echo json_encode([
                        'success' => true,
                        'user' => $user_details,
                        'test_history' => $test_history
                    ]);
                }
                break;
                
            case 'get_test_details':
                if (isset($_GET['test_id'])) {
                    $test_details = getTestDetails($pdo, $_GET['test_id']);
                    $test_attempts = getTestAttempts($pdo, $_GET['test_id']);
                    echo json_encode([
                        'success' => true,
                        'test' => $test_details,
                        'attempts' => $test_attempts
                    ]);
                }
                break;
                
            case 'get_model_details':
                if (isset($_GET['model_id'])) {
                    $model_history = getModelHistory($pdo, $_GET['model_id']);
                    echo json_encode([
                        'success' => true,
                        'history' => $model_history
                    ]);
                }
                break;
        }
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// Статические данные серверов
$servers = [
    [
        'name' => 'Web Server',
        'status' => 'online',
        'cpu' => 45,
        'memory' => 78,
        'disk' => 62,
        'uptime' => '15 дней',
        'ip' => '192.168.1.10',
        'type' => 'web'
    ],
    [
        'name' => 'Database Server',
        'status' => 'online', 
        'cpu' => 23,
        'memory' => 45,
        'disk' => 34,
        'uptime' => '30 дней',
        'ip' => '192.168.1.11',
        'type' => 'database'
    ],
    [
        'name' => 'ML Processing',
        'status' => 'online',
        'cpu' => 67,
        'memory' => 82,
        'disk' => 55,
        'uptime' => '8 дней',
        'ip' => '192.168.1.12',
        'type' => 'ml'
    ],
    [
        'name' => 'File Storage',
        'status' => 'warning',
        'cpu' => 12,
        'memory' => 91,
        'disk' => 88,
        'uptime' => '25 дней',
        'ip' => '192.168.1.13',
        'type' => 'storage'
    ]
];
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Панель мониторинга - Система оценки знаний</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #2c3e50;
            --primary-dark: #1a252f;
            --secondary: #34495e;
            --accent: #3498db;
            --success: #27ae60;
            --warning: #f39c12;
            --danger: #e74c3c;
            --info: #2980b9;
            --light: #ecf0f1;
            --dark: #2c3e50;
            --gray: #7f8c8d;
            --border: #bdc3c7;
            --shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #ecf0f1 0%, #bdc3c7 100%);
            min-height: 100vh;
            color: var(--dark);
            line-height: 1.6;
            font-size: 14px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        /* Header Styles */
        .header {
            background: white;
            border-radius: 8px;
            padding: 25px 30px;
            margin-bottom: 20px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        .header-text h1 {
            font-size: 1.8rem;
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 5px;
        }

        .header-text p {
            font-size: 0.9rem;
            color: var(--gray);
            margin-bottom: 0;
        }

        .header-stats {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .header-stat {
            text-align: center;
            padding: 12px 20px;
            background: white;
            color: var(--dark);
            border-radius: 6px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            min-width: 120px;
        }

        .header-stat .number {
            font-size: 1.5rem;
            font-weight: 600;
            display: block;
            color: var(--primary);
        }

        .header-stat .label {
            font-size: 0.8rem;
            color: var(--gray);
        }

        /* Navigation */
        .nav-tabs {
            display: flex;
            background: white;
            border-radius: 6px;
            padding: 8px;
            margin-bottom: 20px;
            box-shadow: var(--shadow);
            gap: 8px;
            flex-wrap: wrap;
            border: 1px solid var(--border);
        }

        .nav-tab {
            padding: 10px 20px;
            border: none;
            background: transparent;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 500;
            color: var(--gray);
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
        }

        .nav-tab:hover {
            background: var(--light);
            color: var(--primary);
        }

        .nav-tab.active {
            background: var(--primary);
            color: white;
            box-shadow: var(--shadow);
        }

        /* Tab Content */
        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Main Grid */
        .main-grid {
            display: grid;
            grid-template-columns: 1fr 300px;
            gap: 20px;
        }

        @media (max-width: 1200px) {
            .main-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Content Sections */
        .content-section {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border);
        }

        .section-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .section-actions {
            display: flex;
            gap: 8px;
        }

        .btn {
            padding: 8px 16px;
            border: 1px solid var(--border);
            border-radius: 4px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.85rem;
            background: white;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .btn-primary:hover {
            background: var(--primary-dark);
        }

        .btn-outline {
            background: transparent;
            color: var(--gray);
        }

        .btn-outline:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: white;
            color: var(--dark);
            border-radius: 6px;
            padding: 20px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            text-align: center;
        }

        .stat-icon {
            font-size: 2rem;
            margin-bottom: 10px;
            color: var(--primary);
        }

        .stat-number {
            font-size: 1.8rem;
            font-weight: 600;
            margin-bottom: 5px;
            display: block;
            color: var(--primary);
        }

        .stat-label {
            font-size: 0.8rem;
            color: var(--gray);
        }

        /* Tables */
        .table-container {
            overflow-x: auto;
            border-radius: 6px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            font-size: 0.85rem;
        }

        th {
            background: var(--light);
            color: var(--dark);
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
            border-bottom: 1px solid var(--border);
        }

        td {
            padding: 12px 15px;
            border-bottom: 1px solid var(--border);
        }

        tr:hover {
            background: #f8f9fa;
        }

        /* Badges */
        .badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .badge-success {
            background: #d5f4e6;
            color: #27ae60;
        }

        .badge-warning {
            background: #fef5e7;
            color: #f39c12;
        }

        .badge-info {
            background: #e8f4fc;
            color: #2980b9;
        }

        .badge-danger {
            background: #fde8e6;
            color: #e74c3c;
        }

        /* Progress Bars */
        .progress-bar {
            width: 100%;
            height: 6px;
            background: var(--border);
            border-radius: 3px;
            overflow: hidden;
            margin: 6px 0;
        }

        .progress-fill {
            height: 100%;
            background: var(--success);
            border-radius: 3px;
            transition: width 0.3s ease;
        }

        /* Sidebar */
        .sidebar {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .widget {
            background: white;
            border-radius: 6px;
            padding: 20px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
        }

        .widget-header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 15px;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.9rem;
        }

        .activity-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .activity-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            border-radius: 4px;
            transition: background 0.2s ease;
        }

        .activity-item:hover {
            background: var(--light);
        }

        .activity-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 0.8rem;
        }

        .activity-icon.success {
            background: var(--success);
        }

        .activity-icon.info {
            background: var(--info);
        }

        .activity-icon.warning {
            background: var(--warning);
        }

        .activity-content {
            flex: 1;
        }

        .activity-title {
            font-weight: 500;
            margin-bottom: 2px;
            font-size: 0.8rem;
        }

        .activity-time {
            font-size: 0.75rem;
            color: var(--gray);
        }

        /* Clickable Rows */
        .clickable-row {
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .clickable-row:hover {
            background: #f8f9fa !important;
        }

        /* Role Colors */
        .role-admin { border-left: 3px solid var(--danger); }
        .role-teacher { border-left: 3px solid var(--info); }
        .role-student { border-left: 3px solid var(--success); }

        .model-active { border-left: 3px solid var(--success); }
        .model-inactive { border-left: 3px solid var(--gray); }

        /* Server Styles - УЛУЧШЕННЫЙ ДИЗАЙН */
        .server-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .server-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .server-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }

        .server-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--success);
        }

        .server-card.warning::before {
            background: var(--warning);
        }

        .server-card.danger::before {
            background: var(--danger);
        }
        
        .server-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .server-title {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .server-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
        }

        .server-icon.web { background: linear-gradient(135deg, #3498db, #2980b9); }
        .server-icon.database { background: linear-gradient(135deg, #27ae60, #219a52); }
        .server-icon.ml { background: linear-gradient(135deg, #9b59b6, #8e44ad); }
        .server-icon.storage { background: linear-gradient(135deg, #e67e22, #d35400); }
        
        .server-name {
            font-weight: 600;
            font-size: 1rem;
            color: var(--dark);
        }
        
        .server-type {
            font-size: 0.75rem;
            color: var(--gray);
            margin-top: 2px;
        }
        
        .server-status {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .status-online {
            background: #d5f4e6;
            color: #27ae60;
        }
        
        .status-warning {
            background: #fef5e7;
            color: #f39c12;
        }
        
        .status-offline {
            background: #fde8e6;
            color: #e74c3c;
        }
        
        .server-metrics {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .metric {
            background: var(--light);
            border-radius: 8px;
            padding: 12px;
        }
        
        .metric-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
        
        .metric-label {
            font-size: 0.8rem;
            color: var(--gray);
            font-weight: 500;
        }
        
        .metric-value {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--dark);
        }
        
        .metric-progress {
            height: 6px;
            background: var(--border);
            border-radius: 3px;
            overflow: hidden;
        }
        
        .metric-progress-fill {
            height: 100%;
            border-radius: 3px;
            transition: width 0.3s ease;
        }
        
        .cpu-progress { background: linear-gradient(90deg, #e74c3c, #c0392b); }
        .memory-progress { background: linear-gradient(90deg, #27ae60, #219a52); }
        .disk-progress { background: linear-gradient(90deg, #3498db, #2980b9); }
        
        .server-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.75rem;
            color: var(--gray);
            border-top: 1px solid var(--border);
            padding-top: 15px;
        }

        .server-ip {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .server-uptime {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        /* Charts */
        .charts-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 20px;
        }
        
        @media (max-width: 768px) {
            .charts-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .chart-container {
            background: white;
            border-radius: 6px;
            padding: 20px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
        }
        
        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .chart-title {
            font-weight: 600;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
        }

        /* Export Section */
        .export-section {
            background: white;
            border-radius: 6px;
            padding: 20px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            margin-bottom: 20px;
        }
        
        .export-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
            margin-top: 15px;
        }
        
        .export-btn {
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 4px;
            background: white;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
            font-size: 0.8rem;
        }
        
        .export-btn:hover {
            border-color: var(--primary);
            background: var(--light);
        }
        
        .export-btn i {
            font-size: 1rem;
        }

        /* Модальные окна */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 0;
            border-radius: 8px;
            width: 90%;
            max-width: 800px;
            max-height: 85vh;
            overflow-y: auto;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--border);
        }
        
        .modal-header {
            padding: 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--light);
        }
        
        .modal-header h2 {
            margin: 0;
            font-size: 1.2rem;
            color: var(--dark);
            font-weight: 600;
        }
        
        .close {
            color: var(--gray);
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
            background: none;
            border: none;
            padding: 0;
        }
        
        .close:hover {
            color: var(--dark);
        }
        
        .modal-body {
            padding: 20px;
        }

        /* Modal specific styles */
        .user-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border);
        }
        
        .user-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--border);
        }
        
        .stats-grid-modal {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 12px;
            margin: 15px 0;
        }
        
        .stat-card-modal {
            background: var(--light);
            border-radius: 4px;
            padding: 12px;
            text-align: center;
            border: 1px solid var(--border);
        }
        
        .tab-container {
            margin-top: 20px;
        }
        
        .tab-buttons {
            display: flex;
            border-bottom: 1px solid var(--border);
            margin-bottom: 15px;
        }
        
        .tab-button {
            padding: 8px 16px;
            border: none;
            background: none;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            font-size: 0.85rem;
            color: var(--gray);
        }
        
        .tab-button.active {
            border-bottom: 2px solid var(--primary);
            color: var(--primary);
            font-weight: 500;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .history-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            font-size: 0.8rem;
        }
        
        .history-table th,
        .history-table td {
            padding: 8px 12px;
            border-bottom: 1px solid var(--border);
            text-align: left;
        }

        .history-table th {
            background: var(--light);
            font-weight: 600;
        }

        /* Navigation to Dashboard */
        .dashboard-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 16px;
            background: var(--primary);
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 500;
            transition: all 0.2s ease;
            margin-bottom: 15px;
        }

        .dashboard-link:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }
            
            .header {
                padding: 15px;
            }
            
            .header-content {
                flex-direction: column;
                text-align: center;
            }
            
            .header-text h1 {
                font-size: 1.5rem;
            }
            
            .nav-tabs {
                flex-direction: column;
            }
            
            .content-section {
                padding: 15px;
            }
            
            th, td {
                padding: 8px 12px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .server-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Loading Animation */
        .loading {
            text-align: center;
            padding: 40px;
            color: var(--gray);
        }

        .loading::after {
            content: '';
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid var(--border);
            border-top: 2px solid var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-left: 8px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Error Message */
        .error-message {
            text-align: center;
            padding: 40px;
            color: var(--danger);
        }

        .error-message i {
            font-size: 2rem;
            margin-bottom: 10px;
            display: block;
        }

        /* Floating Action Button */
        .fab {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 50px;
            height: 50px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 50%;
            cursor: pointer;
            box-shadow: var(--shadow-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            transition: all 0.2s ease;
            z-index: 100;
        }

        .fab:hover {
            background: var(--primary-dark);
            transform: scale(1.05);
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Навигация на главную -->
        <a href="dashboard.php" class="dashboard-link">
            <i class="fas fa-arrow-left"></i>
            Вернуться на главную
        </a>

        <!-- Header -->
        <header class="header">
            <div class="header-content">
                <div class="header-text">
                    <h1><i class="fas fa-chart-line"></i> Панель мониторинга</h1>
                    <p>Система оценки знаний - Аналитика и статистика</p>
                </div>
                <div class="header-stats">
                    <div class="header-stat">
                        <span class="number"><?php echo $system_stats['total_users']; ?></span>
                        <span class="label">Пользователей</span>
                    </div>
                    <div class="header-stat">
                        <span class="number"><?php echo $system_stats['total_tests']; ?></span>
                        <span class="label">Активных тестов</span>
                    </div>
                    <div class="header-stat">
                        <span class="number"><?php echo $active_models_count; ?></span>
                        <span class="label">ML моделей</span>
                    </div>
                </div>
            </div>
        </header>

        <!-- Navigation Tabs -->
        <nav class="nav-tabs">
            <button class="nav-tab active" data-tab="overview">
                <i class="fas fa-home"></i> Обзор
            </button>
            <button class="nav-tab" data-tab="users">
                <i class="fas fa-users"></i> Пользователи
            </button>
            <button class="nav-tab" data-tab="tests">
                <i class="fas fa-file-alt"></i> Тесты
            </button>
            <button class="nav-tab" data-tab="ml">
                <i class="fas fa-robot"></i> ML модели
            </button>
            <button class="nav-tab" data-tab="servers">
                <i class="fas fa-server"></i> Сервера
            </button>
        </nav>

        <!-- Overview Tab -->
        <div class="tab-content active" id="overview">
            <!-- Quick Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <i class="fas fa-user-graduate stat-icon"></i>
                    <span class="stat-number"><?php echo $student_count; ?></span>
                    <span class="stat-label">Студентов</span>
                </div>
                <div class="stat-card">
                    <i class="fas fa-chalkboard-teacher stat-icon"></i>
                    <span class="stat-number"><?php echo $teacher_count; ?></span>
                    <span class="stat-label">Преподавателей</span>
                </div>
                <div class="stat-card">
                    <i class="fas fa-check-circle stat-icon"></i>
                    <span class="stat-number"><?php echo $total_attempts_count; ?></span>
                    <span class="stat-label">Попыток тестов</span>
                </div>
                <div class="stat-card">
                    <i class="fas fa-percentage stat-icon"></i>
                    <span class="stat-number"><?php echo number_format($system_stats['system_avg_score'], 1); ?>%</span>
                    <span class="stat-label">Средний результат</span>
                </div>
            </div>

            <!-- Charts -->
            <div class="charts-grid">
                <div class="chart-container">
                    <div class="chart-header">
                        <h3 class="chart-title"><i class="fas fa-chart-bar"></i> Активность по дням</h3>
                    </div>
                    <canvas id="activityChart" height="250"></canvas>
                </div>
                <div class="chart-container">
                    <div class="chart-header">
                        <h3 class="chart-title"><i class="fas fa-chart-pie"></i> Распределение по ролям</h3>
                    </div>
                    <canvas id="rolesChart" height="250"></canvas>
                </div>
            </div>

            <!-- Export Section -->
            <div class="export-section">
                <div class="section-header">
                    <h2 class="section-title">
                        <i class="fas fa-download"></i> Экспорт данных
                    </h2>
                </div>
                <p style="color: var(--gray); font-size: 0.9rem; margin-bottom: 10px;">Выберите формат для экспорта статистических данных:</p>
                <div class="export-options">
                    <button class="export-btn" onclick="exportData('pdf')">
                        <i class="fas fa-file-pdf" style="color: #e74c3c;"></i>
                        <span>PDF отчет</span>
                    </button>
                    <button class="export-btn" onclick="exportData('excel')">
                        <i class="fas fa-file-excel" style="color: #27ae60;"></i>
                        <span>Excel таблица</span>
                    </button>
                    <button class="export-btn" onclick="exportData('csv')">
                        <i class="fas fa-file-csv" style="color: #3498db;"></i>
                        <span>CSV данные</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Users Tab -->
        <div class="tab-content" id="users">
            <div class="content-section">
                <div class="section-header">
                    <h2 class="section-title">
                        <i class="fas fa-users"></i> Пользователи системы
                    </h2>
                    <div class="section-actions">
                        <button class="btn btn-outline">
                            <i class="fas fa-filter"></i> Фильтр
                        </button>
                        <button class="btn btn-primary" onclick="exportData('users')">
                            <i class="fas fa-download"></i> Экспорт
                        </button>
                    </div>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Пользователь</th>
                                <th>Роль</th>
                                <th>Тестов</th>
                                <th>Успешно</th>
                                <th>Прогресс</th>
                                <th>Активность</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users_stats as $user): ?>
                            <tr class="role-<?php echo $user['role']; ?> clickable-row" data-type="user" data-id="<?php echo $user['id']; ?>">
                                <td>
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <?php 
                                        $avatarPath = $user['avatar'] && file_exists($user['avatar']) ? $user['avatar'] : 'default-avatar.png';
                                        ?>
                                        <img src="<?php echo $avatarPath; ?>" alt="Avatar" style="width: 32px; height: 32px; border-radius: 50%; object-fit: cover; border: 1px solid var(--border);" onerror="this.src='default-avatar.png'">
                                        <div>
                                            <div style="font-weight: 500;"><?php echo htmlspecialchars($user['full_name']); ?></div>
                                            <div style="font-size: 0.75rem; color: var(--gray);"><?php echo htmlspecialchars($user['email']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge badge-info">
                                        <i class="fas <?php echo $user['role'] === 'admin' ? 'fa-crown' : ($user['role'] === 'teacher' ? 'fa-chalkboard-teacher' : 'fa-user-graduate'); ?>"></i>
                                        <?php 
                                        $role_names = array('admin' => 'Админ', 'teacher' => 'Преподаватель', 'student' => 'Студент');
                                        echo $role_names[$user['role']]; 
                                        ?>
                                    </span>
                                </td>
                                <td style="text-align: center; font-weight: 600;"><?php echo $user['tests_completed']; ?></td>
                                <td style="text-align: center;">
                                    <span class="badge <?php echo $user['passed_tests'] > 0 ? 'badge-success' : 'badge-warning'; ?>">
                                        <?php echo $user['passed_tests']; ?>
                                    </span>
                                </td>
                                <td style="min-width: 120px;">
                                    <div class="progress-bar">
                                        <div class="progress-fill" style="width: <?php echo min($user['avg_score'], 100); ?>%"></div>
                                    </div>
                                    <div style="font-size: 0.75rem; color: var(--gray); text-align: center;"><?php echo number_format($user['avg_score'], 1); ?>%</div>
                                </td>
                                <td>
                                    <div style="font-size: 0.75rem; color: var(--gray);">
                                        <?php echo $user['last_activity'] ? date('d.m.Y H:i', strtotime($user['last_activity'])) : 'Никогда'; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Tests Tab -->
        <div class="tab-content" id="tests">
            <div class="content-section">
                <div class="section-header">
                    <h2 class="section-title">
                        <i class="fas fa-file-alt"></i> Тесты и оценки
                    </h2>
                    <div class="section-actions">
                        <button class="btn btn-outline">
                            <i class="fas fa-sort"></i> Сортировка
                        </button>
                        <button class="btn btn-primary" onclick="exportData('tests')">
                            <i class="fas fa-download"></i> Экспорт
                        </button>
                    </div>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Название теста</th>
                                <th>Автор</th>
                                <th>Попыток</th>
                                <th>Участников</th>
                                <th>Успешных</th>
                                <th>Результат</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tests_stats as $test): ?>
                            <tr class="clickable-row" data-type="test" data-id="<?php echo $test['id']; ?>">
                                <td>
                                    <div style="font-weight: 500;"><?php echo htmlspecialchars($test['title']); ?></div>
                                    <?php if ($test['description']): ?>
                                    <div style="font-size: 0.75rem; color: var(--gray); margin-top: 2px;"><?php echo htmlspecialchars($test['description']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 6px; font-size: 0.85rem;">
                                        <i class="fas fa-user" style="color: var(--info);"></i>
                                        <?php echo htmlspecialchars($test['author']); ?>
                                    </div>
                                </td>
                                <td style="text-align: center; font-weight: 600;"><?php echo $test['attempts']; ?></td>
                                <td style="text-align: center;"><?php echo $test['unique_users']; ?></td>
                                <td style="text-align: center;">
                                    <span class="badge <?php echo $test['passed_count'] > 0 ? 'badge-success' : 'badge-warning'; ?>">
                                        <?php echo $test['passed_count']; ?>
                                    </span>
                                </td>
                                <td style="min-width: 120px;">
                                    <div class="progress-bar">
                                        <div class="progress-fill" style="width: <?php echo min($test['avg_score'], 100); ?>%"></div>
                                    </div>
                                    <div style="font-size: 0.75rem; color: var(--gray); text-align: center;"><?php echo number_format($test['avg_score'], 1); ?>%</div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ML Models Tab -->
        <div class="tab-content" id="ml">
            <div class="content-section">
                <div class="section-header">
                    <h2 class="section-title">
                        <i class="fas fa-robot"></i> Машинное обучение
                    </h2>
                    <div class="section-actions">
                        <button class="btn btn-outline">
                            <i class="fas fa-sync-alt"></i> Обновить
                        </button>
                        <button class="btn btn-primary" onclick="exportData('ml')">
                            <i class="fas fa-download"></i> Экспорт
                        </button>
                    </div>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Модель</th>
                                <th>Точность</th>
                                <th>Статус</th>
                                <th>Тестов</th>
                                <th>Прогнозов</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ml_stats as $model): ?>
                            <tr class="model-<?php echo $model['is_active'] ? 'active' : 'inactive'; ?> clickable-row" data-type="model" data-id="<?php echo $model['id']; ?>">
                                <td>
                                    <div style="font-weight: 500;"><?php echo htmlspecialchars($model['name']); ?></div>
                                    <?php if ($model['description']): ?>
                                    <div style="font-size: 0.75rem; color: var(--gray); margin-top: 2px;"><?php echo htmlspecialchars($model['description']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td style="min-width: 120px;">
                                    <div class="progress-bar">
                                        <div class="progress-fill" style="width: <?php echo $model['accuracy'] * 100; ?>%"></div>
                                    </div>
                                    <div style="font-size: 0.75rem; color: var(--gray); text-align: center;"><?php echo number_format($model['accuracy'] * 100, 1); ?>%</div>
                                </td>
                                <td>
                                    <span class="badge <?php echo $model['is_active'] ? 'badge-success' : 'badge-warning'; ?>">
                                        <i class="fas <?php echo $model['is_active'] ? 'fa-play-circle' : 'fa-pause-circle'; ?>"></i>
                                        <?php echo $model['is_active'] ? 'Активна' : 'Неактивна'; ?>
                                    </span>
                                </td>
                                <td style="text-align: center; font-weight: 600;"><?php echo $model['assigned_tests']; ?></td>
                                <td style="text-align: center;">
                                    <span class="badge badge-info"><?php echo $model['predictions_count']; ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Servers Tab - УЛУЧШЕННЫЙ ДИЗАЙН -->
        <div class="tab-content" id="servers">
            <div class="server-grid">
                <?php foreach ($servers as $server): ?>
                <div class="server-card <?php echo $server['status'] === 'warning' ? 'warning' : ($server['status'] === 'offline' ? 'danger' : ''); ?>">
                    <div class="server-header">
                        <div class="server-title">
                            <div class="server-icon <?php echo $server['type']; ?>">
                                <i class="fas <?php 
                                    echo $server['type'] === 'web' ? 'fa-globe' : 
                                         ($server['type'] === 'database' ? 'fa-database' : 
                                         ($server['type'] === 'ml' ? 'fa-robot' : 'fa-hdd')); 
                                ?>"></i>
                            </div>
                            <div>
                                <div class="server-name"><?php echo $server['name']; ?></div>
                                <div class="server-type"><?php 
                                    echo $server['type'] === 'web' ? 'Веб-сервер' : 
                                         ($server['type'] === 'database' ? 'База данных' : 
                                         ($server['type'] === 'ml' ? 'Машинное обучение' : 'Файловое хранилище')); 
                                ?></div>
                            </div>
                        </div>
                        <div class="server-status status-<?php echo $server['status']; ?>">
                            <i class="fas fa-circle" style="font-size: 0.6rem;"></i> 
                            <?php echo $server['status'] === 'online' ? 'Online' : ($server['status'] === 'warning' ? 'Warning' : 'Offline'); ?>
                        </div>
                    </div>
                    
                    <div class="server-metrics">
                        <div class="metric">
                            <div class="metric-header">
                                <span class="metric-label">CPU</span>
                                <span class="metric-value"><?php echo $server['cpu']; ?>%</span>
                            </div>
                            <div class="metric-progress">
                                <div class="metric-progress-fill cpu-progress" style="width: <?php echo $server['cpu']; ?>%"></div>
                            </div>
                        </div>
                        <div class="metric">
                            <div class="metric-header">
                                <span class="metric-label">Память</span>
                                <span class="metric-value"><?php echo $server['memory']; ?>%</span>
                            </div>
                            <div class="metric-progress">
                                <div class="metric-progress-fill memory-progress" style="width: <?php echo $server['memory']; ?>%"></div>
                            </div>
                        </div>
                        <div class="metric">
                            <div class="metric-header">
                                <span class="metric-label">Диск</span>
                                <span class="metric-value"><?php echo $server['disk']; ?>%</span>
                            </div>
                            <div class="metric-progress">
                                <div class="metric-progress-fill disk-progress" style="width: <?php echo $server['disk']; ?>%"></div>
                            </div>
                        </div>
                        <div class="metric">
                            <div class="metric-header">
                                <span class="metric-label">Аптайм</span>
                                <span class="metric-value"><?php echo $server['uptime']; ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="server-info">
                        <div class="server-ip">
                            <i class="fas fa-network-wired"></i>
                            <span><?php echo $server['ip']; ?></span>
                        </div>
                        <div class="server-uptime">
                            <i class="fas fa-clock"></i>
                            <span>Обновлено: <?php echo date('H:i:s'); ?></span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Server Charts -->
            <div class="charts-grid">
                <div class="chart-container">
                    <div class="chart-header">
                        <h3 class="chart-title"><i class="fas fa-microchip"></i> Нагрузка CPU</h3>
                    </div>
                    <canvas id="cpuChart" height="250"></canvas>
                </div>
                <div class="chart-container">
                    <div class="chart-header">
                        <h3 class="chart-title"><i class="fas fa-memory"></i> Использование памяти</h3>
                    </div>
                    <canvas id="memoryChart" height="250"></canvas>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="sidebar">
            <!-- System Status Widget -->
            <div class="widget">
                <div class="widget-header">
                    <i class="fas fa-server" style="color: var(--info);"></i>
                    <span>Статус системы</span>
                </div>
                <div style="display: flex; flex-direction: column; gap: 12px;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="font-size: 0.85rem;">База данных</span>
                        <span class="badge badge-success"><i class="fas fa-check"></i> Online</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="font-size: 0.85rem;">ML сервисы</span>
                        <span class="badge badge-success"><i class="fas fa-check"></i> Active</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="font-size: 0.85rem;">API Gateway</span>
                        <span class="badge badge-success"><i class="fas fa-check"></i> Running</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="font-size: 0.85rem;">Кэш сервер</span>
                        <span class="badge badge-success"><i class="fas fa-check"></i> Online</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="font-size: 0.85rem;">Файловое хранилище</span>
                        <span class="badge badge-warning"><i class="fas fa-exclamation-triangle"></i> Warning</span>
                    </div>
                </div>
            </div>

            <!-- Recent Activity Widget -->
            <div class="widget">
                <div class="widget-header">
                    <i class="fas fa-bell" style="color: var(--warning);"></i>
                    <span>Последняя активность</span>
                </div>
                <div class="activity-list">
                    <div class="activity-item">
                        <div class="activity-icon success">
                            <i class="fas fa-check"></i>
                        </div>
                        <div class="activity-content">
                            <div class="activity-title">Новый тест завершен</div>
                            <div class="activity-time">2 минуты назад</div>
                        </div>
                    </div>
                    <div class="activity-item">
                        <div class="activity-icon info">
                            <i class="fas fa-user-plus"></i>
                        </div>
                        <div class="activity-content">
                            <div class="activity-title">Новый пользователь</div>
                            <div class="activity-time">5 минут назад</div>
                        </div>
                    </div>
                    <div class="activity-item">
                        <div class="activity-icon warning">
                            <i class="fas fa-robot"></i>
                        </div>
                        <div class="activity-content">
                            <div class="activity-title">ML модель обновлена</div>
                            <div class="activity-time">10 минут назад</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions Widget -->
            <div class="widget">
                <div class="widget-header">
                    <i class="fas fa-bolt" style="color: var(--warning);"></i>
                    <span>Быстрые действия</span>
                </div>
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    <button class="btn btn-primary" style="justify-content: center; font-size: 0.8rem;">
                        <i class="fas fa-plus"></i> Новый тест
                    </button>
                    <button class="btn btn-outline" style="justify-content: center; font-size: 0.8rem;">
                        <i class="fas fa-chart-bar"></i> Отчет
                    </button>
                    <button class="btn btn-outline" style="justify-content: center; font-size: 0.8rem;">
                        <i class="fas fa-cog"></i> Настройки
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Floating Action Button -->
    <button class="fab" onclick="location.reload()">
        <i class="fas fa-sync-alt"></i>
    </button>

    <!-- Модальные окна -->
    <div id="userModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Детальная информация о пользователе</h2>
                <button class="close">&times;</button>
            </div>
            <div class="modal-body" id="userModalBody">
                <div class="loading">Загрузка...</div>
            </div>
        </div>
    </div>

    <div id="testModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Детальная информация о тесте</h2>
                <button class="close">&times;</button>
            </div>
            <div class="modal-body" id="testModalBody">
                <div class="loading">Загрузка...</div>
            </div>
        </div>
    </div>

    <div id="modelModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>История ML модели</h2>
                <button class="close">&times;</button>
            </div>
            <div class="modal-body" id="modelModalBody">
                <div class="loading">Загрузка...</div>
            </div>
        </div>
    </div>

    <script>
        // Инициализация графиков
        function initializeCharts() {
            // Activity Chart
            const activityCtx = document.getElementById('activityChart').getContext('2d');
            new Chart(activityCtx, {
                type: 'line',
                data: {
                    labels: ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'],
                    datasets: [{
                        label: 'Активность пользователей',
                        data: [65, 59, 80, 81, 56, 55, 40],
                        borderColor: '#3498db',
                        backgroundColor: 'rgba(52, 152, 219, 0.1)',
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });

            // Roles Chart
            const rolesCtx = document.getElementById('rolesChart').getContext('2d');
            new Chart(rolesCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Студенты', 'Преподаватели', 'Администраторы'],
                    datasets: [{
                        data: [<?php echo $student_count; ?>, <?php echo $teacher_count; ?>, 1],
                        backgroundColor: [
                            '#27ae60',
                            '#3498db',
                            '#e74c3c'
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });

            // CPU Chart
            const cpuCtx = document.getElementById('cpuChart').getContext('2d');
            new Chart(cpuCtx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode(array_column($servers, 'name')); ?>,
                    datasets: [{
                        label: 'Загрузка CPU (%)',
                        data: <?php echo json_encode(array_column($servers, 'cpu')); ?>,
                        backgroundColor: '#e74c3c'
                    }]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100
                        }
                    }
                }
            });

            // Memory Chart
            const memoryCtx = document.getElementById('memoryChart').getContext('2d');
            new Chart(memoryCtx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode(array_column($servers, 'name')); ?>,
                    datasets: [{
                        label: 'Использование памяти (%)',
                        data: <?php echo json_encode(array_column($servers, 'memory')); ?>,
                        backgroundColor: '#27ae60'
                    }]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100
                        }
                    }
                }
            });
        }

        // Функция экспорта
        function exportData(type) {
            let message = '';
            switch(type) {
                case 'pdf':
                    message = 'Генерация PDF отчета...';
                    break;
                case 'excel':
                    message = 'Экспорт в Excel...';
                    break;
                case 'csv':
                    message = 'Экспорт в CSV...';
                    break;
                case 'users':
                    message = 'Экспорт данных пользователей...';
                    break;
                case 'tests':
                    message = 'Экспорт данных тестов...';
                    break;
                case 'ml':
                    message = 'Экспорт данных ML моделей...';
                    break;
            }
            alert(message + ' (Функция в разработке)');
        }

        // Обработчики для модальных окон
        document.addEventListener('DOMContentLoaded', function() {
            // Инициализация графиков
            initializeCharts();

            // Обработчики вкладок
            document.querySelectorAll('.nav-tab').forEach(tab => {
                tab.addEventListener('click', function() {
                    const tabId = this.dataset.tab;
                    
                    // Убираем активный класс со всех вкладок и контента
                    document.querySelectorAll('.nav-tab').forEach(t => t.classList.remove('active'));
                    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                    
                    // Активируем текущую вкладку
                    this.classList.add('active');
                    document.getElementById(tabId).classList.add('active');
                });
            });

            const modals = {
                user: document.getElementById('userModal'),
                test: document.getElementById('testModal'),
                model: document.getElementById('modelModal')
            };
            
            const modalBodies = {
                user: document.getElementById('userModalBody'),
                test: document.getElementById('testModalBody'),
                model: document.getElementById('modelModalBody')
            };
            
            // Обработчики клика по строкам таблицы
            document.querySelectorAll('.clickable-row').forEach(row => {
                row.addEventListener('click', function() {
                    const type = this.dataset.type;
                    const id = this.dataset.id;
                    openModal(type, id);
                });
            });
            
            // Закрытие модальных окон
            document.querySelectorAll('.close').forEach(closeBtn => {
                closeBtn.addEventListener('click', function() {
                    Object.values(modals).forEach(modal => {
                        modal.style.display = 'none';
                    });
                });
            });
            
            // Закрытие при клике вне модального окна
            window.addEventListener('click', function(event) {
                Object.values(modals).forEach(modal => {
                    if (event.target === modal) {
                        modal.style.display = 'none';
                    }
                });
            });
            
            function openModal(type, id) {
                // Показываем загрузку
                modalBodies[type].innerHTML = '<div class="loading">Загрузка...</div>';
                modals[type].style.display = 'block';
                
                // Загружаем данные
                fetch(`progress.php?action=get_${type}_details&${type}_id=${id}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            modalBodies[type].innerHTML = renderModalContent(type, data);
                        } else {
                            modalBodies[type].innerHTML = `
                                <div class="error-message">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    <div>Ошибка загрузки данных</div>
                                    <div style="font-size: 0.8rem; margin-top: 10px; color: var(--gray);">${data.error || 'Неизвестная ошибка'}</div>
                                </div>
                            `;
                        }
                    })
                    .catch(error => {
                        modalBodies[type].innerHTML = `
                            <div class="error-message">
                                <i class="fas fa-exclamation-triangle"></i>
                                <div>Ошибка загрузки данных</div>
                                <div style="font-size: 0.8rem; margin-top: 10px; color: var(--gray);">${error.message}</div>
                            </div>
                        `;
                        console.error('Error:', error);
                    });
            }
            
            function renderModalContent(type, data) {
                switch (type) {
                    case 'user':
                        return renderUserModal(data);
                    case 'test':
                        return renderTestModal(data);
                    case 'model':
                        return renderModelModal(data);
                    default:
                        return '<div>Неизвестный тип данных</div>';
                }
            }
            
            function renderUserModal(data) {
                const user = data.user;
                const history = data.test_history;
                
                // Проверяем наличие данных
                if (!user) {
                    return '<div class="error-message">Данные пользователя не найдены</div>';
                }
                
                // Исправление ошибки: проверяем, что avg_score является числом
                const avgScore = user.avg_score ? parseFloat(user.avg_score) : 0;
                
                return `
                    <div class="user-header">
                        <img src="${user.avatar || 'default-avatar.png'}" alt="Avatar" class="user-avatar" onerror="this.src='default-avatar.png'">
                        <div>
                            <h3 style="margin: 0;">${user.full_name || 'Неизвестно'}</h3>
                            <p style="margin: 5px 0; color: #666;">${user.email || 'Нет email'}</p>
                            <span class="badge badge-info">
                                ${user.role === 'admin' ? 'Админ' : user.role === 'teacher' ? 'Преподаватель' : 'Студент'}
                            </span>
                            ${user.group_name ? `<p style="margin: 5px 0; font-size: 0.9rem;">Группа: ${user.group_name}</p>` : ''}
                        </div>
                    </div>
                    
                    <div class="stats-grid-modal">
                        <div class="stat-card-modal">
                            <div style="font-size: 20px; font-weight: bold; color: #2c3e50;">${user.tests_taken || 0}</div>
                            <div style="font-size: 0.8rem;">Тестов пройдено</div>
                        </div>
                        <div class="stat-card-modal">
                            <div style="font-size: 20px; font-weight: bold; color: #2c3e50;">${user.passed_tests || 0}</div>
                            <div style="font-size: 0.8rem;">Успешно сдано</div>
                        </div>
                        <div class="stat-card-modal">
                            <div style="font-size: 20px; font-weight: bold; color: #2c3e50;">${avgScore.toFixed(1)}%</div>
                            <div style="font-size: 0.8rem;">Средний балл</div>
                        </div>
                        <div class="stat-card-modal">
                            <div style="font-size: 20px; font-weight: bold; color: #2c3e50;">${user.unique_tests || 0}</div>
                            <div style="font-size: 0.8rem;">Уникальных тестов</div>
                        </div>
                    </div>
                    
                    <div class="tab-container">
                        <div class="tab-buttons">
                            <button class="tab-button active" onclick="switchTab('user', 'history')">История тестов</button>
                            <button class="tab-button" onclick="switchTab('user', 'progress')">Прогресс</button>
                        </div>
                        
                        <div id="user-history" class="tab-content active">
                            <h4 style="margin-bottom: 15px;">Последние попытки тестов</h4>
                            ${history && history.length > 0 ? `
                                <table class="history-table">
                                    <thead>
                                        <tr>
                                            <th>Тест</th>
                                            <th>Результат</th>
                                            <th>Баллы</th>
                                            <th>Время</th>
                                            <th>Дата</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${history.map(attempt => `
                                            <tr>
                                                <td>${attempt.test_title || 'Неизвестный тест'}</td>
                                                <td>
                                                    <span class="badge ${attempt.passed ? 'badge-success' : 'badge-warning'}">
                                                        ${attempt.passed ? 'Сдан' : 'Не сдан'}
                                                    </span>
                                                </td>
                                                <td>${attempt.score || 0}/${attempt.total_points || 0} (${attempt.percentage || 0}%)</td>
                                                <td>${attempt.time_taken || 0} мин</td>
                                                <td>${attempt.completed_at ? new Date(attempt.completed_at).toLocaleDateString() : 'Неизвестно'}</td>
                                            </tr>
                                        `).join('')}
                                    </tbody>
                                </table>
                            ` : '<p style="text-align: center; color: var(--gray); padding: 20px;">Нет данных о тестах</p>'}
                        </div>
                        
                        <div id="user-progress" class="tab-content">
                            <h4 style="margin-bottom: 15px;">Прогресс обучения</h4>
                            <p style="text-align: center; color: var(--gray); padding: 20px;">Графики прогресса будут отображены здесь...</p>
                        </div>
                    </div>
                `;
            }
            
            function renderTestModal(data) {
                const test = data.test;
                const attempts = data.attempts;
                
                if (!test) {
                    return '<div class="error-message">Данные теста не найдены</div>';
                }
                
                return `
                    <div style="margin-bottom: 20px;">
                        <h3 style="margin: 0 0 10px 0;">${test.title || 'Неизвестный тест'}</h3>
                        ${test.description ? `<p style="color: #666; margin-bottom: 10px; font-size: 0.9rem;">${test.description}</p>` : ''}
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-size: 0.9rem;">
                            <div><strong>Автор:</strong> ${test.author_name || 'Неизвестно'} (${test.author_email || 'Нет email'})</div>
                            <div><strong>Создан:</strong> ${test.created_at ? new Date(test.created_at).toLocaleDateString() : 'Неизвестно'}</div>
                            <div><strong>Лимит времени:</strong> ${test.time_limit || 0} минут</div>
                            <div><strong>Вопросов:</strong> ${test.question_count || 0}</div>
                            <div><strong>Максимальный балл:</strong> ${test.total_points || 0}</div>
                        </div>
                    </div>
                    
                    <div class="stats-grid-modal">
                        <div class="stat-card-modal">
                            <div style="font-size: 20px; font-weight: bold; color: #2c3e50;">${attempts ? attempts.length : 0}</div>
                            <div style="font-size: 0.8rem;">Всего попыток</div>
                        </div>
                        <div class="stat-card-modal">
                            <div style="font-size: 20px; font-weight: bold; color: #2c3e50;">
                                ${attempts ? attempts.filter(a => a.passed).length : 0}
                            </div>
                            <div style="font-size: 0.8rem;">Успешных попыток</div>
                        </div>
                        <div class="stat-card-modal">
                            <div style="font-size: 20px; font-weight: bold; color: #2c3e50;">
                                ${attempts && attempts.length > 0 ? (attempts.filter(a => a.passed).length / attempts.length * 100).toFixed(1) : 0}%
                            </div>
                            <div style="font-size: 0.8rem;">Успешность</div>
                        </div>
                        <div class="stat-card-modal">
                            <div style="font-size: 20px; font-weight: bold; color: #2c3e50;">
                                ${attempts && attempts.length > 0 ? (attempts.reduce((sum, a) => sum + (a.percentage || 0), 0) / attempts.length).toFixed(1) : 0}%
                            </div>
                            <div style="font-size: 0.8rem;">Средний балл</div>
                        </div>
                    </div>
                    
                    <div class="tab-container">
                        <div class="tab-buttons">
                            <button class="tab-button active" onclick="switchTab('test', 'attempts')">Попытки</button>
                            <button class="tab-button" onclick="switchTab('test', 'analysis')">Анализ</button>
                        </div>
                        
                        <div id="test-attempts" class="tab-content active">
                            <h4 style="margin-bottom: 15px;">История попыток</h4>
                            ${attempts && attempts.length > 0 ? `
                                <table class="history-table">
                                    <thead>
                                        <tr>
                                            <th>Пользователь</th>
                                            <th>Результат</th>
                                            <th>Баллы</th>
                                            <th>Время</th>
                                            <th>Дата</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${attempts.map(attempt => `
                                            <tr>
                                                <td>${attempt.user_name || 'Неизвестно'}</td>
                                                <td>
                                                    <span class="badge ${attempt.passed ? 'badge-success' : 'badge-warning'}">
                                                        ${attempt.passed ? 'Сдан' : 'Не сдан'}
                                                    </span>
                                                </td>
                                                <td>${attempt.score || 0}/${attempt.total_points || 0} (${attempt.percentage || 0}%)</td>
                                                <td>${attempt.duration_seconds ? Math.round(attempt.duration_seconds / 60) : 0} мин</td>
                                                <td>${attempt.completed_at ? new Date(attempt.completed_at).toLocaleDateString() : 'Неизвестно'}</td>
                                            </tr>
                                        `).join('')}
                                    </tbody>
                                </table>
                            ` : '<p style="text-align: center; color: var(--gray); padding: 20px;">Нет данных о попытках</p>'}
                        </div>
                        
                        <div id="test-analysis" class="tab-content">
                            <h4 style="margin-bottom: 15px;">Анализ теста</h4>
                            <p style="text-align: center; color: var(--gray); padding: 20px;">Статистика по вопросам и аналитика будут отображены здесь...</p>
                        </div>
                    </div>
                `;
            }
            
            function renderModelModal(data) {
                const history = data.history;
                
                return `
                    <div style="margin-bottom: 20px;">
                        <h3 style="margin: 0 0 20px 0;">История изменений</h3>
                    </div>
                    
                    ${history && history.length > 0 ? `
                        <table class="history-table">
                            <thead>
                                <tr>
                                    <th>Дата</th>
                                    <th>Тип изменения</th>
                                    <th>Кто изменил</th>
                                    <th>Старое значение</th>
                                    <th>Новое значение</th>
                                    <th>Описание</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${history.map(record => `
                                    <tr>
                                        <td>${record.changed_at ? new Date(record.changed_at).toLocaleDateString() : 'Неизвестно'}</td>
                                        <td>
                                            <span class="badge ${
                                                record.change_type === 'STATUS_CHANGE' ? 'badge-info' : 
                                                record.change_type === 'ASSIGNMENT' ? 'badge-success' : 'badge-warning'
                                            }">
                                                ${getChangeTypeText(record.change_type)}
                                            </span>
                                        </td>
                                        <td>${record.changed_by_name || 'Система'}</td>
                                        <td>${record.old_value || '-'}</td>
                                        <td>${record.new_value || '-'}</td>
                                        <td>${record.description || '-'}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    ` : '<p style="text-align: center; color: var(--gray); padding: 20px;">Нет данных об истории изменений</p>'}
                `;
            }
            
            function getChangeTypeText(type) {
                const types = {
                    'STATUS_CHANGE': 'Изменение статуса',
                    'ASSIGNMENT': 'Назначение',
                    'UPDATE': 'Обновление',
                    'OTHER': 'Другое'
                };
                return types[type] || type;
            }
            
            // Глобальная функция для переключения вкладок
            window.switchTab = function(modalType, tabName) {
                // Скрываем все вкладки
                document.querySelectorAll(`#${modalType}Modal .tab-content`).forEach(tab => {
                    tab.classList.remove('active');
                });
                
                // Убираем активный класс со всех кнопок
                document.querySelectorAll(`#${modalType}Modal .tab-button`).forEach(button => {
                    button.classList.remove('active');
                });
                
                // Показываем выбранную вкладку
                document.getElementById(`${modalType}-${tabName}`).classList.add('active');
                
                // Активируем кнопку
                event.target.classList.add('active');
            };
        });
    </script>
</body>
</html>