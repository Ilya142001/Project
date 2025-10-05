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

// Добавляем поле avatar в таблицу users если его нет
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS avatar VARCHAR(255) DEFAULT 'default-avatar.png'");
} catch (PDOException $e) {
    // Поле уже существует
}

// Обработка изменения профиля
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['update_profile'])) {
        $full_name = $_POST['full_name'];
        $email = $_POST['email'];
        $phone = trim($_POST['phone'] ?? '');
        $bio = trim($_POST['bio'] ?? '');
        
        // Проверяем, не занят ли email другим пользователем
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$email, $_SESSION['user_id']]);
        
        if ($stmt->rowCount() > 0) {
            $_SESSION['error'] = "Email уже используется другим пользователем.";
        } else {
            // Обработка загрузки аватара
            $avatar = $user['avatar']; // сохраняем текущий аватар по умолчанию
            
            if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = 'uploads/avatars/';
                
                // Создаем директорию, если ее нет
                if (!file_exists($uploadDir)) {
                    if (!mkdir($uploadDir, 0755, true)) {
                        $_SESSION['error'] = "Не удалось создать директорию для загрузки.";
                    }
                }
                
                // Проверяем права на запись
                if (!isset($_SESSION['error']) && !is_writable($uploadDir)) {
                    $_SESSION['error'] = "Директория для загрузки не доступна для записи.";
                }
                
                if (!isset($_SESSION['error'])) {
                    $fileExtension = pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION);
                    $fileName = 'avatar_' . $_SESSION['user_id'] . '_' . time() . '.' . $fileExtension;
                    $uploadPath = $uploadDir . $fileName;
                    
                    // Проверяем тип файла
                    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/jpg'];
                    if (in_array($_FILES['avatar']['type'], $allowedTypes)) {
                        // Проверяем размер файла (максимум 2MB)
                        if ($_FILES['avatar']['size'] > 2 * 1024 * 1024) {
                            $_SESSION['error'] = "Размер файла не должен превышать 2MB.";
                        } else {
                            // Удаляем старый аватар, если он не дефолтный
                            if ($user['avatar'] && $user['avatar'] != 'default-avatar.png' && file_exists($user['avatar'])) {
                                unlink($user['avatar']);
                            }
                            
                            // Перемещаем загруженный файл
                            if (move_uploaded_file($_FILES['avatar']['tmp_name'], $uploadPath)) {
                                $avatar = $uploadPath;
                            } else {
                                $_SESSION['error'] = "Ошибка при загрузке изображения.";
                            }
                        }
                    } else {
                        $_SESSION['error'] = "Разрешены только файлы изображений (JPG, PNG, GIF).";
                    }
                }
            }
            
            if (!isset($_SESSION['error'])) {
                $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, phone = ?, bio = ?, avatar = ? WHERE id = ?");
                if ($stmt->execute([$full_name, $email, $phone, $bio, $avatar, $_SESSION['user_id']])) {
                    $_SESSION['success'] = "Профиль успешно обновлен.";
                    $_SESSION['user_name'] = $full_name;
                    $_SESSION['user_email'] = $email;
                    $_SESSION['user_avatar'] = $avatar;
                    
                    // Обновляем данные пользователя
                    $user['full_name'] = $full_name;
                    $user['email'] = $email;
                    $user['phone'] = $phone;
                    $user['bio'] = $bio;
                    $user['avatar'] = $avatar;
                } else {
                    $_SESSION['error'] = "Ошибка при обновлении профиля.";
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
            $_SESSION['success'] = "Аватар успешно удален.";
            $_SESSION['user_avatar'] = 'default-avatar.png';
            $user['avatar'] = 'default-avatar.png';
        } else {
            $_SESSION['error'] = "Ошибка при удалении аватара.";
        }
    }
    // Обработка изменения пароля
    elseif (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Проверяем текущий пароль
        if (!password_verify($current_password, $user['password'])) {
            $_SESSION['error'] = "Текущий пароль неверен.";
        } elseif ($new_password !== $confirm_password) {
            $_SESSION['error'] = "Новые пароли не совпадают.";
        } else {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            if ($stmt->execute([$hashed_password, $_SESSION['user_id']])) {
                $_SESSION['success'] = "Пароль успешно изменен.";
            } else {
                $_SESSION['error'] = "Ошибка при изменении пароля.";
            }
        }
    }
    
    header("Location: profile.php");
    exit;
}

// Получаем статистику пользователя
if ($user['role'] == 'student') {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_tests,
            AVG(percentage) as avg_score,
            MAX(percentage) as best_score,
            COUNT(CASE WHEN passed = 1 THEN 1 END) as passed_tests
        FROM test_results 
        WHERE user_id = ?
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $user_stats = $stmt->fetch();
    
    // Последние активности
    $stmt = $pdo->prepare("
        SELECT tr.*, t.title, t.subject
        FROM test_results tr
        JOIN tests t ON tr.test_id = t.id
        WHERE tr.user_id = ?
        ORDER BY tr.completed_at DESC
        LIMIT 5
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $recent_activities = $stmt->fetchAll();
} else {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as created_tests,
            (SELECT COUNT(*) FROM test_results WHERE test_id IN (
                SELECT id FROM tests WHERE created_by = ?
            )) as total_attempts,
            (SELECT COUNT(DISTINCT user_id) FROM test_results WHERE test_id IN (
                SELECT id FROM tests WHERE created_by = ?
            )) as unique_students
        FROM tests
        WHERE created_by = ?
    ");
    $stmt->execute([$_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']]);
    $user_stats = $stmt->fetch();
    
    // Последние созданные тесты
    $stmt = $pdo->prepare("
        SELECT t.*, 
               COUNT(tr.id) as attempts,
               AVG(tr.percentage) as avg_score
        FROM tests t
        LEFT JOIN test_results tr ON t.id = tr.test_id
        WHERE t.created_by = ?
        GROUP BY t.id
        ORDER BY t.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $recent_activities = $stmt->fetchAll();
}

// Получаем уведомления
$stmt = $pdo->prepare("
    SELECT COUNT(*) as unread_count 
    FROM notifications 
    WHERE user_id = ? AND is_read = 0
");
$stmt->execute([$_SESSION['user_id']]);
$unread_count = $stmt->fetch()['unread_count'];
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Профиль - Система интеллектуальной оценки знаний</title>
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
        }
        
        body {
            background-color: #f0f2f5;
            color: #333;
            line-height: 1.6;
            display: flex;
            min-height: 100vh;
        }

        /* СТИЛИ САЙДБАРА */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            height: 100vh;
            width: var(--sidebar-width);
            background: linear-gradient(135deg, var(--secondary) 0%, #34495e 100%);
            color: white;
            overflow-y: auto;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            box-shadow: 2px 0 15px rgba(0, 0, 0, 0.1);
        }

        .sidebar::-webkit-scrollbar {
            width: 0px;
        }

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
        }

        .role-badge {
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .role-admin { background: var(--accent); }
        .role-teacher { background: var(--warning); }
        .role-student { background: var(--primary); }

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

        .nav-links {
            list-style: none;
            flex: 1;
        }

        .nav-section {
            padding: 15px 20px 5px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
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

        .sidebar-footer {
            padding: 15px 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
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
        }

        /* ОСНОВНОЙ КОНТЕНТ */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .header h1 {
            font-size: 32px;
            color: var(--secondary);
            font-weight: 700;
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--gray);
            font-size: 14px;
        }
        
        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
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

        /* Профиль */
        .profile-layout {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 25px;
        }

        .profile-sidebar {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            text-align: center;
        }

        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 48px;
            color: white;
            margin: 0 auto 20px;
            font-weight: 600;
            overflow: hidden;
            position: relative;
        }

        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .avatar-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s;
            cursor: pointer;
        }

        .profile-avatar:hover .avatar-overlay {
            opacity: 1;
        }

        .avatar-overlay i {
            color: white;
            font-size: 24px;
        }

        .profile-name {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 10px;
            color: var(--secondary);
        }

        .profile-role {
            background: var(--primary);
            color: white;
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 500;
            display: inline-block;
            margin-bottom: 20px;
        }

        .profile-stats {
            margin: 25px 0;
        }

        .stat-card {
            background: var(--light);
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 10px;
            text-align: center;
        }

        .stat-number {
            font-size: 24px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 12px;
            color: var(--gray);
            text-transform: uppercase;
        }

        .profile-main {
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
        
        .section-header h2 i {
            color: var(--primary);
        }

        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--secondary);
        }
        
        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            border-color: var(--primary);
            outline: none;
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }

        .file-input {
            padding: 10px !important;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
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
        
        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-sm {
            padding: 8px 16px;
            font-size: 12px;
        }

        .tabs {
            display: flex;
            border-bottom: 2px solid #f0f0f0;
            margin-bottom: 25px;
        }

        .tab {
            padding: 12px 24px;
            background: none;
            border: none;
            cursor: pointer;
            font-weight: 500;
            color: var(--gray);
            border-bottom: 2px solid transparent;
            transition: all 0.3s;
        }

        .tab.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .activity-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .activity-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            background: var(--light);
            border-radius: 10px;
            border-left: 4px solid var(--primary);
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
        }

        .activity-content {
            flex: 1;
        }

        .activity-title {
            font-weight: 600;
            margin-bottom: 5px;
            color: var(--secondary);
        }

        .activity-meta {
            font-size: 12px;
            color: var(--gray);
        }

        .activity-score {
            font-weight: 600;
            font-size: 16px;
        }

        .score-excellent { color: var(--success); }
        .score-good { color: var(--warning); }
        .score-poor { color: var(--danger); }

        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .avatar-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 15px;
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

        /* Адаптивность */
        @media (max-width: 992px) {
            .profile-layout {
                grid-template-columns: 1fr;
            }
            
            .sidebar {
                transform: translateX(-100%);
            }
            
            .main-content {
                margin-left: 0;
            }
        }
    </style>
</head>
<body>
    <!-- Сайдбар -->
    <div class="sidebar">
        <div class="logo">
            <h1><i class="fas fa-graduation-cap"></i> EduAI System</h1>
        </div>

        <div class="user-info">
            <div class="user-avatar">
                <?php 
                $avatarPath = !empty($user['avatar']) ? $user['avatar'] : 'default-avatar.png';
                
                if (file_exists($avatarPath)) {
                    echo '<img src="' . $avatarPath . '" alt="Аватар">';
                } else {
                    $firstName = $user['full_name'];
                    if (function_exists('mb_convert_encoding')) {
                        $firstName = mb_convert_encoding($firstName, 'UTF-8', 'auto');
                    }
                    $firstLetter = mb_substr($firstName, 0, 1, 'UTF-8');
                    echo htmlspecialchars(strtoupper($firstLetter));
                }
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
                <i class="fas fa-envelope"></i>
                <span>Непрочитанных: <?php echo $unread_count; ?></span>
            </div>
        </div>

        <ul class="nav-links">
            <div class="nav-section">
                <div class="section-label">Основное</div>
            </div>
            <li><a href="dashboard.php"><i class="fas fa-home"></i> Главная</a></li>
            <li><a href="tests.php"><i class="fas fa-file-alt"></i> Тесты</a></li>
            <li><a href="results.php"><i class="fas fa-chart-bar"></i> Мои результаты</a></li>
            <li><a href="progress.php"><i class="fas fa-chart-line"></i> Мой прогресс</a></li>
            <li><a href="achievements.php"><i class="fas fa-trophy"></i> Мои достижения</a></li>
            <li><a href="skill_map.php"><i class="fas fa-map"></i> Карта навыков</a></li>
            <li><a href="messages.php"><i class="fas fa-comments"></i> Сообщения</a></li>
            
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

            <div class="nav-section">
                <div class="section-label">Система</div>
            </div>
            <li><a href="profile.php" class="active"><i class="fas fa-user-cog"></i> Профиль</a></li>
            <li><a href="settings.php"><i class="fas fa-cog"></i> Настройки</a></li>
            <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Выход</a></li>
        </ul>

        <div class="sidebar-footer">
            <div class="quick-actions">
                <button class="quick-btn" onclick="openHelp()">
                    <i class="fas fa-question-circle"></i> Помощь
                </button>
            </div>
        </div>
    </div>

    <!-- Основной контент -->
    <div class="main-content">
        <div class="container">
            <div class="header">
                <div>
                    <h1><i class="fas fa-user-cog"></i> Мой профиль</h1>
                    <div class="breadcrumb">
                        <a href="dashboard.php">Главная</a>
                        <i class="fas fa-chevron-right"></i>
                        <span>Профиль</span>
                    </div>
                </div>
                <div class="header-actions">
                    <div class="notification-bell">
                        <i class="fas fa-bell"></i>
                        <?php if ($unread_count > 0): ?>
                            <span class="notification-badge"><?php echo $unread_count; ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Сообщения об успехе/ошибке -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-error">
                    <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <div class="profile-layout">
                <!-- Боковая панель профиля -->
                <div class="profile-sidebar">
                    <div class="profile-avatar">
                        <?php 
                        $avatarPath = !empty($user['avatar']) ? $user['avatar'] : 'default-avatar.png';
                        
                        if (file_exists($avatarPath)) {
                            echo '<img src="' . $avatarPath . '" alt="Аватар" id="avatarPreview">';
                        } else {
                            $firstName = $user['full_name'];
                            if (function_exists('mb_convert_encoding')) {
                                $firstName = mb_convert_encoding($firstName, 'UTF-8', 'auto');
                            }
                            $firstLetter = mb_substr($firstName, 0, 1, 'UTF-8');
                            echo htmlspecialchars(strtoupper($firstLetter));
                        }
                        ?>
                        <div class="avatar-overlay" onclick="document.getElementById('avatarInput').click()">
                            <i class="fas fa-camera"></i>
                        </div>
                    </div>
                    
                    <div class="profile-name"><?php echo htmlspecialchars($user['full_name']); ?></div>
                    <div class="profile-role">
                        <?php echo $user['role'] == 'teacher' ? 'Преподаватель' : 
                               ($user['role'] == 'admin' ? 'Администратор' : 'Студент'); ?>
                    </div>
                    
                    <div class="avatar-actions">
                        <div class="avatar-upload-btn">
                            <button type="button" class="btn btn-primary btn-sm">
                                <i class="fas fa-upload"></i> Сменить фото
                            </button>
                            <input type="file" name="avatar" id="avatarInput" accept="image/*" onchange="previewAvatar(this)" form="profileForm">
                        </div>
                        <?php if ($user['avatar'] && $user['avatar'] != 'default-avatar.png'): ?>
                        <button type="submit" name="remove_avatar" value="1" class="btn btn-danger btn-sm" form="profileForm">
                            <i class="fas fa-trash"></i> Удалить
                        </button>
                        <?php endif; ?>
                    </div>

                    <p style="color: var(--gray); margin-bottom: 25px; font-size: 14px;">
                        <?php echo htmlspecialchars($user['email']); ?>
                    </p>

                    <div class="profile-stats">
                        <?php if ($user['role'] == 'student'): ?>
                            <div class="stat-card">
                                <div class="stat-number"><?php echo $user_stats['total_tests']; ?></div>
                                <div class="stat-label">Пройдено тестов</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-number"><?php echo round($user_stats['avg_score'], 1); ?>%</div>
                                <div class="stat-label">Средний результат</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-number"><?php echo $user_stats['passed_tests']; ?></div>
                                <div class="stat-label">Успешных тестов</div>
                            </div>
                        <?php else: ?>
                            <div class="stat-card">
                                <div class="stat-number"><?php echo $user_stats['created_tests']; ?></div>
                                <div class="stat-label">Создано тестов</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-number"><?php echo $user_stats['total_attempts']; ?></div>
                                <div class="stat-label">Всего попыток</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-number"><?php echo $user_stats['unique_students']; ?></div>
                                <div class="stat-label">Уникальных студентов</div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div style="font-size: 12px; color: var(--gray);">
                        <p>Зарегистрирован: <?php echo date('d.m.Y', strtotime($user['created_at'])); ?></p>
                    </div>
                </div>

                <!-- Основное содержимое профиля -->
                <div class="profile-main">
                    <div class="tabs">
                        <button class="tab active" onclick="switchTab('personal')">Личные данные</button>
                        <button class="tab" onclick="switchTab('security')">Безопасность</button>
                        <button class="tab" onclick="switchTab('activity')">Активность</button>
                    </div>

                    <!-- Вкладка личных данных -->
                    <div class="tab-content active" id="personal-tab">
                        <div class="section-header">
                            <h2><i class="fas fa-user-edit"></i> Редактирование профиля</h2>
                        </div>

                        <form method="POST" action="" enctype="multipart/form-data" id="profileForm">
                            <input type="hidden" name="update_profile" value="1">
                            
                            <div class="form-group">
                                <label for="full_name">ФИО *</label>
                                <input type="text" id="full_name" name="full_name" 
                                       value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="email">Email *</label>
                                <input type="email" id="email" name="email" 
                                       value="<?php echo htmlspecialchars($user['email']); ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="phone">Телефон</label>
                                <input type="tel" id="phone" name="phone" 
                                       value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                            </div>

                            <div class="form-group">
                                <label for="bio">О себе</label>
                                <textarea id="bio" name="bio" placeholder="Расскажите немного о себе..."><?php echo htmlspecialchars($user['bio'] ?? ''); ?></textarea>
                            </div>

                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Сохранить изменения
                            </button>
                        </form>
                    </div>

                    <!-- Вкладка безопасности -->
                    <div class="tab-content" id="security-tab">
                        <div class="section-header">
                            <h2><i class="fas fa-shield-alt"></i> Смена пароля</h2>
                        </div>

                        <form method="POST" action="">
                            <input type="hidden" name="change_password" value="1">
                            
                            <div class="form-group">
                                <label for="current_password">Текущий пароль *</label>
                                <input type="password" id="current_password" name="current_password" required>
                            </div>

                            <div class="form-group">
                                <label for="new_password">Новый пароль *</label>
                                <input type="password" id="new_password" name="new_password" required 
                                       minlength="6" placeholder="Минимум 6 символов">
                            </div>

                            <div class="form-group">
                                <label for="confirm_password">Подтверждение пароля *</label>
                                <input type="password" id="confirm_password" name="confirm_password" required>
                            </div>

                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-key"></i> Сменить пароль
                            </button>
                        </form>
                    </div>

                    <!-- Вкладка активности -->
                    <div class="tab-content" id="activity-tab">
                        <div class="section-header">
                            <h2><i class="fas fa-history"></i> Последняя активность</h2>
                        </div>

                        <div class="activity-list">
                            <?php if (!empty($recent_activities)): ?>
                                <?php foreach ($recent_activities as $activity): ?>
                                    <div class="activity-item">
                                        <div class="activity-icon">
                                            <i class="fas fa-<?php echo $user['role'] == 'student' ? 'file-alt' : 'edit'; ?>"></i>
                                        </div>
                                        <div class="activity-content">
                                            <div class="activity-title">
                                                <?php echo htmlspecialchars($activity['title']); ?>
                                                <?php if (isset($activity['subject'])): ?>
                                                    <span style="color: var(--primary); font-size: 12px;">
                                                        (<?php echo htmlspecialchars($activity['subject']); ?>)
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="activity-meta">
                                                <?php if ($user['role'] == 'student'): ?>
                                                    Завершено: <?php echo date('d.m.Y H:i', strtotime($activity['completed_at'])); ?>
                                                    <?php if (isset($activity['percentage'])): ?>
                                                        • 
                                                        <span class="activity-score <?php 
                                                            echo $activity['percentage'] >= 80 ? 'score-excellent' : 
                                                                 ($activity['percentage'] >= 60 ? 'score-good' : 'score-poor'); 
                                                        ?>">
                                                            <?php echo round($activity['percentage'], 1); ?>%
                                                        </span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    Создан: <?php echo date('d.m.Y', strtotime($activity['created_at'])); ?>
                                                    <?php if (isset($activity['attempts'])): ?>
                                                        • Попыток: <?php echo $activity['attempts']; ?>
                                                    <?php endif; ?>
                                                    <?php if (isset($activity['avg_score'])): ?>
                                                        • Средний результат: <?php echo round($activity['avg_score'], 1); ?>%
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div style="text-align: center; padding: 40px; color: var(--gray);">
                                    <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 15px; opacity: 0.5;"></i>
                                    <p>Активность не найдена</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function switchTab(tabName) {
            // Скрываем все вкладки
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Убираем активный класс со всех кнопок
            document.querySelectorAll('.tab').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Показываем выбранную вкладку
            document.getElementById(tabName + '-tab').classList.add('active');
            
            // Активируем кнопку
            event.target.classList.add('active');
        }

        function openHelp() {
            alert('Раздел помощи будет реализован позже');
        }

        // Предпросмотр аватара
        function previewAvatar(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('avatarPreview').src = e.target.result;
                }
                reader.readAsDataURL(input.files[0]);
                
                // Автоматически отправляем форму при выборе файла
                document.getElementById('profileForm').submit();
            }
        }

        // Валидация пароля в реальном времени
        document.addEventListener('DOMContentLoaded', function() {
            const newPassword = document.getElementById('new_password');
            const confirmPassword = document.getElementById('confirm_password');
            
            function validatePassword() {
                if (newPassword.value !== confirmPassword.value) {
                    confirmPassword.style.borderColor = 'var(--danger)';
                } else {
                    confirmPassword.style.borderColor = 'var(--success)';
                }
            }
            
            if (newPassword && confirmPassword) {
                newPassword.addEventListener('input', validatePassword);
                confirmPassword.addEventListener('input', validatePassword);
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
        });

        // Функция для принудительного обновления аватара
        function refreshAvatar() {
            const avatar = document.querySelector('.profile-avatar img');
            if (avatar) {
                avatar.src = avatar.src + '?t=' + new Date().getTime();
            }
        }
    </script>
</body>
</html>