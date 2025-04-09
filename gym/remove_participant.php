<?php
require_once '../config/database.php';
session_start();

// Check if owner is logged in
if (!isset($_SESSION['owner_id'])) {
    header('Location: login.php');
    exit;
}

$db = new GymDatabase();
$conn = $db->getConnection();
$owner_id = $_SESSION['owner_id'];

// Validate request
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['participant_id']) || !isset($_POST['tournament_id'])) {
    $_SESSION['error'] = "Invalid request.";
    header('Location: tournaments.php');
    exit;
}

$participant_id = (int)$_POST['participant_id'];
$tournament_id = (int)$_POST['tournament_id'];

// Validate that this tournament belongs to the owner's gym
$stmt = $conn->prepare("
    SELECT t.* FROM gym_tournaments t
    JOIN gyms g ON t.gym_id = g.gym_id
    WHERE t.id = ? AND g.owner_id = ?
");
$stmt->execute([$tournament_id, $owner_id]);
$tournament = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tournament) {
    $_SESSION['error'] = "Tournament not found or you don't have permission to manage it.";
    header('Location: tournaments.php');
    exit;
}

try {
    // Get participant info for logging
    $stmt = $conn->prepare("
        SELECT p.*, u.username 
        FROM tournament_participants p
        JOIN users u ON p.user_id = u.id
        WHERE p.id = ? AND p.tournament_id = ?
    ");
    $stmt->execute([$participant_id, $tournament_id]);
    $participant = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$participant) {
        $_SESSION['error'] = "Participant not found.";
        header("Location: tournaments.php?id=$tournament_id");
        exit;
    }
    
    // Delete any results for this participant
    $stmt = $conn->prepare("
        DELETE FROM tournament_results
        WHERE tournament_id = ? AND user_id = ?
    ");
    $stmt->execute([$tournament_id, $participant['user_id']]);
    
    // Remove participant
    $stmt = $conn->prepare("
        DELETE FROM tournament_participants
        WHERE id = ? AND tournament_id = ?
    ");
    $stmt->execute([$participant_id, $tournament_id]);
    
    // Log the activity
    $stmt = $conn->prepare("
        INSERT INTO activity_logs (
            user_id, user_type, action, details, ip_address, user_agent
        ) VALUES (?, 'owner', 'remove_participant', ?, ?, ?)
    ");
    
    $details = "Removed participant: " . $participant['username'] . " from tournament: " . $tournament['title'];
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    
    $stmt->execute([$owner_id, $details, $ip, $user_agent]);
    
    $_SESSION['success'] = "Participant removed successfully.";
} catch (PDOException $e) {
    $_SESSION['error'] = "Error removing participant: " . $e->getMessage();
}

// Redirect back to tournament details
header("Location: tournaments.php?id=$tournament_id");
exit;
