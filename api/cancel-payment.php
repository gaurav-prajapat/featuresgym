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
    $payment_id = filter_input(INPUT_POST, 'payment_id', FILTER_VALIDATE_INT);
    $gym_id = filter_input(INPUT_POST, 'gym_id', FILTER_VALIDATE_INT);
    $error_message = filter_input(INPUT_POST, 'error_message', FILTER_SANITIZE_STRING);

    if (!$payment_id || !$gym_id) {
        $response['message'] = 'Missing or invalid parameters';
        echo json_encode($response);
        exit();
    }

    // Connect to database
    $db = new GymDatabase();
    $conn = $db->getConnection();

    // Update payment record
    $stmt = $conn->prepare("
        UPDATE payments 
        SET 
            status = 'failed',
            notes = :error_message
        WHERE id = :payment_id AND user_id = :user_id AND gym_id = :gym_id
    ");

    $stmt->bindParam(':error_message', $error_message, PDO::PARAM_STR);
    $stmt->bindParam(':payment_id', $payment_id, PDO::PARAM_INT);
    $stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
    $stmt->bindParam(':gym_id', $gym_id, PDO::PARAM_INT);

    if ($stmt->execute()) {
        $response = [
            'status' => 'success',
            'message' => 'Payment cancelled successfully'
        ];
    } else {
        $response['message'] = 'Failed to update payment record';
    }
} catch (Exception $e) {
    error_log('Error in cancel-payment.php: ' . $e->getMessage());
    $response['message'] = 'An error occurred: ' . $e->getMessage();
}

echo json_encode($response);
exit();
