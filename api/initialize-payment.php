<?php
header('Content-Type: application/json');
require_once '../config/database.php';
require_once '../includes/csrf.php';
session_start();

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
    $amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
    $base_amount = filter_input(INPUT_POST, 'base_amount', FILTER_VALIDATE_FLOAT);
    $gym_id = filter_input(INPUT_POST, 'gym_id', FILTER_VALIDATE_INT);
    $related_id = filter_input(INPUT_POST, 'related_id', FILTER_VALIDATE_INT);

    if (!$amount || !$gym_id || !$related_id) {
        $response['message'] = 'Missing or invalid parameters';
        echo json_encode($response);
        exit();
    }

    // Connect to database
    $db = new GymDatabase();
    $conn = $db->getConnection();

    // Create a payment record
    $stmt = $conn->prepare("
        INSERT INTO payments (
            user_id, gym_id, amount, base_amount, status, created_at
        ) VALUES (
            :user_id, :gym_id, :amount, :base_amount, 'pending', NOW()
        )
    ");

    $stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
    $stmt->bindParam(':gym_id', $gym_id, PDO::PARAM_INT);
    $stmt->bindParam(':amount', $amount, PDO::PARAM_STR);
    $stmt->bindParam(':base_amount', $base_amount, PDO::PARAM_STR);

    if ($stmt->execute()) {
        $payment_id = $conn->lastInsertId();
        $response = [
            'status' => 'success',
            'payment_id' => $payment_id,
            'message' => 'Payment initialized successfully'
        ];
    } else {
        $response['message'] = 'Failed to create payment record';
    }
} catch (Exception $e) {
    error_log('Error in initialize-payment.php: ' . $e->getMessage());
    $response['message'] = 'An error occurred: ' . $e->getMessage();
}

echo json_encode($response);
exit();
