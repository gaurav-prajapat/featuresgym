<?php
/**
 * Process schedules automatically based on gym settings
 * 
 * @param int $gym_id The ID of the gym
 * @return array Results of processing with counts of accepted and cancelled schedules
 */
function processSchedulesAutomatically($gym_id) {
    $db = new GymDatabase();
    $conn = $db->getConnection();
    
    $accepted = 0;
    $cancelled = 0;
    
    try {
        // Get auto-processing settings for this gym
        $settingsStmt = $conn->prepare("
            SELECT setting_value 
            FROM system_settings 
            WHERE setting_key = ? 
            AND setting_group = ?
        ");
        $settingsStmt->execute(['auto_process_settings', 'gym_' . $gym_id]);
        $settingsJson = $settingsStmt->fetchColumn();
        
        if (!$settingsJson) {
            // Default settings if none found
            $settings = [
                'auto_accept_enabled' => 0,
                'auto_accept_conditions' => [],
                'auto_accept_occupancy_threshold' => 50,
                'auto_cancel_enabled' => 0,
                'auto_cancel_conditions' => [],
                'auto_cancel_occupancy_threshold' => 95,
                'auto_cancel_reason' => 'Due to high demand, we cannot accommodate your booking at this time.'
            ];
        } else {
            $settings = json_decode($settingsJson, true);
        }
        
        // Check if auto-accept is enabled
        if (isset($settings['auto_accept_enabled']) && $settings['auto_accept_enabled'] == 1) {
            $conditions = $settings['auto_accept_conditions'] ?? [];
            $occupancyThreshold = (int)($settings['auto_accept_occupancy_threshold'] ?? 50);
            
            // Get gym capacity
            $capacityStmt = $conn->prepare("SELECT capacity FROM gyms WHERE gym_id = ?");
            $capacityStmt->execute([$gym_id]);
            $capacity = $capacityStmt->fetchColumn() ?: 50; // Default to 50 if not set
            
            // Get pending schedules
            $pendingStmt = $conn->prepare("
                SELECT s.id, s.user_id, s.start_date, s.start_time, s.gym_id, s.activity_type,
                       u.email, u.username, g.name as gym_name,
                       (SELECT COUNT(*) FROM schedules 
                        WHERE gym_id = s.gym_id 
                        AND start_date = s.start_date 
                        AND start_time = s.start_time) as current_occupancy,
                       (SELECT COUNT(*) FROM user_memberships 
                        WHERE user_id = s.user_id 
                        AND gym_id = s.gym_id 
                        AND status = 'active' 
                        AND payment_status = 'paid'
                        AND CURRENT_DATE BETWEEN start_date AND end_date) as is_member,
                       HOUR(s.start_time) as hour_of_day
                FROM schedules s
                JOIN users u ON s.user_id = u.id
                JOIN gyms g ON s.gym_id = g.gym_id
                WHERE s.gym_id = ? 
                AND s.status = 'scheduled'
            ");
            
            $pendingStmt->execute([$gym_id]);
            $pendingSchedules = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($pendingSchedules as $schedule) {
                $shouldAccept = false;
                
                // Check conditions
                if (in_array('members_only', $conditions) && $schedule['is_member'] > 0) {
                    $shouldAccept = true;
                }
                
                if (in_array('off_peak', $conditions) && $schedule['hour_of_day'] >= 10 && $schedule['hour_of_day'] <= 16) {
                    $shouldAccept = true;
                }
                
                if (in_array('low_occupancy', $conditions)) {
                    $occupancyPercentage = ($schedule['current_occupancy'] / $capacity) * 100;
                    if ($occupancyPercentage <= $occupancyThreshold) {
                        $shouldAccept = true;
                    }
                }
                
                if ($shouldAccept) {
                    // Accept the schedule
                    $conn->beginTransaction();
                    
                    $updateStmt = $conn->prepare("
                        UPDATE schedules 
                        SET status = 'confirmed'
                        WHERE id = ?
                    ");
                    $updateStmt->execute([$schedule['id']]);
                    
                    // Create notification for user
                    $notifyStmt = $conn->prepare("
                        INSERT INTO notifications (user_id, type, title, message, related_id, gym_id, is_read)
                        VALUES (?, 'booking', 'Schedule Confirmed', ?, ?, ?, 0)
                    ");
                    
                    $message = "Your booking at {$schedule['gym_name']} on " . date('F j, Y', strtotime($schedule['start_date'])) . 
                               " at " . date('g:i A', strtotime($schedule['start_time'])) . " has been automatically confirmed.";
                    
                    $notifyStmt->execute([
                        $schedule['user_id'],
                        $message,
                        $schedule['id'],
                        $gym_id
                    ]);
                    
                    // Log the action
                    $logStmt = $conn->prepare("
                        INSERT INTO schedule_logs (user_id, schedule_id, action_type, notes)
                        VALUES (?, ?, 'update', ?)
                    ");
                    
                    $logStmt->execute([
                        0, // System user ID
                        $schedule['id'],
                        'Schedule automatically confirmed by system'
                    ]);
                    
                    // Send email notification if email service is available
                    if (file_exists('../includes/EmailService.php')) {
                        require_once '../includes/EmailService.php';
                        
                        $emailService = new EmailService($conn);
                        $subject = "Your Booking at {$schedule['gym_name']} is Confirmed";
                        $body = "
                            <p>Hello {$schedule['username']},</p>
                            <p>Your booking at {$schedule['gym_name']} has been automatically confirmed.</p>
                            <p><strong>Details:</strong></p>
                            <ul>
                                <li>Date: " . date('F j, Y', strtotime($schedule['start_date'])) . "</li>
                                <li>Time: " . date('g:i A', strtotime($schedule['start_time'])) . "</li>
                                <li>Activity: " . ucfirst(str_replace('_', ' ', $schedule['activity_type'])) . "</li>
                            </ul>
                            <p>We look forward to seeing you!</p>
                        ";
                        
                        $emailService->sendEmail($schedule['email'], $subject, $body);
                    }
                    
                    $conn->commit();
                    $accepted++;
                }
            }
        }
        
        // Check if auto-cancel is enabled
        if (isset($settings['auto_cancel_enabled']) && $settings['auto_cancel_enabled'] == 1) {
            $conditions = $settings['auto_cancel_conditions'] ?? [];
            $occupancyThreshold = (int)($settings['auto_cancel_occupancy_threshold'] ?? 95);
            $cancelReason = $settings['auto_cancel_reason'] ?? 'Due to high demand, we cannot accommodate your booking at this time.';
            
            // Get gym capacity
            $capacityStmt = $conn->prepare("SELECT capacity FROM gyms WHERE gym_id = ?");
            $capacityStmt->execute([$gym_id]);
            $capacity = $capacityStmt->fetchColumn() ?: 50; // Default to 50 if not set
            
            // Get pending schedules
            $pendingStmt = $conn->prepare("
                SELECT s.id, s.user_id, s.start_date, s.start_time, s.gym_id, s.activity_type,
                       u.email, u.username, g.name as gym_name,
                       (SELECT COUNT(*) FROM schedules 
                        WHERE gym_id = s.gym_id 
                        AND start_date = s.start_date 
                        AND start_time = s.start_time) as current_occupancy,
                       (SELECT COUNT(*) FROM user_memberships 
                        WHERE user_id = s.user_id 
                        AND gym_id = s.gym_id 
                        AND status = 'active' 
                        AND payment_status = 'paid'
                        AND CURRENT_DATE BETWEEN start_date AND end_date) as is_member,
                       (SELECT COUNT(*) FROM gym_maintenance 
                        WHERE gym_id = s.gym_id 
                        AND maintenance_date = s.start_date) as has_maintenance,
                       HOUR(s.start_time) as hour_of_day
                FROM schedules s
                JOIN users u ON s.user_id = u.id
                JOIN gyms g ON s.gym_id = g.gym_id
                WHERE s.gym_id = ? 
                AND s.status = 'scheduled'
            ");
            
            $pendingStmt->execute([$gym_id]);
            $pendingSchedules = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($pendingSchedules as $schedule) {
                $shouldCancel = false;
                
                // Check conditions
                if (in_array('high_occupancy', $conditions)) {
                    $occupancyPercentage = ($schedule['current_occupancy'] / $capacity) * 100;
                    if ($occupancyPercentage >= $occupancyThreshold) {
                        $shouldCancel = true;
                    }
                }
                
                if (in_array('maintenance', $conditions) && $schedule['has_maintenance'] > 0) {
                    $shouldCancel = true;
                    $cancelReason = 'Your booking has been cancelled due to scheduled maintenance.';
                }
                
                if (in_array('non_members', $conditions) && $schedule['is_member'] == 0) {
                    // Check if it's peak hours (before 10 AM or after 4 PM)
                    if ($schedule['hour_of_day'] < 10 || $schedule['hour_of_day'] > 16) {
                        $shouldCancel = true;
                        $cancelReason = 'Your booking has been cancelled as we prioritize members during peak hours.';
                    }
                }
                
                if ($shouldCancel) {
                    // Cancel the schedule
                    $conn->beginTransaction();
                    
                    $updateStmt = $conn->prepare("
                        UPDATE schedules 
                        SET status = 'cancelled', cancellation_reason = ?
                        WHERE id = ?
                    ");
                    $updateStmt->execute([$cancelReason, $schedule['id']]);
                    
                    // Create notification for user
                    $notifyStmt = $conn->prepare("
                        INSERT INTO notifications (user_id, type, title, message, related_id, gym_id, is_read)
                        VALUES (?, 'booking', 'Schedule Cancelled', ?, ?, ?, 0)
                    ");
                    
                    $message = "Your booking at {$schedule['gym_name']} on " . date('F j, Y', strtotime($schedule['start_date'])) . 
                               " at " . date('g:i A', strtotime($schedule['start_time'])) . " has been automatically cancelled.\n\nReason: {$cancelReason}";
                    
                               $notifyStmt->execute([
                                $schedule['user_id'],
                                $message,
                                $schedule['id'],
                                $gym_id
                            ]);
                            
                            // Log the action
                            $logStmt = $conn->prepare("
                                INSERT INTO schedule_logs (user_id, schedule_id, action_type, notes)
                                VALUES (?, ?, 'cancel', ?)
                            ");
                            
                            $logStmt->execute([
                                0, // System user ID
                                $schedule['id'],
                                'Schedule automatically cancelled by system. Reason: ' . $cancelReason
                            ]);
                            
                            // Send email notification if email service is available
                            if (file_exists('../includes/EmailService.php')) {
                                require_once '../includes/EmailService.php';
                                
                                $emailService = new EmailService($conn);
                                $subject = "Your Booking at {$schedule['gym_name']} has been Cancelled";
                                $body = "
                                    <p>Hello {$schedule['username']},</p>
                                    <p>We regret to inform you that your booking at {$schedule['gym_name']} has been automatically cancelled.</p>
                                    <p><strong>Details:</strong></p>
                                    <ul>
                                        <li>Date: " . date('F j, Y', strtotime($schedule['start_date'])) . "</li>
                                        <li>Time: " . date('g:i A', strtotime($schedule['start_time'])) . "</li>
                                        <li>Activity: " . ucfirst(str_replace('_', ' ', $schedule['activity_type'])) . "</li>
                                    </ul>
                                    <p><strong>Reason for cancellation:</strong> " . htmlspecialchars($cancelReason) . "</p>
                                    <p>We apologize for any inconvenience this may cause.</p>
                                ";
                                
                                $emailService->sendEmail($schedule['email'], $subject, $body);
                            }
                            
                            $conn->commit();
                            $cancelled++;
                        }
                    }
                }
                
                return [
                    'success' => true,
                    'accepted' => $accepted,
                    'cancelled' => $cancelled
                ];
            } catch (Exception $e) {
                // Rollback transaction on error
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                
                return [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'accepted' => $accepted,
                    'cancelled' => $cancelled
                ];
            }
        }
        
        /**
         * Save auto-processing settings for a gym
         * 
         * @param int $gym_id The ID of the gym
         * @param array $settings The settings to save
         * @return bool Whether the settings were saved successfully
         */
        function saveAutoProcessSettings($gym_id, $settings) {
            $db = new GymDatabase();
            $conn = $db->getConnection();
            
            try {
                // Check if settings already exist
                $checkStmt = $conn->prepare("
                    SELECT id FROM system_settings 
                    WHERE setting_key = ? 
                    AND setting_group = ?
                ");
                $checkStmt->execute(['auto_process_settings', 'gym_' . $gym_id]);
                $settingId = $checkStmt->fetchColumn();
                
                $settingsJson = json_encode($settings);
                
                if ($settingId) {
                    // Update existing settings
                    $updateStmt = $conn->prepare("
                        UPDATE system_settings 
                        SET setting_value = ?, updated_at = NOW() 
                        WHERE id = ?
                    ");
                    $updateStmt->execute([$settingsJson, $settingId]);
                } else {
                    // Insert new settings
                    $insertStmt = $conn->prepare("
                        INSERT INTO system_settings (setting_key, setting_value, setting_group) 
                        VALUES (?, ?, ?)
                    ");
                    $insertStmt->execute(['auto_process_settings', $settingsJson, 'gym_' . $gym_id]);
                }
                
                return true;
            } catch (Exception $e) {
                error_log('Error saving auto-process settings: ' . $e->getMessage());
                return false;
            }
        }
        
        /**
         * Get auto-processing settings for a gym
         * 
         * @param int $gym_id The ID of the gym
         * @return array The auto-processing settings
         */
        function getAutoProcessSettings($gym_id) {
            $db = new GymDatabase();
            $conn = $db->getConnection();
            
            try {
                $settingsStmt = $conn->prepare("
                    SELECT setting_value 
                    FROM system_settings 
                    WHERE setting_key = ? 
                    AND setting_group = ?
                ");
                $settingsStmt->execute(['auto_process_settings', 'gym_' . $gym_id]);
                $settingsJson = $settingsStmt->fetchColumn();
                
                if (!$settingsJson) {
                    // Default settings if none found
                    return [
                        'auto_accept_enabled' => 0,
                        'auto_accept_conditions' => [],
                        'auto_accept_occupancy_threshold' => 50,
                        'auto_cancel_enabled' => 0,
                        'auto_cancel_conditions' => [],
                        'auto_cancel_occupancy_threshold' => 95,
                        'auto_cancel_reason' => 'Due to high demand, we cannot accommodate your booking at this time.'
                    ];
                }
                
                return json_decode($settingsJson, true);
            } catch (Exception $e) {
                error_log('Error getting auto-process settings: ' . $e->getMessage());
                
                // Return default settings on error
                return [
                    'auto_accept_enabled' => 0,
                    'auto_accept_conditions' => [],
                    'auto_accept_occupancy_threshold' => 50,
                    'auto_cancel_enabled' => 0,
                    'auto_cancel_conditions' => [],
                    'auto_cancel_occupancy_threshold' => 95,
                    'auto_cancel_reason' => 'Due to high demand, we cannot accommodate your booking at this time.'
                ];
            }
        }
        