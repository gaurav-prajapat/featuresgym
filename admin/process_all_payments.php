<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$db = new GymDatabase();
$conn = $db->getConnection();

try {
    $conn->beginTransaction();

    $stmt = $conn->prepare("
        UPDATE withdrawals 
        SET status = 'completed', 
            processed_at = CURRENT_TIMESTAMP 
        WHERE status = 'pending'
    ");
    $stmt->execute();

    $conn->commit();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    $conn->rollBack();
    echo json_encode(['success' => false, 'message' => 'Failed to process payments']);
}
