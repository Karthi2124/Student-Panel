<?php
// programming_platform.php
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors in output

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/config.php';

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
    $stmtStudent = $pdo->prepare("SELECT id, full_name, department FROM students WHERE id = ?");
    $stmtStudent->execute([$userId]);
    $user = $stmtStudent->fetch();
}

if (!$user) {
    die("User not found.");
}

// Handle AI code review
if (isset($_POST['ai_review'])) {
    header('Content-Type: application/json');
    try {
        $code = $_POST['code'];
        $language = $_POST['language'];
        $question_id = $_POST['question_id'];
        $runOutput = $_POST['run_output'] ?? '';
        
        $stmt = $pdo->prepare("SELECT * FROM programming_questions WHERE id = ?");
        $stmt->execute([$question_id]);
        $question = $stmt->fetch();
        
        if (!$question) {
            throw new Exception("Question not found");
        }
        
        $review = getAIAutoReview($code, $language, $runOutput, $question);
        
        echo json_encode(['success' => true, 'review' => $review]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Handle code execution
if (isset($_POST['execute_code'])) {
    header('Content-Type: application/json');
    try {
        $code = $_POST['code'];
        $language = $_POST['language'];
        
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
        
        $stmt = $pdo->prepare("SELECT * FROM programming_questions WHERE id = ?");
        $stmt->execute([$question_id]);
        $question = $stmt->fetch();
        
        if (!$question) {
            throw new Exception("Question not found");
        }
        
        $checkStmt = $pdo->prepare("SELECT * FROM student_programming_answers WHERE question_id = ? AND student_id = ?");
        $checkStmt->execute([$question_id, $userId]);
        $existing = $checkStmt->fetch();
        
        if ($existing) {
            $_SESSION['error_message'] = "You have already answered this question.";
            header("Location: index.php?page=programming_platform&lab=" . $lab_id);
            exit;
        }
        
        $test_results = runTestCases($code, $language, $question);
        $total_tests = count($test_results['tests']);
        $passed_tests = count(array_filter($test_results['tests'], function($test) {
            return $test['passed'];
        }));
        
        $score = $total_tests > 0 ? ($passed_tests / $total_tests) * 100 : 0;
        $is_correct = $score >= 70;
        
        $ai_feedback = null;
        if ($is_correct) {
            $ai_feedback = getAIFeedback($code, $language, $question, $test_results);
        } else {
            $ai_feedback = getAIImprovement($code, $language, $question, $test_results);
        }
        
        $insertStmt = $pdo->prepare("
            INSERT INTO student_programming_answers 
            (question_id, student_id, code, language, test_results, passed_tests, total_tests, score, is_correct, ai_feedback, submitted_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
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
            $is_correct ? 1 : 0,
            $ai_feedback
        ]);
        
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

// AI Review Function with Run Output
function getAIAutoReview($code, $language, $runOutput, $question) {
    try {
        $apiKey = defined('NVIDIA_API_KEY') ? NVIDIA_API_KEY : (defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '');
        
        if (empty($apiKey)) {
            return "AI review is not available. Please configure your API key.";
        }
        
        $prompt = "You are an expert programming instructor reviewing a student's code in real time.\n\n";
        $prompt .= "### Problem\nTitle: " . $question['title'] . "\nDescription: " . $question['description'] . "\n\n";
        $prompt .= "### Student Code (" . $language . ")\n```" . $language . "\n" . $code . "\n```\n\n";
        
        if (trim($runOutput) !== '') {
            $prompt .= "### Actual Output\n```\n" . $runOutput . "\n```\n\n";
        }
        
        $prompt .= "### Instructions\nProvide a concise, structured review with exactly these 4 sections:\n";
        $prompt .= "1. **What's Working** - what the student got right\n";
        $prompt .= "2. **Issues Found** - bugs or logic errors with line references\n";
        $prompt .= "3. **Suggestions** - improvements for readability and efficiency\n";
        $prompt .= "4. **Edge Cases** - inputs that could break this code\n";
        $prompt .= "Max 400 words. Plain text only.";
        
        $response = callAIAPI($prompt, $apiKey);
        return $response;
        
    } catch (Exception $e) {
        return "AI review temporarily unavailable: " . $e->getMessage();
    }
}

function getAIFeedback($code, $language, $question, $test_results) {
    try {
        $apiKey = defined('NVIDIA_API_KEY') ? NVIDIA_API_KEY : (defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '');
        
        if (empty($apiKey)) {
            return "Great job! Your solution passes the test cases.";
        }
        
        $passed_tests = count(array_filter($test_results['tests'], function($test) {
            return $test['passed'];
        }));
        $total_tests = count($test_results['tests']);
        
        $prompt = "You are a programming instructor. The student solved a problem correctly ($passed_tests/$total_tests tests passed).\n\n";
        $prompt .= "Question: " . $question['title'] . "\n";
        $prompt .= "Code:\n```" . $language . "\n" . $code . "\n```\n\n";
        $prompt .= "Give encouraging feedback, highlight strengths, and suggest any alternative approaches. Max 250 words.";
        
        $response = callAIAPI($prompt, $apiKey);
        return $response;
        
    } catch (Exception $e) {
        return "Excellent work! Your solution passed all test cases!";
    }
}

function getAIImprovement($code, $language, $question, $test_results) {
    try {
        $apiKey = defined('NVIDIA_API_KEY') ? NVIDIA_API_KEY : (defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '');
        
        if (empty($apiKey)) {
            return "Your solution did not pass all test cases. Review your logic and try again.";
        }
        
        $failed_tests = array_filter($test_results['tests'], function($test) {
            return !$test['passed'];
        });
        
        $prompt = "You are a programming instructor. The student's solution has issues.\n\n";
        $prompt .= "Question: " . $question['title'] . "\n";
        $prompt .= "Description: " . $question['description'] . "\n\n";
        $prompt .= "Code:\n```" . $language . "\n" . $code . "\n```\n\n";
        $prompt .= "Failed Tests:\n";
        foreach ($failed_tests as $test) {
            $prompt .= "- Input: {$test['input']}, Expected: {$test['expected']}, Got: {$test['actual']}\n";
        }
        $prompt .= "\nGive constructive hints - no complete solutions. Max 350 words.";
        
        $response = callAIAPI($prompt, $apiKey);
        return $response;
        
    } catch (Exception $e) {
        $failed = count(array_filter($test_results['tests'], function($test) {
            return !$test['passed'];
        }));
        return "Your solution did not pass $failed test case(s). Check your logic and try again.";
    }
}

function callAIAPI($prompt, $apiKey) {
    if (defined('NVIDIA_API_KEY') && !empty(NVIDIA_API_KEY)) {
        return callNVIDIAAPI($prompt, $apiKey);
    }
    
    if (defined('OPENAI_API_KEY') && !empty(OPENAI_API_KEY)) {
        return callOpenAIAPI($prompt, $apiKey);
    }
    
    throw new Exception("No valid API key configured");
}

function callNVIDIAAPI($prompt, $apiKey) {
    $url = "https://integrate.api.nvidia.com/v1/chat/completions";
    
    $data = [
        "model" => "meta/llama-3.1-8b-instruct",
        "messages" => [
            [
                "role" => "user",
                "content" => $prompt
            ]
        ],
        "temperature" => 0.6,
        "max_tokens" => 900,
        "top_p" => 0.95
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . $apiKey,
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        throw new Exception("NVIDIA API error: HTTP $httpCode");
    }
    
    $result = json_decode($response, true);
    
    if (isset($result['choices'][0]['message']['content'])) {
        return $result['choices'][0]['message']['content'];
    }
    
    throw new Exception("Invalid response from NVIDIA API");
}

function callOpenAIAPI($prompt, $apiKey) {
    $url = "https://api.openai.com/v1/chat/completions";
    
    $data = [
        "model" => "gpt-3.5-turbo",
        "messages" => [
            [
                "role" => "system",
                "content" => "You are a helpful programming instructor."
            ],
            [
                "role" => "user",
                "content" => $prompt
            ]
        ],
        "temperature" => 0.7,
        "max_tokens" => 800
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . $apiKey,
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        throw new Exception("OpenAI API error: HTTP $httpCode");
    }
    
    $result = json_decode($response, true);
    
    if (isset($result['choices'][0]['message']['content'])) {
        return $result['choices'][0]['message']['content'];
    }
    
    throw new Exception("Invalid response from OpenAI API");
}

function executeCode($code, $language) {
    $tmpDir = sys_get_temp_dir() . '/code_exec_' . uniqid();
    mkdir($tmpDir, 0700, true);
    $timeout = 5;
    
    $code = preg_replace('/\b(exec|shell_exec|system|passthru|popen|proc_open)\s*\(/i', "//blocked_$1(", $code);
    $code = preg_replace('/`.*?`/s', '"blocked_backtick"', $code);
    
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
    
    exec($command, $output, $return_var);
    $result = implode("\n", $output);
    cleanupDir($tmpDir);
    
    if ($return_var === 124) return "Execution timed out (5s limit)";
    return $result !== '' ? $result : '(no output)';
}

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

function runTestCases($code, $language, $question) {
    $test_cases = json_decode($question['test_cases'], true);
    if (!$test_cases) {
        $test_cases = [];
    }
    
    $results = ['tests' => []];
    
    foreach ($test_cases as $index => $test) {
        $test_code = prepareCodeWithTest($code, $language, $test);
        $output = executeCode($test_code, $language);
        $expected = trim($test['expected_output']);
        $actual = trim($output);
        
        $passed = strcasecmp($actual, $expected) === 0;
        if (!$passed && is_numeric($actual) && is_numeric($expected)) {
            $passed = abs(floatval($actual) - floatval($expected)) < 1e-9;
        }
        
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

function prepareCodeWithTest($code, $language, $test) {
    $input = $test['input'];
    
    $code = preg_replace('/print\s*\(.*\)\s*;?/m', '', $code);
    $code = preg_replace('/console\.log\s*\(.*\)\s*;?/m', '', $code);
    $code = preg_replace('/echo\s+.*;?/m', '', $code);
    
    switch($language) {
        case 'python':
            return $code . "\nprint(" . $input . ")";
        case 'javascript':
            return $code . "\nconsole.log(" . $input . ");";
        case 'php':
            return $code . "\necho " . $input . ";";
        default:
            return $code;
    }
}

function updateProgrammingLabProgress($pdo, $lab_id, $student_id) {
    $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM programming_questions WHERE lab_id = ?");
    $totalStmt->execute([$lab_id]);
    $total_questions = $totalStmt->fetchColumn();
    
    $answeredStmt = $pdo->prepare("
        SELECT COUNT(DISTINCT question_id) 
        FROM student_programming_answers 
        WHERE question_id IN (SELECT id FROM programming_questions WHERE lab_id = ?) 
        AND student_id = ?
    ");
    $answeredStmt->execute([$lab_id, $student_id]);
    $answered_questions = $answeredStmt->fetchColumn();
    
    $progress = $total_questions > 0 ? ($answered_questions / $total_questions) * 100 : 0;
    
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

function getStarterCode($lang) {
    $starters = [
        'python' => "# Write your solution here\n\ndef solution():\n    # your code here\n    pass\n\n# Example:\n# print(solution())\n",
        'javascript' => "// Write your solution here\n\nfunction solution() {\n    // your code here\n}\n\n// console.log(solution());\n",
        'php' => "<?php\n// Write your solution here\n\nfunction solution() {\n    // your code here\n}\n\n// echo solution();\n",
    ];
    return $starters[$lang] ?? "# Write your code here\n";
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

$selected_lab = null;
$questions = [];
$answers = [];
$current_question = null;
$current_answer = null;

if (isset($_GET['lab'])) {
    $lab_id = $_GET['lab'];
    
    $labStmt = $pdo->prepare("SELECT * FROM programming_labs WHERE id = ?");
    $labStmt->execute([$lab_id]);
    $selected_lab = $labStmt->fetch();
    
    if ($selected_lab) {
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
        
        $selected_question_id = $_GET['question'] ?? ($questions[0]['id'] ?? 0);
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
    }
}

$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: #0f0f1a;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Inter', system-ui, sans-serif;
        }
        
        /* Flash Messages */
        .flash-message {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
            animation: slideIn 0.3s ease-out;
        }
        
        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        /* CodeMirror Overrides */
        .CodeMirror {
            height: 100%;
            font-size: 14px;
            font-family: 'JetBrains Mono', 'Fira Code', monospace;
        }
        
        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        
        ::-webkit-scrollbar-track {
            background: #1e1e2e;
        }
        
        ::-webkit-scrollbar-thumb {
            background: #4a4a6a;
            border-radius: 3px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: #6a6a8a;
        }
        
        /* Difficulty Badges */
        .badge-easy {
            background: linear-gradient(135deg, #10b981, #059669);
        }
        
        .badge-medium {
            background: linear-gradient(135deg, #f59e0b, #d97706);
        }
        
        .badge-hard {
            background: linear-gradient(135deg, #ef4444, #dc2626);
        }
        
        /* Loading Spinner */
        .spinner {
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top-color: white;
            border-radius: 50%;
            width: 16px;
            height: 16px;
            animation: spin 0.6s linear infinite;
            display: inline-block;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* AI Feedback Animation */
        .ai-pulse {
            animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }
        
        @keyframes pulse {
            0%, 100% {
                opacity: 1;
            }
            50% {
                opacity: 0.5;
            }
        }
        
        /* Test Result Animations */
        .test-pass {
            animation: fadeIn 0.3s ease-out;
        }
        
        .test-fail {
            animation: shake 0.3s ease-out;
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }
        
        /* Button Hover Effects */
        .btn-hover-effect {
            transition: all 0.2s ease;
        }
        
        .btn-hover-effect:hover {
            transform: translateY(-2px);
            filter: brightness(1.1);
        }
        
        .btn-hover-effect:active {
            transform: translateY(0);
        }
    </style>
</head>
<body>

<!-- Flash Messages -->
<?php if ($success_message): ?>
<div class="flash-message" id="flashMessage">
    <div class="bg-green-500/90 backdrop-blur-sm text-white px-6 py-4 rounded-2xl shadow-2xl flex items-center gap-3 border border-green-400/30">
        <i class="fas fa-check-circle text-xl"></i>
        <span class="font-medium"><?= htmlspecialchars($success_message) ?></span>
        <button onclick="this.parentElement.parentElement.remove()" class="ml-4 text-white/70 hover:text-white transition">
            <i class="fas fa-times"></i>
        </button>
    </div>
</div>
<?php endif; ?>

<?php if ($error_message): ?>
<div class="flash-message" id="flashMessage">
    <div class="bg-red-500/90 backdrop-blur-sm text-white px-6 py-4 rounded-2xl shadow-2xl flex items-center gap-3 border border-red-400/30">
        <i class="fas fa-exclamation-circle text-xl"></i>
        <span class="font-medium"><?= htmlspecialchars($error_message) ?></span>
        <button onclick="this.parentElement.parentElement.remove()" class="ml-4 text-white/70 hover:text-white transition">
            <i class="fas fa-times"></i>
        </button>
    </div>
</div>
<?php endif; ?>

<?php if ($selected_lab && !empty($questions) && $current_question): ?>
<!-- Main Coding Interface -->
<div class="flex flex-col h-screen">
    
    <!-- Header -->
    <header class="bg-gradient-to-r from-gray-900 to-gray-800 border-b border-gray-700/50 px-6 py-3 flex items-center justify-between flex-shrink-0">
        <div class="flex items-center gap-4">
            <a href="index.php?page=programming_platform" class="text-gray-400 hover:text-white transition-all duration-200 flex items-center gap-2 px-3 py-1.5 rounded-lg hover:bg-gray-700/50">
                <i class="fas fa-arrow-left text-sm"></i>
                <span class="text-sm font-medium">Back to Labs</span>
            </a>
            <div class="h-6 w-px bg-gray-600"></div>
            <div>
                <h1 class="text-white font-semibold text-lg flex items-center gap-2">
                    <i class="fas fa-code text-indigo-400"></i>
                    <?= htmlspecialchars($selected_lab['lab_name']) ?>
                </h1>
                <p class="text-gray-400 text-xs mt-0.5"><?= htmlspecialchars($selected_lab['description']) ?></p>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <div class="bg-gray-800/50 rounded-full px-4 py-1.5 border border-gray-700">
                <span class="text-gray-400 text-xs">Progress</span>
                <span class="text-white font-semibold text-sm ml-2"><?= count(array_filter($answers)) ?>/<?= count($questions) ?></span>
            </div>
        </div>
    </header>
    
    <!-- Question Tabs -->
    <div class="bg-gray-900/50 border-b border-gray-800 px-4 flex gap-1 overflow-x-auto flex-shrink-0">
        <?php foreach ($questions as $i => $q):
            $done = isset($answers[$q['id']]);
            $active = ($q['id'] == $current_question['id']);
        ?>
        <a href="index.php?page=programming_platform&lab=<?= $selected_lab['id'] ?>&question=<?= $q['id'] ?>" 
           class="px-5 py-2.5 text-sm font-medium transition-all duration-200 whitespace-nowrap relative
                  <?= $active ? 'text-white' : 'text-gray-400 hover:text-gray-300' ?>">
            <?= $i + 1 ?>. <?= htmlspecialchars($q['title']) ?>
            <?php if ($done): ?>
                <i class="fas fa-check-circle text-green-500 text-xs ml-1.5"></i>
            <?php endif; ?>
            <?php if ($active): ?>
                <div class="absolute bottom-0 left-0 right-0 h-0.5 bg-indigo-500 rounded-full"></div>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>
    
    <!-- Main Content Split View -->
    <div class="flex-1 flex overflow-hidden">
        
        <!-- Left Panel - Question Details -->
        <div class="w-2/5 bg-white overflow-y-auto border-r border-gray-200">
            <div class="p-6">
                <!-- Header with Difficulty -->
                <div class="flex items-center justify-between mb-5">
                    <span class="badge-<?= $current_question['difficulty'] ?> text-white text-xs font-bold px-3 py-1 rounded-full shadow-lg">
                        <i class="fas fa-chart-line mr-1 text-xs"></i>
                        <?= ucfirst($current_question['difficulty']) ?>
                    </span>
                    <span class="bg-indigo-50 text-indigo-700 text-xs font-semibold px-3 py-1 rounded-full border border-indigo-200">
                        <i class="fas fa-code mr-1"></i>
                        <?= ucfirst($current_question['language']) ?>
                    </span>
                </div>
                
                <!-- Title -->
                <h2 class="text-2xl font-bold text-gray-900 mb-4"><?= htmlspecialchars($current_question['title']) ?></h2>
                
                <!-- Description -->
                <div class="mb-6">
                    <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-2 flex items-center gap-2">
                        <i class="fas fa-book-open text-indigo-500 text-xs"></i>
                        Problem Description
                    </h3>
                    <div class="text-gray-600 leading-relaxed text-sm space-y-2">
                        <?= nl2br(htmlspecialchars($current_question['description'])) ?>
                    </div>
                </div>
                
                <!-- Examples -->
                <?php if ($current_question['examples']): ?>
                <div class="mb-6">
                    <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-2 flex items-center gap-2">
                        <i class="fas fa-lightbulb text-yellow-500 text-xs"></i>
                        Examples
                    </h3>
                    <div class="bg-gray-900 rounded-xl p-4 font-mono text-sm">
                        <?= nl2br(htmlspecialchars($current_question['examples'])) ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Constraints -->
                <?php if ($current_question['constraints']): ?>
                <div class="mb-6">
                    <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-2 flex items-center gap-2">
                        <i class="fas fa-chart-bar text-blue-500 text-xs"></i>
                        Constraints
                    </h3>
                    <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 text-sm text-amber-800">
                        <?= nl2br(htmlspecialchars($current_question['constraints'])) ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Test Cases -->
                <?php if ($current_question['test_cases']): 
                    $test_cases = json_decode($current_question['test_cases'], true);
                ?>
                <div class="mb-6">
                    <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-2 flex items-center gap-2">
                        <i class="fas fa-vial text-green-500 text-xs"></i>
                        Test Cases
                    </h3>
                    <div class="space-y-2">
                        <?php foreach ($test_cases as $index => $test): ?>
                        <div class="bg-gray-50 rounded-lg p-3 border border-gray-200">
                            <div class="flex items-center justify-between text-sm">
                                <span class="font-mono text-indigo-600 font-semibold">Test <?= $index + 1 ?></span>
                                <span class="text-gray-500 text-xs">Input → Output</span>
                            </div>
                            <div class="font-mono text-sm mt-1">
                                <span class="text-gray-700"><?= htmlspecialchars($test['input']) ?></span>
                                <i class="fas fa-arrow-right text-gray-400 mx-2 text-xs"></i>
                                <span class="text-green-600 font-semibold"><?= htmlspecialchars($test['expected_output']) ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Submission AI Feedback -->
                <?php if ($current_answer && !empty($current_answer['ai_feedback'])): ?>
                <div class="mt-6 bg-gradient-to-r from-indigo-50 to-purple-50 rounded-xl p-4 border border-indigo-200">
                    <div class="flex items-center gap-2 mb-2">
                        <i class="fas fa-robot text-indigo-600"></i>
                        <h3 class="font-semibold text-gray-800 text-sm">AI Feedback on Submission</h3>
                    </div>
                    <div class="text-sm text-gray-700 leading-relaxed">
                        <?= nl2br(htmlspecialchars($current_answer['ai_feedback'])) ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Right Panel - Code Editor -->
        <div class="flex-1 flex flex-col bg-gray-900">
            <!-- Toolbar -->
            <div class="bg-gray-800 border-b border-gray-700 px-4 py-2 flex items-center justify-between flex-shrink-0">
                <div class="flex items-center gap-3">
                    <select id="language-select" class="bg-gray-700 text-white text-sm rounded-lg px-3 py-1.5 border border-gray-600 focus:outline-none focus:border-indigo-500 transition">
                        <option value="python" <?= ($current_question['language'] ?? '') == 'python' ? 'selected' : '' ?>>🐍 Python</option>
                        <option value="javascript" <?= ($current_question['language'] ?? '') == 'javascript' ? 'selected' : '' ?>>⚡ JavaScript</option>
                        <option value="php" <?= ($current_question['language'] ?? '') == 'php' ? 'selected' : '' ?>>🐘 PHP</option>
                    </select>
                </div>
                <div class="flex items-center gap-2">
                    <button onclick="resetEditor()" class="bg-gray-700 hover:bg-gray-600 text-white text-sm px-3 py-1.5 rounded-lg transition flex items-center gap-2">
                        <i class="fas fa-undo-alt text-xs"></i>
                        Reset
                    </button>
                    <button onclick="runCode()" id="run-btn" class="bg-blue-600 hover:bg-blue-700 text-white text-sm px-4 py-1.5 rounded-lg transition flex items-center gap-2">
                        <i class="fas fa-play text-xs"></i>
                        Run
                        <span class="text-xs opacity-75 ml-1">⌘↵</span>
                    </button>
                    <?php if (!$current_answer): ?>
                    <button onclick="submitCode()" class="bg-green-600 hover:bg-green-700 text-white text-sm px-4 py-1.5 rounded-lg transition flex items-center gap-2">
                        <i class="fas fa-paper-plane text-xs"></i>
                        Submit
                    </button>
                    <?php else: ?>
                    <div class="bg-green-500/20 text-green-400 text-sm px-3 py-1.5 rounded-lg flex items-center gap-2 border border-green-500/30">
                        <i class="fas fa-check-circle"></i>
                        Submitted • Score: <?= round($current_answer['score']) ?>%
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Code Editor -->
            <div class="flex-1 overflow-hidden">
                <textarea id="code-editor" class="w-full h-full"><?= $current_answer ? htmlspecialchars($current_answer['code']) : htmlspecialchars(getStarterCode($current_question['language'] ?? 'python')) ?></textarea>
            </div>
            
            <!-- Bottom Panels: Output + AI Review -->
            <div class="h-64 border-t border-gray-700 flex flex-shrink-0">
                <!-- Output Panel -->
                <div class="w-1/2 flex flex-col border-r border-gray-700">
                    <div class="bg-gray-800 px-4 py-1.5 text-xs font-semibold text-gray-400 uppercase tracking-wide flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <i class="fas fa-terminal"></i>
                            <span>Output</span>
                        </div>
                        <button onclick="clearOutput()" class="text-gray-500 hover:text-gray-300 transition text-xs">
                            <i class="fas fa-trash-alt"></i> Clear
                        </button>
                    </div>
                    <div id="output" class="flex-1 overflow-y-auto p-3 font-mono text-sm bg-gray-950 text-green-400">
                        <span class="text-gray-500">▶ Run your code to see output here...</span>
                    </div>
                </div>
                
                <!-- AI Review Panel -->
                <div class="w-1/2 flex flex-col">
                    <div class="bg-gradient-to-r from-indigo-600 to-purple-600 px-4 py-1.5 text-xs font-semibold text-white uppercase tracking-wide flex items-center gap-2">
                        <i class="fas fa-robot"></i>
                        <span>NVIDIA LLaMA · Live AI Review</span>
                        <span id="ai-dot" class="ml-auto w-2 h-2 bg-green-400 rounded-full animate-pulse"></span>
                    </div>
                    <div id="ai-review" class="flex-1 overflow-y-auto p-3 text-sm bg-gray-900 text-gray-300 leading-relaxed">
                        <span class="text-gray-500">✨ AI review will appear here after running your code...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php else: ?>
<!-- Labs List View -->
<div class="min-h-screen bg-gradient-to-br from-gray-900 via-gray-800 to-gray-900">
    <div class="container mx-auto px-6 py-12">
        <div class="text-center mb-12">
            <div class="inline-flex items-center justify-center w-20 h-20 bg-indigo-500/20 rounded-2xl mb-4">
                <i class="fas fa-code text-4xl text-indigo-400"></i>
            </div>
            <h1 class="text-4xl font-bold text-white mb-2">Programming Labs</h1>
            <p class="text-gray-400 text-lg">Select a lab to start your coding journey</p>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 max-w-7xl mx-auto">
            <?php if (empty($labs)): ?>
                <div class="col-span-full text-center py-16">
                    <div class="bg-gray-800/50 backdrop-blur-sm rounded-2xl p-8 border border-gray-700">
                        <i class="fas fa-database text-5xl text-yellow-500 mb-4"></i>
                        <h3 class="text-xl font-bold text-white mb-2">No Labs Available</h3>
                        <p class="text-gray-400">Please contact your instructor to create programming labs.</p>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($labs as $lab): 
                    $progress = $lab['progress'] ?? 0;
                    $status = $lab['progress_status'] ?? 'not_started';
                    $total = $lab['total_questions'] ?? 0;
                ?>
                    <div class="group bg-gray-800/50 backdrop-blur-sm rounded-2xl overflow-hidden border border-gray-700 hover:border-indigo-500 transition-all duration-300 hover:transform hover:scale-105">
                        <div class="p-6">
                            <div class="flex items-start justify-between mb-4">
                                <div class="w-12 h-12 bg-gradient-to-br from-indigo-500 to-purple-600 rounded-xl flex items-center justify-center shadow-lg">
                                    <i class="fas fa-code text-white text-xl"></i>
                                </div>
                                <span class="px-3 py-1 rounded-full text-xs font-semibold
                                    <?php 
                                    if ($status == 'completed') echo 'bg-green-500/20 text-green-400 border border-green-500/30';
                                    elseif ($status == 'in_progress') echo 'bg-yellow-500/20 text-yellow-400 border border-yellow-500/30';
                                    else echo 'bg-gray-600/50 text-gray-400 border border-gray-600';
                                    ?>">
                                    <?= ucfirst(str_replace('_', ' ', $status)) ?>
                                </span>
                            </div>
                            
                            <h3 class="text-xl font-bold text-white mb-2 group-hover:text-indigo-400 transition"><?= htmlspecialchars($lab['lab_name']) ?></h3>
                            <p class="text-gray-400 text-sm mb-4 line-clamp-2"><?= htmlspecialchars(substr($lab['description'] ?? '', 0, 100)) ?>...</p>
                            
                            <div class="space-y-3">
                                <div class="flex justify-between text-sm">
                                    <span class="text-gray-500">Problems:</span>
                                    <span class="text-white font-semibold"><?= $total ?></span>
                                </div>
                                
                                <?php if ($status != 'not_started'): ?>
                                    <div>
                                        <div class="flex justify-between text-sm mb-1">
                                            <span class="text-gray-500">Progress:</span>
                                            <span class="text-white font-semibold"><?= round($progress) ?>%</span>
                                        </div>
                                        <div class="w-full bg-gray-700 rounded-full h-2 overflow-hidden">
                                            <div class="bg-gradient-to-r from-indigo-500 to-purple-600 h-2 rounded-full transition-all duration-500" style="width: <?= $progress ?>%"></div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <a href="index.php?page=programming_platform&lab=<?= $lab['id'] ?>" 
                               class="mt-6 block w-full text-center px-4 py-2.5 rounded-xl font-semibold transition-all duration-200
                                      <?= $status == 'completed' 
                                          ? 'bg-green-600/20 text-green-400 border border-green-500/50 hover:bg-green-600/30' 
                                          : 'bg-gradient-to-r from-indigo-600 to-purple-600 text-white hover:shadow-lg' ?>">
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
    </div>
</div>
<?php endif; ?>

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
    lineWrapping: true,
    gutters: ["CodeMirror-linenumbers"]
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

// Reset editor to starter code
function resetEditor() {
    if (confirm('Reset to starter code? Your current code will be lost.')) {
        const starters = {
            'python': '# Write your solution here\n\ndef solution():\n    # your code here\n    pass\n\n# Example:\n# print(solution())\n',
            'javascript': '// Write your solution here\n\nfunction solution() {\n    // your code here\n}\n\n// console.log(solution());\n',
            'php': '<?php\n// Write your solution here\n\nfunction solution() {\n    // your code here\n}\n\n// echo solution();\n?>'
        };
        editor.setValue(starters[currentLanguage] || '# Write your code here\n');
    }
}

// Run code
async function runCode() {
    const code = editor.getValue();
    const output = document.getElementById('output');
    const runBtn = document.getElementById('run-btn');
    const aiReview = document.getElementById('ai-review');
    
    runBtn.disabled = true;
    runBtn.innerHTML = '<div class="spinner mr-2"></div>Running...';
    output.innerHTML = '<div class="text-yellow-400"><div class="spinner mr-2"></div>Executing code...</div>';
    aiReview.innerHTML = '<div class="text-purple-400"><div class="spinner mr-2"></div>AI is analyzing your code...</div>';
    
    try {
        // Execute code
        const execResponse = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                'execute_code': '1',
                'code': code,
                'language': currentLanguage
            })
        });
        
        const execData = await execResponse.json();
        const runOutput = execData.success ? (execData.output || '(no output)') : ('Error: ' + (execData.error || 'Unknown'));
        
        if (execData.success) {
            output.innerHTML = `<pre class="text-green-400 whitespace-pre-wrap break-words">${escapeHtml(runOutput)}</pre>`;
        } else {
            output.innerHTML = `<pre class="text-red-400 whitespace-pre-wrap break-words">${escapeHtml(runOutput)}</pre>`;
        }
        
        // Get AI review
        const aiResponse = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                'ai_review': '1',
                'code': code,
                'language': currentLanguage,
                'question_id': currentQuestionId,
                'run_output': runOutput
            })
        });
        
        const aiData = await aiResponse.json();
        
        if (aiData.success) {
            aiReview.innerHTML = formatAIReview(aiData.review);
        } else {
            aiReview.innerHTML = `<div class="text-red-400">Error: ${escapeHtml(aiData.error)}</div>`;
        }
        
    } catch (error) {
        output.innerHTML = `<div class="text-red-400">Error: ${escapeHtml(error.message)}</div>`;
        aiReview.innerHTML = `<div class="text-red-400">Failed to get AI review: ${escapeHtml(error.message)}</div>`;
    } finally {
        runBtn.disabled = false;
        runBtn.innerHTML = '<i class="fas fa-play text-xs"></i> Run <span class="text-xs opacity-75 ml-1">⌘↵</span>';
    }
}

// Format AI review with better styling
function formatAIReview(text) {
    let formatted = escapeHtml(text)
        .replace(/\*\*(.+?)\*\*/g, '<strong class="text-indigo-300">$1</strong>')
        .replace(/`([^`]+)`/g, '<code class="bg-gray-800 text-indigo-300 px-1 py-0.5 rounded text-xs">$1</code>')
        .replace(/^(\d+\.\s+)/gm, '<span class="text-indigo-400 font-bold">$1</span>')
        .replace(/\n/g, '<br>');
    
    return `<div class="text-sm leading-relaxed space-y-2">${formatted}</div>`;
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
    document.getElementById('output').innerHTML = '<span class="text-gray-500">▶ Run your code to see output here...</span>';
}

// Escape HTML
function escapeHtml(unsafe) {
    if (!unsafe) return '';
    return unsafe
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

// Auto-dismiss flash message after 5 seconds
const flashMessage = document.getElementById('flashMessage');
if (flashMessage) {
    setTimeout(() => {
        flashMessage.style.animation = 'slideOut 0.3s ease-out forwards';
        setTimeout(() => flashMessage.remove(), 300);
    }, 5000);
}

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // Ctrl+Enter to run code
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        e.preventDefault();
        runCode();
    }
    // Ctrl+Shift+Enter to submit
    if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key === 'Enter') {
        e.preventDefault();
        submitCode();
    }
});
</script>

<style>
@keyframes slideOut {
    from {
        transform: translateX(0);
        opacity: 1;
    }
    to {
        transform: translateX(100%);
        opacity: 0;
    }
}
</style>

</body>
</html>