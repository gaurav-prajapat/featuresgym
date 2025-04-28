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
$plans = [];
$gyms = [];
$totalPlans = 0;

// Pagination settings
$plansPerPage = 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max(1, $page);
$offset = ($page - 1) * $plansPerPage;

// Filter settings
$filterGym = isset($_GET['gym_id']) ? (int)$_GET['gym_id'] : 0;
$filterTier = isset($_GET['tier']) ? $_GET['tier'] : '';
$filterDuration = isset($_GET['duration']) ? $_GET['duration'] : '';
$filterStatus = isset($_GET['status']) ? $_GET['status'] : '';
$searchTerm = isset($_GET['search']) ? $_GET['search'] : '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Update plan status
    if (isset($_POST['update_status'])) {
        $planId = isset($_POST['plan_id']) ? (int)$_POST['plan_id'] : 0;
        $newStatus = isset($_POST['status']) ? $_POST['status'] : '';
        
        if ($planId > 0 && in_array($newStatus, ['active', 'inactive', 'archived'])) {
            try {
                $sql = "UPDATE gym_membership_plans SET status = ? WHERE plan_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$newStatus, $planId]);
                
                // Log the activity
                $activitySql = "
                    INSERT INTO activity_logs (
                        user_id, user_type, action, details, ip_address, user_agent
                    ) VALUES (
                        ?, 'admin', ?, ?, ?, ?
                    )
                ";
                $details = "Admin updated membership plan (ID: {$planId}) status to {$newStatus}";
                $activityStmt = $conn->prepare($activitySql);
                $activityStmt->execute([
                    $_SESSION['admin_id'],
                    'update_plan_status',
                    $details,
                    $_SERVER['REMOTE_ADDR'],
                    $_SERVER['HTTP_USER_AGENT']
                ]);
                
                $message = "Plan status has been updated to " . ucfirst($newStatus);
            } catch (PDOException $e) {
                $error = "Database error: " . $e->getMessage();
            }
        } else {
            $error = "Invalid plan ID or status.";
        }
    }
    
    // Delete plan
    if (isset($_POST['delete_plan'])) {
        $planId = isset($_POST['plan_id']) ? (int)$_POST['plan_id'] : 0;
        
        if ($planId > 0) {
            try {
                // Check if plan is in use
                $checkSql = "SELECT COUNT(*) FROM user_memberships WHERE plan_id = ?";
                $checkStmt = $conn->prepare($checkSql);
                $checkStmt->execute([$planId]);
                $usageCount = $checkStmt->fetchColumn();
                
                if ($usageCount > 0) {
                    // If plan is in use, just mark it as archived
                    $sql = "UPDATE gym_membership_plans SET status = 'archived' WHERE plan_id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([$planId]);
                    $message = "Plan has been archived because it is currently in use by members.";
                } else {
                    // If plan is not in use, delete it
                    $sql = "DELETE FROM gym_membership_plans WHERE plan_id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([$planId]);
                    $message = "Plan has been permanently deleted.";
                }
                
                // Log the activity
                $activitySql = "
                    INSERT INTO activity_logs (
                        user_id, user_type, action, details, ip_address, user_agent
                    ) VALUES (
                        ?, 'admin', ?, ?, ?, ?
                    )
                ";
                $action = $usageCount > 0 ? 'archive_plan' : 'delete_plan';
                $details = "Admin " . ($usageCount > 0 ? "archived" : "deleted") . " membership plan (ID: {$planId})";
                $activityStmt = $conn->prepare($activitySql);
                $activityStmt->execute([
                    $_SESSION['admin_id'],
                    $action,
                    $details,
                    $_SERVER['REMOTE_ADDR'],
                    $_SERVER['HTTP_USER_AGENT']
                ]);
            } catch (PDOException $e) {
                $error = "Database error: " . $e->getMessage();
            }
        } else {
            $error = "Invalid plan ID.";
        }
    }
    
    // Add new plan
    if (isset($_POST['add_plan'])) {
        $gymId = isset($_POST['gym_id']) ? (int)$_POST['gym_id'] : 0;
        $planName = isset($_POST['plan_name']) ? trim($_POST['plan_name']) : '';
        $tier = isset($_POST['tier']) ? $_POST['tier'] : '';
        $duration = isset($_POST['duration']) ? $_POST['duration'] : '';
        $price = isset($_POST['price']) ? (float)$_POST['price'] : 0;
        $inclusions = isset($_POST['inclusions']) ? trim($_POST['inclusions']) : '';
        $bestFor = isset($_POST['best_for']) ? trim($_POST['best_for']) : '';
        $status = isset($_POST['status']) ? $_POST['status'] : 'active';
        
        // Validate input
        $errors = [];
        
        if ($gymId <= 0) {
            $errors[] = "Please select a valid gym.";
        }
        
        if (empty($planName)) {
            $errors[] = "Plan name is required.";
        }
        
        if (empty($tier)) {
            $errors[] = "Tier is required.";
        }
        
        if (empty($duration)) {
            $errors[] = "Duration is required.";
        }
        
        if ($price <= 0) {
            $errors[] = "Price must be greater than zero.";
        }
        
        if (!empty($errors)) {
            $error = implode("<br>", $errors);
        } else {
            try {
                $sql = "INSERT INTO gym_membership_plans (
                            gym_id, plan_name, tier, duration, price, 
                            inclusions, best_for, status, created_at
                        ) VALUES (
                            ?, ?, ?, ?, ?, 
                            ?, ?, ?, NOW()
                        )";
                
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    $gymId, $planName, $tier, $duration, $price,
                    $inclusions, $bestFor, $status
                ]);
                
                $newPlanId = $conn->lastInsertId();
                
                // Log the activity
                $activitySql = "
                    INSERT INTO activity_logs (
                        user_id, user_type, action, details, ip_address, user_agent
                    ) VALUES (
                        ?, 'admin', ?, ?, ?, ?
                    )
                ";
                $details = "Admin created new membership plan (ID: {$newPlanId}, Name: {$planName})";
                $activityStmt = $conn->prepare($activitySql);
                $activityStmt->execute([
                    $_SESSION['admin_id'],
                    'create_plan',
                    $details,
                    $_SERVER['REMOTE_ADDR'],
                    $_SERVER['HTTP_USER_AGENT']
                ]);
                
                $message = "New membership plan has been created successfully.";
            } catch (PDOException $e) {
                $error = "Database error: " . $e->getMessage();
            }
        }
    }
    
    // Edit plan
    if (isset($_POST['edit_plan'])) {
        $planId = isset($_POST['plan_id']) ? (int)$_POST['plan_id'] : 0;
        $planName = isset($_POST['plan_name']) ? trim($_POST['plan_name']) : '';
        $tier = isset($_POST['tier']) ? $_POST['tier'] : '';
        $duration = isset($_POST['duration']) ? $_POST['duration'] : '';
        $price = isset($_POST['price']) ? (float)$_POST['price'] : 0;
        $inclusions = isset($_POST['inclusions']) ? trim($_POST['inclusions']) : '';
        $bestFor = isset($_POST['best_for']) ? trim($_POST['best_for']) : '';
        $status = isset($_POST['status']) ? $_POST['status'] : 'active';
        
        // Validate input
        $errors = [];
        
        if ($planId <= 0) {
            $errors[] = "Invalid plan ID.";
        }
        
        if (empty($planName)) {
            $errors[] = "Plan name is required.";
        }
        
        if (empty($tier)) {
            $errors[] = "Tier is required.";
        }
        
        if (empty($duration)) {
            $errors[] = "Duration is required.";
        }
        
        if ($price <= 0) {
            $errors[] = "Price must be greater than zero.";
        }
        
        if (!empty($errors)) {
            $error = implode("<br>", $errors);
        } else {
            try {
                $sql = "UPDATE gym_membership_plans SET
                            plan_name = ?, tier = ?, duration = ?, price = ?,
                            inclusions = ?, best_for = ?, status = ?, updated_at = NOW()
                        WHERE plan_id = ?";
                
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    $planName, $tier, $duration, $price,
                    $inclusions, $bestFor, $status, $planId
                ]);
                
                // Log the activity
                $activitySql = "
                    INSERT INTO activity_logs (
                        user_id, user_type, action, details, ip_address, user_agent
                    ) VALUES (
                        ?, 'admin', ?, ?, ?, ?
                    )
                ";
                $details = "Admin updated membership plan (ID: {$planId}, Name: {$planName})";
                $activityStmt = $conn->prepare($activitySql);
                $activityStmt->execute([
                    $_SESSION['admin_id'],
                    'update_plan',
                    $details,
                    $_SERVER['REMOTE_ADDR'],
                    $_SERVER['HTTP_USER_AGENT']
                ]);
                
                $message = "Membership plan has been updated successfully.";
            } catch (PDOException $e) {
                $error = "Database error: " . $e->getMessage();
            }
        }
    }
}

// Build query with filters
$sql = "SELECT gmp.*, g.name as gym_name, g.city, g.state,
        (SELECT COUNT(*) FROM user_memberships um WHERE um.plan_id = gmp.plan_id) as usage_count
        FROM gym_membership_plans gmp
        JOIN gyms g ON gmp.gym_id = g.gym_id
        WHERE 1=1";
$countSql = "SELECT COUNT(*) FROM gym_membership_plans gmp JOIN gyms g ON gmp.gym_id = g.gym_id WHERE 1=1";
$params = [];
$countParams = [];

if ($filterGym > 0) {
    $sql .= " AND gmp.gym_id = ?";
    $countSql .= " AND gmp.gym_id = ?";
    $params[] = $filterGym;
    $countParams[] = $filterGym;
}

if (!empty($filterTier)) {
    $sql .= " AND gmp.tier = ?";
    $countSql .= " AND gmp.tier = ?";
    $params[] = $filterTier;
    $countParams[] = $filterTier;
}

if (!empty($filterDuration)) {
    $sql .= " AND gmp.duration = ?";
    $countSql .= " AND gmp.duration = ?";
    $params[] = $filterDuration;
    $countParams[] = $filterDuration;
}

if (!empty($filterStatus)) {
    $sql .= " AND gmp.status = ?";
    $countSql .= " AND gmp.status = ?";
    $params[] = $filterStatus;
    $countParams[] = $filterStatus;
}

if (!empty($searchTerm)) {
    $sql .= " AND (gmp.plan_name LIKE ? OR g.name LIKE ? OR gmp.inclusions LIKE ?)";
    $countSql .= " AND (gmp.plan_name LIKE ? OR g.name LIKE ? OR gmp.inclusions LIKE ?)";
    $searchParam = "%$searchTerm%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $countParams[] = $searchParam;
    $countParams[] = $searchParam;
    $countParams[] = $searchParam;
}

// Get total count for pagination
$countStmt = $conn->prepare($countSql);
$countStmt->execute($countParams);
$totalPlans = $countStmt->fetchColumn();

// Add pagination to query
$sql .= " ORDER BY gmp.plan_id DESC LIMIT " . (int)$offset . ", " . (int)$plansPerPage;

// Fetch plans
try {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching plans: " . $e->getMessage();
}

// Fetch all gyms for dropdown
try {
    $gymsSql = "SELECT gym_id, name, city, state FROM gyms WHERE status = 'active' ORDER BY name";
    $gymsStmt = $conn->prepare($gymsSql);
    $gymsStmt->execute();
    $gyms = $gymsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching gyms: " . $e->getMessage();
}

// Get available tiers and durations
$tiers = ['Tier 1', 'Tier 2', 'Tier 3'];
$durations = ['Daily', 'Weekly', 'Monthly', '3 Months', '6 Months', 'Yearly'];
$statuses = ['active', 'inactive', 'archived'];

// Calculate pagination
$totalPages = ceil($totalPlans / $plansPerPage);
$prevPage = max(1, $page - 1);
$nextPage = min($totalPages, $page + 1);

// Generate pagination URL
function getPaginationUrl($page) {
    $params = $_GET;
    $params['page'] = $page;
    return '?' . http_build_query($params);
}

// Format currency
function formatCurrency($amount) {
    return '₹' . number_format($amount, 2);
}

// Format date
function formatDate($date) {
    return date('M d, Y', strtotime($date));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Membership Plans - FlexFit Admin</title>
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
        .plan-card {
            transition: all 0.3s ease;
        }
        .plan-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }
    </style>
</head>
<body class="bg-gray-900 text-white">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="ml-0 lg:ml-64 p-4 sm:p-6 transition-all duration-200">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
            <h1 class="text-2xl font-bold">Membership Plans</h1>
            <button id="openAddModal" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg transition-colors duration-200 flex items-center">
                <i class="fas fa-plus mr-2"></i> Add New Plan
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
        
        <!-- Filter Section -->
        <div class="bg-gray-800 rounded-xl shadow-lg overflow-hidden mb-6">
            <div class="p-4 border-b border-gray-700 flex justify-between items-center">
                <h2 class="text-lg font-semibold">Filter Plans</h2>
                <a href="membership_plans.php" class="text-gray-400 hover:text-white text-sm">
                    <i class="fas fa-times mr-1"></i> Clear Filters
                </a>
            </div>
            
            <form action="membership_plans.php" method="GET" class="p-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                    <div>
                        <label for="gym_id" class="block text-sm font-medium text-gray-400 mb-1">Gym</label>
                        <select id="gym_id" name="gym_id" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                            <option value="">All Gyms</option>
                            <?php foreach ($gyms as $gym): ?>
                                <option value="<?php echo $gym['gym_id']; ?>" <?php echo $filterGym == $gym['gym_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($gym['name']) . ' (' . htmlspecialchars($gym['city']) . ', ' . htmlspecialchars($gym['state']) . ')'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label for="tier" class="block text-sm font-medium text-gray-400 mb-1">Tier</label>
                        <select id="tier" name="tier" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                            <option value="">All Tiers</option>
                            <?php foreach ($tiers as $tier): ?>
                                <option value="<?php echo $tier; ?>" <?php echo $filterTier === $tier ? 'selected' : ''; ?>>
                                    <?php echo $tier; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label for="duration" class="block text-sm font-medium text-gray-400 mb-1">Duration</label>
                        <select id="duration" name="duration" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                            <option value="">All Durations</option>
                            <?php foreach ($durations as $duration): ?>
                                <option value="<?php echo $duration; ?>" <?php echo $filterDuration === $duration ? 'selected' : ''; ?>>
                                    <?php echo $duration; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label for="status" class="block text-sm font-medium text-gray-400 mb-1">Status</label>
                        <select id="status" name="status" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                            <option value="">All Statuses</option>
                            <?php foreach ($statuses as $status): ?>
                                <option value="<?php echo $status; ?>" <?php echo $filterStatus === $status ? 'selected' : ''; ?>>
                                    <?php echo ucfirst($status); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="md:col-span-2">
                        <label for="search" class="block text-sm font-medium text-gray-400 mb-1">Search</label>
                        <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($searchTerm); ?>" placeholder="Search by plan name, gym, or inclusions" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                    </div>
                </div>
                
                <div class="mt-4 flex justify-end">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg transition-colors duration-200">
                        <i class="fas fa-filter mr-2"></i> Apply Filters
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Plans Grid -->
        <div class="bg-gray-800 rounded-xl shadow-lg overflow-hidden">
            <div class="p-6 border-b border-gray-700">
                <div class="flex justify-between items-center">
                    <div>
                        <h2 class="text-xl font-semibold">Membership Plans</h2>
                        <p class="text-gray-400 mt-1">
                            Showing <?php echo min($totalPlans, $offset + 1); ?> - <?php echo min($totalPlans, $offset + count($plans)); ?> of <?php echo $totalPlans; ?> plans
                        </p>
                    </div>
                </div>
            </div>
            
            <?php if (empty($plans)): ?>
                <div class="p-6 text-center text-gray-500">
                    <i class="fas fa-search text-4xl mb-3"></i>
                    <p>No membership plans found matching your criteria.</p>
                </div>
            <?php else: ?>
                <div class="p-6 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php foreach ($plans as $plan): ?>
                        <?php
                            $statusClass = '';
                            $statusBadge = '';
                            
                            switch ($plan['status']) {
                                case 'active':
                                    $statusClass = 'bg-green-900 text-green-300';
                                    $statusBadge = '<span class="px-2 py-1 text-xs rounded-full bg-green-900 text-green-300">Active</span>';
                                    break;
                                case 'inactive':
                                    $statusClass = 'bg-yellow-900 text-yellow-300';
                                    $statusBadge = '<span class="px-2 py-1 text-xs rounded-full bg-yellow-900 text-yellow-300">Inactive</span>';
                                    break;
                                case 'archived':
                                    $statusClass = 'bg-red-900 text-red-300';
                                    $statusBadge = '<span class="px-2 py-1 text-xs rounded-full bg-red-900 text-red-300">Archived</span>';
                                    break;
                            }
                            
                            $tierClass = '';
                            switch ($plan['tier']) {
                                case 'Tier 1':
                                    $tierClass = 'border-green-500';
                                    break;
                                case 'Tier 2':
                                    $tierClass = 'border-blue-500';
                                    break;
                                case 'Tier 3':
                                    $tierClass = 'border-purple-500';
                                    break;
                                default:
                                    $tierClass = 'border-gray-500';
                            }
                        ?>
                        <div class="plan-card bg-gray-700 rounded-lg overflow-hidden border-l-4 <?php echo $tierClass; ?>">
                            <div class="p-4 border-b border-gray-600 flex justify-between items-center">
                                <h3 class="font-semibold text-lg"><?php echo htmlspecialchars($plan['plan_name']); ?></h3>
                                <?php echo $statusBadge; ?>
                            </div>
                            
                            <div class="p-4">
                                <div class="mb-3">
                                    <div class="text-sm text-gray-400">Gym</div>
                                    <div class="font-medium"><?php echo htmlspecialchars($plan['gym_name']); ?></div>
                                    <div class="text-xs text-gray-500"><?php echo htmlspecialchars($plan['city'] . ', ' . $plan['state']); ?></div>
                                </div>
                                
                                <div class="grid grid-cols-2 gap-3 mb-3">
                                    <div>
                                        <div class="text-sm text-gray-400">Tier</div>
                                        <div class="font-medium"><?php echo htmlspecialchars($plan['tier']); ?></div>
                                    </div>
                                    <div>
                                        <div class="text-sm text-gray-400">Duration</div>
                                        <div class="font-medium"><?php echo htmlspecialchars($plan['duration']); ?></div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="text-sm text-gray-400">Price</div>
                                    <div class="text-xl font-bold text-yellow-400"><?php echo formatCurrency($plan['price']); ?></div>
                                </div>
                                
                                <?php if (!empty($plan['inclusions'])): ?>
                                    <div class="mb-3">
                                        <div class="text-sm text-gray-400">Inclusions</div>
                                        <div class="text-sm mt-1"><?php echo nl2br(htmlspecialchars($plan['inclusions'])); ?></div>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="mb-3">
                                    <div class="text-sm text-gray-400">Best For</div>
                                    <div class="text-sm"><?php echo htmlspecialchars($plan['best_for']); ?></div>
                                </div>
                                
                                <div class="flex justify-between items-center mt-4">
                                    <div class="text-sm text-gray-400">
                                        <span class="font-medium"><?php echo $plan['usage_count']; ?></span> active members
                                    </div>
                                    <div class="flex space-x-2">
                                        <button class="text-blue-400 hover:text-blue-300 transition-colors duration-200 edit-plan-btn"
                                                data-id="<?php echo $plan['plan_id']; ?>"
                                                data-gym-id="<?php echo $plan['gym_id']; ?>"
                                                data-name="<?php echo htmlspecialchars($plan['plan_name']); ?>"
                                                data-tier="<?php echo htmlspecialchars($plan['tier']); ?>"
                                                data-duration="<?php echo htmlspecialchars($plan['duration']); ?>"
                                                data-price="<?php echo $plan['price']; ?>"
                                                data-inclusions="<?php echo htmlspecialchars($plan['inclusions']); ?>"
                                                data-best-for="<?php echo htmlspecialchars($plan['best_for']); ?>"
                                                data-status="<?php echo $plan['status']; ?>">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        
                                        <?php if ($plan['usage_count'] == 0): ?>
                                            <button class="text-red-400 hover:text-red-300 transition-colors duration-200 delete-plan-btn"
                                                    data-id="<?php echo $plan['plan_id']; ?>"
                                                    data-name="<?php echo htmlspecialchars($plan['plan_name']); ?>">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        <?php else: ?>
                                            <button class="text-gray-500 cursor-not-allowed" title="Cannot delete plans with active members">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
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
    
    <!-- Add Plan Modal -->
    <div id="addPlanModal" class="modal opacity-0 pointer-events-none fixed w-full h-full top-0 left-0 flex items-center justify-center z-50">
        <div class="modal-overlay absolute w-full h-full bg-black opacity-75"></div>
        
        <div class="modal-container bg-gray-800 w-11/12 md:max-w-md mx-auto rounded-xl shadow-lg z-50 overflow-y-auto max-h-[90vh]">
            <div class="modal-content py-4 text-left px-6">
                <div class="flex justify-between items-center pb-3 border-b border-gray-700">
                    <p class="text-xl font-bold">Add New Membership Plan</p>
                    <button class="modal-close cursor-pointer z-50">
                        <i class="fas fa-times text-gray-400 hover:text-white"></i>
                    </button>
                </div>
                
                <form method="POST" class="mt-4">
                    <div class="space-y-4">
                        <div>
                            <label for="add_gym_id" class="block text-sm font-medium text-gray-400 mb-1">Gym</label>
                            <select id="add_gym_id" name="gym_id" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                                <option value="">Select Gym</option>
                                <?php foreach ($gyms as $gym): ?>
                                    <option value="<?php echo $gym['gym_id']; ?>">
                                        <?php echo htmlspecialchars($gym['name']) . ' (' . htmlspecialchars($gym['city']) . ', ' . htmlspecialchars($gym['state']) . ')'; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label for="add_plan_name" class="block text-sm font-medium text-gray-400 mb-1">Plan Name</label>
                            <input type="text" id="add_plan_name" name="plan_name" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500" placeholder="e.g. Premium Monthly">
                        </div>
                        
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label for="add_tier" class="block text-sm font-medium text-gray-400 mb-1">Tier</label>
                                <select id="add_tier" name="tier" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                                    <option value="">Select Tier</option>
                                    <?php foreach ($tiers as $tier): ?>
                                        <option value="<?php echo $tier; ?>"><?php echo $tier; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div>
                                <label for="add_duration" class="block text-sm font-medium text-gray-400 mb-1">Duration</label>
                                <select id="add_duration" name="duration" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                                    <option value="">Select Duration</option>
                                    <?php foreach ($durations as $duration): ?>
                                        <option value="<?php echo $duration; ?>"><?php echo $duration; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div>
                            <label for="add_price" class="block text-sm font-medium text-gray-400 mb-1">Price (₹)</label>
                            <input type="number" id="add_price" name="price" required min="0" step="0.01" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500" placeholder="e.g. 1999.99">
                        </div>
                        
                        <div>
                            <label for="add_inclusions" class="block text-sm font-medium text-gray-400 mb-1">Inclusions</label>
                            <textarea id="add_inclusions" name="inclusions" rows="3" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500" placeholder="List what's included in this plan..."></textarea>
                        </div>
                        
                        <div>
                            <label for="add_best_for" class="block text-sm font-medium text-gray-400 mb-1">Best For</label>
                            <input type="text" id="add_best_for" name="best_for" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500" placeholder="e.g. Beginners, Advanced users, etc.">
                        </div>
                        
                        <div>
                            <label for="add_status" class="block text-sm font-medium text-gray-400 mb-1">Status</label>
                            <select id="add_status" name="status" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mt-6 flex justify-end">
                        <button type="button" class="modal-close bg-gray-600 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded-lg mr-2">
                            Cancel
                        </button>
                        <button type="submit" name="add_plan" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg">
                            <i class="fas fa-plus mr-2"></i> Add Plan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Edit Plan Modal -->
    <div id="editPlanModal" class="modal opacity-0 pointer-events-none fixed w-full h-full top-0 left-0 flex items-center justify-center z-50">
        <div class="modal-overlay absolute w-full h-full bg-black opacity-75"></div>
        
        <div class="modal-container bg-gray-800 w-11/12 md:max-w-md mx-auto rounded-xl shadow-lg z-50 overflow-y-auto max-h-[90vh]">
            <div class="modal-content py-4 text-left px-6">
                <div class="flex justify-between items-center pb-3 border-b border-gray-700">
                    <p class="text-xl font-bold">Edit Membership Plan</p>
                    <button class="modal-close cursor-pointer z-50">
                        <i class="fas fa-times text-gray-400 hover:text-white"></i>
                    </button>
                </div>
                
                <form method="POST" class="mt-4">
                    <input type="hidden" id="edit_plan_id" name="plan_id" value="">
                    
                    <div class="space-y-4">
                        <div>
                            <label for="edit_gym_id" class="block text-sm font-medium text-gray-400 mb-1">Gym</label>
                            <select id="edit_gym_id" name="gym_id" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500" disabled>
                                <?php foreach ($gyms as $gym): ?>
                                    <option value="<?php echo $gym['gym_id']; ?>">
                                        <?php echo htmlspecialchars($gym['name']) . ' (' . htmlspecialchars($gym['city']) . ', ' . htmlspecialchars($gym['state']) . ')'; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="text-xs text-gray-400 mt-1">Gym cannot be changed for existing plans.</p>
                        </div>
                        
                        <div>
                            <label for="edit_plan_name" class="block text-sm font-medium text-gray-400 mb-1">Plan Name</label>
                            <input type="text" id="edit_plan_name" name="plan_name" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                        </div>
                        
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label for="edit_tier" class="block text-sm font-medium text-gray-400 mb-1">Tier</label>
                                <select id="edit_tier" name="tier" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                                    <?php foreach ($tiers as $tier): ?>
                                        <option value="<?php echo $tier; ?>"><?php echo $tier; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div>
                                <label for="edit_duration" class="block text-sm font-medium text-gray-400 mb-1">Duration</label>
                                <select id="edit_duration" name="duration" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                                    <?php foreach ($durations as $duration): ?>
                                        <option                                         <option value="<?php echo $duration; ?>"><?php echo $duration; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div>
                            <label for="edit_price" class="block text-sm font-medium text-gray-400 mb-1">Price (₹)</label>
                            <input type="number" id="edit_price" name="price" required min="0" step="0.01" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                        </div>
                        
                        <div>
                            <label for="edit_inclusions" class="block text-sm font-medium text-gray-400 mb-1">Inclusions</label>
                            <textarea id="edit_inclusions" name="inclusions" rows="3" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500"></textarea>
                        </div>
                        
                        <div>
                            <label for="edit_best_for" class="block text-sm font-medium text-gray-400 mb-1">Best For</label>
                            <input type="text" id="edit_best_for" name="best_for" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                        </div>
                        
                        <div>
                            <label for="edit_status" class="block text-sm font-medium text-gray-400 mb-1">Status</label>
                            <select id="edit_status" name="status" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="archived">Archived</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mt-6 flex justify-end">
                        <button type="button" class="modal-close bg-gray-600 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded-lg mr-2">
                            Cancel
                        </button>
                        <button type="submit" name="edit_plan" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg">
                            <i class="fas fa-save mr-2"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Delete Plan Modal -->
    <div id="deletePlanModal" class="modal opacity-0 pointer-events-none fixed w-full h-full top-0 left-0 flex items-center justify-center z-50">
        <div class="modal-overlay absolute w-full h-full bg-black opacity-75"></div>
        
        <div class="modal-container bg-gray-800 w-11/12 md:max-w-md mx-auto rounded-xl shadow-lg z-50">
            <div class="modal-content py-4 text-left px-6">
                <div class="flex justify-between items-center pb-3 border-b border-gray-700">
                    <p class="text-xl font-bold text-red-500">Delete Membership Plan</p>
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
                                <p class="text-sm text-red-300 mt-1">You are about to delete the plan: <span id="delete_plan_name" class="font-semibold"></span></p>
                            </div>
                        </div>
                    </div>
                    
                    <form method="POST">
                        <input type="hidden" id="delete_plan_id" name="plan_id" value="">
                        
                        <div class="mt-6 flex justify-end">
                            <button type="button" class="modal-close bg-gray-600 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded-lg mr-2">
                                Cancel
                            </button>
                            <button type="submit" name="delete_plan" class="bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded-lg">
                                <i class="fas fa-trash-alt mr-2"></i> Delete Plan
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
        
        // Open add modal
        document.getElementById('openAddModal').addEventListener('click', function() {
            toggleModal('addPlanModal');
        });
        
        // Edit plan
        document.querySelectorAll('.edit-plan-btn').forEach(button => {
            button.addEventListener('click', function() {
                const planId = this.getAttribute('data-id');
                const gymId = this.getAttribute('data-gym-id');
                const name = this.getAttribute('data-name');
                const tier = this.getAttribute('data-tier');
                const duration = this.getAttribute('data-duration');
                const price = this.getAttribute('data-price');
                const inclusions = this.getAttribute('data-inclusions');
                const bestFor = this.getAttribute('data-best-for');
                const status = this.getAttribute('data-status');
                
                // Set values in the edit modal
                document.getElementById('edit_plan_id').value = planId;
                document.getElementById('edit_gym_id').value = gymId;
                document.getElementById('edit_plan_name').value = name;
                document.getElementById('edit_tier').value = tier;
                document.getElementById('edit_duration').value = duration;
                document.getElementById('edit_price').value = price;
                document.getElementById('edit_inclusions').value = inclusions;
                document.getElementById('edit_best_for').value = bestFor;
                document.getElementById('edit_status').value = status;
                
                toggleModal('editPlanModal');
            });
        });
        
        // Delete plan
        document.querySelectorAll('.delete-plan-btn').forEach(button => {
            button.addEventListener('click', function() {
                const planId = this.getAttribute('data-id');
                const name = this.getAttribute('data-name');
                
                document.getElementById('delete_plan_id').value = planId;
                document.getElementById('delete_plan_name').textContent = name;
                
                toggleModal('deletePlanModal');
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
        
        // Highlight table rows on hover
        document.querySelectorAll('.plan-card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.classList.add('transform', 'scale-[1.02]', 'shadow-lg');
            });
            
            card.addEventListener('mouseleave', function() {
                this.classList.remove('transform', 'scale-[1.02]', 'shadow-lg');
            });
        });
        
        // Form validation for add plan
        document.querySelector('form[name="add_plan"]')?.addEventListener('submit', function(e) {
            const price = parseFloat(document.getElementById('add_price').value);
            
            if (price <= 0) {
                e.preventDefault();
                alert('Price must be greater than zero.');
                return false;
            }
            
            return true;
        });
        
        // Form validation for edit plan
        document.querySelector('form[name="edit_plan"]')?.addEventListener('submit', function(e) {
            const price = parseFloat(document.getElementById('edit_price').value);
            
            if (price <= 0) {
                e.preventDefault();
                alert('Price must be greater than zero.');
                return false;
            }
            
            return true;
        });
    </script>
</body>
</html>



