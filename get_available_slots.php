<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$schedule_id = $_GET['schedule_id'] ?? null;
$date = $_GET['date'] ?? date('Y-m-d', strtotime('+1 day'));

if (!$schedule_id) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Schedule ID is required']);
    exit;
}

$db = new GymDatabase();
$conn = $db->getConnection();
$user_id = $_SESSION['user_id'];

// Get the gym ID from the schedule
$stmt = $conn->prepare("SELECT gym_id FROM schedules WHERE id = ? AND user_id = ?");
$stmt->execute([$schedule_id, $user_id]);
$gym_id = $stmt->fetchColumn();

if (!$gym_id) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid schedule ID']);
    exit;
}

// Get gym operating hours
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

// Generate time slots
$timeSlots = [];
$currentTime = new DateTime();
$selectedDate = new DateTime($date);
$isToday = $selectedDate->format('Y-m-d') === $currentTime->format('Y-m-d');

if ($hours) {
    // Morning slots
    if (!empty($hours['morning_open_time']) && !empty($hours['morning_close_time'])) {
        $morning_start = strtotime($hours['morning_open_time']);
        $morning_end = strtotime($hours['morning_close_time']);
        
        for ($time = $morning_start; $time <= $morning_end; $time += 3600) {
            $timeSlot = date('H:i:s', $time);
            
            // For today, only include time slots that are at least 1 hour in the future
            if (!$isToday || strtotime($date . ' ' . $timeSlot) > strtotime('+1 hour')) {
                $timeSlots[] = $timeSlot;
            }
        }
    }

    // Evening slots
    if (!empty($hours['evening_open_time']) && !empty($hours['evening_close_time'])) {
        $evening_start = strtotime($hours['evening_open_time']);
        $evening_end = strtotime($hours['evening_close_time']);
        
        for ($time = $evening_start; $time <= $evening_end; $time += 3600) {
            $timeSlot = date('H:i:s', $time);
            
            // For today, only include time slots that are at least 1 hour in the future
            if (!$isToday || strtotime($date . ' ' . $timeSlot) > strtotime('+1 hour')) {
                $timeSlots[] = $timeSlot;
            }
        }
    }
}

// If no operating hours found, provide default time slots
if (empty($timeSlots)) {
    // Default slots from 6 AM to 10 PM
    for ($hour = 6; $hour <= 22; $hour++) {
        $timeSlot = sprintf("%02d:00:00", $hour);
        
        // For today, only include time slots that are at least 1 hour in the future
        if (!$isToday || strtotime($date . ' ' . $timeSlot) > strtotime('+1 hour')) {
            $timeSlots[] = $timeSlot;
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

// Format slots for response
$formattedSlots = [];
foreach ($timeSlots as $time) {
    $currentOccupancy = isset($occupancyByTime[$time]) ? (int)$occupancyByTime[$time] : 0;
    $available = 50 - $currentOccupancy; // Assuming max capacity is 50
    
    if ($available > 0) {
        $formattedSlots[] = [
            'time' => $time,
            'formatted_time' => date('g:i A', strtotime($time)),
            'available' => $available
        ];
    }
}

header('Content-Type: application/json');
echo json_encode(['slots' => $formattedSlots]);
