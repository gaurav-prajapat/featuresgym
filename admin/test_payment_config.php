<?php
// Check if admin is logged in
session_start();
if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Verify CSRF token
$request_data = json_decode(file_get_contents('php://input'), true);
$headers = getallheaders();
$csrf_token = $headers['X-CSRF-Token'] ?? '';

if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf_token)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Security validation failed']);
    exit();
}

require_once '../config/database.php';

// Get the key ID from the request
$key_id = $request_data['key_id'] ?? '';
$test_mode = $request_data['test_mode'] ?? false;

// Validate key ID
if (empty($key_id)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Razorpay Key ID is required']);
    exit();
}

// Get key secret from database
$db = new GymDatabase();
$conn = $db->getConnection();
$stmt = $conn->prepare("SELECT setting_value FROM payment_settings WHERE setting_key = 'razorpay_key_secret'");
$stmt->execute();
$key_secret = $stmt->fetchColumn();

if (empty($key_secret)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Razorpay Key Secret is not set in the database']);
    exit();
}

// Test the Razorpay API connection
try {
    // Initialize cURL session
    $ch = curl_init();
    
    // Set cURL options
    curl_setopt($ch, CURLOPT_URL, 'https://api.razorpay.com/v1/orders');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, $key_id . ':' . $key_secret);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    // Create a small test order
    $test_order_data = [
        'amount' => 100, // Minimum amount (₹1)
        'currency' => 'INR',
        'receipt' => 'test_' . time(),
        'notes' => [
            'purpose' => 'API test from admin panel'
        ]
    ];
    
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($test_order_data));
    
    // Execute cURL request
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    
    // Close cURL session
    curl_close($ch);
    
    // Check for cURL errors
    if ($curl_error) {
        throw new Exception('cURL Error: ' . $curl_error);
    }
    
    // Parse response
    $response_data = json_decode($response, true);
    
    // Check HTTP status code
    if ($http_code >= 200 && $http_code < 300) {
        // Success
        // Log the successful test
        $stmt = $conn->prepare("
            INSERT INTO activity_logs (
                user_id, user_type, action, details, ip_address, user_agent
            ) VALUES (?, 'admin', 'test_payment_config', 'Successfully tested Razorpay API connection', ?, ?)
        ");
        $stmt->execute([
            $_SESSION['admin_id'],
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT']
        ]);
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true, 
            'message' => 'Connection to Razorpay API successful! Your API keys are working correctly.',
            'order_id' => $response_data['id'] ?? null
        ]);
    } else {
        // API error
        $error_message = $response_data['error']['description'] ?? 'Unknown API error';
        
        // Log the failed test
        $stmt = $conn->prepare("
            INSERT INTO activity_logs (
                user_id, user_type, action, details, ip_address, user_agent
            ) VALUES (?, 'admin', 'test_payment_config', ?, ?, ?)
        ");
        $stmt->execute([
            $_SESSION['admin_id'],
            'Failed to test Razorpay API connection: ' . $error_message,
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT']
        ]);
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false, 
            'message' => 'API Error: ' . $error_message,
            'http_code' => $http_code
        ]);
    }
} catch (Exception $e) {
    // Log the exception
    $stmt = $conn->prepare("
        INSERT INTO activity_logs (
            user_id, user_type, action, details, ip_address, user_agent
        ) VALUES (?, 'admin', 'test_payment_config', ?, ?, ?)
    ");
    $stmt->execute([
        $_SESSION['admin_id'],
        'Exception during Razorpay API test: ' . $e->getMessage(),
        $_SERVER['REMOTE_ADDR'],
        $_SERVER['HTTP_USER_AGENT']
    ]);
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false, 
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
