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
$roles = [];
$totalRoles = 0;

// Pagination settings
$rolesPerPage = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max(1, $page);
$offset = ($page - 1) * $rolesPerPage;

// Search term
$searchTerm = isset($_GET['search']) ? $_GET['search'] : '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add new role
    if (isset($_POST['add_role'])) {
        $roleName = trim($_POST['role_name']);
        $roleDescription = trim($_POST['description']);
        $permissions = isset($_POST['permissions']) ? json_encode($_POST['permissions']) : '[]';
        
        if (!empty($roleName)) {
            try {
                // Check if role already exists
                $checkSql = "SELECT COUNT(*) FROM user_roles WHERE role_name = ?";
                $checkStmt = $conn->prepare($checkSql);
                $checkStmt->execute([$roleName]);
                $roleExists = $checkStmt->fetchColumn();
                
                if ($roleExists) {
                    $error = "A role with this name already exists.";
                } else {
                    // Insert new role
                    $sql = "INSERT INTO user_roles (role_name, description, permissions, created_at) 
                            VALUES (?, ?, ?, NOW())";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([$roleName, $roleDescription, $permissions]);
                    
                    // Log the activity
                    $activitySql = "
                        INSERT INTO activity_logs (
                            user_id, user_type, action, details, ip_address, user_agent
                        ) VALUES (
                            ?, 'admin', ?, ?, ?, ?
                        )
                    ";
                    $details = "Admin created new role: {$roleName}";
                    $activityStmt = $conn->prepare($activitySql);
                    $activityStmt->execute([
                        $_SESSION['admin_id'],
                        'create_role',
                        $details,
                        $_SERVER['REMOTE_ADDR'],
                        $_SERVER['HTTP_USER_AGENT']
                    ]);
                    
                    $message = "New role '{$roleName}' has been created successfully.";
                }
            } catch (PDOException $e) {
                $error = "Database error: " . $e->getMessage();
            }
        } else {
            $error = "Role name is required.";
        }
    }
    
    // Update role
    if (isset($_POST['update_role'])) {
        $roleId = isset($_POST['role_id']) ? (int)$_POST['role_id'] : 0;
        $roleName = trim($_POST['role_name']);
        $roleDescription = trim($_POST['description']);
        $permissions = isset($_POST['permissions']) ? json_encode($_POST['permissions']) : '[]';
        
        if ($roleId > 0 && !empty($roleName)) {
            try {
                // Check if role name already exists for other roles
                $checkSql = "SELECT COUNT(*) FROM user_roles WHERE role_name = ? AND id != ?";
                $checkStmt = $conn->prepare($checkSql);
                $checkStmt->execute([$roleName, $roleId]);
                $roleExists = $checkStmt->fetchColumn();
                
                if ($roleExists) {
                    $error = "A role with this name already exists.";
                } else {
                    // Update role
                    $sql = "UPDATE user_roles SET role_name = ?, description = ?, permissions = ? WHERE id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([$roleName, $roleDescription, $permissions, $roleId]);
                    
                    // Log the activity
                    $activitySql = "
                        INSERT INTO activity_logs (
                            user_id, user_type, action, details, ip_address, user_agent
                        ) VALUES (
                            ?, 'admin', ?, ?, ?, ?
                        )
                    ";
                    $details = "Admin updated role (ID: {$roleId}): {$roleName}";
                    $activityStmt = $conn->prepare($activitySql);
                    $activityStmt->execute([
                        $_SESSION['admin_id'],
                        'update_role',
                        $details,
                        $_SERVER['REMOTE_ADDR'],
                        $_SERVER['HTTP_USER_AGENT']
                    ]);
                    
                    $message = "Role '{$roleName}' has been updated successfully.";
                }
            } catch (PDOException $e) {
                $error = "Database error: " . $e->getMessage();
            }
        } else {
            $error = "Invalid role ID or role name is required.";
        }
    }
    
    // Delete role
    if (isset($_POST['delete_role'])) {
        $roleId = isset($_POST['role_id']) ? (int)$_POST['role_id'] : 0;
        
        if ($roleId > 0) {
            try {
                // Check if role is assigned to any users
                $checkSql = "SELECT COUNT(*) FROM users WHERE role_id = ?";
                $checkStmt = $conn->prepare($checkSql);
                $checkStmt->execute([$roleId]);
                $usersWithRole = $checkStmt->fetchColumn();
                
                if ($usersWithRole > 0) {
                    $error = "Cannot delete role that is assigned to users. Please reassign users first.";
                } else {
                    // Get role details for logging
                    $roleDetailsSql = "SELECT role_name FROM user_roles WHERE id = ?";
                    $roleDetailsStmt = $conn->prepare($roleDetailsSql);
                    $roleDetailsStmt->execute([$roleId]);
                    $roleName = $roleDetailsStmt->fetchColumn();
                    
                    // Delete role
                    $sql = "DELETE FROM user_roles WHERE id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([$roleId]);
                    
                    // Log the activity
                    $activitySql = "
                        INSERT INTO activity_logs (
                            user_id, user_type, action, details, ip_address, user_agent
                        ) VALUES (
                            ?, 'admin', ?, ?, ?, ?
                        )
                    ";
                    $details = "Admin deleted role (ID: {$roleId}, Name: {$roleName})";
                    $activityStmt = $conn->prepare($activitySql);
                    $activityStmt->execute([
                        $_SESSION['admin_id'],
                        'delete_role',
                        $details,
                        $_SERVER['REMOTE_ADDR'],
                        $_SERVER['HTTP_USER_AGENT']
                    ]);
                    
                    $message = "Role '{$roleName}' has been permanently deleted.";
                }
            } catch (PDOException $e) {
                $error = "Database error: " . $e->getMessage();
            }
        } else {
            $error = "Invalid role ID.";
        }
    }
}

// Build query with search
$sql = "SELECT r.*, 
        (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) as user_count
        FROM user_roles r
        WHERE 1=1";
$countSql = "SELECT COUNT(*) FROM user_roles r WHERE 1=1";
$params = [];
$countParams = [];

if (!empty($searchTerm)) {
    $sql .= " AND (r.role_name LIKE ? OR r.description LIKE ?)";
    $countSql .= " AND (r.role_name LIKE ? OR r.description LIKE ?)";
    $searchParam = "%$searchTerm%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $countParams[] = $searchParam;
    $countParams[] = $searchParam;
}

// Get total count for pagination
$countStmt = $conn->prepare($countSql);
$countStmt->execute($countParams);
$totalRoles = $countStmt->fetchColumn();

// Add pagination to query
$sql .= " ORDER BY r.created_at DESC LIMIT " . (int)$offset . ", " . (int)$rolesPerPage;

// Fetch roles
try {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching roles: " . $e->getMessage();
}

// Calculate pagination
$totalPages = ceil($totalRoles / $rolesPerPage);
$prevPage = max(1, $page - 1);
$nextPage = min($totalPages, $page + 1);

// Generate pagination URL
function getPaginationUrl($page) {
    $params = $_GET;
    $params['page'] = $page;
    return '?' . http_build_query($params);
}

// Format date
function formatDate($date) {
    return date('M d, Y', strtotime($date));
}

// Get available permissions
$availablePermissions = [
    'dashboard' => 'View Dashboard',
    'manage_users' => 'Manage Users',
    'manage_gyms' => 'Manage Gyms',
    'manage_memberships' => 'Manage Memberships',
    'manage_payments' => 'Manage Payments',
    'manage_reports' => 'View Reports',
    'manage_settings' => 'Manage Settings',
    'manage_content' => 'Manage Content',
    'approve_gyms' => 'Approve Gyms',
    'approve_reviews' => 'Approve Reviews',
    'manage_promotions' => 'Manage Promotions',
    'manage_tournaments' => 'Manage Tournaments',
     'manage_analytics' => 'Manage Analytics',
     'payouts'=>'Manage Payouts'
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage User Roles - FlexFit Admin</title>
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
        .role-card {
            transition: all 0.3s ease;
        }
        .role-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }
    </style>
</head>
<body class="bg-gray-900 text-white">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="ml-0 lg:ml-64 p-4 sm:p-6 transition-all duration-200">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
            <h1 class="text-2xl font-bold">Manage User Roles</h1>
            <button id="addRoleBtn" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg transition-colors duration-200 flex items-center">
                <i class="fas fa-plus mr-2"></i> Add New Role
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
        
        <!-- Search Section -->
        <div class="bg-gray-800 rounded-xl shadow-lg overflow-hidden mb-6">
            <div class="p-4 border-b border-gray-700 flex justify-between items-center">
                <h2 class="text-lg font-semibold">Search Roles</h2>
                <a href="user_roles.php" class="text-gray-400 hover:text-white text-sm">
                    <i class="fas fa-times mr-1"></i> Clear Search
                </a>
            </div>
            
            <form action="user_roles.php" method="GET" class="p-4">
                <div class="flex flex-col sm:flex-row gap-4">
                    <div class="flex-1">
                        <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($searchTerm); ?>" placeholder="Search by role name or description..." class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                    </div>
                    
                    <div>
                        <button type="submit" class="w-full sm:w-auto bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg transition-colors duration-200">
                            <i class="fas fa-search mr-2"></i> Search
                        </button>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Roles Grid -->
        <div class="bg-gray-800 rounded-xl shadow-lg overflow-hidden">
            <div class="p-6 border-b border-gray-700">
                <div class="flex justify-between items-center">
                    <div>
                        <h2 class="text-xl font-semibold">User Roles</h2>
                        <p class="text-gray-400 mt-1">
                        Showing <?php echo min($totalRoles, $offset + 1); ?> - <?php echo min($totalRoles, $offset + count($roles)); ?> of <?php echo $totalRoles; ?> roles
                        </p>
                    </div>
                </div>
            </div>
            
            <?php if (empty($roles)): ?>
                <div class="p-6 text-center text-gray-500">
                    <i class="fas fa-user-tag text-4xl mb-3"></i>
                    <p>No roles found matching your criteria.</p>
                </div>
            <?php else: ?>
                <div class="p-6 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php foreach ($roles as $role): ?>
                        <?php
                            $permissions = json_decode($role['permissions'] ?? '[]', true);
                            $permissionCount = count($permissions);
                        ?>
                        <div class="role-card bg-gray-700 rounded-lg overflow-hidden">
                            <div class="p-4 border-b border-gray-600 flex justify-between items-center">
                                <h3 class="font-semibold text-lg"><?php echo htmlspecialchars($role['role_name']); ?></h3>
                                <span class="px-2 py-1 text-xs rounded-full bg-blue-900 text-blue-300">
                                    <?php echo $permissionCount; ?> Permission<?php echo $permissionCount !== 1 ? 's' : ''; ?>
                                </span>
                            </div>
                            
                            <div class="p-4">
                                <div class="mb-3">
                                    <div class="text-sm text-gray-400">Description</div>
                                    <div class="font-medium"><?php echo htmlspecialchars($role['description'] ?? 'No description provided'); ?></div>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="text-sm text-gray-400">Permissions</div>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        <?php if (empty($permissions)): ?>
                                            <span class="text-gray-500">No permissions assigned</span>
                                        <?php else: ?>
                                            <?php foreach (array_slice($permissions, 0, 3) as $permission): ?>
                                                <span class="px-2 py-1 text-xs rounded-full bg-gray-600">
                                                    <?php echo htmlspecialchars($availablePermissions[$permission] ?? $permission); ?>
                                                </span>
                                            <?php endforeach; ?>
                                            
                                            <?php if (count($permissions) > 3): ?>
                                                <span class="px-2 py-1 text-xs rounded-full bg-gray-600">
                                                    +<?php echo count($permissions) - 3; ?> more
                                                </span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="grid grid-cols-2 gap-3 mb-3">
                                    <div>
                                        <div class="text-sm text-gray-400">Users</div>
                                        <div class="font-medium"><?php echo $role['user_count']; ?></div>
                                    </div>
                                    <div>
                                        <div class="text-sm text-gray-400">Created</div>
                                        <div class="font-medium"><?php echo formatDate($role['created_at']); ?></div>
                                    </div>
                                </div>
                                
                                <div class="flex flex-wrap gap-2 mt-4">
                                    <button class="edit-btn bg-yellow-600 hover:bg-yellow-700 text-white text-sm px-3 py-1 rounded-lg transition-colors duration-200"
                                            data-id="<?php echo $role['id']; ?>"
                                            data-name="<?php echo htmlspecialchars($role['role_name']); ?>"
                                            data-description="<?php echo htmlspecialchars($role['description'] ?? ''); ?>"
                                            data-permissions='<?php echo htmlspecialchars(json_encode($permissions)); ?>'>
                                        <i class="fas fa-edit mr-1"></i> Edit
                                    </button>
                                    
                                    <?php if ($role['user_count'] == 0): ?>
                                        <button class="delete-btn bg-red-600 hover:bg-red-700 text-white text-sm px-3 py-1 rounded-lg transition-colors duration-200"
                                                data-id="<?php echo $role['id']; ?>"
                                                data-name="<?php echo htmlspecialchars($role['role_name']); ?>">
                                            <i class="fas fa-trash-alt mr-1"></i> Delete
                                        </button>
                                    <?php else: ?>
                                        <button class="bg-gray-600 text-gray-400 text-sm px-3 py-1 rounded-lg cursor-not-allowed" title="Cannot delete role assigned to users">
                                            <i class="fas fa-trash-alt mr-1"></i> Delete
                                        </button>
                                    <?php endif; ?>
                                    
                                    <a href="users.php?role_id=<?php echo $role['id']; ?>" class="bg-blue-600 hover:bg-blue-700 text-white text-sm px-3 py-1 rounded-lg transition-colors duration-200">
                                        <i class="fas fa-users mr-1"></i> View Users
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="p-4 border-t border-gray-700 flex justify-between items-center">
                        <div class="text-sm text-gray-400">
                            Page <?php echo $page; ?> of <?php echo $totalPages; ?>
                        </div>
                        <div class="flex space-x-2">
                            <?php if ($page > 1): ?>
                                <a href="<?php echo getPaginationUrl(1); ?>" class="bg-gray-700 hover:bg-gray-600 text-white px-3 py-1 rounded-lg transition-colors duration-200">
                                    <i class="fas fa-angle-double-left"></i>
                                </a>
                                <a href="<?php echo getPaginationUrl($prevPage); ?>" class="bg-gray-700 hover:bg-gray-600 text-white px-3 py-1 rounded-lg transition-colors duration-200">
                                    <i class="fas fa-angle-left"></i>
                                </a>
                            <?php endif; ?>
                            
                            <?php
                            // Show a limited number of page links
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            
                            // Always show first page
                            if ($startPage > 1) {
                                echo '<a href="' . getPaginationUrl(1) . '" class="bg-gray-700 hover:bg-gray-600 text-white px-3 py-1 rounded-lg transition-colors duration-200">1</a>';
                                if ($startPage > 2) {
                                    echo '<span class="text-gray-400 px-1">...</span>';
                                }
                            }
                            
                            // Show page links
                            for ($i = $startPage; $i <= $endPage; $i++) {
                                $activeClass = $i === $page ? 'bg-blue-600 hover:bg-blue-700' : 'bg-gray-700 hover:bg-gray-600';
                                echo '<a href="' . getPaginationUrl($i) . '" class="' . $activeClass . ' text-white px-3 py-1 rounded-lg transition-colors duration-200">' . $i . '</a>';
                            }
                            
                            // Always show last page
                            if ($endPage < $totalPages) {
                                if ($endPage < $totalPages - 1) {
                                    echo '<span class="text-gray-400 px-1">...</span>';
                                }
                                echo '<a href="' . getPaginationUrl($totalPages) . '" class="bg-gray-700 hover:bg-gray-600 text-white px-3 py-1 rounded-lg transition-colors duration-200">' . $totalPages . '</a>';
                            }
                            ?>
                            
                            <?php if ($page < $totalPages): ?>
                                <a href="<?php echo getPaginationUrl($nextPage); ?>" class="bg-gray-700 hover:bg-gray-600 text-white px-3 py-1 rounded-lg transition-colors duration-200">
                                    <i class="fas fa-angle-right"></i>
                                </a>
                                <a href="<?php echo getPaginationUrl($totalPages); ?>" class="bg-gray-700 hover:bg-gray-600 text-white px-3 py-1 rounded-lg transition-colors duration-200">
                                    <i class="fas fa-angle-double-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Add/Edit Role Modal -->
    <div id="roleModal" class="modal opacity-0 pointer-events-none fixed w-full h-full top-0 left-0 flex items-center justify-center z-50">
        <div class="modal-overlay absolute w-full h-full bg-black opacity-75"></div>
        
        <div class="modal-container bg-gray-800 w-11/12 md:max-w-md mx-auto rounded-xl shadow-lg z-50 overflow-y-auto max-h-screen">
            <div class="modal-content py-4 text-left px-6">
                <div class="flex justify-between items-center pb-3 border-b border-gray-700">
                    <p class="text-xl font-bold" id="modalTitle">Add New Role</p>
                    <button class="modal-close cursor-pointer z-50">
                        <i class="fas fa-times text-gray-400 hover:text-white"></i>
                    </button>
                </div>
                
                <div class="mt-4">
                    <form id="roleForm" method="POST">
                        <input type="hidden" id="role_id" name="role_id" value="">
                        
                        <div class="mb-4">
                            <label for="role_name" class="block text-sm font-medium text-gray-400 mb-1">Role Name</label>
                            <input type="text" id="role_name" name="role_name" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                        </div>
                        
                        <div class="mb-4">
                            <label for="description" class="block text-sm font-medium text-gray-400 mb-1">Description</label>
                            <textarea id="description" name="description" rows="3" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500"></textarea>
                        </div>
                        
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-400 mb-2">Permissions</label>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 max-h-60 overflow-y-auto p-2 bg-gray-700 rounded-lg">
                                <?php foreach ($availablePermissions as $key => $label): ?>
                                    <div class="flex items-center">
                                        <input type="checkbox" id="perm_<?php echo $key; ?>" name="permissions[]" value="<?php echo $key; ?>" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-600 rounded">
                                        <label for="perm_<?php echo $key; ?>" class="ml-2 block text-sm text-gray-300">
                                            <?php echo $label; ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <div class="mt-6 flex justify-end">
                            <button type="button" class="modal-close bg-gray-600 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded-lg mr-2">
                                Cancel
                            </button>
                            <button type="submit" id="submitBtn" name="add_role" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg">
                                <i class="fas fa-save mr-2"></i> Save Role
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Delete Role Modal -->
    <div id="deleteModal" class="modal opacity-0 pointer-events-none fixed w-full h-full top-0 left-0 flex items-center justify-center z-50">
        <div class="modal-overlay absolute w-full h-full bg-black opacity-75"></div>
        
        <div class="modal-container bg-gray-800 w-11/12 md:max-w-md mx-auto rounded-xl shadow-lg z-50">
            <div class="modal-content py-4 text-left px-6">
                <div class="flex justify-between items-center pb-3 border-b border-gray-700">
                    <p class="text-xl font-bold text-red-500">Delete Role</p>
                    <button class="modal-close cursor-pointer z-50">
                        <i class="fas fa-times text-gray-400 hover:text-white"></i>
                    </button>
                </div>
                
                <div class="mt-4">
                    <div class="bg-red-900 bg-opacity-50 p-4 rounded-lg mb-4">
                        <div class="flex items-center">
                            <i class="fas fa-exclamation-triangle text-red-500 text-xl mr-3"></i>
                            <div>
                                <h4 class="font-medium text-white">Warning: This action cannot be undone</h4>
                                <p class="text-sm text-red-300 mt-1">You are about to delete the role: <span id="delete-role-name" class="font-semibold"></span></p>
                            </div>
                        </div>
                    </div>
                    
                    <form method="POST">
                    <input type="hidden" id="delete-role-id" name="role_id" value="">
                        
                        <div class="mt-6 flex justify-end">
                            <button type="button" class="modal-close bg-gray-600 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded-lg mr-2">
                                Cancel
                            </button>
                            <button type="submit" name="delete_role" class="bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded-lg">
                                <i class="fas fa-trash-alt mr-2"></i> Delete Role
                            </button>
                        </div>
                    </form>
                </div>
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
        
        // Add role button
        document.getElementById('addRoleBtn').addEventListener('click', function() {
            // Reset form
            document.getElementById('roleForm').reset();
            document.getElementById('role_id').value = '';
            document.getElementById('modalTitle').textContent = 'Add New Role';
            document.getElementById('submitBtn').textContent = 'Create Role';
            document.getElementById('submitBtn').name = 'add_role';
            
            // Clear all checkboxes
            document.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
                checkbox.checked = false;
            });
            
            toggleModal('roleModal');
        });
        
        // Edit role buttons
        document.querySelectorAll('.edit-btn').forEach(button => {
            button.addEventListener('click', function() {
                const roleId = this.getAttribute('data-id');
                const roleName = this.getAttribute('data-name');
                const roleDescription = this.getAttribute('data-description');
                const permissions = JSON.parse(this.getAttribute('data-permissions') || '[]');
                
                document.getElementById('role_id').value = roleId;
                document.getElementById('role_name').value = roleName;
                document.getElementById('description').value = roleDescription;
                document.getElementById('modalTitle').textContent = 'Edit Role';
                document.getElementById('submitBtn').textContent = 'Update Role';
                document.getElementById('submitBtn').name = 'update_role';
                
                // Reset all checkboxes first
                document.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
                    checkbox.checked = false;
                });
                
                // Check the appropriate permissions
                permissions.forEach(permission => {
                    const checkbox = document.getElementById('perm_' + permission);
                    if (checkbox) {
                        checkbox.checked = true;
                    }
                });
                
                toggleModal('roleModal');
            });
        });
        
        // Delete role buttons
        document.querySelectorAll('.delete-btn').forEach(button => {
            button.addEventListener('click', function() {
                const roleId = this.getAttribute('data-id');
                const roleName = this.getAttribute('data-name');
                
                document.getElementById('delete-role-id').value = roleId;
                document.getElementById('delete-role-name').textContent = roleName;
                
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
        
        // Highlight cards on hover
        document.querySelectorAll('.role-card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.classList.add('transform', 'scale-[1.02]', 'shadow-lg');
            });
            
            card.addEventListener('mouseleave', function() {
                this.classList.remove('transform', 'scale-[1.02]', 'shadow-lg');
            });
        });
    </script>
</body>
</html>


