<?php
session_start();
require_once '../config/database.php';


// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    $_SESSION['error'] = "Please log in to access the admin dashboard.";
    header("Location: login.php");
    exit();
}

$db = new GymDatabase();
$conn = $db->getConnection();

// Handle approval/rejection actions
if (isset($_POST['action']) && isset($_POST['owner_id'])) {
    $owner_id = filter_input(INPUT_POST, 'owner_id', FILTER_VALIDATE_INT);
    $action = $_POST['action'];
    $message = isset($_POST['message']) ? trim($_POST['message']) : '';
    
    if ($owner_id) {
        try {
            $conn->beginTransaction();
            
            // Get owner details for notification
            $stmt = $conn->prepare("SELECT name, email FROM gym_owners WHERE id = ?");
            $stmt->execute([$owner_id]);
            $owner = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($action === 'approve') {
                // Update owner status
                $stmt = $conn->prepare("UPDATE gym_owners SET is_approved = 1, approved_at = NOW(), approved_by = ? WHERE id = ?");
                $stmt->execute([$_SESSION['admin_id'], $owner_id]);
                
                // Create notification
                $stmt = $conn->prepare("
                    INSERT INTO notifications (
                        user_id, type, title, message, created_at, status, is_read
                    ) VALUES (?, 'owner_approval', 'Account Approved', ?, NOW(), 'unread', 0)
                ");
                $notificationMsg = !empty($message) ? 
                    "Your gym owner account has been approved. You can now log in and add your gym. Message from admin: $message" : 
                    "Your gym owner account has been approved. You can now log in and add your gym.";
                $stmt->execute([$owner_id, $notificationMsg]);
                
                // Send email notification
                if (class_exists('EmailService')) {
                    require_once '../includes/EmailService.php';
                    $emailService = new EmailService();
                    $emailService->sendEmail(
                        $owner['email'],
                        "Your Gym Owner Account Has Been Approved",
                        "<p>Dear {$owner['name']},</p>
                        <p>Congratulations! Your gym owner account has been approved. You can now log in to the platform and add your gym.</p>
                        " . (!empty($message) ? "<p><strong>Message from admin:</strong> $message</p>" : "") . "
                        <p>Thank you for joining our platform!</p>
                        <p>Best regards,<br>The Features Gym Team</p>"
                    );
                }
                
                $_SESSION['success'] = "Gym owner account approved successfully.";
            } elseif ($action === 'reject') {
                if (empty($message)) {
                    throw new Exception("Please provide a reason for rejection.");
                }
                
                // Update owner status
                $stmt = $conn->prepare("UPDATE gym_owners SET is_approved = 0, rejection_reason = ?, rejected_at = NOW(), rejected_by = ? WHERE id = ?");
                $stmt->execute([$message, $_SESSION['admin_id'], $owner_id]);
                
                // Create notification
                $stmt = $conn->prepare("
                    INSERT INTO notifications (
                        user_id, type, title, message, created_at, status, is_read
                    ) VALUES (?, 'owner_rejection', 'Account Application Rejected', ?, NOW(), 'unread', 0)
                ");
                $notificationMsg = "Your gym owner account application has been rejected. Reason: $message";
                $stmt->execute([$owner_id, $notificationMsg]);
                
                // Send email notification
                if (class_exists('EmailService')) {
                    require_once '../includes/EmailService.php';
                    $emailService = new EmailService();
                    $emailService->sendEmail(
                        $owner['email'],
                        "Your Gym Owner Account Application Status",
                        "<p>Dear {$owner['name']},</p>
                        <p>We regret to inform you that your gym owner account application has been rejected.</p>
                        <p><strong>Reason:</strong> $message</p>
                        <p>If you believe this is an error or would like to provide additional information, please contact our support team.</p>
                        <p>Best regards,<br>The Features Gym Team</p>"
                    );
                }
                
                $_SESSION['success'] = "Gym owner account rejected successfully.";
            }
            
            // Log the action
            $stmt = $conn->prepare("
                INSERT INTO activity_logs (
                    user_id, user_type, action, details, ip_address, user_agent, created_at
                ) VALUES (?, 'admin', ?, ?, ?, ?, NOW())
            ");
            $actionDetails = $action === 'approve' ? 'approved' : 'rejected';
            $stmt->execute([
                $_SESSION['admin_id'],
                "owner_$actionDetails",
                "Gym owner ID #$owner_id $actionDetails" . ($action === 'reject' ? ": $message" : ""),
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT']
            ]);
            
            $conn->commit();
        } catch (Exception $e) {
            $conn->rollBack();
            $_SESSION['error'] = "Error: " . $e->getMessage();
        }
    }
    
    // Redirect to prevent form resubmission
    header("Location: pending_owners.php");
    exit();
}

// Get filter parameters
$status = isset($_GET['status']) ? $_GET['status'] : 'pending';
$search = isset($_GET['search']) ? $_GET['search'] : '';

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Build query
$query = "
    SELECT go.*, 
           a1.username as approved_by_name,
           a2.username as rejected_by_name
    FROM gym_owners go
    LEFT JOIN users a1 ON go.approved_by = a1.id
    LEFT JOIN users a2 ON go.rejected_by = a2.id
    WHERE 1=1
";
$params = [];

if ($status === 'pending') {
    $query .= " AND go.is_verified = 1 AND go.is_approved IS NULL";
} elseif ($status === 'approved') {
    $query .= " AND go.is_approved = 1";
} elseif ($status === 'rejected') {
    $query .= " AND go.is_approved = 0";
}

if (!empty($search)) {
    $query .= " AND (go.name LIKE ? OR go.email LIKE ? OR go.phone LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

// Count total records for pagination
$countQuery = str_replace("SELECT go.*, a1.username as approved_by_name, a2.username as rejected_by_name", "SELECT COUNT(*)", $query);
$stmt = $conn->prepare($countQuery);
$stmt->execute($params);
$totalRecords = $stmt->fetchColumn();
$totalPages = ceil($totalRecords / $limit);

// Get records with pagination
$query .= " ORDER BY go.created_at DESC LIMIT $offset, $limit";
$stmt = $conn->prepare($query);
$stmt->execute($params);
$owners = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get counts for tabs
$pendingStmt = $conn->prepare("SELECT COUNT(*) FROM gym_owners WHERE is_verified = 1 AND is_approved IS NULL");
$pendingStmt->execute();
$pendingCount = $pendingStmt->fetchColumn();

$approvedStmt = $conn->prepare("SELECT COUNT(*) FROM gym_owners WHERE is_approved = 1");
$approvedStmt->execute();
$approvedCount = $approvedStmt->fetchColumn();

$rejectedStmt = $conn->prepare("SELECT COUNT(*) FROM gym_owners WHERE is_approved = 0");
$rejectedStmt->execute();
$rejectedCount = $rejectedStmt->fetchColumn();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Gym Owners - Admin Dashboard</title>
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
    </style>
</head>
<body class="bg-gray-900 text-white">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="ml-0 lg:ml-64 p-4 sm:p-6 transition-all duration-200">
        <h1 class="text-3xl font-bold mb-6">Gym Owner Applications</h1>
        
        <?php if (isset($_SESSION['success'])): ?>
        <div class="bg-green-500 text-white px-4 py-3 rounded relative mb-6" role="alert">
            <span class="block sm:inline"><?= htmlspecialchars($_SESSION['success']) ?></span>
            <span class="absolute top-0 bottom-0 right-0 px-4 py-3">
                <svg class="fill-current h-6 w-6 text-white" role="button" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><title>Close</title><path d="M14.348 14.849a1.2 1.2 0 0 1-1.697 0L10 11.819l-2.651 3.029a1.2 1.2 0 1 1-1.697-1.697l2.758-3.15-2.759-3.152a1.2 1.2 0 1 1 1.697-1.697L10 8.183l2.651-3.031a1.2 1.2 0 1 1 1.697 1.697l-2.758 3.152 2.758 3.15a1.2 1.2 0 0 1 0 1.698z"/></svg>
            </span>
        </div>
        <?php unset($_SESSION['success']); endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
        <div class="bg-red-500 text-white px-4 py-3 rounded relative mb-6" role="alert">
            <span class="block sm:inline"><?= htmlspecialchars($_SESSION['error']) ?></span>
            <span class="absolute top-0 bottom-0 right-0 px-4 py-3">
                <svg class="fill-current h-6 w-6 text-white" role="button" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><title>Close</title><path d="M14.348 14.849a1.2 1.2 0 0 1-1.697 0L10 11.819l-2.651 3.029a1.2 1.2 0 1 1-1.697-1.697l2.758-3.15-2.759-3.152a1.2 1.2 0 1 1 1.697-1.697L10 8.183l2.651-3.031a1.2 1.2 0 1 1 1.697 1.697l-2.758 3.152 2.758 3.15a1.2 1.2 0 0 1 0 1.698z"/></svg>
            </span>
        </div>
        <?php unset($_SESSION['error']); endif; ?>
        
        <!-- Tabs and Search -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6">
            <div class="flex mb-4 md:mb-0">
                <a href="?status=pending" class="px-4 py-2 rounded-tl-lg rounded-bl-lg <?= $status === 'pending' ? 'bg-yellow-600 text-white' : 'bg-gray-700 text-gray-300 hover:bg-gray-600' ?>">
                    Pending <span class="bg-gray-800 text-white px-2 py-1 rounded-full text-xs"><?= $pendingCount ?></span>
                </a>
                <a href="?status=approved" class="px-4 py-2 <?= $status === 'approved' ? 'bg-green-600 text-white' : 'bg-gray-700 text-gray-300 hover:bg-gray-600' ?>">
                    Approved <span class="bg-gray-800 text-white px-2 py-1 rounded-full text-xs"><?= $approvedCount ?></span>
                </a>
                <a href="?status=rejected" class="px-4 py-2 rounded-tr-lg rounded-br-lg <?= $status === 'rejected' ? 'bg-red-600 text-white' : 'bg-gray-700 text-gray-300 hover:bg-gray-600' ?>">
                    Rejected <span class="bg-gray-800 text-white px-2 py-1 rounded-full text-xs"><?= $rejectedCount ?></span>
                </a>
            </div>
            
            <form class="flex">
                <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search by name, email or phone" class="bg-gray-700 text-white px-4 py-2 rounded-l-lg focus:outline-none focus:ring-2 focus:ring-yellow-500">
                <button type="submit" class="bg-yellow-600 hover:bg-yellow-700 text-white px-4 py-2 rounded-r-lg">
                    <i class="fas fa-search"></i>
                </button>
            </form>
        </div>
        
        <!-- Owners Table -->
        <div class="bg-gray-800 rounded-lg overflow-hidden shadow-lg">
            <table class="min-w-full divide-y divide-gray-700">
                <thead class="bg-gray-700">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Owner</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Contact</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Location</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Registered</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Status</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-700">
                    <?php if (count($owners) > 0): ?>
                        <?php foreach ($owners as $owner): ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0 h-10 w-10">
                                        <?php if (!empty($owner['profile_picture'])): ?>
                                            <img class="h-10 w-10 rounded-full object-cover" src="../<?= htmlspecialchars($owner['profile_picture']) ?>" alt="Profile picture">
                                        <?php else: ?>
                                            <div class="h-10 w-10 rounded-full bg-yellow-600 flex items-center justify-center">
                                                <span class="text-white font-bold"><?= strtoupper(substr($owner['name'], 0, 1)) ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="ml-4">
                                        <div class="text-sm font-medium text-white"><?= htmlspecialchars($owner['name']) ?></div>
                                        <div class="text-sm text-gray-400">ID: <?= $owner['id'] ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-white"><?= htmlspecialchars($owner['email']) ?></div>
                                <div class="text-sm text-gray-400"><?= htmlspecialchars($owner['phone']) ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-white">
                                    <?= htmlspecialchars($owner['city'] . (empty($owner['state']) ? '' : ', ' . $owner['state'])) ?>
                                </div>
                                <div class="text-sm text-gray-400"><?= htmlspecialchars($owner['country']) ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-400">
                                <?= date('M d, Y', strtotime($owner['created_at'])) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php if ($owner['is_approved'] === '1'): ?>
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                        Approved
                                    </span>
                                    <div class="text-xs text-gray-400 mt-1">
                                        by <?= htmlspecialchars($owner['approved_by_name'] ?? 'System') ?>
                                    </div>
                                <?php elseif ($owner['is_approved'] === '0'): ?>
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">
                                        Rejected
                                    </span>
                                    <div class="text-xs text-gray-400 mt-1">
                                        by <?= htmlspecialchars($owner['rejected_by_name'] ?? 'System') ?>
                                    </div>
                                <?php else: ?>
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">
                                        Pending
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <a href="view_owner.php?id=<?= $owner['id'] ?>" class="text-blue-400 hover:text-blue-300 mr-3">
                                    <i class="fas fa-eye"></i> View
                                </a>
                                
                                <?php if ($owner['is_approved'] === null): ?>
                                <button class="text-green-400 hover:text-green-300 mr-3 approve-btn" 
                                        data-id="<?= $owner['id'] ?>" 
                                        data-name="<?= htmlspecialchars($owner['name']) ?>">
                                    <i class="fas fa-check"></i> Approve
                                </button>
                                
                                <button class="text-red-400 hover:text-red-300 reject-btn" 
                                        data-id="<?= $owner['id'] ?>" 
                                        data-name="<?= htmlspecialchars($owner['name']) ?>">
                                    <i class="fas fa-times"></i> Reject
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="px-6 py-4 text-center text-gray-400">
                                No gym owner applications found.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="flex justify-center mt-6">
            <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                <?php if ($page > 1): ?>
                <a href="?page=<?= $page-1 ?>&status=<?= urlencode($status) ?>&search=<?= urlencode($search) ?>" class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-700 bg-gray-800 text-sm font-medium text-gray-400 hover:bg-gray-700">
                    <span class="sr-only">Previous</span>
                    <i class="fas fa-chevron-left"></i>
                </a>
                <?php endif; ?>
                
                <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
                
                if ($startPage > 1) {
                    echo '<a href="?page=1&status=' . urlencode($status) . '&search=' . urlencode($search) . '" class="relative inline-flex items-center px-4 py-2 border border-gray-700 bg-gray-800 text-sm font-medium text-gray-400 hover:bg-gray-700">1</a>';
                    if ($startPage > 2) {
                        echo '<span class="relative inline-flex items-center px-4 py-2 border border-gray-700 bg-gray-800 text-sm font-medium text-gray-400">...</span>';
                    }
                }
                
                for ($i = $startPage; $i <= $endPage; $i++) {
                    echo '<a href="?page=' . $i . '&status=' . urlencode($status) . '&search=' . urlencode($search) . '" class="relative inline-flex items-center px-4 py-2 border border-gray-700 ' . ($page == $i ? 'bg-gray-700 text-white' : 'bg-gray-800 text-gray-400 hover:bg-gray-700') . ' text-sm font-medium">' . $i . '</a>';
                }
                
                if ($endPage < $totalPages) {
                    if ($endPage < $totalPages - 1) {
                        echo '<span class="relative inline-flex items-center px-4 py-2 border border-gray-700 bg-gray-800 text-sm font-medium text-gray-400">...</span>';
                    }
                    echo '<a href="?page=' . $totalPages . '&status=' . urlencode($status) . '&search=' . urlencode($search) . '" class="relative inline-flex items-center px-4 py-2 border border-gray-700 bg-gray-800 text-sm font-medium text-gray-400 hover:bg-gray-700">' . $totalPages . '</a>';
                }
                ?>
                
                <?php if ($page < $totalPages): ?>
                <a href="?page=<?= $page+1 ?>&status=<?= urlencode($status) ?>&search=<?= urlencode($search) ?>" class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-700 bg-gray-800 text-sm font-medium text-gray-400 hover:bg-gray-700">
                    <span class="sr-only">Next</span>
                    <i class="fas fa-chevron-right"></i>
                </a>
                <?php endif; ?>
            </nav>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Approve Modal -->
    <div class="modal opacity-0 pointer-events-none fixed w-full h-full top-0 left-0 flex items-center justify-center z-50">
        <div class="modal-overlay absolute w-full h-full bg-black opacity-75"></div>
        
        <div class="modal-container bg-gray-800 w-11/12 md:max-w-md mx-auto rounded-lg shadow-lg z-50 overflow-y-auto">
            <div class="modal-content py-4 text-left px-6">
                <div class="flex justify-between items-center pb-3">
                    <p class="text-2xl font-bold text-white" id="modal-title">Approve Gym Owner</p>
                    <div class="modal-close cursor-pointer z-50">
                        <i class="fas fa-times text-white"></i>
                    </div>
                </div>
                
                <form id="approval-form" method="POST">
                    <input type="hidden" name="owner_id" id="owner_id">
                    <input type="hidden" name="action" id="action_type">
                    
                    <div class="mb-4">
                        <p class="text-white mb-2" id="modal-message">Are you sure you want to approve this gym owner?</p>
                        
                        <div class="mt-4">
                            <label for="message" class="block text-sm font-medium text-gray-400 mb-2">Message (Optional for approval, required for rejection)</label>
                            <textarea id="message" name="message" rows="3" class="w-full px-3 py-2 text-white bg-gray-700 border border-gray-600 rounded-md focus:outline-none focus:ring-2 focus:ring-yellow-500"></textarea>
                        </div>
                    </div>
                    
                    <div class="flex justify-end pt-2">
                        <button type="button" class="modal-close px-4 py-2 bg-gray-700 text-white rounded-lg mr-2">Cancel</button>
                        <button type="submit" id="confirm-btn" class="px-4 py-2 bg-yellow-600 text-white rounded-lg">Confirm</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        // Modal functionality
        const openModal = (action, id, name) => {
            const modal = document.querySelector('.modal');
            const modalTitle = document.getElementById('modal-title');
            const modalMessage = document.getElementById('modal-message');
            const confirmBtn = document.getElementById('confirm-btn');
            const ownerIdInput = document.getElementById('owner_id');
            const actionTypeInput = document.getElementById('action_type');
            const messageTextarea = document.getElementById('message');
            
            ownerIdInput.value = id;
            actionTypeInput.value = action;
            
            if (action === 'approve') {
                modalTitle.textContent = 'Approve Gym Owner';
                modalMessage.textContent = `Are you sure you want to approve ${name}'s application?`;
                confirmBtn.className = 'px-4 py-2 bg-green-600 text-white rounded-lg';
                messageTextarea.required = false;
            } else {
                modalTitle.textContent = 'Reject Gym Owner';
                modalMessage.textContent = `Are you sure you want to reject ${name}'s application?`;
                confirmBtn.className = 'px-4 py-2 bg-red-600 text-white rounded-lg';
                messageTextarea.required = true;
            }
            
            modal.classList.remove('opacity-0', 'pointer-events-none');
            document.body.classList.add('modal-active');
        };
        
        const closeModal = () => {
            const modal = document.querySelector('.modal');
            modal.classList.add('opacity-0', 'pointer-events-none');
            document.body.classList.remove('modal-active');
        };
        
        // Add event listeners
        document.addEventListener('DOMContentLoaded', () => {
            // Approve buttons
            document.querySelectorAll('.approve-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    const id = btn.getAttribute('data-id');
                    const name = btn.getAttribute('data-name');
                    openModal('approve', id, name);
                });
            });
            
            // Reject buttons
            document.querySelectorAll('.reject-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    const id = btn.getAttribute('data-id');
                    const name = btn.getAttribute('data-name');
                    openModal('reject', id, name);
                });
            });
            
            // Close modal buttons
            document.querySelectorAll('.modal-close').forEach(btn => {
                btn.addEventListener('click', closeModal);
            });
            
            // Close modal when clicking outside
            document.querySelector('.modal-overlay').addEventListener('click', closeModal);
            
                       // Form validation
                       document.getElementById('approval-form').addEventListener('submit', (e) => {
                const action = document.getElementById('action_type').value;
                const message = document.getElementById('message').value.trim();
                
                if (action === 'reject' && message === '') {
                    e.preventDefault();
                    alert('Please provide a reason for rejection.');
                }
            });
            
            // Close alert messages after 5 seconds
            const alerts = document.querySelectorAll('.bg-green-500, .bg-red-500');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.transition = 'opacity 1s ease-out';
                    alert.style.opacity = '0';
                    setTimeout(() => {
                        alert.remove();
                    }, 1000);
                }, 5000);
            });
        });
    </script>
</body>
</html>

