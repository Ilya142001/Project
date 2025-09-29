<?php
include 'config.php';

// Проверяем авторизацию
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Вспомогательная функция для безопасного вывода данных
function safe_echo($data) {
    if (is_array($data)) {
        return htmlspecialchars(implode(', ', $data));
    } elseif (is_string($data)) {
        return htmlspecialchars($data);
    } elseif (is_numeric($data)) {
        return $data;
    } else {
        return 'Неизвестный формат данных';
    }
}

$result_id = $_GET['id'] ?? null;

if (!$result_id) {
    header("Location: tests.php");
    exit;
}

// Получаем информацию о результате
$stmt = $pdo->prepare("
    SELECT tr.*, u.full_name, u.avatar, u.email, 
           t.title as test_title, t.description as test_description,
           t.created_by as test_author_id, author.full_name as author_name
    FROM test_results tr 
    JOIN users u ON tr.user_id = u.id 
    JOIN tests t ON tr.test_id = t.id
    LEFT JOIN users author ON t.created_by = author.id
    WHERE tr.id = ?
");
$stmt->execute([$result_id]);
$result = $stmt->fetch();

if (!$result) {
    header("Location: tests.php?error=result_not_found");
    exit;
}

// Получаем ответы студента
$stmt = $pdo->prepare("
    SELECT ua.*, q.question_text, q.question_type, q.points,
           GROUP_CONCAT(CASE WHEN qo.is_correct = 1 THEN qo.option_text END SEPARATOR '|') as correct_answers,
           qo.option_text as selected_option_text
    FROM user_answers ua
    JOIN questions q ON ua.question_id = q.id
    LEFT JOIN question_options qo ON ua.answer_text = qo.id AND qo.question_id = q.id
    WHERE ua.result_id = ?
    GROUP BY ua.id
    ORDER BY q.sort_order ASC
");
$stmt->execute([$result_id]);
$user_answers = $stmt->fetchAll();

// Получаем AI оценки для этого результата
$stmt = $pdo->prepare("
    SELECT mp.*, m.name as model_name, m.accuracy
    FROM model_predictions mp
    JOIN ml_models m ON mp.model_id = m.id
    WHERE mp.test_result_id = ?
    ORDER BY mp.created_at DESC
");
$stmt->execute([$result_id]);
$predictions = $stmt->fetchAll();

// Рассчитываем статистику
$correct_answers = 0;
$total_questions = count($user_answers);
foreach ($user_answers as $answer) {
    if ($answer['is_correct']) {
        $correct_answers++;
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Детали результата - <?php echo htmlspecialchars($result['full_name']); ?></title>
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
            max-width: 1000px;
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
            font-size: 2.2rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 10px;
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
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 10px;
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

        .student-info {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 25px;
            padding: 20px;
            background: var(--light);
            border-radius: 12px;
        }

        .student-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid var(--primary-light);
        }

        .avatar-placeholder {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 2rem;
            border: 4px solid var(--primary-light);
        }

        .student-details {
            flex: 1;
        }

        .student-name {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .student-email {
            color: var(--gray);
            margin-bottom: 10px;
        }

        .result-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }

        .summary-item {
            text-align: center;
            padding: 15px;
            background: white;
            border-radius: 10px;
            box-shadow: var(--card-shadow);
        }

        .summary-value {
            font-size: 1.8rem;
            font-weight: 800;
            margin-bottom: 5px;
        }

        .summary-label {
            font-size: 0.9rem;
            color: var(--gray);
        }

        .percentage-high { color: #28a745; }
        .percentage-medium { color: #ffc107; }
        .percentage-low { color: #dc3545; }

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

        .question-points {
            background: var(--primary);
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
        }

        .answer-section {
            margin-top: 15px;
        }

        .answer-correct {
            background: rgba(40, 167, 69, 0.1);
            border-left: 4px solid #28a745;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 10px;
        }

        .answer-incorrect {
            background: rgba(220, 53, 69, 0.1);
            border-left: 4px solid #dc3545;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 10px;
        }

        .correct-answers {
            background: rgba(23, 162, 184, 0.1);
            border-left: 4px solid #17a2b8;
            padding: 15px;
            border-radius: 8px;
        }

        .prediction-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            border-left: 4px solid var(--success);
            box-shadow: var(--card-shadow);
            margin-bottom: 15px;
        }

        .prediction-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .prediction-model {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--dark);
        }

        .prediction-confidence {
            background: var(--success);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

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
                font-size: 1.8rem;
            }
            
            .student-info {
                flex-direction: column;
                text-align: center;
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
            <h1><i class="fas fa-clipboard-check"></i> Детали результата теста</h1>
            <h2><?php echo htmlspecialchars($result['test_title']); ?></h2>
            
            <div class="nav-buttons">
                <a href="evaluate_test.php?test_id=<?php echo $result['test_id']; ?>" class="btn btn-primary">
                    <i class="fas fa-arrow-left"></i> Назад к оценке теста
                </a>
                <a href="test_details.php?id=<?php echo $result['test_id']; ?>" class="btn btn-outline">
                    <i class="fas fa-info-circle"></i> Детали теста
                </a>
            </div>
        </div>

        <!-- Информация о студенте -->
        <div class="glass-card section">
            <div class="student-info">
                <?php
                $firstName = $result['full_name'];
                $firstLetter = mb_substr($firstName, 0, 1, 'UTF-8');
                ?>
                <?php if (!empty($result['avatar'])): ?>
                    <img src="<?php echo htmlspecialchars($result['avatar']); ?>" 
                         alt="<?php echo htmlspecialchars($result['full_name']); ?>" 
                         class="student-avatar">
                <?php else: ?>
                    <div class="avatar-placeholder">
                        <?php echo htmlspecialchars(strtoupper($firstLetter), ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>
                
                <div class="student-details">
                    <div class="student-name"><?php echo htmlspecialchars($result['full_name']); ?></div>
                    <div class="student-email"><?php echo htmlspecialchars($result['email']); ?></div>
                    
                    <div class="result-summary">
                        <div class="summary-item">
                            <div class="summary-value 
                                <?php 
                                if ($result['percentage'] >= 80) echo 'percentage-high';
                                elseif ($result['percentage'] >= 60) echo 'percentage-medium';
                                else echo 'percentage-low';
                                ?>
                            ">
                                <?php echo $result['percentage']; ?>%
                            </div>
                            <div class="summary-label">Результат</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-value"><?php echo $result['score']; ?>/<?php echo $result['total_points']; ?></div>
                            <div class="summary-label">Баллы</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-value"><?php echo $correct_answers; ?>/<?php echo $total_questions; ?></div>
                            <div class="summary-label">Правильные ответы</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-value"><?php echo gmdate("H:i:s", $result['time_spent']); ?></div>
                            <div class="summary-label">Время</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Тест:</span>
                    <span class="info-value"><?php echo htmlspecialchars($result['test_title']); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Автор теста:</span>
                    <span class="info-value"><?php echo htmlspecialchars($result['author_name']); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Дата завершения:</span>
                    <span class="info-value"><?php echo date('d.m.Y H:i', strtotime($result['completed_at'])); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Статус:</span>
                    <span class="info-value">
                        <?php if ($result['passed']): ?>
                            <span style="color: #28a745;"><i class="fas fa-check-circle"></i> Тест пройден</span>
                        <?php else: ?>
                            <span style="color: #dc3545;"><i class="fas fa-times-circle"></i> Тест не пройден</span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Ответы студента -->
        <div class="glass-card section">
            <div class="section-header">
                <h3 class="section-title"><i class="fas fa-list-ol"></i> Ответы студента</h3>
                <span style="background: var(--primary); color: white; padding: 6px 12px; border-radius: 20px; font-size: 14px; font-weight: 600;">
                    <?php echo $total_questions; ?> вопросов
                </span>
            </div>
            
            <?php if (!empty($user_answers)): ?>
                <?php foreach ($user_answers as $index => $answer): ?>
                    <div class="question-card">
                        <div class="question-header">
                            <div class="question-text">Вопрос <?php echo $index + 1; ?>: <?php echo htmlspecialchars($answer['question_text']); ?></div>
                            <div class="question-points"><?php echo $answer['points']; ?> баллов</div>
                        </div>
                        
                        <div class="answer-section">
                            <div class="<?php echo $answer['is_correct'] ? 'answer-correct' : 'answer-incorrect'; ?>">
                                <strong>Ответ студента:</strong><br>
                                <?php 
                                if ($answer['question_type'] === 'multiple_choice') {
                                    echo htmlspecialchars($answer['selected_option_text'] ?? 'Ответ не найден');
                                } else {
                                    echo htmlspecialchars($answer['answer_text'] ?? 'Текстовый ответ');
                                }
                                ?>
                                <?php if ($answer['is_correct']): ?>
                                    <div style="color: #28a745; margin-top: 8px;">
                                        <i class="fas fa-check"></i> Правильный ответ (+<?php echo $answer['points_earned']; ?> баллов)
                                    </div>
                                <?php else: ?>
                                    <div style="color: #dc3545; margin-top: 8px;">
                                        <i class="fas fa-times"></i> Неправильный ответ (0 баллов)
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <?php if (!$answer['is_correct'] && !empty($answer['correct_answers'])): ?>
                                <div class="correct-answers">
                                    <strong>Правильные ответы:</strong><br>
                                    <?php 
                                    $correct_answers_list = explode('|', $answer['correct_answers']);
                                    foreach ($correct_answers_list as $correct_answer) {
                                        if (!empty($correct_answer)) {
                                            echo '<div style="margin-top: 5px;">• ' . htmlspecialchars($correct_answer) . '</div>';
                                        }
                                    }
                                    ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-question-circle"></i>
                    <h3>Нет данных об ответах</h3>
                    <p>Информация об ответах студента недоступна</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- AI оценки -->
        <div class="glass-card section">
            <div class="section-header">
                <h3 class="section-title"><i class="fas fa-robot"></i> AI оценки результата</h3>
            </div>
            
            <?php if (!empty($predictions)): ?>
                <?php foreach ($predictions as $prediction): ?>
                    <div class="prediction-card">
                        <div class="prediction-header">
                            <div class="prediction-model"><?php echo safe_echo($prediction['model_name']); ?></div>
                            <div class="prediction-confidence">Уверенность: <?php echo round($prediction['confidence'] * 100); ?>%</div>
                        </div>
                        
                        <?php 
                        $prediction_data = json_decode($prediction['prediction_data'], true);
                        if ($prediction_data): ?>
                            <div style="margin-top: 10px;">
                                <?php if (isset($prediction_data['predicted_score'])): ?>
                                    <div><strong>Предсказанная оценка:</strong> <?php echo safe_echo($prediction_data['predicted_score']); ?>/<?php echo $result['total_points']; ?></div>
                                <?php endif; ?>
                                
                                <?php if (isset($prediction_data['explanation'])): ?>
                                    <div style="margin-top: 8px;">
                                        <strong>Объяснение:</strong> <?php echo safe_echo($prediction_data['explanation']); ?>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (isset($prediction_data['details'])): ?>
                                    <div style="margin-top: 8px; font-size: 0.9rem; color: var(--gray);">
                                        <strong>Детали анализа:</strong> <?php echo safe_echo($prediction_data['details']); ?>
                                    </div>
                                <?php endif; ?>

                                <!-- Дополнительные поля из prediction_data -->
                                <?php foreach ($prediction_data as $key => $value): ?>
                                    <?php if (!in_array($key, ['predicted_score', 'explanation', 'details']) && !empty($value)): ?>
                                        <div style="margin-top: 5px; font-size: 0.9rem;">
                                            <strong><?php echo safe_echo(ucfirst(str_replace('_', ' ', $key))); ?>:</strong> <?php echo safe_echo($value); ?>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div style="margin-top: 10px; color: var(--gray); font-style: italic;">
                                Данные анализа недоступны в читаемом формате
                            </div>
                        <?php endif; ?>
                        
                        <div style="margin-top: 10px; font-size: 0.8rem; color: var(--gray);">
                            <i class="fas fa-calendar"></i> Оценка выполнена: <?php echo date('d.m.Y H:i', strtotime($prediction['created_at'])); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-robot"></i>
                    <h3>Нет AI оценок</h3>
                    <p>Для этого результата еще не выполнены AI оценки</p>
                    <a href="evaluate_test.php?test_id=<?php echo $result['test_id']; ?>" class="btn btn-primary" style="margin-top: 15px;">
                        <i class="fas fa-bolt"></i> Выполнить AI оценку
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>