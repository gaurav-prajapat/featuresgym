<?php
ob_start();
require_once '../config/database.php';
include '../includes/navbar.php';

// Ensure user is authenticated and has admin role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['error'] = "You don't have permission to access this page.";
    header('Location: ../login.php');
    exit();
}

// CSRF protection
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$db = new GymDatabase();
$conn = $db->getConnection();

// Handle owner status changes
if (isset($_POST['update_status']) && isset($_POST['csrf_token'])) {
    if ($_POST['csrf_token'] === $_SESSION['csrf_token']) {
        $owner_id = filter_input(INPUT_POST, 'owner_id', FILTER_SANITIZE_NUMBER_INT);
        $new_status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);
        
        $allowed_statuses = ['active', 'inactive', 'suspended'];
        
        if ($owner_id && in_array($new_status, $allowed_statuses)) {
            try {
                $query = "UPDATE gym_owners SET status = :status WHERE id = :id";
                $stmt = $conn->prepare($query);
                $stmt->execute([
                    ':status' => $new_status,
                    ':id' => $owner_id
                ]);
                
                // Log the activity
                $log_query = "INSERT INTO activity_logs (user_id, user_type, action, details, ip_address, user_agent) 
                             VALUES (:user_id, 'admin', 'update_owner_status', :details, :ip, :user_agent)";
                $log_stmt = $conn->prepare($log_query);
                $log_stmt->execute([
                    ':user_id' => $_SESSION['user_id'],
                    ':details' => "Updated owner ID: $owner_id status to $new_status",
                    ':ip' => $_SERVER['REMOTE_ADDR'],
                    ':user_agent' => $_SERVER['HTTP_USER_AGENT']
                ]);
                
                $_SESSION['success'] = "Owner status updated successfully!";
            } catch (PDOException $e) {
                $_SESSION['error'] = "Failed to update owner status: " . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = "Invalid owner ID or status.";
        }
        
        header("Location: gym-owners.php");
        exit;
    } else {
        $_SESSION['error'] = "CSRF token validation failed.";
    }
}

// Handle owner approval
if (isset($_POST['approve_owner']) && isset($_POST['csrf_token'])) {
    if ($_POST['csrf_token'] === $_SESSION['csrf_token']) {
        $owner_id = filter_input(INPUT_POST, 'owner_id', FILTER_SANITIZE_NUMBER_INT);
        
        if ($owner_id) {
            try {
                $query = "UPDATE gym_owners SET is_approved = 1 WHERE id = :id";
                $stmt = $conn->prepare($query);
                $stmt->execute([':id' => $owner_id]);
                
                // Get owner email for notification
                $email_query = "SELECT email, name FROM gym_owners WHERE id = :id";
                $email_stmt = $conn->prepare($email_query);
                $email_stmt->execute([':id' => $owner_id]);
                $owner = $email_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($owner) {
                    // Add notification for the owner
                    $notif_query = "INSERT INTO notifications (user_id, type, title, message, gym_id, created_at) 
                                   VALUES (:user_id, 'account_approval', 'Account Approved', 
                                   'Your gym owner account has been approved. You can now add and manage your gyms.', 
                                   0, NOW())";
                    $notif_stmt = $conn->prepare($notif_query);
                    $notif_stmt->execute([':user_id' => $owner_id]);
                    
                    // Log the activity
                    $log_query = "INSERT INTO activity_logs (user_id, user_type, action, details, ip_address, user_agent) 
                                 VALUES (:user_id, 'admin', 'approve_owner', :details, :ip, :user_agent)";
                    $log_stmt = $conn->prepare($log_query);
                    $log_stmt->execute([
                        ':user_id' => $_SESSION['user_id'],
                        ':details' => "Approved owner ID: $owner_id ({$owner['name']})",
                        ':ip' => $_SERVER['REMOTE_ADDR'],
                        ':user_agent' => $_SERVER['HTTP_USER_AGENT']
                    ]);
                }
                
                $_SESSION['success'] = "Owner approved successfully!";
            } catch (PDOException $e) {
                $_SESSION['error'] = "Failed to approve owner: " . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = "Invalid owner ID.";
        }
        
        header("Location: gym-owners.php");
        exit;
    } else {
        $_SESSION['error'] = "CSRF token validation failed.";
    }
}

// Fetch all gym owners with their gyms and additional information
$stmt = $conn->prepare("
    SELECT 
        go.*, 
        COALESCE(SUM(g.balance), 0) as total_balance,
        COUNT(g.gym_id) as total_gyms,
        GROUP_CONCAT(g.name SEPARATOR ', ') as gym_names,
        (SELECT COUNT(*) FROM login_history WHERE user_id = go.id AND user_type = 'gym_owner') as login_count,
        (SELECT login_time FROM login_history WHERE user_id = go.id AND user_type = 'gym_owner' ORDER BY login_time DESC LIMIT 1) as last_login
    FROM gym_owners go
    LEFT JOIN gyms g ON go.id = g.owner_id
    GROUP BY go.id
    ORDER BY go.created_at DESC
");
$stmt->execute();
$owners = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get owner count by status for dashboard stats
$status_query = "SELECT status, COUNT(*) as count FROM gym_owners GROUP BY status";
$status_stmt = $conn->prepare($status_query);
$status_stmt->execute();
$owner_status_counts = $status_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Get account type distribution
$account_query = "SELECT account_type, COUNT(*) as count FROM gym_owners GROUP BY account_type";
$account_stmt = $conn->prepare($account_query);
$account_stmt->execute();
$account_type_counts = $account_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

?>

<div class="mx-auto px-4 py-12 pt-10">
    <!-- Display success/error messages -->
    <?php if (isset($_SESSION['success'])): ?>
        <div class="bg-green-500 text-white p-4 rounded-lg mb-6 flex items-center justify-between">
            <div>
                <i class="fas fa-check-circle mr-2"></i>
                <?= htmlspecialchars($_SESSION['success']) ?>
            </div>
            <button class="text-white focus:outline-none" onclick="this.parentElement.style.display='none'">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error'])): ?>
        <div class="bg-red-500 text-white p-4 rounded-lg mb-6 flex items-center justify-between">
            <div>
                <i class="fas fa-exclamation-circle mr-2"></i>
                <?= htmlspecialchars($_SESSION['error']) ?>
            </div>
            <button class="text-white focus:outline-none" onclick="this.parentElement.style.display='none'">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <div class="bg-gray-800 rounded-lg shadow-lg overflow-hidden">
        <div class="p-6">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-6">
                <h1 class="text-3xl font-bold text-white mb-4 md:mb-0">Gym Owners</h1>
                <div class="flex flex-col sm:flex-row gap-3">
                    <a href="add-owner.php" class="bg-yellow-500 hover:bg-yellow-600 text-black font-medium py-2 px-4 rounded-lg transition-colors duration-200 flex items-center justify-center">
                        <i class="fas fa-plus mr-2"></i>Add New Owner
                    </a>
                    <a href="manage_gym.php" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-lg transition-colors duration-200 flex items-center justify-center">
                        <i class="fas fa-dumbbell mr-2"></i>Manage Gyms
                    </a>
                </div>
            </div>
            
            <!-- Stats Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <div class="bg-gray-700 rounded-lg p-4 shadow">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-blue-500 bg-opacity-25 text-blue-500 mr-4">
                            <i class="fas fa-users text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-gray-400 text-sm">Total Owners</p>
                            <p class="text-white text-xl font-bold"><?= count($owners) ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-gray-700 rounded-lg p-4 shadow">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-purple-500 bg-opacity-25 text-green-500 mr-4">
                            <i class="fas fa-check-circle text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-gray-400 text-sm">Active</p>
                            <p class="text-white text-xl font-bold"><?= $owner_status_counts['active'] ?? 0 ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-gray-700 rounded-lg p-4 shadow">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-yellow-300 text-yellow-500 mr-4">
                            <i class="fas fa-crown text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-gray-400 text-sm">Premium</p>
                            <p class="text-white text-xl font-bold"><?= $account_type_counts['premium'] ?? 0 ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-gray-700 rounded-lg p-4 shadow">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-purple-500 bg-opacity-25 text-purple-500 mr-4">
                            <i class="fas fa-dumbbell text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-gray-400 text-sm">Total Gyms</p>
                            <p class="text-white text-xl font-bold">
                                <?= array_sum(array_column($owners, 'total_gyms')) ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Search and Filter -->
            <div class="mb-6">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                    <div class="relative w-full md:w-64">
                        <input type="text" id="searchInput" placeholder="Search owners..." 
                               class="border border-gray-600 bg-gray-700 text-white rounded-lg pl-10 pr-4 py-2 w-full focus:outline-none focus:ring-2 focus:ring-yellow-500">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-search text-gray-400"></i>
                        </div>
                    </div>
                    
                    <div class="flex flex-wrap gap-2">
                        <select id="statusFilter" class="border border-gray-600 bg-gray-700 text-white rounded-lg p-2 focus:outline-none focus:ring-2 focus:ring-yellow-500">
                            <option value="">All Statuses</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="suspended">Suspended</option>
                        </select>
                        
                        <select id="accountTypeFilter" class="border border-gray-600 bg-gray-700 text-white rounded-lg p-2 focus:outline-none focus:ring-2 focus:ring-yellow-500">
                            <option value="">All Account Types</option>
                            <option value="basic">Basic</option>
                            <option value="premium">Premium</option>
                        </select>
                        
                        <select id="approvalFilter" class="border border-gray-600 bg-gray-700 text-white rounded-lg p-2 focus:outline-none focus:ring-2 focus:ring-yellow-500">
                            <option value="">All Approval Status</option>
                            <option value="approved">Approved</option>
                            <option value="pending">Pending Approval</option>
                        </select>
                    </div>
                </div>
            </div>
            
            <!-- Owners Table -->
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-700" id="ownersTable">
                    <thead>
                        <tr class="bg-gray-700">
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Owner</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Contact</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Owner</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Gyms</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Balance</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-700">
                        <?php if (count($owners) > 0): ?>
                            <?php foreach ($owners as $owner): ?>
                                <tr class="hover:bg-gray-700 transition-colors duration-150">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <?php if (!empty($owner['profile_picture'])): ?>
                                                <img src="../<?= htmlspecialchars($owner['profile_picture']) ?>" alt="<?= htmlspecialchars($owner['name']) ?>" class="h-10 w-10 rounded-full object-cover mr-3">
                                            <?php else: ?>
                                                <div class="h-10 w-10 rounded-full bg-gray-600 flex items-center justify-center mr-3">
                                                    <i class="fas fa-user text-yellow-400"></i>
                                                </div>
                                            <?php endif; ?>
                                            <div>
                                                <div class="text-sm font-medium text-white">
                                                    <?= htmlspecialchars($owner['name']) ?>
                                                    <?php if ($owner['account_type'] === 'premium'): ?>
                                                        <span class="ml-1 text-xs bg-yellow-500 text-black px-1.5 py-0.5 rounded-full">Premium</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="text-xs text-gray-400">
                                                    Joined: <?= date('M d, Y', strtotime($owner['created_at'])) ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-white"><?= htmlspecialchars($owner['email']) ?></div>
                                        <div class="text-xs text-gray-400"><?= htmlspecialchars($owner['phone']) ?></div>
                                        <?php if (!empty($owner['last_login'])): ?>
                                            <div class="text-xs text-gray-500 mt-1">
                                                Last login: <?= date('M d, Y H:i', strtotime($owner['last_login'])) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-white">
                                            <?= htmlspecialchars($owner['city'] . ', ' . $owner['state']) ?>
                                        </div>
                                        <div class="text-xs text-gray-400"><?= htmlspecialchars($owner['country']) ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex flex-col space-y-2">
                                            <?php 
                                            $statusClasses = [
                                                'active' => 'bg-green-100 text-green-800',
                                                'inactive' => 'bg-gray-100 text-gray-800',
                                                'suspended' => 'bg-red-100 text-red-800'
                                            ];
                                            $statusClass = $statusClasses[$owner['status']] ?? 'bg-gray-100 text-gray-800';
                                            ?>
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?= $statusClass ?>">
                                                <?= ucfirst(htmlspecialchars($owner['status'])) ?>
                                            </span>
                                            
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                                <?= $owner['is_approved'] ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' ?>">
                                                <?= $owner['is_approved'] ? 'Approved' : 'Pending Approval' ?>
                                            </span>
                                            
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                                <?= $owner['is_verified'] ? 'bg-blue-100 text-blue-800' : 'bg-red-100 text-red-800' ?>">
                                                <?= $owner['is_verified'] ? 'Verified' : 'Unverified' ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-white"><?= $owner['total_gyms'] ?> gyms</div>
                                        <?php if ($owner['gym_names']): ?>
                                            <div class="text-xs text-gray-400 max-w-xs truncate" title="<?= htmlspecialchars($owner['gym_names']) ?>">
                                                <?= htmlspecialchars($owner['gym_names']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-white">
                                            ₹<?= number_format($owner['total_balance'], 2) ?>
                                        </div>
                                        <?php if ($owner['total_balance'] > 0): ?>
                                            <a href="process_payout.php?owner_id=<?= $owner['id'] ?>" class="text-xs text-yellow-400 hover:text-yellow-300">
                                                Process Payout
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <div class="flex flex-wrap gap-2">
                                            <a href="view-owner.php?id=<?= $owner['id'] ?>" class="text-blue-400 hover:text-blue-300" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            
                                            <!-- <a href="edit-owner.php?id=<?= $owner['id'] ?>" class="text-indigo-400 hover:text-indigo-300" title="Edit Owner">
                                                <i class="fas fa-edit"></i>
                                            </a> -->
                                            
                                            <?php if (!$owner['is_approved']): ?>
                                                <button type="button" class="text-green-400 hover:text-green-300 approve-btn" 
                                                        data-owner-id="<?= $owner['id'] ?>" 
                                                        data-owner-name="<?= htmlspecialchars($owner['name']) ?>"
                                                        title="Approve Owner">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                            <?php endif; ?>
                                            
                                            <button type="button" class="text-yellow-400 hover:text-yellow-300 status-btn" 
                                                    data-owner-id="<?= $owner['id'] ?>" 
                                                    data-owner-name="<?= htmlspecialchars($owner['name']) ?>"
                                                    data-current-status="<?= htmlspecialchars($owner['status']) ?>"
                                                    title="Change Status">
                                                <i class="fas fa-exchange-alt"></i>
                                            </button>
                                            
                                            <!-- <a href="owner-gyms.php?owner_id=<?= $owner['id'] ?>" class="text-purple-400 hover:text-purple-300" title="View Gyms">
                                                <i class="fas fa-dumbbell"></i>
                                            </a>
                                            
                                            <a href="owner-transactions.php?owner_id=<?= $owner['id'] ?>" class="text-green-400 hover:text-green-300" title="View Transactions">
                                                <i class="fas fa-money-bill-wave"></i>
                                            </a> -->
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="px-6 py-4 text-center text-gray-400">
                                    No gym owners found. <a href="add-owner.php" class="text-yellow-400 hover:underline">Add a new owner</a>.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Status Change Modal -->
<div id="statusModal" class="fixed inset-0 bg-black bg-opacity-75 flex items-center justify-center z-50 hidden">
    <div class="bg-gray-800 rounded-lg max-w-md w-full mx-4">
        <div class="p-6">
            <h3 class="text-xl font-semibold text-white mb-4">Change Owner Status</h3>
            <p class="text-gray-300 mb-4">Update the status for <span id="ownerNameToUpdate" class="font-semibold text-yellow-400"></span>:</p>
            
            <form id="statusForm" method="POST" action="gym-owners.php">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="update_status" value="1">
                <input type="hidden" id="ownerIdToUpdate" name="owner_id" value="">
                
                <div class="mb-4">
                    <select id="newStatus" name="status" class="border border-gray-600 bg-gray-700 text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-yellow-500">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                        <option value="suspended">Suspended</option>
                    </select>
                </div>
                
                <div class="flex justify-end space-x-3">
                    <button type="button" id="cancelStatus" class="bg-gray-600 hover:bg-gray-700 text-white font-medium py-2 px-4 rounded-lg transition-colors duration-200">
                        Cancel
                    </button>
                    <button type="submit" class="bg-yellow-500 hover:bg-yellow-600 text-black font-medium py-2 px-4 rounded-lg transition-colors duration-200">
                        Update Status
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>


<!-- Approve Owner Modal -->
<div id="approveModal" class="fixed inset-0 bg-black bg-opacity-75 flex items-center justify-center z-50 hidden">
    <div class="bg-gray-800 rounded-lg max-w-md w-full mx-4">
        <div class="p-6">
            <h3 class="text-xl font-semibold text-white mb-4">Approve Owner</h3>
            <p class="text-gray-300 mb-6">Are you sure you want to approve <span id="ownerNameToApprove" class="font-semibold text-yellow-400"></span>? This will grant them access to create and manage gyms.</p>
            
            <form id="approveForm" method="POST" action="gym-owners.php">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="approve_owner" value="1">
                <input type="hidden" id="ownerIdToApprove" name="owner_id" value="">
                
                <div class="flex justify-end space-x-3">
                    <button type="button" id="cancelApprove" class="bg-gray-600 hover:bg-gray-700 text-white font-medium py-2 px-4 rounded-lg transition-colors duration-200">
                        Cancel
                    </button>
                    <button type="submit" class="bg-green-500 hover:bg-green-600 text-white font-medium py-2 px-4 rounded-lg transition-colors duration-200">
                        Approve Owner
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
    // Status modal functionality
const statusModal = document.getElementById('statusModal');
const statusButtons = document.querySelectorAll('.status-btn');
const cancelStatus = document.getElementById('cancelStatus');
const ownerNameToUpdate = document.getElementById('ownerNameToUpdate');
const ownerIdToUpdate = document.getElementById('ownerIdToUpdate');
const newStatus = document.getElementById('newStatus');

statusButtons.forEach(button => {
    button.addEventListener('click', function() {
        const ownerId = this.getAttribute('data-owner-id');
        const ownerName = this.getAttribute('data-owner-name');
        const currentStatus = this.getAttribute('data-current-status');
        
        ownerNameToUpdate.textContent = ownerName;
        ownerIdToUpdate.value = ownerId;
        newStatus.value = currentStatus;
        statusModal.classList.remove('hidden');
    });
});

if (cancelStatus) {
    cancelStatus.addEventListener('click', function() {
        statusModal.classList.add('hidden');
    });
}
        
        // Approve modal functionality
        const approveModal = document.getElementById('approveModal');
        const approveButtons = document.querySelectorAll('.approve-btn');
        const cancelApprove = document.getElementById('cancelApprove');
        const ownerNameToApprove = document.getElementById('ownerNameToApprove');
        const ownerIdToApprove = document.getElementById('ownerIdToApprove');
        
        approveButtons.forEach(button => {
            button.addEventListener('click', function() {
                const ownerId = this.getAttribute('data-owner-id');
                const ownerName = this.getAttribute('data-owner-name');
                
                ownerNameToApprove.textContent = ownerName;
                ownerIdToApprove.value = ownerId;
                approveModal.classList.remove('hidden');
            });
        });
        
        if (cancelApprove) {
            cancelApprove.addEventListener('click', function() {
                approveModal.classList.add('hidden');
            });
        }
        
        // Close modals when clicking outside
        window.addEventListener('click', function(event) {
            if (event.target === statusModal) {
                statusModal.classList.add('hidden');
            }
            if (event.target === approveModal) {
                approveModal.classList.add('hidden');
            }
        });
        
        // Search functionality
        const searchInput = document.getElementById('searchInput');
        const ownersTable = document.getElementById('ownersTable');
        const tableRows = ownersTable.querySelectorAll('tbody tr');
        
        searchInput.addEventListener('keyup', function() {
            const searchTerm = this.value.toLowerCase();
            
            tableRows.forEach(row => {
                const ownerName = row.querySelector('td:first-child').textContent.toLowerCase();
                const contactInfo = row.querySelector('td:nth-child(2)').textContent.toLowerCase();
                const location = row.querySelector('td:nth-child(3)').textContent.toLowerCase();
                
                if (ownerName.includes(searchTerm) || contactInfo.includes(searchTerm) || location.includes(searchTerm)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
        
        // Status filter
        const statusFilter = document.getElementById('statusFilter');
        
        statusFilter.addEventListener('change', function() {
            const selectedStatus = this.value.toLowerCase();
            
            tableRows.forEach(row => {
                const status = row.querySelector('td:nth-child(4)').textContent.toLowerCase();
                
                if (selectedStatus === '' || status.includes(selectedStatus)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
        
        // Account type filter
        const accountTypeFilter = document.getElementById('accountTypeFilter');
        
        accountTypeFilter.addEventListener('change', function() {
            const selectedType = this.value.toLowerCase();
            
            tableRows.forEach(row => {
                const ownerInfo = row.querySelector('td:first-child').textContent.toLowerCase();
                const isPremium = ownerInfo.includes('premium');
                
                if (selectedType === '' || 
                    (selectedType === 'premium' && isPremium) || 
                    (selectedType === 'basic' && !isPremium)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
        
        // Approval filter
        const approvalFilter = document.getElementById('approvalFilter');
        
        approvalFilter.addEventListener('change', function() {
            const selectedApproval = this.value.toLowerCase();
            
            tableRows.forEach(row => {
                const status = row.querySelector('td:nth-child(4)').textContent.toLowerCase();
                const isApproved = status.includes('approved') && !status.includes('pending');
                
                if (selectedApproval === '' || 
                    (selectedApproval === 'approved' && isApproved) || 
                    (selectedApproval === 'pending' && status.includes('pending approval'))) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
        
        // Responsive table handling for mobile
        function adjustTableForMobile() {
            const table = document.getElementById('ownersTable');
            const windowWidth = window.innerWidth;
            
            if (windowWidth < 768) {
                // Add data attributes for mobile labels
                table.querySelectorAll('tbody tr').forEach(row => {
                    const cells = row.querySelectorAll('td');
                    const headers = Array.from(table.querySelectorAll('th')).map(th => th.textContent.trim());
                    
                    cells.forEach((cell, index) => {
                        if (headers[index]) {
                            cell.setAttribute('data-label', headers[index]);
                        }
                    });
                });
                
                table.classList.add('mobile-responsive');
            } else {
                table.classList.remove('mobile-responsive');
            }
        }
        
        // Call on load and resize
        adjustTableForMobile();
        window.addEventListener('resize', adjustTableForMobile);
    });
</script>

<style>
    /* Responsive table styles for mobile */
    @media (max-width: 767px) {
        .mobile-responsive thead {
            display: none;
        }
        
        .mobile-responsive tbody tr {
            display: block;
            margin-bottom: 1rem;
            border: 1px solid rgba(75, 85, 99, 0.5);
            border-radius: 0.5rem;
            padding: 0.5rem;
        }
        
        .mobile-responsive td {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem !important;
            text-align: right;
            border-bottom: 1px solid rgba(75, 85, 99, 0.2);
        }
        
        .mobile-responsive td:last-child {
            border-bottom: none;
        }
        
        .mobile-responsive td::before {
            content: attr(data-label);
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.75rem;
            color: rgba(156, 163, 175, 1);
        }
        
        .mobile-responsive td:first-child {
            display: block;
            text-align: center;
            padding: 1rem !important;
        }
        
        .mobile-responsive td:first-child::before {
            display: none;
        }
        
        .mobile-responsive td .flex {
            margin-left: auto;
        }
    }
    
    /* Tooltip styles */
    [title] {
        position: relative;
        cursor: help;
    }
    
    [title]:hover::after {
        content: attr(title);
        position: absolute;
        bottom: 100%;
        left: 50%;
        transform: translateX(-50%);
        background-color: rgba(31, 41, 55, 0.95);
        color: white;
        padding: 0.5rem;
        border-radius: 0.25rem;
        font-size: 0.75rem;
        white-space: nowrap;
        z-index: 10;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
    }
</style>




