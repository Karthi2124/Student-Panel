<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/database.php';

// ✅ Get logged user
$user_id = $_SESSION['user_id'] ?? $_SESSION['student_id'] ?? 0;

// ✅ Filter (All / Pending / Completed)
$filter = $_GET['status'] ?? 'all';

$tasks = [];

if ($user_id) {

    // ✅ Get student details
    $stmtUser = $pdo->prepare("SELECT id, year, department FROM users WHERE id = ?");
    $stmtUser->execute([$user_id]);
    $user = $stmtUser->fetch();

    if ($user) {

        $query = "
            SELECT 
                t.id,
                t.title,
                t.description,
                t.deadline,
                ta.status
            FROM task_assignments ta
            JOIN tasks t ON t.id = ta.task_id
            WHERE ta.student_id = ?
            AND (t.year = ? OR t.year IS NULL)
        ";

        $params = [$user_id, $user['year']];

        // ✅ Apply filter
        if ($filter !== 'all') {
            $query .= " AND ta.status = ?";
            $params[] = $filter;
        }

        $query .= " ORDER BY t.deadline ASC";

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
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

    <!-- HEADER -->
    <div class="flex justify-between items-center mb-8">
        <h1 class="text-3xl font-bold text-gray-800">My Tasks</h1>

        <!-- FILTER -->
        <div class="flex gap-2">
            <a href="?status=all"
               class="px-4 py-2 rounded-lg border <?= $filter=='all'?'bg-blue-600 text-white':'bg-white' ?>">
               All
            </a>

            <a href="?status=pending"
               class="px-4 py-2 rounded-lg border <?= $filter=='pending'?'bg-yellow-500 text-white':'bg-white' ?>">
               Pending
            </a>

            <a href="?status=completed"
               class="px-4 py-2 rounded-lg border <?= $filter=='completed'?'bg-green-600 text-white':'bg-white' ?>">
               Completed
            </a>
        </div>
    </div>

    <!-- TASK GRID -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

        <?php if (!empty($tasks)): ?>
            <?php foreach ($tasks as $task): ?>

            <div class="bg-white rounded-xl shadow-sm border p-6 hover:shadow-md transition">

                <!-- TITLE -->
                <h2 class="text-lg font-semibold text-gray-800 mb-2">
                    <?= htmlspecialchars($task['title']) ?>
                </h2>

                <!-- DESCRIPTION -->
                <p class="text-gray-600 text-sm mb-4">
                    <?= htmlspecialchars($task['description']) ?>
                </p>

                <!-- DEADLINE -->
                <div class="text-sm text-gray-500 mb-4">
                    📅 Deadline: <?= $task['deadline'] ?>
                </div>

                <!-- STATUS + BUTTON -->
                <div class="flex justify-between items-center">

                    <!-- STATUS -->
                    <span class="px-3 py-1 rounded-full text-xs font-semibold
                        <?= $task['status']=='pending'
                            ? 'bg-yellow-100 text-yellow-700'
                            : 'bg-green-100 text-green-700' ?>">
                        <?= ucfirst($task['status']) ?>
                    </span>

                    <!-- ACTION -->
                    <?php if ($task['status'] == 'pending'): ?>
                        <a href="submit_task.php?id=<?= $task['id'] ?>"
                           class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700">
                           Submit
                        </a>
                    <?php else: ?>
                        <span class="text-green-600 text-sm font-medium">
                            ✔ Completed
                        </span>
                    <?php endif; ?>

                </div>

            </div>

            <?php endforeach; ?>

        <?php else: ?>

            <!-- EMPTY -->
            <div class="col-span-2 text-center py-16 text-gray-500">
                No tasks available
            </div>

        <?php endif; ?>

    </div>

    <!-- STATS -->
    <div class="mt-10 bg-white rounded-xl shadow-sm border p-6">

        <h3 class="text-lg font-semibold mb-4">Task Statistics</h3>

        <div class="grid grid-cols-3 text-center">

            <div>
                <div class="text-2xl font-bold text-blue-600">
                    <?= count($tasks) ?>
                </div>
                <div class="text-sm text-gray-500">Total</div>
            </div>

            <div>
                <div class="text-2xl font-bold text-yellow-500">
                    <?= count(array_filter($tasks, fn($t)=>$t['status']=='pending')) ?>
                </div>
                <div class="text-sm text-gray-500">Pending</div>
            </div>

            <div>
                <div class="text-2xl font-bold text-green-600">
                    <?= count(array_filter($tasks, fn($t)=>$t['status']=='completed')) ?>
                </div>
                <div class="text-sm text-gray-500">Completed</div>
            </div>

        </div>

    </div>

</div>

</body>
</html>