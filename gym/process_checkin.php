<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a gym owner
if (!isset($_SESSION['owner_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => "Unauthorized access"]);
    exit;
}

$owner_id = $_SESSION['owner_id'];
$db = new GymDatabase();
$conn = $db->getConnection();

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['schedule_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => "Invalid request"]);
    exit;
}

$schedule_id = intval($_POST['schedule_id']);
$action = $_POST['action'] ?? 'check_in'; // Default to check_in if not specified

// Verify that the schedule belongs to a gym owned by this owner
$verifyStmt = $conn->prepare("
    SELECT s.id, s.user_id, s.gym_id, s.activity_type, s.start_date, s.start_time, 
           s.status, s.check_in_time, s.check_out_time, s.membership_id, s.daily_rate,
           g.name as gym_name, g.capacity, g.current_occupancy,
           u.username, u.email, u.phone
    FROM schedules s
    JOIN gyms g ON s.gym_id = g.gym_id
    JOIN users u ON s.user_id = u.id
    WHERE s.id = ? AND g.owner_id = ?
");
$verifyStmt->execute([$schedule_id, $owner_id]);
$schedule = $verifyStmt->fetch(PDO::FETCH_ASSOC);

if (!$schedule) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => "Schedule not found or access denied"]);
    exit;
}

// Check if the action is valid for the current schedule state
if ($action === 'check_in' && $schedule['check_in_time']) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => "User has already been checked in"]);
    exit;
}

if ($action === 'check_out' && !$schedule['check_in_time']) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => "User must be checked in before checking out"]);
    exit;
}

if ($action === 'check_out' && $schedule['check_out_time']) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => "User has already been checked out"]);
    exit;
}

try {
    $conn->beginTransaction();
    
    if ($action === 'check_in') {
        // Update schedule with check-in time
        $updateStmt = $conn->prepare("
            UPDATE schedules 
            SET check_in_time = NOW(), 
                status = 'scheduled'
            WHERE id = ?
        ");
        $updateStmt->execute([$schedule_id]);
        
        // Update gym current occupancy
        $updateGymStmt = $conn->prepare("
            UPDATE gyms 
            SET current_occupancy = LEAST(current_occupancy + 1, capacity)
            WHERE gym_id = ?
        ");
        $updateGymStmt->execute([$schedule['gym_id']]);
        
        // Log the action
        $logStmt = $conn->prepare("
            INSERT INTO schedule_logs (user_id, schedule_id, action_type, notes)
            VALUES (?, ?, 'update', ?)
        ");
        
        $logStmt->execute([
            $owner_id,
            $schedule_id,
            'User checked in by gym staff'
        ]);
        
        // Create notification for user
        $notifyStmt = $conn->prepare("
            INSERT INTO notifications (user_id, type, title, message, related_id, gym_id, created_at)
            VALUES (?, 'booking', ?, ?, ?, ?, NOW())
        ");
        
        $title = "Check-in at {$schedule['gym_name']}";
        $message = "You have been checked in for your " . ucfirst(str_replace('_', ' ', $schedule['activity_type'])) . 
                   " on " . date('F j, Y', strtotime($schedule['start_date'])) . " at " . 
                   date('g:i A', strtotime($schedule['start_time'])) . ".";
        
        $notifyStmt->execute([
            $schedule['user_id'],
            $title,
            $message,
            $schedule_id,
            $schedule['gym_id']
        ]);
        
        // Record activity log
        $activityStmt = $conn->prepare("
            INSERT INTO activity_logs (user_id, user_type, action, details, ip_address, user_agent)
            VALUES (?, 'owner', 'check_in_user', ?, ?, ?)
        ");
        
        $details = "Checked in user {$schedule['username']} (ID: {$schedule['user_id']}) for schedule ID: {$schedule_id}";
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        
        $activityStmt->execute([
            $owner_id,
            $details,
            $ip,
            $user_agent
        ]);
        
        $conn->commit();
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => "User has been checked in successfully"]);
        exit;
    } elseif ($action === 'check_out') {
        // Update schedule with check-out time
        $updateStmt = $conn->prepare("
            UPDATE schedules 
            SET check_out_time = NOW(), 
                status = 'completed'
            WHERE id = ?
        ");
        $updateStmt->execute([$schedule_id]);
        
        // Update gym current occupancy
        $updateGymStmt = $conn->prepare("
            UPDATE gyms 
            SET current_occupancy = GREATEST(current_occupancy - 1, 0)
            WHERE gym_id = ?
        ");
        $updateGymStmt->execute([$schedule['gym_id']]);
        
        // Log the action
        $logStmt = $conn->prepare("
            INSERT INTO schedule_logs (user_id, schedule_id, action_type, notes)
            VALUES (?, ?, 'complete', ?)
        ");
        
        $logStmt->execute([
            $owner_id,
            $schedule_id,
            'User checked out by gym staff'
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
        
        // Record revenue if applicable (for daily passes)
        if ($schedule['membership_id'] && $schedule['daily_rate'] > 0) {
            $revenueStmt = $conn->prepare("
                INSERT INTO gym_revenue (
                    gym_id, date, amount, admin_cut, source_type, 
                    schedule_id, notes, daily_rate, cut_type, payment_status, user_id
                )
                VALUES (?, CURRENT_DATE, ?, ?, 'schedule', ?, ?, ?, 'tier_based', 'paid', ?)
            ");
            
            // Calculate admin cut (30% by default, can be adjusted based on tier)
            $amount = $schedule['daily_rate'];
            $adminCut = $amount * 0.3; // 30% to admin
            
            $revenueStmt->execute([
                $schedule['gym_id'],
                $amount,
                $adminCut,
                $schedule_id,
                "Revenue from completed schedule",
                $schedule['daily_rate'],
                $schedule['user_id']
            ]);
        }
        
        // Record activity log
        $activityStmt = $conn->prepare("
            INSERT INTO activity_logs (user_id, user_type, action, details, ip_address, user_agent)
            VALUES (?, 'owner', 'check_out_user', ?, ?, ?)
        ");
        
        $details = "Checked out user {$schedule['username']} (ID: {$schedule['user_id']}) for schedule ID: {$schedule_id}";
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        
        $activityStmt->execute([
            $owner_id,
            $details,
            $ip,
            $user_agent
        ]);
        
        $conn->commit();
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => "User has been checked out successfully"]);
        exit;
    } else {
        $conn->rollBack();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => "Invalid action"]);
        exit;
    }
} catch (Exception $e) {
    $conn->rollBack();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => "Error: " . $e->getMessage()]);
    exit;
}
