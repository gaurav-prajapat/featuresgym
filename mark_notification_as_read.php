<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id']) || !isset($_POST['notification_id'])) {
    exit(json_encode(['success' => false, 'error' => 'Invalid request']));
}

$notification_id = $_POST['notification_id'];

$db = new GymDatabase();
$conn = $db->getConnection();

// Mark the notification as read
$stmt = $conn->prepare("UPDATE notifications SET status = 'read' WHERE notification_id = ? AND user_id = ?");
$stmt->execute([$notification_id, $_SESSION['user_id']]);

echo json_encode(['success' => true]);
?>
