<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and notification ID is provided
if ((!isset($_SESSION['owner_id']) && !isset($_SESSION['user_id'])) || !isset($_POST['notification_id'])) {
    exit(json_encode(['success' => false, 'error' => 'Invalid request']));
}

$notification_id = $_POST['notification_id'];
$user_id = $_SESSION['user_id'] ?? null;
$owner_id = $_SESSION['owner_id'] ?? null;

$db = new GymDatabase();
$conn = $db->getConnection();

// Determine which type of user is making the request
if ($owner_id) {
    // For gym owners, get the gym_id first
    $stmt = $conn->prepare("SELECT gym_id FROM gyms WHERE owner_id = ?");
    $stmt->execute([$owner_id]);
    $gym = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$gym) {
        exit(json_encode(['success' => false, 'error' => 'Gym not found']));
    }
    
    $gym_id = $gym['gym_id'];
    
    // Mark the notification as read
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND gym_id = ?");
    $result = $stmt->execute([$notification_id, $gym_id]);
    
    // Log the activity
    if ($result) {
        $stmt = $conn->prepare("
            INSERT INTO activity_logs 
            (user_id, user_type, action, details, ip_address) 
            VALUES (?, 'owner', 'notification_read', 'Notification #" . $notification_id . " marked as read', ?)
        ");
        $stmt->execute([$owner_id, $_SERVER['REMOTE_ADDR']]);
    }
} else if ($user_id) {
    // For regular users
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $result = $stmt->execute([$notification_id, $user_id]);
    
    // Log the activity
    if ($result) {
        $stmt = $conn->prepare("
            INSERT INTO activity_logs 
            (user_id, user_type, action, details, ip_address) 
            VALUES (?, 'member', 'notification_read', 'Notification #" . $notification_id . " marked as read', ?)
        ");
        $stmt->execute([$user_id, $_SERVER['REMOTE_ADDR']]);
    }
} else {
    exit(json_encode(['success' => false, 'error' => 'Unauthorized access']));
}

// Check if the update was successful
if ($stmt->rowCount() > 0) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Notification not found or already read']);
}
