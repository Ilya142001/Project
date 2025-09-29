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
            $is_correct = 0;
            $points_earned = 0;
            $answer_text = '';

            if ($question['question_type'] === 'text') {
                // Для текстовых вопросов
                $answer_text = trim($user_answer);
                $is_correct = 0;
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
        $passed = $percentage >= 70;

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
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --secondary: #7209b7;
            --accent: #f72585;
            --light: #f8f9fa;
            --gray: #6c757d;
            --success: #4cc9f0;
            --warning: #f8961e;
            --danger: #e63946;
            --dark: #212529;
            --card-bg: #ffffff;
            --shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            line-height: 1.6;
        }
        
        .test-container {
            background: var(--card-bg);
            border-radius: 24px;
            box-shadow: var(--shadow);
            width: 100%;
            max-width: 900px;
            overflow: hidden;
            transition: var(--transition);
        }
        
        .test-container:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
        }
        
        .test-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            padding: 30px;
            position: relative;
            overflow: hidden;
        }
        
        .test-header::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 150px;
            height: 150px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            transform: translate(40%, -40%);
        }
        
        .test-header::after {
            content: '';
            position: absolute;
            bottom: -30px;
            left: -30px;
            width: 100px;
            height: 100px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
        }
        
        .test-title {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 10px;
            position: relative;
            z-index: 1;
        }
        
        .test-description {
            font-size: 16px;
            opacity: 0.9;
            margin-bottom: 20px;
            position: relative;
            z-index: 1;
            line-height: 1.5;
        }
        
        .test-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 20px;
            font-size: 15px;
            opacity: 0.9;
            position: relative;
            z-index: 1;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .timer {
            background: rgba(255, 255, 255, 0.2);
            padding: 10px 20px;
            border-radius: 25px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        
        .timer.warning {
            background: var(--warning);
            color: white;
            animation: pulse 1.5s infinite;
        }
        
        .timer.danger {
            background: var(--danger);
            color: white;
            animation: pulse 0.8s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        
        .progress-container {
            background: rgba(255, 255, 255, 0.2);
            height: 8px;
            border-radius: 4px;
            margin-top: 20px;
            overflow: hidden;
            position: relative;
            z-index: 1;
        }
        
        .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, var(--success) 0%, #4cc9f0 100%);
            border-radius: 4px;
            transition: width 0.5s ease;
            width: 0%;
            position: relative;
            overflow: hidden;
        }
        
        .progress-bar::after {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent);
            animation: shimmer 2s infinite;
        }
        
        @keyframes shimmer {
            0% { left: -100%; }
            100% { left: 100%; }
        }
        
        .test-content {
            padding: 30px;
            max-height: 65vh;
            overflow-y: auto;
        }
        
        .question {
            margin-bottom: 30px;
            padding: 25px;
            background: var(--light);
            border-radius: 20px;
            border-left: 5px solid var(--primary);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }
        
        .question::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            transform: scaleX(0);
            transition: transform 0.5s ease;
        }
        
        .question.active::before {
            transform: scaleX(1);
        }
        
        .question:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }
        
        .question-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
            gap: 15px;
        }
        
        .question-text {
            font-size: 18px;
            font-weight: 600;
            color: var(--dark);
            flex: 1;
            line-height: 1.5;
        }
        
        .question-points {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
            white-space: nowrap;
            box-shadow: 0 4px 12px rgba(67, 97, 238, 0.3);
        }
        
        .options-container {
            margin-top: 20px;
        }
        
        .option {
            margin-bottom: 15px;
            transition: var(--transition);
        }
        
        .option:hover {
            transform: translateX(5px);
        }
        
        .option input[type="radio"],
        .option input[type="checkbox"] {
            display: none;
        }
        
        .option label {
            display: flex;
            align-items: center;
            padding: 18px 20px;
            background: white;
            border: 2px solid #e9ecef;
            border-radius: 15px;
            cursor: pointer;
            transition: var(--transition);
            font-size: 16px;
            gap: 15px;
            position: relative;
            overflow: hidden;
        }
        
        .option label::before {
            content: '';
            width: 20px;
            height: 20px;
            border: 2px solid #dee2e6;
            border-radius: 50%;
            transition: var(--transition);
            flex-shrink: 0;
        }
        
        .option input[type="checkbox"] + label::before {
            border-radius: 5px;
        }
        
        .option label:hover {
            border-color: var(--primary);
            background: rgba(67, 97, 238, 0.03);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
        }
        
        .option input[type="radio"]:checked + label,
        .option input[type="checkbox"]:checked + label {
            border-color: var(--primary);
            background: rgba(67, 97, 238, 0.08);
            font-weight: 600;
        }
        
        .option input[type="radio"]:checked + label::before {
            border-color: var(--primary);
            background: var(--primary);
            box-shadow: inset 0 0 0 3px white;
        }
        
        .option input[type="checkbox"]:checked + label::before {
            border-color: var(--primary);
            background: var(--primary);
            box-shadow: inset 0 0 0 2px white;
        }
        
        .text-answer {
            width: 100%;
            padding: 18px 20px;
            border: 2px solid #e9ecef;
            border-radius: 15px;
            font-size: 16px;
            resize: vertical;
            min-height: 120px;
            transition: var(--transition);
            font-family: inherit;
            line-height: 1.5;
        }
        
        .text-answer:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(67, 97, 238, 0.1);
            transform: translateY(-2px);
        }
        
        .test-footer {
            padding: 25px 30px;
            background: #f8f9fa;
            border-top: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
        }
        
        .navigation-buttons {
            display: flex;
            gap: 12px;
        }
        
        .btn {
            padding: 14px 28px;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 10px;
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
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent);
            transition: left 0.5s;
        }
        
        .btn:hover::before {
            left: 100%;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(67, 97, 238, 0.3);
        }
        
        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(67, 97, 238, 0.4);
        }
        
        .btn-secondary {
            background: var(--gray);
            color: white;
            box-shadow: 0 4px 15px rgba(108, 117, 125, 0.3);
        }
        
        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-3px);
        }
        
        .btn-success {
            background: linear-gradient(135deg, var(--success) 0%, #3a86ff 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(76, 201, 240, 0.3);
        }
        
        .btn-success:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(76, 201, 240, 0.4);
        }
        
        .question-counter {
            font-size: 15px;
            color: var(--gray);
            font-weight: 500;
            background: white;
            padding: 10px 20px;
            border-radius: 25px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }
        
        .error-message {
            background: #ffe6e6;
            color: var(--danger);
            padding: 18px 20px;
            border-radius: 12px;
            margin: 20px 30px;
            border-left: 4px solid var(--danger);
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 4px 12px rgba(230, 57, 70, 0.1);
        }
        
        .question-navigation {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            flex-wrap: wrap;
        }
        
        .nav-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #dee2e6;
            cursor: pointer;
            transition: var(--transition);
        }
        
        .nav-dot.active {
            background: var(--primary);
            transform: scale(1.2);
        }
        
        .nav-dot.answered {
            background: var(--success);
        }
        
        @media (max-width: 768px) {
            body {
                padding: 10px;
                align-items: flex-start;
            }
            
            .test-container {
                border-radius: 20px;
            }
            
            .test-header {
                padding: 25px 20px;
            }
            
            .test-title {
                font-size: 24px;
            }
            
            .test-content {
                padding: 20px;
                max-height: none;
            }
            
            .question {
                padding: 20px;
            }
            
            .question-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .question-points {
                align-self: flex-start;
            }
            
            .question-text {
                font-size: 17px;
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
                padding: 12px 20px;
                font-size: 15px;
            }
            
            .test-meta {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
        }
        
        /* Специальные стили для разных типов вопросов */
        .multiple-choice .option label {
            padding-left: 50px;
        }
        
        .multiple-choice .option label::before {
            position: absolute;
            left: 20px;
        }
        
        .text-question .text-answer {
            font-size: 16px;
        }
        
        /* Анимация появления вопросов */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .question {
            animation: fadeIn 0.5s ease forwards;
        }
    </style>
</head>
<body>
    <div class="test-container">
        <div class="test-header">
            <h1 class="test-title"><?php echo htmlspecialchars($test['title']); ?></h1>
            <p class="test-description"><?php echo htmlspecialchars($test['description']); ?></p>
            
            <div class="test-meta">
                <div>
                    <span><i class="fas fa-clock"></i> Время на тест: <?php echo $test['time_limit']; ?> минут</span>
                </div>
                <div class="timer" id="timer">
                    <i class="fas fa-clock"></i>
                    <span id="time-remaining"><?php echo $test['time_limit']; ?>:00</span>
                </div>
            </div>
            
            <div class="progress-container">
                <div class="progress-bar" id="progress-bar"></div>
            </div>
            
            <div class="question-navigation" id="question-dots">
                <?php for ($i = 0; $i < count($questions); $i++): ?>
                    <div class="nav-dot" data-index="<?php echo $i; ?>"></div>
                <?php endfor; ?>
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
                    <div class="question <?php echo $question['question_type'] === 'text' ? 'text-question' : 'multiple-choice'; ?>" 
                         id="question-<?php echo $question['id']; ?>" data-index="<?php echo $index; ?>">
                        <div class="question-header">
                            <div class="question-text">
                                <span class="question-number">Вопрос <?php echo $index + 1; ?></span>: 
                                <?php echo htmlspecialchars($question['question_text']); ?>
                            </div>
                            <div class="question-points">
                                <i class="fas fa-star"></i> <?php echo $question['points']; ?> баллов
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
                                          placeholder="Введите ваш развернутый ответ здесь..."></textarea>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <div class="test-footer">
                <div class="question-counter">
                    <span id="current-question">1</span> из <?php echo count($questions); ?> вопросов
                </div>
                
                <div class="navigation-buttons">
                    <button type="button" class="btn btn-secondary" id="prev-btn" onclick="prevQuestion()">
                        <i class="fas fa-arrow-left"></i> Назад
                    </button>
                    
                    <button type="button" class="btn btn-primary" id="next-btn" onclick="nextQuestion()">
                        Далее <i class="fas fa-arrow-right"></i>
                    </button>
                    
                    <button type="submit" class="btn btn-success" id="submit-btn">
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
        const questionDots = document.querySelectorAll('.nav-dot');
        let currentQuestion = 0;
        let timeRemaining = <?php echo $test['time_limit'] * 60; ?>;
        let timerInterval;
        let answers = {};

        // Инициализация теста
        function initTest() {
            showQuestion(0);
            startTimer();
            updateProgress();
            updateNavigationDots();
            
            // Инициализация отслеживания ответов
            initAnswerTracking();
        }

        // Показать вопрос
        function showQuestion(index) {
            questions.forEach(q => {
                q.style.display = 'none';
                q.classList.remove('active');
            });
            
            questions[index].style.display = 'block';
            setTimeout(() => questions[index].classList.add('active'), 50);
            
            document.getElementById('current-question').textContent = index + 1;
            
            // Управление видимостью кнопок
            document.getElementById('prev-btn').style.display = index === 0 ? 'none' : 'flex';
            document.getElementById('next-btn').style.display = index === totalQuestions - 1 ? 'none' : 'flex';
            document.getElementById('submit-btn').style.display = index === totalQuestions - 1 ? 'flex' : 'none';
            
            // Обновление навигационных точек
            updateNavigationDots();
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

        // Обновление навигационных точек
        function updateNavigationDots() {
            questionDots.forEach((dot, index) => {
                dot.classList.toggle('active', index === currentQuestion);
                const questionId = questions[index].id.replace('question-', '');
                dot.classList.toggle('answered', answers[questionId]);
            });
        }

        // Инициализация отслеживания ответов
        function initAnswerTracking() {
            // Отслеживание изменений в ответах
            document.querySelectorAll('input[type="checkbox"], input[type="radio"], textarea').forEach(element => {
                element.addEventListener('change', function() {
                    const questionId = this.name.replace('question_', '');
                    answers[questionId] = true;
                    updateNavigationDots();
                });
            });
            
            // Быстрая навигация по точкам
            questionDots.forEach((dot, index) => {
                dot.addEventListener('click', () => {
                    currentQuestion = index;
                    showQuestion(currentQuestion);
                    updateProgress();
                });
            });
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
                timer.classList.remove('warning', 'danger');
                
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

        // Подтверждение отправки теста
        document.getElementById('submit-btn').addEventListener('click', function(e) {
            const unanswered = totalQuestions - Object.keys(answers).length;
            if (unanswered > 0) {
                if (!confirm(`У вас осталось ${unanswered} неотвеченных вопросов. Вы уверены, что хотите завершить тест?`)) {
                    e.preventDefault();
                }
            } else {
                if (!confirm('Вы уверены, что хотите завершить тест?')) {
                    e.preventDefault();
                }
            }
        });

        // Предотвращение выхода со страницы
        window.addEventListener('beforeunload', function(e) {
            if (timeRemaining > 0 && Object.keys(answers).length > 0) {
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