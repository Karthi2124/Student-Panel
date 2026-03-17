<?php
// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// DB connection
require_once __DIR__ . '/../../config/database.php';

if (!isset($pdo)) {
    die("Database connection failed.");
}

try {
    // =============================
    // TASK COUNTS
    // =============================
    $totalTasks = $pdo->query("SELECT COUNT(*) FROM tasks")->fetchColumn();

    $pendingTasks = $pdo->query("
        SELECT COUNT(*) FROM tasks 
        WHERE status IN ('pending','assigned','in_progress')
    ")->fetchColumn();

    $completedTasks = $pdo->query("
        SELECT COUNT(*) FROM tasks 
        WHERE status = 'completed'
    ")->fetchColumn();

    // =============================
    // LABS
    // =============================
    $labs = $pdo->query("
        SELECT lab_name, lab_type, department, capacity 
        FROM labs 
        ORDER BY id DESC 
        LIMIT 6
    ")->fetchAll(PDO::FETCH_ASSOC);

    // =============================
    // RECENT TASKS
    // =============================
    $recentTasks = $pdo->query("
        SELECT 
            title,
            status,
            department,
            deadline,
            created_at
        FROM tasks
        ORDER BY id DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}
?>

<!-- FULL WIDTH -->
<div class="w-full px-4 md:px-6 lg:px-8 py-6">

    <!-- =============================
            STATS CARDS
    ================================= -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8 w-full">

    <?php
    $stats = [
        ['Total Tasks', $totalTasks, 'fa-list-check', 'from-amber-500 to-amber-600'],
        ['Pending Tasks', $pendingTasks, 'fa-hourglass-half', 'from-yellow-500 to-yellow-600'],
        ['Completed Tasks', $completedTasks, 'fa-circle-check', 'from-green-500 to-green-600']
    ];

    foreach ($stats as $s): ?>
        <div class="bg-white rounded-2xl border border-gray-100 p-6 shadow-sm hover:shadow-md transition w-full">
            <div class="flex items-center justify-between mb-4">
                <div class="w-14 h-14 rounded-2xl bg-gradient-to-br <?= $s[3] ?> flex items-center justify-center text-white shadow-lg">
                    <i class="fa-solid <?= $s[2] ?> text-xl"></i>
                </div>
            </div>
            <p class="text-sm text-gray-500"><?= htmlspecialchars($s[0]) ?></p>
            <h3 class="text-3xl font-bold text-gray-800 mt-1"><?= $s[1] ?></h3>
        </div>
    <?php endforeach; ?>

    </div>

    <!-- =============================
            MAIN CONTENT
    ================================= -->
    <div class="w-full space-y-6">

        <!-- =============================
                LABS
        ================================= -->
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden w-full">
            <div class="p-6 border-b border-gray-100">
                <h2 class="text-lg font-bold text-gray-800">CSE Labs Overview</h2>
                <p class="text-sm text-gray-500 mt-1">Computer Science Department Labs</p>
            </div>

            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 w-full">

                    <?php if (!empty($labs)): ?>
                        <?php foreach ($labs as $lab): ?>
                            <div class="p-5 rounded-xl bg-gray-50 border border-gray-200 hover:shadow-md transition w-full">
                                <div class="flex justify-between items-start mb-3">
                                    <div class="w-10 h-10 bg-blue-600 rounded-xl flex items-center justify-center text-white">
                                        <i class="fa-solid fa-flask"></i>
                                    </div>
                                    <span class="px-3 py-1 bg-blue-100 text-blue-700 rounded-full text-[10px] font-bold">
                                        <?= htmlspecialchars($lab['lab_type']) ?>
                                    </span>
                                </div>

                                <h3 class="font-bold text-gray-800">
                                    <?= htmlspecialchars($lab['lab_name']) ?>
                                </h3>

                                <p class="text-xs text-gray-500">
                                    <?= htmlspecialchars($lab['department']) ?> • Capacity: <?= $lab['capacity'] ?>
                                </p>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-gray-500">No labs found.</p>
                    <?php endif; ?>

                </div>
            </div>
        </div>

        <!-- =============================
                RECENT TASKS
        ================================= -->
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 w-full">
            <h3 class="text-lg font-bold text-gray-800 mb-4">Recent Task Updates</h3>

            <div class="space-y-4 w-full">

                <?php if (!empty($recentTasks)): ?>
                    <?php foreach ($recentTasks as $task): ?>
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between p-4 bg-gray-50 rounded-xl hover:bg-gray-100 transition gap-3 w-full">
                            
                            <div class="flex-1">
                                <p class="font-medium text-gray-800">
                                    <?= htmlspecialchars($task['title']) ?>
                                </p>

                                <p class="text-xs text-gray-500 mt-1">
                                    <i class="fa-regular fa-building mr-1"></i>
                                    <?= htmlspecialchars($task['department'] ?? 'No Department') ?>
                                </p>
                            </div>

                            <div class="flex items-center gap-3">
                                <?php
                                $statusColor = 'bg-blue-100 text-blue-700';

                                if ($task['status'] == 'completed') {
                                    $statusColor = 'bg-green-100 text-green-700';
                                } elseif (in_array($task['status'], ['pending','assigned','in_progress'])) {
                                    $statusColor = 'bg-yellow-100 text-yellow-700';
                                }
                                ?>
                                <span class="text-xs px-3 py-1 <?= $statusColor ?> rounded-full capitalize">
                                    <?= str_replace('_', ' ', $task['status']) ?>
                                </span>

                                <span class="text-xs text-gray-400">
                                    <?= $task['deadline'] 
                                        ? date('d M Y', strtotime($task['deadline'])) 
                                        : date('d M Y', strtotime($task['created_at'])) ?>
                                </span>
                            </div>

                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-gray-500 text-sm">No recent tasks found.</p>
                <?php endif; ?>

            </div>
        </div>

    </div>

</div>

<style>
body {
    width: 100%;
    overflow-x: hidden;
    margin: 0;
    padding: 0;
    background-color: #f9fafb;
}
.w-full {
    width: 100% !important;
}
</style>