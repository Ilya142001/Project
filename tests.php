<?php
include 'config.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

$search = $_GET['search'] ?? '';
$subject = $_GET['subject'] ?? '';
$status = $_GET['status'] ?? '';
$sort = $_GET['sort'] ?? 'newest';

// Базовый запрос для тестов
if ($user['role'] == 'student') {
    $query = "
        SELECT 
            t.*, 
            u.full_name as creator_name,
            (SELECT COUNT(*) FROM test_results WHERE test_id = t.id AND user_id = ?) as attempts,
            (SELECT MAX(percentage) FROM test_results WHERE test_id = t.id AND user_id = ?) as best_score,
            (SELECT passed FROM test_results WHERE test_id = t.id AND user_id = ? ORDER BY completed_at DESC LIMIT 1) as last_status,
            (SELECT COUNT(*) FROM test_results WHERE test_id = t.id) as total_attempts,
            (SELECT AVG(percentage) FROM test_results WHERE test_id = t.id) as avg_success,
            (SELECT MAX(attempt_number) FROM test_results WHERE test_id = t.id AND user_id = ?) as user_attempts
        FROM tests t
        JOIN users u ON t.created_by = u.id
        WHERE t.is_active = 1
    ";
    $params = [$_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']];
} else {
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

if (!empty($search)) {
    $query .= " AND (t.title LIKE ? OR t.description LIKE ? OR t.subject LIKE ?)";
    $search_term = "%$search%";
    array_push($params, $search_term, $search_term, $search_term);
}

if (!empty($subject)) {
    $query .= " AND t.subject = ?";
    $params[] = $subject;
}

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

switch ($sort) {
    case 'title': $query .= " ORDER BY t.title ASC"; break;
    case 'subject': $query .= " ORDER BY t.subject ASC, t.title ASC"; break;
    case 'popular': $query .= " ORDER BY total_attempts DESC"; break;
    case 'success': $query .= " ORDER BY avg_success DESC"; break;
    case 'newest':
    default: $query .= " ORDER BY t.created_at DESC"; break;
}

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$tests = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT DISTINCT subject FROM tests WHERE is_active = 1 ORDER BY subject");
$stmt->execute();
$subjects = $stmt->fetchAll(PDO::FETCH_COLUMN);

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
    <title>Тесты - Система оценки знаний</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        }
        
        :root {
            --primary: #2563eb;
            --primary-light: #3b82f6;
            --success: #059669;
            --warning: #d97706;
            --danger: #dc2626;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-500: #6b7280;
            --gray-700: #374151;
            --gray-900: #111827;
        }
        
        body {
            background: var(--gray-50);
            color: var(--gray-900);
            line-height: 1.6;
            min-height: 100vh;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            background: white;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--gray-200);
        }
        
        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header h1 {
            font-size: 24px;
            font-weight: 600;
            color: var(--gray-900);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--gray-500);
            font-size: 14px;
        }
        
        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
        }

        /* Stats Overview */
        <?php if ($user['role'] == 'teacher' || $user['role'] == 'admin'): ?>
        .stats-overview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            border: 1px solid var(--gray-200);
            transition: transform 0.2s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
        }
        
        .stat-card h3 {
            font-size: 28px;
            margin-bottom: 4px;
            color: var(--gray-900);
            font-weight: 700;
        }
        
        .stat-card p {
            color: var(--gray-500);
            font-size: 14px;
        }
        <?php endif; ?>

        /* Filters */
        .filters-section {
            background: white;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            border: 1px solid var(--gray-200);
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr auto;
            gap: 16px;
            align-items: end;
        }
        
        @media (max-width: 1024px) {
            .filters-grid {
                grid-template-columns: 1fr 1fr;
            }
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
            font-weight: 500;
            color: var(--gray-700);
        }
        
        .filter-input, .filter-select {
            padding: 12px 16px;
            border: 1px solid var(--gray-200);
            border-radius: 8px;
            font-size: 14px;
            background: white;
            width: 100%;
            transition: border-color 0.2s ease;
        }
        
        .filter-input:focus, .filter-select:focus {
            outline: none;
            border-color: var(--primary);
        }
        
        .filter-actions {
            display: flex;
            gap: 12px;
        }

        /* Tests Grid */
        .tests-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }

        .test-card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            border: 1px solid var(--gray-200);
            transition: all 0.2s ease;
            position: relative;
        }

        .test-card:hover {
            border-color: var(--primary);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.1);
        }

        .test-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px;
        }

        .test-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 8px;
            line-height: 1.4;
            letter-spacing: -0.01em;
        }

        .test-subject {
            display: inline-block;
            background: var(--gray-100);
            color: var(--gray-700);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            margin-bottom: 12px;
            border: 1px solid var(--gray-200);
        }

        .test-description {
            color: var(--gray-500);
            font-size: 14px;
            margin-bottom: 20px;
            line-height: 1.5;
        }

        .test-meta {
            display: flex;
            justify-content: space-between;
            font-size: 13px;
            color: var(--gray-500);
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--gray-200);
        }

        .test-meta-item {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .test-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 20px;
        }

        .stat-item {
            text-align: center;
            padding: 12px;
            background: var(--gray-50);
            border-radius: 8px;
        }

        .stat-value {
            font-size: 18px;
            font-weight: 600;
            color: var(--gray-900);
        }

        .stat-label {
            font-size: 12px;
            color: var(--gray-500);
        }

        .test-actions {
            display: flex;
            gap: 8px;
        }

        .btn {
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
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
            background: var(--primary-light);
        }

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        .btn-warning:hover {
            background: #b45309;
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--gray-300);
            color: var(--gray-700);
        }

        .btn-outline:hover {
            background: var(--gray-50);
            border-color: var(--gray-400);
        }

        /* Status Badges */
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
            display: inline-block;
        }

        .status-available {
            background: #ecfdf5;
            color: var(--success);
            border: 1px solid #a7f3d0;
        }

        .status-completed {
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .status-failed {
            background: #fef2f2;
            color: var(--danger);
            border: 1px solid #fecaca;
        }

        /* Results Info */
        .results-info {
            background: var(--gray-50);
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            border: 1px solid var(--gray-200);
        }

        .score-display {
            font-size: 20px;
            font-weight: 600;
            text-align: center;
            margin-bottom: 4px;
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

        .attempts-counter {
            text-align: center;
            font-size: 12px;
            color: var(--gray-500);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--gray-500);
            grid-column: 1 / -1;
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
            display: block;
        }
        
        .empty-state h3 {
            font-size: 20px;
            margin-bottom: 8px;
            color: var(--gray-700);
        }
        
        .empty-state p {
            font-size: 14px;
            margin-bottom: 20px;
        }

        /* Create Test Section */
        .create-test-section {
            text-align: center;
            padding: 40px 20px;
            background: white;
            border-radius: 12px;
            margin-bottom: 24px;
            border: 2px dashed var(--gray-300);
        }
        
        .create-test-btn {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            padding: 16px 24px;
            background: var(--primary);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 500;
            transition: background-color 0.2s ease;
        }
        
        .create-test-btn:hover {
            background: var(--primary-light);
        }

        /* Back Button */
        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            background: var(--primary);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 500;
            transition: background-color 0.2s ease;
            margin-bottom: 20px;
        }
        
        .back-button:hover {
            background: var(--primary-light);
        }

        /* Modal Results */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 12px;
            width: 100%;
            max-width: 800px;
            max-height: 90vh;
            overflow: hidden;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        }

        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h2 {
            font-size: 20px;
            font-weight: 600;
            color: var(--gray-900);
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 20px;
            cursor: pointer;
            color: var(--gray-500);
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
            transition: background-color 0.2s ease;
        }

        .modal-close:hover {
            background: var(--gray-100);
        }

        .modal-body {
            padding: 24px;
            max-height: calc(90vh - 80px);
            overflow-y: auto;
        }

        .results-summary {
            text-align: center;
            margin-bottom: 24px;
            padding: 20px;
            background: var(--gray-50);
            border-radius: 8px;
            border: 1px solid var(--gray-200);
        }

        .results-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 16px;
            color: var(--gray-900);
        }

        .results-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }

        .result-stat {
            text-align: center;
            padding: 12px;
        }

        .stat-number {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .stat-label {
            font-size: 12px;
            color: var(--gray-500);
        }

        .attempts-history {
            margin-top: 24px;
        }

        .attempts-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 16px;
            color: var(--gray-900);
        }

        .attempt-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .attempt-item {
            padding: 16px;
            background: var(--gray-50);
            border-radius: 8px;
            border: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .attempt-info {
            flex: 1;
        }

        .attempt-number {
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 4px;
        }

        .attempt-date {
            font-size: 12px;
            color: var(--gray-500);
        }

        .attempt-score {
            font-size: 18px;
            font-weight: 700;
        }

        .score-excellent { color: var(--success); }
        .score-good { color: var(--warning); }
        .score-poor { color: var(--danger); }

        .no-results {
            text-align: center;
            padding: 40px 20px;
            color: var(--gray-500);
        }

        .no-results i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        /* Table Styles */
        .results-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .results-table th,
        .results-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--gray-200);
        }

        .results-table th {
            background: var(--gray-50);
            font-weight: 600;
            color: var(--gray-700);
            font-size: 14px;
        }

        .results-table tr:last-child td {
            border-bottom: none;
        }

        .results-table tr:hover {
            background: var(--gray-50);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .tests-grid {
                grid-template-columns: 1fr;
            }
            
            .header-content {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }
            
            .test-actions {
                flex-direction: column;
            }
            
            .btn {
                padding: 10px 16px;
            }

            .modal-content {
                margin: 0;
                max-width: 95%;
            }

            .attempt-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }

            .attempt-score {
                align-self: flex-end;
            }

            .results-table {
                font-size: 14px;
            }

            .results-table th,
            .results-table td {
                padding: 8px;
            }
        }

        /* Animation */
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .test-card {
            animation: fadeIn 0.3s ease forwards;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="index.php" class="back-button">
            <i class="fas fa-arrow-left"></i>
            Назад к панели
        </a>

        <div class="header">
            <div class="header-content">
                <h1><i class="fas fa-file-alt"></i> <?php echo $user['role'] == 'student' ? 'Доступные тесты' : 'Мои тесты'; ?></h1>
                <div class="breadcrumb">
                    <a href="index.php">Главная</a>
                    <i class="fas fa-chevron-right"></i>
                    <span>Тесты</span>
                </div>
            </div>
        </div>

        <?php if ($user['role'] == 'teacher' || $user['role'] == 'admin'): ?>
        <div class="stats-overview">
            <div class="stat-card">
                <h3><?php echo $teacher_stats['total_tests']; ?></h3>
                <p>Всего тестов</p>
            </div>
            <div class="stat-card">
                <h3><?php echo $teacher_stats['active_tests']; ?></h3>
                <p>Активных тестов</p>
            </div>
            <div class="stat-card">
                <h3><?php echo $teacher_stats['total_subjects']; ?></h3>
                <p>Предметов</p>
            </div>
            <div class="stat-card">
                <h3><?php echo $teacher_stats['total_attempts']; ?></h3>
                <p>Всего попыток</p>
            </div>
        </div>

        <div class="create-test-section">
            <a href="create_test.php" class="create-test-btn">
                <i class="fas fa-plus"></i>
                Создать новый тест
            </a>
        </div>
        <?php endif; ?>

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

        <div class="tests-grid">
            <?php if (count($tests) > 0): ?>
                <?php foreach ($tests as $test): ?>
                    <div class="test-card">
                        <div class="test-header">
                            <div style="flex: 1;">
                                <div class="test-subject"><?php echo htmlspecialchars($test['subject'] ?? 'Без предмета'); ?></div>
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
                            <div class="attempts-counter">
                                Попыток: <?php echo $test['user_attempts']; ?>
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
                        </div>

                        <div class="test-actions">
                            <?php if ($user['role'] == 'student'): ?>
                                <?php if ($test['attempts'] == 0): ?>
                                    <a href="take_test.php?id=<?php echo $test['id']; ?>" class="btn btn-primary">
                                        <i class="fas fa-play"></i> Начать тест
                                    </a>
                                <?php else: ?>
                                    <!-- ИСПРАВЛЕННАЯ ССЫЛКА ПОВТОРИТЬ -->
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
                                <a href="test_results.php?test_id=<?php echo $test['id']; ?>" class="btn btn-outline">
                                    <i class="fas fa-chart-bar"></i> Результаты
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-file-alt"></i>
                    <h3>Тесты не найдены</h3>
                    <p><?php echo !empty($search) || !empty($subject) || !empty($status) ? 
                        'Попробуйте изменить параметры поиска или фильтры' : 
                        ($user['role'] == 'student' ? 
                            'В данный момент нет доступных тестов. Обратитесь к преподавателю.' : 
                            'Вы еще не создали ни одного теста.'); ?>
                    </p>
                    <?php if ($user['role'] != 'student'): ?>
                        <a href="create_test.php" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Создать первый тест
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- МОДАЛЬНОЕ ОКНО РЕЗУЛЬТАТОВ -->
    <div class="modal-overlay" id="resultsModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle">Результаты теста</h2>
                <button class="modal-close" id="modalClose">
                    <i class="fas fa-times"></i>
                </button>
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
            
            // Показываем данные без AJAX запроса (заглушка)
            displayMockResults(testId, testTitle);
            
            resultsModal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function displayMockResults(testId, testTitle) {
            const mockData = {
                statistics: {
                    total_attempts: 3,
                    passed_attempts: 2,
                    avg_score: 70,
                    best_score: 90
                },
                attempts: [
                    { 
                        attempt_number: 1, 
                        score: 9, 
                        total_points: 10, 
                        percentage: 90, 
                        completed_at: '2024-01-15 14:30:00', 
                        passed: true 
                    },
                    { 
                        attempt_number: 2, 
                        score: 8, 
                        total_points: 10, 
                        percentage: 80, 
                        completed_at: '2024-01-20 10:15:00', 
                        passed: true 
                    },
                    { 
                        attempt_number: 3, 
                        score: 4, 
                        total_points: 10, 
                        percentage: 40, 
                        completed_at: '2024-01-25 16:45:00', 
                        passed: false 
                    }
                ]
            };

            let html = `
                <div class="results-summary">
                    <div class="results-title">Общая статистика по тесту: ${testTitle}</div>
                    <div class="results-stats">
                        <div class="result-stat">
                            <div class="stat-number">${mockData.statistics.total_attempts}</div>
                            <div class="stat-label">Всего попыток</div>
                        </div>
                        <div class="result-stat">
                            <div class="stat-number">${mockData.statistics.passed_attempts}</div>
                            <div class="stat-label">Успешных</div>
                        </div>
                        <div class="result-stat">
                            <div class="stat-number">${mockData.statistics.avg_score}%</div>
                            <div class="stat-label">Средний результат</div>
                        </div>
                        <div class="result-stat">
                            <div class="stat-number">${mockData.statistics.best_score}%</div>
                            <div class="stat-label">Лучший результат</div>
                        </div>
                    </div>
                </div>

                <div class="attempts-history">
                    <div class="attempts-title">Мои попытки</div>
                    <div class="attempt-list">
            `;

            if (mockData.attempts.length === 0) {
                html += `
                    <div class="no-results">
                        <i class="fas fa-clipboard-list"></i>
                        <p>Нет результатов прохождения</p>
                    </div>
                `;
            } else {
                mockData.attempts.forEach(attempt => {
                    const scoreClass = attempt.percentage >= 80 ? 'score-excellent' : 
                                     attempt.percentage >= 60 ? 'score-good' : 'score-poor';
                    const date = new Date(attempt.completed_at);
                    
                    html += `
                        <div class="attempt-item">
                            <div class="attempt-info">
                                <div class="attempt-number">Попытка #${attempt.attempt_number}</div>
                                <div class="attempt-date">${date.toLocaleDateString('ru-RU')} ${date.toLocaleTimeString('ru-RU', {hour: '2-digit', minute:'2-digit'})}</div>
                                <div class="attempt-details">${attempt.score}/${attempt.total_points} баллов • ${attempt.passed ? 'Сдан' : 'Не сдан'}</div>
                            </div>
                            <div class="attempt-score ${scoreClass}">
                                ${attempt.percentage}%
                            </div>
                        </div>
                    `;
                });
            }

            html += `
                    </div>
                </div>

                <!-- ТАБЛИЦА С ДАННЫМИ ИЗ СКРИНШОТА -->
                <div style="margin-top: 30px;">
                    <div class="attempts-title">Детальные результаты тестов</div>
                    <table class="results-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Название теста</th>
                                <th>Описание</th>
                                <th>Создан</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>7</td>
                                <td>Фортинайти или Бабаджи</td>
                                <td>Фортинайти или Бабаджи</td>
                                <td>${new Date('2024-01-15').toLocaleDateString('ru-RU')}</td>
                            </tr>
                            <tr>
                                <td>8</td>
                                <td>да или нет 2</td>
                                <td>да или нет 5</td>
                                <td>${new Date('2024-01-16').toLocaleDateString('ru-RU')}</td>
                            </tr>
                            <tr>
                                <td>10</td>
                                <td>да или нет 5</td>
                                <td>да или нет 5</td>
                                <td>${new Date('2024-01-17').toLocaleDateString('ru-RU')}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- ЧЕКБОКСЫ ИЗ СКРИНШОТА -->
                <div style="margin-top: 20px; padding: 15px; background: var(--gray-50); border-radius: 8px;">
                    <div style="margin-bottom: 10px;">
                        <label style="display: flex; align-items: center; gap: 8px;">
                            <input type="checkbox" id="selectAll">
                            <span style="font-weight: 500;">Отметить все</span>
                        </label>
                    </div>
                    <div>
                        <strong>С отмеченными:</strong>
                        <div style="margin-top: 8px; font-size: 14px; color: var(--gray-600);">
                            Выберите элементы для выполнения действий
                        </div>
                    </div>
                </div>
            `;

            modalContent.innerHTML = html;

            // Добавляем обработчик для чекбокса "Отметить все"
            const selectAllCheckbox = document.getElementById('selectAll');
            if (selectAllCheckbox) {
                selectAllCheckbox.addEventListener('change', function() {
                    const checkboxes = modalContent.querySelectorAll('input[type="checkbox"]:not(#selectAll)');
                    checkboxes.forEach(checkbox => {
                        checkbox.checked = this.checked;
                    });
                });
            }
        }

        function closeModal() {
            resultsModal.classList.remove('active');
            document.body.style.overflow = 'auto';
        }

        modalClose.addEventListener('click', closeModal);
        resultsModal.addEventListener('click', (e) => {
            if (e.target === resultsModal) closeModal();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeModal();
        });

        // Анимация карточек
        const cards = document.querySelectorAll('.test-card');
        cards.forEach((card, index) => {
            card.style.animationDelay = `${index * 0.1}s`;
        });
    });
</script>
</body>
</html>