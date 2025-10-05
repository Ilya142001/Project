<?php
// sidebar_menu.php
include 'config.php';

if (!isset($_SESSION['user_id'])) {
    return;
}

// Получаем информацию о пользователе
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

// Получаем непрочитанные сообщения (ИСПРАВЛЕННЫЙ ЗАПРОС)
$unread_count = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as unread_count FROM messages WHERE receiver_id = ? AND is_read = FALSE");
    $stmt->execute([$_SESSION['user_id']]);
    $result = $stmt->fetch();
    $unread_count = $result ? $result['unread_count'] : 0;
} catch (PDOException $e) {
    error_log("Error counting unread messages: " . $e->getMessage());
    $unread_count = 0;
}
?>

<!-- HTML код сайдбара -->
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
            <i class="fas fa-envelope"></i>
            <span>Непрочитанных: <?php echo $unread_count; ?></span>
        </div>
        <div class="stat-item">
            <i class="fas fa-users"></i>
            <span>Групп: 0</span>
        </div>
        <div class="stat-item">
            <i class="fas fa-comments"></i>
            <span>Контактов: 0</span>
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
        <li><a href="skill_map.php"><i class="fas fa-map"></i> Карта навыков</a></li>
        <li><a href="messages.php"><i class="fas fa-comments"></i> Сообщения</a></li>
        
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

<script>
function openHelp() {
    alert('Раздел помощи будет реализован позже');
}

function openFeedback() {
    alert('Форма обратной связи будет реализована позже');
}

// Управление мобильным меню
document.addEventListener('DOMContentLoaded', function() {
    const mobileMenuToggle = document.getElementById('mobileMenuToggle');
    const sidebar = document.getElementById('sidebar');
    
    if (mobileMenuToggle) {
        mobileMenuToggle.addEventListener('click', function() {
            sidebar.classList.toggle('mobile-open');
        });
    }
});
</script>