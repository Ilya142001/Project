<?php
include 'config.php';

// Проверяем, был ли передан параметр подтверждения
if (isset($_GET['confirm']) && $_GET['confirm'] === 'yes') {
    // Уничтожаем сессию
    session_destroy();
    // Перенаправляем на главную страницу
    header("Location: index.php");
    exit;
}

// Если параметр confirm не равен 'yes', показываем страницу с JavaScript подтверждением
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Выход из системы</title>
    <script>
        function confirmLogout() {
            if (confirm('Вы точно хотите выйти?')) {
                // Если пользователь нажал "Да", перенаправляем с подтверждением
                window.location.href = 'logout.php?confirm=yes';
            } else {
                // Если пользователь нажал "Нет", возвращаем на предыдущую страницу
                window.history.back();
            }
        }
        
        // Автоматически вызываем подтверждение при загрузке страницы
        window.onload = confirmLogout;
    </script>
</head>
<body>
    <div style="text-align: center; margin-top: 50px;">
        <p>Перенаправление...</p>
    </div>
</body>
</html>