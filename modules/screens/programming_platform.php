<?php
// programming_platform.php
error_reporting(E_ALL);
ini_set('display_errors', 0);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/config.php';

$success_message = '';
$error_message = '';
$selected_lab = null;
$questions = [];
$answers = [];
$labs = [];
$user = null;
$current_question = null;
$current_answer = null;

$userId = $_SESSION['user_id'] ?? $_SESSION['student_id'] ?? null;
if (!$userId) {
    header("Location: login.php");
    exit;
}

try {
    $stmtUser = $pdo->prepare("SELECT id, full_name, department, role FROM users WHERE id = ?");
    $stmtUser->execute([$userId]);
    $user = $stmtUser->fetch();
    if (!$user) {
        $stmtStudent = $pdo->prepare("SELECT id, full_name, department FROM students WHERE id = ?");
        $stmtStudent->execute([$userId]);
        $user = $stmtStudent->fetch();
    }
    if (!$user) die("User not found.");
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    die("Database error occurred.");
}

// Handle AI code review
if (isset($_POST['ai_review'])) {
    ob_clean(); // ✅ ADD THIS LINE
    header('Content-Type: application/json');
    try {
        $code = $_POST['code'];
        $language = $_POST['language'];
        $question_id = $_POST['question_id'];
        $runOutput = $_POST['run_output'] ?? '';
        $stmt = $pdo->prepare("SELECT * FROM programming_questions WHERE id = ?");
        $stmt->execute([$question_id]);
        $question = $stmt->fetch();
        if (!$question) throw new Exception("Question not found");
        $review = getAIAutoReview($code, $language, $runOutput, $question);
        echo json_encode(['success' => true, 'review' => $review]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Handle code execution
if (isset($_POST['execute_code'])) {
    ob_clean(); // ✅ ADD THIS LINE
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

// Handle answer submission
if (isset($_POST['submit_programming_answer'])) {
    try {
        $question_id = $_POST['question_id'];
        $lab_id = $_POST['lab_id'];
        $code = $_POST['code'];
        $language = $_POST['language'];
        $stmt = $pdo->prepare("SELECT * FROM programming_questions WHERE id = ?");
        $stmt->execute([$question_id]);
        $question = $stmt->fetch();
        if (!$question) throw new Exception("Question not found");
        
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
        $passed_tests = count(array_filter($test_results['tests'], function($test) { return $test['passed']; }));
        $score = $total_tests > 0 ? ($passed_tests / $total_tests) * 100 : 0;
        $is_correct = $score >= 70;
        $ai_feedback = $is_correct ? getAIFeedback($code, $language, $question, $test_results) : getAIImprovement($code, $language, $question, $test_results);
        
        $insertStmt = $pdo->prepare("INSERT INTO student_programming_answers (question_id, student_id, code, language, test_results, passed_tests, total_tests, score, is_correct, ai_feedback, submitted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $insertStmt->execute([$question_id, $userId, $code, $language, json_encode($test_results), $passed_tests, $total_tests, $score, $is_correct ? 1 : 0, $ai_feedback]);
        
        updateProgrammingLabProgress($pdo, $lab_id, $userId);
        $_SESSION['success_message'] = "Code submitted successfully! Passed $passed_tests/$total_tests tests.";
        header("Location: index.php?page=programming_platform&lab=" . $lab_id);
        exit;
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error: " . $e->getMessage();
        header("Location: index.php?page=programming_platform&lab=" . ($_POST['lab_id'] ?? ''));
        exit;
    }
}

function getAIAutoReview($code, $language, $runOutput, $question) {
    try {
        $apiKey = NVIDIA_API_KEY;
        if (empty($apiKey)) return "AI review is not available.";
        
        $prompt = "You are an expert programming instructor reviewing a student's code in real time.\n\n";
        $prompt .= "### Problem\nTitle: " . $question['title'] . "\nDescription: " . $question['description'] . "\n\n";
        $prompt .= "### Student Code (" . $language . ")\n```" . $language . "\n" . $code . "\n```\n\n";
        if (trim($runOutput) !== '') $prompt .= "### Actual Output\n```\n" . $runOutput . "\n```\n\n";
        $prompt .= "### Instructions\nProvide a concise, structured review with exactly these 4 sections:\n";
        $prompt .= "1. **What's Working** - what the student got right\n";
        $prompt .= "2. **Issues Found** - bugs or logic errors with line references\n";
        $prompt .= "3. **Suggestions** - improvements for readability and efficiency\n";
        $prompt .= "4. **Edge Cases** - inputs that could break this code\n";
        $prompt .= "Max 400 words. Plain text only.";
        
        return callNVIDIAAPI($prompt, $apiKey);
    } catch (Exception $e) {
        return "AI review temporarily unavailable: " . $e->getMessage();
    }
}

function getAIFeedback($code, $language, $question, $test_results) {
    try {
        $apiKey = NVIDIA_API_KEY;
        if (empty($apiKey)) return "Great job! Your solution passes the test cases.";
        
        $passed_tests = count(array_filter($test_results['tests'], function($test) { return $test['passed']; }));
        $total_tests = count($test_results['tests']);
        $prompt = "You are a programming instructor. The student solved a problem correctly ($passed_tests/$total_tests tests passed).\n\n";
        $prompt .= "Question: " . $question['title'] . "\nCode:\n```" . $language . "\n" . $code . "\n```\n\n";
        $prompt .= "Give encouraging feedback, highlight strengths, and suggest any alternative approaches. Max 250 words.";
        
        return callNVIDIAAPI($prompt, $apiKey);
    } catch (Exception $e) {
        return "Excellent work! Your solution passed all test cases!";
    }
}

function getAIImprovement($code, $language, $question, $test_results) {
    try {
        $apiKey = NVIDIA_API_KEY;
        if (empty($apiKey)) return "Your solution did not pass all test cases. Review your logic and try again.";
        
        $failed_tests = array_filter($test_results['tests'], function($test) { return !$test['passed']; });
        $prompt = "You are a programming instructor. The student's solution has issues.\n\nQuestion: " . $question['title'] . "\nDescription: " . $question['description'] . "\n\nCode:\n```" . $language . "\n" . $code . "\n```\n\nFailed Tests:\n";
        foreach ($failed_tests as $test) $prompt .= "- Input: {$test['input']}, Expected: {$test['expected']}, Got: {$test['actual']}\n";
        $prompt .= "\nGive constructive hints - no complete solutions. Max 350 words.";
        
        return callNVIDIAAPI($prompt, $apiKey);
    } catch (Exception $e) {
        $failed = count(array_filter($test_results['tests'], function($test) { return !$test['passed']; }));
        return "Your solution did not pass $failed test case(s). Check your logic and try again.";
    }
}

function callNVIDIAAPI($prompt, $apiKey) {
    $url = "https://integrate.api.nvidia.com/v1/chat/completions";
    $data = [
        "model" => "meta/llama-3.1-8b-instruct",
        "messages" => [["role" => "user", "content" => $prompt]],
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
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        throw new Exception("CURL error: " . $curlError);
    }
    
    if ($httpCode !== 200) {
        throw new Exception("API error: HTTP $httpCode");
    }
    
    $result = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON response");
    }
    
    if (!isset($result['choices'][0]['message']['content'])) {
        throw new Exception("Unexpected API response structure");
    }
    
    return $result['choices'][0]['message']['content'];
}

function executeCode($code, $language) {
    $tmpDir = __DIR__ . '/tmp/code_exec_' . uniqid();
    mkdir($tmpDir, 0777, true);
    $timeout = 5;
    
    // Security: block dangerous functions
    $code = preg_replace('/\b(exec|shell_exec|system|passthru|popen|proc_open)\s*\(/i', "//blocked_$1(", $code);
    $code = preg_replace('/`.*?`/s', '"blocked_backtick"', $code);
    
    $filename = '';
    $command = '';
    
    switch($language) {
        case 'python':
            $filename = $tmpDir . '/script.py';
            file_put_contents($filename, $code);
            chmod($filename, 0777); // ✅ allow execution
            $command = "python3 {$filename} 2>&1";
            break;
            
        case 'javascript':
            $filename = $tmpDir . '/script.js';
            file_put_contents($filename, $code);
            $command = "node {$filename} 2>&1";
            break;
            
        case 'php':
            $filename = $tmpDir . '/script.php';
            file_put_contents($filename, $code);
            $command = "php {$filename} 2>&1";
            break;
            
        default:
            cleanupDir($tmpDir);
            return "Unsupported language: " . $language;
    }
    
    // Execute the command
    $output = [];
    $return_var = 0;
    exec($command, $output, $return_var);
    
    // Clean up
    cleanupDir($tmpDir);
    
    // Check for timeout
    if ($return_var === 124) {
        return "Execution timed out ({$timeout}s limit)";
    }
    
    $result = implode("\n", $output);
    return $result !== '' ? $result : '(no output)';
}

function cleanupDir($dir) {
    if (is_dir($dir)) { 
        $files = glob($dir . '/*'); 
        foreach ($files as $file) {
            if (is_file($file)) unlink($file);
        }
        rmdir($dir); 
    }
}

function runTestCases($code, $language, $question) {
    $test_cases = json_decode($question['test_cases'], true);
    if (!$test_cases) $test_cases = [];
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
    
    // Remove print/echo statements that might interfere with testing
    $code = preg_replace('/print\s*\(.*\)\s*;?/m', '', $code);
    $code = preg_replace('/console\.log\s*\(.*\)\s*;?/m', '', $code);
    $code = preg_replace('/echo\s+.*;?/m', '', $code);
    
    switch($language) {
        case 'python':
            return $code . "\n\n# Test case\nprint(solution(" . $input . "))";
        case 'javascript':
            return $code . "\n\n// Test case\nconsole.log(solution(" . $input . "));";
        case 'php':
            return $code . "\n\n// Test case\necho solution(" . $input . ");";
        default:
            return $code;
    }
}

function updateProgrammingLabProgress($pdo, $lab_id, $student_id) {
    $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM programming_questions WHERE lab_id = ?");
    $totalStmt->execute([$lab_id]);
    $total_questions = $totalStmt->fetchColumn();
    
    $answeredStmt = $pdo->prepare("SELECT COUNT(DISTINCT question_id) FROM student_programming_answers WHERE question_id IN (SELECT id FROM programming_questions WHERE lab_id = ?) AND student_id = ?");
    $answeredStmt->execute([$lab_id, $student_id]);
    $answered_questions = $answeredStmt->fetchColumn();
    
    $progress = $total_questions > 0 ? ($answered_questions / $total_questions) * 100 : 0;
    
    $checkStmt = $pdo->prepare("SELECT * FROM student_programming_progress WHERE lab_id = ? AND student_id = ?");
    $checkStmt->execute([$lab_id, $student_id]);
    $existing = $checkStmt->fetch();
    
    if ($existing) {
        $updateStmt = $pdo->prepare("UPDATE student_programming_progress SET progress = ?, answered_questions = ?, last_activity = NOW(), status = CASE WHEN ? >= 100 THEN 'completed' ELSE 'in_progress' END WHERE lab_id = ? AND student_id = ?");
        $updateStmt->execute([$progress, $answered_questions, $progress, $lab_id, $student_id]);
    } else {
        $insertStmt = $pdo->prepare("INSERT INTO student_programming_progress (lab_id, student_id, progress, answered_questions, total_questions, status, started_at, last_activity) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())");
        $status = $progress >= 100 ? 'completed' : 'in_progress';
        $insertStmt->execute([$lab_id, $student_id, $progress, $answered_questions, $total_questions, $status]);
    }
}

function getStarterCode($lang) {
    $starters = [
        'python' => "# Write your solution here\n\ndef solution(a, b):\n    # your code here\n    return a + b\n\n# Example:\n# print(solution(5, 3))\n",
        'javascript' => "// Write your solution here\n\nfunction solution(a, b) {\n    // your code here\n    return a + b;\n}\n\n// console.log(solution(5, 3));\n",
        'php' => "<?php\n// Write your solution here\n\nfunction solution(\$a, \$b) {\n    // your code here\n    return \$a + \$b;\n}\n\n// echo solution(5, 3);\n",
    ];
    return $starters[$lang] ?? "# Write your code here\n";
}

// Get programming labs
try {
    $labsStmt = $pdo->prepare("SELECT l.*, (SELECT COUNT(*) FROM programming_questions WHERE lab_id = l.id) as total_questions, spp.progress, spp.status as progress_status, spp.answered_questions, spp.last_activity FROM programming_labs l LEFT JOIN student_programming_progress spp ON l.id = spp.lab_id AND spp.student_id = ? WHERE l.department = ? OR l.department IS NULL ORDER BY CASE WHEN spp.status = 'in_progress' THEN 0 WHEN spp.status = 'not_started' THEN 1 ELSE 2 END, spp.last_activity DESC, l.created_at DESC");
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

// Determine view mode
$view_mode = 'dashboard';

if (isset($_GET['lab'])) {
    $lab_id = $_GET['lab'];
    $labStmt = $pdo->prepare("SELECT * FROM programming_labs WHERE id = ?");
    $labStmt->execute([$lab_id]);
    $selected_lab = $labStmt->fetch();

    if ($selected_lab) {
        $questionsStmt = $pdo->prepare("SELECT * FROM programming_questions WHERE lab_id = ? ORDER BY CASE difficulty WHEN 'easy' THEN 1 WHEN 'medium' THEN 2 WHEN 'hard' THEN 3 END, created_at");
        $questionsStmt->execute([$lab_id]);
        $questions = $questionsStmt->fetchAll();

        $question_ids = array_column($questions, 'id');
        if (!empty($question_ids)) {
            $placeholders = implode(',', array_fill(0, count($question_ids), '?'));
            $answersStmt = $pdo->prepare("SELECT * FROM student_programming_answers WHERE question_id IN ($placeholders) AND student_id = ?");
            $params = array_merge($question_ids, [$userId]);
            $answersStmt->execute($params);
            $answers_data = $answersStmt->fetchAll();
            foreach ($answers_data as $answer) $answers[$answer['question_id']] = $answer;
        }

        if (isset($_GET['question'])) {
            $view_mode = 'ide';
            $selected_question_id = $_GET['question'];
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
        } else {
            $view_mode = 'question_list';
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>CodeLabs | Programming Platform</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.css">
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

        :root {
            --bg: #ffffff;
            --bg-secondary: #f9fafb;
            --bg-tertiary: #f3f4f6;
            --border: #e5e7eb;
            --border-light: #f0f0f0;
            --text-primary: #111827;
            --text-secondary: #6b7280;
            --text-muted: #9ca3af;
            --accent: #2563eb;
            --accent-hover: #1d4ed8;
            --accent-light: #eff6ff;
            --accent-border: #bfdbfe;
            --green: #16a34a;
            --green-bg: #f0fdf4;
            --green-border: #bbf7d0;
            --red: #dc2626;
            --red-bg: #fef2f2;
            --red-border: #fecaca;
            --yellow: #d97706;
            --yellow-bg: #fffbeb;
            --yellow-border: #fde68a;
            --radius-sm: 4px;
            --radius: 8px;
            --radius-lg: 12px;
            --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
            --shadow: 0 1px 3px rgba(0,0,0,0.1);
            --font-sans: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            --font-mono: 'JetBrains Mono', 'Fira Code', monospace;
        }

        html, body {
            height: 100%;
            overflow: hidden;
            width: 100%;
            position: fixed;
        }

        body {
            font-family: var(--font-sans);
            background: var(--bg);
            color: var(--text-primary);
            line-height: 1.5;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: var(--bg-tertiary); border-radius: 99px; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 99px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

        .topnav {
            height: 52px;
            border-bottom: 1px solid var(--border);
            background: var(--bg);
            display: flex;
            align-items: center;
            padding: 0 24px;
            gap: 20px;
            flex-shrink: 0;
        }
        
        .topnav-logo {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 700;
            font-size: 16px;
            color: var(--text-primary);
            text-decoration: none;
        }
        
        .topnav-logo .logo-icon {
            width: 30px;
            height: 30px;
            background: var(--accent);
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 14px;
        }
        
        .topnav-divider {
            width: 1px;
            height: 24px;
            background: var(--border);
        }
        
        .topnav-breadcrumb {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: var(--text-secondary);
            flex-wrap: nowrap;
        }
        
        .topnav-breadcrumb a {
            color: var(--text-secondary);
            text-decoration: none;
        }
        
        .topnav-breadcrumb a:hover {
            color: var(--text-primary);
        }
        
        .topnav-breadcrumb .current {
            color: var(--text-primary);
            font-weight: 500;
        }
        
        .topnav-right {
            margin-left: auto;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-shrink: 0;
        }
        
        .topnav-stat {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: var(--text-secondary);
            background: var(--bg-tertiary);
            padding: 4px 12px;
            border-radius: 99px;
        }
        
        .badge {
            display: inline-flex;
            align-items: center;
            font-size: 11px;
            font-weight: 500;
            padding: 2px 8px;
            border-radius: 99px;
        }
        
        .badge-easy { background: var(--green-bg); color: var(--green); }
        .badge-medium { background: var(--yellow-bg); color: var(--yellow); }
        .badge-hard { background: var(--red-bg); color: var(--red); }
        .badge-lang { background: var(--accent-light); color: var(--accent); }
        .badge-neutral { background: var(--bg-tertiary); color: var(--text-secondary); }

        .dashboard-wrapper {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
        }
        
        .dashboard-hero {
            padding: 32px 40px 24px;
            border-bottom: 1px solid var(--border);
            background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
        }
        
        .dashboard-hero h1 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 6px;
        }
        
        .dashboard-hero p {
            font-size: 14px;
            color: var(--text-secondary);
        }
        
        .dashboard-stats {
            display: flex;
            gap: 16px;
            margin-top: 24px;
            flex-wrap: wrap;
        }
        
        .stat-chip {
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 12px 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .stat-chip .stat-val {
            font-size: 20px;
            font-weight: 700;
            line-height: 1;
        }
        
        .stat-chip .stat-lbl {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 2px;
        }
        
        .dashboard-body {
            padding: 32px 40px;
        }
        
        .section-title {
            font-size: 13px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            margin-bottom: 16px;
        }
        
        .labs-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
        }
        
        .lab-card {
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            background: var(--bg);
            padding: 20px;
            transition: all 0.2s;
            text-decoration: none;
            color: inherit;
            display: block;
        }
        
        .lab-card:hover {
            box-shadow: var(--shadow);
            border-color: var(--accent-border);
            transform: translateY(-2px);
        }
        
        .lab-card-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: 12px;
        }
        
        .lab-card-icon {
            width: 40px;
            height: 40px;
            background: var(--accent-light);
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--accent);
            font-size: 18px;
        }
        
        .lab-card h3 {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 6px;
        }
        
        .lab-card p {
            font-size: 12px;
            color: var(--text-secondary);
            line-height: 1.5;
            margin-bottom: 16px;
        }
        
        .progress-bar {
            height: 4px;
            background: var(--bg-tertiary);
            border-radius: 99px;
            overflow: hidden;
            margin: 12px 0;
        }
        
        .progress-bar-fill {
            height: 100%;
            background: var(--accent);
            border-radius: 99px;
            transition: width 0.3s;
        }
        
        .lab-card-btn {
            display: block;
            width: 100%;
            text-align: center;
            padding: 8px;
            border-radius: var(--radius-sm);
            font-size: 13px;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.15s;
            margin-top: 12px;
        }
        
        .lab-card-btn.primary {
            background: var(--accent);
            color: white;
        }
        
        .lab-card-btn.primary:hover {
            background: var(--accent-hover);
        }
        
        .lab-card-btn.secondary {
            background: var(--bg-tertiary);
            color: var(--text-primary);
        }

        .qlist-wrapper {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            display: flex;
            flex-direction: column;
        }
        
        .qlist-header {
            padding: 24px 32px;
            border-bottom: 1px solid var(--border);
            background: var(--bg);
        }
        
        .qlist-header h2 {
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 6px;
        }
        
        .qlist-header p {
            font-size: 13px;
            color: var(--text-secondary);
        }
        
        .qlist-body {
            padding: 24px 32px;
            flex: 1;
            overflow-y: auto;
        }
        
        .filter-row {
            display: flex;
            gap: 8px;
            padding: 12px 0;
            border-bottom: 1px solid var(--border-light);
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .filter-btn {
            padding: 6px 14px;
            border-radius: 99px;
            font-size: 12px;
            font-weight: 500;
            border: 1px solid var(--border);
            background: var(--bg);
            color: var(--text-secondary);
            cursor: pointer;
            transition: all 0.15s;
        }
        
        .filter-btn:hover,
        .filter-btn.active {
            border-color: var(--accent);
            color: var(--accent);
            background: var(--accent-light);
        }
        
        .problem-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .problem-table th {
            text-align: left;
            padding: 12px 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            color: var(--text-muted);
            border-bottom: 1px solid var(--border);
        }
        
        .problem-table td {
            padding: 12px;
            font-size: 13px;
            border-bottom: 1px solid var(--border-light);
        }
        
        .problem-row:hover {
            background: var(--bg-secondary);
        }
        
        .problem-title-link {
            color: var(--text-primary);
            text-decoration: none;
            font-weight: 500;
        }
        
        .problem-title-link:hover {
            color: var(--accent);
        }
        
        .start-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: var(--radius-sm);
            font-size: 12px;
            font-weight: 500;
            text-decoration: none;
            background: var(--accent);
            color: white;
            transition: all 0.15s;
            border: none;
            cursor: pointer;
        }
        
        .start-btn:hover {
            background: var(--accent-hover);
        }
        
        .start-btn.done {
            background: var(--bg-tertiary);
            color: var(--text-secondary);
        }

        .ide-wrapper {
            display: flex;
            flex: 1;
            min-height: 0;
            overflow: hidden;
            width: 100%;
        }

        .ide-left {
            width: 35%;
            min-width: 300px;
            max-width: 450px;
            display: flex;
            flex-direction: column;
            border-right: 1px solid var(--border);
            overflow: hidden;
            background: var(--bg);
        }

        .ide-right {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .ide-left-tabs {
            display: flex;
            border-bottom: 1px solid var(--border);
            background: var(--bg);
            padding: 0 16px;
            flex-shrink: 0;
        }
        
        .ide-tab {
            padding: 12px 20px;
            font-size: 13px;
            font-weight: 500;
            color: var(--text-secondary);
            border-bottom: 2px solid transparent;
            cursor: pointer;
            transition: all 0.15s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .ide-tab:hover {
            color: var(--text-primary);
        }
        
        .ide-tab.active {
            color: var(--text-primary);
            border-bottom-color: var(--text-primary);
        }
        
        .ide-left-body {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 20px;
        }

        .problem-header h1 {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 12px;
        }
        
        .problem-header-meta {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .problem-desc {
            font-size: 14px;
            line-height: 1.6;
            margin-bottom: 24px;
        }
        
        .problem-section-title {
            font-size: 14px;
            font-weight: 600;
            margin: 20px 0 12px;
        }
        
        .example-block {
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 16px;
            margin-bottom: 16px;
        }
        
        .example-block pre {
            font-family: var(--font-mono);
            font-size: 12px;
            white-space: pre-wrap;
            word-break: break-word;
        }
        
        .constraints-block {
            background: var(--bg-secondary);
            border-left: 3px solid var(--accent);
            border-radius: var(--radius);
            padding: 12px 16px;
            font-size: 13px;
            margin-bottom: 20px;
        }
        
        .test-case-grid {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-bottom: 20px;
        }
        
        .tc-card {
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
        }
        
        .tc-card-header {
            padding: 8px 12px;
            background: var(--bg-secondary);
            border-bottom: 1px solid var(--border);
            font-size: 11px;
            font-weight: 600;
        }
        
        .tc-card-body {
            padding: 12px;
            font-family: var(--font-mono);
            font-size: 12px;
        }
        
        .ai-feedback-box {
            background: linear-gradient(135deg, #eff6ff, #f0fdf4);
            border: 1px solid var(--accent-border);
            border-radius: var(--radius-lg);
            padding: 16px;
            margin-top: 20px;
        }

        .ide-right-toolbar {
            height: 48px;
            display: flex;
            align-items: center;
            padding: 0 16px;
            gap: 12px;
            border-bottom: 1px solid var(--border);
            background: var(--bg);
            flex-shrink: 0;
        }
        
        .lang-select {
            font-family: var(--font-sans);
            font-size: 13px;
            padding: 6px 28px 6px 10px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            background: var(--bg);
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 8px center;
        }
        
        .btn-run, .btn-submit, .btn-reset {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: var(--radius-sm);
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.15s;
            border: 1px solid var(--border);
            background: var(--bg-secondary);
            color: var(--text-primary);
        }
        
        .btn-submit {
            background: var(--green);
            color: white;
            border: none;
        }
        
        .btn-submit:hover {
            background: #15803d;
        }
        
        .btn-run:hover {
            background: var(--bg-tertiary);
        }
        
        .btn-reset {
            padding: 6px 10px;
        }

        .ide-editor-area {
            flex: 1;
            min-height: 0;
            overflow: hidden;
        }
        
        .CodeMirror {
            height: 100% !important;
            font-family: var(--font-mono) !important;
            font-size: 13px !important;
            line-height: 1.6 !important;
        }
        
        .ide-editor-statusbar {
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 12px;
            border-top: 1px solid var(--border);
            background: var(--bg-secondary);
            font-size: 11px;
            color: var(--text-muted);
            flex-shrink: 0;
        }

        .ide-bottom {
            height: 220px;
            border-top: 1px solid var(--border);
            display: flex;
            flex-shrink: 0;
        }
        
        .output-pane, .ai-pane {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        
        .output-pane {
            border-right: 1px solid var(--border);
        }
        
        .ai-header-bar {
            padding: 8px 16px;
            border-bottom: 1px solid var(--border);
            background: var(--bg-secondary);
            display: flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
        }
        
        .bottom-pane-body {
            flex: 1;
            overflow-y: auto;
            padding: 12px 16px;
            font-size: 13px;
            font-family: var(--font-mono);
        }
        
        .output-pre {
            white-space: pre-wrap;
            word-break: break-word;
            margin: 0;
        }
        
        .output-empty {
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .ide-qtabs {
            display: flex;
            overflow-x: auto;
            border-bottom: 1px solid var(--border);
            background: var(--bg);
            padding: 0 12px;
            flex-shrink: 0;
        }
        
        .ide-qtabs::-webkit-scrollbar {
            height: 3px;
        }
        
        .ide-qtab {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 16px;
            font-size: 12px;
            font-weight: 500;
            color: var(--text-secondary);
            border-bottom: 2px solid transparent;
            white-space: nowrap;
            cursor: pointer;
            text-decoration: none;
        }
        
        .ide-qtab:hover {
            color: var(--text-primary);
        }
        
        .ide-qtab.active {
            color: var(--accent);
            border-bottom-color: var(--accent);
        }
        
        .q-num {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: var(--bg-tertiary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 600;
        }
        
        .ide-qtab.active .q-num {
            background: var(--accent);
            color: white;
        }

        .flash {
            position: fixed;
            top: 64px;
            right: 24px;
            z-index: 10000;
            min-width: 280px;
            max-width: 400px;
            padding: 12px 16px;
            border-radius: var(--radius);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            font-weight: 500;
            animation: slideIn 0.25s ease-out;
        }
        
        .flash-success {
            background: var(--green-bg);
            color: var(--green);
            border: 1px solid var(--green-border);
        }
        
        .flash-error {
            background: var(--red-bg);
            color: var(--red);
            border: 1px solid var(--red-border);
        }
        
        .flash-close {
            margin-left: auto;
            cursor: pointer;
            opacity: 0.6;
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

        .loader {
            display: inline-block;
            width: 12px;
            height: 12px;
            border: 2px solid var(--bg-tertiary);
            border-top-color: var(--accent);
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .empty-state {
            text-align: center;
            padding: 48px 24px;
        }
        
        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 16px;
            color: var(--text-muted);
        }
        
        @media (max-width: 768px) {
            .ide-left {
                width: 40%;
                min-width: 260px;
            }
            .dashboard-hero,
            .dashboard-body {
                padding: 20px;
            }
            .qlist-header,
            .qlist-body {
                padding: 16px;
            }
        }
    </style>
</head>
<body>

<nav class="topnav">
    <a href="index.php?page=programming_platform" class="topnav-logo">
        <div class="logo-icon"><i class="fas fa-code"></i></div>
        CodeLabs
    </a>
    <div class="topnav-divider"></div>

    <?php if ($view_mode === 'ide' || $view_mode === 'question_list'): ?>
    <div class="topnav-breadcrumb">
        <a href="index.php?page=programming_platform">Labs</a>
        <i class="fas fa-chevron-right" style="font-size: 10px; color: var(--text-muted);"></i>
        <?php if ($view_mode === 'ide'): ?>
            <a href="index.php?page=programming_platform&lab=<?= $selected_lab['id'] ?>"><?= htmlspecialchars($selected_lab['lab_name']) ?></a>
            <i class="fas fa-chevron-right" style="font-size: 10px; color: var(--text-muted);"></i>
            <span class="current"><?= htmlspecialchars($current_question['title']) ?></span>
        <?php else: ?>
            <span class="current"><?= htmlspecialchars($selected_lab['lab_name']) ?></span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="topnav-right">
        <div class="topnav-stat">
            <i class="fas fa-user-circle"></i>
            <span><?= htmlspecialchars($user['full_name'] ?? 'Student') ?></span>
        </div>
    </div>
</nav>

<?php if ($view_mode === 'dashboard'): ?>
<div class="dashboard-wrapper">
    <div class="dashboard-hero">
        <h1>Programming Labs</h1>
        <p>Choose a lab to start solving coding challenges</p>
        <div class="dashboard-stats">
            <?php
                $completed = count(array_filter($labs, fn($l) => ($l['progress_status']??'') === 'completed'));
                $inprogress = count(array_filter($labs, fn($l) => ($l['progress_status']??'') === 'in_progress'));
            ?>
            <div class="stat-chip">
                <div><div class="stat-val"><?= count($labs) ?></div><div class="stat-lbl">Total Labs</div></div>
            </div>
            <div class="stat-chip">
                <div><div class="stat-val"><?= $completed ?></div><div class="stat-lbl">Completed</div></div>
            </div>
            <div class="stat-chip">
                <div><div class="stat-val"><?= $inprogress ?></div><div class="stat-lbl">In Progress</div></div>
            </div>
        </div>
    </div>

    <div class="dashboard-body">
        <div class="section-title">Available Labs</div>
        <?php if (empty($labs)): ?>
        <div class="empty-state">
            <div class="empty-state-icon"><i class="fas fa-folder-open"></i></div>
            <h3>No Labs Available</h3>
            <p>Please check back later or contact your instructor.</p>
        </div>
        <?php else: ?>
        <div class="labs-grid">
            <?php foreach ($labs as $lab):
                $progress = $lab['progress'] ?? 0;
                $status = $lab['progress_status'] ?? 'not_started';
                $total = $lab['total_questions'] ?? 0;
            ?>
            <a href="index.php?page=programming_platform&lab=<?= $lab['id'] ?>" class="lab-card">
                <div class="lab-card-header">
                    <div class="lab-card-icon"><i class="fas fa-laptop-code"></i></div>
                    <span class="badge <?= $status === 'completed' ? 'badge-easy' : ($status === 'in_progress' ? 'badge-medium' : 'badge-neutral') ?>">
                        <?= ucfirst(str_replace('_', ' ', $status)) ?>
                    </span>
                </div>
                <h3><?= htmlspecialchars($lab['lab_name']) ?></h3>
                <p><?= htmlspecialchars(substr($lab['description'] ?? 'Start solving coding challenges.', 0, 100)) ?></p>
                <div style="font-size: 12px; color: var(--text-muted); margin: 8px 0;">
                    <strong><?= $total ?></strong> problems
                </div>
                <?php if ($status !== 'not_started'): ?>
                <div class="progress-bar">
                    <div class="progress-bar-fill" style="width:<?= $progress ?>%"></div>
                </div>
                <?php endif; ?>
                <div class="lab-card-btn <?= $status === 'completed' ? 'secondary' : 'primary' ?>">
                    <?= $status === 'completed' ? 'Review' : ($status === 'in_progress' ? 'Continue' : 'Start') ?>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($view_mode === 'question_list'): ?>
<div class="qlist-wrapper">
    <div class="qlist-header">
        <h2><?= htmlspecialchars($selected_lab['lab_name']) ?></h2>
        <p><?= htmlspecialchars($selected_lab['description'] ?? 'Complete all problems to master this topic.') ?></p>
        <div style="margin-top: 12px; display: flex; gap: 12px;">
            <span class="badge badge-neutral"><i class="fas fa-list"></i> <?= count($questions) ?> Problems</span>
            <span class="badge badge-easy"><i class="fas fa-check-circle"></i> <?= count(array_filter($answers)) ?> Solved</span>
        </div>
    </div>

    <div class="qlist-body">
        <div class="filter-row">
            <button class="filter-btn active" onclick="filterProblems('all', this)">All</button>
            <button class="filter-btn" onclick="filterProblems('easy', this)">Easy</button>
            <button class="filter-btn" onclick="filterProblems('medium', this)">Medium</button>
            <button class="filter-btn" onclick="filterProblems('hard', this)">Hard</button>
            <button class="filter-btn" onclick="filterProblems('unsolved', this)">Unsolved</button>
        </div>

        <table class="problem-table">
            <thead>
                <tr><th style="width: 40px"></th><th style="width: 50px">#</th><th>Title</th><th>Difficulty</th><th style="width: 100px">Action</th> </tr>
            </thead>
            <tbody>
                <?php foreach ($questions as $i => $q):
                    $solved = isset($answers[$q['id']]);
                ?>
                <tr class="problem-row" data-difficulty="<?= $q['difficulty'] ?>" data-solved="<?= $solved ? 'solved' : 'unsolved' ?>">
                    <td><?= $solved ? '<i class="fas fa-check-circle" style="color: var(--green);"></i>' : '<i class="far fa-circle" style="color: var(--text-muted);"></i>' ?></td>
                    <td style="color: var(--text-muted);"><?= $i + 1 ?></td>
                    <td><a href="index.php?page=programming_platform&lab=<?= $selected_lab['id'] ?>&question=<?= $q['id'] ?>" class="problem-title-link"><?= htmlspecialchars($q['title']) ?></a></td>
                    <td><span class="badge badge-<?= $q['difficulty'] ?>"><?= ucfirst($q['difficulty']) ?></span></td>
                    <td><a href="index.php?page=programming_platform&lab=<?= $selected_lab['id'] ?>&question=<?= $q['id'] ?>" class="start-btn <?= $solved ? 'done' : '' ?>"><?= $solved ? 'Review' : 'Solve' ?></a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function filterProblems(type, btn) {
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('.problem-row').forEach(row => {
        const diff = row.dataset.difficulty;
        const solved = row.dataset.solved;
        if (type === 'all') row.style.display = '';
        else if (type === 'unsolved') row.style.display = solved === 'unsolved' ? '' : 'none';
        else row.style.display = diff === type ? '' : 'none';
    });
}
</script>

<?php elseif ($view_mode === 'ide' && $current_question): ?>
<div class="ide-wrapper">
    <div class="ide-left">
        <div class="ide-left-tabs">
            <span class="ide-tab active" onclick="switchTab('description', this)">
                <i class="fas fa-align-left"></i> Description
            </span>
            <span class="ide-tab" onclick="switchTab('submissions', this)">
                <i class="fas fa-history"></i> Submissions
            </span>
        </div>

        <div id="tab-description" class="ide-left-body">
            <div class="problem-header">
                <h1><?= htmlspecialchars($current_question['title']) ?></h1>
                <div class="problem-header-meta">
                    <span class="badge badge-<?= $current_question['difficulty'] ?>"><?= ucfirst($current_question['difficulty']) ?></span>
                    <span class="badge badge-lang"><i class="fas fa-code"></i> <?= ucfirst($current_question['language'] ?? 'Python') ?></span>
                    <?php if ($current_answer): ?>
                    <span class="badge badge-easy"><i class="fas fa-check-circle"></i> Solved</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="problem-desc"><?= nl2br(htmlspecialchars($current_question['description'])) ?></div>
            
            <?php if ($current_question['examples']): ?>
            <div class="problem-section-title">Examples</div>
            <div class="example-block"><pre><?= htmlspecialchars($current_question['examples']) ?></pre></div>
            <?php endif; ?>
            
            <?php if ($current_question['constraints']): ?>
            <div class="problem-section-title">Constraints</div>
            <div class="constraints-block"><?= nl2br(htmlspecialchars($current_question['constraints'])) ?></div>
            <?php endif; ?>
            
            <?php if ($current_answer && $current_answer['ai_feedback']): ?>
            <div class="ai-feedback-box">
                <strong><i class="fas fa-robot"></i> AI Feedback</strong>
                <div style="margin-top: 8px;"><?= nl2br(htmlspecialchars($current_answer['ai_feedback'])) ?></div>
            </div>
            <?php endif; ?>
        </div>

        <div id="tab-submissions" class="ide-left-body" style="display: none;">
            <?php if ($current_answer): ?>
            <div style="background: var(--bg-secondary); border-radius: var(--radius); padding: 16px; margin-bottom: 16px;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 12px;">
                    <span><strong>Score:</strong> <?= round($current_answer['score']) ?>%</span>
                    <span><strong>Tests:</strong> <?= $current_answer['passed_tests'] ?>/<?= $current_answer['total_tests'] ?></span>
                </div>
                <div style="font-size: 12px; color: var(--text-muted);">Submitted: <?= date('M j, Y g:i A', strtotime($current_answer['submitted_at'])) ?></div>
            </div>
            <div><strong>Your Code:</strong></div>
            <pre style="background: var(--bg-tertiary); padding: 12px; border-radius: var(--radius); overflow-x: auto; font-size: 12px; margin-top: 8px;"><?= htmlspecialchars($current_answer['code']) ?></pre>
            <?php else: ?>
            <div class="empty-state"><div class="empty-state-icon"><i class="fas fa-code-branch"></i></div><h3>No submissions yet</h3><p>Write and submit your solution</p></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="ide-right">
        <div class="ide-qtabs">
            <?php foreach ($questions as $i => $q): ?>
            <a href="index.php?page=programming_platform&lab=<?= $selected_lab['id'] ?>&question=<?= $q['id'] ?>" class="ide-qtab <?= $q['id'] == $current_question['id'] ? 'active' : '' ?>">
                <span class="q-num"><?= $i + 1 ?></span>
                <span><?= htmlspecialchars($q['title']) ?></span>
                <?php if (isset($answers[$q['id']])): ?><i class="fas fa-check" style="color: var(--green); font-size: 10px;"></i><?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>

        <div class="ide-right-toolbar">
            <select id="language-select" class="lang-select">
                <option value="python" <?= ($current_question['language']??'') == 'python' ? 'selected' : '' ?>>Python</option>
                <option value="javascript" <?= ($current_question['language']??'') == 'javascript' ? 'selected' : '' ?>>JavaScript</option>
                <option value="php" <?= ($current_question['language']??'') == 'php' ? 'selected' : '' ?>>PHP</option>
            </select>
            <button class="btn-reset" onclick="resetEditor()" title="Reset"><i class="fas fa-undo-alt"></i></button>
            <button onclick="runCode()" id="run-btn" class="btn-run"><i class="fas fa-play"></i> Run</button>
            <?php if (!$current_answer): ?>
            <button onclick="submitCode()" class="btn-submit"><i class="fas fa-paper-plane"></i> Submit</button>
            <?php else: ?>
            <span class="badge badge-easy"><i class="fas fa-check"></i> Completed</span>
            <?php endif; ?>
        </div>

        <div class="ide-editor-area">
            <textarea id="code-editor"><?= $current_answer ? htmlspecialchars($current_answer['code']) : htmlspecialchars(getStarterCode($current_question['language'] ?? 'python')) ?></textarea>
        </div>

        <div class="ide-editor-statusbar">
            <span id="cursor-pos">Ln 1, Col 1</span>
            <span>Ctrl + Enter to Run</span>
        </div>

        <div class="ide-bottom">
            <div class="output-pane">
                <div class="ai-header-bar"><i class="fas fa-terminal"></i> Output</div>
                <div id="output" class="bottom-pane-body"><span class="output-empty"><i class="fas fa-play"></i> Run your code</span></div>
            </div>
            <div class="ai-pane">
                <div class="ai-header-bar"><i class="fas fa-robot"></i> AI Review</div>
                <div id="ai-review" class="bottom-pane-body"><span class="output-empty"><i class="fas fa-sparkles"></i> Review appears after running</span></div>
            </div>
        </div>
    </div>
</div>

<script>
let editor = CodeMirror.fromTextArea(document.getElementById('code-editor'), {
    lineNumbers: true,
    mode: '<?= $current_question['language'] ?? 'python' ?>',
    autoCloseBrackets: true,
    matchBrackets: true,
    indentUnit: 4,
    tabSize: 4,
    lineWrapping: true
});

editor.on('cursorActivity', function() {
    let pos = editor.getCursor();
    document.getElementById('cursor-pos').textContent = `Ln ${pos.line+1}, Col ${pos.ch+1}`;
});

let currentQuestionId = <?= $current_question['id'] ?? 0 ?>;
let currentLanguage = '<?= $current_question['language'] ?? 'python' ?>';

document.getElementById('language-select').addEventListener('change', function() {
    currentLanguage = this.value;
    let modeMap = { python: 'python', javascript: 'javascript', php: 'php' };
    editor.setOption('mode', modeMap[currentLanguage] || 'python');
});

function resetEditor() {
    if (!confirm('Reset to starter code?')) return;
    let starters = {
        python: '# Write your solution here\n\ndef solution(a, b):\n    return a + b\n',
        javascript: '// Write your solution here\n\nfunction solution(a, b) {\n    return a + b;\n}\n',
        php: '<?php\nfunction solution($a, $b) {\n    return $a + $b;\n}\n'
    };
    editor.setValue(starters[currentLanguage] || '# Write your code here');
}

async function runCode() {
    let code = editor.getValue();
    let outputDiv = document.getElementById('output');
    let aiDiv = document.getElementById('ai-review');
    let runBtn = document.getElementById('run-btn');
    
    runBtn.disabled = true;
    runBtn.innerHTML = '<div class="loader"></div> Running';
    outputDiv.innerHTML = '<span class="output-empty"><div class="loader"></div> Executing...</span>';
    aiDiv.innerHTML = '<span class="output-empty"><div class="loader"></div> Analyzing...</span>';
    
    try {
        let execResp = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ execute_code: '1', code: code, language: currentLanguage })
        });
        let execText = await execResp.text();
console.log(execText); // debug

let execData;
try {
    execData = JSON.parse(execText);
} catch (e) {
    throw new Error("Invalid JSON response from server");
}
        let runOutput = execData.success ? (execData.output || '(no output)') : ('Error: ' + (execData.error || 'Unknown'));
        outputDiv.innerHTML = `<pre class="output-pre">${escapeHtml(runOutput)}</pre>`;
        
        let aiResp = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ ai_review: '1', code: code, language: currentLanguage, question_id: currentQuestionId, run_output: runOutput })
        });
        let aiText = await aiResp.text();

let aiData;
try {
    aiData = JSON.parse(aiText);
} catch (e) {
    throw new Error("Invalid JSON response from server");
}
        if (aiData.success) {
            aiDiv.innerHTML = `<div style="white-space: pre-wrap;">${escapeHtml(aiData.review).replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>').replace(/\n/g, '<br>')}</div>`;
        } else {
            aiDiv.innerHTML = `<span style="color: var(--red);">Error: ${escapeHtml(aiData.error)}</span>`;
        }
    } catch (err) {
        console.error('Error:', err);
        outputDiv.innerHTML = `<span style="color: var(--red);">Network error: ${err.message}</span>`;
        aiDiv.innerHTML = `<span style="color: var(--red);">Failed to get review</span>`;
    } finally {
        runBtn.disabled = false;
        runBtn.innerHTML = '<i class="fas fa-play"></i> Run';
    }
}

function submitCode() {
    if (!confirm('Submit your solution?')) return;
    let form = document.createElement('form');
    form.method = 'POST';
    form.action = window.location.href;
    let fields = {
        submit_programming_answer: '1',
        question_id: currentQuestionId,
        lab_id: '<?= $_GET['lab'] ?? '' ?>',
        code: editor.getValue(),
        language: currentLanguage
    };
    for (let key in fields) {
        let input = document.createElement('input');
        input.type = 'hidden';
        input.name = key;
        input.value = fields[key];
        form.appendChild(input);
    }
    document.body.appendChild(form);
    form.submit();
}

function switchTab(tab, el) {
    document.querySelectorAll('.ide-tab').forEach(t => t.classList.remove('active'));
    el.classList.add('active');
    document.getElementById('tab-description').style.display = tab === 'description' ? 'block' : 'none';
    document.getElementById('tab-submissions').style.display = tab === 'submissions' ? 'block' : 'none';
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        e.preventDefault();
        runCode();
    }
});

setTimeout(() => {
    let flash = document.querySelector('.flash');
    if (flash) flash.remove();
}, 5000);
</script>
<?php endif; ?>

<?php if ($success_message): ?>
<div class="flash flash-success"><i class="fas fa-check-circle"></i><span><?= htmlspecialchars($success_message) ?></span><span class="flash-close" onclick="this.parentElement.remove()">×</span></div>
<?php endif; ?>
<?php if ($error_message): ?>
<div class="flash flash-error"><i class="fas fa-exclamation-circle"></i><span><?= htmlspecialchars($error_message) ?></span><span class="flash-close" onclick="this.parentElement.remove()">×</span></div>
<?php endif; ?>

</body>
</html>