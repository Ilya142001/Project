<?php
include 'config.php';
session_start();

// Проверяем, авторизован ли пользователь
if (!isset($_SESSION['user_id'])) {
    echo "<div class='error'>Необходима авторизация</div>";
    exit;
}

// Получаем информацию о пользователе
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

// Проверяем права доступа
if ($user['role'] == 'student') {
    echo "<div class='error'>Доступ запрещен</div>";
    exit;
}

// Получаем ID теста
$test_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Получаем информацию о тесте
$stmt = $pdo->prepare("SELECT * FROM tests WHERE id = ? AND created_by = ?");
$stmt->execute([$test_id, $_SESSION['user_id']]);
$test = $stmt->fetch();

if (!$test) {
    echo "<div class='error'>Тест не найден</div>";
    exit;
}

// Обработка обновления информации о тесте
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_test'])) {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $time_limit = intval($_POST['time_limit']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $is_published = isset($_POST['is_published']) ? 1 : 0;
    
    if (!empty($title)) {
        $stmt = $pdo->prepare("UPDATE tests SET title = ?, description = ?, time_limit = ?, is_active = ?, is_published = ? WHERE id = ?");
        $stmt->execute([$title, $description, $time_limit, $is_active, $is_published, $test_id]);
        
        echo "<div class='success'>Информация о тесте обновлена!</div>";
        // Обновляем данные теста
        $stmt = $pdo->prepare("SELECT * FROM tests WHERE id = ?");
        $stmt->execute([$test_id]);
        $test = $stmt->fetch();
    }
}

// Обработка добавления вопроса
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_question'])) {
    $question_text = trim($_POST['question_text']);
    $question_type = $_POST['question_type'];
    $points = intval($_POST['points']);
    
    if (!empty($question_text) && $points > 0) {
        try {
            $pdo->beginTransaction();
            
            // Добавляем вопрос
            $stmt = $pdo->prepare("INSERT INTO questions (question_text, question_type, points, created_by) VALUES (?, ?, ?, ?)");
            $stmt->execute([$question_text, $question_type, $points, $_SESSION['user_id']]);
            $question_id = $pdo->lastInsertId();
            
            // Получаем максимальный порядковый номер
            $stmt_order = $pdo->prepare("SELECT MAX(sort_order) as max_order FROM test_questions WHERE test_id = ?");
            $stmt_order->execute([$test_id]);
            $max_order = $stmt_order->fetch()['max_order'] ?? 0;
            
            // Связываем вопрос с тестом
            $stmt = $pdo->prepare("INSERT INTO test_questions (test_id, question_id, sort_order) VALUES (?, ?, ?)");
            $stmt->execute([$test_id, $question_id, $max_order + 1]);
            
            // Добавляем варианты ответов для множественного выбора
            if ($question_type === 'multiple_choice' && isset($_POST['options'])) {
                $options = $_POST['options'];
                $correct_option = intval($_POST['correct_option']);
                
                foreach ($options as $index => $option_text) {
                    if (!empty(trim($option_text))) {
                        $is_correct = ($index == $correct_option) ? 1 : 0;
                        $stmt = $pdo->prepare("INSERT INTO question_options (question_id, option_text, is_correct, sort_order) VALUES (?, ?, ?, ?)");
                        $stmt->execute([$question_id, trim($option_text), $is_correct, $index]);
                    }
                }
            }
            
            $pdo->commit();
            echo "<div class='success'>Вопрос успешно добавлен!</div>";
            
        } catch (Exception $e) {
            $pdo->rollBack();
            echo "<div class='error'>Ошибка при добавлении вопроса: " . $e->getMessage() . "</div>";
        }
    } else {
        echo "<div class='error'>Заполните все обязательные поля</div>";
    }
}

// Обработка удаления вопроса
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_question'])) {
    $question_id = intval($_POST['delete_question']);
    
    try {
        $pdo->beginTransaction();
        
        // Удаляем связь вопроса с тестом
        $stmt = $pdo->prepare("DELETE FROM test_questions WHERE test_id = ? AND question_id = ?");
        $stmt->execute([$test_id, $question_id]);
        
        // Удаляем варианты ответов
        $stmt = $pdo->prepare("DELETE FROM question_options WHERE question_id = ?");
        $stmt->execute([$question_id]);
        
        // Удаляем сам вопрос
        $stmt = $pdo->prepare("DELETE FROM questions WHERE id = ? AND created_by = ?");
        $stmt->execute([$question_id, $_SESSION['user_id']]);
        
        $pdo->commit();
        echo "<div class='success'>Вопрос удален!</div>";
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "<div class='error'>Ошибка при удалении вопроса: " . $e->getMessage() . "</div>";
    }
}

// Обработка сохранения порядка вопросов
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_order'])) {
    if (isset($_POST['order'])) {
        $order = json_decode($_POST['order'], true);
        
        try {
            $pdo->beginTransaction();
            
            foreach ($order as $item) {
                $stmt = $pdo->prepare("UPDATE test_questions SET sort_order = ? WHERE test_id = ? AND question_id = ?");
                $stmt->execute([$item['order'], $test_id, $item['id']]);
            }
            
            $pdo->commit();
            echo "<div class='success'>Порядок вопросов сохранен!</div>";
            
        } catch (Exception $e) {
            $pdo->rollBack();
            echo "<div class='error'>Ошибка при сохранении порядка: " . $e->getMessage() . "</div>";
        }
    }
}

// Получаем вопросы теста
$stmt = $pdo->prepare("SELECT q.*, tq.sort_order 
                      FROM questions q 
                      INNER JOIN test_questions tq ON q.id = tq.question_id 
                      WHERE tq.test_id = ? 
                      ORDER BY tq.sort_order ASC");
$stmt->execute([$test_id]);
$questions = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Редактор теста</title>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css">
</head>
<body>
<div class="test-editor-container">
    <div class="editor-header">
        <h1 class="editor-title">Редактор теста</h1>
        <div class="test-meta">
            <span class="test-badge">ID: <?php echo $test_id; ?></span>
            <span class="questions-count"><?php echo count($questions); ?> вопросов</span>
        </div>
    </div>

    <div class="editor-grid">
        <!-- Блок информации о тесте -->
        <div class="editor-card test-info-card">
            <div class="card-header">
                <h2>Информация о тесте</h2>
            </div>
            <div class="card-content">
                <form method="POST" class="test-info-form">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Название теста:*</label>
                            <input type="text" name="title" class="form-input" value="<?php echo htmlspecialchars($test['title']); ?>" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Описание:</label>
                            <textarea name="description" class="form-textarea" placeholder="Описание теста..."><?php echo htmlspecialchars($test['description']); ?></textarea>
                        </div>
                    </div>

                    <div class="form-row columns-2">
                        <div class="form-group">
                            <label class="form-label">Время на выполнение (мин):*</label>
                            <input type="number" name="time_limit" class="form-input" value="<?php echo $test['time_limit']; ?>" min="1" max="300" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Статус:</label>
                            <div class="checkbox-group">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="is_active" <?php echo $test['is_active'] ? 'checked' : ''; ?>>
                                    <span class="checkbox-custom"></span>
                                    Активный
                                </label>
                                <label class="checkbox-label">
                                    <input type="checkbox" name="is_published" <?php echo $test['is_published'] ? 'checked' : ''; ?>>
                                    <span class="checkbox-custom"></span>
                                    Опубликован
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" name="update_test" class="btn btn-primary">
                            <i>💾</i> Сохранить изменения
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Блок добавления вопроса -->
        <div class="editor-card add-question-card">
            <div class="card-header">
                <h2>Добавить новый вопрос</h2>
            </div>
            <div class="card-content">
                <form method="POST" id="addQuestionForm" class="question-form">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Текст вопроса:*</label>
                            <textarea name="question_text" class="form-textarea" placeholder="Введите текст вопроса..." required></textarea>
                        </div>
                    </div>

                    <div class="form-row columns-2">
                        <div class="form-group">
                            <label class="form-label">Тип вопроса:*</label>
                            <select name="question_type" id="question_type" class="form-select" onchange="toggleOptions()" required>
                                <option value="text">Текстовый ответ</option>
                                <option value="multiple_choice">Множественный выбор</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Баллы:*</label>
                            <input type="number" name="points" class="form-input" value="1" min="1" max="100" required>
                        </div>
                    </div>

                    <div id="options_container" class="options-container" style="display: none;">
                        <div class="options-header">
                            <h3>Варианты ответов*</h3>
                            <button type="button" class="btn btn-sm btn-outline" onclick="addOption()">
                                <i>+</i> Добавить вариант
                            </button>
                        </div>
                        
                        <div id="options_list" class="options-list">
                            <div class="option-item">
                                <input type="text" name="options[]" class="option-input" placeholder="Вариант ответа 1">
                                <label class="option-correct">
                                    <input type="radio" name="correct_option" value="0" checked required>
                                    <span class="radio-checkmark"></span>
                                    Правильный
                                </label>
                                <button type="button" class="btn btn-sm btn-danger" onclick="removeOption(this)" style="display:none;">×</button>
                            </div>
                            <div class="option-item">
                                <input type="text" name="options[]" class="option-input" placeholder="Вариант ответа 2">
                                <label class="option-correct">
                                    <input type="radio" name="correct_option" value="1">
                                    <span class="radio-checkmark"></span>
                                    Правильный
                                </label>
                                <button type="button" class="btn btn-sm btn-danger" onclick="removeOption(this)">×</button>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" name="add_question" class="btn btn-primary btn-lg">
                            <i>+</i> Добавить вопрос
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Список вопросов -->
        <div class="editor-card questions-card">
            <div class="card-header">
                <h2>Вопросы теста</h2>
                <div class="questions-stats">
                    <span class="stat-item">Всего: <?php echo count($questions); ?></span>
                    <span class="stat-item">Баллов: <?php echo array_sum(array_column($questions, 'points')); ?></span>
                </div>
            </div>
            
            <div class="card-content">
                <?php if (empty($questions)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">📝</div>
                        <h3>Вопросы пока не добавлены</h3>
                        <p>Начните с добавления первого вопроса</p>
                    </div>
                <?php else: ?>
                    <div class="sortable-container">
                        <div id="sortable" class="questions-list">
                            <?php foreach ($questions as $index => $question): ?>
                                <div class="question-item" data-id="<?php echo $question['id']; ?>">
                                    <div class="question-drag-handle">
                                        <div class="drag-icon">⋮⋮</div>
                                        <div class="question-number"><?php echo $index + 1; ?></div>
                                    </div>
                                    
                                    <div class="question-content">
                                        <div class="question-header">
                                            <div class="question-meta">
                                                <span class="question-type-badge <?php echo $question['question_type']; ?>">
                                                    <?php echo $question['question_type'] == 'text' ? 'Текст' : 'Выбор'; ?>
                                                </span>
                                                <span class="question-points">
                                                    <?php echo $question['points']; ?> баллов
                                                </span>
                                            </div>
                                            <div class="question-actions">
                                                <a href="question_edit.php?id=<?php echo $question['id']; ?>" 
                                                   class="btn btn-sm btn-outline" target="_blank">
                                                    <i>✏️</i> Редактировать
                                                </a>
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Удалить этот вопрос?')">
                                                    <input type="hidden" name="delete_question" value="<?php echo $question['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-danger">
                                                        <i>🗑️</i> Удалить
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                        
                                        <div class="question-text">
                                            <?php echo nl2br(htmlspecialchars($question['question_text'])); ?>
                                        </div>
                                        
                                        <?php if ($question['question_type'] == 'multiple_choice'): ?>
                                            <?php
                                            $stmt = $pdo->prepare("SELECT * FROM question_options WHERE question_id = ? ORDER BY sort_order ASC");
                                            $stmt->execute([$question['id']]);
                                            $options = $stmt->fetchAll();
                                            ?>
                                            <?php if (!empty($options)): ?>
                                                <div class="question-options">
                                                    <h4>Варианты ответов:</h4>
                                                    <div class="options-grid">
                                                        <?php foreach ($options as $option): ?>
                                                            <div class="option-display <?php echo $option['is_correct'] ? 'correct' : ''; ?>">
                                                                <span class="option-text"><?php echo htmlspecialchars($option['option_text']); ?></span>
                                                                <?php if ($option['is_correct']): ?>
                                                                    <span class="correct-indicator">✓ Правильный</span>
                                                                <?php endif; ?>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <form method="POST" id="order_form" class="save-order-container">
                        <input type="hidden" name="update_order" value="1">
                        <input type="hidden" name="order" id="order_data" value="">
                        <button type="submit" class="btn btn-success">
                            <i>💾</i> Сохранить порядок вопросов
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function toggleOptions() {
    const type = document.getElementById('question_type').value;
    const container = document.getElementById('options_container');
    container.style.display = type === 'multiple_choice' ? 'block' : 'none';
    
    // Обновляем required атрибуты
    const optionInputs = document.querySelectorAll('.option-input');
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
        <input type="text" name="options[]" class="option-input" placeholder="Вариант ответа ${optionCount + 1}">
        <label class="option-correct">
            <input type="radio" name="correct_option" value="${optionCount}">
            <span class="radio-checkmark"></span>
            Правильный
        </label>
        <button type="button" class="btn btn-sm btn-danger" onclick="removeOption(this)">×</button>
    `;
    
    optionsList.appendChild(div);
    
    // Показываем кнопку удаления у первого элемента, если теперь есть больше 2 элементов
    if (optionCount + 1 > 2) {
        optionsList.querySelectorAll('.option-item').forEach(item => {
            item.querySelector('.btn-danger').style.display = 'inline-block';
        });
    }
}

function removeOption(button) {
    const optionsList = document.getElementById('options_list');
    const optionItems = optionsList.querySelectorAll('.option-item');
    
    if (optionItems.length > 2) {
        const optionItem = button.parentElement;
        const index = Array.from(optionItems).indexOf(optionItem);
        
        // Если удаляем выбранный правильный вариант, выбираем первый
        const radioToRemove = optionItem.querySelector('input[type="radio"]');
        if (radioToRemove.checked) {
            optionItems[0].querySelector('input[type="radio"]').checked = true;
        }
        
        optionItem.remove();
        
        // Пересчитываем радиокнопки
        document.querySelectorAll('.option-item').forEach((item, newIndex) => {
            const radio = item.querySelector('input[type="radio"]');
            radio.value = newIndex;
        });
        
        // Скрываем кнопки удаления, если осталось только 2 элемента
        if (optionItems.length - 1 === 2) {
            optionsList.querySelectorAll('.option-item').forEach(item => {
                item.querySelector('.btn-danger').style.display = 'none';
            });
        }
    }
}

// Инициализация после загрузки
document.addEventListener('DOMContentLoaded', function() {
    // Инициализация сортировки
    if (typeof $("#sortable").sortable === 'function') {
        $("#sortable").sortable({
            handle: ".question-drag-handle",
            update: function() {
                $('#sortable .question-item').each(function(index) {
                    $(this).find('.question-number').text(index + 1);
                });
            }
        });
        $("#sortable").disableSelection();
    }
    
    // Обработка сохранения порядка
    document.getElementById('order_form')?.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const order = [];
        document.querySelectorAll('#sortable .question-item').forEach((item, index) => {
            order.push({
                id: item.dataset.id,
                order: index + 1
            });
        });
        
        document.getElementById('order_data').value = JSON.stringify(order);
        this.submit();
    });
    
    // Инициализация отображения опций при загрузке
    toggleOptions();
});
</script>

<style>
:root {
    --primary: #4361ee;
    --primary-dark: #3a56d4;
    --secondary: #6c757d;
    --success: #28a745;
    --danger: #dc3545;
    --warning: #ffc107;
    --info: #17a2b8;
    --light: #f8f9fa;
    --dark: #343a40;
    --gray: #6c757d;
    --gray-light: #dee2e6;
    --border-radius: 12px;
    --shadow: 0 4px 6px rgba(0, 0, 0, 0.07);
    --shadow-hover: 0 8px 15px rgba(0, 0, 0, 0.1);
    --transition: all 0.3s ease;
}

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background-color: #f5f7fa;
    color: #333;
    line-height: 1.6;
}

.test-editor-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}

.editor-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    padding-bottom: 20px;
    border-bottom: 2px solid var(--gray-light);
}

.editor-title {
    color: var(--dark);
    margin: 0;
    font-size: 2.2rem;
    font-weight: 700;
}

.test-meta {
    display: flex;
    gap: 15px;
}

.test-badge, .questions-count {
    padding: 8px 16px;
    background: var(--light);
    border-radius: 20px;
    font-size: 0.9rem;
    font-weight: 500;
}

.editor-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 25px;
}

.editor-card {
    background: white;
    border-radius: var(--border-radius);
    box-shadow: var(--shadow);
    transition: var(--transition);
    border: 1px solid var(--gray-light);
}

.editor-card:hover {
    box-shadow: var(--shadow-hover);
}

.questions-card {
    grid-column: 1 / -1;
}

.card-header {
    padding: 20px 25px;
    border-bottom: 1px solid var(--gray-light);
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: var(--border-radius) var(--border-radius) 0 0;
}

.card-header h2 {
    margin: 0;
    font-size: 1.4rem;
    font-weight: 600;
}

.card-content {
    padding: 25px;
}

/* Формы */
.form-row {
    margin-bottom: 20px;
}

.columns-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.form-label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: var(--dark);
    font-size: 0.95rem;
}

.form-input, .form-select, .form-textarea {
    width: 100%;
    padding: 12px 16px;
    border: 2px solid var(--gray-light);
    border-radius: 8px;
    font-size: 1rem;
    transition: var(--transition);
    background: var(--light);
}

.form-input:focus, .form-select:focus, .form-textarea:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
}

.form-textarea {
    min-height: 100px;
    resize: vertical;
}

/* Кнопки */
.btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    transition: var(--transition);
    font-size: 0.95rem;
}

.btn-primary {
    background: var(--primary);
    color: white;
}

.btn-primary:hover {
    background: var(--primary-dark);
    transform: translateY(-2px);
}

.btn-success {
    background: var(--success);
    color: white;
}

.btn-danger {
    background: var(--danger);
    color: white;
}

.btn-outline {
    background: transparent;
    border: 2px solid var(--gray-light);
    color: var(--dark);
}

.btn-outline:hover {
    border-color: var(--primary);
    color: var(--primary);
}

.btn-sm {
    padding: 6px 12px;
    font-size: 0.85rem;
}

.btn-lg {
    padding: 14px 28px;
    font-size: 1.1rem;
}

/* Вопросы */
.questions-list {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.question-item {
    display: flex;
    background: white;
    border: 2px solid var(--gray-light);
    border-radius: var(--border-radius);
    overflow: hidden;
    transition: var(--transition);
}

.question-item:hover {
    border-color: var(--primary);
    transform: translateY(-2px);
    box-shadow: var(--shadow);
}

.question-drag-handle {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: var(--light);
    cursor: move;
    min-width: 80px;
}

.drag-icon {
    font-size: 1.2rem;
    color: var(--gray);
    margin-bottom: 10px;
}

.question-number {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--primary);
}

.question-content {
    flex: 1;
    padding: 20px;
}

.question-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 15px;
}

.question-meta {
    display: flex;
    gap: 12px;
    align-items: center;
}

.question-type-badge {
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
}

.question-type-badge.text {
    background: #e3f2fd;
    color: #1976d2;
}

.question-type-badge.multiple_choice {
    background: #f3e5f5;
    color: #7b1fa2;
}

.question-points {
    font-weight: 600;
    color: var(--success);
}

.question-actions {
    display: flex;
    gap: 10px;
}

.question-text {
    font-size: 1.1rem;
    line-height: 1.6;
    color: var(--dark);
    margin-bottom: 20px;
}

/* Опции */
.options-container {
    margin-top: 25px;
    padding: 20px;
    background: var(--light);
    border-radius: var(--border-radius);
}

.options-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.options-header h3 {
    margin: 0;
    font-size: 1.2rem;
    color: var(--dark);
}

.options-list {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.option-item {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 15px;
    background: white;
    border-radius: 8px;
    border: 1px solid var(--gray-light);
}

.option-input {
    flex: 1;
    padding: 10px 15px;
    border: 2px solid var(--gray-light);
    border-radius: 6px;
    font-size: 0.95rem;
}

.option-correct {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    font-weight: 500;
    white-space: nowrap;
}

.radio-checkmark {
    width: 18px;
    height: 18px;
    border: 2px solid var(--gray);
    border-radius: 50%;
    display: inline-block;
    position: relative;
}

input[type="radio"]:checked + .radio-checkmark::after {
    content: '';
    position: absolute;
    top: 2px;
    left: 2px;
    width: 10px;
    height: 10px;
    background: var(--primary);
    border-radius: 50%;
}

input[type="radio"] {
    display: none;
}

/* Отображение вариантов */
.question-options {
    margin-top: 20px;
}

.question-options h4 {
    margin: 0 0 15px 0;
    font-size: 1rem;
    color: var(--secondary);
}

.options-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 12px;
}

.option-display {
    padding: 15px;
    background: var(--light);
    border-radius: 8px;
    border-left: 4px solid var(--gray);
}

.option-display.correct {
    background: #f0fff4;
    border-left-color: var(--success);
}

.option-text {
    display: block;
    margin-bottom: 8px;
    font-weight: 500;
}

.correct-indicator {
    font-size: 0.85rem;
    color: var(--success);
    font-weight: 600;
}

/* Состояния */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: var(--gray);
}

.empty-icon {
    font-size: 4rem;
    margin-bottom: 20px;
    opacity: 0.5;
}

.save-order-container {
    margin-top: 30px;
    padding-top: 20px;
    border-top: 2px solid var(--gray-light);
    text-align: center;
}

/* Чекбоксы */
.checkbox-group {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.checkbox-label {
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
}

.checkbox-custom {
    width: 20px;
    height: 20px;
    border: 2px solid var(--gray);
    border-radius: 4px;
    display: inline-block;
    position: relative;
}

input[type="checkbox"]:checked + .checkbox-custom::after {
    content: '✓';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    color: var(--primary);
    font-weight: bold;
}

input[type="checkbox"] {
    display: none;
}

/* Уведомления */
.success {
    background: #d4edda;
    color: #155724;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
    border-left: 4px solid var(--success);
}

.error {
    background: #f8d7da;
    color: #721c24;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
    border-left: 4px solid var(--danger);
}

/* Адаптивность */
@media (max-width: 1024px) {
    .editor-grid {
        grid-template-columns: 1fr;
    }
    
    .columns-2 {
        grid-template-columns: 1fr;
    }
    
    .question-header {
        flex-direction: column;
        gap: 15px;
    }
    
    .question-actions {
        width: 100%;
        justify-content: center;
    }
}

@media (max-width: 768px) {
    .test-editor-container {
        padding: 15px;
    }
    
    .editor-header {
        flex-direction: column;
        gap: 15px;
        text-align: center;
    }
    
    .question-item {
        flex-direction: column;
    }
    
    .question-drag-handle {
        flex-direction: row;
        justify-content: center;
        padding: 15px;
    }
    
    .drag-icon {
        margin-bottom: 0;
        margin-right: 10px;
    }
    
    .option-item {
        flex-direction: column;
        align-items: stretch;
    }
}

/* Анимации */
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}

.editor-card {
    animation: fadeIn 0.6s ease;
}

.question-item {
    animation: fadeIn 0.4s ease;
}

.form-actions {
    margin-top: 25px;
    text-align: center;
}

.questions-stats {
    display: flex;
    gap: 15px;
}

.stat-item {
    padding: 5px 12px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 15px;
    font-size: 0.9rem;
}
</style>
</body>
</html>