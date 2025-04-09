<?php
session_start();
require 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $schedule_id = intval($_POST['schedule_id']);
    $user_id = $_SESSION['user_id'];
    $can_cancel_free = isset($_POST['can_cancel_free']) ? $_POST['can_cancel_free'] == '1' : true;
    
    // Get cancellation reason
    $cancel_reason = $_POST['cancel_reason'] ?? '';
    if ($cancel_reason === 'other') {
        $cancellation_reason = trim($_POST['other_reason'] ?? '');
    } else {
        $cancellation_reason = trim($cancel_reason);
    }
    
    if (empty($cancellation_reason)) {
        $_SESSION['error'] = "Please provide a reason for cancellation.";
        header('Location: schedule-history.php');
        exit;
    }

    $db = new GymDatabase();
    $conn = $db->getConnection();

    // Begin transaction
    $conn->beginTransaction();
    
    try {
        // Fetch schedule and validate
        $stmt = $conn->prepare("
            SELECT s.*, g.name as gym_name, u.balance 
            FROM schedules s 
            JOIN gyms g ON s.gym_id = g.gym_id
            JOIN users u ON s.user_id = u.id
            WHERE s.id = ? AND s.user_id = ? AND s.status = 'scheduled'
        ");
        $stmt->execute([$schedule_id, $user_id]);
        $schedule = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$schedule) {
            throw new Exception("Invalid schedule or already canceled.");
        }

        // Apply cancellation fee if applicable
        $fee_applied = false;
        $fee_amount = 0;
        
        if (!$can_cancel_free) {
            $fee_amount = 200; // Default cancellation fee
            $new_balance = $schedule['balance'] - $fee_amount;
            
            if ($new_balance < 0) {
                throw new Exception("Insufficient balance to cover the cancellation fee of ₹{$fee_amount}.");
            }
            
            // Update user balance
            $stmt = $conn->prepare("UPDATE users SET balance = ? WHERE id = ?");
            $stmt->execute([$new_balance, $user_id]);
            
            // Record the transaction
            $stmt = $conn->prepare("
                INSERT INTO transactions (user_id, amount, type, description, status, created_at)
                VALUES (?, ?, 'fee', ?, 'completed', NOW())
            ");
            $description = "Cancellation fee for workout at {$schedule['gym_name']} on " . 
                           date('M j, Y', strtotime($schedule['start_date'])) . " at " . 
                           date('g:i A', strtotime($schedule['start_time']));
            $stmt->execute([$user_id, $fee_amount, $description]);
            
            $fee_applied = true;
        }

        // Update schedule status
        $stmt = $conn->prepare("
            UPDATE schedules 
            SET status = 'cancelled', cancellation_reason = ? 
            WHERE id = ?
        ");
        $stmt->execute([$cancellation_reason, $schedule_id]);

        // Log the cancellation
        $stmt = $conn->prepare("
            INSERT INTO schedule_logs (schedule_id, user_id, action_type, reason, fee_applied, fee_amount, created_at)
            VALUES (?, ?, 'cancel', ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $schedule_id, $user_id, $cancellation_reason, $fee_applied ? 1 : 0, $fee_amount
        ]);

        $conn->commit();
        
        $_SESSION['success'] = "Your workout has been cancelled successfully." . 
                              ($fee_applied ? " A cancellation fee of ₹{$fee_amount} has been charged." : "");
        header('Location: schedule-history.php');
        exit;
    } catch (Exception $e) {
        $conn->rollBack();
        $_SESSION['error'] = $e->getMessage();
        header('Location: schedule-history.php');
        exit;
    }
} else {
    header('Location: schedule-history.php');
    exit;
}
?>
