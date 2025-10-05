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

// Получаем категории материалов
$categories = [];
try {
    $stmt = $pdo->query("SELECT * FROM material_categories ORDER BY name");
    $categories = $stmt->fetchAll();
} catch (PDOException $e) {
    $categories = [
        ['id' => 1, 'name' => 'Видеоуроки', 'icon' => 'fas fa-video'],
        ['id' => 2, 'name' => 'PDF материалы', 'icon' => 'fas fa-file-pdf'],
        ['id' => 3, 'name' => 'Интерактивные задания', 'icon' => 'fas fa-puzzle-piece'],
        ['id' => 4, 'name' => 'Ссылки на ресурсы', 'icon' => 'fas fa-link']
    ];
}

// Получаем материалы
$materials = [];
try {
    $stmt = $pdo->prepare("
        SELECT m.*, mc.name as category_name, mc.icon as category_icon,
               (SELECT COUNT(*) FROM material_views WHERE material_id = m.id AND user_id = ?) as viewed
        FROM materials m
        LEFT JOIN material_categories mc ON m.category_id = mc.id
        WHERE m.is_active = 1
        ORDER BY m.created_at DESC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $materials = $stmt->fetchAll();
} catch (PDOException $e) {
    $materials = [
        [
            'id' => 1,
            'title' => 'Введение в программирование',
            'description' => 'Основные концепции программирования для начинающих. Этот курс охватывает базовые принципы программирования, включая переменные, циклы, условия и функции.',
            'category_id' => 1,
            'category_name' => 'Видеоуроки',
            'category_icon' => 'fas fa-video',
            'file_type' => 'video',
            'file_path' => 'uploads/videos/intro_programming.mp4',
            'duration' => '25:30',
            'file_size' => '150 MB',
            'viewed' => 1,
            'created_at' => date('Y-m-d H:i:s', strtotime('-5 days'))
        ],
        [
            'id' => 2,
            'title' => 'Сборник задач по математике',
            'description' => 'Практические задания для закрепления материала по алгебре и геометрии. Содержит задачи различной сложности с подробными решениями.',
            'category_id' => 2,
            'category_name' => 'PDF материалы',
            'category_icon' => 'fas fa-file-pdf',
            'file_type' => 'pdf',
            'file_path' => 'uploads/pdfs/math_problems.pdf',
            'duration' => '45 стр.',
            'file_size' => '8.2 MB',
            'viewed' => 0,
            'created_at' => date('Y-m-d H:i:s', strtotime('-3 days'))
        ],
        [
            'id' => 3,
            'title' => 'Интерактивный тест по физике',
            'description' => 'Проверьте свои знания в интерактивном формате. Тест включает вопросы по механике, термодинамике и электромагнетизму.',
            'category_id' => 3,
            'category_name' => 'Интерактивные задания',
            'category_icon' => 'fas fa-puzzle-piece',
            'file_type' => 'interactive',
            'file_path' => 'uploads/interactive/physics_test.zip',
            'duration' => '15 мин',
            'file_size' => '2.1 MB',
            'viewed' => 1,
            'created_at' => date('Y-m-d H:i:s', strtotime('-1 day'))
        ],
        [
            'id' => 4,
            'title' => 'Официальная документация Python',
            'description' => 'Полное руководство по языку программирования Python. Включает справочник по синтаксису, стандартной библиотеке и лучшим практикам.',
            'category_id' => 4,
            'category_name' => 'Ссылки на ресурсы',
            'category_icon' => 'fas fa-link',
            'file_type' => 'link',
            'external_url' => 'https://docs.python.org/3/',
            'duration' => '',
            'file_size' => '',
            'viewed' => 0,
            'created_at' => date('Y-m-d H:i:s')
        ]
    ];
}

// Обработка поиска
$search_query = '';
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search_query = trim($_GET['search']);
    $filtered_materials = array_filter($materials, function($material) use ($search_query) {
        return stripos($material['title'], $search_query) !== false || 
               stripos($material['description'], $search_query) !== false;
    });
    $materials = $filtered_materials;
}

// Обработка фильтрации по категориям
$selected_category = '';
if (isset($_GET['category']) && !empty($_GET['category'])) {
    $selected_category = $_GET['category'];
    $filtered_materials = array_filter($materials, function($material) use ($selected_category) {
        return $material['category_id'] == $selected_category;
    });
    $materials = $filtered_materials;
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Библиотека материалов - Система интеллектуальной оценки знаний</title>
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
        }

        .sidebar::-webkit-scrollbar {
            width: 0px;
            background: transparent;
        }

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
            background: var(--primary);
        }

        .nav-links {
            list-style: none;
            flex: 1;
            overflow-y: auto;
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

        /* Основной контент */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            padding: 20px;
            transition: margin-left 0.3s;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .welcome h2 {
            font-size: 28px;
            color: var(--secondary);
            margin-bottom: 8px;
            font-weight: 700;
        }
        
        .welcome p {
            color: var(--gray);
            font-size: 16px;
        }
        
        .header-actions {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        
        /* Поиск и фильтры */
        .search-filters {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            margin-bottom: 25px;
        }
        
        .search-box {
            display: flex;
            align-items: center;
            background: var(--light);
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        
        .search-box input {
            border: none;
            outline: none;
            padding: 8px 15px;
            font-size: 16px;
            flex: 1;
            background: transparent;
        }
        
        .search-box i {
            color: var(--gray);
            font-size: 18px;
        }
        
        .filters {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .filter-group {
            flex: 1;
            min-width: 200px;
        }
        
        .filter-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--secondary);
        }
        
        .filter-select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            background: white;
            cursor: pointer;
            transition: border-color 0.3s;
        }
        
        .filter-select:focus {
            border-color: var(--primary);
            outline: none;
        }
        
        /* Статистика */
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            display: flex;
            align-items: center;
            transition: transform 0.3s, box-shadow 0.3s;
            border-left: 4px solid var(--primary);
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.12);
        }
        
        .stat-card.video {
            border-left-color: #e74c3c;
        }
        
        .stat-card.pdf {
            border-left-color: #3498db;
        }
        
        .stat-card.interactive {
            border-left-color: #2ecc71;
        }
        
        .stat-card.link {
            border-left-color: #f39c12;
        }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            margin-right: 15px;
            color: white;
        }
        
        .icon-video {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
        }
        
        .icon-pdf {
            background: linear-gradient(135deg, #3498db, #2980b9);
        }
        
        .icon-interactive {
            background: linear-gradient(135deg, #2ecc71, #27ae60);
        }
        
        .icon-link {
            background: linear-gradient(135deg, #f39c12, #e67e22);
        }
        
        .stat-details h3 {
            font-size: 24px;
            margin-bottom: 5px;
            color: var(--secondary);
            font-weight: 700;
        }
        
        .stat-details p {
            color: var(--gray);
            font-size: 14px;
        }
        
        /* Сетка материалов */
        .materials-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .material-card {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            transition: all 0.3s;
            position: relative;
        }
        
        .material-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        }
        
        .material-card.new::before {
            content: 'НОВОЕ';
            position: absolute;
            top: 15px;
            right: 15px;
            background: var(--accent);
            color: white;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            z-index: 2;
        }
        
        .material-header {
            padding: 20px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            align-items: flex-start;
            gap: 15px;
        }
        
        .material-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: white;
            flex-shrink: 0;
        }
        
        .material-icon.video {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
        }
        
        .material-icon.pdf {
            background: linear-gradient(135deg, #3498db, #2980b9);
        }
        
        .material-icon.interactive {
            background: linear-gradient(135deg, #2ecc71, #27ae60);
        }
        
        .material-icon.link {
            background: linear-gradient(135deg, #f39c12, #e67e22);
        }
        
        .material-info {
            flex: 1;
        }
        
        .material-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--secondary);
            line-height: 1.3;
        }
        
        .material-category {
            display: inline-block;
            background: rgba(52, 152, 219, 0.1);
            color: var(--primary);
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
            margin-bottom: 8px;
        }
        
        .material-description {
            color: var(--gray);
            font-size: 14px;
            line-height: 1.4;
            margin-bottom: 10px;
        }
        
        .material-meta {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            color: var(--gray);
        }
        
        .material-body {
            padding: 20px;
        }
        
        .material-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .detail-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: var(--gray);
        }
        
        .detail-item i {
            width: 16px;
            color: var(--primary);
        }
        
        .material-actions {
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
        
        .btn-outline {
            background: transparent;
            color: var(--primary);
            border: 2px solid var(--primary);
        }
        
        .btn-outline:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
        }
        
        /* Состояние просмотра */
        .viewed-badge {
            position: absolute;
            top: 15px;
            left: 15px;
            background: var(--success);
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            z-index: 2;
        }
        
        /* Модальное окно для просмотра материалов */
        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.8);
            animation: fadeIn 0.3s;
        }

        .modal-content {
            background-color: white;
            margin: 2% auto;
            padding: 0;
            border-radius: 15px;
            width: 95%;
            max-width: 1200px;
            max-height: 90vh;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: slideIn 0.3s;
            position: relative;
            display: flex;
            flex-direction: column;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .modal-header {
            padding: 20px 30px;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--light);
            border-radius: 15px 15px 0 0;
        }

        .modal-header h3 {
            color: var(--secondary);
            font-size: 24px;
            margin: 0;
            flex: 1;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 28px;
            color: var(--gray);
            cursor: pointer;
            padding: 5px 10px;
            transition: all 0.3s;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .close-modal:hover {
            color: var(--accent);
            background: rgba(231, 76, 60, 0.1);
        }

        .modal-body {
            padding: 0;
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        /* Контейнеры для разных типов контента */
        .video-container {
            width: 100%;
            height: 100%;
            background: #000;
        }

        .video-container video {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .pdf-container {
            width: 100%;
            height: 100%;
            border: none;
        }

        .pdf-container iframe {
            width: 100%;
            height: 100%;
            border: none;
        }

        .interactive-container {
            width: 100%;
            height: 100%;
            padding: 30px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
        }

        .interactive-container i {
            font-size: 80px;
            color: var(--success);
            margin-bottom: 20px;
        }

        .interactive-container h4 {
            color: var(--secondary);
            margin-bottom: 15px;
            font-size: 24px;
        }

        .interactive-container p {
            color: var(--gray);
            margin-bottom: 25px;
            font-size: 16px;
            max-width: 500px;
        }

        .link-container {
            width: 100%;
            height: 100%;
            padding: 40px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
        }

        .link-container i {
            font-size: 80px;
            color: var(--warning);
            margin-bottom: 20px;
        }

        .link-container h4 {
            color: var(--secondary);
            margin-bottom: 15px;
            font-size: 24px;
        }

        .link-container p {
            color: var(--gray);
            margin-bottom: 25px;
            font-size: 16px;
            max-width: 600px;
        }

        .external-link {
            font-size: 18px;
            color: var(--primary);
            text-decoration: none;
            word-break: break-all;
            padding: 15px 25px;
            border: 2px solid var(--primary);
            border-radius: 10px;
            transition: all 0.3s;
        }

        .external-link:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
        }

        /* Информационная панель */
        .info-panel {
            padding: 20px 30px;
            background: var(--light);
            border-top: 1px solid #e0e0e0;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .info-item-modal {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .info-label {
            font-size: 12px;
            color: var(--gray);
            font-weight: 600;
            text-transform: uppercase;
        }

        .info-value {
            font-size: 14px;
            color: var(--secondary);
            font-weight: 500;
        }

        /* Адаптивность */
        @media (max-width: 1200px) {
            .materials-grid {
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            }
            
            .modal-content {
                width: 98%;
                margin: 1% auto;
            }
        }
        
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
            
            .filters {
                flex-direction: column;
            }
            
            .filter-group {
                min-width: 100%;
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
            
            .materials-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-cards {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .material-details {
                grid-template-columns: 1fr;
            }
            
            .material-actions {
                flex-direction: column;
            }
            
            .modal-header {
                padding: 15px 20px;
            }
            
            .modal-header h3 {
                font-size: 20px;
            }
            
            .info-panel {
                padding: 15px 20px;
            }
        }
        
        @media (max-width: 480px) {
            .stats-cards {
                grid-template-columns: 1fr;
            }
            
            .modal-content {
                width: 100%;
                height: 100%;
                margin: 0;
                border-radius: 0;
            }
        }
    </style>
</head>
<body>
    <!-- Мобильное меню -->
    <div class="mobile-menu-toggle">
        <i class="fas fa-bars"></i>
    </div>

    <!-- Сайдбар -->
    <div class="sidebar">
        <div class="logo">
            <h1><i class="fas fa-graduation-cap"></i> EduSystem</h1>
            <div class="system-status">
                <div class="status-indicator online"></div>
                <span>Система активна</span>
            </div>
        </div>

        <div class="user-info">
            <div class="user-avatar">
                <?php 
                $nameParts = explode(' ', $user['full_name']);
                $initials = '';
                foreach ($nameParts as $part) {
                    $initials .= mb_substr($part, 0, 1);
                }
                echo mb_strtoupper($initials);
                ?>
            </div>
            <div class="user-details">
                <h3><?php echo htmlspecialchars($user['full_name']); ?></h3>
                <p><?php echo htmlspecialchars($user['email']); ?></p>
                <span class="role-badge"><?php echo htmlspecialchars($user['role']); ?></span>
            </div>
        </div>

        <ul class="nav-links">
            <div class="nav-section">
                <div class="section-label">Основное</div>
            </div>
            <li><a href="index.php"><i class="fas fa-home"></i> Главная</a></li>
            <li><a href="learning_path.php"><i class="fas fa-road"></i> Траектория обучения</a></li>
            <li><a href="study_materials.php" class="active"><i class="fas fa-book"></i> Материалы</a></li>
            <li><a href="tests.php"><i class="fas fa-play"></i> Тесты</a></li>
            <li><a href="results.php"><i class="fas fa-chart-bar"></i> Результаты</a></li>

            <?php if ($user['role'] == 'teacher'): ?>
            <li><a href="upload_material.php"><i class="fas fa-upload"></i> Загрузить материалы</a></li>
            <?php endif; ?>

            <div class="nav-section">
                <div class="section-label">Дополнительно</div>
            </div>
            <li><a href="profile.php"><i class="fas fa-user"></i> Профиль</a></li>
            <li><a href="help.php"><i class="fas fa-question-circle"></i> Помощь</a></li>
            <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Выход</a></li>
        </ul>

        <div class="sidebar-footer">
            <div class="system-info">
                <div class="info-item">
                    <i class="fas fa-database"></i>
                    <span>База данных: Активна</span>
                </div>
                <div class="info-item">
                    <i class="fas fa-shield-alt"></i>
                    <span>Безопасность: Включена</span>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
        <div class="header">
            <div class="welcome">
                <h2>Библиотека обучающих материалов</h2>
                <p>Доступ к видеоурокам, PDF материалам, интерактивным заданиям и ссылкам на ресурсы</p>
            </div>
        </div>
        
        <!-- Поиск и фильтры -->
        <div class="search-filters">
            <form method="GET" action="study_materials.php" class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" name="search" placeholder="Поиск материалов..." value="<?php echo htmlspecialchars($search_query); ?>">
                <button type="submit" style="display: none;"></button>
            </form>
            
            <div class="filters">
                <div class="filter-group">
                    <label for="category">Категория</label>
                    <select name="category" id="category" class="filter-select" onchange="this.form.submit()">
                        <option value="">Все категории</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo $category['id']; ?>" 
                                <?php echo $selected_category == $category['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($category['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        
        <!-- Статистика -->
        <div class="stats-cards">
            <div class="stat-card video">
                <div class="stat-icon icon-video">
                    <i class="fas fa-video"></i>
                </div>
                <div class="stat-details">
                    <h3><?php echo count(array_filter($materials, function($m) { return $m['category_id'] == 1; })); ?></h3>
                    <p>Видеоуроков</p>
                </div>
            </div>
            
            <div class="stat-card pdf">
                <div class="stat-icon icon-pdf">
                    <i class="fas fa-file-pdf"></i>
                </div>
                <div class="stat-details">
                    <h3><?php echo count(array_filter($materials, function($m) { return $m['category_id'] == 2; })); ?></h3>
                    <p>PDF материалов</p>
                </div>
            </div>
            
            <div class="stat-card interactive">
                <div class="stat-icon icon-interactive">
                    <i class="fas fa-puzzle-piece"></i>
                </div>
                <div class="stat-details">
                    <h3><?php echo count(array_filter($materials, function($m)                     { return $m['category_id'] == 3; })); ?></h3>
                    <p>Интерактивных заданий</p>
                </div>
            </div>
            
            <div class="stat-card link">
                <div class="stat-icon icon-link">
                    <i class="fas fa-link"></i>
                </div>
                <div class="stat-details">
                    <h3><?php echo count(array_filter($materials, function($m) { return $m['category_id'] == 4; })); ?></h3>
                    <p>Ссылок на ресурсы</p>
                </div>
            </div>
        </div>
        
        <!-- Сетка материалов -->
        <div class="materials-grid">
            <?php foreach ($materials as $material): 
                $isNew = strtotime($material['created_at']) > strtotime('-7 days');
            ?>
            <div class="material-card <?php echo $isNew ? 'new' : ''; ?>">
                <?php if ($material['viewed']): ?>
                    <div class="viewed-badge">ПРОСМОТРЕНО</div>
                <?php endif; ?>
                
                <div class="material-header">
                    <div class="material-icon <?php echo $material['category_name'] == 'Видеоуроки' ? 'video' : 
                                                     ($material['category_name'] == 'PDF материалы' ? 'pdf' : 
                                                     ($material['category_name'] == 'Интерактивные задания' ? 'interactive' : 'link')); ?>">
                        <i class="<?php echo $material['category_icon']; ?>"></i>
                    </div>
                    <div class="material-info">
                        <h3 class="material-title"><?php echo htmlspecialchars($material['title']); ?></h3>
                        <span class="material-category"><?php echo htmlspecialchars($material['category_name']); ?></span>
                        <p class="material-description"><?php echo htmlspecialchars($material['description']); ?></p>
                        <div class="material-meta">
                            <span><i class="far fa-calendar"></i> <?php echo date('d.m.Y', strtotime($material['created_at'])); ?></span>
                            <?php if ($material['file_size']): ?>
                                <span><i class="far fa-hdd"></i> <?php echo $material['file_size']; ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="material-body">
                    <div class="material-details">
                        <?php if ($material['duration']): ?>
                        <div class="detail-item">
                            <i class="far fa-clock"></i>
                            <span><?php echo $material['duration']; ?></span>
                        </div>
                        <?php endif; ?>
                        
                        <div class="detail-item">
                            <i class="far fa-file"></i>
                            <span>
                                <?php 
                                switch($material['file_type']) {
                                    case 'video': echo 'Видеофайл'; break;
                                    case 'pdf': echo 'PDF документ'; break;
                                    case 'interactive': echo 'Интерактивный модуль'; break;
                                    case 'link': echo 'Внешняя ссылка'; break;
                                    default: echo 'Материал'; break;
                                }
                                ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="material-actions">
                        <button class="btn btn-primary" onclick="openMaterialModal(<?php echo htmlspecialchars(json_encode($material)); ?>)">
                            <i class="fas fa-eye"></i> Просмотреть
                        </button>
                        <?php if ($material['file_type'] != 'link'): ?>
                        <button class="btn btn-outline" onclick="downloadMaterial('<?php echo $material['file_path']; ?>')">
                            <i class="fas fa-download"></i> Скачать
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            
            <?php if (empty($materials)): ?>
            <div style="grid-column: 1 / -1; text-align: center; padding: 40px;">
                <i class="fas fa-search" style="font-size: 60px; color: #bdc3c7; margin-bottom: 20px;"></i>
                <h3 style="color: #7f8c8d; margin-bottom: 10px;">Материалы не найдены</h3>
                <p style="color: #95a5a6;">Попробуйте изменить параметры поиска или фильтрации</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Модальное окно для просмотра материалов -->
    <div id="materialModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Заголовок материала</h3>
                <button class="close-modal" onclick="closeMaterialModal()">&times;</button>
            </div>
            <div class="modal-body" id="modalBody">
                <!-- Контент будет загружаться динамически -->
            </div>
            <div class="info-panel">
                <div class="info-grid">
                    <div class="info-item-modal">
                        <span class="info-label">Тип материала</span>
                        <span class="info-value" id="infoType">-</span>
                    </div>
                    <div class="info-item-modal">
                        <span class="info-label">Категория</span>
                        <span class="info-value" id="infoCategory">-</span>
                    </div>
                    <div class="info-item-modal">
                        <span class="info-label">Длительность</span>
                        <span class="info-value" id="infoDuration">-</span>
                    </div>
                    <div class="info-item-modal">
                        <span class="info-label">Размер</span>
                        <span class="info-value" id="infoSize">-</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Открытие модального окна с материалом
        function openMaterialModal(material) {
            const modal = document.getElementById('materialModal');
            const modalTitle = document.getElementById('modalTitle');
            const modalBody = document.getElementById('modalBody');
            const infoType = document.getElementById('infoType');
            const infoCategory = document.getElementById('infoCategory');
            const infoDuration = document.getElementById('infoDuration');
            const infoSize = document.getElementById('infoSize');
            
            // Заполняем заголовок
            modalTitle.textContent = material.title;
            
            // Заполняем информацию в панели
            infoType.textContent = getMaterialTypeText(material.file_type);
            infoCategory.textContent = material.category_name;
            infoDuration.textContent = material.duration || '-';
            infoSize.textContent = material.file_size || '-';
            
            // Очищаем тело модального окна
            modalBody.innerHTML = '';
            
            // Создаем контент в зависимости от типа материала
            switch(material.file_type) {
                case 'video':
                    const videoContainer = document.createElement('div');
                    videoContainer.className = 'video-container';
                    videoContainer.innerHTML = `
                        <video controls>
                            <source src="${material.file_path}" type="video/mp4">
                            Ваш браузер не поддерживает видео тег.
                        </video>
                    `;
                    modalBody.appendChild(videoContainer);
                    break;
                    
                case 'pdf':
                    const pdfContainer = document.createElement('div');
                    pdfContainer.className = 'pdf-container';
                    pdfContainer.innerHTML = `
                        <iframe src="${material.file_path}"></iframe>
                    `;
                    modalBody.appendChild(pdfContainer);
                    break;
                    
                case 'interactive':
                    const interactiveContainer = document.createElement('div');
                    interactiveContainer.className = 'interactive-container';
                    interactiveContainer.innerHTML = `
                        <i class="fas fa-puzzle-piece"></i>
                        <h4>Интерактивное задание</h4>
                        <p>${material.description}</p>
                        <button class="btn btn-primary" onclick="startInteractive('${material.file_path}')">
                            <i class="fas fa-play"></i> Запустить задание
                        </button>
                    `;
                    modalBody.appendChild(interactiveContainer);
                    break;
                    
                case 'link':
                    const linkContainer = document.createElement('div');
                    linkContainer.className = 'link-container';
                    linkContainer.innerHTML = `
                        <i class="fas fa-external-link-alt"></i>
                        <h4>Внешний ресурс</h4>
                        <p>${material.description}</p>
                        <a href="${material.external_url}" target="_blank" class="external-link">
                            <i class="fas fa-external-link-alt"></i> Перейти к ресурсу
                        </a>
                    `;
                    modalBody.appendChild(linkContainer);
                    break;
                    
                default:
                    modalBody.innerHTML = `<p>Тип материала не поддерживается для просмотра.</p>`;
            }
            
            // Показываем модальное окно
            modal.style.display = 'block';
            
            // Отмечаем материал как просмотренный
            markMaterialAsViewed(material.id);
        }
        
        // Закрытие модального окна
        function closeMaterialModal() {
            const modal = document.getElementById('materialModal');
            modal.style.display = 'none';
            
            // Останавливаем видео при закрытии
            const video = modal.querySelector('video');
            if (video) {
                video.pause();
            }
        }
        
        // Получение текстового описания типа материала
        function getMaterialTypeText(fileType) {
            switch(fileType) {
                case 'video': return 'Видеоурок';
                case 'pdf': return 'PDF документ';
                case 'interactive': return 'Интерактивное задание';
                case 'link': return 'Внешняя ссылка';
                default: return 'Материал';
            }
        }
        
        // Запуск интерактивного задания
        function startInteractive(filePath) {
            alert('Запуск интерактивного задания: ' + filePath + '\n\nВ реальной системе здесь будет запуск интерактивного модуля.');
            // В реальной системе здесь будет код для запуска интерактивного задания
        }
        
        // Скачивание материала
        function downloadMaterial(filePath) {
            // В реальной системе здесь будет перенаправление на скачивание файла
            window.location.href = 'download.php?file=' + encodeURIComponent(filePath);
        }
        
        // Отметка материала как просмотренного
        function markMaterialAsViewed(materialId) {
            // Отправляем AJAX запрос для отметки просмотра
            fetch('mark_viewed.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'material_id=' + materialId
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Обновляем интерфейс - добавляем бейдж "ПРОСМОТРЕНО"
                    const materialCard = document.querySelector(`.material-card[data-id="${materialId}"]`);
                    if (materialCard && !materialCard.querySelector('.viewed-badge')) {
                        const badge = document.createElement('div');
                        badge.className = 'viewed-badge';
                        badge.textContent = 'ПРОСМОТРЕНО';
                        materialCard.appendChild(badge);
                    }
                }
            })
            .catch(error => console.error('Ошибка:', error));
        }
        
        // Закрытие модального окна при клике вне его
        window.onclick = function(event) {
            const modal = document.getElementById('materialModal');
            if (event.target == modal) {
                closeMaterialModal();
            }
        }
        
        // Мобильное меню
        document.querySelector('.mobile-menu-toggle').addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('mobile-open');
        });
        
        // Добавляем data-id атрибуты для карточек материалов
        document.addEventListener('DOMContentLoaded', function() {
            const materialCards = document.querySelectorAll('.material-card');
            materialCards.forEach(card => {
                const title = card.querySelector('.material-title').textContent;
                const materials = <?php echo json_encode($materials); ?>;
                const material = materials.find(m => m.title === title);
                if (material) {
                    card.setAttribute('data-id', material.id);
                }
            });
        });
    </script>
</body>
</html>