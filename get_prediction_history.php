<?php
// get_prediction_history.php
include 'config.php';
include 'ml_model_handler.php';

// Проверяем авторизацию
if (!isset($_SESSION['user_id'])) {
    header("HTTP/1.1 401 Unauthorized");
    exit;
}

$test_result_id = $_GET['test_result_id'] ?? null;

if (!$test_result_id) {
    header("HTTP/1.1 400 Bad Request");
    exit;
}

// Создаем обработчик моделей
$modelHandler = new MLModelHandler($pdo);

// Получаем все предсказания для результата теста
$predictions = $modelHandler->getPredictionsForTestResult($test_result_id);

// Получаем информацию о результате теста
$stmt = $pdo->prepare("
    SELECT tr.*, u.full_name as student_name, t.title as test_title
    FROM test_results tr
    JOIN users u ON tr.user_id = u.id
    JOIN tests t ON tr.test_id = t.id
    WHERE tr.id = ?
");
$stmt->execute([$test_result_id]);
$test_result = $stmt->fetch();

if (!$test_result) {
    echo '<div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> Результат теста не найден</div>';
    exit;
}
?>

<div class="modal-section">
    <h3 class="modal-section-title">
        <i class="fas fa-user-graduate"></i> Информация о студенте
    </h3>
    <div class="detail-grid">
        <div class="detail-item">
            <span class="detail-label">Студент:</span>
            <span class="detail-value"><?php echo htmlspecialchars($test_result['student_name']); ?></span>
        </div>
        <div class="detail-item">
            <span class="detail-label">Тест:</span>
            <span class="detail-value"><?php echo htmlspecialchars($test_result['test_title']); ?></span>
        </div>
        <div class="detail-item">
            <span class="detail-label">Реальный результат:</span>
            <span class="detail-value"><?php echo $test_result['score']; ?>/<?php echo $test_result['total_points']; ?> (<?php echo $test_result['percentage']; ?>%)</span>
        </div>
        <div class="detail-item">
            <span class="detail-label">Статус:</span>
            <span class="detail-value">
                <?php if ($test_result['passed']): ?>
                    <span style="color: #28a745;"><i class="fas fa-check-circle"></i> Пройден</span>
                <?php else: ?>
                    <span style="color: #dc3545;"><i class="fas fa-times-circle"></i> Не пройден</span>
                <?php endif; ?>
            </span>
        </div>
    </div>
</div>

<div class="modal-section">
    <h3 class="modal-section-title">
        <i class="fas fa-history"></i> История AI оценок (<?php echo count($predictions); ?>)
    </h3>
    
    <?php if (!empty($predictions)): ?>
        <div class="prediction-history">
            <?php foreach ($predictions as $prediction): ?>
                <div class="history-item">
                    <div class="history-header">
                        <strong><?php echo htmlspecialchars($prediction['model_name']); ?></strong>
                        <span class="history-date">
                            <?php echo date('d.m.Y H:i', strtotime($prediction['created_at'])); ?>
                        </span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <span>Предсказанная оценка: <strong><?php echo $prediction['predicted_score']; ?>/<?php echo $test_result['total_points']; ?></strong></span>
                        <span class="confidence-badge">Уверенность: <?php echo round($prediction['confidence'] * 100); ?>%</span>
                    </div>
                    <?php if (!empty($prediction['explanation'])): ?>
                        <div style="font-size: 0.85rem; color: var(--gray); background: var(--light); padding: 8px; border-radius: 6px;">
                            <strong>Объяснение:</strong> <?php echo htmlspecialchars($prediction['explanation']); ?>
                        </div>
                    <?php endif; ?>
                    <div style="margin-top: 5px; font-size: 0.75rem; color: var(--gray);">
                        ID предсказания: <?php echo $prediction['id']; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="empty-state" style="padding: 20px;">
            <i class="fas fa-robot" style="font-size: 2rem;"></i>
            <h4>Нет оценок AI</h4>
            <p>Для этого результата еще не выполнены AI оценки</p>
        </div>
    <?php endif; ?>
</div>