<?php
session_start();
include 'config.php';

// Проверяем авторизацию
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

// Проверяем права (только преподаватели и админы могут удалять)
$stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if ($user['role'] != 'teacher' && $user['role'] != 'admin') {
    header("Location: tests.php");
    exit;
}

// Проверяем, передан ли ID теста
if (!isset($_GET['id'])) {
    header("Location: tests.php");
    exit;
}

$test_id = intval($_GET['id']);

// Проверяем, принадлежит ли тест текущему пользователю (если не админ)
if ($user['role'] != 'admin') {
    $stmt = $pdo->prepare("SELECT id FROM tests WHERE id = ? AND created_by = ?");
    $stmt->execute([$test_id, $_SESSION['user_id']]);
    if ($stmt->rowCount() == 0) {
        header("Location: tests.php");
        exit;
    }
}

try {
    // Начинаем транзакцию
    $pdo->beginTransaction();
    
    // 1. Удаляем результаты теста
    $stmt = $pdo->prepare("DELETE FROM test_results WHERE test_id = ?");
    $stmt->execute([$test_id]);
    
    // 2. Удаляем вопросы теста
    $stmt = $pdo->prepare("DELETE FROM questions WHERE test_id = ?");
    $stmt->execute([$test_id]);
    
    // 3. Удаляем варианты ответов (если есть отдельная таблица)
    // $stmt = $pdo->prepare("DELETE FROM answers WHERE question_id IN (SELECT id FROM questions WHERE test_id = ?)");
    // $stmt->execute([$test_id]);
    
    // 4. Удаляем сам тест
    $stmt = $pdo->prepare("DELETE FROM tests WHERE id = ?");
    $stmt->execute([$test_id]);
    
    // Подтверждаем транзакцию
    $pdo->commit();
    
    $_SESSION['success_message'] = "Тест успешно удален!";
    
} catch (Exception $e) {
    // Откатываем транзакцию в случае ошибки
    $pdo->rollBack();
    $_SESSION['error_message'] = "Ошибка при удалении теста: " . $e->getMessage();
}

header("Location: tests.php");
exit;
?>