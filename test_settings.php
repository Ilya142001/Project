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
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Настройки теста - <?php echo htmlspecialchars($test['title']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #4a6bdf;
            --secondary: #6c757d;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --light: #f8f9fa;
            --dark: #343a40;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f7fb;
            color: #333;
            line-height: 1.6;
        }
        
        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        
        header {
            background: linear-gradient(135deg, var(--primary), #2c4fdb);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        header h1 {
            font-size: 2.2em;
            margin-bottom: 15px;
        }
        
        nav {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        nav a {
            color: white;
            text-decoration: none;
            padding: 8px 16px;
            border-radius: 5px;
            background: rgba(255,255,255,0.2);
            transition: background 0.3s;
        }
        
        nav a:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .error {
            background-color: #ffebee;
            color: var(--danger);
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border-left: 4px solid var(--danger);
        }
        
        .success {
            background-color: #e8f5e9;
            color: var(--success);
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border-left: 4px solid var(--success);
        }
        
        .card {
            background: white;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            border: 1px solid #e0e0e0;
        }
        
        .card h2 {
            color: var(--primary);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--dark);
        }
        
        input[type="text"],
        input[type="number"],
        textarea,
        select {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.3s;
        }
        
        input[type="text"]:focus,
        input[type="number"]:focus,
        textarea:focus,
        select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(74, 107, 223, 0.1);
        }
        
        textarea {
            min-height: 100px;
            resize: vertical;
        }
        
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background: var(--primary);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn:hover {
            background: #3a5bd0;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(74, 107, 223, 0.3);
        }
        
        .btn-danger {
            background: var(--danger);
        }
        
        .btn-danger:hover {
            background: #c82333;
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
        }
        
        .btn-success {
            background: var(--success);
        }
        
        .btn-success:hover {
            background: #218838;
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
        }
        
        .btn-warning {
            background: var(--warning);
            color: var(--dark);
        }
        
        .btn-warning:hover {
            background: #e0a800;
            box-shadow: 0 4px 12px rgba(255, 193, 7, 0.3);
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .checkbox-group input[type="checkbox"] {
            width: auto;
            transform: scale(1.2);
        }
        
        .danger-zone {
            border: 2px solid var(--danger);
            background: #fff5f5;
        }
        
        .danger-zone h2 {
            color: var(--danger);
            border-bottom-color: var(--danger);
        }
        
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .stat-item {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
        }
        
        .stat-number {
            font-size: 24px;
            font-weight: bold;
            color: var(--primary);
        }
        
        .stat-label {
            font-size: 14px;
            color: var(--secondary);
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }
            
            header {
                padding: 15px;
            }
            
            nav {
                flex-direction: column;
                gap: 10px;
            }
            
            .stats {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1><i class="fas fa-cog"></i> Настройки теста: <?php echo htmlspecialchars($test['title']); ?></h1>
            <nav>
                <a href="test_edit.php?id=<?php echo $test_id; ?>"><i class="fas fa-arrow-left"></i> Назад к редактированию</a>
                <a href="tests.php"><i class="fas fa-list"></i> Мои тесты</a>
                <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Выйти</a>
            </nav>
        </header>

        <?php if (isset($error)): ?>
            <div class="error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
        <?php endif; ?>

        <?php if (isset($success)): ?>
            <div class="success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
        <?php endif; ?>

        <!-- Статистика теста -->
        <div class="card">
            <h2><i class="fas fa-chart-bar"></i> Статистика теста</h2>
            <div class="stats">
                <?php
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
                ?>
                
                <div class="stat-item">
                    <div class="stat-number"><?php echo $question_count; ?></div>
                    <div class="stat-label">Вопросов</div>
                </div>
                
                <div class="stat-item">
                    <div class="stat-number"><?php echo $result_count; ?></div>
                    <div class="stat-label">Завершенных попыток</div>
                </div>
                
                <div class="stat-item">
                    <div class="stat-number"><?php echo $session_count; ?></div>
                    <div class="stat-label">Активных сессий</div>
                </div>
            </div>
        </div>

        <!-- Основные настройки -->
        <div class="card">
            <h2><i class="fas fa-sliders-h"></i> Основные настройки</h2>
            <form method="POST">
                <div class="form-group">
                    <label><i class="fas fa-heading"></i> Название теста:</label>
                    <input type="text" name="title" value="<?php echo htmlspecialchars($test['title']); ?>" required>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-align-left"></i> Описание теста:</label>
                    <textarea name="description" placeholder="Опишите содержание и цели теста..."><?php echo htmlspecialchars($test['description']); ?></textarea>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-clock"></i> Время на выполнение (минут):</label>
                    <input type="number" name="time_limit" value="<?php echo $test['time_limit']; ?>" min="1" max="300" required>
                </div>
                
                <div class="checkbox-group">
                    <input type="checkbox" name="is_active" id="is_active" <?php echo $test['is_active'] ? 'checked' : ''; ?>>
                    <label for="is_active">Тест активен (доступен для прохождения)</label>
                </div>
                
                <div class="checkbox-group">
                    <input type="checkbox" name="is_published" id="is_published" <?php echo $test['is_published'] ? 'checked' : ''; ?>>
                    <label for="is_published">Опубликовать тест (сделать видимым для студентов)</label>
                </div>
                
                <button type="submit" name="save_settings" class="btn btn-success">
                    <i class="fas fa-save"></i> Сохранить настройки
                </button>
            </form>
        </div>

        <!-- Опасная зона -->
        <div class="card danger-zone">
            <h2><i class="fas fa-exclamation-triangle"></i> Опасная зона</h2>
            <p style="margin-bottom: 20px; color: var(--danger);">
                <i class="fas fa-warning"></i> Эти действия нельзя отменить. Будьте осторожны!
            </p>
            
            <form method="POST" onsubmit="return confirm('ВНИМАНИЕ! Вы действительно хотите удалить этот тест? Это действие удалит все вопросы, ответы и результаты теста. Отменить это действие будет невозможно!');">
                <button type="submit" name="delete_test" class="btn btn-danger">
                    <i class="fas fa-trash"></i> Удалить тест полностью
                </button>
            </form>
        </div>
    </div>

    <script>
    // Добавляем подтверждение для чекбоксов публикации
    document.addEventListener('DOMContentLoaded', function() {
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