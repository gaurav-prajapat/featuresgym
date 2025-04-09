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
if (!isset($_POST['owner_id']) || !isset($_POST['method_type'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required data']);
    exit();
}

$owner_id = (int)$_POST['owner_id'];
$method_type = $_POST['method_type'];
$payment_id = isset($_POST['payment_id']) ? (int)$_POST['payment_id'] : 0;
$is_primary = isset($_POST['is_primary']) ? 1 : 0;

// Validate method type
if (!in_array($method_type, ['bank', 'upi'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid payment method type']);
    exit();
}

try {
    $conn->beginTransaction();
    
    // If setting as primary, unset all other payment methods as primary
    if ($is_primary) {
        $stmt = $conn->prepare("UPDATE payment_methods SET is_primary = 0 WHERE owner_id = ?");
        $stmt->execute([$owner_id]);
    }
    
    // Check if we're updating an existing payment method or adding a new one
    if ($payment_id > 0) {
        // Update existing payment method
        if ($method_type === 'bank') {
            $stmt = $conn->prepare("
                UPDATE payment_methods 
                SET method_type = ?, account_name = ?, account_number = ?, ifsc_code = ?, bank_name = ?, is_primary = ?
                WHERE id = ? AND owner_id = ?
            ");
            $stmt->execute([
                $method_type,
                $_POST['account_name'],
                $_POST['account_number'],
                $_POST['ifsc_code'],
                $_POST['bank_name'],
                $is_primary,
                $payment_id,
                $owner_id
            ]);
        } else {
            $stmt = $conn->prepare("
                UPDATE payment_methods 
                SET method_type = ?, upi_id = ?, is_primary = ?
                WHERE id = ? AND owner_id = ?
            ");
            $stmt->execute([
                $method_type,
                $_POST['upi_id'],
                $is_primary,
                $payment_id,
                $owner_id
            ]);
        }
        
        // Log the activity
        $log_query = "INSERT INTO activity_logs (user_id, user_type, action, details, ip_address, user_agent) 
                     VALUES (:user_id, 'admin', 'update_payment_method', :details, :ip, :user_agent)";
        $log_stmt = $conn->prepare($log_query);
        $log_stmt->execute([
            ':user_id' => $_SESSION['user_id'],
            ':details' => "Updated payment method ID: $payment_id for owner ID: $owner_id",
            ':ip' => $_SERVER['REMOTE_ADDR'],
            ':user_agent' => $_SERVER['HTTP_USER_AGENT']
        ]);
        
        $message = "Payment method updated successfully";
    } else {
        // Add new payment method
        if ($method_type === 'bank') {
            $stmt = $conn->prepare("
                INSERT INTO payment_methods (owner_id, method_type, account_name, account_number, ifsc_code, bank_name, is_primary)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $owner_id,
                $method_type,
                $_POST['account_name'],
                $_POST['account_number'],
                $_POST['ifsc_code'],
                $_POST['bank_name'],
                $is_primary
            ]);
        } else {
            $stmt = $conn->prepare("
                INSERT INTO payment_methods (owner_id, method_type, upi_id, is_primary)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([
                $owner_id,
                $method_type,
                $_POST['upi_id'],
                $is_primary
            ]);
        }
        
        $new_payment_id = $conn->lastInsertId();
        
        // Log the activity
        $log_query = "INSERT INTO activity_logs (user_id, user_type, action, details, ip_address, user_agent) 
                     VALUES (:user_id, 'admin', 'add_payment_method', :details, :ip, :user_agent)";
        $log_stmt = $conn->prepare($log_query);
        $log_stmt->execute([
            ':user_id' => $_SESSION['user_id'],
            ':details' => "Added new payment method ID: $new_payment_id for owner ID: $owner_id",
            ':ip' => $_SERVER['REMOTE_ADDR'],
            ':user_agent' => $_SERVER['HTTP_USER_AGENT']
        ]);
        
        $message = "Payment method added successfully";
    }
    
    $conn->commit();
    echo json_encode(['success' => true, 'message' => $message]);
    
} catch (PDOException $e) {
    $conn->rollBack();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}

