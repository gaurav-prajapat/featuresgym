<?php
session_start();
require '../config/database.php';

if (!isset($_SESSION['owner_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['count' => 0]);
    exit;
}

$db = new GymDatabase();
$conn = $db->getConnection();

$stmt = $conn->prepare("
    SELECT COUNT(*) as count
    FROM schedules s 
    WHERE s.gym_id = (SELECT gym_id FROM gyms WHERE owner_id = ?)
    AND DATE(s.start_date) = CURRENT_DATE
    AND s.status = 'scheduled'
");
$stmt->execute([$_SESSION['owner_id']]);
$result = $stmt->fetch(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode(['count' => $result['count']]);
