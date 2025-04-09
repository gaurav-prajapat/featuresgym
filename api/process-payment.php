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
    $transaction_id = filter_input(INPUT_POST, 'transaction_id', FILTER_SANITIZE_STRING);
    $payment_method = filter_input(INPUT_POST, 'payment_method', FILTER_SANITIZE_STRING);
    $gym_id = filter_input(INPUT_POST, 'gym_id', FILTER_VALIDATE_INT);

    if (!$payment_id || !$transaction_id || !$payment_method || !$gym_id) {
        $response['message'] = 'Missing or invalid parameters';
        echo json_encode($response);
        exit();
    }

    // Connect to database
    $db = new GymDatabase();
    $conn = $db->getConnection();

    // Start transaction
    $conn->beginTransaction();

    // Verify the payment exists and is pending
    $stmt = $conn->prepare("SELECT * FROM payments WHERE id = ? AND user_id = ? AND gym_id = ? AND status = 'pending'");
    $stmt->execute([$payment_id, $_SESSION['user_id'], $gym_id]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        $conn->rollBack();
        $response['message'] = 'Invalid payment or payment already processed';
        echo json_encode($response);
        exit();
    }

    // Update payment record
    $stmt = $conn->prepare("
        UPDATE payments 
        SET 
            status = 'completed',
            transaction_id = :transaction_id,
            payment_method = :payment_method,
            payment_date = NOW()
        WHERE id = :payment_id
    ");

    $stmt->bindParam(':transaction_id', $transaction_id, PDO::PARAM_STR);
    $stmt->bindParam(':payment_method', $payment_method, PDO::PARAM_STR);
    $stmt->bindParam(':payment_id', $payment_id, PDO::PARAM_INT);

    if ($stmt->execute()) {
        $conn->commit();
        $response = [
            'status' => 'success',
            'payment_id' => $payment_id,
            'message' => 'Payment processed successfully'
        ];
    } else {
        $conn->rollBack();
        $response['message'] = 'Failed to update payment record';
    }
} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollBack();
    }
    error_log('Error in process-payment.php: ' . $e->getMessage());
    $response['message'] = 'An error occurred: ' . $e->getMessage();
}

echo json_encode($response);
exit();
