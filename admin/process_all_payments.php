<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in as admin
if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Check if it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$db = new GymDatabase();
$conn = $db->getConnection();

try {
    $conn->beginTransaction();
    
    // Get all pending withdrawals
    $stmt = $conn->prepare("
        SELECT w.*, g.name as gym_name
        FROM withdrawals w
        JOIN gyms g ON w.gym_id = g.gym_id
        WHERE w.status = 'pending'
        ORDER BY w.created_at ASC
    ");
    $stmt->execute();
    $withdrawals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($withdrawals)) {
        $conn->rollBack();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'No pending withdrawals found']);
        exit();
    }
    
    $processed = 0;
    
    foreach ($withdrawals as $withdrawal) {
        // Generate a transaction ID
        $transaction_id = 'BATCH-' . date('YmdHis') . '-' . $withdrawal['id'];
        
        // Update withdrawal status
        $stmt = $conn->prepare("
            UPDATE withdrawals 
            SET status = 'completed', 
                transaction_id = ?, 
                notes = 'Batch processed by admin', 
                processed_at = NOW(),
                admin_id = ?
            WHERE id = ?
        ");
        $stmt->execute([$transaction_id, $_SESSION['admin_id'], $withdrawal['id']]);
        
        // Log the activity
        $stmt = $conn->prepare("
            INSERT INTO activity_logs (
                user_id, user_type, action, details, ip_address, user_agent
            ) VALUES (?, 'admin', 'batch_process_withdrawal', ?, ?, ?)
        ");
        
        $details = "Batch processed payout of ₹" . number_format($withdrawal['amount'], 2) . 
                   " for gym: " . $withdrawal['gym_name'] . " (ID: " . $withdrawal['gym_id'] . ")";
        
        $stmt->execute([
            $_SESSION['admin_id'],
            $details,
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT']
        ]);
        
        // Send notification to gym owner
        $stmt = $conn->prepare("
            INSERT INTO gym_notifications (
                gym_id, title, message, created_at
            ) VALUES (?, ?, ?, NOW())
        ");
        
        $notificationTitle = "Payout Processed";
        $notificationMessage = "Your withdrawal request of ₹" . number_format($withdrawal['amount'], 2) . 
                              " has been processed. Transaction ID: " . $transaction_id;
        
        $stmt->execute([
            $withdrawal['gym_id'],
            $notificationTitle,
            $notificationMessage
        ]);
        
        $processed++;
    }
    
    $conn->commit();
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true, 
        'message' => "Successfully processed $processed withdrawals",
        'processed' => $processed
    ]);
    
} catch (PDOException $e) {
    $conn->rollBack();
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false, 
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}

