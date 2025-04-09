<?php
require_once '../config/database.php';
session_start();

// Ensure user is authenticated and has admin role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['error'] = "You don't have permission to access this page.";
    header('Location: ../login.php');
    exit();
}

$db = new GymDatabase();
$conn = $db->getConnection();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        // Add new coupon
        if ($_POST['action'] === 'add') {
            $code = strtoupper(trim($_POST['code']));
            $description = trim($_POST['description']);
            $discount_type = $_POST['discount_type'];
            $discount_value = (float)$_POST['discount_value'];
            $applicable_to_type = $_POST['applicable_to_type'];
            $applicable_to_id = ($applicable_to_type !== 'all') ? (int)$_POST['applicable_to_id'] : null;
            $min_purchase_amount = !empty($_POST['min_purchase_amount']) ? (float)$_POST['min_purchase_amount'] : null;
            $usage_limit = !empty($_POST['usage_limit']) ? (int)$_POST['usage_limit'] : null;
            $expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            // Validate input
            $errors = [];
            
            if (empty($code)) {
                $errors[] = "Coupon code is required.";
            } elseif (strlen($code) < 3 || strlen($code) > 20) {
                $errors[] = "Coupon code must be between 3 and 20 characters.";
            }
            
            if ($discount_type === 'percentage' && ($discount_value <= 0 || $discount_value > 100)) {
                $errors[] = "Percentage discount must be between 1 and 100.";
            } elseif ($discount_type === 'fixed' && $discount_value <= 0) {
                $errors[] = "Fixed discount must be greater than 0.";
            }
            
            // Check if coupon code already exists
            $stmt = $conn->prepare("SELECT id FROM coupons WHERE code = ?");
            $stmt->execute([$code]);
            if ($stmt->rowCount() > 0) {
                $errors[] = "Coupon code already exists.";
            }
            
            if (empty($errors)) {
                try {
                    $stmt = $conn->prepare("
                    INSERT INTO coupons (
                        code, description, discount_type, discount_value, 
                        applicable_to_type, applicable_to_id, min_purchase_amount, 
                        usage_limit, is_active, expiry_date
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $code, $description, $discount_type, $discount_value,
                    $applicable_to_type, $applicable_to_id, $min_purchase_amount,
                    $usage_limit, $is_active, $expiry_date
                ]);
                
                    
                    // Log admin activity
                    $stmt = $conn->prepare("
                        INSERT INTO activity_logs (
                            user_id, user_type, action, details, ip_address, user_agent
                        ) VALUES (?, 'admin', 'create_coupon', ?, ?, ?)
                    ");
                    $details = "Created new coupon: $code";
                    $stmt->execute([
                        $_SESSION['user_id'],
                        $details,
                        $_SERVER['REMOTE_ADDR'],
                        $_SERVER['HTTP_USER_AGENT']
                    ]);
                    
                    $_SESSION['success'] = "Coupon created successfully.";
                    header("Location: coupons.php");
                    exit();
                } catch (PDOException $e) {
                    $_SESSION['error'] = "Database error: " . $e->getMessage();
                }
            } else {
                $_SESSION['error'] = implode("<br>", $errors);
            }
        }
        
        // Update existing coupon
        elseif ($_POST['action'] === 'update' && isset($_POST['coupon_id'])) {
            $coupon_id = (int)$_POST['coupon_id'];
            $description = trim($_POST['description']);
            $discount_type = $_POST['discount_type'];
            $discount_value = (float)$_POST['discount_value'];
            $applicable_to_type = $_POST['applicable_to_type'];
            $applicable_to_id = ($applicable_to_type !== 'all') ? (int)$_POST['applicable_to_id'] : null;
            $min_purchase_amount = !empty($_POST['min_purchase_amount']) ? (float)$_POST['min_purchase_amount'] : null;
            $usage_limit = !empty($_POST['usage_limit']) ? (int)$_POST['usage_limit'] : null;
            $expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            // Validate input
            $errors = [];
            
            if ($discount_type === 'percentage' && ($discount_value <= 0 || $discount_value > 100)) {
                $errors[] = "Percentage discount must be between 1 and 100.";
            } elseif ($discount_type === 'fixed' && $discount_value <= 0) {
                $errors[] = "Fixed discount must be greater than 0.";
            }
            
            if (empty($errors)) {
                try {
                    $stmt = $conn->prepare("
                        UPDATE coupons SET
                            description = ?,
                            discount_type = ?,
                            discount_value = ?,
                            applicable_to_type = ?,
                            applicable_to_id = ?,
                            min_purchase_amount = ?,
                            usage_limit = ?,
                            is_active = ?,
                            expiry_date = ?
                        WHERE id = ?
                    ");
                    
                    $stmt->execute([
                        $description, $discount_type, $discount_value,
                        $applicable_to_type, $applicable_to_id, $min_purchase_amount,
                        $usage_limit, $is_active, $expiry_date, $coupon_id
                    ]);
                    
                    // Get coupon code for activity log
                    $stmt = $conn->prepare("SELECT code FROM coupons WHERE id = ?");
                    $stmt->execute([$coupon_id]);
                    $coupon = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    // Log admin activity
                    $stmt = $conn->prepare("
                        INSERT INTO activity_logs (
                            user_id, user_type, action, details, ip_address, user_agent
                        ) VALUES (?, 'admin', 'update_coupon', ?, ?, ?)
                    ");
                    $details = "Updated coupon: " . $coupon['code'];
                    $stmt->execute([
                        $_SESSION['user_id'],
                        $details,
                        $_SERVER['REMOTE_ADDR'],
                        $_SERVER['HTTP_USER_AGENT']
                    ]);
                    
                    $_SESSION['success'] = "Coupon updated successfully.";
                    header("Location: coupons.php");
                    exit();
                } catch (PDOException $e) {
                    $_SESSION['error'] = "Database error: " . $e->getMessage();
                }
            } else {
                $_SESSION['error'] = implode("<br>", $errors);
            }
        }
        
        // Delete coupon
        elseif ($_POST['action'] === 'delete' && isset($_POST['coupon_id'])) {
            $coupon_id = (int)$_POST['coupon_id'];
            
            try {
                // Get coupon code for activity log
                $stmt = $conn->prepare("SELECT code FROM coupons WHERE id = ?");
                $stmt->execute([$coupon_id]);
                $coupon = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Delete the coupon
                $stmt = $conn->prepare("DELETE FROM coupons WHERE id = ?");
                $stmt->execute([$coupon_id]);
                
                // Log admin activity
                $stmt = $conn->prepare("
                    INSERT INTO activity_logs (
                        user_id, user_type, action, details, ip_address, user_agent
                    ) VALUES (?, 'admin', 'delete_coupon', ?, ?, ?)
                ");
                $details = "Deleted coupon: " . $coupon['code'];
                $stmt->execute([
                    $_SESSION['user_id'],
                    $details,
                    $_SERVER['REMOTE_ADDR'],
                    $_SERVER['HTTP_USER_AGENT']
                ]);
                
                $_SESSION['success'] = "Coupon deleted successfully.";
                header("Location: coupons.php");
                exit();
            } catch (PDOException $e) {
                $_SESSION['error'] = "Database error: " . $e->getMessage();
            }
        }
    }
}

// Pagination variables
$limit = 10; // Number of records per page
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Handle filters
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? 'all';
$type = $_GET['type'] ?? 'all';

// Build query with filters
$query = "SELECT * FROM coupons WHERE 1=1";
$countQuery = "SELECT COUNT(*) FROM coupons WHERE 1=1";

$queryParams = [];
$countParams = [];

if (!empty($search)) {
    $query .= " AND (code LIKE ? OR description LIKE ?)";
    $countQuery .= " AND (code LIKE ? OR description LIKE ?)";
    $queryParams[] = "%$search%";
    $queryParams[] = "%$search%";
    $countParams[] = "%$search%";
    $countParams[] = "%$search%";
}

if ($status !== 'all') {
    $is_active = ($status === 'active') ? 1 : 0;
    $query .= " AND is_active = ?";
    $countQuery .= " AND is_active = ?";
    $queryParams[] = $is_active;
    $countParams[] = $is_active;
}

if ($type !== 'all') {
    $query .= " AND discount_type = ?";
    $countQuery .= " AND discount_type = ?";
    $queryParams[] = $type;
    $countParams[] = $type;
}

// Remove the placeholders for LIMIT and OFFSET
$query .= " ORDER BY created_at DESC";

// Get total count for pagination
$countStmt = $conn->prepare($countQuery);
$countStmt->execute($countParams);
$totalCoupons = $countStmt->fetchColumn();

// Add LIMIT and OFFSET directly to the query string
$query .= " LIMIT $limit OFFSET $offset";

// Get coupons for current page
$stmt = $conn->prepare($query);
$stmt->execute($queryParams); // No need to include $limit and $offset in params

// Execute queries
try {
     // Get total count for pagination
     $countStmt = $conn->prepare($countQuery);
     $countStmt->execute($countParams);
     $totalCoupons = $countStmt->fetchColumn();
     
     // Get coupons for current page
     $stmt = $conn->prepare($query);
     $stmt->execute($queryParams);
     $coupons = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate total pages
    $totalPages = ceil($totalCoupons / $limit);
    
    // Fetch gyms for dropdown
    $gymsStmt = $conn->query("SELECT gym_id, name FROM gyms ORDER BY name ASC");
    $gyms = $gymsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch plans for dropdown
    $plansStmt = $conn->query("SELECT plan_id, plan_name FROM gym_membership_plans ORDER BY plan_name ASC");
    $plans = $plansStmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $_SESSION['error'] = "Database error: " . $e->getMessage();
    $coupons = [];
    $totalPages = 0;
    $gyms = [];
    $plans = [];
}

// Function to generate pagination URL
function getPaginationUrl($pageNum) {
    global $search, $status, $type;
    return "coupons.php?page=$pageNum&search=" . urlencode($search) . "&status=$status&type=$type";
}

// Count pending gyms for sidebar
$pendingGymsStmt = $conn->prepare("SELECT COUNT(*) FROM gyms WHERE status = 'pending'");
$pendingGymsStmt->execute();
$pendingGyms = $pendingGymsStmt->fetchColumn();

// Count pending reviews for sidebar
$pendingReviewsStmt = $conn->prepare("SELECT COUNT(*) FROM reviews WHERE status = 'pending'");
$pendingReviewsStmt->execute();
$pendingReviews = $pendingReviewsStmt->fetchColumn();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Coupons - FlexFit Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body class="bg-gray-900 text-white">

    <!-- Include sidebar -->
    <?php include 'includes/sidebar.php'; ?>

    <!-- Main content -->
    <div class="lg:ml-64 p-8">
        <div class="container mx-auto">
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

            <div class="flex justify-between items-center mb-6">
                <h1 class="text-2xl font-bold text-white">Manage Coupons</h1>
                <button onclick="openAddCouponModal()" class="bg-yellow-500 hover:bg-yellow-600 text-black px-4 py-2 rounded-lg transition-colors duration-200">
                    <i class="fas fa-plus mr-2"></i> Create New Coupon
                </button>
            </div>

            <!-- Filters -->
            <div class="bg-gray-800 rounded-lg shadow-lg p-6 mb-8">
                <form class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-1">Search</label>
                        <input type="text" name="search" value="<?= htmlspecialchars($search); ?>" placeholder="Code or description" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-1">Status</label>
                        <select name="status" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                            <option value="all" <?= $status === 'all' ? 'selected' : ''; ?>>All</option>
                            <option value="active" <?= $status === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?= $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-1">Discount Type</label>
                        <select name="type" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                            <option value="all" <?= $type === 'all' ? 'selected' : ''; ?>>All</option>
                            <option value="percentage" <?= $type === 'percentage' ? 'selected' : ''; ?>>Percentage</option>
                            <option value="fixed" <?= $type === 'fixed' ? 'selected' : ''; ?>>Fixed Amount</option>
                        </select>
                    </div>
                    <div class="flex items-end">
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors duration-200 w-full">
                            <i class="fas fa-filter mr-2"></i> Apply Filters
                        </button>
                    </div>
                </form>
            </div>

            <!-- Coupons Table -->
            <div class="bg-gray-800 rounded-lg shadow-lg overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-700">
                        <thead class="bg-gray-700">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                    Code
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                    Discount
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                    Applicable To
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                    Usage / Limit
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                    Expiry Date
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                    Status
                                </th>
                                <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-300 uppercase tracking-wider">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-gray-800 divide-y divide-gray-700">
                            <?php if (empty($coupons)): ?>
                                <tr>
                                    <td colspan="7" class="px-6 py-4 text-center text-gray-400">
                                        No coupons found matching your criteria.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($coupons as $coupon): ?>
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="flex-shrink-0 h-10 w-10 flex items-center justify-center bg-yellow-500 text-black rounded-full">
                                                    <i class="fas fa-ticket-alt"></i>
                                                </div>
                                                <div class="ml-4">
                                                    <div class="text-sm font-medium text-white">
                                                        <?= htmlspecialchars($coupon['code']); ?>
                                                    </div>
                                                    <div class="text-sm text-gray-400">
                                                        <?= htmlspecialchars(substr($coupon['description'], 0, 30)); ?>
                                                        <?= strlen($coupon['description']) > 30 ? '...' : ''; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-white">
                                                <?php if ($coupon['discount_type'] === 'percentage'): ?>
                                                    <?= htmlspecialchars($coupon['discount_value']); ?>%
                                                <?php else: ?>
                                                    ₹<?= number_format($coupon['discount_value'], 2); ?>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-xs text-gray-400">
                                                <?= $coupon['discount_type'] === 'percentage' ? 'Percentage' : 'Fixed Amount'; ?>
                                            </div>
                                            <?php if ($coupon['min_purchase_amount']): ?>
                                                <div class="text-xs text-gray-500">
                                                    Min. purchase: ₹<?= number_format($coupon['min_purchase_amount'], 2); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php if ($coupon['applicable_to_type'] === 'all'): ?>
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-900 text-blue-300">
                                                    All
                                                </span>
                                            <?php elseif ($coupon['applicable_to_type'] === 'gym'): ?>
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-900 text-green-300">
                                                    Specific Gym
                                                </span>
                                            <?php elseif ($coupon['applicable_to_type'] === 'plan'): ?>
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-purple-900 text-purple-300">
                                                    Specific Plan
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-400">
                                            <?= $coupon['usage_count']; ?> 
                                            <?php if ($coupon['usage_limit']): ?>
                                                / <?= $coupon['usage_limit']; ?>
                                            <?php else: ?>
                                                / ∞
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-400">
                                            <?php if ($coupon['expiry_date']): ?>
                                                <?php 
                                                $expiry_date = new DateTime($coupon['expiry_date']);
                                                $today = new DateTime();
                                                $is_expired = $expiry_date < $today;
                                                ?>
                                                <span class="<?= $is_expired ? 'text-red-400' : 'text-gray-400'; ?>">
                                                    <?= $expiry_date->format('M d, Y'); ?>
                                                </span>
                                                <?php if ($is_expired): ?>
                                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-900 text-red-300">
                                                        Expired
                                                    </span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-gray-500">No expiry</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php if ($coupon['is_active']): ?>
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-900 text-green-300">
                                                    Active
                                                </span>
                                            <?php else: ?>
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-700 text-gray-300">
                                                    Inactive
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                            <div class="flex justify-end space-x-2">
                                                <button onclick="openEditCouponModal(<?= htmlspecialchars(json_encode($coupon)); ?>)" class="text-blue-400 hover:text-blue-300" title="Edit Coupon">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                
                                                <form method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete this coupon?');">
                                                    <input type="hidden" name="coupon_id" value="<?= $coupon['id']; ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <button type="submit" class="text-red-400 hover:text-red-300" title="Delete Coupon">
                                                        <i class="fas fa-trash-alt"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="p-4 border-t border-gray-700 flex justify-between items-center">
                        <div class="text-sm text-gray-400">
                            Showing <?= ($offset + 1) ?>-<?= min($offset + $limit, $totalCoupons) ?> of <?= $totalCoupons ?> coupons
                        </div>
                        <div class="flex space-x-2">
                            <?php if ($page > 1): ?>
                                <a href="<?= getPaginationUrl(1); ?>" class="bg-gray-700 hover:bg-gray-600 text-white px-3 py-1 rounded-lg transition-colors duration-200">
                                    <i class="fas fa-angle-double-left"></i>
                                </a>
                                <a href="<?= getPaginationUrl($page - 1); ?>" class="bg-gray-700 hover:bg-gray-600 text-white px-3 py-1 rounded-lg transition-colors duration-200">
                                    <i class="fas fa-angle-left"></i>
                                </a>
                            <?php endif; ?>
                            
                            <?php
                            // Show a limited number of page links
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            
                            for ($i = $startPage; $i <= $endPage; $i++):
                            ?>
                                <a href="<?= getPaginationUrl($i); ?>" class="<?= $i === $page ? 'bg-blue-600' : 'bg-gray-700 hover:bg-gray-600'; ?> text-white px-3 py-1 rounded-lg transition-colors duration-200">
                                    <?= $i ?>
                                </a>
                            <?php endfor; ?>
                            
                            <?php if ($page < $totalPages): ?>
                                <a href="<?= getPaginationUrl($page + 1); ?>" class="bg-gray-700 hover:bg-gray-600 text-white px-3 py-1 rounded-lg transition-colors duration-200">
                                    <i class="fas fa-angle-right"></i>
                                </a>
                                <a href="<?= getPaginationUrl($totalPages); ?>" class="bg-gray-700 hover:bg-gray-600 text-white px-3 py-1 rounded-lg transition-colors duration-200">
                                    <i class="fas fa-angle-double-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Add Coupon Modal -->
    <div id="addCouponModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
        <div class="bg-gray-800 rounded-lg shadow-lg p-6 w-full max-w-2xl">
            <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-semibold text-white">Create New Coupon</h3>
                <button onclick="closeAddCouponModal()" class="text-gray-400 hover:text-white focus:outline-none">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form method="POST" id="addCouponForm">
                <input type="hidden" name="action" value="add">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label for="code" class="block text-sm font-medium text-gray-300 mb-1">Coupon Code*</label>
                        <input type="text" name="code" id="code" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500" placeholder="e.g. SUMMER2023">
                        <p class="text-xs text-gray-400 mt-1">Unique code for the coupon (3-20 characters)</p>
                    </div>
                    
                    <div>
                        <label for="discount_type" class="block text-sm font-medium text-gray-300 mb-1">Discount Type*</label>
                        <select name="discount_type" id="discount_type" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                            <option value="percentage">Percentage</option>
                            <option value="fixed">Fixed Amount</option>
                        </select>
                    </div>
                    
                    <div>
                        <label for="discount_value" class="block text-sm font-medium text-gray-300 mb-1">Discount Value*</label>
                        <input type="number" name="discount_value" id="discount_value" required min="1" step="0.01" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500" placeholder="e.g. 10">
                        <p class="text-xs text-gray-400 mt-1">For percentage: 1-100, for fixed amount: any positive value</p>
                    </div>
                    
                    <div>
                        <label for="min_purchase_amount" class="block text-sm font-medium text-gray-300 mb-1">Minimum Purchase Amount</label>
                        <input type="number" name="min_purchase_amount" id="min_purchase_amount" min="0" step="0.01" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500" placeholder="e.g. 1000">
                        <p class="text-xs text-gray-400 mt-1">Leave empty for no minimum</p>
                    </div>
                    
                    <div>
                        <label for="applicable_to_type" class="block text-sm font-medium text-gray-300 mb-1">Applicable To*</label>
                        <select name="applicable_to_type" id="applicable_to_type" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500" onchange="toggleApplicableToId()">
                            <option value="all">All</option>
                            <option value="gym">Specific Gym</option>
                            <option value="plan">Specific Plan</option>
                        </select>
                    </div>
                    
                    <div id="applicable_to_id_container" class="hidden">
                        <label for="applicable_to_id" class="block text-sm font-medium text-gray-300 mb-1">Select Specific Item*</label>
                        <select name="applicable_to_id" id="applicable_to_id" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                            <option value="">Select...</option>
                            <!-- Options will be populated by JavaScript -->
                        </select>
                    </div>
                    
                    <div>
                        <label for="usage_limit" class="block text-sm font-medium text-gray-300 mb-1">Usage Limit</label>
                        <input type="number" name="usage_limit" id="usage_limit" min="1" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500" placeholder="e.g. 100">
                        <p class="text-xs text-gray-400 mt-1">Leave empty for unlimited usage</p>
                    </div>
                    
                    <div>
                        <label for="expiry_date" class="block text-sm font-medium text-gray-300 mb-1">Expiry Date</label>
                        <input type="date" name="expiry_date" id="expiry_date" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                        <p class="text-xs text-gray-400 mt-1">Leave empty for no expiry</p>
                    </div>
                    
                    <div class="flex items-center">
                        <input type="checkbox" name="is_active" id="is_active" checked class="h-4 w-4 text-yellow-500 focus:ring-yellow-500 border-gray-600 rounded">
                        <label for="is_active" class="ml-2 block text-sm text-gray-300">Active</label>
                    </div>
                </div>
                
                <div class="mb-4">
                    <label for="description" class="block text-sm font-medium text-gray-300 mb-1">Description</label>
                    <textarea name="description" id="description" rows="3" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500" placeholder="Describe the coupon and its conditions"></textarea>
                </div>
                
                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="closeAddCouponModal()" class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                        Cancel
                    </button>
                    <button type="submit" class="bg-yellow-500 hover:bg-yellow-600 text-black px-4 py-2 rounded-lg transition-colors duration-200">
                        Create Coupon
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Coupon Modal -->
    <div id="editCouponModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
        <div class="bg-gray-800 rounded-lg shadow-lg p-6 w-full max-w-2xl">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-semibold text-white">Edit Coupon</h3>
                <button onclick="closeEditCouponModal()" class="text-gray-400 hover:text-white focus:outline-none">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form method="POST" id="editCouponForm">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="coupon_id" id="edit_coupon_id">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-1">Coupon Code</label>
                        <input type="text" id="edit_code" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-gray-400 focus:outline-none" readonly>
                    </div>
                    
                    <div>
                        <label for="edit_discount_type" class="block text-sm font-medium text-gray-300 mb-1">Discount Type*</label>
                        <select name="discount_type" id="edit_discount_type" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                            <option value="percentage">Percentage</option>
                            <option value="fixed">Fixed Amount</option>
                        </select>
                    </div>
                    
                    <div>
                        <label for="edit_discount_value" class="block text-sm font-medium text-gray-300 mb-1">Discount Value*</label>
                        <input type="number" name="discount_value" id="edit_discount_value" required min="1" step="0.01" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                        <p class="text-xs text-gray-400 mt-1">For percentage: 1-100, for fixed amount: any positive value</p>
                    </div>
                    
                    <div>
                        <label for="edit_min_purchase_amount" class="block text-sm font-medium text-gray-300 mb-1">Minimum Purchase Amount</label>
                        <input type="number" name="min_purchase_amount" id="edit_min_purchase_amount" min="0" step="0.01" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                        <p class="text-xs text-gray-400 mt-1">Leave empty for no minimum</p>
                    </div>
                    
                    <div>
                        <label for="edit_applicable_to_type" class="block text-sm font-medium text-gray-300 mb-1">Applicable To*</label>
                        <select name="applicable_to_type" id="edit_applicable_to_type" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500" onchange="toggleEditApplicableToId()">
                            <option value="all">All</option>
                            <option value="gym">Specific Gym</option>
                            <option value="plan">Specific Plan</option>
                        </select>
                    </div>
                    
                    <div id="edit_applicable_to_id_container" class="hidden">
                        <label for="edit_applicable_to_id" class="block text-sm font-medium text-gray-300 mb-1">Select Specific Item*</label>
                        <select name="applicable_to_id" id="edit_applicable_to_id" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                            <option value="">Select...</option>
                            <!-- Options will be populated by JavaScript -->
                        </select>
                    </div>
                    
                    <div>
                        <label for="edit_usage_limit" class="block text-sm font-medium text-gray-300 mb-1">Usage Limit</label>
                        <input type="number" name="usage_limit" id="edit_usage_limit" min="1" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                        <p class="text-xs text-gray-400 mt-1">Leave empty for unlimited usage</p>
                    </div>
                    
                    <div>
                        <label for="edit_expiry_date" class="block text-sm font-medium text-gray-300 mb-1">Expiry Date</label>
                        <input type="date" name="expiry_date" id="edit_expiry_date" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                        <p class="text-xs text-gray-400 mt-1">Leave empty for no expiry</p>
                    </div>
                    
                    <div class="flex items-center">
                        <input type="checkbox" name="is_active" id="edit_is_active" class="h-4 w-4 text-yellow-500 focus:ring-yellow-500 border-gray-600 rounded">
                        <label for="edit_is_active" class="ml-2 block text-sm text-gray-300">Active</label>
                    </div>
                    
                    <div>
                        <div class="text-sm text-gray-400">
                            <span class="font-medium">Current Usage:</span> 
                            <span id="edit_usage_count"></span>
                        </div>
                    </div>
                </div>
                
                <div class="mb-4">
                    <label for="edit_description" class="block text-sm font-medium text-gray-300 mb-1">Description</label>
                    <textarea name="description" id="edit_description" rows="3" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500"></textarea>
                </div>
                
                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="closeEditCouponModal()" class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                        Cancel
                    </button>
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                        Update Coupon
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Store gyms and plans data for dropdowns
        const gyms = <?= json_encode($gyms); ?>;
        const plans = <?= json_encode($plans); ?>;
        
        // Add Coupon Modal Functions
        function openAddCouponModal() {
            document.getElementById('addCouponModal').classList.remove('hidden');
        }
        
        function closeAddCouponModal() {
            document.getElementById('addCouponModal').classList.add('hidden');
            document.getElementById('addCouponForm').reset();
            document.getElementById('applicable_to_id_container').classList.add('hidden');
        }
        
        function toggleApplicableToId() {
            const applicableType = document.getElementById('applicable_to_type').value;
            const container = document.getElementById('applicable_to_id_container');
            const select = document.getElementById('applicable_to_id');
            
            if (applicableType === 'all') {
                container.classList.add('hidden');
                select.innerHTML = '<option value="">Select...</option>';
                select.removeAttribute('required');
            } else {
                container.classList.remove('hidden');
                select.setAttribute('required', 'required');
                
                // Clear previous options
                select.innerHTML = '<option value="">Select...</option>';
                
                // Add new options based on type
                if (applicableType === 'gym') {
                    gyms.forEach(gym => {
                        const option = document.createElement('option');
                        option.value = gym.gym_id;
                        option.textContent = gym.name;
                        select.appendChild(option);
                    });
                } else if (applicableType === 'plan') {
                    plans.forEach(plan => {
                        const option = document.createElement('option');
                        option.value = plan.plan_id;
                        option.textContent = plan.plan_name;
                        select.appendChild(option);
                    });
                }
            }
        }
        
        // Edit Coupon Modal Functions
        function openEditCouponModal(coupon) {
            document.getElementById('edit_coupon_id').value = coupon.id;
            document.getElementById('edit_code').value = coupon.code;
            document.getElementById('edit_discount_type').value = coupon.discount_type;
            document.getElementById('edit_discount_value').value = coupon.discount_value;
            document.getElementById('edit_min_purchase_amount').value = coupon.min_purchase_amount || '';
            document.getElementById('edit_applicable_to_type').value = coupon.applicable_to_type;
            document.getElementById('edit_usage_limit').value = coupon.usage_limit || '';
            document.getElementById('edit_expiry_date').value = coupon.expiry_date || '';
            document.getElementById('edit_is_active').checked = coupon.is_active == 1;
            document.getElementById('edit_description').value = coupon.description || '';
            document.getElementById('edit_usage_count').textContent = coupon.usage_count + (coupon.usage_limit ? ' / ' + coupon.usage_limit : ' / ∞');
            
            // Handle applicable_to_id dropdown
            toggleEditApplicableToId();
            
            // Set the applicable_to_id if it exists
            if (coupon.applicable_to_id) {
                setTimeout(() => {
                    document.getElementById('edit_applicable_to_id').value = coupon.applicable_to_id;
                }, 100);
            }
            
            document.getElementById('editCouponModal').classList.remove('hidden');
        }
        
        function closeEditCouponModal() {
            document.getElementById('editCouponModal').classList.add('hidden');
        }
        
        function toggleEditApplicableToId() {
            const applicableType = document.getElementById('edit_applicable_to_type').value;
            const container = document.getElementById('edit_applicable_to_id_container');
            const select = document.getElementById('edit_applicable_to_id');
            
            if (applicableType === 'all') {
                container.classList.add('hidden');
                select.innerHTML = '<option value="">Select...</option>';
                select.removeAttribute('required');
            } else {
                container.classList.remove('hidden');
                select.setAttribute('required', 'required');
                
                // Clear previous options
                select.innerHTML = '<option value="">Select...</option>';
                
                // Add new options based on type
                if (applicableType === 'gym') {
                    gyms.forEach(gym => {
                        const option = document.createElement('option');
                        option.value = gym.gym_id;
                        option.textContent = gym.name;
                        select.appendChild(option);
                    });
                } else if (applicableType === 'plan') {
                    plans.forEach(plan => {
                        const option = document.createElement('option');
                        option.value = plan.plan_id;
                        option.textContent = plan.plan_name;
                        select.appendChild(option);
                    });
                }
            }
        }
        
        // Initialize event listeners
        document.addEventListener('DOMContentLoaded', function() {
            // Set min date for expiry date inputs to today
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('expiry_date').min = today;
            document.getElementById('edit_expiry_date').min = today;
            
            // Initialize tooltips or other UI elements if needed
        });
    </script>
</body>
</html>



