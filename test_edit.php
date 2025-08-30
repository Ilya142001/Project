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
            // Удаляем варианты ответов
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
    <link rel="stylesheet" href="css/style.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://code.jquery.com/ui/1.13.1/jquery-ui.min.js"></script>
</head>
<body>
    <div class="container">
        <header>
            <h1>Редактирование теста: <?php echo htmlspecialchars($test['title']); ?></h1>
            <nav>
                <a href="tests.php">Мои тесты</a>
                <a href="logout.php">Выйти</a>
            </nav>
        </header>

        <?php if (isset($error)): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>

        <div class="test-info">
            <h2>Информация о тесте</h2>
            <p><strong>Описание:</strong> <?php echo htmlspecialchars($test['description']); ?></p>
            <p><strong>Время на выполнение:</strong> <?php echo $test['time_limit']; ?> минут</p>
            <a href="test_settings.php?id=<?php echo $test_id; ?>" class="btn">Настройки теста</a>
        </div>

        <div class="add-question">
            <h2>Добавить вопрос</h2>
            <form method="POST">
                <div class="form-group">
                    <label>Текст вопроса:</label>
                    <textarea name="question_text" required></textarea>
                </div>
                
                <div class="form-group">
                    <label>Тип вопроса:</label>
                    <select name="question_type" id="question_type" onchange="toggleOptions()">
                        <option value="text">Текстовый ответ</option>
                        <option value="multiple_choice">Множественный выбор</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Баллы:</label>
                    <input type="number" name="points" value="1" min="1" required>
                </div>
                
                <div id="options_container" style="display: none;">
                    <h3>Варианты ответов</h3>
                    <div id="options_list">
                        <div class="option-item">
                            <input type="text" name="options[]" placeholder="Вариант ответа">
                            <input type="radio" name="correct_option" value="0" checked> Правильный
                        </div>
                        <div class="option-item">
                            <input type="text" name="options[]" placeholder="Вариант ответа">
                            <input type="radio" name="correct_option" value="1"> Правильный
                        </div>
                    </div>
                    <button type="button" onclick="addOption()">Добавить вариант</button>
                </div>
                
                <button type="submit" name="add_question">Добавить вопрос</button>
            </form>
        </div>

        <div class="questions-list">
            <h2>Вопросы теста (<?php echo count($questions); ?>)</h2>
            
            <?php if (empty($questions)): ?>
                <p>Вопросы пока не добавлены.</p>
            <?php else: ?>
                <form method="POST" id="order_form">
                    <ul id="sortable">
                        <?php foreach ($questions as $question): ?>
                            <li class="question-item" data-id="<?php echo $question['id']; ?>">
                                <div class="question-header">
                                    <span class="handle">☰</span>
                                    <h3>Вопрос #<?php echo $question['sort_order']; ?></h3>
                                    <span class="points">(<?php echo $question['points']; ?> баллов)</span>
                                    <span class="type"><?php echo $question['question_type'] == 'text' ? 'Текстовый' : 'Множественный выбор'; ?></span>
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
                                                        <span class="correct-marker">✓</span>
                                                    <?php endif; ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="question-actions">
                                    <a href="question_edit.php?id=<?php echo $question['id']; ?>" class="btn">Редактировать</a>
                                    <a href="test_edit.php?id=<?php echo $test_id; ?>&delete_question=<?php echo $question['id']; ?>" 
                                       class="btn btn-danger" 
                                       onclick="return confirm('Удалить этот вопрос?')">Удалить</a>
                                </div>
                                
                                <input type="hidden" name="order[]" value="<?php echo $question['id']; ?>">
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    
                    <button type="submit" name="update_order" class="btn">Сохранить порядок</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

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
            <input type="text" name="options[]" placeholder="Вариант ответа">
            <input type="radio" name="correct_option" value="${optionCount}"> Правильный
        `;
        
        optionsList.appendChild(div);
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
    </script>
</body>
</html>