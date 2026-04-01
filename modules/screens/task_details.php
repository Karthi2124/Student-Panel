<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../config/database.php';

$task_id = $_GET['task_id'] ?? 0;
$assignment_id = $_GET['assignment_id'] ?? 0;

$stmt = $pdo->prepare("
    SELECT t.*, ta.status 
    FROM tasks t
    JOIN task_assignments ta ON ta.task_id = t.id
    WHERE t.id = ? AND ta.id = ?
");
$stmt->execute([$task_id, $assignment_id]);
$task = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$task) {
    echo "Task not found";
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Task Details</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100">

<div class="max-w-4xl mx-auto py-10">

    <div class="bg-white p-6 rounded-xl shadow">

        <h1 class="text-2xl font-bold mb-4">
            <?= htmlspecialchars($task['title']) ?>
        </h1>

        <p class="mb-4 text-gray-600">
            <?= nl2br(htmlspecialchars($task['description'])) ?>
        </p>

        <div class="mb-4">
            <strong>📅 Deadline:</strong>
            <?= date('F j, Y', strtotime($task['deadline'])) ?>
        </div>

        <div class="mb-4">
            <strong>📘 Instructions:</strong><br>
            <?= nl2br(htmlspecialchars($task['instructions'] ?? 'No instructions')) ?>
        </div>

        <form action="submit_task.php" method="POST" class="space-y-4 mt-6">

            <input type="hidden" name="assignment_id" value="<?= $assignment_id ?>">

            <div>
                <label class="block font-medium">🔗 Deployment Link</label>
                <input type="text" name="submission_link"
                       class="w-full border p-2 rounded"
                       placeholder="https://your-project-link.com" required>
            </div>

            <button type="submit"
                    class="px-6 py-2 bg-blue-600 text-white rounded">
                Submit Task
            </button>

        </form>

    </div>

</div>

</body>
</html>