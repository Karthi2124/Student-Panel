<?php
session_start();
require_once __DIR__ . '/../../config/database.php';

$assignment_id = $_POST['assignment_id'];
$link = $_POST['submission_link'];

$stmt = $pdo->prepare("
    UPDATE task_assignments 
    SET submission_link = ?, status = 'completed', submitted_at = NOW()
    WHERE id = ?
");

$stmt->execute([$link, $assignment_id]);

header("Location: index.php?page=tasks");
exit;