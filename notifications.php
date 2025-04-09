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

<div class="min-h-screen bg-gradient-to-b from-gray-900 to-black pt-24 pb-12 sm:py-16 lg:py-20">
    <div class="container mx-auto px-4 sm:px-6 lg:px-8 max-w-5xl">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-3xl font-bold text-white">Notifications</h1>
            
            <div class="flex space-x-2">
                <?php if ($unread_count > 0): ?>
                <form method="post" action="notifications.php?page=<?= $page ?>&filter=<?= $filter ?>">
                    <input type="hidden" name="mark_all_read" value="1">
                    <button type="submit" class="bg-yellow-500 hover:bg-yellow-600 text-black px-4 py-2 rounded-lg transition-colors duration-200 font-medium text-sm">
                        Mark All as Read
                    </button>
                </form>
                <?php endif; ?>
                
                <div class="relative">
                    <button id="filterDropdown" class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition-colors duration-200 font-medium text-sm flex items-center">
                        <i class="fas fa-filter mr-2"></i> Filter
                        <i class="fas fa-chevron-down ml-2"></i>
                    </button>
                    <div id="filterMenu" class="absolute right-0 mt-2 w-48 bg-gray-800 rounded-lg shadow-lg z-10 hidden">
                        <div class="py-1">
                            <a href="notifications.php?filter=all" class="block px-4 py-2 text-sm text-white hover:bg-gray-700 <?= $filter === 'all' ? 'bg-gray-700' : '' ?>">
                                All Notifications
                                <span class="float-right bg-gray-600 text-xs px-2 py-1 rounded-full"><?= $total_notifications ?></span>
                            </a>
                            <a href="notifications.php?filter=unread" class="block px-4 py-2 text-sm text-white hover:bg-gray-700 <?= $filter === 'unread' ? 'bg-gray-700' : '' ?>">
                                Unread
                                <span class="float-right bg-yellow-500 text-black text-xs px-2 py-1 rounded-full"><?= $unread_count ?></span>
                            </a>
                            <a href="notifications.php?filter=read" class="block px-4 py-2 text-sm text-white hover:bg-gray-700 <?= $filter === 'read' ? 'bg-gray-700' : '' ?>">
                                Read
                                <span class="float-right bg-gray-600 text-xs px-2 py-1 rounded-full"><?= $total_notifications - $unread_count ?></span>
                            </a>
                            <div class="border-t border-gray-700 my-1"></div>
                            <a href="notifications.php?filter=schedule" class="block px-4 py-2 text-sm text-white hover:bg-gray-700 <?= $filter === 'schedule' ? 'bg-gray-700' : '' ?>">
                                Schedule
                                <span class="float-right bg-blue-500 text-xs px-2 py-1 rounded-full"><?= isset($typeCounts['schedule']) ? $typeCounts['schedule']['count'] : 0 ?></span>
                            </a>
                            <a href="notifications.php?filter=payment" class="block px-4 py-2 text-sm text-white hover:bg-gray-700 <?= $filter === 'payment' ? 'bg-gray-700' : '' ?>">
                                Payments
                                <span class="float-right bg-green-500 text-xs px-2 py-1 rounded-full"><?= isset($typeCounts['payment']) ? $typeCounts['payment']['count'] : 0 ?></span>
                            </a>
                            <a href="notifications.php?filter=membership" class="block px-4 py-2 text-sm text-white hover:bg-gray-700 <?= $filter === 'membership' ? 'bg-gray-700' : '' ?>">
                                Memberships
                                <span class="float-right bg-purple-500 text-xs px-2 py-1 rounded-full"><?= isset($typeCounts['membership']) ? $typeCounts['membership']['count'] : 0 ?></span>
                            </a>
                            <a href="notifications.php?filter=system" class="block px-4 py-2 text-sm text-white hover:bg-gray-700 <?= $filter === 'system' ? 'bg-gray-700' : '' ?>">
                                System
                                <span class="float-right bg-red-500 text-xs px-2 py-1 rounded-full"><?= isset($typeCounts['system']) ? $typeCounts['system']['count'] : 0 ?></span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if (!empty($error_message)): ?>
            <div class="bg-red-500 bg-opacity-80 text-white p-4 rounded-xl mb-6 animate-pulse">
                An error occurred. Please try again later.
            </div>
        <?php endif; ?>

        <?php if (count($notifications) > 0): ?>
            <div class="space-y-4">
                <?php foreach ($notifications as $notification): 
                    // Determine notification type styling
                    $typeIcon = 'bell';
                    $typeColor = 'from-gray-700 to-gray-800';
                    $typeBorder = 'border-gray-600';
                    
                    if ($notification['type'] === 'schedule') {
                        $typeIcon = 'calendar-check';
                        $typeColor = 'from-blue-700 to-blue-800';
                        $typeBorder = 'border-blue-600';
                    } elseif ($notification['type'] === 'payment') {
                        $typeIcon = 'credit-card';
                        $typeColor = 'from-green-700 to-green-800';
                        $typeBorder = 'border-green-600';
                    } elseif ($notification['type'] === 'membership') {
                        $typeIcon = 'id-card';
                        $typeColor = 'from-purple-700 to-purple-800';
                        $typeBorder = 'border-purple-600';
                    } elseif ($notification['type'] === 'system') {
                        $typeIcon = 'exclamation-circle';
                        $typeColor = 'from-red-700 to-red-800';
                        $typeBorder = 'border-red-600';
                    }
                    
                    // Override for unread notifications
                    if (!$notification['is_read']) {
                        $typeColor = 'from-yellow-400 to-yellow-500';
                        $typeBorder = 'border-yellow-400';
                    }
                ?>
                    <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl overflow-hidden transform hover:scale-[1.01] transition-transform duration-300 border-l-4 <?= $notification['is_read'] ? $typeBorder : 'border-yellow-400' ?>">
                        <div class="p-5 bg-gradient-to-r <?= $typeColor ?> flex justify-between items-center">
                            <div class="flex items-center">
                                <i class="fas fa-<?= $typeIcon ?> mr-3 <?= $notification['is_read'] ? 'text-white' : 'text-gray-900' ?>"></i>
                                <h3 class="text-xl font-bold <?= $notification['is_read'] ? 'text-white' : 'text-gray-900' ?>">
                                    <?= htmlspecialchars($notification['title']) ?>
                                </h3>
                            </div>
                            
                            <div class="flex space-x-2">
                                <?php if (!$notification['is_read']): ?>
                                <form method="post" action="notifications.php?page=<?= $page ?>&filter=<?= $filter ?>">
                                    <input type="hidden" name="notification_id" value="<?= $notification['id'] ?>">
                                    <button type="submit" name="mark_read" class="text-sm bg-black bg-opacity-20 hover:bg-opacity-30 px-3 py-1 rounded-full transition-colors duration-200">
                                        Mark as Read
                                    </button>
                                </form>
                                <?php endif; ?>
                                
                                <form method="post" action="notifications.php?page=<?= $page ?>&filter=<?= $filter ?>" onsubmit="return confirm('Are you sure you want to delete this notification?')">
                                    <input type="hidden" name="notification_id" value="<?= $notification['id'] ?>">
                                    <button type="submit" name="delete_notification" class="text-sm bg-black bg-opacity-20 hover:bg-opacity-30 px-3 py-1 rounded-full transition-colors duration-200">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                        
                        <div class="p-5">
                            <div class="flex justify-between items-start mb-4">
                                <div>
                                    <?php if ($notification['gym_id']): ?>
                                    <a href="gym-profile.php?id=<?= $notification['gym_id'] ?>" class="text-blue-400 hover:text-blue-300 text-sm">
                                        <i class="fas fa-dumbbell mr-1"></i> <?= htmlspecialchars($notification['gym_name']) ?>
                                    </a>
                                    <?php endif; ?>
                                </div>
                                <div class="text-gray-400 text-sm">
                                    <i class="far fa-clock mr-1"></i> <?= date('M d, Y h:i A', strtotime($notification['created_at'])) ?>
                                </div>
                            </div>
                            
                            <p class="text-gray-300 mb-4">
                                <?= htmlspecialchars($notification['message']) ?>
                            </p>
                            
                            <div class="flex justify-end mt-2">
                                <?php if ($notification['type'] === 'schedule' && $notification['related_id']): ?>
                                <a href="schedule-history.php?id=<?= $notification['related_id'] ?>" class="text-sm bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                                    View Schedule
                                </a>
                                <?php elseif ($notification['type'] === 'payment' && $notification['related_id']): ?>
                                <a href="payment_history.php?id=<?= $notification['related_id'] ?>" class="text-sm bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                                    View Payment
                                </a>
                                <?php elseif ($notification['type'] === 'membership' && $notification['related_id']): ?>
                                <a href="view_membership.php?id=<?= $notification['related_id'] ?>" class="text-sm bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                                    View Membership
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="flex justify-center mt-8">
                    <div class="inline-flex rounded-md shadow-sm">
                        <?php if ($page > 1): ?>
                        <a href="notifications.php?page=1&filter=<?= $filter ?>" class="px-4 py-2 text-sm font-medium text-white bg-gray-700 rounded-l-lg hover:bg-gray-600 transition-colors duration-200">
                            <i class="fas fa-angle-double-left"></i>
                        </a>
                        <a href="notifications.php?page=<?= $page - 1 ?>&filter=<?= $filter ?>" class="px-4 py-2 text-sm font-medium text-white bg-gray-700 hover:bg-gray-600 transition-colors duration-200">
                            <i class="fas fa-angle-left"></i>
                        </a>
                        <?php endif; ?>
                        
                        <?php
                        // Show a limited number of page links
                        $startPage = max(1, $page - 2);
                        $endPage = min($total_pages, $page + 2);
                        
                        for ($i = $startPage; $i <= $endPage; $i++):
                        ?>
                            <a href="notifications.php?page=<?= $i ?>&filter=<?= $filter ?>" class="px-4 py-2 text-sm font-medium <?= $i === $page ? 'text-black bg-yellow-500' : 'text-white bg-gray-700 hover:bg-gray-600' ?> transition-colors duration-200">
                                <?= $i ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                        <a href="notifications.php?page=<?= $page + 1 ?>&filter=<?= $filter ?>" class="px-4 py-2 text-sm font-medium text-white bg-gray-700 hover:bg-gray-600 transition-colors duration-200">
                            <i class="fas fa-angle-right"></i>
                        </a>
                        <a href="notifications.php?page=<?= $total_pages ?>&filter=<?= $filter ?>" class="px-4 py-2 text-sm font-medium text-white bg-gray-700 rounded-r-lg hover:bg-gray-600 transition-colors duration-200">
                            <i class="fas fa-angle-double-right"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                
            </div>
        <?php else: ?>
            <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl p-8 text-center">
                <div class="text-gray-400 mb-4">
                    <i class="fas fa-bell-slash text-5xl"></i>
                </div>
                <h3 class="text-xl font-bold text-white mb-2">No Notifications</h3>
                <p class="text-gray-400">
                    <?php if ($filter !== 'all'): ?>
                        No <?= $filter ?> notifications found. Try changing your filter.
                    <?php else: ?>
                        You don't have any notifications yet. They will appear here when you receive them.
                    <?php endif; ?>
                </p>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    // Filter dropdown functionality
    const filterDropdown = document.getElementById('filterDropdown');
    const filterMenu = document.getElementById('filterMenu');
    
    if (filterDropdown && filterMenu) {
        filterDropdown.addEventListener('click', function() {
            filterMenu.classList.toggle('hidden');
        });
        
        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            if (!filterDropdown.contains(event.target) && !filterMenu.contains(event.target)) {
                filterMenu.classList.add('hidden');
            }
        });
    }
    
    // Auto-hide notifications after marking as read
    document.querySelectorAll('form[name="mark_read"]').forEach(form => {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            
            fetch('notifications.php?page=<?= $page ?>&filter=<?= $filter ?>', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (response.ok) {
                    // Find the parent notification element and add a fade-out class
                    const notificationElement = this.closest('.notification-item');
                    if (notificationElement) {
                        notificationElement.classList.add('opacity-50', 'transition-opacity', 'duration-500');
                        setTimeout(() => {
                            window.location.reload();
                        }, 500);
                    } else {
                        window.location.reload();
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                window.location.reload();
            });
        });
    });
    
    // Animate new notifications
    document.addEventListener('DOMContentLoaded', function() {
        const unreadNotifications = document.querySelectorAll('.border-yellow-400');
        unreadNotifications.forEach((notification, index) => {
            setTimeout(() => {
                notification.classList.add('animate-pulse-once');
            }, index * 200); // Stagger the animations
        });
    });
</script>

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
</style>
<?php include('includes/footer.php'); ?>
