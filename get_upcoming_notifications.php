<?php
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$db = new GymDatabase();
$conn = $db->getConnection();
$user_id = $_SESSION['user_id'];

// Get upcoming workouts within the next 24 hours
$stmt = $conn->prepare("
    SELECT s.id, s.start_date, s.start_time, g.name as gym_name
    FROM schedules s
    JOIN gyms g ON s.gym_id = g.gym_id
    WHERE s.user_id = ?
    AND s.status = 'scheduled'
    AND CONCAT(s.start_date, ' ', s.start_time) BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 24 HOUR)
    ORDER BY s.start_date ASC, s.start_time ASC
");
$stmt->execute([$user_id]);
$upcoming_workouts = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode(['upcoming_workouts' => $upcoming_workouts]);
