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

$gym_id = isset($_GET['gym_id']) ? (int)$_GET['gym_id'] : 0;

if (!$gym_id) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Missing gym ID']);
    exit();
}

try {
    // Get operating hours for this gym
    $stmt = $conn->prepare("
        SELECT day, morning_open_time, morning_close_time, evening_open_time, evening_close_time
        FROM gym_operating_hours
        WHERE gym_id = ?
        ORDER BY FIELD(day, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday', 'Daily')
    ");
    $stmt->execute([$gym_id]);
    $hours = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format times for display
    foreach ($hours as &$hour) {
        $hour['morning_open_time'] = date('h:i A', strtotime($hour['morning_open_time']));
        $hour['morning_close_time'] = date('h:i A', strtotime($hour['morning_close_time']));
        $hour['evening_open_time'] = date('h:i A', strtotime($hour['evening_open_time']));
        $hour['evening_close_time'] = date('h:i A', strtotime($hour['evening_close_time']));
    }
    
    header('Content-Type: application/json');
    if (count($hours) > 0) {
        echo json_encode([
            'success' => true,
            'hours' => $hours
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'No operating hours found for this gym'
        ]);
    }
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
