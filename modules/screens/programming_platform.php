<?php
// programming_platform.php
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

// Handle code execution
if (isset($_POST['execute_code'])) {
    header('Content-Type: application/json');
    try {
        $code = $_POST['code'];
        $language = $_POST['language'];
        
        // Execute code based on language
        $result = executeCode($code, $language);
        
        echo json_encode(['success' => true, 'output' => $result]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Handle answer submission with code
if (isset($_POST['submit_programming_answer'])) {
    try {
        $question_id = $_POST['question_id'];
        $lab_id = $_POST['lab_id'];
        $code = $_POST['code'];
        $language = $_POST['language'];
        
        // Get question details with test cases
        $stmt = $pdo->prepare("SELECT * FROM programming_questions WHERE id = ?");
        $stmt->execute([$question_id]);
        $question = $stmt->fetch();
        
        if (!$question) {
            throw new Exception("Question not found");
        }
        
        // Check if already attempted
        $checkStmt = $pdo->prepare("SELECT * FROM student_programming_answers WHERE question_id = ? AND student_id = ?");
        $checkStmt->execute([$question_id, $userId]);
        $existing = $checkStmt->fetch();
        
        if ($existing) {
            $_SESSION['error_message'] = "You have already answered this question.";
            header("Location: index.php?page=programming_platform&lab=" . $lab_id);
            exit;
        }
        
        // Run test cases
        $test_results = runTestCases($code, $language, $question);
        
        // Calculate score based on test cases passed
        $total_tests = count($test_results['tests']);
        $passed_tests = count(array_filter($test_results['tests'], function($test) {
            return $test['passed'];
        }));
        
        $score = $total_tests > 0 ? ($passed_tests / $total_tests) * 100 : 0;
        $is_correct = $score >= 70; // Pass if 70% or more tests pass
        
        // Save answer to database
        $insertStmt = $pdo->prepare("
            INSERT INTO student_programming_answers 
            (question_id, student_id, code, language, test_results, passed_tests, total_tests, score, is_correct, submitted_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $insertStmt->execute([
            $question_id,
            $userId,
            $code,
            $language,
            json_encode($test_results),
            $passed_tests,
            $total_tests,
            $score,
            $is_correct ? 1 : 0
        ]);
        
        // Update lab progress
        updateProgrammingLabProgress($pdo, $lab_id, $userId);
        
        $_SESSION['success_message'] = "Code submitted successfully! Passed $passed_tests/$total_tests tests.";
        header("Location: index.php?page=programming_platform&lab=" . $lab_id);
        exit;
        
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error: " . $e->getMessage();
        header("Location: index.php?page=programming_platform&lab=" . $lab_id);
        exit;
    }
}

// Code execution function with security improvements
function executeCode($code, $language) {
    // Create temporary directory for code execution
    $tmpDir = sys_get_temp_dir() . '/code_exec_' . uniqid();
    mkdir($tmpDir);
    
    $output = [];
    $return_var = 0;
    
    // Add timeout to prevent infinite loops (5 seconds)
    $timeout = 5;
    
    // Security: Remove dangerous functions
    $code = preg_replace('/exec\s*\(/i', '// disabled exec(', $code);
    $code = preg_replace('/shell_exec\s*\(/i', '// disabled shell_exec(', $code);
    $code = preg_replace('/system\s*\(/i', '// disabled system(', $code);
    $code = preg_replace('/passthru\s*\(/i', '// disabled passthru(', $code);
    $code = preg_replace('/`.*?`/s', '// disabled backticks', $code);
    
    switch($language) {
        case 'python':
            $filename = $tmpDir . '/script.py';
            file_put_contents($filename, $code);
            $command = "timeout $timeout python3 $filename 2>&1";
            break;
            
        case 'javascript':
            $filename = $tmpDir . '/script.js';
            file_put_contents($filename, $code);
            $command = "timeout $timeout node $filename 2>&1";
            break;
            
        case 'php':
            $filename = $tmpDir . '/script.php';
            file_put_contents($filename, "<?php\n" . $code . "\n?>");
            $command = "timeout $timeout php $filename 2>&1";
            break;
            
        default:
            cleanupDir($tmpDir);
            return "Supported languages: Python, JavaScript, PHP";
    }
    
    if (isset($command)) {
        exec($command, $output, $return_var);
        $result = implode("\n", $output);
        cleanupDir($tmpDir);
        return $result;
    }
    
    cleanupDir($tmpDir);
    return "Execution failed";
}

// Clean up temporary directory
function cleanupDir($dir) {
    if (is_dir($dir)) {
        $files = glob($dir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($dir);
    }
}

// Run test cases function
function runTestCases($code, $language, $question) {
    $test_cases = json_decode($question['test_cases'], true);
    if (!$test_cases) {
        $test_cases = [];
    }
    
    $results = [
        'tests' => [],
        'summary' => ''
    ];
    
    foreach ($test_cases as $index => $test) {
        // Prepare code with test input
        $test_code = prepareCodeWithTest($code, $language, $test);
        
        // Execute
        $output = executeCode($test_code, $language);
        $expected = trim($test['expected_output']);
        $actual = trim($output);
        
        // Compare output
        $passed = (strcasecmp(trim($actual), trim($expected)) === 0);
        
        $results['tests'][] = [
            'test_number' => $index + 1,
            'input' => $test['input'],
            'expected' => $expected,
            'actual' => $actual,
            'passed' => $passed
        ];
    }
    
    return $results;
}

// Prepare code with test input
function prepareCodeWithTest($code, $language, $test) {
    $input = $test['input'];
    
    // Remove any existing print statements for clean testing
    $code = preg_replace('/print\s*\(.*\)\s*;?/m', '', $code);
    $code = preg_replace('/console\.log\s*\(.*\)\s*;?/m', '', $code);
    $code = preg_replace('/echo\s+.*;?/m', '', $code);
    
    switch($language) {
        case 'python':
            return $code . "\n\n# Test with provided input\nprint(" . $input . ")";
            
        case 'javascript':
            return $code . "\n\n// Test with provided input\nconsole.log(" . $input . ");";
            
        case 'php':
            return $code . "\n\n// Test with provided input\necho " . $input . ";";
            
        default:
            return $code;
    }
}

// Update programming lab progress
function updateProgrammingLabProgress($pdo, $lab_id, $student_id) {
    // Get total questions in programming lab
    $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM programming_questions WHERE lab_id = ?");
    $totalStmt->execute([$lab_id]);
    $total_questions = $totalStmt->fetchColumn();
    
    // Get answered questions
    $answeredStmt = $pdo->prepare("
        SELECT COUNT(DISTINCT question_id) 
        FROM student_programming_answers 
        WHERE question_id IN (SELECT id FROM programming_questions WHERE lab_id = ?) 
        AND student_id = ?
    ");
    $answeredStmt->execute([$lab_id, $student_id]);
    $answered_questions = $answeredStmt->fetchColumn();
    
    // Calculate progress
    $progress = $total_questions > 0 ? ($answered_questions / $total_questions) * 100 : 0;
    
    // Check if lab already has progress record
    $checkStmt = $pdo->prepare("SELECT * FROM student_programming_progress WHERE lab_id = ? AND student_id = ?");
    $checkStmt->execute([$lab_id, $student_id]);
    $existing = $checkStmt->fetch();
    
    if ($existing) {
        $updateStmt = $pdo->prepare("
            UPDATE student_programming_progress 
            SET progress = ?, answered_questions = ?, last_activity = NOW(), 
                status = CASE WHEN ? >= 100 THEN 'completed' ELSE 'in_progress' END
            WHERE lab_id = ? AND student_id = ?
        ");
        $updateStmt->execute([$progress, $answered_questions, $progress, $lab_id, $student_id]);
    } else {
        $insertStmt = $pdo->prepare("
            INSERT INTO student_programming_progress 
            (lab_id, student_id, progress, answered_questions, total_questions, status, started_at, last_activity)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $status = $progress >= 100 ? 'completed' : 'in_progress';
        $insertStmt->execute([$lab_id, $student_id, $progress, $answered_questions, $total_questions, $status]);
    }
}

// Get programming labs for student
try {
    $labsStmt = $pdo->prepare("
        SELECT l.*, 
               (SELECT COUNT(*) FROM programming_questions WHERE lab_id = l.id) as total_questions,
               spp.progress, spp.status as progress_status, spp.answered_questions,
               spp.last_activity
        FROM programming_labs l
        LEFT JOIN student_programming_progress spp ON l.id = spp.lab_id AND spp.student_id = ?
        WHERE l.department = ? OR l.department IS NULL
        ORDER BY 
            CASE WHEN spp.status = 'in_progress' THEN 0 
                 WHEN spp.status = 'not_started' THEN 1 
                 ELSE 2 END,
            spp.last_activity DESC,
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
    $labStmt = $pdo->prepare("SELECT * FROM programming_labs WHERE id = ?");
    $labStmt->execute([$lab_id]);
    $selected_lab = $labStmt->fetch();
    
    if ($selected_lab) {
        // Get questions for this lab
        $questionsStmt = $pdo->prepare("
            SELECT * FROM programming_questions 
            WHERE lab_id = ? 
            ORDER BY 
                CASE difficulty 
                    WHEN 'easy' THEN 1 
                    WHEN 'medium' THEN 2 
                    WHEN 'hard' THEN 3 
                END, 
                created_at
        ");
        $questionsStmt->execute([$lab_id]);
        $questions = $questionsStmt->fetchAll();
        
        // Get student's answers
        $question_ids = array_column($questions, 'id');
        if (!empty($question_ids)) {
            $placeholders = implode(',', array_fill(0, count($question_ids), '?'));
            $answersStmt = $pdo->prepare("
                SELECT * FROM student_programming_answers 
                WHERE question_id IN ($placeholders) AND student_id = ?
            ");
            $params = array_merge($question_ids, [$userId]);
            $answersStmt->execute($params);
            $answers_data = $answersStmt->fetchAll();
            
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
    <title>Programming Platform</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/theme/dracula.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/python/python.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/javascript/javascript.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/php/php.min.js"></script>
    <style>
        .CodeMirror {
            height: 400px;
            border-radius: 0.5rem;
            font-size: 14px;
        }
        .split-pane {
            display: flex;
            height: calc(100vh - 250px);
            min-height: 600px;
        }
        .question-panel {
            width: 40%;
            overflow-y: auto;
            padding: 1.5rem;
            background: #f9fafb;
        }
        .code-panel {
            width: 60%;
            display: flex;
            flex-direction: column;
            background: #1e1e1e;
        }
        .output-panel {
            height: 200px;
            overflow-y: auto;
            background: #1e1e1e;
            color: #fff;
            padding: 1rem;
            font-family: 'Monaco', 'Menlo', monospace;
            font-size: 14px;
            border-top: 1px solid #333;
        }
        .difficulty-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .difficulty-easy { background: #d1fae5; color: #065f46; }
        .difficulty-medium { background: #fef3c7; color: #92400e; }
        .difficulty-hard { background: #fee2e2; color: #991b1b; }
    </style>
</head>
<body>

<div class="max-w-7xl mx-auto">
    
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

    <?php if ($selected_lab && !empty($questions)): ?>
        <!-- Programming Lab View -->
        <div class="mb-4 flex justify-between items-center">
            <div>
                <h1 class="text-2xl font-bold text-gray-800"><?= htmlspecialchars($selected_lab['lab_name']) ?></h1>
                <p class="text-gray-600"><?= htmlspecialchars($selected_lab['description']) ?></p>
            </div>
            <a href="index.php?page=programming_platform" 
               class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                <i class="fas fa-arrow-left mr-2"></i>Back to Labs
            </a>
        </div>

        <!-- Question Selector -->
        <div class="mb-4 flex space-x-2 overflow-x-auto pb-2">
            <?php foreach ($questions as $index => $question): ?>
                <?php $answered = isset($answers[$question['id']]); ?>
                <a href="index.php?page=programming_platform&lab=<?= $selected_lab['id'] ?>&question=<?= $question['id'] ?>" 
                   class="question-tab px-4 py-2 rounded-lg whitespace-nowrap transition-all
                          <?= $answered ? 'bg-green-100 text-green-800 border-green-300' : 'bg-white border-gray-300' ?>
                          border hover:shadow-md <?= (isset($_GET['question']) && $_GET['question'] == $question['id']) || (!isset($_GET['question']) && $index === 0) ? 'ring-2 ring-blue-500' : '' ?>">
                    <span class="font-medium"><?= $index + 1 ?>. <?= htmlspecialchars($question['title']) ?></span>
                    <?php if ($answered): ?>
                        <i class="fas fa-check-circle text-green-600 ml-2"></i>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Split Pane for Selected Question -->
        <?php 
        // Get the selected question or first one
        $selected_question_id = $_GET['question'] ?? ($questions[0]['id'] ?? 0);
        $current_question = null;
        $current_answer = null;
        
        foreach ($questions as $q) {
            if ($q['id'] == $selected_question_id) {
                $current_question = $q;
                $current_answer = $answers[$q['id']] ?? null;
                break;
            }
        }
        
        if (!$current_question && !empty($questions)) {
            $current_question = $questions[0];
            $current_answer = $answers[$questions[0]['id']] ?? null;
        }
        ?>
        
        <?php if ($current_question): ?>
        <div class="split-pane border rounded-lg overflow-hidden bg-white shadow-lg">
            <!-- Question Panel -->
            <div class="question-panel border-r">
                <div class="question-content">
                    <div class="flex justify-between items-start mb-4">
                        <span class="difficulty-badge difficulty-<?= $current_question['difficulty'] ?>">
                            <?= ucfirst($current_question['difficulty']) ?>
                        </span>
                        <span class="px-3 py-1 bg-purple-100 text-purple-800 rounded-full text-sm font-medium">
                            <i class="fas fa-code mr-1"></i><?= ucfirst($current_question['language']) ?>
                        </span>
                    </div>
                    
                    <h2 class="text-xl font-bold mb-4"><?= htmlspecialchars($current_question['title']) ?></h2>
                    
                    <div class="prose max-w-none mb-6">
                        <h3 class="font-semibold text-gray-700 mb-2">Problem Description:</h3>
                        <p class="text-gray-600"><?= nl2br(htmlspecialchars($current_question['description'])) ?></p>
                    </div>
                    
                    <?php if ($current_question['examples']): ?>
                        <div class="mb-6">
                            <h3 class="font-semibold text-gray-700 mb-2">Examples:</h3>
                            <div class="bg-gray-800 text-green-400 p-4 rounded-lg font-mono text-sm">
                                <?= nl2br(htmlspecialchars($current_question['examples'])) ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($current_question['constraints']): ?>
                        <div class="mb-6">
                            <h3 class="font-semibold text-gray-700 mb-2">Constraints:</h3>
                            <div class="bg-gray-100 p-4 rounded-lg">
                                <?= nl2br(htmlspecialchars($current_question['constraints'])) ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Test Cases Preview -->
                    <?php if ($current_question['test_cases']): 
                        $test_cases = json_decode($current_question['test_cases'], true);
                    ?>
                        <div>
                            <h3 class="font-semibold text-gray-700 mb-2">Test Cases:</h3>
                            <div class="space-y-2">
                                <?php foreach ($test_cases as $index => $test): ?>
                                    <div class="p-3 bg-gray-100 rounded text-sm">
                                        <div class="font-mono">
                                            <span class="text-blue-600">Test <?= $index + 1 ?>:</span>
                                            <span class="ml-2 text-gray-600">Input: <?= htmlspecialchars($test['input']) ?></span>
                                            <span class="ml-2 text-green-600">→ Output: <?= htmlspecialchars($test['expected_output']) ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Code Panel -->
            <div class="code-panel">
                <div class="p-4 bg-gray-800 border-b border-gray-700 flex justify-between items-center">
                    <select id="language-select" class="px-3 py-2 bg-gray-700 text-white border border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="python" <?= $current_question['language'] == 'python' ? 'selected' : '' ?>>Python</option>
                        <option value="javascript" <?= $current_question['language'] == 'javascript' ? 'selected' : '' ?>>JavaScript</option>
                        <option value="php" <?= $current_question['language'] == 'php' ? 'selected' : '' ?>>PHP</option>
                    </select>
                    
                    <div class="space-x-2">
                        <button onclick="runCode()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                            <i class="fas fa-play mr-2"></i>Run Code
                        </button>
                        <?php if (!$current_answer): ?>
                        <button onclick="submitCode()" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition">
                            <i class="fas fa-paper-plane mr-2"></i>Submit
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Code Editor -->
                <div class="flex-1 p-4">
                    <textarea id="code-editor" name="code"><?= $current_answer ? htmlspecialchars($current_answer['code']) : "# Write your code here\n\ndef solution():\n    # Your solution here\n    pass\n" ?></textarea>
                </div>
                
                <!-- Output Panel -->
                <div class="p-4 bg-gray-900 border-t border-gray-700">
                    <div class="flex justify-between items-center mb-2">
                        <h3 class="font-bold text-white">Output:</h3>
                        <button onclick="clearOutput()" class="text-sm text-gray-400 hover:text-white transition">
                            <i class="fas fa-trash mr-1"></i>Clear
                        </button>
                    </div>
                    <div id="output" class="output-panel">
                        <span class="text-gray-400">▶ Run your code to see output here...</span>
                    </div>
                    
                    <!-- Test Results -->
                    <?php if ($current_answer): 
                        $test_results = json_decode($current_answer['test_results'], true);
                    ?>
                        <div class="mt-4">
                            <h3 class="font-bold text-white mb-2">Test Results:</h3>
                            <div class="space-y-2">
                                <?php foreach ($test_results['tests'] as $test): ?>
                                    <div class="p-3 <?= $test['passed'] ? 'bg-green-900/30' : 'bg-red-900/30' ?> rounded-lg border <?= $test['passed'] ? 'border-green-700' : 'border-red-700' ?>">
                                        <div class="flex items-center text-sm">
                                            <i class="fas <?= $test['passed'] ? 'fa-check-circle text-green-500' : 'fa-times-circle text-red-500' ?> mr-2"></i>
                                            <span class="font-medium text-white">Test <?= $test['test_number'] ?>:</span>
                                            <span class="ml-2 text-gray-300">Input: <?= htmlspecialchars($test['input']) ?></span>
                                            <span class="ml-2 text-green-400">Expected: <?= htmlspecialchars($test['expected']) ?></span>
                                            <span class="ml-2 <?= $test['passed'] ? 'text-green-400' : 'text-red-400' ?>">
                                                Got: <?= htmlspecialchars($test['actual']) ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="mt-3 text-right">
                                <span class="text-white font-bold">Score: <?= $current_answer['score'] ?>%</span>
                                <span class="ml-2 text-gray-400">(<?= $current_answer['passed_tests'] ?>/<?= $current_answer['total_tests'] ?> tests passed)</span>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

    <?php else: ?>
        <!-- Labs List View -->
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-gray-800">Programming Labs</h1>
            <p class="text-gray-600">Select a programming lab to start coding</p>
        </div>

        <!-- Labs Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php if (empty($labs)): ?>
                <div class="col-span-3 text-center py-12">
                    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-8">
                        <i class="fas fa-database text-yellow-500 text-4xl mb-4"></i>
                        <h3 class="text-xl font-bold text-gray-800 mb-2">No Labs Available</h3>
                        <p class="text-gray-600">Please contact your instructor to create programming labs.</p>
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
                                <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-code text-purple-600 text-xl"></i>
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
                                    <span class="text-gray-500">Problems:</span>
                                    <span class="font-medium"><?= $total ?></span>
                                </div>
                                
                                <?php if ($status != 'not_started'): ?>
                                    <div>
                                        <div class="flex justify-between text-sm mb-1">
                                            <span class="text-gray-500">Progress:</span>
                                            <span class="font-medium"><?= round($progress) ?>%</span>
                                        </div>
                                        <div class="w-full bg-gray-200 rounded-full h-2">
                                            <div class="bg-purple-600 h-2 rounded-full" style="width: <?= $progress ?>%"></div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <a href="index.php?page=programming_platform&lab=<?= $lab['id'] ?>" 
                               class="mt-6 block w-full text-center px-4 py-2 
                                      <?= $status == 'completed' ? 'bg-green-600 hover:bg-green-700' : 'bg-purple-600 hover:bg-purple-700' ?>
                                      text-white rounded-lg transition">
                                <?php 
                                if ($status == 'completed') echo '<i class="fas fa-redo mr-2"></i>Review';
                                elseif ($status == 'in_progress') echo '<i class="fas fa-play mr-2"></i>Continue';
                                else echo '<i class="fas fa-play mr-2"></i>Start Coding';
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
// Initialize CodeMirror
let editor = CodeMirror.fromTextArea(document.getElementById('code-editor'), {
    lineNumbers: true,
    mode: 'python',
    theme: 'dracula',
    autoCloseBrackets: true,
    matchBrackets: true,
    indentUnit: 4,
    tabSize: 4,
    lineWrapping: true
});

let currentQuestionId = <?= $current_question['id'] ?? 0 ?>;
let currentLanguage = '<?= $current_question['language'] ?? 'python' ?>';

// Language selector
document.getElementById('language-select').addEventListener('change', function() {
    const language = this.value;
    currentLanguage = language;
    
    let mode = 'python';
    switch(language) {
        case 'python': mode = 'python'; break;
        case 'javascript': mode = 'javascript'; break;
        case 'php': mode = 'php'; break;
    }
    
    editor.setOption('mode', mode);
});

// Run code
function runCode() {
    const code = editor.getValue();
    const output = document.getElementById('output');
    
    output.innerHTML = '<div class="text-yellow-400"><i class="fas fa-spinner fa-spin mr-2"></i>Running code...</div>';
    
    fetch(window.location.href, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
            'execute_code': '1',
            'code': code,
            'language': currentLanguage,
            'question_id': currentQuestionId
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            output.innerHTML = '<pre class="text-green-400">' + escapeHtml(data.output) + '</pre>';
        } else {
            output.innerHTML = '<div class="text-red-400">Error: ' + escapeHtml(data.error) + '</div>';
        }
    })
    .catch(error => {
        output.innerHTML = '<div class="text-red-400">Error: ' + escapeHtml(error) + '</div>';
    });
}

// Submit code
function submitCode() {
    if (!confirm('Are you sure you want to submit your code? This action cannot be undone.')) {
        return;
    }
    
    const code = editor.getValue();
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = window.location.href;
    
    const fields = {
        'submit_programming_answer': '1',
        'question_id': currentQuestionId,
        'lab_id': '<?= $_GET['lab'] ?? '' ?>',
        'code': code,
        'language': currentLanguage
    };
    
    for (const key in fields) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = key;
        input.value = fields[key];
        form.appendChild(input);
    }
    
    document.body.appendChild(form);
    form.submit();
}

// Clear output
function clearOutput() {
    document.getElementById('output').innerHTML = '<span class="text-gray-400">▶ Run your code to see output here...</span>';
}

// Escape HTML
function escapeHtml(unsafe) {
    return unsafe
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // Ctrl+Enter to run code
    if (e.ctrlKey && e.key === 'Enter') {
        e.preventDefault();
        runCode();
    }
    // Ctrl+Shift+Enter to submit
    if (e.ctrlKey && e.shiftKey && e.key === 'Enter') {
        e.preventDefault();
        submitCode();
    }
});
</script>

</body>
</html>