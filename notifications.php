<?php
require 'config/database.php';

// Ensure user is logged in before processing anything else
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$db = new GymDatabase();
$conn = $db->getConnection();

// Get timezone setting from system_settings
try {
    $timezoneStmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'default_timezone'");
    $timezoneStmt->execute();
    $timezone = $timezoneStmt->fetchColumn() ?: 'Asia/Kolkata'; // Default to Asia/Kolkata if not found
    
    // Set the timezone
    date_default_timezone_set($timezone);
} catch (PDOException $e) {
    // If there's an error, default to Asia/Kolkata
    date_default_timezone_set('Asia/Kolkata');
    error_log("Error fetching timezone setting: " . $e->getMessage());
}

// Initialize variables
$notifications = [];
$error_message = '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 10; // Pagination for better performance
$offset = ($page - 1) * $per_page;
$total_notifications = 0;
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';

try {
    // Mark notifications as read if requested
    if (isset($_POST['mark_read']) && isset($_POST['notification_id'])) {
        $notification_id = filter_input(INPUT_POST, 'notification_id', FILTER_SANITIZE_NUMBER_INT);
        
        // Use prepared statement for security
        $markReadStmt = $conn->prepare("
            UPDATE notifications 
            SET is_read = 1 
            WHERE id = :notification_id AND user_id = :user_id
        ");
        $markReadStmt->execute([
            ':notification_id' => $notification_id,
            ':user_id' => $user_id
        ]);
    }
    
    // Mark all notifications as read if requested
    if (isset($_POST['mark_all_read'])) {
        $markAllReadStmt = $conn->prepare("
            UPDATE notifications 
            SET is_read = 1 
            WHERE user_id = :user_id AND is_read = 0
        ");
        $markAllReadStmt->execute([':user_id' => $user_id]);
    }
    
    // Delete notification if requested
    if (isset($_POST['delete_notification']) && isset($_POST['notification_id'])) {
        $notification_id = filter_input(INPUT_POST, 'notification_id', FILTER_SANITIZE_NUMBER_INT);
        
        $deleteStmt = $conn->prepare("
            DELETE FROM notifications 
            WHERE id = :notification_id AND user_id = :user_id
        ");
        $deleteStmt->execute([
            ':notification_id' => $notification_id,
            ':user_id' => $user_id
        ]);
    }
    
    // Build the query based on filter
    $whereClause = "WHERE user_id = :user_id";
    if ($filter === 'unread') {
        $whereClause .= " AND is_read = 0";
    } elseif ($filter === 'read') {
        $whereClause .= " AND is_read = 1";
    } elseif (in_array($filter, ['schedule', 'payment', 'membership', 'system'])) {
        $whereClause .= " AND type = :type";
    }
    
    // Get total count for pagination
    $countQuery = "SELECT COUNT(*) FROM notifications $whereClause";
    $countStmt = $conn->prepare($countQuery);
    $countStmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    if (in_array($filter, ['schedule', 'payment', 'membership', 'system'])) {
        $countStmt->bindParam(':type', $filter, PDO::PARAM_STR);
    }
    $countStmt->execute();
    $total_notifications = $countStmt->fetchColumn();
    $total_pages = ceil($total_notifications / $per_page);
    
    // Fetch paginated notifications with read status
    $notificationQuery = "
        SELECT n.id, n.title, n.message, n.created_at, n.is_read, n.type, n.related_id, g.name as gym_name, g.gym_id
        FROM notifications n
        LEFT JOIN gyms g ON n.gym_id = g.gym_id
        $whereClause
        ORDER BY n.created_at DESC
        LIMIT :offset, :per_page
    ";
    
    $notificationStmt = $conn->prepare($notificationQuery);
    $notificationStmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    if (in_array($filter, ['schedule', 'payment', 'membership', 'system'])) {
        $notificationStmt->bindParam(':type', $filter, PDO::PARAM_STR);
    }
    $notificationStmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    $notificationStmt->bindParam(':per_page', $per_page, PDO::PARAM_INT);
    $notificationStmt->execute();
    $notifications = $notificationStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Count unread notifications
    $unreadStmt = $conn->prepare("
        SELECT COUNT(*) FROM notifications 
        WHERE user_id = :user_id AND is_read = 0
    ");
    $unreadStmt->execute([':user_id' => $user_id]);
    $unread_count = $unreadStmt->fetchColumn();
    
    // Get notification type counts for filter badges
    $typeCountsStmt = $conn->prepare("
        SELECT type, COUNT(*) as count, SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread
        FROM notifications
        WHERE user_id = :user_id
        GROUP BY type
    ");
    $typeCountsStmt->execute([':user_id' => $user_id]);
    $typeCounts = [];
    while ($row = $typeCountsStmt->fetch(PDO::FETCH_ASSOC)) {
        $typeCounts[$row['type']] = [
            'count' => $row['count'],
            'unread' => $row['unread']
        ];
    }
    
} catch (PDOException $e) {
    $error_message = "Error retrieving notifications: " . $e->getMessage();
    // Log error to server logs instead of displaying to user
    error_log($error_message);
}

// Include navbar after processing to ensure proper session handling
include 'includes/navbar.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - FeaturesGym</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Custom animation for unread notifications */
        @keyframes pulseOnce {
            0% { transform: scale(1); }
            50% { transform: scale(1.02); }
            100% { transform: scale(1); }
        }
        
        .animate-pulse-once {
            animation: pulseOnce 1s ease-in-out;
        }
        
        /* Smooth transitions */
        .notification-item {
            transition: all 0.3s ease;
        }
        
        /* Fade out animation */
        .fade-out {
            opacity: 0;
            transform: translateX(20px);
            transition: opacity 0.5s ease, transform 0.5s ease;
        }
        
        /* Mobile optimizations */
        @media (max-width: 640px) {
            .notification-actions {
                flex-direction: column;
                align-items: flex-end;
                gap: 0.5rem;
            }
            
            .notification-badge {
                font-size: 0.65rem;
                padding: 0.15rem 0.4rem;
            }
            
            .notification-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
            
            .notification-timestamp {
                margin-left: 0;
                width: 100%;
                text-align: left;
            }
        }
        
        /* Improved filter dropdown */
        .filter-dropdown {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
        }
        
        .filter-dropdown.show {
            max-height: 400px;
        }
        
        /* Improved pagination */
        .pagination-item {
            transition: all 0.2s ease;
        }
        
        .pagination-item:hover:not(.active) {
            transform: translateY(-2px);
        }
        
        /* Skeleton loading animation */
        @keyframes shimmer {
            0% { background-position: -1000px 0; }
            100% { background-position: 1000px 0; }
        }
        
        .skeleton {
            background: linear-gradient(90deg, rgba(255,255,255,0.05) 25%, rgba(255,255,255,0.1) 50%, rgba(255,255,255,0.05) 75%);
            background-size: 1000px 100%;
            animation: shimmer 2s infinite;
        }
    </style>
</head>
<body>

<div class="min-h-screen bg-gradient-to-b from-gray-900 to-black pt-24 pb-12 sm:py-16 lg:py-20">
    <div class="container mx-auto px-4 sm:px-6 lg:px-8 max-w-5xl">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-8 gap-4">
            <h1 class="text-3xl font-bold text-white">Notifications</h1>
            
            <div class="flex flex-col sm:flex-row gap-2 w-full sm:w-auto">
                <?php if ($unread_count > 0): ?>
                <form method="post" action="notifications.php?page=<?= $page ?>&filter=<?= $filter ?>" class="w-full sm:w-auto">
                    <input type="hidden" name="mark_all_read" value="1">
                    <button type="submit" class="w-full sm:w-auto bg-yellow-500 hover:bg-yellow-600 text-black px-4 py-2 rounded-lg transition-colors duration-200 font-medium text-sm">
                        <i class="fas fa-check-double mr-2"></i> Mark All as Read
                    </button>
                </form>
                <?php endif; ?>
                
                <div class="relative w-full sm:w-auto">
                    <button id="filterDropdown" class="w-full sm:w-auto bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition-colors duration-200 font-medium text-sm flex items-center justify-between">
                        <span><i class="fas fa-filter mr-2"></i> Filter</span>
                        <i class="fas fa-chevron-down ml-2 transition-transform duration-200"></i>
                    </button>
                    <div id="filterMenu" class="filter-dropdown absolute right-0 mt-2 w-full sm:w-64 bg-gray-800 rounded-lg shadow-lg z-10 overflow-hidden">
                        <div class="py-1">
                            <a href="notifications.php?filter=all" class="block px-4 py-2 text-sm text-white hover:bg-gray-700 <?= $filter === 'all' ? 'bg-gray-700' : '' ?>">
                                All Notifications
                                <span class="float-right bg-gray-600 text-xs px-2 py-1 rounded-full notification-badge"><?= $total_notifications ?></span>
                            </a>
                            <a href="notifications.php?filter=unread" class="block px-4 py-2 text-sm text-white hover:bg-gray-700 <?= $filter === 'unread' ? 'bg-gray-700' : '' ?>">
                                Unread
                                <span class="float-right bg-yellow-500 text-black text-xs px-2 py-1 rounded-full notification-badge"><?= $unread_count ?></span>
                            </a>
                            <a href="notifications.php?filter=read" class="block px-4 py-2 text-sm text-white hover:bg-gray-700 <?= $filter === 'read' ? 'bg-gray-700' : '' ?>">
                                Read
                                <span class="float-right bg-gray-600 text-xs px-2 py-1 rounded-full notification-badge"><?= $total_notifications - $unread_count ?></span>
                            </a>
                            <div class="border-t border-gray-700 my-1"></div>
                            <a href="notifications.php?filter=schedule" class="block px-4 py-2 text-sm text-white hover:bg-gray-700 <?= $filter === 'schedule' ? 'bg-gray-700' : '' ?>">
                                Schedule
                                <span class="float-right bg-blue-500 text-xs px-2 py-1 rounded-full notification-badge"><?= isset($typeCounts['schedule']) ? $typeCounts['schedule']['count'] : 0 ?></span>
                            </a>
                            <a href="notifications.php?filter=payment" class="block px-4 py-2 text-sm text-white hover:bg-gray-700 <?= $filter === 'payment' ? 'bg-gray-700' : '' ?>">
                                Payments
                                <span class="float-right bg-green-500 text-xs px-2 py-1 rounded-full notification-badge"><?= isset($typeCounts['payment']) ? $typeCounts['payment']['count'] : 0 ?></span>
                            </a>
                            <a href="notifications.php?filter=membership" class="block px-4 py-2 text-sm text-white hover:bg-gray-700 <?= $filter === 'membership' ? 'bg-gray-700' : '' ?>">
                                Memberships
                                <span class="float-right bg-purple-500 text-xs px-2 py-1 rounded-full notification-badge"><?= isset($typeCounts['membership']) ? $typeCounts['membership']['count'] : 0 ?></span>
                            </a>
                            <a href="notifications.php?filter=system" class="block px-4 py-2 text-sm text-white hover:bg-gray-700 <?= $filter === 'system' ? 'bg-gray-700' : '' ?>">
                                System
                                <span class="float-right bg-red-500 text-xs px-2 py-1 rounded-full notification-badge"><?= isset($typeCounts['system']) ? $typeCounts['system']['count'] : 0 ?></span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Filter indicator -->
        <div class="mb-6">
            <div class="inline-flex items-center px-3 py-1 rounded-full text-sm bg-gray-800 text-gray-300">
            <span class="
                    <?php if ($filter === 'all'): ?>text-white<?php endif; ?>
                    <?php if ($filter === 'unread'): ?>text-yellow-400<?php endif; ?>
                    <?php if ($filter === 'read'): ?>text-gray-400<?php endif; ?>
                    <?php if ($filter === 'schedule'): ?>text-blue-400<?php endif; ?>
                    <?php if ($filter === 'payment'): ?>text-green-400<?php endif; ?>
                    <?php if ($filter === 'membership'): ?>text-purple-400<?php endif; ?>
                    <?php if ($filter === 'system'): ?>text-red-400<?php endif; ?>
                ">
                    <i class="fas fa-filter mr-1"></i>
                    Filter: 
                    <?php if ($filter === 'all'): ?>All Notifications
                    <?php elseif ($filter === 'unread'): ?>Unread
                    <?php elseif ($filter === 'read'): ?>Read
                    <?php elseif ($filter === 'schedule'): ?>Schedule
                    <?php elseif ($filter === 'payment'): ?>Payments
                    <?php elseif ($filter === 'membership'): ?>Memberships
                    <?php elseif ($filter === 'system'): ?>System
                    <?php endif; ?>
                </span>
                <a href="notifications.php" class="ml-2 text-yellow-500 hover:text-yellow-400 transition-colors duration-200">
                    <i class="fas fa-times"></i>
                </a>
            </div>
        </div>
        
        <?php if ($error_message): ?>
            <div class="bg-red-900 text-white p-4 rounded-lg mb-6">
                <p><i class="fas fa-exclamation-circle mr-2"></i> <?= htmlspecialchars($error_message) ?></p>
            </div>
        <?php endif; ?>
        
        <?php if (empty($notifications)): ?>
            <div class="bg-gray-800 rounded-lg p-8 text-center">
                <div class="flex flex-col items-center justify-center">
                    <i class="fas fa-bell-slash text-gray-500 text-5xl mb-4"></i>
                    <h3 class="text-xl font-semibold text-white mb-2">No notifications found</h3>
                    <p class="text-gray-400">
                        <?php if ($filter !== 'all'): ?>
                            Try changing your filter or check back later.
                        <?php else: ?>
                            You don't have any notifications yet. Check back later.
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        <?php else: ?>
            <div class="space-y-4">
                <?php foreach ($notifications as $notification): ?>
                    <div id="notification-<?= $notification['id'] ?>" class="notification-item bg-gray-800 rounded-lg overflow-hidden shadow-lg <?= $notification['is_read'] ? '' : 'border-l-4 border-yellow-500 animate-pulse-once' ?>">
                        <div class="p-4 sm:p-6">
                            <div class="flex flex-col sm:flex-row justify-between notification-header">
                                <div class="flex items-start">
                                    <div class="flex-shrink-0 mr-3">
                                        <?php if ($notification['type'] === 'schedule'): ?>
                                            <div class="w-10 h-10 rounded-full bg-blue-600 flex items-center justify-center">
                                                <i class="fas fa-calendar-check text-white"></i>
                                            </div>
                                        <?php elseif ($notification['type'] === 'payment'): ?>
                                            <div class="w-10 h-10 rounded-full bg-green-600 flex items-center justify-center">
                                                <i class="fas fa-credit-card text-white"></i>
                                            </div>
                                        <?php elseif ($notification['type'] === 'membership'): ?>
                                            <div class="w-10 h-10 rounded-full bg-purple-600 flex items-center justify-center">
                                                <i class="fas fa-id-card text-white"></i>
                                            </div>
                                        <?php elseif ($notification['type'] === 'system'): ?>
                                            <div class="w-10 h-10 rounded-full bg-red-600 flex items-center justify-center">
                                                <i class="fas fa-cogs text-white"></i>
                                            </div>
                                        <?php else: ?>
                                            <div class="w-10 h-10 rounded-full bg-gray-600 flex items-center justify-center">
                                                <i class="fas fa-bell text-white"></i>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <h3 class="text-lg font-semibold text-white">
                                            <?= htmlspecialchars($notification['title']) ?>
                                            <?php if (!$notification['is_read']): ?>
                                                <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-500 text-black">
                                                    New
                                                </span>
                                            <?php endif; ?>
                                        </h3>
                                        <p class="text-gray-300 mt-1"><?= nl2br(htmlspecialchars($notification['message'])) ?></p>
                                        
                                        <?php if ($notification['gym_name']): ?>
                                            <div class="mt-2">
                                                <a href="gym-profile.php?id=<?= $notification['gym_id'] ?>" class="text-yellow-400 hover:text-yellow-300 text-sm inline-flex items-center">
                                                    <i class="fas fa-dumbbell mr-1"></i>
                                                    <?= htmlspecialchars($notification['gym_name']) ?>
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="notification-timestamp text-sm text-gray-400 mt-2 sm:mt-0 sm:ml-4">
                                    <span title="<?= date('F j, Y g:i A', strtotime($notification['created_at'])) ?>">
                                        <?= time_elapsed_string($notification['created_at']) ?>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="mt-4 flex justify-end gap-2 notification-actions">
                                <?php if (!$notification['is_read']): ?>
                                    <form method="post" action="notifications.php?page=<?= $page ?>&filter=<?= $filter ?>" class="mark-read-form">
                                        <input type="hidden" name="notification_id" value="<?= $notification['id'] ?>">
                                        <input type="hidden" name="mark_read" value="1">
                                        <button type="submit" class="bg-gray-700 hover:bg-gray-600 text-white px-3 py-1 rounded text-sm transition-colors duration-200">
                                            <i class="fas fa-check mr-1"></i> Mark as Read
                                        </button>
                                    </form>
                                <?php endif; ?>
                                
                                <?php if ($notification['related_id'] && $notification['type'] === 'schedule'): ?>
                                    <a href="view-schedule.php?id=<?= $notification['related_id'] ?>" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded text-sm transition-colors duration-200">
                                        <i class="fas fa-calendar mr-1"></i> View Schedule
                                    </a>
                                <?php elseif ($notification['related_id'] && $notification['type'] === 'payment'): ?>
                                    <a href="payment-history.php?id=<?= $notification['related_id'] ?>" class="bg-green-600 hover:bg-green-700 text-white px-3 py-1 rounded text-sm transition-colors duration-200">
                                        <i class="fas fa-receipt mr-1"></i> View Payment
                                    </a>
                                <?php elseif ($notification['related_id'] && $notification['type'] === 'membership'): ?>
                                    <a href="membership-details.php?id=<?= $notification['related_id'] ?>" class="bg-purple-600 hover:bg-purple-700 text-white px-3 py-1 rounded text-sm transition-colors duration-200">
                                        <i class="fas fa-id-card mr-1"></i> View Membership
                                    </a>
                                <?php endif; ?>
                                
                                <form method="post" action="notifications.php?page=<?= $page ?>&filter=<?= $filter ?>" class="delete-notification-form">
                                    <input type="hidden" name="notification_id" value="<?= $notification['id'] ?>">
                                    <input type="hidden" name="delete_notification" value="1">
                                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-3 py-1 rounded text-sm transition-colors duration-200">
                                        <i class="fas fa-trash-alt mr-1"></i> Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="mt-8 flex justify-center">
                    <nav class="inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                        <?php if ($page > 1): ?>
                            <a href="notifications.php?page=<?= $page - 1 ?>&filter=<?= $filter ?>" class="pagination-item relative inline-flex items-center px-3 py-2 rounded-l-md border border-gray-700 bg-gray-800 text-sm font-medium text-gray-300 hover:bg-gray-700">
                                <i class="fas fa-chevron-left"></i>
                                <span class="sr-only">Previous</span>
                            </a>
                        <?php else: ?>
                            <span class="relative inline-flex items-center px-3 py-2 rounded-l-md border border-gray-700 bg-gray-900 text-sm font-medium text-gray-500 cursor-not-allowed">
                                <i class="fas fa-chevron-left"></i>
                                <span class="sr-only">Previous</span>
                            </span>
                        <?php endif; ?>
                        
                        <?php
                        // Calculate range of pages to show
                        $range = 2; // Show 2 pages before and after current page
                        $start_page = max(1, $page - $range);
                        $end_page = min($total_pages, $page + $range);
                        
                        // Always show first page
                        if ($start_page > 1) {
                            echo '<a href="notifications.php?page=1&filter=' . $filter . '" class="pagination-item relative inline-flex items-center px-4 py-2 border border-gray-700 bg-gray-800 text-sm font-medium text-gray-300 hover:bg-gray-700">1</a>';
                            if ($start_page > 2) {
                                echo '<span class="relative inline-flex items-center px-4 py-2 border border-gray-700 bg-gray-800 text-sm font-medium text-gray-300">...</span>';
                            }
                        }
                        
                        // Show page links
                        for ($i = $start_page; $i <= $end_page; $i++) {
                            if ($i == $page) {
                                echo '<span class="pagination-item active relative inline-flex items-center px-4 py-2 border border-yellow-500 bg-yellow-500 text-sm font-medium text-black">' . $i . '</span>';
                            } else {
                                echo '<a href="notifications.php?page=' . $i . '&filter=' . $filter . '" class="pagination-item relative inline-flex items-center px-4 py-2 border border-gray-700 bg-gray-800 text-sm font-medium text-gray-300 hover:bg-gray-700">' . $i . '</a>';
                            }
                        }
                        
                        // Always show last page
                        if ($end_page < $total_pages) {
                            if ($end_page < $total_pages - 1) {
                                echo '<span class="relative inline-flex items-center px-4 py-2 border border-gray-700 bg-gray-800 text-sm font-medium text-gray-300">...</span>';
                            }
                            echo '<a href="notifications.php?page=' . $total_pages . '&filter=' . $filter . '" class="pagination-item relative inline-flex items-center px-4 py-2 border border-gray-700 bg-gray-800 text-sm font-medium text-gray-300 hover:bg-gray-700">' . $total_pages . '</a>';
                        }
                        ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <a href="notifications.php?page=<?= $page + 1 ?>&filter=<?= $filter ?>" class="pagination-item relative inline-flex items-center px-3 py-2 rounded-r-md border border-gray-700 bg-gray-800 text-sm font-medium text-gray-300 hover:bg-gray-700">
                                <i class="fas fa-chevron-right"></i>
                                <span class="sr-only">Next</span>
                            </a>
                        <?php else: ?>
                            <span class="relative inline-flex items-center px-3 py-2 rounded-r-md border border-gray-700 bg-gray-900 text-sm font-medium text-gray-500 cursor-not-allowed">
                                <i class="fas fa-chevron-right"></i>
                                <span class="sr-only">Next</span>
                            </span>
                        <?php endif; ?>
                    </nav>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script>
    // Filter dropdown toggle
    const filterDropdown = document.getElementById('filterDropdown');
    const filterMenu = document.getElementById('filterMenu');
    const chevronIcon = filterDropdown.querySelector('.fa-chevron-down');
    
    filterDropdown.addEventListener('click', function() {
        filterMenu.classList.toggle('show');
        chevronIcon.style.transform = filterMenu.classList.contains('show') ? 'rotate(180deg)' : 'rotate(0)';
    });
    
    // Close dropdown when clicking outside
    document.addEventListener('click', function(event) {
        if (!filterDropdown.contains(event.target) && !filterMenu.contains(event.target)) {
            filterMenu.classList.remove('show');
            chevronIcon.style.transform = 'rotate(0)';
        }
    });
    
    // Smooth animations for form submissions
    document.querySelectorAll('.mark-read-form').forEach(form => {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            const notificationId = this.querySelector('input[name="notification_id"]').value;
            const notificationElement = document.getElementById('notification-' + notificationId);
            
            // Remove the yellow border and add a transition effect
            notificationElement.classList.remove('border-l-4', 'border-yellow-500');
            notificationElement.style.borderLeftWidth = '0';
            
            // Remove the "New" badge
            const newBadge = notificationElement.querySelector('.bg-yellow-500.text-black');
            if (newBadge) {
                newBadge.remove();
            }
            
            // Remove the "Mark as Read" button
            const markReadButton = this.querySelector('button');
            markReadButton.innerHTML = '<i class="fas fa-check mr-1"></i> Marked as Read';
            markReadButton.classList.add('bg-gray-600');
            markReadButton.disabled = true;
            
            // Submit the form after animation
            setTimeout(() => {
                this.submit();
            }, 500);
        });
    });
    
    // Animation for delete notification
    document.querySelectorAll('.delete-notification-form').forEach(form => {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            const notificationId = this.querySelector('input[name="notification_id"]').value;
            const notificationElement = document.getElementById('notification-' + notificationId);
            
            // Add fade out animation
            notificationElement.classList.add('fade-out');
            
            // Submit the form after animation
            setTimeout(() => {
                this.submit();
            }, 500);
        });
    });
    
    // Lazy load images for better performance
    document.addEventListener('DOMContentLoaded', function() {
        const lazyImages = [].slice.call(document.querySelectorAll('img.lazy'));
        
        if ('IntersectionObserver' in window) {
            let lazyImageObserver = new IntersectionObserver(function(entries, observer) {
                entries.forEach(function(entry) {
                    if (entry.isIntersecting) {
                        let lazyImage = entry.target;
                        lazyImage.src = lazyImage.dataset.src;
                        lazyImage.classList.remove('lazy');
                        lazyImageObserver.unobserve(lazyImage);
                    }
                });
            });
            
            lazyImages.forEach(function(lazyImage) {
                lazyImageObserver.observe(lazyImage);
            });
        }
    });
    
    // Responsive design adjustments
    function adjustForMobile() {
        const isMobile = window.innerWidth < 640;
        const actionButtons = document.querySelectorAll('.notification-actions button, .notification-actions a');
        
        actionButtons.forEach(button => {
            // Adjust button text on mobile
            if (isMobile) {
                // Store original text in data attribute if not already stored
                if (!button.dataset.originalText) {
                    button.dataset.originalText = button.innerHTML;
                    
                    // Show only icon on mobile
                    const icon = button.querySelector('i');
                    if (icon) {
                        button.innerHTML = icon.outerHTML;
                    }
                }
            } else {
                // Restore original text on desktop
                if (button.dataset.originalText) {
                    button.innerHTML = button.dataset.originalText;
                }
            }
        });
    }
    
    // Call on load and resize
    window.addEventListener('load', adjustForMobile);
    window.addEventListener('resize', adjustForMobile);
    
    // Real-time notification updates
    let lastChecked = new Date().getTime();
    
    function checkForNewNotifications() {
        fetch('api/check_notifications.php?since=' + lastChecked)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.new_notifications > 0) {
                    // Show notification indicator
                    const indicator = document.createElement('div');
                    indicator.className = 'fixed bottom-4 right-4 bg-yellow-500 text-black px-4 py-2 rounded-lg shadow-lg z-50 animate-pulse-once';
                    indicator.innerHTML = `
                        <div class="flex items-center">
                            <i class="fas fa-bell mr-2"></i>
                            <span>You have ${data.new_notifications} new notification${data.new_notifications > 1 ? 's' : ''}!</span>
                            <button class="ml-3 text-black hover:text-gray-800" onclick="refreshPage()">
                                <i class="fas fa-sync-alt"></i> Refresh
                            </button>
                        </div>
                    `;
                    document.body.appendChild(indicator);
                    
                    // Auto-remove after 10 seconds
                    setTimeout(() => {
                        indicator.classList.add('fade-out');
                        setTimeout(() => {
                            indicator.remove();
                        }, 500);
                    }, 10000);
                }
                
                lastChecked = new Date().getTime();
            })
            .catch(error => console.error('Error checking for notifications:', error));
    }
    
    function refreshPage() {
        window.location.reload();
    }
    
    // Check for new notifications every 60 seconds
    setInterval(checkForNewNotifications, 60000);
</script>

<?php
// Helper function to format time elapsed string
function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime('now', new DateTimeZone(date_default_timezone_get()));
    $ago = new DateTime($datetime, new DateTimeZone(date_default_timezone_get()));
    $diff = $now->diff($ago);

    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;

    $string = array(
        'y' => 'year',
        'm' => 'month',
        'w' => 'week',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'minute',
        's' => 'second',
    );
    
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' ago' : 'just now';
}

?>

</body>
</html>