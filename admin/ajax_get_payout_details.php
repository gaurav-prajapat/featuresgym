<?php
require_once '../config/database.php';
session_start();

// Check if user is logged in as admin
if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$db = new GymDatabase();
$conn = $db->getConnection();

// Get payout ID
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid payout ID']);
    exit();
}

try {
    // Get withdrawal details
    $stmt = $conn->prepare("
        SELECT w.*, g.name as gym_name, g.city, g.state, 
               u.username as owner_name, u.email as owner_email,
               pm.method_type, pm.account_name, pm.bank_name, pm.account_number, pm.ifsc_code, pm.upi_id,
               a.username as admin_name
        FROM withdrawals w
        JOIN gyms g ON w.gym_id = g.gym_id
        JOIN users u ON g.owner_id = u.id
        LEFT JOIN payment_methods pm ON w.payment_method_id = pm.id
        LEFT JOIN users a ON w.admin_id = a.id
        WHERE w.id = ?
    ");
    $stmt->execute([$id]);
    $payout = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$payout) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Payout not found']);
        exit();
    }
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'payout' => $payout]);
    
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
