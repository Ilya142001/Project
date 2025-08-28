<?php
include 'config.php';

// Обработка данных формы
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $user_input = trim($_POST['username']);
    $password = $_POST['password'];
    
    // Проверка на пустые поля
    if (empty($user_input) || empty($password)) {
        die("Все поля обязательны для заполнения.");
    }
    
    // Поиск пользователя по имени пользователя или email
    $stmt = $conn->prepare("SELECT id, username, password FROM users WHERE username = ? OR email = ?");
    $stmt->bind_param("ss", $user_input, $user_input);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        
        // Проверка пароля
        if (password_verify($password, $user['password'])) {
            // Запуск сессии
            session_start();
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            
            // Перенаправление на защищенную страницу
            header("Location: dashboard.php");
            exit();
        } else {
            echo "Неверный пароль. <a href='index.html'>Попробовать снова</a>";
        }
    } else {
        echo "Пользователь не найден. <a href='index.html'>Попробовать снова</a> или <a href='index.html#show-register'>зарегистрироваться</a>";
    }
    
    $stmt->close();
    $conn->close();
}
?>