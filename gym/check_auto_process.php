<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a gym owner
if (!isset($_SESSION['owner_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$owner_id = $_SESSION['owner_id'];
$gym_id = $_GET['gym_id'] ?? null;

// If no specific gym_id is provided, get the first gym owned by this owner
if (!$gym_id) {
    $db = new GymDatabase();
    $conn = $db->getConnection();
    
    $gymStmt = $conn->prepare("SELECT gym_id FROM gyms WHERE owner_id = ? LIMIT 1");
    $gymStmt->execute([$owner_id]);
    $gym = $gymStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($gym) {
        $gym_id = $gym['gym_id'];
    } else {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'No gym found for this owner']);
        exit;
    }
}

// Include the auto-processing function
require_once 'auto_process_functions.php';

// Run the auto-processing
$result = processSchedulesAutomatically($gym_id);

// Return the result
header('Content-Type: application/json');
echo json_encode($result);