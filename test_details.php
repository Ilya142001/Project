<?php
include 'config.php';

// Проверяем авторизацию
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$test_id = $_GET['id'] ?? null;

if (!$test_id) {
    header("Location: tests.php");
    exit;
}

// Получаем информацию о тесте
$stmt = $pdo->prepare("
    SELECT t.*, u.full_name as author_name, u.email as author_email
    FROM tests t 
    JOIN users u ON t.created_by = u.id 
    WHERE t.id = ?
");
$stmt->execute([$test_id]);
$test = $stmt->fetch();

if (!$test) {
    header("Location: tests.php?error=test_not_found");
    exit;
}

// Получаем вопросы теста
$stmt = $pdo->prepare("
    SELECT q.*, 
           COUNT(qo.id) as options_count,
           SUM(CASE WHEN qo.is_correct = 1 THEN 1 ELSE 0 END) as correct_options_count
    FROM questions q
    LEFT JOIN question_options qo ON q.id = qo.question_id
    WHERE q.test_id = ?
    GROUP BY q.id
    ORDER BY q.sort_order ASC
");
$stmt->execute([$test_id]);
$questions = $stmt->fetchAll();

// Получаем статистику теста
$stmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT tr.id) as attempts_count,
        COUNT(DISTINCT tr.user_id) as unique_students,
        AVG(tr.percentage) as avg_score,
        SUM(CASE WHEN tr.passed = 1 THEN 1 ELSE 0 END) as passed_count,
        MAX(tr.completed_at) as last_attempt
    FROM test_results tr
    WHERE tr.test_id = ?
");
$stmt->execute([$test_id]);
$stats = $stmt->fetch();

// Получаем примененные модели
$stmt = $pdo->prepare("
    SELECT m.*, mta.created_at as assigned_date
    FROM ml_models m
    JOIN model_test_assignments mta ON m.id = mta.model_id
    WHERE mta.test_id = ?
    ORDER BY mta.created_at DESC
");
$stmt->execute([$test_id]);
$applied_models = $stmt->fetchAll();

// Получаем последние результаты
$stmt = $pdo->prepare("
    SELECT tr.*, u.full_name as student_name, u.avatar as student_avatar
    FROM test_results tr
    JOIN users u ON tr.user_id = u.id
    WHERE tr.test_id = ?
    ORDER BY tr.completed_at DESC
    LIMIT 5
");
$stmt->execute([$test_id]);
$recent_results = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Детали теста - <?php echo htmlspecialchars($test['title']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #4361ee;
            --primary-light: #4895ef;
            --secondary: #3f37c9;
            --success: #4cc9f0;
            --accent: #f72585;
            --dark: #1e1e2c;
            --light: #f8f9fa;
            --gray: #6c757d;
            --border: #e2e8f0;
            --card-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', sans-serif;
        }

        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: var(--dark);
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: var(--card-shadow);
            overflow: hidden;
            margin-bottom: 25px;
        }

        .header {
            padding: 40px;
            text-align: center;
            position: relative;
        }

        .header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--accent));
        }

        .header h1 {
            font-size: 2.5rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 10px;
        }

        .header h2 {
            font-size: 1.3rem;
            color: var(--gray);
            font-weight: 500;
            margin-bottom: 15px;
        }

        .nav-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 25px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 12px 24px;
            border-radius: 12px;
            text-decoration: none;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            box-shadow: 0 4px 15px rgba(67, 97, 238, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(67, 97, 238, 0.4);
        }

        .btn-outline {
            background: transparent;
            color: var(--primary);
            border: 2px solid var(--primary);
        }

        .btn-outline:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
        }

        .section {
            padding: 30px;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--border);
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px;
            border-radius: 16px;
            text-align: center;
            box-shadow: var(--card-shadow);
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .stat-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .info-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .info-label {
            font-size: 0.9rem;
            color: var(--gray);
            font-weight: 500;
        }

        .info-value {
            font-weight: 600;
            color: var(--dark);
        }

        .question-card {
            background: white;
            padding: 25px;
            border-radius: 16px;
            border-left: 5px solid var(--primary);
            box-shadow: var(--card-shadow);
            margin-bottom: 20px;
        }

        .question-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .question-text {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--dark);
            flex: 1;
        }

        .question-meta {
            display: flex;
            gap: 15px;
            font-size: 0.9rem;
            color: var(--gray);
        }

        .options-list {
            margin-top: 15px;
        }

        .option-item {
            padding: 10px 15px;
            margin-bottom: 8px;
            background: var(--light);
            border-radius: 8px;
            border-left: 3px solid var(--primary-light);
        }

        .option-correct {
            border-left-color: #28a745;
            background: rgba(40, 167, 69, 0.1);
        }

        .model-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            border-left: 4px solid var(--success);
            box-shadow: var(--card-shadow);
            margin-bottom: 15px;
        }

        .model-name {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--dark);
        }

        .model-accuracy {
            display: inline-block;
            background: var(--success);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-bottom: 10px;
        }

        .results-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: var(--card-shadow);
        }

        .results-table th {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            font-weight: 600;
            padding: 15px;
            text-align: left;
        }

        .results-table td {
            padding: 15px;
            border-bottom: 1px solid var(--border);
        }

        .results-table tr:last-child td {
            border-bottom: none;
        }

        .student-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .student-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--primary-light);
        }

        .avatar-placeholder {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1rem;
        }

        .percentage-high { color: #28a745; font-weight: 600; }
        .percentage-medium { color: #ffc107; font-weight: 600; }
        .percentage-low { color: #dc3545; font-weight: 600; }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 15px;
            opacity: 0.5;
        }

        @media (max-width: 768px) {
            .container {
                padding: 15px;
            }
            
            .header {
                padding: 25px 20px;
            }
            
            .header h1 {
                font-size: 2rem;
            }
            
            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }
            
            .nav-buttons {
                flex-direction: column;
                align-items: center;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Заголовок -->
        <div class="glass-card header">
            <h1><i class="fas fa-file-alt"></i> <?php echo htmlspecialchars($test['title']); ?></h1>
            <h2><?php echo htmlspecialchars($test['description']); ?></h2>
            
            <div class="nav-buttons">
                <a href="evaluate_test.php?test_id=<?php echo $test_id; ?>" class="btn btn-primary">
                    <i class="fas fa-robot"></i> AI Оценка теста
                </a>
                <a href="tests.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i> Назад к тестам
                </a>
                <a href="edit_test.php?id=<?php echo $test_id; ?>" class="btn btn-outline">
                    <i class="fas fa-edit"></i> Редактировать тест
                </a>
            </div>
        </div>

        <!-- Статистика -->
        <div class="glass-card section">
            <div class="section-header">
                <h3 class="section-title"><i class="fas fa-chart-bar"></i> Статистика теста</h3>
            </div>
            
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?php echo $stats['attempts_count'] ?? 0; ?></div>
                    <div class="stat-label">Попыток</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $stats['unique_students'] ?? 0; ?></div>
                    <div class="stat-label">Уникальных студентов</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo round($stats['avg_score'] ?? 0, 1); ?>%</div>
                    <div class="stat-label">Средний результат</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $stats['passed_count'] ?? 0; ?></div>
                    <div class="stat-label">Успешных попыток</div>
                </div>
            </div>

            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Автор:</span>
                    <span class="info-value"><?php echo htmlspecialchars($test['author_name']); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Лимит времени:</span>
                    <span class="info-value"><?php echo $test['time_limit']; ?> минут</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Статус:</span>
                    <span class="info-value">
                        <?php if ($test['is_published']): ?>
                            <span style="color: #28a745;"><i class="fas fa-check-circle"></i> Опубликован</span>
                        <?php else: ?>
                            <span style="color: #dc3545;"><i class="fas fa-times-circle"></i> Черновик</span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="info-item">
                    <span class="info-label">Дата создания:</span>
                    <span class="info-value"><?php echo date('d.m.Y H:i', strtotime($test['created_at'])); ?></span>
                </div>
            </div>
        </div>

        <!-- Вопросы теста -->
        <div class="glass-card section">
            <div class="section-header">
                <h3 class="section-title"><i class="fas fa-question-circle"></i> Вопросы теста (<?php echo count($questions); ?>)</h3>
            </div>
            
            <?php if (!empty($questions)): ?>
                <?php foreach ($questions as $question): ?>
                    <div class="question-card">
                        <div class="question-header">
                            <div class="question-text"><?php echo htmlspecialchars($question['question_text']); ?></div>
                            <div class="question-meta">
                                <span><i class="fas fa-star"></i> <?php echo $question['points']; ?> баллов</span>
                                <span><i class="fas fa-list"></i> <?php echo $question['options_count']; ?> вариантов</span>
                            </div>
                        </div>
                        
                        <?php 
                        // Получаем варианты ответов для этого вопроса
                        $stmt = $pdo->prepare("SELECT * FROM question_options WHERE question_id = ? ORDER BY sort_order ASC");
                        $stmt->execute([$question['id']]);
                        $options = $stmt->fetchAll();
                        ?>
                        
                        <?php if (!empty($options)): ?>
                            <div class="options-list">
                                <?php foreach ($options as $option): ?>
                                    <div class="option-item <?php echo $option['is_correct'] ? 'option-correct' : ''; ?>">
                                        <?php echo htmlspecialchars($option['option_text']); ?>
                                        <?php if ($option['is_correct']): ?>
                                            <span style="color: #28a745; margin-left: 10px;">
                                                <i class="fas fa-check"></i> Правильный ответ
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-question-circle"></i>
                    <h3>В тесте пока нет вопросов</h3>
                    <p>Добавьте вопросы для проведения тестирования</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Примененные AI модели -->
        <div class="glass-card section">
            <div class="section-header">
                <h3 class="section-title"><i class="fas fa-robot"></i> Примененные AI модели</h3>
            </div>
            
            <?php if (!empty($applied_models)): ?>
                <?php foreach ($applied_models as $model): ?>
                    <div class="model-card">
                        <div class="model-name"><?php echo htmlspecialchars($model['name']); ?></div>
                        <div class="model-accuracy">
                            <i class="fas fa-bullseye"></i> Точность: <?php echo $model['accuracy']; ?>%
                        </div>
                        <p style="font-size: 14px; color: var(--gray); margin-bottom: 10px;">
                            <?php echo htmlspecialchars($model['description']); ?>
                        </p>
                        <div style="font-size: 12px; color: var(--gray);">
                            <i class="fas fa-calendar"></i> Применена: <?php echo date('d.m.Y H:i', strtotime($model['assigned_date'])); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-robot"></i>
                    <h3>Нет примененных моделей</h3>
                    <p>Для применения AI моделей перейдите в раздел оценки теста</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Последние результаты -->
        <div class="glass-card section">
            <div class="section-header">
                <h3 class="section-title"><i class="fas fa-history"></i> Последние результаты</h3>
            </div>
            
            <?php if (!empty($recent_results)): ?>
                <table class="results-table">
                    <thead>
                        <tr>
                            <th>Студент</th>
                            <th>Результат</th>
                            <th>Время</th>
                            <th>Дата</th>
                            <th>Статус</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_results as $result): 
                            $firstName = $result['student_name'];
                            $firstLetter = mb_substr($firstName, 0, 1, 'UTF-8');
                        ?>
                        <tr>
                            <td>
                                <div class="student-info">
                                    <?php if (!empty($result['student_avatar'])): ?>
                                        <img src="<?php echo htmlspecialchars($result['student_avatar']); ?>" 
                                             alt="<?php echo htmlspecialchars($result['student_name']); ?>" 
                                             class="student-avatar">
                                    <?php else: ?>
                                        <div class="avatar-placeholder">
                                            <?php echo htmlspecialchars(strtoupper($firstLetter), ENT_QUOTES, 'UTF-8'); ?>
                                        </div>
                                    <?php endif; ?>
                                    <span><?php echo htmlspecialchars($result['student_name']); ?></span>
                                </div>
                            </td>
                            <td>
                                <?php echo $result['score']; ?>/<?php echo $result['total_points']; ?>
                                <span class="
                                    <?php 
                                    if ($result['percentage'] >= 80) echo 'percentage-high';
                                    elseif ($result['percentage'] >= 60) echo 'percentage-medium';
                                    else echo 'percentage-low';
                                    ?>
                                ">
                                    (<?php echo $result['percentage']; ?>%)
                                </span>
                            </td>
                            <td><?php echo gmdate("H:i:s", $result['time_spent']); ?></td>
                            <td><?php echo date('d.m.Y H:i', strtotime($result['completed_at'])); ?></td>
                            <td>
                                <?php if ($result['passed']): ?>
                                    <span style="color: #28a745;"><i class="fas fa-check-circle"></i> Пройден</span>
                                <?php else: ?>
                                    <span style="color: #dc3545;"><i class="fas fa-times-circle"></i> Не пройден</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <div style="text-align: center; margin-top: 20px;">
                    <a href="evaluate_test.php?test_id=<?php echo $test_id; ?>" class="btn btn-outline">
                        <i class="fas fa-list"></i> Все результаты
                    </a>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-clipboard-list"></i>
                    <h3>Нет результатов теста</h3>
                    <p>Студенты еще не прошли этот тест</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>