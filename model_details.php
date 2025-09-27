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

// Проверяем наличие ID модели
if (!isset($_GET['id'])) {
    header("Location: ml_models.php");
    exit;
}

$model_id = $_GET['id'];

// Получаем информацию о модели
$stmt = $pdo->prepare("SELECT * FROM ml_models WHERE id = ?");
$stmt->execute([$model_id]);
$model = $stmt->fetch();

if (!$model) {
    header("Location: ml_models.php?error=model_not_found");
    exit;
}

// Получаем историю использования модели - ИСПРАВЛЕННЫЙ ЗАПРОС
try {
    $stmt = $pdo->prepare("
        SELECT 
            t.title as test_name, 
            COUNT(r.id) as usage_count, 
            AVG(r.score) as avg_score, 
            MAX(r.completed_at) as last_used
        FROM test_results r
        JOIN tests t ON r.test_id = t.id
        WHERE r.ml_model_used = ?
        GROUP BY t.id
        ORDER BY last_used DESC
    ");
    $stmt->execute([$model_id]);
    $usage_stats = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching usage stats: " . $e->getMessage());
    $usage_stats = [];
}

// Получаем все тесты для применения модели
$tests = [];
try {
    $stmt = $pdo->prepare("SELECT id, title FROM tests WHERE is_active = 1 ORDER BY title");
    $stmt->execute();
    $tests = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching tests: " . $e->getMessage());
}

// Получаем тесты, к которым уже применена модель
$assigned_tests = [];
try {
    $stmt = $pdo->prepare("
        SELECT t.id, t.title 
        FROM model_test_assignments mta
        JOIN tests t ON mta.test_id = t.id
        WHERE mta.model_id = ?
    ");
    $stmt->execute([$model_id]);
    $assigned_tests = $stmt->fetchAll();
} catch (PDOException $e) {
    // Таблица может не существовать, игнорируем ошибку
    $assigned_tests = [];
}

// Получаем историю изменений модели с группировкой
$model_history = [];
$grouped_history = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            mh.*, 
            u.full_name as changed_by_name,
            DATE(mh.changed_at) as change_date
        FROM model_history mh
        LEFT JOIN users u ON mh.changed_by = u.id
        WHERE mh.model_id = ?
        ORDER BY mh.changed_at DESC
        LIMIT 50
    ");
    $stmt->execute([$model_id]);
    $raw_history = $stmt->fetchAll();
    
    // Группируем изменения по дате и типу
    $grouped_history = [];
    foreach ($raw_history as $history) {
        $date = date('Y-m-d', strtotime($history['changed_at']));
        $key = $date . '_' . $history['change_type'] . '_' . $history['changed_by'];
        
        if (!isset($grouped_history[$key])) {
            $grouped_history[$key] = [
                'changes' => [],
                'count' => 0,
                'first_change' => $history,
                'group_id' => count($grouped_history) + 1
            ];
        }
        
        $grouped_history[$key]['changes'][] = $history;
        $grouped_history[$key]['count']++;
    }
    
    // Преобразуем в плоский список с группировкой
    foreach ($grouped_history as $group) {
        if ($group['count'] == 1) {
            $model_history[] = $group['changes'][0];
        } else {
            // Создаем групповую запись
            $first = $group['first_change'];
            $group_record = $first;
            $group_record['is_group'] = true;
            $group_record['group_id'] = $group['group_id'];
            $group_record['group_count'] = $group['count'];
            $group_record['group_changes'] = $group['changes'];
            $group_record['description'] = "{$first['description']}";
            $model_history[] = $group_record;
        }
    }
    
    // Сортируем по времени
    usort($model_history, function($a, $b) {
        return strtotime($b['changed_at']) - strtotime($a['changed_at']);
    });
    
} catch (PDOException $e) {
    $model_history = [];
}

// Обработка POST запросов
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // УДАЛЕНИЕ МОДЕЛИ
    if (isset($_POST['delete_model']) && $user['role'] == 'admin') {
        try {
            // Логируем удаление
            $stmt = $pdo->prepare("INSERT INTO model_history (model_id, changed_by, change_type, description) VALUES (?, ?, 'DELETE', ?)");
            $stmt->execute([$model_id, $user['id'], "Модель '{$model['name']}' удалена"]);
            
            // Логируем в activity_logs
            $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, description) VALUES (?, ?, ?)");
            $stmt->execute([$user['id'], 'MODEL_DELETED', "Модель '{$model['name']}' удалена"]);
            
            // Удаляем связи с тестами
            $stmt = $pdo->prepare("DELETE FROM model_test_assignments WHERE model_id = ?");
            $stmt->execute([$model_id]);
            
            // Удаляем историю модели
            $stmt = $pdo->prepare("DELETE FROM model_history WHERE model_id = ?");
            $stmt->execute([$model_id]);
            
            // Удаляем саму модель
            $stmt = $pdo->prepare("DELETE FROM ml_models WHERE id = ?");
            $stmt->execute([$model_id]);
            
            header("Location: ml_models.php?success=model_deleted");
            exit;
            
        } catch (PDOException $e) {
            error_log("Error deleting model: " . $e->getMessage());
            header("Location: model_details.php?id=$model_id&error=delete_failed");
            exit;
        }
    }
    
    // Изменение статуса модели
    if (isset($_POST['toggle_status']) && ($user['role'] == 'admin' || $user['role'] == 'teacher')) {
        $new_status = $model['is_active'] ? 0 : 1;
        $status_text = $new_status ? 'активна' : 'неактивна';
        
        $stmt = $pdo->prepare("UPDATE ml_models SET is_active = ? WHERE id = ?");
        if ($stmt->execute([$new_status, $model_id])) {
            // Логируем изменение статуса
            try {
                $stmt = $pdo->prepare("INSERT INTO model_history (model_id, changed_by, change_type, old_value, new_value, description) VALUES (?, ?, 'STATUS_CHANGE', ?, ?, ?)");
                $stmt->execute([
                    $model_id, 
                    $user['id'], 
                    $model['is_active'] ? 'активна' : 'неактивна', 
                    $status_text,
                    "Статус модели изменен на '$status_text'"
                ]);
            } catch (PDOException $e) {
                error_log("Error logging status change: " . $e->getMessage());
            }
            
            // Логируем в activity_logs
            try {
                $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, description) VALUES (?, ?, ?)");
                $action = $new_status ? 'MODEL_ACTIVATED' : 'MODEL_DEACTIVATED';
                $stmt->execute([$user['id'], $action, "Модель '{$model['name']}' теперь $status_text"]);
            } catch (PDOException $e) {
                error_log("Error logging activity: " . $e->getMessage());
            }
        }
        
        header("Location: model_details.php?id=$model_id&success=status_updated");
        exit;
    }
    
    // Применение модели к тесту
    if (isset($_POST['assign_test']) && ($user['role'] == 'admin' || $user['role'] == 'teacher')) {
        $test_id = $_POST['test_id'];
        
        try {
            $stmt = $pdo->prepare("INSERT INTO model_test_assignments (model_id, test_id, assigned_by) VALUES (?, ?, ?)");
            $stmt->execute([$model_id, $test_id, $user['id']]);
            
            // Логируем применение модели
            try {
                $stmt = $pdo->prepare("SELECT title FROM tests WHERE id = ?");
                $stmt->execute([$test_id]);
                $test = $stmt->fetch();
                
                $stmt = $pdo->prepare("INSERT INTO model_history (model_id, changed_by, change_type, new_value, description) VALUES (?, ?, 'ASSIGNMENT', ?, ?)");
                $stmt->execute([
                    $model_id, 
                    $user['id'], 
                    $test['title'],
                    "Модель применена к тесту '{$test['title']}'"
                ]);
            } catch (PDOException $e) {
                error_log("Error logging assignment: " . $e->getMessage());
            }
            
        } catch (PDOException $e) {
            // Возможно, связь уже существует
        }
        
        header("Location: model_details.php?id=$model_id&success=test_assigned");
        exit;
    }
    
    // Удаление связи с тестом
    if (isset($_POST['remove_assignment']) && ($user['role'] == 'admin' || $user['role'] == 'teacher')) {
        $test_id = $_POST['test_id'];
        
        try {
            $stmt = $pdo->prepare("DELETE FROM model_test_assignments WHERE model_id = ? AND test_id = ?");
            $stmt->execute([$model_id, $test_id]);
            
            // Логируем удаление связи
            try {
                $stmt = $pdo->prepare("SELECT title FROM tests WHERE id = ?");
                $stmt->execute([$test_id]);
                $test = $stmt->fetch();
                
                $stmt = $pdo->prepare("INSERT INTO model_history (model_id, changed_by, change_type, old_value, description) VALUES (?, ?, 'ASSIGNMENT', ?, ?)");
                $stmt->execute([
                    $model_id, 
                    $user['id'], 
                    $test['title'],
                    "Модель откреплена от теста '{$test['title']}'"
                ]);
            } catch (PDOException $e) {
                error_log("Error logging assignment removal: " . $e->getMessage());
            }
            
        } catch (PDOException $e) {
            error_log("Error removing assignment: " . $e->getMessage());
        }
        
        header("Location: model_details.php?id=$model_id&success=assignment_removed");
        exit;
    }
    
    // Обновление информации о модели (только для администраторов)
    if (isset($_POST['update_model']) && $user['role'] == 'admin') {
        $name = $_POST['name'];
        $description = $_POST['description'];
        $accuracy = $_POST['accuracy'];
        $path = $_POST['path'];
        
        // Сохраняем старые значения для лога
        $old_values = [
            'name' => $model['name'],
            'description' => $model['description'],
            'accuracy' => $model['accuracy'],
            'path' => $model['path']
        ];
        
        $stmt = $pdo->prepare("UPDATE ml_models SET name = ?, description = ?, accuracy = ?, path = ? WHERE id = ?");
        $stmt->execute([$name, $description, $accuracy, $path, $model_id]);
        
        // Логируем изменения
        try {
            $changes = [];
            if ($old_values['name'] != $name) $changes[] = "название: '{$old_values['name']}' → '$name'";
            if ($old_values['description'] != $description) $changes[] = "описание изменено";
            if ($old_values['accuracy'] != $accuracy) $changes[] = "точность: {$old_values['accuracy']} → $accuracy";
            if ($old_values['path'] != $path) $changes[] = "путь изменен";
            
            if (!empty($changes)) {
                $stmt = $pdo->prepare("INSERT INTO model_history (model_id, changed_by, change_type, description) VALUES (?, ?, 'UPDATE', ?)");
                $stmt->execute([$model_id, $user['id'], "Обновлены параметры модели: " . implode(', ', $changes)]);
            }
        } catch (PDOException $e) {
            error_log("Error logging update: " . $e->getMessage());
        }
        
        header("Location: model_details.php?id=$model_id&success=model_updated");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($model['name']); ?> - Детали модели</title>
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
            --sidebar-width: 250px;
        }
        
        body {
            background-color: #f0f2f5;
            color: #333;
            line-height: 1.6;
            display: flex;
            min-height: 100vh;
        }
        
        .sidebar {
            width: var(--sidebar-width);
            background: linear-gradient(180deg, var(--secondary) 0%, #1a2530 100%);
            color: white;
            height: 100vh;
            position: fixed;
            overflow-y: auto;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        }
        
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            padding: 30px;
            background-color: #f8f9fa;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            background: white;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 2px 15px rgba(0,0,0,0.08);
        }
        
        .model-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 15px;
            padding: 30px;
            color: white;
            margin-bottom: 25px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            position: relative;
            overflow: hidden;
        }
        
        .model-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 200%;
            background: rgba(255,255,255,0.1);
            transform: rotate(30deg);
        }
        
        .model-title {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
            position: relative;
            z-index: 2;
        }
        
        .model-status {
            display: inline-block;
            padding: 8px 20px;
            border-radius: 25px;
            font-size: 14px;
            font-weight: 600;
            backdrop-filter: blur(10px);
            border: 2px solid rgba(255,255,255,0.3);
        }
        
        .status-active {
            background: rgba(46, 204, 113, 0.3);
            color: #fff;
        }
        
        .status-inactive {
            background: rgba(149, 165, 166, 0.3);
            color: #fff;
        }
        
        .model-meta {
            display: flex;
            gap: 30px;
            margin-top: 20px;
            flex-wrap: wrap;
            position: relative;
            z-index: 2;
        }
        
        .meta-item {
            display: flex;
            align-items: center;
            gap: 10px;
            color: rgba(255,255,255,0.9);
            font-size: 15px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 25px;
        }
        
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            text-align: center;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border-left: 5px solid var(--primary);
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        
        .stat-value {
            font-size: 42px;
            font-weight: 800;
            margin-bottom: 10px;
            color: var(--secondary);
        }
        
        .stat-label {
            color: var(--gray);
            font-size: 15px;
            font-weight: 500;
        }
        
        .section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            margin-bottom: 25px;
            border: 1px solid #e9ecef;
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f1f3f4;
        }
        
        .section-title {
            font-size: 22px;
            font-weight: 700;
            color: var(--secondary);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .btn {
            padding: 12px 25px;
            border-radius: 10px;
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
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.4);
        }
        
        .btn-success {
            background: linear-gradient(135deg, var(--success) 0%, #27ae60 100%);
            color: white;
        }
        
        .btn-warning {
            background: linear-gradient(135deg, var(--warning) 0%, #e67e22 100%);
            color: white;
        }
        
        .btn-danger {
            background: linear-gradient(135deg, var(--danger) 0%, #c0392b 100%);
            color: white;
        }
        
        .btn-sm {
            padding: 8px 15px;
            font-size: 13px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        
        table th {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            color: var(--secondary);
            font-weight: 600;
            padding: 15px;
            text-align: left;
            border-bottom: 2px solid #dee2e6;
        }
        
        table td {
            padding: 15px;
            border-bottom: 1px solid #e9ecef;
            transition: background-color 0.3s ease;
        }
        
        table tr:hover td {
            background-color: #f8f9fa;
        }
        
        .history-item.grouped {
            border-left: 4px solid #9b59b6;
            cursor: pointer;
        }

        .history-item.grouped:hover {
            background-color: #f8f9fa;
        }

        .history-item.grouped::before {
            background: #9b59b6;
            box-shadow: 0 0 0 2px #9b59b6;
        }

        .history-type.GROUP {
            background: rgba(155, 89, 182, 0.1);
            color: #9b59b6;
        }

        .group-badge {
            display: inline-block;
            background: #9b59b6;
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            margin-left: 10px;
            font-weight: bold;
        }
        
        .group-changes {
            display: none;
            margin-top: 15px;
            padding-left: 20px;
            border-left: 2px solid #e9ecef;
        }
        
        .group-change-item {
            padding: 10px;
            margin-bottom: 10px;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 3px solid #9b59b6;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 5px solid;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-success {
            background: rgba(46, 204, 113, 0.1);
            color: var(--success);
            border-left-color: var(--success);
        }
        
        .alert-danger {
            background: rgba(231, 76, 60, 0.1);
            color: var(--danger);
            border-left-color: var(--danger);
        }
        
        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            margin-bottom: 15px;
            transition: border-color 0.3s ease;
            font-size: 15px;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--secondary);
        }
        
        .tag {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #e3f2fd;
            color: #1976d2;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 500;
            margin: 5px;
        }
        
        .tag-remove {
            background: none;
            border: none;
            color: #1976d2;
            cursor: pointer;
            padding: 2px;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .tag-remove:hover {
            background: rgba(25, 118, 210, 0.1);
        }
        
        /* Улучшенный дизайн для истории изменений */
        .history-timeline {
            position: relative;
            padding-left: 30px;
        }
        
        .history-timeline::before {
            content: '';
            position: absolute;
            left: 15px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #e9ecef;
        }
        
        .history-item {
            position: relative;
            margin-bottom: 20px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        
        .history-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .history-item::before {
            content: '';
            position: absolute;
            left: -35px;
            top: 25px;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: var(--primary);
            border: 3px solid white;
            box-shadow: 0 0 0 2px var(--primary);
        }
        
        .history-item.STATUS_CHANGE::before { background: var(--warning); box-shadow: 0 0 0 2px var(--warning); }
        .history-item.ASSIGNMENT::before { background: var(--success); box-shadow: 0 0 0 2px var(--success); }
        .history-item.UPDATE::before { background: var(--primary); box-shadow: 0 0 0 2px var(--primary); }
        .history-item.DELETE::before { background: var(--danger); box-shadow: 0 0 0 2px var(--danger); }
        
        .history-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .history-user {
            font-weight: 600;
            color: var(--secondary);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .history-time {
            color: var(--gray);
            font-size: 13px;
        }
        
        .history-type {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 8px;
        }
        
        .history-type.STATUS_CHANGE { background: rgba(243, 156, 18, 0.1); color: var(--warning); }
        .history-type.ASSIGNMENT { background: rgba(46, 204, 113, 0.1); color: var(--success); }
        .history-type.UPDATE { background: rgba(52, 152, 219, 0.1); color: var(--primary); }
        .history-type.DELETE { background: rgba(231, 76, 60, 0.1); color: var(--danger); }
        
        .history-description {
            color: var(--secondary);
            line-height: 1.5;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--gray);
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        
        .empty-state h3 {
            margin-bottom: 10px;
            color: var(--gray);
        }
        
        .danger-zone {
            border: 2px solid var(--danger);
            background: rgba(231, 76, 60, 0.05);
        }
        
        .danger-zone .section-header {
            border-bottom-color: var(--danger);
        }

        .collapsed {
            display: none;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                width: 70px;
            }
            
            .main-content {
                margin-left: 70px;
                padding: 15px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .model-meta {
                flex-direction: column;
                gap: 15px;
            }
            
            .history-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 5px;
            }
            
            .history-timeline {
                padding-left: 20px;
            }
            
            .history-item::before {
                left: -25px;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="logo" style="padding: 20px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1);">
            <h1 style="font-size: 18px; color: #3498db;"><i class="fas fa-brain"></i> AI Оценка</h1>
        </div>
        
        <div class="user-info" style="padding: 20px; border-bottom: 1px solid rgba(255,255,255,0.1);">
            <div style="display: flex; align-items: center; gap: 15px;">
                <div class="user-avatar" style="width: 50px; height: 50px; border-radius: 50%; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 20px;">
                    <?php 
                    $firstName = $user['full_name'];
                    if (function_exists('mb_convert_encoding')) {
                        $firstName = mb_convert_encoding($firstName, 'UTF-8', 'auto');
                    }
                    $firstLetter = mb_substr($firstName, 0, 1, 'UTF-8');
                    echo htmlspecialchars(strtoupper($firstLetter), ENT_QUOTES, 'UTF-8');
                    ?>
                </div>
                <div>
                    <h3 style="font-size: 16px; margin-bottom: 5px;">
                        <?php 
                        $nameParts = explode(' ', $user['full_name']);
                        $firstName = $nameParts[1] ?? $nameParts[0];
                        echo htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8'); 
                        ?>
                    </h3>
                    <span style="font-size: 12px; opacity: 0.8;">
                        <?php 
                        if ($user['role'] == 'admin') echo 'Администратор';
                        else if ($user['role'] == 'teacher') echo 'Преподаватель';
                        else echo 'Студент';
                        ?>
                    </span>
                </div>
            </div>
        </div>
        
        <ul class="nav-links" style="list-style: none; padding: 20px 0;">
            <li><a href="dashboard.php" style="display: flex; align-items: center; gap: 15px; padding: 15px 20px; color: rgba(255,255,255,0.8); text-decoration: none; transition: all 0.3s ease;"><i class="fas fa-th-large"></i> <span>Главная</span></a></li>
            <li><a href="tests.php" style="display: flex; align-items: center; gap: 15px; padding: 15px 20px; color: rgba(255,255,255,0.8); text-decoration: none; transition: all 0.3s ease;"><i class="fas fa-file-alt"></i> <span>Тесты</span></a></li>
            <li><a href="results.php" style="display: flex; align-items: center; gap: 15px; padding: 15px 20px; color: rgba(255,255,255,0.8); text-decoration: none; transition: all 0.3s ease;"><i class="fas fa-chart-line"></i> <span>Результаты</span></a></li>
            <?php if ($user['role'] == 'teacher' || $user['role'] == 'admin'): ?>
            <li><a href="students.php" style="display: flex; align-items: center; gap: 15px; padding: 15px 20px; color: rgba(255,255,255,0.8); text-decoration: none; transition: all 0.3s ease;"><i class="fas fa-users"></i> <span>Студенты</span></a></li>
            <?php endif; ?>
            <li><a href="ml_models.php" style="display: flex; align-items: center; gap: 15px; padding: 15px 20px; background: rgba(52, 152, 219, 0.2); color: white; text-decoration: none; border-left: 4px solid #3498db;"><i class="fas fa-robot"></i> <span>ML модели</span></a></li>
            <li><a href="settings.php" style="display: flex; align-items: center; gap: 15px; padding: 15px 20px; color: rgba(255,255,255,0.8); text-decoration: none; transition: all 0.3s ease;"><i class="fas fa-cog"></i> <span>Настройки</span></a></li>
            <li><a href="logout.php" style="display: flex; align-items: center; gap: 15px; padding: 15px 20px; color: rgba(255,255,255,0.8); text-decoration: none; transition: all 0.3s ease;"><i class="fas fa-sign-out-alt"></i> <span>Выход</span></a></li>
        </ul>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
        <div class="header">
            <div class="welcome">
                <h2 style="color: var(--secondary); margin-bottom: 5px;"><i class="fas fa-robot" style="color: var(--primary);"></i> Детали модели</h2>
                <p style="color: var(--gray);">Подробная информация о машинной модели</p>
            </div>
            <div class="actions">
                <a href="ml_models.php" class="btn btn-primary"><i class="fas fa-arrow-left"></i> Назад к моделям</a>
            </div>
        </div>

        <!-- Сообщения об ошибках/успехе -->
        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php
                $messages = [
                    'status_updated' => 'Статус модели успешно обновлен',
                    'test_assigned' => 'Модель успешно применена к тесту',
                    'assignment_removed' => 'Связь с тестом удалена',
                    'model_updated' => 'Информация о модели обновлена',
                    'model_deleted' => 'Модель успешно удалена'
                ];
                echo $messages[$_GET['success']] ?? 'Операция выполнена успешно';
                ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <?php
                $messages = [
                    'delete_failed' => 'Ошибка при удалении модели',
                    'model_not_found' => 'Модель не найдена'
                ];
                echo $messages[$_GET['error']] ?? 'Произошла ошибка';
                ?>
            </div>
        <?php endif; ?>

        <!-- Заголовок модели -->
        <div class="model-header">
            <h1 class="model-title">
                <i class="fas fa-robot"></i> <?php echo htmlspecialchars($model['name']); ?>
                <span class="model-status <?php echo $model['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                    <?php echo $model['is_active'] ? 'Активна' : 'Неактивна'; ?>
                </span>
            </h1>
            <p style="font-size: 16px; opacity: 0.9; margin-bottom: 10px; position: relative; z-index: 2;">
                <?php echo htmlspecialchars($model['description']); ?>
            </p>
            <div class="model-meta">
                <div class="meta-item">
                    <i class="fas fa-bullseye"></i>
                    <span>Точность: <strong><?php echo htmlspecialchars($model['accuracy']); ?>%</strong></span>
                </div>
                <div class="meta-item">
                    <i class="fas fa-calendar-alt"></i>
                    <span>Создана: <strong><?php echo date('d.m.Y', strtotime($model['created_at'])); ?></strong></span>
                </div>
                <div class="meta-item">
                    <i class="fas fa-folder-open"></i>
                    <span>Путь: <strong><?php echo htmlspecialchars($model['path']); ?></strong></span>
                </div>
            </div>
        </div>

        <!-- Статистика использования -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo count($usage_stats); ?></div>
                <div class="stat-label">Тестов с моделью</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">
                    <?php 
                    $total_usage = array_sum(array_column($usage_stats, 'usage_count'));
                    echo $total_usage > 0 ? $total_usage : '0';
                    ?>
                </div>
                <div class="stat-label">Всего использований</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">
                    <?php
                    if ($total_usage > 0) {
                        $avg_score = array_sum(array_column($usage_stats, 'avg_score')) / count($usage_stats);
                        echo round($avg_score, 1);
                    } else {
                        echo '0';
                    }
                    ?>
                </div>
                <div class="stat-label">Средняя оценка</div>
            </div>
        </div>

        <!-- Действия с моделью -->
        <div class="section">
            <div class="section-header">
                <h3 class="section-title"><i class="fas fa-cogs"></i> Управление моделью</h3>
            </div>
            
            <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                <!-- Кнопка изменения статуса -->
                <form method="POST" style="display: inline;">
                    <button type="submit" name="toggle_status" class="btn <?php echo $model['is_active'] ? 'btn-warning' : 'btn-success'; ?>">
                        <i class="fas fa-power-off"></i> 
                        <?php echo $model['is_active'] ? 'Деактивировать' : 'Активировать'; ?>
                    </button>
                </form>

                <!-- Кнопка редактирования (только для админов) -->
                <?php if ($user['role'] == 'admin'): ?>
                <button type="button" onclick="toggleEditForm()" class="btn btn-primary">
                    <i class="fas fa-edit"></i> Редактировать
                </button>
                <?php endif; ?>

                <!-- Кнопка применения к тесту -->
                <?php if ($user['role'] == 'admin' || $user['role'] == 'teacher'): ?>
                <button type="button" onclick="toggleAssignForm()" class="btn btn-success">
                    <i class="fas fa-link"></i> Применить к тесту
                </button>
                <?php endif; ?>
            </div>

            <!-- Форма редактирования -->
            <?php if ($user['role'] == 'admin'): ?>
            <div id="edit-form" style="display: none; margin-top: 25px; padding-top: 25px; border-top: 1px solid #e9ecef;">
                <form method="POST">
                    <div class="form-group">
                        <label class="form-label">Название модели</label>
                        <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($model['name']); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Описание</label>
                        <textarea name="description" class="form-control" rows="3"><?php echo htmlspecialchars($model['description']); ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Точность (%)</label>
                        <input type="number" name="accuracy" class="form-control" min="0" max="100" step="0.1" value="<?php echo htmlspecialchars($model['accuracy']); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Путь к модели</label>
                        <input type="text" name="path" class="form-control" value="<?php echo htmlspecialchars($model['path']); ?>" required>
                    </div>
                    
                    <div style="display: flex; gap: 15px;">
                        <button type="submit" name="update_model" class="btn btn-success">
                            <i class="fas fa-save"></i> Сохранить изменения
                        </button>
                        <button type="button" onclick="toggleEditForm()" class="btn btn-warning">
                            <i class="fas fa-times"></i> Отмена
                        </button>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <!-- Форма применения к тесту -->
            <?php if ($user['role'] == 'admin' || $user['role'] == 'teacher'): ?>
            <div id="assign-form" style="display: none; margin-top: 25px; padding-top: 25px; border-top: 1px solid #e9ecef;">
                <form method="POST">
                    <div class="form-group">
                        <label class="form-label">Выберите тест</label>
                        <select name="test_id" class="form-control" required>
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
                                <option value="<?php echo $test['id']; ?>"><?php echo htmlspecialchars($test['title']); ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div style="display: flex; gap: 15px;">
                        <button type="submit" name="assign_test" class="btn btn-success">
                            <i class="fas fa-link"></i> Применить модель
                        </button>
                        <button type="button" onclick="toggleAssignForm()" class="btn btn-warning">
                            <i class="fas fa-times"></i> Отмена
                        </button>
                    </div>
                </form>
            </div>
            <?php endif; ?>
        </div>

        <!-- Примененные тесты -->
        <div class="section">
            <div class="section-header">
                <h3 class="section-title"><i class="fas fa-link"></i> Примененные тесты</h3>
                <span class="badge" style="background: var(--primary); color: white; padding: 5px 10px; border-radius: 10px;">
                    <?php echo count($assigned_tests); ?>
                </span>
            </div>

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
                                <?php if ($user['role'] == 'admin' || $user['role'] == 'teacher'): ?>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="test_id" value="<?php echo $test['id']; ?>">
                                    <button type="submit" name="remove_assignment" class="btn btn-danger btn-sm" onclick="return confirm('Удалить связь с тестом?')">
                                        <i class="fas fa-unlink"></i> Удалить
                                    </button>
                                </form>
                                <?php endif; ?>
                                <a href="test_details.php?id=<?php echo $test['id']; ?>" class="btn btn-primary btn-sm">
                                    <i class="fas fa-eye"></i> Просмотр
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-unlink"></i>
                    <h3>Нет примененных тестов</h3>
                    <p>Эта модель еще не применена ни к одному тесту</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Статистика использования -->
        <div class="section">
            <div class="section-header">
                <h3 class="section-title"><i class="fas fa-chart-bar"></i> Статистика использования</h3>
            </div>

            <?php if (!empty($usage_stats)): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Тест</th>
                            <th>Использований</th>
                            <th>Средняя оценка</th>
                            <th>Последнее использование</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($usage_stats as $stat): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($stat['test_name']); ?></td>
                            <td><?php echo $stat['usage_count']; ?></td>
                            <td><?php echo round($stat['avg_score'], 1); ?></td>
                            <td><?php echo $stat['last_used'] ? date('d.m.Y H:i', strtotime($stat['last_used'])) : 'Никогда'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-chart-bar"></i>
                    <h3>Нет данных об использовании</h3>
                    <p>Эта модель еще не использовалась для оценки тестов</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- История изменений -->
        <div class="section">
            <div class="section-header">
                <h3 class="section-title"><i class="fas fa-history"></i> История изменений</h3>
            </div>

            <?php if (!empty($model_history)): ?>
                <div class="history-timeline">
                    <?php foreach ($model_history as $history): ?>
                        <?php if (isset($history['is_group']) && $history['is_group']): ?>
                            <!-- Групповая запись -->
                            <div class="history-item grouped" onclick="toggleGroup(<?php echo $history['group_id']; ?>)">
                                <div class="history-header">
                                    <div class="history-user">
                                        <i class="fas fa-user-circle"></i>
                                        <?php echo htmlspecialchars($history['changed_by_name'] ?: 'Система'); ?>
                                    </div>
                                    <div class="history-time">
                                        <?php echo date('d.m.Y H:i', strtotime($history['changed_at'])); ?>
                                    </div>
                                </div>
                                <span class="history-type GROUP">ГРУППА ИЗМЕНЕНИЙ</span>
                                <span class="group-badge"><?php echo $history['group_count']; ?> изменений</span>
                                <div class="history-description">
                                    <?php echo htmlspecialchars($history['description']); ?>
                                </div>
                                
                                <div id="group-<?php echo $history['group_id']; ?>" class="group-changes">
                                    <?php foreach ($history['group_changes'] as $change): ?>
                                        <div class="group-change-item">
                                            <div class="history-header">
                                                <div class="history-time">
                                                    <?php echo date('H:i', strtotime($change['changed_at'])); ?>
                                                </div>
                                            </div>
                                            <span class="history-type <?php echo $change['change_type']; ?>">
                                                <?php echo $change['change_type']; ?>
                                            </span>
                                            <div class="history-description">
                                                <?php echo htmlspecialchars($change['description']); ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <!-- Одиночная запись -->
                            <div class="history-item <?php echo $history['change_type']; ?>">
                                <div class="history-header">
                                    <div class="history-user">
                                        <i class="fas fa-user-circle"></i>
                                        <?php echo htmlspecialchars($history['changed_by_name'] ?: 'Система'); ?>
                                    </div>
                                    <div class="history-time">
                                        <?php echo date('d.m.Y H:i', strtotime($history['changed_at'])); ?>
                                    </div>
                                </div>
                                <span class="history-type <?php echo $history['change_type']; ?>">
                                    <?php echo $history['change_type']; ?>
                                </span>
                                <div class="history-description">
                                    <?php echo htmlspecialchars($history['description']); ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-history"></i>
                    <h3>История изменений пуста</h3>
                    <p>По этой модели еще не было изменений</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Опасная зона (только для админов) -->
        <?php if ($user['role'] == 'admin'): ?>
        <div class="section danger-zone">
            <div class="section-header">
                <h3 class="section-title" style="color: var(--danger);"><i class="fas fa-exclamation-triangle"></i> Опасная зона</h3>
            </div>
            
            <p style="margin-bottom: 20px; color: var(--danger);">
                <i class="fas fa-info-circle"></i> Удаление модели нельзя отменить. Все данные, связанные с моделью, будут безвозвратно удалены.
            </p>
            
            <form method="POST" onsubmit="return confirm('Вы уверены, что хотите удалить эту модель? Это действие нельзя отменить.');">
                <button type="submit" name="delete_model" class="btn btn-danger">
                    <i class="fas fa-trash-alt"></i> Удалить модель
                </button>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <script>
        function toggleEditForm() {
            const form = document.getElementById('edit-form');
            form.style.display = form.style.display === 'none' ? 'block' : 'none';
        }

        function toggleAssignForm() {
            const form = document.getElementById('assign-form');
            form.style.display = form.style.display === 'none' ? 'block' : 'none';
        }

        function toggleGroup(groupId) {
            const group = document.getElementById('group-' + groupId);
            group.style.display = group.style.display === 'none' ? 'block' : 'none';
        }
    </script>
</body>
</html>