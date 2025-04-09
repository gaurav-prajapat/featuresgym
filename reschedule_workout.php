<?php
session_start();    
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = "You must be logged in to reschedule a workout.";
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = "Invalid request method.";
    header('Location: schedule-history.php');
    exit;
}

$schedule_id = filter_input(INPUT_POST, 'schedule_id', FILTER_VALIDATE_INT);
$new_time = filter_input(INPUT_POST, 'new_time', FILTER_SANITIZE_STRING);
$reschedule_reason = filter_input(INPUT_POST, 'reschedule_reason', FILTER_SANITIZE_STRING);
$other_reason = filter_input(INPUT_POST, 'other_reschedule_reason', FILTER_SANITIZE_STRING);
$can_reschedule_free = filter_input(INPUT_POST, 'can_reschedule_free', FILTER_VALIDATE_INT, ['options' => ['default' => 1]]);

// Validate required fields
if (!$schedule_id || !$new_time || !$reschedule_reason) {
    $_SESSION['error'] = "All fields are required.";
    header('Location: schedule-history.php');
    exit;
}

if ($reschedule_reason === 'other' && empty($other_reason)) {
    $_SESSION['error'] = "Please provide a reason for rescheduling.";
    header('Location: schedule-history.php');
    exit;
}

$final_reason = $reschedule_reason === 'other' ? $other_reason : $reschedule_reason;

$db = new GymDatabase();
$conn = $db->getConnection();
$user_id = $_SESSION['user_id'];

// Start transaction
$conn->beginTransaction();

try {
    // Get the schedule details
    $stmt = $conn->prepare("
        SELECT s.*, g.name as gym_name, g.reschedule_fee_amount, u.balance, u.email
        FROM schedules s
        JOIN gyms g ON s.gym_id = g.gym_id
        JOIN users u ON s.user_id = u.id
        WHERE s.id = ? AND s.user_id = ?
    ");
    $stmt->execute([$schedule_id, $user_id]);
    $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$schedule) {
        throw new Exception("Schedule not found or you don't have permission to modify it.");
    }
    
    if ($schedule['status'] !== 'scheduled') {
        throw new Exception("Only scheduled workouts can be rescheduled.");
    }
    
    // Check if the workout has already started
    $currentDateTime = new DateTime();
    $workoutDateTime = new DateTime($schedule['start_date'] . ' ' . $schedule['start_time']);
    
    if ($currentDateTime >= $workoutDateTime) {
        throw new Exception("Cannot reschedule a workout that has already started or passed.");
    }
    
    // Check if the new time slot is available on the same date
    $stmt = $conn->prepare("
        SELECT COUNT(*) 
        FROM schedules 
        WHERE gym_id = ? 
        AND start_date = ? 
        AND start_time = ?
    ");
    $stmt->execute([$schedule['gym_id'], $schedule['start_date'], $new_time]);
    $occupancy = $stmt->fetchColumn();
    
    if ($occupancy >= 50) { // Assuming max capacity is 50
        throw new Exception("The selected time slot is no longer available. Please choose another time.");
    }
    
    // Check if user already has a booking for the new time
    $stmt = $conn->prepare("
        SELECT COUNT(*) 
        FROM schedules 
        WHERE user_id = ? 
        AND gym_id = ? 
        AND start_date = ? 
        AND start_time = ?
        AND id != ?
    ");
    $stmt->execute([$user_id, $schedule['gym_id'], $schedule['start_date'], $new_time, $schedule_id]);
    $existingBooking = $stmt->fetchColumn();
    
    if ($existingBooking > 0) {
        throw new Exception("You already have a booking for this date and time.");
    }
    
    // Apply rescheduling fee if applicable
    $fee_applied = false;
    $fee_amount = 0;
    
    if ($can_reschedule_free === 0 && $schedule['reschedule_fee_amount'] > 0) {
        $fee_amount = $schedule['reschedule_fee_amount'];
        $new_balance = $schedule['balance'] - $fee_amount;
        
        if ($new_balance < 0) {
            throw new Exception("Insufficient balance to cover the rescheduling fee of ₹{$fee_amount}.");
        }
        
        // Update user balance
        $stmt = $conn->prepare("UPDATE users SET balance = ? WHERE id = ?");
        $stmt->execute([$new_balance, $user_id]);
        
        // Record the transaction
        $stmt = $conn->prepare("
            INSERT INTO transactions (user_id, amount, type, description, status, created_at)
            VALUES (?, ?, 'fee', ?, 'completed', NOW())
        ");
        $description = "Rescheduling fee for workout at {$schedule['gym_name']} from " . 
                       date('g:i A', strtotime($schedule['start_time'])) . " to " . 
                       date('g:i A', strtotime($new_time)) . " on " . 
                       date('M j, Y', strtotime($schedule['start_date']));
        $stmt->execute([$user_id, $fee_amount, $description]);
        
        $fee_applied = true;
    }
    
    // Store old time for logging
    $old_time = $schedule['start_time'];
    
    // Update the schedule - only changing the time, not the date
    $stmt = $conn->prepare("
        UPDATE schedules 
        SET start_time = ?, notes = CONCAT(IFNULL(notes, ''), '\n[Rescheduled] ', ?)
        WHERE id = ?
    ");
    $rescheduled_note = "Time rescheduled from " . 
                        date('g:i A', strtotime($old_time)) . " to " . 
                        date('g:i A', strtotime($new_time)) . " on " . 
                        date('M j, Y', strtotime($schedule['start_date'])) . ". Reason: " . $final_reason;
    $stmt->execute([$new_time, $rescheduled_note, $schedule_id]);
    
    // Log the rescheduling using the schedule_logs table structure
    $stmt = $conn->prepare("
        INSERT INTO schedule_logs (
            schedule_id, 
            user_id, 
            action_type, 
            old_gym_id,
            new_gym_id,
            old_time, 
            new_time, 
            amount, 
            notes, 
            created_at
        ) VALUES (?, ?, 'update', ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $schedule_id, 
        $user_id, 
        $schedule['gym_id'], // Same gym for old and new
        $schedule['gym_id'], 
        $old_time, 
        $new_time, 
        $fee_amount,
        "Rescheduled time only. Reason: " . $final_reason
    ]);
    
    $conn->commit();
    
    $_SESSION['success'] = "Your workout time has been rescheduled successfully." . 
                          ($fee_applied ? " A rescheduling fee of ₹{$fee_amount} has been charged." : "");
    header('Location: schedule-history.php');
    exit;
    
} catch (Exception $e) {
    $conn->rollBack();
    $_SESSION['error'] = $e->getMessage();
    header('Location: schedule-history.php');
    exit;
}
