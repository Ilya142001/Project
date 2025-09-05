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
if ($user['role'] == 'student') {
    header("Location: tests.php");
    exit;
}

// Получаем ID теста
$test_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Получаем информацию о тесте
$stmt = $pdo->prepare("SELECT * FROM tests WHERE id = ? AND created_by = ?");
$stmt->execute([$test_id, $_SESSION['user_id']]);
$test = $stmt->fetch();

if (!$test) {
    header("Location: tests.php");
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
            --primary-hover: #4f46e5;
            --secondary: #64748b;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --light: #f8fafc;
            --dark: #1e293b;
            --border: #e2e8f0;
            --card-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            --card-shadow-hover: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --radius: 12px;
            --transition: all 0.2s ease-in-out;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
            color: var(--dark);
            line-height: 1.6;
            min-height: 100vh;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        /* Header Styles */
        .header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-hover) 100%);
            color: white;
            padding: 2rem;
            border-radius: var(--radius);
            margin-bottom: 2rem;
            box-shadow: var(--card-shadow);
            backdrop-filter: blur(10px);
        }
        
        .header h1 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .nav {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }
        
        .nav-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: white;
            text-decoration: none;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.15);
            transition: var(--transition);
            font-weight: 500;
        }
        
        .nav-link:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: translateY(-1px);
        }
        
        /* Card Styles */
        .card {
            background: white;
            border-radius: var(--radius);
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--card-shadow);
            border: 1px solid var(--border);
            transition: var(--transition);
        }
        
        .card:hover {
            box-shadow: var(--card-shadow-hover);
        }
        
        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--light);
        }
        
        .card-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        /* Alert Styles */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-weight: 500;
        }
        
        .alert-error {
            background: #fef2f2;
            color: var(--danger);
            border-left: 4px solid var(--danger);
        }
        
        .alert-success {
            background: #f0fdf4;
            color: var(--success);
            border-left: 4px solid var(--success);
        }
        
        /* Form Styles */
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--dark);
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
            border-radius: 8px;
            font-size: 1rem;
            transition: var(--transition);
            font-family: inherit;
        }
        
        .form-input:focus,
        .form-textarea:focus,
        .form-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }
        
        .form-textarea {
            min-height: 120px;
            resize: vertical;
        }
        
        /* Button Styles */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 1rem 2rem;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: var(--transition);
            font-family: inherit;
        }
        
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        
        .btn-primary:hover {
            background: var(--primary-hover);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
        }
        
        .btn-success {
            background: var(--success);
            color: white;
        }
        
        .btn-success:hover {
            background: #059669;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }
        
        .btn-danger {
            background: var(--danger);
            color: white;
        }
        
        .btn-danger:hover {
            background: #dc2626;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
        }
        
        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
        }
        
        /* Options Styles */
        .options-container {
            background: var(--light);
            padding: 1.5rem;
            border-radius: 8px;
            margin: 1.5rem 0;
        }
        
        .option-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
            padding: 1rem;
            background: white;
            border-radius: 8px;
            border: 1px solid var(--border);
        }
        
        .option-item input[type="text"] {
            flex: 1;
            margin: 0;
        }
        
        .correct-marker {
            color: var(--success);
            font-weight: 600;
            white-space: nowrap;
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
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            border: 1px solid var(--border);
            transition: var(--transition);
            position: relative;
        }
        
        .question-item:hover {
            transform: translateY(-2px);
            box-shadow: var(--card-shadow-hover);
        }
        
        .question-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--light);
        }
        
        .handle {
            cursor: move;
            color: var(--secondary);
            padding: 0.5rem;
            border-radius: 6px;
            background: var(--light);
            transition: var(--transition);
        }
        
        .handle:hover {
            background: var(--border);
        }
        
        .question-number {
            font-weight: 600;
            color: var(--dark);
        }
        
        .badge {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
        }
        
        .badge-primary {
            background: var(--primary);
            color: white;
        }
        
        .badge-secondary {
            background: var(--secondary);
            color: white;
        }
        
        .question-text {
            font-size: 1.125rem;
            margin-bottom: 1rem;
            line-height: 1.6;
            color: var(--dark);
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
            background: var(--light);
            border-radius: 6px;
            border-left: 3px solid var(--secondary);
            transition: var(--transition);
        }
        
        .options-list li.correct {
            background: #f0fdf4;
            border-left-color: var(--success);
        }
        
        .options-list li:hover {
            transform: translateX(4px);
        }
        
        .question-actions {
            display: flex;
            gap: 0.75rem;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--light);
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
            color: var(--secondary);
        }
        
        .test-details strong {
            color: var(--dark);
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--secondary);
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: var(--border);
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }
            
            .header {
                padding: 1.5rem;
            }
            
            .header h1 {
                font-size: 2rem;
            }
            
            .nav {
                flex-direction: column;
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
            }
            
            .card {
                padding: 1.5rem;
            }
        }
        
        /* Animation */
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .question-item {
            animation: fadeIn 0.3s ease-out;
        }
        
        /* Drag and Drop Styles */
        .sortable-helper {
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            transform: rotate(2deg);
        }
        
        .sortable-placeholder {
            background: var(--light);
            border: 2px dashed var(--border);
            border-radius: 12px;
            margin-bottom: 1rem;
            height: 100px;
        }
    </style>
</head>
<body>
    <div class="container">
        <header class="header">
            <h1>
                <i class="fas fa-edit"></i>
                Редактирование теста: <?php echo htmlspecialchars($test['title']); ?>
            </h1>
            <nav class="nav">
                <a href="tests.php" class="nav-link">
                    <i class="fas fa-arrow-left"></i>
                    Мои тесты
                </a>
                <a href="logout.php" class="nav-link">
                    <i class="fas fa-sign-out-alt"></i>
                    Выйти
                </a>
            </nav>
        </header>

        <?php if (isset($error)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="test-info">
                <div class="test-details">
                    <h2 class="card-title">
                        <i class="fas fa-info-circle"></i>
                        Информация о тесте
                    </h2>
                    <p><strong>Описание:</strong> <?php echo htmlspecialchars($test['description']); ?></p>
                    <p><strong>Время на выполнение:</strong> <?php echo $test['time_limit']; ?> минут</p>
                    <p><strong>Статус:</strong> 
                        <?php echo $test['is_active'] ? 'Активный' : 'Неактивный'; ?> | 
                        <?php echo $test['is_published'] ? 'Опубликован' : 'Черновик'; ?>
                    </p>
                </div>
                <a href="test_settings.php?id=<?php echo $test_id; ?>" class="btn btn-primary">
                    <i class="fas fa-cog"></i>
                    Настройки теста
                </a>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <i class="fas fa-plus-circle"></i>
                    Добавить вопрос
                </h2>
            </div>
            <form method="POST">
                <div class="form-group">
                    <label class="form-label">
                        <i class="fas fa-question-circle"></i>
                        Текст вопроса:
                    </label>
                    <textarea name="question_text" class="form-textarea" required placeholder="Введите текст вопроса..."></textarea>
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
                        Баллы:
                    </label>
                    <input type="number" name="points" class="form-input" value="1" min="1" required>
                </div>
                
                <div id="options_container" class="options-container" style="display: none;">
                    <h3 style="margin-bottom: 1rem; color: var(--dark);">
                        <i class="fas fa-list-ol"></i>
                        Варианты ответов
                    </h3>
                    <div id="options_list">
                        <div class="option-item">
                            <input type="text" name="options[]" class="form-input" placeholder="Вариант ответа 1">
                            <input type="radio" name="correct_option" value="0" checked> 
                            <span class="correct-marker">Правильный</span>
                        </div>
                        <div class="option-item">
                            <input type="text" name="options[]" class="form-input" placeholder="Вариант ответа 2">
                            <input type="radio" name="correct_option" value="1"> 
                            <span class="correct-marker">Правильный</span>
                        </div>
                    </div>
                    <button type="button" class="btn btn-primary" onclick="addOption()">
                        <i class="fas fa-plus"></i>
                        Добавить вариант
                    </button>
                </div>
                
                <button type="submit" name="add_question" class="btn btn-success">
                    <i class="fas fa-save"></i>
                    Добавить вопрос
                </button>
            </form>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <i class="fas fa-list"></i>
                    Вопросы теста
                    <span class="badge badge-primary"><?php echo count($questions); ?></span>
                </h2>
            </div>
            
            <?php if (empty($questions)): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <h3>Вопросы пока не добавлены</h3>
                    <p>Добавьте первый вопрос, используя форму выше</p>
                </div>
            <?php else: ?>
                <form method="POST" id="order_form">
                    <ul id="sortable" class="sortable-list">
                        <?php foreach ($questions as $index => $question): ?>
                            <li class="question-item" data-id="<?php echo $question['id']; ?>">
                                <div class="question-header">
                                    <span class="handle">
                                        <i class="fas fa-bars"></i>
                                    </span>
                                    <span class="question-number">Вопрос #<?php echo ($index + 1); ?></span>
                                    <span class="badge badge-primary"><?php echo $question['points']; ?> баллов</span>
                                    <span class="badge badge-secondary">
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
                                            <h4 style="margin-bottom: 0.75rem; color: var(--dark);">
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
                                                                Правильный
                                                            </span>
                                                        <?php endif; ?>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <div class="question-actions">
                                    <a href="question_edit.php?id=<?php echo $question['id']; ?>" class="btn btn-primary btn-sm">
                                        <i class="fas fa-edit"></i>
                                        Редактировать
                                    </a>
                                    <a href="test_edit.php?id=<?php echo $test_id; ?>&delete_question=<?php echo $question['id']; ?>" 
                                       class="btn btn-danger btn-sm" 
                                       onclick="return confirm('Удалить этот вопрос?')">
                                        <i class="fas fa-trash"></i>
                                        Удалить
                                    </a>
                                </div>
                                
                                <input type="hidden" name="order[]" value="<?php echo $question['id']; ?>">
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    
                    <button type="submit" name="update_order" class="btn btn-success">
                        <i class="fas fa-save"></i>
                        Сохранить порядок
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://code.jquery.com/ui/1.13.1/jquery-ui.min.js"></script>
    <script>
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
            <input type="text" name="options[]" class="form-input" placeholder="Вариант ответа ${optionCount + 1}">
            <div style="display: flex; align-items: center; gap: 0.5rem;">
                <input type="radio" name="correct_option" value="${optionCount}"> 
                <span class="correct-marker">Правильный</span>
                <button type="button" class="btn btn-danger btn-sm" onclick="removeOption(this)">
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
        }
    }
    
    // Сортировка вопросов
    $(function() {
        $("#sortable").sortable({
            handle: ".handle",
            placeholder: "sortable-placeholder",
            helper: function(e, ui) {
                ui.children().each(function() {
                    $(this).width($(this).width());
                });
                return ui;
            },
            update: function() {
                $('#sortable li').each(function(index) {
                    $(this).find('.question-number').text('Вопрос #' + (index + 1));
                });
            }
        });
        $("#sortable").disableSelection();
    });
    
    // Инициализация при загрузке
    document.addEventListener('DOMContentLoaded', function() {
        toggleOptions();
        
        // Добавляем анимацию для карточек
        document.querySelectorAll('.question-item').forEach((item, index) => {
            item.style.animationDelay = `${index * 0.1}s`;
        });
    });
    </script>
</body>
</html>