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

// Создаем таблицы для системы сообщений, если они не существуют
$pdo->exec("
    CREATE TABLE IF NOT EXISTS chat_groups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        description TEXT,
        created_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (created_by) REFERENCES users(id)
    )
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS group_members (
        id INT AUTO_INCREMENT PRIMARY KEY,
        group_id INT NOT NULL,
        user_id INT NOT NULL,
        joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (group_id) REFERENCES chat_groups(id),
        FOREIGN KEY (user_id) REFERENCES users(id),
        UNIQUE KEY unique_membership (group_id, user_id)
    )
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sender_id INT NOT NULL,
        receiver_id INT NULL,
        group_id INT NULL,
        message TEXT NOT NULL,
        is_read BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (sender_id) REFERENCES users(id),
        FOREIGN KEY (receiver_id) REFERENCES users(id),
        FOREIGN KEY (group_id) REFERENCES chat_groups(id)
    )
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        title VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        is_read BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        expires_at TIMESTAMP NULL,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )
");

// Обработка отправки личного сообщения
if (isset($_POST['action']) && $_POST['action'] == 'send_private_message') {
    $receiver_id = $_POST['receiver_id'];
    $message = trim($_POST['message']);
    
    if (!empty($message) && !empty($receiver_id)) {
        $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, message) VALUES (?, ?, ?)");
        $stmt->execute([$_SESSION['user_id'], $receiver_id, $message]);
        
        // Создаем уведомление для получателя
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, created_at) VALUES (?, 'Новое сообщение', ?, NOW())");
        $stmt->execute([$receiver_id, "Вы получили новое сообщение от " . $user['full_name']]);
        
        $_SESSION['success'] = "Сообщение отправлено!";
    }
}

// Обработка отправки группового сообщения
if (isset($_POST['action']) && $_POST['action'] == 'send_group_message') {
    $group_id = $_POST['group_id'];
    $message = trim($_POST['message']);
    
    if (!empty($message) && !empty($group_id)) {
        $stmt = $pdo->prepare("INSERT INTO messages (sender_id, group_id, message) VALUES (?, ?, ?)");
        $stmt->execute([$_SESSION['user_id'], $group_id, $message]);
        
        // Получаем участников группы для уведомлений
        $stmt = $pdo->prepare("SELECT user_id FROM group_members WHERE group_id = ? AND user_id != ?");
        $stmt->execute([$group_id, $_SESSION['user_id']]);
        $members = $stmt->fetchAll();
        
        foreach ($members as $member) {
            $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, created_at) VALUES (?, 'Новое сообщение в группе', ?, NOW())");
            $stmt->execute([$member['user_id'], "Новое сообщение в групповом чате"]);
        }
        
        $_SESSION['success'] = "Сообщение отправлено в группу!";
    }
}

// Обработка создания новой группы
if (isset($_POST['action']) && $_POST['action'] == 'create_group') {
    $group_name = trim($_POST['group_name']);
    $group_description = trim($_POST['group_description']);
    $selected_users = $_POST['group_members'] ?? [];
    
    if (!empty($group_name)) {
        $stmt = $pdo->prepare("INSERT INTO chat_groups (name, description, created_by) VALUES (?, ?, ?)");
        $stmt->execute([$group_name, $group_description, $_SESSION['user_id']]);
        $group_id = $pdo->lastInsertId();
        
        // Добавляем создателя в группу
        $stmt = $pdo->prepare("INSERT INTO group_members (group_id, user_id) VALUES (?, ?)");
        $stmt->execute([$group_id, $_SESSION['user_id']]);
        
        // Добавляем выбранных пользователей
        foreach ($selected_users as $user_id) {
            $stmt = $pdo->prepare("INSERT INTO group_members (group_id, user_id) VALUES (?, ?)");
            $stmt->execute([$group_id, $user_id]);
        }
        
        $_SESSION['success'] = "Группа создана успешно!";
    }
}

// Получаем список пользователей для чатов
$stmt = $pdo->prepare("
    SELECT id, full_name, email, role 
    FROM users 
    WHERE id != ? 
    ORDER BY full_name
");
$stmt->execute([$_SESSION['user_id']]);
$users = $stmt->fetchAll();

// Получаем список групп пользователя
$stmt = $pdo->prepare("
    SELECT cg.*, u.full_name as creator_name
    FROM chat_groups cg
    JOIN group_members gm ON cg.id = gm.group_id
    JOIN users u ON cg.created_by = u.id
    WHERE gm.user_id = ?
    ORDER BY cg.created_at DESC
");
$stmt->execute([$_SESSION['user_id']]);
$user_groups = $stmt->fetchAll();

// Получаем непрочитанные ЛИЧНЫЕ сообщения
$unread_count = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as unread_count 
        FROM messages 
        WHERE receiver_id = ? AND is_read = FALSE
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $result = $stmt->fetch();
    $unread_count = $result ? $result['unread_count'] : 0;
} catch (PDOException $e) {
    error_log("Error counting unread messages: " . $e->getMessage());
    $unread_count = 0;
}

// Получаем историю личных сообщений
$personal_messages = [];
if (isset($_GET['chat_with'])) {
    $chat_with = $_GET['chat_with'];
    
    try {
        $stmt = $pdo->prepare("
            SELECT m.*, u1.full_name as sender_name, u2.full_name as receiver_name
            FROM messages m
            JOIN users u1 ON m.sender_id = u1.id
            LEFT JOIN users u2 ON m.receiver_id = u2.id
            WHERE (m.sender_id = ? AND m.receiver_id = ?) 
               OR (m.sender_id = ? AND m.receiver_id = ?)
            ORDER BY m.created_at DESC
            LIMIT 50
        ");
        $stmt->execute([$_SESSION['user_id'], $chat_with, $chat_with, $_SESSION['user_id']]);
        $personal_messages = array_reverse($stmt->fetchAll());
        
        // Помечаем сообщения как прочитанные
        $stmt = $pdo->prepare("
            UPDATE messages 
            SET is_read = TRUE 
            WHERE receiver_id = ? AND sender_id = ? AND is_read = FALSE
        ");
        $stmt->execute([$_SESSION['user_id'], $chat_with]);
    } catch (PDOException $e) {
        error_log("Error fetching personal messages: " . $e->getMessage());
    }
}

// Получаем историю групповых сообщений
$group_messages = [];
if (isset($_GET['group_chat'])) {
    $group_chat = $_GET['group_chat'];
    
    try {
        $stmt = $pdo->prepare("
            SELECT m.*, u.full_name as sender_name, cg.name as group_name
            FROM messages m
            JOIN users u ON m.sender_id = u.id
            JOIN chat_groups cg ON m.group_id = cg.id
            WHERE m.group_id = ?
            ORDER BY m.created_at DESC
            LIMIT 50
        ");
        $stmt->execute([$group_chat]);
        $group_messages = array_reverse($stmt->fetchAll());
    } catch (PDOException $e) {
        error_log("Error fetching group messages: " . $e->getMessage());
    }
}

// Получаем уведомления
$notifications = [];
try {
    $stmt = $pdo->prepare("
        SELECT * FROM notifications 
        WHERE user_id = ? AND (expires_at IS NULL OR expires_at > NOW())
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $notifications = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching notifications: " . $e->getMessage());
}

// Помечаем уведомления как прочитанные
if (!empty($notifications)) {
    try {
        $stmt = $pdo->prepare("
            UPDATE notifications SET is_read = 1 
            WHERE user_id = ? AND is_read = 0
        ");
        $stmt->execute([$_SESSION['user_id']]);
    } catch (PDOException $e) {
        error_log("Error updating notifications: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Сообщения - Система интеллектуальной оценки знаний</title>
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

        /* ОСНОВНОЙ КОНТЕНТ */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            padding: 20px;
            transition: margin-left 0.3s;
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
        
        .breadcrumb i {
            font-size: 12px;
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

        /* Messages Layout */
        .messages-layout {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 20px;
            height: calc(100vh - 200px);
        }

        @media (max-width: 992px) {
            .messages-layout {
                grid-template-columns: 1fr;
            }
        }

        /* Chat List */
        .chat-list {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        
        .chat-list-header {
            padding: 20px;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .chat-list-header h2 {
            font-size: 18px;
            color: var(--secondary);
        }
        
        .new-chat-btn {
            background: var(--primary);
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 14px;
        }
        
        .chat-tabs {
            display: flex;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .chat-tab {
            flex: 1;
            padding: 12px;
            text-align: center;
            background: none;
            border: none;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s;
        }
        
        .chat-tab.active {
            background: var(--light);
            color: var(--primary);
            border-bottom: 2px solid var(--primary);
        }
        
        .chat-items {
            flex: 1;
            overflow-y: auto;
        }
        
        .chat-item {
            padding: 15px 20px;
            border-bottom: 1px solid #f0f0f0;
            cursor: pointer;
            transition: background 0.3s;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .chat-item:hover {
            background: var(--light);
        }
        
        .chat-item.active {
            background: var(--primary);
            color: white;
        }
        
        .chat-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 14px;
        }
        
        .chat-info {
            flex: 1;
        }
        
        .chat-name {
            font-weight: 600;
            margin-bottom: 4px;
        }
        
        .chat-last-message {
            font-size: 12px;
            color: var(--gray);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .chat-item.active .chat-last-message {
            color: rgba(255,255,255,0.8);
        }
        
        .chat-meta {
            text-align: right;
        }
        
        .chat-time {
            font-size: 11px;
            color: var(--gray);
            margin-bottom: 4px;
        }
        
        .chat-item.active .chat-time {
            color: rgba(255,255,255,0.8);
        }
        
        .unread-badge {
            background: var(--accent);
            color: white;
            border-radius: 10px;
            padding: 2px 6px;
            font-size: 10px;
            font-weight: 600;
        }

        /* Chat Area */
        .chat-area {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        
        .chat-header {
            padding: 20px;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .chat-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--secondary);
        }
        
        .chat-status {
            font-size: 12px;
            color: var(--gray);
        }
        
        .messages-container {
            flex: 1;
            padding: 20px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 15px;
            max-height: 400px;
        }
        
        .message {
            max-width: 70%;
            padding: 12px 16px;
            border-radius: 18px;
            position: relative;
        }
        
        .message.sent {
            align-self: flex-end;
            background: var(--primary);
            color: white;
            border-bottom-right-radius: 4px;
        }
        
        .message.received {
            align-self: flex-start;
            background: var(--light);
            color: var(--secondary);
            border-bottom-left-radius: 4px;
        }
        
        .message-content {
            margin-bottom: 5px;
        }
        
        .message-time {
            font-size: 11px;
            opacity: 0.7;
            text-align: right;
        }
        
        .message-input {
            padding: 20px;
            border-top: 1px solid #e0e0e0;
            display: flex;
            gap: 10px;
        }
        
        .message-input textarea {
            flex: 1;
            padding: 12px 16px;
            border: 1px solid #e0e0e0;
            border-radius: 25px;
            resize: none;
            outline: none;
            font-family: inherit;
            font-size: 14px;
        }
        
        .send-btn {
            background: var(--primary);
            color: white;
            border: none;
            width: 45px;
            height: 45px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.3s;
        }
        
        .send-btn:hover {
            background: var(--primary-dark);
        }

        /* Modal */
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
            max-width: 500px;
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
            padding: 25px;
            max-height: calc(90vh - 80px);
            overflow-y: auto;
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
        
        .form-group select {
            height: 45px;
        }
        
        .checkbox-group {
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-height: 200px;
            overflow-y: auto;
        }
        
        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .checkbox-item input {
            width: auto;
        }
        
        .modal-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #e9ecef;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s;
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
            border: 2px solid var(--gray);
            color: var(--gray);
        }

        .btn-outline:hover {
            background: var(--gray);
            color: white;
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

        /* Back Button */
        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: var(--primary);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s;
            margin-bottom: 20px;
        }
        
        .back-button:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
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
            
            .header-actions {
                width: 100%;
                justify-content: space-between;
            }
            
            .message {
                max-width: 85%;
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
                <i class="fas fa-envelope"></i>
                <span>Непрочитанных: <?php echo $unread_count; ?></span>
            </div>
            <div class="stat-item">
                <i class="fas fa-users"></i>
                <span>Групп: <?php echo count($user_groups); ?></span>
            </div>
            <div class="stat-item">
                <i class="fas fa-comments"></i>
                <span>Контактов: <?php echo count($users); ?></span>
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
            <li><a href="messages.php" class="active"><i class="fas fa-comments"></i> Сообщения</a></li>
            
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
        <div class="container">
            <!-- Хлебные крошки -->
            <a href="dashboard.php" class="back-button">
                <i class="fas fa-arrow-left"></i>
                Назад к панели
            </a>

            <div class="header">
                <h1><i class="fas fa-comments"></i> Сообщения</h1>
                <div class="header-actions">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="messageSearch" placeholder="Поиск сообщений...">
                    </div>
                    <div class="notification-bell" onclick="toggleNotifications()">
                        <i class="fas fa-bell"></i>
                        <?php if (count($notifications) > 0): ?>
                            <span class="notification-badge"><?php echo count($notifications); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Сообщения об успехе/ошибке -->
            <?php if (isset($_SESSION['success'])): ?>
                <div style="background: var(--success); color: white; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                    <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div style="background: var(--danger); color: white; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                    <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <div class="messages-layout">
                <!-- Боковая панель с чатами -->
                <div class="chat-list">
                    <div class="chat-list-header">
                        <h2>Чаты</h2>
                        <button class="new-chat-btn" onclick="openNewChatModal()">
                            <i class="fas fa-plus"></i> Новый чат
                        </button>
                    </div>
                    
                    <div class="chat-tabs">
                        <button class="chat-tab active" onclick="switchTab('private')">Личные</button>
                        <button class="chat-tab" onclick="switchTab('groups')">Группы</button>
                    </div>
                    
                    <div class="chat-items" id="privateChats">
                        <?php if (count($users) > 0): ?>
                            <?php foreach ($users as $contact): ?>
                                <div class="chat-item <?php echo isset($_GET['chat_with']) && $_GET['chat_with'] == $contact['id'] ? 'active' : ''; ?>" 
                                     onclick="openPrivateChat(<?php echo $contact['id']; ?>)">
                                    <div class="chat-avatar">
                                        <?php echo htmlspecialchars(strtoupper(mb_substr($contact['full_name'], 0, 1))); ?>
                                    </div>
                                    <div class="chat-info">
                                        <div class="chat-name"><?php echo htmlspecialchars($contact['full_name']); ?></div>
                                        <div class="chat-last-message"><?php echo htmlspecialchars($contact['role']); ?></div>
                                    </div>
                                    <div class="chat-meta">
                                        <div class="chat-time">Сейчас</div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-users"></i>
                                <p>Нет доступных контактов</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="chat-items" id="groupChats" style="display: none;">
                        <?php if (count($user_groups) > 0): ?>
                            <?php foreach ($user_groups as $group): ?>
                                <div class="chat-item <?php echo isset($_GET['group_chat']) && $_GET['group_chat'] == $group['id'] ? 'active' : ''; ?>" 
                                     onclick="openGroupChat(<?php echo $group['id']; ?>)">
                                    <div class="chat-avatar" style="background: var(--warning);">
                                        <i class="fas fa-users"></i>
                                    </div>
                                    <div class="chat-info">
                                        <div class="chat-name"><?php echo htmlspecialchars($group['name']); ?></div>
                                        <div class="chat-last-message">Создатель: <?php echo htmlspecialchars($group['creator_name']); ?></div>
                                    </div>
                                    <div class="chat-meta">
                                        <div class="chat-time">Сейчас</div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-users"></i>
                                <p>Вы не состоите в группах</p>
                                <button class="new-chat-btn" onclick="openNewGroupModal()" style="margin-top: 10px;">
                                    <i class="fas fa-plus"></i> Создать группу
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Область чата -->
                <div class="chat-area">
                    <?php if (isset($_GET['chat_with']) || isset($_GET['group_chat'])): ?>
                        <div class="chat-header">
                            <div class="chat-avatar">
                                <?php if (isset($_GET['chat_with'])): ?>
                                    <?php 
                                    $chat_user = array_filter($users, function($u) { return $u['id'] == $_GET['chat_with']; });
                                    $chat_user = reset($chat_user);
                                    echo htmlspecialchars(strtoupper(mb_substr($chat_user['full_name'], 0, 1))); 
                                    ?>
                                <?php else: ?>
                                    <i class="fas fa-users"></i>
                                <?php endif; ?>
                            </div>
                            <div>
                                <div class="chat-title">
                                    <?php if (isset($_GET['chat_with'])): ?>
                                        <?php echo htmlspecialchars($chat_user['full_name']); ?>
                                    <?php else: ?>
                                        <?php 
                                        $chat_group = array_filter($user_groups, function($g) { return $g['id'] == $_GET['group_chat']; });
                                        $chat_group = reset($chat_group);
                                        echo htmlspecialchars($chat_group['name']); 
                                        ?>
                                    <?php endif; ?>
                                </div>
                                <div class="chat-status">
                                    <?php if (isset($_GET['chat_with'])): ?>
                                        <?php echo htmlspecialchars($chat_user['role']); ?>
                                    <?php else: ?>
                                        Групповой чат
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="messages-container" id="messagesContainer">
                            <?php if (isset($_GET['chat_with'])): ?>
                                <?php foreach ($personal_messages as $message): ?>
                                    <div class="message <?php echo $message['sender_id'] == $_SESSION['user_id'] ? 'sent' : 'received'; ?>">
                                        <div class="message-content"><?php echo htmlspecialchars($message['message']); ?></div>
                                        <div class="message-time">
                                            <?php echo date('H:i', strtotime($message['created_at'])); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <?php foreach ($group_messages as $message): ?>
                                    <div class="message <?php echo $message['sender_id'] == $_SESSION['user_id'] ? 'sent' : 'received'; ?>">
                                        <div class="message-content">
                                            <?php if ($message['sender_id'] != $_SESSION['user_id']): ?>
                                                <strong><?php echo htmlspecialchars($message['sender_name']); ?>:</strong> 
                                            <?php endif; ?>
                                            <?php echo htmlspecialchars($message['message']); ?>
                                        </div>
                                        <div class="message-time">
                                            <?php echo date('H:i', strtotime($message['created_at'])); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        
                        <form method="POST" class="message-input">
                            <input type="hidden" name="action" value="<?php echo isset($_GET['chat_with']) ? 'send_private_message' : 'send_group_message'; ?>">
                            <input type="hidden" name="<?php echo isset($_GET['chat_with']) ? 'receiver_id' : 'group_id'; ?>" 
                                   value="<?php echo isset($_GET['chat_with']) ? $_GET['chat_with'] : $_GET['group_chat']; ?>">
                            <textarea name="message" placeholder="Введите сообщение..." rows="1" required></textarea>
                            <button type="submit" class="send-btn">
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </form>
                    <?php else: ?>
                        <div class="empty-state" style="flex: 1; display: flex; align-items: center; justify-content: center;">
                            <div>
                                <i class="fas fa-comments" style="font-size: 64px;"></i>
                                <h3>Выберите чат для начала общения</h3>
                                <p>Начните новую беседу или выберите существующий чат</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Модальное окно нового чата -->
    <div class="modal-overlay" id="newChatModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-plus"></i> Новый чат</h2>
                <button class="modal-close" onclick="closeNewChatModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Выберите пользователя:</label>
                    <select id="newChatUser">
                        <option value="">-- Выберите пользователя --</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user['id']; ?>">
                                <?php echo htmlspecialchars($user['full_name']); ?> (<?php echo htmlspecialchars($user['role']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-outline" onclick="closeNewChatModal()">Отмена</button>
                    <button type="button" class="btn btn-primary" onclick="startNewChat()">Начать чат</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Модальное окно новой группы -->
    <div class="modal-overlay" id="newGroupModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-users"></i> Новая группа</h2>
                <button class="modal-close" onclick="closeNewGroupModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="create_group">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Название группы:</label>
                        <input type="text" name="group_name" required placeholder="Введите название группы">
                    </div>
                    <div class="form-group">
                        <label>Описание группы:</label>
                        <textarea name="group_description" placeholder="Введите описание группы"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Участники группы:</label>
                        <div class="checkbox-group">
                            <?php foreach ($users as $user): ?>
                                <div class="checkbox-item">
                                    <input type="checkbox" name="group_members[]" value="<?php echo $user['id']; ?>">
                                    <span><?php echo htmlspecialchars($user['full_name']); ?> (<?php echo htmlspecialchars($user['role']); ?>)</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-outline" onclick="closeNewGroupModal()">Отмена</button>
                        <button type="submit" class="btn btn-primary">Создать группу</button>
                    </div>
                </div>
            </form>
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

        // Переключение вкладок
        function switchTab(tab) {
            const privateChats = document.getElementById('privateChats');
            const groupChats = document.getElementById('groupChats');
            const tabs = document.querySelectorAll('.chat-tab');
            
            tabs.forEach(t => t.classList.remove('active'));
            event.target.classList.add('active');
            
            if (tab === 'private') {
                privateChats.style.display = 'block';
                groupChats.style.display = 'none';
            } else {
                privateChats.style.display = 'none';
                groupChats.style.display = 'block';
            }
        }

        // Открытие личного чата
        function openPrivateChat(userId) {
            window.location.href = 'messages.php?chat_with=' + userId;
        }

        // Открытие группового чата
        function openGroupChat(groupId) {
            window.location.href = 'messages.php?group_chat=' + groupId;
        }

        // Модальные окна
        function openNewChatModal() {
            document.getElementById('newChatModal').classList.add('active');
        }

        function closeNewChatModal() {
            document.getElementById('newChatModal').classList.remove('active');
        }

        function openNewGroupModal() {
            document.getElementById('newGroupModal').classList.add('active');
        }

        function closeNewGroupModal() {
            document.getElementById('newGroupModal').classList.remove('active');
        }

        // Начать новый чат
        function startNewChat() {
            const userId = document.getElementById('newChatUser').value;
            if (userId) {
                openPrivateChat(userId);
                closeNewChatModal();
            }
        }

        // Автоматическая прокрутка к новым сообщениям
        document.addEventListener('DOMContentLoaded', function() {
            const messagesContainer = document.getElementById('messagesContainer');
            if (messagesContainer) {
                messagesContainer.scrollTop = messagesContainer.scrollHeight;
            }
        });

        // Авто-расширение текстового поля
        const textarea = document.querySelector('.message-input textarea');
        if (textarea) {
            textarea.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = (this.scrollHeight) + 'px';
            });
        }

        function openHelp() {
            alert('Раздел помощи будет реализован позже');
        }

        function openFeedback() {
            alert('Форма обратной связи будет реализована позже');
        }

        // Закрытие модальных окон по ESC и клику вне окна
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeNewChatModal();
                closeNewGroupModal();
            }
        });

        document.querySelectorAll('.modal-overlay').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    modal.classList.remove('active');
                }
            });
        });
    </script>
</body>
</html>