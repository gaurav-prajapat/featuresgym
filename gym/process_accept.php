<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a gym owner
if (!isset($_SESSION['owner_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Check if schedule_id is provided
if (!isset($_POST['schedule_id'])) {
    echo json_encode(['success' => false, 'message' => 'Missing schedule ID']);
    exit;
}

$schedule_id = intval($_POST['schedule_id']);
$owner_id = $_SESSION['owner_id'];

try {
    $db = new GymDatabase();
    $conn = $db->getConnection();
    
    // Verify the schedule belongs to a gym owned by this owner
    $stmt = $conn->prepare("
        SELECT s.id, s.gym_id, s.user_id, s.status, g.name as gym_name
        FROM schedules s
        JOIN gyms g ON s.gym_id = g.gym_id
        WHERE s.id = ? AND g.owner_id = ?
    ");
    $stmt->execute([$schedule_id, $owner_id]);
    $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$schedule) {
        echo json_encode(['success' => false, 'message' => 'Schedule not found or not authorized']);
        exit;
    }
    
    if ($schedule['status'] !== 'scheduled') {
        echo json_encode(['success' => false, 'message' => 'This booking cannot be accepted (current status: ' . $schedule['status'] . ')']);
        exit;
    }
    
    // Update the schedule status to "accepted"
    $updateStmt = $conn->prepare("
        UPDATE schedules 
        SET status = 'accepted'
        WHERE id = ?
    ");
    $updateStmt->execute([$schedule_id]);
    
    // Log the action
    $logStmt = $conn->prepare("
        INSERT INTO schedule_logs (user_id, schedule_id, action_type, notes)
        VALUES (?, ?, 'update', ?)
    ");
    $logStmt->execute([$owner_id, $schedule_id, "Booking accepted by gym owner"]);
    
    // Create a notification for the user
    $notifyStmt = $conn->prepare("
        INSERT INTO notifications (user_id, type, message, related_id, title, gym_id)
        VALUES (?, 'booking_accepted', ?, ?, ?, ?)
    ");
    $notifyStmt->execute([
        $schedule['user_id'],
        "Your booking at " . $schedule['gym_name'] . " has been accepted.",
        $schedule_id,
        "Booking Accepted",
        $schedule['gym_id']
    ]);
    
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}