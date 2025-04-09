<?php
session_start();
require_once 'config/database.php';
require_once 'vendor/autoload.php';
require_once 'includes/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = "Please login to complete payment.";
    header('Location: login.php');
    exit();
}

// Get payment ID from URL
$payment_id = filter_input(INPUT_GET, 'payment_id', FILTER_VALIDATE_INT);

if (!$payment_id) {
    $_SESSION['error'] = "Invalid payment ID.";
    header('Location: view_membership.php');
    exit();
}

// Initialize database connection
$db = new GymDatabase();
$conn = $db->getConnection();

try {
    // Get payment details
    $stmt = $conn->prepare("
        SELECT p.*, um.id as membership_id, um.user_id, um.gym_id, um.plan_id, 
               g.name as gym_name, gmp.plan_name
        FROM payments p
        JOIN user_memberships um ON p.membership_id = um.id
        JOIN gyms g ON um.gym_id = g.gym_id
        JOIN gym_membership_plans gmp ON um.plan_id = gmp.plan_id
        WHERE p.id = ? AND um.user_id = ?
    ");
    $stmt->execute([$payment_id, $_SESSION['user_id']]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$payment) {
        throw new Exception("Payment not found or you don't have permission to access it.");
    }
    
    // Check if payment is already completed
    if ($payment['status'] === 'completed') {
        $_SESSION['success'] = "This payment has already been completed.";
        header('Location: view_membership.php?id=' . $payment['membership_id']);
        exit();
    }
    
    // Get payment settings from database
    $stmt = $conn->prepare("
        SELECT setting_key, setting_value 
        FROM payment_settings 
        WHERE setting_key IN ('razorpay_key_id', 'razorpay_key_secret', 'payment_gateway_enabled', 'test_mode')
    ");
    $stmt->execute();
    $payment_settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $payment_settings[$row['setting_key']] = $row['setting_value'];
    }
    
    // Check if payment gateway is enabled
    $payment_gateway_enabled = isset($payment_settings['payment_gateway_enabled']) && 
                              $payment_settings['payment_gateway_enabled'] == '1';
    
    if (!$payment_gateway_enabled) {
        throw new Exception("Payment gateway is currently disabled. Please try again later or contact support.");
    }
    
    // Get Razorpay keys
    $razorpay_key_id = $payment_settings['razorpay_key_id'] ?? '';
    $razorpay_key_secret = $payment_settings['razorpay_key_secret'] ?? '';
    $test_mode = isset($payment_settings['test_mode']) && $payment_settings['test_mode'] == '1';
    
    // If keys are not found in database, try to get from .env
    if (empty($razorpay_key_id) || empty($razorpay_key_secret)) {
        // Load environment variables
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
        $dotenv->load();
        $razorpay_key_id = $_ENV['RAZORPAY_KEY_ID'] ?? '';
        $razorpay_key_secret = $_ENV['RAZORPAY_KEY_SECRET'] ?? '';
        
        // If still empty, show error
        if (empty($razorpay_key_id) || empty($razorpay_key_secret)) {
            throw new Exception("Payment gateway is not properly configured. Please contact support.");
        }
    }
    
    // Initialize Razorpay API
    $api = new Razorpay\Api\Api($razorpay_key_id, $razorpay_key_secret);
    
    // Check if there's an existing order ID
    $order_id = $payment['transaction_id'] ?? null;
    
    // If no order exists, create a new one
    if (empty($order_id)) {
        $orderData = [
            'receipt' => 'membership_' . $payment['membership_id'],
            'amount' => $payment['amount'] * 100, // Convert to paise
            'currency' => 'INR',
            'notes' => [
                'membership_id' => $payment['membership_id'],
                'payment_id' => $payment_id,
                'gym_id' => $payment['gym_id'],
                'plan_id' => $payment['plan_id'],
                'user_id' => $_SESSION['user_id']
            ]
        ];
        
        $razorpayOrder = $api->order->create($orderData);
        $order_id = $razorpayOrder['id'];
        
        // Update payment record with order ID
        $stmt = $conn->prepare("UPDATE payments SET transaction_id = ? WHERE id = ?");
        $stmt->execute([$order_id, $payment_id]);
    } else {
        // Verify the order exists and is valid
        try {
            $razorpayOrder = $api->order->fetch($order_id);
        } catch (Exception $e) {
            // If order doesn't exist, create a new one
            $orderData = [
                'receipt' => 'membership_' . $payment['membership_id'],
                'amount' => $payment['amount'] * 100, // Convert to paise
                'currency' => 'INR',
                'notes' => [
                    'membership_id' => $payment['membership_id'],
                    'payment_id' => $payment_id,
                    'gym_id' => $payment['gym_id'],
                    'plan_id' => $payment['plan_id'],
                    'user_id' => $_SESSION['user_id']
                ]
            ];
            
            $razorpayOrder = $api->order->create($orderData);
            $order_id = $razorpayOrder['id'];
            
            // Update payment record with new order ID
            $stmt = $conn->prepare("UPDATE payments SET transaction_id = ? WHERE id = ?");
            $stmt->execute([$order_id, $payment_id]);
        }
    }
    
    // Get user details for prefilling
    $stmt = $conn->prepare("SELECT username, email, phone FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Prepare data for the payment page
    $payment_data = [
        'key' => $razorpay_key_id,
        'amount' => $payment['amount'] * 100, // in paise
        'currency' => 'INR',
        'name' => 'Fitness Hub',
        'description' => $payment['plan_name'] . ' Membership',
        'order_id' => $order_id,
        'prefill' => [
            'name' => $user['username'] ?? '',
            'email' => $user['email'] ?? '',
            'contact' => $user['phone'] ?? ''
        ],
        'notes' => [
            'membership_id' => $payment['membership_id'],
            'payment_id' => $payment_id,
            'gym_id' => $payment['gym_id'],
            'plan_id' => $payment['plan_id']
        ],
        'theme' => [
            'color' => '#EAB308' // Yellow-500 color
        ]
    ];
    
    // Pass data to the payment page
    $_SESSION['payment_data'] = $payment_data;
    $_SESSION['payment_details'] = [
        'membership_id' => $payment['membership_id'],
        'payment_id' => $payment_id,
        'amount' => $payment['amount'],
        'gym_name' => $payment['gym_name'],
        'plan_name' => $payment['plan_name'],
        'test_mode' => $test_mode
    ];
    
    // Redirect to payment page
    header('Location: payment_page.php');
    exit();
    
} catch (Exception $e) {
    // Log error
    error_log("Payment processing error: " . $e->getMessage());
    
    // Set error message
    $_SESSION['error'] = "Payment processing failed: " . $e->getMessage();
    
    // Redirect to membership page
    header('Location: view_membership.php');
    exit();
}
?>
