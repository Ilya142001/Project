<?php
include 'config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $full_name = $_POST['full_name'];
    $email = $_POST['email'];
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Проверяем, совпадают ли пароли
    if ($password !== $confirm_password) {
        echo json_encode([
            'success' => false,
            'message' => 'Пароли не совпадают.'
        ]);
        exit;
    }
    
    // Проверяем, не существует ли уже пользователь с таким email
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    
    if ($stmt->rowCount() > 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Пользователь с таким email уже существует.'
        ]);
        exit;
    }
    
    // Хешируем пароль и сохраняем пользователя
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (email, password, full_name) VALUES (?, ?, ?)");
    
    if ($stmt->execute([$email, $hashed_password, $full_name])) {
        echo json_encode([
            'success' => true,
            'message' => 'Регистрация прошла успешно! Теперь вы можете войти.',
            'redirect' => 'index.php'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Ошибка регистрации. Пожалуйста, попробуйте снова.'
        ]);
    }
}
?>