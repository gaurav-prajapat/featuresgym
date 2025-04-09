<?php
ob_start();
require_once '../config/database.php';
session_start();

// Check if owner is logged in
if (!isset($_SESSION['owner_id'])) {
    header("Location: login.php");
    exit;
}

// Check if required parameters are provided
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || 
    !isset($_POST['tournament_id']) || 
    !isset($_POST['user_id']) || 
    !isset($_POST['status'])) {
    $_SESSION['error'] = "Invalid request.";
    header("Location: manage_tournaments.php");
    exit;
}

$db = new GymDatabase();
$conn = $db->getConnection();
$owner_id = $_SESSION['owner_id'];
$tournament_id = (int)$_POST['tournament_id'];
$user_id = (int)$_POST['user_id'];
$status = $_POST['status'];

// Validate status
if ($status !== 'paid' && $status !== 'pending') {
    $_SESSION['error'] = "Invalid payment status.";
    header("Location: tournaments.php?id=$tournament_id");
    exit;
}

try {
    // Start transaction
    $conn->beginTransaction();
    
    // Verify gym ownership
    $stmt = $conn->prepare("
        SELECT g.gym_id 
        FROM gyms g
        JOIN gym_tournaments t ON g.gym_id = t.gym_id
        WHERE g.owner_id = ? AND t.id = ?
    ");
    $stmt->execute([$owner_id, $tournament_id]);
    $gym = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$gym) {
        throw new Exception("Tournament not found or you don't have permission to update it.");
    }
    
    // Get participant details
    $stmt = $conn->prepare("
        SELECT tp.*, t.entry_fee, t.title
        FROM tournament_participants tp
        JOIN gym_tournaments t ON tp.tournament_id = t.id
        WHERE tp.tournament_id = ? AND tp.user_id = ?
    ");
    $stmt->execute([$tournament_id, $user_id]);
    $participant = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$participant) {
        throw new Exception("Participant not found in this tournament.");
    }
    
    // If changing from pending to paid, add revenue
    if ($participant['payment_status'] === 'pending' && $status === 'paid') {
        // Add revenue entry - using a unique identifier for each transaction
        $stmt = $conn->prepare("
            INSERT INTO gym_revenue (
                gym_id, source_type, source_id, user_id, amount, description, date
            ) VALUES (?, 'tournament', ?, ?, ?, ?, NOW())
        ");
        
        $description = "Tournament entry fee for: " . $participant['title'];
        $stmt->execute([
            $gym['gym_id'], 
            $tournament_id, 
            $user_id, 
            $participant['entry_fee'], 
            $description
        ]);
        
        // Update gym balance
        $stmt = $conn->prepare("
            UPDATE gyms 
            SET balance = balance + ? 
            WHERE gym_id = ?
        ");
        $stmt->execute([$participant['entry_fee'], $gym['gym_id']]);
    }
    
    // Update payment status
    $stmt = $conn->prepare("
        UPDATE tournament_participants 
        SET payment_status = ?, updated_at = NOW()
        WHERE tournament_id = ? AND user_id = ?
    ");
    $stmt->execute([$status, $tournament_id, $user_id]);
    
    // Log the activity
    $stmt = $conn->prepare("
        INSERT INTO activity_logs (
            user_id, user_type, action, details, ip_address, user_agent
        ) VALUES (?, 'owner', 'update_payment', ?, ?, ?)
    ");
    
    $details = "Updated payment status to '$status' for user #$user_id in tournament #$tournament_id";
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    
    $stmt->execute([$owner_id, $details, $ip, $user_agent]);
    
    // Commit transaction
    $conn->commit();
    
    $_SESSION['success'] = "Payment status updated successfully.";
    
} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollBack();
    $_SESSION['error'] = "Failed to update payment status: " . $e->getMessage();
}

// Redirect back to tournament page
header("Location: tournaments.php?id=$tournament_id");
exit;
?>
