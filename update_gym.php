<?php
session_start();
require_once 'config/database.php';

$db = new GymDatabase();
$conn = $db->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_SESSION['user_id'];
    $new_gym_id = $_POST['new_gym_id'];
    $old_gym_id = $_POST['old_gym_id'];
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    
    try {
        $conn->beginTransaction();
        
        // Get user's membership plan details
        $planStmt = $conn->prepare("
            SELECT um.*, gmp.price, gmp.duration
            FROM user_memberships um
            JOIN gym_membership_plans gmp ON um.plan_id = gmp.plan_id
            WHERE um.user_id = ? AND um.status = 'active'
            AND um.gym_id = ?
        ");
        $planStmt->execute([$user_id, $old_gym_id]);
        $membership = $planStmt->fetch(PDO::FETCH_ASSOC);
        
        // Calculate daily rate
        $monthlyRate = $membership['price'];
        $dailyRate = $monthlyRate / 30;
        
        // Calculate days between dates
        $date1 = new DateTime($start_date);
        $date2 = new DateTime($end_date);
        $interval = $date1->diff($date2);
        $days = $interval->days + 1;
        
        // Calculate refund amount for old gym
        $refundAmount = $dailyRate * $days;
        
        // Update old gym revenue
        $deductStmt = $conn->prepare("
            INSERT INTO gym_revenue 
            (gym_id, date, amount, source_type, notes)
            VALUES (?, CURRENT_DATE, ?, 'refund', 'Membership transfer deduction')
        ");
        $deductStmt->execute([$old_gym_id, -$refundAmount]);
        
        // Add revenue to new gym
        $addStmt = $conn->prepare("
            INSERT INTO gym_revenue 
            (gym_id, date, amount, source_type, notes)
            VALUES (?, CURRENT_DATE, ?, 'transfer', 'Membership transfer addition')
        ");
        $addStmt->execute([$new_gym_id, $refundAmount]);
        
        // Update user schedules
        $updateScheduleStmt = $conn->prepare("
            UPDATE schedules 
            SET gym_id = ?
            WHERE user_id = ? 
            AND start_date BETWEEN ? AND ?
        ");
        $updateScheduleStmt->execute([
            $new_gym_id,
            $user_id,
            $start_date,
            $end_date
        ]);
        
        $conn->commit();
        echo json_encode(['success' => true]);
        
    } catch (Exception $e) {
        $conn->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
