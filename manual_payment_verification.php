<?php
session_start();
require_once 'config/database.php';
require_once 'includes/auth_check.php';

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = "Please login to complete payment.";
    header('Location: login.php');
    exit();
}

// Check if we're in test mode
$db = new GymDatabase();
$conn = $db->getConnection();

$stmt = $conn->prepare("SELECT setting_value FROM payment_settings WHERE setting_key = 'test_mode'");
$stmt->execute();
$test_mode = ($stmt->fetchColumn() === '1');

// Only allow manual verification in test mode
if (!$test_mode) {
    $_SESSION['error'] = "Manual payment verification is only available in test mode.";
    header('Location: membership.php');
    exit();
}

// Verify required parameters
if (!isset($_POST['membership_id']) || !isset($_POST['payment_id']) || !isset($_POST['order_id'])) {
    $_SESSION['error'] = "Missing required parameters.";
    header('Location: membership.php');
    exit();
}

$membership_id = $_POST['membership_id'];
$payment_id = $_POST['payment_id'];
$order_id = $_POST['order_id'];

try {
    // Begin transaction
    $conn->beginTransaction();
    
    // Generate a fake Razorpay payment ID
    $fake_razorpay_payment_id = 'rzp_test_' . bin2hex(random_bytes(12));
    
    // Update membership status
    $stmt = $conn->prepare("
        UPDATE user_memberships 
        SET payment_status = 'paid', 
            payment_id = ?, 
            payment_date = NOW() 
        WHERE id = ? AND user_id = ?
    ");
    $stmt->execute([$fake_razorpay_payment_id, $membership_id, $_SESSION['user_id']]);
    
    // Update payment record
    $stmt = $conn->prepare("
        UPDATE payments 
        SET status = 'completed', 
            transaction_id = ?, 
            payment_date = NOW() 
        WHERE id = ?
    ");
    $stmt->execute([$fake_razorpay_payment_id, $payment_id]);
    
    // Log the transaction
    $stmt = $conn->prepare("
        INSERT INTO transactions (
            user_id, gym_id, amount, transaction_type, status, 
            description, transaction_date, payment_method, transaction_id
        ) SELECT 
            um.user_id, um.gym_id, um.amount, 'membership_purchase', 'completed',
            CONCAT('Purchase of ', gmp.plan_name, ' membership'), 
            NOW(), 'razorpay', ?
        FROM user_memberships um
        JOIN gym_membership_plans gmp ON um.plan_id = gmp.plan_id
        WHERE um.id = ?
    ");
    $stmt->execute([$fake_razorpay_payment_id, $membership_id]);
    
    // Log activity
    $stmt = $conn->prepare("
        INSERT INTO activity_logs (
            user_id, user_type, action, details, ip_address, user_agent
        ) VALUES (?, 'member', 'simulated_payment', ?, ?, ?)
    ");
    $stmt->execute([
        $_SESSION['user_id'],
        "Simulated payment for membership ID: $membership_id",
        $_SERVER['REMOTE_ADDR'],
        $_SERVER['HTTP_USER_AGENT']
    ]);
    
    // Commit transaction
    $conn->commit();
    
    // Set success message
    $_SESSION['success'] = "Payment simulation successful! Your membership is now active.";
    
    // Redirect to membership page
    header('Location: view_membership.php?id=' . $membership_id);
    exit();
    
} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollBack();
    
    // Log error
    error_log("Payment simulation error: " . $e->getMessage());
    
    // Set error message
    $_SESSION['error'] = "Payment simulation failed: " . $e->getMessage();
    
    // Redirect back
    header('Location: payment_page.php');
    exit();
}
