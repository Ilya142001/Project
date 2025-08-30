<?php
include 'config.php';

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

// Получаем вопросы теста
$stmt = $pdo->prepare("SELECT * FROM questions WHERE test_id = ? ORDER BY sort_order ASC");
$stmt->execute([$test_id]);
$questions = $stmt->fetchAll();
?>

<div class="test-editor-modal">
    <div class="test-info">
        <h2>Информация о тесте</h2>
        <p><strong>Название:</strong> <?php echo htmlspecialchars($test['title']); ?></p>
        <p><strong>Описание:</strong> <?php echo htmlspecialchars($test['description']); ?></p>
        <p><strong>Время на выполнение:</strong> <?php echo $test['time_limit']; ?> минут</p>
        <a href="test_settings.php?id=<?php echo $test_id; ?>" class="btn" target="_blank">Настройки теста</a>
    </div>

    <div class="add-question">
        <h2>Добавить вопрос</h2>
        <form method="POST" id="addQuestionForm">
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
            
            <button type="button" onclick="addQuestion()">Добавить вопрос</button>
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
                                <a href="question_edit.php?id=<?php echo $question['id']; ?>" class="btn" target="_blank">Редактировать</a>
                                <button type="button" onclick="deleteQuestion(<?php echo $question['id']; ?>)" 
                                       class="btn btn-danger">Удалить</button>
                            </div>
                            
                            <input type="hidden" name="order[]" value="<?php echo $question['id']; ?>">
                        </li>
                    <?php endforeach; ?>
                </ul>
                
                <button type="button" onclick="saveQuestionOrder()" class="btn">Сохранить порядок</button>
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

function addQuestion() {
    const formData = new FormData(document.getElementById('addQuestionForm'));
    formData.append('add_question', 'true');
    formData.append('test_id', <?php echo $test_id; ?>);
    
    fetch('ajax_questions.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Вопрос добавлен успешно!');
            // Перезагружаем редактор
            loadTestEditor(<?php echo $test_id; ?>);
        } else {
            alert('Ошибка: ' + data.message);
        }
    })
    .catch(error => {
        alert('Ошибка: ' + error);
    });
}

function deleteQuestion(questionId) {
    if (!confirm('Удалить этот вопрос?')) return;
    
    fetch('ajax_questions.php?delete_question=' + questionId + '&test_id=<?php echo $test_id; ?>')
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Вопрос удален успешно!');
            loadTestEditor(<?php echo $test_id; ?>);
        } else {
            alert('Ошибка: ' + data.message);
        }
    })
    .catch(error => {
        alert('Ошибка: ' + error);
    });
}

function saveQuestionOrder() {
    const order = [];
    $('#sortable li').each(function(index) {
        order.push($(this).data('id'));
    });
    
    fetch('ajax_questions.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            update_order: true,
            order: order,
            test_id: <?php echo $test_id; ?>
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Порядок сохранен успешно!');
            loadTestEditor(<?php echo $test_id; ?>);
        } else {
            alert('Ошибка: ' + data.message);
        }
    })
    .catch(error => {
        alert('Ошибка: ' + error);
    });
}

function loadTestEditor(testId) {
    fetch('test_edit_modal.php?id=' + testId)
        .then(response => response.text())
        .then(data => {
            document.getElementById('testEditorContent').innerHTML = data;
            initializeTestEditor();
        })
        .catch(error => {
            document.getElementById('testEditorContent').innerHTML = 
                '<div class="error">Ошибка загрузки редактора: ' + error + '</div>';
        });
}

// Инициализация после загрузки
$(document).ready(function() {
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

<style>
.test-editor-modal {
    padding: 10px;
}

.test-info {
    background: #f9f9f9;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
    border-left: 4px solid var(--primary);
}

.add-question {
    background: #f5f7fa;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.questions-list {
    margin-top: 20px;
}

.question-item {
    background: white;
    padding: 15px;
    margin-bottom: 15px;
    border-radius: 8px;
    border: 1px solid #e0e0e0;
    position: relative;
}

.question-header {
    display: flex;
    align-items: center;
    margin-bottom: 10px;
    padding-bottom: 10px;
    border-bottom: 1px solid #eee;
}

.handle {
    cursor: move;
    margin-right: 10px;
    font-size: 18px;
    color: var(--gray);
}

.question-header h3 {
    margin: 0;
    margin-right: 15px;
    font-size: 16px;
}

.points, .type {
    margin-right: 15px;
    font-size: 14px;
    color: var(--gray);
}

.question-text {
    margin-bottom: 15px;
    font-size: 15px;
    line-height: 1.5;
}

.options-list {
    margin: 15px 0;
    padding: 15px;
    background: #f9f9f9;
    border-radius: 6px;
}

.options-list h4 {
    margin: 0 0 10px 0;
    font-size: 14px;
    color: var(--secondary);
}

.options-list ul {
    list-style: none;
    padding: 0;
    margin: 0;
}

.options-list li {
    padding: 8px 12px;
    margin: 5px 0;
    background: white;
    border-radius: 4px;
    border-left: 3px solid #ddd;
}

.options-list li.correct {
    border-left-color: var(--success);
    background: #f0fff4;
}

.correct-marker {
    color: var(--success);
    margin-left: 10px;
    font-weight: bold;
}

.question-actions {
    display: flex;
    gap: 10px;
    margin-top: 15px;
}

.option-item {
    display: flex;
    align-items: center;
    margin-bottom: 10px;
    gap: 10px;
}

.option-item input[type="text"] {
    flex: 1;
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
}

.form-group {
    margin-bottom: 15px;
}

.form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 500;
    color: var(--secondary);
}

.form-group textarea,
.form-group input[type="number"],
.form-group select {
    width: 100%;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
}

.error {
    color: var(--accent);
    padding: 15px;
    background: #fee;
    border-radius: 6px;
    border-left: 4px solid var(--accent);
}
</style>