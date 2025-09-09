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

// Получаем результаты в зависимости от роли пользователя
if ($user['role'] == 'student') {
    // Результаты для студента
    $stmt = $pdo->prepare("
        SELECT t.title, tr.score, tr.total_points, tr.completed_at, tr.id as result_id
        FROM test_results tr
        JOIN tests t ON tr.test_id = t.id
        WHERE tr.user_id = ?
        ORDER BY tr.completed_at DESC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $results = $stmt->fetchAll();
    
} else if ($user['role'] == 'teacher' || $user['role'] == 'admin') {
    // Результаты для преподавателя/администратора
    $stmt = $pdo->prepare("
        SELECT tr.*, t.title, u.full_name as student_name
        FROM test_results tr
        JOIN tests t ON tr.test_id = t.id
        JOIN users u ON tr.user_id = u.id
        WHERE t.created_by = ?
        ORDER BY tr.completed_at DESC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $results = $stmt->fetchAll();
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Результаты - Система интеллектуальной оценки знаний</title>
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
        
        /* Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.5);
            animation: fadeIn 0.3s;
        }
        
        .modal-content {
            background-color: #fefefe;
            margin: 5% auto;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            width: 80%;
            max-width: 800px;
            animation: slideIn 0.3s;
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }
        
        .modal-header h2 {
            color: var(--secondary);
            font-size: 22px;
        }
        
        .close {
            color: #aaa;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            transition: color 0.3s;
        }
        
        .close:hover {
            color: var(--accent);
        }
        
        .modal-body {
            margin-bottom: 20px;
        }
        
        .result-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .detail-item {
            padding: 15px;
            background-color: var(--light);
            border-radius: 8px;
        }
        
        .detail-item h3 {
            font-size: 14px;
            color: var(--gray);
            margin-bottom: 5px;
        }
        
        .detail-item p {
            font-size: 18px;
            font-weight: 500;
            color: var(--secondary);
        }
        
        .test-section {
            margin-bottom: 25px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .test-header {
            background-color: var(--light);
            padding: 12px 15px;
            font-weight: 600;
            color: var(--secondary);
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .test-score {
            background-color: var(--primary);
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
        }
        
        .answers-list {
            padding: 15px;
        }
        
        .answer-item {
            padding: 15px;
            margin-bottom: 10px;
            border-radius: 8px;
            background-color: #f9f9f9;
            border-left: 4px solid var(--primary);
        }
        
        .answer-item.correct {
            border-left-color: var(--success);
        }
        
        .answer-item.incorrect {
            border-left-color: var(--accent);
        }
        
        .answer-question {
            font-weight: 500;
            margin-bottom: 8px;
            color: var(--secondary);
        }
        
        .answer-user, .answer-correct {
            font-size: 14px;
            margin-bottom: 5px;
        }
        
        .answer-user {
            color: var(--accent);
        }
        
        .answer-correct {
            color: var(--success);
        }
        
        .no-answers {
            text-align: center;
            padding: 20px;
            color: var(--gray);
            font-style: italic;
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
            
            .modal-content {
                width: 95%;
                margin: 10% auto;
            }
            
            .result-details {
                grid-template-columns: 1fr;
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
            <li><a href="results.php" class="active"><i class="fas fa-chart-line"></i> <span>Результаты</span></a></li>
            <?php if ($user['role'] == 'teacher' || $user['role'] == 'admin'): ?>
            <li><a href="students.php"><i class="fas fa-users"></i> <span>Студенты</span></a></li>
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
                <h2><i class="fas fa-chart-line"></i> Результаты тестирования</h2>
                <p>Анализ и статистика результатов</p>
            </div>
            
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" placeholder="Поиск результатов...">
            </div>
        </div>
        
        <?php if ($user['role'] == 'student'): ?>
            <!-- Статистика для студентов -->
            <?php
            $total_score = 0;
            $total_points = 0;
            $total_tests = count($results);
            
            foreach ($results as $result) {
                $total_score += $result['score'];
                $total_points += $result['total_points'];
            }
            
            $average_score = $total_points > 0 ? round(($total_score / $total_points) * 100, 1) : 0;
            ?>
            
            <div class="stats-cards">
                <div class="stat-card">
                    <div class="stat-icon icon-primary">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div class="stat-details">
                        <h3><?php echo $total_tests; ?></h3>
                        <p>Пройденных тестов</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon icon-success">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-details">
                        <h3><?php echo $total_score . '/' . $total_points; ?></h3>
                        <p>Общий балл</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon icon-warning">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-details">
                        <h3><?php echo $average_score; ?>%</h3>
                        <p>Средний результат</p>
                    </div>
                </div>
            </div>
            
            <div class="section">
                <div class="section-header">
                    <h2>История результатов</h2>
                </div>
                
                <?php if (count($results) > 0): ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Тест</th>
                                <th>Результат</th>
                                <th>Процент</th>
                                <th>Дата прохождения</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results as $result): 
                                $percentage = $result['total_points'] > 0 ? round(($result['score'] / $result['total_points']) * 100, 1) : 0;
                            ?>
                                <tr>
                                    <td><?php echo $result['title']; ?></td>
                                    <td><?php echo $result['score']; ?>/<?php echo $result['total_points']; ?></td>
                                    <td>
                                        <div class="progress-container">
                                            <div class="progress-bar" style="width: <?php echo $percentage; ?>%"></div>
                                        </div>
                                        <?php echo $percentage; ?>%
                                    </td>
                                    <td><?php echo date('d.m.Y H:i', strtotime($result['completed_at'])); ?></td>
                                    <td>
                                        <button class="btn btn-success view-details" 
                                                data-result-id="<?php echo $result['result_id']; ?>" 
                                                data-title="<?php echo htmlspecialchars($result['title'], ENT_QUOTES); ?>"
                                                data-score="<?php echo $result['score']; ?>"
                                                data-total="<?php echo $result['total_points']; ?>"
                                                data-date="<?php echo date('d.m.Y H:i', strtotime($result['completed_at'])); ?>"
                                                data-percentage="<?php echo $percentage; ?>">
                                            Подробнее
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>Вы еще не прошли ни одного теста.</p>
                <?php endif; ?>
            </div>
            
        <?php else: ?>
            <!-- Статистика для преподавателей/администраторов -->
            <?php
            $total_attempts = count($results);
            $unique_students = array_unique(array_column($results, 'student_name'));
            $total_students = count($unique_students);
            
            $total_score = 0;
            $total_points = 0;
            
            foreach ($results as $result) {
                $total_score += $result['score'];
                $total_points += $result['total_points'];
            }
            
            $average_score = $total_points > 0 ? round(($total_score / $total_points) * 100, 1) : 0;
            ?>
            
            <div class="stats-cards">
                <div class="stat-card">
                    <div class="stat-icon icon-primary">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div class="stat-details">
                        <h3><?php echo $total_attempts; ?></h3>
                        <p>Всего попыток</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon icon-success">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-details">
                        <h3><?php echo $total_students; ?></h3>
                        <p>Уникальных студентов</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon icon-warning">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-details">
                        <h3><?php echo $average_score; ?>%</h3>
                        <p>Средний результат</p>
                    </div>
                </div>
            </div>
            
            <div class="section">
                <div class="section-header">
                    <h2>Результаты студентов</h2>
                </div>
                
                <?php if (count($results) > 0): ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Студент</th>
                                <th>Тест</th>
                                <th>Результат</th>
                                <th>Процент</th>
                                <th>Дата прохождения</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results as $result): 
                                $percentage = $result['total_points'] > 0 ? round(($result['score'] / $result['total_points']) * 100, 1) : 0;
                            ?>
                                <tr>
                                    <td><?php echo $result['student_name']; ?></td>
                                    <td><?php echo $result['title']; ?></td>
                                    <td><?php echo $result['score']; ?>/<?php echo $result['total_points']; ?></td>
                                    <td>
                                        <div class="progress-container">
                                            <div class="progress-bar" style="width: <?php echo $percentage; ?>%"></div>
                                        </div>
                                        <?php echo $percentage; ?>%
                                    </td>
                                    <td><?php echo date('d.m.Y H:i', strtotime($result['completed_at'])); ?></td>
                                    <td>
                                        <button class="btn btn-success view-details" 
                                                data-result-id="<?php echo $result['id']; ?>" 
                                                data-title="<?php echo htmlspecialchars($result['title'], ENT_QUOTES); ?>"
                                                data-student="<?php echo htmlspecialchars($result['student_name'], ENT_QUOTES); ?>"
                                                data-score="<?php echo $result['score']; ?>"
                                                data-total="<?php echo $result['total_points']; ?>"
                                                data-date="<?php echo date('d.m.Y H:i', strtotime($result['completed_at'])); ?>"
                                                data-percentage="<?php echo $percentage; ?>">
                                            Подробнее
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>Нет данных о результатах.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Модальное окно -->
    <div id="resultModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Детали результата тестирования</h2>
                <span class="close">&times;</span>
            </div>
            <div class="modal-body">
                <div class="result-details">
                    <div class="detail-item">
                        <h3>Тест</h3>
                        <p id="modal-title"></p>
                    </div>
                    <?php if ($user['role'] != 'student'): ?>
                    <div class="detail-item">
                        <h3>Студент</h3>
                        <p id="modal-student"></p>
                    </div>
                    <?php endif; ?>
                    <div class="detail-item">
                        <h3>Результат</h3>
                        <p id="modal-score"></p>
                    </div>
                    <div class="detail-item">
                        <h3>Процент выполнения</h3>
                        <p id="modal-percentage"></p>
                    </div>
                    <div class="detail-item">
                        <h3>Дата прохождения</h3>
                        <p id="modal-date"></p>
                    </div>
                    <div class="detail-item">
                        <h3>Статус</h3>
                        <p id="modal-status"></p>
                    </div>
                </div>
                
                <h3 style="margin-bottom: 15px;">Ответы на вопросы</h3>
                <div id="tests-container">
                    <p>Загрузка информации о тестах и ответах...</p>
                </div>
            </div>
        </div>
    </div>

    <script>
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
            
            // Получаем модальное окно
            const modal = document.getElementById("resultModal");
            const closeBtn = document.getElementsByClassName("close")[0];
            
            // Обработчики для открытия/закрытия модального окна
            document.querySelectorAll('.view-details').forEach(button => {
                button.addEventListener('click', function() {
                    const resultId = this.getAttribute('data-result-id');
                    const title = this.getAttribute('data-title');
                    const score = this.getAttribute('data-score');
                    const total = this.getAttribute('data-total');
                    const date = this.getAttribute('data-date');
                    const percentage = this.getAttribute('data-percentage');
                    
                    // Заполняем основную информацию
                    document.getElementById('modal-title').textContent = title;
                    document.getElementById('modal-score').textContent = `${score}/${total}`;
                    document.getElementById('modal-percentage').textContent = `${percentage}%`;
                    document.getElementById('modal-date').textContent = date;
                    
                    // Определяем статус
                    const statusElement = document.getElementById('modal-status');
                    if (percentage >= 80) {
                        statusElement.textContent = 'Отлично';
                        statusElement.style.color = 'var(--success)';
                    } else if (percentage >= 60) {
                        statusElement.textContent = 'Хорошо';
                        statusElement.style.color = 'var(--primary)';
                    } else if (percentage >= 40) {
                        statusElement.textContent = 'Удовлетворительно';
                        statusElement.style.color = 'var(--warning)';
                    } else {
                        statusElement.textContent = 'Неудовлетворительно';
                        statusElement.style.color = 'var(--accent)';
                    }
                    
                    // Если это преподаватель/админ, показываем имя студента
                    <?php if ($user['role'] != 'student'): ?>
                    const student = this.getAttribute('data-student');
                    document.getElementById('modal-student').textContent = student;
                    <?php endif; ?>
                    
                    // Загружаем информацию о тестах и ответах через AJAX
                    loadTestsAndAnswers(resultId);
                    
                    // Показываем модальное окно
                    modal.style.display = "block";
                });
            });
            
            // Закрытие модального окна
            closeBtn.onclick = function() {
                modal.style.display = "none";
            }
            
            // Закрытие при клике вне модального окна
            window.onclick = function(event) {
                if (event.target == modal) {
                    modal.style.display = "none";
                }
            }
            
            // Функция загрузки тестов и ответов через AJAX
            function loadTestsAndAnswers(resultId) {
                const testsContainer = document.getElementById('tests-container');
                testsContainer.innerHTML = '<p>Загрузка информации о тестах и ответах...</p>';
                
                // В реальном приложении здесь должен быть AJAX-запрос к серверу
                // Для демонстрации используем имитацию с разделением на тесты
                setTimeout(() => {
                    // Имитируем получение данных с сервера с разделением по тестам
                    const testsData = [
                        {
                            testId: 1,
                            testTitle: "Основы PHP",
                            testScore: 8,
                            testTotal: 10,
                            answers: [
                                {
                                    question: "Что такое PHP?",
                                    userAnswer: "Язык программирования",
                                    correctAnswer: "Язык программирования",
                                    isCorrect: true
                                },
                                {
                                    question: "Какая функция используется для вывода данных в PHP?",
                                    userAnswer: "print()",
                                    correctAnswer: "echo() или print()",
                                    isCorrect: true
                                },
                                {
                                    question: "Какой оператор используется для сравнения по значению и типу в PHP?",
                                    userAnswer: "==",
                                    correctAnswer: "===",
                                    isCorrect: false
                                }
                            ]
                        },
                        {
                            testId: 2,
                            testTitle: "Базы данных MySQL",
                            testScore: 6,
                            testTotal: 8,
                            answers: [
                                {
                                    question: "Какой оператор используется для выборки данных в MySQL?",
                                    userAnswer: "SELECT",
                                    correctAnswer: "SELECT",
                                    isCorrect: true
                                },
                                {
                                    question: "Как создать соединение с MySQL в PHP?",
                                    userAnswer: "mysql_connect()",
                                    correctAnswer: "mysqli_connect() или PDO",
                                    isCorrect: false
                                },
                                {
                                    question: "Какой тип JOIN возвращает все записи из левой таблицы и совпадающие из правой?",
                                    userAnswer: "LEFT JOIN",
                                    correctAnswer: "LEFT JOIN",
                                    isCorrect: true
                                }
                            ]
                        }
                    ];
                    
                    // Очищаем контейнер
                    testsContainer.innerHTML = '';
                    
                    if (testsData.length === 0) {
                        testsContainer.innerHTML = '<div class="no-answers">Нет данных о тестах и ответах</div>';
                        return;
                    }
                    
                    // Добавляем каждый тест с его ответами
                    testsData.forEach(test => {
                        const testSection = document.createElement('div');
                        testSection.className = 'test-section';
                        
                        const testPercentage = Math.round((test.testScore / test.testTotal) * 100);
                        
                        testSection.innerHTML = `
                            <div class="test-header">
                                <span>${test.testTitle}</span>
                                <span class="test-score">${test.testScore}/${test.testTotal} (${testPercentage}%)</span>
                            </div>
                            <div class="answers-list">
                        `;
                        
                        if (test.answers.length === 0) {
                            testSection.innerHTML += '<div class="no-answers">Нет данных об ответах</div>';
                        } else {
                            test.answers.forEach(answer => {
                                const answerItem = document.createElement('div');
                                answerItem.className = `answer-item ${answer.isCorrect ? 'correct' : 'incorrect'}`;
                                
                                answerItem.innerHTML = `
                                    <div class="answer-question">${answer.question}</div>
                                    <div class="answer-user">Ваш ответ: ${answer.userAnswer}</div>
                                    <div class="answer-correct">Правильный ответ: ${answer.correctAnswer}</div>
                                `;
                                
                                testSection.querySelector('.answers-list').appendChild(answerItem);
                            });
                        }
                        
                        testSection.innerHTML += `</div>`;
                        testsContainer.appendChild(testSection);
                    });
                }, 500);
            }
        });
    </script>
</body>
</html>