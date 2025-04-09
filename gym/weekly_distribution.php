<?php
require_once 'config/database.php';

$db = new GymDatabase();
$conn = $db->getConnection();

try {
    $conn->beginTransaction();
    
    // Get all pending revenues >= 1000
    $stmt = $conn->prepare("
        SELECT 
            visited_gym_id as gym_id,
            SUM(daily_rate) as total_amount
        FROM gym_visit_revenue
        WHERE distribution_status = 'pending'
        GROUP BY visited_gym_id
        HAVING total_amount >= 1000
    ");
    $stmt->execute();
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Create revenue distribution record
        $distributeStmt = $conn->prepare("
            INSERT INTO gym_revenue (
                gym_id,
                date,
                amount,
                source_type,
                notes
            ) VALUES (?, CURRENT_DATE, ?, 'distribution', 'Weekly revenue distribution')
        ");
        $distributeStmt->execute([$row['gym_id'], $row['total_amount']]);
        
        // Update processed records
        $updateStmt = $conn->prepare("
            UPDATE gym_visit_revenue
            SET distribution_status = 'processed'
            WHERE visited_gym_id = ?
            AND distribution_status = 'pending'
        ");
        $updateStmt->execute([$row['gym_id']]);
    }
    
    $conn->commit();
    
} catch (Exception $e) {
    $conn->rollBack();
    error_log($e->getMessage());
}
