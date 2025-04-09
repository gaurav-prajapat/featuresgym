<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in as owner
if (!isset($_SESSION['owner_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Get JSON data from request
$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['method_id']) || !isset($data['action'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit;
}

$method_id = intval($data['method_id']);
$action = $data['action'];
$owner_id = $_SESSION['owner_id'];

// Initialize database connection
$db = new GymDatabase();
$conn = $db->getConnection();

try {
    // Verify that the payment method belongs to the logged-in owner
    $verifyStmt = $conn->prepare("SELECT id FROM payment_methods WHERE id = ? AND owner_id = ?");
    $verifyStmt->execute([$method_id, $owner_id]);
    
    if ($verifyStmt->rowCount() === 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Payment method not found or does not belong to you']);
        exit;
    }
    
    // Process the action
    if ($action === 'set_primary') {
        // Begin transaction
        $conn->beginTransaction();
        
        // First, set all payment methods for this owner to non-primary
        $resetStmt = $conn->prepare("UPDATE payment_methods SET is_primary = 0 WHERE owner_id = ?");
        $resetStmt->execute([$owner_id]);
        
        // Then, set the selected method as primary
        $updateStmt = $conn->prepare("UPDATE payment_methods SET is_primary = 1 WHERE id = ? AND owner_id = ?");
        $updateStmt->execute([$method_id, $owner_id]);
        
        // Commit transaction
        $conn->commit();
        
        // Log the action
        $logStmt = $conn->prepare("INSERT INTO activity_logs (user_id, user_type, action, details, ip_address) 
                                  VALUES (?, 'owner', 'update_payment_method', ?, ?)");
        $logStmt->execute([
            $owner_id, 
            "Set payment method #$method_id as primary", 
            $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Payment method set as primary successfully']);
        
    } elseif ($action === 'delete') {
        // Check if this is the only payment method or if it's the primary one
        $checkStmt = $conn->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN is_primary = 1 THEN 1 ELSE 0 END) as is_primary 
                                    FROM payment_methods WHERE owner_id = ?");
        $checkStmt->execute([$owner_id]);
        $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        // Check if this is the only payment method
        if ($result['total'] <= 1) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Cannot delete your only payment method. Please add another method first.']);
            exit;
        }
        
        // Check if this is the primary payment method
        $isPrimaryStmt = $conn->prepare("SELECT is_primary FROM payment_methods WHERE id = ?");
        $isPrimaryStmt->execute([$method_id]);
        $isPrimary = $isPrimaryStmt->fetchColumn();
        
        // Begin transaction
        $conn->beginTransaction();
        
        // Delete the payment method
        $deleteStmt = $conn->prepare("DELETE FROM payment_methods WHERE id = ? AND owner_id = ?");
        $deleteStmt->execute([$method_id, $owner_id]);
        
        // If we deleted the primary method, set another one as primary
        if ($isPrimary) {
            $newPrimaryStmt = $conn->prepare("UPDATE payment_methods SET is_primary = 1 
                                             WHERE owner_id = ? ORDER BY id LIMIT 1");
            $newPrimaryStmt->execute([$owner_id]);
        }
        
        // Commit transaction
        $conn->commit();
        
        // Log the action
        $logStmt = $conn->prepare("INSERT INTO activity_logs (user_id, user_type, action, details, ip_address) 
                                  VALUES (?, 'owner', 'delete_payment_method', ?, ?)");
        $logStmt->execute([
            $owner_id, 
            "Deleted payment method #$method_id", 
            $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Payment method deleted successfully']);
        
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    
} catch (PDOException $e) {
    // Rollback transaction if there was an error
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    
    // Log the error
    error_log("Payment method update error: " . $e->getMessage());
    
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database error occurred. Please try again later.']);
}
