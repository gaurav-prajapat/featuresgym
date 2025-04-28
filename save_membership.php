<?php
// Set proper headers
header('Content-Type: application/json');

// Include necessary files
require_once 'config/database.php';
require_once 'includes/csrf.php';
session_start();

// Initialize response array
$response = ['status' => 'error', 'message' => 'Unknown error'];

try {
    // Verify user is logged in
    if (!isset($_SESSION['user_id'])) {
        $response['message'] = 'User not logged in';
        echo json_encode($response);
        exit();
    }

    // Verify CSRF token
    $csrf = new CSRF();
    if (!$csrf->verifyToken($_POST['csrf_token'] ?? '')) {
        $response['message'] = 'Security validation failed';
        echo json_encode($response);
        exit();
    }

    // Validate required parameters
    $plan_id = filter_input(INPUT_POST, 'plan_id', FILTER_VALIDATE_INT);
    $start_date = filter_input(INPUT_POST, 'start_date', FILTER_SANITIZE_STRING);
    $end_date = filter_input(INPUT_POST, 'end_date', FILTER_SANITIZE_STRING);
    $payment_id = filter_input(INPUT_POST, 'payment_id', FILTER_VALIDATE_INT);
    $coupon_code = filter_input(INPUT_POST, 'coupon_code', FILTER_SANITIZE_STRING);

    if (!$plan_id || !$start_date || !$end_date || !$payment_id) {
        $response['message'] = 'Missing or invalid parameters';
        echo json_encode($response);
        exit();
    }

    // Connect to database
    $db = new GymDatabase();
    $conn = $db->getConnection();

    // Start transaction
    $conn->beginTransaction();

    // Get gym_id and plan details from the plan
    $stmt = $conn->prepare("
        SELECT gym_id, price, duration 
        FROM gym_membership_plans 
        WHERE plan_id = ?
    ");
    $stmt->execute([$plan_id]);
    $plan = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$plan) {
        $conn->rollBack();
        $response['message'] = 'Invalid plan ID';
        echo json_encode($response);
        exit();
    }

    $gym_id = $plan['gym_id'];
    $plan_price = $plan['price'];
    $plan_duration = $plan['duration'];

    // Verify payment exists and is completed
    $stmt = $conn->prepare("SELECT * FROM payments WHERE id = ? AND user_id = ? AND gym_id = ?");
    $stmt->execute([$payment_id, $_SESSION['user_id'], $gym_id]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        $conn->rollBack();
        $response['message'] = 'Payment not found';
        echo json_encode($response);
        exit();
    }

    if ($payment['status'] !== 'completed') {
        // If payment is not completed, check if it's a valid payment
        if ($payment['status'] === 'pending') {
            // Try to update the payment status to completed
            $stmt = $conn->prepare("
                UPDATE payments 
                SET status = 'completed', payment_date = NOW()
                WHERE id = ? AND user_id = ? AND gym_id = ? AND status = 'pending'
            ");
            $stmt->execute([$payment_id, $_SESSION['user_id'], $gym_id]);
            
            if ($stmt->rowCount() === 0) {
                $conn->rollBack();
                $response['message'] = 'Payment could not be updated';
                echo json_encode($response);
                exit();
            }
        } else {
            $conn->rollBack();
            $response['message'] = 'Payment is not in a valid state: ' . $payment['status'];
            echo json_encode($response);
            exit();
        }
    }

    // Check if membership already exists for this payment
    $stmt = $conn->prepare("
        SELECT id FROM user_memberships 
        WHERE payment_id = ? AND user_id = ? AND gym_id = ?
    ");
    $stmt->execute([$payment_id, $_SESSION['user_id'], $gym_id]);
    $existing_membership = $stmt->fetchColumn();

    if ($existing_membership) {
        // Membership already exists, return success with existing ID
        $conn->commit();
        $response = [
            'status' => 'success',
            'membership_id' => $existing_membership,
            'message' => 'Membership already exists'
        ];
        echo json_encode($response);
        exit();
    }

    // Create membership record - make sure column names match your database schema
    $stmt = $conn->prepare("
        INSERT INTO user_memberships (
            user_id, gym_id, plan_id, start_date, end_date, 
            payment_id, status, payment_status, created_at,
            amount, base_price
        ) VALUES (
            :user_id, :gym_id, :plan_id, :start_date, :end_date, 
            :payment_id, 'active', 'paid', NOW(),
            :amount, :base_price
        )
    ");

    // Use the base amount from payment or plan price if not available
    $base_amount = $payment['base_amount'] ?? $plan_price;

    $stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
    $stmt->bindParam(':gym_id', $gym_id, PDO::PARAM_INT);
    $stmt->bindParam(':plan_id', $plan_id, PDO::PARAM_INT);
    $stmt->bindParam(':start_date', $start_date, PDO::PARAM_STR);
    $stmt->bindParam(':end_date', $end_date, PDO::PARAM_STR);
    $stmt->bindParam(':payment_id', $payment_id, PDO::PARAM_INT);
    $stmt->bindParam(':amount', $payment['amount'], PDO::PARAM_STR);
    $stmt->bindParam(':base_price', $base_amount, PDO::PARAM_STR);

    if ($stmt->execute()) {
        $membership_id = $conn->lastInsertId();
        
        // Update payment with membership_id
        $stmt = $conn->prepare("
            UPDATE payments 
            SET membership_id = :membership_id
            WHERE id = :payment_id
        ");
        $stmt->bindParam(':membership_id', $membership_id, PDO::PARAM_INT);
        $stmt->bindParam(':payment_id', $payment_id, PDO::PARAM_INT);
        $stmt->execute();
        
        // If coupon was used, update its usage count
        if (!empty($coupon_code)) {
            $stmt = $conn->prepare("
                UPDATE coupons 
                SET usage_count = usage_count + 1 
                WHERE code = ? AND is_active = 1
            ");
            $stmt->execute([$coupon_code]);
        }
        
        // Calculate balance to add based on plan duration and price
        $balance_to_add = $base_amount;
        
        // Add the plan amount to user's balance
        $stmt = $conn->prepare("
            UPDATE users 
            SET balance = balance + :balance_amount
            WHERE id = :user_id
        ");
        $stmt->bindParam(':balance_amount', $balance_to_add, PDO::PARAM_STR);
        $stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
        
        if (!$stmt->execute()) {
            $conn->rollBack();
            $response['message'] = 'Failed to update user balance';
            echo json_encode($response);
            exit();
        }
        
        // Log the balance addition
        $stmt = $conn->prepare("
            INSERT INTO activity_logs (
                user_id, user_type, action, details, ip_address
            ) VALUES (
                :user_id, 'member', 'balance_added', :details, :ip_address
            )
        ");
        
        $log_details = json_encode([
            'amount' => $balance_to_add,
            'source' => 'membership_purchase',
            'plan_id' => $plan_id,
            'membership_id' => $membership_id
        ]);
        
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
        
        $stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
        $stmt->bindParam(':details', $log_details, PDO::PARAM_STR);
        $stmt->bindParam(':ip_address', $ip_address, PDO::PARAM_STR);
        $stmt->execute();
        
        $conn->commit();
        $response = [
            'status' => 'success',
            'membership_id' => $membership_id,
            'balance_added' => $balance_to_add,
            'message' => 'Membership created successfully and balance updated'
        ];
    } else {
        $conn->rollBack();
        $response['message'] = 'Failed to create membership record';
    }
    // Add this after the successful membership creation, just before the final response
// Around line 172, after the activity log is inserted and before the commit

// Send confirmation email to the user
try {
    // First, get the user's email
    $userStmt = $conn->prepare("SELECT email, username FROM users WHERE id = ?");
    $userStmt->execute([$_SESSION['user_id']]);
    $userData = $userStmt->fetch(PDO::FETCH_ASSOC);
    
    // Get gym name
    $gymStmt = $conn->prepare("SELECT name FROM gyms WHERE gym_id = ?");
    $gymStmt->execute([$gym_id]);
    $gymName = $gymStmt->fetchColumn();
    
    // Include the email service
    require_once 'includes/EmailService.php';
    $emailService = new EmailService();
    
    // Prepare email content
    $subject = "Membership Confirmation - " . htmlspecialchars($gymName);
    
    $body = "<h2>Your Membership is Purchase Successful!</h2>";
    $body .= "<p>Hello " . htmlspecialchars($userData['username']) . ",</p>";
    $body .= "<p>Thank you for purchasing a membership at <strong>" . htmlspecialchars($gymName) . "</strong>.</p>";
    $body .= "<p><strong>Membership Details:</strong></p>";
    $body .= "<ul>";
    $body .= "<li>Plan: " . htmlspecialchars($plan['name'] ?? 'Standard Plan') . "</li>";
    $body .= "<li>Start Date: " . htmlspecialchars($start_date) . "</li>";
    $body .= "<li>End Date: " . htmlspecialchars($end_date) . "</li>";
    $body .= "<li>Amount Paid: ₹" . number_format($payment['amount'], 2) . "</li>";
    $body .= "<li>Balance Added: ₹" . number_format($balance_to_add, 2) . "</li>";
    $body .= "</ul>";
    $body .= "<p>You can view your membership details and schedule your workouts by logging into your account.</p>";
    $body .= "<p>Thank you for choosing us!</p>";
    
    // Send the email
    $emailService->sendEmail($userData['email'], $subject, $body);
    
    // Log the email sending
    $emailLogStmt = $conn->prepare("
        INSERT INTO activity_logs (
            user_id, user_type, action, details, ip_address
        ) VALUES (
            :user_id, 'member', 'email_sent', :details, :ip_address
        )
    ");
    
    $emailLogDetails = json_encode([
        'type' => 'membership_confirmation',
        'membership_id' => $membership_id,
        'email' => $userData['email']
    ]);
    
    $emailLogStmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
    $emailLogStmt->bindParam(':details', $emailLogDetails, PDO::PARAM_STR);
    $emailLogStmt->bindParam(':ip_address', $ip_address, PDO::PARAM_STR);
    $emailLogStmt->execute();
    
    // Add email sent confirmation to the response
    $response['email_sent'] = true;
    
} catch (Exception $emailError) {
    // Log the error but don't fail the transaction
    error_log('Error sending membership confirmation email: ' . $emailError->getMessage());
    $response['email_sent'] = false;
    $response['email_error'] = 'Could not send confirmation email';
}

} catch (PDOException $e) {
    if (isset($conn)) {
        $conn->rollBack();
    }
    // Log the error server-side
    error_log('Database error in save_membership.php: ' . $e->getMessage());
    error_log('SQL State: ' . $e->getCode());
    error_log('Error occurred on line: ' . $e->getLine());
    error_log('Stack trace: ' . $e->getTraceAsString());
    
    // Return more detailed error for debugging
    $response['message'] = 'Database error: ' . $e->getMessage();
    $response['code'] = $e->getCode();
} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollBack();
    }
    // Log the error server-side
    error_log('Error in save_membership.php: ' . $e->getMessage());
    $response['message'] = 'An error occurred: ' . $e->getMessage();
}

// Return the response
echo json_encode($response);
exit();
