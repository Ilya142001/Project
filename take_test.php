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

// Проверяем, передан ли ID теста
if (!isset($_GET['id'])) {
    header("Location: tests.php");
    exit;
}

$test_id = $_GET['id'];

// Получаем информацию о тесте
$stmt = $pdo->prepare("SELECT * FROM tests WHERE id = ?");
$stmt->execute([$test_id]);
$test = $stmt->fetch();

if (!$test) {
    header("Location: tests.php");
    exit;
}

// Проверяем, не проходил ли пользователь уже этот тест
$stmt = $pdo->prepare("SELECT * FROM test_results WHERE user_id = ? AND test_id = ?");
$stmt->execute([$_SESSION['user_id'], $test_id]);
$existing_result = $stmt->fetch();

if ($existing_result) {
    header("Location: test_result.php?id=" . $existing_result['id']);
    exit;
}

// Получаем вопросы для теста
$stmt = $pdo->prepare("SELECT * FROM questions WHERE test_id = ? ORDER BY sort_order, id");
$stmt->execute([$test_id]);
$questions = $stmt->fetchAll();

// Получаем варианты ответов для каждого вопроса
foreach ($questions as &$question) {
    $stmt = $pdo->prepare("SELECT * FROM question_options WHERE question_id = ? ORDER BY sort_order, id");
    $stmt->execute([$question['id']]);
    $question['options'] = $stmt->fetchAll();
}
unset($question);

// Обработка отправки теста
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $start_time = date('Y-m-d H:i:s');
    $score = 0;
    $total_points = 0;
    
    try {
        $pdo->beginTransaction();
        
        // Сначала создаем запись о результате теста
        $stmt = $pdo->prepare("INSERT INTO test_results (user_id, test_id, score, total_points, start_time) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$_SESSION['user_id'], $test_id, 0, 0, $start_time]);
        $result_id = $pdo->lastInsertId();
        
        // Проверяем ответы на каждый вопрос
        foreach ($questions as $question) {
            $total_points += $question['points'];
            $user_answer = $_POST['question_' . $question['id']] ?? '';
            
            // Инициализируем переменные
            $is_correct = 0; // Всегда целое число
            $points_earned = 0;
            $answer_text = '';

            if ($question['question_type'] === 'text') {
                // Для текстовых вопросов
                $answer_text = trim($user_answer);
                $is_correct = 0; // Для текстовых всегда 0 (не проверяем автоматически)
                $points_earned = 0;
            } else {
                // Для вопросов с множественным выбором
                $stmt = $pdo->prepare("SELECT id FROM question_options WHERE question_id = ? AND is_correct = 1");
                $stmt->execute([$question['id']]);
                $correct_options = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                // Обрабатываем ответ пользователя
                if (empty($user_answer)) {
                    // Пользователь ничего не выбрал
                    $answer_text = '';
                    $is_correct = 0;
                    $points_earned = 0;
                } else {
                    $user_answers = is_array($user_answer) ? $user_answer : [$user_answer];
                    $answer_text = implode(',', $user_answers);
                    
                    // Проверяем правильность ответа
                    $is_correct = (count($user_answers) === count($correct_options)) && 
                                 empty(array_diff($user_answers, $correct_options));
                    
                    $points_earned = $is_correct ? $question['points'] : 0;
                    $score += $points_earned;
                }
            }
            
            // Сохраняем ответ пользователя
            $stmt = $pdo->prepare("INSERT INTO user_answers (result_id, question_id, answer_text, is_correct, points_earned) 
                                  VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$result_id, $question['id'], $answer_text, (int)$is_correct, $points_earned]);
        }
        
        // Обновляем общий результат
        $percentage = $total_points > 0 ? round(($score / $total_points) * 100, 2) : 0;
        $passed = $percentage >= 70; // 70% для прохождения
        
        $stmt = $pdo->prepare("UPDATE test_results SET score = ?, total_points = ?, percentage = ?, passed = ?, end_time = ? WHERE id = ?");
        $stmt->execute([$score, $total_points, $percentage, (int)$passed, date('Y-m-d H:i:s'), $result_id]);
        
        $pdo->commit();
        
        header("Location: test_result.php?id=" . $result_id);
        exit;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Ошибка при сохранении результатов: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Прохождение теста: <?php echo htmlspecialchars($test['title']); ?></title>
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
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .test-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 800px;
            overflow: hidden;
        }
        
        .test-header {
            background: var(--secondary);
            color: white;
            padding: 25px;
            position: relative;
        }
        
        .test-title {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 10px;
        }
        
        .test-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 15px;
            font-size: 14px;
            opacity: 0.9;
        }
        
        .timer {
            background: rgba(255, 255, 255, 0.2);
            padding: 8px 15px;
            border-radius: 20px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .timer.warning {
            background: var(--warning);
            color: white;
        }
        
        .timer.danger {
            background: var(--danger);
            color: white;
            animation: pulse 1s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
        
        .progress-container {
            background: rgba(255, 255, 255, 0.2);
            height: 6px;
            border-radius: 3px;
            margin-top: 15px;
            overflow: hidden;
        }
        
        .progress-bar {
            height: 100%;
            background: var(--primary);
            border-radius: 3px;
            transition: width 0.3s ease;
            width: 0%;
        }
        
        .test-content {
            padding: 30px;
            max-height: 60vh;
            overflow-y: auto;
        }
        
        .question {
            margin-bottom: 30px;
            padding: 20px;
            background: var(--light);
            border-radius: 15px;
            border-left: 4px solid var(--primary);
        }
        
        .question-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        
        .question-text {
            font-size: 18px;
            font-weight: 500;
            color: var(--secondary);
            flex: 1;
        }
        
        .question-points {
            background: var(--primary);
            color: white;
            padding: 5px 12px;
            border-radius: 15px;
            font-size: 14px;
            font-weight: 600;
            margin-left: 15px;
        }
        
        .options-container {
            margin-top: 15px;
        }
        
        .option {
            margin-bottom: 12px;
        }
        
        .option input[type="radio"],
        .option input[type="checkbox"] {
            display: none;
        }
        
        .option label {
            display: block;
            padding: 15px;
            background: white;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 16px;
        }
        
        .option label:hover {
            border-color: var(--primary);
            background: rgba(52, 152, 219, 0.05);
        }
        
        .option input[type="radio"]:checked + label,
        .option input[type="checkbox"]:checked + label {
            border-color: var(--primary);
            background: rgba(52, 152, 219, 0.1);
            font-weight: 500;
        }
        
        .text-answer {
            width: 100%;
            padding: 15px;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            font-size: 16px;
            resize: vertical;
            min-height: 100px;
            transition: border-color 0.3s ease;
        }
        
        .text-answer:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }
        
        .test-footer {
            padding: 25px;
            background: #f8f9fa;
            border-top: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .navigation-buttons {
            display: flex;
            gap: 10px;
        }
        
        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }
        
        .btn-secondary {
            background: var(--gray);
            color: white;
        }
        
        .btn-secondary:hover {
            background: #6c757d;
        }
        
        .btn-success {
            background: var(--success);
            color: white;
        }
        
        .btn-success:hover {
            background: #27ae60;
            transform: translateY(-2px);
        }
        
        .question-counter {
            font-size: 14px;
            color: var(--gray);
            font-weight: 500;
        }
        
        .error-message {
            background: #fee;
            color: var(--danger);
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 4px solid var(--danger);
        }
        
        @media (max-width: 768px) {
            body {
                padding: 10px;
            }
            
            .test-container {
                border-radius: 15px;
            }
            
            .test-header {
                padding: 20px;
            }
            
            .test-content {
                padding: 20px;
            }
            
            .question {
                padding: 15px;
            }
            
            .question-text {
                font-size: 16px;
            }
            
            .test-footer {
                flex-direction: column;
                gap: 15px;
                padding: 20px;
            }
            
            .navigation-buttons {
                width: 100%;
                justify-content: space-between;
            }
            
            .btn {
                padding: 10px 20px;
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
    <div class="test-container">
        <div class="test-header">
            <h1 class="test-title"><?php echo htmlspecialchars($test['title']); ?></h1>
            <p><?php echo htmlspecialchars($test['description']); ?></p>
            
            <div class="test-meta">
                <div>
                    <span>Время на тест: <?php echo $test['time_limit']; ?> минут</span>
                </div>
                <div class="timer" id="timer">
                    <i class="fas fa-clock"></i>
                    <span id="time-remaining"><?php echo $test['time_limit']; ?>:00</span>
                </div>
            </div>
            
            <div class="progress-container">
                <div class="progress-bar" id="progress-bar"></div>
            </div>
        </div>
        
        <?php if (isset($error)): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <form id="test-form" method="POST" action="take_test.php?id=<?php echo $test_id; ?>">
            <div class="test-content">
                <?php foreach ($questions as $index => $question): ?>
                    <div class="question" id="question-<?php echo $question['id']; ?>">
                        <div class="question-header">
                            <div class="question-text">
                                Вопрос <?php echo $index + 1; ?>: <?php echo htmlspecialchars($question['question_text']); ?>
                            </div>
                            <div class="question-points">
                                <?php echo $question['points']; ?> баллов
                            </div>
                        </div>
                        
                        <div class="options-container">
                            <?php if ($question['question_type'] === 'multiple_choice'): ?>
                                <?php foreach ($question['options'] as $option): ?>
                                    <div class="option">
                                        <input type="checkbox" name="question_<?php echo $question['id']; ?>[]" 
                                               id="option_<?php echo $option['id']; ?>" value="<?php echo $option['id']; ?>">
                                        <label for="option_<?php echo $option['id']; ?>">
                                            <?php echo htmlspecialchars($option['option_text']); ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                                
                            <?php elseif ($question['question_type'] === 'text'): ?>
                                <textarea class="text-answer" name="question_<?php echo $question['id']; ?>" 
                                          placeholder="Введите ваш ответ здесь..."></textarea>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <div class="test-footer">
                <div class="question-counter">
                    Вопрос <span id="current-question">1</span> из <?php echo count($questions); ?>
                </div>
                
                <div class="navigation-buttons">
                    <button type="button" class="btn btn-secondary" id="prev-btn" onclick="prevQuestion()">
                        <i class="fas fa-arrow-left"></i> Назад
                    </button>
                    
                    <button type="button" class="btn btn-primary" id="next-btn" onclick="nextQuestion()">
                        Далее <i class="fas fa-arrow-right"></i>
                    </button>
                    
                    <button type="submit" class="btn btn-success" id="submit-btn" style="display: none;">
                        <i class="fas fa-paper-plane"></i> Завершить тест
                    </button>
                </div>
            </div>
        </form>
    </div>

    <script>
        // Переменные для управления тестом
        const questions = document.querySelectorAll('.question');
        const totalQuestions = questions.length;
        let currentQuestion = 0;
        let timeRemaining = <?php echo $test['time_limit'] * 60; ?>; // в секундах
        let timerInterval;

        // Инициализация теста
        function initTest() {
            // Показываем только первый вопрос
            showQuestion(0);
            
            // Запускаем таймер
            startTimer();
            
            // Обновляем прогресс
            updateProgress();
        }

        // Показать вопрос
        function showQuestion(index) {
            // Скрываем все вопросы
            questions.forEach(q => q.style.display = 'none');
            
            // Показываем текущий вопрос
            questions[index].style.display = 'block';
            
            // Обновляем счетчик
            document.getElementById('current-question').textContent = index + 1;
            
            // Управляем видимостью кнопок
            document.getElementById('prev-btn').style.display = index === 0 ? 'none' : 'flex';
            document.getElementById('next-btn').style.display = index === totalQuestions - 1 ? 'none' : 'flex';
            document.getElementById('submit-btn').style.display = index === totalQuestions - 1 ? 'flex' : 'none';
        }

        // Следующий вопрос
        function nextQuestion() {
            if (currentQuestion < totalQuestions - 1) {
                currentQuestion++;
                showQuestion(currentQuestion);
                updateProgress();
            }
        }

        // Предыдущий вопрос
        function prevQuestion() {
            if (currentQuestion > 0) {
                currentQuestion--;
                showQuestion(currentQuestion);
                updateProgress();
            }
        }

        // Обновление прогресс-бара
        function updateProgress() {
            const progress = ((currentQuestion + 1) / totalQuestions) * 100;
            document.getElementById('progress-bar').style.width = progress + '%';
        }

        // Таймер обратного отсчета
        function startTimer() {
            updateTimerDisplay();
            
            timerInterval = setInterval(() => {
                timeRemaining--;
                
                if (timeRemaining <= 0) {
                    clearInterval(timerInterval);
                    alert('Время вышло! Тест будет автоматически отправлен.');
                    document.getElementById('test-form').submit();
                    return;
                }
                
                // Изменяем цвет таймера при малом остатке времени
                const timer = document.getElementById('timer');
                if (timeRemaining <= 60) {
                    timer.classList.add('danger');
                } else if (timeRemaining <= 300) {
                    timer.classList.add('warning');
                }
                
                updateTimerDisplay();
            }, 1000);
        }

        // Обновление отображения таймера
        function updateTimerDisplay() {
            const minutes = Math.floor(timeRemaining / 60);
            const seconds = timeRemaining % 60;
            document.getElementById('time-remaining').textContent = 
                `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
        }

        // Предотвращение выхода со страницы
        window.addEventListener('beforeunload', function(e) {
            if (timeRemaining > 0) {
                const message = 'Вы уверены, что хотите покинуть страницу? Прогресс теста будет потерян.';
                e.returnValue = message;
                return message;
            }
        });

        // Инициализация при загрузке страницы
        document.addEventListener('DOMContentLoaded', initTest);
    </script>
</body>
</html>