<?php
ob_start();
require_once '../config/database.php';
session_start();

// Check if user is logged in as admin
if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ./login.php');
    exit();
}

$db = new GymDatabase();
$conn = $db->getConnection();

// Get booking ID from URL
$booking_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$booking_id) {
    $_SESSION['error'] = "Invalid booking ID.";
    header('Location: all_bookings.php');
    exit();
}


// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle status change actions
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
        
        try {
            $conn->beginTransaction();
            
            if ($action === 'complete') {
                $stmt = $conn->prepare("UPDATE schedules SET status = 'completed', notes = CONCAT(IFNULL(notes, ''), '\n\nCompleted notes: " . $conn->quote($notes) . "') WHERE id = ?");
                $stmt->execute([$booking_id]);
                
                // Log the action
                $stmt = $conn->prepare("
                    INSERT INTO schedule_logs (user_id, schedule_id, action_type, notes)
                    VALUES (?, ?, 'complete', ?)
                ");
                $stmt->execute([$_SESSION['user_id'], $booking_id, "Marked as completed by admin. Notes: $notes"]);
                
                $_SESSION['success'] = "Booking marked as completed successfully.";
            } 
            elseif ($action === 'missed') {
                $stmt = $conn->prepare("UPDATE schedules SET status = 'missed', notes = CONCAT(IFNULL(notes, ''), '\n\nMissed notes: " . $conn->quote($notes) . "') WHERE id = ?");
                $stmt->execute([$booking_id]);
                
                // Log the action
                $stmt = $conn->prepare("
                    INSERT INTO schedule_logs (user_id, schedule_id, action_type, notes)
                    VALUES (?, ?, 'update', ?)
                ");
                $stmt->execute([$_SESSION['user_id'], $booking_id, "Marked as missed by admin. Notes: $notes"]);
                
                $_SESSION['success'] = "Booking marked as missed successfully.";
            }
            elseif ($action === 'cancel') {
                if (empty($notes)) {
                    throw new Exception("Cancellation reason is required.");
                }
                
                $stmt = $conn->prepare("UPDATE schedules SET status = 'cancelled', cancellation_reason = ?, notes = CONCAT(IFNULL(notes, ''), '\n\nCancellation notes: " . $conn->quote($notes) . "') WHERE id = ?");
                $stmt->execute([$notes, $booking_id]);
                
                // Log the action
                $stmt = $conn->prepare("
                    INSERT INTO schedule_logs (user_id, schedule_id, action_type, notes)
                    VALUES (?, ?, 'cancel', ?)
                ");
                $stmt->execute([$_SESSION['user_id'], $booking_id, "Cancelled by admin. Reason: $notes"]);
                
                $_SESSION['success'] = "Booking cancelled successfully.";
            }
            
            // Log in activity_logs
            $stmt = $conn->prepare("
                INSERT INTO activity_logs (
                    user_id, user_type, action, details, ip_address, user_agent
                ) VALUES (?, 'admin', ?, ?, ?, ?)
            ");
            
            $details = "Updated booking ID: $booking_id status to $action";
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            
            $stmt->execute([$_SESSION['user_id'], "update_booking_status", $details, $ip, $user_agent]);
            
            $conn->commit();
            
            header("Location: view_booking.php?id=$booking_id");
            exit();
            
        } catch (Exception $e) {
            $conn->rollBack();
            $_SESSION['error'] = $e->getMessage();
        }
    }
    
    // Handle send reminder
    if (isset($_POST['send_reminder'])) {
        try {
            // Get booking details for the email
            $stmt = $conn->prepare("
                SELECT s.*, u.username, u.email, g.name as gym_name
                FROM schedules s
                JOIN users u ON s.user_id = u.id
                JOIN gyms g ON s.gym_id = g.gym_id
                WHERE s.id = ?
            ");
            $stmt->execute([$booking_id]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$booking) {
                throw new Exception("Booking not found.");
            }
            
            // Send email reminder
            $to = $booking['email'];
            $subject = "Reminder: Your Upcoming Gym Session";
            
            $message = "
            <html>
            <head>
                <title>Booking Reminder</title>
            </head>
            <body>
                <p>Hello " . htmlspecialchars($booking['username']) . ",</p>
                <p>This is a friendly reminder about your upcoming gym session:</p>
                <p>
                    <strong>Gym:</strong> " . htmlspecialchars($booking['gym_name']) . "<br>
                    <strong>Date:</strong> " . date('F j, Y', strtotime($booking['start_date'])) . "<br>
                    <strong>Time:</strong> " . date('h:i A', strtotime($booking['start_time'])) . "
                </p>
                <p>We're looking forward to seeing you!</p>
                <p>If you need to reschedule, please contact us as soon as possible.</p>
                <p>Best regards,<br>FlexFit Team</p>
            </body>
            </html>
            ";
            
            // Set email headers
            $headers = "MIME-Version: 1.0" . "\r\n";
            $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
            $headers .= 'From: FlexFit <noreply@flexfit.com>' . "\r\n";
            
            // Send the email
            if (mail($to, $subject, $message, $headers)) {
                // Update reminder sent status
                $stmt = $conn->prepare("
                    UPDATE schedules 
                    SET reminder_sent = 1, last_reminder_sent = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$booking_id]);
                
                // Log the action
                $stmt = $conn->prepare("
                    INSERT INTO schedule_logs (user_id, schedule_id, action_type, notes)
                    VALUES (?, ?, 'update', ?)
                ");
                $stmt->execute([$_SESSION['user_id'], $booking_id, "Reminder email sent by admin"]);
                
                // Log in activity_logs
                $stmt = $conn->prepare("
                    INSERT INTO activity_logs (
                        user_id, user_type, action, details, ip_address, user_agent
                    ) VALUES (?, 'admin', ?, ?, ?, ?)
                ");
                
                $details = "Sent reminder email for booking ID: $booking_id to " . $booking['email'];
                $ip = $_SERVER['REMOTE_ADDR'] ?? null;
                $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
                
                $stmt->execute([$_SESSION['user_id'], "send_booking_reminder", $details, $ip, $user_agent]);
                
                $_SESSION['success'] = "Reminder email sent successfully.";
            } else {
                throw new Exception("Failed to send reminder email. Please try again later.");
            }
            
        } catch (Exception $e) {
            $_SESSION['error'] = $e->getMessage();
        }
        
        header("Location: view_booking.php?id=$booking_id");
        exit();
    }
}


// Handle booking status change if requested
if (isset($_POST['action'])) {
    $action = $_POST['action'];
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
    
    try {
        $conn->beginTransaction();
        
        if ($action === 'complete') {
            $stmt = $conn->prepare("UPDATE schedules SET status = 'completed', notes = ? WHERE id = ?");
            $stmt->execute([$notes, $booking_id]);
            $_SESSION['success'] = "Booking marked as completed.";
        } elseif ($action === 'cancel') {
            $stmt = $conn->prepare("UPDATE schedules SET status = 'cancelled', cancellation_reason = ?, notes = ? WHERE id = ?");
            $stmt->execute([$notes, $notes, $booking_id]);
            $_SESSION['success'] = "Booking cancelled successfully.";
        } elseif ($action === 'missed') {
            $stmt = $conn->prepare("UPDATE schedules SET status = 'missed', notes = ? WHERE id = ?");
            $stmt->execute([$notes, $booking_id]);
            $_SESSION['success'] = "Booking marked as missed.";
        }
        
        // Log the activity
        $stmt = $conn->prepare("
            INSERT INTO activity_logs (
                user_id, user_type, action, details, ip_address, user_agent
            ) VALUES (?, 'admin', ?, ?, ?, ?)
        ");
        
        $details = "Changed booking ID: $booking_id status to " . strtoupper($action);
        if (!empty($notes)) {
            $details .= " with notes: $notes";
        }
        
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        
        $stmt->execute([$_SESSION['user_id'], "update_booking_status", $details, $ip, $user_agent]);
        
        $conn->commit();
        
    } catch (PDOException $e) {
        $conn->rollBack();
        $_SESSION['error'] = "Database error: " . $e->getMessage();
    }
}

// Fetch booking details
try {
    $stmt = $conn->prepare("
        SELECT s.*, 
               u.username, u.email, u.phone, u.profile_image,
               g.name as gym_name, g.address as gym_address, g.city as gym_city, g.state as gym_state,
               g.phone as gym_phone, g.email as gym_email,
               gmp.plan_name, gmp.price as plan_price, gmp.duration
        FROM schedules s
        JOIN users u ON s.user_id = u.id
        JOIN gyms g ON s.gym_id = g.gym_id
        LEFT JOIN user_memberships um ON s.membership_id = um.id
        LEFT JOIN gym_membership_plans gmp ON um.plan_id = gmp.plan_id
        WHERE s.id = ?
    ");
    $stmt->execute([$booking_id]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$booking) {
        $_SESSION['error'] = "Booking not found.";
        header('Location: all_bookings.php');
        exit();
    }
    
    // Get booking logs
    $stmt = $conn->prepare("
        SELECT sl.*, u.username
        FROM schedule_logs sl
        JOIN users u ON sl.user_id = u.id
        WHERE sl.schedule_id = ?
        ORDER BY sl.created_at DESC
    ");
    $stmt->execute([$booking_id]);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $_SESSION['error'] = "Database error: " . $e->getMessage();
    header('Location: all_bookings.php');
    exit();
}

// Function to format date
function formatDate($date) {
    return date('M d, Y', strtotime($date));
}

// Function to format time
function formatTime($time) {
    return date('h:i A', strtotime($time));
}

// Function to format datetime
function formatDateTime($datetime) {
    return date('M d, Y h:i A', strtotime($datetime));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Booking - FlexFit Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body class="bg-gray-900 text-white">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="ml-0 lg:ml-64 p-4 sm:p-6 transition-all duration-200">
        <div class="flex justify-between items-center mb-6">
            <div class="flex items-center">
                <a href="all_bookings.php" class="mr-4 text-gray-400 hover:text-white">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <h1 class="text-2xl font-bold">Booking Details</h1>
            </div>
            <div class="flex space-x-2">
                <a href="edit_booking.php?id=<?= $booking_id ?>" class="bg-yellow-600 hover:bg-yellow-700 text-white px-4 py-2 rounded-lg flex items-center">
                    <i class="fas fa-edit mr-2"></i> Edit
                </a>
                <a href="#" onclick="window.print()" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg flex items-center">
                    <i class="fas fa-print mr-2"></i> Print
                </a>
            </div>
        </div>
        
        <?php if (isset($_SESSION['success'])): ?>
        <div class="bg-green-600 text-white p-4 rounded-lg mb-6">
            <p><?= htmlspecialchars($_SESSION['success']) ?></p>
            <?php unset($_SESSION['success']); ?>
        </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
        <div class="bg-red-600 text-white p-4 rounded-lg mb-6">
            <p><?= htmlspecialchars($_SESSION['error']) ?></p>
            <?php unset($_SESSION['error']); ?>
        </div>
        <?php endif; ?>
        
        <!-- Booking Status -->
        <div class="bg-gray-800 rounded-xl shadow-lg overflow-hidden mb-6">
            <div class="p-6 flex justify-between items-center">
                <div>
                    <h2 class="text-xl font-semibold">Booking #<?= $booking_id ?></h2>
                    <p class="text-gray-400 mt-1">Created on <?= formatDateTime($booking['created_at']) ?></p>
                </div>
                <?php
                    $statusClass = '';
                    $statusBadge = '';
                    
                    switch ($booking['status']) {
                        case 'scheduled':
                            $statusClass = 'bg-blue-900 text-blue-300';
                            $statusBadge = '<i class="fas fa-calendar-check mr-1"></i> Scheduled';
                            break;
                        case 'completed':
                            $statusClass = 'bg-green-900 text-green-300';
                            $statusBadge = '<i class="fas fa-check-circle mr-1"></i> Completed';
                            break;
                        case 'cancelled':
                            $statusClass = 'bg-red-900 text-red-300';
                            $statusBadge = '<i class="fas fa-times-circle mr-1"></i> Cancelled';
                            break;
                        case 'missed':
                            $statusClass = 'bg-yellow-900 text-yellow-300';
                            $statusBadge = '<i class="fas fa-exclamation-circle mr-1"></i> Missed';
                            break;
                        default:
                            $statusClass = 'bg-gray-700 text-gray-300';
                            $statusBadge = '<i class="fas fa-question-circle mr-1"></i> Unknown';
                    }
                ?>
                <span class="px-4 py-2 rounded-full text-sm font-semibold <?= $statusClass ?>">
                    <?= $statusBadge ?>
                </span>
            </div>
        </div>
        
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
            <!-- Member Information -->
            <div class="bg-gray-800 rounded-xl shadow-lg overflow-hidden">
                <div class="p-4 bg-gray-700 border-b border-gray-600">
                    <h3 class="font-semibold">Member Information</h3>
                </div>
                <div class="p-6">
                    <div class="flex items-center mb-4">
                        <?php if (!empty($booking['profile_image'])): ?>
                            <img src="<?= htmlspecialchars($booking['profile_image']) ?>" alt="Profile" class="h-16 w-16 rounded-full object-cover">
                        <?php else: ?>
                            <div class="h-16 w-16 rounded-full bg-gray-600 flex items-center justify-center">
                            <i class="fas fa-user text-gray-300 text-2xl"></i>
                            </div>
                        <?php endif; ?>
                        <div class="ml-4">
                            <h4 class="text-lg font-medium"><?= htmlspecialchars($booking['username']) ?></h4>
                            <p class="text-gray-400">Member ID: <?= $booking['user_id'] ?></p>
                        </div>
                    </div>
                    
                    <div class="space-y-3">
                        <div class="flex items-start">
                            <i class="fas fa-envelope text-gray-500 mt-1 w-5"></i>
                            <div class="ml-3">
                                <p class="text-sm text-gray-400">Email</p>
                                <p><?= htmlspecialchars($booking['email']) ?></p>
                            </div>
                        </div>
                        
                        <?php if (!empty($booking['phone'])): ?>
                        <div class="flex items-start">
                            <i class="fas fa-phone text-gray-500 mt-1 w-5"></i>
                            <div class="ml-3">
                                <p class="text-sm text-gray-400">Phone</p>
                                <p><?= htmlspecialchars($booking['phone']) ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="flex items-start">
                            <i class="fas fa-id-card text-gray-500 mt-1 w-5"></i>
                            <div class="ml-3">
                                <p class="text-sm text-gray-400">Membership</p>
                                <p><?= !empty($booking['plan_name']) ? htmlspecialchars($booking['plan_name']) : 'Pay Per Visit' ?></p>
                                <?php if (!empty($booking['plan_name'])): ?>
                                <p class="text-sm text-gray-400">
                                    ₹<?= number_format($booking['plan_price'], 2) ?> / <?= htmlspecialchars($booking['duration']) ?>
                                </p>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="mt-4 pt-4 border-t border-gray-700">
                            <a href="view_member.php?id=<?= $booking['user_id'] ?>" class="text-blue-400 hover:text-blue-300 flex items-center">
                                <i class="fas fa-user-circle mr-2"></i> View Full Profile
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Gym Information -->
            <div class="bg-gray-800 rounded-xl shadow-lg overflow-hidden">
                <div class="p-4 bg-gray-700 border-b border-gray-600">
                    <h3 class="font-semibold">Gym Information</h3>
                </div>
                <div class="p-6">
                    <h4 class="text-lg font-medium mb-2"><?= htmlspecialchars($booking['gym_name']) ?></h4>
                    
                    <div class="space-y-3">
                        <div class="flex items-start">
                            <i class="fas fa-map-marker-alt text-gray-500 mt-1 w-5"></i>
                            <div class="ml-3">
                                <p class="text-sm text-gray-400">Address</p>
                                <p><?= htmlspecialchars($booking['gym_address']) ?></p>
                                <p><?= htmlspecialchars($booking['gym_city']) . ', ' . htmlspecialchars($booking['gym_state']) ?></p>
                            </div>
                        </div>
                        
                        <div class="flex items-start">
                            <i class="fas fa-phone text-gray-500 mt-1 w-5"></i>
                            <div class="ml-3">
                                <p class="text-sm text-gray-400">Phone</p>
                                <p><?= htmlspecialchars($booking['gym_phone']) ?></p>
                            </div>
                        </div>
                        
                        <div class="flex items-start">
                            <i class="fas fa-envelope text-gray-500 mt-1 w-5"></i>
                            <div class="ml-3">
                                <p class="text-sm text-gray-400">Email</p>
                                <p><?= htmlspecialchars($booking['gym_email']) ?></p>
                            </div>
                        </div>
                        
                        <div class="mt-4 pt-4 border-t border-gray-700">
                            <a href="view_gym.php?id=<?= $booking['gym_id'] ?>" class="text-blue-400 hover:text-blue-300 flex items-center">
                                <i class="fas fa-dumbbell mr-2"></i> View Gym Details
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Booking Details -->
            <div class="bg-gray-800 rounded-xl shadow-lg overflow-hidden">
                <div class="p-4 bg-gray-700 border-b border-gray-600">
                    <h3 class="font-semibold">Booking Details</h3>
                </div>
                <div class="p-6">
                    <div class="space-y-3">
                        <div class="flex items-start">
                            <i class="fas fa-calendar text-gray-500 mt-1 w-5"></i>
                            <div class="ml-3">
                                <p class="text-sm text-gray-400">Date</p>
                                <p><?= formatDate($booking['start_date']) ?></p>
                            </div>
                        </div>
                        
                        <div class="flex items-start">
                            <i class="fas fa-clock text-gray-500 mt-1 w-5"></i>
                            <div class="ml-3">
                                <p class="text-sm text-gray-400">Time</p>
                                <p><?= formatTime($booking['start_time']) ?></p>
                            </div>
                        </div>
                        
                        <?php if (!empty($booking['activity_type'])): ?>
                        <div class="flex items-start">
                            <i class="fas fa-running text-gray-500 mt-1 w-5"></i>
                            <div class="ml-3">
                                <p class="text-sm text-gray-400">Activity Type</p>
                                <p><?= ucfirst(str_replace('_', ' ', $booking['activity_type'])) ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($booking['daily_rate'])): ?>
                        <div class="flex items-start">
                            <i class="fas fa-money-bill-wave text-gray-500 mt-1 w-5"></i>
                            <div class="ml-3">
                                <p class="text-sm text-gray-400">Rate</p>
                                <p>₹<?= number_format($booking['daily_rate'], 2) ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($booking['check_in_time'])): ?>
                        <div class="flex items-start">
                            <i class="fas fa-sign-in-alt text-gray-500 mt-1 w-5"></i>
                            <div class="ml-3">
                                <p class="text-sm text-gray-400">Check-in Time</p>
                                <p><?= formatDateTime($booking['check_in_time']) ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($booking['check_out_time'])): ?>
                        <div class="flex items-start">
                            <i class="fas fa-sign-out-alt text-gray-500 mt-1 w-5"></i>
                            <div class="ml-3">
                                <p class="text-sm text-gray-400">Check-out Time</p>
                                <p><?= formatDateTime($booking['check_out_time']) ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($booking['cancellation_reason'])): ?>
                        <div class="flex items-start">
                            <i class="fas fa-ban text-gray-500 mt-1 w-5"></i>
                            <div class="ml-3">
                                <p class="text-sm text-gray-400">Cancellation Reason</p>
                                <p><?= htmlspecialchars($booking['cancellation_reason']) ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($booking['notes'])): ?>
                        <div class="flex items-start">
                            <i class="fas fa-sticky-note text-gray-500 mt-1 w-5"></i>
                            <div class="ml-3">
                                <p class="text-sm text-gray-400">Notes</p>
                                <p><?= nl2br(htmlspecialchars($booking['notes'])) ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Action Buttons (for scheduled bookings) -->
        <?php if ($booking['status'] === 'scheduled'): ?>
        <div class="bg-gray-800 rounded-xl shadow-lg overflow-hidden mb-6">
            <div class="p-4 bg-gray-700 border-b border-gray-600">
                <h3 class="font-semibold">Actions</h3>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <form method="POST" class="bg-green-900 bg-opacity-30 rounded-lg p-4" onsubmit="return confirm('Are you sure you want to mark this booking as completed?');">
                        <h4 class="font-medium text-green-400 mb-2">Mark as Completed</h4>
                        <textarea name="notes" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-green-500 mb-3" placeholder="Add completion notes (optional)"></textarea>
                        <input type="hidden" name="action" value="complete">
                        <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded-lg transition-colors duration-200">
                            <i class="fas fa-check mr-2"></i> Complete
                        </button>
                    </form>
                    
                    <form method="POST" class="bg-yellow-900 bg-opacity-30 rounded-lg p-4" onsubmit="return confirm('Are you sure you want to mark this booking as missed?');">
                        <h4 class="font-medium text-yellow-400 mb-2">Mark as Missed</h4>
                        <textarea name="notes" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-yellow-500 mb-3" placeholder="Add notes about missed appointment (optional)"></textarea>
                        <input type="hidden" name="action" value="missed">
                        <button type="submit" class="w-full bg-yellow-600 hover:bg-yellow-700 text-white font-bold py-2 px-4 rounded-lg transition-colors duration-200">
                            <i class="fas fa-exclamation-circle mr-2"></i> Missed
                        </button>
                    </form>
                    
                    <form method="POST" class="bg-red-900 bg-opacity-30 rounded-lg p-4" onsubmit="return confirm('Are you sure you want to cancel this booking?');">
                        <h4 class="font-medium text-red-400 mb-2">Cancel Booking</h4>
                        <textarea name="notes" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-red-500 mb-3" placeholder="Cancellation reason (required)" required></textarea>
                        <input type="hidden" name="action" value="cancel">
                        <button type="submit" class="w-full bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded-lg transition-colors duration-200">
                            <i class="fas fa-times mr-2"></i> Cancel
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Activity Logs -->
        <?php if (!empty($logs)): ?>
        <div class="bg-gray-800 rounded-xl shadow-lg overflow-hidden">
            <div class="p-4 bg-gray-700 border-b border-gray-600">
                <h3 class="font-semibold">Activity History</h3>
            </div>
            <div class="p-6">
                <div class="space-y-4">
                    <?php foreach ($logs as $log): ?>
                        <?php
                            $actionIcon = '';
                            $actionClass = '';
                            
                            switch ($log['action_type']) {
                                case 'create':
                                    $actionIcon = 'fa-plus-circle';
                                    $actionClass = 'text-green-400';
                                    break;
                                case 'update':
                                    $actionIcon = 'fa-edit';
                                    $actionClass = 'text-blue-400';
                                    break;
                                case 'cancel':
                                    $actionIcon = 'fa-times-circle';
                                    $actionClass = 'text-red-400';
                                    break;
                                case 'complete':
                                    $actionIcon = 'fa-check-circle';
                                    $actionClass = 'text-green-400';
                                    break;
                                default:
                                    $actionIcon = 'fa-circle';
                                    $actionClass = 'text-gray-400';
                            }
                        ?>
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <div class="h-10 w-10 rounded-full bg-gray-700 flex items-center justify-center">
                                    <i class="fas <?= $actionIcon ?> <?= $actionClass ?>"></i>
                                </div>
                            </div>
                            <div class="ml-4">
                                <div class="flex items-center">
                                    <h4 class="font-medium">
                                    <?= ucfirst($log['action_type']) ?> action by <?= htmlspecialchars($log['username']) ?>
                                    </h4>
                                    <span class="ml-2 text-sm text-gray-400"><?= formatDateTime($log['created_at']) ?></span>
                                </div>
                                
                                <?php if (!empty($log['notes'])): ?>
                                <p class="text-gray-300 mt-1"><?= htmlspecialchars($log['notes']) ?></p>
                                <?php endif; ?>
                                
                                <?php if ($log['action_type'] === 'update'): ?>
                                <div class="mt-2 text-sm">
                                    <?php if ($log['old_gym_id'] != $log['new_gym_id']): ?>
                                    <p class="text-gray-400">
                                        Gym changed from ID: <?= $log['old_gym_id'] ?> to ID: <?= $log['new_gym_id'] ?>
                                    </p>
                                    <?php endif; ?>
                                    
                                    <?php if ($log['old_time'] != $log['new_time']): ?>
                                    <p class="text-gray-400">
                                        Time changed from <?= formatTime($log['old_time']) ?> to <?= formatTime($log['new_time']) ?>
                                    </p>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($log['amount'])): ?>
                                    <p class="text-gray-400">
                                        Amount: ₹<?= number_format($log['amount'], 2) ?>
                                    </p>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <!-- Actions Card -->
<div class="bg-gray-800 rounded-xl shadow-lg overflow-hidden mt-6">
    <div class="p-4 bg-gray-700 border-b border-gray-600">
        <h3 class="font-semibold">Actions</h3>
    </div>
    <div class="p-6 space-y-4">
        <?php if ($booking['status'] === 'scheduled'): ?>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <form method="POST" class="bg-green-900 bg-opacity-30 rounded-lg p-4" onsubmit="return confirm('Are you sure you want to mark this booking as completed?');">
                    <h4 class="font-medium text-green-400 mb-2">Mark as Completed</h4>
                    <textarea name="notes" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-green-500 mb-3" placeholder="Add completion notes (optional)"></textarea>
                    <input type="hidden" name="action" value="complete">
                    <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded-lg transition-colors duration-200">
                        <i class="fas fa-check mr-2"></i> Complete
                    </button>
                </form>
                
                <form method="POST" class="bg-yellow-900 bg-opacity-30 rounded-lg p-4" onsubmit="return confirm('Are you sure you want to mark this booking as missed?');">
                    <h4 class="font-medium text-yellow-400 mb-2">Mark as Missed</h4>
                    <textarea name="notes" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-yellow-500 mb-3" placeholder="Add notes about missed appointment (optional)"></textarea>
                    <input type="hidden" name="action" value="missed">
                    <button type="submit" class="w-full bg-yellow-600 hover:bg-yellow-700 text-white font-bold py-2 px-4 rounded-lg transition-colors duration-200">
                        <i class="fas fa-exclamation-circle mr-2"></i> Missed
                    </button>
                </form>
                
                <form method="POST" class="bg-red-900 bg-opacity-30 rounded-lg p-4" onsubmit="return confirm('Are you sure you want to cancel this booking?');">
                    <h4 class="font-medium text-red-400 mb-2">Cancel Booking</h4>
                    <textarea name="notes" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-red-500 mb-3" placeholder="Cancellation reason (required)" required></textarea>
                    <input type="hidden" name="action" value="cancel">
                    <button type="submit" class="w-full bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded-lg transition-colors duration-200">
                        <i class="fas fa-times mr-2"></i> Cancel
                    </button>
                </form>
            </div>
        <?php endif; ?>
        
        <div class="border-t border-gray-700 pt-4 mt-4">
            <!-- Send Reminder Form -->
            <form method="POST" action="" class="mb-3">
                <input type="hidden" name="send_reminder" value="1">
                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-lg transition duration-300">
                    <i class="fas fa-bell mr-2"></i> Send Reminder Email
                </button>
            </form>
            
            <!-- Other Actions -->
            <a href="user_bookings.php?user_id=<?= $booking['user_id'] ?>" class="block w-full bg-purple-600 hover:bg-purple-700 text-white text-center font-medium py-2 px-4 rounded-lg transition duration-300 mb-3">
                <i class="fas fa-calendar-alt mr-2"></i> View User's Bookings
            </a>
            
            <a href="gym_bookings.php?gym_id=<?= $booking['gym_id'] ?>" class="block w-full bg-green-600 hover:bg-green-700 text-white text-center font-medium py-2 px-4 rounded-lg transition duration-300">
                <i class="fas fa-dumbbell mr-2"></i> View Gym's Bookings
            </a>
        </div>
    </div>
</div>

    </div>
</body>
</html>

</div>

