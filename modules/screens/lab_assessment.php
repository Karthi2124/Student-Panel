<?php
// lab_assessment.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/database.php';

// Check login - support both user_id and student_id
$userId = $_SESSION['user_id'] ?? $_SESSION['student_id'] ?? null;
if (!$userId) {
    header("Location: login.php");
    exit;
}

// Get user info
$stmtUser = $pdo->prepare("SELECT id, full_name, department, role FROM users WHERE id = ?");
$stmtUser->execute([$userId]);
$user = $stmtUser->fetch();

if (!$user) {
    // Try students table if users table doesn't have the record
    $stmtStudent = $pdo->prepare("SELECT id, full_name, department FROM students WHERE id = ?");
    $stmtStudent->execute([$userId]);
    $user = $stmtStudent->fetch();
}

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
        
        if (!$question) {
            throw new Exception("Question not found");
        }
        
        // Check if already attempted
        $checkStmt = $pdo->prepare("SELECT * FROM student_answers WHERE question_id = ? AND student_id = ?");
        $checkStmt->execute([$question_id, $userId]);
        $existing = $checkStmt->fetch();
        
        if ($existing) {
            $_SESSION['error_message'] = "You have already answered this question.";
            header("Location: index.php?page=lab_assessment&lab=" . $lab_id);
            exit;
        }
        
        // Simple answer checking (replace with AI later)
        $api_result = [
            'is_correct' => true,
            'score' => 85,
            'feedback' => "Good attempt! Your answer shows understanding of the concept."
        ];
        
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
try {
    $labsStmt = $pdo->prepare("
        SELECT l.*, 
               (SELECT COUNT(*) FROM lab_questions WHERE lab_id = l.id) as total_questions,
               slp.progress, slp.status as progress_status, slp.answered_questions,
               slp.last_activity
        FROM labs l
        LEFT JOIN student_lab_progress slp ON l.id = slp.lab_id AND slp.student_id = ?
        WHERE l.department = ? OR l.department IS NULL
        ORDER BY 
            CASE WHEN slp.status = 'in_progress' THEN 0 
                 WHEN slp.status = 'not_started' THEN 1 
                 ELSE 2 END,
            slp.last_activity DESC,
            l.created_at DESC
    ");
    $labsStmt->execute([$userId, $user['department'] ?? 'Computer Science']);
    $labs = $labsStmt->fetchAll();
} catch (PDOException $e) {
    $labs = [];
    error_log("Database error: " . $e->getMessage());
}

// If specific lab selected, get its questions
$selected_lab = null;
$questions = [];
$answers = [];
if (isset($_GET['lab'])) {
    $lab_id = $_GET['lab'];
    
    // Get lab details
    $labStmt = $pdo->prepare("SELECT * FROM labs WHERE id = ?");
    $labStmt->execute([$lab_id]);
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
    <title>Lab Assessment</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .question-card {
            transition: all 0.3s ease;
        }
        .question-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body class="bg-gray-50">

<div class="max-w-7xl mx-auto p-6">
    
    <!-- Success/Error Messages -->
    <?php if ($success_message): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4">
            <i class="fas fa-check-circle mr-2"></i>
            <?= htmlspecialchars($success_message) ?>
        </div>
    <?php endif; ?>
    
    <?php if ($error_message): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4">
            <i class="fas fa-exclamation-circle mr-2"></i>
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
            <a href="index.php?page=lab_assessment" 
               class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                <i class="fas fa-arrow-left mr-2"></i>Back to Labs
            </a>
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
            <?php if (empty($questions)): ?>
                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-8 text-center">
                    <i class="fas fa-info-circle text-yellow-500 text-4xl mb-4"></i>
                    <h3 class="text-xl font-bold text-gray-800 mb-2">No Questions Available</h3>
                    <p class="text-gray-600">This lab doesn't have any questions yet.</p>
                </div>
            <?php else: ?>
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
                                        <?= htmlspecialchars($question['question_type'] ?? 'General') ?>
                                    </span>
                                    <?php if ($answered): ?>
                                        <span class="inline-block px-3 py-1 bg-green-100 text-green-800 rounded-full text-xs font-medium">
                                            <i class="fas fa-check mr-1"></i>Answered
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($answered): ?>
                                    <div class="text-right">
                                        <span class="text-sm font-medium <?= ($answers[$question['id']]['is_correct'] ?? false) ? 'text-green-600' : 'text-red-600' ?>">
                                            Score: <?= $answers[$question['id']]['score'] ?? 0 ?>%
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <h3 class="font-bold text-lg text-gray-800 mb-2"><?= htmlspecialchars($question['question_title'] ?? 'Question') ?></h3>
                            <p class="text-gray-600 mb-4"><?= nl2br(htmlspecialchars($question['question_text'] ?? '')) ?></p>
                            
                            <?php if (!empty($question['file_path'])): ?>
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
                                    <p class="text-gray-600 mb-3"><?= nl2br(htmlspecialchars($answers[$question['id']]['answer_text'] ?? '')) ?></p>
                                    <?php if (!empty($answers[$question['id']]['feedback'])): ?>
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
                                    
                                    <textarea name="student_answer" rows="4" required
                                              class="w-full p-3 border rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                              placeholder="Type your answer here..."></textarea>
                                    
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
            <?php endif; ?>
        </div>

        <!-- Lab Completion -->
        <?php if ($answered_q == $total_q && $total_q > 0): ?>
            <div class="mt-8 text-center">
                <div class="bg-green-100 border border-green-400 text-green-700 px-6 py-4 rounded-lg mb-4">
                    <i class="fas fa-trophy text-3xl mb-2"></i>
                    <h3 class="text-xl font-bold mb-2">Congratulations!</h3>
                    <p>You have completed all questions in this lab.</p>
                </div>
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
            <?php if (empty($labs)): ?>
                <div class="col-span-3 text-center py-12">
                    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-8">
                        <i class="fas fa-flask text-yellow-500 text-4xl mb-4"></i>
                        <h3 class="text-xl font-bold text-gray-800 mb-2">No Labs Available</h3>
                        <p class="text-gray-600">Please check back later for lab assessments.</p>
                    </div>
                </div>
            <?php else: ?>
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
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<script>
// Form validation
function validateForm(form) {
    const answer = form.querySelector('[name="student_answer"]');
    if (!answer.value.trim()) {
        alert('Please provide an answer before submitting.');
        return false;
    }
    return true;
}
</script>

</body>
</html>