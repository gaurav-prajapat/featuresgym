<?php
session_start();
require_once '../config/database.php';
require_once 'auto_process_functions.php';

// Check if user is logged in and is a gym owner
if (!isset($_SESSION['owner_id'])) {
    header('Location: login.php');
    exit;
}

$owner_id = $_SESSION['owner_id'];
$db = new GymDatabase();
$conn = $db->getConnection();

// Get the gym ID (use the first gym if not specified)
$gymStmt = $conn->prepare("SELECT gym_id, name FROM gyms WHERE owner_id = ? ORDER BY created_at ASC LIMIT 1");
$gymStmt->execute([$owner_id]);
$gym = $gymStmt->fetch(PDO::FETCH_ASSOC);

if (!$gym) {
    header('Location: dashboard.php?error=no_gym');
    exit;
}

$gym_id = $gym['gym_id'];

// Initialize variables
$error = '';
$success = '';
$filter = $_GET['filter'] ?? 'all';
$search = $_GET['search'] ?? '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;

// Process mark as read
if (isset($_POST['mark_read']) && isset($_POST['notification_id'])) {
    $notificationId = $_POST['notification_id'];
    
    try {
        $markReadStmt = $conn->prepare("
            UPDATE gym_notifications 
            SET is_read = 1 
            WHERE id = ? AND gym_id = ?
        ");
        $markReadStmt->execute([$notificationId, $gym_id]);
        
        // If this is an AJAX request, return JSON response
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
        }
        
        $success = 'Notification marked as read';
    } catch (Exception $e) {
        $error = 'Error marking notification as read: ' . $e->getMessage();
    }
}

// Process mark all as read
if (isset($_POST['mark_all_read'])) {
    try {
        $markAllReadStmt = $conn->prepare("
            UPDATE gym_notifications 
            SET is_read = 1 
            WHERE gym_id = ? AND is_read = 0
        ");
        $markAllReadStmt->execute([$gym_id]);
        
        $success = 'All notifications marked as read';
    } catch (Exception $e) {
        $error = 'Error marking all notifications as read: ' . $e->getMessage();
    }
}

// Process delete notification
if (isset($_POST['delete_notification']) && isset($_POST['notification_id'])) {
    $notificationId = $_POST['notification_id'];
    
    try {
        $deleteStmt = $conn->prepare("
            DELETE FROM gym_notifications 
            WHERE id = ? AND gym_id = ?
        ");
        $deleteStmt->execute([$notificationId, $gym_id]);
        
        // If this is an AJAX request, return JSON response
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
        }
        
        $success = 'Notification deleted successfully';
    } catch (Exception $e) {
        $error = 'Error deleting notification: ' . $e->getMessage();
    }
}

// Process auto-process settings update
if (isset($_POST['update_auto_settings'])) {
    $autoAcceptEnabled = isset($_POST['auto_accept_enabled']) ? 1 : 0;
    $autoCancelEnabled = isset($_POST['auto_cancel_enabled']) ? 1 : 0;
    
    $autoAcceptConditions = [];
    if (isset($_POST['auto_accept_members_only'])) $autoAcceptConditions[] = 'members_only';
    if (isset($_POST['auto_accept_off_peak'])) $autoAcceptConditions[] = 'off_peak';
    if (isset($_POST['auto_accept_low_occupancy'])) $autoAcceptConditions[] = 'low_occupancy';
    
    $autoCancelConditions = [];
    if (isset($_POST['auto_cancel_high_occupancy'])) $autoCancelConditions[] = 'high_occupancy';
    if (isset($_POST['auto_cancel_maintenance'])) $autoCancelConditions[] = 'maintenance';
    if (isset($_POST['auto_cancel_non_members'])) $autoCancelConditions[] = 'non_members';
    
    $autoAcceptOccupancyThreshold = isset($_POST['auto_accept_occupancy_threshold']) ? intval($_POST['auto_accept_occupancy_threshold']) : 50;
    $autoCancelOccupancyThreshold = isset($_POST['auto_cancel_occupancy_threshold']) ? intval($_POST['auto_cancel_occupancy_threshold']) : 95;
    $autoCancelReason = $_POST['auto_cancel_reason'] ?? 'Due to high demand, we cannot accommodate your booking at this time.';
    
    $settings = [
        'auto_accept_enabled' => $autoAcceptEnabled,
        'auto_accept_conditions' => $autoAcceptConditions,
        'auto_accept_occupancy_threshold' => $autoAcceptOccupancyThreshold,
        'auto_cancel_enabled' => $autoCancelEnabled,
        'auto_cancel_conditions' => $autoCancelConditions,
        'auto_cancel_occupancy_threshold' => $autoCancelOccupancyThreshold,
        'auto_cancel_reason' => $autoCancelReason
    ];
    
    if (saveAutoProcessSettings($gym_id, $settings)) {
        $success = 'Auto-processing settings updated successfully';
    } else {
        $error = 'Error updating auto-processing settings';
    }
}

// Process run auto-process
if (isset($_POST['run_auto_process'])) {
    $result = processSchedulesAutomatically($gym_id);
    
    if ($result['success']) {
        $success = "Auto-processing completed: {$result['accepted']} schedules accepted, {$result['cancelled']} schedules cancelled";
    } else {
        $error = 'Error running auto-processing: ' . ($result['error'] ?? 'Unknown error');
    }
}

// Get auto-processing settings
$autoSettings = getAutoProcessSettings($gym_id);

// Get pending schedules
$pendingStmt = $conn->prepare("
    SELECT s.id, s.user_id, s.start_date, s.start_time, s.activity_type, s.notes,
           u.username, u.email, u.profile_image
    FROM schedules s
    JOIN users u ON s.user_id = u.id
    WHERE s.gym_id = ? AND s.status = 'scheduled'
    ORDER BY s.start_date ASC, s.start_time ASC
");
$pendingStmt->execute([$gym_id]);
$pendingSchedules = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);

// Build the query based on filter and search
$query = "
    SELECT n.id, n.title, n.message, n.created_at, n.is_read,
           u.username, u.profile_image, u.id as user_id
    FROM gym_notifications n
    LEFT JOIN users u ON n.id = u.id
    WHERE n.gym_id = ?
";

$params = [$gym_id];

if ($filter === 'unread') {
    $query .= " AND n.is_read = 0";
} elseif ($filter === 'read') {
    $query .= " AND n.is_read = 1";
} elseif (in_array($filter, ['booking', 'payment', 'review', 'system'])) {
    $query .= " AND n.type = ?";
    $params[] = $filter;
}

if (!empty($search)) {
    $query .= " AND (n.title LIKE ? OR n.message LIKE ? OR u.username LIKE ?)";
    $searchTerm = "%{$search}%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

// Count total notifications for pagination
$countQuery = str_replace("SELECT n.id, n.title, n.message, n.type, n.related_id, n.created_at, n.is_read,
           u.username, u.profile_image, u.user_id as user_id", "SELECT COUNT(*)", $query);
$countStmt = $conn->prepare($countQuery);
$countStmt->execute($params);
$totalNotifications = $countStmt->fetchColumn();

// Calculate total pages
$totalPages = ceil($totalNotifications / $perPage);

// Add pagination to the query
$query .= " ORDER BY n.created_at DESC LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;
$notificationsStmt = $conn->prepare($query);
$notificationsStmt->execute($params);

// Count unread notifications
$unreadStmt = $conn->prepare("
    SELECT COUNT(*) FROM gym_notifications 
    WHERE gym_id = ? AND is_read = 0
");
$unreadStmt->execute([$gym_id]);
$unreadCount = $unreadStmt->fetchColumn();

// Get recent activity (last 5 schedule logs)
$activityStmt = $conn->prepare("
    SELECT sl.id, sl.user_id, sl.schedule_id, sl.action_type, sl.notes, sl.created_at,
           u.username, u.profile_image,
           s.start_date, s.start_time, s.activity_type
    FROM schedule_logs sl
    JOIN schedules s ON sl.schedule_id = s.id
    LEFT JOIN users u ON sl.user_id = u.id
    WHERE s.gym_id = ?
    ORDER BY sl.created_at DESC
    LIMIT 5
");
$activityStmt->execute([$gym_id]);
$recentActivity = $activityStmt->fetchAll(PDO::FETCH_ASSOC);

// Page title
$pageTitle = "Notifications & Schedule Management";
?>

    <style>
        body {
            background-color: #111827;
            color: #f3f4f6;
        }
        
        .tab {
            position: relative;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .tab.active {
            color: #F59E0B;
            border-bottom: 2px solid #F59E0B;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
            animation: fadeIn 0.3s ease-in-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .notification-item {
            transition: all 0.3s ease;
        }
        
        .notification-item:hover {
            background-color: #1F2937;
        }
        
        .fade-out {
            opacity: 0;
            transform: translateX(20px);
            transition: all 0.5s ease;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            z-index: 1000;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .modal.show {
            display: block;
            opacity: 1;
        }
        
        .modal-content {
            transform: translateY(-20px);
            transition: transform 0.3s ease;
        }
        
        .modal.show .modal-content {
            transform: translateY(0);
        }
        
        .checkbox-container {
            display: flex;
            align-items: center;
        }
        
        .checkbox-container input[type="checkbox"] {
            width: 18px;
            height: 18px;
            margin-right: 10px;
            accent-color: #F59E0B;
        }
        
        /* Custom switch styling */
        .switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }
        
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #4B5563;
            transition: .4s;
            border-radius: 24px;
        }
        
        .slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        input:checked + .slider {
            background-color: #F59E0B;
        }
        
        input:checked + .slider:before {
            transform: translateX(26px);
        }
        
        /* Filter dropdown */
        #filterMenu {
            display: none;
            position: absolute;
            right: 0;
            top: 100%;
            z-index: 10;
            transition: all 0.3s ease;
        }
        
        #filterMenu.show {
            display: block;
            animation: fadeIn 0.2s ease-in-out;
        }
        
        /* Pagination styling */
        .pagination-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            border-radius: 9999px;
            transition: all 0.2s ease;
        }
        
        .pagination-link:hover:not(.active) {
            background-color: #374151;
        }
        
        .pagination-link.active {
            background-color: #F59E0B;
            color: #000;
            font-weight: 600;
        }
    </style>

    <?php include '../includes/navbar.php'; ?>
    
    <div class="container mx-auto px-4 py-8 pt-24">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6">
            <h1 class="text-3xl font-bold text-white mb-4 md:mb-0">
                <i class="fas fa-bell mr-3 text-yellow-500"></i>
                <?= $pageTitle ?>
            </h1>
            
            <div class="flex flex-col sm:flex-row gap-3">
                <form method="post" action="" class="inline-block">
                    <button type="submit" name="mark_all_read" class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition-colors duration-200 flex items-center">
                        <i class="fas fa-check-double mr-2"></i> Mark All Read
                    </button>
                </form>
                
                <form method="post" action="" class="inline-block">
                    <button type="submit" name="run_auto_process" class="bg-blue-700 hover:bg-blue-600 text-white px-4 py-2 rounded-lg transition-colors duration-200 flex items-center">
                        <i class="fas fa-robot mr-2"></i> Run Auto-Process
                    </button>
                </form>
                
                <div class="relative">
                    <button id="filterDropdown" class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition-colors duration-200 flex items-center">
                        <i class="fas fa-filter mr-2"></i> Filter
                        <i class="fas fa-chevron-down ml-2 text-xs transition-transform duration-200"></i>
                    </button>
                    
                    <div id="filterMenu" class="bg-gray-800 rounded-lg shadow-lg p-4 w-48">
                        <h4 class="text-sm font-semibold text-gray-400 mb-2">Filter By:</h4>
                        <ul class="space-y-2">
                            <li>
                                <a href="?filter=all" class="block text-white hover:text-yellow-400 transition-colors duration-200 <?= $filter === 'all' ? 'text-yellow-400' : '' ?>">
                                    <i class="fas fa-globe-americas mr-2"></i> All
                                </a>
                            </li>
                            <li>
                                <a href="?filter=unread" class="block text-white hover:text-yellow-400 transition-colors duration-200 <?= $filter === 'unread' ? 'text-yellow-400' : '' ?>">
                                    <i class="fas fa-envelope mr-2"></i> Unread
                                </a>
                            </li>
                            <li>
                                <a href="?filter=read" class="block text-white hover:text-yellow-400 transition-colors duration-200 <?= $filter === 'read' ? 'text-yellow-400' : '' ?>">
                                    <i class="fas fa-envelope-open mr-2"></i> Read
                                </a>
                            </li>
                            <li>
                                <a href="?filter=booking" class="block text-white hover:text-yellow-400 transition-colors duration-200 <?= $filter === 'booking' ? 'text-yellow-400' : '' ?>">
                                    <i class="fas fa-calendar-check mr-2"></i> Bookings
                                </a>
                            </li>
                            <li>
                                <a href="?filter=payment" class="block text-white hover:text-yellow-400 transition-colors duration-200 <?= $filter === 'payment' ? 'text-yellow-400' : '' ?>">
                                    <i class="fas fa-money-bill-wave mr-2"></i> Payments
                                </a>
                            </li>
                            <li>
                                <a href="?filter=review" class="block text-white hover:text-yellow-400 transition-colors duration-200 <?= $filter === 'review' ? 'text-yellow-400' : '' ?>">
                                    <i class="fas fa-star mr-2"></i> Reviews
                                </a>
                            </li>
                            <li>
                                <a href="?filter=system" class="block text-white hover:text-yellow-400 transition-colors duration-200 <?= $filter === 'system' ? 'text-yellow-400' : '' ?>">
                                    <i class="fas fa-cog mr-2"></i> System
                                </a>
                            </li>
                        </ul>
                        
                        <div class="mt-4 pt-4 border-t border-gray-700">
                            <form action="" method="get">
                                <div class="flex">
                                    <input type="text" name="search" placeholder="Search..." value="<?= htmlspecialchars($search) ?>" 
                                           class="bg-gray-700 text-white rounded-l-lg px-3 py-2 w-full focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                    <button type="submit" class="bg-yellow-600 hover:bg-yellow-700 text-white px-3 rounded-r-lg">
                                        <i class="fas fa-search"></i>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if ($error): ?>
            <div class="bg-red-900 text-white p-4 rounded-lg mb-6">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas fa-exclamation-circle text-red-300"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm"><?= $error ?></p>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="bg-green-900 text-white p-4 rounded-lg mb-6">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas fa-check-circle text-green-300"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm"><?= $success ?></p>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        
        <div class="bg-gray-800 rounded-lg shadow-lg overflow-hidden">
            <!-- Tabs -->
            <div class="flex border-b border-gray-700">
                <div class="tab active px-6 py-4 text-lg font-medium" data-tab="notifications">
                    Notifications
                    <?php if ($unreadCount > 0): ?>
                        <span class="ml-2 bg-yellow-500 text-black text-xs font-bold px-2 py-1 rounded-full"><?= $unreadCount ?></span>
                    <?php endif; ?>
                </div>
                <div class="tab px-6 py-4 text-lg font-medium" data-tab="pending">
                    Pending Schedules
                    <?php if (count($pendingSchedules) > 0): ?>
                        <span class="ml-2 bg-blue-500 text-white text-xs font-bold px-2 py-1 rounded-full"><?= count($pendingSchedules) ?></span>
                    <?php endif; ?>
                </div>
                <div class="tab px-6 py-4 text-lg font-medium" data-tab="activity">
                    Recent Activity
                </div>
                <div class="tab px-6 py-4 text-lg font-medium" data-tab="settings">
                    Auto-Processing
                </div>
            </div>
            
            <!-- Tab Contents -->
            <div class="p-6">
                <!-- Notifications Tab -->
                <div id="notifications-content" class="tab-content active">
                    <?php if (empty($notifications)): ?>
                        <div class="bg-gray-800 rounded-lg p-8 text-center">
                            <i class="fas fa-bell-slash text-gray-500 text-5xl mb-4"></i>
                            <h3 class="text-xl font-semibold text-white mb-2">No notifications found</h3>
                            <p class="text-gray-400">
                                <?php if (!empty($search)): ?>
                                    No notifications match your search criteria. <a href="?filter=all" class="text-yellow-400 hover:underline">Clear filters</a>
                                    <?php else: ?>
                                    You don't have any notifications yet. Check back later.
                                <?php endif; ?>
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($notifications as $notification): ?>
                                <div id="notification-<?= $notification['id'] ?>" class="notification-item bg-gray-800 rounded-lg p-4 flex items-start <?= $notification['is_read'] ? '' : 'border-l-4 border-yellow-500' ?>">
                                    <div class="flex-shrink-0 mr-4">
                                        <?php if ($notification['user_id']): ?>
                                            <?php if ($notification['profile_image']): ?>
                                                <img src="<?= htmlspecialchars($notification['profile_image']) ?>" alt="User" class="w-12 h-12 rounded-full object-cover">
                                            <?php else: ?>
                                                <div class="w-12 h-12 rounded-full bg-gray-600 flex items-center justify-center">
                                                    <i class="fas fa-user text-gray-400"></i>
                                                </div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <div class="w-12 h-12 rounded-full bg-blue-900 flex items-center justify-center">
                                                <i class="fas fa-<?= getNotificationIcon($notification['type']) ?> text-blue-300"></i>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="flex-grow">
                                        <div class="flex justify-between items-start">
                                            <div>
                                                <h3 class="text-lg font-semibold text-white">
                                                    <?= htmlspecialchars($notification['title']) ?>
                                                    <?php if (!$notification['is_read']): ?>
                                                        <span class="ml-2 bg-yellow-500 text-black text-xs px-2 py-0.5 rounded">New</span>
                                                    <?php endif; ?>
                                                </h3>
                                                
                                                <?php if ($notification['user_id']): ?>
                                                    <p class="text-sm text-gray-400">
                                                        From: <?= htmlspecialchars($notification['username']) ?>
                                                    </p>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <div class="text-sm text-gray-500">
                                                <?= formatTimeAgo(strtotime($notification['created_at'])) ?>
                                            </div>
                                        </div>
                                        
                                        <div class="mt-2 text-gray-300 whitespace-pre-line">
                                            <?= nl2br(htmlspecialchars($notification['message'])) ?>
                                        </div>
                                        
                                        <div class="mt-3 flex items-center justify-between">
                                            <div class="flex items-center space-x-2">
                                                <?php if ($notification['type'] === 'booking' && $notification['related_id']): ?>
                                                    <a href="view_schedule.php?id=<?= $notification['related_id'] ?>" class="text-blue-400 hover:text-blue-300 text-sm">
                                                        <i class="fas fa-calendar-alt mr-1"></i> View Schedule
                                                    </a>
                                                <?php elseif ($notification['type'] === 'payment' && $notification['related_id']): ?>
                                                    <a href="view_payment.php?id=<?= $notification['related_id'] ?>" class="text-green-400 hover:text-green-300 text-sm">
                                                        <i class="fas fa-money-bill-wave mr-1"></i> View Payment
                                                    </a>
                                                <?php elseif ($notification['type'] === 'review' && $notification['related_id']): ?>
                                                    <a href="view_review.php?id=<?= $notification['related_id'] ?>" class="text-yellow-400 hover:text-yellow-300 text-sm">
                                                        <i class="fas fa-star mr-1"></i> View Review
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <div class="flex items-center space-x-2">
                                                <?php if (!$notification['is_read']): ?>
                                                    <form class="mark-read-form" method="post">
                                                        <input type="hidden" name="notification_id" value="<?= $notification['id'] ?>">
                                                        <button type="submit" name="mark_read" class="text-gray-400 hover:text-white text-sm">
                                                            <i class="fas fa-check mr-1"></i> Mark as Read
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <span class="text-sm text-gray-500">
                                                        <i class="fas fa-check mr-1"></i> Read
                                                    </span>
                                                <?php endif; ?>
                                                
                                                <form class="delete-notification-form" method="post">
                                                    <input type="hidden" name="notification_id" value="<?= $notification['id'] ?>">
                                                    <button type="submit" name="delete_notification" class="text-red-400 hover:text-red-300 text-sm">
                                                        <i class="fas fa-trash-alt mr-1"></i> Delete
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            
                            <!-- Pagination -->
                            <?php if ($totalPages > 1): ?>
                                <div class="flex justify-center mt-6">
                                    <div class="flex space-x-1">
                                        <?php if ($page > 1): ?>
                                            <a href="?filter=<?= $filter ?>&search=<?= urlencode($search) ?>&page=<?= $page - 1 ?>" class="pagination-link bg-gray-700 text-gray-300">
                                                <i class="fas fa-chevron-left"></i>
                                            </a>
                                        <?php endif; ?>
                                        
                                        <?php
                                        $startPage = max(1, $page - 2);
                                        $endPage = min($totalPages, $startPage + 4);
                                        
                                        if ($endPage - $startPage < 4 && $startPage > 1) {
                                            $startPage = max(1, $endPage - 4);
                                        }
                                        
                                        for ($i = $startPage; $i <= $endPage; $i++):
                                        ?>
                                            <a href="?filter=<?= $filter ?>&search=<?= urlencode($search) ?>&page=<?= $i ?>" 
                                               class="pagination-link <?= $i === $page ? 'active' : 'bg-gray-700 text-gray-300' ?>">
                                                <?= $i ?>
                                            </a>
                                        <?php endfor; ?>
                                        
                                        <?php if ($page < $totalPages): ?>
                                            <a href="?filter=<?= $filter ?>&search=<?= urlencode($search) ?>&page=<?= $page + 1 ?>" class="pagination-link bg-gray-700 text-gray-300">
                                                <i class="fas fa-chevron-right"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Pending Schedules Tab -->
                <div id="pending-content" class="tab-content">
                    <?php if (empty($pendingSchedules)): ?>
                        <div class="bg-gray-800 rounded-lg p-8 text-center">
                            <i class="fas fa-calendar-check text-gray-500 text-5xl mb-4"></i>
                            <h3 class="text-xl font-semibold text-white mb-2">No pending schedules</h3>
                            <p class="text-gray-400">
                                You don't have any pending schedules that need approval.
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full bg-gray-800 rounded-lg overflow-hidden">
                                <thead class="bg-gray-700">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">User</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Date & Time</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Activity</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Notes</th>
                                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-300 uppercase tracking-wider">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-700">
                                    <?php foreach ($pendingSchedules as $schedule): ?>
                                        <tr class="hover:bg-gray-700 transition-colors duration-200">
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="flex items-center">
                                                    <div class="flex-shrink-0 h-10 w-10">
                                                        <?php if ($schedule['profile_image']): ?>
                                                            <img class="h-10 w-10 rounded-full object-cover" src="<?= htmlspecialchars($schedule['profile_image']) ?>" alt="User">
                                                        <?php else: ?>
                                                            <div class="h-10 w-10 rounded-full bg-gray-600 flex items-center justify-center">
                                                                <i class="fas fa-user text-gray-400"></i>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="ml-4">
                                                        <div class="text-sm font-medium text-white"><?= htmlspecialchars($schedule['username']) ?></div>
                                                        <div class="text-sm text-gray-400"><?= htmlspecialchars($schedule['email']) ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="text-sm text-white"><?= date('F j, Y', strtotime($schedule['start_date'])) ?></div>
                                                <div class="text-sm text-gray-400"><?= date('g:i A', strtotime($schedule['start_time'])) ?></div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                                    <?= getActivityBadgeColor($schedule['activity_type']) ?>">
                                                    <?= ucfirst(str_replace('_', ' ', $schedule['activity_type'])) ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4">
                                                <div class="text-sm text-gray-300 truncate max-w-xs">
                                                    <?= !empty($schedule['notes']) ? htmlspecialchars($schedule['notes']) : '<span class="text-gray-500 italic">No notes</span>' ?>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                <button class="accept-btn text-green-400 hover:text-green-300 mr-3" data-id="<?= $schedule['id'] ?>">
                                                    <i class="fas fa-check mr-1"></i> Accept
                                                </button>
                                                <button class="cancel-btn text-red-400 hover:text-red-300" data-id="<?= $schedule['id'] ?>">
                                                    <i class="fas fa-times mr-1"></i> Cancel
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Recent Activity Tab -->
                <div id="activity-content" class="tab-content">
                    <?php if (empty($recentActivity)): ?>
                        <div class="bg-gray-800 rounded-lg p-8 text-center">
                            <i class="fas fa-history text-gray-500 text-5xl mb-4"></i>
                            <h3 class="text-xl font-semibold text-white mb-2">No recent activity</h3>
                            <p class="text-gray-400">
                                There hasn't been any recent activity for your gym.
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($recentActivity as $activity): ?>
                                <div class="bg-gray-800 rounded-lg p-4">
                                    <div class="flex items-start">
                                        <div class="flex-shrink-0 mr-4">
                                            <?php if ($activity['user_id'] && isset($activity['profile_image'])): ?>
                                                <?php if ($activity['profile_image']): ?>
                                                    <img src="<?= htmlspecialchars($activity['profile_image']) ?>" alt="User" class="w-12 h-12 rounded-full object-cover">
                                                <?php else: ?>
                                                    <div class="w-12 h-12 rounded-full bg-gray-600 flex items-center justify-center">
                                                        <i class="fas fa-user text-gray-400"></i>
                                                    </div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <div class="w-12 h-12 rounded-full bg-gray-600 flex items-center justify-center">
                                                    <i class="fas fa-cog text-gray-400"></i>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="flex-grow">
                                            <div class="flex justify-between items-start">
                                                <div>
                                                    <h3 class="text-lg font-semibold text-white">
                                                        <?= getActivityTitle($activity['action_type']) ?>
                                                    </h3>
                                                    
                                                    <?php if ($activity['user_id'] && isset($activity['username'])): ?>
                                                        <p class="text-sm text-gray-400">
                                                            By: <?= htmlspecialchars($activity['username'] ?? 'System') ?>
                                                        </p>
                                                    <?php else: ?>
                                                        <p class="text-sm text-gray-400">
                                                            By: System
                                                        </p>
                                                    <?php endif; ?>
                                                </div>
                                                
                                                <div class="text-sm text-gray-500">
                                                    <?= formatTimeAgo(strtotime($activity['created_at'])) ?>
                                                </div>
                                            </div>
                                            
                                            <div class="mt-2 text-gray-300">
                                                <p>
                                                    <span class="font-medium">Schedule:</span> 
                                                    <?= date('F j, Y', strtotime($activity['start_date'])) ?> at 
                                                    <?= date('g:i A', strtotime($activity['start_time'])) ?> - 
                                                    <?= ucfirst(str_replace('_', ' ', $activity['activity_type'])) ?>
                                                </p>
                                                
                                                <?php if (!empty($activity['notes'])): ?>
                                                    <p class="mt-1 text-sm text-gray-400">
                                                        <span class="font-medium">Notes:</span> 
                                                        <?= htmlspecialchars($activity['notes']) ?>
                                                    </p>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <div class="mt-3">
                                                <a href="view_schedule.php?id=<?= $activity['schedule_id'] ?>" class="text-blue-400 hover:text-blue-300 text-sm">
                                                    <i class="fas fa-calendar-alt mr-1"></i> View Schedule
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            
                            <div class="text-center mt-6">
                                <a href="activity_logs.php" class="inline-flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-black bg-yellow-500 hover:bg-yellow-600 transition-colors duration-200">
                                    <i class="fas fa-history mr-2"></i> View All Activity
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Auto-Processing Settings Tab -->
                <div id="settings-content" class="tab-content">
                    <div class="bg-gray-800 rounded-lg p-6">
                        <h3 class="text-xl font-semibold text-white mb-4">Auto-Processing Settings</h3>
                        <p class="text-gray-400 mb-6">
                            Configure how the system automatically processes schedules for your gym. This can help streamline operations and reduce manual work.
                        </p>
                        
                        <form method="post" action="">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                                <!-- Auto-Accept Settings -->
                                <div class="bg-gray-700 rounded-lg p-5">
                                    <div class="flex items-center justify-between mb-4">
                                        <h4 class="text-lg font-medium text-white">Auto-Accept Bookings</h4>
                                        <label class="switch">
                                            <input type="checkbox" name="auto_accept_enabled" <?= $autoSettings['auto_accept_enabled'] ? 'checked' : '' ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    
                                    <p class="text-sm text-gray-400 mb-4">
                                        Automatically accept bookings that meet the criteria below.
                                    </p>
                                    
                                    <div class="space-y-3 mb-4">
                                        <div class="checkbox-container">
                                            <input type="checkbox" id="auto_accept_members_only" name="auto_accept_members_only" 
                                                <?= in_array('members_only', $autoSettings['auto_accept_conditions'] ?? []) ? 'checked' : '' ?>>
                                            <label for="auto_accept_members_only" class="text-gray-300">
                                                Accept bookings from active members
                                            </label>
                                        </div>
                                        
                                        <div class="checkbox-container">
                                            <input type="checkbox" id="auto_accept_off_peak" name="auto_accept_off_peak"
                                                <?= in_array('off_peak', $autoSettings['auto_accept_conditions'] ?? []) ? 'checked' : '' ?>>
                                            <label for="auto_accept_off_peak" class="text-gray-300">
                                                Accept bookings during off-peak hours (10 AM - 4 PM)
                                            </label>
                                        </div>
                                        
                                        <div class="checkbox-container">
                                            <input type="checkbox" id="auto_accept_low_occupancy" name="auto_accept_low_occupancy"
                                                <?= in_array('low_occupancy', $autoSettings['auto_accept_conditions'] ?? []) ? 'checked' : '' ?>>
                                            <label for="auto_accept_low_occupancy" class="text-gray-300">
                                                Accept bookings when occupancy is below threshold
                                            </label>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-4">
                                        <label for="auto_accept_occupancy_threshold" class="block text-sm font-medium text-gray-400 mb-2">
                                            Occupancy Threshold (%)
                                        </label>
                                        <input type="range" id="auto_accept_occupancy_threshold" name="auto_accept_occupancy_threshold" 
                                               min="10" max="90" step="5" value="<?= $autoSettings['auto_accept_occupancy_threshold'] ?? 50 ?>"
                                               class="w-full h-2 bg-gray-600 rounded-lg appearance-none cursor-pointer">
                                        <div class="flex justify-between text-xs text-gray-500 mt-1">
                                            <span>10%</span>
                                            <span id="auto_accept_threshold_display"><?= $autoSettings['auto_accept_occupancy_threshold'] ?? 50 ?>%</span>
                                            <span>90%</span>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Auto-Cancel Settings -->
                                <div class="bg-gray-700 rounded-lg p-5">
                                    <div class="flex items-center justify-between mb-4">
                                        <h4 class="text-lg font-medium text-white">Auto-Cancel Bookings</h4>
                                        <label class="switch">
                                            <input type="checkbox" name="auto_cancel_enabled" <?= $autoSettings['auto_cancel_enabled'] ? 'checked' : '' ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    
                                    <p class="text-sm text-gray-400 mb-4">
                                        Automatically cancel bookings that meet the criteria below.
                                    </p>
                                    
                                    <div class="space-y-3 mb-4">
                                        <div class="checkbox-container">
                                            <input type="checkbox" id="auto_cancel_high_occupancy" name="auto_cancel_high_occupancy"
                                                <?= in_array('high_occupancy', $autoSettings['auto_cancel_conditions'] ?? []) ? 'checked' : '' ?>>
                                            <label for="auto_cancel_high_occupancy" class="text-gray-300">
                                                Cancel when occupancy exceeds threshold
                                            </label>
                                        </div>
                                        
                                        <div class="checkbox-container">
                                            <input type="checkbox" id="auto_cancel_maintenance" name="auto_cancel_maintenance"
                                                <?= in_array('maintenance', $autoSettings['auto_cancel_conditions'] ?? []) ? 'checked' : '' ?>>
                                            <label for="auto_cancel_maintenance" class="text-gray-300">
                                                Cancel when maintenance is scheduled
                                            </label>
                                        </div>
                                        
                                        <div class="checkbox-container">
                                            <input type="checkbox" id="auto_cancel_non_members" name="auto_cancel_non_members"
                                                <?= in_array('non_members', $autoSettings['auto_cancel_conditions'] ?? []) ? 'checked' : '' ?>>
                                            <label for="auto_cancel_non_members" class="text-gray-300">
                                                Cancel non-member bookings during peak hours
                                            </label>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-4">
                                        <label for="auto_cancel_occupancy_threshold" class="block text-sm font-medium text-gray-400 mb-2">
                                            Occupancy Threshold (%)
                                        </label>
                                        <input type="range" id="auto_cancel_occupancy_threshold" name="auto_cancel_occupancy_threshold" 
                                               min="50" max="100" step="5" value="<?= $autoSettings['auto_cancel_occupancy_threshold'] ?? 95 ?>"
                                               class="w-full h-2 bg-gray-600 rounded-lg appearance-none cursor-pointer">
                                        <div class="flex justify-between text-xs text-gray-500 mt-1">
                                            <span>50%</span>
                                            <span id="auto_cancel_threshold_display"><?= $autoSettings['auto_cancel_occupancy_threshold'] ?? 95 ?>%</span>
                                            <span>100%</span>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-4">
                                        <label for="auto_cancel_reason" class="block text-sm font-medium text-gray-400 mb-2">
                                            Cancellation Reason
                                        </label>
                                        <textarea id="auto_cancel_reason" name="auto_cancel_reason" rows="3" 
                                                  class="w-full bg-gray-600 border border-gray-500 rounded-lg px-3 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500"><?= htmlspecialchars($autoSettings['auto_cancel_reason'] ?? 'Due to high demand, we cannot accommodate your booking at this time.') ?></textarea>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mt-6 text-center">
                                <button type="submit" name="update_auto_settings" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-black bg-yellow-500 hover:bg-yellow-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-yellow-500">
                                    <i class="fas fa-save mr-2"></i> Save Settings
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Cancel Confirmation Modal -->
    <div id="cancelModal" class="modal">
        <div class="modal-content bg-gray-800 rounded-lg shadow-xl max-w-md mx-auto mt-20 p-6">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-bold text-white">Cancel Schedule</h3>
                <button id="closeCancelModal" class="text-gray-400 hover:text-white">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <p class="text-gray-300 mb-4">
                Are you sure you want to cancel this schedule? This action cannot be undone.
            </p>
            
            <div class="mb-4">
                <label for="cancelReason" class="block text-sm font-medium text-gray-400 mb-2">
                    Cancellation Reason (will be sent to the user)
                </label>
                <textarea id="cancelReason" rows="3" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500"></textarea>
            </div>
            
            <div class="flex justify-end space-x-3">
                <button id="confirmCancelBtn" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors duration-200">
                    <i class="fas fa-times mr-2"></i> Cancel Schedule
                </button>
                <button id="cancelCancelBtn" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200">
                    <i class="fas fa-arrow-left mr-2"></i> Go Back
                </button>
            </div>
        </div>
    </div>
    
    <?php include '../includes/footer.php'; ?>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Tab switching
            const tabs = document.querySelectorAll('.tab');
            const tabContents = document.querySelectorAll('.tab-content');
            
            tabs.forEach(tab => {
                tab.addEventListener('click', function() {
                    const target = this.dataset.tab;
                    
                    // Remove active class from all tabs and contents
                    tabs.forEach(t => t.classList.remove('active'));
                    tabContents.forEach(c => c.classList.remove('active'));
                    
                    // Add active class to clicked tab and corresponding content
                    this.classList.add('active');
                    document.getElementById(`${target}-content`).classList.add('active');
                });
            });
            
            // Filter dropdown
            const filterDropdown = document.getElementById('filterDropdown');
            const filterMenu = document.getElementById('filterMenu');
            
            if (filterDropdown && filterMenu) {
                filterDropdown.addEventListener('click', function() {
                    filterMenu.classList.toggle('show');
                    
                                        // Toggle chevron direction
                                        const chevron = this.querySelector('.fa-chevron-down');
                    if (chevron) {
                        chevron.classList.toggle('transform');
                        chevron.classList.toggle('rotate-180');
                    }
                });
                
                // Close filter menu when clicking outside
                document.addEventListener('click', function(event) {
                    if (!filterDropdown.contains(event.target) && !filterMenu.contains(event.target)) {
                        filterMenu.classList.remove('show');
                        
                        // Reset chevron direction
                        const chevron = filterDropdown.querySelector('.fa-chevron-down');
                        if (chevron) {
                            chevron.classList.remove('transform');
                            chevron.classList.remove('rotate-180');
                        }
                    }
                });
            }
            
            // Mark as read functionality with AJAX
            const markReadForms = document.querySelectorAll('.mark-read-form');
            
            markReadForms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    const notificationId = this.querySelector('input[name="notification_id"]').value;
                    const formData = new FormData(this);
                    
                    fetch('notifications.php', {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Update UI to show notification as read
                            const notification = document.getElementById(`notification-${notificationId}`);
                            if (notification) {
                                notification.classList.remove('border-l-4', 'border-yellow-500');
                                
                                // Update the button to show "Read" instead of "Mark as Read"
                                const button = this.querySelector('button');
                                button.outerHTML = '<span class="text-sm text-gray-500"><i class="fas fa-check mr-1"></i> Read</span>';
                                
                                // Remove "New" badge
                                const badge = notification.querySelector('.bg-yellow-500.text-black');
                                if (badge) {
                                    badge.remove();
                                }
                                
                                // Update unread count
                                const unreadCountElement = document.querySelector('.tab[data-tab="notifications"] span');
                                if (unreadCountElement) {
                                    let count = parseInt(unreadCountElement.textContent);
                                    if (count > 0) {
                                        count--;
                                        if (count === 0) {
                                            unreadCountElement.remove();
                                        } else {
                                            unreadCountElement.textContent = count;
                                        }
                                    }
                                }
                            }
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                    });
                });
            });
            
            // Delete notification functionality with AJAX
            const deleteNotificationForms = document.querySelectorAll('.delete-notification-form');
            
            deleteNotificationForms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    const notificationId = this.querySelector('input[name="notification_id"]').value;
                    const formData = new FormData(this);
                    
                    fetch('notifications.php', {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Fade out and remove the notification
                            const notification = document.getElementById(`notification-${notificationId}`);
                            if (notification) {
                                notification.classList.add('fade-out');
                                
                                setTimeout(() => {
                                    notification.remove();
                                    
                                    // Check if there are no more notifications
                                    const notifications = document.querySelectorAll('.notification-item');
                                    if (notifications.length === 0) {
                                        const notificationsContent = document.getElementById('notifications-content');
                                        notificationsContent.innerHTML = `
                                            <div class="bg-gray-800 rounded-lg p-8 text-center">
                                                <i class="fas fa-bell-slash text-gray-500 text-5xl mb-4"></i>
                                                <h3 class="text-xl font-semibold text-white mb-2">No notifications found</h3>
                                                <p class="text-gray-400">
                                                    You don't have any notifications yet. Check back later.
                                                </p>
                                            </div>
                                        `;
                                    }
                                }, 500);
                                
                                // If it was unread, update the unread count
                                if (notification.classList.contains('border-l-4')) {
                                    const unreadCountElement = document.querySelector('.tab[data-tab="notifications"] span');
                                    if (unreadCountElement) {
                                        let count = parseInt(unreadCountElement.textContent);
                                        if (count > 0) {
                                            count--;
                                            if (count === 0) {
                                                unreadCountElement.remove();
                                            } else {
                                                unreadCountElement.textContent = count;
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                    });
                });
            });
            
            // Accept schedule functionality
            const acceptButtons = document.querySelectorAll('.accept-btn');
            
            acceptButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const scheduleId = this.dataset.id;
                    
                    fetch('process_schedule.php', {
                        method: 'POST',
                        body: JSON.stringify({
                            action: 'accept',
                            schedule_id: scheduleId
                        }),
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Remove the row from the table
                            const row = this.closest('tr');
                            row.classList.add('fade-out');
                            
                            setTimeout(() => {
                                row.remove();
                                
                                // Check if there are no more pending schedules
                                const pendingSchedules = document.querySelectorAll('#pending-content tbody tr');
                                if (pendingSchedules.length === 0) {
                                    document.getElementById('pending-content').innerHTML = `
                                        <div class="bg-gray-800 rounded-lg p-8 text-center">
                                            <i class="fas fa-calendar-check text-gray-500 text-5xl mb-4"></i>
                                            <h3 class="text-xl font-semibold text-white mb-2">No pending schedules</h3>
                                            <p class="text-gray-400">
                                                You don't have any pending schedules that need approval.
                                            </p>
                                        </div>
                                    `;
                                }
                                
                                // Update the pending count
                                const pendingCountElement = document.querySelector('.tab[data-tab="pending"] span');
                                if (pendingCountElement) {
                                    let count = parseInt(pendingCountElement.textContent);
                                    if (count > 0) {
                                        count--;
                                        if (count === 0) {
                                            pendingCountElement.remove();
                                        } else {
                                            pendingCountElement.textContent = count;
                                        }
                                    }
                                }
                            }, 500);
                            
                            // Show success message
                            showToast('Schedule accepted successfully', 'success');
                        } else {
                            showToast(data.message || 'An error occurred', 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showToast('An error occurred', 'error');
                    });
                });
            });
            
            // Cancel schedule functionality
            const cancelButtons = document.querySelectorAll('.cancel-btn');
            const cancelModal = document.getElementById('cancelModal');
            const closeCancelModal = document.getElementById('closeCancelModal');
            const cancelCancelBtn = document.getElementById('cancelCancelBtn');
            const confirmCancelBtn = document.getElementById('confirmCancelBtn');
            const cancelReasonInput = document.getElementById('cancelReason');
            
            let currentScheduleId = null;
            
            cancelButtons.forEach(button => {
                button.addEventListener('click', function() {
                    currentScheduleId = this.dataset.id;
                    cancelModal.classList.add('show');
                    cancelReasonInput.value = '';
                });
            });
            
            function closeModal() {
                cancelModal.classList.remove('show');
                currentScheduleId = null;
            }
            
            closeCancelModal.addEventListener('click', closeModal);
            cancelCancelBtn.addEventListener('click', closeModal);
            
            // Close modal when clicking outside
            cancelModal.addEventListener('click', function(event) {
                if (event.target === cancelModal) {
                    closeModal();
                }
            });
            
            confirmCancelBtn.addEventListener('click', function() {
                if (!currentScheduleId) return;
                
                const reason = cancelReasonInput.value.trim();
                
                if (!reason) {
                    alert('Please provide a reason for cancellation');
                    return;
                }
                
                fetch('process_schedule.php', {
                    method: 'POST',
                    body: JSON.stringify({
                        action: 'cancel',
                        schedule_id: currentScheduleId,
                        reason: reason
                    }),
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    closeModal();
                    
                    if (data.success) {
                        // Remove the row from the table
                        const row = document.querySelector(`.cancel-btn[data-id="${currentScheduleId}"]`).closest('tr');
                        row.classList.add('fade-out');
                        
                        setTimeout(() => {
                            row.remove();
                            
                            // Check if there are no more pending schedules
                            const pendingSchedules = document.querySelectorAll('#pending-content tbody tr');
                            if (pendingSchedules.length === 0) {
                                document.getElementById('pending-content').innerHTML = `
                                    <div class="bg-gray-800 rounded-lg p-8 text-center">
                                        <i class="fas fa-calendar-check text-gray-500 text-5xl mb-4"></i>
                                        <h3 class="text-xl font-semibold text-white mb-2">No pending schedules</h3>
                                        <p class="text-gray-400">
                                            You don't have any pending schedules that need approval.
                                        </p>
                                    </div>
                                `;
                            }
                            
                            // Update the pending count
                            const pendingCountElement = document.querySelector('.tab[data-tab="pending"] span');
                            if (pendingCountElement) {
                                let count = parseInt(pendingCountElement.textContent);
                                if (count > 0) {
                                    count--;
                                    if (count === 0) {
                                        pendingCountElement.remove();
                                    } else {
                                        pendingCountElement.textContent = count;
                                    }
                                }
                            }
                        }, 500);
                        
                        // Show success message
                        showToast('Schedule cancelled successfully', 'success');
                    } else {
                        showToast(data.message || 'An error occurred', 'error');
                    }
                })
                .catch(error => {
                    closeModal();
                    console.error('Error:', error);
                    showToast('An error occurred', 'error');
                });
            });
            
            // Auto-processing settings sliders
            const autoAcceptThreshold = document.getElementById('auto_accept_occupancy_threshold');
            const autoAcceptDisplay = document.getElementById('auto_accept_threshold_display');
            const autoCancelThreshold = document.getElementById('auto_cancel_occupancy_threshold');
            const autoCancelDisplay = document.getElementById('auto_cancel_threshold_display');
            
            if (autoAcceptThreshold && autoAcceptDisplay) {
                autoAcceptThreshold.addEventListener('input', function() {
                    autoAcceptDisplay.textContent = this.value + '%';
                });
            }
            
            if (autoCancelThreshold && autoCancelDisplay) {
                autoCancelThreshold.addEventListener('input', function() {
                    autoCancelDisplay.textContent = this.value + '%';
                });
            }
            
            // Toast notification function
            function showToast(message, type = 'error') {
                // Check if toast container exists, if not create it
                let toastContainer = document.getElementById('toast-container');
                
                if (!toastContainer) {
                    toastContainer = document.createElement('div');
                    toastContainer.id = 'toast-container';
                    toastContainer.className = 'fixed top-4 right-4 z-50';
                    document.body.appendChild(toastContainer);
                }
                
                // Create toast element
                const toast = document.createElement('div');
                toast.className = `mb-3 p-4 rounded-lg shadow-lg flex items-start max-w-xs transform transition-all duration-300 translate-x-full`;
                
                if (type === 'success') {
                    toast.classList.add('bg-green-900', 'text-white');
                } else {
                    toast.classList.add('bg-red-900', 'text-white');
                }
                
                // Toast content
                toast.innerHTML = `
                    <div class="flex-shrink-0 mr-3">
                        <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
                    </div>
                    <div class="flex-grow">
                        <p>${message}</p>
                    </div>
                    <div class="ml-3 flex-shrink-0">
                        <button type="button" class="text-white hover:text-gray-300 focus:outline-none">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                `;
                
                // Add toast to container
                toastContainer.appendChild(toast);
                
                // Animate in
                setTimeout(() => {
                    toast.classList.remove('translate-x-full');
                }, 10);
                
                // Add close button functionality
                const closeButton = toast.querySelector('button');
                closeButton.addEventListener('click', () => {
                    toast.classList.add('translate-x-full');
                    setTimeout(() => {
                        toast.remove();
                    }, 300);
                });
                
                // Auto remove after 5 seconds
                setTimeout(() => {
                    toast.classList.add('translate-x-full');
                    setTimeout(() => {
                        toast.remove();
                    }, 300);
                }, 5000);
            }
        });
    </script>
</body>
</html>

<?php
/**
 * Helper function to get notification icon based on type
 */
function getNotificationIcon($type) {
    switch ($type) {
        case 'booking':
            return 'calendar-check';
        case 'payment':
            return 'money-bill-wave';
        case 'review':
            return 'star';
        case 'system':
            return 'cog';
        default:
            return 'bell';
    }
}

/**
 * Helper function to format time ago
 */
function formatTimeAgo($timestamp) {
    $current_time = time();
    $time_difference = $current_time - $timestamp;
    
    if ($time_difference < 60) {
        return 'Just now';
    } elseif ($time_difference < 3600) {
        $minutes = floor($time_difference / 60);
        return $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
    } elseif ($time_difference < 86400) {
        $hours = floor($time_difference / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($time_difference < 604800) {
        $days = floor($time_difference / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } elseif ($time_difference < 2592000) {
        $weeks = floor($time_difference / 604800);
        return $weeks . ' week' . ($weeks > 1 ? 's' : '') . ' ago';
    } else {
        return date('M j, Y', $timestamp);
    }
}

/**
 * Helper function to get activity badge color
 */
function getActivityBadgeColor($activityType) {
    switch ($activityType) {
        case 'gym_visit':
            return 'bg-green-100 text-green-800';
        case 'class':
            return 'bg-blue-100 text-blue-800';
        case 'personal_training':
            return 'bg-purple-100 text-purple-800';
        default:
            return 'bg-gray-100 text-gray-800';
    }
}

/**
 * Helper function to get activity title
 */
function getActivityTitle($actionType) {
    switch ($actionType) {
        case 'create':
            return 'Schedule Created';
        case 'update':
            return 'Schedule Updated';
        case 'cancel':
            return 'Schedule Cancelled';
        case 'complete':
            return 'Schedule Completed';
        default:
            return 'Schedule Activity';
    }
}
?>





