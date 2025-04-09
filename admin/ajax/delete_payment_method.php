<?php
require_once '../../config/database.php';
session_start();

// Check if user is logged in as admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Initialize database connection
$db = new GymDatabase();
$conn = $db->getConnection();

// Check if required data is provided
if (!isset($_POST['owner_id']) || !isset($_POST['payment_id'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required data']);
    exit();
}

$owner_id = (int)$_POST['owner_id'];
$payment_id = (int)$_POST['payment_id'];

try {
    // Check if payment method exists and belongs to the owner
    $stmt = $conn->prepare("SELECT * FROM payment_methods WHERE id = ? AND owner_id = ?");
    $stmt->execute([$payment_id, $owner_id]);
    $payment_method = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$payment_method) {
        echo json_encode(['success' => false, 'message' => 'Payment method not found or does not belong to this owner']);
        exit();
    }
    
    // Check if this is the only primary payment method
    if ($payment_method['is_primary']) {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM payment_methods WHERE owner_id = ? AND is_primary = 1");
        $stmt->execute([$owner_id]);
        $primary_count = $stmt->fetchColumn();
        
        if ($primary_count <= 1) {
            // Check if there are other payment methods that can be set as primary
            $stmt = $conn->prepare("SELECT COUNT(*) FROM payment_methods WHERE owner_id = ? AND id != ?");
            $stmt->execute([$owner_id, $payment_id]);
            $other_methods = $stmt->fetchColumn();
            
            if ($other_methods > 0) {
                // Set another payment method as primary
                $stmt = $conn->prepare("
                    UPDATE payment_methods 
                    SET is_primary = 1 
                    WHERE owner_id = ? AND id != ? 
                    LIMIT 1
                ");
                $stmt->execute([$owner_id, $payment_id]);
            }
        }
    }
    
    // Delete the payment method
    $stmt = $conn->prepare("DELETE FROM payment_methods WHERE id = ? AND owner_id = ?");
    $stmt->execute([$payment_id, $owner_id]);
    
    // Log the activity
    $log_query = "INSERT INTO activity_logs (user_id, user_type, action, details, ip_address, user_agent) 
                 VALUES (:user_id, 'admin', 'delete_payment_method', :details, :ip, :user_agent)";
    $log_stmt = $conn->prepare($log_query);
    $log_stmt->execute([
        ':user_id' => $_SESSION['user_id'],
        ':details' => "Deleted payment method ID: $payment_id for owner ID: $owner_id",
        ':ip' => $_SERVER['REMOTE_ADDR'],
        ':user_agent' => $_SERVER['HTTP_USER_AGENT']
    ]);
    
    echo json_encode(['success' => true, 'message' => 'Payment method deleted successfully']);
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
