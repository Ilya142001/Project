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

// Обработка изменения профиля
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['update_profile'])) {
        $full_name = $_POST['full_name'];
        $email = $_POST['email'];
        
        // Проверяем, не занят ли email другим пользователем
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$email, $_SESSION['user_id']]);
        
        if ($stmt->rowCount() > 0) {
            $error = "Email уже используется другим пользователем.";
        } else {
            // Обработка загрузки аватара
            $avatar = $user['avatar']; // сохраняем текущий аватар по умолчанию
            
            if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = 'uploads/avatars/';
                
                // Создаем директорию, если ее нет
                if (!file_exists($uploadDir)) {
                    if (!mkdir($uploadDir, 0755, true)) {
                        $error = "Не удалось создать директорию для загрузки.";
                    }
                }
                
                // Проверяем права на запись
                if (!isset($error) && !is_writable($uploadDir)) {
                    $error = "Директория для загрузки не доступна для записи.";
                }
                
                if (!isset($error)) {
                    $fileExtension = pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION);
                    $fileName = 'avatar_' . $_SESSION['user_id'] . '_' . time() . '.' . $fileExtension;
                    $uploadPath = $uploadDir . $fileName;
                    
                    // Проверяем тип файла
                    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/jpg'];
                    if (in_array($_FILES['avatar']['type'], $allowedTypes)) {
                        // Проверяем размер файла (максимум 2MB)
                        if ($_FILES['avatar']['size'] > 2 * 1024 * 1024) {
                            $error = "Размер файла не должен превышать 2MB.";
                        } else {
                            // Удаляем старый аватар, если он не дефолтный
                            if ($user['avatar'] && $user['avatar'] != 'default-avatar.png' && file_exists($user['avatar'])) {
                                unlink($user['avatar']);
                            }
                            
                            // Перемещаем загруженный файл
                            if (move_uploaded_file($_FILES['avatar']['tmp_name'], $uploadPath)) {
                                $avatar = $uploadPath;
                            } else {
                                $error = "Ошибка при загрузке изображения.";
                            }
                        }
                    } else {
                        $error = "Разрешены только файлы изображений (JPG, PNG, GIF).";
                    }
                }
            }
            
            if (!isset($error)) {
                $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, avatar = ? WHERE id = ?");
                if ($stmt->execute([$full_name, $email, $avatar, $_SESSION['user_id']])) {
                    $success = "Профиль успешно обновлен.";
                    $_SESSION['user_name'] = $full_name;
                    $_SESSION['user_email'] = $email;
                    $_SESSION['user_avatar'] = $avatar;
                    
                    // Обновляем данные пользователя
                    $user['full_name'] = $full_name;
                    $user['email'] = $email;
                    $user['avatar'] = $avatar;
                } else {
                    $error = "Ошибка при обновлении профиля.";
                }
            }
        }
    }
    // Обработка удаления аватара
    elseif (isset($_POST['remove_avatar'])) {
        if ($user['avatar'] && $user['avatar'] != 'default-avatar.png' && file_exists($user['avatar'])) {
            unlink($user['avatar']);
        }
        
        $stmt = $pdo->prepare("UPDATE users SET avatar = 'default-avatar.png' WHERE id = ?");
        if ($stmt->execute([$_SESSION['user_id']])) {
            $success = "Аватар успешно удален.";
            $_SESSION['user_avatar'] = 'default-avatar.png';
            $user['avatar'] = 'default-avatar.png';
        } else {
            $error = "Ошибка при удалении аватара.";
        }
    }
    // Обработка изменения пароля
    elseif (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Проверяем текущий пароль
        if (!password_verify($current_password, $user['password'])) {
            $error = "Текущий пароль неверен.";
        } elseif ($new_password !== $confirm_password) {
            $error = "Новые пароли не совпадают.";
        } else {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            if ($stmt->execute([$hashed_password, $_SESSION['user_id']])) {
                $success = "Пароль успешно изменен.";
            } else {
                $error = "Ошибка при изменении пароля.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Настройки - Система интеллектуальной оценки знаний</title>
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
        
        /* Forms */
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--secondary);
        }
        
        .form-group input, .form-group select {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 16px;
            transition: border-color 0.3s;
        }
        
        .form-group input:focus, .form-group select:focus {
            border-color: var(--primary);
            outline: none;
        }
        
        .btn {
            padding: 12px 20px;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
        }
        
        .btn-small {
            padding: 8px 15px;
            font-size: 14px;
        }
        
        .btn-danger {
            background: var(--accent);
            color: white;
        }
        
        .btn-danger:hover {
            background: #c0392b;
        }
        
        /* Messages */
        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: rgba(46, 204, 113, 0.2);
            color: var(--success);
            border: 1px solid rgba(46, 204, 113, 0.3);
        }
        
        .alert-error {
            background: rgba(231, 76, 60, 0.2);
            color: var(--accent);
            border: 1px solid rgba(231, 76, 60, 0.3);
        }
        
        /* Settings tabs */
        .settings-tabs {
            display: flex;
            border-bottom: 1px solid #eee;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .tab {
            padding: 10px 20px;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            transition: all 0.3s;
        }
        
        .tab.active {
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
        
        /* Новые стили для загрузки аватара */
        .avatar-upload {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .avatar-preview {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            overflow: hidden;
            margin-right: 20px;
            border: 3px solid #e2e8f0;
            position: relative;
        }
        
        .avatar-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .avatar-actions {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .avatar-upload-btn {
            position: relative;
            overflow: hidden;
            display: inline-block;
        }
        
        .avatar-upload-btn input[type="file"] {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }
        
        /* Form controls */
        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 16px;
            transition: border-color 0.3s;
        }
        
        .form-control:focus {
            border-color: var(--primary);
            outline: none;
        }
        
        /* Checkbox styles */
        input[type="checkbox"] {
            margin-right: 8px;
            width: 18px;
            height: 18px;
            vertical-align: middle;
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
            
            .avatar-upload {
                flex-direction: column;
                text-align: center;
            }
            
            .avatar-preview {
                margin-right: 0;
                margin-bottom: 15px;
            }
        }
        
        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .settings-tabs {
                flex-direction: column;
            }
            
            .tab {
                border-bottom: 1px solid #eee;
            }
            
            .avatar-actions {
                flex-direction: row;
                justify-content: center;
                flex-wrap: wrap;
            }
            
            .main-content {
                margin-left: 0;
                padding: 15px;
            }
            
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s;
                z-index: 1000;
            }
            
            .sidebar.active {
                transform: translateX(0);
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
                $avatarPath = !empty($user['avatar']) ? $user['avatar'] : 'default-avatar.png';
                
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
                    $firstName = $nameParts[1] ?? $nameParts[0];
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
            <li><a href="ml_models.php"><i class="fas fa-robot"></i> <span>ML модели</span></a></li>
            <li><a href="settings.php" class="active"><i class="fas fa-cog"></i> <span>Настройки</span></a></li>
            <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> <span>Выход</span></a></li>
        </ul>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
        <div class="header">
            <div class="welcome">
                <h2><i class="fas fa-cog"></i> Настройки системы</h2>
                <p>Управление профилем и настройками аккаунта</p>
            </div>
        </div>
        
        <!-- Сообщения -->
        <?php if (isset($success)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo $success; ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <!-- Табы настроек -->
        <div class="settings-tabs">
            <div class="tab active" data-tab="profile">Профиль</div>
            <div class="tab" data-tab="password">Безопасность</div>
            <div class="tab" data-tab="preferences">Предпочтения</div>
            <?php if ($user['role'] == 'admin'): ?>
            <div class="tab" data-tab="system">Системные настройки</div>
            <?php endif; ?>
        </div>
        
        <!-- Содержимое табов -->
        <div class="tab-content active" id="profileTab">
            <div class="section">
                <div class="section-header">
                    <h2>Личная информация</h2>
                </div>
                
                <form method="POST" action="" enctype="multipart/form-data">
                    <input type="hidden" name="update_profile" value="1">
                    
                    <div class="avatar-upload">
                        <div class="avatar-preview">
                            <img src="<?php echo !empty($user['avatar']) ? $user['avatar'] : 'default-avatar.png'; ?>" alt="Аватар" id="avatarPreview">
                        </div>
                        <div class="avatar-actions">
                            <div class="avatar-upload-btn">
                                <button type="button" class="btn btn-primary btn-small">
                                    <i class="fas fa-upload"></i> Выбрать фото
                                </button>
                                <input type="file" name="avatar" id="avatarInput" accept="image/*" onchange="previewAvatar(this)">
                            </div>
                            <?php if ($user['avatar'] && $user['avatar'] != 'default-avatar.png'): ?>
                            <button type="submit" name="remove_avatar" value="1" class="btn btn-danger btn-small">
                                <i class="fas fa-trash"></i> Удалить фото
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="full_name">Полное имя</label>
                        <input type="text" id="full_name" name="full_name" value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="email">Email адрес</label>
                        <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="role">Роль</label>
                        <input type="text" id="role" value="<?php 
                            if ($user['role'] == 'admin') echo 'Администратор';
                            else if ($user['role'] == 'teacher') echo 'Преподаватель';
                            else echo 'Студент';
                        ?>" disabled>
                    </div>
                    
                    <div class="form-group">
                        <label for="created_at">Дата регистрации</label>
                        <input type="text" id="created_at" value="<?php echo date('d.m.Y H:i', strtotime($user['created_at'])); ?>" disabled>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Сохранить изменения</button>
                </form>
            </div>
        </div>
        
        <div class="tab-content" id="passwordTab">
            <div class="section">
                <div class="section-header">
                    <h2>Смена пароля</h2>
                </div>
                
                <form method="POST" action="">
                    <input type="hidden" name="change_password" value="1">
                    
                    <div class="form-group">
                        <label for="current_password">Текущий пароль</label>
                        <input type="password" id="current_password" name="current_password" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="new_password">Новый пароль</label>
                        <input type="password" id="new_password" name="new_password" required minlength="6">
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password">Подтвердите новый пароль</label>
                        <input type="password" id="confirm_password" name="confirm_password" required minlength="6">
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Сменить пароль</button>
                </form>
            </div>
        </div>
        
        <div class="tab-content" id="preferencesTab">
            <div class="section">
                <div class="section-header">
                    <h2>Настройки отображения</h2>
                </div>
                
                <form id="preferencesForm">
                    <div class="form-group">
                        <label for="theme">Тема интерфейса</label>
                        <select id="theme" name="theme" class="form-control">
                            <option value="light">Светлая</option>
                            <option value="dark">Темная</option>
                            <option value="auto">Системная</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="language">Язык интерфейса</label>
                        <select id="language" name="language" class="form-control">
                            <option value="ru">Русский</option>
                            <option value="en">English</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="notifications">Уведомления</label>
                        <div>
                            <input type="checkbox" id="email_notifications" name="email_notifications" checked>
                            <label for="email_notifications">Email уведомления</label>
                        </div>
                        <div>
                            <input type="checkbox" id="result_notifications" name="result_notifications" checked>
                            <label for="result_notifications">Уведомления о результатах</label>
                        </div>
                    </div>
                    
                    <button type="button" class="btn btn-primary" onclick="savePreferences()">Сохранить настройки</button>
                </form>
            </div>
        </div>
        
        <?php if ($user['role'] == 'admin'): ?>
        <div class="tab-content" id="systemTab">
            <div class="section">
                <div class="section-header">
                    <h2>Системные настройки</h2>
                </div>
                
                <form id="systemForm">
                    <div class="form-group">
                        <label for="system_name">Название системы</label>
                        <input type="text" id="system_name" name="system_name" value="Система интеллектуальной оценки знаний" class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label for="max_file_size">Максимальный размер файлов (МБ)</label>
                        <input type="number" id="max_file_size" name="max_file_size" value="10" min="1" max="100" class="form-control">
                    </div>
                    
                                        <div class="form-group">
                        <label for="session_timeout">Таймаут сессии (минуты)</label>
                        <input type="number" id="session_timeout" name="session_timeout" value="30" min="5" max="240" class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label for="registration_allowed">Регистрация новых пользователей</label>
                        <select id="registration_allowed" name="registration_allowed" class="form-control">
                            <option value="1">Разрешена</option>
                            <option value="0">Запрещена</option>
                        </select>
                    </div>
                    
                    <button type="button" class="btn btn-primary" onclick="saveSystemSettings()">Сохранить системные настройки</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
        // Управление табами
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', function() {
                // Деактивируем все табы
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                
                // Активируем выбранный таб
                this.classList.add('active');
                const tabId = this.getAttribute('data-tab') + 'Tab';
                document.getElementById(tabId).classList.add('active');
            });
        });
        
        // Предпросмотр аватара
        function previewAvatar(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('avatarPreview').src = e.target.result;
                }
                reader.readAsDataURL(input.files[0]);
            }
        }
        
        // Сохранение предпочтений
        function savePreferences() {
            const formData = new FormData(document.getElementById('preferencesForm'));
            alert('Настройки сохранены!');
            // Здесь будет AJAX запрос для сохранения настроек
        }
        
        // Сохранение системных настроек
        function saveSystemSettings() {
            const formData = new FormData(document.getElementById('systemForm'));
            alert('Системные настройки сохранены!');
            // Здесь будет AJAX запрос для сохранения системных настроек
        }
        
        // Валидация паролей
        const passwordForm = document.querySelector('form[action=""]');
        if (passwordForm) {
            passwordForm.addEventListener('submit', function(e) {
                const newPassword = document.getElementById('new_password');
                const confirmPassword = document.getElementById('confirm_password');
                
                if (newPassword && confirmPassword && newPassword.value !== confirmPassword.value) {
                    e.preventDefault();
                    alert('Пароли не совпадают!');
                    confirmPassword.focus();
                }
            });
        }

        // Обработка ошибок загрузки аватара
        const avatarInput = document.getElementById('avatarInput');
        if (avatarInput) {
            avatarInput.addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (file) {
                    // Проверка размера файла
                    if (file.size > 2 * 1024 * 1024) {
                        alert('Размер файла не должен превышать 2MB');
                        this.value = '';
                        return;
                    }
                    
                    // Проверка типа файла
                    const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/jpg'];
                    if (!allowedTypes.includes(file.type)) {
                        alert('Разрешены только файлы изображений (JPG, PNG, GIF)');
                        this.value = '';
                        return;
                    }
                }
            });
        }

        // Мобильное меню
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('active');
        }

        // Закрытие sidebar при клике вне его
        document.addEventListener('click', function(e) {
            const sidebar = document.querySelector('.sidebar');
            const menuBtn = document.querySelector('.menu-btn');
            
            if (sidebar.classList.contains('active') && 
                !sidebar.contains(e.target) && 
                !menuBtn.contains(e.target)) {
                sidebar.classList.remove('active');
            }
        });

        // Добавляем кнопку меню для мобильных устройств
        const header = document.querySelector('.header');
        if (header && window.innerWidth <= 768) {
            const menuBtn = document.createElement('button');
            menuBtn.innerHTML = '<i class="fas fa-bars"></i>';
            menuBtn.classList.add('menu-btn');
            menuBtn.style.background = 'var(--primary)';
            menuBtn.style.color = 'white';
            menuBtn.style.border = 'none';
            menuBtn.style.borderRadius = '5px';
            menuBtn.style.padding = '10px';
            menuBtn.style.marginRight = '15px';
            menuBtn.style.cursor = 'pointer';
            menuBtn.onclick = toggleSidebar;
            
            header.insertBefore(menuBtn, header.firstChild);
        }
    </script>
</body>
</html>