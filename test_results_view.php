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

// Получаем ID теста
$test_id = isset($_GET['test_id']) ? intval($_GET['test_id']) : 0;

// Если учитель или админ - показываем все результаты, иначе только свои
if ($user['role'] == 'student') {
    $results_condition = " AND tr.user_id = ?";
    $params = [$test_id, $_SESSION['user_id']];
} else {
    $results_condition = "";
    $params = [$test_id];
}

// Получаем информацию о тесте
$stmt = $pdo->prepare("SELECT * FROM tests WHERE id = ?");
$stmt->execute([$test_id]);
$test = $stmt->fetch();

if (!$test) {
    header("Location: tests.php");
    exit;
}

// Получаем результаты теста
$sql = "SELECT 
            tr.*, 
            u.full_name, 
            u.email,
            ROUND((tr.score / tr.total_points * 100), 1) as percentage,
            CASE 
                WHEN (tr.score / tr.total_points * 100) >= 85 THEN 'excellent'
                WHEN (tr.score / tr.total_points * 100) >= 70 THEN 'good'
                WHEN (tr.score / tr.total_points * 100) >= 50 THEN 'average'
                ELSE 'poor'
            END as performance
        FROM test_results tr 
        JOIN users u ON tr.user_id = u.id 
        WHERE tr.test_id = ? $results_condition 
        ORDER BY tr.completed_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$results = $stmt->fetchAll();

// Получаем статистику
$stats_sql = "SELECT 
                COUNT(*) as total_attempts,
                AVG(tr.score) as avg_score,
                MAX(tr.score) as max_score,
                MIN(tr.score) as min_score,
                AVG(tr.time_spent) as avg_time
            FROM test_results tr 
            WHERE tr.test_id = ? $results_condition";

$stmt = $pdo->prepare($stats_sql);
$stmt->execute($params);
$stats = $stmt->fetch();

// Обработка фильтров
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';

if ($filter != 'all') {
    $results = array_filter($results, function($result) use ($filter) {
        return $result['performance'] == $filter;
    });
}

// Обработка поиска
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
if (!empty($search)) {
    $results = array_filter($results, function($result) use ($search) {
        return stripos($result['full_name'], $search) !== false || 
               stripos($result['email'], $search) !== false;
    });
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Результаты теста - <?php echo htmlspecialchars($test['title']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #6366f1;
            --primary-hover: #4f46e5;
            --secondary: #64748b;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --light: #f8fafc;
            --dark: #1e293b;
            --border: #e2e8f0;
            --card-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            --card-shadow-hover: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --radius: 12px;
            --transition: all 0.2s ease-in-out;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
            color: var(--dark);
            line-height: 1.6;
            min-height: 100vh;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        /* Header Styles */
        .header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-hover) 100%);
            color: white;
            padding: 2rem;
            border-radius: var(--radius);
            margin-bottom: 2rem;
            box-shadow: var(--card-shadow);
        }

        .header h1 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .nav {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .nav-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: white;
            text-decoration: none;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.15);
            transition: var(--transition);
            font-weight: 500;
        }

        .nav-link:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: translateY(-1px);
        }

        /* Card Styles */
        .card {
            background: white;
            border-radius: var(--radius);
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--card-shadow);
            border: 1px solid var(--border);
        }

        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--light);
        }

        .card-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: linear-gradient(135deg, var(--light) 0%, #ffffff 100%);
            padding: 1.5rem;
            border-radius: var(--radius);
            border-left: 4px solid var(--primary);
            box-shadow: var(--card-shadow);
        }

        .stat-card.excellent { border-left-color: var(--success); }
        .stat-card.good { border-left-color: var(--info); }
        .stat-card.average { border-left-color: var(--warning); }
        .stat-card.poor { border-left-color: var(--danger); }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .stat-label {
            font-size: 0.875rem;
            color: var(--secondary);
            text-transform: uppercase;
            font-weight: 600;
        }

        .stat-description {
            color: var(--secondary);
            margin-top: 0.5rem;
            font-size: 0.9rem;
        }

        /* Filters */
        .filters {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            align-items: center;
        }

        .filter-btn {
            padding: 0.75rem 1.5rem;
            border: 2px solid var(--border);
            border-radius: 8px;
            background: white;
            cursor: pointer;
            transition: var(--transition);
            font-weight: 500;
        }

        .filter-btn:hover {
            border-color: var(--primary);
        }

        .filter-btn.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .search-box {
            flex: 1;
            max-width: 300px;
            position: relative;
        }

        .search-input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 3rem;
            border: 2px solid var(--border);
            border-radius: 8px;
            font-size: 1rem;
            transition: var(--transition);
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary);
        }

        .search-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--secondary);
        }

        /* Results Table */
        .results-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: var(--radius);
            overflow: hidden;
            box-shadow: var(--card-shadow);
        }

        .results-table th,
        .results-table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }

        .results-table th {
            background: var(--light);
            font-weight: 600;
            color: var(--dark);
            cursor: pointer;
            transition: var(--transition);
        }

        .results-table th:hover {
            background: var(--border);
        }

        .results-table tr:hover {
            background: #f8fafc;
        }

        .results-table tr:last-child td {
            border-bottom: none;
        }

        /* Progress Bar */
        .progress-bar {
            width: 100%;
            height: 8px;
            background: var(--light);
            border-radius: 4px;
            overflow: hidden;
            margin: 0.5rem 0;
        }

        .progress-fill {
            height: 100%;
            border-radius: 4px;
            transition: var(--transition);
        }

        .progress-fill.excellent { background: var(--success); }
        .progress-fill.good { background: var(--info); }
        .progress-fill.average { background: var(--warning); }
        .progress-fill.poor { background: var(--danger); }

        /* Badges */
        .badge {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
            display: inline-block;
        }

        .badge-excellent {
            background: #ecfdf5;
            color: var(--success);
            border: 1px solid #d1fae5;
        }

        .badge-good {
            background: #eff6ff;
            color: var(--info);
            border: 1px solid #dbeafe;
        }

        .badge-average {
            background: #fffbeb;
            color: var(--warning);
            border: 1px solid #fef3c7;
        }

        .badge-poor {
            background: #fef2f2;
            color: var(--danger);
            border: 1px solid #fecaca;
        }

        /* Button Styles */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: var(--transition;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-hover);
            transform: translateY(-1px);
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--secondary);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: var(--border);
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

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .filters {
                flex-direction: column;
                align-items: stretch;
            }

            .search-box {
                max-width: none;
            }

            .results-table {
                display: block;
                overflow-x: auto;
            }

            .nav {
                flex-direction: column;
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

        .result-row {
            animation: fadeIn 0.3s ease-out;
        }
    </style>
</head>
<body>
    <div class="container">
        <header class="header">
            <h1>
                <i class="fas fa-chart-line"></i>
                Результаты теста: <?php echo htmlspecialchars($test['title']); ?>
            </h1>
            <nav class="nav">
                <a href="tests.php" class="nav-link">
                    <i class="fas fa-arrow-left"></i>
                    Назад к тестам
                </a>
                <a href="test_edit.php?id=<?php echo $test_id; ?>" class="nav-link">
                    <i class="fas fa-edit"></i>
                    Редактировать тест
                </a>
                <a href="logout.php" class="nav-link">
                    <i class="fas fa-sign-out-alt"></i>
                    Выйти
                </a>
            </nav>
        </header>

        <!-- Статистика -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['total_attempts'] ?? 0; ?></div>
                <div class="stat-label">Всего попыток</div>
                <div class="stat-description">Общее количество завершенных тестов</div>
            </div>

            <div class="stat-card">
                <div class="stat-number"><?php echo round($stats['avg_score'] ?? 0, 1); ?></div>
                <div class="stat-label">Средний балл</div>
                <div class="stat-description">Из <?php echo $test['time_limit']; ?> возможных</div>
            </div>

            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['max_score'] ?? 0; ?></div>
                <div class="stat-label">Максимальный балл</div>
                <div class="stat-description">Лучший результат</div>
            </div>

            <div class="stat-card">
                <div class="stat-number"><?php echo round(($stats['avg_time'] ?? 0) / 60, 1); ?>м</div>
                <div class="stat-label">Среднее время</div>
                <div class="stat-description">Затрачено на тест</div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <i class="fas fa-list-check"></i>
                    Детализация результатов
                </h2>
                <span class="badge <?php echo count($results) > 0 ? 'badge-excellent' : 'badge-secondary'; ?>">
                    <?php echo count($results); ?> записей
                </span>
            </div>

            <!-- Фильтры и поиск -->
            <div class="filters">
                <div class="filter-group">
                    <button class="filter-btn <?php echo $filter == 'all' ? 'active' : ''; ?>" onclick="setFilter('all')">
                        Все результаты
                    </button>
                    <button class="filter-btn <?php echo $filter == 'excellent' ? 'active' : ''; ?>" onclick="setFilter('excellent')">
                        Отлично (85%+)
                    </button>
                    <button class="filter-btn <?php echo $filter == 'good' ? 'active' : ''; ?>" onclick="setFilter('good')">
                        Хорошо (70%+)
                    </button>
                    <button class="filter-btn <?php echo $filter == 'average' ? 'active' : ''; ?>" onclick="setFilter('average')">
                        Удовлетворительно (50%+)
                    </button>
                    <button class="filter-btn <?php echo $filter == 'poor' ? 'active' : ''; ?>" onclick="setFilter('poor')">
                        Неудовлетворительно
                    </button>
                </div>

                <div class="search-box">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" class="search-input" placeholder="Поиск по имени или email..." 
                           value="<?php echo htmlspecialchars($search); ?>" oninput="handleSearch(this.value)">
                </div>
            </div>

            <?php if (empty($results)): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <h3>Результаты не найдены</h3>
                    <p>Пока нет завершенных попыток этого теста</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="results-table">
                        <thead>
                            <tr>
                                <th>Студент</th>
                                <th>Email</th>
                                <th>Баллы</th>
                                <th>Процент</th>
                                <th>Время</th>
                                <th>Дата завершения</th>
                                <th>Статус</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results as $result): ?>
                                <tr class="result-row">
                                    <td>
                                        <strong><?php echo htmlspecialchars($result['full_name']); ?></strong>
                                    </td>
                                    <td><?php echo htmlspecialchars($result['email']); ?></td>
                                    <td>
                                        <?php echo $result['score']; ?> / <?php echo $result['total_points']; ?>
                                    </td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                                            <span><?php echo $result['percentage']; ?>%</span>
                                            <div class="progress-bar">
                                                <div class="progress-fill <?php echo $result['performance']; ?>" 
                                                     style="width: <?php echo min($result['percentage'], 100); ?>%"></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?php echo round($result['time_spent'] / 60, 1); ?> мин</td>
                                    <td><?php echo date('d.m.Y H:i', strtotime($result['completed_at'])); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo $result['performance']; ?>">
                                            <?php switch($result['performance']):
                                                case 'excellent': echo 'Отлично'; break;
                                                case 'good': echo 'Хорошо'; break;
                                                case 'average': echo 'Удовлетворительно'; break;
                                                case 'poor': echo 'Неудовлетворительно'; break;
                                            endswitch; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="test_result_detail.php?result_id=<?php echo $result['id']; ?>" class="btn btn-primary btn-sm">
                                            <i class="fas fa-eye"></i>
                                            Подробнее
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    function setFilter(filter) {
        const url = new URL(window.location);
        url.searchParams.set('filter', filter);
        window.location.href = url.toString();
    }

    function handleSearch(value) {
        // Debounce поиска
        clearTimeout(window.searchTimeout);
        window.searchTimeout = setTimeout(() => {
            const url = new URL(window.location);
            if (value) {
                url.searchParams.set('search', value);
            } else {
                url.searchParams.delete('search');
            }
            window.location.href = url.toString();
        }, 500);
    }

    // Сортировка таблицы
    document.addEventListener('DOMContentLoaded', function() {
        const headers = document.querySelectorAll('.results-table th');
        headers.forEach((header, index) => {
            header.addEventListener('click', () => {
                // Реализация сортировки может быть добавлена здесь
                console.log('Sort by column:', index);
            });
        });
    });
    </script>
</body>
</html>