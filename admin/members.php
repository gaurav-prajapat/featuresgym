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
$membership = $_GET['membership'] ?? 'all';
$search = $_GET['search'] ?? '';
$plan_id = $_GET['plan_id'] ?? '';
$sort_by = $_GET['sort_by'] ?? 'username';
$sort_order = $_GET['sort_order'] ?? 'asc';

// Fetch available gyms for the filter dropdown
$gymStmt = $conn->query("SELECT gym_id, name FROM gyms ORDER BY name ASC");
$gyms = $gymStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch available membership plans for the filter dropdown
$planStmt = $conn->query("SELECT plan_id, plan_name FROM gym_membership_plans WHERE 1 ORDER BY plan_name ASC");
$membershipPlans = $planStmt->fetchAll(PDO::FETCH_ASSOC);

// Build query with filters
$query = "
SELECT 
    u.id, u.username, u.email, u.phone, u.city, u.profile_image, u.status as user_status, u.created_at,
    um.id as membership_id, um.status as membership_status, um.start_date, um.end_date,
    gmp.plan_id, gmp.plan_name, gmp.duration, gmp.price, gmp.tier,
    g.gym_id, g.name as gym_name, g.city as gym_city,
    (SELECT COUNT(*) FROM schedules s2 
     WHERE s2.user_id = u.id 
     AND s2.start_date BETWEEN um.start_date AND um.end_date) as used_visits,
    CASE 
        WHEN gmp.duration = 'Daily' THEN 1
        WHEN gmp.duration = 'Weekly' THEN 7
        WHEN gmp.duration = 'Monthly' THEN 30
        WHEN gmp.duration = 'Quarterly' THEN 90
        WHEN gmp.duration = 'Half Yearly' THEN 180
        WHEN gmp.duration = 'Yearly' THEN 365
        ELSE 30
    END as total_visits
FROM users u
LEFT JOIN user_memberships um ON u.id = um.user_id
LEFT JOIN gym_membership_plans gmp ON um.plan_id = gmp.plan_id
LEFT JOIN gyms g ON um.gym_id = g.gym_id
WHERE u.role = 'member'
";

if ($gym_id !== 'all') {
    $query .= " AND g.gym_id = :gym_id";
}
if ($membership !== 'all') {
    $query .= " AND um.status = :membership";
}
if ($search) {
    $query .= " AND (u.username LIKE :search OR u.email LIKE :search OR u.phone LIKE :search)";
}
if ($plan_id) {
    $query .= " AND gmp.plan_id = :plan_id";
}

$query .= " GROUP BY u.id, um.id";
$totalQuery = $query; // Query for counting total records

// Add sorting
$validSortColumns = ['username', 'email', 'gym_name', 'plan_name', 'price', 'membership_status', 'used_visits', 'created_at'];
$validSortOrders = ['asc', 'desc'];

if (in_array($sort_by, $validSortColumns) && in_array($sort_order, $validSortOrders)) {
    $query .= " ORDER BY $sort_by $sort_order";
} else {
    $query .= " ORDER BY u.username ASC";
}

$query .= " LIMIT :limit OFFSET :offset";

$stmt = $conn->prepare($query);

// Bind parameters
if ($gym_id !== 'all')
    $stmt->bindValue(':gym_id', $gym_id);
if ($membership !== 'all')
    $stmt->bindValue(':membership', $membership);
if ($search)
    $stmt->bindValue(':search', "%$search%");
if ($plan_id)
    $stmt->bindValue(':plan_id', $plan_id);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

$stmt->execute();
$members = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count total records for pagination
$totalStmt = $conn->prepare($totalQuery);
if ($gym_id !== 'all')
    $totalStmt->bindValue(':gym_id', $gym_id);
if ($membership !== 'all')
    $totalStmt->bindValue(':membership', $membership);
if ($search)
    $totalStmt->bindValue(':search', "%$search%");
if ($plan_id)
    $totalStmt->bindValue(':plan_id', $plan_id);
$totalStmt->execute();
$totalRecords = $totalStmt->rowCount();

$totalPages = ceil($totalRecords / $limit);

// Handle member actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && isset($_POST['member_id'])) {
        $member_id = (int)$_POST['member_id'];
        $action = $_POST['action'];
        
        try {
            switch ($action) {
                case 'activate':
                    $stmt = $conn->prepare("UPDATE users SET status = 'active' WHERE id = ?");
                    $stmt->execute([$member_id]);
                    $_SESSION['success'] = "Member activated successfully.";
                    break;
                    
                case 'deactivate':
                    $stmt = $conn->prepare("UPDATE users SET status = 'inactive' WHERE id = ?");
                    $stmt->execute([$member_id]);
                    $_SESSION['success'] = "Member deactivated successfully.";
                    break;
                    
                case 'delete':
                    // First check if member has active memberships
                    $stmt = $conn->prepare("SELECT COUNT(*) FROM user_memberships WHERE user_id = ? AND status = 'active'");
                    $stmt->execute([$member_id]);
                    $hasActiveMemberships = $stmt->fetchColumn() > 0;
                    
                    if ($hasActiveMemberships) {
                        $_SESSION['error'] = "Cannot delete member with active memberships.";
                    } else {
                        // Delete related records first
                        $conn->beginTransaction();
                        
                        // Delete schedules
                        $stmt = $conn->prepare("DELETE FROM schedules WHERE user_id = ?");
                        $stmt->execute([$member_id]);
                        
                        // Delete memberships
                        $stmt = $conn->prepare("DELETE FROM user_memberships WHERE user_id = ?");
                        $stmt->execute([$member_id]);
                        
                        // Delete reviews
                        $stmt = $conn->prepare("DELETE FROM reviews WHERE user_id = ?");
                        $stmt->execute([$member_id]);
                        
                        // Delete notifications
                        $stmt = $conn->prepare("DELETE FROM notifications WHERE user_id = ?");
                        $stmt->execute([$member_id]);
                        
                        // Finally delete the user
                        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
                        $stmt->execute([$member_id]);
                        
                        $conn->commit();
                        $_SESSION['success'] = "Member deleted successfully.";
                    }
                    break;
                    
                case 'extend_membership':
                    $membership_id = (int)$_POST['membership_id'];
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
            }
        } catch (PDOException $e) {
            $_SESSION['error'] = "Error: " . $e->getMessage();
        }
        
        // Redirect to refresh the page
        header("Location: members.php?page=$page&gym_id=$gym_id&membership=$membership&search=$search&plan_id=$plan_id");
        exit();
    }
}

// For pagination URL generation
function getPaginationUrl($pageNum) {
    global $gym_id, $membership, $search, $plan_id, $sort_by, $sort_order;
    return "members.php?page=$pageNum&gym_id=$gym_id&membership=$membership&search=" . urlencode($search) . "&plan_id=$plan_id&sort_by=$sort_by&sort_order=$sort_order";
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
    <title>Manage Members - FlexFit Admin</title>
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
                <h1 class="text-2xl font-bold text-white">Manage Members</h1>
                <a href="add_member.php" class="bg-yellow-500 hover:bg-yellow-600 text-black px-4 py-2 rounded-lg transition-colors duration-200">
                    <i class="fas fa-user-plus mr-2"></i> Add New Member
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
                        <label class="block text-sm font-medium text-gray-300 mb-1">Membership Status</label>
                        <select name="membership" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                            <option value="all">All</option>
                            <option value="active" <?= $membership === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="expired" <?= $membership === 'expired' ? 'selected' : ''; ?>>Expired</option>
                            <option value="cancelled" <?= $membership === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-1">Membership Plan</label>
                        <select name="plan_id" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                            <option value="">All Plans</option>
                            <?php foreach ($membershipPlans as $plan): ?>
                                <option value="<?= $plan['plan_id']; ?>" <?= ($plan_id == $plan['plan_id']) ? 'selected' : ''; ?>>
                                <?= htmlspecialchars($plan['plan_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-1">Search</label>
                        <input type="text" name="search" value="<?= htmlspecialchars($search); ?>" placeholder="Name, Email or Phone" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                    </div>
                    <div class="flex items-end">
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors duration-200 w-full">
                            <i class="fas fa-filter mr-2"></i> Apply Filters
                        </button>
                    </div>
                </form>
            </div>

            <!-- Members Table -->
            <div class="bg-gray-800 rounded-lg shadow-lg overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-700">
                        <thead class="bg-gray-700">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                    <a href="?page=<?= $page ?>&gym_id=<?= $gym_id ?>&membership=<?= $membership ?>&search=<?= urlencode($search) ?>&plan_id=<?= $plan_id ?>&sort_by=username&sort_order=<?= ($sort_by === 'username' && $sort_order === 'asc') ? 'desc' : 'asc' ?>" class="flex items-center">
                                        Member
                                        <?php if ($sort_by === 'username'): ?>
                                            <i class="fas fa-sort-<?= $sort_order === 'asc' ? 'up' : 'down' ?> ml-1"></i>
                                        <?php else: ?>
                                            <i class="fas fa-sort ml-1 text-gray-500"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                    <a href="?page=<?= $page ?>&gym_id=<?= $gym_id ?>&membership=<?= $membership ?>&search=<?= urlencode($search) ?>&plan_id=<?= $plan_id ?>&sort_by=gym_name&sort_order=<?= ($sort_by === 'gym_name' && $sort_order === 'asc') ? 'desc' : 'asc' ?>" class="flex items-center">
                                        Gym
                                        <?php if ($sort_by === 'gym_name'): ?>
                                            <i class="fas fa-sort-<?= $sort_order === 'asc' ? 'up' : 'down' ?> ml-1"></i>
                                        <?php else: ?>
                                            <i class="fas fa-sort ml-1 text-gray-500"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                    <a href="?page=<?= $page ?>&gym_id=<?= $gym_id ?>&membership=<?= $membership ?>&search=<?= urlencode($search) ?>&plan_id=<?= $plan_id ?>&sort_by=plan_name&sort_order=<?= ($sort_by === 'plan_name' && $sort_order === 'asc') ? 'desc' : 'asc' ?>" class="flex items-center">
                                        Membership
                                        <?php if ($sort_by === 'plan_name'): ?>
                                            <i class="fas fa-sort-<?= $sort_order === 'asc' ? 'up' : 'down' ?> ml-1"></i>
                                        <?php else: ?>
                                            <i class="fas fa-sort ml-1 text-gray-500"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                    <a href="?page=<?= $page ?>&gym_id=<?= $gym_id ?>&membership=<?= $membership ?>&search=<?= urlencode($search) ?>&plan_id=<?= $plan_id ?>&sort_by=membership_status&sort_order=<?= ($sort_by === 'membership_status' && $sort_order === 'asc') ? 'desc' : 'asc' ?>" class="flex items-center">
                                        Status
                                        <?php if ($sort_by === 'membership_status'): ?>
                                            <i class="fas fa-sort-<?= $sort_order === 'asc' ? 'up' : 'down' ?> ml-1"></i>
                                        <?php else: ?>
                                            <i class="fas fa-sort ml-1 text-gray-500"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                    <a href="?page=<?= $page ?>&gym_id=<?= $gym_id ?>&membership=<?= $membership ?>&search=<?= urlencode($search) ?>&plan_id=<?= $plan_id ?>&sort_by=used_visits&sort_order=<?= ($sort_by === 'used_visits' && $sort_order === 'asc') ? 'desc' : 'asc' ?>" class="flex items-center">
                                        Usage
                                        <?php if ($sort_by === 'used_visits'): ?>
                                            <i class="fas fa-sort-<?= $sort_order === 'asc' ? 'up' : 'down' ?> ml-1"></i>
                                        <?php else: ?>
                                            <i class="fas fa-sort ml-1 text-gray-500"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                    <a href="?page=<?= $page ?>&gym_id=<?= $gym_id ?>&membership=<?= $membership ?>&search=<?= urlencode($search) ?>&plan_id=<?= $plan_id ?>&sort_by=created_at&sort_order=<?= ($sort_by === 'created_at' && $sort_order === 'asc') ? 'desc' : 'asc' ?>" class="flex items-center">
                                        Joined
                                        <?php if ($sort_by === 'created_at'): ?>
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
                            <?php if (empty($members)): ?>
                                <tr>
                                    <td colspan="7" class="px-6 py-4 text-center text-gray-400">
                                        No members found matching your criteria.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($members as $member): ?>
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="flex-shrink-0 h-10 w-10">
                                                    <?php if ($member['profile_image']): ?>
                                                        <img class="h-10 w-10 rounded-full object-cover" src="../uploads/profile/<?= htmlspecialchars($member['profile_image']); ?>" alt="Profile">
                                                    <?php else: ?>
                                                        <div class="h-10 w-10 rounded-full bg-gray-600 flex items-center justify-center">
                                                            <i class="fas fa-user text-gray-300"></i>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="ml-4">
                                                    <div class="text-sm font-medium text-white">
                                                        <?= htmlspecialchars($member['username']); ?>
                                                    </div>
                                                    <div class="text-sm text-gray-400">
                                                        <?= htmlspecialchars($member['email']); ?>
                                                    </div>
                                                    <?php if ($member['phone']): ?>
                                                        <div class="text-xs text-gray-500">
                                                            <?= htmlspecialchars($member['phone']); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php if ($member['gym_name']): ?>
                                                <div class="text-sm text-white"><?= htmlspecialchars($member['gym_name']); ?></div>
                                                <div class="text-xs text-gray-400"><?= htmlspecialchars($member['gym_city'] ?? ''); ?></div>
                                            <?php else: ?>
                                                <span class="text-gray-500">No gym</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php if ($member['plan_name']): ?>
                                                <div class="text-sm text-white"><?= htmlspecialchars($member['plan_name']); ?></div>
                                                <div class="text-xs text-gray-400">
                                                    <?= htmlspecialchars($member['duration']); ?> • 
                                                    ₹<?= number_format($member['price'], 2); ?>
                                                </div>
                                                <div class="text-xs text-gray-500">
                                                    <?php 
                                                    if ($member['start_date'] && $member['end_date']) {
                                                        echo date('M d, Y', strtotime($member['start_date'])) . ' - ' . date('M d, Y', strtotime($member['end_date']));
                                                    }
                                                    ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-gray-500">No active plan</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php if ($member['membership_status'] === 'active'): ?>
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-900 text-green-300">
                                                    Active
                                                </span>
                                            <?php elseif ($member['membership_status'] === 'expired'): ?>
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-900 text-red-300">
                                                    Expired
                                                </span>
                                            <?php elseif ($member['membership_status'] === 'cancelled'): ?>
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-700 text-gray-300">
                                                    Cancelled
                                                </span>
                                            <?php else: ?>
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-700 text-gray-300">
                                                    No membership
                                                </span>
                                            <?php endif; ?>
                                            
                                            <div class="mt-1">
                                                <?php if ($member['user_status'] === 'active'): ?>
                                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-900 text-blue-300">
                                                        Account Active
                                                    </span>
                                                <?php else: ?>
                                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-700 text-gray-300">
                                                        Account <?= ucfirst($member['user_status']); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php if (isset($member['used_visits']) && isset($member['total_visits'])): ?>
                                                <div class="text-sm text-white">
                                                    <?= $member['used_visits']; ?> / <?= $member['total_visits']; ?> visits
                                                </div>
                                                <div class="w-full bg-gray-700 rounded-full h-2 mt-1">
                                                    <div class="bg-blue-500 h-2 rounded-full" style="width: <?= min(100, ($member['used_visits'] / max(1, $member['total_visits'])) * 100); ?>%"></div>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-gray-500">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-400">
                                            <?= date('M d, Y', strtotime($member['created_at'])); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                            <div class="flex justify-end space-x-2">
                                                <a href="member_details.php?id=<?= $member['id']; ?>" class="text-blue-400                                                  hover:text-blue-300" title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                
                                                <div class="relative" x-data="{ open: false }">
                                                    <button @click="open = !open" class="text-gray-400 hover:text-white focus:outline-none" title="More Actions">
                                                        <i class="fas fa-ellipsis-v"></i>
                                                    </button>
                                                    
                                                    <div x-show="open" @click.away="open = false" class="origin-top-right absolute right-0 mt-2 w-48 rounded-md shadow-lg bg-gray-700 ring-1 ring-black ring-opacity-5 z-10">
                                                        <div class="py-1">
                                                            <?php if ($member['user_status'] === 'active'): ?>
                                                                <form method="POST" class="block">
                                                                    <input type="hidden" name="member_id" value="<?= $member['id']; ?>">
                                                                    <input type="hidden" name="action" value="deactivate">
                                                                    <button type="submit" class="block w-full text-left px-4 py-2 text-sm text-gray-300 hover:bg-gray-600">
                                                                        <i class="fas fa-user-slash mr-2"></i> Deactivate Account
                                                                    </button>
                                                                </form>
                                                            <?php else: ?>
                                                                <form method="POST" class="block">
                                                                    <input type="hidden" name="member_id" value="<?= $member['id']; ?>">
                                                                    <input type="hidden" name="action" value="activate">
                                                                    <button type="submit" class="block w-full text-left px-4 py-2 text-sm text-gray-300 hover:bg-gray-600">
                                                                        <i class="fas fa-user-check mr-2"></i> Activate Account
                                                                    </button>
                                                                </form>
                                                            <?php endif; ?>
                                                            
                                                            <?php if ($member['membership_id']): ?>
                                                                <button type="button" onclick="openExtendModal(<?= $member['id']; ?>, <?= $member['membership_id']; ?>, '<?= htmlspecialchars($member['username']); ?>')" class="block w-full text-left px-4 py-2 text-sm text-gray-300 hover:bg-gray-600">
                                                                    <i class="fas fa-calendar-plus mr-2"></i> Extend Membership
                                                                </button>
                                                            <?php endif; ?>
                                                            
                                                            <a href="edit_member.php?id=<?= $member['id']; ?>" class="block px-4 py-2 text-sm text-gray-300 hover:bg-gray-600">
                                                                <i class="fas fa-edit mr-2"></i> Edit Member
                                                            </a>
                                                            
                                                            <form method="POST" class="block" onsubmit="return confirm('Are you sure you want to delete this member? This action cannot be undone.');">
                                                                <input type="hidden" name="member_id" value="<?= $member['id']; ?>">
                                                                <input type="hidden" name="action" value="delete">
                                                                <button type="submit" class="block w-full text-left px-4 py-2 text-sm text-red-400 hover:bg-gray-600">
                                                                    <i class="fas fa-trash-alt mr-2"></i> Delete Member
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
                            Showing <?= ($offset + 1) ?>-<?= min($offset + $limit, $totalRecords) ?> of <?= $totalRecords ?> members
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
                <input type="hidden" name="member_id" id="extendMemberId">
                <input type="hidden" name="membership_id" id="extendMembershipId">
                <input type="hidden" name="action" value="extend_membership">
                
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

    <script src="https://cdn.jsdelivr.net/npm/alpinejs@2.8.2/dist/alpine.min.js" defer></script>
    <script>
        function openExtendModal(memberId, membershipId, memberName) {
            document.getElementById('extendMemberId').value = memberId;
            document.getElementById('extendMembershipId').value = membershipId;
            document.getElementById('extendMemberName').textContent = memberName;
            document.getElementById('extendMembershipModal').classList.remove('hidden');
        }
        
        function closeExtendModal() {
            document.getElementById('extendMembershipModal').classList.add('hidden');
        }
    </script>
</body>
</html>


