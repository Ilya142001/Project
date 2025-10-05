<?php
include 'config.php';
session_start();

// Проверяем авторизацию
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Получаем ID результата
$result_id = $_GET['id'] ?? 0;

if (!$result_id) {
    header("Location: tests.php");
    exit;
}

// Получаем информацию о пользователе
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

// Получаем детальную информацию о результате теста
if ($user['role'] == 'student') {
    $stmt = $pdo->prepare("
        SELECT 
            tr.*,
            t.title as test_title,
            t.description as test_description,
            t.subject,
            t.time_limit,
            t.passing_score,
            u.full_name as teacher_name,
            (SELECT COUNT(*) FROM test_results WHERE test_id = t.id AND user_id = ?) as total_attempts,
            (SELECT AVG(percentage) FROM test_results WHERE test_id = t.id AND user_id = ?) as user_avg_score
        FROM test_results tr
        JOIN tests t ON tr.test_id = t.id
        JOIN users u ON t.created_by = u.id
        WHERE tr.id = ? AND tr.user_id = ?
    ");
    $stmt->execute([$_SESSION['user_id'], $_SESSION['user_id'], $result_id, $_SESSION['user_id']]);
} else {
    $stmt = $pdo->prepare("
        SELECT 
            tr.*,
            t.title as test_title,
            t.description as test_description,
            t.subject,
            t.time_limit,
            t.passing_score,
            u_student.full_name as student_name,
            u_student.group_name,
            u_teacher.full_name as teacher_name,
            (SELECT COUNT(*) FROM test_results WHERE test_id = t.id AND user_id = tr.user_id) as student_attempts
        FROM test_results tr
        JOIN tests t ON tr.test_id = t.id
        JOIN users u_student ON tr.user_id = u_student.id
        JOIN users u_teacher ON t.created_by = u_teacher.id
        WHERE tr.id = ? AND (t.created_by = ? OR ? = 'admin')
    ");
    $stmt->execute([$result_id, $_SESSION['user_id'], $user['role']]);
}

$result = $stmt->fetch();

if (!$result) {
    header("Location: tests.php");
    exit;
}

// Получаем ответы пользователя на вопросы
$stmt = $pdo->prepare("
    SELECT 
        ua.*,
        q.question_text,
        q.question_type,
        q.correct_answer,
        q.points,
        q.options,
        q.explanation
    FROM user_answers ua
    JOIN questions q ON ua.question_id = q.id
    WHERE ua.result_id = ?
    ORDER BY ua.id
");
$stmt->execute([$result_id]);
$user_answers = $stmt->fetchAll();

// Вычисляем статистику по ответам
$total_questions = count($user_answers);
$correct_answers = 0;
$points_earned = 0;
$total_points = 0;

foreach ($user_answers as $answer) {
    $total_points += $answer['points'];
    if ($answer['is_correct']) {
        $correct_answers++;
        $points_earned += $answer['points'];
    }
}

// Анализ результатов по типам вопросов
$question_types = [];
foreach ($user_answers as $answer) {
    $type = $answer['question_type'];
    if (!isset($question_types[$type])) {
        $question_types[$type] = ['total' => 0, 'correct' => 0];
    }
    $question_types[$type]['total']++;
    if ($answer['is_correct']) {
        $question_types[$type]['correct']++;
    }
}

// Получаем рекомендации на основе результата
$recommendations = [];

if ($result['percentage'] < 60) {
    $recommendations[] = [
        'type' => 'danger',
        'title' => 'Низкий результат',
        'message' => 'Рекомендуем повторить материал и пройти тест еще раз. Обратите внимание на вопросы, где были допущены ошибки.'
    ];
} elseif ($result['percentage'] < 80) {
    $recommendations[] = [
        'type' => 'warning',
        'title' => 'Хороший результат',
        'message' => 'Результат выше среднего, но есть возможности для улучшения. Проанализируйте ошибки для дальнейшего прогресса.'
    ];
} else {
    $recommendations[] = [
        'type' => 'success',
        'title' => 'Отличный результат!',
        'message' => 'Поздравляем с высоким результатом! Продолжайте в том же духе.'
    ];
}

// Анализ слабых мест
$weak_areas = [];
foreach ($user_answers as $index => $answer) {
    if (!$answer['is_correct']) {
        $weak_areas[] = [
            'question_number' => $index + 1,
            'question_text' => $answer['question_text'],
            'user_answer' => $answer['user_answer'],
            'correct_answer' => $answer['correct_answer'],
            'question_type' => $answer['question_type']
        ];
    }
}

if (!empty($weak_areas)) {
    $recommendations[] = [
        'type' => 'info',
        'title' => 'Внимание на ошибки',
        'message' => 'Обратите особое внимание на вопросы, где были допущены ошибки. Рекомендуем изучить эти темы дополнительно.'
    ];
}

// Анализ по типам вопросов
foreach ($question_types as $type => $stats) {
    $success_rate = ($stats['correct'] / $stats['total']) * 100;
    if ($success_rate < 60) {
        $type_name = get_question_type_name($type);
        $recommendations[] = [
            'type' => 'info',
            'title' => "Слабый результат по {$type_name}",
            'message' => "Вам стоит уделить больше внимания вопросам типа '{$type_name}'. Успешность: " . round($success_rate, 1) . "%"
        ];
    }
}

function get_question_type_name($type) {
    $names = [
        'single' => 'одиночный выбор',
        'multiple' => 'множественный выбор',
        'text' => 'текстовый ответ',
        'code' => 'программный код'
    ];
    return $names[$type] ?? $type;
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Просмотр результата - Система интеллектуальной оценки знаний</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        }
        
        body {
            background-color: #f0f2f5;
            color: #333;
            line-height: 1.6;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
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

        /* Main Result Overview */
        .result-overview {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            margin-bottom: 30px;
            border-left: 6px solid var(--primary);
        }
        
        .result-overview.success {
            border-left-color: var(--success);
        }
        
        .result-overview.warning {
            border-left-color: var(--warning);
        }
        
        .result-overview.danger {
            border-left-color: var(--danger);
        }

        .overview-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 25px;
        }
        
        .test-info h2 {
            font-size: 24px;
            color: var(--secondary);
            margin-bottom: 10px;
        }
        
        .test-description {
            color: var(--gray);
            margin-bottom: 15px;
        }
        
        .test-meta {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        
        .meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--gray);
            font-size: 14px;
        }
        
        .score-section {
            text-align: center;
            padding: 20px;
            background: var(--light);
            border-radius: 10px;
            min-width: 200px;
        }
        
        .score-value {
            font-size: 48px;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .score-excellent {
            color: var(--success);
        }
        
        .score-good {
            color: var(--warning);
        }
        
        .score-poor {
            color: var(--danger);
        }
        
        .score-label {
            font-size: 16px;
            color: var(--gray);
            margin-bottom: 10px;
        }
        
        .status-badge {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
        }
        
        .status-passed {
            background: rgba(46, 204, 113, 0.1);
            color: var(--success);
        }
        
        .status-failed {
            background: rgba(243, 156, 18, 0.1);
            color: var(--warning);
        }

        /* Progress Section */
        .progress-section {
            margin-top: 20px;
        }
        
        .progress-info {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 14px;
            color: var(--gray);
        }
        
        .progress-bar {
            height: 10px;
            background: #e0e0e0;
            border-radius: 5px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            border-radius: 5px;
            transition: width 0.8s ease;
        }
        
        .progress-success {
            background: linear-gradient(90deg, var(--success), #27ae60);
        }
        
        .progress-warning {
            background: linear-gradient(90deg, var(--warning), #e67e22);
        }
        
        .progress-danger {
            background: linear-gradient(90deg, var(--danger), #c0392b);
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
            border-left: 4px solid var(--primary);
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: var(--secondary);
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: var(--gray);
            font-size: 14px;
        }

        /* Charts Section */
        .charts-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
            margin-bottom: 30px;
        }
        
        @media (max-width: 768px) {
            .charts-section {
                grid-template-columns: 1fr;
            }
        }
        
        .chart-container {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }
        
        .chart-title {
            font-size: 18px;
            color: var(--secondary);
            margin-bottom: 20px;
            text-align: center;
            font-weight: 600;
        }

        /* Sections */
        .section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            margin-bottom: 25px;
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f8f9fa;
        }
        
        .section-header h2 {
            font-size: 20px;
            color: var(--secondary);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .section-header h2 i {
            color: var(--primary);
        }

        /* Questions List */
        .questions-list {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        
        .question-item {
            padding: 20px;
            border-radius: 10px;
            border: 2px solid #f0f0f0;
            transition: all 0.3s;
        }
        
        .question-item.correct {
            border-color: var(--success);
            background: rgba(46, 204, 113, 0.05);
        }
        
        .question-item.incorrect {
            border-color: var(--danger);
            background: rgba(231, 76, 60, 0.05);
        }
        
        .question-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        
        .question-text {
            font-size: 16px;
            font-weight: 600;
            color: var(--secondary);
            flex: 1;
            margin-right: 15px;
            line-height: 1.4;
        }
        
        .question-meta {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .question-type {
            background: var(--light);
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            color: var(--primary);
        }
        
        .question-points {
            background: var(--light);
            padding: 5px 12px;
            border-radius: 15px;
            font-size: 14px;
            font-weight: 600;
            color: var(--secondary);
        }
        
        .question-answer {
            margin-bottom: 15px;
        }
        
        .answer-label {
            font-weight: 600;
            color: var(--secondary);
            margin-bottom: 8px;
            display: block;
        }
        
        .user-answer {
            padding: 12px;
            background: var(--light);
            border-radius: 8px;
            margin-bottom: 10px;
            border-left: 4px solid var(--primary);
        }
        
        .user-answer.correct {
            border-left-color: var(--success);
            background: rgba(46, 204, 113, 0.1);
        }
        
        .user-answer.incorrect {
            border-left-color: var(--danger);
            background: rgba(231, 76, 60, 0.1);
        }
        
        .correct-answer {
            padding: 12px;
            background: rgba(46, 204, 113, 0.1);
            border-radius: 8px;
            border-left: 4px solid var(--success);
        }
        
        .answer-explanation {
            margin-top: 10px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            font-size: 14px;
            color: var(--gray);
            border-left: 3px solid var(--info);
        }

        /* Recommendations */
        .recommendations-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .recommendation-item {
            padding: 20px;
            border-radius: 12px;
            border-left: 4px solid var(--primary);
            background: var(--light);
        }
        
        .recommendation-item.success {
            border-left-color: var(--success);
        }
        
        .recommendation-item.warning {
            border-left-color: var(--warning);
        }
        
        .recommendation-item.danger {
            border-left-color: var(--danger);
        }
        
        .recommendation-item.info {
            border-left-color: var(--info);
        }
        
        .recommendation-title {
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--secondary);
            font-size: 16px;
        }
        
        .recommendation-message {
            color: var(--gray);
            font-size: 14px;
            line-height: 1.5;
        }

        /* Actions */
        .actions-section {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 30px;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-align: center;
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
        
        .btn-success:hover {
            background: #27ae60;
            transform: translateY(-2px);
        }
        
        .btn-outline {
            background: transparent;
            border: 2px solid var(--primary);
            color: var(--primary);
        }
        
        .btn-outline:hover {
            background: var(--primary);
            color: white;
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

        /* Responsive */
        @media (max-width: 768px) {
            .overview-header {
                flex-direction: column;
                gap: 20px;
            }
            
            .score-section {
                width: 100%;
            }
            
            .test-meta {
                flex-direction: column;
                gap: 10px;
            }
            
            .actions-section {
                flex-direction: column;
            }
            
            .question-header {
                flex-direction: column;
                gap: 10px;
            }
            
            .question-meta {
                align-self: flex-start;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Хлебные крошки -->
        <a href="tests.php" class="back-button">
            <i class="fas fa-arrow-left"></i>
            Назад к тестам
        </a>

        <div class="header">
            <h1><i class="fas fa-chart-bar"></i> Просмотр результата теста</h1>
            <div class="breadcrumb">
                <a href="index.php">Главная</a>
                <i class="fas fa-chevron-right"></i>
                <a href="tests.php">Тесты</a>
                <i class="fas fa-chevron-right"></i>
                <span>Результат</span>
            </div>
        </div>

        <!-- Основная информация о результате -->
        <div class="result-overview <?php echo $result['passed'] ? 'success' : 'danger'; ?>">
            <div class="overview-header">
                <div class="test-info">
                    <h2><?php echo htmlspecialchars($result['test_title']); ?></h2>
                    <?php if (!empty($result['test_description'])): ?>
                        <p class="test-description"><?php echo htmlspecialchars($result['test_description']); ?></p>
                    <?php endif; ?>
                    <div class="test-meta">
                        <div class="meta-item">
                            <i class="fas fa-book"></i>
                            Предмет: <?php echo htmlspecialchars($result['subject']); ?>
                        </div>
                        <div class="meta-item">
                            <i class="fas fa-user"></i>
                            Преподаватель: <?php echo htmlspecialchars($result['teacher_name']); ?>
                        </div>
                        <div class="meta-item">
                            <i class="fas fa-calendar"></i>
                            Дата прохождения: <?php echo date('d.m.Y H:i', strtotime($result['completed_at'])); ?>
                        </div>
                        <?php if ($user['role'] != 'student'): ?>
                        <div class="meta-item">
                            <i class="fas fa-user-graduate"></i>
                            Студент: <?php echo htmlspecialchars($result['student_name']); ?>
                            <?php if (!empty($result['group_name'])): ?>
                                (Группа: <?php echo htmlspecialchars($result['group_name']); ?>)
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="score-section">
                    <div class="score-value <?php 
                        echo $result['percentage'] >= 80 ? 'score-excellent' : 
                             ($result['percentage'] >= 60 ? 'score-good' : 'score-poor'); 
                    ?>">
                        <?php echo round($result['percentage'], 1); ?>%
                    </div>
                    <div class="score-label">
                        <?php echo $result['score']; ?> / <?php echo $result['total_points']; ?> баллов
                    </div>
                    <span class="status-badge <?php echo $result['passed'] ? 'status-passed' : 'status-failed'; ?>">
                        <?php echo $result['passed'] ? 'Тест сдан' : 'Тест не сдан'; ?>
                    </span>
                    <?php if ($result['passing_score'] > 0): ?>
                        <div style="margin-top: 8px; font-size: 12px; color: var(--gray);">
                            Проходной балл: <?php echo $result['passing_score']; ?>%
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Прогресс бар -->
            <div class="progress-section">
                <div class="progress-info">
                    <span>0%</span>
                    <span>Прогресс: <?php echo round($result['percentage'], 1); ?>%</span>
                    <span>100%</span>
                </div>
                <div class="progress-bar">
                    <div class="progress-fill <?php 
                        echo $result['percentage'] >= 80 ? 'progress-success' : 
                             ($result['percentage'] >= 60 ? 'progress-warning' : 'progress-danger'); 
                    ?>" style="width: <?php echo $result['percentage']; ?>%"></div>
                </div>
            </div>
        </div>

        <!-- Статистика -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $correct_answers; ?>/<?php echo $total_questions; ?></div>
                <div class="stat-label">Правильных ответов</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $points_earned; ?>/<?php echo $total_points; ?></div>
                <div class="stat-label">Набрано баллов</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo round(($correct_answers / $total_questions) * 100, 1); ?>%</div>
                <div class="stat-label">Точность ответов</div>
            </div>
            <?php if ($user['role'] == 'student'): ?>
            <div class="stat-card">
                <div class="stat-value"><?php echo $result['total_attempts']; ?></div>
                <div class="stat-label">Всего попыток</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo round($result['user_avg_score'], 1); ?>%</div>
                <div class="stat-label">Средний результат</div>
            </div>
            <?php else: ?>
            <div class="stat-card">
                <div class="stat-value"><?php echo $result['student_attempts']; ?></div>
                <div class="stat-label">Попыток студента</div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Графики -->
        <div class="charts-section">
            <div class="chart-container">
                <div class="chart-title">Распределение ответов</div>
                <canvas id="answersChart" height="250"></canvas>
            </div>
            <div class="chart-container">
                <div class="chart-title">Результаты по типам вопросов</div>
                <canvas id="typesChart" height="250"></canvas>
            </div>
        </div>

        <!-- Детализация по вопросам -->
        <div class="section">
            <div class="section-header">
                <h2><i class="fas fa-list-ol"></i> Детализация ответов</h2>
                <span class="stat-label"><?php echo $correct_answers; ?> из <?php echo $total_questions; ?> правильно (<?php echo round(($correct_answers / $total_questions) * 100, 1); ?>%)</span>
            </div>
            
            <div class="questions-list">
                <?php foreach ($user_answers as $index => $answer): ?>
                    <div class="question-item <?php echo $answer['is_correct'] ? 'correct' : 'incorrect'; ?>">
                        <div class="question-header">
                            <div class="question-text">
                                <strong>Вопрос <?php echo $index + 1; ?>:</strong> <?php echo htmlspecialchars($answer['question_text']); ?>
                            </div>
                            <div class="question-meta">
                                <span class="question-type"><?php echo get_question_type_name($answer['question_type']); ?></span>
                                <span class="question-points">
                                    <?php echo $answer['is_correct'] ? $answer['points'] : 0; ?>/<?php echo $answer['points']; ?> баллов
                                </span>
                            </div>
                        </div>
                        
                        <div class="question-answer">
                            <span class="answer-label">Ваш ответ:</span>
                            <div class="user-answer <?php echo $answer['is_correct'] ? 'correct' : 'incorrect'; ?>">
                                <?php 
                                if ($answer['question_type'] == 'multiple' && !empty($answer['user_answer'])) {
                                    $user_answers_arr = json_decode($answer['user_answer'], true);
                                    if (is_array($user_answers_arr)) {
                                        echo '<ul style="margin: 0; padding-left: 20px;">';
                                        foreach ($user_answers_arr as $user_ans) {
                                            echo '<li>' . htmlspecialchars($user_ans) . '</li>';
                                        }
                                        echo '</ul>';
                                    } else {
                                        echo htmlspecialchars($answer['user_answer']);
                                    }
                                } else {
                                    echo !empty($answer['user_answer']) ? htmlspecialchars($answer['user_answer']) : '<em style="color: var(--gray);">Ответ не предоставлен</em>';
                                }
                                ?>
                            </div>
                            
                            <?php if (!$answer['is_correct']): ?>
                                <span class="answer-label">Правильный ответ:</span>
                                <div class="correct-answer">
                                    <?php 
                                    if ($answer['question_type'] == 'multiple' && !empty($answer['correct_answer'])) {
                                        $correct_answers_arr = json_decode($answer['correct_answer'], true);
                                        if (is_array($correct_answers_arr)) {
                                            echo '<ul style="margin: 0; padding-left: 20px;">';
                                            foreach ($correct_answers_arr as $correct_ans) {
                                                echo '<li>' . htmlspecialchars($correct_ans) . '</li>';
                                            }
                                            echo '</ul>';
                                        } else {
                                            echo htmlspecialchars($answer['correct_answer']);
                                        }
                                    } else {
                                        echo htmlspecialchars($answer['correct_answer']);
                                    }
                                    ?>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($answer['explanation'])): ?>
                                <div class="answer-explanation">
                                    <strong><i class="fas fa-info-circle"></i> Объяснение:</strong><br>
                                    <?php echo htmlspecialchars($answer['explanation']); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Рекомендации -->
        <?php if (!empty($recommendations)): ?>
        <div class="section">
            <div class="section-header">
                <h2><i class="fas fa-lightbulb"></i> Рекомендации и анализ</h2>
            </div>
            <div class="recommendations-list">
                <?php foreach ($recommendations as $rec): ?>
                    <div class="recommendation-item <?php echo $rec['type']; ?>">
                        <div class="recommendation-title"><?php echo htmlspecialchars($rec['title']); ?></div>
                        <div class="recommendation-message"><?php echo htmlspecialchars($rec['message']); ?></div>
                    </div>
                <?php endforeach; ?>
                
                <?php if (!empty($weak_areas)): ?>
                    <div class="recommendation-item info">
                        <div class="recommendation-title">Вопросы для повторения</div>
                        <div class="recommendation-message">
                            Рекомендуем обратить особое внимание на следующие вопросы, где были допущены ошибки:
                            <ul style="margin-top: 10px; padding-left: 20px;">
                                <?php foreach ($weak_areas as $weak): ?>
                                    <li><strong>Вопрос <?php echo $weak['question_number']; ?>:</strong> <?php echo htmlspecialchars(mb_substr($weak['question_text'], 0, 100)) . '...'; ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Действия -->
        <div class="actions-section">
            <a href="take_test.php?id=<?php echo $result['test_id']; ?>" class="btn btn-success">
                <i class="fas fa-redo"></i> Пройти тест еще раз
            </a>
            <a href="tests.php" class="btn btn-outline">
                <i class="fas fa-list"></i> К списку тестов
            </a>
            <?php if ($user['role'] == 'student'): ?>
                <a href="my_progress.php" class="btn btn-primary">
                    <i class="fas fa-chart-line"></i> Мой прогресс
                </a>
            <?php else: ?>
                <a href="test_results.php?test_id=<?php echo $result['test_id']; ?>" class="btn btn-primary">
                    <i class="fas fa-users"></i> Все результаты теста
                </a>
            <?php endif; ?>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Данные для графиков
            const answersData = {
                correct: <?php echo $correct_answers; ?>,
                incorrect: <?php echo $total_questions - $correct_answers; ?>
            };

            const typesData = {
                labels: <?php echo json_encode(array_keys($question_types)); ?>,
                datasets: [{
                    label: 'Правильные ответы',
                    data: <?php echo json_encode(array_column($question_types, 'correct')); ?>,
                    backgroundColor: '#2ecc71'
                }, {
                    label: 'Всего вопросов',
                    data: <?php echo json_encode(array_column($question_types, 'total')); ?>,
                    backgroundColor: '#3498db'
                }]
            };

            // График распределения ответов
            const answersCtx = document.getElementById('answersChart').getContext('2d');
            new Chart(answersCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Правильные ответы', 'Неправильные ответы'],
                    datasets: [{
                        data: [answersData.correct, answersData.incorrect],
                        backgroundColor: ['#2ecc71', '#e74c3c'],
                        borderWidth: 2,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const value = context.raw;
                                    const percentage = Math.round((value / total) * 100);
                                    return `${context.label}: ${value} (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });

            // График по типам вопросов
            const typesCtx = document.getElementById('typesChart').getContext('2d');
            new Chart(typesCtx, {
                type: 'bar',
                data: typesData,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Количество вопросов'
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Типы вопросов'
                            }
                        }
                    },
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return `${context.dataset.label}: ${context.raw}`;
                                }
                            }
                        }
                    }
                }
            });

            // Анимация прогресс-баров
            const progressBars = document.querySelectorAll('.progress-fill');
            progressBars.forEach(bar => {
                const width = bar.style.width;
                bar.style.width = '0';
                setTimeout(() => {
                    bar.style.width = width;
                }, 800);
            });

            // Анимация появления элементов
            const elements = document.querySelectorAll('.question-item, .stat-card, .recommendation-item');
            elements.forEach((element, index) => {
                element.style.opacity = '0';
                element.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    element.style.transition = 'all 0.6s ease';
                    element.style.opacity = '1';
                    element.style.transform = 'translateY(0)';
                }, index * 100);
            });

            // Подсветка неправильных ответов при наведении
            const incorrectAnswers = document.querySelectorAll('.question-item.incorrect');
            incorrectAnswers.forEach(item => {
                item.addEventListener('mouseenter', function() {
                    this.style.transform = 'scale(1.02)';
                    this.style.boxShadow = '0 8px 25px rgba(231, 76, 60, 0.15)';
                });
                
                item.addEventListener('mouseleave', function() {
                    this.style.transform = 'scale(1)';
                    this.style.boxShadow = 'none';
                });
            });
        });
    </script>
</body>
</html>