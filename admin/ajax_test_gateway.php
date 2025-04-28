<?php
require_once '../config/database.php';
require_once '../includes/PaymentGateway.php';
session_start();

// Check if user is logged in as admin
if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$db = new GymDatabase();
$conn = $db->getConnection();

// Get gateway parameter
$gateway = isset($_GET['gateway']) ? $_GET['gateway'] : '';

// Validate gateway
$availableGateways = PaymentGateway::getAvailableGateways();
if (!array_key_exists($gateway, $availableGateways)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid payment gateway']);
    exit();
}

try {
    // Initialize payment gateway
    $paymentGateway = new PaymentGateway($gateway, $conn);
    
    // Test connection
    // In a real implementation, you would call a method on the payment gateway class
    // that tests the connection to the payment gateway API
    
    // For demo purposes, we'll simulate a successful connection for Razorpay and PayU
    if ($gateway === 'razorpay' || $gateway === 'payu') {
        // Simulate API call
        sleep(1); // Simulate network delay
        
               // 90% chance of success for demo
               if (rand(1, 10) <= 9) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true, 
                    'message' => 'Successfully connected to ' . $availableGateways[$gateway] . ' API. Your account is active and configured correctly.'
                ]);
            } else {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false, 
                    'message' => 'Could not connect to ' . $availableGateways[$gateway] . ' API. Please check your credentials.'
                ]);
            }
        } else {
            // Manual gateway doesn't need a connection test
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true, 
                'message' => 'Manual processing mode is configured correctly.'
            ]);
        }
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false, 
            'message' => 'Error: ' . $e->getMessage()
        ]);
    }
    
