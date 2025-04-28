<?php
require_once '../config/database.php';
require_once '../includes/PaymentGateway.php';

// No session needed as this will run via cron
$db = new GymDatabase();
$conn = $db->getConnection();

// Log function
function logActivity($conn, $action, $details) {
    $stmt = $conn->prepare("
        INSERT INTO activity_logs (
            user_type, action, details, ip_address, user_agent
        ) VALUES (?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        'system',
        $action,
        $details,
        'SYSTEM',
        'Auto Payout Script'
    ]);
}

// Get auto-payout settings
function getSettings($conn) {
    $settings = [
        'auto_payout_enabled' => 0,
        'auto_payout_min_hours' => 24,
        'auto_payout_max_amount' => 10000,
        'auto_payout_payment_gateway' => 'razorpay'
    ];
    
    try {
        $stmt = $conn->prepare("
            SELECT setting_key, setting_value
            FROM system_settings
            WHERE setting_key LIKE 'auto_payout_%'
        ");
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($results as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    } catch (PDOException $e) {
        logActivity($conn, 'auto_payout_error', "Error loading settings: " . $e->getMessage());
    }
    
    return $settings;
}

// Main execution
try {
    // Get settings
    $settings = getSettings($conn);
    
    // Check if auto-payout is enabled
    if (!$settings['auto_payout_enabled']) {
        echo "Auto-payout is disabled in settings.\n";
        exit;
    }
    
    // Initialize payment gateway
    $gateway = new PaymentGateway($settings['auto_payout_payment_gateway'], $conn);
    
    $conn->beginTransaction();
    
    // Get pending withdrawals that are eligible for auto-processing
    $stmt = $conn->prepare("
        SELECT w.*, g.name as gym_name, g.gym_id, u.username as owner_name, u.email as owner_email,
               pm.method_type, pm.account_name, pm.bank_name, pm.account_number, pm.ifsc_code, pm.upi_id
        FROM withdrawals w
        JOIN gyms g ON w.gym_id = g.gym_id
        JOIN users u ON g.owner_id = u.id
        LEFT JOIN payment_methods pm ON w.payment_method_id = pm.id
        WHERE w.status = 'pending'
        AND w.created_at < DATE_SUB(NOW(), INTERVAL 1 MINUTE) 
        AND w.amount <= ?
    ");
    $stmt->execute([
        $settings['auto_payout_min_hours'],
        $settings['auto_payout_max_amount']
    ]);
    $withdrawals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $processed = 0;
    $failed = 0;
    
    foreach ($withdrawals as $withdrawal) {
        // Process the payout via the payment gateway
        $result = $gateway->processPayout($withdrawal);
        
        if ($result['success']) {
            // Update withdrawal status
            $stmt = $conn->prepare("
                UPDATE withdrawals 
                SET status = 'completed', 
                    transaction_id = ?, 
                    notes = ?, 
                    processed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $result['transaction_id'], 
                $result['message'], 
                $withdrawal['id']
            ]);
            
            // Log the activity
            $details = "Auto-processed payout of ₹" . number_format($withdrawal['amount'], 2) . 
                       " for gym: " . $withdrawal['gym_name'] . " (ID: " . $withdrawal['gym_id'] . 
                       "). Transaction ID: " . $result['transaction_id'];
            
            logActivity($conn, 'auto_process_payout', $details);
            
            // Send notification to gym owner
            $stmt = $conn->prepare("
                INSERT INTO gym_notifications (
                    gym_id, title, message, created_at
                ) VALUES (?, ?, ?, NOW())
            ");
            
            $notificationTitle = "Payout Processed";
            $notificationMessage = "Your withdrawal request of ₹" . number_format($withdrawal['amount'], 2) . 
                                  " has been automatically processed. Transaction ID: " . $result['transaction_id'];
            
            $stmt->execute([
                $withdrawal['gym_id'],
                $notificationTitle,
                $notificationMessage
            ]);
            
            $processed++;
        } else {
            // Mark as failed if payment gateway returns error
            $stmt = $conn->prepare("
                UPDATE withdrawals 
                SET status = 'failed', 
                    notes = ?, 
                    processed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                'Automatic processing failed: ' . $result['message'], 
                $withdrawal['id']
            ]);
            
            // Return the amount to gym balance
            $stmt = $conn->prepare("
                UPDATE gyms 
                SET balance = balance + ? 
                WHERE gym_id = ?
            ");
            $stmt->execute([$withdrawal['amount'], $withdrawal['gym_id']]);
            
            // Log the failure
            $details = "Failed to auto-process payout of ₹" . number_format($withdrawal['amount'], 2) . 
                       " for gym: " . $withdrawal['gym_name'] . " (ID: " . $withdrawal['gym_id'] . 
                       "). Reason: " . $result['message'];
            
            logActivity($conn, 'auto_process_payout_failed', $details);
            
            // Send notification to gym owner
            $stmt = $conn->prepare("
                INSERT INTO gym_notifications (
                    gym_id, title, message, created_at
                ) VALUES (?, ?, ?, NOW())
            ");
            
            $notificationTitle = "Payout Processing Failed";
            $notificationMessage = "Your withdrawal request of ₹" . number_format($withdrawal['amount'], 2) . 
                                  " could not be processed automatically. Reason: " . $result['message'] . 
                                  ". The amount has been returned to your gym balance.";
            
            $stmt->execute([
                $withdrawal['gym_id'],
                $notificationTitle,
                $notificationMessage
            ]);
            
            $failed++;
        }
    }
    
    $conn->commit();
    
    // Log summary
    $summary = "Auto payout run completed. Processed: $processed, Failed: $failed, Total: " . count($withdrawals);
    logActivity($conn, 'auto_payout_summary', $summary);
    
    echo $summary . "\n";
    
} catch (PDOException $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    $errorMsg = "Database error: " . $e->getMessage();
    logActivity($conn, 'auto_payout_error', $errorMsg);
    echo $errorMsg . "\n";
} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    $errorMsg = "Error: " . $e->getMessage();
    logActivity($conn, 'auto_payout_error', $errorMsg);
    echo $errorMsg . "\n";
}
