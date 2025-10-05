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

// Обработка поиска и фильтров
$search = $_GET['search'] ?? '';
$subject = $_GET['subject'] ?? '';
$status = $_GET['status'] ?? '';
$sort = $_GET['sort'] ?? 'newest';

// Базовый запрос для тестов
if ($user['role'] == 'student') {
    // Для студентов показываем доступные тесты и пройденные
    $query = "
        SELECT 
            t.*, 
            u.full_name as creator_name,
            (SELECT COUNT(*) FROM test_results WHERE test_id = t.id AND user_id = ?) as attempts,
            (SELECT MAX(percentage) FROM test_results WHERE test_id = t.id AND user_id = ?) as best_score,
            (SELECT passed FROM test_results WHERE test_id = t.id AND user_id = ? ORDER BY completed_at DESC LIMIT 1) as last_status,
            (SELECT COUNT(*) FROM test_results WHERE test_id = t.id) as total_attempts,
            (SELECT AVG(percentage) FROM test_results WHERE test_id = t.id) as avg_success
        FROM tests t
        JOIN users u ON t.created_by = u.id
        WHERE t.is_active = 1
    ";
    $params = [$_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']];
} else {
    // Для преподавателей и администраторов показываем все их тесты
    $query = "
        SELECT 
            t.*, 
            u.full_name as creator_name,
            COUNT(tr.id) as total_attempts,
            AVG(tr.percentage) as avg_success,
            COUNT(DISTINCT tr.user_id) as unique_students,
            MAX(tr.completed_at) as last_activity
        FROM tests t
        JOIN users u ON t.created_by = u.id
        LEFT JOIN test_results tr ON t.id = tr.test_id
        WHERE t.created_by = ?
        GROUP BY t.id
    ";
    $params = [$_SESSION['user_id']];
}

// Добавляем условия поиска и фильтров
if (!empty($search)) {
    $query .= " AND (t.title LIKE ? OR t.description LIKE ? OR t.subject LIKE ?)";
    $search_term = "%$search%";
    array_push($params, $search_term, $search_term, $search_term);
}

if (!empty($subject)) {
    $query .= " AND t.subject = ?";
    $params[] = $subject;
}

// Фильтр по статусу для студентов
if ($user['role'] == 'student' && !empty($status)) {
    if ($status == 'available') {
        $query .= " AND (SELECT COUNT(*) FROM test_results WHERE test_id = t.id AND user_id = ?) = 0";
        $params[] = $_SESSION['user_id'];
    } elseif ($status == 'passed') {
        $query .= " AND (SELECT passed FROM test_results WHERE test_id = t.id AND user_id = ? ORDER BY completed_at DESC LIMIT 1) = 1";
        $params[] = $_SESSION['user_id'];
    } elseif ($status == 'failed') {
        $query .= " AND (SELECT passed FROM test_results WHERE test_id = t.id AND user_id = ? ORDER BY completed_at DESC LIMIT 1) = 0";
        $params[] = $_SESSION['user_id'];
    }
}

// Сортировка
switch ($sort) {
    case 'title':
        $query .= " ORDER BY t.title ASC";
        break;
    case 'subject':
        $query .= " ORDER BY t.subject ASC, t.title ASC";
        break;
    case 'popular':
        $query .= " ORDER BY total_attempts DESC";
        break;
    case 'success':
        $query .= " ORDER BY avg_success DESC";
        break;
    case 'newest':
    default:
        $query .= " ORDER BY t.created_at DESC";
        break;
}

// Выполняем запрос
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$tests = $stmt->fetchAll();

// Получаем список предметов для фильтра
$stmt = $pdo->prepare("SELECT DISTINCT subject FROM tests WHERE is_active = 1 ORDER BY subject");
$stmt->execute();
$subjects = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Получаем статистику для преподавателей
if ($user['role'] == 'teacher' || $user['role'] == 'admin') {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_tests,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_tests,
            COUNT(DISTINCT subject) as total_subjects,
            (SELECT COUNT(*) FROM test_results WHERE test_id IN (SELECT id FROM tests WHERE created_by = ?)) as total_attempts
        FROM tests 
        WHERE created_by = ?
    ");
    $stmt->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
    $teacher_stats = $stmt->fetch();
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Тесты - Система интеллектуальной оценки знаний</title>
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
            --info: #17a2b8;
        }
        
        body {
            background-color: #f0f2f5;
            color: #333;
            line-height: 1.6;
        }

        .container {
            max-width: 1400px;
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

        /* Stats Overview for Teachers */
        <?php if ($user['role'] == 'teacher' || $user['role'] == 'admin'): ?>
        .stats-overview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
            text-align: center;
            border-left: 4px solid var(--primary);
        }
        
        .stat-card h3 {
            font-size: 28px;
            margin-bottom: 5px;
            color: var(--secondary);
            font-weight: 700;
        }
        
        .stat-card p {
            color: var(--gray);
            font-size: 14px;
        }
        
        .stat-card.success {
            border-left-color: var(--success);
        }
        
        .stat-card.warning {
            border-left-color: var(--warning);
        }
        
        .stat-card.info {
            border-left-color: var(--info);
        }
        <?php endif; ?>

        /* Filters and Search */
        .filters-section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            margin-bottom: 25px;
        }
        
        .filters-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .filters-title {
            font-size: 18px;
            color: var(--secondary);
            font-weight: 600;
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr auto;
            gap: 15px;
            align-items: end;
        }
        
        @media (max-width: 768px) {
            .filters-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .filter-label {
            font-size: 14px;
            font-weight: 600;
            color: var(--secondary);
        }
        
        .filter-input, .filter-select {
            padding: 12px 15px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            background: white;
            width: 100%;
        }
        
        .filter-input:focus, .filter-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }
        
        .filter-actions {
            display: flex;
            gap: 10px;
        }

        /* Tests Grid */
        .tests-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .test-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            border-left: 4px solid var(--primary);
            position: relative;
            overflow: hidden;
        }
        
        .test-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            transform: scaleX(0);
            transition: transform 0.3s;
        }
        
        .test-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.12);
        }
        
        .test-card:hover::before {
            transform: scaleX(1);
        }
        
        .test-card.available {
            border-left-color: var(--success);
        }
        
        .test-card.completed {
            border-left-color: var(--info);
        }
        
        .test-card.failed {
            border-left-color: var(--warning);
        }
        
        .test-header {
            display: flex;
            justify-content: between;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        
        .test-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--secondary);
            margin-bottom: 8px;
            line-height: 1.3;
        }
        
        .test-subject {
            display: inline-block;
            background: rgba(52, 152, 219, 0.1);
            color: var(--primary);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 10px;
        }
        
        .test-description {
            color: var(--gray);
            font-size: 14px;
            margin-bottom: 15px;
            line-height: 1.5;
        }
        
        .test-meta {
            display: flex;
            justify-content: space-between;
            font-size: 13px;
            color: var(--gray);
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .test-meta-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .test-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .stat-item {
            text-align: center;
            padding: 10px;
            background: var(--light);
            border-radius: 8px;
        }
        
        .stat-value {
            font-size: 18px;
            font-weight: 700;
            color: var(--secondary);
        }
        
        .stat-label {
            font-size: 12px;
            color: var(--gray);
        }
        
        .test-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn {
            padding: 10px 20px;
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
            justify-content: center;
            flex: 1;
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
        
        .btn-warning {
            background: var(--warning);
            color: white;
        }
        
        .btn-warning:hover {
            background: #e67e22;
            transform: translateY(-2px);
        }
        
        .btn-danger {
            background: var(--danger);
            color: white;
        }
        
        .btn-danger:hover {
            background: #c0392b;
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

        .btn-sm {
            padding: 8px 15px;
            font-size: 13px;
        }

        /* Status Badges */
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-available {
            background: rgba(46, 204, 113, 0.1);
            color: var(--success);
        }
        
        .status-completed {
            background: rgba(52, 152, 219, 0.1);
            color: var(--primary);
        }
        
        .status-failed {
            background: rgba(243, 156, 18, 0.1);
            color: var(--warning);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--gray);
            width: 100%;
        }
        
        .empty-state i {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
            display: block;
        }
        
        .empty-state h3 {
            font-size: 24px;
            margin-bottom: 10px;
            color: var(--secondary);
        }
        
        .empty-state p {
            font-size: 16px;
            margin-bottom: 25px;
        }

        /* Create Test Button for Teachers */
        .create-test-section {
            text-align: center;
            padding: 40px 20px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            margin-bottom: 30px;
        }
        
        .create-test-btn {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            padding: 15px 30px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            text-decoration: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .create-test-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(52, 152, 219, 0.3);
        }

        /* Results Info */
        .results-info {
            background: var(--light);
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 15px;
        }
        
        .score-display {
            font-size: 24px;
            font-weight: 700;
            text-align: center;
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

        /* Модальное окно результатов */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 1000;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .modal-overlay.active {
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 1;
        }

        .modal-content {
            background: white;
            border-radius: 15px;
            width: 90%;
            max-width: 900px;
            max-height: 90vh;
            overflow: hidden;
            transform: scale(0.9);
            transition: transform 0.3s ease;
        }

        .modal-overlay.active .modal-content {
            transform: scale(1);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 25px;
            border-bottom: 1px solid #e0e0e0;
            background: var(--light);
        }

        .modal-header h2 {
            margin: 0;
            color: var(--secondary);
            font-size: 20px;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--gray);
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s;
        }

        .modal-close:hover {
            background: rgba(0, 0, 0, 0.1);
            color: var(--danger);
        }

        .modal-body {
            padding: 25px;
            max-height: 70vh;
            overflow-y: auto;
        }

        .results-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .result-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            background: var(--light);
            border-radius: 10px;
            border-left: 4px solid var(--primary);
        }

        .result-item.success {
            border-left-color: var(--success);
        }

        .result-item.failed {
            border-left-color: var(--warning);
        }

        .result-info {
            flex: 1;
        }

        .result-date {
            font-size: 14px;
            color: var(--gray);
            margin-bottom: 5px;
        }

        .result-score {
            font-size: 18px;
            font-weight: 700;
        }

        .result-actions {
            display: flex;
            gap: 10px;
        }

        .no-results {
            text-align: center;
            padding: 40px 20px;
            color: var(--gray);
        }

        .no-results i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
            display: block;
        }

        /* Detailed results styles */
        .detailed-results {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .attempt-item {
            transition: all 0.3s ease;
        }

        .attempt-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .tests-grid {
                grid-template-columns: 1fr;
            }
            
            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .test-actions {
                flex-direction: column;
            }

            .modal-content {
                width: 95%;
                margin: 20px;
            }

            .result-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .result-actions {
                width: 100%;
                justify-content: space-between;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Хлебные крошки -->
        <a href="index.php" class="back-button">
            <i class="fas fa-arrow-left"></i>
            Назад к панели
        </a>

        <div class="header">
            <h1><i class="fas fa-file-alt"></i> <?php echo $user['role'] == 'student' ? 'Доступные тесты' : 'Мои тесты'; ?></h1>
            <div class="breadcrumb">
                <a href="index.php">Главная</a>
                <i class="fas fa-chevron-right"></i>
                <span>Тесты</span>
            </div>
        </div>

        <?php if ($user['role'] == 'teacher' || $user['role'] == 'admin'): ?>
        <!-- Статистика для преподавателей -->
        <div class="stats-overview">
            <div class="stat-card">
                <h3><?php echo $teacher_stats['total_tests']; ?></h3>
                <p>Всего тестов</p>
            </div>
            <div class="stat-card success">
                <h3><?php echo $teacher_stats['active_tests']; ?></h3>
                <p>Активных тестов</p>
            </div>
            <div class="stat-card info">
                <h3><?php echo $teacher_stats['total_subjects']; ?></h3>
                <p>Предметов</p>
            </div>
            <div class="stat-card warning">
                <h3><?php echo $teacher_stats['total_attempts']; ?></h3>
                <p>Всего попыток</p>
            </div>
        </div>

        <!-- Создание нового теста -->
        <div class="create-test-section">
            <a href="create_test.php" class="create-test-btn">
                <i class="fas fa-plus"></i>
                Создать новый тест
            </a>
        </div>
        <?php endif; ?>

        <!-- Фильтры и поиск -->
        <div class="filters-section">
            <form method="GET" action="tests.php">
                <div class="filters-grid">
                    <div class="filter-group">
                        <label class="filter-label">Поиск тестов</label>
                        <input type="text" name="search" class="filter-input" placeholder="Название, описание или предмет..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Предмет</label>
                        <select name="subject" class="filter-select">
                            <option value="">Все предметы</option>
                            <?php foreach ($subjects as $subj): ?>
                                <option value="<?php echo htmlspecialchars($subj); ?>" <?php echo $subject == $subj ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($subj); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <?php if ($user['role'] == 'student'): ?>
                    <div class="filter-group">
                        <label class="filter-label">Статус</label>
                        <select name="status" class="filter-select">
                            <option value="">Все тесты</option>
                            <option value="available" <?php echo $status == 'available' ? 'selected' : ''; ?>>Доступные</option>
                            <option value="passed" <?php echo $status == 'passed' ? 'selected' : ''; ?>>Сданные</option>
                            <option value="failed" <?php echo $status == 'failed' ? 'selected' : ''; ?>>Не сданные</option>
                        </select>
                    </div>
                    <?php endif; ?>
                    
                    <div class="filter-group">
                        <label class="filter-label">Сортировка</label>
                        <select name="sort" class="filter-select">
                            <option value="newest" <?php echo $sort == 'newest' ? 'selected' : ''; ?>>Сначала новые</option>
                            <option value="title" <?php echo $sort == 'title' ? 'selected' : ''; ?>>По названию</option>
                            <option value="subject" <?php echo $sort == 'subject' ? 'selected' : ''; ?>>По предмету</option>
                            <option value="popular" <?php echo $sort == 'popular' ? 'selected' : ''; ?>>По популярности</option>
                            <option value="success" <?php echo $sort == 'success' ? 'selected' : ''; ?>>По успешности</option>
                        </select>
                    </div>
                    
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Применить
                        </button>
                        <a href="tests.php" class="btn btn-outline">
                            <i class="fas fa-times"></i> Сбросить
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Сетка тестов -->
        <div class="tests-grid">
            <?php if (count($tests) > 0): ?>
                <?php foreach ($tests as $test): ?>
                    <div class="test-card 
                        <?php if ($user['role'] == 'student'): ?>
                            <?php echo $test['attempts'] == 0 ? 'available' : ($test['last_status'] ? 'completed' : 'failed'); ?>
                        <?php endif; ?>
                    ">
                        <div class="test-header">
                            <div style="flex: 1;">
                                <div class="test-subject"><?php echo htmlspecialchars($test['subject']); ?></div>
                                <h3 class="test-title"><?php echo htmlspecialchars($test['title']); ?></h3>
                            </div>
                            <?php if ($user['role'] == 'student'): ?>
                                <span class="status-badge 
                                    <?php echo $test['attempts'] == 0 ? 'status-available' : ($test['last_status'] ? 'status-completed' : 'status-failed'); ?>">
                                    <?php echo $test['attempts'] == 0 ? 'Доступен' : ($test['last_status'] ? 'Сдан' : 'Не сдан'); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (!empty($test['description'])): ?>
                            <p class="test-description"><?php echo htmlspecialchars($test['description']); ?></p>
                        <?php endif; ?>
                        
                        <div class="test-meta">
                            <div class="test-meta-item">
                                <i class="fas fa-user"></i>
                                <?php echo htmlspecialchars($test['creator_name']); ?>
                            </div>
                            <div class="test-meta-item">
                                <i class="fas fa-clock"></i>
                                <?php echo $test['time_limit']; ?> мин
                            </div>
                        </div>

                        <?php if ($user['role'] == 'student' && $test['attempts'] > 0): ?>
                        <div class="results-info">
                            <div class="score-display 
                                <?php echo $test['best_score'] >= 80 ? 'score-excellent' : 
                                     ($test['best_score'] >= 60 ? 'score-good' : 'score-poor'); ?>">
                                Лучший результат: <?php echo round($test['best_score'], 1); ?>%
                            </div>
                            <div style="text-align: center; font-size: 14px; color: var(--gray);">
                                Попыток: <?php echo $test['attempts']; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="test-stats">
                            <div class="stat-item">
                                <div class="stat-value"><?php echo $test['total_attempts'] ?? 0; ?></div>
                                <div class="stat-label">Попыток</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value"><?php echo isset($test['avg_success']) ? round($test['avg_success'], 1) . '%' : 'N/A'; ?></div>
                                <div class="stat-label">Успешность</div>
                            </div>
                            <?php if ($user['role'] != 'student'): ?>
                            <div class="stat-item">
                                <div class="stat-value"><?php echo $test['unique_students'] ?? 0; ?></div>
                                <div class="stat-label">Студентов</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value">
                                    <?php if (isset($test['last_activity']) && $test['last_activity']): ?>
                                        <?php echo date('d.m', strtotime($test['last_activity'])); ?>
                                    <?php else: ?>
                                        Нет
                                    <?php endif; ?>
                                </div>
                                <div class="stat-label">Активность</div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="test-actions">
                            <?php if ($user['role'] == 'student'): ?>
                                <?php if ($test['attempts'] == 0): ?>
                                    <a href="take_test.php?id=<?php echo $test['id']; ?>" class="btn btn-primary">
                                        <i class="fas fa-play"></i> Начать тест
                                    </a>
                                <?php else: ?>
                                    <a href="take_test.php?id=<?php echo $test['id']; ?>" class="btn btn-warning">
                                        <i class="fas fa-redo"></i> Повторить
                                    </a>
                                    <button class="btn btn-outline view-results-btn" data-test-id="<?php echo $test['id']; ?>" data-test-title="<?php echo htmlspecialchars($test['title']); ?>">
                                        <i class="fas fa-chart-bar"></i> Результаты
                                    </button>
                                <?php endif; ?>
                            <?php else: ?>
                                <a href="test_edit.php?id=<?php echo $test['id']; ?>" class="btn btn-primary">
                                    <i class="fas fa-edit"></i> Редактировать
                                </a>
                                <a href="test_results.php?test_id=<?php echo $test['id']; ?>" class="btn btn-success">
                                    <i class="fas fa-chart-bar"></i> Результаты
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state" style="grid-column: 1 / -1;">
                    <i class="fas fa-file-alt"></i>
                    <h3>Тесты не найдены</h3>
                    <p><?php echo !empty($search) || !empty($subject) || !empty($status) ? 
                        'Попробуйте изменить параметры поиска или фильтры' : 
                        ($user['role'] == 'student' ? 
                            'В данный момент нет доступных тестов. Обратитесь к преподавателю.' : 
                            'Вы еще не создали ни одного теста.'); ?>
                    </p>
                    <?php if ($user['role'] != 'student'): ?>
                        <a href="create_test.php" class="btn btn-primary" style="margin-top: 15px;">
                            <i class="fas fa-plus"></i> Создать первый тест
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Модальное окно результатов -->
    <div class="modal-overlay" id="resultsModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle">Результаты теста</h2>
                <button class="modal-close" id="modalClose">&times;</button>
            </div>
            <div class="modal-body">
                <div id="modalContent">
                    <!-- Контент будет загружен через JavaScript -->
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const resultsModal = document.getElementById('resultsModal');
            const modalClose = document.getElementById('modalClose');
            const modalTitle = document.getElementById('modalTitle');
            const modalContent = document.getElementById('modalContent');

            // Обработчики для кнопок просмотра результатов
            document.querySelectorAll('.view-results-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const testId = this.getAttribute('data-test-id');
                    const testTitle = this.getAttribute('data-test-title');
                    showResultsModal(testId, testTitle);
                });
            });

            function showResultsModal(testId, testTitle) {
                modalTitle.textContent = `Результаты: ${testTitle}`;
                modalContent.innerHTML = `
                    <div style="text-align: center; padding: 40px;">
                        <i class="fas fa-spinner fa-spin" style="font-size: 32px; color: var(--primary); margin-bottom: 15px;"></i>
                        <p>Загрузка подробной информации...</p>
                    </div>
                `;
                
                resultsModal.classList.add('active');
                document.body.style.overflow = 'hidden';

                fetch(`get_test_results.php?test_id=${testId}`)
                    .then(response => {
                        if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            displayDetailedResults(data);
                        } else {
                            showError(data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showError('Ошибка загрузки: ' + error.message);
                    });
            }

            function displayDetailedResults(data) {
                const { test_info, statistics, attempts_history, user_info, summary } = data;
                
                let html = `
                    <div class="detailed-results">
                        <!-- Заголовок и основная информация -->
                        <div style="background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: white; padding: 20px; border-radius: 10px; margin-bottom: 20px;">
                            <h2 style="margin-bottom: 10px;">${test_info.title}</h2>
                            <div style="display: flex; gap: 20px; flex-wrap: wrap; font-size: 14px;">
                                <div><i class="fas fa-book"></i> ${test_info.subject}</div>
                                <div><i class="fas fa-user"></i> ${test_info.creator_name}</div>
                                <div><i class="fas fa-clock"></i> ${test_info.time_limit} мин</div>
                                <div><i class="fas fa-calendar"></i> Создан: ${new Date(test_info.created_at).toLocaleDateString('ru-RU')}</div>
                            </div>
                        </div>

                        <!-- Статистика -->
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px;">
                            <div style="background: white; padding: 20px; border-radius: 10px; text-align: center; border-left: 4px solid var(--primary);">
                                <div style="font-size: 24px; font-weight: bold; color: var(--primary);">${statistics.total_attempts}</div>
                                <div style="font-size: 14px; color: var(--gray);">Всего попыток</div>
                            </div>
                            <div style="background: white; padding: 20px; border-radius: 10px; text-align: center; border-left: 4px solid var(--success);">
                                <div style="font-size: 24px; font-weight: bold; color: var(--success);">${statistics.passed_attempts}</div>
                                <div style="font-size: 14px; color: var(--gray);">Успешных</div>
                            </div>
                            <div style="background: white; padding: 20px; border-radius: 10px; text-align: center; border-left: 4px solid var(--warning);">
                                <div style="font-size: 24px; font-weight: bold; color: var(--warning);">${statistics.avg_score}%</div>
                                <div style="font-size: 14px; color: var(--gray);">Средний результат</div>
                            </div>
                            <div style="background: white; padding: 20px; border-radius: 10px; text-align: center; border-left: 4px solid var(--info);">
                                <div style="font-size: 24px; font-weight: bold; color: var(--info);">${statistics.success_rate}%</div>
                                <div style="font-size: 14px; color: var(--gray);">Успешность</div>
                            </div>
                        </div>

                        <!-- Лучшие результаты -->
                        <div style="background: var(--light); padding: 15px; border-radius: 10px; margin-bottom: 20px;">
                            <h4 style="margin-bottom: 15px; color: var(--secondary);">
                                <i class="fas fa-chart-line"></i> Лучшие результаты
                            </h4>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; text-align: center;">
                                <div>
                                    <div style="font-size: 20px; font-weight: bold; color: var(--success);">${statistics.best_score}%</div>
                                    <div style="font-size: 12px; color: var(--gray);">Лучший результат</div>
                                </div>
                                <div>
                                    <div style="font-size: 20px; font-weight: bold; color: var(--danger);">${statistics.worst_score}%</div>
                                    <div style="font-size: 12px; color: var(--gray);">Худший результат</div>
                                </div>
                                ${user_info.role !== 'student' ? `
                                    <div>
                                        <div style="font-size: 20px; font-weight: bold; color: var(--info);">${statistics.unique_students}</div>
                                        <div style="font-size: 12px; color: var(--gray);">Уникальных студентов</div>
                                    </div>
                                ` : ''}
                            </div>
                        </div>
                `;

                // История прохождения
                html += `
                    <div class="attempts-history">
                        <h3 style="margin-bottom: 20px; color: var(--secondary); border-bottom: 2px solid var(--light); padding-bottom: 10px;">
                            <i class="fas fa-history"></i> История прохождения (${attempts_history.length})
                        </h3>
                `;

                if (attempts_history.length === 0) {
                    html += `
                        <div style="text-align: center; padding: 40px; color: var(--gray);">
                            <i class="fas fa-clipboard-list" style="font-size: 48px; margin-bottom: 15px; opacity: 0.5;"></i>
                            <h4>Нет результатов прохождения</h4>
                            <p>Этот тест еще никто не проходил</p>
                            <a href="take_test.php?id=${test_info.id}" class="btn btn-primary" style="margin-top: 15px;">
                                <i class="fas fa-play"></i> Пройти первым
                            </a>
                        </div>
                    `;
                } else {
                    attempts_history.forEach(attempt => {
                        html += `
                            <div class="attempt-item ${attempt.status_class}" style="background: white; padding: 20px; border-radius: 10px; margin-bottom: 15px; border-left: 4px solid var(--${attempt.status_class}); position: relative;">
                                ${attempt.is_best ? '<div style="position: absolute; top: 10px; right: 10px; background: var(--success); color: white; padding: 2px 8px; border-radius: 10px; font-size: 12px;"><i class="fas fa-crown"></i> Лучший</div>' : ''}
                                ${attempt.is_last ? '<div style="position: absolute; top: 10px; right: 10px; background: var(--info); color: white; padding: 2px 8px; border-radius: 10px; font-size: 12px;"><i class="fas fa-star"></i> Последняя</div>' : ''}
                                
                                <div style="display: flex; justify-content: between; align-items: flex-start; margin-bottom: 15px;">
                                    <div style="flex: 1;">
                                        <div style="font-weight: bold; margin-bottom: 5px;">
                                            Попытка #${attempt.attempt_number}
                                            <span style="font-size: 14px; font-weight: normal; color: var(--gray); margin-left: 10px;">
                                                ${attempt.formatted_date} (${attempt.time_ago})
                                            </span>
                                        </div>
                                        ${user_info.role !== 'student' && attempt.student_name ? `
                                            <div style="font-size: 14px; color: var(--secondary);">
                                                <i class="fas fa-user-graduate"></i> ${attempt.student_name}
                                                ${attempt.group_name ? ` (${attempt.group_name})` : ''}
                                            </div>
                                        ` : ''}
                                    </div>
                                    <div style="text-align: right;">
                                        <div class="score-display ${attempt.score_class}" style="font-size: 24px; font-weight: bold;">
                                            ${attempt.percentage}%
                                        </div>
                                        <div style="font-size: 14px; color: var(--gray);">
                                            ${attempt.score}/${attempt.total_points} баллов
                                        </div>
                                    </div>
                                </div>

                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; font-size: 14px;">
                                    <div>
                                        <strong>Статус:</strong> 
                                        <span class="status-badge ${attempt.passed ? 'status-passed' : 'status-failed'}">
                                            ${attempt.passed ? 'Сдан' : 'Не сдан'}
                                        </span>
                                    </div>
                                    <div>
                                        <strong>Оценка:</strong> 
                                        <span style="color: var(--${attempt.score_class}); font-weight: bold;">
                                            ${attempt.score_class === 'excellent' ? 'Отлично' : attempt.score_class === 'good' ? 'Хорошо' : 'Плохо'}
                                        </span>
                                    </div>
                                </div>

                                <div style="display: flex; gap: 10px; margin-top: 15px;">
                                    <a href="test_results_view.php?id=${attempt.id}" class="btn btn-sm btn-primary">
                                        <i class="fas fa-eye"></i> Подробный разбор
                                    </a>
                                    <a href="take_test.php?id=${attempt.test_id}" class="btn btn-sm btn-warning">
                                        <i class="fas fa-redo"></i> Повторить
                                    </a>
                                    ${user_info.role !== 'student' && attempt.email ? `
                                        <a href="mailto:${attempt.email}" class="btn btn-sm btn-outline">
                                            <i class="fas fa-envelope"></i> Написать
                                        </a>
                                    ` : ''}
                                </div>
                            </div>
                        `;
                    });
                }

                html += '</div></div>';
                modalContent.innerHTML = html;
            }

            function showError(message) {
                modalContent.innerHTML = `
                    <div style="text-align: center; padding: 40px; color: var(--gray);">
                        <i class="fas fa-exclamation-triangle" style="font-size: 48px; color: var(--danger); margin-bottom: 15px;"></i>
                        <h3>Ошибка загрузки</h3>
                        <p>${message}</p>
                        <button class="btn btn-outline" onclick="location.reload()" style="margin-top: 15px;">
                            <i class="fas fa-refresh"></i> Обновить страницу
                        </button>
                    </div>
                `;
            }

            function closeModal() {
                resultsModal.classList.remove('active');
                document.body.style.overflow = 'auto';
                setTimeout(() => modalContent.innerHTML = '', 300);
            }

            modalClose.addEventListener('click', closeModal);
            resultsModal.addEventListener('click', (e) => e.target === resultsModal && closeModal());
            document.addEventListener('keydown', (e) => e.key === 'Escape' && closeModal());

            // Анимация карточек
            const cards = document.querySelectorAll('.test-card');
            cards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    card.style.transition = 'all 0.6s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 100);
            });
        });
    </script>
</body>
</html>