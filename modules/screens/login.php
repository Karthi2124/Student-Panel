<?php
session_start();
require_once '../../config/database.php';

$error = '';

if(isset($_POST['login'])) {

    $email = $_POST['email'];
    $password = $_POST['password'];

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND role = 'student'");
    $stmt->execute([$email]);
    $student = $stmt->fetch();

    if($student && password_verify($password, $student['password'])) {
        $_SESSION['student_id'] = $student['id'];
        $_SESSION['student_name'] = $student['full_name'];
        header("Location: ../../index.php");
        exit;
    } else {
        $error = "Invalid login credentials!";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Student Login</title>
<script src="https://cdn.tailwindcss.com"></script>

<style>
body {
    background: #0f172a;
}
</style>
</head>

<body class="flex items-center justify-center h-screen">

<div class="bg-white p-8 rounded-2xl shadow-2xl w-96">

    <h2 class="text-2xl font-bold text-center mb-2 text-gray-800">
        Student Portal
    </h2>

    <p class="text-center text-gray-500 mb-6">
        Sign in to continue
    </p>

    <?php if($error): ?>
        <div class="bg-red-100 text-red-600 p-2 mb-4 rounded">
            <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <form method="POST">

        <div class="mb-4">
            <label class="text-sm text-gray-600">Email Address</label>
            <input type="email" name="email" required
                class="w-full p-3 mt-1 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
        </div>

        <div class="mb-6">
            <label class="text-sm text-gray-600">Password</label>
            <input type="password" name="password" required
                class="w-full p-3 mt-1 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
        </div>

        <button type="submit" name="login"
            class="w-full bg-blue-600 text-white p-3 rounded-lg font-semibold hover:bg-blue-700 transition">
            Sign In
        </button>

    </form>

    <div class="text-center mt-4 text-sm text-gray-400">
        Forgot Password?
    </div>

</div>

</body>
</html>