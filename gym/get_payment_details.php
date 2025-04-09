<?php
require_once '../config/database.php';
session_start();

// Check if gym owner is logged in
if (!isset($_SESSION['owner_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Check if participant ID is provided
if (!isset($_GET['participant_id']) || empty($_GET['participant_id'])) {
    echo json_encode(['success' => false, 'message' => 'Participant ID is required']);
    exit();
}

$db = new GymDatabase();
$conn = $db->getConnection();
$owner_id = $_SESSION['owner_id'];
$participant_id = (int)$_GET['participant_id'];

// Get gym information
$stmt = $conn->prepare("SELECT gym_id FROM gyms WHERE owner_id = ?");
$stmt->execute([$owner_id]);
$gym_id = $stmt->fetchColumn();

if (!$gym_id) {
    echo json_encode(['success' => false, 'message' => 'No gym found for this owner']);
    exit();
}

// Get participant payment details
$stmt = $conn->prepare("
    SELECT tp.*, u.username, u.email, gt.title as tournament_title
    FROM tournament_participants tp
    JOIN users u ON tp.user_id = u.id
    JOIN gym_tournaments gt ON tp.tournament_id = gt.id
    WHERE tp.id = ? AND gt.gym_id = ?
");
$stmt->execute([$participant_id, $gym_id]);
$participant = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$participant) {
    echo json_encode(['success' => false, 'message' => 'Participant not found or you don\'t have permission to access this data']);
    exit();
}

// Format dates for display
$registration_date = date('M d, Y g:i A', strtotime($participant['registration_date']));
$payment_date = $participant['payment_date'] ? date('M d, Y g:i A', strtotime($participant['payment_date'])) : null;

// Prepare response data
$response = [
    'success' => true,
    'id' => $participant['id'],
    'user_id' => $participant['user_id'],
    'tournament_id' => $participant['tournament_id'],
    'tournament_title' => $participant['tournament_title'],
    'username' => $participant['username'],
    'email' => $participant['email'],
    'registration_date' => $registration_date,
    'payment_status' => $participant['payment_status'],
    'payment_date' => $payment_date,
    'payment_method' => $participant['payment_method'],
    'transaction_id' => $participant['transaction_id'],
    'payment_notes' => $participant['payment_notes'],
    'payment_proof' => $participant['payment_proof']
];

echo json_encode($response);
exit();
