<?php
// apply_model.php
include 'config.php';
checkAuth();

// Проверяем роль (только админы и преподаватели)
if ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'teacher') {
    die("Доступ запрещен");
}

$model_id = $_GET['model_id'] ?? null;

if (!$model_id) {
    header("Location: ml_models.php");
    exit;
}

// Получаем информацию о модели
$stmt = $pdo->prepare("SELECT * FROM ml_models WHERE id = ?");
$stmt->execute([$model_id]);
$model = $stmt->fetch();

if (!$model) {
    header("Location: ml_models.php?error=model_not_found");
    exit;
}

// Получаем все активные тесты
$stmt = $pdo->prepare("SELECT * FROM tests WHERE is_active = 1 ORDER BY title");
$stmt->execute();
$tests = $stmt->fetchAll();

// Получаем уже примененные тесты
$stmt = $pdo->prepare("
    SELECT t.id, t.title 
    FROM model_test_assignments mta
    JOIN tests t ON mta.test_id = t.id
    WHERE mta.model_id = ?
");
$stmt->execute([$model_id]);
$assigned_tests = $stmt->fetchAll();

// Обработка формы
if ($_POST['assign_test'] ?? '') {
    $test_id = $_POST['test_id'];
    
    try {
        // Создаем связь модель-тест
        $stmt = $pdo->prepare("
            INSERT INTO model_test_assignments (model_id, test_id, assigned_by) 
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$model_id, $test_id, $_SESSION['user_id']]);
        
        $success = "Модель успешно применена к тесту!";
        
    } catch (PDOException $e) {
        $error = "Ошибка: " . $e->getMessage();
    }
}

if ($_POST['remove_assignment'] ?? '') {
    $test_id = $_POST['test_id'];
    
    try {
        $stmt = $pdo->prepare("DELETE FROM model_test_assignments WHERE model_id = ? AND test_id = ?");
        $stmt->execute([$model_id, $test_id]);
        
        $success = "Связь с тестом удалена!";
        
    } catch (PDOException $e) {
        $error = "Ошибка: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Применить модель: <?php echo htmlspecialchars($model['name']); ?></title>
    <style>
        body { font-family: Arial; margin: 20px; }
        .container { max-width: 800px; margin: 0 auto; }
        .header { background: #f8f9fa; padding: 20px; border-radius: 5px; margin-bottom: 20px; }
        .section { background: white; padding: 20px; border: 1px solid #ddd; border-radius: 5px; margin-bottom: 20px; }
        .btn { padding: 8px 15px; margin: 5px; text-decoration: none; border-radius: 4px; display: inline-block; }
        .btn-primary { background: #007bff; color: white; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-success { background: #28a745; color: white; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 10px; border-bottom: 1px solid #ddd; text-align: left; }
        .alert { padding: 10px; margin: 10px 0; border-radius: 4px; }
        .alert-success { background: #d4edda; color: #155724; }
        .alert-error { background: #f8d7da; color: #721c24; }
    </style>
</head>
<body>
    <div class="container">
        <!-- Заголовок -->
        <div class="header">
            <h1>Применение модели: <?php echo htmlspecialchars($model['name']); ?></h1>
            <p><?php echo htmlspecialchars($model['description']); ?></p>
            <a href="ml_models.php" class="btn btn-primary">← Назад к моделям</a>
        </div>

        <!-- Сообщения -->
        <?php if (isset($success)): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        <?php if (isset($error)): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>

        <!-- Применить к новому тесту -->
        <div class="section">
            <h3>Применить к тесту</h3>
            <form method="POST">
                <select name="test_id" required style="padding: 8px; min-width: 300px;">
                    <option value="">-- Выберите тест --</option>
                    <?php foreach ($tests as $test): ?>
                        <?php 
                        $is_assigned = false;
                        foreach ($assigned_tests as $assigned) {
                            if ($assigned['id'] == $test['id']) {
                                $is_assigned = true;
                                break;
                            }
                        }
                        ?>
                        <?php if (!$is_assigned): ?>
                            <option value="<?php echo $test['id']; ?>">
                                <?php echo htmlspecialchars($test['title']); ?>
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
                <button type="submit" name="assign_test" class="btn btn-success">Применить</button>
            </form>
        </div>

        <!-- Уже примененные тесты -->
        <div class="section">
            <h3>Примененные тесты (<?php echo count($assigned_tests); ?>)</h3>
            
            <?php if (!empty($assigned_tests)): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Название теста</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assigned_tests as $test): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($test['title']); ?></td>
                            <td>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="test_id" value="<?php echo $test['id']; ?>">
                                    <button type="submit" name="remove_assignment" class="btn btn-danger" 
                                            onclick="return confirm('Удалить связь?')">
                                        Удалить
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>Модель еще не применена ни к одному тесту</p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>