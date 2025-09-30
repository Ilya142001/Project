<?php
// sidebar_menu.php
if (!isset($_SESSION)) {
    session_start();
}

// Проверяем авторизацию
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Получаем информацию о пользователе
include 'config.php';
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

// Получаем количество непрочитанных сообщений
$unread_count = 0;
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE recipient_id = ? AND is_read = 0");
$stmt->execute([$_SESSION['user_id']]);
$unread_count = $stmt->fetchColumn();

// Получаем количество ожидающих тестов для студента
$pending_tests = 0;
if ($user['role'] == 'student') {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM tests t 
        WHERE t.id NOT IN (
            SELECT test_id FROM test_results WHERE user_id = ?
        ) AND t.is_active = 1
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $pending_tests = $stmt->fetchColumn();
}

// Получаем количество тестов на проверке для преподавателя
$tests_to_review = 0;
if ($user['role'] == 'teacher' || $user['role'] == 'admin') {
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT tr.id) 
        FROM test_results tr
        JOIN tests t ON tr.test_id = t.id 
        WHERE t.created_by = ? AND tr.needs_review = 1
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $tests_to_review = $stmt->fetchColumn();
}
?>

<!-- Sidebar Menu -->
<div class="sidebar">
    <div class="logo">
        <h1><i class="fas fa-brain"></i> EduAI Analytics</h1>
        <div class="system-status">
            <span class="status-indicator online"></span>
            <small>Система активна</small>
        </div>
    </div>
    
    <div class="user-info">
        <div class="user-avatar">
            <?php 
            $avatarPath = !empty($user['avatar']) ? $user['avatar'] : 'default_avatar.jpg';
            if (file_exists($avatarPath)) {
                echo '<img src="' . $avatarPath . '" alt="Аватар">';
            } else {
                $firstName = $user['full_name'];
                if (function_exists('mb_convert_encoding')) {
                    $firstName = mb_convert_encoding($firstName, 'UTF-8', 'auto');
                }
                $firstLetter = mb_substr($firstName, 0, 1, 'UTF-8');
                echo htmlspecialchars(strtoupper($firstLetter));
            }
            ?>
        </div>
        <div class="user-details">
            <h3><?php echo htmlspecialchars(explode(' ', $user['full_name'])[1] ?? $user['full_name']); ?></h3>
            <p><?php echo htmlspecialchars($user['email']); ?></p>
            <span class="role-badge role-<?php echo $user['role']; ?>">
                <?php 
                $role_names = [
                    'admin' => 'Администратор',
                    'teacher' => 'Преподаватель', 
                    'student' => 'Студент'
                ];
                echo $role_names[$user['role']];
                ?>
            </span>
        </div>
    </div>

    <!-- Quick Stats -->
    <div class="quick-stats">
        <?php if ($user['role'] == 'student'): ?>
            <div class="stat-item">
                <i class="fas fa-clock"></i>
                <span><?php echo $pending_tests; ?> тестов ожидают</span>
            </div>
        <?php else: ?>
            <div class="stat-item">
                <i class="fas fa-check-double"></i>
                <span><?php echo $tests_to_review; ?> на проверке</span>
            </div>
        <?php endif; ?>
        <div class="stat-item">
            <i class="fas fa-envelope"></i>
            <span><?php echo $unread_count; ?> новых сообщений</span>
        </div>
    </div>

    <!-- Main Navigation -->
    <ul class="nav-links">
        <!-- Основной раздел -->
        <li><a href="dashboard.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">
            <i class="fas fa-th-large"></i> 
            <span>Главная панель</span>
        </a></li>

        <!-- Учебные материалы -->
        <li class="nav-section">
            <span class="section-label">УЧЕБНЫЕ МАТЕРИАЛЫ</span>
        </li>
        <li><a href="tests.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'tests.php' ? 'active' : ''; ?>">
            <i class="fas fa-file-alt"></i> 
            <span>Все тесты</span>
            <?php if ($pending_tests > 0): ?>
                <span class="nav-badge"><?php echo $pending_tests; ?></span>
            <?php endif; ?>
        </a></li>
        
        <?php if ($user['role'] == 'student'): ?>
        <li><a href="learning_path.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'learning_path.php' ? 'active' : ''; ?>">
            <i class="fas fa-road"></i> 
            <span>Мой учебный путь</span>
        </a></li>
        <li><a href="study_materials.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'study_materials.php' ? 'active' : ''; ?>">
            <i class="fas fa-book"></i> 
            <span>Учебные материалы</span>
        </a></li>
        <?php endif; ?>

        <!-- Аналитика и результаты -->
        <li class="nav-section">
            <span class="section-label">АНАЛИТИКА И РЕЗУЛЬТАТЫ</span>
        </li>
        <li><a href="results.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'results.php' ? 'active' : ''; ?>">
            <i class="fas fa-chart-line"></i> 
            <span>Результаты</span>
        </a></li>
        
        <?php if ($user['role'] == 'student'): ?>
        <li><a href="my_progress.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'my_progress.php' ? 'active' : ''; ?>">
            <i class="fas fa-trend-up"></i> 
            <span>Мой прогресс</span>
        </a></li>
        <li><a href="achievements.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'achievements.php' ? 'active' : ''; ?>">
            <i class="fas fa-trophy"></i> 
            <span>Достижения</span>
        </a></li>
        <li><a href="skill_map.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'skill_map.php' ? 'active' : ''; ?>">
            <i class="fas fa-map"></i> 
            <span>Карта навыков</span>
        </a></li>
        <?php endif; ?>

        <!-- Для преподавателей -->
        <?php if ($user['role'] == 'teacher' || $user['role'] == 'admin'): ?>
        <li class="nav-section">
            <span class="section-label">ПРЕПОДАВАТЕЛЬСКИЙ РАЗДЕЛ</span>
        </li>
        <li><a href="students.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'students.php' ? 'active' : ''; ?>">
            <i class="fas fa-users"></i> 
            <span>Студенты</span>
        </a></li>
        <li><a href="analytics.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'analytics.php' ? 'active' : ''; ?>">
            <i class="fas fa-chart-bar"></i> 
            <span>Аналитика</span>
        </a></li>
        <li><a href="question_bank.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'question_bank.php' ? 'active' : ''; ?>">
            <i class="fas fa-database"></i> 
            <span>Банк вопросов</span>
        </a></li>
        <li><a href="templates.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'templates.php' ? 'active' : ''; ?>">
            <i class="fas fa-layer-group"></i> 
            <span>Шаблоны тестов</span>
        </a></li>
        <li><a href="grading.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'grading.php' ? 'active' : ''; ?>">
            <i class="fas fa-check-double"></i> 
            <span>Проверка работ</span>
            <?php if ($tests_to_review > 0): ?>
                <span class="nav-badge"><?php echo $tests_to_review; ?></span>
            <?php endif; ?>
        </a></li>
        <?php endif; ?>

        <!-- ИИ и умные функции -->
        <li class="nav-section">
            <span class="section-label">ИСКУССТВЕННЫЙ ИНТЕЛЛЕКТ</span>
        </li>
        <li><a href="ml_models.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'ml_models.php' ? 'active' : ''; ?>">
            <i class="fas fa-robot"></i> 
            <span>ML модели</span>
        </a></li>
        <li><a href="ai_assistant.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'ai_assistant.php' ? 'active' : ''; ?>">
            <i class="fas fa-magic"></i> 
            <span>AI Ассистент</span>
        </a></li>
        <li><a href="recommendations.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'recommendations.php' ? 'active' : ''; ?>">
            <i class="fas fa-lightbulb"></i> 
            <span>Рекомендации</span>
        </a></li>
        <li><a href="predictions.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'predictions.php' ? 'active' : ''; ?>">
            <i class="fas fa-crystal-ball"></i> 
            <span>Прогнозы успеваемости</span>
        </a></li>

        <!-- Коммуникации -->
        <li class="nav-section">
            <span class="section-label">КОММУНИКАЦИИ</span>
        </li>
        <li><a href="messages.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'messages.php' ? 'active' : ''; ?>">
            <i class="fas fa-comments"></i> 
            <span>Сообщения</span>
            <?php if ($unread_count > 0): ?>
                <span class="nav-badge"><?php echo $unread_count; ?></span>
            <?php endif; ?>
        </a></li>
        <li><a href="discussions.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'discussions.php' ? 'active' : ''; ?>">
            <i class="fas fa-comment-dots"></i> 
            <span>Обсуждения</span>
        </a></li>
        <li><a href="announcements.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'announcements.php' ? 'active' : ''; ?>">
            <i class="fas fa-bullhorn"></i> 
            <span>Объявления</span>
        </a></li>

        <!-- Планирование -->
        <li class="nav-section">
            <span class="section-label">ПЛАНИРОВАНИЕ</span>
        </li>
        <li><a href="calendar.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'calendar.php' ? 'active' : ''; ?>">
            <i class="fas fa-calendar"></i> 
            <span>Календарь</span>
        </a></li>
        <li><a href="tasks.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'tasks.php' ? 'active' : ''; ?>">
            <i class="fas fa-tasks"></i> 
            <span>Задачи</span>
        </a></li>
        <li><a href="deadlines.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'deadlines.php' ? 'active' : ''; ?>">
            <i class="fas fa-clock"></i> 
            <span>Дедлайны</span>
        </a></li>

        <!-- Административный раздел -->
        <?php if ($user['role'] == 'admin'): ?>
        <li class="nav-section">
            <span class="section-label">АДМИНИСТРИРОВАНИЕ</span>
        </li>
        <li><a href="user_management.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'user_management.php' ? 'active' : ''; ?>">
            <i class="fas fa-user-cog"></i> 
            <span>Управление пользователями</span>
        </a></li>
        <li><a href="system_logs.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'system_logs.php' ? 'active' : ''; ?>">
            <i class="fas fa-clipboard-list"></i> 
            <span>Логи системы</span>
        </a></li>
        <li><a href="backup.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'backup.php' ? 'active' : ''; ?>">
            <i class="fas fa-download"></i> 
            <span>Резервные копии</span>
        </a></li>
        <li><a href="system_settings.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'system_settings.php' ? 'active' : ''; ?>">
            <i class="fas fa-sliders-h"></i> 
            <span>Настройки системы</span>
        </a></li>
        <?php endif; ?>

        <!-- Быстрые действия -->
        <li class="nav-section">
            <span class="section-label">БЫСТРЫЕ ДЕЙСТВИЯ</span>
        </li>
        <li class="has-submenu">
            <a href="#">
                <i class="fas fa-plus-circle"></i> 
                <span>Создать новый</span>
                <i class="fas fa-chevron-down dropdown-arrow"></i>
            </a>
            <ul class="submenu">
                <?php if ($user['role'] == 'teacher' || $user['role'] == 'admin'): ?>
                <li><a href="create_test.php"><i class="fas fa-file-alt"></i> Новый тест</a></li>
                <li><a href="create_question.php"><i class="fas fa-question-circle"></i> Новый вопрос</a></li>
                <li><a href="create_announcement.php"><i class="fas fa-bullhorn"></i> Новое объявление</a></li>
                <?php endif; ?>
                <li><a href="create_message.php"><i class="fas fa-envelope"></i> Новое сообщение</a></li>
                <li><a href="create_task.php"><i class="fas fa-tasks"></i> Новая задача</a></li>
            </ul>
        </li>

        <!-- Персональный раздел -->
        <li class="nav-section">
            <span class="section-label">ПЕРСОНАЛЬНЫЙ РАЗДЕЛ</span>
        </li>
        <li><a href="profile.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'profile.php' ? 'active' : ''; ?>">
            <i class="fas fa-user"></i> 
            <span>Мой профиль</span>
        </a></li>
        <li><a href="settings.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'active' : ''; ?>">
            <i class="fas fa-cog"></i> 
            <span>Настройки</span>
        </a></li>
        <li><a href="help.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'help.php' ? 'active' : ''; ?>">
            <i class="fas fa-question-circle"></i> 
            <span>Помощь</span>
        </a></li>
        <li><a href="feedback.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'feedback.php' ? 'active' : ''; ?>">
            <i class="fas fa-comment-medical"></i> 
            <span>Обратная связь</span>
        </a></li>
        <li><a href="logout.php" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i> 
            <span>Выход</span>
        </a></li>
    </ul>

    <!-- Footer Sidebar -->
    <div class="sidebar-footer">
        <div class="system-info">
            <div class="info-item">
                <i class="fas fa-database"></i>
                <span>База данных: Online</span>
            </div>
            <div class="info-item">
                <i class="fas fa-server"></i>
                <span>AI модели: Active</span>
            </div>
        </div>
        <div class="quick-actions">
            <button class="quick-btn" onclick="openModal('ai_assistant_modal')">
                <i class="fas fa-robot"></i>
                <span>AI Помощник</span>
            </button>
            <button class="quick-btn" onclick="openModal('search_modal')">
                <i class="fas fa-search"></i>
                <span>Поиск</span>
            </button>
        </div>
    </div>
</div>

<!-- Mobile Menu Toggle -->
<div class="mobile-menu-toggle" id="mobileMenuToggle">
    <i class="fas fa-bars"></i>
</div>

<!-- Модальные окна -->
<div class="modal-overlay" id="ai_assistant_modal">
    <div class="modal-content assistant-modal">
        <div class="modal-header">
            <h2><i class="fas fa-robot"></i> AI Ассистент</h2>
            <button class="modal-close" onclick="closeModal('ai_assistant_modal')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="assistant-chat">
                <div class="chat-messages" id="assistantMessages">
                    <div class="message bot-message">
                        <div class="message-avatar">
                            <i class="fas fa-robot"></i>
                        </div>
                        <div class="message-content">
                            <p>Привет! Я ваш AI ассистент. Чем могу помочь?</p>
                        </div>
                    </div>
                </div>
                <div class="chat-input">
                    <input type="text" id="assistantInput" placeholder="Задайте вопрос...">
                    <button onclick="sendAssistantMessage()">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal-overlay" id="search_modal">
    <div class="modal-content search-modal">
        <div class="modal-header">
            <h2><i class="fas fa-search"></i> Глобальный поиск</h2>
            <button class="modal-close" onclick="closeModal('search_modal')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="search-container">
                <input type="text" id="globalSearch" placeholder="Поиск тестов, студентов, вопросов...">
                <div class="search-results" id="searchResults">
                    <!-- Результаты поиска будут здесь -->
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    /* Дополнительные стили для расширенного меню */
    .nav-section {
        padding: 15px 20px 5px;
        border-bottom: 1px solid rgba(255,255,255,0.1);
        margin-bottom: 10px;
    }
    
    .section-label {
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: rgba(255,255,255,0.5);
    }
    
    .nav-badge {
        background: var(--accent);
        color: white;
        border-radius: 10px;
        padding: 2px 8px;
        font-size: 11px;
        font-weight: 600;
        margin-left: auto;
    }
    
    .quick-stats {
        padding: 15px 20px;
        border-bottom: 1px solid rgba(255,255,255,0.1);
        margin-bottom: 10px;
    }
    
    .stat-item {
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 13px;
        color: rgba(255,255,255,0.8);
        margin-bottom: 8px;
    }
    
    .stat-item i {
        width: 16px;
        text-align: center;
    }
    
    .has-submenu {
        position: relative;
    }
    
    .submenu {
        display: none;
        list-style: none;
        padding-left: 20px;
        background: rgba(0,0,0,0.2);
        border-radius: 0 0 8px 8px;
        margin-top: 5px;
    }
    
    .has-submenu.active .submenu {
        display: block;
    }
    
    .submenu a {
        padding: 10px 15px !important;
        font-size: 14px;
    }
    
    .dropdown-arrow {
        margin-left: auto;
        transition: transform 0.3s;
    }
    
    .has-submenu.active .dropdown-arrow {
        transform: rotate(180deg);
    }
    
    .sidebar-footer {
        padding: 15px 20px;
        border-top: 1px solid rgba(255,255,255,0.1);
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
        color: rgba(255,255,255,0.7);
        margin-bottom: 5px;
    }
    
    .quick-actions {
        display: flex;
        gap: 10px;
    }
    
    .quick-btn {
        flex: 1;
        background: rgba(255,255,255,0.1);
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
        background: rgba(255,255,255,0.2);
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
        box-shadow: 0 2px 10px rgba(0,0,0,0.2);
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
    }
</style>

<script>
    // Управление мобильным меню
    document.getElementById('mobileMenuToggle').addEventListener('click', function() {
        document.querySelector('.sidebar').classList.toggle('mobile-open');
    });
    
    // Управление подменю
    document.querySelectorAll('.has-submenu > a').forEach(item => {
        item.addEventListener('click', function(e) {
            e.preventDefault();
            const parent = this.parentElement;
            parent.classList.toggle('active');
        });
    });
    
    // Функции для модальных окон
    function openModal(modalId) {
        document.getElementById(modalId).classList.add('active');
    }
    
    function closeModal(modalId) {
        document.getElementById(modalId).classList.remove('active');
    }
    
    // Закрытие модальных окон по клику вне
    document.querySelectorAll('.modal-overlay').forEach(modal => {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                this.classList.remove('active');
            }
        });
    });
    
    // Глобальный поиск
    document.getElementById('globalSearch').addEventListener('input', function() {
        const query = this.value.trim();
        if (query.length > 2) {
            // Здесь будет AJAX запрос для поиска
            performSearch(query);
        }
    });
    
    function performSearch(query) {
        // Заглушка для поиска
        const results = document.getElementById('searchResults');
        results.innerHTML = `
            <div class="search-result-item">
                <i class="fas fa-file-alt"></i>
                <div>
                    <h4>Тест: "${query}"</h4>
                    <p>Найдено в названиях тестов</p>
                </div>
            </div>
            <div class="search-result-item">
                <i class="fas fa-user"></i>
                <div>
                    <h4>Студенты</h4>
                    <p>Найдено 5 студентов</p>
                </div>
            </div>
        `;
    }
    
    // AI Ассистент
    function sendAssistantMessage() {
        const input = document.getElementById('assistantInput');
        const message = input.value.trim();
        
        if (message) {
            const messagesContainer = document.getElementById('assistantMessages');
            
            // Добавляем сообщение пользователя
            messagesContainer.innerHTML += `
                <div class="message user-message">
                    <div class="message-content">
                        <p>${message}</p>
                    </div>
                    <div class="message-avatar">
                        <i class="fas fa-user"></i>
                    </div>
                </div>
            `;
            
            // Очищаем input
            input.value = '';
            
            // Имитируем ответ AI
            setTimeout(() => {
                messagesContainer.innerHTML += `
                    <div class="message bot-message">
                        <div class="message-avatar">
                            <i class="fas fa-robot"></i>
                        </div>
                        <div class="message-content">
                            <p>Я обрабатываю ваш запрос: "${message}". Это демо-версия AI ассистента.</p>
                        </div>
                    </div>
                `;
                messagesContainer.scrollTop = messagesContainer.scrollHeight;
            }, 1000);
            
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }
    }
    
    // Отправка сообщения по Enter
    document.getElementById('assistantInput').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            sendAssistantMessage();
        }
    });
</script>