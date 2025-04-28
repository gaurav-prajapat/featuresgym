<?php
require_once '../config/database.php';
session_start();

if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ./login.php');
    exit();
}

try {
    $db = new GymDatabase();
    $conn = $db->getConnection();
    
    $conn->beginTransaction();

    // Get completed visits pending payment
    $stmt = $conn->prepare("
        SELECT 
            s.gym_id,
            g.name as gym_name,
            g.bank_account_number,
            g.bank_ifsc,
            SUM(s.daily_rate) as total_revenue,
            COUNT(*) as visit_count
        FROM schedules s
        JOIN gyms g ON s.gym_id = g.gym_id
        WHERE s.status = 'completed' 
        AND s.payment_status = 'pending'
        AND YEARWEEK(s.start_date) = YEARWEEK(NOW())
        GROUP BY s.gym_id
    ");
    $stmt->execute();
    $payouts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Process each gym's payout
    foreach($payouts as $payout) {
        // Record payout transaction
        $stmt = $conn->prepare("
            INSERT INTO gym_payouts (
                gym_id, amount, visit_count, 
                payout_date, status, notes
            ) VALUES (?, ?, ?, NOW(), 'processed', ?)
        ");
        $stmt->execute([
            $payout['gym_id'],
            $payout['total_revenue'],
            $payout['visit_count'],
            "Weekly payout for " . date('Y-m-d')
        ]);

        // Update schedules payment status
        $stmt = $conn->prepare("
            UPDATE schedules 
            SET payment_status = 'paid',
                payout_date = NOW()
            WHERE gym_id = ? 
            AND status = 'completed'
            AND payment_status = 'pending'
        ");
        $stmt->execute([$payout['gym_id']]);

        // Update gym balance
        $stmt = $conn->prepare("
            UPDATE gyms 
            SET balance = balance + ?,
                last_payout_date = NOW()
            WHERE gym_id = ?
        ");
        $stmt->execute([$payout['total_revenue'], $payout['gym_id']]);
    }

    $conn->commit();
    $_SESSION['success'] = "Gym payouts processed successfully";
    header('Location: dashboard.php');
    exit();

} catch (Exception $e) {
    $conn->rollBack();
    error_log("Payout processing failed: " . $e->getMessage());
    $_SESSION['error'] = "Failed to process payouts: " . $e->getMessage();
    header('Location: dashboard.php');
    exit();
}
?>
