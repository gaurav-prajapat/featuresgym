<?php
require_once '../../config/database.php';
session_start();

// Check if user is logged in as admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

$db = new GymDatabase();
$conn = $db->getConnection();

$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$gym_id = isset($_GET['gym_id']) ? (int)$_GET['gym_id'] : 0;

if (!$user_id || !$gym_id) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Missing required parameters']);
    exit();
}

try {
    // Check if user has an active membership for this gym
    $stmt = $conn->prepare("
              SELECT um.id, um.end_date, gmp.plan_name, gmp.price, gmp.duration
        FROM user_memberships um
        JOIN gym_membership_plans gmp ON um.plan_id = gmp.plan_id
        WHERE um.user_id = ? AND um.gym_id = ? AND um.status = 'active' 
        AND CURDATE() BETWEEN um.start_date AND um.end_date
        ORDER BY um.end_date DESC
        LIMIT 1
    ");
    $stmt->execute([$user_id, $gym_id]);
    $membership = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get daily rate for this gym
    $stmt = $conn->prepare("
        SELECT price FROM gym_membership_plans 
        WHERE gym_id = ? AND duration = 'Daily' 
        ORDER BY price ASC LIMIT 1
    ");
    $stmt->execute([$gym_id]);
    $daily_plan = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $daily_rate = $daily_plan ? $daily_plan['price'] : 0;
    
    if ($membership) {
        header('Content-Type: application/json');
        echo json_encode([
            'has_membership' => true,
            'membership_id' => $membership['id'],
            'plan_name' => $membership['plan_name'],
            'end_date' => date('M d, Y', strtotime($membership['end_date'])),
            'daily_rate' => $daily_rate
        ]);
    } else {
        header('Content-Type: application/json');
        echo json_encode([
            'has_membership' => false,
            'daily_rate' => $daily_rate
        ]);
    }
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}

