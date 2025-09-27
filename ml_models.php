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

// Получаем данные для графиков (статистика по точности моделей за последние 6 месяцев)
$stmt = $pdo->prepare("
    SELECT 
        DATE_FORMAT(created_at, '%Y-%m') as month,
        COUNT(*) as model_count,
        AVG(accuracy) as avg_accuracy
    FROM ml_models 
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY month DESC
    LIMIT 6
");
$stmt->execute();
$monthlyStats = $stmt->fetchAll();

// Подготовка данных для графика
$chartLabels = [];
$chartAccuracy = [];
$chartCount = [];

foreach ($monthlyStats as $stat) {
    $chartLabels[] = date('M Y', strtotime($stat['month'] . '-01'));
    $chartAccuracy[] = round($stat['avg_accuracy'] * 100, 1);
    $chartCount[] = $stat['model_count'];
}

// Прогнозирование будущих показателей (простой линейный прогноз)
$futurePredictions = [];
if (count($chartAccuracy) >= 2) {
    // Простой линейный прогноз на следующие 3 месяца
    $lastAccuracy = $chartAccuracy[count($chartAccuracy)-1];
    $prevAccuracy = $chartAccuracy[count($chartAccuracy)-2];
    $trend = $lastAccuracy - $prevAccuracy;
    
    for ($i = 1; $i <= 3; $i++) {
        $futureMonth = date('M Y', strtotime("+$i months"));
        $predictedAccuracy = max(0, min(100, $lastAccuracy + ($trend * $i)));
        $futurePredictions[] = [
            'month' => $futureMonth,
            'accuracy' => round($predictedAccuracy, 1)
        ];
    }
}

// Анализ производительности моделей
$stmt = $pdo->prepare("
    SELECT 
        CASE 
            WHEN accuracy >= 0.9 THEN 'Отличная'
            WHEN accuracy >= 0.8 THEN 'Хорошая'
            WHEN accuracy >= 0.7 THEN 'Удовлетворительная'
            ELSE 'Низкая'
        END as performance_category,
        COUNT(*) as model_count,
        AVG(accuracy) as avg_accuracy
    FROM ml_models 
    GROUP BY performance_category
    ORDER BY avg_accuracy DESC
");
$stmt->execute();
$performanceStats = $stmt->fetchAll();

// Обработка добавления новой модели
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_model']) && $user['role'] == 'admin') {
    $name = $_POST['name'];
    $description = $_POST['description'];
    $accuracy = $_POST['accuracy'];
    $path = $_POST['path'];
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    $stmt = $pdo->prepare("INSERT INTO ml_models (name, description, accuracy, path, is_active) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$name, $description, $accuracy, $path, $is_active]);
    
    // Логируем действие
    $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, description) VALUES (?, ?, ?)");
    $stmt->execute([$user['id'], 'MODEL_ADDED', "Добавлена модель: $name"]);
    
    header("Location: ml_models.php?success=1");
    exit;
}

// Обработка активации/деактивации модели
if (isset($_GET['toggle']) && $user['role'] == 'admin') {
    $model_id = $_GET['id'];
    $stmt = $pdo->prepare("SELECT is_active FROM ml_models WHERE id = ?");
    $stmt->execute([$model_id]);
    $model = $stmt->fetch();
    
    $new_status = $model['is_active'] ? 0 : 1;
    $stmt = $pdo->prepare("UPDATE ml_models SET is_active = ? WHERE id = ?");
    $stmt->execute([$new_status, $model_id]);
    
    // Логируем действие
    $action = $new_status ? 'MODEL_ACTIVATED' : 'MODEL_DEACTIVATED';
    $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, description) VALUES (?, ?, ?)");
    $stmt->execute([$user['id'], $action, "Модель ID: $model_id"]);
    
    header("Location: ml_models.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ML модели - Система интеллектуальной оценки знаний</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* Основные стили */
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
            overflow-y: auto;
        }
        
        .logo {
            padding: 20px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .logo h1 {
            font-size: 20px;
            font-weight: 600;
        }
        
        .user-info {
            padding: 20px;
            display: flex;
            align-items: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .user-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 20px;
            margin-right: 15px;
        }
        
        .user-details h3 {
            font-size: 16px;
            margin-bottom: 5px;
        }
        
        .user-details p {
            font-size: 12px;
            opacity: 0.7;
            margin-bottom: 5px;
        }
        
        .role-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 15px;
            font-size: 11px;
            font-weight: 500;
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
            padding: 10px 0;
        }
        
        .nav-links li a {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            transition: all 0.3s;
        }
        
        .nav-links li a:hover, .nav-links li a.active {
            background: rgba(255,255,255,0.1);
            color: white;
        }
        
        .nav-links li a i {
            margin-right: 10px;
            width: 20px;
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
            flex-wrap: wrap;
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
            position: relative;
        }
        
        .search-box input {
            padding: 10px 15px 10px 40px;
            border: 1px solid #ddd;
            border-radius: 20px;
            outline: none;
            width: 250px;
        }
        
        .search-box i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
        }
        
        /* Stats Cards */
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
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
            width: 60px;
            height: 60px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-right: 15px;
        }
        
        .icon-primary {
            background: rgba(52, 152, 219, 0.1);
            color: var(--primary);
        }
        
        .icon-success {
            background: rgba(46, 204, 113, 0.1);
            color: var(--success);
        }
        
        .icon-warning {
            background: rgba(243, 156, 18, 0.1);
            color: var(--warning);
        }
        
        .stat-details h3 {
            font-size: 24px;
            margin-bottom: 5px;
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
            background: var(--primary);
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
        }
        
        .view-all:hover {
            background: var(--primary-dark);
        }
        
        /* Model List */
        .model-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .model-item {
            border: 1px solid #eee;
            border-radius: 8px;
            padding: 15px;
            transition: all 0.3s;
        }
        
        .model-item.active {
            border-left: 4px solid var(--success);
        }
        
        .model-item.inactive {
            border-left: 4px solid var(--gray);
        }
        
        .model-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .model-title {
            font-weight: 600;
            font-size: 16px;
        }
        
        .model-status {
            font-size: 12px;
            padding: 3px 8px;
            border-radius: 15px;
        }
        
        .status-active {
            background: rgba(46, 204, 113, 0.1);
            color: var(--success);
        }
        
        .status-inactive {
            background: rgba(127, 140, 141, 0.1);
            color: var(--gray);
        }
        
        .model-description {
            color: var(--gray);
            margin-bottom: 10px;
            font-size: 14px;
        }
        
        .model-details {
            display: flex;
            justify-content: space-between;
            margin-bottom: 15px;
            font-size: 13px;
        }
        
        .model-accuracy {
            color: var(--primary);
            font-weight: 500;
        }
        
        .model-date {
            color: var(--gray);
        }
        
        .model-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn {
            padding: 6px 12px;
            border-radius: 5px;
            font-size: 13px;
            text-decoration: none;
            cursor: pointer;
            border: none;
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
        
        .btn-danger {
            background: var(--accent);
            color: white;
        }
        
        /* Alerts */
        .alert {
            padding: 12px 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
        }
        
        .alert-success {
            background: rgba(46, 204, 113, 0.1);
            color: var(--success);
            border-left: 4px solid var(--success);
        }
        
        .alert i {
            margin-right: 10px;
        }
        
        /* Стили для графиков */
        .charts-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .chart-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .chart-wrapper {
            position: relative;
            height: 300px;
            width: 100%;
        }
        
        .performance-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        
        .performance-item {
            padding: 15px;
            border-radius: 8px;
            text-align: center;
        }
        
        .performance-excellent {
            background: rgba(46, 204, 113, 0.1);
            border-left: 4px solid var(--success);
        }
        
        .performance-good {
            background: rgba(52, 152, 219, 0.1);
            border-left: 4px solid var(--primary);
        }
        
        .performance-satisfactory {
            background: rgba(243, 156, 18, 0.1);
            border-left: 4px solid var(--warning);
        }
        
        .performance-low {
            background: rgba(231, 76, 60, 0.1);
            border-left: 4px solid var(--accent);
        }
        
        .performance-value {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .performance-label {
            font-size: 14px;
            color: var(--gray);
        }
        
        .prediction-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-top: 20px;
        }
        
        .prediction-header {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .prediction-header i {
            font-size: 24px;
            margin-right: 10px;
        }
        
        .prediction-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
        }
        
        .prediction-item {
            text-align: center;
            padding: 10px;
            background: rgba(255,255,255,0.2);
            border-radius: 8px;
        }
        
        /* Form Styles */
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }
        
        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            outline: none;
        }
        
        .form-check {
            display: flex;
            align-items: center;
        }
        
        .form-check-input {
            margin-right: 10px;
        }
        
        /* Modal Styles */
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
            border-radius: 10px;
            width: 500px;
            max-width: 90%;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            animation: modalFade 0.3s;
        }
        
        @keyframes modalFade {
            from {opacity: 0; transform: translateY(-50px);}
            to {opacity: 1; transform: translateY(0);}
        }
        
        .modal-header {
            padding: 15px 20px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h2 {
            font-size: 18px;
            color: var(--secondary);
        }
        
        .close {
            color: #aaa;
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
        }
        
        .close:hover {
            color: var(--secondary);
        }
        
        .modal-content form {
            padding: 20px;
        }
        
        /* Адаптивность */
        @media (max-width: 992px) {
            .sidebar {
                width: 70px;
                overflow: visible;
            }
            
            .logo h1, .user-details, .nav-links li span {
                display: none;
            }
            
            .user-info {
                justify-content: center;
                padding: 15px 10px;
            }
            
            .user-avatar {
                margin-right: 0;
            }
            
            .nav-links li a {
                justify-content: center;
                padding: 15px 10px;
            }
            
            .nav-links li a i {
                margin-right: 0;
                font-size: 18px;
            }
            
            .main-content {
                margin-left: 70px;
            }
            
            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .search-box input {
                width: 100%;
            }
            
            .charts-container {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .model-list {
                grid-template-columns: 1fr;
            }
            
            .stats-cards {
                grid-template-columns: 1fr;
            }
            
            .charts-container {
                grid-template-columns: 1fr;
            }
            
            .chart-card {
                padding: 15px;
            }
            
            .chart-wrapper {
                height: 250px;
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
                if (file_exists($avatarPath)) {
                    echo '<img src="' . $avatarPath . '" alt="Аватар" style="width: 50px; height: 50px; border-radius: 50%; object-fit: cover;">';
                } else {
                    $firstName = $user['full_name'];
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
        
        <!-- Уведомления -->
        <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> Модель успешно добавлена!
        </div>
        <?php endif; ?>
        
        <!-- Графики и аналитика -->
        <div class="charts-container">
            <div class="chart-card">
                <div class="section-header">
                    <h2><i class="fas fa-chart-line"></i> Динамика точности моделей</h2>
                </div>
                <div class="chart-wrapper">
                    <canvas id="accuracyChart"></canvas>
                </div>
            </div>
            
            <div class="chart-card">
                <div class="section-header">
                    <h2><i class="fas fa-chart-bar"></i> Количество моделей по месяцам</h2>
                </div>
                <div class="chart-wrapper">
                    <canvas id="countChart"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Прогноз будущих показателей -->
        <?php if (!empty($futurePredictions)): ?>
        <div class="prediction-card">
            <div class="prediction-header">
                <i class="fas fa-crystal-ball"></i>
                <h3>Прогноз точности на будущие месяцы</h3>
            </div>
            <div class="prediction-list">
                <?php foreach ($futurePredictions as $prediction): ?>
                <div class="prediction-item">
                    <div class="performance-value"><?php echo $prediction['accuracy']; ?>%</div>
                    <div class="performance-label"><?php echo $prediction['month']; ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Анализ производительности -->
        <div class="section">
            <div class="section-header">
                <h2><i class="fas fa-analytics"></i> Анализ производительности моделей</h2>
            </div>
            
            <div class="performance-stats">
                <?php foreach ($performanceStats as $stat): ?>
                <div class="performance-item performance-<?php echo strtolower($stat['performance_category']); ?>">
                    <div class="performance-value"><?php echo $stat['model_count']; ?></div>
                    <div class="performance-label"><?php echo $stat['performance_category']; ?> точность</div>
                    <div class="performance-value"><?php echo round($stat['avg_accuracy'] * 100, 1); ?>%</div>
                </div>
                <?php endforeach; ?>
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
                <button class="view-all" onclick="openModal()">Добавить модель</button>
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
                                        <a href="ml_models.php?toggle=1&id=<?php echo $model['id']; ?>" class="btn btn-danger">Деактивировать</a>
                                    <?php else: ?>
                                        <a href="ml_models.php?toggle=1&id=<?php echo $model['id']; ?>" class="btn btn-success">Активировать</a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p>Нет доступных ML моделей. <?php if ($user['role'] == 'admin'): ?><a href="#" onclick="openModal()">Добавить первую модель</a><?php endif; ?></p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Модальное окно добавления модели -->
    <div id="addModelModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Добавить новую модель</h2>
                <span class="close" onclick="closeModal()">&times;</span>
            </div>
            
            <form method="POST">
                <input type="hidden" name="add_model" value="1">
                
                <div class="form-group">
                    <label for="name">Название модели</label>
                    <input type="text" id="name" name="name" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="description">Описание</label>
                    <textarea id="description" name="description" class="form-control" rows="3"></textarea>
                </div>
                
                <div class="form-group">
                    <label for="accuracy">Точность (0.0 - 1.0)</label>
                    <input type="number" id="accuracy" name="accuracy" class="form-control" min="0" max="1" step="0.01" required>
                </div>
                
                <div class="form-group">
                    <label for="path">Путь к модели</label>
                    <input type="text" id="path" name="path" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <div class="form-check">
                        <input type="checkbox" id="is_active" name="is_active" class="form-check-input" value="1" checked>
                        <label for="is_active" class="form-check-label">Активная модель</label>
                    </div>
                </div>
                
                <div class="form-group">
                    <button type="submit" class="btn btn-primary">Добавить модель</button>
                    <button type="button" class="btn" onclick="closeModal()">Отмена</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Функции для работы с модальным окном
        function openModal() {
            document.getElementById('addModelModal').style.display = 'block';
        }
        
        function closeModal() {
            document.getElementById('addModelModal').style.display = 'none';
        }
        
        // Закрытие модального окна при клике вне его
        window.onclick = function(event) {
            var modal = document.getElementById('addModelModal');
            if (event.target == modal) {
                closeModal();
            }
        }
        
        // Поиск моделей
        document.getElementById('searchInput').addEventListener('input', function() {
            var searchText = this.value.toLowerCase();
            var modelItems = document.querySelectorAll('.model-item');
            
            modelItems.forEach(function(item) {
                var modelName = item.getAttribute('data-name');
                if (modelName.indexOf(searchText) !== -1) {
                    item.style.display = 'block';
                } else {
                    item.style.display = 'none';
                }
            });
        });
        
        // Инициализация графиков
        document.addEventListener('DOMContentLoaded', function() {
            // График точности
            var accuracyCtx = document.getElementById('accuracyChart').getContext('2d');
            var accuracyChart = new Chart(accuracyCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode(array_reverse($chartLabels)); ?>,
                    datasets: [{
                        label: 'Средняя точность (%)',
                        data: <?php echo json_encode(array_reverse($chartAccuracy)); ?>,
                        borderColor: '#3498db',
                        backgroundColor: 'rgba(52, 152, 219, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4
                                }]
                        },
                        options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                        legend: {
                        display: true,
                        position: 'top'
                                },
                        tooltip: {
                        mode: 'index',
                        intersect: false
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
                                        // График количества моделей
        var countCtx = document.getElementById('countChart').getContext('2d');
        var countChart = new Chart(countCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_reverse($chartLabels)); ?>,
                datasets: [{
                    label: 'Количество моделей',
                    data: <?php echo json_encode(array_reverse($chartCount)); ?>,
                    backgroundColor: 'rgba(46, 204, 113, 0.7)',
                    borderColor: 'rgba(46, 204, 113, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'top'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });

        // Анимация появления элементов
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);

        // Применяем анимацию к карточкам моделей
        document.querySelectorAll('.model-item').forEach((item, index) => {
            item.style.opacity = '0';
            item.style.transform = 'translateY(20px)';
            item.style.transition = `opacity 0.5s ease ${index * 0.1}s, transform 0.5s ease ${index * 0.1}s`;
            observer.observe(item);
        });

        // Применяем анимацию к статистическим карточкам
        document.querySelectorAll('.stat-card').forEach((card, index) => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            card.style.transition = `opacity 0.5s ease ${index * 0.1}s, transform 0.5s ease ${index * 0.1}s`;
            observer.observe(card);
        });
    });

    // Функция для подтверждения действий
    function confirmAction(message) {
        return confirm(message);
    }

    // Добавляем подтверждение для активации/деактивации моделей
    document.addEventListener('DOMContentLoaded', function() {
        const toggleLinks = document.querySelectorAll('a[href*="toggle=1"]');
        toggleLinks.forEach(link => {
            link.addEventListener('click', function(e) {
                const isActive = this.classList.contains('btn-success');
                const message = isActive 
                    ? 'Вы уверены, что хотите активировать эту модель?' 
                    : 'Вы уверены, что хотите деактивировать эту модель?';
                
                if (!confirmAction(message)) {
                    e.preventDefault();
                }
            });
        });
    });
</script>
</body> </html>