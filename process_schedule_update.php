<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$db = new GymDatabase();
$conn = $db->getConnection();
$user_id = $_SESSION['user_id'];

// Validate inputs
$schedule_id = filter_input(INPUT_POST, 'update_schedule', FILTER_VALIDATE_INT);
$new_gym_id = filter_input(INPUT_POST, 'new_gym_id', FILTER_VALIDATE_INT);
$old_gym_id = filter_input(INPUT_POST, 'old_gym_id', FILTER_VALIDATE_INT);
$membership_id = filter_input(INPUT_POST, 'membership_id', FILTER_VALIDATE_INT);
$start_date = filter_input(INPUT_POST, 'start_date', FILTER_SANITIZE_STRING);
$start_time = filter_input(INPUT_POST, 'start_time', FILTER_SANITIZE_STRING);
$notes = filter_input(INPUT_POST, 'notes', FILTER_SANITIZE_STRING);

if (!$schedule_id || !$new_gym_id || !$start_date || !$start_time) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

try {
    $conn->beginTransaction();
    
    // Check if the gym is open
    $gymStatusCheck = $conn->prepare("
        SELECT is_open FROM gyms WHERE gym_id = ?
    ");
    $gymStatusCheck->execute([$new_gym_id]);
    $isGymOpen = $gymStatusCheck->fetchColumn();
    
    if (!$isGymOpen) {
        throw new Exception("Cannot schedule at this gym as it is currently closed.");
    }
    
    // Check if the selected time slot is available
    $occupancyCheck = $conn->prepare("
        SELECT COUNT(*) as current_occupancy 
        FROM schedules 
        WHERE gym_id = ? 
        AND start_date = ? 
        AND start_time = ?
        AND id != ?
    ");
    $occupancyCheck->execute([$new_gym_id, $start_date, $start_time, $schedule_id]);
    $currentOccupancy = $occupancyCheck->fetch(PDO::FETCH_ASSOC)['current_occupancy'];

    if ($currentOccupancy >= 50) {
        throw new Exception("Selected time slot is full. Maximum capacity (50) reached.");
    }
    
    // Check if the schedule exists and get current details
    $checkScheduleStmt = $conn->prepare("
        SELECT id, daily_rate, cut_type FROM schedules WHERE id = ? AND user_id = ?
    ");
    $checkScheduleStmt->execute([$schedule_id, $user_id]);
    $currentSchedule = $checkScheduleStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$currentSchedule) {
        throw new Exception("Schedule not found or you don't have permission to update it");
    }
    
    // Get membership details to check validity
    $membershipStmt = $conn->prepare("
        SELECT end_date FROM user_memberships 
        WHERE id = ? AND user_id = ? AND status = 'active'
    ");
    $membershipStmt->execute([$membership_id, $user_id]);
    $membership = $membershipStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$membership) {
        throw new Exception("Invalid membership selected");
    }
    
    // Check if the selected date is within the membership period
    $membershipEndDate = new DateTime($membership['end_date']);
    $selectedDate = new DateTime($start_date);
    
    if ($selectedDate > $membershipEndDate) {
        throw new Exception("Cannot schedule beyond your membership end date (" . $membershipEndDate->format('Y-m-d') . ")");
    }

    // Update the schedule - keep the same daily_rate and cut_type
    $updateStmt = $conn->prepare("
        UPDATE schedules
        SET gym_id = ?, 
            start_date = ?, 
            end_date = ?, 
            start_time = ?, 
            notes = ?
        WHERE id = ? AND user_id = ?
    ");
    
    $updateStmt->execute([
        $new_gym_id,
        $start_date,
        $start_date, // end_date is same as start_date for single day
        $start_time,
        $notes,
        $schedule_id,
        $user_id
    ]);

    // Update gym occupancy for old and new gym only if changing gyms
    if ($old_gym_id && $old_gym_id != $new_gym_id) {
        $updateOldGymStmt = $conn->prepare("
            UPDATE gyms 
            SET current_occupancy = GREATEST(current_occupancy - 1, 0)
            WHERE gym_id = ?
        ");
        $updateOldGymStmt->execute([$old_gym_id]);
        
        $updateNewGymStmt = $conn->prepare("
            UPDATE gyms 
            SET current_occupancy = current_occupancy + 1
            WHERE gym_id = ?
        ");
        $updateNewGymStmt->execute([$new_gym_id]);
    }

    // Create notifications
    $notifyStmt = $conn->prepare("
        INSERT INTO notifications (
            user_id, gym_id, message, title, status
        ) VALUES 
        (?, NULL, ?, 'Schedule Updated', 'unread'),
        (NULL, ?, ?, 'New Schedule', 'unread')
    ");
    $notifyStmt->execute([
        $user_id,
        "Your schedule has been updated to " . date('d M Y', strtotime($start_date)) . " at " . date('g:i A', strtotime($start_time)),
        $new_gym_id,
        "New schedule from user #" . $user_id . " on " . date('d M Y', strtotime($start_date))
    ]);

    // Log the activity
    $logStmt = $conn->prepare("
        INSERT INTO activity_logs (
            user_id, user_type, action, details, ip_address, user_agent
        ) VALUES (?, 'member', 'schedule_update', ?, ?, ?)
    ");
    $logStmt->execute([
        $user_id,
        json_encode([
            'schedule_id' => $schedule_id,
            'old_gym_id' => $old_gym_id,
            'new_gym_id' => $new_gym_id,
            'start_date' => $start_date,
            'start_time' => $start_time
        ]),
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);

    $conn->commit();
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => 'Schedule updated successfully',
        'data' => [
            'schedule_id' => $schedule_id,
            'gym_id' => $new_gym_id,
            'start_date' => $start_date,
            'start_time' => $start_time
        ]
    ]);

} catch (Exception $e) {
    $conn->rollBack();
    error_log("Schedule update failed: " . $e->getMessage());
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

// Helper function to check if a time slot is in the past
function isTimeSlotInPast($date, $time) {
    $dateTime = new DateTime($date . ' ' . $time);
    $now = new DateTime();
    
    // Add a 15-minute buffer
    $buffer = new DateInterval('PT15M');
    $now->add($buffer);
    
    return $dateTime <= $now;
}

// Helper function to check if a time slot is within gym operating hours
function isWithinOperatingHours($conn, $gymId, $date, $time) {
    // Get the day of week
    $dayOfWeek = date('l', strtotime($date));
    
    // First check if there's a specific schedule for this day
    $stmt = $conn->prepare("
        SELECT 
            morning_open_time, morning_close_time,
            evening_open_time, evening_close_time
        FROM gym_operating_hours
        WHERE gym_id = ? AND day = ?
    ");
    $stmt->execute([$gymId, $dayOfWeek]);
    $hours = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // If no specific schedule, check for 'Daily' schedule
    if (!$hours) {
        $stmt = $conn->prepare("
            SELECT 
                morning_open_time, morning_close_time,
                evening_open_time, evening_close_time
            FROM gym_operating_hours
            WHERE gym_id = ? AND day = 'Daily'
        ");
        $stmt->execute([$gymId]);
        $hours = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // If no hours found, assume gym is closed
    if (!$hours) {
        return false;
    }
    
    // Convert time strings to timestamps for comparison
    $timeStamp = strtotime($time);
    $morningOpenStamp = strtotime($hours['morning_open_time']);
    $morningCloseStamp = strtotime($hours['morning_close_time']);
    $eveningOpenStamp = strtotime($hours['evening_open_time']);
    $eveningCloseStamp = strtotime($hours['evening_close_time']);
    
    // Check if time is within morning or evening hours
    return ($timeStamp >= $morningOpenStamp && $timeStamp <= $morningCloseStamp) ||
           ($timeStamp >= $eveningOpenStamp && $timeStamp <= $eveningCloseStamp);
}
?>
