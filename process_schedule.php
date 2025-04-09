<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$db = new GymDatabase();
$conn = $db->getConnection();
$user_id = $_SESSION['user_id'];

// Validate inputs
$membership_id = filter_input(INPUT_POST, 'membership_id', FILTER_VALIDATE_INT);
$gym_id = filter_input(INPUT_POST, 'gym_id', FILTER_VALIDATE_INT);
$start_date = filter_input(INPUT_POST, 'start_date', FILTER_SANITIZE_STRING);
$end_date = filter_input(INPUT_POST, 'end_date', FILTER_SANITIZE_STRING) ?? $start_date; // Default to start_date if not provided
$activity_type = filter_input(INPUT_POST, 'activity_type', FILTER_SANITIZE_STRING);
$notes = filter_input(INPUT_POST, 'notes', FILTER_SANITIZE_STRING);
$recurring = filter_input(INPUT_POST, 'recurring', FILTER_SANITIZE_STRING) ?? 'none';

// Get time slot based on activity type
$start_time = null;
if ($activity_type === 'gym_visit') {
    $start_time = filter_input(INPUT_POST, 'start_time', FILTER_SANITIZE_STRING);
} elseif ($activity_type === 'class') {
    $class_id = filter_input(INPUT_POST, 'class_id', FILTER_VALIDATE_INT);
    $start_time = filter_input(INPUT_POST, 'class_time', FILTER_SANITIZE_STRING);
    // If class_time not provided, get the class schedule from database
    if (!$start_time && $class_id) {
        $classStmt = $conn->prepare("SELECT schedule FROM gym_classes WHERE id = ? AND gym_id = ?");
        $classStmt->execute([$class_id, $gym_id]);
        $classData = $classStmt->fetch(PDO::FETCH_ASSOC);
        if ($classData && $classData['schedule']) {
            $schedule = json_decode($classData['schedule'], true);
            // Extract time from schedule based on selected date
            $dayOfWeek = strtolower(date('l', strtotime($start_date)));
            if (isset($schedule[$dayOfWeek])) {
                $start_time = $schedule[$dayOfWeek]['start_time'] ?? null;
            }
        }
    }
} elseif ($activity_type === 'personal_training') {
    $start_time = filter_input(INPUT_POST, 'training_time', FILTER_SANITIZE_STRING);
    $training_focus = filter_input(INPUT_POST, 'training_focus', FILTER_SANITIZE_STRING);
    if ($training_focus) {
        $notes = "Training focus: $training_focus" . ($notes ? " | $notes" : "");
    }
}

// Process days of week for weekly recurring
$days = [];
if ($recurring === 'weekly' && isset($_POST['days_of_week'])) {
    $days = $_POST['days_of_week'];
    // Convert numeric days to lowercase day names
    $dayMap = [
        '1' => 'monday',
        '2' => 'tuesday',
        '3' => 'wednesday',
        '4' => 'thursday',
        '5' => 'friday',
        '6' => 'saturday',
        '7' => 'sunday'
    ];
    $days = array_map(function($day) use ($dayMap) {
        return $dayMap[$day] ?? $day;
    }, $days);
}

// Validate required fields
$errors = [];
if (!$membership_id) $errors[] = "Membership is required";
if (!$gym_id) $errors[] = "Gym is required";
if (!$start_date) $errors[] = "Start date is required";
if (!$end_date) $errors[] = "End date is required";
if (!$start_time) $errors[] = "Time slot is required";
if (!$activity_type) $errors[] = "Activity type is required";

// Validate date range
if ($start_date && $end_date) {
    $start = new DateTime($start_date);
    $end = new DateTime($end_date);
    $today = new DateTime();
    $today->setTime(0, 0, 0);
    $maxDate = clone $today;
    $maxDate->modify('+90 days');
    
    if ($start < $today) {
        $errors[] = "Start date cannot be in the past";
    }
    if ($end < $start) {
        $errors[] = "End date cannot be before start date";
    }
    if ($end > $maxDate) {
        $errors[] = "Cannot schedule more than 90 days in advance";
    }
}

// Validate recurring options
if ($recurring === 'weekly' && empty($days)) {
    $errors[] = "Please select at least one day for weekly scheduling";
}

// If there are validation errors, redirect back with error message
if (!empty($errors)) {
    $_SESSION['error'] = implode(". ", $errors);
    header('Location: schedule.php');
    exit();
}

function getExactDaysBetween($start_date, $end_date)
{
    $start = new DateTime($start_date);
    $end = new DateTime($end_date);
    $interval = $start->diff($end);
    return $interval->days + 1;
}

// Calculate total days for selected period
$totalDays = getExactDaysBetween($start_date, $end_date);

try {
    $conn->beginTransaction();

    // Check time slot occupancy for the start date
    $occupancyCheck = $conn->prepare("
        SELECT COUNT(*) as current_occupancy 
        FROM schedules 
        WHERE gym_id = ? 
        AND start_date = ? 
        AND start_time = ?
    ");
    $occupancyCheck->execute([$gym_id, $start_date, $start_time]);
    $currentOccupancy = $occupancyCheck->fetch(PDO::FETCH_ASSOC)['current_occupancy'];

    // Get gym capacity
    $capacityStmt = $conn->prepare("SELECT capacity FROM gyms WHERE gym_id = ?");
    $capacityStmt->execute([$gym_id]);
    $gymCapacity = $capacityStmt->fetchColumn() ?: 50; // Default to 50 if not set

    if ($currentOccupancy >= $gymCapacity) {
        throw new Exception("Selected time slot is full. Maximum capacity ({$gymCapacity}) reached.");
    }

    // Check if user already has a booking for the selected date at this gym
    $existingBookingCheck = $conn->prepare("
        SELECT COUNT(*) 
        FROM schedules 
        WHERE user_id = ? 
        AND gym_id = ? 
        AND start_date = ?
        AND status != 'cancelled'
    ");
    $existingBookingCheck->execute([$user_id, $gym_id, $start_date]);
    if ($existingBookingCheck->fetchColumn() > 0) {
        throw new Exception("You already have a booking at this gym for " . date('d M Y', strtotime($start_date)));
    }

    // Get membership details
    $stmt = $conn->prepare("
        SELECT 
            um.*,
            gmp.tier,
            gmp.price as plan_price,
            gmp.duration,
            g.name as gym_name,
            CASE 
                WHEN gmp.price BETWEEN fbc.price_range_start AND fbc.price_range_end THEN 'fee_based'
                ELSE 'tier_based'
            END as cut_type,
            CASE 
                WHEN gmp.price BETWEEN fbc.price_range_start AND fbc.price_range_end THEN fbc.admin_cut_percentage
                ELSE coc.admin_cut_percentage
            END as admin_cut_percentage,
            CASE 
                WHEN gmp.price BETWEEN fbc.price_range_start AND fbc.price_range_end THEN fbc.gym_cut_percentage
                ELSE coc.gym_owner_cut_percentage
            END as gym_owner_cut_percentage,
            u.balance as user_balance,
            (SELECT COUNT(*) FROM schedules WHERE membership_id = um.id) as used_days
        FROM user_memberships um
        JOIN gym_membership_plans gmp ON um.plan_id = gmp.plan_id
        JOIN gyms g ON um.gym_id = g.gym_id
        LEFT JOIN cut_off_chart coc ON gmp.tier = coc.tier AND gmp.duration = coc.duration
        LEFT JOIN fee_based_cuts fbc ON gmp.price BETWEEN fbc.price_range_start AND fbc.price_range_end
        JOIN users u ON um.user_id = u.id
        WHERE um.id = ? AND um.user_id = ?
        AND um.status = 'active'
        AND um.payment_status = 'paid'
    ");

    $stmt->execute([$membership_id, $user_id]);
    $membership = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$membership) {
        throw new Exception("Invalid membership selected");
    }

    // Check if membership is for the selected gym
    if ($membership['gym_id'] != $gym_id) {
        throw new Exception("The selected membership is not valid for this gym");
    }

    // Check if this is a daily pass and how many days it's valid for
    $isDailyPass = (strtolower($membership['duration']) == 'daily');
    $purchasedDays = 1; // Default for single day pass
    
    // If this is a multi-day daily pass, get the purchased days
    if ($isDailyPass) {
        // Check if there's a specific field for purchased days or calculate from dates
        $purchasedDays = getExactDaysBetween($membership['start_date'], $membership['end_date']);
        
        // Check how many days have already been used
        $usedDays = $membership['used_days'];
        
        // Calculate remaining days
        $remainingDays = $purchasedDays - $usedDays;
        
        // Check if we have enough days left in the pass
        if ($remainingDays < $totalDays) {
            throw new Exception("Your daily pass only has {$remainingDays} day(s) remaining, but you're trying to schedule for {$totalDays} day(s).");
        }
    }

    // Calculate membership duration in days
    $membership_duration_days = 0;
    if ($membership['duration'] == 'Daily' || $membership['duration'] == 'daily' || $membership['duration'] == 1) {
        $membership_duration_days = $purchasedDays; // Use actual purchased days for daily pass
    } elseif ($membership['duration'] == 'Weekly' || $membership['duration'] == 'weekly' || $membership['duration'] == 7) {
        $membership_duration_days = 7;
    } elseif ($membership['duration'] == 'Monthly' || $membership['duration'] == 'monthly' || $membership['duration'] == 30) {
        $membership_duration_days = 30;
    } elseif ($membership['duration'] == 'Quarterly' || $membership['duration'] == 'quarterly' || $membership['duration'] == 3) {
        $membership_duration_days = 90;
    } elseif ($membership['duration'] == 'Half Yearly' || $membership['duration'] == 'half-yearly' || $membership['duration'] == 6) {
        $membership_duration_days = 180;
    } elseif ($membership['duration'] == 'Yearly' || $membership['duration'] == 'yearly' || $membership['duration'] == 12) {
        $membership_duration_days = 365;
    } else {
        // Try to convert numeric duration (assuming it's in months)
        $numeric_duration = intval($membership['duration']);
        if ($numeric_duration > 0) {
            $membership_duration_days = $numeric_duration * 30;
        } else {
            // Default to 30 days if we can't determine the duration
            $membership_duration_days = 30;
            error_log("Could not determine membership duration from value: " . $membership['duration']);
        }
    }

    // Calculate revenue distribution
    $total_plan_price = $membership['plan_price'];
    $admin_cut_percentage = $membership['admin_cut_percentage'];
    $gym_cut_percentage = $membership['gym_owner_cut_percentage'];

    // Calculate gym cut and admin cut
    $gym_cut_total = floor(($total_plan_price * $gym_cut_percentage) / 100);
    $admin_cut_total = $total_plan_price - $gym_cut_total;

    // Calculate daily rates
    if ($membership['duration'] == 'Daily' || $membership['duration'] == 'daily' || $membership['duration'] == 1) {
        // For daily passes, use the full daily rate without dividing
        $daily_gym_rate = floor(($total_plan_price * $gym_cut_percentage) / 100);
        $daily_admin_rate = $total_plan_price - $daily_gym_rate;
    } else {
        // For other membership types, calculate prorated daily rates
        $daily_gym_rate = ($gym_cut_total / $membership_duration_days);
        $daily_admin_rate = ($admin_cut_total / $membership_duration_days);
        
        // Apply the rounding logic
        $selected_days_total = $daily_gym_rate * $totalDays;
        if (floatval($selected_days_total) > floatval($gym_cut_total)) {
            $daily_gym_rate = (floatval($daily_gym_rate) - 0.01);
            $selected_days_total = $daily_gym_rate * $totalDays;
        }
        
        // Format to 2 decimal places
        $daily_gym_rate = floor($daily_gym_rate * 100) / 100;
        $daily_admin_rate = floor($daily_admin_rate * 100) / 100;
    }

    // Determine dates to schedule based on recurring option
    $dates = [];
    
    if ($recurring == 'none') {
        // Single day schedule
        $dates[] = $start_date;
    } else if ($recurring == 'daily') {
        // Daily schedule from start to end date
        $current = new DateTime($start_date);
        $end = new DateTime($end_date);
        
        while ($current <= $end) {
            $dates[] = $current->format('Y-m-d');
            $current->modify('+1 day');
        }
    } else if ($recurring == 'weekly') {
        // Weekly schedule on selected days
        if (empty($days)) {
            throw new Exception("Please select at least one day for weekly scheduling");
        }
        
        $current = new DateTime($start_date);
        $end = new DateTime($end_date);
        
        while ($current <= $end) {
            $dayOfWeek = strtolower($current->format('l'));
            if (in_array($dayOfWeek, $days)) {
                $dates[] = $current->format('Y-m-d');
            }
            $current->modify('+1 day');
        }
    } else if ($recurring == 'monthly') {
                // Monthly schedule on the same day of month
                $current = new DateTime($start_date);
                $end = new DateTime($end_date);
                $dayOfMonth = $current->format('d');
                
                while ($current <= $end) {
                    if ($current->format('d') == $dayOfMonth) {
                        $dates[] = $current->format('Y-m-d');
                    }
                    $current->modify('+1 day');
                }
            }
            
            // Check if we have enough days in daily pass for all scheduled dates
            if ($isDailyPass && count($dates) > $remainingDays) {
                throw new Exception("Your daily pass only has {$remainingDays} day(s) remaining, but you're trying to schedule for " . count($dates) . " day(s).");
            }
            
            // Check if user has enough balance for any fees
            $userBalance = $membership['user_balance'];
            
            // Store scheduled IDs for revenue tracking
            $scheduledIds = [];
            
            // Create schedule entries for each date
            foreach ($dates as $date) {
                // Check if user already has a booking for this date at this gym
                $existingBookingCheck->execute([$user_id, $gym_id, $date]);
                if ($existingBookingCheck->fetchColumn() > 0) {
                    // Skip this date if already booked
                    continue;
                }
                
                // Insert schedule
                $insertStmt = $conn->prepare("
                    INSERT INTO schedules (
                        user_id, gym_id, membership_id, activity_type, 
                        start_date, end_date, start_time, status, 
                        notes, created_at, recurring, daily_rate, cut_type
                    ) VALUES (
                        ?, ?, ?, ?, 
                        ?, ?, ?, 'scheduled', 
                        ?, NOW(), ?, ?, ?
                    )
                ");
                
                $insertStmt->execute([
                    $user_id, $gym_id, $membership_id, $activity_type,
                    $date, $date, $start_time,
                    $notes, $recurring, $daily_gym_rate, $membership['cut_type']
                ]);
                
                $scheduleId = $conn->lastInsertId();
                $scheduledIds[] = $scheduleId;
                
                // Log the schedule creation
                $logStmt = $conn->prepare("
                    INSERT INTO schedule_logs (
                        user_id, schedule_id, action_type, new_gym_id, new_time, notes, created_at
                    ) VALUES (
                        ?, ?, 'create', ?, ?, ?, NOW()
                    )
                ");
                
                $logStmt->execute([
                    $user_id, $scheduleId, $gym_id, $start_time, "Schedule created"
                ]);
                
                // Update gym occupancy for this time slot
                $updateOccupancyStmt = $conn->prepare("
                    UPDATE gyms 
                    SET current_occupancy = current_occupancy + 1 
                    WHERE gym_id = ?
                ");
                $updateOccupancyStmt->execute([$gym_id]);
                
                // Create revenue entry for this schedule
                $revenueStmt = $conn->prepare("
                    INSERT INTO gym_revenue (
                        gym_id, date, amount, admin_cut, source_type, 
                        schedule_id, created_at, notes, daily_rate, cut_type, user_id
                    ) VALUES (
                        ?, ?, ?, ?, 'membership', 
                        ?, NOW(), ?, ?, ?, ?
                    )
                ");
                
                $revenueStmt->execute([
                    $gym_id, $date, $daily_gym_rate, $daily_admin_rate,
                    $scheduleId, "Revenue from schedule ID: {$scheduleId}", $daily_gym_rate, $membership['cut_type'], $user_id
                ]);
            }
            
            // If this is a daily pass, update the used days count
            if ($isDailyPass) {
                // We only need to update if at least one schedule was created
                if (!empty($scheduledIds)) {
                    // No need to update a counter in the database, as we're counting actual schedule entries
                }
            }
            
            // Create notification for gym owner
            $notificationStmt = $conn->prepare("
                INSERT INTO gym_notifications (
                    gym_id, title, message, created_at
                ) VALUES (
                    ?, ?, ?, NOW()
                )
            ");
            
            $scheduledDatesText = count($dates) > 1 
                ? "multiple dates starting " . date('M d, Y', strtotime($dates[0])) 
                : date('M d, Y', strtotime($dates[0]));
            
            $notificationStmt->execute([
                $gym_id,
                "New Schedule",
                "A new " . ucfirst($activity_type) . " has been scheduled for " . $scheduledDatesText . " at " . date('h:i A', strtotime($start_time))
            ]);
            
            // Create notification for user
            $userNotificationStmt = $conn->prepare("
                INSERT INTO notifications (
                    user_id, type, message, title, created_at, gym_id
                ) VALUES (
                    ?, 'schedule', ?, ?, NOW(), ?
                )
            ");
            
            $userNotificationStmt->execute([
                $user_id,
                "Your " . ucfirst($activity_type) . " has been scheduled for " . $scheduledDatesText . " at " . date('h:i A', strtotime($start_time)),
                "Schedule Confirmed",
                $gym_id
            ]);
            
            // Commit transaction
            $conn->commit();
            
            // Set success message
            $_SESSION['success'] = count($scheduledIds) . " schedule(s) created successfully!";
            
            // Redirect to dashboard
            header('Location: dashboard.php');
            exit();
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollBack();
            
            // Log the error
            error_log("Schedule creation error: " . $e->getMessage());
            
            // Set error message
            $_SESSION['error'] = $e->getMessage();
            
            // Redirect back to scheduling page
            header('Location: schedule.php?membership_id=' . $membership_id);
            exit();
        }
        
