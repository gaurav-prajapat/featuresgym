<?php
session_start();
require_once 'config/database.php';
require_once 'includes/csrf.php';
require_once 'includes/auth_check.php';

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = "Please login to purchase a membership.";
    header('Location: login.php');
    exit();
}

// Validate form submission
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = "Invalid request method.";
    header('Location: membership.php');
    exit();
}

// Verify CSRF token
$csrf = new CSRF();
if (!$csrf->verifyToken($_POST['csrf_token'] ?? '')) {
    $_SESSION['error'] = "Security validation failed. Please try again.";
    header('Location: membership.php');
    exit();
}

// Verify transaction nonce to prevent replay attacks
if (!isset($_SESSION['transaction_nonce']) || $_SESSION['transaction_nonce'] !== ($_POST['transaction_nonce'] ?? '')) {
    $_SESSION['error'] = "Invalid transaction. Please try again.";
    header('Location: membership.php');
    exit();
}

// Initialize database connection
$db = new GymDatabase();
$conn = $db->getConnection();

// Sanitize and validate input
$user_id = $_SESSION['user_id'];
$plan_id = filter_input(INPUT_POST, 'plan_id', FILTER_VALIDATE_INT);
$gym_id = filter_input(INPUT_POST, 'gym_id', FILTER_VALIDATE_INT);
$start_date = filter_input(INPUT_POST, 'start_date', FILTER_SANITIZE_STRING);
$amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
$base_amount = filter_input(INPUT_POST, 'base_amount', FILTER_VALIDATE_FLOAT);
$discount_amount = filter_input(INPUT_POST, 'discount_amount', FILTER_VALIDATE_FLOAT) ?: 0;
$gateway_tax = filter_input(INPUT_POST, 'gateway_tax', FILTER_VALIDATE_FLOAT) ?: 0;
$govt_tax = filter_input(INPUT_POST, 'govt_tax', FILTER_VALIDATE_FLOAT) ?: 0;
$coupon_code = filter_input(INPUT_POST, 'coupon_code', FILTER_SANITIZE_STRING);
$num_days = filter_input(INPUT_POST, 'num_days', FILTER_VALIDATE_INT);

// Validate required fields
if (!$plan_id || !$gym_id || !$start_date || !$amount) {
    $_SESSION['error'] = "Missing required fields.";
    header('Location: buy_membership.php?plan_id=' . $plan_id . '&gym_id=' . $gym_id);
    exit();
}

// Validate start date
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || strtotime($start_date) < strtotime(date('Y-m-d'))) {
    $_SESSION['error'] = "Invalid start date.";
    header('Location: buy_membership.php?plan_id=' . $plan_id . '&gym_id=' . $gym_id);
    exit();
}

// Get payment gateway settings
$stmt = $conn->prepare("
    SELECT setting_key, setting_value 
    FROM payment_settings 
    WHERE setting_key IN ('razorpay_key_id', 'razorpay_key_secret', 'payment_gateway_enabled', 'test_mode')
");
$stmt->execute();
$payment_settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$razorpay_key_id = $payment_settings['razorpay_key_id'] ?? '';
$razorpay_key_secret = $payment_settings['razorpay_key_secret'] ?? '';
$payment_gateway_enabled = ($payment_settings['payment_gateway_enabled'] ?? '0') === '1';
$test_mode = ($payment_settings['test_mode'] ?? '0') === '1';

// Check if payment gateway is properly configured
if (!$payment_gateway_enabled || empty($razorpay_key_id) || empty($razorpay_key_secret)) {
    $_SESSION['error'] = "Payment system is currently unavailable. Please try again later.";
    header('Location: buy_membership.php?plan_id=' . $plan_id . '&gym_id=' . $gym_id);
    exit();
}

try {
    // Begin transaction
    $conn->beginTransaction();
    
    // Fetch plan details
    $stmt = $conn->prepare("
        SELECT gmp.*, g.name as gym_name 
        FROM gym_membership_plans gmp
        JOIN gyms g ON gmp.gym_id = g.gym_id
        WHERE gmp.plan_id = ? AND g.gym_id = ? AND g.status = 'active'
    ");
    $stmt->execute([$plan_id, $gym_id]);
    $plan = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$plan) {
        throw new Exception("The selected plan is not available.");
    }
    
    // Define duration days mapping
    $duration_days = [
        'Daily' => 1,
        'Weekly' => 7,
        'Monthly' => 30,
        'Quarterly' => 90,
        'Half Yearly' => 180,
        'Yearly' => 365
    ];
    
    // Calculate end date
    if ($plan['duration'] === 'Daily' && $num_days) {
        $days = $num_days;
    } else {
        $days = $duration_days[$plan['duration']] ?? 1;
    }
    
    // Subtract 1 from days because the end date is inclusive of the start date
    $end_date = date('Y-m-d', strtotime($start_date . ' + ' . ($days - 1) . ' days'));
    
    // Verify coupon if provided
    if (!empty($coupon_code)) {
        $stmt = $conn->prepare("
            SELECT * FROM coupons 
            WHERE code = ? 
            AND is_active = 1 
            AND (expiry_date IS NULL OR expiry_date >= CURDATE())
            AND (usage_limit IS NULL OR usage_count < usage_limit)
        ");
        $stmt->execute([$coupon_code]);
        $coupon = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$coupon) {
            throw new Exception("Invalid or expired coupon code.");
        }
        
        // Check if coupon is applicable to this plan or gym
        $is_applicable = true;
        
        if ($coupon['applicable_to_type'] === 'plan' && $coupon['applicable_to_id'] != $plan_id) {
            $is_applicable = false;
        } elseif ($coupon['applicable_to_type'] === 'gym' && $coupon['applicable_to_id'] != $gym_id) {
            $is_applicable = false;
        }
        
        if (!$is_applicable) {
            throw new Exception("This coupon is not applicable to this plan.");
        }
        
        // Update coupon usage count
        $stmt = $conn->prepare("UPDATE coupons SET usage_count = usage_count + 1 WHERE id = ?");
        $stmt->execute([$coupon['id']]);
    }
    
    // Generate a unique order ID
    $order_id = 'ORD' . time() . rand(1000, 9999);
    
    // Create a pending membership record
    $stmt = $conn->prepare("
        INSERT INTO user_memberships (
            user_id, gym_id, plan_id, start_date, end_date, 
            status, payment_status, amount, base_price, discount_amount,
            coupon_code, gateway_tax, govt_tax, created_at
        ) VALUES (
            ?, ?, ?, ?, ?, 
            'pending', 'pending', ?, ?, ?,
            ?, ?, ?, NOW()
        )
    ");
    $stmt->execute([
        $user_id, $gym_id, $plan_id, $start_date, $end_date,
        $amount, $base_amount, $discount_amount,
        $coupon_code, $gateway_tax, $govt_tax
    ]);
    
    $membership_id = $conn->lastInsertId();
    
    // Create a payment record
    $stmt = $conn->prepare("
        INSERT INTO payments (
            user_id, gym_id, membership_id, amount, base_amount,
            discount_amount, coupon_code, gateway_tax, govt_tax,
            status, payment_method, created_at
        ) VALUES (
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?,
            'pending', 'razorpay', NOW()
        )
    ");
    $stmt->execute([
        $user_id, $gym_id, $membership_id, $amount, $base_amount,
        $discount_amount, $coupon_code, $gateway_tax, $govt_tax
    ]);
    
    $payment_id = $conn->lastInsertId();
    
    // Log the transaction attempt
    $stmt = $conn->prepare("
        INSERT INTO activity_logs (
            user_id, user_type, action, details, ip_address, user_agent
        ) VALUES (
            ?, 'member', 'initiate_payment', ?, ?, ?
        )
    ");
    $stmt->execute([
        $user_id,
        "Initiated payment for membership ID: $membership_id, Amount: $amount",
        $_SERVER['REMOTE_ADDR'],
        $_SERVER['HTTP_USER_AGENT']
    ]);
    
    // Commit transaction
    $conn->commit();
    
    // Prepare Razorpay order
    $razorpay_amount = $amount * 100; // Convert to paise
    
    // Get user details for Razorpay
    $stmt = $conn->prepare("SELECT username, email, phone FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
   // Create Razorpay order
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://api.razorpay.com/v1/orders');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'amount' => $razorpay_amount,
    'currency' => 'INR',
    'receipt' => $order_id,
    'notes' => [
        'membership_id' => $membership_id,
        'payment_id' => $payment_id,
        'gym_id' => $gym_id,
        'plan_id' => $plan_id,
        'user_id' => $user_id
    ]
]));
curl_setopt($ch, CURLOPT_USERPWD, $razorpay_key_id . ':' . $razorpay_key_secret);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
   // After the curl request, add detailed logging
$response = curl_exec($ch);
$err = curl_error($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

// Log detailed information
error_log("Razorpay API Response Code: " . $http_code);
error_log("Razorpay API Response: " . $response);
error_log("Razorpay API Error: " . $err);

    
    curl_close($ch);
    
    if ($err) {
        throw new Exception("Error creating payment order: " . $err);
    }
    
    $order_data = json_decode($response, true);
    
    if (!$order_data || isset($order_data['error'])) {
        throw new Exception("Error creating payment order: " . ($order_data['error']['description'] ?? 'Unknown error'));
    }
    
    // Update payment record with order ID
    $stmt = $conn->prepare("UPDATE payments SET transaction_id = ? WHERE id = ?");
    $stmt->execute([$order_data['id'], $payment_id]);
    
    // Store order details in session for verification later
    $_SESSION['razorpay_order'] = [
        'order_id' => $order_data['id'],
        'membership_id' => $membership_id,
        'payment_id' => $payment_id,
        'gym_id' => $gym_id,
        'plan_id' => $plan_id,
        'amount' => $amount,
        'user_id' => $user_id
    ];
    
    // Clear transaction nonce to prevent reuse
    unset($_SESSION['transaction_nonce']);
    
    // Redirect to payment page
    header('Location: payment_page.php');
    exit();
    
} catch (Exception $e) {
    // Rollback transaction on error
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    
    // Log the error
    error_log("Payment processing error: " . $e->getMessage());
    
    $_SESSION['error'] = $e->getMessage();
    header('Location: buy_membership.php?plan_id=' . $plan_id . '&gym_id=' . $gym_id);
    exit();
}

