<?php
include 'config.php';

// Обработка данных формы
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Проверка на пустые поля
    if (empty($username) || empty($email) || empty($password) || empty($confirm_password)) {
        die("Все поля обязательны для заполнения.");
    }
    
    // Проверка совпадения паролей
    if ($password !== $confirm_password) {
        die("Пароли не совпадают. <a href='index.html#show-register'>Попробовать снова</a>");
    }
    
    // Проверка длины пароля
    if (strlen($password) < 6) {
        die("Пароль должен содержать не менее 6 символов. <a href='index.html#show-register'>Попробовать снова</a>");
    }
    
    // Хеширование пароля
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    
    // Проверка существования пользователя
    $check_user = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
    $check_user->bind_param("ss", $username, $email);
    $check_user->execute();
    $check_user->store_result();
    
    if ($check_user->num_rows > 0) {
        die("Пользователь с таким именем или email уже существует. <a href='index.html#show-register'>Попробовать снова</a>");
    }
    $check_user->close();
    
    // Вставка нового пользователя в базу данных
    $stmt = $conn->prepare("INSERT INTO users (username, email, password) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $username, $email, $hashed_password);
    
    if ($stmt->execute()) {
        echo "Регистрация успешна! <a href='index.html'>Войти</a>";
    } else {
        echo "Ошибка: " . $stmt->error . " <a href='index.html#show-register'>Попробовать снова</a>";
    }
    
    $stmt->close();
    $conn->close();
}
?>