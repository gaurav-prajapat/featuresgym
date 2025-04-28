<?php 
session_start();
require_once '../config/database.php';

// Ensure user is authenticated and has admin role
if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['error'] = "You don't have permission to access this page.";
    header('Location: ../login.php');
    exit();
}

$db = new GymDatabase();
$conn = $db->getConnection();

// CSRF protection
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Handle Gym Status Change via AJAX
if (isset($_POST['ajax_update_status']) && isset($_POST['csrf_token'])) {
    if ($_POST['csrf_token'] === $_SESSION['csrf_token']) {
        $gym_id = filter_input(INPUT_POST, 'gym_id', FILTER_SANITIZE_NUMBER_INT);
        $new_status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);
        
        $allowed_statuses = ['active', 'inactive', 'pending', 'suspended', 'deleted'];
        
        if ($gym_id && in_array($new_status, $allowed_statuses)) {
            try {
                $query = "UPDATE gyms SET status = :status WHERE gym_id = :gym_id";
                $stmt = $conn->prepare($query);
                $stmt->execute([
                    ':status' => $new_status,
                    ':gym_id' => $gym_id
                ]);
                
                // Log the activity
                $log_query = "INSERT INTO activity_logs (user_id, user_type, action, details, ip_address, user_agent) 
                             VALUES (:user_id, 'admin', 'update_gym_status', :details, :ip, :user_agent)";
                $log_stmt = $conn->prepare($log_query);
                $log_stmt->execute([
                    ':user_id' => $_SESSION['admin_id'],
                    ':details' => "Updated gym ID: $gym_id status to $new_status",
                    ':ip' => $_SERVER['REMOTE_ADDR'],
                    ':user_agent' => $_SERVER['HTTP_USER_AGENT']
                ]);
                
                echo json_encode(['success' => true, 'message' => 'Gym status updated successfully!', 'new_status' => $new_status]);
                exit;
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'message' => 'Failed to update gym status: ' . $e->getMessage()]);
                exit;
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid gym ID or status.']);
            exit;
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'CSRF token validation failed.']);
        exit;
    }
}

// Handle Gym Deletion with CSRF protection
if (isset($_POST['delete_gym']) && isset($_POST['csrf_token'])) {
    if ($_POST['csrf_token'] === $_SESSION['csrf_token']) {
        $gym_id_to_delete = filter_input(INPUT_POST, 'gym_id', FILTER_SANITIZE_NUMBER_INT);
        
        if ($gym_id_to_delete) {
            try {
                $conn->beginTransaction();
                
                // Delete related gym data using the structure from gym-p (2).sql
                $related_tables = [
                    'gym_images', 'gym_operating_hours', 'gym_equipment', 
                    'gym_membership_plans', 'gym_gallery', 'gym_edit_permissions',
                    'gym_notifications', 'gym_policies', 'gym_page_settings',
                    'gym_revenue', 'gym_tournaments', 'gym_classes'
                ];
                
                foreach ($related_tables as $table) {
                    $query = "DELETE FROM $table WHERE gym_id = :gym_id";
                    $stmt = $conn->prepare($query);
                    $stmt->execute([':gym_id' => $gym_id_to_delete]);
                }
                
                // Delete the gym
                $query = "DELETE FROM gyms WHERE gym_id = :gym_id";
                $stmt = $conn->prepare($query);
                $stmt->execute([':gym_id' => $gym_id_to_delete]);
                
                $conn->commit();
                
                // Log the activity
                $log_query = "INSERT INTO activity_logs (user_id, user_type, action, details, ip_address, user_agent) 
                             VALUES (:user_id, 'admin', 'delete_gym', :details, :ip, :user_agent)";
                $log_stmt = $conn->prepare($log_query);
                $log_stmt->execute([
                    ':user_id' => $_SESSION['admin_id'],
                    ':details' => "Deleted gym ID: $gym_id_to_delete",
                    ':ip' => $_SERVER['REMOTE_ADDR'],
                    ':user_agent' => $_SERVER['HTTP_USER_AGENT']
                ]);
                
                $_SESSION['success'] = "Gym deleted successfully!";
            } catch (PDOException $e) {
                $conn->rollBack();
                $_SESSION['error'] = "Failed to delete gym: " . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = "Invalid gym ID.";
        }
    } else {
        $_SESSION['error'] = "CSRF token validation failed.";
    }
    
    header("Location: manage_gym.php");
    exit;
}

// Handle gym status changes via form submission
if (isset($_POST['update_status']) && isset($_POST['csrf_token'])) {
    if ($_POST['csrf_token'] === $_SESSION['csrf_token']) {
        $gym_id = filter_input(INPUT_POST, 'gym_id', FILTER_SANITIZE_NUMBER_INT);
        $new_status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);
        
        $allowed_statuses = ['active', 'inactive', 'pending', 'suspended', 'deleted'];
        
        if ($gym_id && in_array($new_status, $allowed_statuses)) {
            try {
                $query = "UPDATE gyms SET status = :status WHERE gym_id = :gym_id";
                $stmt = $conn->prepare($query);
                $stmt->execute([
                    ':status' => $new_status,
                    ':gym_id' => $gym_id
                ]);
                
                // Log the activity
                $log_query = "INSERT INTO activity_logs (user_id, user_type, action, details, ip_address, user_agent) 
                             VALUES (:user_id, 'admin', 'update_gym_status', :details, :ip, :user_agent)";
                $log_stmt = $conn->prepare($log_query);
                $log_stmt->execute([
                    ':user_id' => $_SESSION['admin_id'],
                    ':details' => "Updated gym ID: $gym_id status to $new_status",
                    ':ip' => $_SERVER['REMOTE_ADDR'],
                    ':user_agent' => $_SERVER['HTTP_USER_AGENT']
                ]);
                
                $_SESSION['success'] = "Gym status updated successfully!";
            } catch (PDOException $e) {
                $_SESSION['error'] = "Failed to update gym status: " . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = "Invalid gym ID or status.";
        }
        
        header("Location: manage_gym.php");
        exit;
    } else {
        $_SESSION['error'] = "CSRF token validation failed.";
    }
}

// Fetch all gyms with their respective owner names and additional information
$query = "
    SELECT g.*, go.name AS owner_name, go.email AS owner_email, go.phone AS owner_phone,
           (SELECT COUNT(*) FROM gym_membership_plans WHERE gym_id = g.gym_id) AS plan_count,
           (SELECT COUNT(*) FROM reviews WHERE gym_id = g.gym_id) AS review_count,
           (SELECT AVG(rating) FROM reviews WHERE gym_id = g.gym_id) AS avg_rating
    FROM gyms g
    LEFT JOIN gym_owners go ON g.owner_id = go.id
    ORDER BY g.created_at DESC
";
$stmt = $conn->prepare($query);
$stmt->execute();
$gyms = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get gym count by status for dashboard stats
$status_query = "SELECT status, COUNT(*) as count FROM gyms GROUP BY status";
$status_stmt = $conn->prepare($status_query);
$status_stmt->execute();
$gym_status_counts = $status_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

?>
 <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($siteName) ?> - <?= htmlspecialchars($siteTagline) ?></title>
        <link rel="icon" href="<?= htmlspecialchars($faviconPath) ?>" type="image/x-icon">
        <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
        <style>
            .animate-fade-in {
                animation: fadeIn 1s ease-out;
            }

            .animate-fade-in-delay {
                animation: fadeIn 1s ease-out 0.3s both;
            }

            .animate-fade-in-delay-2 {
                animation: fadeIn 1s ease-out 0.6s both;
            }

            @keyframes fadeIn {
                from {
                    opacity: 0;
                    transform: translateY(20px);
                }

                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
        </style>
    </head>

<body class="bg-gray-900 text-white">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="ml-0 lg:ml-64 p-4 sm:p-6 transition-all duration-200">
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
                <h1 class="text-3xl font-bold text-white mb-4 md:mb-0">Manage Gyms</h1>
                <div class="flex flex-col sm:flex-row gap-3">
                    <a href="add_gym.php" class="bg-yellow-500 hover:bg-yellow-600 text-black font-medium py-2 px-4 rounded-lg transition-colors duration-200 flex items-center justify-center">
                        <i class="fas fa-plus mr-2"></i>Add New Gym
                    </a>
                    <a href="gym-owners.php" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-lg transition-colors duration-200 flex items-center justify-center">
                        <i class="fas fa-users mr-2"></i>Manage Owners
                    </a>
                </div>
            </div>
            
            <!-- Stats Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
                <div class="bg-gray-700 rounded-lg p-4 shadow">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-blue-500 bg-opacity-25 text-blue-500 mr-4">
                            <i class="fas fa-dumbbell text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-gray-400 text-sm">Total Gyms</p>
                            <p class="text-white text-xl font-bold"><?= count($gyms) ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-gray-700 rounded-lg p-4 shadow">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-green-500 bg-opacity-25 text-green-500 mr-4">
                            <i class="fas fa-check-circle text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-gray-400 text-sm">Active</p>
                            <p class="text-white text-xl font-bold"><?= $gym_status_counts['active'] ?? 0 ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-gray-700 rounded-lg p-4 shadow">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-yellow-500 bg-opacity-25 text-yellow-500 mr-4">
                            <i class="fas fa-clock text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-gray-400 text-sm">Pending</p>
                            <p class="text-white text-xl font-bold"><?= $gym_status_counts['pending'] ?? 0 ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-gray-700 rounded-lg p-4 shadow">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-red-500 bg-opacity-25 text-red-500 mr-4">
                            <i class="fas fa-ban text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-gray-400 text-sm">Suspended</p>
                            <p class="text-white text-xl font-bold"><?= $gym_status_counts['suspended'] ?? 0 ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-gray-700 rounded-lg p-4 shadow">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-gray-500 bg-opacity-25 text-gray-400 mr-4">
                            <i class="fas fa-power-off text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-gray-400 text-sm">Inactive</p>
                            <p class="text-white text-xl font-bold"><?= $gym_status_counts['inactive'] ?? 0 ?></p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Search and Filter -->
            <div class="mb-6">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                    <div class="relative w-full md:w-64">
                        <input type="text" id="searchInput" placeholder="Search gyms..." 
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
                            <option value="pending">Pending</option>
                            <option value="suspended">Suspended</option>
                            <option value="deleted">Deleted</option>
                        </select>
                        
                        <select id="sortBy" class="border border-gray-600 bg-gray-700 text-white rounded-lg p-2 focus:outline-none focus:ring-2 focus:ring-yellow-500">
                            <option value="newest">Newest First</option>
                            <option value="oldest">Oldest First</option>
                            <option value="name_asc">Name (A-Z)</option>
                            <option value="name_desc">Name (Z-A)</option>
                            <option value="rating_high">Highest Rated</option>
                            <option value="rating_low">Lowest Rated</option>
                        </select>
                    </div>
                </div>
            </div>
            
            <!-- Gyms Table -->
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-700" id="gymsTable">
                    <thead>
                        <tr class="bg-gray-700">
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Gym</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Owner</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Location</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Rating</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-700">
                        <?php if (count($gyms) > 0): ?>
                            <?php foreach ($gyms as $gym): ?>
                                <tr class="hover:bg-gray-700 transition-colors duration-150">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <?php if (!empty($gym['cover_photo'])): ?>
                                                <img src="../<?= htmlspecialchars($gym['cover_photo']) ?>" alt="<?= htmlspecialchars($gym['name']) ?>" class="h-10 w-10 rounded-md object-cover mr-3">
                                            <?php else: ?>
                                                <div class="h-10 w-10 rounded-md bg-gray-600 flex items-center justify-center mr-3">
                                                    <i class="fas fa-dumbbell text-yellow-400"></i>
                                                </div>
                                            <?php endif; ?>
                                            <div>
                                                <div class="text-sm font-medium text-white"><?= htmlspecialchars($gym['name']) ?></div>
                                                <div class="text-xs text-gray-400">ID: <?= $gym['gym_id'] ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-white"><?= htmlspecialchars($gym['owner_name'] ?? 'Unknown') ?></div>
                                        <?php if (!empty($gym['owner_email'])): ?>
                                            <div class="text-xs text-gray-400"><?= htmlspecialchars($gym['owner_email']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-white"><?= htmlspecialchars($gym['city']) ?>, <?= htmlspecialchars($gym['state']) ?></div>
                                        <div class="text-xs text-gray-400"><?= htmlspecialchars($gym['country']) ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <?php 
                                            $rating = round($gym['avg_rating'] ?? 0, 1);
                                            $fullStars = floor($rating);
                                            $halfStar = $rating - $fullStars >= 0.5;
                                            $emptyStars = 5 - $fullStars - ($halfStar ? 1 : 0);
                                            ?>
                                            
                                            <div class="flex text-yellow-400">
                                                <?php for ($i = 0; $i < $fullStars; $i++): ?>
                                                    <i class="fas fa-star"></i>
                                                <?php endfor; ?>
                                                
                                                <?php if ($halfStar): ?>
                                                    <i class="fas fa-star-half-alt"></i>
                                                <?php endif; ?>
                                                
                                                <?php for ($i = 0; $i < $emptyStars; $i++): ?>
                                                    <i class="far fa-star"></i>
                                                <?php endfor; ?>
                                            </div>
                                            
                                            <span class="ml-1 text-sm text-white"><?= number_format($rating, 1) ?></span>
                                            <span class="ml-1 text-xs text-gray-400">(<?= $gym['review_count'] ?? 0 ?>)</span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php 
                                        $statusClasses = [
                                            'active' => 'bg-green-100 text-green-800',
                                            'inactive' => 'bg-gray-100 text-gray-800',
                                            'pending' => 'bg-yellow-100 text-yellow-800',
                                            'suspended' => 'bg-red-100 text-red-800',
                                            'deleted' => 'bg-red-100 text-red-800'
                                        ];
                                        $statusClass = $statusClasses[$gym['status']] ?? 'bg-gray-100 text-gray-800';
                                        ?>
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?= $statusClass ?> status-badge" data-gym-id="<?= $gym['gym_id'] ?>">
                                            <?= ucfirst(htmlspecialchars($gym['status'])) ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <div class="flex flex-wrap gap-2">
                                            <a href="edit_gym.php?id=<?= $gym['gym_id'] ?>" class="text-blue-400 hover:text-blue-300" title="Edit Gym">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            
                                            <a href="manage_gym_permissions.php?id=<?= $gym['gym_id'] ?>" class="text-purple-400 hover:text-purple-300" title="Manage Permissions">
                                                <i class="fas fa-key"></i>
                                            </a>
                                            
                                            <button type="button" class="text-yellow-400 hover:text-yellow-300 status-btn" 
                                                    data-gym-id="<?= $gym['gym_id'] ?>" 
                                                    data-gym-name="<?= htmlspecialchars($gym['name']) ?>"
                                                    data-current-status="<?= htmlspecialchars($gym['status']) ?>"
                                                    title="Change Status">
                                                <i class="fas fa-exchange-alt"></i>
                                            </button>
                                            
                                            <button type="button" class="text-red-400 hover:text-red-300 delete-btn" 
                                                    data-gym-id="<?= $gym['gym_id'] ?>" 
                                                    data-gym-name="<?= htmlspecialchars($gym['name']) ?>"
                                                    title="Delete Gym">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="px-6 py-4 text-center text-gray-400">
                                    No gyms found. <a href="add_gym.php" class="text-yellow-400 hover:underline">Add a new gym</a>.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="fixed inset-0 bg-black bg-opacity-75 flex items-center justify-center z-50 hidden">
    <div class="bg-gray-800 rounded-lg max-w-md w-full mx-4">
        <div class="p-6">
            <h3 class="text-xl font-semibold text-white mb-4">Confirm Deletion</h3>
            <p class="text-gray-300 mb-6">Are you sure you want to delete <span id="gymNameToDelete" class="font-semibold text-yellow-400"></span>? This action cannot be undone.</p>
            
            <form id="deleteForm" method="POST" action="manage_gym.php">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="delete_gym" value="1">
                <input type="hidden" id="gymIdToDelete" name="gym_id" value="">
                
                <div class="flex justify-end space-x-3">
                    <button type="button" id="cancelDelete" class="bg-gray-600 hover:bg-gray-700 text-white font-medium py-2 px-4 rounded-lg transition-colors duration-200">
                        Cancel
                    </button>
                    <button type="submit" class="bg-red-500 hover:bg-red-600 text-white font-medium py-2 px-4 rounded-lg transition-colors duration-200">
                        Delete Gym
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Status Change Modal -->
<div id="statusModal" class="fixed inset-0 bg-black bg-opacity-75 flex items-center justify-center z-50 hidden">
    <div class="bg-gray-800 rounded-lg max-w-md w-full mx-4">
        <div class="p-6">
            <h3 class="text-xl font-semibold text-white mb-4">Change Gym Status</h3>
            <p class="text-gray-300 mb-4">Update the status for <span id="gymNameToUpdate" class="font-semibold text-yellow-400"></span>:</p>
            
            <div id="statusForm">
                <input type="hidden" id="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" id="gymIdToUpdate" value="">
                
                <div class="mb-4">
                    <select id="newStatus" class="border border-gray-600 bg-gray-700 text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-yellow-500">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                        <option value="pending">Pending</option>
                        <option value="suspended">Suspended</option>
                    </select>
                </div>
                
                <div class="flex justify-end space-x-3">
                    <button type="button" id="cancelStatus" class="bg-gray-600 hover:bg-gray-700 text-white font-medium py-2 px-4 rounded-lg transition-colors duration-200">
                        Cancel
                    </button>
                    <button type="button" id="updateStatus" class="bg-yellow-500 hover:bg-yellow-600 text-black font-medium py-2 px-4 rounded-lg transition-colors duration-200">
                        Update Status
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Delete modal functionality
        const deleteModal = document.getElementById('deleteModal');
        const deleteButtons = document.querySelectorAll('.delete-btn');
        const cancelDelete = document.getElementById('cancelDelete');
        const gymNameToDelete = document.getElementById('gymNameToDelete');
        const gymIdToDelete = document.getElementById('gymIdToDelete');
        
        deleteButtons.forEach(button => {
            button.addEventListener('click', function() {
                const gymId = this.getAttribute('data-gym-id');
                const gymName = this.getAttribute('data-gym-name');
                
                gymNameToDelete.textContent = gymName;
                gymIdToDelete.value = gymId;
                deleteModal.classList.remove('hidden');
            });
        });
        
        if (cancelDelete) {
            cancelDelete.addEventListener('click', function() {
                deleteModal.classList.add('hidden');
            });
        }
        
        // Status modal functionality
        const statusModal = document.getElementById('statusModal');
        const statusButtons = document.querySelectorAll('.status-btn');
        const statusButtons = document.querySelectorAll('.status-btn');
        const cancelStatus = document.getElementById('cancelStatus');
        const gymNameToUpdate = document.getElementById('gymNameToUpdate');
        const gymIdToUpdate = document.getElementById('gymIdToUpdate');
        const newStatus = document.getElementById('newStatus');
        const updateStatusBtn = document.getElementById('updateStatus');
        
        statusButtons.forEach(button => {
            button.addEventListener('click', function() {
                const gymId = this.getAttribute('data-gym-id');
                const gymName = this.getAttribute('data-gym-name');
                const currentStatus = this.getAttribute('data-current-status');
                
                gymNameToUpdate.textContent = gymName;
                gymIdToUpdate.value = gymId;
                newStatus.value = currentStatus;
                statusModal.classList.remove('hidden');
            });
        });
        
        if (cancelStatus) {
            cancelStatus.addEventListener('click', function() {
                statusModal.classList.add('hidden');
            });
        }
        
        // AJAX status update
        if (updateStatusBtn) {
            updateStatusBtn.addEventListener('click', function() {
                const gymId = gymIdToUpdate.value;
                const status = newStatus.value;
                const csrfToken = document.getElementById('csrf_token').value;
                
                // Create form data
                const formData = new FormData();
                formData.append('ajax_update_status', '1');
                formData.append('gym_id', gymId);
                formData.append('status', status);
                formData.append('csrf_token', csrfToken);
                
                // Send AJAX request
                fetch('manage_gym.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Update status badge in the table
                        const statusBadges = document.querySelectorAll(`.status-badge[data-gym-id="${gymId}"]`);
                        
                        statusBadges.forEach(badge => {
                            // Remove old status classes
                            badge.classList.remove('bg-green-100', 'text-green-800', 'bg-gray-100', 'text-gray-800', 
                                                  'bg-yellow-100', 'text-yellow-800', 'bg-red-100', 'text-red-800');
                            
                            // Add new status classes
                            let newClasses = 'bg-gray-100 text-gray-800';
                            if (status === 'active') {
                                newClasses = 'bg-green-100 text-green-800';
                            } else if (status === 'pending') {
                                newClasses = 'bg-yellow-100 text-yellow-800';
                            } else if (status === 'suspended' || status === 'deleted') {
                                newClasses = 'bg-red-100 text-red-800';
                            }
                            
                            badge.classList.add(...newClasses.split(' '));
                            badge.textContent = status.charAt(0).toUpperCase() + status.slice(1);
                        });
                        
                        // Update data-current-status attribute on the status button
                        const statusBtn = document.querySelector(`.status-btn[data-gym-id="${gymId}"]`);
                        if (statusBtn) {
                            statusBtn.setAttribute('data-current-status', status);
                        }
                        
                        // Show success message
                        const successMessage = document.createElement('div');
                        successMessage.className = 'bg-green-500 text-white p-4 rounded-lg mb-6 flex items-center justify-between fixed top-4 right-4 z-50';
                        successMessage.innerHTML = `
                            <div>
                                <i class="fas fa-check-circle mr-2"></i>
                                ${data.message}
                            </div>
                            <button class="text-white focus:outline-none" onclick="this.parentElement.remove()">
                                <i class="fas fa-times"></i>
                            </button>
                        `;
                        document.body.appendChild(successMessage);
                        
                        // Auto remove after 3 seconds
                        setTimeout(() => {
                            successMessage.remove();
                        }, 3000);
                        
                        // Close the modal
                        statusModal.classList.add('hidden');
                    } else {
                        // Show error message
                        alert(data.message || 'Failed to update status. Please try again.');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred. Please try again.');
                });
            });
        }
        
        // Close modals when clicking outside
        window.addEventListener('click', function(event) {
            if (event.target === deleteModal) {
                deleteModal.classList.add('hidden');
            }
            if (event.target === statusModal) {
                statusModal.classList.add('hidden');
            }
        });
        
        // Search functionality
        const searchInput = document.getElementById('searchInput');
        const gymsTable = document.getElementById('gymsTable');
        const tableRows = gymsTable.querySelectorAll('tbody tr');
        
        searchInput.addEventListener('keyup', function() {
            const searchTerm = this.value.toLowerCase();
            
            tableRows.forEach(row => {
                const gymName = row.querySelector('td:first-child').textContent.toLowerCase();
                const ownerName = row.querySelector('td:nth-child(2)').textContent.toLowerCase();
                const location = row.querySelector('td:nth-child(3)').textContent.toLowerCase();
                
                if (gymName.includes(searchTerm) || ownerName.includes(searchTerm) || location.includes(searchTerm)) {
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
                const status = row.querySelector('td:nth-child(5) span').textContent.toLowerCase();
                
                if (selectedStatus === '' || status.includes(selectedStatus)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
        
        // Sort functionality
        const sortBy = document.getElementById('sortBy');
        
        sortBy.addEventListener('change', function() {
            const sortValue = this.value;
            const tbody = gymsTable.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            
            rows.sort((a, b) => {
                switch(sortValue) {
                    case 'newest':
                        // Using gym_id as proxy for creation date (higher ID = newer)
                        const idA = parseInt(a.querySelector('td:first-child .text-xs').textContent.replace('ID: ', ''));
                        const idB = parseInt(b.querySelector('td:first-child .text-xs').textContent.replace('ID: ', ''));
                        return idB - idA;
                    
                    case 'oldest':
                        const idAOld = parseInt(a.querySelector('td:first-child .text-xs').textContent.replace('ID: ', ''));
                        const idBOld = parseInt(b.querySelector('td:first-child .text-xs').textContent.replace('ID: ', ''));
                        return idAOld - idBOld;
                    
                    case 'name_asc':
                        const nameA = a.querySelector('td:first-child .text-sm').textContent.toLowerCase();
                        const nameB = b.querySelector('td:first-child .text-sm').textContent.toLowerCase();
                        return nameA.localeCompare(nameB);
                    
                    case 'name_desc':
                        const nameADesc = a.querySelector('td:first-child .text-sm').textContent.toLowerCase();
                        const nameBDesc = b.querySelector('td:first-child .text-sm').textContent.toLowerCase();
                        return nameBDesc.localeCompare(nameADesc);
                    
                    case 'rating_high':
                        const ratingA = parseFloat(a.querySelector('td:nth-child(4) .text-sm').textContent) || 0;
                        const ratingB = parseFloat(b.querySelector('td:nth-child(4) .text-sm').textContent) || 0;
                        return ratingB - ratingA;
                    
                    case 'rating_low':
                        const ratingALow = parseFloat(a.querySelector('td:nth-child(4) .text-sm').textContent) || 0;
                        const ratingBLow = parseFloat(b.querySelector('td:nth-child(4) .text-sm').textContent) || 0;
                        return ratingALow - ratingBLow;
                    
                    default:
                        return 0;
                }
            });
            
            // Clear and re-append rows in new order
            rows.forEach(row => tbody.appendChild(row));
        });
        
        // Responsive table handling for mobile
        function adjustTableForMobile() {
            const table = document.getElementById('gymsTable');
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
</style>




