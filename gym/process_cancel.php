<?php
session_start();
require_once '../config/database.php';

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

// Check if the schedule is in a valid state for cancellation
if ($schedule['status'] !== 'scheduled') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Only scheduled bookings can be cancelled']);
    exit;
}

try {
    $conn->beginTransaction();
    
    // Get cancellation reason
    $reason = isset($_POST['reason']) ? trim($_POST['reason']) : 'Cancelled by gym management';
    
    // Update schedule status to cancelled
    $updateStmt = $conn->prepare("
        UPDATE schedules 
        SET status = 'cancelled', 
            cancellation_reason = ?
        WHERE id = ?
    ");
    $updateStmt->execute([$reason, $schedule_id]);
    
    // Log the action
    $logStmt = $conn->prepare("
        INSERT INTO schedule_logs (user_id, schedule_id, action_type, notes)
        VALUES (?, ?, 'cancel', ?)
    ");
    
    $logStmt->execute([
        $owner_id,
        $schedule_id,
        'Booking cancelled by gym staff. Reason: ' . $reason
    ]);
    
    // Create notification for user
    $notifyStmt = $conn->prepare("
        INSERT INTO notifications (user_id, type, title, message, related_id, gym_id, created_at)
        VALUES (?, 'booking', ?, ?, ?, ?, NOW())
    ");
    
    $title = "Booking Cancelled at {$schedule['gym_name']}";
    $message = "Your booking for " . ucfirst(str_replace('_', ' ', $schedule['activity_type'])) . " on " . 
               date('F j, Y', strtotime($schedule['start_date'])) . " at " . 
               date('g:i A', strtotime($schedule['start_time'])) . " has been cancelled.\n\nReason: " . $reason;
    
    $notifyStmt->execute([
        $schedule['user_id'],
        $title,
        $message,
        $schedule_id,
        $schedule['gym_id']
    ]);
    
    // Send email notification if email service is available
    if (file_exists('../includes/EmailService.php')) {
        require_once '../includes/EmailService.php';
        
        $emailService = new EmailService($conn);
        $subject = "Your Booking at {$schedule['gym_name']} has been Cancelled";
        $body = "
            <p>Hello {$schedule['username']},</p>
            <p>We regret to inform you that your booking at {$schedule['gym_name']} has been cancelled.</p>
            <p><strong>Details:</strong></p>
            <ul>
                <li>Date: " . date('F j, Y', strtotime($schedule['start_date'])) . "</li>
                <li>Time: " . date('g:i A', strtotime($schedule['start_time'])) . "</li>
                <li>Activity: " . ucfirst(str_replace('_', ' ', $schedule['activity_type'])) . "</li>
            </ul>
            <p><strong>Reason for cancellation:</strong> " . htmlspecialchars($reason) . "</p>
            <p>We apologize for any inconvenience this may cause.</p>
        ";
        
        $emailService->sendEmail($schedule['email'], $subject, $body);
    }
    
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