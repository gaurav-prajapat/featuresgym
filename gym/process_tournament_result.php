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
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['tournament_id'])) {
    $_SESSION['error'] = "Invalid request.";
    header('Location: tournaments.php');
    exit;
}

$tournament_id = (int)$_POST['tournament_id'];
$user_id = (int)$_POST['user_id'];
$position = (int)$_POST['position'];
$score = trim($_POST['score']);
$prize_amount = (float)$_POST['prize_amount'];

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

// Check if we're updating an existing result or adding a new one
if (isset($_POST['result_id']) && !empty($_POST['result_id'])) {
    // Update existing result
    $result_id = (int)$_POST['result_id'];
    
    try {
        $stmt = $conn->prepare("
            UPDATE tournament_results 
            SET user_id = ?, position = ?, score = ?, prize_amount = ?, updated_at = NOW()
            WHERE id = ? AND tournament_id = ?
        ");
        
        $stmt->execute([$user_id, $position, $score, $prize_amount, $result_id, $tournament_id]);
        
        $_SESSION['success'] = "Tournament result updated successfully!";
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error updating result: " . $e->getMessage();
    }
} else {
    // Add new result
    try {
        // Check if this participant already has a result
        $stmt = $conn->prepare("
            SELECT id FROM tournament_results
            WHERE tournament_id = ? AND user_id = ?
        ");
        $stmt->execute([$tournament_id, $user_id]);
        
        if ($stmt->rowCount() > 0) {
            $_SESSION['error'] = "This participant already has a result. Please edit the existing result instead.";
        } else {
            $stmt = $conn->prepare("
                INSERT INTO tournament_results 
                (tournament_id, user_id, position, score, prize_amount, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([$tournament_id, $user_id, $position, $score, $prize_amount]);
            
            $_SESSION['success'] = "Tournament result added successfully!";
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error adding result: " . $e->getMessage();
    }
}

// Redirect back to tournament details
header("Location: tournaments.php?id=$tournament_id");
exit;
