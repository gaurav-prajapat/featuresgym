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

// Pagination variables
$limit = 10; // Number of records per page
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Handle filters
$gym_id = $_GET['gym_id'] ?? 'all';
$plan_id = $_GET['plan_id'] ?? 'all';
$search = $_GET['search'] ?? '';
$sort_by = $_GET['sort_by'] ?? 'start_date';
$sort_order = $_GET['sort_order'] ?? 'desc';
$expiry_filter = $_GET['expiry'] ?? 'all';

// Fetch available gyms for the filter dropdown
$gymStmt = $conn->query("SELECT gym_id, name FROM gyms ORDER BY name ASC");
$gyms = $gymStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch available membership plans for the filter dropdown
$planStmt = $conn->query("SELECT plan_id, plan_name FROM gym_membership_plans ORDER BY plan_name ASC");
$membershipPlans = $planStmt->fetchAll(PDO::FETCH_ASSOC);

// Build query with filters
$query = "
SELECT 
    um.id, um.amount, um.start_date, um.end_date, um.status, um.payment_status, um.created_at,
    u.id as user_id, u.username, u.email, u.phone, u.profile_image,
    g.gym_id, g.name as gym_name, g.city as gym_city,
    gmp.plan_id, gmp.plan_name, gmp.duration, gmp.price, gmp.tier,
    (SELECT COUNT(*) FROM schedules s 
     WHERE s.user_id = u.id 
     AND s.membership_id = um.id) as used_visits
FROM user_memberships um
JOIN users u ON um.user_id = u.id
JOIN gyms g ON um.gym_id = g.gym_id
JOIN gym_membership_plans gmp ON um.plan_id = gmp.plan_id
WHERE um.status = 'active'
";

if ($gym_id !== 'all') {
    $query .= " AND g.gym_id = :gym_id";
}
if ($plan_id !== 'all') {
    $query .= " AND gmp.plan_id = :plan_id";
}
if ($search) {
    $query .= " AND (u.username LIKE :search OR u.email LIKE :search OR u.phone LIKE :search)";
}

// Handle expiry filter
if ($expiry_filter === 'expiring_soon') {
    $query .= " AND um.end_date BETWEEN CURRENT_DATE AND DATE_ADD(CURRENT_DATE, INTERVAL 7 DAY)";
} elseif ($expiry_filter === 'expired_today') {
    $query .= " AND DATE(um.end_date) = CURRENT_DATE";
}

$totalQuery = $query; // Query for counting total records

// Add sorting
$validSortColumns = ['username', 'gym_name', 'plan_name', 'start_date', 'end_date', 'amount', 'payment_status', 'used_visits'];
$validSortOrders = ['asc', 'desc'];

if (in_array($sort_by, $validSortColumns) && in_array($sort_order, $validSortOrders)) {
    $query .= " ORDER BY $sort_by $sort_order";
} else {
    $query .= " ORDER BY um.start_date DESC";
}

$query .= " LIMIT :limit OFFSET :offset";

$stmt = $conn->prepare($query);

// Bind parameters
if ($gym_id !== 'all')
    $stmt->bindValue(':gym_id', $gym_id);
if ($plan_id !== 'all')
    $stmt->bindValue(':plan_id', $plan_id);
if ($search)
    $stmt->bindValue(':search', "%$search%");
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

$stmt->execute();
$memberships = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count total records for pagination
$totalStmt = $conn->prepare($totalQuery);
if ($gym_id !== 'all')
    $totalStmt->bindValue(':gym_id', $gym_id);
if ($plan_id !== 'all')
    $totalStmt->bindValue(':plan_id', $plan_id);
if ($search)
    $totalStmt->bindValue(':search', "%$search%");
$totalStmt->execute();
$totalRecords = $totalStmt->rowCount();

$totalPages = ceil($totalRecords / $limit);

// Handle membership actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && isset($_POST['membership_id'])) {
        $membership_id = (int)$_POST['membership_id'];
        $action = $_POST['action'];
        
        try {
            switch ($action) {
                case 'cancel':
                    $stmt = $conn->prepare("UPDATE user_memberships SET status = 'cancelled' WHERE id = ?");
                    $stmt->execute([$membership_id]);
                    $_SESSION['success'] = "Membership cancelled successfully.";
                    break;
                    
                case 'extend':
                    $days = (int)$_POST['days'];
                    
                    if ($days > 0) {
                        $stmt = $conn->prepare("
                            UPDATE user_memberships 
                            SET end_date = DATE_ADD(end_date, INTERVAL ? DAY) 
                            WHERE id = ?
                        ");
                        $stmt->execute([$days, $membership_id]);
                        $_SESSION['success'] = "Membership extended by $days days.";
                    } else {
                        $_SESSION['error'] = "Please enter a valid number of days.";
                    }
                    break;
                    
                case 'update_payment':
                    $payment_status = $_POST['payment_status'];
                    $stmt = $conn->prepare("UPDATE user_memberships SET payment_status = ? WHERE id = ?");
                    $stmt->execute([$payment_status, $membership_id]);
                    $_SESSION['success'] = "Payment status updated successfully.";
                    break;
            }
        } catch (PDOException $e) {
            $_SESSION['error'] = "Error: " . $e->getMessage();
        }
        
        // Redirect to refresh the page
        header("Location: active_memberships.php?page=$page&gym_id=$gym_id&plan_id=$plan_id&search=$search&expiry=$expiry_filter");
        exit();
    }
}

// For pagination URL generation
function getPaginationUrl($pageNum) {
    global $gym_id, $plan_id, $search, $sort_by, $sort_order, $expiry_filter;
    return "active_memberships.php?page=$pageNum&gym_id=$gym_id&plan_id=$plan_id&search=" . urlencode($search) . "&sort_by=$sort_by&sort_order=$sort_order&expiry=$expiry_filter";
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
    <title>Active Memberships - FlexFit Admin</title>
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
                <h1 class="text-2xl font-bold text-white">Active Memberships</h1>
                <a href="add_membership.php" class="bg-yellow-500 hover:bg-yellow-600 text-black px-4 py-2 rounded-lg transition-colors duration-200">
                    <i class="fas fa-plus mr-2"></i> Add New Membership
                </a>
            </div>

            <!-- Filters -->
            <div class="bg-gray-800 rounded-lg shadow-lg p-6 mb-8">
                <form class="grid grid-cols-1 md:grid-cols-5 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-1">Gym</label>
                        <select name="gym_id" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                            <option value="all">All Gyms</option>
                            <?php foreach ($gyms as $gym): ?>
                                <option value="<?= $gym['gym_id']; ?>" <?= ($gym_id == $gym['gym_id']) ? 'selected' : ''; ?>>
                                    <?= htmlspecialchars($gym['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-1">Membership Plan</label>
                        <select name="plan_id" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                            <option value="all">All Plans</option>
                            <?php foreach ($membershipPlans as $plan): ?>
                                <option value="<?= $plan['plan_id']; ?>" <?= ($plan_id == $plan['plan_id']) ? 'selected' : ''; ?>>
                                    <?= htmlspecialchars($plan['plan_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-1">Expiry Status</label>
                        <select name="expiry" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                            <option value="all" <?= $expiry_filter === 'all' ? 'selected' : ''; ?>>All</option>
                            <option value="expiring_soon" <?= $expiry_filter === 'expiring_soon' ? 'selected' : ''; ?>>Expiring in 7 days</option>
                            <option value="expired_today" <?= $expiry_filter === 'expired_today' ? 'selected' : ''; ?>>Expiring today</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-1">Search</label>
                        <input type="text" name="search" value="<?= htmlspecialchars($search); ?>" placeholder="Member name or email" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                    </div>
                    <div class="flex items-end">
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors duration-200 w-full">
                            <i class="fas fa-filter mr-2"></i> Apply Filters
                        </button>
                    </div>
                </form>
            </div>

            <!-- Memberships Table -->
            <div class="bg-gray-800 rounded-lg shadow-lg overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-700">
                        <thead class="bg-gray-700">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                <a href="?page=<?= $page ?>&gym_id=<?= $gym_id ?>&plan_id=<?= $plan_id ?>&search=<?= urlencode($search) ?>&sort_by=username&sort_order=<?= ($sort_by === 'username' && $sort_order === 'asc') ? 'desc' : 'asc' ?>&expiry=<?= $expiry_filter ?>" class="flex items-center">
                                        Member
                                        <?php if ($sort_by === 'username'): ?>
                                            <i class="fas fa-sort-<?= $sort_order === 'asc' ? 'up' : 'down' ?> ml-1"></i>
                                        <?php else: ?>
                                            <i class="fas fa-sort ml-1 text-gray-500"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                    <a href="?page=<?= $page ?>&gym_id=<?= $gym_id ?>&plan_id=<?= $plan_id ?>&search=<?= urlencode($search) ?>&sort_by=gym_name&sort_order=<?= ($sort_by === 'gym_name' && $sort_order === 'asc') ? 'desc' : 'asc' ?>&expiry=<?= $expiry_filter ?>" class="flex items-center">
                                        Gym
                                        <?php if ($sort_by === 'gym_name'): ?>
                                            <i class="fas fa-sort-<?= $sort_order === 'asc' ? 'up' : 'down' ?> ml-1"></i>
                                        <?php else: ?>
                                            <i class="fas fa-sort ml-1 text-gray-500"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                    <a href="?page=<?= $page ?>&gym_id=<?= $gym_id ?>&plan_id=<?= $plan_id ?>&search=<?= urlencode($search) ?>&sort_by=plan_name&sort_order=<?= ($sort_by === 'plan_name' && $sort_order === 'asc') ? 'desc' : 'asc' ?>&expiry=<?= $expiry_filter ?>" class="flex items-center">
                                        Membership Plan
                                        <?php if ($sort_by === 'plan_name'): ?>
                                            <i class="fas fa-sort-<?= $sort_order === 'asc' ? 'up' : 'down' ?> ml-1"></i>
                                        <?php else: ?>
                                            <i class="fas fa-sort ml-1 text-gray-500"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                    <a href="?page=<?= $page ?>&gym_id=<?= $gym_id ?>&plan_id=<?= $plan_id ?>&search=<?= urlencode($search) ?>&sort_by=start_date&sort_order=<?= ($sort_by === 'start_date' && $sort_order === 'asc') ? 'desc' : 'asc' ?>&expiry=<?= $expiry_filter ?>" class="flex items-center">
                                        Start Date
                                        <?php if ($sort_by === 'start_date'): ?>
                                            <i class="fas fa-sort-<?= $sort_order === 'asc' ? 'up' : 'down' ?> ml-1"></i>
                                        <?php else: ?>
                                            <i class="fas fa-sort ml-1 text-gray-500"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                    <a href="?page=<?= $page ?>&gym_id=<?= $gym_id ?>&plan_id=<?= $plan_id ?>&search=<?= urlencode($search) ?>&sort_by=end_date&sort_order=<?= ($sort_by === 'end_date' && $sort_order === 'asc') ? 'desc' : 'asc' ?>&expiry=<?= $expiry_filter ?>" class="flex items-center">
                                        End Date
                                        <?php if ($sort_by === 'end_date'): ?>
                                            <i class="fas fa-sort-<?= $sort_order === 'asc' ? 'up' : 'down' ?> ml-1"></i>
                                        <?php else: ?>
                                            <i class="fas fa-sort ml-1 text-gray-500"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                    <a href="?page=<?= $page ?>&gym_id=<?= $gym_id ?>&plan_id=<?= $plan_id ?>&search=<?= urlencode($search) ?>&sort_by=amount&sort_order=<?= ($sort_by === 'amount' && $sort_order === 'asc') ? 'desc' : 'asc' ?>&expiry=<?= $expiry_filter ?>" class="flex items-center">
                                        Amount
                                        <?php if ($sort_by === 'amount'): ?>
                                            <i class="fas fa-sort-<?= $sort_order === 'asc' ? 'up' : 'down' ?> ml-1"></i>
                                        <?php else: ?>
                                            <i class="fas fa-sort ml-1 text-gray-500"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                    <a href="?page=<?= $page ?>&gym_id=<?= $gym_id ?>&plan_id=<?= $plan_id ?>&search=<?= urlencode($search) ?>&sort_by=payment_status&sort_order=<?= ($sort_by === 'payment_status' && $sort_order === 'asc') ? 'desc' : 'asc' ?>&expiry=<?= $expiry_filter ?>" class="flex items-center">
                                        Payment Status
                                        <?php if ($sort_by === 'payment_status'): ?>
                                            <i class="fas fa-sort-<?= $sort_order === 'asc' ? 'up' : 'down' ?> ml-1"></i>
                                        <?php else: ?>
                                            <i class="fas fa-sort ml-1 text-gray-500"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                    <a href="?page=<?= $page ?>&gym_id=<?= $gym_id ?>&plan_id=<?= $plan_id ?>&search=<?= urlencode($search) ?>&sort_by=used_visits&sort_order=<?= ($sort_by === 'used_visits' && $sort_order === 'asc') ? 'desc' : 'asc' ?>&expiry=<?= $expiry_filter ?>" class="flex items-center">
                                        Usage
                                        <?php if ($sort_by === 'used_visits'): ?>
                                            <i class="fas fa-sort-<?= $sort_order === 'asc' ? 'up' : 'down' ?> ml-1"></i>
                                        <?php else: ?>
                                            <i class="fas fa-sort ml-1 text-gray-500"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-300 uppercase tracking-wider">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-gray-800 divide-y divide-gray-700">
                            <?php if (empty($memberships)): ?>
                                <tr>
                                    <td colspan="9" class="px-6 py-4 text-center text-gray-400">
                                        No active memberships found matching your criteria.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($memberships as $membership): ?>
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="flex-shrink-0 h-10 w-10">
                                                    <?php if ($membership['profile_image']): ?>
                                                        <img class="h-10 w-10 rounded-full object-cover" src="../uploads/profile/<?= htmlspecialchars($membership['profile_image']); ?>" alt="Profile">
                                                    <?php else: ?>
                                                        <div class="h-10 w-10 rounded-full bg-gray-600 flex items-center justify-center">
                                                            <i class="fas fa-user text-gray-300"></i>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="ml-4">
                                                    <div class="text-sm font-medium text-white">
                                                        <?= htmlspecialchars($membership['username']); ?>
                                                    </div>
                                                    <div class="text-sm text-gray-400">
                                                        <?= htmlspecialchars($membership['email']); ?>
                                                    </div>
                                                    <?php if ($membership['phone']): ?>
                                                        <div class="text-xs text-gray-500">
                                                            <?= htmlspecialchars($membership['phone']); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-white"><?= htmlspecialchars($membership['gym_name']); ?></div>
                                            <div class="text-xs text-gray-400"><?= htmlspecialchars($membership['gym_city'] ?? ''); ?></div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-white"><?= htmlspecialchars($membership['plan_name']); ?></div>
                                            <div class="text-xs text-gray-400">
                                                <?= htmlspecialchars($membership['duration']); ?> • 
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                                    <?php 
                                                    if ($membership['tier'] === 'Tier 1') echo 'bg-green-900 text-green-300';
                                                    elseif ($membership['tier'] === 'Tier 2') echo 'bg-blue-900 text-blue-300';
                                                    else echo 'bg-purple-900 text-purple-300';
                                                    ?>">
                                                    <?= htmlspecialchars($membership['tier']); ?>
                                                </span>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-400">
                                            <?= date('M d, Y', strtotime($membership['start_date'])); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php 
                                            $end_date = strtotime($membership['end_date']);
                                            $today = strtotime('today');
                                            $days_left = ceil(($end_date - $today) / (60 * 60 * 24));
                                            
                                            if ($days_left <= 0) {
                                                $badge_class = 'bg-red-900 text-red-300';
                                                $days_text = 'Expired today';
                                            } elseif ($days_left <= 7) {
                                                $badge_class = 'bg-yellow-900 text-yellow-300';
                                                $days_text = "$days_left days left";
                                            } else {
                                                $badge_class = 'bg-green-900 text-green-300';
                                                $days_text = "$days_left days left";
                                            }
                                            ?>
                                            <div class="text-sm text-gray-400">
                                                <?= date('M d, Y', $end_date); ?>
                                            </div>
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?= $badge_class ?>">
                                                <?= $days_text; ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-400">
                                            ₹<?= number_format($membership['amount'], 2); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php if ($membership['payment_status'] === 'paid'): ?>
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-900 text-green-300">
                                                    Paid
                                                </span>
                                            <?php elseif ($membership['payment_status'] === 'pending'): ?>
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-900 text-yellow-300">
                                                    Pending
                                                </span>
                                            <?php else: ?>
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-900 text-red-300">
                                                    Failed
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-white">
                                                <?= $membership['used_visits']; ?> visits                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                            <div class="flex justify-end space-x-2">
                                                <a href="../member_profile.php?id=<?= $membership['user_id']; ?>" class="text-blue-400 hover:text-blue-300" title="View Member">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                
                                                <div class="relative" x-data="{ open: false }">
                                                    <button @click="open = !open" class="text-gray-400 hover:text-white focus:outline-none" title="More Actions">
                                                        <i class="fas fa-ellipsis-v"></i>
                                                    </button>
                                                    
                                                    <div x-show="open" @click.away="open = false" class="origin-top-right absolute right-0 mt-2 w-48 rounded-md shadow-lg bg-gray-700 ring-1 ring-black ring-opacity-5 z-10">
                                                        <div class="py-1">
                                                            <button type="button" onclick="openExtendModal(<?= $membership['id']; ?>, '<?= htmlspecialchars($membership['username']); ?>')" class="block w-full text-left px-4 py-2 text-sm text-gray-300 hover:bg-gray-600">
                                                                <i class="fas fa-calendar-plus mr-2"></i> Extend Membership
                                                            </button>
                                                            
                                                            <button type="button" onclick="openPaymentModal(<?= $membership['id']; ?>, '<?= htmlspecialchars($membership['username']); ?>', '<?= $membership['payment_status']; ?>')" class="block w-full text-left px-4 py-2 text-sm text-gray-300 hover:bg-gray-600">
                                                                <i class="fas fa-money-bill-wave mr-2"></i> Update Payment Status
                                                            </button>
                                                            
                                                            <form method="POST" class="block" onsubmit="return confirm('Are you sure you want to cancel this membership?');">
                                                                <input type="hidden" name="membership_id" value="<?= $membership['id']; ?>">
                                                                <input type="hidden" name="action" value="cancel">
                                                                <button type="submit" class="block w-full text-left px-4 py-2 text-sm text-red-400 hover:bg-gray-600">
                                                                    <i class="fas fa-ban mr-2"></i> Cancel Membership
                                                                </button>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
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
                            Showing <?= ($offset + 1) ?>-<?= min($offset + $limit, $totalRecords) ?> of <?= $totalRecords ?> memberships
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

    <!-- Extend Membership Modal -->
    <div id="extendMembershipModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
        <div class="bg-gray-800 rounded-lg shadow-lg p-6 w-full max-w-md">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-semibold text-white">Extend Membership</h3>
                <button onclick="closeExtendModal()" class="text-gray-400 hover:text-white focus:outline-none">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form method="POST" id="extendMembershipForm">
                <input type="hidden" name="membership_id" id="extendMembershipId">
                <input type="hidden" name="action" value="extend">
                
                <p class="text-gray-300 mb-4">Extend membership for <span id="extendMemberName" class="font-semibold"></span></p>
                
                <div class="mb-4">
                    <label for="days" class="block text-sm font-medium text-gray-300 mb-1">Number of Days</label>
                    <input type="number" name="days" id="days" min="1" value="30" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                </div>
                
                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="closeExtendModal()" class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                        Cancel
                    </button>
                    <button type="submit" class="bg-yellow-500 hover:bg-yellow-600 text-black px-4 py-2 rounded-lg transition-colors duration-200">
                        Extend Membership
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Update Payment Status Modal -->
    <div id="paymentStatusModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
        <div class="bg-gray-800 rounded-lg shadow-lg p-6 w-full max-w-md">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-semibold text-white">Update Payment Status</h3>
                <button onclick="closePaymentModal()" class="text-gray-400 hover:text-white focus:outline-none">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form method="POST" id="updatePaymentForm">
                <input type="hidden" name="membership_id" id="paymentMembershipId">
                <input type="hidden" name="action" value="update_payment">
                
                <p class="text-gray-300 mb-4">Update payment status for <span id="paymentMemberName" class="font-semibold"></span></p>
                
                <div class="mb-4">
                    <label for="payment_status" class="block text-sm font-medium text-gray-300 mb-1">Payment Status</label>
                    <select name="payment_status" id="payment_status" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                        <option value="paid">Paid</option>
                        <option value="pending">Pending</option>
                        <option value="failed">Failed</option>
                    </select>
                </div>
                
                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="closePaymentModal()" class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                        Cancel
                    </button>
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                        Update Status
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/alpinejs@2.8.2/dist/alpine.min.js" defer></script>
    <script>
        function openExtendModal(membershipId, memberName) {
            document.getElementById('extendMembershipId').value = membershipId;
            document.getElementById('extendMemberName').textContent = memberName;
            document.getElementById('extendMembershipModal').classList.remove('hidden');
        }
        
        function closeExtendModal() {
            document.getElementById('extendMembershipModal').classList.add('hidden');
        }
        
        function openPaymentModal(membershipId, memberName, currentStatus) {
            document.getElementById('paymentMembershipId').value = membershipId;
            document.getElementById('paymentMemberName').textContent = memberName;
            document.getElementById('payment_status').value = currentStatus;
            document.getElementById('paymentStatusModal').classList.remove('hidden');
        }
        
        function closePaymentModal() {
            document.getElementById('paymentStatusModal').classList.add('hidden');
        }
    </script>
</body>
</html>


