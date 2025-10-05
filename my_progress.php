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

// Проверяем, что пользователь - студент
if ($user['role'] != 'student') {
    header("Location: index.php");
    exit;
}

// Получаем общую статистику студента
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_tests,
        COALESCE(SUM(score), 0) as total_score,
        COALESCE(SUM(total_points), 0) as total_points,
        AVG(percentage) as avg_percentage,
        COUNT(CASE WHEN passed = 1 THEN 1 END) as passed_tests,
        MAX(percentage) as best_score,
        MIN(percentage) as worst_score,
        COUNT(CASE WHEN percentage >= 80 THEN 1 END) as excellent_tests,
        COUNT(CASE WHEN percentage >= 60 AND percentage < 80 THEN 1 END) as good_tests,
        COUNT(CASE WHEN percentage < 60 THEN 1 END) as poor_tests
    FROM test_results 
    WHERE user_id = ?
");
$stmt->execute([$_SESSION['user_id']]);
$overall_stats = $stmt->fetch();

// Получаем прогресс по месяцам
$stmt = $pdo->prepare("
    SELECT 
        DATE_FORMAT(completed_at, '%Y-%m') as month,
        COUNT(*) as tests_count,
        AVG(percentage) as avg_score,
        MAX(percentage) as best_score,
        COUNT(CASE WHEN passed = 1 THEN 1 END) as passed_tests
    FROM test_results 
    WHERE user_id = ? AND completed_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(completed_at, '%Y-%m')
    ORDER BY month DESC
    LIMIT 12
");
$stmt->execute([$_SESSION['user_id']]);
$monthly_progress = $stmt->fetchAll();

// Получаем статистику по предметам
$stmt = $pdo->prepare("
    SELECT 
        t.subject,
        COUNT(*) as tests_count,
        AVG(tr.percentage) as avg_score,
        MAX(tr.percentage) as best_score,
        MIN(tr.percentage) as worst_score,
        COUNT(CASE WHEN tr.passed = 1 THEN 1 END) as passed_tests,
        SUM(tr.score) as total_score,
        SUM(tr.total_points) as total_points
    FROM test_results tr
    JOIN tests t ON tr.test_id = t.id
    WHERE tr.user_id = ?
    GROUP BY t.subject
    ORDER BY avg_score DESC
");
$stmt->execute([$_SESSION['user_id']]);
$subject_stats = $stmt->fetchAll();

// Получаем прогресс по неделям для графика
$stmt = $pdo->prepare("
    SELECT 
        YEARWEEK(completed_at) as week,
        DATE_FORMAT(MIN(completed_at), '%d.%m') as week_start,
        COUNT(*) as tests_count,
        AVG(percentage) as avg_score,
        COUNT(CASE WHEN passed = 1 THEN 1 END) as passed_tests
    FROM test_results 
    WHERE user_id = ? AND completed_at >= DATE_SUB(NOW(), INTERVAL 12 WEEK)
    GROUP BY YEARWEEK(completed_at)
    ORDER BY week ASC
    LIMIT 12
");
$stmt->execute([$_SESSION['user_id']]);
$weekly_progress = $stmt->fetchAll();

// Получаем последние результаты тестов (исправленный запрос без started_at)
$stmt = $pdo->prepare("
    SELECT 
        t.title,
        t.subject,
        tr.score,
        tr.total_points,
        tr.percentage,
        tr.passed,
        tr.completed_at,
        u.full_name as teacher_name
    FROM test_results tr
    JOIN tests t ON tr.test_id = t.id
    JOIN users u ON t.created_by = u.id
    WHERE tr.user_id = ?
    ORDER BY tr.completed_at DESC
    LIMIT 10
");
$stmt->execute([$_SESSION['user_id']]);
$recent_results = $stmt->fetchAll();

// Получаем рекомендации на основе прогресса
$recommendations = [];

if ($overall_stats['total_tests'] > 0) {
    if ($overall_stats['avg_percentage'] < 60) {
        $recommendations[] = [
            'type' => 'warning',
            'title' => 'Низкий средний результат',
            'message' => 'Рекомендуем уделить больше времени подготовке к тестам и обратиться к преподавателю за помощью.'
        ];
    }

    if ($overall_stats['passed_tests'] < $overall_stats['total_tests'] * 0.7) {
        $recommendations[] = [
            'type' => 'danger',
            'title' => 'Много непройденных тестов',
            'message' => 'Старайтесь лучше готовиться к тестам и проходить их повторно для улучшения результатов.'
        ];
    }

    // Находим слабые предметы
    $weak_subjects = array_filter($subject_stats, function($subject) {
        return $subject['avg_score'] < 60;
    });

    foreach ($weak_subjects as $subject) {
        $recommendations[] = [
            'type' => 'info',
            'title' => 'Слабый предмет: ' . $subject['subject'],
            'message' => 'Рекомендуем уделить дополнительное время изучению этого предмета.'
        ];
    }

    if (empty($recommendations)) {
        $recommendations[] = [
            'type' => 'success',
            'title' => 'Отличные результаты!',
            'message' => 'Продолжайте в том же духе! Ваши результаты выше среднего.'
        ];
    }
} else {
    $recommendations[] = [
        'type' => 'info',
        'title' => 'Начните обучение',
        'message' => 'Вы еще не прошли ни одного теста. Начните с доступных тестов на главной странице.'
    ];
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Мой прогресс - Система интеллектуальной оценки знаний</title>
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

        /* Stats Overview */
        .stats-overview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            display: flex;
            align-items: center;
            transition: transform 0.3s, box-shadow 0.3s;
            border-left: 4px solid var(--primary);
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.12);
        }
        
        .stat-card.success {
            border-left-color: var(--success);
        }
        
        .stat-card.warning {
            border-left-color: var(--warning);
        }
        
        .stat-card.danger {
            border-left-color: var(--danger);
        }
        
        .stat-card.info {
            border-left-color: var(--info);
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-right: 20px;
            color: white;
        }
        
        .icon-primary {
            background: linear-gradient(135deg, var(--primary), #4a69bd);
        }
        
        .icon-success {
            background: linear-gradient(135deg, var(--success), #1dd1a1);
        }
        
        .icon-warning {
            background: linear-gradient(135deg, var(--warning), #f6b93b);
        }
        
        .icon-danger {
            background: linear-gradient(135deg, var(--danger), #e55039);
        }
        
        .icon-info {
            background: linear-gradient(135deg, var(--info), #48dbfb);
        }
        
        .stat-details h3 {
            font-size: 32px;
            margin-bottom: 5px;
            color: var(--secondary);
            font-weight: 700;
        }
        
        .stat-details p {
            color: var(--gray);
            font-size: 15px;
            margin-bottom: 8px;
        }

        /* Dashboard Grid */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 25px;
            margin-bottom: 25px;
        }

        @media (max-width: 992px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
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
            margin-bottom: 25px;
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
        
        .view-all {
            color: var(--primary);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: color 0.3s;
        }
        
        .view-all:hover {
            color: var(--primary-dark);
        }

        /* Charts */
        .chart-container {
            height: 300px;
            position: relative;
        }

        /* Progress Bars */
        .progress-bars {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .progress-item {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .progress-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .progress-label {
            font-weight: 600;
            color: var(--secondary);
        }
        
        .progress-value {
            font-weight: 600;
            color: var(--primary);
        }
        
        .progress-bar {
            height: 8px;
            background: #e0e0e0;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.5s ease;
        }
        
        .progress-fill.excellent {
            background: var(--success);
        }
        
        .progress-fill.good {
            background: var(--warning);
        }
        
        .progress-fill.poor {
            background: var(--danger);
        }

        /* Subject Stats */
        .subject-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .subject-card {
            background: var(--light);
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            transition: transform 0.3s;
            border-left: 4px solid var(--primary);
        }
        
        .subject-card:hover {
            transform: translateY(-3px);
        }
        
        .subject-name {
            font-weight: 600;
            margin-bottom: 10px;
            color: var(--secondary);
        }
        
        .subject-score {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .subject-meta {
            font-size: 12px;
            color: var(--gray);
        }

        /* Results Table */
        .results-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .results-table th, .results-table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .results-table th {
            background-color: var(--light);
            font-weight: 600;
            color: var(--secondary);
            font-size: 14px;
        }
        
        .results-table tr:hover {
            background-color: #f8f9fa;
        }
        
        .score-cell {
            font-weight: 600;
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
        
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-passed {
            background: rgba(46, 204, 113, 0.1);
            color: var(--success);
        }
        
        .status-failed {
            background: rgba(231, 76, 60, 0.1);
            color: var(--danger);
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
        }
        
        .recommendation-message {
            color: var(--gray);
            font-size: 14px;
        }

        /* Empty States */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--gray);
            width: 100%;
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
            display: block;
        }
        
        .empty-state p {
            font-size: 16px;
            margin-bottom: 15px;
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

        .no-data {
            text-align: center;
            padding: 40px 20px;
            color: var(--gray);
            font-style: italic;
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
            <h1><i class="fas fa-chart-line"></i> Мой прогресс</h1>
            <div class="breadcrumb">
                <a href="index.php">Главная</a>
                <i class="fas fa-chevron-right"></i>
                <span>Мой прогресс</span>
            </div>
        </div>

        <!-- Общая статистика -->
        <div class="stats-overview">
            <div class="stat-card success">
                <div class="stat-icon icon-success">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-details">
                    <h3><?php echo $overall_stats['passed_tests']; ?>/<?php echo $overall_stats['total_tests']; ?></h3>
                    <p>Пройдено тестов</p>
                    <div class="stat-trend">
                        <?php echo $overall_stats['total_tests'] > 0 ? round(($overall_stats['passed_tests'] / $overall_stats['total_tests']) * 100, 1) : 0; ?>% успеха
                    </div>
                </div>
            </div>
            
            <div class="stat-card info">
                <div class="stat-icon icon-info">
                    <i class="fas fa-percentage"></i>
                </div>
                <div class="stat-details">
                    <h3><?php echo $overall_stats['total_tests'] > 0 ? round($overall_stats['avg_percentage'], 1) : 0; ?>%</h3>
                    <p>Средний результат</p>
                    <div class="stat-trend">
                        Лучший: <?php echo $overall_stats['best_score'] ? round($overall_stats['best_score'], 1) : 0; ?>%
                    </div>
                </div>
            </div>
            
            <div class="stat-card primary">
                <div class="stat-icon icon-primary">
                    <i class="fas fa-file-alt"></i>
                </div>
                <div class="stat-details">
                    <h3><?php echo $overall_stats['total_tests']; ?></h3>
                    <p>Всего тестов</p>
                    <div class="stat-trend">
                        <?php echo $overall_stats['excellent_tests']; ?> отличных
                    </div>
                </div>
            </div>
            
            <div class="stat-card warning">
                <div class="stat-icon icon-warning">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-details">
                    <h3><?php echo count($weekly_progress); ?></h3>
                    <p>Недель активности</p>
                    <div class="stat-trend">
                        <?php echo count($recent_results); ?> недавних тестов
                    </div>
                </div>
            </div>
        </div>

        <div class="dashboard-grid">
            <!-- Основной контент -->
            <div class="main-content">
                <!-- График прогресса -->
                <div class="section">
                    <div class="section-header">
                        <h2><i class="fas fa-chart-line"></i> Прогресс по неделям</h2>
                    </div>
                    <div class="chart-container">
                        <?php if (count($weekly_progress) > 0): ?>
                            <canvas id="progressChart"></canvas>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-chart-line"></i>
                                <p>Нет данных для построения графика</p>
                                <p class="no-data">Пройдите несколько тестов, чтобы увидеть ваш прогресс</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Статистика по предметам -->
                <div class="section">
                    <div class="section-header">
                        <h2><i class="fas fa-book"></i> Результаты по предметам</h2>
                    </div>
                    <div class="subject-stats">
                        <?php if (count($subject_stats) > 0): ?>
                            <?php foreach ($subject_stats as $subject): ?>
                                <div class="subject-card">
                                    <div class="subject-name"><?php echo htmlspecialchars($subject['subject']); ?></div>
                                    <div class="subject-score <?php 
                                        echo $subject['avg_score'] >= 80 ? 'score-excellent' : 
                                             ($subject['avg_score'] >= 60 ? 'score-good' : 'score-poor'); 
                                    ?>">
                                        <?php echo round($subject['avg_score'], 1); ?>%
                                    </div>
                                    <div class="subject-meta">
                                        <?php echo $subject['tests_count']; ?> тестов
                                    </div>
                                    <div class="subject-meta">
                                        Лучший: <?php echo round($subject['best_score'], 1); ?>%
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-book"></i>
                                <p>Нет данных по предметам</p>
                                <p class="no-data">Пройдите тесты по разным предметам</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Последние результаты -->
                <div class="section">
                    <div class="section-header">
                        <h2><i class="fas fa-history"></i> Последние результаты</h2>
                        <a href="results.php" class="view-all">
                            Вся история <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                    
                    <table class="results-table">
                        <thead>
                            <tr>
                                <th>Тест</th>
                                <th>Предмет</th>
                                <th>Результат</th>
                                <th>Статус</th>
                                <th>Дата</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($recent_results) > 0): ?>
                                <?php foreach ($recent_results as $result): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($result['title']); ?></td>
                                        <td><?php echo htmlspecialchars($result['subject']); ?></td>
                                        <td class="score-cell <?php 
                                            echo $result['percentage'] >= 80 ? 'score-excellent' : 
                                                 ($result['percentage'] >= 60 ? 'score-good' : 'score-poor'); 
                                        ?>">
                                            <?php echo $result['score']; ?>/<?php echo $result['total_points']; ?> 
                                            (<?php echo round($result['percentage'], 1); ?>%)
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo $result['passed'] ? 'status-passed' : 'status-failed'; ?>">
                                                <?php echo $result['passed'] ? 'Сдан' : 'Не сдан'; ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('d.m.Y H:i', strtotime($result['completed_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; padding: 40px;">
                                        <div class="empty-state">
                                            <i class="fas fa-clipboard-list"></i>
                                            <p>Нет данных о результатах тестов</p>
                                            <a href="tests.php" class="back-button" style="margin-top: 15px;">
                                                <i class="fas fa-play"></i> Пройти первый тест
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Боковая панель -->
            <div class="sidebar">
                <!-- Распределение результатов -->
                <div class="section">
                    <div class="section-header">
                        <h2><i class="fas fa-chart-pie"></i> Распределение</h2>
                    </div>
                    <div class="chart-container">
                        <?php if ($overall_stats['total_tests'] > 0): ?>
                            <canvas id="distributionChart"></canvas>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-chart-pie"></i>
                                <p>Нет данных для диаграммы</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Рекомендации -->
                <div class="section">
                    <div class="section-header">
                        <h2><i class="fas fa-lightbulb"></i> Рекомендации</h2>
                    </div>
                    <div class="recommendations-list">
                        <?php foreach ($recommendations as $rec): ?>
                            <div class="recommendation-item <?php echo $rec['type']; ?>">
                                <div class="recommendation-title"><?php echo htmlspecialchars($rec['title']); ?></div>
                                <div class="recommendation-message"><?php echo htmlspecialchars($rec['message']); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Качество результатов -->
                <div class="section">
                    <div class="section-header">
                        <h2><i class="fas fa-star"></i> Качество результатов</h2>
                    </div>
                    <div class="progress-bars">
                        <?php if ($overall_stats['total_tests'] > 0): ?>
                            <div class="progress-item">
                                <div class="progress-header">
                                    <span class="progress-label">Отличные (80-100%)</span>
                                    <span class="progress-value"><?php echo $overall_stats['excellent_tests']; ?></span>
                                </div>
                                <div class="progress-bar">
                                    <div class="progress-fill excellent" style="width: <?php echo ($overall_stats['excellent_tests'] / $overall_stats['total_tests']) * 100; ?>%"></div>
                                </div>
                            </div>
                            <div class="progress-item">
                                <div class="progress-header">
                                    <span class="progress-label">Хорошие (60-79%)</span>
                                    <span class="progress-value"><?php echo $overall_stats['good_tests']; ?></span>
                                </div>
                                <div class="progress-bar">
                                    <div class="progress-fill good" style="width: <?php echo ($overall_stats['good_tests'] / $overall_stats['total_tests']) * 100; ?>%"></div>
                                </div>
                            </div>
                            <div class="progress-item">
                                <div class="progress-header">
                                    <span class="progress-label">Слабые (0-59%)</span>
                                    <span class="progress-value"><?php echo $overall_stats['poor_tests']; ?></span>
                                </div>
                                <div class="progress-bar">
                                    <div class="progress-fill poor" style="width: <?php echo ($overall_stats['poor_tests'] / $overall_stats['total_tests']) * 100; ?>%"></div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-star"></i>
                                <p>Нет данных о качестве</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            <?php if (count($weekly_progress) > 0): ?>
            // График прогресса по неделям
            const progressCtx = document.getElementById('progressChart').getContext('2d');
            const progressChart = new Chart(progressCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode(array_map(function($week) { 
                        return $week['week_start']; 
                    }, $weekly_progress)); ?>,
                    datasets: [{
                        label: 'Средний результат (%)',
                        data: <?php echo json_encode(array_map(function($week) { 
                            return round($week['avg_score'], 1); 
                        }, $weekly_progress)); ?>,
                        borderColor: '#3498db',
                        backgroundColor: 'rgba(52, 152, 219, 0.1)',
                        tension: 0.4,
                        fill: true,
                        borderWidth: 3
                    }, {
                        label: 'Пройдено тестов',
                        data: <?php echo json_encode(array_map(function($week) { 
                            return $week['tests_count']; 
                        }, $weekly_progress)); ?>,
                        borderColor: '#2ecc71',
                        backgroundColor: 'rgba(46, 204, 113, 0.1)',
                        tension: 0.4,
                        fill: false,
                        borderWidth: 2,
                        yAxisID: 'y1'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false,
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100,
                            title: {
                                display: true,
                                text: 'Результат (%)'
                            },
                            ticks: {
                                callback: function(value) {
                                    return value + '%';
                                }
                            }
                        },
                        y1: {
                            position: 'right',
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Количество тестов'
                            },
                            grid: {
                                drawOnChartArea: false
                            }
                        }
                    }
                }
            });
            <?php endif; ?>

            <?php if ($overall_stats['total_tests'] > 0): ?>
            // Круговая диаграмма распределения результатов
            const distributionCtx = document.getElementById('distributionChart').getContext('2d');
            const distributionChart = new Chart(distributionCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Отличные', 'Хорошие', 'Слабые'],
                    datasets: [{
                        data: [
                            <?php echo $overall_stats['excellent_tests']; ?>,
                            <?php echo $overall_stats['good_tests']; ?>,
                            <?php echo $overall_stats['poor_tests']; ?>
                        ],
                        backgroundColor: [
                            '#2ecc71',
                            '#f39c12',
                            '#e74c3c'
                        ],
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
                        }
                    },
                    cutout: '70%'
                }
            });
            <?php endif; ?>

            // Анимация прогресс-баров
            document.querySelectorAll('.progress-fill').forEach(bar => {
                const width = bar.style.width;
                bar.style.width = '0';
                setTimeout(() => {
                    bar.style.width = width;
                }, 500);
            });
        });
    </script>
</body>
</html>