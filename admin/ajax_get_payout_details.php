<?php
require_once '../config/database.php';
session_start();

// Check if user is logged in as admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Check if ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid payout ID']);
    exit();
}

$payout_id = (int)$_GET['id'];
$db = new GymDatabase();
$conn = $db->getConnection();

try {
    // Get payout details
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
    $stmt->execute([$payout_id]);
    $payout = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($payout) {
        echo json_encode(['success' => true, 'payout' => $payout]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Payout not found']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
