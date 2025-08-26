<?php
session_start();
require_once 'config/database.php';

// Проверка авторизации
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Получение данных пользователя
$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("
    SELECT u.*, 
           CONCAT(u.first_name, ' ', u.last_name) as full_name 
    FROM users u 
    WHERE u.id = ?
");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Получение компетенций с детальными оценками
$stmt = $pdo->prepare("
    SELECT c.*, 
           cr.id as criteria_id,
           cr.criteria_text,
           cr.weight,
           dr.self_rating,
           dr.manager_rating,
           dr.colleague_rating,
           dr.subordinate_rating,
           dr.rating_date
    FROM competencies c
    LEFT JOIN competency_criteria cr ON c.id = cr.competency_id
    LEFT JOIN detailed_ratings dr ON cr.id = dr.criteria_id AND dr.user_id = ?
    ORDER BY c.id, cr.id
");
$stmt->execute([$user_id]);
$competenciesData = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Группируем данные по компетенциям
$competencies = [];
foreach ($competenciesData as $row) {
    $compId = $row['id'];
    if (!isset($competencies[$compId])) {
        $competencies[$compId] = [
            'id' => $row['id'],
            'name' => $row['name'],
            'description' => $row['description'],
            'criteria' => []
        ];
    }
    
    if ($row['criteria_id']) {
        $competencies[$compId]['criteria'][] = [
            'id' => $row['criteria_id'],
            'text' => $row['criteria_text'],
            'weight' => $row['weight'],
            'self_rating' => $row['self_rating'],
            'manager_rating' => $row['manager_rating'],
            'colleague_rating' => $row['colleague_rating'],
            'subordinate_rating' => $row['subordinate_rating']
        ];
    }
}

// Рассчитываем общие итоги
$competencyStats = [];
foreach ($competencies as &$comp) {
    $total_self = $total_manager = $total_colleague = $total_subordinate = 0;
    $count = count($comp['criteria']);
    
    foreach ($comp['criteria'] as $criteria) {
        $total_self += $criteria['self_rating'] ?? 0;
        $total_manager += $criteria['manager_rating'] ?? 0;
        $total_colleague += $criteria['colleague_rating'] ?? 0;
        $total_subordinate += $criteria['subordinate_rating'] ?? 0;
    }
    
    $comp['totals'] = [
        'self' => $count > 0 ? $total_self / $count : 0,
        'manager' => $count > 0 ? $total_manager / $count : 0,
        'colleague' => $count > 0 ? $total_colleague / $count : 0,
        'subordinate' => $count > 0 ? $total_subordinate / $count : 0,
        'general' => $count > 0 ? ($total_self + $total_manager + $total_colleague + $total_subordinate) / ($count * 4) : 0
    ];
    
    $competencyStats[] = $comp['totals']['general'];
}

// Данные для графика
$chartLabels = ['Межфункц.', 'Коммуник.', 'Лидерство', 'Тех. эксп.', 'Клиентор.'];
$chartData = [3.8, 4.2, 3.5, 4.5, 4.0];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Оценки 360° - Дашборд</title>
    <link rel="stylesheet" href="css/dashboard.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <!-- Боковое меню -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <nav class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="user-sidebar-info">
                <img src="<?= htmlspecialchars($user['photo_url'] ?? 'images/avatar.jpg') ?>" 
                     alt="Фото" class="user-avatar-sidebar">
                <div class="user-sidebar-details">
                    <h4><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></h4>
                    <p><?= htmlspecialchars($user['position']) ?></p>
                </div>
            </div>
            <button class="close-menu" onclick="closeSidebar()">×</button>
        </div>
        <ul class="sidebar-menu">
            <li><a href="#" class="active">● Главная</a></li>
            <li><a href="#">● Мои оценки</a></li>
            <li><a href="#">● Компетенции</a></li>
            <li><a href="#">● Отчеты</a></li>
            <li><a href="#">● Настройки</a></li>
            <li><a href="logout.php" class="logout-btn">● Выход</a></li>
        </ul>
        
        <!-- Статистика в меню -->
        <div class="sidebar-stats">
            <div class="stat-item">
                <span class="stat-value"><?= number_format(array_sum($competencyStats) / count($competencyStats), 1) ?></span>
                <span class="stat-label">Средний балл</span>
            </div>
            <div class="stat-item">
                <span class="stat-value"><?= count($competencies) ?></span>
                <span class="stat-label">Компетенций</span>
            </div>
        </div>
    </nav>

    <!-- Основной контент -->
    <div class="main-content">
        <!-- Хедер -->
        <header class="top-header">
            <button class="menu-toggle" onclick="toggleSidebar()">
                <span class="menu-icon">≡</span> Меню
            </button>
            <div class="header-title">
                <h1>Оценки 360 градусов</h1>
                <p>в разрезе ролей</p>
            </div>
            <div class="header-user">
                <span><?= htmlspecialchars($user['first_name']) ?></span>
                <img src="<?= htmlspecialchars($user['photo_url'] ?? 'images/avatar.jpg') ?>" 
                     alt="Фото" class="user-avatar-header">
            </div>
        </header>

        <!-- Основная сетка -->
        <div class="dashboard-grid">
            <!-- Информация о сотруднике -->
            <div class="user-card">
                <div class="user-info-main">
                    <img src="<?= htmlspecialchars($user['photo_url'] ?? 'images/avatar.jpg') ?>" 
                         alt="Фото" class="user-avatar-main">
                    <div class="user-details-main">
                        <h2><?= htmlspecialchars($user['full_name']) ?></h2>
                        <div class="user-meta">
                            <span class="user-department"><?= htmlspecialchars($user['department']) ?></span>
                            <span class="user-position"><?= htmlspecialchars($user['position']) ?></span>
                        </div>
                    </div>
                </div>
                <div class="user-stats">
                    <div class="stat-badge">
                        <span class="stat-number"><?= number_format(array_sum($competencyStats) / count($competencyStats), 1) ?></span>
                        <span class="stat-text">Общий рейтинг</span>
                    </div>
                </div>
            </div>

            <!-- График компетенций -->
            <div class="chart-section">
                <div class="chart-card">
                    <div class="chart-header">
                        <h3>Уровень развития компетенций</h3>
                        <div class="chart-legend">
                            <span class="legend-item">● Текущие</span>
                            <span class="legend-item">-- Целевые</span>
                        </div>
                    </div>
                    <div class="chart-container">
                        <canvas id="competencyChart" width="400" height="250"></canvas>
                    </div>
                </div>
            </div>

            <!-- Компетенции -->
            <div class="competencies-section">
                <div class="section-header">
                    <h2>Оценка компетенций</h2>
                    <span class="section-badge"><?= count($competencies) ?> компетенций</span>
                </div>
                
                <?php foreach ($competencies as $comp): ?>
                <div class="competency-card compact">
                    <div class="competency-header">
                        <div class="competency-info">
                            <h3><?= htmlspecialchars($comp['name']) ?></h3>
                            <p class="competency-desc"><?= htmlspecialchars($comp['description']) ?></p>
                        </div>
                        <div class="competency-totals">
                            <span class="total-score"><?= number_format($comp['totals']['general'], 1) ?></span>
                            <span class="total-label">Общий итог</span>
                            <div class="progress-circle" data-value="<?= $comp['totals']['general'] ?>"></div>
                        </div>
                    </div>

                    <div class="ratings-summary">
                        <div class="rating-item">
                            <span class="rating-value"><?= number_format($comp['totals']['self'], 1) ?></span>
                            <span class="rating-label">Самооценка</span>
                        </div>
                        <div class="rating-item">
                            <span class="rating-value"><?= number_format($comp['totals']['manager'], 1) ?></span>
                            <span class="rating-label">Руководитель</span>
                        </div>
                        <div class="rating-item">
                            <span class="rating-value"><?= number_format($comp['totals']['colleague'], 1) ?></span>
                            <span class="rating-label">Коллеги</span>
                        </div>
                        <div class="rating-item">
                            <span class="rating-value"><?= number_format($comp['totals']['subordinate'], 1) ?></span>
                            <span class="rating-label">Подчиненные</span>
                        </div>
                    </div>

                    <button class="toggle-criteria" onclick="toggleCriteria(this)">
                        <span class="toggle-icon">▼</span>
                        Критерии оценки
                    </button>

                    <div class="criteria-table" style="display: none;">
                        <table>
                            <thead>
                                <tr>
                                    <th>Критерии оценки</th>
                                    <th width="60">С</th>
                                    <th width="60">К</th>
                                    <th width="60">П</th>
                                    <th width="60">Р</th>
                                    <th width="60">Общ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($comp['criteria'] as $criteria): ?>
                                <?php 
                                $general = ($criteria['self_rating'] + $criteria['colleague_rating'] + 
                                          $criteria['subordinate_rating'] + $criteria['manager_rating']) / 4;
                                ?>
                                <tr>
                                    <td class="criteria-text"><?= htmlspecialchars($criteria['text']) ?></td>
                                    <td class="rating-cell"><?= number_format($criteria['self_rating'], 1) ?></td>
                                    <td class="rating-cell"><?= number_format($criteria['colleague_rating'], 1) ?></td>
                                    <td class="rating-cell"><?= number_format($criteria['subordinate_rating'], 1) ?></td>
                                    <td class="rating-cell"><?= number_format($criteria['manager_rating'], 1) ?></td>
                                    <td class="rating-cell total"><?= number_format($general, 1) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <script>
        // Управление боковым меню
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('active');
            document.getElementById('sidebarOverlay').classList.toggle('active');
            document.body.classList.toggle('menu-open');
        }

        function closeSidebar() {
            document.getElementById('sidebar').classList.remove('active');
            document.getElementById('sidebarOverlay').classList.remove('active');
            document.body.classList.remove('menu-open');
        }

        // Закрытие меню при клике на оверлей
        document.getElementById('sidebarOverlay').addEventListener('click', closeSidebar);

        // Переключение отображения критериев
        function toggleCriteria(button) {
            const criteriaTable = button.nextElementSibling;
            const isVisible = criteriaTable.style.display === 'table';
            
            criteriaTable.style.display = isVisible ? 'none' : 'table';
            button.innerHTML = isVisible ? 
                '<span class="toggle-icon">▼</span> Критерии оценки' : 
                '<span class="toggle-icon">▲</span> Скрыть критерии';
        }

        // Создание кругов прогресса
        function createProgressCircles() {
            document.querySelectorAll('.progress-circle').forEach(circle => {
                const value = parseFloat(circle.getAttribute('data-value'));
                const percentage = (value / 5) * 100;
                circle.innerHTML = `
                    <svg width="40" height="40" viewBox="0 0 40 40">
                        <circle cx="20" cy="20" r="18" stroke="#e6e6e6" stroke-width="3" fill="none"/>
                        <circle cx="20" cy="20" r="18" stroke="#5cb85c" stroke-width="3" fill="none"
                                stroke-dasharray="${2 * Math.PI * 18}" 
                                stroke-dashoffset="${2 * Math.PI * 18 * (1 - percentage / 100)}"
                                transform="rotate(-90 20 20)"/>
                    </svg>
                    <span class="progress-text">${value.toFixed(1)}</span>
                `;
            });
        }

        // График компетенций
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('competencyChart').getContext('2d');
            new Chart(ctx, {
                type: 'radar',
                data: {
                    labels: <?= json_encode($chartLabels) ?>,
                    datasets: [{
                        label: 'Текущий уровень',
                        data: <?= json_encode($chartData) ?>,
                        backgroundColor: 'rgba(92, 184, 92, 0.2)',
                        borderColor: 'rgba(92, 184, 92, 1)',
                        borderWidth: 2,
                        pointBackgroundColor: 'rgba(92, 184, 92, 1)',
                        pointBorderColor: '#fff',
                        pointHoverBackgroundColor: '#fff',
                        pointHoverBorderColor: 'rgba(92, 184, 92, 1)'
                    }, {
                        label: 'Целевой уровень',
                        data: [4.5, 4.5, 4.0, 4.8, 4.7],
                        backgroundColor: 'rgba(91, 192, 222, 0.1)',
                        borderColor: 'rgba(91, 192, 222, 0.6)',
                        borderWidth: 2,
                        borderDash: [5, 5],
                        pointBackgroundColor: 'rgba(91, 192, 222, 0.6)',
                        pointBorderColor: '#fff'
                    }]
                },
                options: {
                    scales: {
                        r: {
                            angleLines: { color: 'rgba(0, 0, 0, 0.1)' },
                            grid: { color: 'rgba(0, 0, 0, 0.1)' },
                            pointLabels: { 
                                font: { 
                                    size: 11,
                                    weight: '600'
                                }
                            },
                            suggestedMin: 0,
                            suggestedMax: 5,
                            ticks: { 
                                stepSize: 1,
                                font: { size: 10 }
                            }
                        }
                    },
                    plugins: {
                        legend: { 
                            position: 'bottom',
                            labels: {
                                font: { size: 12 }
                            }
                        }
                    },
                    maintainAspectRatio: false,
                    animation: {
                        duration: 2000,
                        easing: 'easeOutQuart'
                    }
                }
            });

            createProgressCircles();
        });
    </script>
</body>
</html>