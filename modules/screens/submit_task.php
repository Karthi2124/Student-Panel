<?php
session_start();
require_once '../../config/database.php';

if(!isset($_SESSION['student_id'])) {
    header("Location: login.php");
    exit;
}

$task_id = $_GET['id'] ?? 0;
$error = '';
$success = '';

// Fetch task details
$query = "SELECT * FROM tasks WHERE id = $task_id";
$result = mysqli_query($conn, $query);
$task = mysqli_fetch_assoc($result);

if(!$task) {
    header("Location: tasks.php");
    exit;
}

if(isset($_POST['submit_task'])) {
    $submission_text = mysqli_real_escape_string($conn, $_POST['submission_text']);
    $student_id = $_SESSION['student_id'];
    
    // Handle file upload
    $file_name = '';
    if(isset($_FILES['submission_file']) && $_FILES['submission_file']['error'] == 0) {
        $target_dir = "../../uploads/";
        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        $file_name = time() . '_' . basename($_FILES['submission_file']['name']);
        $target_file = $target_dir . $file_name;
        
        if(move_uploaded_file($_FILES['submission_file']['tmp_name'], $target_file)) {
            // File uploaded successfully
        } else {
            $error = "Error uploading file.";
        }
    }
    
    if(empty($error)) {
        $insert_query = "INSERT INTO submissions (task_id, student_id, submission_text, file_name, submitted_at) 
                         VALUES ($task_id, $student_id, '$submission_text', '$file_name', NOW())";
        
        if(mysqli_query($conn, $insert_query)) {
            $success = "Task submitted successfully!";
        } else {
            $error = "Error submitting task: " . mysqli_error($conn);
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Submit Task</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .gradient-bg {
            background: linear-gradient(135deg, #1e2634 0%, #427a9f 100%);
        }
        .btn-submit {
            background: linear-gradient(135deg, #2665ec 0%, #9c2dd1 100%);
            transition: all 0.3s ease;
        }
        .btn-submit:hover {
            background: linear-gradient(135deg, #9c2dd1 0%, #2665ec 100%);
        }
    </style>
</head>

<body class="bg-[#f3f7f8]">
    <div class="container mx-auto px-4 py-8">
        <div class="max-w-2xl mx-auto">
            <!-- Header -->
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-2xl font-bold text-[#1e2634]">Submit Task</h1>
                <a href="tasks.php" class="text-[#2665ec] hover:text-[#9c2dd1] transition-colors">
                    ← Back to Tasks
                </a>
            </div>

            <!-- Task Info Card -->
            <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
                <h2 class="text-xl font-semibold text-[#1e2634] mb-2"><?php echo $task['title']; ?></h2>
                <p class="text-[#427a9f] mb-4"><?php echo $task['description']; ?></p>
                <div class="flex items-center text-sm text-[#427a9f]">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                    </svg>
                    Deadline: <?php echo $task['deadline']; ?>
                </div>
            </div>

            <!-- Submission Form -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <?php if($error): ?>
                <div class="bg-red-100 border-l-4 border-[#fc343d] text-[#fc343d] p-3 mb-4 rounded">
                    <?php echo $error; ?>
                </div>
                <?php endif; ?>

                <?php if($success): ?>
                <div class="bg-green-100 border-l-4 border-[#40d757] text-[#40d757] p-3 mb-4 rounded">
                    <?php echo $success; ?>
                </div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data">
                    <div class="mb-4">
                        <label class="block text-[#1e2634] font-medium mb-2">Submission Text</label>
                        <textarea name="submission_text" rows="6" 
                                  class="w-full p-3 border rounded-lg focus:outline-none focus:ring-2 focus:ring-[#2665ec] focus:border-transparent"
                                  placeholder="Write your submission here..." required></textarea>
                    </div>

                    <div class="mb-6">
                        <label class="block text-[#1e2634] font-medium mb-2">Upload File (Optional)</label>
                        <input type="file" name="submission_file" 
                               class="w-full p-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-[#2665ec]">
                        <p class="text-sm text-[#427a9f] mt-1">Allowed formats: PDF, DOC, DOCX, ZIP (Max: 10MB)</p>
                    </div>

                    <button type="submit" name="submit_task"
                            class="btn-submit w-full text-white p-3 rounded-lg font-semibold">
                        Submit Task
                    </button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>