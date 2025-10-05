<?php
include 'config.php';
session_start();

// Проверяем авторизацию и права доступа
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Получаем информацию о пользователе
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

// Проверяем, что пользователь - преподаватель
if ($user['role'] != 'teacher') {
    header("Location: index.php");
    exit;
}

// Получаем категории материалов
$categories = [];
try {
    $stmt = $pdo->query("SELECT * FROM material_categories ORDER BY name");
    $categories = $stmt->fetchAll();
} catch (PDOException $e) {
    // Если таблицы нет, используем массив по умолчанию
    $categories = [
        ['id' => 1, 'name' => 'Видеоуроки', 'icon' => 'fas fa-video'],
        ['id' => 2, 'name' => 'PDF материалы', 'icon' => 'fas fa-file-pdf'],
        ['id' => 3, 'name' => 'Интерактивные задания', 'icon' => 'fas fa-puzzle-piece'],
        ['id' => 4, 'name' => 'Ссылки на ресурсы', 'icon' => 'fas fa-link']
    ];
}

// Проверяем, что категории загружены
if (empty($categories)) {
    $categories = [
        ['id' => 1, 'name' => 'Видеоуроки', 'icon' => 'fas fa-video'],
        ['id' => 2, 'name' => 'PDF материалы', 'icon' => 'fas fa-file-pdf'],
        ['id' => 3, 'name' => 'Интерактивные задания', 'icon' => 'fas fa-puzzle-piece'],
        ['id' => 4, 'name' => 'Ссылки на ресурсы', 'icon' => 'fas fa-link']
    ];
}

// Обработка формы загрузки
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $category_id = intval($_POST['category_id']);
    $duration = trim($_POST['duration']);
    
    // Валидация
    if (empty($title) || empty($category_id)) {
        $message = 'Пожалуйста, заполните все обязательные поля';
        $message_type = 'error';
    } else {
        try {
            // Обработка в зависимости от типа материала
            $file_path = null;
            $external_url = null;
            $file_size = null;
            
            switch ($category_id) {
                case 1: // Видеоуроки
                    if (isset($_FILES['video_file']) && $_FILES['video_file']['error'] === UPLOAD_ERR_OK) {
                        $allowed_video_types = ['video/mp4', 'video/mkv', 'video/avi', 'video/mov'];
                        $file_type = $_FILES['video_file']['type'];
                        
                        if (!in_array($file_type, $allowed_video_types)) {
                            throw new Exception('Разрешены только видеофайлы: MP4, MKV, AVI, MOV');
                        }
                        
                        // Создаем директорию если нет
                        $upload_dir = 'uploads/videos/';
                        if (!is_dir($upload_dir)) {
                            mkdir($upload_dir, 0777, true);
                        }
                        
                        // Генерируем уникальное имя файла
                        $file_extension = pathinfo($_FILES['video_file']['name'], PATHINFO_EXTENSION);
                        $file_name = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9]/', '_', $title) . '.' . $file_extension;
                        $file_path = $upload_dir . $file_name;
                        
                        if (!move_uploaded_file($_FILES['video_file']['tmp_name'], $file_path)) {
                            throw new Exception('Ошибка при загрузке видеофайла');
                        }
                        
                        $file_size = formatFileSize($_FILES['video_file']['size']);
                    } else {
                        throw new Exception('Пожалуйста, выберите видеофайл');
                    }
                    break;
                    
                case 2: // PDF материалы
                    if (isset($_FILES['pdf_file']) && $_FILES['pdf_file']['error'] === UPLOAD_ERR_OK) {
                        if ($_FILES['pdf_file']['type'] !== 'application/pdf') {
                            throw new Exception('Разрешены только PDF файлы');
                        }
                        
                        $upload_dir = 'uploads/pdfs/';
                        if (!is_dir($upload_dir)) {
                            mkdir($upload_dir, 0777, true);
                        }
                        
                        $file_name = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9]/', '_', $title) . '.pdf';
                        $file_path = $upload_dir . $file_name;
                        
                        if (!move_uploaded_file($_FILES['pdf_file']['tmp_name'], $file_path)) {
                            throw new Exception('Ошибка при загрузке PDF файла');
                        }
                        
                        $file_size = formatFileSize($_FILES['pdf_file']['size']);
                    } else {
                        throw new Exception('Пожалуйста, выберите PDF файл');
                    }
                    break;
                    
                case 3: // Интерактивные задания
                    if (isset($_FILES['interactive_file']) && $_FILES['interactive_file']['error'] === UPLOAD_ERR_OK) {
                        $allowed_types = ['application/zip', 'application/x-zip-compressed'];
                        $file_type = $_FILES['interactive_file']['type'];
                        
                        if (!in_array($file_type, $allowed_types)) {
                            throw new Exception('Разрешены только ZIP архивы для интерактивных заданий');
                        }
                        
                        $upload_dir = 'uploads/interactive/';
                        if (!is_dir($upload_dir)) {
                            mkdir($upload_dir, 0777, true);
                        }
                        
                        $file_name = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9]/', '_', $title) . '.zip';
                        $file_path = $upload_dir . $file_name;
                        
                        if (!move_uploaded_file($_FILES['interactive_file']['tmp_name'], $file_path)) {
                            throw new Exception('Ошибка при загрузке файла задания');
                        }
                        
                        $file_size = formatFileSize($_FILES['interactive_file']['size']);
                    } else {
                        throw new Exception('Пожалуйста, выберите файл задания');
                    }
                    break;
                    
                case 4: // Ссылки на ресурсы
                    $external_url = trim($_POST['external_url']);
                    if (empty($external_url)) {
                        throw new Exception('Пожалуйста, укажите URL');
                    }
                    
                    if (!filter_var($external_url, FILTER_VALIDATE_URL)) {
                        throw new Exception('Пожалуйста, укажите корректный URL');
                    }
                    break;
                    
                default:
                    throw new Exception('Неверная категория материала');
            }
            
            // Определяем тип файла для БД
            $file_type_db = '';
            switch ($category_id) {
                case 1: $file_type_db = 'video'; break;
                case 2: $file_type_db = 'pdf'; break;
                case 3: $file_type_db = 'interactive'; break;
                case 4: $file_type_db = 'link'; break;
            }
            
            // Сохраняем в базу данных
            $stmt = $pdo->prepare("
                INSERT INTO materials (title, description, category_id, file_type, file_path, external_url, duration, file_size, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $title,
                $description,
                $category_id,
                $file_type_db,
                $file_path,
                $external_url,
                $duration,
                $file_size,
                $_SESSION['user_id']
            ]);
            
            $message = 'Материал успешно загружен!';
            $message_type = 'success';
            
            // Очищаем форму после успешной загрузки
            $_POST = array();
            
        } catch (Exception $e) {
            $message = $e->getMessage();
            $message_type = 'error';
        }
    }
}

// Функция для форматирования размера файла
function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Загрузка материалов - Система интеллектуальной оценки знаний</title>
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
            --sidebar-collapsed: 70px;
        }
        
        body {
            background-color: #f0f2f5;
            color: #333;
            line-height: 1.6;
            display: flex;
            min-height: 100vh;
            transition: all 0.3s ease;
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
            transition: all 0.3s ease;
        }

        .sidebar.collapsed {
            width: var(--sidebar-collapsed);
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
            position: relative;
        }

        .logo h1 {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: opacity 0.3s;
        }

        .sidebar.collapsed .logo h1 {
            opacity: 0;
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
            transition: opacity 0.3s;
        }

        .sidebar.collapsed .system-status {
            opacity: 0;
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
            transition: all 0.3s;
        }

        .sidebar.collapsed .user-info {
            justify-content: center;
            padding: 15px;
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
            flex-shrink: 0;
        }

        .user-details {
            transition: opacity 0.3s;
        }

        .sidebar.collapsed .user-details {
            opacity: 0;
            width: 0;
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
            background: var(--primary);
        }

        /* Навигация */
        .nav-links {
            list-style: none;
            flex: 1;
            overflow-y: auto;
        }

        .nav-section {
            padding: 15px 20px 5px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            margin-bottom: 10px;
            transition: all 0.3s;
        }

        .sidebar.collapsed .nav-section {
            padding: 10px 15px 5px;
            text-align: center;
        }

        .section-label {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: rgba(255, 255, 255, 0.5);
            transition: opacity 0.3s;
        }

        .sidebar.collapsed .section-label {
            opacity: 0;
        }

        .nav-links li a {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            transition: all 0.3s;
            border-left: 3px solid transparent;
            white-space: nowrap;
        }

        .sidebar.collapsed .nav-links li a {
            padding: 15px;
            justify-content: center;
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
            flex-shrink: 0;
        }

        .sidebar.collapsed .nav-links li a i {
            margin-right: 0;
            font-size: 18px;
        }

        .nav-links li a span {
            transition: opacity 0.3s;
        }

        .sidebar.collapsed .nav-links li a span {
            opacity: 0;
            width: 0;
            overflow: hidden;
        }

        /* Кнопка переключения сайдбара */
        .toggle-sidebar {
            position: absolute;
            top: 20px;
            right: -15px;
            width: 30px;
            height: 30px;
            background: var(--primary);
            border: none;
            border-radius: 50%;
            color: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            z-index: 1001;
            transition: all 0.3s;
        }

        .toggle-sidebar:hover {
            transform: scale(1.1);
            background: var(--primary-dark);
        }

        /* Основной контент */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            padding: 20px;
            transition: margin-left 0.3s ease;
            min-height: 100vh;
        }

        .sidebar.collapsed ~ .main-content {
            margin-left: var(--sidebar-collapsed);
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
        
        /* Форма загрузки */
        .upload-container {
            max-width: 800px;
            margin: 0 auto;
        }
        
        .upload-form {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--secondary);
        }
        
        .form-group.required label::after {
            content: ' *';
            color: var(--accent);
        }
        
        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.3s;
        }
        
        .form-control:focus {
            border-color: var(--primary);
            outline: none;
        }
        
        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }
        
        .file-upload {
            border: 2px dashed #e0e0e0;
            border-radius: 8px;
            padding: 30px;
            text-align: center;
            transition: all 0.3s;
            cursor: pointer;
        }
        
        .file-upload:hover {
            border-color: var(--primary);
            background: rgba(52, 152, 219, 0.05);
        }
        
        .file-upload i {
            font-size: 48px;
            color: var(--gray);
            margin-bottom: 15px;
        }
        
        .file-upload input[type="file"] {
            display: none;
        }
        
        .file-info {
            margin-top: 10px;
            font-size: 14px;
            color: var(--gray);
        }
        
        .category-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-top: 10px;
        }
        
        .category-option {
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .category-option:hover {
            border-color: var(--primary);
        }
        
        .category-option.selected {
            border-color: var(--primary);
            background: rgba(52, 152, 219, 0.1);
        }
        
        .category-option i {
            font-size: 32px;
            margin-bottom: 10px;
            display: block;
        }
        
        .category-option.video i { color: #e74c3c; }
        .category-option.pdf i { color: #3498db; }
        .category-option.interactive i { color: #2ecc71; }
        .category-option.link i { color: #f39c12; }
        
        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
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
            transform: translateY(-2px);
        }
        
        .btn-secondary {
            background: var(--gray);
            color: white;
        }
        
        .btn-secondary:hover {
            background: #6c7a7b;
            transform: translateY(-2px);
        }
        
        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
        }
        
        /* Сообщения */
        .message {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 25px;
            font-weight: 500;
        }
        
        .message.success {
            background: rgba(46, 204, 113, 0.1);
            color: var(--success);
            border: 1px solid var(--success);
        }
        
        .message.error {
            background: rgba(231, 76, 60, 0.1);
            color: var(--danger);
            border: 1px solid var(--danger);
        }
        
        /* Адаптивность */
        @media (max-width: 768px) {
            .main-content {
                padding: 15px;
                margin-left: 0 !important;
            }
            
            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .category-options {
                grid-template-columns: 1fr;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.mobile-open {
                transform: translateX(0);
            }
        }
    </style>
</head>
<body>
    <!-- Сайдбар -->
    <div class="sidebar">
        <button class="toggle-sidebar" onclick="toggleSidebar()">
            <i class="fas fa-chevron-left"></i>
        </button>

        <div class="logo">
            <h1><i class="fas fa-graduation-cap"></i> EduSystem</h1>
            <div class="system-status">
                <div class="status-indicator online"></div>
                <span>Система активна</span>
            </div>
        </div>

        <div class="user-info">
            <div class="user-avatar">
                <?php 
                $nameParts = explode(' ', $user['full_name']);
                $initials = '';
                foreach ($nameParts as $part) {
                    if (!empty(trim($part))) {
                        $initials .= mb_substr(trim($part), 0, 1);
                    }
                }
                echo !empty($initials) ? mb_strtoupper($initials) : 'U';
                ?>
            </div>
            <div class="user-details">
                <h3><?php echo htmlspecialchars($user['full_name'] ?? 'Пользователь'); ?></h3>
                <p><?php echo htmlspecialchars($user['email'] ?? 'email@example.com'); ?></p>
                <span class="role-badge"><?php echo htmlspecialchars($user['role'] ?? 'teacher'); ?></span>
            </div>
        </div>

        <ul class="nav-links">
            <div class="nav-section">
                <div class="section-label">Основное</div>
            </div>
            <li><a href="index.php"><i class="fas fa-home"></i> <span>Главная</span></a></li>
            <li><a href="learning_path.php"><i class="fas fa-road"></i> <span>Траектория обучения</span></a></li>
            <li><a href="study_materials.php"><i class="fas fa-book"></i> <span>Материалы</span></a></li>
            <li><a href="upload_material.php" class="active"><i class="fas fa-upload"></i> <span>Загрузить материалы</span></a></li>
            <li><a href="tests.php"><i class="fas fa-play"></i> <span>Тесты</span></a></li>
            <li><a href="results.php"><i class="fas fa-chart-bar"></i> <span>Результаты</span></a></li>

            <div class="nav-section">
                <div class="section-label">Дополнительно</div>
            </div>
            <li><a href="profile.php"><i class="fas fa-user"></i> <span>Профиль</span></a></li>
            <li><a href="help.php"><i class="fas fa-question-circle"></i> <span>Помощь</span></a></li>
            <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> <span>Выход</span></a></li>
        </ul>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
        <div class="header">
            <div class="welcome">
                <h2>Загрузка обучающих материалов</h2>
                <p>Добавьте новые материалы для студентов</p>
            </div>
        </div>
        
        <div class="upload-container">
            <?php if (!empty($message)): ?>
                <div class="message <?php echo $message_type; ?>">
                    <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="upload_material.php" enctype="multipart/form-data" class="upload-form">
                <!-- Основная информация -->
                <div class="form-group required">
                    <label for="title">Название материала</label>
                    <input type="text" id="title" name="title" class="form-control" 
                           value="<?php echo isset($_POST['title']) ? htmlspecialchars($_POST['title']) : ''; ?>" 
                           required placeholder="Введите название материала">
                </div>
                
                <div class="form-group">
                    <label for="description">Описание</label>
                    <textarea id="description" name="description" class="form-control" 
                              placeholder="Опишите материал..."><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
                </div>
                
                <!-- Выбор категории -->
                <div class="form-group required">
                    <label>Тип материала</label>
                    <div class="category-options">
                        <?php if (!empty($categories)): ?>
                            <?php foreach ($categories as $category): ?>
                                <div class="category-option <?php echo strtolower(str_replace(' ', '-', $category['name'])); ?>"
                                     onclick="selectCategory(<?php echo $category['id']; ?>)">
                                    <i class="<?php echo $category['icon']; ?>"></i>
                                    <span><?php echo htmlspecialchars($category['name']); ?></span>
                                    <input type="radio" name="category_id" value="<?php echo $category['id']; ?>" 
                                           style="display: none;" required>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p style="color: var(--gray); text-align: center; grid-column: 1 / -1;">
                                Категории не найдены
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Длительность -->
                <div class="form-group">
                    <label for="duration">Длительность/Объем</label>
                    <input type="text" id="duration" name="duration" class="form-control" 
                           value="<?php echo isset($_POST['duration']) ? htmlspecialchars($_POST['duration']) : ''; ?>" 
                           placeholder="Например: 45 мин, 30 стр., 2 часа">
                </div>
                
                <!-- Динамические поля для разных типов материалов -->
                <div id="video-fields" class="material-fields" style="display: none;">
                    <div class="form-group required">
                        <label>Видеофайл</label>
                        <div class="file-upload" onclick="document.getElementById('video_file').click()">
                            <i class="fas fa-video"></i>
                            <p>Нажмите для загрузки видеофайла</p>
                            <p class="file-info">MP4, MKV, AVI, MOV (макс. 100MB)</p>
                            <input type="file" id="video_file" name="video_file" accept="video/*">
                        </div>
                    </div>
                </div>
                
                <div id="pdf-fields" class="material-fields" style="display: none;">
                    <div class="form-group required">
                        <label>PDF файл</label>
                        <div class="file-upload" onclick="document.getElementById('pdf_file').click()">
                            <i class="fas fa-file-pdf"></i>
                            <p>Нажмите для загрузки PDF файла</p>
                            <p class="file-info">PDF (макс. 50MB)</p>
                            <input type="file" id="pdf_file" name="pdf_file" accept=".pdf">
                        </div>
                    </div>
                </div>
                
                <div id="interactive-fields" class="material-fields" style="display: none;">
                    <div class="form-group required">
                        <label>Файл задания</label>
                        <div class="file-upload" onclick="document.getElementById('interactive_file').click()">
                            <i class="fas fa-puzzle-piece"></i>
                            <p>Нажмите для загрузки файла задания</p>
                            <p class="file-info">ZIP архив (макс. 20MB)</p>
                            <input type="file" id="interactive_file" name="interactive_file" accept=".zip">
                        </div>
                    </div>
                </div>
                
                <div id="link-fields" class="material-fields" style="display: none;">
                    <div class="form-group required">
                        <label for="external_url">URL ресурса</label>
                        <input type="url" id="external_url" name="external_url" class="form-control" 
                               value="<?php echo isset($_POST['external_url']) ? htmlspecialchars($_POST['external_url']) : ''; ?>" 
                               placeholder="https://example.com">
                    </div>
                </div>
                
                <!-- Кнопки действий -->
                <div class="form-actions">
                    <a href="study_materials.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Назад к материалам
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-upload"></i> Загрузить материал
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Функция для переключения сайдбара
        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            const toggleBtn = document.querySelector('.toggle-sidebar i');
            
            sidebar.classList.toggle('collapsed');
            
            if (sidebar.classList.contains('collapsed')) {
                toggleBtn.className = 'fas fa-chevron-right';
            } else {
                toggleBtn.className = 'fas fa-chevron-left';
            }
        }

        // Выбор категории
        function selectCategory(categoryId) {
            // Убираем выделение со всех категорий
            document.querySelectorAll('.category-option').forEach(option => {
                option.classList.remove('selected');
            });
            
            // Выделяем выбранную категорию
            event.currentTarget.classList.add('selected');
            
            // Устанавливаем значение радиокнопки
            document.querySelector(`input[name="category_id"][value="${categoryId}"]`).checked = true;
            
            // Показываем соответствующие поля
            document.querySelectorAll('.material-fields').forEach(field => {
                field.style.display = 'none';
            });
            
            switch(categoryId) {
                case 1:
                    document.getElementById('video-fields').style.display = 'block';
                    break;
                case 2:
                    document.getElementById('pdf-fields').style.display = 'block';
                    break;
                case 3:
                    document.getElementById('interactive-fields').style.display = 'block';
                    break;
                case 4:
                    document.getElementById('link-fields').style.display = 'block';
                    break;
            }
        }
        
        // Обработка выбора файла
        document.querySelectorAll('input[type="file"]').forEach(input => {
            input.addEventListener('change', function() {
                if (this.files.length > 0) {
                    const file = this.files[0];
                    const fileSize = (file.size / 1024 / 1024).toFixed(2) + ' MB';
                    const fileName = file.name;
                    
                    const uploadDiv = this.parentElement;
                    uploadDiv.querySelector('p').textContent = `Выбран файл: ${fileName}`;
                    uploadDiv.querySelector('.file-info').textContent = `Размер: ${fileSize}`;
                    uploadDiv.style.borderColor = '#2ecc71';
                    uploadDiv.style.background = 'rgba(46, 204, 113, 0.1)';
                }
            });
        });
        
        // Валидация формы
        document.querySelector('form').addEventListener('submit', function(e) {
            const categoryId = document.querySelector('input[name="category_id"]:checked');
            if (!categoryId) {
                e.preventDefault();
                alert('Пожалуйста, выберите тип материала');
                return;
            }
        });

        // Мобильное меню для маленьких экранов
        function handleResize() {
            const sidebar = document.querySelector('.sidebar');
            if (window.innerWidth <= 768) {
                sidebar.classList.remove('collapsed');
            }
        }

        window.addEventListener('resize', handleResize);
        handleResize(); // Проверяем при загрузке
    </script>
</body>
</html>