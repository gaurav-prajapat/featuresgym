<?php
ob_start();
require_once 'config/database.php';
include 'includes/navbar.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = "Please log in to register for tournaments.";
    header('Location: login.php');
    exit;
}

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['tournament_id'])) {
    $_SESSION['error'] = "Invalid request.";
    header('Location: tournaments.php');
    exit;
}

$db = new GymDatabase();
$conn = $db->getConnection();
$user_id = $_SESSION['user_id'];
$tournament_id = (int)$_POST['tournament_id'];

// Get tournament details
$stmt = $conn->prepare("
    SELECT t.*, g.name as gym_name, g.owner_id,
           (SELECT COUNT(*) FROM tournament_participants WHERE tournament_id = t.id) as participant_count,
           (SELECT COUNT(*) FROM tournament_participants WHERE tournament_id = t.id AND user_id = ?) as is_registered
    FROM gym_tournaments t
    JOIN gyms g ON t.gym_id = g.gym_id
    WHERE t.id = ?
");
$stmt->execute([$user_id, $tournament_id]);
$tournament = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tournament) {
    $_SESSION['error'] = "Tournament not found.";
    header('Location: tournaments.php');
    exit;
}

// Check if already registered
if ($tournament['is_registered'] > 0) {
    $_SESSION['error'] = "You are already registered for this tournament.";
    header("Location: tournament_details.php?id=$tournament_id");
    exit;
}

// Check if registration is open
$registration_open = $tournament['status'] === 'upcoming' && 
                     strtotime($tournament['registration_deadline']) >= time() && 
                     $tournament['participant_count'] < $tournament['max_participants'];

if (!$registration_open) {
    $_SESSION['error'] = "Registration for this tournament is closed.";
    header("Location: tournament_details.php?id=$tournament_id");
    exit;
}

// Check eligibility based on criteria set by gym owner
$eligibility_status = [
    'eligible' => true,
    'message' => ''
];

// Check membership requirements
if ($tournament['eligibility_type'] !== 'all') {
    // Check if user has an active membership with this gym
    $stmt = $conn->prepare("
        SELECT um.*, gmp.tier 
        FROM user_memberships um
        JOIN gym_membership_plans gmp ON um.plan_id = gmp.plan_id
        WHERE um.user_id = ? AND um.gym_id = ? AND um.status = 'active' 
        AND um.end_date >= CURRENT_DATE()
        ORDER BY um.end_date DESC LIMIT 1
    ");
    $stmt->execute([$user_id, $tournament['gym_id']]);
    $active_membership = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$active_membership) {
        $eligibility_status['eligible'] = false;
        $eligibility_status['message'] = "This tournament requires an active gym membership.";
    } else {
        // Check premium membership requirement
        if ($tournament['eligibility_type'] === 'premium_members') {
            // Get the gym's premium tier names
            $stmt = $conn->prepare("
                SELECT premium_tiers FROM gyms WHERE gym_id = ?
            ");
            $stmt->execute([$tournament['gym_id']]);
            $premium_tiers_json = $stmt->fetchColumn();
            $premium_tiers = $premium_tiers_json ? json_decode($premium_tiers_json, true) : ['premium'];
            
            if (!in_array($active_membership['tier'], $premium_tiers)) {
                $eligibility_status['eligible'] = false;
                $eligibility_status['message'] = "This tournament is only open to premium members.";
            }
        }
        
        // Check minimum membership days
        if ($tournament['min_membership_days'] > 0) {
            $membership_start = new DateTime($active_membership['start_date']);
            $today = new DateTime();
            $days_as_member = $today->diff($membership_start)->days;
            
            if ($days_as_member < $tournament['min_membership_days']) {
                $eligibility_status['eligible'] = false;
                $eligibility_status['message'] = "You need to be a member for at least " . $tournament['min_membership_days'] . " days to join this tournament.";
            }
        }
    }
} elseif ($tournament['eligibility_type'] === 'invite_only') {
    // Check if user has an invitation
    $stmt = $conn->prepare("
        SELECT * FROM tournament_invitations 
        WHERE tournament_id = ? AND user_id = ? AND status = 'active'
    ");
    $stmt->execute([$tournament_id, $user_id]);
    $invitation = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$invitation) {
        $eligibility_status['eligible'] = false;
        $eligibility_status['message'] = "This tournament is by invitation only.";
    }
}

// Check age restrictions if set
if ($eligibility_status['eligible'] && ($tournament['min_age'] !== null || $tournament['max_age'] !== null)) {
    // Get user's date of birth
    $stmt = $conn->prepare("SELECT date_of_birth FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $dob = $stmt->fetchColumn();
    
    if ($dob) {
        $birth_date = new DateTime($dob);
        $today = new DateTime();
        $age = $today->diff($birth_date)->y;
        
        if ($tournament['min_age'] !== null && $age < $tournament['min_age']) {
            $eligibility_status['eligible'] = false;
            $eligibility_status['message'] = "This tournament requires participants to be at least " . $tournament['min_age'] . " years old.";
        } elseif ($tournament['max_age'] !== null && $age > $tournament['max_age']) {
            $eligibility_status['eligible'] = false;
            $eligibility_status['message'] = "This tournament is open to participants up to " . $tournament['max_age'] . " years old.";
        }
    } else {
        // If date of birth is not set in profile
        $eligibility_status['eligible'] = false;
        $eligibility_status['message'] = "Please update your date of birth in your profile to verify age eligibility.";
    }
}

// Check gender restrictions if set
if ($eligibility_status['eligible'] && $tournament['gender_restriction'] !== 'none') {
    // Get user's gender
    $stmt = $conn->prepare("SELECT gender FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $gender = $stmt->fetchColumn();
    
    if (!$gender) {
        $eligibility_status['eligible'] = false;
        $eligibility_status['message'] = "Please update your gender in your profile to verify eligibility.";
    } elseif ($gender !== $tournament['gender_restriction']) {
        $eligibility_status['eligible'] = false;
        $eligibility_status['message'] = "This tournament is only open to " . 
            ($tournament['gender_restriction'] === 'male' ? 'male' : 'female') . " participants.";
    }
}

// If not eligible, redirect with error
if (!$eligibility_status['eligible']) {
    $_SESSION['error'] = "You are not eligible to register: " . $eligibility_status['message'];
    header("Location: tournament_details.php?id=$tournament_id");
    exit;
}

// Process registration
try {
    $conn->beginTransaction();
    
    // Determine if this is a paid tournament
    $is_paid_tournament = $tournament['entry_fee'] > 0;
    
    // For free tournaments, register immediately
    if (!$is_paid_tournament) {
        // Register user for the tournament with paid status
        $stmt = $conn->prepare("
            INSERT INTO tournament_participants (
                tournament_id, user_id, registration_date, payment_status, notes
            ) VALUES (?, ?, NOW(), 'paid', ?)
        ");
        $stmt->execute([
            $tournament_id, 
            $user_id, 
            "Registered via website - Free tournament"
        ]);
        
        // Create notification for the user
        $stmt = $conn->prepare("
            INSERT INTO notifications (
                user_id, type, title, message, related_id, created_at, is_read
            ) VALUES (?, 'tournament_registration', ?, ?, ?, NOW(), 0)
        ");
        $stmt->execute([
            $user_id,
            "Tournament Registration Confirmed",
            "You have successfully registered for " . $tournament['title'] . ". Your spot is confirmed.",
            $tournament_id
        ]);
        
        // Create notification for gym owner
        $stmt = $conn->prepare("
            INSERT INTO notifications (
                user_id, type, title, message, related_id, created_at, is_read
            ) VALUES (?, 'new_tournament_participant', ?, ?, ?, NOW(), 0)
        ");
        $stmt->execute([
            $tournament['owner_id'],
            "New Tournament Participant",
            "A new participant has registered for " . $tournament['title'] . ".",
            $tournament_id
        ]);
        
        // Log the activity
        $stmt = $conn->prepare("
            INSERT INTO activity_logs (
                user_id, user_type, action, details, ip_address, user_agent
            ) VALUES (?, 'user', 'tournament_registration', ?, ?, ?)
        ");
        
        $details = "Registered for tournament: " . $tournament['title'] . " (Free)";
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        
        $stmt->execute([$user_id, $details, $ip, $user_agent]);
        
        $conn->commit();
        
        $_SESSION['success'] = "Registration successful! Your spot in the tournament is confirmed.";
        header("Location: tournament_details.php?id=$tournament_id");
        exit;
    } 
    // For paid tournaments, create a pending registration and redirect to payment
    else {
        // Register user with pending payment status
        $stmt = $conn->prepare("
            INSERT INTO tournament_participants (
                tournament_id, user_id, registration_date, payment_status, notes
            ) VALUES (?, ?, NOW(), 'pending', ?)
        ");
        $stmt->execute([
            $tournament_id, 
            $user_id, 
            "Registered via website - Payment pending"
        ]);
        
        // Get the participant ID for the payment process
        $participant_id = $conn->lastInsertId();
        
        // Create notification for the user
        $stmt = $conn->prepare("
            INSERT INTO notifications (
                user_id, type, title, message, related_id, created_at, is_read
            ) VALUES (?, 'tournament_registration', ?, ?, ?, NOW(), 0)
        ");
        $stmt->execute([
            $user_id,
            "Tournament Registration Started",
            "You have initiated registration for " . $tournament['title'] . ". Please complete your payment to secure your spot.",
            $tournament_id
        ]);
        
        // Log the activity
        $stmt = $conn->prepare("
            INSERT INTO activity_logs (
                user_id, user_type, action, details, ip_address, user_agent
            ) VALUES (?, 'user', 'tournament_registration_initiated', ?, ?, ?)
        ");
        
        $details = "Initiated registration for tournament: " . $tournament['title'] . " (Payment pending)";
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        
        $stmt->execute([$user_id, $details, $ip, $user_agent]);
        
        $conn->commit();
        
        $_SESSION['success'] = "Registration initiated! Please complete your payment to secure your spot.";
        header("Location: tournament_payment.php?id=$tournament_id");
        exit;
    }
    
} catch (Exception $e) {
    $conn->rollBack();
    $_SESSION['error'] = "Registration failed: " . $e->getMessage();
    header("Location: tournament_details.php?id=$tournament_id");
    exit;
}
?>
