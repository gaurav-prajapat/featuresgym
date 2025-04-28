<?php
session_start();
require_once '../config/database.php';

try {
    $timezoneStmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'default_timezone'");
    $timezoneStmt->execute();
    $timezone = $timezoneStmt->fetchColumn() ?: 'Asia/Kolkata'; // Default to Asia/Kolkata if not found
    
    // Set the timezone
    date_default_timezone_set($timezone);
} catch (PDOException $e) {
    // If there's an error, default to Asia/Kolkata
    date_default_timezone_set('Asia/Kolkata');
    error_log("Error fetching timezone setting: " . $e->getMessage());
}

// Check if user is logged in and is a gym owner
if (!isset($_SESSION['owner_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

$owner_id = $_SESSION['owner_id'];
$db = new GymDatabase();
$conn = $db->getConnection();

// Get schedule ID from POST data
if (!isset($_POST['schedule_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Schedule ID is required']);
    exit;
}

$schedule_id = intval($_POST['schedule_id']);

// Verify that the schedule belongs to a gym owned by this owner
$verifyStmt = $conn->prepare("
    SELECT s.id, s.user_id, s.gym_id, s.activity_type, s.start_date, s.start_time, s.status,
           g.name as gym_name, u.username, u.email
    FROM schedules s
    JOIN gyms g ON s.gym_id = g.gym_id
    JOIN users u ON s.user_id = u.id
    WHERE s.id = ? AND g.owner_id = ?
");
$verifyStmt->execute([$schedule_id, $owner_id]);
$schedule = $verifyStmt->fetch(PDO::FETCH_ASSOC);

if (!$schedule) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Schedule not found or access denied']);
    exit;
}

// Check if the schedule is in a valid state for checkout
if ($schedule['status'] !== 'accepted') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Only scheduled bookings can be checked out']);
    exit;
}

try {
    $conn->beginTransaction();
    
    // Update schedule with check-out time and set status to completed
    $updateStmt = $conn->prepare("
        UPDATE schedules 
        SET check_out_time = NOW(), 
            status = 'completed'
        WHERE id = ?
    ");
    $updateStmt->execute([$schedule_id]);
    
    // Log the action
    $logStmt = $conn->prepare("
        INSERT INTO schedule_logs (user_id, schedule_id, action_type, notes)
        VALUES (?, ?, 'complete', ?)
    ");
    
    $logStmt->execute([
        $owner_id,
        $schedule_id,
        'Member checked out by gym staff'
    ]);
    
    // Create notification for user
    $notifyStmt = $conn->prepare("
        INSERT INTO notifications (user_id, type, title, message, related_id, gym_id, created_at)
        VALUES (?, 'booking', ?, ?, ?, ?, NOW())
    ");
    
    $title = "Check-out from {$schedule['gym_name']}";
    $message = "You have been checked out from your " . ucfirst(str_replace('_', ' ', $schedule['activity_type'])) . 
               " on " . date('F j, Y', strtotime($schedule['start_date'])) . ". Thank you for visiting!";
    
    $notifyStmt->execute([
        $schedule['user_id'],
        $title,
        $message,
        $schedule_id,
        $schedule['gym_id']
    ]);
    
    $conn->commit();
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
} catch (Exception $e) {
    $conn->rollBack();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    exit;
}