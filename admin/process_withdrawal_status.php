<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in as admin
if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Check if it's a POST request with JSON content
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

// Get JSON data
$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!$data || !isset($data['withdrawal_id']) || !isset($data['status'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request data']);
    exit();
}

$withdrawal_id = (int)$data['withdrawal_id'];
$status = $data['status'];

// Validate status
if (!in_array($status, ['completed', 'failed'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid status value']);
    exit();
}

$db = new GymDatabase();
$conn = $db->getConnection();

try {
    $conn->beginTransaction();
    
    // Get withdrawal details
    $stmt = $conn->prepare("
        SELECT w.*, g.name as gym_name, g.balance
        FROM withdrawals w
        JOIN gyms g ON w.gym_id = g.gym_id
        WHERE w.id = ? AND w.status = 'pending'
    ");
    $stmt->execute([$withdrawal_id]);
    $withdrawal = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$withdrawal) {
        $conn->rollBack();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Withdrawal not found or already processed']);
        exit();
    }
    
    if ($status === 'completed') {
        // Generate a transaction ID
        $transaction_id = 'TXN-' . date('YmdHis') . '-' . $withdrawal_id;
        
        // Update withdrawal status
        $stmt = $conn->prepare("
            UPDATE withdrawals 
            SET status = 'completed', 
                transaction_id = ?, 
                processed_at = NOW(),
                admin_id = ?
            WHERE id = ?
        ");
        $stmt->execute([$transaction_id, $_SESSION['admin_id'], $withdrawal_id]);
        
        // Log the activity
        $stmt = $conn->prepare("
            INSERT INTO activity_logs (
                user_id, user_type, action, details, ip_address, user_agent
            ) VALUES (?, 'admin', 'process_withdrawal', ?, ?, ?)
        ");
        
        $details = "Processed payout of ₹" . number_format($withdrawal['amount'], 2) . 
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
        
        $message = "Withdrawal successfully processed";
    } else {
        // Update withdrawal status
        $stmt = $conn->prepare("
            UPDATE withdrawals 
            SET status = 'failed', 
                notes = 'Rejected by admin', 
                processed_at = NOW(),
                admin_id = ?
            WHERE id = ?
        ");
        $stmt->execute([$_SESSION['admin_id'], $withdrawal_id]);
        
        // Return the amount to gym balance
        $stmt = $conn->prepare("
            UPDATE gyms 
            SET balance = balance + ? 
            WHERE gym_id = ?
        ");
        $stmt->execute([$withdrawal['amount'], $withdrawal['gym_id']]);
        
        // Log the activity
        $stmt = $conn->prepare("
            INSERT INTO activity_logs (
                user_id, user_type, action, details, ip_address, user_agent
            ) VALUES (?, 'admin', 'reject_withdrawal', ?, ?, ?)
        ");
        
        $details = "Rejected payout of ₹" . number_format($withdrawal['amount'], 2) . 
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
        
        $notificationTitle = "Payout Request Rejected";
        $notificationMessage = "Your withdrawal request of ₹" . number_format($withdrawal['amount'], 2) . 
                              " has been rejected. The amount has been returned to your gym balance.";
        
        $stmt->execute([
            $withdrawal['gym_id'],
            $notificationTitle,
            $notificationMessage
        ]);
        
        $message = "Withdrawal rejected and amount returned to gym balance";
    }
    
    $conn->commit();
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => $message]);
    
} catch (PDOException $e) {
    $conn->rollBack();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
