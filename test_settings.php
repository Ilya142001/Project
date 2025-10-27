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

// Обработка сохранения настроек
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_settings'])) {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $time_limit = intval($_POST['time_limit']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $is_published = isset($_POST['is_published']) ? 1 : 0;
    
    // Валидация
    if (empty($title)) {
        $error = "Название теста не может быть пустым";
    } elseif ($time_limit < 1) {
        $error = "Время на выполнение должно быть не менее 1 минуты";
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE tests SET title = ?, description = ?, time_limit = ?, is_active = ?, is_published = ? WHERE id = ?");
            $stmt->execute([$title, $description, $time_limit, $is_active, $is_published, $test_id]);
            
            $success = "Настройки теста успешно сохранены!";
            
            // Обновляем данные теста
            $stmt = $pdo->prepare("SELECT * FROM tests WHERE id = ?");
            $stmt->execute([$test_id]);
            $test = $stmt->fetch();
            
        } catch (PDOException $e) {
            $error = "Ошибка при сохранении настроек: " . $e->getMessage();
        }
    }
}

// Обработка удаления теста
if (isset($_POST['delete_test'])) {
    try {
        // Начинаем транзакцию
        $pdo->beginTransaction();
        
        // Удаляем связанные данные (предсказания моделей)
        $stmt = $pdo->prepare("DELETE mp FROM model_predictions mp JOIN test_results tr ON mp.test_result_id = tr.id WHERE tr.test_id = ?");
        $stmt->execute([$test_id]);
        
        // Удаляем результаты тестов
        $stmt = $pdo->prepare("DELETE FROM test_results WHERE test_id = ?");
        $stmt->execute([$test_id]);
        
        // Удаляем сессии тестов
        $stmt = $pdo->prepare("DELETE FROM test_sessions WHERE test_id = ?");
        $stmt->execute([$test_id]);
        
        // Удаляем связи вопросов с тестами
        $stmt = $pdo->prepare("DELETE FROM test_questions WHERE test_id = ?");
        $stmt->execute([$test_id]);
        
        // Удаляем варианты ответов вопросов этого теста
        $stmt = $pdo->prepare("DELETE qo FROM question_options qo JOIN questions q ON qo.question_id = q.id WHERE q.test_id = ?");
        $stmt->execute([$test_id]);
        
        // Удаляем вопросы теста
        $stmt = $pdo->prepare("DELETE FROM questions WHERE test_id = ?");
        $stmt->execute([$test_id]);
        
        // Удаляем сам тест
        $stmt = $pdo->prepare("DELETE FROM tests WHERE id = ?");
        $stmt->execute([$test_id]);
        
        $pdo->commit();
        
        header("Location: tests.php");
        exit;
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error = "Ошибка при удалении теста: " . $e->getMessage();
    }
}

// Получаем статистику
$stmt = $pdo->prepare("SELECT COUNT(*) as question_count FROM questions WHERE test_id = ?");
$stmt->execute([$test_id]);
$question_count = $stmt->fetch()['question_count'];

$stmt = $pdo->prepare("SELECT COUNT(*) as result_count FROM test_results WHERE test_id = ?");
$stmt->execute([$test_id]);
$result_count = $stmt->fetch()['result_count'];

$stmt = $pdo->prepare("SELECT COUNT(*) as session_count FROM test_sessions WHERE test_id = ?");
$stmt->execute([$test_id]);
$session_count = $stmt->fetch()['session_count'];

$stmt = $pdo->prepare("SELECT AVG(percentage) as avg_score FROM test_results WHERE test_id = ?");
$stmt->execute([$test_id]);
$avg_score = $stmt->fetch()['avg_score'];
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Настройки теста - <?php echo htmlspecialchars($test['title']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #6366f1;
            --primary-light: #818cf8;
            --primary-dark: #4f46e5;
            --secondary: #64748b;
            --success: #10b981;
            --success-light: #34d399;
            --danger: #ef4444;
            --danger-light: #f87171;
            --warning: #f59e0b;
            --info: #3b82f6;
            --light: #f8fafc;
            --light-gray: #f1f5f9;
            --border: #e2e8f0;
            --border-dark: #cbd5e1;
            --text: #334155;
            --text-light: #64748b;
            --text-dark: #1e293b;
            --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --radius: 16px;
            --radius-sm: 12px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            color: var(--text);
            line-height: 1.6;
            min-height: 100vh;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }
        
        /* Header Styles */
        .header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 2rem;
            border-radius: var(--radius);
            margin-bottom: 2rem;
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
        }
        
        .header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 100" fill="rgba(255,255,255,0.1)"><polygon points="0,0 1000,100 1000,0"/></svg>');
            background-size: cover;
        }
        
        .header-content {
            position: relative;
            z-index: 2;
        }
        
        .header h1 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            color: rgba(255, 255, 255, 0.9);
            font-size: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .breadcrumb a {
            color: white;
            text-decoration: none;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .breadcrumb a:hover {
            color: rgba(255, 255, 255, 0.8);
            transform: translateX(-2px);
        }
        
        .nav-actions {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }
        
        .nav-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1.5rem;
            background: rgba(255, 255, 255, 0.15);
            color: white;
            text-decoration: none;
            border-radius: var(--radius-sm);
            font-weight: 500;
            transition: var(--transition);
            border: 1px solid rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
        }
        
        .nav-btn:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        
        /* Alert Styles */
        .alert {
            padding: 1.25rem 1.5rem;
            border-radius: var(--radius-sm);
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            font-weight: 500;
            border-left: 4px solid;
            animation: slideIn 0.4s ease-out;
            box-shadow: var(--shadow);
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
        
        .alert-error {
            background: #fef2f2;
            color: var(--danger);
            border-left-color: var(--danger);
        }
        
        .alert-success {
            background: #f0fdf4;
            color: var(--success);
            border-left-color: var(--success);
        }
        
        /* Card Styles */
        .card {
            background: white;
            border-radius: var(--radius);
            padding: 2.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }
        
        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: linear-gradient(to bottom, var(--primary), var(--success));
        }
        
        .card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-4px);
        }
        
        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--light-gray);
        }
        
        .card-title {
            font-size: 1.75rem;
            font-weight: 600;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: linear-gradient(135deg, var(--light) 0%, white 100%);
            padding: 2rem;
            border-radius: var(--radius-sm);
            text-align: center;
            border: 1px solid var(--border);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--success));
        }
        
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-md);
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            color: white;
            font-size: 1.5rem;
        }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 0.5rem;
            line-height: 1;
        }
        
        .stat-label {
            color: var(--text-light);
            font-size: 1rem;
            font-weight: 500;
        }
        
        /* Form Styles */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
        }
        
        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-label {
            display: block;
            margin-bottom: 0.75rem;
            font-weight: 600;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 1.1rem;
        }
        
        .form-input,
        .form-textarea,
        .form-select {
            width: 100%;
            padding: 1.25rem;
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 1rem;
            font-family: inherit;
            transition: var(--transition);
            background: white;
        }
        
        .form-input:focus,
        .form-textarea:focus,
        .form-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
            transform: translateY(-2px);
        }
        
        .form-textarea {
            min-height: 140px;
            resize: vertical;
            line-height: 1.6;
        }
        
        /* Checkbox Styles */
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding: 1rem;
            background: var(--light-gray);
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            transition: var(--transition);
        }
        
        .checkbox-group:hover {
            background: white;
            border-color: var(--primary);
        }
        
        .checkbox-group input[type="checkbox"] {
            width: 24px;
            height: 24px;
            border-radius: 6px;
            border: 2px solid var(--border);
            cursor: pointer;
            transition: var(--transition);
        }
        
        .checkbox-group input[type="checkbox"]:checked {
            background: var(--success);
            border-color: var(--success);
        }
        
        .checkbox-label {
            flex: 1;
            font-weight: 500;
            color: var(--text-dark);
            cursor: pointer;
        }
        
        .checkbox-hint {
            font-size: 0.875rem;
            color: var(--text-light);
            margin-top: 0.25rem;
        }
        
        /* Button Styles */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            padding: 1.25rem 2.5rem;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 1.1rem;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            font-family: inherit;
            transition: var(--transition);
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
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: left 0.5s;
        }
        
        .btn:hover::before {
            left: 100%;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }
        
        .btn-success {
            background: linear-gradient(135deg, var(--success) 0%, #059669 100%);
            color: white;
        }
        
        .btn-success:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px -3px rgba(16, 185, 129, 0.4);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, var(--danger) 0%, #dc2626 100%);
            color: white;
        }
        
        .btn-danger:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px -3px rgba(239, 68, 68, 0.4);
        }
        
        .btn-outline {
            background: transparent;
            border: 2px solid var(--primary);
            color: var(--primary);
        }
        
        .btn-outline:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-3px);
        }
        
        /* Danger Zone */
        .danger-zone {
            border: 2px solid var(--danger);
            background: linear-gradient(135deg, #fef2f2 0%, #fecaca 100%);
            position: relative;
        }
        
        .danger-zone::before {
            background: linear-gradient(to bottom, var(--danger), #dc2626);
        }
        
        .danger-zone .card-title {
            color: var(--danger);
        }
        
        .danger-content {
            text-align: center;
            padding: 2rem;
        }
        
        .warning-icon {
            font-size: 4rem;
            color: var(--danger);
            margin-bottom: 1.5rem;
        }
        
        .danger-text {
            color: var(--danger);
            font-size: 1.2rem;
            margin-bottom: 2rem;
            font-weight: 500;
        }
        
        /* Status Badges */
        .status-badge {
            padding: 0.75rem 1.5rem;
            border-radius: 50px;
            font-size: 0.875rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .status-active {
            background: #f0fdf4;
            color: var(--success);
            border: 1px solid #bbf7d0;
        }
        
        .status-inactive {
            background: #fef2f2;
            color: var(--danger);
            border: 1px solid #fecaca;
        }
        
        .status-published {
            background: #eff6ff;
            color: var(--info);
            border: 1px solid #bfdbfe;
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }
            
            .header {
                padding: 1.5rem;
            }
            
            .header h1 {
                font-size: 2rem;
            }
            
            .nav-actions {
                flex-direction: column;
            }
            
            .card {
                padding: 1.5rem;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
        }
        
        /* Loading animation */
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 1s ease-in-out infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Progress bar for time limit */
        .time-slider {
            width: 100%;
            height: 8px;
            background: var(--light-gray);
            border-radius: 4px;
            margin-top: 0.5rem;
            overflow: hidden;
        }
        
        .time-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), var(--success));
            border-radius: 4px;
            transition: width 0.3s ease;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <header class="header">
            <div class="header-content">
                <h1>
                    <i class="fas fa-cog"></i>
                    Настройки теста
                </h1>
                <div class="breadcrumb">
                    <a href="tests.php">
                        <i class="fas fa-arrow-left"></i>
                        Мои тесты
                    </a>
                    <i class="fas fa-chevron-right"></i>
                    <a href="test_edit.php?id=<?php echo $test_id; ?>">
                        <i class="fas fa-edit"></i>
                        Редактирование
                    </a>
                    <i class="fas fa-chevron-right"></i>
                    <span>Настройки</span>
                </div>
                <div class="nav-actions">
                    <a href="test_edit.php?id=<?php echo $test_id; ?>" class="nav-btn">
                        <i class="fas fa-arrow-left"></i>
                        Назад к редактированию
                    </a>
                    <a href="tests.php" class="nav-btn">
                        <i class="fas fa-list"></i>
                        Все тесты
                    </a>
                </div>
            </div>
        </header>

        <!-- Alerts -->
        <?php if (isset($error)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <?php if (isset($success)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo $success; ?>
            </div>
        <?php endif; ?>

        <!-- Statistics Card -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <i class="fas fa-chart-bar"></i>
                    Статистика теста
                </h2>
                <div>
                    <span class="status-badge <?php echo $test['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                        <i class="fas fa-circle"></i>
                        <?php echo $test['is_active'] ? 'Активный' : 'Неактивный'; ?>
                    </span>
                    <span class="status-badge status-published">
                        <i class="fas fa-eye"></i>
                        <?php echo $test['is_published'] ? 'Опубликован' : 'Черновик'; ?>
                    </span>
                </div>
            </div>
            
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-question-circle"></i>
                    </div>
                    <div class="stat-number"><?php echo $question_count; ?></div>
                    <div class="stat-label">Вопросов в тесте</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-number"><?php echo $result_count; ?></div>
                    <div class="stat-label">Завершенных попыток</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-play-circle"></i>
                    </div>
                    <div class="stat-number"><?php echo $session_count; ?></div>
                    <div class="stat-label">Активных сессий</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-number"><?php echo $avg_score ? round($avg_score, 1) . '%' : 'N/A'; ?></div>
                    <div class="stat-label">Средний результат</div>
                </div>
            </div>
        </div>

        <!-- Main Settings Card -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <i class="fas fa-sliders-h"></i>
                    Основные настройки
                </h2>
            </div>
            
            <form method="POST" id="settingsForm">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-heading"></i>
                            Название теста:
                        </label>
                        <input type="text" name="title" class="form-input" value="<?php echo htmlspecialchars($test['title']); ?>" required placeholder="Введите название теста">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-clock"></i>
                            Время на выполнение:
                        </label>
                        <input type="number" name="time_limit" class="form-input" value="<?php echo $test['time_limit']; ?>" min="1" max="300" required>
                        <div class="time-slider">
                            <div class="time-fill" style="width: <?php echo min(100, ($test['time_limit'] / 180 * 100)); ?>%"></div>
                        </div>
                        <div style="font-size: 0.875rem; color: var(--text-light); margin-top: 0.5rem;">
                            Рекомендуется: 15-60 минут
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">
                        <i class="fas fa-align-left"></i>
                        Описание теста:
                    </label>
                    <textarea name="description" class="form-textarea" placeholder="Опишите содержание, цели и особенности теста..."><?php echo htmlspecialchars($test['description']); ?></textarea>
                </div>
                
                <div class="checkbox-group">
                    <input type="checkbox" name="is_active" id="is_active" <?php echo $test['is_active'] ? 'checked' : ''; ?>>
                    <div style="flex: 1;">
                        <label for="is_active" class="checkbox-label">Активировать тест</label>
                        <div class="checkbox-hint">Сделать тест доступным для прохождения</div>
                    </div>
                </div>
                
                <div class="checkbox-group">
                    <input type="checkbox" name="is_published" id="is_published" <?php echo $test['is_published'] ? 'checked' : ''; ?>>
                    <div style="flex: 1;">
                        <label for="is_published" class="checkbox-label">Опубликовать тест</label>
                        <div class="checkbox-hint">Сделать тест видимым для студентов</div>
                    </div>
                </div>
                
                <div style="display: flex; gap: 1rem; margin-top: 2rem; flex-wrap: wrap;">
                    <button type="submit" name="save_settings" class="btn btn-success">
                        <i class="fas fa-save"></i>
                        Сохранить настройки
                    </button>
                    <button type="button" class="btn btn-outline" onclick="resetForm()">
                        <i class="fas fa-undo"></i>
                        Сбросить изменения
                    </button>
                </div>
            </form>
        </div>

        <!-- Danger Zone Card -->
        <div class="card danger-zone">
            <div class="card-header">
                <h2 class="card-title">
                    <i class="fas fa-exclamation-triangle"></i>
                    Опасная зона
                </h2>
            </div>
            
            <div class="danger-content">
                <div class="warning-icon">
                    <i class="fas fa-radiation"></i>
                </div>
                <div class="danger-text">
                    Эти действия нельзя отменить. Все данные будут удалены безвозвратно!
                </div>
                
                <form method="POST" id="deleteForm" onsubmit="return confirmDeletion()">
                    <button type="submit" name="delete_test" class="btn btn-danger">
                        <i class="fas fa-trash"></i>
                        Удалить тест полностью
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
    function resetForm() {
        if (confirm('Сбросить все изменения к исходным значениям?')) {
            document.getElementById('settingsForm').reset();
        }
    }
    
    function confirmDeletion() {
        const testTitle = "<?php echo addslashes($test['title']); ?>";
        const questionCount = <?php echo $question_count; ?>;
        const resultCount = <?php echo $result_count; ?>;
        
        const message = `ВНИМАНИЕ! Вы собираетесь удалить тест "${testTitle}".\n\n` +
                       `Это приведет к удалению:\n` +
                       `• ${questionCount} вопросов\n` +
                       `• ${resultCount} результатов тестирования\n` +
                       `• Всех связанных данных\n\n` +
                       `Это действие НЕВОЗМОЖНО отменить!\n\n` +
                       `Для подтверждения введите название теста:`;
        
        const userInput = prompt(message);
        
        if (userInput === testTitle) {
            return true;
        } else if (userInput !== null) {
            alert('Название теста введено неверно. Удаление отменено.');
        }
        return false;
    }
    
    // Обновление прогресс-бара времени
    document.addEventListener('DOMContentLoaded', function() {
        const timeInput = document.querySelector('input[name="time_limit"]');
        const timeFill = document.querySelector('.time-fill');
        
        function updateTimeProgress() {
            const value = parseInt(timeInput.value) || 1;
            const percentage = Math.min(100, (value / 180 * 100));
            timeFill.style.width = percentage + '%';
        }
        
        timeInput.addEventListener('input', updateTimeProgress);
        updateTimeProgress();
        
        // Взаимодействие чекбоксов
        const publishCheckbox = document.getElementById('is_published');
        const activeCheckbox = document.getElementById('is_active');
        
        publishCheckbox.addEventListener('change', function() {
            if (this.checked && !activeCheckbox.checked) {
                if (confirm('Для публикации тест должен быть активен. Активировать тест?')) {
                    activeCheckbox.checked = true;
                } else {
                    this.checked = false;
                }
            }
        });
        
        activeCheckbox.addEventListener('change', function() {
            if (!this.checked && publishCheckbox.checked) {
                if (confirm('Если тест не активен, он не будет виден студентам. Снять с публикации?')) {
                    publishCheckbox.checked = false;
                } else {
                    this.checked = true;
                }
            }
        });
    });
    </script>
</body>
</html>