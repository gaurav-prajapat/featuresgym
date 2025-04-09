<?php
session_start();
require '../config/database.php';

if (!isset($_SESSION['owner_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$visit_id = $data['visit_id'] ?? null;

if (!$visit_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$db = new GymDatabase();
$conn = $db->getConnection();

try {
    $stmt = $conn->prepare("
        UPDATE schedules 
        SET status = 'completed', 
            start_time = CURRENT_TIMESTAMP 
        WHERE id = ? AND gym_id = (
            SELECT gym_id FROM gyms WHERE owner_id = ?
        )
    ");
    
    $result = $stmt->execute([$visit_id, $_SESSION['owner_id']]);
    
    echo json_encode(['success' => $result]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
