<?php
include 'config.php';
session_start();

header('Content-Type: application/json');

// Проверяем авторизацию
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Необходима авторизация']);
    exit;
}

// Проверяем права доступа
$stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if ($user['role'] == 'student') {
    echo json_encode(['success' => false, 'message' => 'Доступ запрещен']);
    exit;
}

// Обработка добавления вопроса
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_question'])) {
    $test_id = intval($_POST['test_id']);
    $question_text = trim($_POST['question_text']);
    $question_type = $_POST['question_type'];
    $points = intval($_POST['points']);
    
    // Проверяем владельца теста
    $stmt = $pdo->prepare("SELECT created_by FROM tests WHERE id = ?");
    $stmt->execute([$test_id]);
    $test = $stmt->fetch();
    
    if (!$test || $test['created_by'] != $_SESSION['user_id']) {
        echo json_encode(['success' => false, 'message' => 'Доступ запрещен']);
        exit;
    }
    
    if (!empty($question_text)) {
        try {
            // Определяем порядок сортировки
            $stmt = $pdo->prepare("SELECT MAX(sort_order) as max_order FROM questions WHERE test_id = ?");
            $stmt->execute([$test_id]);
            $max_order = $stmt->fetch()['max_order'] ?? 0;
            $sort_order = $max_order + 1;
            
            $stmt = $pdo->prepare("INSERT INTO questions (test_id, question_text, question_type, points, sort_order) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$test_id, $question_text, $question_type, $points, $sort_order]);
            
            $question_id = $pdo->lastInsertId();
            
            // Если вопрос с выбором ответов, добавляем варианты
            if ($question_type == 'multiple_choice' && isset($_POST['options'])) {
                $options = $_POST['options'];
                $correct_option = intval($_POST['correct_option']);
                
                foreach ($options as $index => $option_text) {
                    if (!empty(trim($option_text))) {
                        $is_correct = ($index == $correct_option) ? 1 : 0;
                        $stmt = $pdo->prepare("INSERT INTO question_options (question_id, option_text, is_correct) VALUES (?, ?, ?)");
                        $stmt->execute([$question_id, trim($option_text), $is_correct]);
                    }
                }
            }
            
            echo json_encode(['success' => true]);
            exit;
            
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Ошибка при добавлении вопроса: ' . $e->getMessage()]);
            exit;
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Текст вопроса обязателен']);
        exit;
    }
}

// Обработка удаления вопроса
if (isset($_GET['delete_question'])) {
    $question_id = intval($_GET['delete_question']);
    $test_id = intval($_GET['test_id']);
    
    // Проверяем владельца теста
    $stmt = $pdo->prepare("SELECT q.test_id, t.created_by 
                          FROM questions q 
                          JOIN tests t ON q.test_id = t.id 
                          WHERE q.id = ?");
    $stmt->execute([$question_id]);
    $question = $stmt->fetch();
    
    if (!$question || $question['created_by'] != $_SESSION['user_id'] || $question['test_id'] != $test_id) {
        echo json_encode(['success' => false, 'message' => 'Доступ запрещен']);
        exit;
    }
    
    try {
        // Удаляем варианты ответов
        $stmt = $pdo->prepare("DELETE FROM question_options WHERE question_id = ?");
        $stmt->execute([$question_id]);
        
        // Удаляем вопрос
        $stmt = $pdo->prepare("DELETE FROM questions WHERE id = ?");
        $stmt->execute([$question_id]);
        
        echo json_encode(['success' => true]);
        exit;
        
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Ошибка при удалении вопроса: ' . $e->getMessage()]);
        exit;
    }
}

// Обработка изменения порядка вопросов
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (isset($input['update_order']) && isset($input['order']) && isset($input['test_id'])) {
        $order = $input['order'];
        $test_id = intval($input['test_id']);
        
        // Проверяем владельца теста
        $stmt = $pdo->prepare("SELECT created_by FROM tests WHERE id = ?");
        $stmt->execute([$test_id]);
        $test = $stmt->fetch();
        
        if (!$test || $test['created_by'] != $_SESSION['user_id']) {
            echo json_encode(['success' => false, 'message' => 'Доступ запрещен']);
            exit;
        }
        
        try {
            foreach ($order as $sort_order => $question_id) {
                $stmt = $pdo->prepare("UPDATE questions SET sort_order = ? WHERE id = ? AND test_id = ?");
                $stmt->execute([$sort_order + 1, $question_id, $test_id]);
            }
            
            echo json_encode(['success' => true]);
            exit;
            
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Ошибка при обновлении порядка: ' . $e->getMessage()]);
            exit;
        }
    }
}

echo json_encode(['success' => false, 'message' => 'Неизвестный запрос']);
?>