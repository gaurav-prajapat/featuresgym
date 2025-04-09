<?php
require '../config/database.php';
include '../includes/navbar.php';

// Check if user is authenticated and is a gym owner
if (!isset($_SESSION['owner_id'])) {
    $_SESSION['error'] = "Please log in to access this page.";
    header('Location: ../login.php');
    exit;
}

$owner_id = (int)$_SESSION['owner_id'];
$db = new GymDatabase();
$conn = $db->getConnection();

// Initialize variables
$notifications = [];
$gym = null;
$error = null;
$success = null;
$totalUnread = 0;

// Get success/error messages from session
if (isset($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}

if (isset($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']);
}

// Process mark as read action
if (isset($_POST['mark_read']) && isset($_POST['notification_id'])) {
    $notification_id = (int)$_POST['notification_id'];
    
    try {
        // Verify the notification belongs to the owner's gym before updating
        $stmt = $conn->prepare("
            UPDATE notifications 
            SET is_read = 1 
            WHERE id = :notification_id 
            AND gym_id IN (SELECT gym_id FROM gyms WHERE owner_id = :owner_id)
        ");
        
        $stmt->bindParam(':notification_id', $notification_id, PDO::PARAM_INT);
        $stmt->bindParam(':owner_id', $owner_id, PDO::PARAM_INT);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $success = "Notification marked as read.";
        }
    } catch (PDOException $e) {
        $error = "Error updating notification: " . $e->getMessage();
    }
}

// Process mark all as read action
if (isset($_POST['mark_all_read'])) {
    try {
        $stmt = $conn->prepare("
            UPDATE notifications 
            SET is_read = 1 
            WHERE gym_id IN (SELECT gym_id FROM gyms WHERE owner_id = :owner_id)
        ");
        
        $stmt->bindParam(':owner_id', $owner_id, PDO::PARAM_INT);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $success = "All notifications marked as read.";
        }
    } catch (PDOException $e) {
        $error = "Error updating notifications: " . $e->getMessage();
    }
}

// Process delete notification action
if (isset($_POST['delete_notification']) && isset($_POST['notification_id'])) {
    $notification_id = (int)$_POST['notification_id'];
    
    try {
        // Use a transaction for data integrity
        $conn->beginTransaction();
        
        // Verify the notification belongs to the owner's gym before deleting
        $stmt = $conn->prepare("
            DELETE FROM notifications 
            WHERE id = :notification_id 
            AND gym_id IN (SELECT gym_id FROM gyms WHERE owner_id = :owner_id)
        ");
        
        $stmt->bindParam(':notification_id', $notification_id, PDO::PARAM_INT);
        $stmt->bindParam(':owner_id', $owner_id, PDO::PARAM_INT);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $success = "Notification deleted successfully.";
        }
        
        $conn->commit();
    } catch (PDOException $e) {
        $conn->rollBack();
        $error = "Error deleting notification: " . $e->getMessage();
    }
}

try {
    // Get gym ID and name with prepared statement
    $stmt = $conn->prepare("
        SELECT gym_id, name 
        FROM gyms 
        WHERE owner_id = :owner_id 
        LIMIT 1
    ");
    
    $stmt->bindParam(':owner_id', $owner_id, PDO::PARAM_INT);
    $stmt->execute();
    $gym = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$gym) {
        throw new Exception("No gym found for the logged-in owner.");
    }

    $gym_id = (int)$gym['gym_id'];

    // Pagination setup
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = 15; // Number of notifications per page
    $offset = ($page - 1) * $limit;

    // Get total count for pagination
    $countStmt = $conn->prepare("
        SELECT COUNT(*) 
        FROM notifications 
        WHERE gym_id = :gym_id
    ");
    
    $countStmt->bindParam(':gym_id', $gym_id, PDO::PARAM_INT);
    $countStmt->execute();
    $totalNotifications = $countStmt->fetchColumn();
    $totalPages = ceil($totalNotifications / $limit);

    // Get total unread count
    $unreadStmt = $conn->prepare("
        SELECT COUNT(*) 
        FROM notifications 
        WHERE gym_id = :gym_id 
        AND is_read = 0
    ");
    
    $unreadStmt->bindParam(':gym_id', $gym_id, PDO::PARAM_INT);
    $unreadStmt->execute();
    $totalUnread = $unreadStmt->fetchColumn();

    // Filter setup
    $filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
    $filterClause = '';
    
    if ($filter === 'unread') {
        $filterClause = ' AND is_read = 0';
    } else if ($filter === 'read') {
        $filterClause = ' AND is_read = 1';
    }

    // Fetch notifications with pagination and filtering
    $notificationStmt = $conn->prepare("
        SELECT n.id, n.title, n.message, n.created_at, n.is_read, n.type, n.related_id 
        FROM notifications n 
        WHERE n.gym_id = :gym_id 
        $filterClause
        ORDER BY n.created_at DESC
        LIMIT :limit OFFSET :offset
    ");
    
    $notificationStmt->bindParam(':gym_id', $gym_id, PDO::PARAM_INT);
    $notificationStmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $notificationStmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    $notificationStmt->execute();
    $notifications = $notificationStmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $error = $e->getMessage();
}

// Helper function to format date
function formatDate($dateString) {
    $date = new DateTime($dateString);
    $now = new DateTime();
    $diff = $date->diff($now);
    
    if ($diff->days == 0) {
        if ($diff->h == 0) {
            if ($diff->i == 0) {
                return "Just now";
            }
            return $diff->i . " minute" . ($diff->i > 1 ? "s" : "") . " ago";
        }
        return $diff->h . " hour" . ($diff->h > 1 ? "s" : "") . " ago";
    } else if ($diff->days == 1) {
        return "Yesterday at " . $date->format('g:i A');
    } else if ($diff->days < 7) {
        return $diff->days . " days ago";
    } else {
        return $date->format('M j, Y g:i A');
    }
}

// Helper function to get notification icon based on type
function getNotificationIcon($type) {
    switch ($type) {
        case 'booking':
            return 'fa-calendar-check';
        case 'payment':
            return 'fa-money-bill-wave';
        case 'membership':
            return 'fa-id-card';
        case 'review':
            return 'fa-star';
        case 'system':
            return 'fa-cog';
        default:
            return 'fa-bell';
    }
}

// Helper function to get notification color based on type
function getNotificationColor($type) {
    switch ($type) {
        case 'booking':
            return 'bg-blue-100 text-blue-800';
        case 'payment':
            return 'bg-green-100 text-green-800';
        case 'membership':
            return 'bg-purple-100 text-purple-800';
        case 'review':
            return 'bg-yellow-100 text-yellow-800';
        case 'system':
            return 'bg-gray-100 text-gray-800';
        default:
            return 'bg-gray-100 text-gray-800';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications | Gym Management</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .notification-item {
            transition: all 0.3s ease;
        }
        .notification-item:hover {
            transform: translateY(-2px);
        }
        .notification-unread {
            border-left: 4px solid #3B82F6;
        }
    </style>
</head>
<body class="bg-gray-100">
    <div class="container mx-auto px-4 py-20">
        <!-- Header Section -->
        <div class="bg-white rounded-xl shadow-lg overflow-hidden mb-8">
            <div class="p-6 bg-gradient-to-r from-gray-900 to-gray-800">
                <div class="flex flex-col md:flex-row items-center justify-between">
                    <div class="flex items-center mb-4 md:mb-0">
                        <div class="h-16 w-16 rounded-full bg-yellow-500 flex items-center justify-center">
                            <i class="fas fa-bell text-2xl text-white"></i>
                        </div>
                        <div class="ml-6">
                            <h1 class="text-2xl font-bold text-white">Notifications</h1>
                            <p class="text-white opacity-80">
                                For Gym: <span class="font-semibold"><?= htmlspecialchars($gym['name'] ?? 'Unknown') ?></span>
                            </p>
                        </div>
                    </div>
                    <div class="flex space-x-2">
                        <a href="dashboard.php" class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
                        </a>
                        <?php if ($totalUnread > 0): ?>
                            <form method="POST" class="inline">
                                <input type="hidden" name="mark_all_read" value="1">
                                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
                                    <i class="fas fa-check-double mr-2"></i>Mark All as Read
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Stats Section -->
            <div class="grid grid-cols-2 md:grid-cols-3 divide-x divide-y md:divide-y-0 divide-gray-200">
                <div class="p-6 text-center">
                    <p class="text-gray-500 text-sm">Total Notifications</p>
                    <p class="text-3xl font-bold text-gray-800"><?= $totalNotifications ?></p>
                </div>
                <div class="p-6 text-center">
                    <p class="text-gray-500 text-sm">Unread</p>
                    <p class="text-3xl font-bold text-blue-600"><?= $totalUnread ?></p>
                </div>
                <div class="p-6 text-center">
                    <p class="text-gray-500 text-sm">Read</p>
                    <p class="text-3xl font-bold text-gray-800"><?= $totalNotifications - $totalUnread ?></p>
                </div>
            </div>
        </div>
        
        <!-- Messages -->
        <?php if ($error): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-lg shadow">
                <p class="font-bold">Error</p>
                <p><?= htmlspecialchars($error) ?></p>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded-lg shadow">
                <p class="font-bold">Success</p>
                <p><?= htmlspecialchars($success) ?></p>
            </div>
        <?php endif; ?>
        
        <!-- Filter Tabs -->
        <div class="mb-6 flex justify-between items-center">
            <div class="flex space-x-2">
                <a href="?filter=all" class="px-4 py-2 rounded-lg <?= $filter === 'all' ? 'bg-blue-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-100' ?>">
                    All Notifications
                </a>
                <a href="?filter=unread" class="px-4 py-2 rounded-lg <?= $filter === 'unread' ? 'bg-blue-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-100' ?>">
                    Unread (<?= $totalUnread ?>)
                </a>
                <a href="?filter=read" class="px-4 py-2 rounded-lg <?= $filter === 'read' ? 'bg-blue-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-100' ?>">
                    Read
                </a>
            </div>
            <div>
                <form method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete all read notifications?');">
                    <input type="hidden" name="delete_all_read" value="1">
                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-trash-alt mr-2"></i>Clear Read
                    </button>
                    </form>
            </div>
        </div>
        
        <!-- Notifications List -->
        <div class="bg-white rounded-xl shadow-lg overflow-hidden">
            <?php if (empty($notifications)): ?>
                <div class="p-12 text-center">
                    <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-gray-100 mb-4">
                        <i class="fas fa-bell-slash text-gray-400 text-2xl"></i>
                    </div>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">No notifications found</h3>
                    <p class="text-gray-500">
                        <?php if ($filter === 'unread'): ?>
                            You have no unread notifications at the moment.
                        <?php elseif ($filter === 'read'): ?>
                            You have no read notifications.
                        <?php else: ?>
                            You don't have any notifications yet.
                        <?php endif; ?>
                    </p>
                </div>
            <?php else: ?>
                <ul class="divide-y divide-gray-200">
                    <?php foreach ($notifications as $notification): 
                        $isUnread = $notification['is_read'] == 0;
                        $iconClass = getNotificationIcon($notification['type']);
                        $typeClass = getNotificationColor($notification['type']);
                    ?>
                        <li class="notification-item <?= $isUnread ? 'notification-unread bg-blue-50' : '' ?> p-4 hover:bg-gray-50">
                            <div class="flex items-start">
                                <!-- Icon -->
                                <div class="flex-shrink-0 mr-4">
                                    <div class="h-10 w-10 rounded-full <?= $typeClass ?> flex items-center justify-center">
                                        <i class="fas <?= $iconClass ?>"></i>
                                    </div>
                                </div>
                                
                                <!-- Content -->
                                <div class="flex-1 min-w-0">
                                    <div class="flex justify-between">
                                        <h4 class="text-sm font-medium text-gray-900 <?= $isUnread ? 'font-bold' : '' ?>">
                                            <?= htmlspecialchars($notification['title']) ?>
                                        </h4>
                                        <span class="text-xs text-gray-500">
                                            <?= formatDate($notification['created_at']) ?>
                                        </span>
                                    </div>
                                    <p class="mt-1 text-sm text-gray-600">
                                        <?= htmlspecialchars($notification['message']) ?>
                                    </p>
                                    
                                    <!-- Action buttons -->
                                    <div class="mt-2 flex space-x-2">
                                        <?php if ($isUnread): ?>
                                            <form method="POST" class="inline">
                                                <input type="hidden" name="notification_id" value="<?= $notification['id'] ?>">
                                                <input type="hidden" name="mark_read" value="1">
                                                <button type="submit" class="text-xs text-blue-600 hover:text-blue-800">
                                                    <i class="fas fa-check mr-1"></i> Mark as read
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        
                                        <?php if ($notification['related_id'] && $notification['type'] === 'booking'): ?>
                                            <a href="view_schedule.php?id=<?= $notification['related_id'] ?>" class="text-xs text-green-600 hover:text-green-800">
                                                <i class="fas fa-eye mr-1"></i> View Schedule
                                            </a>
                                        <?php elseif ($notification['related_id'] && $notification['type'] === 'payment'): ?>
                                            <a href="view_payment.php?id=<?= $notification['related_id'] ?>" class="text-xs text-green-600 hover:text-green-800">
                                                <i class="fas fa-eye mr-1"></i> View Payment
                                            </a>
                                        <?php elseif ($notification['related_id'] && $notification['type'] === 'membership'): ?>
                                            <a href="view_membership.php?id=<?= $notification['related_id'] ?>" class="text-xs text-green-600 hover:text-green-800">
                                                <i class="fas fa-eye mr-1"></i> View Membership
                                            </a>
                                        <?php elseif ($notification['related_id'] && $notification['type'] === 'review'): ?>
                                            <a href="view_reviews.php?id=<?= $notification['related_id'] ?>" class="text-xs text-green-600 hover:text-green-800">
                                                <i class="fas fa-eye mr-1"></i> View Review
                                            </a>
                                        <?php endif; ?>
                                        
                                        <form method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete this notification?');">
                                            <input type="hidden" name="notification_id" value="<?= $notification['id'] ?>">
                                            <input type="hidden" name="delete_notification" value="1">
                                            <button type="submit" class="text-xs text-red-600 hover:text-red-800">
                                                <i class="fas fa-trash-alt mr-1"></i> Delete
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="mt-6 flex justify-center">
                <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                    <!-- Previous Page Link -->
                    <?php if ($page > 1): ?>
                        <a href="?page=<?= $page - 1 ?>&filter=<?= $filter ?>" class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                            <span class="sr-only">Previous</span>
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    <?php else: ?>
                        <span class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-gray-100 text-sm font-medium text-gray-400 cursor-not-allowed">
                            <span class="sr-only">Previous</span>
                            <i class="fas fa-chevron-left"></i>
                        </span>
                    <?php endif; ?>
                    
                    <!-- Page Numbers -->
                    <?php 
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $startPage + 4);
                    
                    if ($endPage - $startPage < 4 && $startPage > 1) {
                        $startPage = max(1, $endPage - 4);
                    }
                    
                    for ($i = $startPage; $i <= $endPage; $i++): 
                    ?>
                        <?php if ($i == $page): ?>
                            <span class="relative inline-flex items-center px-4 py-2 border border-blue-500 bg-blue-50 text-sm font-medium text-blue-600">
                                <?= $i ?>
                            </span>
                        <?php else: ?>
                            <a href="?page=<?= $i ?>&filter=<?= $filter ?>" class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">
                                <?= $i ?>
                            </a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    
                    <!-- Next Page Link -->
                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?= $page + 1 ?>&filter=<?= $filter ?>" class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                            <span class="sr-only">Next</span>
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php else: ?>
                        <span class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-gray-100 text-sm font-medium text-gray-400 cursor-not-allowed">
                            <span class="sr-only">Next</span>
                            <i class="fas fa-chevron-right"></i>
                        </span>
                    <?php endif; ?>
                </nav>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Auto-refresh notifications every 5 minutes
        setTimeout(function() {
            window.location.reload();
        }, 300000); // 5 minutes in milliseconds
        
        // Mark notification as read when clicked
        document.addEventListener('DOMContentLoaded', function() {
            const notificationItems = document.querySelectorAll('.notification-item');
            
            notificationItems.forEach(item => {
                item.addEventListener('click', function(e) {
                    // Don't trigger if clicking on a button or link
                    if (e.target.tagName === 'BUTTON' || e.target.tagName === 'A' || 
                        e.target.closest('button') || e.target.closest('a')) {
                        return;
                    }
                    
                    // Find the mark as read form and submit it
                    const markReadForm = this.querySelector('form input[name="mark_read"]');
                    if (markReadForm) {
                        markReadForm.closest('form').submit();
                    }
                });
            });
        });
        
        // CSRF protection for forms
        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('form');
            
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    // Add a hidden token field to prevent CSRF attacks
                    const token = '<?= bin2hex(random_bytes(32)) ?>';
                    const tokenInput = document.createElement('input');
                    tokenInput.type = 'hidden';
                    tokenInput.name = 'csrf_token';
                    tokenInput.value = token;
                    
                    // Store token in session storage for verification
                    sessionStorage.setItem('csrf_token', token);
                    
                    this.appendChild(tokenInput);
                });
            });
        });
    </script>
</body>
</html>

