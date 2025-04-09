<?php
require_once 'config/database.php';
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = "Please log in to register for tournaments.";
    header('Location: login.php');
    exit;
}

// Check if tournament ID is provided
if (!isset($_POST['tournament_id']) || empty($_POST['tournament_id'])) {
    $_SESSION['error'] = "Invalid tournament ID.";
    header('Location: tournaments.php');
    exit;
}

$db = new GymDatabase();
$conn = $db->getConnection();
$user_id = $_SESSION['user_id'];
$tournament_id = (int)$_POST['tournament_id'];

try {
    // Start transaction
    $conn->beginTransaction();
    
    // Check if tournament exists and is open for registration
    $stmt = $conn->prepare("
        SELECT t.*, 
               (SELECT COUNT(*) FROM tournament_participants WHERE tournament_id = t.id) as participant_count,
               (SELECT COUNT(*) FROM tournament_participants WHERE tournament_id = t.id AND user_id = ?) as is_registered
        FROM gym_tournaments t
        WHERE t.id = ?
    ");
    $stmt->execute([$user_id, $tournament_id]);
    $tournament = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$tournament) {
        throw new Exception("Tournament not found.");
    }
    
    if ($tournament['is_registered'] > 0) {
        throw new Exception("You are already registered for this tournament.");
    }
    
    if ($tournament['status'] !== 'upcoming') {
        throw new Exception("Registration is only available for upcoming tournaments.");
    }
    
    if (strtotime($tournament['registration_deadline']) < time()) {
        throw new Exception("Registration deadline has passed.");
    }
    
    if ($tournament['participant_count'] >= $tournament['max_participants']) {
        throw new Exception("Tournament has reached maximum participants.");
    }
    
    // Check if user has active membership with this gym
    $stmt = $conn->prepare("
        SELECT COUNT(*) as has_membership
        FROM user_memberships
        WHERE user_id = ? AND gym_id = ? AND status = 'active' AND end_date >= CURRENT_DATE
    ");
    $stmt->execute([$user_id, $tournament['gym_id']]);
    $membership = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($membership['has_membership'] <= 0) {
        throw new Exception("You need an active membership with this gym to register.");
    }
    
    // Register user for the tournament
    $stmt = $conn->prepare("
        INSERT INTO tournament_participants 
        (tournament_id, user_id, registration_date, payment_status) 
        VALUES (?, ?, NOW(), 'pending')
    ");
    $stmt->execute([$tournament_id, $user_id]);
    
    // Commit transaction
    $conn->commit();
    
    // Redirect to payment page if entry fee > 0, otherwise mark as paid
    if ($tournament['entry_fee'] > 0) {
        $_SESSION['success'] = "Registration successful! Please complete the payment to confirm your spot.";
        header("Location: tournament_payment.php?id=$tournament_id");
    } else {
        // If entry fee is 0, mark as paid directly
        $stmt = $conn->prepare("
            UPDATE tournament_participants 
            SET payment_status = 'paid', payment_date = NOW() 
            WHERE tournament_id = ? AND user_id = ?
        ");
        $stmt->execute([$tournament_id, $user_id]);
        
        $_SESSION['success'] = "Registration successful! Your spot is confirmed.";
        header("Location: tournament_details.php?id=$tournament_id");
    }
    
} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollBack();
    
    $_SESSION['error'] = "Registration failed: " . $e->getMessage();
    header("Location: tournament_details.php?id=$tournament_id");
}
exit;
