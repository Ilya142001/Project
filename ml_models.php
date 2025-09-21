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

// Данные для GigaChat API
$GIGA_CHAT_CLIENT_ID = "c33a9773-16da-4d9d-a268-21a3c149fa07";
$GIGA_CHAT_AUTH_KEY = "YzMzYTk3NzMtMTZkYS00ZDlkLWEyNjgtMjFhM2MxNDlmYTA3OjQ4NzU4MTQxLWQ5MDYtNGEwYS04YmJlLTkxNWQ2MmFjMTRiNQ==";
$GIGA_CHAT_SCOPE = "GIGACHAT_API_PERS";

// Путь к сертификатам НУЦ Минцифры (замените на реальный путь)
$RUSSIAN_TRUSTED_ROOT_CA = "C:\OSPanel\domains\Project\Cert\russian_trusted_root_ca.cer";
$RUSSIAN_TRUSTED_SUB_CA = "C:\OSPanel\domains\Project\Cert\russian_trusted_root_ca_gost_2025.cer";

// Функция для получения access token с поддержкой сертификатов
function getGigaChatToken() {
    global $GIGA_CHAT_CLIENT_ID, $GIGA_CHAT_AUTH_KEY, $GIGA_CHAT_SCOPE;
    global $RUSSIAN_TRUSTED_ROOT_CA, $RUSSIAN_TRUSTED_SUB_CA;
    
    $url = "https://ngw.devices.sberbank.ru:9443/api/v2/oauth";
    
    $headers = [
        "Authorization: Basic " . $GIGA_CHAT_AUTH_KEY,
        "RqUID: " . uniqid(),
        "Content-Type: application/x-www-form-urlencoded"
    ];
    
    $data = "scope=" . $GIGA_CHAT_SCOPE;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    // Настройки для работы с сертификатами НУЦ Минцифры
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    
    // Указываем пути к сертификатам
    if (file_exists($RUSSIAN_TRUSTED_ROOT_CA) && file_exists($RUSSIAN_TRUSTED_SUB_CA)) {
        curl_setopt($ch, CURLOPT_CAINFO, $RUSSIAN_TRUSTED_ROOT_CA);
        curl_setopt($ch, CURLOPT_CAPATH, dirname($RUSSIAN_TRUSTED_ROOT_CA));
    } else {
        // Альтернативный вариант - использовать системные сертификаты
        // если они уже установлены на уровне ОС
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    }
    
    // Дополнительные настройки для отладки (можно убрать в продакшене)
    curl_setopt($ch, CURLOPT_VERBOSE, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    
    if (curl_errno($ch)) {
        $error_msg = "Ошибка CURL: " . curl_error($ch);
        curl_close($ch);
        return $error_msg;
    }
    
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        return "Ошибка HTTP $httpCode: Не удалось получить токен доступа";
    }
    
    $result = json_decode($response, true);
    return $result['access_token'] ?? "Ошибка: Токен не найден в ответе";
}

// Альтернативная функция получения токена (если сертификаты не установлены)
function getGigaChatTokenAlternative() {
    global $GIGA_CHAT_CLIENT_ID, $GIGA_CHAT_AUTH_KEY, $GIGA_CHAT_SCOPE;
    
    $url = "https://ngw.devices.sberbank.ru:9443/api/v2/oauth";
    
    $headers = [
        "Authorization: Basic " . $GIGA_CHAT_AUTH_KEY,
        "RqUID: " . uniqid(),
        "Content-Type: application/x-www-form-urlencoded"
    ];
    
    $data = "scope=" . $GIGA_CHAT_SCOPE;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    // Отключаем проверку SSL (НЕ рекомендуется для продакшена!)
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    
    if (curl_errno($ch)) {
        $error_msg = "Ошибка CURL: " . curl_error($ch);
        curl_close($ch);
        return $error_msg;
    }
    
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        return "Ошибка HTTP $httpCode: Не удалось получить токен доступа";
    }
    
    $result = json_decode($response, true);
    return $result['access_token'] ?? "Ошибка: Токен не найден в ответе";
}

// Функция для отправки сообщения в GigaChat
function sendToGigaChat($message, $conversationHistory = []) {
    // Пытаемся получить токен с проверкой сертификатов
    $token = getGigaChatToken();
    
    // Если возникает ошибка с сертификатами, пробуем альтернативный метод
    if (strpos($token, 'Ошибка') !== false || strpos($token, 'CURL') !== false) {
        $token = getGigaChatTokenAlternative();
    }
    
    // Если токен содержит текст ошибки, возвращаем его
    if (strpos($token, 'Ошибка') === 0) {
        return $token;
    }
    
    if (!$token) return "Ошибка: Не удалось получить токен доступа";
    
    $url = "https://gigachat.devices.sberbank.ru/api/v1/chat/completions";
    
    // Формируем историю сообщений
    $messages = [];
    
    // Добавляем системное сообщение для контекста
    $messages[] = [
        "role" => "system",
        "content" => "Ты - AI-ассистент в системе интеллектуальной оценки знаний. Ты помогаешь преподавателям и студентам с вопросами об образовании, тестировании и анализе результатов."
    ];
    
    // Добавляем историю conversation
    foreach ($conversationHistory as $msg) {
        $messages[] = $msg;
    }
    
    // Добавляем текущее сообщение пользователя
    $messages[] = [
        "role" => "user",
        "content" => $message
    ];
    
    $data = [
        "model" => "GigaChat",
        "messages" => $messages,
        "temperature" => 0.7,
        "max_tokens" => 1024
    ];
    
    $headers = [
        "Authorization: Bearer " . $token,
        "Content-Type: application/json"
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    // Настройки SSL для запроса к API
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Временно отключаем для тестирования
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($response === false) {
        return "Ошибка CURL при отправке сообщения: " . $error;
    }
    
    $result = json_decode($response, true);
    
    if ($httpCode !== 200) {
        return "Ошибка HTTP $httpCode от GigaChat: " . json_encode($result);
    }
    
    if (isset($result['choices'][0]['message']['content'])) {
        return $result['choices'][0]['message']['content'];
    } else {
        return "Ошибка: Не удалось получить ответ от GigaChat. Ответ сервера: " . json_encode($result);
    }
}

// Функция для проверки начала строки (аналог str_starts_with для старых версий PHP)
function startsWith($haystack, $needle) {
    return substr($haystack, 0, strlen($needle)) === $needle;
}

// Обработка очистки чата
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_chat'])) {
    $_SESSION['gigachat_history'] = [];
    header("Location: ml_models.php");
    exit;
}

// Обработка запроса к GigaChat
$chatResponse = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['chat_message'])) {
    $userMessage = trim($_POST['chat_message']);
    
    if (!empty($userMessage)) {
        // Получаем историю conversation из сессии
        $conversationHistory = $_SESSION['gigachat_history'] ?? [];
        
        // Добавляем сообщение пользователя в историю
        $conversationHistory[] = [
            "role" => "user",
            "content" => $userMessage
        ];
        
        // Отправляем запрос к GigaChat
        $chatResponse = sendToGigaChat($userMessage, $conversationHistory);
        
        // Добавляем ответ ассистента в историю
        if (!startsWith($chatResponse, "Ошибка:")) {
            $conversationHistory[] = [
                "role" => "assistant",
                "content" => $chatResponse
            ];
        }
        
        // Сохраняем обновленную историю в сессии (ограничиваем размер)
        if (count($conversationHistory) > 10) {
            $conversationHistory = array_slice($conversationHistory, -10);
        }
        $_SESSION['gigachat_history'] = $conversationHistory;
    }
}

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
        
        /* Стили для чата GigaChat */
        .chat-container {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 20px;
            height: 500px;
            display: flex;
            flex-direction: column;
        }
        
        .chat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }
        
        .chat-header h2 {
            font-size: 18px;
            color: var(--secondary);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 10px;
            margin-bottom: 15px;
            background: var(--light);
            border-radius: 8px;
        }
        
        .message {
            margin-bottom: 15px;
            padding: 10px 15px;
            border-radius: 8px;
            max-width: 80%;
        }
        
        .message.user {
            background: var(--primary);
            color: white;
            margin-left: auto;
        }
        
        .message.assistant {
            background: #e3e3e3;
            color: #333;
            margin-right: auto;
        }
        
        .message.error {
            background: rgba(231, 76, 60, 0.1);
            color: var(--accent);
            border-left: 3px solid var(--accent);
        }
        
        .chat-input {
            display: flex;
            gap: 10px;
        }
        
        .chat-input input {
            flex: 1;
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 20px;
            outline: none;
        }
        
        .chat-input button {
            padding: 10px 20px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 20px;
            cursor: pointer;
        }
        
        .chat-input button:hover {
            background: var(--primary-dark);
        }
        
        .clear-chat {
            background: var(--accent) !important;
            margin-left: 10px;
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
        }
        
        @media (max-width: 768px) {
            .chat-container {
                height: 400px;
            }
            
            .message {
                max-width: 90%;
            }
            
            .model-list {
                grid-template-columns: 1fr;
            }
            
            .stats-cards {
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
        
        <!-- Чат с GigaChat -->
        <div class="chat-container">
            <div class="chat-header">
                <h2><i class="fas fa-comments"></i> AI-ассистент GigaChat</h2>
                <form method="POST" style="display: inline;">
                    <button type="submit" name="clear_chat" class="btn btn-danger clear-chat">Очистить чат</button>
                </form>
            </div>
            
            <div class="chat-messages" id="chatMessages">
                <?php
                // Показываем историю переписки
                $conversationHistory = $_SESSION['gigachat_history'] ?? [];
                foreach ($conversationHistory as $message) {
                    $class = $message['role'] == 'user' ? 'user' : 'assistant';
                    $icon = $message['role'] == 'user' ? 'fas fa-user' : 'fas fa-robot';
                    echo '<div class="message ' . $class . '">';
                    echo '<div><i class="' . $icon . '"></i> ' . nl2br(htmlspecialchars($message['content'])) . '</div>';
                    echo '</div>';
                }
                
                // Показываем последний ответ, если есть
                if (!empty($chatResponse)) {
                    $class = startsWith($chatResponse, "Ошибка:") ? 'assistant error' : 'assistant';
                    echo '<div class="message ' . $class . '">';
                    echo '<div><i class="fas fa-robot"></i> ' . nl2br(htmlspecialchars($chatResponse)) . '</div>';
                    echo '</div>';
                }
                ?>
            </div>
            
            <form method="POST" class="chat-input">
                <input type="text" name="chat_message" placeholder="Задайте вопрос о системе, моделях или анализе данных..." required>
                <button type="submit"><i class="fas fa-paper-plane"></i> Отправить</button>
            </form>
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
        
        // Автопрокрутка чата вниз
        var chatMessages = document.getElementById('chatMessages');
        if (chatMessages) {
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }
    </script>
</body>
</html>