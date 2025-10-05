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

// Получаем статистику по предметам для карты навыков
$stmt = $pdo->prepare("
    SELECT 
        t.subject,
        COUNT(*) as tests_count,
        AVG(tr.percentage) as avg_score,
        MAX(tr.percentage) as best_score,
        MIN(tr.percentage) as worst_score,
        COUNT(CASE WHEN tr.passed = 1 THEN 1 END) as passed_tests,
        SUM(tr.score) as total_score,
        SUM(tr.total_points) as total_points,
        COUNT(DISTINCT t.id) as unique_tests
    FROM test_results tr
    JOIN tests t ON tr.test_id = t.id
    WHERE tr.user_id = ?
    GROUP BY t.subject
    ORDER BY avg_score DESC
");
$stmt->execute([$_SESSION['user_id']]);
$subject_stats = $stmt->fetchAll();

// Получаем детальную статистику по типам вопросов
$stmt = $pdo->prepare("
    SELECT 
        t.subject,
        q.question_type,
        COUNT(*) as questions_count,
        AVG(ua.is_correct) as accuracy,
        COUNT(CASE WHEN ua.is_correct = 1 THEN 1 END) as correct_answers,
        COUNT(CASE WHEN ua.is_correct = 0 THEN 1 END) as wrong_answers
    FROM user_answers ua
    JOIN questions q ON ua.question_id = q.id
    JOIN tests t ON q.test_id = t.id
    JOIN test_results tr ON ua.result_id = tr.id
    WHERE tr.user_id = ?
    GROUP BY t.subject, q.question_type
    ORDER BY t.subject, accuracy DESC
");
$stmt->execute([$_SESSION['user_id']]);
$question_type_stats = $stmt->fetchAll();

// Группируем типы вопросов по предметам
$question_types_by_subject = [];
foreach ($question_type_stats as $type) {
    $subject = $type['subject'];
    if (!isset($question_types_by_subject[$subject])) {
        $question_types_by_subject[$subject] = [];
    }
    $question_types_by_subject[$subject][] = $type;
}

// Определяем уровни навыков на основе процентов
function getSkillLevel($percentage) {
    if ($percentage >= 90) return ['level' => 'Эксперт', 'color' => '#2ecc71', 'class' => 'expert'];
    if ($percentage >= 80) return ['level' => 'Продвинутый', 'color' => '#27ae60', 'class' => 'advanced'];
    if ($percentage >= 70) return ['level' => 'Средний', 'color' => '#f39c12', 'class' => 'intermediate'];
    if ($percentage >= 60) return ['level' => 'Начинающий', 'color' => '#e67e22', 'class' => 'beginner'];
    return ['level' => 'Новичок', 'color' => '#e74c3c', 'class' => 'novice'];
}

// Получаем рекомендации для улучшения
$recommendations = [];
foreach ($subject_stats as $subject) {
    $skill_level = getSkillLevel($subject['avg_score']);
    if ($subject['avg_score'] < 70) {
        $recommendations[] = [
            'subject' => $subject['subject'],
            'current_level' => $skill_level['level'],
            'message' => 'Рекомендуем уделить больше времени практике по этому предмету',
            'priority' => 'high'
        ];
    }
    
    // Добавляем рекомендации на основе типов вопросов
    if (isset($question_types_by_subject[$subject['subject']])) {
        foreach ($question_types_by_subject[$subject['subject']] as $type) {
            if ($type['accuracy'] < 0.6) {
                $type_name = getQuestionTypeName($type['question_type']);
                $recommendations[] = [
                    'subject' => $subject['subject'],
                    'current_level' => $skill_level['level'],
                    'message' => "Слабые результаты в вопросах типа '$type_name' (" . round($type['accuracy'] * 100, 0) . "%)",
                    'priority' => 'medium'
                ];
            }
        }
    }
}

// Функция для получения читаемого названия типа вопроса
function getQuestionTypeName($type) {
    $types = [
        'single' => 'одиночный выбор',
        'multiple' => 'множественный выбор',
        'text' => 'текстовый ответ',
        'code' => 'программный код'
    ];
    return $types[$type] ?? $type;
}

// Сортируем предметы по уровню навыков
$sorted_subjects = [];
foreach ($subject_stats as $subject) {
    $skill_level = getSkillLevel($subject['avg_score']);
    $subject['skill_level'] = $skill_level;
    $sorted_subjects[] = $subject;
}

// Сортируем по убыванию уровня навыков
usort($sorted_subjects, function($a, $b) {
    return $b['avg_score'] <=> $a['avg_score'];
});

// Получаем уведомления
$stmt = $pdo->prepare("
    SELECT * FROM notifications 
    WHERE user_id = ? AND (expires_at IS NULL OR expires_at > NOW())
    ORDER BY created_at DESC
    LIMIT 5
");
$stmt->execute([$_SESSION['user_id']]);
$notifications = $stmt->fetchAll();

// Помечаем уведомления как прочитанные
if (!empty($notifications)) {
    $stmt = $pdo->prepare("
        UPDATE notifications SET is_read = 1 
        WHERE user_id = ? AND is_read = 0
    ");
    $stmt->execute([$_SESSION['user_id']]);
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Карта навыков - Система интеллектуальной оценки знаний</title>
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
            --sidebar-width: 280px;
        }
        
        body {
            background-color: #f0f2f5;
            color: #333;
            line-height: 1.6;
            display: flex;
            min-height: 100vh;
        }

        /* ===== СТИЛИ САЙДБАРА ===== */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            height: 100vh;
            width: var(--sidebar-width);
            background: linear-gradient(135deg, var(--secondary) 0%, #34495e 100%);
            color: white;
            overflow-y: auto;
            overflow-x: hidden;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            box-shadow: 2px 0 15px rgba(0, 0, 0, 0.1);
            scrollbar-width: none;
        }

        .sidebar::-webkit-scrollbar {
            width: 0px;
            background: transparent;
        }

        /* Логотип */
        .logo {
            padding: 25px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            text-align: center;
        }

        .logo h1 {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .logo h1 i {
            color: var(--primary);
        }

        .system-status {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-size: 12px;
            color: rgba(255, 255, 255, 0.7);
        }

        .status-indicator {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--success);
        }

        /* Информация о пользователе */
        .user-info {
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .user-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 18px;
            color: white;
            overflow: hidden;
        }

        .user-details h3 {
            font-size: 16px;
            margin-bottom: 4px;
        }

        .user-details p {
            font-size: 12px;
            color: rgba(255, 255, 255, 0.7);
            margin-bottom: 6px;
        }

        .role-badge {
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .role-admin {
            background: var(--accent);
        }

        .role-teacher {
            background: var(--warning);
        }

        .role-student {
            background: var(--primary);
        }

        /* Быстрая статистика */
        .quick-stats {
            padding: 15px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .stat-item {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            color: rgba(255, 255, 255, 0.8);
            margin-bottom: 8px;
        }

        .stat-item i {
            width: 16px;
            text-align: center;
        }

        /* Навигация */
        .nav-links {
            list-style: none;
            flex: 1;
            overflow-y: auto;
            scrollbar-width: none;
        }

        .nav-links::-webkit-scrollbar {
            display: none;
        }

        .nav-section {
            padding: 15px 20px 5px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            margin-bottom: 10px;
        }

        .section-label {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: rgba(255, 255, 255, 0.5);
        }

        .nav-links li a {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            transition: all 0.3s;
            border-left: 3px solid transparent;
        }

        .nav-links li a:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border-left-color: var(--primary);
        }

        .nav-links li a.active {
            background: rgba(255, 255, 255, 0.15);
            color: white;
            border-left-color: var(--primary);
        }

        .nav-links li a i {
            width: 20px;
            margin-right: 12px;
            font-size: 14px;
        }

        /* Футер сайдбара */
        .sidebar-footer {
            padding: 15px 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            margin-top: auto;
        }

        .system-info {
            margin-bottom: 15px;
        }

        .info-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: rgba(255, 255, 255, 0.7);
            margin-bottom: 5px;
        }

        .quick-actions {
            display: flex;
            gap: 10px;
        }

        .quick-btn {
            flex: 1;
            background: rgba(255, 255, 255, 0.1);
            border: none;
            color: white;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: background 0.3s;
        }

        .quick-btn:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        /* Кнопка выхода */
        .logout-btn {
            color: var(--accent) !important;
        }

        .logout-btn:hover {
            background: rgba(231, 76, 60, 0.1) !important;
        }

        /* Мобильное меню */
        .mobile-menu-toggle {
            display: none;
            position: fixed;
            top: 20px;
            left: 20px;
            background: var(--primary);
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 1001;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }

        /* ===== ОСНОВНОЙ КОНТЕНТ ===== */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            padding: 20px;
            transition: margin-left 0.3s;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
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

        .header-actions {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        
        .notification-bell {
            position: relative;
            background: white;
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            transition: all 0.3s;
        }
        
        .notification-bell:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
        }
        
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: var(--accent);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }
        
        .search-box {
            display: flex;
            align-items: center;
            background: white;
            padding: 12px 20px;
            border-radius: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            min-width: 300px;
        }
        
        .search-box input {
            border: none;
            outline: none;
            padding: 5px 10px;
            font-size: 15px;
            flex: 1;
            background: transparent;
        }
        
        .search-box i {
            color: var(--gray);
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

        /* Skill Map */
        .skill-map-section {
            margin-bottom: 30px;
        }
        
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

        /* Skill Cards */
        .skills-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
        }
        
        .skill-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
            transition: all 0.3s;
            border: 2px solid transparent;
            position: relative;
            overflow: hidden;
        }
        
        .skill-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.12);
        }
        
        .skill-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
        }
        
        .skill-card.expert::before { background: #2ecc71; }
        .skill-card.advanced::before { background: #27ae60; }
        .skill-card.intermediate::before { background: #f39c12; }
        .skill-card.beginner::before { background: #e67e22; }
        .skill-card.novice::before { background: #e74c3c; }
        
        .skill-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        
        .skill-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--secondary);
            margin-bottom: 5px;
        }
        
        .skill-level {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            color: white;
        }
        
        .skill-level.expert { background: #2ecc71; }
        .skill-level.advanced { background: #27ae60; }
        .skill-level.intermediate { background: #f39c12; }
        .skill-level.beginner { background: #e67e22; }
        .skill-level.novice { background: #e74c3c; }
        
        .skill-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 15px;
            text-align: center;
        }
        
        .stat-item-small {
            display: flex;
            flex-direction: column;
            gap: 5px;
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
        
        .progress-container {
            margin: 15px 0;
        }
        
        .progress-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 14px;
            color: var(--secondary);
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
        
        .skill-types {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #f0f0f0;
        }
        
        .types-title {
            font-size: 14px;
            font-weight: 600;
            color: var(--secondary);
            margin-bottom: 10px;
        }
        
        .types-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        
        .type-tag {
            padding: 4px 8px;
            background: var(--light);
            border-radius: 12px;
            font-size: 11px;
            color: var(--secondary);
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .type-accuracy {
            font-weight: 600;
            color: var(--primary);
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
        
        .recommendation-item.high {
            border-left-color: var(--danger);
        }
        
        .recommendation-item.medium {
            border-left-color: var(--warning);
        }
        
        .recommendation-item.low {
            border-left-color: var(--info);
        }
        
        .recommendation-title {
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--secondary);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .recommendation-message {
            color: var(--gray);
            font-size: 14px;
        }

        /* Legend */
        .legend {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-top: 20px;
            flex-wrap: wrap;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
        }
        
        .legend-color {
            width: 12px;
            height: 12px;
            border-radius: 2px;
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

        /* Адаптивность */
        @media (max-width: 992px) {
            .mobile-menu-toggle {
                display: flex;
            }
            
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s;
            }
            
            .sidebar.mobile-open {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }
            
            .skills-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .main-content {
                padding: 15px;
            }
            
            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .search-box {
                min-width: 100%;
            }
            
            .stats-overview {
                grid-template-columns: 1fr;
            }
            
            .header-actions {
                width: 100%;
                justify-content: space-between;
            }
            
            .skill-stats {
                grid-template-columns: 1fr;
                gap: 10px;
            }
            
            .legend {
                flex-direction: column;
                align-items: center;
                gap: 10px;
            }
        }
    </style>
</head>
<body>
    <!-- Мобильное меню -->
    <div class="mobile-menu-toggle" id="mobileMenuToggle">
        <i class="fas fa-bars"></i>
    </div>

    <!-- Сайдбар -->
    <div class="sidebar" id="sidebar">
        <div class="logo">
            <h1><i class="fas fa-graduation-cap"></i> EduAI System</h1>
            <div class="system-status">
                <div class="status-indicator online"></div>
                <span>Система активна</span>
            </div>
        </div>

        <div class="user-info">
            <div class="user-avatar">
                <?php 
                $firstName = $user['full_name'];
                if (function_exists('mb_convert_encoding')) {
                    $firstName = mb_convert_encoding($firstName, 'UTF-8', 'auto');
                }
                $firstLetter = mb_substr($firstName, 0, 1, 'UTF-8');
                echo htmlspecialchars(strtoupper($firstLetter));
                ?>
            </div>
            <div class="user-details">
                <h3><?php echo htmlspecialchars($user['full_name']); ?></h3>
                <p><?php echo htmlspecialchars($user['email']); ?></p>
                <span class="role-badge role-<?php echo $user['role']; ?>">
                    <?php echo $user['role'] == 'teacher' ? 'Преподаватель' : 
                           ($user['role'] == 'admin' ? 'Администратор' : 'Студент'); ?>
                </span>
            </div>
        </div>

        <div class="quick-stats">
            <div class="stat-item">
                <i class="fas fa-book"></i>
                <span>Предметов: <?php echo count($subject_stats); ?></span>
            </div>
            <div class="stat-item">
                <i class="fas fa-chart-line"></i>
                <span>Средний уровень: <?php echo count($subject_stats) > 0 ? round(array_sum(array_column($subject_stats, 'avg_score')) / count($subject_stats), 1) : 0; ?>%</span>
            </div>
            <div class="stat-item">
                <i class="fas fa-tasks"></i>
                <span>Типов вопросов: <?php echo count($question_type_stats); ?></span>
            </div>
        </div>

        <ul class="nav-links">
            <div class="nav-section">
                <div class="section-label">Основное</div>
            </div>
            <li><a href="dashboard.php"><i class="fas fa-home"></i> Главная</a></li>
            <li><a href="tests.php"><i class="fas fa-file-alt"></i> Тесты</a></li>
            <li><a href="results.php"><i class="fas fa-chart-bar"></i> Мои результаты</a></li>
            <li><a href="progress.php"><i class="fas fa-chart-line"></i> Мой прогресс</a></li>
            <li><a href="achievements.php"><i class="fas fa-trophy"></i> Мои достижения</a></li>
            <li><a href="skill_map.php" class="active"><i class="fas fa-map"></i> Карта навыков</a></li>
            
            <?php if ($user['role'] == 'teacher' || $user['role'] == 'admin'): ?>
            <div class="nav-section">
                <div class="section-label">Преподавание</div>
            </div>
            <li><a href="create_test.php"><i class="fas fa-plus-circle"></i> Создать тест</a></li>
            <li><a href="my_tests.php"><i class="fas fa-list"></i> Мои тесты</a></li>
            <li><a href="grading.php"><i class="fas fa-check-double"></i> Проверка работ</a></li>
            <?php endif; ?>

            <div class="nav-section">
                <div class="section-label">Аналитика</div>
            </div>
            <li><a href="analytics.php"><i class="fas fa-chart-line"></i> Статистика</a></li>

            <div class="nav-section">
                <div class="section-label">Система</div>
            </div>
            <li><a href="profile.php"><i class="fas fa-user-cog"></i> Профиль</a></li>
            <li><a href="settings.php"><i class="fas fa-cog"></i> Настройки</a></li>
            <li><a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Выход</a></li>
        </ul>

        <div class="sidebar-footer">
            <div class="system-info">
                <div class="info-item">
                    <i class="fas fa-database"></i>
                    <span>База данных: Активна</span>
                </div>
                <div class="info-item">
                    <i class="fas fa-robot"></i>
                    <span>AI Модели: Загружены</span>
                </div>
            </div>
            <div class="quick-actions">
                <button class="quick-btn" onclick="openHelp()">
                    <i class="fas fa-question-circle"></i> Помощь
                </button>
                <button class="quick-btn" onclick="openFeedback()">
                    <i class="fas fa-comment"></i> Отзыв
                </button>
            </div>
        </div>
    </div>

    <!-- Основной контент -->
    <div class="main-content">
        <div class="container">
            <!-- Хлебные крошки -->
            <a href="dashboard.php" class="back-button">
                <i class="fas fa-arrow-left"></i>
                Назад к панели
            </a>

            <div class="header">
                <h1><i class="fas fa-map"></i> Карта навыков</h1>
                <div class="header-actions">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="skillSearch" placeholder="Поиск навыков...">
                    </div>
                    <div class="notification-bell" onclick="toggleNotifications()">
                        <i class="fas fa-bell"></i>
                        <?php if (count($notifications) > 0): ?>
                            <span class="notification-badge"><?php echo count($notifications); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Общая статистика -->
            <div class="stats-overview">
                <div class="stat-card success">
                    <div class="stat-icon icon-success">
                        <i class="fas fa-book"></i>
                    </div>
                    <div class="stat-details">
                        <h3><?php echo count($subject_stats); ?></h3>
                        <p>Изучаемых предметов</p>
                        <div class="stat-trend">
                            <?php echo count($question_type_stats); ?> типов вопросов
                        </div>
                    </div>
                </div>
                
                <div class="stat-card info">
                    <div class="stat-icon icon-info">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-details">
                        <h3><?php echo count($subject_stats) > 0 ? round(array_sum(array_column($subject_stats, 'avg_score')) / count($subject_stats), 1) : 0; ?>%</h3>
                        <p>Средний уровень</p>
                        <div class="stat-trend">
                            Общая успеваемость
                        </div>
                    </div>
                </div>
                
                <div class="stat-card primary">
                    <div class="stat-icon icon-primary">
                        <i class="fas fa-trophy"></i>
                    </div>
                    <div class="stat-details">
                        <h3><?php echo count(array_filter($subject_stats, function($subject) { return $subject['avg_score'] >= 80; })); ?></h3>
                        <p>Сильных сторон</p>
                        <div class="stat-trend">
                            Уровень выше 80%
                        </div>
                    </div>
                </div>
                
                <div class="stat-card warning">
                    <div class="stat-icon icon-warning">
                        <i class="fas fa-bullseye"></i>
                    </div>
                    <div class="stat-details">
                        <h3><?php echo count(array_filter($subject_stats, function($subject) { return $subject['avg_score'] < 70; })); ?></h3>
                        <p>Областей роста</p>
                        <div class="stat-trend">
                            Требуют внимания
                        </div>
                    </div>
                </div>
            </div>

            <!-- Легенда уровней -->
            <div class="section">
                <div class="section-header">
                    <h2><i class="fas fa-info-circle"></i> Уровни навыков</h2>
                </div>
                <div class="legend">
                    <div class="legend-item">
                        <div class="legend-color" style="background: #2ecc71;"></div>
                        <span>Эксперт (90-100%)</span>
                    </div>
                    <div class="legend-item">
                        <div class="legend-color" style="background: #27ae60;"></div>
                        <span>Продвинутый (80-89%)</span>
                    </div>
                    <div class="legend-item">
                        <div class="legend-color" style="background: #f39c12;"></div>
                        <span>Средний (70-79%)</span>
                    </div>
                    <div class="legend-item">
                        <div class="legend-color" style="background: #e67e22;"></div>
                        <span>Начинающий (60-69%)</span>
                    </div>
                    <div class="legend-item">
                        <div class="legend-color" style="background: #e74c3c;"></div>
                        <span>Новичок (0-59%)</span>
                    </div>
                </div>
            </div>

            <!-- Карта навыков -->
            <div class="skill-map-section">
                <!-- Основные навыки -->
                <div class="section">
                    <div class="section-header">
                        <h2><i class="fas fa-star"></i> Основные навыки</h2>
                        <span><?php echo count($subject_stats); ?> предметов</span>
                    </div>
                    <div class="skills-grid">
                        <?php if (count($sorted_subjects) > 0): ?>
                            <?php foreach ($sorted_subjects as $subject): ?>
                                <div class="skill-card <?php echo $subject['skill_level']['class']; ?>">
                                    <div class="skill-header">
                                        <div>
                                            <div class="skill-title"><?php echo htmlspecialchars($subject['subject']); ?></div>
                                            <div class="skill-description">Навыки и компетенции</div>
                                        </div>
                                        <div class="skill-level <?php echo $subject['skill_level']['class']; ?>">
                                            <?php echo $subject['skill_level']['level']; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="skill-stats">
                                        <div class="stat-item-small">
                                            <div class="stat-value"><?php echo round($subject['avg_score'], 1); ?>%</div>
                                            <div class="stat-label">Уровень</div>
                                        </div>
                                        <div class="stat-item-small">
                                            <div class="stat-value"><?php echo $subject['tests_count']; ?></div>
                                            <div class="stat-label">Тестов</div>
                                        </div>
                                        <div class="stat-item-small">
                                            <div class="stat-value"><?php echo $subject['passed_tests']; ?></div>
                                            <div class="stat-label">Сдано</div>
                                        </div>
                                    </div>
                                    
                                    <div class="progress-container">
                                        <div class="progress-label">
                                            <span>Прогресс освоения</span>
                                            <span><?php echo round($subject['avg_score'], 1); ?>%</span>
                                        </div>
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width: <?php echo $subject['avg_score']; ?>%; background: <?php echo $subject['skill_level']['color']; ?>"></div>
                                        </div>
                                    </div>
                                    
                                    <?php if (isset($question_types_by_subject[$subject['subject']])): ?>
                                    <div class="skill-types">
                                        <div class="types-title">Типы вопросов:</div>
                                        <div class="types-list">
                                            <?php foreach (array_slice($question_types_by_subject[$subject['subject']], 0, 4) as $type): ?>
                                                <div class="type-tag">
                                                    <span><?php echo getQuestionTypeName($type['question_type']); ?></span>
                                                    <span class="type-accuracy"><?php echo round($type['accuracy'] * 100, 0); ?>%</span>
                                                </div>
                                            <?php endforeach; ?>
                                            <?php if (count($question_types_by_subject[$subject['subject']]) > 4): ?>
                                                <div class="type-tag">
                                                    <span>+<?php echo count($question_types_by_subject[$subject['subject']]) - 4; ?> еще</span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-book"></i>
                                <p>Нет данных о навыках</p>
                                <p>Пройдите тесты, чтобы увидеть вашу карту навыков</p>
                                <a href="tests.php" class="back-button" style="margin-top: 15px;">
                                    <i class="fas fa-play"></i> Начать тестирование
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Рекомендации по развитию -->
                <?php if (count($recommendations) > 0): ?>
                <div class="section">
                    <div class="section-header">
                        <h2><i class="fas fa-lightbulb"></i> Рекомендации по развитию</h2>
                        <span><?php echo count($recommendations); ?> предложений</span>
                    </div>
                    <div class="recommendations-list">
                        <?php foreach ($recommendations as $rec): ?>
                            <div class="recommendation-item <?php echo $rec['priority']; ?>">
                                <div class="recommendation-title">
                                    <i class="fas fa-book"></i>
                                    <?php echo htmlspecialchars($rec['subject']); ?> - <?php echo $rec['current_level']; ?> уровень
                                </div>
                                <div class="recommendation-message"><?php echo htmlspecialchars($rec['message']); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Управление мобильным меню
        document.getElementById('mobileMenuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('mobile-open');
        });

        // Управление уведомлениями
        function toggleNotifications() {
            alert('Функция уведомлений будет реализована позже');
        }

        // Поиск навыков
        document.getElementById('skillSearch').addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const skillCards = document.querySelectorAll('.skill-card');
            
            skillCards.forEach(card => {
                const title = card.querySelector('.skill-title').textContent.toLowerCase();
                
                if (title.includes(searchTerm)) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        });

        // Анимация прогресс-баров
        document.addEventListener('DOMContentLoaded', function() {
            const progressBars = document.querySelectorAll('.progress-fill');
            progressBars.forEach(bar => {
                const width = bar.style.width;
                bar.style.width = '0';
                setTimeout(() => {
                    bar.style.width = width;
                }, 500);
            });
        });

        function openHelp() {
            alert('Раздел помощи будет реализован позже');
        }

        function openFeedback() {
            alert('Форма обратной связи будет реализована позже');
        }
    </script>
</body>
</html>