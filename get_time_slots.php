<?php
require_once 'config/database.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$gym_id = $_GET['gym_id'] ?? null;
$date = $_GET['date'] ?? date('Y-m-d');

if (!$gym_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing gym_id parameter']);
    exit;
}

$db = new GymDatabase();
$conn = $db->getConnection();

// Get day of week for the selected date
$dayOfWeek = date('l', strtotime($date));

// Get gym operating hours - first check for the specific day of the week
$hoursStmt = $conn->prepare("
    SELECT 
        morning_open_time, 
        morning_close_time, 
        evening_open_time, 
        evening_close_time
    FROM gym_operating_hours 
    WHERE gym_id = ? AND day = ?
");
$hoursStmt->execute([$gym_id, $dayOfWeek]);
$hours = $hoursStmt->fetch(PDO::FETCH_ASSOC);

// If no specific day found, fall back to 'Daily' hours
if (!$hours) {
    $hoursStmt = $conn->prepare("
        SELECT 
            morning_open_time, 
            morning_close_time, 
            evening_open_time, 
            evening_close_time
        FROM gym_operating_hours 
        WHERE gym_id = ? AND day = 'Daily'
    ");
    $hoursStmt->execute([$gym_id]);
    $hours = $hoursStmt->fetch(PDO::FETCH_ASSOC);
}

// Generate time slots
$timeSlots = [];
$currentTime = new DateTime();
// Add one hour to current time for the minimum bookable slot
$minBookableTime = clone $currentTime;
$minBookableTime->modify('+1 hour');
$minBookableTimeStr = $minBookableTime->format('H:i:s');
$isToday = $date === date('Y-m-d');

if ($hours) {
    // Morning slots
    if ($hours['morning_open_time'] && $hours['morning_close_time']) {
        $morning_start = strtotime($hours['morning_open_time']);
        $morning_end = strtotime($hours['morning_close_time']);
        for ($time = $morning_start; $time <= $morning_end; $time += 3600) {
            $timeSlot = date('H:i:s', $time);
            
            // For today, only include time slots that are at least 1 hour in the future
            if (!$isToday || $timeSlot >= $minBookableTimeStr) {
                $timeSlots[] = $timeSlot;
            }
        }
    }

    // Evening slots
    if ($hours['evening_open_time'] && $hours['evening_close_time']) {
        $evening_start = strtotime($hours['evening_open_time']);
        $evening_end = strtotime($hours['evening_close_time']);
        for ($time = $evening_start; $time <= $evening_end; $time += 3600) {
            $timeSlot = date('H:i:s', $time);
            
            // For today, only include time slots that are at least 1 hour in the future
            if (!$isToday || $timeSlot >= $minBookableTimeStr) {
                $timeSlots[] = $timeSlot;
            }
        }
    }
}

// Get current occupancy for each time slot
$occupancyStmt = $conn->prepare("
    SELECT start_time, COUNT(*) as current_occupancy 
    FROM schedules 
    WHERE gym_id = ? 
    AND start_date = ?
    GROUP BY start_time
");
$occupancyStmt->execute([$gym_id, $date]);
$occupancyByTime = $occupancyStmt->fetchAll(PDO::FETCH_KEY_PAIR);

echo json_encode([
    'timeSlots' => $timeSlots,
    'occupancy' => $occupancyByTime
]);
?>