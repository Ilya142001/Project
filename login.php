<?php
session_start();

// Подключение к базе данных
$host = 'localhost';
$dbname = 'training_system';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    header("Location: index.html?message=" . urlencode("Ошибка подключения к базе данных") . "&type=error");
    exit;
}

// Проверяем, что форма была отправлена
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Получаем данные из формы
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    // Валидация на сервере
    if (empty($email) || empty($password)) {
        header("Location: index.html?message=" . urlencode("Все поля обязательны для заполнения") . "&type=error");
        exit;
    }

    // Поиск пользователя в базе данных
    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
            // Успешный вход
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['full_name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['logged_in'] = true;
            
            // Перенаправление на защищенную страницу
            header("Location: Main_page\dashboard.php");
            exit;
        } else {
            // Неверные credentials
            header("Location: index.html?message=" . urlencode("Неверный email или пароль") . "&type=error");
            exit;
        }
    } catch (PDOException $e) {
        header("Location: index.html?message=" . urlencode("Ошибка базы данных") . "&type=error");
        exit;
    }
} else {
    // Если не POST запрос, перенаправляем на главную
    header("Location: index.html");
    exit;
}
?>