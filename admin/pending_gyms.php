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

// Process gym approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['approve_gym'])) {
        $gymId = (int)$_POST['gym_id'];
        
        // Update gym status to active
        $updateSql = "UPDATE gyms SET status = 'active' WHERE gym_id = ?";
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->execute([$gymId]);
        
        // Get gym and owner details for notification
        $gymSql = "SELECT g.name, g.owner_id, u.email FROM gyms g JOIN users u ON g.owner_id = u.id WHERE g.gym_id = ?";
        $gymStmt = $conn->prepare($gymSql);
        $gymStmt->execute([$gymId]);
        $gymData = $gymStmt->fetch(PDO::FETCH_ASSOC);
        
        // Send notification to gym owner (email would be implemented here)
        // For now, just log the activity
        
        // Log the activity
        $activitySql = "INSERT INTO activity_logs (user_id, user_type, action, details, ip_address, user_agent) 
                        VALUES (?, 'admin', 'approve_gym', ?, ?, ?)";
        $activityStmt = $conn->prepare($activitySql);
        $activityStmt->execute([
            $_SESSION['admin_id'],
            "Approved gym (ID: $gymId) - " . $gymData['name'],
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT']
        ]);
        
        $_SESSION['success'] = "Gym has been approved successfully.";
        header('Location: pending_gyms.php');
        exit();
    }
    
    if (isset($_POST['reject_gym'])) {
        $gymId = (int)$_POST['gym_id'];
        $rejectionReason = trim($_POST['rejection_reason']);
        
        if (empty($rejectionReason)) {
            $_SESSION['error'] = "Please provide a reason for rejection.";
            header('Location: pending_gyms.php');
            exit();
        }
        
        // Update gym status to rejected
        $updateSql = "UPDATE gyms SET status = 'rejected', rejection_reason = ? WHERE gym_id = ?";
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->execute([$rejectionReason, $gymId]);
        
        // Get gym and owner details for notification
        $gymSql = "SELECT g.name, g.owner_id, u.email FROM gyms g JOIN users u ON g.owner_id = u.id WHERE g.gym_id = ?";
        $gymStmt = $conn->prepare($gymSql);
        $gymStmt->execute([$gymId]);
        $gymData = $gymStmt->fetch(PDO::FETCH_ASSOC);
        
        // Send notification to gym owner (email would be implemented here)
        // For now, just log the activity
        
        // Log the activity
        $activitySql = "INSERT INTO activity_logs (user_id, user_type, action, details, ip_address, user_agent) 
                        VALUES (?, 'admin', 'reject_gym', ?, ?, ?)";
        $activityStmt = $conn->prepare($activitySql);
        $activityStmt->execute([
            $_SESSION['admin_id'],
            "Rejected gym (ID: $gymId) - " . $gymData['name'] . ". Reason: " . $rejectionReason,
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT']
        ]);
        
        $_SESSION['success'] = "Gym has been rejected successfully.";
        header('Location: pending_gyms.php');
        exit();
    }
}

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Get total count of pending gyms
$countSql = "SELECT COUNT(*) FROM gyms WHERE status = 'pending'";
$countStmt = $conn->prepare($countSql);
$countStmt->execute();
$totalGyms = $countStmt->fetchColumn();
$totalPages = ceil($totalGyms / $limit);

// Ensure page is within valid range
if ($page < 1) $page = 1;
if ($page > $totalPages && $totalPages > 0) $page = $totalPages;

// Calculate pagination values
$prevPage = $page - 1;
$nextPage = $page + 1;

// Fetch pending gyms with owner information
$sql = "SELECT g.*, 
        u.username as owner_name, 
        u.email as owner_email,
        u.phone as owner_phone,
        (SELECT COUNT(*) FROM gym_membership_plans WHERE gym_id = g.gym_id) as plan_count
        FROM gyms g
        LEFT JOIN users u ON g.owner_id = u.id
        WHERE g.status = 'pending'
        ORDER BY g.created_at DESC
        LIMIT ?, ?";

$stmt = $conn->prepare($sql);
$stmt->bindValue(1, $offset, PDO::PARAM_INT);
$stmt->bindValue(2, $limit, PDO::PARAM_INT);
$stmt->execute();
$pendingGyms = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper function for pagination URLs
function getPaginationUrl($page) {
    $queryParams = $_GET;
    $queryParams['page'] = $page;
    return 'pending_gyms.php?' . http_build_query($queryParams);
}

// Format date
function formatDate($date) {
    return date('M d, Y', strtotime($date));
}

// Format time
function formatTime($time) {
    return date('g:i A', strtotime($time));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Gym Approvals - FlexFit Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .modal {
            transition: opacity 0.25s ease;
        }
        .modal-active {
            overflow-y: hidden;
        }
    </style>
</head>
<body class="bg-gray-900 text-white">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="ml-0 lg:ml-64 p-4 sm:p-6 transition-all duration-200">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold">Pending Gym Approvals</h1>
            <a href="gyms.php" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg transition-colors duration-200 flex items-center">
                <i class="fas fa-dumbbell mr-2"></i> All Gyms
            </a>
        </div>
        
        <!-- Alerts -->
        <?php if (isset($_SESSION['success'])): ?>
            <div class="bg-green-600 text-white p-4 rounded-lg mb-6 flex items-center justify-between">
                <div class="flex items-center">
                    <i class="fas fa-check-circle mr-2"></i>
                    <span><?php echo $_SESSION['success']; ?></span>
                </div>
                <button class="text-white" onclick="this.parentElement.style.display='none'">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="bg-red-600 text-white p-4 rounded-lg mb-6 flex items-center justify-between">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    <span><?php echo $_SESSION['error']; ?></span>
                </div>
                <button class="text-white" onclick="this.parentElement.style.display='none'">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>
        
        <!-- Pending Gyms List -->
        <div class="bg-gray-800 rounded-xl shadow-lg overflow-hidden">
            <div class="p-6 border-b border-gray-700">
                <div class="flex justify-between items-center">
                    <div>
                        <h2 class="text-xl font-semibold">Pending Approvals</h2>
                        <p class="text-gray-400 mt-1">
                            Showing <?php echo min($totalGyms, $offset + 1); ?> - <?php echo min($totalGyms, $offset + count($pendingGyms)); ?> of <?php echo $totalGyms; ?> pending gyms
                        </p>
                    </div>
                </div>
            </div>
            
            <?php if (empty($pendingGyms)): ?>
                <div class="p-6 text-center text-gray-500">
                    <i class="fas fa-check-circle text-4xl mb-3"></i>
                    <p>No pending gym approvals at this time.</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-700">
                        <thead>
                            <tr>
                                <th class="px-6 py-3 bg-gray-700 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Gym</th>
                                <th class="px-6 py-3 bg-gray-700 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Owner</th>
                                <th class="px-6 py-3 bg-gray-700 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Location</th>
                                <th class="px-6 py-3 bg-gray-700 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Submitted</th>
                                <th class="px-6 py-3 bg-gray-700 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Plans</th>
                                <th class="px-6 py-3 bg-gray-700 text-right text-xs font-medium text-gray-300 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-700">
                            <?php foreach ($pendingGyms as $gym): ?>
                                <tr class="hover:bg-gray-700">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="h-10 w-10 flex-shrink-0 mr-3">
                                                <img class="h-10 w-10 rounded-full object-cover" 
                                                     src="../uploads/gym_images/<?php echo htmlspecialchars($gym['cover_photo'] ?? 'default_gym.jpg'); ?>" 
                                                     alt="<?php echo htmlspecialchars($gym['name']); ?>">
                                            </div>
                                            <div>
                                                <div class="font-medium"><?php echo htmlspecialchars($gym['name']); ?></div>
                                                <div class="text-sm text-gray-400"><?php echo htmlspecialchars($gym['email']); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="font-medium"><?php echo htmlspecialchars($gym['owner_name'] ?? 'N/A'); ?></div>
                                        <div class="text-sm text-gray-400"><?php echo htmlspecialchars($gym['owner_email'] ?? 'N/A'); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="font-medium"><?php echo htmlspecialchars($gym['city']); ?></div>
                                        <div class="text-sm text-gray-400"><?php echo htmlspecialchars($gym['state']); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="font-medium"><?php echo formatDate($gym['created_at']); ?></div>
                                        <div class="text-sm text-gray-400"><?php echo formatTime($gym['created_at']); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="font-medium"><?php echo $gym['plan_count']; ?> plans</div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right">
                                        <div class="flex justify-end space-x-2">
                                            <a href="view_gym.php?id=<?php echo $gym['gym_id']; ?>" class="bg-blue-600 hover:bg-blue-700 text-white text-sm px-3 py-1 rounded-lg transition-colors duration-200">
                                                <i class="fas fa-eye mr-1"></i> View
                                            </a>
                                            
                                            <button class="approve-btn bg-green-600 hover:bg-green-700 text-white text-sm px-3 py-1 rounded-lg transition-colors duration-200"
                                                    data-id="<?php echo $gym['gym_id']; ?>"
                                                    data-name="<?php echo htmlspecialchars($gym['name']); ?>">
                                                <i class="fas fa-check mr-1"></i> Approve
                                            </button>
                                            
                                            <button class="reject-btn bg-red-600 hover:bg-red-700 text-white text-sm px-3 py-1 rounded-lg transition-colors duration-200"
                                            data-id="<?php echo $gym['gym_id']; ?>"
                                                    data-name="<?php echo htmlspecialchars($gym['name']); ?>">
                                                <i class="fas fa-times mr-1"></i> Reject
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="px-6 py-4 bg-gray-700 border-t border-gray-600">
                        <div class="flex justify-between items-center">
                            <div>
                                <span class="text-sm text-gray-400">
                                    Page <?php echo $page; ?> of <?php echo $totalPages; ?>
                                </span>
                            </div>
                            <div class="flex space-x-2">
                                <?php if ($page > 1): ?>
                                    <a href="<?php echo getPaginationUrl(1); ?>" class="bg-gray-600 hover:bg-gray-500 text-white px-3 py-1 rounded-lg text-sm">
                                        <i class="fas fa-angle-double-left"></i>
                                    </a>
                                    <a href="<?php echo getPaginationUrl($prevPage); ?>" class="bg-gray-600 hover:bg-gray-500 text-white px-3 py-1 rounded-lg text-sm">
                                        <i class="fas fa-angle-left"></i>
                                    </a>
                                <?php endif; ?>
                                
                                <?php
                                // Show limited page numbers with current page in the middle
                                $startPage = max(1, $page - 2);
                                $endPage = min($totalPages, $page + 2);
                                
                                // Ensure we always show 5 pages when possible
                                if ($endPage - $startPage < 4) {
                                    if ($startPage == 1) {
                                        $endPage = min($totalPages, $startPage + 4);
                                    } elseif ($endPage == $totalPages) {
                                        $startPage = max(1, $endPage - 4);
                                    }
                                }
                                
                                for ($i = $startPage; $i <= $endPage; $i++):
                                ?>
                                    <a href="<?php echo getPaginationUrl($i); ?>" class="<?php echo $i == $page ? 'bg-blue-600 text-white' : 'bg-gray-600 hover:bg-gray-500 text-white'; ?> px-3 py-1 rounded-lg text-sm">
                                        <?php echo $i; ?>
                                    </a>
                                <?php endfor; ?>
                                
                                <?php if ($page < $totalPages): ?>
                                    <a href="<?php echo getPaginationUrl($nextPage); ?>" class="bg-gray-600 hover:bg-gray-500 text-white px-3 py-1 rounded-lg text-sm">
                                        <i class="fas fa-angle-right"></i>
                                    </a>
                                    <a href="<?php echo getPaginationUrl($totalPages); ?>" class="bg-gray-600 hover:bg-gray-500 text-white px-3 py-1 rounded-lg text-sm">
                                        <i class="fas fa-angle-double-right"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Approve Gym Modal -->
    <div id="approve-modal" class="modal fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center hidden">
        <div class="bg-gray-800 rounded-xl max-w-md w-full mx-4 overflow-hidden">
            <div class="bg-gray-700 px-6 py-4 border-b border-gray-600">
                <h3 class="text-xl font-bold">Approve Gym</h3>
            </div>
            <div class="p-6">
                <p class="mb-4">Are you sure you want to approve <span id="approve-gym-name" class="font-semibold"></span>?</p>
                <p class="text-gray-400 mb-4">This will make the gym visible to all users and allow members to purchase memberships.</p>
                
                <form id="approve-form" method="POST" action="pending_gyms.php">
                    <input type="hidden" name="gym_id" id="approve-gym-id">
                    <div class="flex justify-end space-x-3">
                        <button type="button" id="close-approve-modal" class="bg-gray-600 hover:bg-gray-500 text-white px-4 py-2 rounded-lg">
                            Cancel
                        </button>
                        <button type="submit" name="approve_gym" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-check mr-2"></i> Approve Gym
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Reject Gym Modal -->
    <div id="reject-modal" class="modal fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center hidden">
        <div class="bg-gray-800 rounded-xl max-w-md w-full mx-4 overflow-hidden">
            <div class="bg-gray-700 px-6 py-4 border-b border-gray-600">
                <h3 class="text-xl font-bold">Reject Gym</h3>
            </div>
            <div class="p-6">
                <p class="mb-4">Are you sure you want to reject <span id="reject-gym-name" class="font-semibold"></span>?</p>
                
                <form id="reject-form" method="POST" action="pending_gyms.php">
                    <input type="hidden" name="gym_id" id="reject-gym-id">
                    
                    <div class="mb-4">
                        <label for="rejection_reason" class="block text-sm font-medium text-gray-300 mb-1">Reason for Rejection</label>
                        <textarea id="rejection_reason" name="rejection_reason" rows="3" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="Please provide a reason for rejection..." required></textarea>
                    </div>
                    
                    <div class="flex justify-end space-x-3">
                        <button type="button" id="close-reject-modal" class="bg-gray-600 hover:bg-gray-500 text-white px-4 py-2 rounded-lg">
                            Cancel
                        </button>
                        <button type="submit" name="reject_gym" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-times mr-2"></i> Reject Gym
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        // Modal functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Approve modal
            const approveModal = document.getElementById('approve-modal');
            const approveGymName = document.getElementById('approve-gym-name');
            const approveGymId = document.getElementById('approve-gym-id');
            const closeApproveModal = document.getElementById('close-approve-modal');
            
            document.querySelectorAll('.approve-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const gymId = this.getAttribute('data-id');
                    const gymName = this.getAttribute('data-name');
                    
                    approveGymId.value = gymId;
                    approveGymName.textContent = gymName;
                    
                    approveModal.classList.remove('hidden');
                    document.body.classList.add('modal-active');
                });
            });
            
            closeApproveModal.addEventListener('click', function() {
                approveModal.classList.add('hidden');
                document.body.classList.remove('modal-active');
            });
            
            // Reject modal
            const rejectModal = document.getElementById('reject-modal');
            const rejectGymName = document.getElementById('reject-gym-name');
            const rejectGymId = document.getElementById('reject-gym-id');
            const closeRejectModal = document.getElementById('close-reject-modal');
            
            document.querySelectorAll('.reject-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const gymId = this.getAttribute('data-id');
                    const gymName = this.getAttribute('data-name');
                    
                    rejectGymId.value = gymId;
                    rejectGymName.textContent = gymName;
                    
                    rejectModal.classList.remove('hidden');
                    document.body.classList.add('modal-active');
                });
            });
            
            closeRejectModal.addEventListener('click', function() {
                rejectModal.classList.add('hidden');
                document.body.classList.remove('modal-active');
            });
            
            // Close modals when clicking outside
            window.addEventListener('click', function(event) {
                if (event.target === approveModal) {
                    approveModal.classList.add('hidden');
                    document.body.classList.remove('modal-active');
                }
                
                if (event.target === rejectModal) {
                    rejectModal.classList.add('hidden');
                    document.body.classList.remove('modal-active');
                }
            });
            
            // Close modals with ESC key
            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape') {
                    approveModal.classList.add('hidden');
                    rejectModal.classList.add('hidden');
                    document.body.classList.remove('modal-active');
                }
            });
            
            // Form validation
            document.getElementById('reject-form').addEventListener('submit', function(event) {
                const rejectionReason = document.getElementById('rejection_reason').value.trim();
                
                if (rejectionReason === '') {
                    event.preventDefault();
                    alert('Please provide a reason for rejection.');
                }
            });
        });
    </script>
</body>
</html>

