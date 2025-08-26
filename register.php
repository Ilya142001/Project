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
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    // Валидация на сервере
    if (empty($full_name) || empty($email) || empty($password) || empty($confirm_password)) {
        header("Location: index.html?message=" . urlencode("Все поля обязательны для заполнения") . "&type=error");
        exit;
    }

    if ($password !== $confirm_password) {
        header("Location: index.html?message=" . urlencode("Пароли не совпадают") . "&type=error");
        exit;
    }

    if (strlen($password) < 6) {
        header("Location: index.html?message=" . urlencode("Пароль должен содержать минимум 6 символов") . "&type=error");
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        header("Location: index.html?message=" . urlencode("Неверный формат email") . "&type=error");
        exit;
    }

    // Проверка существования email
    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
        $stmt->execute(['email' => $email]);
        
        if ($stmt->fetch()) {
            header("Location: index.html?message=" . urlencode("Пользователь с таким email уже существует") . "&type=error");
            exit;
        }

        // Хеширование пароля
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        // Создание пользователя
        $stmt = $pdo->prepare("INSERT INTO users (full_name, email, password, created_at) 
                              VALUES (:full_name, :email, :password, NOW())");
        
        if ($stmt->execute([
            'full_name' => $full_name,
            'email' => $email,
            'password' => $hashed_password
        ])) {
            // Успешная регистрация
            header("Location: index.html?message=" . urlencode("Регистрация прошла успешно! Теперь вы можете войти.") . "&type=success");
            exit;
        } else {
            header("Location: index.html?message=" . urlencode("Ошибка при регистрации") . "&type=error");
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