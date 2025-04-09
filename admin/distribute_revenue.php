<?php
ob_start();

require_once 'config/database.php';

session_start();

// Ensure admin authentication
if (!isset($_SESSION['admin_id'])) {
    exit(json_encode(['success' => false, 'error' => 'Not authenticated as admin']));
}

$db = new GymDatabase();
$conn = $db->getConnection();

try {
    $conn->beginTransaction();

    // Fetch pending revenues for processing
    $pendingStmt = $conn->prepare("
        SELECT id, visit_id, original_gym_id, visited_gym_id, daily_rate, visit_date 
        FROM gym_visit_revenue 
        WHERE distribution_status = 'pending'
    ");
    $pendingStmt->execute();
    $pendingRevenues = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($pendingRevenues)) {
        throw new Exception("No pending revenues to process.");
    }

    // Process each pending revenue
    $updateStatusStmt = $conn->prepare("
        UPDATE gym_visit_revenue 
        SET distribution_status = 'processed' 
        WHERE id = ?
    ");

    $addRevenueStmt = $conn->prepare("
        INSERT INTO gym_revenue 
        (gym_id, date, amount, source_type, notes) 
        VALUES (?, ?, ?, 'visit_revenue_distribution', ?)
    ");

    foreach ($pendingRevenues as $revenue) {
        // Distribute revenue to the visited gym
        $addRevenueStmt->execute([
            $revenue['visited_gym_id'],
            $revenue['visit_date'],
            $revenue['daily_rate'],
            "Distributed revenue for visit ID #{$revenue['visit_id']}"
        ]);

        // Mark the revenue as processed
        $updateStatusStmt->execute([$revenue['id']]);
    }

    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Revenues distributed successfully.']);

} catch (Exception $e) {
    $conn->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
