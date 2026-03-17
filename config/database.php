<?php

$host = "localhost";
$dbname = "hod_panel";
$user = "root";
$password = "";

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, // Show errors
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, // Fetch as associative array
            PDO::ATTR_EMULATE_PREPARES => false, // Use real prepared statements
        ]
    );

} catch (PDOException $e) {
    // ❌ Don't expose full error in production
    die("Database connection failed.");
}
?>