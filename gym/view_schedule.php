<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a gym owner
if (!isset($_SESSION['owner_id'])) {
    header('Location: login.php');
    exit;
}

$owner_id = $_SESSION['owner_id'];
$db = new GymDatabase();
$conn = $db->getConnection();

// Get schedule ID from URL
$schedule_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$schedule_id) {
    $_SESSION['error'] = "Invalid schedule ID";
    header('Location: dashboard.php');
    exit;
}

// Fetch schedule details with user and gym information
$stmt = $conn->prepare("
    SELECT 
        s.id, s.user_id, s.gym_id, s.membership_id, s.activity_type, 
        s.start_date, s.end_date, s.start_time, s.status, s.notes,
        s.created_at, s.recurring, s.recurring_until, s.days_of_week,
        s.check_in_time, s.check_out_time, s.daily_rate, s.payment_status,
        u.username, u.email, u.phone, u.profile_image,
        g.name as gym_name, g.address, g.city, g.cover_photo,
        um.id as membership_id, gmp.tier, gmp.duration, gmp.price
    FROM schedules s
    JOIN users u ON s.user_id = u.id
    JOIN gyms g ON s.gym_id = g.gym_id
    LEFT JOIN user_memberships um ON s.membership_id = um.id
    LEFT JOIN gym_membership_plans gmp ON um.plan_id = gmp.plan_id
    WHERE s.id = ? AND g.owner_id = ?
");

$stmt->execute([$schedule_id, $owner_id]);
$schedule = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$schedule) {
    $_SESSION['error'] = "Schedule not found or you don't have permission to view it";
    header('Location: dashboard.php');
    exit;
}

// Get schedule logs
$logsStmt = $conn->prepare("
    SELECT 
        sl.id, sl.user_id, sl.action_type, sl.notes, sl.created_at,
        u.username, u.profile_image
    FROM schedule_logs sl
    LEFT JOIN users u ON sl.user_id = u.id
    WHERE sl.schedule_id = ?
    ORDER BY sl.created_at DESC
");
$logsStmt->execute([$schedule_id]);
$logs = $logsStmt->fetchAll(PDO::FETCH_ASSOC);

// Process status update if submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $new_status = $_POST['status'];
    $notes = $_POST['notes'] ?? '';
    
    try {
        $conn->beginTransaction();
        
        // Update schedule status
        $updateStmt = $conn->prepare("
            UPDATE schedules 
            SET status = ?, notes = CONCAT(IFNULL(notes, ''), '\n\nStatus updated: " . $conn->quote($notes) . "')
            WHERE id = ?
        ");
        $updateStmt->execute([$new_status, $schedule_id]);
        
        // Log the action
        $logStmt = $conn->prepare("
            INSERT INTO schedule_logs (user_id, schedule_id, action_type, notes)
            VALUES (?, ?, 'update', ?)
        ");
        $logStmt->execute([$owner_id, $schedule_id, "Status updated to {$new_status}. Notes: {$notes}"]);
        
        // Create notification for user
        $notifyStmt = $conn->prepare("
            INSERT INTO notifications (user_id, type, title, message, related_id, gym_id, created_at)
            VALUES (?, 'booking', ?, ?, ?, ?, NOW())
        ");
        
        $title = "Booking Status Updated at {$schedule['gym_name']}";
        $message = "Your booking for " . ucfirst(str_replace('_', ' ', $schedule['activity_type'])) . " on " . 
                   date('F j, Y', strtotime($schedule['start_date'])) . " has been updated to " . ucfirst($new_status) . ".";
        
        if (!empty($notes)) {
            $message .= "\n\nNotes: " . $notes;
        }
        
        $notifyStmt->execute([
            $schedule['user_id'],
            $title,
            $message,
            $schedule_id,
            $schedule['gym_id']
        ]);
        
        $conn->commit();
        
        $_SESSION['success'] = "Schedule status updated successfully";
        header("Location: view_schedule.php?id={$schedule_id}");
        exit;
    } catch (Exception $e) {
        $conn->rollBack();
        $_SESSION['error'] = "Error updating status: " . $e->getMessage();
    }
}

// Helper function to format recurring pattern
function formatRecurringPattern($recurring, $daysOfWeek, $until) {
    if ($recurring === 'none') {
        return 'One-time booking';
    }
    
    $pattern = ucfirst($recurring);
    
    if ($recurring === 'weekly' && !empty($daysOfWeek)) {
        $days = json_decode($daysOfWeek, true);
        if (is_array($days) && !empty($days)) {
            $dayNames = ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
            $selectedDays = [];
            
            foreach ($days as $day) {
                if (isset($dayNames[$day])) {
                    $selectedDays[] = $dayNames[$day];
                }
            }
            
            if (!empty($selectedDays)) {
                $pattern .= ' on ' . implode(', ', $selectedDays);
            }
        }
    }
    
    if ($until) {
        $pattern .= ' until ' . date('F j, Y', strtotime($until));
    }
    
    return $pattern;
}

// Include header
include '../includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Schedule - <?= htmlspecialchars($schedule['gym_name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body {
            background-color: #111827;
            color: #f3f4f6;
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-weight: 600;
            font-size: 0.875rem;
        }
        
        .status-scheduled {
            background-color: #1E40AF;
            color: #DBEAFE;
        }
        
        .status-completed {
            background-color: #065F46;
            color: #D1FAE5;
        }
        
        .status-cancelled {
            background-color: #991B1B;
            color: #FEE2E2;
        }
        
        .status-missed {
            background-color: #92400E;
            color: #FEF3C7;
        }
        
        .timeline-item {
            position: relative;
            padding-left: 2rem;
            padding-bottom: 2rem;
        }
        
        .timeline-item:before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 2px;
            background-color: #4B5563;
        }
        
        .timeline-item:last-child:before {
            bottom: 50%;
        }
        
        .timeline-dot {
            position: absolute;
            left: -0.5rem;
            top: 0;
            width: 1rem;
            height: 1rem;
            border-radius: 50%;
            background-color: #F59E0B;
            border: 2px solid #111827;
        }
    </style>
</head>
<body>
    <div class="container mx-auto px-4 py-8">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold text-white">Schedule Details</h1>
            <a href="dashboard.php" class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                <i class="fas fa-arrow-left mr-2"></i> Back to Dashboard
            </a>
        </div>
        
        <?php if (isset($_SESSION['success'])): ?>
            <div class="bg-green-900 text-green-100 p-4 rounded-lg mb-6">
                <?= $_SESSION['success'] ?>
                <?php unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="bg-red-900 text-red-100 p-4 rounded-lg mb-6">
                <?= $_SESSION['error'] ?>
                <?php unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>
        
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Main Schedule Information -->
            <div class="lg:col-span-2">
                <div class="bg-gray-800 rounded-lg shadow-lg overflow-hidden">
                    <div class="relative h-40 bg-gray-700">
                        <?php if (!empty($schedule['cover_photo'])): ?>
                            <img src="<?= htmlspecialchars($schedule['cover_photo']) ?>" alt="<?= htmlspecialchars($schedule['gym_name']) ?>" class="w-full h-full object-cover">
                        <?php else: ?>
                            <div class="w-full h-full flex items-center justify-center bg-gray-700">
                                <i class="fas fa-dumbbell text-5xl text-gray-500"></i>
                            </div>
                        <?php endif; ?>
                        
                        <div class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black to-transparent p-4">
                            <h2 class="text-xl font-bold text-white"><?= htmlspecialchars($schedule['gym_name']) ?></h2>
                            <p class="text-gray-300">
                                <i class="fas fa-map-marker-alt mr-1"></i> 
                                <?= htmlspecialchars($schedule['address']) ?>, <?= htmlspecialchars($schedule['city']) ?>
                            </p>
                        </div>
                    </div>
                    
                    <div class="p-6">
                        <div class="flex flex-wrap justify-between items-start mb-6">
                            <div>
                                <h3 class="text-xl font-bold text-white mb-1">
                                    <?= ucfirst(str_replace('_', ' ', $schedule['activity_type'])) ?>
                                </h3>
                                <p class="text-gray-400">
                                    <i class="far fa-calendar mr-1"></i> 
                                    <?= date('F j, Y', strtotime($schedule['start_date'])) ?> at 
                                    <?= date('g:i A', strtotime($schedule['start_time'])) ?>
                                </p>
                            </div>
                            
                            <div>
                                <span class="status-badge status-<?= $schedule['status'] ?>">
                                    <i class="fas fa-<?= getStatusIcon($schedule['status']) ?> mr-1"></i>
                                    <?= ucfirst($schedule['status']) ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                            <div>
                                <h4 class="text-lg font-semibold text-white mb-3">Schedule Details</h4>
                                <ul class="space-y-2 text-gray-300">
                                    <li class="flex items-start">
                                        <span class="text-gray-500 w-32">Activity:</span>
                                        <span><?= ucfirst(str_replace('_', ' ', $schedule['activity_type'])) ?></span>
                                    </li>
                                    <li class="flex items-start">
                                        <span class="text-gray-500 w-32">Date:</span>
                                        <span><?= date('F j, Y', strtotime($schedule['start_date'])) ?></span>
                                    </li>
                                    <li class="flex items-start">
                                        <span class="text-gray-500 w-32">Time:</span>
                                        <span><?= date('g:i A', strtotime($schedule['start_time'])) ?></span>
                                    </li>
                                    <li class="flex items-start">
                                        <span class="text-gray-500 w-32">Recurring:</span>
                                        <span><?= formatRecurringPattern($schedule['recurring'], $schedule['days_of_week'], $schedule['recurring_until']) ?></span>
                                    </li>
                                    <li class="flex items-start">
                                        <span class="text-gray-500 w-32">Created:</span>
                                        <span><?= date('F j, Y g:i A', strtotime($schedule['created_at'])) ?></span>
                                    </li>
                                    <?php if ($schedule['check_in_time']): ?>
                                    <li class="flex items-start">
                                        <span class="text-gray-500 w-32">Check-in:</span>
                                        <span><?= date('F j, Y g:i A', strtotime($schedule['check_in_time'])) ?></span>
                                    </li>
                                    <?php endif; ?>
                                    <?php if ($schedule['check_out_time']): ?>
                                    <li class="flex items-start">
                                        <span class="text-gray-500 w-32">Check-out:</span>
                                        <span><?= date('F j, Y g:i A', strtotime($schedule['check_out_time'])) ?></span>
                                    </li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                            
                            <div>
                                <h4 class="text-lg font-semibold text-white mb-3">Membership Details</h4>
                                <ul class="space-y-2 text-gray-300">
                                    <li class="flex items-start">
                                        <span class="text-gray-500 w-32">Membership:</span>
                                        <span><?= $schedule['tier'] ?? 'N/A' ?> (<?= $schedule['duration'] ?? 'N/A' ?>)</span>
                                    </li>
                                    <li class="flex items-start">
                                        <span class="text-gray-500 w-32">Daily Rate:</span>
                                        <span>₹<?= number_format($schedule['daily_rate'], 2) ?></span>
                                    </li>
                                    <li class="flex items-start">
                                        <span class="text-gray-500 w-32">Payment Status:</span>
                                        <span class="<?= $schedule['payment_status'] === 'paid' ? 'text-green-400' : 'text-yellow-400' ?>">
                                            <?= ucfirst($schedule['payment_status']) ?>
                                        </span>
                                    </li>
                                </ul>
                                
                                <?php if (!empty($schedule['notes'])): ?>
                                <div class="mt-4">
                                    <h4 class="text-lg font-semibold text-white mb-2">Notes</h4>
                                    <div class="bg-gray-700 rounded-lg p-3 text-gray-300 whitespace-pre-line">
                                        <?= nl2br(htmlspecialchars($schedule['notes'])) ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Update Status Form -->
                        <div class="mt-6 border-t border-gray-700 pt-6">
                            <h4 class="text-lg font-semibold text-white mb-4">Update Status</h4>
                            <form method="post" action="">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                    <div>
                                        <label for="status" class="block text-gray-400 mb-2">Status</label>
                                        <select id="status" name="status" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                            <option value="scheduled" <?= $schedule['status'] === 'scheduled' ? 'selected' : '' ?>>Scheduled</option>
                                            <option value="completed" <?= $schedule['status'] === 'completed' ? 'selected' : '' ?>>Completed</option>
                                            <option value="cancelled" <?= $schedule['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                            <option value="missed" <?= $schedule['status'] === 'missed' ? 'selected' : '' ?>>Missed</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label for="notes" class="block text-gray-400 mb-2">Notes</label>
                                        <textarea id="notes" name="notes" rows="2" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500" placeholder="Add notes about this status update..."></textarea>
                                    </div>
                                </div>
                                <div class="flex justify-end">
                                    <button type="submit" name="update_status" class="bg-yellow-600 hover:bg-yellow-700 text-black font-bold px-4 py-2 rounded-lg transition-colors duration-200">
                                        <i class="fas fa-save mr-2"></i> Update Status
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Activity Timeline -->
                <div class="bg-gray-800 rounded-lg shadow-lg p-6 mt-6">
                    <h3 class="text-xl font-bold text-white mb-4">Activity Timeline</h3>
                    
                    <?php if (empty($logs)): ?>
                        <div class="text-center py-6 text-gray-500">
                            <i class="fas fa-history text-4xl mb-3"></i>
                            <p>No activity logs found for this schedule.</p>
                        </div>
                    <?php else: ?>
                        <div class="timeline">
                            <?php foreach ($logs as $log): ?>
                                <div class="timeline-item">
                                    <div class="timeline-dot"></div>
                                    <div class="bg-gray-700 rounded-lg p-4">
                                        <div class="flex justify-between items-start">
                                            <div class="flex items-center">
                                                <?php if (!empty($log['profile_image'])): ?>
                                                    <img src="<?= htmlspecialchars($log['profile_image']) ?>" alt="User" class="w-8 h-8 rounded-full mr-3 object-cover">
                                                <?php else: ?>
                                                    <div class="w-8 h-8 rounded-full bg-gray-600 flex items-center justify-center mr-3">
                                                        <i class="fas fa-user text-gray-400"></i>
                                                    </div>
                                                <?php endif; ?>
                                                <div>
                                                    <p class="font-medium text-white">
                                                        <?= htmlspecialchars($log['username'] ?? 'System') ?>
                                                    </p>
                                                    <p class="text-sm text-gray-400">
                                                        <?= date('F j, Y g:i A', strtotime($log['created_at'])) ?>
                                                    </p>
                                                </div>
                                            </div>
                                            <span class="text-sm font-medium px-2 py-1 rounded-full 
                                                <?= getActionTypeColor($log['action_type']) ?>">
                                                <?= ucfirst($log['action_type']) ?>
                                            </span>
                                        </div>
                                        <div class="mt-3 text-gray-300">
                                            <?= nl2br(htmlspecialchars($log['notes'])) ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- User Information Sidebar -->
            <div class="lg:col-span-1">
                <div class="bg-gray-800 rounded-lg shadow-lg p-6">
                    <h3 class="text-xl font-bold text-white mb-4">User Information</h3>
                    
                    <div class="flex items-center mb-6">
                        <?php if (!empty($schedule['profile_image'])): ?>
                            <img src="<?= htmlspecialchars($schedule['profile_image']) ?>" alt="User" class="w-16 h-16 rounded-full mr-4 object-cover">
                        <?php else: ?>
                            <div class="w-16 h-16 rounded-full bg-gray-700 flex items-center justify-center mr-4">
                                <i class="fas fa-user text-gray-500 text-2xl"></i>
                            </div>
                        <?php endif; ?>
                        
                        <div>
                            <h4 class="text-lg font-semibold text-white"><?= htmlspecialchars($schedule['username']) ?></h4>
                            <p class="text-gray-400">Member</p>
                        </div>
                    </div>
                    
                    <ul class="space-y-3 text-gray-300">
                        <li class="flex items-center">
                            <i class="fas fa-envelope text-gray-500 w-6"></i>
                            <span class="ml-2"><?= htmlspecialchars($schedule['email']) ?></span>
                        </li>
                        <?php if (!empty($schedule['phone'])): ?>
                        <li class="flex items-center">
                            <i class="fas fa-phone text-gray-500 w-6"></i>
                            <span class="ml-2"><?= htmlspecialchars($schedule['phone']) ?></span>
                        </li>
                        <?php endif; ?>
                    </ul>
                    
                    <div class="mt-6 space-y-3">
                        <a href="mailto:<?= htmlspecialchars($schedule['email']) ?>" class="flex items-center justify-center w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-lg transition-colors duration-200">
                            <i class="fas fa-envelope mr-2"></i> Send Email
                        </a>
                        
                        <?php if (!empty($schedule['phone'])): ?>
                        <a href="tel:<?= htmlspecialchars($schedule['phone']) ?>" class="flex items-center justify-center w-full bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-4 rounded-lg transition-colors duration-200">
                            <i class="fas fa-phone mr-2"></i> Call User
                        </a>
                        <?php endif; ?>
                        
                        <a href="user_profile.php?id=<?= $schedule['user_id'] ?>" class="flex items-center justify-center w-full bg-gray-700 hover:bg-gray-600 text-white font-medium py-2 px-4 rounded-lg transition-colors duration-200">
                            <i class="fas fa-user mr-2"></i> View Profile
                        </a>
                    </div>
                </div>
                
                <!-- Quick Actions -->
                <div class="bg-gray-800 rounded-lg shadow-lg p-6 mt-6">
                    <h3 class="text-xl font-bold text-white mb-4">Quick Actions</h3>
                    
                    <div class="space-y-3">
                        <?php if ($schedule['status'] === 'scheduled'): ?>
                            <button type="button" id="checkInBtn" class="flex items-center justify-center w-full bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-4 rounded-lg transition-colors duration-200">
                                <i class="fas fa-sign-in-alt mr-2"></i> Check In User
                            </button>
                            
                            <button type="button" id="cancelBtn" class="flex items-center justify-center w-full bg-red-600 hover:bg-red-700 text-white font-medium py-2 px-4 rounded-lg transition-colors duration-200">
                                <i class="fas fa-times mr-2"></i> Cancel Booking
                            </button>
                        <?php endif; ?>
                        
                        <?php if ($schedule['status'] === 'scheduled' && $schedule['check_in_time'] && !$schedule['check_out_time']): ?>
                            <button type="button" id="checkOutBtn" class="flex items-center justify-center w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-lg transition-colors duration-200">
                                <i class="fas fa-sign-out-alt mr-2"></i> Check Out User
                            </button>
                        <?php endif; ?>
                        
                        <a href="print_schedule.php?id=<?= $schedule_id ?>" target="_blank" class="flex items-center justify-center w-full bg-gray-700 hover:bg-gray-600 text-white font-medium py-2 px-4 rounded-lg transition-colors duration-200">
                            <i class="fas fa-print mr-2"></i> Print Details
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Check In Modal -->
    <div id="checkInModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
        <div class="bg-gray-800 rounded-lg shadow-xl max-w-md w-full mx-4 p-6">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-bold text-white">Check In User</h3>
                <button type="button" class="close-modal text-gray-400 hover:text-white">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <p class="text-gray-300 mb-4">
                Are you sure you want to check in this user for their scheduled activity?
            </p>
            
            <form id="checkInForm" method="post" action="process_checkin.php">
                <input type="hidden" name="schedule_id" value="<?= $schedule_id ?>">
                <input type="hidden" name="action" value="check_in">
                
                <div class="flex justify-end space-x-3 mt-6">
                    <button type="button" class="close-modal px-4 py-2 bg-gray-700 text-white rounded-lg hover:bg-gray-600 transition-colors duration-200">
                        Cancel
                    </button>
                    <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors duration-200">
                        <i class="fas fa-sign-in-alt mr-2"></i> Check In
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Check Out Modal -->
    <div id="checkOutModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
        <div class="bg-gray-800 rounded-lg shadow-xl max-w-md w-full mx-4 p-6">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-bold text-white">Check Out User</h3>
                <button type="button" class="close-modal text-gray-400 hover:text-white">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <p class="text-gray-300 mb-4">
                Are you sure you want to check out this user from their activity?
            </p>
            
            <form id="checkOutForm" method="post" action="process_checkin.php">
                <input type="hidden" name="schedule_id" value="<?= $schedule_id ?>">
                <input type="hidden" name="action" value="check_out">
                
                <div class="flex justify-end space-x-3 mt-6">
                <button type="button" class="close-modal px-4 py-2 bg-gray-700 text-white rounded-lg hover:bg-gray-600 transition-colors duration-200">
                        Cancel
                    </button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors duration-200">
                        <i class="fas fa-sign-out-alt mr-2"></i> Check Out
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Cancel Modal -->
    <div id="cancelModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
        <div class="bg-gray-800 rounded-lg shadow-xl max-w-md w-full mx-4 p-6">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-bold text-white">Cancel Booking</h3>
                <button type="button" class="close-modal text-gray-400 hover:text-white">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <p class="text-gray-300 mb-4">
                Are you sure you want to cancel this booking? This action cannot be undone.
            </p>
            
            <form id="cancelForm" method="post" action="process_schedule.php">
                <input type="hidden" name="schedule_id" value="<?= $schedule_id ?>">
                <input type="hidden" name="action" value="cancel">
                
                <div class="mb-4">
                    <label for="cancelReason" class="block text-gray-400 mb-2">Reason for Cancellation</label>
                    <textarea id="cancelReason" name="reason" rows="3" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-red-500" placeholder="Please provide a reason for cancellation..." required></textarea>
                </div>
                
                <div class="flex justify-end space-x-3 mt-6">
                    <button type="button" class="close-modal px-4 py-2 bg-gray-700 text-white rounded-lg hover:bg-gray-600 transition-colors duration-200">
                        Cancel
                    </button>
                    <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors duration-200">
                        <i class="fas fa-times mr-2"></i> Cancel Booking
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // Modal functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Check In Modal
            const checkInBtn = document.getElementById('checkInBtn');
            const checkInModal = document.getElementById('checkInModal');
            
            if (checkInBtn) {
                checkInBtn.addEventListener('click', function() {
                    checkInModal.classList.remove('hidden');
                });
            }
            
            // Check Out Modal
            const checkOutBtn = document.getElementById('checkOutBtn');
            const checkOutModal = document.getElementById('checkOutModal');
            
            if (checkOutBtn) {
                checkOutBtn.addEventListener('click', function() {
                    checkOutModal.classList.remove('hidden');
                });
            }
            
            // Cancel Modal
            const cancelBtn = document.getElementById('cancelBtn');
            const cancelModal = document.getElementById('cancelModal');
            
            if (cancelBtn) {
                cancelBtn.addEventListener('click', function() {
                    cancelModal.classList.remove('hidden');
                });
            }
            
            // Close modals
            const closeButtons = document.querySelectorAll('.close-modal');
            closeButtons.forEach(button => {
                button.addEventListener('click', function() {
                    checkInModal.classList.add('hidden');
                    checkOutModal.classList.add('hidden');
                    cancelModal.classList.add('hidden');
                });
            });
            
            // Close modals when clicking outside
            window.addEventListener('click', function(event) {
                if (event.target === checkInModal) {
                    checkInModal.classList.add('hidden');
                }
                if (event.target === checkOutModal) {
                    checkOutModal.classList.add('hidden');
                }
                if (event.target === cancelModal) {
                    cancelModal.classList.add('hidden');
                }
            });
            
            // Form submissions with confirmation
            const checkInForm = document.getElementById('checkInForm');
            if (checkInForm) {
                checkInForm.addEventListener('submit', function(e) {
                    if (!confirm('Are you sure you want to check in this user?')) {
                        e.preventDefault();
                    }
                });
            }
            
            const checkOutForm = document.getElementById('checkOutForm');
            if (checkOutForm) {
                checkOutForm.addEventListener('submit', function(e) {
                    if (!confirm('Are you sure you want to check out this user?')) {
                        e.preventDefault();
                    }
                });
            }
            
            const cancelForm = document.getElementById('cancelForm');
            if (cancelForm) {
                cancelForm.addEventListener('submit', function(e) {
                    const reason = document.getElementById('cancelReason').value.trim();
                    if (!reason) {
                        alert('Please provide a reason for cancellation');
                        e.preventDefault();
                        return;
                    }
                    
                    if (!confirm('Are you sure you want to cancel this booking?')) {
                        e.preventDefault();
                    }
                });
            }
        });
    </script>
</body>
</html>

<?php
/**
 * Helper function to get status icon
 */
function getStatusIcon($status) {
    switch ($status) {
        case 'scheduled':
            return 'calendar-check';
        case 'completed':
            return 'check-circle';
        case 'cancelled':
            return 'times-circle';
        case 'missed':
            return 'exclamation-circle';
        default:
            return 'question-circle';
    }
}

/**
 * Helper function to get action type color
 */
function getActionTypeColor($actionType) {
    switch ($actionType) {
        case 'create':
            return 'bg-green-900 text-green-300';
        case 'update':
            return 'bg-blue-900 text-blue-300';
        case 'cancel':
            return 'bg-red-900 text-red-300';
        case 'complete':
            return 'bg-purple-900 text-purple-300';
        default:
            return 'bg-gray-900 text-gray-300';
    }
}
?>

