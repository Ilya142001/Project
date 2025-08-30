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

// Получаем вопросы теста
$stmt = $pdo->prepare("SELECT * FROM questions WHERE test_id = ? ORDER BY sort_order ASC");
$stmt->execute([$test_id]);
$questions = $stmt->fetchAll();

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
            
            $stmt = $pdo->prepare("INSERT INTO questions (test_id, question_text, question_type, points, sort_order) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$test_id, $question_text, $question_type, $points, $sort_order]);
            
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
        foreach ($order as $sort_order => $question_id) {
            $stmt = $pdo->prepare("UPDATE questions SET sort_order = ? WHERE id = ? AND test_id = ?");
            $stmt->execute([$sort_order, $question_id, $test_id]);
        }
        
        header("Location: test_edit.php?id=" . $test_id);
        exit;
        
    } catch (PDOException $e) {
        $error = "Ошибка при обновлении порядка: " . $e->getMessage();
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
    <style>
        :root {
            --primary: #4a6bdf;
            --secondary: #6c757d;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --light: #f8f9fa;
            --dark: #343a40;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f7fb;
            color: #333;
            line-height: 1.6;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        header {
            background: linear-gradient(135deg, var(--primary), #2c4fdb);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        header h1 {
            font-size: 2.2em;
            margin-bottom: 15px;
        }
        
        nav {
            display: flex;
            gap: 15px;
        }
        
        nav a {
            color: white;
            text-decoration: none;
            padding: 8px 16px;
            border-radius: 5px;
            background: rgba(255,255,255,0.2);
            transition: background 0.3s;
        }
        
        nav a:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .error {
            background-color: #ffebee;
            color: var(--danger);
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border-left: 4px solid var(--danger);
        }
        
        .success {
            background-color: #e8f5e9;
            color: var(--success);
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border-left: 4px solid var(--success);
        }
        
        .card {
            background: white;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            border: 1px solid #e0e0e0;
        }
        
        .card h2 {
            color: var(--primary);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--dark);
        }
        
        input[type="text"],
        input[type="number"],
        textarea,
        select {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.3s;
        }
        
        input[type="text"]:focus,
        input[type="number"]:focus,
        textarea:focus,
        select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(74, 107, 223, 0.1);
        }
        
        textarea {
            min-height: 100px;
            resize: vertical;
        }
        
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background: var(--primary);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn:hover {
            background: #3a5bd0;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(74, 107, 223, 0.3);
        }
        
        .btn-danger {
            background: var(--danger);
        }
        
        .btn-danger:hover {
            background: #c82333;
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
        }
        
        .btn-success {
            background: var(--success);
        }
        
        .btn-success:hover {
            background: #218838;
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
        }
        
        .option-item {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .option-item input[type="text"] {
            flex: 1;
        }
        
        .questions-list ul {
            list-style: none;
        }
        
        .question-item {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            border: 1px solid #e0e0e0;
            transition: transform 0.2s;
        }
        
        .question-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .question-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .handle {
            cursor: move;
            font-size: 18px;
            color: var(--secondary);
        }
        
        .points {
            background: var(--primary);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
        }
        
        .type {
            background: var(--secondary);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 14px;
        }
        
        .question-text {
            font-size: 18px;
            margin-bottom: 15px;
            line-height: 1.5;
        }
        
        .options-list ul {
            list-style: none;
            margin-left: 20px;
        }
        
        .options-list li {
            padding: 8px 12px;
            margin-bottom: 8px;
            background: #f8f9fa;
            border-radius: 6px;
            border-left: 3px solid #6c757d;
        }
        
        .options-list li.correct {
            background: #e8f5e9;
            border-left-color: var(--success);
        }
        
        .correct-marker {
            color: var(--success);
            font-weight: bold;
            margin-left: 10px;
        }
        
        .question-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #f0f0f0;
        }
        
        .test-info {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 20px;
            align-items: center;
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }
            
            header {
                padding: 15px;
            }
            
            nav {
                flex-direction: column;
                gap: 10px;
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
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1><i class="fas fa-edit"></i> Редактирование теста: <?php echo htmlspecialchars($test['title']); ?></h1>
            <nav>
                <a href="tests.php"><i class="fas fa-arrow-left"></i> Мои тесты</a>
                <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Выйти</a>
            </nav>
        </header>

        <?php if (isset($error)): ?>
            <div class="error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
        <?php endif; ?>

        <div class="card test-info">
            <div>
                <h2><i class="fas fa-info-circle"></i> Информация о тесте</h2>
                <p><strong>Описание:</strong> <?php echo htmlspecialchars($test['description']); ?></p>
                <p><strong>Время на выполнение:</strong> <?php echo $test['time_limit']; ?> минут</p>
            </div>
            <a href="test_settings.php?id=<?php echo $test_id; ?>" class="btn">
                <i class="fas fa-cog"></i> Настройки теста
            </a>
        </div>

        <div class="card add-question">
            <h2><i class="fas fa-plus-circle"></i> Добавить вопрос</h2>
            <form method="POST">
                <div class="form-group">
                    <label><i class="fas fa-question-circle"></i> Текст вопроса:</label>
                    <textarea name="question_text" required placeholder="Введите текст вопроса..."></textarea>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-list-alt"></i> Тип вопроса:</label>
                    <select name="question_type" id="question_type" onchange="toggleOptions()">
                        <option value="text">Текстовый ответ</option>
                        <option value="multiple_choice">Множественный выбор</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-star"></i> Баллы:</label>
                    <input type="number" name="points" value="1" min="1" required>
                </div>
                
                <div id="options_container" style="display: none;">
                    <h3><i class="fas fa-list-ol"></i> Варианты ответов</h3>
                    <div id="options_list">
                        <div class="option-item">
                            <input type="text" name="options[]" placeholder="Вариант ответа 1" required>
                            <input type="radio" name="correct_option" value="0" checked> 
                            <span class="correct-marker">Правильный</span>
                        </div>
                        <div class="option-item">
                            <input type="text" name="options[]" placeholder="Вариант ответа 2" required>
                            <input type="radio" name="correct_option" value="1"> 
                            <span class="correct-marker">Правильный</span>
                        </div>
                    </div>
                    <button type="button" class="btn" onclick="addOption()">
                        <i class="fas fa-plus"></i> Добавить вариант
                    </button>
                </div>
                
                <button type="submit" name="add_question" class="btn btn-success">
                    <i class="fas fa-save"></i> Добавить вопрос
                </button>
            </form>
        </div>

        <div class="card questions-list">
            <h2><i class="fas fa-list"></i> Вопросы теста (<?php echo count($questions); ?>)</h2>
            
            <?php if (empty($questions)): ?>
                <p class="text-center">Вопросы пока не добавлены.</p>
            <?php else: ?>
                <form method="POST" id="order_form">
                    <ul id="sortable">
                        <?php foreach ($questions as $question): ?>
                            <li class="question-item" data-id="<?php echo $question['id']; ?>">
                                <div class="question-header">
                                    <span class="handle"><i class="fas fa-bars"></i></span>
                                    <h3>Вопрос #<?php echo $question['sort_order']; ?></h3>
                                    <span class="points"><?php echo $question['points']; ?> баллов</span>
                                    <span class="type">
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
                                    <div class="options-list">
                                        <h4>Варианты ответов:</h4>
                                        <ul>
                                            <?php foreach ($options as $option): ?>
                                                <li class="<?php echo $option['is_correct'] ? 'correct' : ''; ?>">
                                                    <?php echo htmlspecialchars($option['option_text']); ?>
                                                    <?php if ($option['is_correct']): ?>
                                                        <span class="correct-marker"><i class="fas fa-check"></i> Правильный</span>
                                                    <?php endif; ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="question-actions">
                                    <a href="question_edit.php?id=<?php echo $question['id']; ?>" class="btn">
                                        <i class="fas fa-edit"></i> Редактировать
                                    </a>
                                    <a href="test_edit.php?id=<?php echo $test_id; ?>&delete_question=<?php echo $question['id']; ?>" 
                                       class="btn btn-danger" 
                                       onclick="return confirm('Удалить этот вопрос?')">
                                        <i class="fas fa-trash"></i> Удалить
                                    </a>
                                </div>
                                
                                <input type="hidden" name="order[]" value="<?php echo $question['id']; ?>">
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    
                    <button type="submit" name="update_order" class="btn btn-success">
                        <i class="fas fa-save"></i> Сохранить порядок
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
        
        // Делаем поля обязательными только для множественного выбора
        const optionInputs = document.querySelectorAll('input[name="options[]"]');
        optionInputs.forEach(input => {
            input.required = type === 'multiple_choice';
        });
    }
    
    function addOption() {
        const optionsList = document.getElementById('options_list');
        const optionCount = optionsList.children.length;
        
        const div = document.createElement('div');
        div.className = 'option-item';
        div.innerHTML = `
            <input type="text" name="options[]" placeholder="Вариант ответа ${optionCount + 1}" required>
            <input type="radio" name="correct_option" value="${optionCount}"> 
            <span class="correct-marker">Правильный</span>
            <button type="button" class="btn btn-danger" onclick="removeOption(this)" style="padding: 5px 10px; margin-left: 10px;">
                <i class="fas fa-times"></i>
            </button>
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
            update: function() {
                $('#sortable li').each(function(index) {
                    $(this).find('h3').text('Вопрос #' + (index + 1));
                });
            }
        });
        $("#sortable").disableSelection();
    });
    
    // Инициализация при загрузке
    document.addEventListener('DOMContentLoaded', function() {
        toggleOptions();
    });
    </script>
</body>
</html>