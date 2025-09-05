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

// Проверяем права доступа
if ($user['role'] != 'teacher' && $user['role'] != 'admin') {
    header("Location: dashboard.php");
    exit;
}

// Получаем список студентов
$stmt = $pdo->prepare("
    SELECT u.*, COUNT(tr.id) as test_attempts, 
           COALESCE(SUM(tr.score), 0) as total_score,
           COALESCE(SUM(tr.total_points), 0) as total_points
    FROM users u
    LEFT JOIN test_results tr ON u.id = tr.user_id
    WHERE u.role = 'student'
    GROUP BY u.id
    ORDER BY u.full_name
");
$stmt->execute();
$students = $stmt->fetchAll();

// Получаем статистику по студентам
$stmt = $pdo->prepare("
    SELECT COUNT(*) as total_students,
           COUNT(CASE WHEN test_attempts > 0 THEN 1 END) as active_students,
           AVG(CASE WHEN total_points > 0 THEN total_score/total_points ELSE 0 END) as avg_performance
    FROM (
        SELECT u.id, 
               COUNT(tr.id) as test_attempts, 
               COALESCE(SUM(tr.score), 0) as total_score,
               COALESCE(SUM(tr.total_points), 0) as total_points
        FROM users u
        LEFT JOIN test_results tr ON u.id = tr.user_id
        WHERE u.role = 'student'
        GROUP BY u.id
    ) student_stats
");
$stmt->execute();
$stats = $stmt->fetch();

// Обработка AJAX запроса для получения данных студента
if (isset($_GET['action']) && $_GET['action'] == 'get_student' && isset($_GET['id'])) {
    $studentId = intval($_GET['id']);
    
    $stmt = $pdo->prepare("
        SELECT u.*, 
               COUNT(tr.id) as test_attempts, 
               COALESCE(SUM(tr.score), 0) as total_score,
               COALESCE(SUM(tr.total_points), 0) as total_points,
               MAX(tr.completed_at) as last_attempt
        FROM users u
        LEFT JOIN test_results tr ON u.id = tr.user_id
        WHERE u.id = ? AND u.role = 'student'
        GROUP BY u.id
    ");
    $stmt->execute([$studentId]);
    $studentData = $stmt->fetch();
    
    if ($studentData) {
        // Получаем последние результаты тестов
        $stmt = $pdo->prepare("
            SELECT tr.*, t.title as test_title 
            FROM test_results tr
            JOIN tests t ON tr.test_id = t.id
            WHERE tr.user_id = ?
            ORDER BY tr.completed_at DESC
            LIMIT 5
        ");
        $stmt->execute([$studentId]);
        $testResults = $stmt->fetchAll();
        
        // Формируем ответ
        $performance = $studentData['total_points'] > 0 ? 
            round(($studentData['total_score'] / $studentData['total_points']) * 100, 1) : 0;
        
        $response = [
            'success' => true,
            'student' => [
                'id' => $studentData['id'],
                'full_name' => $studentData['full_name'],
                'email' => $studentData['email'],
                'created_at' => date('d.m.Y', strtotime($studentData['created_at'])),
                'last_activity' => $studentData['last_activity'] ? 
                    date('d.m.Y H:i', strtotime($studentData['last_activity'])) : 'Нет данных',
                'avatar' => !empty($studentData['avatar']) ? $studentData['avatar'] : 'default-avatar.png',
                'test_attempts' => $studentData['test_attempts'],
                'total_score' => $studentData['total_score'],
                'total_points' => $studentData['total_points'],
                'performance' => $performance,
                'last_attempt' => $studentData['last_attempt'] ? 
                    date('d.m.Y H:i', strtotime($studentData['last_attempt'])) : 'Нет попыток'
            ],
            'test_results' => $testResults
        ];
        
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Студент не найден']);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Студенты - Система интеллектуальной оценки знаний</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Стили из dashboard.php */
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
            display: flex;
            align-items: center;
            gap: 10px;
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
        
        /* Stats */
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
        
        /* Table */
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .data-table th, .data-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        .data-table th {
            background-color: var(--light);
            font-weight: 600;
            color: var(--secondary);
        }
        
        .data-table tr:hover {
            background-color: #f9f9f9;
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
        
        .btn-danger {
            background: var(--accent);
            color: white;
        }
        
        .btn-danger:hover {
            opacity: 0.9;
        }
        
        /* Student card */
        .student-card {
            display: flex;
            align-items: center;
            padding: 15px;
            background: var(--light);
            border-radius: 8px;
            margin-bottom: 10px;
        }
        
        .student-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            font-weight: bold;
            color: white;
            margin-right: 15px;
        }
        
        .student-info {
            flex: 1;
        }
        
        .student-name {
            font-weight: 600;
            margin-bottom: 5px;
            color: var(--secondary);
        }
        
        .student-details {
            font-size: 14px;
            color: var(--gray);
        }
        
        .student-performance {
            text-align: right;
        }
        
        .performance-value {
            font-size: 18px;
            font-weight: 600;
            color: var(--success);
        }
        
        .performance-label {
            font-size: 12px;
            color: var(--gray);
        }
        
        /* Progress bar */
        .progress-container {
            width: 100%;
            background-color: #e0e0e0;
            border-radius: 5px;
            margin: 10px 0;
        }
        
        .progress-bar {
            height: 10px;
            background-color: var(--success);
            border-radius: 5px;
            width: 0%;
            transition: width 0.5s ease-in-out;
        }
        
        /* Модальное окно */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            animation: fadeIn 0.3s;
        }
        
        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 0;
            border-radius: 10px;
            width: 90%;
            max-width: 700px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            animation: slideIn 0.3s;
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 20px;
            border-bottom: 1px solid #eee;
            background: var(--light);
            border-radius: 10px 10px 0 0;
        }
        
        .modal-header h2 {
            margin: 0;
            color: var(--secondary);
            font-size: 20px;
        }
        
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            transition: color 0.3s;
        }
        
        .close:hover {
            color: var(--accent);
        }
        
        .modal-body {
            padding: 20px;
        }
        
        .student-profile {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        
        .profile-header {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .profile-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            font-weight: bold;
            color: white;
            position: relative;
            overflow: hidden;
        }
        
        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .profile-info h3 {
            font-size: 24px;
            margin-bottom: 5px;
            color: var(--secondary);
        }
        
        .profile-info p {
            color: var(--gray);
            margin-bottom: 5px;
        }
        
        .profile-stats {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .stat-item {
            background: var(--light);
            padding: 15px;
            border-radius: 8px;
            text-align: center;
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: bold;
            color: var(--primary);
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 14px;
            color: var(--gray);
        }
        
        .test-results {
            margin-top: 20px;
        }
        
        .test-results h4 {
            margin-bottom: 15px;
            color: var(--secondary);
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
        
        .test-result-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .test-result-item:last-child {
            border-bottom: none;
        }
        
        .test-name {
            font-weight: 500;
        }
        
        .test-score {
            color: var(--success);
            font-weight: 600;
        }
        
        .test-date {
            color: var(--gray);
            font-size: 12px;
        }
        
        .no-results {
            text-align: center;
            color: var(--gray);
            padding: 20px;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes slideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
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
            
            .data-table {
                display: block;
                overflow-x: auto;
            }
            
            .student-card {
                flex-direction: column;
                text-align: center;
            }
            
            .student-avatar {
                margin-right: 0;
                margin-bottom: 10px;
            }
            
            .student-performance {
                text-align: center;
                margin-top: 10px;
            }
            
            .profile-header {
                flex-direction: column;
                text-align: center;
            }
            
            .profile-stats {
                grid-template-columns: 1fr 1fr;
            }
        }
        
        @media (max-width: 480px) {
            .profile-stats {
                grid-template-columns: 1fr;
            }
            
            .modal-content {
                width: 95%;
                margin: 10% auto;
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
            <li><a href="dashboard.php"><i class="fas fa-th-large"></i> <span>Главная</span></a></li>
            <li><a href="tests.php"><i class="fas fa-file-alt"></i> <span>Тесты</span></a></li>
            <li><a href="results.php"><i class="fas fa-chart-line"></i> <span>Результаты</span></a></li>
            <?php if ($user['role'] == 'teacher' || $user['role'] == 'admin'): ?>
            <li><a href="students.php" class="active"><i class="fas fa-users"></i> <span>Студенты</span></a></li>
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
                <h2><i class="fas fa-users"></i> Управление студентами</h2>
                <p>Список всех студентов и их успеваемость</p>
            </div>
            
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" placeholder="Поиск студентов..." id="searchInput">
            </div>
        </div>
        
        <!-- Статистика -->
        <div class="stats-cards">
            <div class="stat-card">
                <div class="stat-icon icon-primary">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-details">
                    <h3><?php echo $stats['total_students']; ?></h3>
                    <p>Всего студентов</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon icon-success">
                    <i class="fas fa-user-check"></i>
                </div>
                <div class="stat-details">
                    <h3><?php echo $stats['active_students']; ?></h3>
                    <p>Активных студентов</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon icon-warning">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="stat-details">
                    <h3><?php echo round($stats['avg_performance'] * 100, 1); ?>%</h3>
                    <p>Средняя успеваемость</p>
                </div>
            </div>
        </div>
        
        <!-- Список студентов -->
        <div class="section">
            <div class="section-header">
                <h2>Список студентов</h2>
                <a href="student_add.php" class="view-all">Добавить студента</a>
            </div>
            
            <?php if (count($students) > 0): ?>
                <div id="studentsList">
                    <?php foreach ($students as $student): 
                        $performance = $student['total_points'] > 0 ? round(($student['total_score'] / $student['total_points']) * 100, 1) : 0;
                    ?>
                        <div class="student-card" data-name="<?php echo strtolower($student['full_name']); ?>">
                            <div class="student-avatar">
                                <?php echo strtoupper(substr($student['full_name'], 0, 1)); ?>
                            </div>
                            <div class="student-info">
                                <div class="student-name"><?php echo $student['full_name']; ?></div>
                                <div class="student-details">
                                    <?php echo $student['email']; ?> • 
                                    <?php echo $student['test_attempts']; ?> попыток • 
                                    Зарегистрирован: <?php echo date('d.m.Y', strtotime($student['created_at'])); ?>
                                </div>
                            </div>
                            <div class="student-performance">
                                <div class="performance-value"><?php echo $performance; ?>%</div>
                                <div class="performance-label">Успеваемость</div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p>Нет зарегистрированных студентов.</p>
            <?php endif; ?>
        </div>
        
        <!-- Детальная таблица -->
        <div class="section">
            <div class="section-header">
                <h2>Детальная статистика</h2>
            </div>
            
            <?php if (count($students) > 0): ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Студент</th>
                            <th>Email</th>
                            <th>Попыток</th>
                            <th>Общий балл</th>
                            <th>Успеваемость</th>
                            <th>Дата регистрации</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $student): 
                            $performance = $student['total_points'] > 0 ? round(($student['total_score'] / $student['total_points']) * 100, 1) : 0;
                        ?>
                            <tr>
                                <td><?php echo $student['full_name']; ?></td>
                                <td><?php echo $student['email']; ?></td>
                                <td><?php echo $student['test_attempts']; ?></td>
                                <td><?php echo $student['total_score']; ?>/<?php echo $student['total_points']; ?></td>
                                <td>
                                    <div class="progress-container">
                                        <div class="progress-bar" style="width: <?php echo $performance; ?>%"></div>
                                    </div>
                                    <?php echo $performance; ?>%
                                </td>
                                <td><?php echo date('d.m.Y', strtotime($student['created_at'])); ?></td>
                                <td>
                                    <button class="btn btn-primary view-profile" data-id="<?php echo $student['id']; ?>">Профиль</button>
                                    <a href="student_results.php?id=<?php echo $student['id']; ?>" class="btn btn-success">Результаты</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>Нет зарегистрированных студентов.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Модальное окно профиля студента -->
    <div id="studentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Профиль студента</h2>
                <span class="close">&times;</span>
            </div>
            <div class="modal-body" id="studentModalBody">
                <!-- Содержимое будет загружено через AJAX -->
            </div>
        </div>
    </div>

    <script>
        // Поиск студентов
        document.getElementById('searchInput').addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const studentCards = document.querySelectorAll('.student-card');
            
            studentCards.forEach(card => {
                const studentName = card.getAttribute('data-name');
                if (studentName.includes(searchTerm)) {
                    card.style.display = 'flex';
                } else {
                    card.style.display = 'none';
                }
            });
        });
        
        // Анимация прогресс-баров
        document.addEventListener('DOMContentLoaded', function() {
            const progressBars = document.querySelectorAll('.progress-bar');
            progressBars.forEach(bar => {
                const width = bar.style.width;
                bar.style.width = '0';
                setTimeout(() => {
                    bar.style.width = width;
                }, 100);
            });
        });
        
        // Модальное окно профиля студента
        const modal = document.getElementById('studentModal');
        const closeBtn = document.querySelector('.close');
        const modalBody = document.getElementById('studentModalBody');
        
        // Обработчики для кнопок "Профиль"
        document.querySelectorAll('.view-profile').forEach(button => {
            button.addEventListener('click', function() {
                const studentId = this.getAttribute('data-id');
                loadStudentProfile(studentId);
            });
        });
        
        // Закрытие модального окна
        closeBtn.addEventListener('click', function() {
            modal.style.display = 'none';
        });
        
        window.addEventListener('click', function(event) {
            if (event.target == modal) {
                modal.style.display = 'none';
            }
        });
        
        // Загрузка данных студента через AJAX
        function loadStudentProfile(studentId) {
            modalBody.innerHTML = '<div style="text-align: center; padding: 20px;"><i class="fas fa-spinner fa-spin"></i> Загрузка...</div>';
            modal.style.display = 'block';
            
            fetch(`students.php?action=get_student&id=${studentId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        renderStudentProfile(data);
                    } else {
                        modalBody.innerHTML = `<div class="no-results">${data.message || 'Ошибка загрузки данных'}</div>`;
                    }
                })
                .catch(error => {
                    console.error('Ошибка:', error);
                    modalBody.innerHTML = '<div class="no-results">Ошибка загрузки данных</div>';
                });
        }
        
        // Отрисовка профиля студента
        function renderStudentProfile(data) {
            const student = data.student;
            const testResults = data.test_results || [];
            
            let testResultsHTML = '';
            if (testResults.length > 0) {
                testResults.forEach(result => {
                    const percentage = result.total_points > 0 ? 
                        Math.round((result.score / result.total_points) * 100) : 0;
                    const passed = result.passed ? 'Пройден' : 'Не пройден';
                    
                    testResultsHTML += `
                        <div class="test-result-item">
                            <div>
                                <div class="test-name">${result.test_title}</div>
                                <div class="test-date">${new Date(result.completed_at).toLocaleDateString('ru-RU')}</div>
                            </div>
                            <div>
                                <div class="test-score">${result.score}/${result.total_points} (${percentage}%)</div>
                                <div>${passed}</div>
                            </div>
                        </div>
                    `;
                });
            } else {
                testResultsHTML = '<div class="no-results">Нет результатов тестирования</div>';
            }
            
            modalBody.innerHTML = `
                <div class="student-profile">
                    <div class="profile-header">
                        <div class="profile-avatar">
                            ${student.avatar && student.avatar !== 'default-avatar.png' ? 
                                `<img src="${student.avatar}" alt="Аватар">` : 
                                student.full_name.charAt(0).toUpperCase()}
                        </div>
                        <div class="profile-info">
                            <h3>${student.full_name}</h3>
                            <p>${student.email}</p>
                            <p>Зарегистрирован: ${student.created_at}</p>
                            <p>Последняя активность: ${student.last_activity}</p>
                        </div>
                    </div>
                    
                    <div class="profile-stats">
                        <div class="stat-item">
                            <div class="stat-value">${student.test_attempts}</div>
                            <div class="stat-label">Попыток тестирования</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value">${student.total_score}/${student.total_points}</div>
                            <div class="stat-label">Общий балл</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value">${student.performance}%</div>
                            <div class="stat-label">Успеваемость</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value">${student.last_attempt}</div>
                            <div class="stat-label">Последняя попытка</div>
                        </div>
                    </div>
                    
                    <div class="test-results">
                        <h4>Последние результаты тестов</h4>
                        ${testResultsHTML}
                    </div>
                </div>
            `;
        }
    </script>
</body>
</html>