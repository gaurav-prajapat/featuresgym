<?php
require_once '../config/database.php';
session_start();

// Check if user is logged in as gym owner
if (!isset($_SESSION['owner_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Validate input
if (!isset($_POST['gym_id']) || !isset($_POST['is_open'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit;
}

$gym_id = (int)$_POST['gym_id'];
$is_open = (int)$_POST['is_open'];
$owner_id = $_SESSION['owner_id'];

// Connect to database
$db = new GymDatabase();
$conn = $db->getConnection();

// Verify gym belongs to this owner
$stmt = $conn->prepare("SELECT * FROM gyms WHERE gym_id = ? AND owner_id = ?");
$stmt->execute([$gym_id, $owner_id]);
$gym = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$gym) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access to this gym']);
    exit;
}

// Update gym status
$stmt = $conn->prepare("UPDATE gyms SET is_open = ? WHERE gym_id = ?");
$result = $stmt->execute([$is_open, $gym_id]);

if ($result) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
