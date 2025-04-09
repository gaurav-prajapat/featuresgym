<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['owner_id']) || empty($_POST['method_type'])) {
    header('Location: withdraw.php');
    exit;
}

$db = new GymDatabase();
$conn = $db->getConnection();

$method_type = $_POST['method_type'];
$owner_id = $_SESSION['owner_id'];

// Check if this is the first payment method (will be set as primary)
$stmt = $conn->prepare("SELECT COUNT(*) FROM payment_methods WHERE owner_id = ?");
$stmt->execute([$owner_id]);
$is_first_method = $stmt->fetchColumn() === 0;

try {
    $conn->beginTransaction();

    if ($method_type === 'bank') {
        $stmt = $conn->prepare("
            INSERT INTO payment_methods (
                owner_id, 
                method_type, 
                account_name, 
                account_number, 
                ifsc_code, 
                bank_name,
                is_primary
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $owner_id,
            'bank',
            $_POST['account_name'],
            $_POST['account_number'],
            $_POST['ifsc_code'],
            $_POST['bank_name'],
            $is_first_method
        ]);
    } else {
        $stmt = $conn->prepare("
            INSERT INTO payment_methods (
                owner_id, 
                method_type, 
                upi_id,
                is_primary
            ) VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
            $owner_id,
            'upi',
            $_POST['upi_id'],
            $is_first_method
        ]);
    }

    $conn->commit();
    header('Location: withdraw.php?success=payment_method_added');

} catch (PDOException $e) {
    $conn->rollBack();
    header('Location: withdraw.php?error=failed_to_add_payment_method');
}
