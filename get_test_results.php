<?php
// Включите отображение ошибок для отладки
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Подключаем конфигурацию базы данных
include 'config.php';

// Запускаем сессию
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Проверяем авторизацию
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Не авторизован']);
    exit;
}

// Получаем ID теста
$test_id = isset($_GET['test_id']) ? intval($_GET['test_id']) : 0;

if ($test_id <= 0) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Неверный ID теста']);
    exit;
}

try {
    // Проверяем подключение к базе данных
    if (!$pdo) {
        throw new Exception('Нет подключения к базе данных');
    }

    $user_id = $_SESSION['user_id'];

    // Получаем результаты только для текущего пользователя и выбранного теста
    $stmt = $pdo->prepare("
        SELECT 
            tr.*,
            t.title as test_title,
            t.total_points,
            t.subject,
            t.created_at
        FROM test_results tr
        JOIN tests t ON tr.test_id = t.id
        WHERE tr.test_id = ? AND tr.user_id = ?
        ORDER BY tr.attempt_number DESC
    ");
    
    if (!$stmt->execute([$test_id, $user_id])) {
        throw new Exception('Ошибка выполнения запроса');
    }
    
    $attempts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Получаем статистику
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_attempts,
            SUM(CASE WHEN passed = 1 THEN 1 ELSE 0 END) as passed_attempts,
            AVG(percentage) as avg_score,
            MAX(percentage) as best_score
        FROM test_results 
        WHERE test_id = ? AND user_id = ?
    ");
    
    if (!$stmt->execute([$test_id, $user_id])) {
        throw new Exception('Ошибка выполнения запроса статистики');
    }
    
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Формируем ответ
    $response = [
        'attempts' => $attempts ?: [],
        'statistics' => [
            'total_attempts' => intval($stats['total_attempts'] ?? 0),
            'passed_attempts' => intval($stats['passed_attempts'] ?? 0),
            'avg_score' => $stats['avg_score'] ? round(floatval($stats['avg_score']), 1) : 0,
            'best_score' => $stats['best_score'] ? round(floatval($stats['best_score']), 1) : 0
        ]
    ];

    // Устанавливаем заголовки и возвращаем JSON
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    // Логируем ошибку
    error_log("Error in get_test_results.php: " . $e->getMessage());
    
    // Возвращаем ошибку
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Внутренняя ошибка сервера',
        'debug' => $e->getMessage() // Уберите это в продакшене
    ]);
}
?>