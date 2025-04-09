<?php
ob_start();
require_once '../config/database.php';
session_start();

// Check if user is logged in as admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: /gym/views/auth/login.php');
    exit();
}

$db = new GymDatabase();
$conn = $db->getConnection();

// Handle user status change if requested
if (isset($_GET['action']) && isset($_GET['id'])) {
    $user_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    $action = $_GET['action'];
    
    if ($user_id) {
        try {
            if ($action === 'activate') {
                $stmt = $conn->prepare("UPDATE users SET status = 'active' WHERE id = ?");
                $stmt->execute([$user_id]);
                $_SESSION['success'] = "User activated successfully.";
            } elseif ($action === 'deactivate') {
                $stmt = $conn->prepare("UPDATE users SET status = 'inactive' WHERE id = ?");
                $stmt->execute([$user_id]);
                $_SESSION['success'] = "User deactivated successfully.";
            } elseif ($action === 'suspend') {
                $stmt = $conn->prepare("UPDATE users SET status = 'suspended' WHERE id = ?");
                $stmt->execute([$user_id]);
                $_SESSION['success'] = "User suspended successfully.";
            }
            
            // Log the activity
            $stmt = $conn->prepare("
                INSERT INTO activity_logs (
                    user_id, user_type, action, details, ip_address, user_agent
                ) VALUES (?, 'admin', ?, ?, ?, ?)
            ");
            
            $details = "Changed user ID: $user_id status to $action";
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            
            $stmt->execute([$_SESSION['user_id'], "update_user_status", $details, $ip, $user_agent]);
            
        } catch (PDOException $e) {
            $_SESSION['error'] = "Database error: " . $e->getMessage();
        }
        
        header("Location: users.php");
        exit;
    }
}

// Handle role assignment if requested
if (isset($_POST['assign_role'])) {
    $user_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    $role_id = filter_input(INPUT_POST, 'role_id', FILTER_VALIDATE_INT);
    
    if ($user_id && $role_id) {
        try {
            // Get role name for logging
            $roleStmt = $conn->prepare("SELECT role_name FROM user_roles WHERE id = ?");
            $roleStmt->execute([$role_id]);
            $role_name = $roleStmt->fetchColumn();
            
            // Update user's role_id
            $stmt = $conn->prepare("UPDATE users SET role_id = ? WHERE id = ?");
            $stmt->execute([$role_id, $user_id]);
            
            // Log the activity
            $stmt = $conn->prepare("
                INSERT INTO activity_logs (
                    user_id, user_type, action, details, ip_address, user_agent
                ) VALUES (?, 'admin', ?, ?, ?, ?)
            ");
            
            $details = "Assigned role '$role_name' to user ID: $user_id";
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            
            $stmt->execute([$_SESSION['user_id'], "assign_user_role", $details, $ip, $user_agent]);
            
            $_SESSION['success'] = "User role has been updated successfully.";
        } catch (PDOException $e) {
            $_SESSION['error'] = "Database error: " . $e->getMessage();
        }
        
        header("Location: users.php");
        exit;
    } else {
        $_SESSION['error'] = "Invalid user or role selected.";
    }
}

// Get search and filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';
$role = isset($_GET['role']) ? $_GET['role'] : '';
$role_id = isset($_GET['role_id']) ? (int)$_GET['role_id'] : 0;
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'created_at';
$order = isset($_GET['order']) ? $_GET['order'] : 'DESC';

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Build query
$query = "SELECT u.*, r.role_name 
          FROM users u 
          LEFT JOIN user_roles r ON u.role_id = r.id 
          WHERE 1=1";
$countQuery = "SELECT COUNT(*) FROM users u LEFT JOIN user_roles r ON u.role_id = r.id WHERE 1=1";
$params = [];

if (!empty($search)) {
    $query .= " AND (u.username LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
    $countQuery .= " AND (u.username LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

if (!empty($status)) {
    $query .= " AND u.status = ?";
    $countQuery .= " AND u.status = ?";
    $params[] = $status;
}

if (!empty($role)) {
    $query .= " AND u.role = ?";
    $countQuery .= " AND u.role = ?";
    $params[] = $role;
}

if ($role_id > 0) {
    $query .= " AND u.role_id = ?";
    $countQuery .= " AND u.role_id = ?";
    $params[] = $role_id;
}

// Add sorting
$query .= " ORDER BY u.$sort $order";

// Add pagination
$query .= " LIMIT $per_page OFFSET $offset";

// Execute queries
try {
    // Get total count
    $stmt = $conn->prepare($countQuery);
    $stmt->execute($params);
    $total_users = $stmt->fetchColumn();
    
    // Get users for current page
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate total pages
    $total_pages = ceil($total_users / $per_page);
    
    // Get all roles for dropdown
    $rolesStmt = $conn->prepare("SELECT id, role_name FROM user_roles ORDER BY role_name");
    $rolesStmt->execute();
    $roles = $rolesStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get recent users
    $recentStmt = $conn->prepare("
        SELECT u.*, r.role_name 
        FROM users u 
        LEFT JOIN user_roles r ON u.role_id = r.id 
        ORDER BY u.created_at DESC 
        LIMIT 5
    ");
    $recentStmt->execute();
    $recent_users = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $_SESSION['error'] = "Database error: " . $e->getMessage();
    $users = [];
    $total_users = 0;
    $total_pages = 1;
    $roles = [];
    $recent_users = [];
}

// Generate pagination URL
function getPaginationUrl($page) {
    $params = $_GET;
    $params['page'] = $page;
    return '?' . http_build_query($params);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - FlexFit Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body class="bg-gray-900 text-white">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="ml-0 lg:ml-64 p-4 sm:p-6 transition-all duration-200">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
            <h1 class="text-2xl font-bold">Manage Users</h1>
            <div class="flex space-x-2">
                <a href="user_roles.php" class="bg-purple-600 hover:bg-purple-700 text-white font-bold py-2 px-4 rounded-lg transition-colors duration-200 flex items-center">
                    <i class="fas fa-user-tag mr-2"></i> Manage Roles
                </a>
                <a href="add_user.php" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg transition-colors duration-200 flex items-center">
                    <i class="fas fa-user-plus mr-2"></i> Add New User
                </a>
            </div>
        </div>
        
        <?php if (isset($_SESSION['success'])): ?>
        <div class="bg-green-600 text-white p-4 rounded-lg mb-6 animate-fade-in">
            <?php echo $_SESSION['success']; ?>
            <?php unset($_SESSION['success']); ?>
        </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
        <div class="bg-red-600 text-white p-4 rounded-lg mb-6 animate-fade-in">
            <?php echo $_SESSION['error']; ?>
            <?php unset($_SESSION['error']); ?>
        </div>
        <?php endif; ?>
        
        <!-- Filters -->
        <div class="bg-gray-800 rounded-xl shadow-lg overflow-hidden mb-6">
            <div class="p-4 border-b border-gray-700 flex justify-between items-center">
                <h2 class="text-lg font-semibold">Filter Users</h2>
                <a href="users.php" class="text-gray-400 hover:text-white text-sm">
                    <i class="fas fa-times mr-1"></i> Clear Filters
                </a>
            </div>
            
            <form action="" method="GET" class="p-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4">
                    <div>
                        <label for="search" class="block text-sm font-medium text-gray-400 mb-1">Search</label>
                        <input type="text" id="search" name="search" value="<?= htmlspecialchars($search) ?>" 
                            class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500"
                            placeholder="Username, email, phone...">
                    </div>
                    
                    <div>
                        <label for="status" class="block text-sm font-medium text-gray-400 mb-1">Status</label>
                        <select id="status" name="status" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                            <option value="">All Statuses</option>
                            <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            <option value="suspended" <?= $status === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                        </select>
                    </div>
                    
                    <div>
                        <label for="role" class="block text-sm font-medium text-gray-400 mb-1">User Type</label>
                        <select id="role" name="role" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                            <option value="">All Types</option>
                            <option value="member" <?= $role === 'member' ? 'selected' : '' ?>>Member</option>
                            <option value="admin" <?= $role === 'admin' ? 'selected' : '' ?>>Admin</option>
                            <option value="gym_partner" <?= $role === 'gym_partner' ? 'selected' : '' ?>>Gym Partner</option>
                        </select>
                    </div>
                    
                    <div>
                        <label for="role_id" class="block text-sm font-medium text-gray-400 mb-1">Role</label>
                        <select id="role_id" name="role_id" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                            <option value="">All Roles</option>
                            <?php foreach ($roles as $r): ?>
                                <option value="<?= $r['id'] ?>" <?= $role_id === (int)$r['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($r['role_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="mt-4 flex justify-end">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg transition-colors duration-200">
                        <i class="fas fa-filter mr-2"></i> Apply Filters
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Users Table -->
        <div class="bg-gray-800 rounded-xl shadow-lg overflow-hidden">
            <div class="p-6 border-b border-gray-700">
                <div class="flex justify-between items-center">
                    <div>
                        <h2 class="text-xl font-semibold">User Listings</h2>
                        <p class="text-gray-400 mt-1">
                            Showing <?php echo min($total_users, $offset + 1); ?> - <?php echo min($total_users, $offset + count($users)); ?> of <?php echo $total_users; ?> users
                        </p>
                    </div>
                </div>
            </div>
            
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-700">
                    <thead class="bg-gray-700">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">User</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Contact</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">City</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Balance</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Type</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Role</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Status</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Registered</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-gray-800 divide-y divide-gray-700">
                        <?php if (empty($users)): ?>
                            <tr>
                                <td colspan="9" class="px-6 py-4 text-center text-gray-400">
                                    No users found matching your criteria.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($users as $user): ?>
                                <tr class="hover:bg-gray-700 transition-colors duration-200">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <?php if (!empty($user['profile_image'])): ?>
                                                <img src="<?= htmlspecialchars($user['profile_image']) ?>" alt="Profile" class="h-10 w-10 rounded-full object-cover">
                                            <?php else: ?>
                                                <div class="h-10 w-10 rounded-full bg-gray-600 flex items-center justify-center">
                                                    <i class="fas fa-user text-gray-300"></i>
                                                </div>
                                            <?php endif; ?>
                                            <div class="ml-4">
                                                <div class="text-sm font-medium text-white"><?= htmlspecialchars($user['username']) ?></div>
                                                <div class="text-sm text-gray-400">ID: <?= $user['id'] ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-white"><?= htmlspecialchars($user['email']) ?></div>
                                        <?php if (!empty($user['phone'])): ?>
                                            <div class="text-sm text-gray-400"><?= htmlspecialchars($user['phone']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-white"><?= htmlspecialchars($user['city'] ?? 'N/A') ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-white">₹<?= number_format($user['balance'], 2) ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php if ($user['role'] === 'admin'): ?>
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-purple-900 text-purple-300">
                                                Admin
                                            </span>
                                        <?php elseif ($user['role'] === 'gym_partner'): ?>
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-900 text-blue-300">
                                                Gym Partner
                                            </span>
                                        <?php else: ?>
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-900 text-green-300">
                                                Member
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <button class="assign-role-btn text-indigo-400 hover:text-indigo-300" 
                                                data-user-id="<?= $user['id'] ?>" 
                                                data-username="<?= htmlspecialchars($user['username']) ?>"
                                                data-role-id="<?= $user['role_id'] ?? '' ?>">
                                            <?php if (!empty($user['role_name'])): ?>
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-indigo-900 text-indigo-300">
                                                    <?= htmlspecialchars($user['role_name']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-700 text-gray-300">
                                                    Assign Role
                                                </span>
                                            <?php endif; ?>
                                        </button>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php if ($user['status'] === 'active'): ?>
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-900 text-green-300">
                                                Active
                                            </span>
                                        <?php elseif ($user['status'] === 'inactive'): ?>
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-700 text-gray-300">
                                                Inactive
                                            </span>
                                        <?php else: ?>
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-900 text-red-300">
                                                Suspended
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-400">
                                        <?= date('M d, Y', strtotime($user['created_at'])) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <div class="flex space-x-2">
                                            <a href="edit_user.php?id=<?= $user['id'] ?>" class="text-indigo-400 hover:text-indigo-300" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="view_user.php?id=<?= $user['id'] ?>" class="text-blue-400 hover:text-blue-300" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            
                                            <?php if ($user['status'] === 'active'): ?>
                                                <a href="users.php?action=deactivate&id=<?= $user['id'] ?>" class="text-yellow-400 hover:text-yellow-300" title="Deactivate" 
                                                onclick="return confirm('Are you sure you want to deactivate this user?');">
                                                    <i class="fas fa-user-slash"></i>
                                                </a>
                                            <?php elseif ($user['status'] === 'inactive' || $user['status'] === 'suspended'): ?>
                                                <a href="users.php?action=activate&id=<?= $user['id'] ?>" class="text-green-400 hover:text-green-300" title="Activate" 
                                                onclick="return confirm('Are you sure you want to activate this user?');">
                                                    <i class="fas fa-user-check"></i>
                                                </a>
                                            <?php endif; ?>
                                            
                                            <?php if ($user['status'] !== 'suspended'): ?>
                                                <a href="users.php?action=suspend&id=<?= $user['id'] ?>" class="text-red-400 hover:text-red-300" title="Suspend" 
                                                onclick="return confirm('Are you sure you want to suspend this user?');">
                                                    <i class="fas fa-ban"></i>
                                                </a>
                                            <?php endif; ?>
                                            
                                            <a href="delete_user.php?id=<?= $user['id'] ?>" class="text-red-400 hover:text-red-300" title="Delete" 
                                            onclick="return confirm('Are you sure you want to delete this user? This action cannot be undone.');">
                                                <i class="fas fa-trash-alt"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="p-4 border-t border-gray-700 flex justify-between items-center">
                    <div class="text-sm text-gray-400">
                        Page <?php echo $page; ?> of <?php echo $total_pages; ?>
                    </div>
                    <div class="flex space-x-2">
                        <?php if ($page > 1): ?>
                            <a href="<?php echo getPaginationUrl(1); ?>" class="bg-gray-700 hover:bg-gray-600 text-white px-3 py-1 rounded-lg transition-colors duration-200">
                                <i class="fas fa-angle-double-left"></i>
                            </a>
                            <a href="<?php echo getPaginationUrl($page - 1); ?>" class="bg-gray-700 hover:bg-gray-600 text-white px-3 py-1 rounded-lg transition-colors duration-200">
                                <i class="fas fa-angle-left"></i>
                            </a>
                        <?php endif; ?>
                        
                        <?php
                        // Show a limited number of page links
                        $startPage = max(1, $page - 2);
                        $endPage = min($total_pages, $page + 2);
                        
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
                        if ($endPage < $total_pages) {
                            if ($endPage < $total_pages - 1) {
                                echo '<span class="text-gray-400 px-1">...</span>';
                            }
                            echo '<a href="' . getPaginationUrl($total_pages) . '" class="bg-gray-700 hover:bg-gray-600 text-white px-3 py-1 rounded-lg transition-colors duration-200">' . $total_pages . '</a>';
                        }
                        ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <a href="<?php echo getPaginationUrl($page + 1); ?>" class="bg-gray-700 hover:bg-gray-600 text-white px-3 py-1 rounded-lg transition-colors duration-200">
                                <i class="fas fa-angle-right"></i>
                            </a>
                            <a href="<?php echo getPaginationUrl($total_pages); ?>" class="bg-gray-700 hover:bg-gray-600 text-white px-3 py-1 rounded-lg transition-colors duration-200">
                                <i class="fas fa-angle-double-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
            <!-- Recent Registrations -->
            <div class="bg-gray-800 rounded-xl shadow-lg overflow-hidden my-8">
            <div class="px-6 py-5 border-b border-gray-700">
                <h3 class="text-lg font-semibold text-white">Recent Registrations</h3>
            </div>
            <div class="p-6">
                <?php if (empty($recent_users)): ?>
                    <p class="text-gray-400 text-center">No recent registrations found.</p>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($recent_users as $user): ?>
                            <div class="flex items-center justify-between p-4 bg-gray-700 rounded-lg">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0 h-10 w-10">
                                        <?php if (!empty($user['profile_image'])): ?>
                                            <img class="h-10 w-10 rounded-full object-cover" src="<?= htmlspecialchars($user['profile_image']) ?>" alt="Profile image">
                                        <?php else: ?>
                                            <div class="h-10 w-10 rounded-full bg-gray-600 flex items-center justify-center">
                                                <i class="fas fa-user text-gray-300"></i>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="ml-4">
                                        <div class="text-sm font-medium text-white"><?= htmlspecialchars($user['username']) ?></div>
                                        <div class="text-sm text-gray-400"><?= htmlspecialchars($user['email']) ?></div>
                                        <?php if (!empty($user['role_name'])): ?>
                                            <div class="text-xs text-indigo-400"><?= htmlspecialchars($user['role_name']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div class="text-sm text-gray-400">Joined</div>
                                    <div class="text-sm font-medium text-white"><?= date('M d, Y', strtotime($user['created_at'])) ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
    
    <!-- Assign Role Modal -->
    <div id="assignRoleModal" class="fixed inset-0 z-50 overflow-y-auto hidden">
        <div class="flex items-center justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <div class="fixed inset-0 transition-opacity" aria-hidden="true">
                <div class="absolute inset-0 bg-gray-900 opacity-75"></div>
            </div>
            
            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
            
            <div class="inline-block align-bottom bg-gray-800 rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
                <form method="POST" action="">
                    <input type="hidden" id="modal-user-id" name="user_id" value="">
                    
                    <div class="bg-gray-800 px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                        <div class="sm:flex sm:items-start">
                            <div class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-indigo-900 sm:mx-0 sm:h-10 sm:w-10">
                                <i class="fas fa-user-tag text-indigo-300"></i>
                            </div>
                            <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left">
                                <h3 class="text-lg leading-6 font-medium text-white" id="modal-title">
                                    Assign Role to User
                                </h3>
                                <div class="mt-2">
                                    <p class="text-sm text-gray-400" id="modal-description">
                                        Select a role to assign to this user.
                                    </p>
                                </div>
                                
                                <div class="mt-4">
                                    <label for="role_id" class="block text-sm font-medium text-gray-400">Role</label>
                                    <select id="modal-role-id" name="role_id" class="mt-1 block w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                                        <option value="">-- Select Role --</option>
                                        <?php foreach ($roles as $r): ?>
                                            <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['role_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-gray-700 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                        <button type="submit" name="assign_role" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-indigo-600 text-base font-medium text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:ml-3 sm:w-auto sm:text-sm">
                            Assign Role
                        </button>
                        <button type="button" id="closeRoleModal" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-600 shadow-sm px-4 py-2 bg-gray-800 text-base font-medium text-gray-300 hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        // Role assignment modal functionality
        const assignRoleButtons = document.querySelectorAll('.assign-role-btn');
        const assignRoleModal = document.getElementById('assignRoleModal');
        const closeRoleModalButton = document.getElementById('closeRoleModal');
        const modalUserId = document.getElementById('modal-user-id');
        const modalRoleId = document.getElementById('modal-role-id');
        const modalTitle = document.getElementById('modal-title');
        const modalDescription = document.getElementById('modal-description');
        
        assignRoleButtons.forEach(button => {
            button.addEventListener('click', () => {
                const userId = button.getAttribute('data-user-id');
                const username = button.getAttribute('data-username');
                const roleId = button.getAttribute('data-role-id');
                
                modalUserId.value = userId;
                modalRoleId.value = roleId;
                modalTitle.textContent = `Assign Role to ${username}`;
                modalDescription.textContent = `Select a role to assign to ${username}.`;
                
                assignRoleModal.classList.remove('hidden');
            });
        });
        
        closeRoleModalButton.addEventListener('click', () => {
            assignRoleModal.classList.add('hidden');
        });
        
        // Close modal when clicking outside
        window.addEventListener('click', (event) => {
            if (event.target === assignRoleModal) {
                assignRoleModal.classList.add('hidden');
            }
        });
        
        // Close modal with Escape key
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !assignRoleModal.classList.contains('hidden')) {
                assignRoleModal.classList.add('hidden');
            }
        });
    </script>
</body>
</html>


