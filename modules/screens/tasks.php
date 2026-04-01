<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/database.php';

// ✅ STEP 1: Check login (student)
$user_id = $_SESSION['student_id'] ?? 0;

if (!$user_id) {
    $user = null;
    $tasks = [];
} else {

    // ✅ STEP 2: Get user details (from users table)
    $stmtUser = $pdo->prepare("
        SELECT id, full_name AS name, department, year, email 
        FROM users 
        WHERE id = ?
    ");
    $stmtUser->execute([$user_id]);
    $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

    // ✅ STEP 3: Get student table ID
    $stmtStudent = $pdo->prepare("SELECT id FROM students WHERE user_id = ?");
    $stmtStudent->execute([$user_id]);
    $student = $stmtStudent->fetch(PDO::FETCH_ASSOC);

    $student_id = $student['id'] ?? 0;

    // ✅ STEP 4: Get tasks
    $tasks = [];

    if ($student_id) {
        $query = "
            SELECT 
                t.id,
                t.title,
                t.description,
                t.deadline,
                t.year,
                t.department,
                ta.id AS assignment_id,
                ta.status,
                ta.assigned_at,
                ta.marks,
                ta.submission_file
            FROM task_assignments ta
            INNER JOIN tasks t ON t.id = ta.task_id
            WHERE ta.student_id = ?
            ORDER BY t.deadline ASC
        ";

        $stmt = $pdo->prepare($query);
        $stmt->execute([$student_id]);
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>My Tasks</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-[#f6f9fc]">

<div class="max-w-6xl mx-auto py-10 px-4">

    <h1 class="text-3xl font-bold mb-4">My Tasks</h1>

    <?php if ($user): ?>
        <p class="mb-6 text-gray-600">
            Welcome, <?= htmlspecialchars($user['name']) ?> |
            Year: <?= htmlspecialchars($user['year'] ?? 'Not set') ?> |
            Department: <?= htmlspecialchars($user['department']) ?>
        </p>
    <?php endif; ?>

    <!-- TASK LIST -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

        <?php if (!empty($tasks)): ?>

            <?php foreach ($tasks as $task): ?>
                <div class="bg-white p-6 rounded-xl shadow">

                    <h2 class="text-lg font-semibold mb-2">
                        <?= htmlspecialchars($task['title']) ?>
                    </h2>

                    <p class="text-sm text-gray-600 mb-3">
                        <?= htmlspecialchars(substr($task['description'], 0, 120)) ?>
                    </p>

                    <p class="text-sm mb-2">
                        📅 Deadline: <?= date('M d, Y', strtotime($task['deadline'])) ?>
                    </p>

                    <p class="text-sm mb-4">
                        Status:
                        <span class="font-semibold text-blue-600">
                            <?= ucfirst($task['status'] ?? 'pending') ?>
                        </span>
                    </p>

                    <?php if (($task['status'] ?? 'pending') == 'pending'): ?>
<a href="index.php?page=task_details&task_id=<?= $task['id'] ?>&assignment_id=<?= $task['assignment_id'] ?>"
   class="px-4 py-2 bg-green-600 text-white rounded">
   Start Task
</a>
                    <?php endif; ?>

                </div>
            <?php endforeach; ?>

        <?php else: ?>

            <div class="col-span-2 text-center py-16">
                <h3 class="text-xl font-semibold mb-2">No tasks available</h3>
                <p class="text-gray-400">You don't have any assigned tasks.</p>

                <?php if (!$user): ?>
                    <p class="text-red-500 mt-4">⚠️ Please log in</p>
                <?php endif; ?>
            </div>

        <?php endif; ?>

    </div>

</div>

</body>
</html>