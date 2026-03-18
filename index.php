<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

if (!isset($_SESSION['student_id']) && !isset($_SESSION['user_id'])) {
    header("Location: modules/screens/login.php");
    exit;
}

// Use either student_id or user_id based on your session structure
$userId = $_SESSION['user_id'] ?? $_SESSION['student_id'] ?? null;
if (!$userId) {
    header("Location: modules/screens/login.php");
    exit;
}

$page = $_GET['page'] ?? 'dashboard';
?>

<!DOCTYPE html>
<html>
<head>
    <title>Student Panel</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-[#f3f7f8]">

<?php include __DIR__ . "/includes/sidebar.php"; ?>

<?php
switch ($page) {
    case 'dashboard':
        include __DIR__ . "/modules/screens/dashboard.php";
        break;
    case 'tasks':
        include __DIR__ . "/modules/screens/tasks.php";
        break;
    case 'lab_assessment':
        include __DIR__ . "/modules/screens/lab_assessment.php";
        break;
    case 'programming_platform':
        include __DIR__ . "/modules/screens/programming_platform.php";
        break;
    default:
        echo "<h2 class='text-red-500 p-6'>Page not found</h2>";
        break;
}
?>

</body>
</html>