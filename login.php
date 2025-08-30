<?php
include 'config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = $_POST['email'];
    $password = $_POST['password'];
    
    // Проверяем, существует ли пользователь
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_name'] = $user['full_name'];
        $_SESSION['user_role'] = $user['role'];
        
        echo json_encode([
            'success' => true,
            'message' => 'Вход выполнен успешно!',
            'redirect' => 'dashboard.php'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Неверный email или пароль.'
        ]);
    }
}
?>