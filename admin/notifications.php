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

// Initialize variables
$message = '';
$error = '';
$notifications = [];
$recipients = [];

// Check if notifications table exists, if not create it
try {
    $tableCheckQuery = "SHOW TABLES LIKE 'admin_notifications'";
    $tableCheckStmt = $conn->prepare($tableCheckQuery);
    $tableCheckStmt->execute();
    
    if ($tableCheckStmt->rowCount() == 0) {
        // Table doesn't exist, create it
        $createTableQuery = "
            CREATE TABLE `admin_notifications` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `title` varchar(255) NOT NULL,
              `message` text NOT NULL,
              `recipient_type` enum('all','members','gym_owners','specific') NOT NULL DEFAULT 'all',
              `recipient_ids` text DEFAULT NULL,
              `status` enum('draft','sent','scheduled') NOT NULL DEFAULT 'draft',
              `notification_type` enum('system','promotional','informational','alert') NOT NULL DEFAULT 'system',
              `send_email` tinyint(1) NOT NULL DEFAULT 0,
              `send_push` tinyint(1) NOT NULL DEFAULT 1,
              `send_sms` tinyint(1) NOT NULL DEFAULT 0,
              `scheduled_at` datetime DEFAULT NULL,
              `sent_at` datetime DEFAULT NULL,
              `created_by` int(11) NOT NULL,
              `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
              `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ";
        
        $conn->exec($createTableQuery);
        $message = "Notifications table created successfully.";
    }
} catch (PDOException $e) {
    $error = "Error checking/creating notifications table: " . $e->getMessage();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Create new notification
    if (isset($_POST['create_notification'])) {
        $title = trim($_POST['title']);
        $notificationMessage = trim($_POST['message']);
        $recipientType = $_POST['recipient_type'];
        $recipientIds = isset($_POST['recipient_ids']) ? implode(',', $_POST['recipient_ids']) : null;
        $notificationType = $_POST['notification_type'];
        $sendEmail = isset($_POST['send_email']) ? 1 : 0;
        $sendPush = isset($_POST['send_push']) ? 1 : 0;
        $sendSms = isset($_POST['send_sms']) ? 1 : 0;
        $status = $_POST['status'];
        $scheduledAt = null;
        
        if ($status === 'scheduled' && !empty($_POST['scheduled_date']) && !empty($_POST['scheduled_time'])) {
            $scheduledAt = $_POST['scheduled_date'] . ' ' . $_POST['scheduled_time'] . ':00';
        }
        
        // Validate inputs
        if (empty($title) || empty($notificationMessage)) {
            $error = "Please fill in all required fields.";
        } else {
            try {
                $sql = "INSERT INTO admin_notifications (
                            title, message, recipient_type, recipient_ids, 
                            notification_type, send_email, send_push, send_sms,
                            status, scheduled_at, created_by
                        ) VALUES (
                            ?, ?, ?, ?, 
                            ?, ?, ?, ?,
                            ?, ?, ?
                        )";
                
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    $title, $notificationMessage, $recipientType, $recipientIds,
                    $notificationType, $sendEmail, $sendPush, $sendSms,
                    $status, $scheduledAt, $_SESSION['admin_id']
                ]);
                
                $notificationId = $conn->lastInsertId();
                
                // If status is 'sent', process the notification immediately
                if ($status === 'sent') {
                    // Update sent_at timestamp
                    $updateSql = "UPDATE admin_notifications SET sent_at = NOW() WHERE id = ?";
                    $updateStmt = $conn->prepare($updateSql);
                    $updateStmt->execute([$notificationId]);
                    
                    // Send the notification based on recipient type
                    sendNotification($conn, $notificationId, $title, $notificationMessage, $recipientType, $recipientIds, $sendEmail, $sendPush, $sendSms);
                }
                
                // Log the activity
                $activitySql = "
                    INSERT INTO activity_logs (
                        user_id, user_type, action, details, ip_address, user_agent
                    ) VALUES (
                        ?, 'admin', ?, ?, ?, ?
                    )
                ";
                $details = "Admin created new notification: {$title} (Status: {$status})";
                $activityStmt = $conn->prepare($activitySql);
                $activityStmt->execute([
                    $_SESSION['admin_id'],
                    'create_notification',
                    $details,
                    $_SERVER['REMOTE_ADDR'],
                    $_SERVER['HTTP_USER_AGENT']
                ]);
                
                $message = "Notification created successfully!";
            } catch (PDOException $e) {
                $error = "Database error: " . $e->getMessage();
            }
        }
    }
    
    // Delete notification
    if (isset($_POST['delete_notification'])) {
        $notificationId = intval($_POST['notification_id']);
        
        try {
            // Get notification details for logging
            $getStmt = $conn->prepare("SELECT title FROM admin_notifications WHERE id = ?");
            $getStmt->execute([$notificationId]);
            $notificationDetails = $getStmt->fetch(PDO::FETCH_ASSOC);
            
            // Delete the notification
            $sql = "DELETE FROM admin_notifications WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$notificationId]);
            
            // Log the activity
            $activitySql = "
                INSERT INTO activity_logs (
                    user_id, user_type, action, details, ip_address, user_agent
                ) VALUES (
                    ?, 'admin', ?, ?, ?, ?
                )
            ";
            $details = "Admin deleted notification: {$notificationDetails['title']}";
            $activityStmt = $conn->prepare($activitySql);
            $activityStmt->execute([
                $_SESSION['admin_id'],
                'delete_notification',
                $details,
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT']
            ]);
            
            $message = "Notification deleted successfully!";
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
    
    // Send draft notification
    if (isset($_POST['send_notification'])) {
        $notificationId = intval($_POST['notification_id']);
        
        try {
            // Get notification details
            $getStmt = $conn->prepare("
                SELECT title, message, recipient_type, recipient_ids, send_email, send_push, send_sms 
                FROM admin_notifications 
                WHERE id = ?
            ");
            $getStmt->execute([$notificationId]);
            $notificationDetails = $getStmt->fetch(PDO::FETCH_ASSOC);
            
            // Update notification status
            $updateSql = "UPDATE admin_notifications SET status = 'sent', sent_at = NOW() WHERE id = ?";
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->execute([$notificationId]);
            
            // Send the notification
            sendNotification(
                $conn, 
                $notificationId, 
                $notificationDetails['title'], 
                $notificationDetails['message'], 
                $notificationDetails['recipient_type'], 
                $notificationDetails['recipient_ids'],
                $notificationDetails['send_email'],
                $notificationDetails['send_push'],
                $notificationDetails['send_sms']
            );
            
            // Log the activity
            $activitySql = "
                INSERT INTO activity_logs (
                    user_id, user_type, action, details, ip_address, user_agent
                ) VALUES (
                    ?, 'admin', ?, ?, ?, ?
                )
            ";
            $details = "Admin sent notification: {$notificationDetails['title']}";
            $activityStmt = $conn->prepare($activitySql);
            $activityStmt->execute([
                $_SESSION['admin_id'],
                'send_notification',
                $details,
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT']
            ]);
            
            $message = "Notification sent successfully!";
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}

// Function to send notifications
function sendNotification($conn, $notificationId, $title, $message, $recipientType, $recipientIds, $sendEmail, $sendPush, $sendSms) {
    try {
        // Determine recipients based on type
        $recipientQuery = "";
        $params = [];
        
        switch ($recipientType) {
            case 'all':
                $recipientQuery = "SELECT id, email, phone, username, role FROM users WHERE status = 'active'";
                break;
            case 'members':
                $recipientQuery = "SELECT id, email, phone, username, role FROM users WHERE role = 'member' AND status = 'active'";
                break;
            case 'gym_owners':
                $recipientQuery = "SELECT id, email, phone, username, role FROM users WHERE role = 'gym_owner' AND status = 'active'";
                break;
            case 'specific':
                if (!empty($recipientIds)) {
                    $ids = explode(',', $recipientIds);
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $recipientQuery = "SELECT id, email, phone, username, role FROM users WHERE id IN ($placeholders) AND status = 'active'";
                    $params = $ids;
                }
                break;
        }
        
        if (!empty($recipientQuery)) {
            $stmt = $conn->prepare($recipientQuery);
            $stmt->execute($params);
            $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Insert notifications for each recipient
            foreach ($recipients as $recipient) {
                // Insert into user_notifications table
                $insertSql = "
                    INSERT INTO user_notifications (
                        user_id, title, message, notification_type, is_read, created_at
                    ) VALUES (
                        ?, ?, ?, 'admin', 0, NOW()
                    )
                ";
                $insertStmt = $conn->prepare($insertSql);
                $insertStmt->execute([
                    $recipient['id'],
                    $title,
                    $message
                ]);
                
                // Send email if enabled
                if ($sendEmail && !empty($recipient['email'])) {
                    // In a real application, you would integrate with an email service here
                    // For now, we'll just log it
                    error_log("Sending email to {$recipient['email']}: {$title}");
                }
                
                // Send SMS if enabled
                if ($sendSms && !empty($recipient['phone'])) {
                    // In a real application, you would integrate with an SMS service here
                    // For now, we'll just log it
                    error_log("Sending SMS to {$recipient['phone']}: {$title}");
                }
                
                // Send push notification if enabled
                if ($sendPush) {
                    // In a real application, you would integrate with a push notification service here
                    // For now, we'll just log it
                    error_log("Sending push notification to user {$recipient['id']}: {$title}");
                }
            }
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Error sending notification: " . $e->getMessage());
        return false;
    }
}

// Fetch all notifications
try {
    $sql = "
        SELECT n.*, u.username as created_by_name
        FROM admin_notifications n
        LEFT JOIN users u ON n.created_by = u.id
        ORDER BY 
            CASE 
                WHEN n.status = 'scheduled' AND n.scheduled_at > NOW() THEN 1
                WHEN n.status = 'draft' THEN 2
                ELSE 3
            END,
            n.created_at DESC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching notifications: " . $e->getMessage();
}

// Fetch users for recipient selection
try {
    $sql = "
        SELECT id, username, email, role 
        FROM users 
        WHERE status = 'active'
        ORDER BY role, username
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching users: " . $e->getMessage();
}

// Check for scheduled notifications that need to be sent
try {
    $scheduledSql = "
        SELECT id, title, message, recipient_type, recipient_ids, send_email, send_push, send_sms
        FROM admin_notifications 
        WHERE status = 'scheduled' 
        AND scheduled_at <= NOW()
        AND sent_at IS NULL
    ";
    $scheduledStmt = $conn->prepare($scheduledSql);
    $scheduledStmt->execute();
    $scheduledNotifications = $scheduledStmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($scheduledNotifications as $notification) {
        // Update status to sent
        $updateSql = "UPDATE admin_notifications SET status = 'sent', sent_at = NOW() WHERE id = ?";
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->execute([$notification['id']]);
        
        // Send the notification
        sendNotification(
            $conn, 
            $notification['id'], 
            $notification['title'], 
            $notification['message'], 
            $notification['recipient_type'], 
            $notification['recipient_ids'],
            $notification['send_email'],
            $notification['send_push'],
            $notification['send_sms']
        );
    }
} catch (PDOException $e) {
    $error = "Error processing scheduled notifications: " . $e->getMessage();
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - FlexFit Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .modal {
            transition: opacity 0.25s ease;
        }
        body.modal-active {
            overflow-x: hidden;
            overflow-y: visible !important;
        }
        .animate-fade-in {
            animation: fadeIn 0.5s ease-in-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .recipient-list {
            max-height: 200px;
            overflow-y: auto;
        }
        .recipient-list::-webkit-scrollbar {
            width: 8px;
        }
        .recipient-list::-webkit-scrollbar-track {
            background: #374151;
        }
        .recipient-list::-webkit-scrollbar-thumb {
            background-color: #4B5563;
            border-radius: 20px;
        }
    </style>
</head>
<body class="bg-gray-900 text-white">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="ml-0 lg:ml-64 p-4 sm:p-6 transition-all duration-200">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold">Notifications</h1>
            <button id="openCreateModal" class="bg-yellow-500 hover:bg-yellow-600 text-black font-bold py-2 px-4 rounded-lg transition-colors duration-200 flex items-center">
                <i class="fas fa-plus mr-2"></i> Create Notification
            </button>
        </div>
        
        <?php if (!empty($message)): ?>
            <div class="bg-green-600 text-white p-4 rounded-lg mb-6 animate-fade-in">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($error)): ?>
            <div class="bg-red-600 text-white p-4 rounded-lg mb-6 animate-fade-in">
                <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <!-- Notifications Table -->
        <div class="bg-gray-800 rounded-xl shadow-lg overflow-hidden">
            <div class="p-6 border-b border-gray-700">
                <h2 class="text-xl font-semibold">All Notifications</h2>
                <p class="text-gray-400 mt-1">Manage system notifications sent to users</p>
            </div>
            
            <?php if (empty($notifications)): ?>
                <div class="p-6 text-center text-gray-500">
                    <i class="fas fa-bell-slash text-4xl mb-3"></i>
                    <p>No notifications found. Create your first notification to get started.</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full bg-gray-800">
                        <thead>
                            <tr class="bg-gray-700">
                                <th class="py-3 px-4 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Title</th>
                                <th class="py-3 px-4 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Type</th>
                                <th class="py-3 px-4 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Recipients</th>
                                <th class="py-3 px-4 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Channels</th>
                                <th class="py-3 px-4 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Status</th>
                                <th class="py-3 px-4 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Created</th>
                                <th class="py-3 px-4 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-700">
                            <?php foreach ($notifications as $notification): ?>
                                <?php 
                                    $statusClass = '';
                                    $statusIcon = '';
                                    
                                    switch ($notification['status']) {
                                        case 'draft':
                                            $statusClass = 'bg-gray-600 text-gray-200';
                                            $statusIcon = 'fa-pencil';
                                            break;
                                        case 'scheduled':
                                            $statusClass = 'bg-blue-600 text-blue-200';
                                            $statusIcon = 'fa-clock';
                                            break;
                                        case 'sent':
                                            $statusClass = 'bg-green-600 text-green-200';
                                            $statusIcon = 'fa-check';
                                            break;
                                    }
                                    
                                    $typeClass = '';
                                    switch ($notification['notification_type']) {
                                        case 'system':
                                            $typeClass = 'bg-gray-700 text-gray-300';
                                            break;
                                        case 'promotional':
                                            $typeClass = 'bg-purple-700 text-purple-300';
                                            break;
                                        case 'informational':
                                            $typeClass = 'bg-blue-700 text-blue-300';
                                            break;
                                        case 'alert':
                                            $typeClass = 'bg-red-700 text-red-300';
                                            break;
                                    }
                                ?>
                                <tr class="hover:bg-gray-700 transition-colors duration-200">
                                    <td class="py-3 px-4">
                                        <div class="font-medium"><?php echo htmlspecialchars($notification['title']); ?></div>
                                        <div class="text-sm text-gray-400 truncate max-w-xs"><?php echo htmlspecialchars(substr($notification['message'], 0, 50) . (strlen($notification['message']) > 50 ? '...' : '')); ?></div>
                                    </td>
                                    <td class="py-3 px-4">
                                        <span class="px-2 py-1 text-xs rounded-full <?php echo $typeClass; ?>">
                                            <?php echo ucfirst($notification['notification_type']); ?>
                                        </span>
                                    </td>
                                    <td class="py-3 px-4">
                                        <?php 
                                            switch ($notification['recipient_type']) {
                                                case 'all':
                                                    echo '<span class="text-gray-300">All Users</span>';
                                                    break;
                                                case 'members':
                                                    echo '<span class="text-blue-400">Members Only</span>';
                                                    break;
                                                case 'gym_owners':
                                                    echo '<span class="text-green-400">Gym Owners Only</span>';
                                                    break;
                                                case 'specific':
                                                    $count = count(explode(',', $notification['recipient_ids']));
                                                    echo '<span class="text-yellow-400">' . $count . ' Selected Users</span>';
                                                    break;
                                            }
                                        ?>
                                    </td>
                                    <td class="py-3 px-4">
                                        <div class="flex space-x-2">
                                            <?php if ($notification['send_push']): ?>
                                                <span class="text-xs px-2 py-1 bg-blue-900 text-blue-300 rounded-full">Push</span>
                                            <?php endif; ?>
                                            
                                            <?php if ($notification['send_email']): ?>
                                                <span class="text-xs px-2 py-1 bg-green-900 text-green-300 rounded-full">Email</span>
                                            <?php endif; ?>
                                            
                                            <?php if ($notification['send_sms']): ?>
                                                <span class="text-xs px-2 py-1 bg-purple-900 text-purple-300 rounded-full">SMS</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="py-3 px-4">
                                        <span class="px-2 py-1 text-xs rounded-full flex items-center w-fit <?php echo $statusClass; ?>">
                                            <i class="fas <?php echo $statusIcon; ?> mr-1"></i>
                                            <?php 
                                                echo ucfirst($notification['status']);
                                                if ($notification['status'] === 'scheduled') {
                                                    echo '<span class="ml-1">(' . date('M d, H:i', strtotime($notification['scheduled_at'])) . ')</span>';
                                                }
                                            ?>
                                        </span>
                                    </td>
                                    <td class="py-3 px-4">
                                        <div class="text-sm">
                                            <div><?php echo date('M d, Y', strtotime($notification['created_at'])); ?></div>
                                            <div class="text-gray-400 text-xs">by <?php echo htmlspecialchars($notification['created_by_name']); ?></div>
                                        </div>
                                    </td>
                                    <td class="py-3 px-4">
                                        <div class="flex space-x-2">
                                            <button class="text-blue-400 hover:text-blue-300 transition-colors duration-200 view-btn"
                                                    data-id="<?php echo $notification['id']; ?>"
                                                    data-title="<?php echo htmlspecialchars($notification['title']); ?>"
                                                    data-message="<?php echo htmlspecialchars($notification['message']); ?>"
                                                    data-type="<?php echo $notification['notification_type']; ?>"
                                                    data-recipient-type="<?php echo $notification['recipient_type']; ?>"
                                                    data-recipient-ids="<?php echo $notification['recipient_ids']; ?>"
                                                    data-status="<?php echo $notification['status']; ?>"
                                                    data-scheduled-at="<?php echo $notification['scheduled_at']; ?>"
                                                    data-sent-at="<?php echo $notification['sent_at']; ?>">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            
                                            <?php if ($notification['status'] === 'draft'): ?>
                                                <button class="text-green-400 hover:text-green-300 transition-colors duration-200 send-btn"
                                                        data-id="<?php echo $notification['id']; ?>"
                                                        data-title="<?php echo htmlspecialchars($notification['title']); ?>">
                                                    <i class="fas fa-paper-plane"></i>
                                                </button>
                                            <?php endif; ?>
                                            
                                            <button class="text-red-400 hover:text-red-300 transition-colors duration-200 delete-btn"
                                                    data-id="<?php echo $notification['id']; ?>"
                                                    data-title="<?php echo htmlspecialchars($notification['title']); ?>">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Create Notification Modal -->
    <div id="createModal" class="modal opacity-0 pointer-events-none fixed w-full h-full top-0 left-0 flex items-center justify-center z-50">
        <div class="modal-overlay absolute w-full h-full bg-black opacity-75"></div>
        
        <div class="modal-container bg-gray-800 w-11/12 md:max-w-2xl mx-auto rounded-xl shadow-lg z-50 overflow-y-auto max-h-[90vh]">
            <div class="modal-content py-4 text-left px-6">
                <div class="flex justify-between items-center pb-3 border-b border-gray-700">
                    <p class="text-xl font-bold">Create New Notification</p>
                    <button class="modal-close cursor-pointer z-50">
                        <i class="fas fa-times text-gray-400 hover:text-white"></i>
                    </button>
                </div>
                
                <form method="POST" class="mt-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="col-span-2">
                            <label class="block text-gray-300 mb-1">Title <span class="text-red-500">*</span></label>
                            <input type="text" name="title" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-yellow-500">
                        </div>
                        
                        <div class="col-span-2">
                            <label class="block text-gray-300 mb-1">Message <span class="text-red-500">*</span></label>
                            <textarea name="message" rows="4" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-yellow-500"></textarea>
                        </div>
                        
                        <div>
                            <label class="block text-gray-300 mb-1">Notification Type <span class="text-red-500">*</span></label>
                            <select name="notification_type" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-yellow-500">
                                <option value="system">System</option>
                                <option value="promotional">Promotional</option>
                                <option value="informational">Informational</option>
                                <option value="alert">Alert</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-gray-300 mb-1">Recipients <span class="text-red-500">*</span></label>
                            <select name="recipient_type" id="recipient_type" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-yellow-500">
                                <option value="all">All Users</option>
                                <option value="members">Members Only</option>
                                <option value="gym_owners">Gym Owners Only</option>
                                <option value="specific">Specific Users</option>
                            </select>
                        </div>
                        
                        <div id="specific_recipients_container" class="col-span-2 hidden">
                            <label class="block text-gray-300 mb-1">Select Users <span class="text-red-500">*</span></label>
                            <div class="recipient-list bg-gray-700 border border-gray-600 rounded-lg p-3">
                                <div class="mb-2">
                                    <input type="text" id="recipient_search" placeholder="Search users..." class="w-full bg-gray-600 border border-gray-500 rounded-lg px-3 py-1 text-white text-sm focus:outline-none focus:border-yellow-500">
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                    <?php foreach ($recipients as $recipient): ?>
                                        <div class="recipient-item flex items-center">
                                            <input type="checkbox" name="recipient_ids[]" value="<?php echo $recipient['id']; ?>" id="recipient_<?php echo $recipient['id']; ?>" class="mr-2 rounded border-gray-600 bg-gray-800 text-yellow-500 focus:ring-yellow-500">
                                            <label for="recipient_<?php echo $recipient['id']; ?>" class="text-sm">
                                                <span class="font-medium"><?php echo htmlspecialchars($recipient['username']); ?></span>
                                                <span class="text-xs text-gray-400 ml-1">(<?php echo ucfirst($recipient['role']); ?>)</span>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-span-2">
                            <label class="block text-gray-300 mb-1">Delivery Channels</label>
                            <div class="flex flex-wrap gap-4">
                                <label class="flex items-center">
                                    <input type="checkbox" name="send_push" checked class="rounded border-gray-600 bg-gray-800 text-yellow-500 focus:ring-yellow-500">
                                    <span class="ml-2">Push Notification</span>
                                </label>
                                <label class="flex items-center">
                                    <input type="checkbox" name="send_email" class="rounded border-gray-600 bg-gray-800 text-yellow-500 focus:ring-yellow-500">
                                    <span class="ml-2">Email</span>
                                </label>
                                <label class="flex items-center">
                                    <input type="checkbox" name="send_sms" class="rounded border-gray-600 bg-gray-800 text-yellow-500 focus:ring-yellow-500">
                                    <span class="ml-2">SMS</span>
                                </label>
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-gray-300 mb-1">Status <span class="text-red-500">*</span></label>
                            <select name="status" id="notification_status" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-yellow-500">
                                <option value="draft">Save as Draft</option>
                                <option value="sent">Send Immediately</option>
                                <option value="scheduled">Schedule for Later</option>
                            </select>
                        </div>
                        
                        <div id="schedule_container" class="hidden">
                            <label class="block text-gray-300 mb-1">Schedule Date & Time <span class="text-red-500">*</span></label>
                            <div class="grid grid-cols-2 gap-2">
                                <input type="date" name="scheduled_date" class="bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-yellow-500">
                                <input type="time" name="scheduled_time" class="bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-yellow-500">
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-6 flex justify-end">
                        <button type="button" class="modal-close bg-gray-600 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded-lg mr-2">
                            Cancel
                        </button>
                        <button type="submit" name="create_notification" class="bg-yellow-500 hover:bg-yellow-600 text-black font-bold py-2 px-4 rounded-lg">
                            Create Notification
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- View Notification Modal -->
    <div id="viewModal" class="modal opacity-0 pointer-events-none fixed w-full h-full top-0 left-0 flex items-center justify-center z-50">
        <div class="modal-overlay absolute w-full h-full bg-black opacity-75"></div>
        
        <div class="modal-container bg-gray-800 w-11/12 md:max-w-2xl mx-auto rounded-xl shadow-lg z-50 overflow-y-auto max-h-[90vh]">
            <div class="modal-content py-4 text-left px-6">
                <div class="flex justify-between items-center pb-3 border-b border-gray-700">
                    <p class="text-xl font-bold">Notification Details</p>
                    <button class="modal-close cursor-pointer z-50">
                        <i class="fas fa-times text-gray-400 hover:text-white"></i>
                    </button>
                </div>
                
                <div class="mt-4 space-y-4">
                    <div>
                        <h3 id="view_title" class="text-xl font-semibold text-white"></h3>
                        <div id="view_status" class="mt-1"></div>
                    </div>
                    
                    <div class="bg-gray-700 rounded-lg p-4">
                        <p id="view_message" class="text-gray-300 whitespace-pre-line"></p>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <h4 class="text-sm font-medium text-gray-400 mb-1">Notification Type</h4>
                            <p id="view_type" class="text-white"></p>
                        </div>
                        
                        <div>
                            <h4 class="text-sm font-medium text-gray-400 mb-1">Recipients</h4>
                            <p id="view_recipients" class="text-white"></p>
                        </div>
                        
                        <div>
                            <h4 class="text-sm font-medium text-gray-400 mb-1">Delivery Channels</h4>
                            <div id="view_channels" class="flex space-x-2"></div>
                        </div>
                        
                        <div>
                            <h4 class="text-sm font-medium text-gray-400 mb-1">Timing</h4>
                            <div id="view_timing" class="text-white"></div>
                        </div>
                    </div>
                    
                    <div id="view_actions" class="mt-6 flex justify-end">
                        <button type="button" class="modal-close bg-gray-600 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded-lg">
                            Close
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Send Confirmation Modal -->
    <div id="sendModal" class="modal opacity-0 pointer-events-none fixed w-full h-full top-0 left-0 flex items-center justify-center z-50">
        <div class="modal-overlay absolute w-full h-full bg-black opacity-75"></div>
        
        <div class="modal-container bg-gray-800 w-11/12 md:max-w-md mx-auto rounded-xl shadow-lg z-50">
            <div class="modal-content py-4 text-left px-6">
                <div class="flex justify-between items-center pb-3 border-b border-gray-700">
                    <p class="text-xl font-bold text-green-500">Confirm Sending</p>
                    <button class="modal-close cursor-pointer z-50">
                        <i class="fas fa-times text-gray-400 hover:text-white"></i>
                    </button>
                </div>
                
                <div class="my-4">
                    <p class="text-white">Are you sure you want to send the notification: <span id="send_notification_title" class="font-semibold"></span>?</p>
                    <p class="text-gray-400 mt-2">This action cannot be undone and will deliver the notification to all recipients immediately.</p>
                </div>
                
                <form method="POST" class="mt-6 flex justify-end">
                    <input type="hidden" name="notification_id" id="send_notification_id">
                    
                    <button type="button" class="modal-close bg-gray-600 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded-lg mr-2">
                        Cancel
                    </button>
                    <button type="submit" name="send_notification" class="bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded-lg">
                        Send Now
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal opacity-0 pointer-events-none fixed w-full h-full top-0 left-0 flex items-center justify-center z-50">
        <div class="modal-overlay absolute w-full h-full bg-black opacity-75"></div>
        
        <div class="modal-container bg-gray-800 w-11/12 md:max-w-md mx-auto rounded-xl shadow-lg z-50">
            <div class="modal-content py-4 text-left px-6">
                <div class="flex justify-between items-center pb-3 border-b border-gray-700">
                    <p class="text-xl font-bold text-red-500">Confirm Deletion</p>
                    <button class="modal-close cursor-pointer z-50">
                        <i class="fas fa-times text-gray-400 hover:text-white"></i>
                    </button>
                </div>
                
                <div class="my-4">
                    <p class="text-white">Are you sure you want to delete the notification: <span id="delete_notification_title" class="font-semibold"></span>?</p>
                    <p class="text-gray-400 mt-2">This action cannot be undone.</p>
                </div>
                
                <form method="POST" class="mt-6 flex justify-end">
                    <input type="hidden" name="notification_id" id="delete_notification_id">
                    
                    <button type="button" class="modal-close bg-gray-600 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded-lg mr-2">
                        Cancel
                    </button>
                    <button type="submit" name="delete_notification" class="bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded-lg">
                        Delete
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        // Modal functionality
        function toggleModal(modalId) {
            const modal = document.getElementById(modalId);
            modal.classList.toggle('opacity-0');
            modal.classList.toggle('pointer-events-none');
            document.body.classList.toggle('modal-active');
        }
        
        // Create modal
        document.getElementById('openCreateModal').addEventListener('click', function() {
            toggleModal('createModal');
            
            // Set default date and time for scheduling
            const now = new Date();
            const tomorrow = new Date(now);
            tomorrow.setDate(tomorrow.getDate() + 1);
            
            const dateInput = document.querySelector('input[name="scheduled_date"]');
            dateInput.value = tomorrow.toISOString().split('T')[0];
            dateInput.min = now.toISOString().split('T')[0];
            
            const timeInput = document.querySelector('input[name="scheduled_time"]');
            timeInput.value = now.getHours().toString().padStart(2, '0') + ':' + now.getMinutes().toString().padStart(2, '0');
        });
        
        // Recipient type change handler
        document.getElementById('recipient_type').addEventListener('change', function() {
            const specificContainer = document.getElementById('specific_recipients_container');
            specificContainer.classList.toggle('hidden', this.value !== 'specific');
        });
        
        // Status change handler
        document.getElementById('notification_status').addEventListener('change', function() {
            const scheduleContainer = document.getElementById('schedule_container');
            scheduleContainer.classList.toggle('hidden', this.value !== 'scheduled');
        });
        
        // Recipient search functionality
        document.getElementById('recipient_search').addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const recipientItems = document.querySelectorAll('.recipient-item');
            
            recipientItems.forEach(item => {
                const username = item.querySelector('label span:first-child').textContent.toLowerCase();
                const role = item.querySelector('label span:last-child').textContent.toLowerCase();
                
                if (username.includes(searchTerm) || role.includes(searchTerm)) {
                    item.style.display = '';
                } else {
                    item.style.display = 'none';
                }
            });
        });
        
        // View notification details
        document.querySelectorAll('.view-btn').forEach(button => {
            button.addEventListener('click', function() {
                const id = this.getAttribute('data-id');
                const title = this.getAttribute('data-title');
                const message = this.getAttribute('data-message');
                const type = this.getAttribute('data-type');
                const recipientType = this.getAttribute('data-recipient-type');
                const recipientIds = this.getAttribute('data-recipient-ids');
                const status = this.getAttribute('data-status');
                const scheduledAt = this.getAttribute('data-scheduled-at');
                const sentAt = this.getAttribute('data-sent-at');
                
                // Set values in the view modal
                document.getElementById('view_title').textContent = title;
                document.getElementById('view_message').textContent = message;
                
                // Set notification type
                let typeDisplay = '';
                switch (type) {
                    case 'system':
                        typeDisplay = '<span class="px-2 py-1 text-xs rounded-full bg-gray-700 text-gray-300">System</span>';
                        break;
                    case 'promotional':
                        typeDisplay = '<span class="px-2 py-1 text-xs rounded-full bg-purple-700 text-purple-300">Promotional</span>';
                        break;
                    case 'informational':
                        typeDisplay = '<span class="px-2 py-1 text-xs rounded-full bg-blue-700 text-blue-300">Informational</span>';
                        break;
                    case 'alert':
                        typeDisplay = '<span class="px-2 py-1 text-xs rounded-full bg-red-700 text-red-300">Alert</span>';
                        break;
                }
                document.getElementById('view_type').innerHTML = typeDisplay;
                
                // Set status
                let statusDisplay = '';
                switch (status) {
                    case 'draft':
                        statusDisplay = '<span class="px-2 py-1 text-xs rounded-full bg-gray-600 text-gray-200"><i class="fas fa-pencil mr-1"></i> Draft</span>';
                        break;
                    case 'scheduled':
                        statusDisplay = '<span class="px-2 py-1 text-xs rounded-full bg-blue-600 text-blue-200"><i class="fas fa-clock mr-1"></i> Scheduled</span>';
                        break;
                    case 'sent':
                        statusDisplay = '<span class="px-2 py-1 text-xs rounded-full bg-green-600 text-green-200"><i class="fas fa-check mr-1"></i> Sent</span>';
                        break;
                }
                document.getElementById('view_status').innerHTML = statusDisplay;
                
                // Set recipients
                let recipientsDisplay = '';
                switch (recipientType) {
                    case 'all':
                        recipientsDisplay = '<span class="text-gray-300">All Users</span>';
                        break;
                    case 'members':
                        recipientsDisplay = '<span class="text-blue-400">Members Only</span>';
                        break;
                    case 'gym_owners':
                        recipientsDisplay = '<span class="text-green-400">Gym Owners Only</span>';
                        break;
                    case 'specific':
                        const count = recipientIds ? recipientIds.split(',').length : 0;
                        recipientsDisplay = '<span class="text-yellow-400">' + count + ' Selected Users</span>';
                        break;
                }
                document.getElementById('view_recipients').innerHTML = recipientsDisplay;
                
                // Set channels
                const channelsContainer = document.getElementById('view_channels');
                channelsContainer.innerHTML = '';
                
                if (this.closest('tr').querySelector('td:nth-child(4) span:nth-child(1)')) {
                    channelsContainer.innerHTML += '<span class="text-xs px-2 py-1 bg-blue-900 text-blue-300 rounded-full">Push</span>';
                }
                
                if (this.closest('tr').querySelector('td:nth-child(4) span:nth-child(2)')) {
                    channelsContainer.innerHTML += '<span class="text-xs px-2 py-1 bg-green-900 text-green-300 rounded-full">Email</span>';
                }
                
                if (this.closest('tr').querySelector('td:nth-child(4) span:nth-child(3)')) {
                    channelsContainer.innerHTML += '<span class="text-xs px-2 py-1 bg-purple-900 text-purple-300 rounded-full">SMS</span>';
                }
                
                // Set timing information
                const timingContainer = document.getElementById('view_timing');
                let timingHTML = '';
                
                if (status === 'draft') {
                    timingHTML = '<span class="text-gray-400">Not sent yet</span>';
                } else if (status === 'scheduled') {
                    const scheduledDate = new Date(scheduledAt);
                    timingHTML = '<span class="text-blue-400">Scheduled for: ' + scheduledDate.toLocaleString() + '</span>';
                } else if (status === 'sent') {
                    const sentDate = new Date(sentAt);
                    timingHTML = '<span class="text-green-400">Sent on: ' + sentDate.toLocaleString() + '</span>';
                }
                
                timingContainer.innerHTML = timingHTML;
                
                // Add send button for draft notifications
                const actionsContainer = document.getElementById('view_actions');
                if (status === 'draft') {
                    actionsContainer.innerHTML = `
                        <form method="POST" class="inline-block mr-2">
                            <input type="hidden" name="notification_id" value="${id}">
                            <button type="submit" name="send_notification" class="bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded-lg">
                                <i class="fas fa-paper-plane mr-1"></i> Send Now
                            </button>
                        </form>
                        <button type="button" class="modal-close bg-gray-600 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded-lg">
                            Close
                        </button>
                    `;
                } else {
                    actionsContainer.innerHTML = `
                        <button type="button" class="modal-close bg-gray-600 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded-lg">
                            Close
                        </button>
                    `;
                }
                
                toggleModal('viewModal');
            });
        });
        
        // Send notification confirmation
        document.querySelectorAll('.send-btn').forEach(button => {
            button.addEventListener('click', function() {
                const id = this.getAttribute('data-id');
                const title = this.getAttribute('data-title');
                
                document.getElementById('send_notification_id').value = id;
                document.getElementById('send_notification_title').textContent = title;
                
                toggleModal('sendModal');
            });
        });
        
        // Delete notification confirmation
        document.querySelectorAll('.delete-btn').forEach(button => {
            button.addEventListener('click', function() {
                const id = this.getAttribute('data-id');
                const title = this.getAttribute('data-title');
                
                document.getElementById('delete_notification_id').value = id;
                document.getElementById('delete_notification_title').textContent = title;
                
                toggleModal('deleteModal');
            });
        });
        
        // Close modals
        document.querySelectorAll('.modal-close').forEach(button => {
            button.addEventListener('click', function() {
                const modal = this.closest('.modal');
                toggleModal(modal.id);
            });
        });
        
        // Close modals when clicking on overlay
        document.querySelectorAll('.modal-overlay').forEach(overlay => {
            overlay.addEventListener('click', function() {
                const modal = this.closest('.modal');
                toggleModal(modal.id);
            });
        });
        
        // Close modals with ESC key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && document.body.classList.contains('modal-active')) {
                document.querySelectorAll('.modal').forEach(modal => {
                    if (!modal.classList.contains('opacity-0')) {
                        toggleModal(modal.id);
                    }
                });
            }
        });
        
        // Form validation
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                if (this.querySelector('button[name="create_notification"]')) {
                    const recipientType = document.getElementById('recipient_type').value;
                    const status = document.getElementById('notification_status').value;
                    
                    // Validate specific recipients
                    if (recipientType === 'specific') {
                        const selectedRecipients = document.querySelectorAll('input[name="recipient_ids[]"]:checked');
                        if (selectedRecipients.length === 0) {
                            e.preventDefault();
                            alert('Please select at least one recipient.');
                            return false;
                        }
                    }
                    
                    // Validate scheduled date and time
                    if (status === 'scheduled') {
                        const scheduledDate = document.querySelector('input[name="scheduled_date"]').value;
                        const scheduledTime = document.querySelector('input[name="scheduled_time"]').value;
                        
                        if (!scheduledDate || !scheduledTime) {
                            e.preventDefault();
                            alert('Please select both date and time for scheduled notifications.');
                            return false;
                        }
                        
                        const scheduledDateTime = new Date(scheduledDate + 'T' + scheduledTime);
                        const now = new Date();
                        
                        if (scheduledDateTime <= now) {
                            e.preventDefault();
                            alert('Scheduled time must be in the future.');
                            return false;
                        }
                    }
                }
                
                return true;
            });
        });
        
        // Initialize tooltips
        document.addEventListener('DOMContentLoaded', function() {
            // Check for scheduled notifications that need to be sent
            setInterval(function() {
                fetch('check_scheduled_notifications.php')
                    .then(response => response.json())
                    .then(data => {
                        if (data.sent > 0) {
                            console.log(`${data.sent} scheduled notifications were sent.`);
                            // Optionally refresh the page to show updated statuses
                            // location.reload();
                        }
                    })
                    .catch(error => console.error('Error checking scheduled notifications:', error));
            }, 60000); // Check every minute
        });
    </script>
</body>
</html>



