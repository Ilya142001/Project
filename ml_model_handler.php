<?php
// ml_model_handler.php
class MLModelHandler {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Применить модель к результату теста
     */
    public function applyToTestResult($model_id, $test_result_id) {
        try {
            // 1. Получаем информацию о модели
            $model = $this->getModel($model_id);
            if (!$model || !$model['is_active']) {
                throw new Exception("Модель не найдена или неактивна");
            }
            
            // 2. Получаем данные результата теста
            $test_data = $this->getTestResultData($test_result_id);
            if (!$test_data) {
                throw new Exception("Данные теста не найдены");
            }
            
            // 3. Подготавливаем данные для модели
            $input_data = $this->prepareInputData($test_data);
            
            // 4. Выполняем предсказание
            $prediction = $this->makePrediction($model, $input_data);
            
            // 5. Сохраняем результат предсказания
            $this->savePrediction($model_id, $test_result_id, $prediction);
            
            return $prediction;
            
        } catch (Exception $e) {
            error_log("ML Model Error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Получить информацию о модели
     */
    private function getModel($model_id) {
        $stmt = $this->pdo->prepare("SELECT * FROM ml_models WHERE id = ?");
        $stmt->execute([$model_id]);
        return $stmt->fetch();
    }
    
    /**
     * Получить данные результата теста
     */
    private function getTestResultData($test_result_id) {
        $stmt = $this->pdo->prepare("
            SELECT 
                tr.*,
                t.title as test_title,
                u.full_name as student_name,
                GROUP_CONCAT(
                    CONCAT(q.question_text, '|', ua.answer_text, '|', ua.is_correct)
                    SEPARATOR ';;'
                ) as answers_data
            FROM test_results tr
            JOIN tests t ON tr.test_id = t.id
            JOIN users u ON tr.user_id = u.id
            LEFT JOIN user_answers ua ON tr.id = ua.result_id
            LEFT JOIN questions q ON ua.question_id = q.id
            WHERE tr.id = ?
            GROUP BY tr.id
        ");
        $stmt->execute([$test_result_id]);
        return $stmt->fetch();
    }
    
    /**
     * Подготовить данные для модели
     */
    private function prepareInputData($test_data) {
        // Парсим ответы
        $answers = [];
        if (!empty($test_data['answers_data'])) {
            $answer_parts = explode(';;', $test_data['answers_data']);
            foreach ($answer_parts as $part) {
                list($question, $answer, $is_correct) = explode('|', $part);
                $answers[] = [
                    'question' => $question,
                    'answer' => $answer,
                    'is_correct' => (bool)$is_correct
                ];
            }
        }
        
        return [
            'test_id' => $test_data['test_id'],
            'test_title' => $test_data['test_title'],
            'student_id' => $test_data['user_id'],
            'student_name' => $test_data['student_name'],
            'score' => $test_data['score'],
            'total_points' => $test_data['total_points'],
            'percentage' => $test_data['percentage'],
            'time_spent' => $test_data['time_spent'],
            'answers' => $answers,
            'completed_at' => $test_data['completed_at']
        ];
    }
    
    /**
     * Выполнить предсказание
     */
    private function makePrediction($model, $input_data) {
        // В реальной системе здесь будет вызов ML модели
        // Сейчас используем простую логику на основе названия модели
        
        $model_name = strtolower($model['name']);
        
        if (strpos($model_name, 'essay') !== false) {
            // Модель оценки эссе
            $prediction = $this->predictEssayScore($input_data);
        } elseif (strpos($model_name, 'plagiarism') !== false) {
            // Детектор плагиата
            $prediction = $this->predictPlagiarism($input_data);
        } elseif (strpos($model_name, 'complexity') !== false) {
            // Классификатор сложности
            $prediction = $this->predictComplexity($input_data);
        } else {
            // Общая модель
            $prediction = $this->predictGeneral($input_data);
        }
        
        return $prediction;
    }
    
    private function predictEssayScore($input_data) {
        // Простая логика оценки текстовых ответов
        $text_answers = array_filter($input_data['answers'], function($answer) {
            return !is_numeric($answer['answer']); // Текстовые ответы
        });
        
        $avg_length = 0;
        if (!empty($text_answers)) {
            $lengths = array_map(function($answer) {
                return strlen($answer['answer']);
            }, $text_answers);
            $avg_length = array_sum($lengths) / count($lengths);
        }
        
        $score = min(1.0, $avg_length / 500); // Нормализуем по длине
        
        return [
            'prediction' => round($score * $input_data['total_points'], 1),
            'confidence' => max(0.7, min(0.95, $score)),
            'explanation' => 'Оценка на основе анализа текстовых ответов',
            'details' => [
                'avg_answer_length' => round($avg_length, 1),
                'text_answers_count' => count($text_answers)
            ]
        ];
    }
    
    private function predictPlagiarism($input_data) {
        // Простая логика детекции плагиата
        $similarity_score = 0.1; // Базовый уровень
        $text_answers = array_filter($input_data['answers'], function($answer) {
            return !is_numeric($answer['answer']) && strlen($answer['answer']) > 10;
        });
        
        if (!empty($text_answers)) {
            // Простая эвристика: короткие ответы = выше риск плагиата
            $short_answers = array_filter($text_answers, function($answer) {
                return strlen($answer['answer']) < 50;
            });
            $similarity_score = min(0.8, count($short_answers) / count($text_answers) * 0.7);
        }
        
        return [
            'prediction' => $similarity_score,
            'confidence' => 0.85,
            'explanation' => $similarity_score > 0.5 ? 'Высокая вероятность заимствований' : 'Низкая вероятность заимствований',
            'risk_level' => $similarity_score > 0.7 ? 'high' : ($similarity_score > 0.3 ? 'medium' : 'low')
        ];
    }
    
    private function predictComplexity($input_data) {
        // Анализ сложности вопросов
        $complex_questions = 0;
        foreach ($input_data['answers'] as $answer) {
            $words = str_word_count($answer['question']);
            if ($words > 15) $complex_questions++;
        }
        
        $complexity_ratio = count($input_data['answers']) > 0 ? 
            $complex_questions / count($input_data['answers']) : 0;
            
        return [
            'prediction' => $complexity_ratio,
            'confidence' => 0.9,
            'explanation' => 'Анализ сложности вопросов теста',
            'complexity_level' => $complexity_ratio > 0.7 ? 'high' : ($complexity_ratio > 0.3 ? 'medium' : 'low')
        ];
    }
    
    private function predictGeneral($input_data) {
        // Общая модель предсказания
        $base_score = $input_data['percentage'] / 100;
        
        // Учитываем время выполнения
        $time_factor = 1.0;
        if ($input_data['time_spent'] > 0) {
            $expected_time = 30 * 60; // 30 минут в секундах
            $time_factor = min(1.5, $expected_time / $input_data['time_spent']);
        }
        
        $adjusted_score = $base_score * $time_factor;
        
        return [
            'prediction' => min(1.0, $adjusted_score),
            'confidence' => max(0.6, min(0.95, $adjusted_score)),
            'explanation' => 'Общая оценка с учетом времени выполнения',
            'details' => [
                'base_score' => $base_score,
                'time_factor' => $time_factor,
                'adjusted_score' => $adjusted_score
            ]
        ];
    }
    
    /**
     * Сохранить результат предсказания
     */
    private function savePrediction($model_id, $test_result_id, $prediction) {
        $stmt = $this->pdo->prepare("
            INSERT INTO model_predictions 
            (model_id, test_result_id, prediction_data, confidence, created_at) 
            VALUES (?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $model_id,
            $test_result_id,
            json_encode($prediction, JSON_UNESCAPED_UNICODE),
            $prediction['confidence']
        ]);
        
        return $this->pdo->lastInsertId();
    }
    
    /**
     * Получить предсказания для результата теста
     */
    public function getPredictionsForTestResult($test_result_id) {
        $stmt = $this->pdo->prepare("
            SELECT mp.*, mm.name as model_name, mm.description as model_description
            FROM model_predictions mp
            JOIN ml_models mm ON mp.model_id = mm.id
            WHERE mp.test_result_id = ?
            ORDER BY mp.created_at DESC
        ");
        $stmt->execute([$test_result_id]);
        return $stmt->fetchAll();
    }
    
    /**
     * Получить примененные модели для теста
     */
    public function getAppliedModelsForTest($test_id) {
        $stmt = $this->pdo->prepare("
            SELECT mm.* 
            FROM ml_models mm
            JOIN model_test_assignments mta ON mm.id = mta.model_id
            WHERE mta.test_id = ? AND mm.is_active = 1
        ");
        $stmt->execute([$test_id]);
        return $stmt->fetchAll();
    }
}
?>