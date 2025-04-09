<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit(json_encode(['success' => false]));
}

$db = new GymDatabase();
$conn = $db->getConnection();

$schedule_id = $_POST['schedule_id'];
$new_time = $_POST['new_time'];
$new_type = $_POST['new_type'];

try {
    $stmt = $conn->prepare("
        UPDATE schedules 
        SET start_time = ?, activity_type = ?
        WHERE id = ? AND user_id = ?
    ");
    
    $success = $stmt->execute([
        $new_time,
        $new_type,
        $schedule_id,
        $_SESSION['user_id']
    ]);
    
    echo json_encode(['success' => $success]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
