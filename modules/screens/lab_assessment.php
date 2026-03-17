<?php
// student_lab_assessment.php - Add this as a new page
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/database.php';

// Check login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$userId = $_SESSION['user_id'];

// Get user info
$stmtUser = $pdo->prepare("SELECT id, full_name, department, role FROM users WHERE id = ?");
$stmtUser->execute([$userId]);
$user = $stmtUser->fetch();

if (!$user) {
    die("User not found.");
}

// Handle answer submission
if (isset($_POST['submit_answer'])) {
    try {
        $question_id = $_POST['question_id'];
        $lab_id = $_POST['lab_id'];
        $student_answer = $_POST['student_answer'];
        
        // Get question details for answer checking
        $stmt = $pdo->prepare("SELECT * FROM lab_questions WHERE id = ?");
        $stmt->execute([$question_id]);
        $question = $stmt->fetch();
        
        // Check if already attempted
        $checkStmt = $pdo->prepare("SELECT * FROM student_answers WHERE question_id = ? AND student_id = ?");
        $checkStmt->execute([$question_id, $userId]);
        $existing = $checkStmt->fetch();
        
        if ($existing) {
            $_SESSION['error_message'] = "You have already answered this question.";
            header("Location: index.php?page=lab_assessment&lab=" . $lab_id);
            exit;
        }
        
        // Call AI API to check answer
        $api_result = checkAnswerWithAI($question, $student_answer);
        
        // Save answer to database
        $insertStmt = $pdo->prepare("
            INSERT INTO student_answers 
            (question_id, student_id, answer_text, is_correct, score, feedback, submitted_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $insertStmt->execute([
            $question_id,
            $userId,
            $student_answer,
            $api_result['is_correct'] ? 1 : 0,
            $api_result['score'],
            $api_result['feedback']
        ]);
        
        // Update lab progress
        updateLabProgress($pdo, $lab_id, $userId);
        
        $_SESSION['success_message'] = "Answer submitted successfully!";
        header("Location: index.php?page=lab_assessment&lab=" . $lab_id);
        exit;
        
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error: " . $e->getMessage();
        header("Location: index.php?page=lab_assessment&lab=" . $lab_id);
        exit;
    }
}

// AI Answer Checking Function
function checkAnswerWithAI($question, $student_answer) {
    // This is a simulated AI response - in production, replace with actual API call
    // to services like OpenAI, Claude, or custom ML model
    
    $question_text = strtolower($question['question_text']);
    $question_type = $question['question_type'];
    $answer_text = strtolower(trim($student_answer));
    
    // Simulated AI logic based on question type
    switch($question_type) {
        case 'multiple_choice':
            // For MCQ, check against correct answer (you'd store this in DB)
            $correct_answer = "A"; // This should come from database
            $is_correct = ($answer_text === $correct_answer);
            $score = $is_correct ? 100 : 0;
            $feedback = $is_correct ? "Correct answer!" : "Incorrect. Please try again.";
            break;
            
        case 'true_false':
            $correct_answer = "true"; // This should come from database
            $is_correct = ($answer_text === $correct_answer);
            $score = $is_correct ? 100 : 0;
            $feedback = $is_correct ? "Correct!" : "Incorrect. Review the material.";
            break;
            
        case 'practical':
            // Check for key terms in practical answers
            $keywords = ['function', 'method', 'class', 'variable']; // Dynamic based on question
            $keyword_matches = 0;
            foreach ($keywords as $keyword) {
                if (strpos($answer_text, $keyword) !== false) {
                    $keyword_matches++;
                }
            }
            $score = min(100, ($keyword_matches / count($keywords)) * 100);
            $is_correct = $score >= 70;
            $feedback = "Score: $score%. " . ($is_correct ? "Good job!" : "Need more detail.");
            break;
            
        case 'essay':
        default:
            // Essay checking - check length and key concepts
            $word_count = str_word_count($answer_text);
            $min_words = 50; // Minimum words expected
            $length_score = min(50, ($word_count / $min_words) * 50);
            
            // Check for key concepts (these would be question-specific)
            $concepts = ['analysis', 'evaluation', 'conclusion']; 
            $concept_score = 0;
            foreach ($concepts as $concept) {
                if (strpos($answer_text, $concept) !== false) {
                    $concept_score += 10;
                }
            }
            
            $score = $length_score + $concept_score;
            $is_correct = $score >= 60;
            $feedback = "Word count: $word_count, Quality score: $score%. " .
                       ($is_correct ? "Good answer!" : "Please provide more detail.");
            break;
    }
    
    // In production, replace with actual API call:
    /*
    $api_key = "YOUR_API_KEY";
    $api_url = "https://api.openai.com/v1/chat/completions";
    
    $prompt = "Question: " . $question['question_text'] . "\nStudent Answer: " . $student_answer . "\nEvaluate if correct (0-100 score) and provide feedback.";
    
    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $api_key
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'model' => 'gpt-3.5-turbo',
        'messages' => [['role' => 'user', 'content' => $prompt]]
    ]));
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $result = json_decode($response, true);
    // Parse result...
    */
    
    return [
        'is_correct' => $is_correct,
        'score' => $score,
        'feedback' => $feedback
    ];
}

// Update lab progress function
function updateLabProgress($pdo, $lab_id, $student_id) {
    // Get total questions in lab
    $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM lab_questions WHERE lab_id = ?");
    $totalStmt->execute([$lab_id]);
    $total_questions = $totalStmt->fetchColumn();
    
    // Get answered questions
    $answeredStmt = $pdo->prepare("
        SELECT COUNT(DISTINCT question_id) 
        FROM student_answers sa
        JOIN lab_questions lq ON sa.question_id = lq.id
        WHERE lq.lab_id = ? AND sa.student_id = ?
    ");
    $answeredStmt->execute([$lab_id, $student_id]);
    $answered_questions = $answeredStmt->fetchColumn();
    
    // Calculate progress
    $progress = $total_questions > 0 ? ($answered_questions / $total_questions) * 100 : 0;
    
    // Check if lab already has progress record
    $checkStmt = $pdo->prepare("SELECT * FROM student_lab_progress WHERE lab_id = ? AND student_id = ?");
    $checkStmt->execute([$lab_id, $student_id]);
    $existing = $checkStmt->fetch();
    
    if ($existing) {
        $updateStmt = $pdo->prepare("
            UPDATE student_lab_progress 
            SET progress = ?, answered_questions = ?, last_activity = NOW(), 
                status = CASE WHEN ? >= 100 THEN 'completed' ELSE 'in_progress' END
            WHERE lab_id = ? AND student_id = ?
        ");
        $updateStmt->execute([$progress, $answered_questions, $progress, $lab_id, $student_id]);
    } else {
        $insertStmt = $pdo->prepare("
            INSERT INTO student_lab_progress 
            (lab_id, student_id, progress, answered_questions, total_questions, status, started_at, last_activity)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $status = $progress >= 100 ? 'completed' : 'in_progress';
        $insertStmt->execute([$lab_id, $student_id, $progress, $answered_questions, $total_questions, $status]);
    }
}

// Get labs for student based on department
$labsStmt = $pdo->prepare("
    SELECT l.*, 
           (SELECT COUNT(*) FROM lab_questions WHERE lab_id = l.id) as total_questions,
           slp.progress, slp.status as progress_status, slp.answered_questions,
           slp.last_activity
    FROM labs l
    LEFT JOIN student_lab_progress slp ON l.id = slp.lab_id AND slp.student_id = ?
    WHERE l.department = ?
    ORDER BY 
        CASE WHEN slp.status = 'in_progress' THEN 0 ELSE 1 END,
        slp.last_activity DESC,
        l.created_at DESC
");
$labsStmt->execute([$userId, $user['department']]);
$labs = $labsStmt->fetchAll();

// If specific lab selected, get its questions
$selected_lab = null;
$questions = [];
$answers = [];
if (isset($_GET['lab'])) {
    $lab_id = $_GET['lab'];
    
    // Get lab details
    $labStmt = $pdo->prepare("SELECT * FROM labs WHERE id = ? AND department = ?");
    $labStmt->execute([$lab_id, $user['department']]);
    $selected_lab = $labStmt->fetch();
    
    if ($selected_lab) {
        // Get questions for this lab
        $questionsStmt = $pdo->prepare("
            SELECT * FROM lab_questions 
            WHERE lab_id = ? 
            ORDER BY created_at
        ");
        $questionsStmt->execute([$lab_id]);
        $questions = $questionsStmt->fetchAll();
        
        // Get student's answers for these questions
        $question_ids = array_column($questions, 'id');
        if (!empty($question_ids)) {
            $placeholders = implode(',', array_fill(0, count($question_ids), '?'));
            $answersStmt = $pdo->prepare("
                SELECT * FROM student_answers 
                WHERE question_id IN ($placeholders) AND student_id = ?
            ");
            $params = array_merge($question_ids, [$userId]);
            $answersStmt->execute($params);
            $answers_data = $answersStmt->fetchAll();
            
            // Index answers by question_id
            foreach ($answers_data as $answer) {
                $answers[$answer['question_id']] = $answer;
            }
        }
    }
}

$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Lab Assessment - Proctor Mode</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .proctor-bar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .question-card {
            transition: all 0.3s ease;
        }
        .question-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1);
        }
        .proctor-indicator {
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }
        .fullscreen-mode {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 9999;
            background: white;
            overflow-y: auto;
        }
    </style>
</head>
<body class="bg-gray-50">

<!-- Proctor Header (always visible) -->
<div class="proctor-bar text-white p-2 text-sm fixed top-0 left-0 right-0 z-50 flex justify-between items-center px-4">
    <div class="flex items-center space-x-4">
        <i class="fas fa-video proctor-indicator"></i>
        <span>Proctor Mode Active</span>
        <span class="text-xs bg-white/20 px-2 py-1 rounded">Camera: <span id="cameraStatus">Checking...</span></span>
        <span class="text-xs bg-white/20 px-2 py-1 rounded">Tab Focus: <span id="focusStatus">OK</span></span>
    </div>
    <div id="timer" class="font-mono text-lg">00:00:00</div>
</div>

<div class="pt-12">
    <div class="max-w-7xl mx-auto p-6">
        
        <!-- Success/Error Messages -->
        <?php if ($success_message): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4">
                <?= htmlspecialchars($success_message) ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4">
                <?= htmlspecialchars($error_message) ?>
            </div>
        <?php endif; ?>

        <?php if ($selected_lab): ?>
            <!-- Lab Assessment View -->
            <div class="mb-6 flex justify-between items-center">
                <div>
                    <h1 class="text-2xl font-bold text-gray-800"><?= htmlspecialchars($selected_lab['lab_name']) ?></h1>
                    <p class="text-gray-600"><?= htmlspecialchars($selected_lab['description']) ?></p>
                </div>
                <div class="flex space-x-3">
                    <a href="index.php?page=lab_assessment" 
                       class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Labs
                    </a>
                    <button onclick="toggleFullscreen()" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">
                        <i class="fas fa-expand mr-2"></i>Fullscreen
                    </button>
                </div>
            </div>

            <!-- Progress Bar -->
            <?php
            $total_q = count($questions);
            $answered_q = count($answers);
            $progress_percent = $total_q > 0 ? ($answered_q / $total_q) * 100 : 0;
            ?>
            <div class="bg-white rounded-lg shadow-sm p-4 mb-6">
                <div class="flex justify-between text-sm text-gray-600 mb-2">
                    <span>Progress: <?= $answered_q ?>/<?= $total_q ?> questions answered</span>
                    <span><?= round($progress_percent) ?>% Complete</span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-2.5">
                    <div class="bg-green-600 h-2.5 rounded-full" style="width: <?= $progress_percent ?>%"></div>
                </div>
            </div>

            <!-- Questions Grid -->
            <div class="grid gap-6">
                <?php foreach ($questions as $index => $question): ?>
                    <?php $answered = isset($answers[$question['id']]); ?>
                    <div class="question-card bg-white rounded-lg shadow-sm border <?= $answered ? 'border-green-300 bg-green-50' : 'border-gray-200' ?>" id="question-<?= $question['id'] ?>">
                        <div class="p-6">
                            <div class="flex justify-between items-start mb-4">
                                <div>
                                    <span class="inline-block px-3 py-1 bg-blue-100 text-blue-800 rounded-full text-xs font-medium mr-2">
                                        Question <?= $index + 1 ?>
                                    </span>
                                    <span class="inline-block px-3 py-1 bg-gray-100 text-gray-800 rounded-full text-xs font-medium">
                                        <?= htmlspecialchars($question['question_type']) ?>
                                    </span>
                                    <?php if ($answered): ?>
                                        <span class="inline-block px-3 py-1 bg-green-100 text-green-800 rounded-full text-xs font-medium">
                                            <i class="fas fa-check mr-1"></i>Answered
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($answered): ?>
                                    <div class="text-right">
                                        <span class="text-sm font-medium <?= $answers[$question['id']]['is_correct'] ? 'text-green-600' : 'text-red-600' ?>">
                                            Score: <?= $answers[$question['id']]['score'] ?>%
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <h3 class="font-bold text-lg text-gray-800 mb-2"><?= htmlspecialchars($question['question_title']) ?></h3>
                            <p class="text-gray-600 mb-4"><?= nl2br(htmlspecialchars($question['question_text'])) ?></p>
                            
                            <?php if ($question['file_path']): ?>
                                <div class="mb-4">
                                    <a href="../../<?= htmlspecialchars($question['file_path']) ?>" target="_blank" 
                                       class="text-blue-600 hover:text-blue-800 text-sm">
                                        <i class="fas fa-paperclip mr-1"></i>View Attachment
                                    </a>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($answered): ?>
                                <!-- Show Answer -->
                                <div class="mt-4 p-4 bg-white rounded-lg border">
                                    <p class="text-sm font-medium text-gray-700 mb-2">Your Answer:</p>
                                    <p class="text-gray-600 mb-3"><?= nl2br(htmlspecialchars($answers[$question['id']]['answer_text'])) ?></p>
                                    <?php if ($answers[$question['id']]['feedback']): ?>
                                        <div class="mt-2 p-3 bg-blue-50 rounded-lg">
                                            <p class="text-sm font-medium text-blue-800 mb-1">Feedback:</p>
                                            <p class="text-sm text-blue-700"><?= htmlspecialchars($answers[$question['id']]['feedback']) ?></p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <!-- Answer Form -->
                                <form method="POST" class="mt-4" onsubmit="return validateForm(this)">
                                    <input type="hidden" name="question_id" value="<?= $question['id'] ?>">
                                    <input type="hidden" name="lab_id" value="<?= $selected_lab['id'] ?>">
                                    
                                    <?php if ($question['question_type'] == 'multiple_choice'): ?>
                                        <!-- MCQ Options - You'd populate from database -->
                                        <div class="space-y-2 mb-4">
                                            <label class="flex items-center p-3 border rounded-lg hover:bg-gray-50">
                                                <input type="radio" name="student_answer" value="A" class="mr-3" required>
                                                <span>Option A</span>
                                            </label>
                                            <label class="flex items-center p-3 border rounded-lg hover:bg-gray-50">
                                                <input type="radio" name="student_answer" value="B" class="mr-3">
                                                <span>Option B</span>
                                            </label>
                                            <label class="flex items-center p-3 border rounded-lg hover:bg-gray-50">
                                                <input type="radio" name="student_answer" value="C" class="mr-3">
                                                <span>Option C</span>
                                            </label>
                                            <label class="flex items-center p-3 border rounded-lg hover:bg-gray-50">
                                                <input type="radio" name="student_answer" value="D" class="mr-3">
                                                <span>Option D</span>
                                            </label>
                                        </div>
                                    <?php elseif ($question['question_type'] == 'true_false'): ?>
                                        <div class="space-y-2 mb-4">
                                            <label class="flex items-center p-3 border rounded-lg hover:bg-gray-50">
                                                <input type="radio" name="student_answer" value="true" class="mr-3" required>
                                                <span>True</span>
                                            </label>
                                            <label class="flex items-center p-3 border rounded-lg hover:bg-gray-50">
                                                <input type="radio" name="student_answer" value="false" class="mr-3">
                                                <span>False</span>
                                            </label>
                                        </div>
                                    <?php else: ?>
                                        <textarea name="student_answer" rows="4" required
                                                  class="w-full p-3 border rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                                  placeholder="Type your answer here..."></textarea>
                                    <?php endif; ?>
                                    
                                    <div class="flex justify-end mt-4">
                                        <button type="submit" name="submit_answer"
                                                class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-medium">
                                            <i class="fas fa-paper-plane mr-2"></i>Submit Answer
                                        </button>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Lab Completion -->
            <?php if ($answered_q == $total_q && $total_q > 0): ?>
                <div class="mt-8 text-center">
                    <div class="bg-green-100 border border-green-400 text-green-700 px-6 py-4 rounded-lg mb-4">
                        <i class="fas fa-trophy text-3xl mb-2"></i>
                        <h3 class="text-xl font-bold mb-2">Congratulations!</h3>
                        <p>You have completed all questions in this lab.</p>
                    </div>
                    <button onclick="markLabComplete(<?= $selected_lab['id'] ?>)" 
                            class="px-8 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 font-bold">
                        <i class="fas fa-check-circle mr-2"></i>Mark Lab as Complete
                    </button>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <!-- Labs List View -->
            <div class="mb-6">
                <h1 class="text-2xl font-bold text-gray-800">Lab Assessments</h1>
                <p class="text-gray-600">Select a lab to begin your assessment</p>
            </div>

            <!-- Labs Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($labs as $lab): 
                    $progress = $lab['progress'] ?? 0;
                    $status = $lab['progress_status'] ?? 'not_started';
                    $total = $lab['total_questions'] ?? 0;
                ?>
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden hover:shadow-lg transition">
                        <div class="p-6">
                            <div class="flex justify-between items-start mb-4">
                                <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-flask text-blue-600 text-xl"></i>
                                </div>
                                <span class="px-3 py-1 rounded-full text-xs font-medium
                                    <?php 
                                    if ($status == 'completed') echo 'bg-green-100 text-green-800';
                                    elseif ($status == 'in_progress') echo 'bg-yellow-100 text-yellow-800';
                                    else echo 'bg-gray-100 text-gray-800';
                                    ?>">
                                    <?= ucfirst(str_replace('_', ' ', $status)) ?>
                                </span>
                            </div>
                            
                            <h3 class="font-bold text-lg text-gray-800 mb-2"><?= htmlspecialchars($lab['lab_name']) ?></h3>
                            <p class="text-sm text-gray-600 mb-4"><?= htmlspecialchars(substr($lab['description'] ?? '', 0, 100)) ?>...</p>
                            
                            <div class="space-y-3">
                                <div class="flex justify-between text-sm">
                                    <span class="text-gray-500">Questions:</span>
                                    <span class="font-medium"><?= $total ?></span>
                                </div>
                                
                                <?php if ($status != 'not_started'): ?>
                                    <div>
                                        <div class="flex justify-between text-sm mb-1">
                                            <span class="text-gray-500">Progress:</span>
                                            <span class="font-medium"><?= round($progress) ?>%</span>
                                        </div>
                                        <div class="w-full bg-gray-200 rounded-full h-2">
                                            <div class="bg-blue-600 h-2 rounded-full" style="width: <?= $progress ?>%"></div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <a href="index.php?page=lab_assessment&lab=<?= $lab['id'] ?>" 
                               class="mt-6 block w-full text-center px-4 py-2 
                                      <?= $status == 'completed' ? 'bg-green-600 hover:bg-green-700' : 'bg-blue-600 hover:bg-blue-700' ?>
                                      text-white rounded-lg transition">
                                <?php 
                                if ($status == 'completed') echo '<i class="fas fa-redo mr-2"></i>Review';
                                elseif ($status == 'in_progress') echo '<i class="fas fa-play mr-2"></i>Continue';
                                else echo '<i class="fas fa-play mr-2"></i>Start Lab';
                                ?>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Proctor Script -->
<script>
// Proctor Mode Features
let proctorActive = true;
let startTime = new Date();
let timerInterval;

// Timer function
function updateTimer() {
    const now = new Date();
    const diff = new Date(now - startTime);
    const hours = String(diff.getUTCHours()).padStart(2, '0');
    const minutes = String(diff.getUTCMinutes()).padStart(2, '0');
    const seconds = String(diff.getUTCSeconds()).padStart(2, '0');
    document.getElementById('timer').textContent = `${hours}:${minutes}:${seconds}`;
}

// Start timer
timerInterval = setInterval(updateTimer, 1000);

// Fullscreen toggle
function toggleFullscreen() {
    if (!document.fullscreenElement) {
        document.documentElement.requestFullscreen();
        document.querySelector('.fa-expand').classList.replace('fa-expand', 'fa-compress');
    } else {
        if (document.exitFullscreen) {
            document.exitFullscreen();
            document.querySelector('.fa-compress').classList.replace('fa-compress', 'fa-expand');
        }
    }
}

// Tab focus detection
document.addEventListener('visibilitychange', function() {
    const focusStatus = document.getElementById('focusStatus');
    if (document.hidden) {
        focusStatus.textContent = 'WARNING';
        focusStatus.style.color = '#ff0000';
        logProctorEvent('tab_switch', 'Student switched tabs');
    } else {
        focusStatus.textContent = 'OK';
        focusStatus.style.color = 'inherit';
    }
});

// Camera simulation (in production, use getUserMedia)
function checkCamera() {
    const cameraStatus = document.getElementById('cameraStatus');
    if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
        navigator.mediaDevices.getUserMedia({ video: true })
            .then(function(stream) {
                cameraStatus.textContent = 'Active';
                cameraStatus.style.color = '#00ff00';
                // Stop tracks to free camera
                stream.getTracks().forEach(track => track.stop());
            })
            .catch(function(err) {
                cameraStatus.textContent = 'Not Available';
                cameraStatus.style.color = '#ff0000';
                logProctorEvent('camera_error', 'Camera access denied');
            });
    } else {
        cameraStatus.textContent = 'Not Supported';
        cameraStatus.style.color = '#ff0000';
    }
}

// Log proctor events
function logProctorEvent(eventType, details) {
    // In production, send to server
    console.log('Proctor Event:', eventType, details);
    
    // You can implement AJAX call to log events
    /*
    fetch('log_proctor_event.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            event_type: eventType,
            details: details,
            timestamp: new Date().toISOString()
        })
    });
    */
}

// Form validation
function validateForm(form) {
    const answer = form.querySelector('[name="student_answer"]');
    if (!answer.value.trim()) {
        alert('Please provide an answer before submitting.');
        return false;
    }
    
    // Log submission attempt
    logProctorEvent('answer_submitted', 'Student submitted answer');
    return true;
}

// Mark lab complete
function markLabComplete(labId) {
    if (confirm('Are you sure you want to mark this lab as complete?')) {
        window.location.href = 'index.php?page=lab_assessment&complete=' + labId;
    }
}

// Prevent copy-paste
document.addEventListener('copy', (e) => {
    e.preventDefault();
    logProctorEvent('copy_attempt', 'Student attempted to copy');
    alert('Copying is disabled during assessment');
});

document.addEventListener('paste', (e) => {
    e.preventDefault();
    logProctorEvent('paste_attempt', 'Student attempted to paste');
    alert('Pasting is disabled during assessment');
});

// Detect right-click
document.addEventListener('contextmenu', (e) => {
    e.preventDefault();
    logProctorEvent('right_click', 'Student attempted right click');
    return false;
});

// Detect keyboard shortcuts
document.addEventListener('keydown', (e) => {
    // Prevent Ctrl+C, Ctrl+V, Ctrl+X
    if (e.ctrlKey && (e.key === 'c' || e.key === 'v' || e.key === 'x')) {
        e.preventDefault();
        logProctorEvent('keyboard_shortcut', `Student attempted Ctrl+${e.key}`);
        alert('This keyboard shortcut is disabled during assessment');
    }
    
    // Prevent Alt+Tab detection (can't fully prevent but can warn)
    if (e.altKey && e.key === 'Tab') {
        logProctorEvent('alt_tab', 'Student attempted Alt+Tab');
    }
});

// Initialize proctor features
document.addEventListener('DOMContentLoaded', function() {
    checkCamera();
    
    // Check every 30 seconds
    setInterval(checkCamera, 30000);
    
    // Warn before leaving
    window.addEventListener('beforeunload', function(e) {
        if (proctorActive) {
            e.preventDefault();
            e.returnValue = 'Assessment in progress. Are you sure you want to leave?';
            logProctorEvent('page_exit_attempt', 'Student attempted to leave page');
        }
    });
});

// Cleanup on page unload
window.addEventListener('unload', function() {
    clearInterval(timerInterval);
    logProctorEvent('session_end', 'Student ended assessment session');
});
</script>

<!-- Database Tables Creation SQL (run once) -->
<!--
CREATE TABLE IF NOT EXISTS student_answers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    question_id INT NOT NULL,
    student_id INT NOT NULL,
    answer_text TEXT,
    is_correct BOOLEAN DEFAULT FALSE,
    score INT DEFAULT 0,
    feedback TEXT,
    submitted_at DATETIME,
    FOREIGN KEY (question_id) REFERENCES lab_questions(id),
    FOREIGN KEY (student_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS student_lab_progress (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lab_id INT NOT NULL,
    student_id INT NOT NULL,
    progress FLOAT DEFAULT 0,
    answered_questions INT DEFAULT 0,
    total_questions INT DEFAULT 0,
    status ENUM('not_started', 'in_progress', 'completed') DEFAULT 'not_started',
    started_at DATETIME,
    completed_at DATETIME,
    last_activity DATETIME,
    FOREIGN KEY (lab_id) REFERENCES labs(id),
    FOREIGN KEY (student_id) REFERENCES users(id),
    UNIQUE KEY unique_lab_student (lab_id, student_id)
);
-->

</body>
</html>