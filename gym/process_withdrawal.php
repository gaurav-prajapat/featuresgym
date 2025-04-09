<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['owner_id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: withdraw.php');
    exit;
}

$db = new GymDatabase();
$conn = $db->getConnection();

try {
    $conn->beginTransaction();

    $amount = $_POST['amount'];
    $payment_method_id = $_POST['payment_method'];
    $owner_id = $_SESSION['owner_id'];

    // Validate minimum withdrawal amount
    if ($amount < 500) {
        throw new Exception('Minimum withdrawal amount is ₹500');
    }

    // Get gym ID
    $gymStmt = $conn->prepare("SELECT gym_id FROM gyms WHERE owner_id = ?");
    $gymStmt->execute([$owner_id]);
    $gym_id = $gymStmt->fetchColumn();

    // Verify available balance
    $balanceStmt = $conn->prepare("
        SELECT COALESCE(SUM(amount), 0) as total_revenue 
        FROM gym_revenue 
        WHERE gym_id = ? 
        AND date <= CURRENT_DATE()
    ");
    $balanceStmt->execute([$gym_id]);
    $balance = $balanceStmt->fetchColumn();

    if ($amount > $balance) {
        throw new Exception('Insufficient balance');
    }

    // Verify payment method belongs to owner
    $methodStmt = $conn->prepare("SELECT id FROM payment_methods WHERE id = ? AND owner_id = ?");
    $methodStmt->execute([$payment_method_id, $owner_id]);
    if (!$methodStmt->fetch()) {
        throw new Exception('Invalid payment method');
    }

    // Create withdrawal record
    $withdrawalStmt = $conn->prepare("
        INSERT INTO withdrawals (
            gym_id,
            amount,
            bank_account,
            status,
            created_at
        ) VALUES (?, ?, ?, 'pending', CURRENT_TIMESTAMP)
    ");
    
    $withdrawalStmt->execute([
        $gym_id,
        $amount,
        $payment_method_id
    ]);

    // Update gym revenue
    $revenueStmt = $conn->prepare("
        INSERT INTO gym_revenue (
            gym_id,
            date,
            amount,
            source_type
        ) VALUES (?, CURRENT_DATE, ?, 'withdrawal')
    ");
    
    $revenueStmt->execute([
        $gym_id,
        -$amount
    ]);

    $conn->commit();
    $_SESSION['success'] = 'Withdrawal request submitted successfully';
    
} catch (Exception $e) {
    $conn->rollBack();
    $_SESSION['error'] = $e->getMessage();
}

header('Location: withdraw.php');
exit;
?>