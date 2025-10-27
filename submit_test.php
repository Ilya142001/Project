<?php
include 'config.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $test_id = $_POST['test_id'];
    $user_id = $_SESSION['user_id'];
    $attempt_number = $_POST['attempt_number'];
    
    // Здесь должна быть логика проверки ответов и расчета результата
    // Это упрощенный пример
    
    $score = 0;
    $total_points = 0;
    
    // Расчет результатов
    foreach ($_POST as $key => $value) {
        if (strpos($key, 'question_') === 0) {
            $question_id = str_replace('question_', '', $key);
            $selected_option_id = $value;
            
            // Проверяем правильность ответа
            $stmt = $pdo->prepare("SELECT points, is_correct FROM questions q JOIN question_options o ON q.id = o.question_id WHERE o.id = ?");
            $stmt->execute([$selected_option_id]);
            $result = $stmt->fetch();
            
            if ($result && $result['is_correct']) {
                $score += $result['points'];
            }
            $total_points += $result['points'];
        }
    }
    
    $percentage = $total_points > 0 ? round(($score / $total_points) * 100, 1) : 0;
    $passed = $percentage >= 60; // Порог сдачи 60%
    
    // Сохраняем результат
    $stmt = $pdo->prepare("
        INSERT INTO test_results (test_id, user_id, score, total_points, percentage, passed, attempt_number) 
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$test_id, $user_id, $score, $total_points, $percentage, $passed, $attempt_number]);
    
    // Перенаправляем на страницу результатов
    header("Location: test_results.php?test_id=" . $test_id);
    exit;
}
?>