<?php
include 'config.php';

// Проверяем, авторизован ли пользователь
if (!isset($_SESSION['user_id'])) {
    echo "<script>window.parent.postMessage('closeModal', '*');</script>";
    exit;
}

// Получаем информацию о пользователе
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

// Проверяем права доступа
if ($user['role'] == 'student') {
    echo "<script>window.parent.postMessage('closeModal', '*');</script>";
    exit;
}

// Получаем ID теста
$test_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Получаем информацию о тесте
$stmt = $pdo->prepare("SELECT * FROM tests WHERE id = ? AND created_by = ?");
$stmt->execute([$test_id, $_SESSION['user_id']]);
$test = $stmt->fetch();

if (!$test) {
    echo "<script>window.parent.postMessage('closeModal', '*');</script>";
    exit;
}

// Проверяем наличие столбца sort_order и добавляем его если нужно
try {
    $stmt = $pdo->query("SELECT sort_order FROM questions LIMIT 1");
} catch (PDOException $e) {
    // Если столбец не существует, добавляем его
    if ($e->getCode() == '42S22') {
        $pdo->query("ALTER TABLE questions ADD COLUMN sort_order INT DEFAULT 0 AFTER points");
        
        // Устанавливаем значения по умолчанию для существующих записей
        $pdo->query("UPDATE questions SET sort_order = id WHERE sort_order IS NULL OR sort_order = 0");
    }
}

// Получаем вопросы теста с сортировкой по sort_order
try {
    $stmt = $pdo->prepare("SELECT * FROM questions WHERE test_id = ? ORDER BY sort_order ASC, id ASC");
    $stmt->execute([$test_id]);
    $questions = $stmt->fetchAll();
} catch (PDOException $e) {
    // Если произошла ошибка из-за отсутствия столбца, получаем без сортировки
    if ($e->getCode() == '42S22') {
        $stmt = $pdo->prepare("SELECT * FROM questions WHERE test_id = ? ORDER BY id ASC");
        $stmt->execute([$test_id]);
        $questions = $stmt->fetchAll();
    } else {
        throw $e;
    }
}

// Обработка добавления вопроса
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_question'])) {
    $question_text = trim($_POST['question_text']);
    $question_type = $_POST['question_type'];
    $points = intval($_POST['points']);
    
    if (!empty($question_text)) {
        try {
            // Определяем порядок сортировки
            $stmt = $pdo->prepare("SELECT MAX(sort_order) as max_order FROM questions WHERE test_id = ?");
            $stmt->execute([$test_id]);
            $max_order = $stmt->fetch()['max_order'] ?? 0;
            $sort_order = $max_order + 1;
            
            // Используем NULL для created_by вместо 0
            $stmt = $pdo->prepare("INSERT INTO questions (test_id, question_text, question_type, points, sort_order, created_by) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$test_id, $question_text, $question_type, $points, $sort_order, $_SESSION['user_id']]);
            
            $question_id = $pdo->lastInsertId();
            
            // Если вопрос с выбором ответов, добавляем варианты
            if ($question_type == 'multiple_choice' && isset($_POST['options'])) {
                $options = $_POST['options'];
                $correct_option = intval($_POST['correct_option']);
                
                foreach ($options as $index => $option_text) {
                    if (!empty(trim($option_text))) {
                        $is_correct = ($index == $correct_option) ? 1 : 0;
                        $stmt = $pdo->prepare("INSERT INTO question_options (question_id, option_text, is_correct) VALUES (?, ?, ?)");
                        $stmt->execute([$question_id, trim($option_text), $is_correct]);
                    }
                }
            }
            
            header("Location: test_edit.php?id=" . $test_id);
            exit;
            
        } catch (PDOException $e) {
            $error = "Ошибка при добавлении вопроса: " . $e->getMessage();
        }
    } else {
        $error = "Текст вопроса не может быть пустым";
    }
}

// Обработка удаления вопроса
if (isset($_GET['delete_question'])) {
    $question_id = intval($_GET['delete_question']);
    
    $stmt = $pdo->prepare("SELECT test_id FROM questions WHERE id = ?");
    $stmt->execute([$question_id]);
    $question = $stmt->fetch();
    
    if ($question && $question['test_id'] == $test_id) {
        try {
            // Удаляем варианты ответов (если есть)
            $stmt = $pdo->prepare("DELETE FROM question_options WHERE question_id = ?");
            $stmt->execute([$question_id]);
            
            // Удаляем вопрос
            $stmt = $pdo->prepare("DELETE FROM questions WHERE id = ?");
            $stmt->execute([$question_id]);
            
            header("Location: test_edit.php?id=" . $test_id);
            exit;
            
        } catch (PDOException $e) {
            $error = "Ошибка при удалении вопроса: " . $e->getMessage();
        }
    }
}

// Обработка изменения порядка вопросов
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_order'])) {
    $order = $_POST['order'];
    
    try {
        // Проверяем наличие столбца sort_order
        $stmt = $pdo->query("SELECT sort_order FROM questions LIMIT 1");
        
        foreach ($order as $sort_order => $question_id) {
            $stmt = $pdo->prepare("UPDATE questions SET sort_order = ? WHERE id = ? AND test_id = ?");
            $stmt->execute([$sort_order, $question_id, $test_id]);
        }
        
        header("Location: test_edit.php?id=" . $test_id);
        exit;
        
    } catch (PDOException $e) {
        // Если столбец не существует, игнорируем обновление порядка
        if ($e->getCode() != '42S22') {
            $error = "Ошибка при обновлении порядка: " . $e->getMessage();
        }
        header("Location: test_edit.php?id=" . $test_id);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Редактирование теста - <?php echo htmlspecialchars($test['title']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #6366f1;
            --primary-light: #818cf8;
            --primary-dark: #4f46e5;
            --secondary: #64748b;
            --success: #10b981;
            --success-light: #34d399;
            --danger: #ef4444;
            --danger-light: #f87171;
            --warning: #f59e0b;
            --info: #3b82f6;
            --light: #f8fafc;
            --light-gray: #f1f5f9;
            --border: #e2e8f0;
            --border-dark: #cbd5e1;
            --text: #334155;
            --text-light: #64748b;
            --text-dark: #1e293b;
            --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --radius: 12px;
            --radius-sm: 8px;
            --transition: all 0.2s ease-in-out;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            color: var(--text);
            line-height: 1.6;
            min-height: 100vh;
            overflow-x: hidden;
        }
        
        .container {
            max-width: 100%;
            margin: 0;
            padding: 0;
        }
        
        /* Header Styles */
        .header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 1.5rem 2rem;
            box-shadow: var(--shadow-lg);
            position: sticky;
            top: 0;
            z-index: 100;
            backdrop-filter: blur(10px);
        }
        
        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .header h1 {
            font-size: 1.75rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .header-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }
        
        .close-btn {
            background: rgba(255, 255, 255, 0.15);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 0.75rem 1.5rem;
            border-radius: var(--radius-sm);
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            transition: var(--transition);
            backdrop-filter: blur(10px);
        }
        
        .close-btn:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: translateY(-1px);
        }
        
        /* Main Content */
        .main-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }
        
        /* Card Styles */
        .card {
            background: white;
            border-radius: var(--radius);
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            transition: var(--transition);
        }
        
        .card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
        }
        
        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--light-gray);
        }
        
        .card-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        /* Alert Styles */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: var(--radius-sm);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-weight: 500;
            border-left: 4px solid;
            animation: slideIn 0.3s ease-out;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-10px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        .alert-error {
            background: #fef2f2;
            color: var(--danger);
            border-left-color: var(--danger);
        }
        
        .alert-success {
            background: #f0fdf4;
            color: var(--success);
            border-left-color: var(--success);
        }
        
        /* Form Styles */
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .form-input,
        .form-textarea,
        .form-select {
            width: 100%;
            padding: 1rem;
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 1rem;
            font-family: inherit;
            transition: var(--transition);
            background: white;
        }
        
        .form-input:focus,
        .form-textarea:focus,
        .form-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
            transform: translateY(-1px);
        }
        
        .form-textarea {
            min-height: 120px;
            resize: vertical;
            line-height: 1.5;
        }
        
        /* Button Styles */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 1rem 2rem;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 1rem;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            font-family: inherit;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }
        
        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: left 0.5s;
        }
        
        .btn:hover::before {
            left: 100%;
        }
        
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        
        .btn-success {
            background: var(--success);
            color: white;
        }
        
        .btn-success:hover {
            background: #059669;
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        
        .btn-danger {
            background: var(--danger);
            color: white;
        }
        
        .btn-danger:hover {
            background: #dc2626;
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        
        .btn-outline {
            background: transparent;
            border: 2px solid var(--primary);
            color: var(--primary);
        }
        
        .btn-outline:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
        }
        
        .btn-sm {
            padding: 0.75rem 1.5rem;
            font-size: 0.875rem;
        }
        
        /* Options Styles */
        .options-container {
            background: var(--light-gray);
            padding: 1.5rem;
            border-radius: var(--radius-sm);
            margin: 1.5rem 0;
            border: 1px solid var(--border);
            animation: fadeIn 0.3s ease-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .option-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
            padding: 1rem;
            background: white;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            transition: var(--transition);
        }
        
        .option-item:hover {
            border-color: var(--primary);
            transform: translateX(5px);
        }
        
        .option-item input[type="text"] {
            flex: 1;
            margin: 0;
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 0.75rem;
        }
        
        .correct-marker {
            color: var(--success);
            font-weight: 600;
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        /* Questions List */
        .questions-list {
            margin-top: 2rem;
        }
        
        .sortable-list {
            list-style: none;
        }
        
        .question-item {
            background: white;
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 1rem;
            border: 1px solid var(--border);
            position: relative;
            transition: var(--transition);
            animation: slideInUp 0.3s ease-out;
        }
        
        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .question-item:hover {
            border-color: var(--primary);
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
        }
        
        .question-item.placeholder {
            background: var(--light-gray);
            border: 2px dashed var(--border-dark);
            height: 100px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-light);
        }
        
        .question-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--light-gray);
        }
        
        .handle {
            cursor: move;
            color: var(--secondary);
            padding: 0.5rem;
            border-radius: 6px;
            background: var(--light-gray);
            transition: var(--transition);
        }
        
        .handle:hover {
            background: var(--primary);
            color: white;
        }
        
        .question-number {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 1.1rem;
        }
        
        .badge {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
            transition: var(--transition);
        }
        
        .badge-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white;
        }
        
        .badge-secondary {
            background: var(--secondary);
            color: white;
        }
        
        .badge-success {
            background: var(--success);
            color: white;
        }
        
        .question-text {
            font-size: 1.125rem;
            margin-bottom: 1rem;
            line-height: 1.6;
            color: var(--text-dark);
            padding: 0.5rem 0;
        }
        
        .options-list {
            margin: 1rem 0;
        }
        
        .options-list ul {
            list-style: none;
            margin-left: 1rem;
        }
        
        .options-list li {
            padding: 0.75rem;
            margin-bottom: 0.5rem;
            background: var(--light-gray);
            border-radius: 6px;
            border-left: 3px solid var(--secondary);
            transition: var(--transition);
        }
        
        .options-list li:hover {
            transform: translateX(5px);
        }
        
        .options-list li.correct {
            background: #f0fdf4;
            border-left-color: var(--success);
        }
        
        .question-actions {
            display: flex;
            gap: 0.75rem;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--light-gray);
        }
        
        /* Test Info */
        .test-info {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 2rem;
            align-items: start;
        }
        
        .test-details p {
            margin-bottom: 0.5rem;
            color: var(--text-light);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .test-details strong {
            color: var(--text-dark);
            min-width: 150px;
            display: inline-block;
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .stat-card {
            background: var(--light-gray);
            padding: 1.5rem;
            border-radius: var(--radius-sm);
            text-align: center;
            border: 1px solid var(--border);
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            color: var(--text-light);
            font-size: 0.875rem;
            font-weight: 500;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--text-light);
        }
        
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            color: var(--border);
            opacity: 0.5;
        }
        
        .empty-state h3 {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }
        
        /* Progress Bar */
        .progress-bar {
            width: 100%;
            height: 8px;
            background: var(--light-gray);
            border-radius: 4px;
            overflow: hidden;
            margin: 1rem 0;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary) 0%, var(--success) 100%);
            border-radius: 4px;
            transition: width 0.3s ease;
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }
            
            .header {
                padding: 1rem;
            }
            
            .header h1 {
                font-size: 1.5rem;
            }
            
            .header-content {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }
            
            .header-actions {
                width: 100%;
                justify-content: flex-end;
            }
            
            .test-info {
                grid-template-columns: 1fr;
            }
            
            .question-header {
                flex-wrap: wrap;
            }
            
            .question-actions {
                flex-direction: column;
            }
            
            .option-item {
                flex-direction: column;
                align-items: stretch;
                gap: 0.75rem;
            }
            
            .card {
                padding: 1.5rem;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
        
        /* Compact styles for modal */
        .compact .card {
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .compact .card-header {
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
        }
        
        .compact .card-title {
            font-size: 1.25rem;
        }
        
        .compact .btn {
            padding: 0.75rem 1.5rem;
        }
        
        /* Loading animation */
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 1s ease-in-out infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Floating action button */
        .fab {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            width: 60px;
            height: 60px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            cursor: pointer;
            box-shadow: var(--shadow-lg);
            transition: var(--transition);
            z-index: 1000;
        }
        
        .fab:hover {
            background: var(--primary-dark);
            transform: scale(1.1);
        }
    </style>
</head>
<body class="compact">
    <div class="container">
        <header class="header">
            <div class="header-content">
                <h1>
                    <i class="fas fa-edit"></i>
                    Редактирование: <?php echo htmlspecialchars($test['title']); ?>
                </h1>
                <div class="header-actions">
                    <button class="close-btn" onclick="closeModal()">
                        <i class="fas fa-times"></i>
                        Закрыть
                    </button>
                </div>
            </div>
        </header>

        <div class="main-content">
            <?php if (isset($error)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <!-- Test Overview Card -->
            <div class="card">
                <div class="test-info">
                    <div>
                        <h2 class="card-title">
                            <i class="fas fa-info-circle"></i>
                            Информация о тесте
                        </h2>
                        <p><strong><i class="fas fa-align-left"></i> Описание:</strong> <?php echo htmlspecialchars($test['description']); ?></p>
                        <p><strong><i class="fas fa-clock"></i> Время на выполнение:</strong> <?php echo $test['time_limit']; ?> минут</p>
                        <p><strong><i class="fas fa-chart-bar"></i> Статус:</strong> 
                            <span class="badge <?php echo $test['is_active'] ? 'badge-success' : 'badge-secondary'; ?>">
                                <?php echo $test['is_active'] ? 'Активный' : 'Неактивный'; ?>
                            </span> | 
                            <span class="badge <?php echo $test['is_published'] ? 'badge-success' : 'badge-secondary'; ?>">
                                <?php echo $test['is_published'] ? 'Опубликован' : 'Черновик'; ?>
                            </span>
                        </p>
                        
                        <div class="stats-grid">
                            <div class="stat-card">
                                <div class="stat-number"><?php echo count($questions); ?></div>
                                <div class="stat-label">Всего вопросов</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-number">
                                    <?php 
                                        $total_points = 0;
                                        foreach ($questions as $q) {
                                            $total_points += $q['points'];
                                        }
                                        echo $total_points;
                                    ?>
                                </div>
                                <div class="stat-label">Общее количество баллов</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-number">
                                    <?php
                                        $multiple_choice = 0;
                                        foreach ($questions as $q) {
                                            if ($q['question_type'] == 'multiple_choice') {
                                                $multiple_choice++;
                                            }
                                        }
                                        echo $multiple_choice;
                                    ?>
                                </div>
                                <div class="stat-label">Вопросы с выбором</div>
                            </div>
                        </div>
                    </div>
                    <a href="test_settings.php?id=<?php echo $test_id; ?>" class="btn btn-primary" target="_blank">
                        <i class="fas fa-cog"></i>
                        Настройки теста
                    </a>
                </div>
            </div>

            <!-- Add Question Card -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">
                        <i class="fas fa-plus-circle"></i>
                        Добавить новый вопрос
                    </h2>
                </div>
                <form method="POST" id="questionForm">
                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-question-circle"></i>
                            Текст вопроса:
                        </label>
                        <textarea name="question_text" class="form-textarea" required placeholder="Введите текст вопроса..." id="questionText"></textarea>
                        <div style="display: flex; justify-content: space-between; margin-top: 0.5rem; font-size: 0.875rem; color: var(--text-light);">
                            <span id="charCount">0 символов</span>
                            <span>Минимум 10 символов</span>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-list-alt"></i>
                            Тип вопроса:
                        </label>
                        <select name="question_type" id="question_type" class="form-select" onchange="toggleOptions()">
                            <option value="text">Текстовый ответ</option>
                            <option value="multiple_choice">Множественный выбор</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-star"></i>
                            Баллы за вопрос:
                        </label>
                        <input type="number" name="points" class="form-input" value="1" min="1" max="10" required>
                    </div>
                    
                    <div id="options_container" class="options-container" style="display: none;">
                        <h3 style="margin-bottom: 1rem; color: var(--text-dark); display: flex; align-items: center; gap: 0.5rem;">
                            <i class="fas fa-list-ol"></i>
                            Варианты ответов
                        </h3>
                        <div id="options_list">
                            <div class="option-item">
                                <input type="text" name="options[]" class="form-input" placeholder="Вариант ответа 1" required>
                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                    <input type="radio" name="correct_option" value="0" checked> 
                                    <span class="correct-marker">
                                        <i class="fas fa-check"></i>
                                        Правильный
                                    </span>
                                </div>
                            </div>
                            <div class="option-item">
                                <input type="text" name="options[]" class="form-input" placeholder="Вариант ответа 2" required>
                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                    <input type="radio" name="correct_option" value="1"> 
                                    <span class="correct-marker">
                                        <i class="fas fa-check"></i>
                                        Правильный
                                    </span>
                                    <button type="button" class="btn btn-danger btn-sm" onclick="removeOption(this)" style="padding: 0.5rem;">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-outline" onclick="addOption()">
                            <i class="fas fa-plus"></i>
                            Добавить вариант ответа
                        </button>
                    </div>
                    
                    <div style="display: flex; gap: 1rem; margin-top: 2rem;">
                        <button type="submit" name="add_question" class="btn btn-success" id="submitBtn">
                            <i class="fas fa-save"></i>
                            Сохранить вопрос
                        </button>
                        <button type="button" class="btn btn-outline" onclick="resetForm()">
                            <i class="fas fa-undo"></i>
                            Очистить форму
                        </button>
                    </div>
                </form>
            </div>

            <!-- Questions List Card -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">
                        <i class="fas fa-list"></i>
                        Вопросы теста
                        <span class="badge badge-primary"><?php echo count($questions); ?></span>
                    </h2>
                    <div style="display: flex; align-items: center; gap: 1rem;">
                        <span style="color: var(--text-light); font-size: 0.875rem;">
                            <i class="fas fa-arrows-alt-v"></i>
                            Перетащите для изменения порядка
                        </span>
                    </div>
                </div>
                
                <?php if (empty($questions)): ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <h3>Вопросы пока не добавлены</h3>
                        <p>Добавьте первый вопрос, используя форму выше</p>
                    </div>
                <?php else: ?>
                    <form method="POST" id="order_form">
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo min(100, (count($questions) / 20 * 100)); ?>%"></div>
                        </div>
                        <div style="text-align: center; margin-bottom: 1rem; color: var(--text-light); font-size: 0.875rem;">
                            <?php echo count($questions); ?> из 20 вопросов (рекомендуется)
                        </div>
                        
                        <ul id="sortable" class="sortable-list">
                            <?php foreach ($questions as $index => $question): ?>
                                <li class="question-item" data-id="<?php echo $question['id']; ?>">
                                    <div class="question-header">
                                        <span class="handle">
                                            <i class="fas fa-bars"></i>
                                        </span>
                                        <span class="question-number">Вопрос #<?php echo ($index + 1); ?></span>
                                        <span class="badge badge-primary">
                                            <i class="fas fa-star"></i>
                                            <?php echo $question['points']; ?> баллов
                                        </span>
                                        <span class="badge <?php echo $question['question_type'] == 'text' ? 'badge-secondary' : 'badge-success'; ?>">
                                            <?php echo $question['question_type'] == 'text' ? 'Текстовый' : 'Множественный выбор'; ?>
                                        </span>
                                    </div>
                                    
                                    <div class="question-text">
                                        <?php echo nl2br(htmlspecialchars($question['question_text'])); ?>
                                    </div>
                                    
                                    <?php if ($question['question_type'] == 'multiple_choice'): ?>
                                        <?php
                                        $stmt = $pdo->prepare("SELECT * FROM question_options WHERE question_id = ? ORDER BY id");
                                        $stmt->execute([$question['id']]);
                                        $options = $stmt->fetchAll();
                                        ?>
                                        <?php if (!empty($options)): ?>
                                            <div class="options-list">
                                                <h4 style="margin-bottom: 0.75rem; color: var(--text-dark); display: flex; align-items: center; gap: 0.5rem;">
                                                    <i class="fas fa-list-check"></i>
                                                    Варианты ответов:
                                                </h4>
                                                <ul>
                                                    <?php foreach ($options as $option): ?>
                                                        <li class="<?php echo $option['is_correct'] ? 'correct' : ''; ?>">
                                                            <?php echo htmlspecialchars($option['option_text']); ?>
                                                            <?php if ($option['is_correct']): ?>
                                                                <span class="correct-marker">
                                                                    <i class="fas fa-check"></i>
                                                                    Правильный ответ
                                                                </span>
                                                            <?php endif; ?>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    
                                    <div class="question-actions">
                                        <a href="question_edit.php?id=<?php echo $question['id']; ?>" class="btn btn-primary btn-sm" target="_blank">
                                            <i class="fas fa-edit"></i>
                                            Редактировать
                                        </a>
                                        <a href="test_edit.php?id=<?php echo $test_id; ?>&delete_question=<?php echo $question['id']; ?>" 
                                           class="btn btn-danger btn-sm" 
                                           onclick="return confirm('Вы уверены, что хотите удалить этот вопрос?')">
                                            <i class="fas fa-trash"></i>
                                            Удалить
                                        </a>
                                    </div>
                                    
                                    <input type="hidden" name="order[]" value="<?php echo $question['id']; ?>">
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        
                        <div style="display: flex; gap: 1rem; margin-top: 2rem;">
                            <button type="submit" name="update_order" class="btn btn-success">
                                <i class="fas fa-save"></i>
                                Сохранить порядок вопросов
                            </button>
                            <button type="button" class="btn btn-outline" onclick="window.scrollTo({top: 0, behavior: 'smooth'})">
                                <i class="fas fa-arrow-up"></i>
                                Наверх
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Floating Action Button -->
    <button class="fab" onclick="scrollToAddForm()" title="Добавить вопрос">
        <i class="fas fa-plus"></i>
    </button>

    <script>
    function closeModal() {
        window.parent.postMessage('closeModal', '*');
    }
    
    function toggleOptions() {
        const type = document.getElementById('question_type').value;
        const container = document.getElementById('options_container');
        container.style.display = type === 'multiple_choice' ? 'block' : 'none';
    }
    
    function addOption() {
        const optionsList = document.getElementById('options_list');
        const optionCount = optionsList.children.length;
        
        const div = document.createElement('div');
        div.className = 'option-item';
        div.innerHTML = `
            <input type="text" name="options[]" class="form-input" placeholder="Вариант ответа ${optionCount + 1}" required>
            <div style="display: flex; align-items: center; gap: 0.5rem;">
                <input type="radio" name="correct_option" value="${optionCount}"> 
                <span class="correct-marker">
                    <i class="fas fa-check"></i>
                    Правильный
                </span>
                <button type="button" class="btn btn-danger btn-sm" onclick="removeOption(this)" style="padding: 0.5rem;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `;
        
        optionsList.appendChild(div);
    }
    
    function removeOption(button) {
        const optionItem = button.closest('.option-item');
        if (document.querySelectorAll('.option-item').length > 2) {
            optionItem.remove();
            // Обновляем значения радиокнопок
            document.querySelectorAll('input[name="correct_option"]').forEach((radio, index) => {
                radio.value = index;
            });
        } else {
            alert('Должно быть как минимум 2 варианта ответа');
        }
    }
    
    function resetForm() {
        document.getElementById('questionForm').reset();
        document.getElementById('questionText').focus();
        updateCharCount();
        toggleOptions();
    }
    
    function scrollToAddForm() {
        document.querySelector('.card:nth-child(2)').scrollIntoView({ 
            behavior: 'smooth',
            block: 'start'
        });
    }
    
    function updateCharCount() {
        const textarea = document.getElementById('questionText');
        const charCount = document.getElementById('charCount');
        charCount.textContent = textarea.value.length + ' символов';
        
        // Подсветка при недостаточной длине
        if (textarea.value.length < 10) {
            charCount.style.color = 'var(--danger)';
        } else {
            charCount.style.color = 'var(--success)';
        }
    }
    
    // Инициализация при загрузке
    document.addEventListener('DOMContentLoaded', function() {
        toggleOptions();
        
        // Счетчик символов для текста вопроса
        const questionText = document.getElementById('questionText');
        if (questionText) {
            questionText.addEventListener('input', updateCharCount);
            updateCharCount();
        }
        
        // Drag and drop для вопросов
        const sortable = document.getElementById('sortable');
        if (sortable) {
            let draggedItem = null;
            
            // Добавляем обработчики событий для drag and drop
            document.querySelectorAll('.question-item').forEach(item => {
                item.setAttribute('draggable', true);
                
                item.addEventListener('dragstart', function(e) {
                    draggedItem = this;
                    setTimeout(() => this.style.opacity = '0.5', 0);
                });
                
                item.addEventListener('dragend', function() {
                    this.style.opacity = '1';
                    draggedItem = null;
                });
                
                item.addEventListener('dragover', function(e) {
                    e.preventDefault();
                });
                
                item.addEventListener('drop', function(e) {
                    e.preventDefault();
                    if (draggedItem && draggedItem !== this) {
                        const allItems = Array.from(sortable.children);
                        const thisIndex = allItems.indexOf(this);
                        const draggedIndex = allItems.indexOf(draggedItem);
                        
                        if (draggedIndex < thisIndex) {
                            this.parentNode.insertBefore(draggedItem, this.nextSibling);
                        } else {
                            this.parentNode.insertBefore(draggedItem, this);
                        }
                        
                        // Обновляем порядок в скрытых полях
                        updateOrderFields();
                    }
                });
            });
        }
    });
    
    function updateOrderFields() {
        const items = document.querySelectorAll('.question-item');
        items.forEach((item, index) => {
            const hiddenInput = item.querySelector('input[name="order[]"]');
            if (hiddenInput) {
                hiddenInput.value = item.getAttribute('data-id');
            }
        });
    }
    </script>
</body>
</html>