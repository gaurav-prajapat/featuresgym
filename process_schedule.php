<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = "Invalid request";
    header('Location: dashboard.php');
    exit;
}

$db = new GymDatabase();
$conn = $db->getConnection();
$user_id = $_SESSION['user_id'];

try {
    // Begin transaction
    $conn->beginTransaction();
    
    // Get form data
    $membership_id = $_POST['membership_id'] ?? null;
    $gym_id = $_POST['gym_id'] ?? null;
    $activity_type = $_POST['activity_type'] ?? 'gym_visit';
    $daily_rate = $_POST['daily_rate'] ?? 0;
    $cut_type = $_POST['cut_type'] ?? 'tier_based';
    $notes = $_POST['notes'] ?? null;
    
    // Determine start date and time based on activity type
    if ($activity_type === 'gym_visit') {
        $start_date = $_POST['start_date'] ?? null;
        $end_date = $_POST['end_date'] ?? $start_date;
        $start_time = $_POST['start_time'] ?? null;
    } elseif ($activity_type === 'class') {
        $start_date = $_POST['class_date'] ?? null;
        $end_date = $start_date;
        
        // Get class details and time
        $class_id = $_POST['class_id'] ?? null;
        if (!$class_id) {
            throw new Exception("No class selected");
        }
        
        // Get class time from the class schedule
        $classStmt = $conn->prepare("SELECT schedule FROM gym_classes WHERE id = ?");
        $classStmt->execute([$class_id]);
        $classData = $classStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$classData) {
            throw new Exception("Class not found");
        }
        
        $schedule = json_decode($classData['schedule'], true);
        $dayOfWeek = date('l', strtotime($start_date));
        
        // Find the time for the selected day
        $start_time = null;
        foreach ($schedule as $day => $times) {
            if ($day === $dayOfWeek || $day === 'Daily') {
                $start_time = $times['start_time'] ?? null;
                break;
            }
        }
        
        if (!$start_time) {
            throw new Exception("No available time for the selected class on this date");
        }
    } elseif ($activity_type === 'personal_training') {
        $start_date = $_POST['training_date'] ?? null;
        $end_date = $start_date;
        $start_time = $_POST['training_time'] ?? null;
    } else {
        throw new Exception("Invalid activity type");
    }
    
    if (!$start_date || !$start_time) {
        throw new Exception("Date and time are required");
    }
    
    // Check if user already has a booking for this date at this gym
    $checkStmt = $conn->prepare("
        SELECT COUNT(*) FROM schedules 
        WHERE user_id = ? AND gym_id = ? AND start_date = ? AND status != 'cancelled'
    ");
    $checkStmt->execute([$user_id, $gym_id, $start_date]);
    
    if ($checkStmt->fetchColumn() > 0) {
        throw new Exception("You already have a booking at this gym for the selected date");
    }
    
    // Get system settings for commission rates
    $settingsStmt = $conn->prepare("
        SELECT setting_key, setting_value 
        FROM system_settings 
        WHERE setting_group = 'commission' 
        AND setting_key IN ('admin_commission_rate', 'gym_commission_rate')
    ");
    $settingsStmt->execute();
    $settings = $settingsStmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // Default commission rates if not set
    $adminCommissionRate = isset($settings['admin_commission_rate']) ? 
        (float)$settings['admin_commission_rate'] : 30.00;
    $gymCommissionRate = isset($settings['gym_commission_rate']) ? 
        (float)$settings['gym_commission_rate'] : 70.00;
    
    // Calculate admin cut based on daily rate
    $adminCut = $daily_rate * ($adminCommissionRate / 100);
    
    // Insert schedule
    $insertStmt = $conn->prepare("
        INSERT INTO schedules (
            user_id, gym_id, membership_id, activity_type, 
            start_date, end_date, start_time, status, notes,
            daily_rate, cut_type, payment_status,
            recurring, recurring_until, days_of_week,
            created_at
        ) VALUES (
            ?, ?, ?, ?, 
            ?, ?, ?, 'scheduled', ?,
            ?, ?, 'pending',
            ?, ?, ?,
            NOW()
        )
    ");
    
    // Handle recurring options
    $recurring = 'none';
    $recurringUntil = null;
    $daysOfWeek = null;
    
    if (isset($_POST['is_recurring']) && $_POST['is_recurring']) {
        $recurring = $_POST['recurring'] ?? 'none';
        $recurringUntil = $_POST['recurring_until'] ?? null;
        
        if ($recurring === 'weekly' && isset($_POST['days_of_week'])) {
            $daysOfWeek = json_encode($_POST['days_of_week']);
        }
    }
    
    $insertStmt->execute([
        $user_id, $gym_id, $membership_id, $activity_type,
        $start_date, $end_date, $start_time, $notes,
        $daily_rate, $cut_type,
        $recurring, $recurringUntil, $daysOfWeek
    ]);
    
    $schedule_id = $conn->lastInsertId();
    
    // Create recurring schedules if needed
    if ($recurring !== 'none' && $recurringUntil) {
        $startDateObj = new DateTime($start_date);
        $endDateObj = new DateTime($recurringUntil);
        $interval = null;
        
        switch ($recurring) {
            case 'daily':
                $interval = new DateInterval('P1D');
                break;
            case 'weekly':
                $interval = new DateInterval('P7D');
                break;
            case 'monthly':
                $interval = new DateInterval('P1M');
                break;
        }
        
        if ($interval) {
            $currentDate = clone $startDateObj;
            $currentDate->add($interval); // Start from next occurrence
            
            $recurringInsertStmt = $conn->prepare("
                INSERT INTO schedules (
                    user_id, gym_id, membership_id, activity_type, 
                    start_date, end_date, start_time, status, notes,
                    daily_rate, cut_type, payment_status,
                    recurring, recurring_until, days_of_week,
                    created_at
                ) VALUES (
                    ?, ?, ?, ?, 
                    ?, ?, ?, 'scheduled', ?,
                    ?, ?, 'pending',
                    ?, ?, ?,
                    NOW()
                )
            ");
            
            // For weekly recurrence with specific days
            if ($recurring === 'weekly' && $daysOfWeek) {
                $selectedDays = json_decode($daysOfWeek, true);
                
                while ($currentDate <= $endDateObj) {
                    $dayOfWeek = (int)$currentDate->format('N'); // 1 (Monday) to 7 (Sunday)
                    
                    if (in_array($dayOfWeek, $selectedDays)) {
                        $recurringInsertStmt->execute([
                            $user_id, $gym_id, $membership_id, $activity_type,
                            $currentDate->format('Y-m-d'), $currentDate->format('Y-m-d'), $start_time, $notes,
                            $daily_rate, $cut_type,
                            $recurring, $recurringUntil, $daysOfWeek
                        ]);
                    }
                    
                    $currentDate->add(new DateInterval('P1D'));
                }
            } else {
                // For daily and monthly recurrence
                while ($currentDate <= $endDateObj) {
                    $recurringInsertStmt->execute([
                        $user_id, $gym_id, $membership_id, $activity_type,
                        $currentDate->format('Y-m-d'), $currentDate->format('Y-m-d'), $start_time, $notes,
                        $daily_rate, $cut_type,
                        $recurring, $recurringUntil, $daysOfWeek
                    ]);
                    
                    $currentDate->add($interval);
                }
            }
        }
    }
    
    // Record revenue for the gym
    $revenueStmt = $conn->prepare("
        INSERT INTO gym_revenue (
            gym_id, date, amount, admin_cut, source_type, 
            schedule_id, notes, daily_rate, cut_type, 
            payment_status, description, user_id
        ) VALUES (
            ?, CURRENT_DATE(), ?, ?, 'schedule', 
            ?, ?, ?, ?, 
            'pending', ?, ?
        )
    ");
    
    $revenueDescription = "Revenue from " . ucfirst(str_replace('_', ' ', $activity_type));
    
    $revenueStmt->execute([
        $gym_id, $daily_rate, $adminCut, 
        $schedule_id, "Schedule booking", $daily_rate, $cut_type,
        $revenueDescription, $user_id
    ]);
    
    // Update gym occupancy
    $updateGymStmt = $conn->prepare("
        UPDATE gyms 
        SET current_occupancy = current_occupancy + 1
        WHERE gym_id = ?
    ");
    $updateGymStmt->execute([$gym_id]);
    
    // Create notification for gym owner
    $notifyStmt = $conn->prepare("
        INSERT INTO gym_notifications (
            gym_id, title, message, created_at
        ) VALUES (
            ?, 'New Booking', ?, NOW()
        )
    ");
    
    $notifyMessage = "A new " . str_replace('_', ' ', $activity_type) . " has been scheduled for " . 
                    date('M d, Y', strtotime($start_date)) . " at " . 
                    date('h:i A', strtotime($start_time));
    
    $notifyStmt->execute([$gym_id, $notifyMessage]);
    
    // Create notification for user
    $userNotifyStmt = $conn->prepare("
        INSERT INTO notifications (
            user_id, type, title, message, related_id, gym_id, created_at
        ) VALUES (
            ?, 'booking', 'Booking Confirmed', ?, ?, ?, NOW()
        )
    ");
    
    $userNotifyMessage = "Your " . str_replace('_', ' ', $activity_type) . " has been scheduled for " . 
                        date('M d, Y', strtotime($start_date)) . " at " . 
                        date('h:i A', strtotime($start_time));
    
    $userNotifyStmt->execute([$user_id, $userNotifyMessage, $schedule_id, $gym_id]);
    
    // Commit transaction
    $conn->commit();
    
    $_SESSION['success'] = "Your workout has been scheduled successfully!";
    header('Location: dashboard.php');
    exit;
    
} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollBack();
    
    $_SESSION['error'] = "Error scheduling workout: " . $e->getMessage();
    header('Location: schedule.php?membership_id=' . ($membership_id ?? ''));
    exit;
}
