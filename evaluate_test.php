<?php
// evaluate_test.php
include 'config.php';
include 'ml_model_handler.php';

// Проверяем авторизацию
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$test_id = $_GET['test_id'] ?? null;
$model_id = $_GET['model_id'] ?? null;

if (!$test_id) {
    header("Location: tests.php");
    exit;
}

// Получаем информацию о тесте
$stmt = $pdo->prepare("SELECT * FROM tests WHERE id = ?");
$stmt->execute([$test_id]);
$test = $stmt->fetch();

if (!$test) {
    header("Location: tests.php?error=test_not_found");
    exit;
}

// Получаем результаты теста с аватарами студентов
$stmt = $pdo->prepare("
    SELECT tr.*, u.full_name as student_name, u.avatar as student_avatar
    FROM test_results tr
    JOIN users u ON tr.user_id = u.id
    WHERE tr.test_id = ?
    ORDER BY tr.completed_at DESC
");
$stmt->execute([$test_id]);
$test_results = $stmt->fetchAll();

// Создаем обработчик моделей
$modelHandler = new MLModelHandler($pdo);

// Получаем примененные модели
$applied_models = [];
if ($model_id) {
    // Конкретная модель
    $stmt = $pdo->prepare("SELECT * FROM ml_models WHERE id = ?");
    $stmt->execute([$model_id]);
    $applied_models = $stmt->fetchAll();
} else {
    // Все примененные модели
    $applied_models = $modelHandler->getAppliedModelsForTest($test_id);
}

// Обработка массовой оценки
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['evaluate_all'])) {
        // Массовая оценка всех результатов
        $selected_model_id = $_POST['model_id'] ?? $model_id;
        $success_count = 0;
        $error_count = 0;
        
        if ($selected_model_id) {
            foreach ($test_results as $result) {
                try {
                    $prediction = $modelHandler->applyToTestResult($selected_model_id, $result['id']);
                    if ($prediction) {
                        $success_count++;
                    } else {
                        $error_count++;
                    }
                } catch (Exception $e) {
                    $error_count++;
                }
            }
            
            if ($success_count > 0) {
                $success = "Успешно оценено {$success_count} результатов";
                if ($error_count > 0) {
                    $success .= " ({$error_count} с ошибками)";
                }
            } else {
                $error = "Не удалось оценить ни одного результата";
            }
        }
    }
    elseif (isset($_POST['evaluate'])) {
        // Оценка одного результата
        $test_result_id = $_POST['test_result_id'];
        $selected_model_id = $_POST['model_id'] ?? $model_id;
        
        if ($test_result_id && $selected_model_id) {
            try {
                $prediction = $modelHandler->applyToTestResult($selected_model_id, $test_result_id);
                
                if ($prediction) {
                    $success = "Оценка выполнена успешно!";
                } else {
                    $error = "Ошибка при выполнении оценки";
                }
                
            } catch (Exception $e) {
                $error = "Ошибка: " . $e->getMessage();
            }
        }
    }
}

// Получаем существующие предсказания с группировкой по результатам
$predictions_map = [];
$latest_predictions = []; // Только последние оценки для каждого результата

foreach ($test_results as $result) {
    $all_predictions = $modelHandler->getPredictionsForTestResult($result['id']);
    $predictions_map[$result['id']] = $all_predictions;
    
    // Группируем по model_id и берем только последнюю оценку для каждой модели
    $grouped_predictions = [];
    foreach ($all_predictions as $prediction) {
        $model_id = $prediction['model_id'];
        if (!isset($grouped_predictions[$model_id]) || 
            strtotime($prediction['created_at']) > strtotime($grouped_predictions[$model_id]['created_at'])) {
            $grouped_predictions[$model_id] = $prediction;
        }
    }
    $latest_predictions[$result['id']] = array_values($grouped_predictions);
}

// Статистика по оценкам
$total_predictions = 0;
$avg_confidence = 0;
foreach ($predictions_map as $predictions) {
    $total_predictions += count($predictions);
    foreach ($predictions as $prediction) {
        $avg_confidence += $prediction['confidence'];
    }
}
if ($total_predictions > 0) {
    $avg_confidence = round(($avg_confidence / $total_predictions) * 100, 1);
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Оценка теста - <?php echo htmlspecialchars($test['title']); ?></title>
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
            --hover-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', 'Segoe UI', system-ui, sans-serif;
        }

        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: var(--dark);
            line-height: 1.6;
        }

        .container {
            max-width: 1400px;
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
        }

        .header {
            padding: 40px;
            margin-bottom: 30px;
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
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: inline-flex;
            align-items: center;
            gap: 8px;
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
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }

        .btn:hover::before {
            left: 100%;
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
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
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

        .section {
            padding: 30px;
            margin-bottom: 25px;
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

        .model-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }

        .model-card {
            background: white;
            padding: 25px;
            border-radius: 16px;
            border-left: 5px solid var(--primary);
            box-shadow: var(--card-shadow);
            transition: all 0.3s ease;
        }

        .model-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--hover-shadow);
        }

        .model-name {
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 10px;
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
            margin-bottom: 15px;
        }

        .mass-evaluation {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 25px;
            border: 2px dashed #ffd43b;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--dark);
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid var(--border);
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: white;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: var(--card-shadow);
        }

        table th {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            font-weight: 600;
            padding: 20px;
            text-align: left;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        table td {
            padding: 20px;
            border-bottom: 1px solid var(--border);
            vertical-align: top;
        }

        table tr:last-child td {
            border-bottom: none;
        }

        table tr:hover td {
            background: rgba(67, 97, 238, 0.03);
        }

        .student-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .student-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--primary-light);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }

        .avatar-placeholder {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.2rem;
            border: 3px solid var(--primary-light);
        }

        .student-details {
            display: flex;
            flex-direction: column;
        }

        .student-name {
            font-weight: 600;
            color: var(--dark);
        }

        .student-id {
            font-size: 0.8rem;
            color: var(--gray);
        }

        .score-display {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .score-value {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--dark);
        }

        .score-percentage {
            font-size: 0.9rem;
            padding: 4px 8px;
            border-radius: 8px;
            text-align: center;
            font-weight: 600;
        }

        .percentage-high { background: #d4edda; color: #155724; }
        .percentage-medium { background: #fff3cd; color: #856404; }
        .percentage-low { background: #f8d7da; color: #721c24; }

        .time-display {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .time-value {
            font-weight: 600;
            color: var(--dark);
        }

        .time-minutes {
            font-size: 0.8rem;
            color: var(--gray);
        }

        .predictions-container {
            max-width: 300px;
        }

        .prediction-item {
            background: var(--light);
            padding: 12px;
            border-radius: 10px;
            margin-bottom: 8px;
            border-left: 3px solid var(--primary);
            position: relative;
        }

        .prediction-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 5px;
        }

        .prediction-model {
            font-weight: 600;
            font-size: 0.8rem;
            color: var(--dark);
        }

        .confidence-badge {
            background: var(--primary);
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .prediction-result {
            font-size: 0.8rem;
            margin-bottom: 3px;
        }

        .prediction-explanation {
            font-size: 0.7rem;
            color: var(--gray);
            font-style: italic;
        }

        .prediction-date {
            font-size: 0.6rem;
            color: var(--gray);
            margin-top: 3px;
        }

        .prediction-count {
            position: absolute;
            top: -8px;
            right: -8px;
            background: var(--accent);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 0.6rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }

        .compact-predictions {
            max-height: 120px;
            overflow-y: auto;
            padding-right: 5px;
        }

        .compact-predictions::-webkit-scrollbar {
            width: 4px;
        }

        .compact-predictions::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 2px;
        }

        .compact-predictions::-webkit-scrollbar-thumb {
            background: var(--primary);
            border-radius: 2px;
        }

        .action-buttons {
            display: flex;
    flex-direction: column;
    gap: 8px;
    min-width: 180px; /* Увеличиваем минимальную ширину */
        }

        .btn-sm {
            padding: 10px 16px; /* Увеличиваем padding */
    font-size: 0.85rem; /* Немного увеличиваем шрифт */
    border-radius: 8px;
    min-width: 120px; /* Минимальная ширина кнопок */
    justify-content: center;
        }
        /* Увеличиваем ячейку с действиями в таблице */
table td:nth-child(6) {
    min-width: 200px;
}

        .btn-success {
            background: linear-gradient(135deg, #4cc9f0, #4895ef);
            color: white;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(76, 201, 240, 0.4);
        }

        .view-all-predictions {
            background: transparent;
            border: 1px solid var(--primary);
            color: var(--primary);
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.7rem;
            cursor: pointer;
            margin-top: 8px;
            transition: all 0.3s ease;
        }

        .view-all-predictions:hover {
            background: var(--primary);
            color: white;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background: white;
            margin: 5% auto;
            padding: 0;
            border-radius: 20px;
            width: 90%;
            max-width: 800px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            animation: modalSlideIn 0.3s ease-out;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px) scale(0.9);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .modal-header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 25px;
            border-radius: 20px 20px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title {
            font-size: 1.5rem;
            font-weight: 700;
        }

        .close {
            color: white;
            font-size: 1.5rem;
            font-weight: bold;
            cursor: pointer;
            background: none;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.3s ease;
        }

        .close:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .modal-body {
            padding: 30px;
            max-height: 70vh;
            overflow-y: auto;
        }

        .modal-section {
            margin-bottom: 25px;
        }

        .modal-section-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 15px;
            color: var(--dark);
            padding-bottom: 10px;
            border-bottom: 2px solid var(--border);
        }

        .detail-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .detail-label {
            font-size: 0.8rem;
            color: var(--gray);
            font-weight: 500;
        }

        .detail-value {
            font-weight: 600;
            color: var(--dark);
        }

        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.3s ease-out;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .alert-success {
            background: rgba(76, 201, 240, 0.1);
            color: #155724;
            border: 1px solid #4cc9f0;
        }

        .alert-error {
            background: rgba(220, 53, 69, 0.1);
            color: #721c24;
            border: 1px solid #f8d7da;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .empty-state h3 {
            font-size: 1.5rem;
            margin-bottom: 10px;
            color: var(--dark);
        }

        .prediction-history {
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 15px;
        }

        .history-item {
            padding: 10px;
            border-bottom: 1px solid var(--border);
            margin-bottom: 8px;
        }

        .history-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }

        .history-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 5px;
        }

        .history-date {
            font-size: 0.7rem;
            color: var(--gray);
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
            
            .model-grid {
                grid-template-columns: 1fr;
            }
            
            table {
                display: block;
                overflow-x: auto;
            }
            
            .detail-grid {
                grid-template-columns: 1fr;
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
            <h1><i class="fas fa-brain"></i> AI Оценка теста</h1>
            <h2><?php echo htmlspecialchars($test['title']); ?></h2>
            <p style="margin-top: 10px; color: var(--gray); max-width: 600px; margin-left: auto; margin-right: auto;">
                <?php echo htmlspecialchars($test['description']); ?>
            </p>
            
            <div class="nav-buttons">
                <a href="model_details.php?id=<?php echo $model_id ?: ''; ?>" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i> Назад к модели
                </a>
                <a href="test_details.php?id=<?php echo $test_id; ?>" class="btn btn-outline">
                    <i class="fas fa-info-circle"></i> Детали теста
                </a>
                <a href="tests.php" class="btn btn-primary">
                    <i class="fas fa-list"></i> Все тесты
                </a>
            </div>
        </div>

        <!-- Сообщения -->
        <?php if (isset($success)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo $success; ?>
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <!-- Статистика -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo count($test_results); ?></div>
                <div class="stat-label">Результатов теста</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo count($applied_models); ?></div>
                <div class="stat-label">ML моделей</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $total_predictions; ?></div>
                <div class="stat-label">AI оценок</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $avg_confidence; ?>%</div>
                <div class="stat-label">Уверенность AI</div>
            </div>
        </div>

        <!-- Примененные модели -->
        <div class="glass-card section">
            <div class="section-header">
                <h3 class="section-title"><i class="fas fa-robot"></i> Примененные AI модели</h3>
            </div>
            
            <?php if (!empty($applied_models)): ?>
                <div class="model-grid">
                    <?php foreach ($applied_models as $model): ?>
                        <div class="model-card">
                            <div class="model-name"><?php echo htmlspecialchars($model['name']); ?></div>
                            <div class="model-accuracy">
                                <i class="fas fa-bullseye"></i> Точность: <?php echo $model['accuracy']; ?>%
                            </div>
                            <p style="font-size: 14px; color: var(--gray); line-height: 1.5;">
                                <?php echo htmlspecialchars($model['description']); ?>
                            </p>
                            <div style="margin-top: 15px; font-size: 12px; color: var(--gray);">
                                <i class="fas fa-calendar"></i> Создана: <?php echo date('d.m.Y', strtotime($model['created_at'])); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-robot"></i>
                    <h3>Нет примененных моделей</h3>
                    <p>К этому тесту еще не применены AI модели</p>
                    <a href="models.php" class="btn btn-primary" style="margin-top: 20px;">
                        <i class="fas fa-plus"></i> Выбрать модели
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Массовая оценка -->
        <?php if (!empty($applied_models) && !empty($test_results)): ?>
        <div class="mass-evaluation">
            <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 20px;">
                <i class="fas fa-bolt" style="font-size: 1.5rem; color: #856404;"></i>
                <h3 style="color: #856404; margin: 0;">Массовая AI оценка</h3>
            </div>
            <form method="POST">
                <div class="form-group">
                    <label class="form-label">Выберите модель для оценки всех результатов:</label>
                    <select name="model_id" class="form-control" required>
                        <option value="">-- Выберите AI модель --</option>
                        <?php foreach ($applied_models as $model): ?>
                            <option value="<?php echo $model['id']; ?>" <?php echo $model_id == $model['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($model['name']); ?> (Точность: <?php echo $model['accuracy']; ?>%)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" name="evaluate_all" class="btn btn-primary" 
                        onclick="return confirm('Вы уверены, что хотите оценить все <?php echo count($test_results); ?> результатов с помощью AI?')">
                    <i class="fas fa-bolt"></i> Запустить AI оценку (<?php echo count($test_results); ?> результатов)
                </button>
                <small style="display: block; margin-top: 12px; color: #856404;">
                    <i class="fas fa-info-circle"></i> AI модель проанализирует все результаты и предоставит оценки
                </small>
            </form>
        </div>
        <?php endif; ?>

        <!-- Результаты теста -->
        <div class="glass-card section">
            <div class="section-header">
                <h3 class="section-title"><i class="fas fa-list-alt"></i> Результаты студентов</h3>
                <span style="background: var(--primary); color: white; padding: 8px 16px; border-radius: 20px; font-size: 14px; font-weight: 600;">
                    <?php echo count($test_results); ?> записей
                </span>
            </div>

            <?php if (!empty($test_results)): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Студент</th>
                            <th>Результат</th>
                            <th>Время</th>
                            <th>Дата</th>
                            <th>AI Оценки</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($test_results as $result): 
                            $percentage_class = 'percentage-medium';
                            if ($result['percentage'] >= 80) $percentage_class = 'percentage-high';
                            elseif ($result['percentage'] < 60) $percentage_class = 'percentage-low';
                            
                            // Получаем первую букву имени для аватара
                            $firstName = $result['student_name'];
                            if (function_exists('mb_convert_encoding')) {
                                $firstName = mb_convert_encoding($firstName, 'UTF-8', 'auto');
                            }
                            $firstLetter = mb_substr($firstName, 0, 1, 'UTF-8');
                            
                            // Получаем актуальные оценки (последние для каждой модели)
                            $current_predictions = $latest_predictions[$result['id']] ?? [];
                            $total_predictions_count = count($predictions_map[$result['id']] ?? []);
                        ?>
                        <tr>
                            <td>
                                <div class="student-info">
                                    <?php if (!empty($result['student_avatar'])): ?>
                                        <?php
                                        $avatarPath = $result['student_avatar'];
                                        // Проверяем, существует ли файл
                                        if (file_exists($avatarPath)): ?>
                                            <img src="<?php echo htmlspecialchars($avatarPath); ?>" 
                                                 alt="<?php echo htmlspecialchars($result['student_name']); ?>" 
                                                 class="student-avatar">
                                        <?php else: ?>
                                            <div class="avatar-placeholder">
                                                <?php echo htmlspecialchars(strtoupper($firstLetter), ENT_QUOTES, 'UTF-8'); ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="avatar-placeholder">
                                            <?php echo htmlspecialchars(strtoupper($firstLetter), ENT_QUOTES, 'UTF-8'); ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="student-details">
                                        <span class="student-name"><?php echo htmlspecialchars($result['student_name']); ?></span>
                                        <span class="student-id">ID: <?php echo $result['user_id']; ?></span>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="score-display">
                                    <div class="score-value">
                                        <?php echo $result['score']; ?>/<?php echo $result['total_points']; ?>
                                    </div>
                                    <div class="score-percentage <?php echo $percentage_class; ?>">
                                        <?php echo $result['percentage']; ?>%
                                    </div>
                                    <?php if ($result['passed']): ?>
                                        <div style="font-size: 11px; color: var(--success); margin-top: 3px;">
                                            <i class="fas fa-check-circle"></i> Тест пройден
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div class="time-display">
                                    <?php if ($result['time_spent'] > 0): ?>
                                        <div class="time-value"><?php echo gmdate("H:i:s", $result['time_spent']); ?></div>
                                        <div class="time-minutes"><?php echo round($result['time_spent'] / 60, 1); ?> минут</div>
                                    <?php else: ?>
                                        <span style="color: var(--gray);">—</span>
                                    <?php endif; ?>
                                </div>
                                                       </td>
                            <td>
                                <div class="time-display">
                                    <div class="time-value"><?php echo date('d.m.Y', strtotime($result['completed_at'])); ?></div>
                                    <div class="time-minutes"><?php echo date('H:i', strtotime($result['completed_at'])); ?></div>
                                </div>
                            </td>
                            <td>
                                <div class="predictions-container">
                                    <?php if (!empty($current_predictions)): ?>
                                        <div class="compact-predictions">
                                            <?php foreach ($current_predictions as $prediction): ?>
                                                <div class="prediction-item">
                                                    
                                                    <div class="prediction-header">
                                                        <span class="prediction-model"><?php echo htmlspecialchars($prediction['model_name']); ?></span>
                                                        <span class="confidence-badge"><?php echo round($prediction['confidence'] * 100); ?>%</span>
                                                    </div>
                                                    <div class="prediction-result">
                                                        Оценка: <strong><?php echo $prediction['predicted_score']; ?>/<?php echo $result['total_points']; ?></strong>
                                                    </div>
                                                    <?php if (!empty($prediction['explanation'])): ?>
                                                        <div class="prediction-explanation">
                                                            <?php echo htmlspecialchars(mb_strimwidth($prediction['explanation'], 0, 50, '...')); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <div class="prediction-date">
                                                        <?php echo date('d.m.Y H:i', strtotime($prediction['created_at'])); ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <button type="button" class="view-all-predictions" 
                                                onclick="openPredictionModal(<?php echo $result['id']; ?>)">
                                            <i class="fas fa-expand"></i> Все оценки
                                        </button>
                                    <?php else: ?>
                                        <span style="color: var(--gray); font-style: italic;">Нет оценок</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <?php if (!empty($applied_models)): ?>
                                        <form method="POST" style="margin: 0;">
                                            <input type="hidden" name="test_result_id" value="<?php echo $result['id']; ?>">
                                            <select name="model_id" class="form-control" style="margin-bottom: 8px; font-size: 12px; padding: 6px 10px;" required>
                                                <option value="">-- Модель --</option>
                                                <?php foreach ($applied_models as $model): ?>
                                                    <option value="<?php echo $model['id']; ?>"><?php echo htmlspecialchars($model['name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" name="evaluate" class="btn btn-success btn-sm">
                                                <i class="fas fa-robot"></i> AI Оценка
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <a href="test_result_details.php?id=<?php echo $result['id']; ?>" class="btn btn-outline btn-sm" style="text-align: center;">
                                        <i class="fas fa-eye"></i> Детали
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-clipboard-list"></i>
                    <h3>Нет результатов теста</h3>
                    <p>Студенты еще не прошли этот тест</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Модальное окно для просмотра всех оценок -->
    <div id="predictionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fas fa-chart-line"></i> История AI оценок</h3>
                <button type="button" class="close">&times;</button>
            </div>
            <div class="modal-body">
                <div id="modalContent">
                    <!-- Контент будет загружен через AJAX -->
                </div>
            </div>
        </div>
    </div>

    <script>
        // Функция для открытия модального окна с историей оценок
        function openPredictionModal(testResultId) {
            // Загружаем данные через AJAX
            fetch(`get_prediction_history.php?test_result_id=${testResultId}`)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('modalContent').innerHTML = data;
                    document.getElementById('predictionModal').style.display = 'block';
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('modalContent').innerHTML = '<div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> Ошибка загрузки данных</div>';
                    document.getElementById('predictionModal').style.display = 'block';
                });
        }

        // Закрытие модального окна
        document.querySelector('.close').addEventListener('click', function() {
            document.getElementById('predictionModal').style.display = 'none';
        });

        // Закрытие при клике вне окна
        window.addEventListener('click', function(event) {
            const modal = document.getElementById('predictionModal');
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        });

        // Автоматическое скрытие сообщений через 5 секунд
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.opacity = '0';
                alert.style.transition = 'opacity 0.5s ease';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);
    </script>
</body>
</html>