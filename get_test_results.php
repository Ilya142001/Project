<?php
include 'config.php';
session_start();

// Отключаем вывод ошибок на экран
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Сразу устанавливаем заголовок JSON
header('Content-Type: application/json; charset=utf-8');

// Проверяем авторизацию
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Не авторизован']);
    exit;
}

// Получаем ID теста
$test_id = intval($_GET['test_id'] ?? 0);

if (!$test_id) {
    echo json_encode(['success' => false, 'message' => 'ID теста не указан']);
    exit;
}

try {
    // Получаем информацию о пользователе
    $stmt = $pdo->prepare("SELECT id, role, full_name FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        throw new Exception("Пользователь не найден");
    }

    // Получаем информацию о тесте
    $stmt = $pdo->prepare("
        SELECT 
            t.*,
            u.full_name as creator_name
        FROM tests t
        LEFT JOIN users u ON t.created_by = u.id
        WHERE t.id = ?
    ");
    $stmt->execute([$test_id]);
    $test_info = $stmt->fetch();

    if (!$test_info) {
        throw new Exception("Тест не найден");
    }

    // Получаем статистику по тесту
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_attempts,
            AVG(percentage) as avg_score,
            MAX(percentage) as best_score,
            MIN(percentage) as worst_score,
            COUNT(DISTINCT user_id) as unique_students,
            SUM(CASE WHEN passed = 1 THEN 1 ELSE 0 END) as passed_attempts
        FROM test_results 
        WHERE test_id = ?
    ");
    $stmt->execute([$test_id]);
    $test_stats = $stmt->fetch();

    // Получаем историю прохождения
    if ($user['role'] == 'student') {
        // Для студентов - только их результаты
        $stmt = $pdo->prepare("
            SELECT 
                tr.*,
                t.title as test_title,
                t.subject
            FROM test_results tr
            JOIN tests t ON tr.test_id = t.id
            WHERE tr.user_id = ? AND tr.test_id = ?
            ORDER BY tr.completed_at DESC
        ");
        $stmt->execute([$_SESSION['user_id'], $test_id]);
    } else {
        // Для преподавателей - все результаты по тесту
        $stmt = $pdo->prepare("
            SELECT 
                tr.*,
                t.title as test_title,
                t.subject,
                u.full_name as student_name,
                u.group_name,
                u.email
            FROM test_results tr
            JOIN tests t ON tr.test_id = t.id
            JOIN users u ON tr.user_id = u.id
            WHERE t.id = ? AND t.created_by = ?
            ORDER BY tr.completed_at DESC
        ");
        $stmt->execute([$test_id, $_SESSION['user_id']]);
    }

    $results = $stmt->fetchAll();

    // Статистика для ответа
    $stats = [
        'total_attempts' => $test_stats['total_attempts'] ?? 0,
        'passed_attempts' => $test_stats['passed_attempts'] ?? 0,
        'failed_attempts' => ($test_stats['total_attempts'] ?? 0) - ($test_stats['passed_attempts'] ?? 0),
        'avg_score' => round($test_stats['avg_score'] ?? 0, 1),
        'best_score' => round($test_stats['best_score'] ?? 0, 1),
        'worst_score' => round($test_stats['worst_score'] ?? 100, 1),
        'unique_students' => $test_stats['unique_students'] ?? 0,
        'success_rate' => $test_stats['total_attempts'] > 0 ? 
            round(($test_stats['passed_attempts'] / $test_stats['total_attempts']) * 100, 1) : 0
    ];

    // Форматируем историю прохождения
    $attempts_history = [];
    foreach ($results as $index => $result) {
        $attempt = [
            'id' => $result['id'],
            'test_id' => $result['test_id'],
            'attempt_number' => count($results) - $index,
            'score' => $result['score'],
            'total_points' => $result['total_points'],
            'percentage' => $result['percentage'],
            'passed' => (bool)$result['passed'],
            'completed_at' => $result['completed_at'],
            'formatted_date' => date('d.m.Y H:i', strtotime($result['completed_at'])),
            'time_ago' => getTimeAgo($result['completed_at']),
            'status_class' => $result['passed'] ? 'success' : 'failed',
            'score_class' => getScoreClass($result['percentage']),
            'is_best' => $result['percentage'] == $stats['best_score'],
            'is_last' => $index == 0
        ];

        // Информация о студенте для преподавателей
        if ($user['role'] != 'student') {
            $attempt['student_name'] = $result['student_name'] ?? '';
            $attempt['group_name'] = $result['group_name'] ?? '';
            $attempt['email'] = $result['email'] ?? '';
        }

        $attempts_history[] = $attempt;
    }

    // Подготавливаем финальный ответ
    $response = [
        'success' => true,
        'test_info' => [
            'id' => $test_info['id'],
            'title' => $test_info['title'],
            'subject' => $test_info['subject'],
            'description' => $test_info['description'],
            'time_limit' => $test_info['time_limit'],
            'creator_name' => $test_info['creator_name'],
            'created_at' => $test_info['created_at'],
            'is_active' => (bool)$test_info['is_active']
        ],
        'statistics' => $stats,
        'attempts_history' => $attempts_history,
        'user_info' => [
            'role' => $user['role'],
            'user_name' => $user['full_name']
        ],
        'summary' => [
            'total_attempts' => count($attempts_history),
            'has_attempts' => !empty($attempts_history)
        ]
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("Error in get_test_results.php: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Ошибка загрузки: ' . $e->getMessage()
    ]);
}

// Вспомогательные функции
function getScoreClass($percentage) {
    if ($percentage >= 80) return 'excellent';
    if ($percentage >= 60) return 'good';
    return 'poor';
}

function getTimeAgo($datetime) {
    $time = strtotime($datetime);
    $time_diff = time() - $time;
    
    if ($time_diff < 60) {
        return 'только что';
    } elseif ($time_diff < 3600) {
        $mins = floor($time_diff / 60);
        return $mins . ' ' . getNoun($mins, ['минуту', 'минуты', 'минут']) . ' назад';
    } elseif ($time_diff < 86400) {
        $hours = floor($time_diff / 3600);
        return $hours . ' ' . getNoun($hours, ['час', 'часа', 'часов']) . ' назад';
    } elseif ($time_diff < 2592000) {
        $days = floor($time_diff / 86400);
        return $days . ' ' . getNoun($days, ['день', 'дня', 'дней']) . ' назад';
    } else {
        return date('d.m.Y', $time);
    }
}

function getNoun($number, $titles) {
    $cases = [2, 0, 1, 1, 1, 2];
    return $titles[($number % 100 > 4 && $number % 100 < 20) ? 2 : $cases[min($number % 10, 5)]];
}
?>