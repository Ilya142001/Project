<?php
include 'config.php';
// Показываем сообщения об успехе/ошибке
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}
// Проверяем, авторизован ли пользователь
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

// Получаем информацию о пользователе
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

// Обработка создания теста (если форма отправлена)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_test'])) {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $is_published = isset($_POST['is_published']) ? 1 : 0;
    
    if (!empty($title)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO tests (title, description, is_published, created_by) VALUES (?, ?, ?, ?)");
            $stmt->execute([$title, $description, $is_published, $_SESSION['user_id']]);
            
            $test_id = $pdo->lastInsertId();
            header("Location: test_edit.php?id=" . $test_id);
            exit;
            
        } catch (PDOException $e) {
            $error = "Ошибка при создании теста: " . $e->getMessage();
        }
    } else {
        $error = "Название теста обязательно для заполнения";
    }
}

// Получаем тесты в зависимости от роли пользователя
if ($user['role'] == 'student') {
    // Тесты для студента (только опубликованные)
    $stmt = $pdo->prepare("
        SELECT t.*, u.full_name as creator_name
        FROM tests t
        JOIN users u ON t.created_by = u.id
        WHERE t.is_published = TRUE 
        AND t.id NOT IN (
            SELECT test_id FROM test_results WHERE user_id = ?
        )
        ORDER BY t.created_at DESC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $tests = $stmt->fetchAll();
    
    // Пройденные тесты
    $stmt = $pdo->prepare("
        SELECT t.*, tr.score, tr.total_points, tr.completed_at 
        FROM test_results tr
        JOIN tests t ON tr.test_id = t.id
        WHERE tr.user_id = ?
        ORDER BY tr.completed_at DESC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $completed_tests = $stmt->fetchAll();
    
} else if ($user['role'] == 'teacher' || $user['role'] == 'admin') {
    // Тесты для преподавателя/администратора
    $stmt = $pdo->prepare("
        SELECT t.*, 
               COUNT(tr.id) as attempts,
               COUNT(DISTINCT tr.user_id) as unique_students,
               (SELECT COUNT(*) FROM questions WHERE test_id = t.id) as question_count
        FROM tests t
        LEFT JOIN test_results tr ON t.id = tr.test_id
        WHERE t.created_by = ?
        GROUP BY t.id
        ORDER BY t.created_at DESC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $tests = $stmt->fetchAll();
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Тесты - Система интеллектуальной оценки знаний</title>
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
        
        /* Test Items */
        .test-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 15px;
        }
        
        .test-item {
            padding: 15px;
            background: var(--light);
            border-radius: 8px;
            transition: transform 0.2s;
        }
        
        .test-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
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
            margin-bottom: 10px;
        }
        
        .test-score {
            font-weight: 600;
            color: var(--success);
        }
        
        .test-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
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
        
        .btn-warning {
            background: var(--warning);
            color: white;
        }
        
        .btn-warning:hover {
            opacity: 0.9;
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
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: var(--light);
            padding: 15px;
            border-radius: 8px;
            text-align: center;
        }
        
        .stat-number {
            font-size: 24px;
            font-weight: bold;
            color: var(--primary);
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 14px;
            color: var(--gray);
        }
        
        /* Стили для модального окна */
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
            max-width: 600px;
            box-shadow: 0 5px 25px rgba(0,0,0,0.2);
            animation: slideIn 0.3s;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes slideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        .modal-header {
            padding: 20px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--secondary);
            color: white;
            border-radius: 10px 10px 0 0;
        }
        
        .modal-header h3 {
            margin: 0;
            color: white;
            font-weight: 500;
        }
        
        .close {
            color: white;
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
            background: none;
            border: none;
            opacity: 0.8;
        }
        
        .close:hover {
            opacity: 1;
        }
        
        .modal-body {
            padding: 25px;
            max-height: 70vh;
            overflow-y: auto;
        }
        
        .modal-footer {
            padding: 15px 25px;
            border-top: 1px solid #eee;
            text-align: right;
            background: #f9f9f9;
            border-radius: 0 0 10px 10px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--secondary);
        }
        
        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 15px;
            transition: border-color 0.3s;
        }
        
        .form-control:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.2);
        }
        
        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .checkbox-group input[type="checkbox"] {
            margin-right: 10px;
            width: 18px;
            height: 18px;
            accent-color: var(--primary);
        }
        
        .checkbox-group label {
            margin: 0;
            font-weight: normal;
            color: var(--secondary);
        }
        
        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
            margin-left: 10px;
        }
        
        .status-published {
            background: var(--success);
            color: white;
        }
        
        .status-draft {
            background: var(--gray);
            color: white;
        }
        
        /* Стили для редактора тестов */
        .editor-container {
            display: none;
            margin-top: 20px;
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .question-editor {
            margin-bottom: 25px;
            padding: 15px;
            border: 1px solid #eee;
            border-radius: 8px;
            background: #f9f9f9;
        }
        
        .question-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }
        
        .question-title {
            font-weight: 600;
            color: var(--secondary);
        }
        
        .answer-option {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
            padding: 10px;
            background: white;
            border-radius: 5px;
            border: 1px solid #eee;
        }
        
        .answer-option input[type="text"] {
            flex: 1;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            margin-right: 10px;
        }
        
        .answer-option input[type="checkbox"] {
            margin-right: 10px;
            accent-color: var(--primary);
        }
        
        .answer-option .remove-answer {
            color: var(--accent);
            cursor: pointer;
            padding: 5px;
        }
        
        .add-answer {
            margin-top: 10px;
            color: var(--primary);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            font-weight: 500;
        }
        
        .add-answer i {
            margin-right: 5px;
        }
        
        .question-actions {
            display: flex;
            justify-content: space-between;
            margin-top: 15px;
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 13px;
        }
        /* Стили для модального окна редактирования */
.modal-content.large {
    max-width: 95%;
    width: 1200px;
    height: 90vh;
}

.modal-body.scrollable {
    height: calc(90vh - 130px);
    overflow-y: auto;
}
/* Анимации для удаления */
@keyframes fadeOut {
    from { opacity: 1; transform: translateY(0); }
    to { opacity: 0; transform: translateY(-20px); height: 0; padding: 0; margin: 0; }
}

@keyframes shake {
    0%, 100% { transform: translateX(0); }
    10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
    20%, 40%, 60%, 80% { transform: translateX(5px); }
}

.deleting {
    animation: fadeOut 0.5s ease forwards;
    overflow: hidden;
}

.shake {
    animation: shake 0.5s ease;
}

/* Стили для модального окна подтверждения */
.confirm-modal {
    display: none;
    position: fixed;
    z-index: 2000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.7);
    animation: fadeIn 0.3s;
}

.confirm-modal-content {
    background: white;
    margin: 15% auto;
    padding: 0;
    border-radius: 15px;
    width: 90%;
    max-width: 400px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.3);
    animation: slideIn 0.3s;
    overflow: hidden;
}

.confirm-modal-header {
    background: linear-gradient(135deg, #e74c3c, #c0392b);
    color: white;
    padding: 20px;
    text-align: center;
}

.confirm-modal-header i {
    font-size: 48px;
    margin-bottom: 10px;
    display: block;
}

.confirm-modal-body {
    padding: 25px;
    text-align: center;
}

.confirm-modal-footer {
    padding: 15px 25px;
    display: flex;
    gap: 10px;
    justify-content: center;
    background: #f9f9f9;
}

.confirm-btn {
    padding: 10px 20px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
    min-width: 100px;
}

.confirm-btn-danger {
    background: #e74c3c;
    color: white;
}

.confirm-btn-danger:hover {
    background: #c0392b;
    transform: translateY(-2px);
}

.confirm-btn-secondary {
    background: #95a5a6;
    color: white;
}

.confirm-btn-secondary:hover {
    background: #7f8c8d;
    transform: translateY(-2px);
}

/* Эффект для кнопки удаления */
.btn-danger {
    transition: all 0.3s;
}

.btn-danger:hover {
    transform: scale(1.1);
    box-shadow: 0 5px 15px rgba(231, 76, 60, 0.3);
}

/* Адаптивность для модального окна */
@media (max-width: 1300px) {
    .modal-content.large {
        width: 95%;
        margin: 2% auto;
    }
}

@media (max-width: 768px) {
    .modal-content.large {
        width: 98%;
        height: 95vh;
    }
    
    .modal-body.scrollable {
        height: calc(95vh - 130px);
    }
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
            
            .test-list {
                grid-template-columns: 1fr;
            }
            
            .data-table {
                display: block;
                overflow-x: auto;
            }
            
            .test-actions {
                flex-direction: column;
            }
            
            .modal-content {
                margin: 10% auto;
                width: 95%;
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
            <li><a href="tests.php" class="active"><i class="fas fa-file-alt"></i> <span>Тесты</span></a></li>
            <li><a href="results.php"><i class="fas fa-chart-line"></i> <span>Результаты</span></a></li>
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
                <h2>Управление тестами</h2>
                <p>Все доступные тесты и результаты</p>
            </div>
            
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" placeholder="Поиск тестов...">
            </div>
        </div>
        
        <?php if (isset($error)): ?>
            <div class="section" style="border-left: 4px solid var(--accent);">
                <div style="color: var(--accent); font-weight: 500;">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if ($user['role'] == 'student'): ?>
            <!-- Секция для студентов -->
            <div class="section">
                <div class="section-header">
                    <h2>Доступные тесты</h2>
                </div>
                
                <div class="test-list">
                    <?php if (count($tests) > 0): ?>
                        <?php foreach ($tests as $test): ?>
                            <div class="test-item">
                                <div class="test-title"><?php echo $test['title']; ?></div>
                                <div class="test-meta">
                                    <span>Автор: <?php echo $test['creator_name']; ?></span>
                                    <span>Создан: <?php echo date('d.m.Y', strtotime($test['created_at'])); ?></span>
                                </div>
                                <?php if (!empty($test['description'])): ?>
                                <div class="test-meta">
                                    <span><?php echo $test['description']; ?></span>
                                </div>
                                <?php endif; ?>
                                <div class="test-actions">
                                    <a href="take_test.php?id=<?php echo $test['id']; ?>" class="btn btn-primary">Пройти тест</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p>Нет доступных тестов для прохождения.</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="section">
                <div class="section-header">
                    <h2>Пройденные тесты</h2>
                </div>
                
                <?php if (count($completed_tests) > 0): ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Тест</th>
                                <th>Результат</th>
                                <th>Дата прохождения</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($completed_tests as $test): ?>
                                <tr>
                                    <td><?php echo $test['title']; ?></td>
                                    <td><?php echo $test['score']; ?>/<?php echo $test['total_points']; ?></td>
                                    <td><?php echo date('d.m.Y H:i', strtotime($test['completed_at'])); ?></td>
                                    <td>
                                        <a href="test_result.php?id=<?php echo $test['id']; ?>" class="btn btn-success">Подробнее</a>
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
            <!-- Секция для преподавателей/администраторов -->
            <div class="section">
                <div class="section-header">
                    <h2>Мои тесты</h2>
                    <button class="btn btn-primary" onclick="openModal()">
                        <i class="fas fa-plus"></i> Создать тест
                    </button>
                </div>
                
                <?php 
                // Статистика для преподавателя
                $total_tests = count($tests);
                $total_questions = 0;
                $total_attempts = 0;
                $total_students = 0;
                
                foreach ($tests as $test) {
                    $total_questions += $test['question_count'];
                    $total_attempts += $test['attempts'];
                    $total_students += $test['unique_students'];
                }
                ?>
                
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $total_tests; ?></div>
                        <div class="stat-label">Всего тестов</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $total_questions; ?></div>
                        <div class="stat-label">Всего вопросов</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $total_attempts; ?></div>
                        <div class="stat-label">Попыток прохождения</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $total_students; ?></div>
                        <div class="stat-label">Уникальных студентов</div>
                    </div>
                </div>
                
                <?php if (count($tests) > 0): ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Название теста</th>
                                <th>Вопросов</th>
                                <th>Попыток</th>
                                <th>Уникальных студентов</th>
                                <th>Статус</th>
                                <th>Дата создания</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tests as $test): ?>
                                <tr>
                                    <td>
                                        <div class="test-title"><?php echo $test['title']; ?></div>
                                        <?php if (!empty($test['description'])): ?>
                                        <div style="font-size: 12px; color: var(--gray); margin-top: 5px;">
                                            <?php echo mb_strlen($test['description']) > 100 ? mb_substr($test['description'], 0, 100) . '...' : $test['description']; ?>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $test['question_count']; ?></td>
                                    <td><?php echo $test['attempts']; ?></td>
                                    <td><?php echo $test['unique_students']; ?></td>
                                    <td>
                                        <span class="status-badge <?php echo $test['is_published'] ? 'status-published' : 'status-draft'; ?>">
                                            <?php echo $test['is_published'] ? 'Опубликован' : 'Черновик'; ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('d.m.Y', strtotime($test['created_at'])); ?></td>
                                    <td>
                                        <div class="test-actions">
                                            <a href="javascript:void(0)" onclick="openTestEditor(<?php echo $test['id']; ?>, '<?php echo addslashes($test['title']); ?>')" 
                                                    class="btn btn-primary" title="Редактировать">
                                                        <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="test_results_view.php?id=<?php echo $test['id']; ?>" class="btn btn-success" title="Результаты">
                                                <i class="fas fa-chart-bar"></i>
                                            </a>
                                           <a href="test_delete.php?id=<?php echo $test['id']; ?>" class="btn btn-danger" title="Удалить" 
   onclick="return confirm('Вы уверены, что хотите удалить тест \'<?php echo addslashes($test['title']); ?>\'? Все связанные вопросы и результаты также будут удалены!')">
    <i class="fas fa-trash"></i>
</a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>Вы еще не создали ни одного теста. Нажмите "Создать тест" чтобы начать.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Модальное окно создания теста -->
    <div id="createTestModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Создание нового теста</h3>
                <button class="close" onclick="closeModal()">&times;</button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <div class="form-group">
                        <label for="title">Название теста *</label>
                        <input type="text" id="title" name="title" class="form-control" required 
                               placeholder="Введите название теста">
                    </div>
                    
                    <div class="form-group">
                        <label for="description">Описание теста</label>
                        <textarea id="description" name="description" class="form-control" 
                                  placeholder="Опишите содержание теста, его цели и особенности" rows="3"></textarea>
                    </div>
                    
                    <div class="checkbox-group">
                        <input type="checkbox" id="is_published" name="is_published" value="1">
                        <label for="is_published">Опубликовать тест сразу</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Отмена</button>
                    <button type="submit" name="create_test" class="btn btn-primary">Создать тест</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Функции для модального окна
        function openModal() {
            document.getElementById('createTestModal').style.display = 'block';
        }
        
        function closeModal() {
            document.getElementById('createTestModal').style.display = 'none';
        }
        
        // Закрытие модального окна при клике вне его
        window.onclick = function(event) {
            const modal = document.getElementById('createTestModal');
            if (event.target === modal) {
                closeModal();
            }
        }
        
        // Простая интерактивность
        document.querySelectorAll('.test-item').forEach(item => {
            item.addEventListener('click', function(e) {
                if (!e.target.classList.contains('btn')) {
                    this.querySelector('a.btn').click();
                }
            });
        });
        
        // Поиск тестов
        document.querySelector('.search-box input').addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            
            document.querySelectorAll('.test-item, .data-table tbody tr').forEach(item => {
                const text = item.textContent.toLowerCase();
                if (text.includes(searchTerm)) {
                    item.style.display = '';
                } else {
                    item.style.display = 'none';
                }
            });
        });
        // Функции для модального окна редактирования теста
function openTestEditor(testId, testTitle) {
    document.getElementById('editTestModal').style.display = 'block';
    document.getElementById('modalTestTitle').textContent = 'Редактирование теста: ' + testTitle;
    
    // Загружаем контент редактора теста через AJAX
    fetch('test_edit_modal.php?id=' + testId)
        .then(response => response.text())
        .then(data => {
            document.getElementById('testEditorContent').innerHTML = data;
        })
        .catch(error => {
            document.getElementById('testEditorContent').innerHTML = 
                '<div class="error">Ошибка загрузки редактора: ' + error + '</div>';
        });
}

function closeEditModal() {
    document.getElementById('editTestModal').style.display = 'none';
    // Очищаем контент при закрытии
    document.getElementById('testEditorContent').innerHTML = 
        '<div style="text-align: center; padding: 40px;">' +
        '<i class="fas fa-spinner fa-spin" style="font-size: 24px; color: var(--primary);"></i>' +
        '<p>Загрузка редактора теста...</p>' +
        '</div>';
}

// Обновите обработчик клика вне модального окна
window.onclick = function(event) {
    const modal = document.getElementById('createTestModal');
    const editModal = document.getElementById('editTestModal');
    
    if (event.target === modal) {
        closeModal();
    }
    if (event.target === editModal) {
        closeEditModal();
    }
}// Переменные для управления удалением
let currentDeleteId = null;
let currentDeleteElement = null;

// Функция для открытия модального окна подтверждения
function showDeleteConfirm(testId, testTitle, element) {
    currentDeleteId = testId;
    currentDeleteElement = element.closest('tr') || element.closest('.test-item');
    
    document.getElementById('confirmDeleteText').textContent = 
        `Вы уверены, что хотите удалить тест "${testTitle}"?`;
    
    document.getElementById('confirmDeleteModal').style.display = 'block';
}

// Функция для закрытия модального окна
function closeConfirmModal() {
    document.getElementById('confirmDeleteModal').style.display = 'none';
    currentDeleteId = null;
    currentDeleteElement = null;
}

// Функция для подтверждения удаления
function confirmDelete() {
    if (!currentDeleteId) return;
    
    // Добавляем класс для анимации
    if (currentDeleteElement) {
        currentDeleteElement.classList.add('deleting');
    }
    
    // Закрываем модальное окно
    closeConfirmModal();
    
    // Отправляем запрос на удаление через 300ms (чтобы анимация успела проиграться)
    setTimeout(() => {
        window.location.href = `test_delete.php?id=${currentDeleteId}`;
    }, 500);
}

// Функция для удаления с AJAX (более современный подход)
async function deleteTestAjax(testId, element) {
    try {
        const response = await fetch(`test_delete.php?id=${testId}`);
        const result = await response.text();
        
        if (response.ok) {
            // Анимация удаления
            const item = element.closest('tr') || element.closest('.test-item');
            item.classList.add('deleting');
            
            // Полное удаление из DOM после анимации
            setTimeout(() => {
                item.remove();
                // Показываем уведомление об успехе
                showNotification('Тест успешно удален!', 'success');
            }, 500);
        } else {
            throw new Error(result);
        }
    } catch (error) {
        console.error('Ошибка удаления:', error);
        showNotification('Ошибка при удалении теста', 'error');
    }
}

// Функция для показа уведомлений
function showNotification(message, type = 'info') {
    // Создаем элемент уведомления
    const notification = document.createElement('div');
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 15px 20px;
        border-radius: 8px;
        color: white;
        font-weight: 500;
        z-index: 3000;
        animation: slideInRight 0.3s ease;
        box-shadow: 0 5px 15px rgba(0,0,0,0.2);
    `;
    
    if (type === 'success') {
        notification.style.background = 'linear-gradient(135deg, #2ecc71, #27ae60)';
    } else if (type === 'error') {
        notification.style.background = 'linear-gradient(135deg, #e74c3c, #c0392b)';
    } else {
        notification.style.background = 'linear-gradient(135deg, #3498db, #2980b9)';
    }
    
    notification.textContent = message;
    document.body.appendChild(notification);
    
    // Автоматическое скрытие через 3 секунды
    setTimeout(() => {
        notification.style.animation = 'fadeOut 0.5s ease forwards';
        setTimeout(() => notification.remove(), 500);
    }, 3000);
}

// Анимация для уведомлений
const style = document.createElement('style');
style.textContent = `
    @keyframes slideInRight {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
    
    @keyframes fadeOut {
        from { opacity: 1; transform: translateX(0); }
        to { opacity: 0; transform: translateX(100%); }
    }
`;
document.head.appendChild(style);

// Закрытие модального окна при клике вне его
window.onclick = function(event) {
    const modal = document.getElementById('createTestModal');
    const editModal = document.getElementById('editTestModal');
    const confirmModal = document.getElementById('confirmDeleteModal');
    
    if (event.target === modal) closeModal();
    if (event.target === editModal) closeEditModal();
    if (event.target === confirmModal) closeConfirmModal();
}
    </script>
    <!-- Модальное окно редактирования теста -->
<div id="editTestModal" class="modal">
    <div class="modal-content" style="max-width: 95%; width: 1200px; height: 90vh;">
        <div class="modal-header">
            <h3 id="modalTestTitle">Редактирование теста</h3>
            <button class="close" onclick="closeEditModal()">&times;</button>
        </div>
        <div class="modal-body" style="height: calc(90vh - 130px); overflow-y: auto;">
            <div id="testEditorContent">
                <div style="text-align: center; padding: 40px;">
                    <i class="fas fa-spinner fa-spin" style="font-size: 24px; color: var(--primary);"></i>
                    <p>Загрузка редактора теста...</p>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Закрыть</button>
        </div>
    </div>
</div>
<!-- Модальное окно подтверждения удаления -->
<div id="confirmDeleteModal" class="confirm-modal">
    <div class="confirm-modal-content">
        <div class="confirm-modal-header">
            <i class="fas fa-exclamation-triangle"></i>
            <h3>Подтверждение удаления</h3>
        </div>
        <div class="confirm-modal-body">
            <p id="confirmDeleteText">Вы уверены, что хотите удалить этот тест?</p>
            <p style="color: #e74c3c; font-size: 14px; margin-top: 10px;">
                <i class="fas fa-info-circle"></i> Все связанные вопросы и результаты также будут удалены!
            </p>
        </div>
        <div class="confirm-modal-footer">
            <button class="confirm-btn confirm-btn-secondary" onclick="closeConfirmModal()">Отмена</button>
            <button class="confirm-btn confirm-btn-danger" onclick="confirmDelete()">Удалить</button>
        </div>
    </div>
</div>
</body>
</html>