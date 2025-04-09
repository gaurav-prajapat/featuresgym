<?php
session_start();
require_once 'config/database.php';
require_once 'vendor/autoload.php';
require_once 'includes/csrf.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error_message'] = "Please login to complete payment.";
    header('Location: login.php');
    exit();
}

// Verify CSRF token
$csrf = new CSRF();
if (!$csrf->verifyToken($_POST['csrf_token'] ?? '')) {
    $_SESSION['error_message'] = "Security validation failed. Please try again.";
    header('Location: membership.php');
    exit();
}

// Validate required parameters
$razorpay_payment_id = $_POST['razorpay_payment_id'] ?? '';
$razorpay_order_id = $_POST['razorpay_order_id'] ?? '';
$razorpay_signature = $_POST['razorpay_signature'] ?? '';
$membership_id = filter_input(INPUT_POST, 'membership_id', FILTER_VALIDATE_INT);
$payment_id = filter_input(INPUT_POST, 'payment_id', FILTER_VALIDATE_INT);

if (!$razorpay_payment_id || !$razorpay_order_id || !$razorpay_signature || !$membership_id || !$payment_id) {
    $_SESSION['error_message'] = "Missing payment verification parameters.";
    header('Location: membership.php');
    exit();
}

// Initialize database connection
$db = new GymDatabase();
$conn = $db->getConnection();

try {
    // Begin transaction
    $conn->beginTransaction();
    
    // Get payment settings
    $stmt = $conn->prepare("
               SELECT setting_key, setting_value 
        FROM payment_settings 
        WHERE setting_key IN ('razorpay_key_id', 'razorpay_key_secret', 'test_mode')
    ");
    $stmt->execute();
    $payment_settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $payment_settings[$row['setting_key']] = $row['setting_value'];
    }
    
    $razorpay_key_id = $payment_settings['razorpay_key_id'] ?? '';
    $razorpay_key_secret = $payment_settings['razorpay_key_secret'] ?? '';
    $test_mode = ($payment_settings['test_mode'] ?? '0') === '1';
    
    // If keys are not found in database, try using the ones from .env
    if (empty($razorpay_key_id) || empty($razorpay_key_secret)) {
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
        $dotenv->load();
        $razorpay_key_id = $_ENV['RAZORPAY_KEY_ID'] ?? '';
        $razorpay_key_secret = $_ENV['RAZORPAY_KEY_SECRET'] ?? '';
    }
    
    if (empty($razorpay_key_id) || empty($razorpay_key_secret)) {
        throw new Exception("Payment gateway is not properly configured");
    }
    
    // Verify payment signature
    $api = new Razorpay\Api\Api($razorpay_key_id, $razorpay_key_secret);
    
    $attributes = [
        'razorpay_order_id' => $razorpay_order_id,
        'razorpay_payment_id' => $razorpay_payment_id,
        'razorpay_signature' => $razorpay_signature
    ];
    
    // Skip signature verification in test mode if needed
    $signature_verified = $test_mode;
    
    if (!$test_mode) {
        try {
            $api->utility->verifyPaymentSignature($attributes);
            $signature_verified = true;
        } catch (Exception $e) {
            $signature_verified = false;
            throw new Exception("Payment signature verification failed: " . $e->getMessage());
        }
    }
    
    if ($signature_verified) {
        // Get membership details
        $stmt = $conn->prepare("
            SELECT um.*, g.name as gym_name, gmp.plan_name
            FROM user_memberships um
            JOIN gyms g ON um.gym_id = g.gym_id
            JOIN gym_membership_plans gmp ON um.plan_id = gmp.plan_id
            WHERE um.id = ? AND um.user_id = ?
        ");
        $stmt->execute([$membership_id, $_SESSION['user_id']]);
        $membership = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$membership) {
            throw new Exception("Membership not found");
        }
        
        // Update membership status
        $stmt = $conn->prepare("
            UPDATE user_memberships 
            SET payment_status = 'paid', 
                status = 'active', 
                payment_id = ?, 
                payment_date = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$razorpay_payment_id, $membership_id]);
        
        // Update payment record
        $stmt = $conn->prepare("
            UPDATE payments 
            SET status = 'completed', 
                transaction_id = ?, 
                payment_id = ?,
                payment_date = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$razorpay_payment_id, $razorpay_payment_id, $payment_id]);
        
        // Log the transaction
        $stmt = $conn->prepare("
            INSERT INTO transactions (
                user_id, gym_id, amount, transaction_type, status, 
                description, transaction_date, payment_method, transaction_id
            ) VALUES (
                ?, ?, ?, 'membership_purchase', 'completed',
                ?, NOW(), 'razorpay', ?
            )
        ");
        $stmt->execute([
            $_SESSION['user_id'], 
            $membership['gym_id'], 
            $membership['amount'],
            "Purchase of {$membership['plan_name']} membership",
            $razorpay_payment_id
        ]);
        
        // Update coupon usage if a coupon was used
        if (!empty($membership['coupon_code'])) {
            $stmt = $conn->prepare("
                UPDATE coupons 
                SET usage_count = usage_count + 1 
                WHERE code = ?
            ");
            $stmt->execute([$membership['coupon_code']]);
        }
        
        // Create notification for user
        $stmt = $conn->prepare("
            INSERT INTO notifications (
                user_id, type, message, related_id, title, created_at, status, gym_id
            ) VALUES (
                ?, 'membership', ?, ?, ?, NOW(), 'unread', ?
            )
        ");
        $stmt->execute([
            $_SESSION['user_id'],
            "Your membership at {$membership['gym_name']} has been activated successfully.",
            $membership_id,
            "Membership Activated",
            $membership['gym_id']
        ]);
        
        // Create notification for gym owner
        $stmt = $conn->prepare("
            INSERT INTO gym_notifications (
                gym_id, title, message, is_read, created_at
            ) VALUES (
                ?, ?, ?, 0, NOW()
            )
        ");
        $stmt->execute([
            $membership['gym_id'],
            "New Membership Purchase",
            "A new membership has been purchased by {$_SESSION['username']} for {$membership['plan_name']}."
        ]);
        
        // Log activity
        $stmt = $conn->prepare("
            INSERT INTO activity_logs (
                user_id, user_type, action, details, ip_address, user_agent
            ) VALUES (?, 'member', 'membership_purchase', ?, ?, ?)
        ");
        $stmt->execute([
            $_SESSION['user_id'],
            "Purchased {$membership['plan_name']} membership at {$membership['gym_name']}",
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT']
        ]);
        
        // Commit transaction
        $conn->commit();
        
        // Set success message
        $_SESSION['success'] = "Payment successful! Your membership is now active.";
        
        // Redirect to membership page
        header('Location: view_membership.php?id=' . $membership_id);
        exit();
    } else {
        throw new Exception("Payment verification failed");
    }
    
} catch (Exception $e) {
    // Rollback transaction on error
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    
    // Log error
    error_log("Payment verification error: " . $e->getMessage());
    
    // Set error message
    $_SESSION['error_message'] = "Payment verification failed: " . $e->getMessage();
    
    // Redirect to membership page
    header('Location: membership.php');
    exit();
}

