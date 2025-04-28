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

$gym_id = isset($_GET['gym_id']) ? (int)$_GET['gym_id'] : 0;

if (!$gym_id) {
    $_SESSION['error'] = "Invalid gym ID.";
    header('Location: gyms.php');
    exit();
}

// Get gym details
try {
    $stmt = $conn->prepare("
        SELECT gym_id, name, address, city, state, status, cover_photo, owner_id
        FROM gyms
        WHERE gym_id = ?
    ");
    $stmt->execute([$gym_id]);
    $gym = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$gym) {
        $_SESSION['error'] = "Gym not found.";
        header('Location: gyms.php');
        exit();
    }
    
    // Get gym owner details
    $stmt = $conn->prepare("
        SELECT id, username, email, phone, profile_image
        FROM users
        WHERE id = ? AND role = 'gym_partner'
    ");
    $stmt->execute([$gym['owner_id']]);
    $owner = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $_SESSION['error'] = "Database error: " . $e->getMessage();
    header('Location: gyms.php');
    exit();
}

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Filters
$status = isset($_GET['status']) ? $_GET['status'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$membership_status = isset($_GET['membership_status']) ? $_GET['membership_status'] : '';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'um.created_at';
$order = isset($_GET['order']) ? $_GET['order'] : 'DESC';

// Build query
$query = "
    SELECT u.id, u.username, u.email, u.phone, u.profile_image, u.status, u.city,
           um.id as membership_id, um.start_date, um.end_date, um.status as membership_status,
           gmp.plan_name, gmp.price, gmp.duration,
           (SELECT COUNT(*) FROM schedules s WHERE s.user_id = u.id AND s.gym_id = ? AND s.status = 'completed') as visit_count
    FROM users u
    JOIN user_memberships um ON u.id = um.user_id
    JOIN gym_membership_plans gmp ON um.plan_id = gmp.plan_id
    WHERE um.gym_id = ?
";
$countQuery = "
    SELECT COUNT(DISTINCT u.id) 
    FROM users u
    JOIN user_memberships um ON u.id = um.user_id
    WHERE um.gym_id = ?
";
$params = [$gym_id, $gym_id];
$countParams = [$gym_id];

if (!empty($status)) {
    $query .= " AND u.status = ?";
    $countQuery .= " AND u.status = ?";
    $params[] = $status;
    $countParams[] = $status;
}

if (!empty($search)) {
    $query .= " AND (u.username LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
    $countQuery .= " AND (u.username LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $countParams[] = $searchParam;
    $countParams[] = $searchParam;
    $countParams[] = $searchParam;
}

if (!empty($membership_status)) {
    $query .= " AND um.status = ?";
    $countQuery .= " AND um.status = ?";
    $params[] = $membership_status;
    $countParams[] = $membership_status;
}

// Group by user to avoid duplicates
$query .= " GROUP BY u.id";

// Add sorting
$query .= " ORDER BY $sort $order";

// Add pagination
$query .= " LIMIT $per_page OFFSET $offset";

// Execute queries
try {
    // Get total count
    $stmt = $conn->prepare($countQuery);
    $stmt->execute($countParams);
    $total_members = $stmt->fetchColumn();
    
    // Get members for current page
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate total pages
    $total_pages = ceil($total_members / $per_page);
} catch (PDOException $e) {
    $_SESSION['error'] = "Database error: " . $e->getMessage();
    $members = [];
    $total_members = 0;
    $total_pages = 1;
}

// Get membership statistics
try {
    $stmt = $conn->prepare("
        SELECT 
            COUNT(DISTINCT um.user_id) as total_members,
            SUM(CASE WHEN um.status = 'active' THEN 1 ELSE 0 END) as active_memberships,
            SUM(CASE WHEN um.status = 'expired' THEN 1 ELSE 0 END) as expired_memberships,
            SUM(CASE WHEN um.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_memberships,
            SUM(um.amount) as total_revenue
        FROM user_memberships um
        WHERE um.gym_id = ?
    ");
    $stmt->execute([$gym_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $stats = [
        'total_members' => 0,
        'active_memberships' => 0,
        'expired_memberships' => 0,
        'cancelled_memberships' => 0,
        'total_revenue' => 0
    ];
}

// Function to format date
function formatDate($date) {
    return date('M d, Y', strtotime($date));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gym Members - FlexFit Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body class="bg-gray-900 text-white">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="ml-0 lg:ml-64 p-4 sm:p-6 transition-all duration-200">
        <div class="flex justify-between items-center mb-6">
            <div class="flex items-center">
                <a href="gyms.php" class="mr-4 text-gray-400 hover:text-white">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <h1 class="text-2xl font-bold">Members of <?= htmlspecialchars($gym['name']) ?></h1>
            </div>
            <a href="add_membership.php?gym_id=<?= $gym_id ?>" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-plus mr-2"></i> Add Membership
            </a>
        </div>
        
        <?php if (isset($_SESSION['success'])): ?>
        <div class="bg-green-600 text-white p-4 rounded-lg mb-6">
            <p><?= htmlspecialchars($_SESSION['success']) ?></p>
            <?php unset($_SESSION['success']); ?>
        </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
        <div class="bg-red-600 text-white p-4 rounded-lg mb-6">
            <p><?= htmlspecialchars($_SESSION['error']) ?></p>
            <?php unset($_SESSION['error']); ?>
        </div>
        <?php endif; ?>
        
        <!-- Gym Profile Card -->
        <div class="bg-gray-800 rounded-xl shadow-lg overflow-hidden mb-6">
            <div class="h-40 bg-gray-700 relative">
                <img src="../uploads/gym_images/<?= htmlspecialchars($gym['cover_photo'] ?? 'default_gym.jpg') ?>" 
                     alt="<?= htmlspecialchars($gym['name']) ?>" 
                     class="w-full h-full object-cover">
                     
                <?php
                    $statusClass = '';
                    $statusBadge = '';
                    
                    switch ($gym['status']) {
                        case 'active':
                            $statusClass = 'bg-green-900 text-green-300';
                            $statusBadge = '<span class="px-2 py-1 text-xs rounded-full bg-green-900 text-green-300">Active</span>';
                            break;
                        case 'inactive':
                            $statusClass = 'bg-yellow-900 text-yellow-300';
                            $statusBadge = '<span class="px-2 py-1 text-xs rounded-full bg-yellow-900 text-yellow-300">Inactive</span>';
                            break;
                        case 'pending':
                            $statusClass = 'bg-blue-900 text-blue-300';
                            $statusBadge = '<span class="px-2 py-1 text-xs rounded-full bg-blue-900 text-blue-300">Pending</span>';
                            break;
                        case 'suspended':
                            $statusClass = 'bg-red-900 text-red-300';
                            $statusBadge = '<span class="px-2 py-1 text-xs rounded-full bg-red-900 text-red-300">Suspended</span>';
                            break;
                        default:
                            $statusClass = 'bg-gray-700 text-gray-300';
                            $statusBadge = '<span class="px-2 py-1 text-xs rounded-full bg-gray-700 text-gray-300">Unknown</span>';
                    }
                ?>
                <div class="absolute top-2 right-2">
                    <?= $statusBadge ?>
                </div>
            </div>
            
            <div class="p-6">
                <div class="flex justify-between items-start">
                    <div>
                        <h2 class="text-xl font-semibold"><?= htmlspecialchars($gym['name']) ?></h2>
                        <p class="text-gray-400 mt-1">
                            <i class="fas fa-map-marker-alt mr-2"></i>
                            <?= htmlspecialchars($gym['address']) ?>, 
                            <?= htmlspecialchars($gym['city']) ?>, 
                            <?= htmlspecialchars($gym['state']) ?>
                        </p>
                    </div>
                    
                    <?php if ($owner): ?>
                    <div class="flex items-center">
                        <div class="mr-3 text-right">
                            <p class="text-sm text-gray-400">Owner</p>
                            <p class="font-medium"><?= htmlspecialchars($owner['username']) ?></p>
                            <p class="text-xs text-gray-400"><?= htmlspecialchars($owner['email']) ?></p>
                        </div>
                        <div class="flex-shrink-0">
                            <?php if (!empty($owner['profile_image'])): ?>
                                <img src="<?= htmlspecialchars($owner['profile_image']) ?>" alt="Owner" class="h-10 w-10 rounded-full object-cover">
                            <?php else: ?>
                                <div class="h-10 w-10 rounded-full bg-gray-600 flex items-center justify-center">
                                    <i class="fas fa-user text-gray-300"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="p-6 bg-gray-700 bg-opacity-50">
                <div class="flex space-x-2">
                    <a href="view_gym.php?id=<?= $gym_id ?>" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center">
                        <i class="fas fa-dumbbell mr-2"></i> View Gym
                    </a>
                    <a href="edit_gym.php?id=<?= $gym_id ?>" class="bg-yellow-600 hover:bg-yellow-700 text-white px-4 py-2 rounded-lg flex items-center">
                        <i class="fas fa-edit mr-2"></i> Edit Gym
                    </a>
                    <a href="gym_bookings.php?gym_id=<?= $gym_id ?>" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg flex items-center">
                        <i class="fas fa-calendar-check mr-2"></i> View Bookings
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div class="bg-gray-800 rounded-xl shadow-lg p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-400 text-sm">Total Members</p>
                        <h3 class="text-2xl font-bold"><?= number_format($stats['total_members']) ?></h3>
                    </div>
                    <div class="bg-blue-900 bg-opacity-50 p-3 rounded-full">
                        <i class="fas fa-users text-blue-400 text-xl"></i>
                    </div>
                </div>
            </div>
            
            <div class="bg-gray-800 rounded-xl shadow-lg p-4">
                <div class="flex items-center justify-between">
                    <div>
                    <p class="text-gray-400 text-sm">Active Memberships</p>
                        <h3 class="text-2xl font-bold"><?= number_format($stats['active_memberships']) ?></h3>
                    </div>
                    <div class="bg-green-900 bg-opacity-50 p-3 rounded-full">
                        <i class="fas fa-user-check text-green-400 text-xl"></i>
                    </div>
                </div>
            </div>
            
            <div class="bg-gray-800 rounded-xl shadow-lg p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-400 text-sm">Expired Memberships</p>
                        <h3 class="text-2xl font-bold"><?= number_format($stats['expired_memberships']) ?></h3>
                    </div>
                    <div class="bg-yellow-900 bg-opacity-50 p-3 rounded-full">
                        <i class="fas fa-user-clock text-yellow-400 text-xl"></i>
                    </div>
                </div>
            </div>
            
            <div class="bg-gray-800 rounded-xl shadow-lg p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-400 text-sm">Total Revenue</p>
                        <h3 class="text-2xl font-bold">₹<?= number_format($stats['total_revenue'], 2) ?></h3>
                    </div>
                    <div class="bg-purple-900 bg-opacity-50 p-3 rounded-full">
                        <i class="fas fa-rupee-sign text-purple-400 text-xl"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="bg-gray-800 rounded-xl shadow-lg p-6 mb-6">
            <form action="" method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <input type="hidden" name="gym_id" value="<?= $gym_id ?>">
                
                <div>
                    <label for="search" class="block text-sm font-medium text-gray-400 mb-1">Search</label>
                    <input type="text" id="search" name="search" value="<?= htmlspecialchars($search) ?>" 
                        class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500"
                        placeholder="Name, email, phone...">
                </div>
                
                <div>
                    <label for="status" class="block text-sm font-medium text-gray-400 mb-1">User Status</label>
                    <select id="status" name="status" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                        <option value="">All Statuses</option>
                        <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        <option value="suspended" <?= $status === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                    </select>
                </div>
                
                <div>
                    <label for="membership_status" class="block text-sm font-medium text-gray-400 mb-1">Membership Status</label>
                    <select id="membership_status" name="membership_status" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                        <option value="">All Memberships</option>
                        <option value="active" <?= $membership_status === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="expired" <?= $membership_status === 'expired' ? 'selected' : '' ?>>Expired</option>
                        <option value="cancelled" <?= $membership_status === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                </div>
                
                <div class="flex items-end">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg w-full">
                        <i class="fas fa-filter mr-2"></i> Apply Filters
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Members Table -->
        <div class="bg-gray-800 rounded-xl shadow-lg overflow-hidden">
            <div class="p-6 border-b border-gray-700">
                <div class="flex justify-between items-center">
                    <h2 class="text-xl font-semibold">Gym Members</h2>
                    <p class="text-gray-400">
                        Total: <?= number_format($total_members) ?> members
                    </p>
                </div>
            </div>
            
            <?php if (empty($members)): ?>
                <div class="p-6 text-center text-gray-500">
                    <i class="fas fa-users text-4xl mb-3"></i>
                    <p>No members found matching your criteria.</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-700">
                        <thead>
                            <tr class="bg-gray-700">
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Member</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Membership</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Period</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Visits</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-gray-800 divide-y divide-gray-700">
                            <?php foreach ($members as $member): ?>
                                <tr class="hover:bg-gray-700">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="flex-shrink-0 h-10 w-10">
                                                <?php if (!empty($member['profile_image'])): ?>
                                                    <img class="h-10 w-10 rounded-full object-cover" src="<?= htmlspecialchars($member['profile_image']) ?>" alt="Profile image">
                                                <?php else: ?>
                                                    <div class="h-10 w-10 rounded-full bg-gray-600 flex items-center justify-center">
                                                        <i class="fas fa-user text-gray-300"></i>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="ml-4">
                                                <div class="text-sm font-medium text-white"><?= htmlspecialchars($member['username']) ?></div>
                                                <div class="text-sm text-gray-400"><?= htmlspecialchars($member['email']) ?></div>
                                                <?php if (!empty($member['phone'])): ?>
                                                    <div class="text-sm text-gray-400"><?= htmlspecialchars($member['phone']) ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-white"><?= htmlspecialchars($member['plan_name']) ?></div>
                                        <div class="text-sm text-gray-400">₹<?= number_format($member['price'], 2) ?></div>
                                        <div class="text-xs text-gray-500"><?= htmlspecialchars($member['duration']) ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-300">
                                        <?= formatDate($member['start_date']) ?> to<br>
                                        <?= formatDate($member['end_date']) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php
                                            $membershipStatusClass = '';
                                            $membershipStatusText = '';
                                            
                                            switch ($member['membership_status']) {
                                                case 'active':
                                                    $membershipStatusClass = 'bg-green-900 text-green-300';
                                                    $membershipStatusText = 'Active';
                                                    break;
                                                case 'expired':
                                                    $membershipStatusClass = 'bg-yellow-900 text-yellow-300';
                                                    $membershipStatusText = 'Expired';
                                                    break;
                                                case 'cancelled':
                                                    $membershipStatusClass = 'bg-red-900 text-red-300';
                                                    $membershipStatusText = 'Cancelled';
                                                    break;
                                                default:
                                                    $membershipStatusClass = 'bg-gray-700 text-gray-300';
                                                    $membershipStatusText = 'Unknown';
                                            }
                                            
                                            $userStatusClass = '';
                                            
                                            switch ($member['status']) {
                                                case 'active':
                                                    $userStatusClass = 'bg-green-900 text-green-300';
                                                    $userStatusText = 'Active';
                                                    break;
                                                case 'inactive':
                                                    $userStatusClass = 'bg-yellow-900 text-yellow-300';
                                                    $userStatusText = 'Inactive';
                                                    break;
                                                case 'suspended':
                                                    $userStatusClass = 'bg-red-900 text-red-300';
                                                    $userStatusText = 'Suspended';
                                                    break;
                                                default:
                                                    $userStatusClass = 'bg-gray-700 text-gray-300';
                                                    $userStatusText = 'Unknown';
                                            }
                                        ?>
                                        <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?= $membershipStatusClass ?>">
                                            <?= $membershipStatusText ?>
                                        </span>
                                        <div class="mt-1">
                                            <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?= $userStatusClass ?>">
                                                User: <?= $userStatusText ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-300">
                                        <div class="text-center">
                                            <span class="text-xl font-bold"><?= $member['visit_count'] ?></span>
                                            <div class="text-xs text-gray-500">visits</div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <div class="flex space-x-2">
                                            <a href="view_user.php?id=<?= $member['id'] ?>" class="text-blue-400 hover:text-blue-300" title="View Member">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="edit_user.php?id=<?= $member['id'] ?>" class="text-yellow-400 hover:text-yellow-300" title="Edit Member">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="edit_membership.php?id=<?= $member['membership_id'] ?>" class="text-green-400 hover:text-green-300" title="Edit Membership">
                                                <i class="fas fa-id-card"></i>
                                            </a>
                                            <a href="user_bookings.php?user_id=<?= $member['id'] ?>" class="text-purple-400 hover:text-purple-300" title="View Bookings">
                                                <i class="fas fa-calendar-check"></i>
                                            </a>
                                            <a href="member_schedule.php?id=<?= $member['id'] ?>" class="text-indigo-400 hover:text-indigo-300" title="View Schedule">
                                                <i class="fas fa-calendar-alt"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="px-6 py-4 bg-gray-700 border-t border-gray-600">
                    <div class="flex items-center justify-between">
                        <div class="text-sm text-gray-400">
                            Showing <?= ($page - 1) * $per_page + 1 ?> to <?= min($page * $per_page, $total_members) ?> of <?= $total_members ?> members
                        </div>
                        <div class="flex space-x-1">
                            <?php if ($page > 1): ?>
                                <a href="?gym_id=<?= $gym_id ?>&page=1&status=<?= urlencode($status) ?>&search=<?= urlencode($search) ?>&membership_status=<?= urlencode($membership_status) ?>&sort=<?= urlencode($sort) ?>&order=<?= urlencode($order) ?>" class="px-3 py-1 rounded-md bg-gray-600 text-white hover:bg-gray-500">
                                    <i class="fas fa-angle-double-left"></i>
                                </a>
                                <a href="?gym_id=<?= $gym_id ?>&page=<?= $page - 1 ?>&status=<?= urlencode($status) ?>&search=<?= urlencode($search) ?>&membership_status=<?= urlencode($membership_status) ?>&sort=<?= urlencode($sort) ?>&order=<?= urlencode($order) ?>" class="px-3 py-1 rounded-md bg-gray-600 text-white hover:bg-gray-500">
                                    <i class="fas fa-angle-left"></i>
                                </a>
                            <?php endif; ?>
                            
                            <?php
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);
                            
                            for ($i = $start_page; $i <= $end_page; $i++):
                            ?>
                                <a href="?gym_id=<?= $gym_id ?>&page=<?= $i ?>&status=<?= urlencode($status) ?>&search=<?= urlencode($search) ?>&membership_status=<?= urlencode($membership_status) ?>&sort=<?= urlencode($sort) ?>&order=<?= urlencode($order) ?>" class="px-3 py-1 rounded-md <?= $i === $page ? 'bg-blue-600 text-white' : 'bg-gray-600 text-white hover:bg-gray-500' ?>">
                                    <?= $i ?>
                                </a>
                            <?php endfor; ?>
                            
                            <?php if ($page < $total_pages): ?>
                                <a href="?gym_id=<?= $gym_id ?>&page=<?= $page + 1 ?>&status=<?= urlencode($status) ?>&search=<?= urlencode($search) ?>&membership_status=<?= urlencode($membership_status) ?>&sort=<?= urlencode($sort) ?>&order=<?= urlencode($order) ?>" class="px-3 py-1 rounded-md bg-gray-600 text-white hover:bg-gray-500">
                                    <i class="fas fa-angle-right"></i>
                                </a>
                                <a href="?gym_id=<?= $gym_id ?>&page=<?= $total_pages ?>&status=<?= urlencode($status) ?>&search=<?= urlencode($search) ?>&membership_status=<?= urlencode($membership_status) ?>&sort=<?= urlencode($sort) ?>&order=<?= urlencode($order) ?>" class="px-3 py-1 rounded-md bg-gray-600 text-white hover:bg-gray-500">
                                    <i class="fas fa-angle-double-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        
        <!-- Add Member Modal Button -->
        <div class="mt-6 text-center">
            <button id="openAddMemberModal" class="bg-green-600 hover:bg-green-700 text-white px-6 py-3 rounded-lg inline-flex items-center">
                <i class="fas fa-user-plus mr-2"></i> Quick Add Member to Gym
            </button>
        </div>
        
        <!-- Add Member Modal -->
        <div id="addMemberModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center hidden">
            <div class="bg-gray-800 rounded-xl shadow-lg w-full max-w-2xl mx-4">
                <div class="p-6 border-b border-gray-700 flex justify-between items-center">
                    <h3 class="text-xl font-semibold">Add Member to <?= htmlspecialchars($gym['name']) ?></h3>
                    <button id="closeAddMemberModal" class="text-gray-400 hover:text-white">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                
                <form action="process_add_member.php" method="POST" class="p-6">
                    <input type="hidden" name="gym_id" value="<?= $gym_id ?>">
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label for="member_type" class="block text-sm font-medium text-gray-400 mb-1">Member Type</label>
                            <select id="member_type" name="member_type" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500" required>
                                <option value="existing">Existing User</option>
                                <option value="new">New User</option>
                            </select>
                        </div>
                        
                        <div id="existing_user_field">
                            <label for="user_id" class="block text-sm font-medium text-gray-400 mb-1">Select User</label>
                            <select id="user_id" name="user_id" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                                <option value="">Select a user...</option>
                                <!-- This will be populated via AJAX -->
                            </select>
                        </div>
                        
                        <div id="new_user_fields" class="hidden md:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="username" class="block text-sm font-medium text-gray-400 mb-1">Username</label>
                                <input type="text" id="username" name="username" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                            </div>
                            
                            <div>
                                <label for="email" class="block text-sm font-medium text-gray-400 mb-1">Email</label>
                                <input type="email" id="email" name="email" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                            </div>
                            
                            <div>
                                <label for="phone" class="block text-sm font-medium text-gray-400 mb-1">Phone</label>
                                <input type="text" id="phone" name="phone" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                            </div>
                            
                            <div>
                                <label for="password" class="block text-sm font-medium text-gray-400 mb-1">Password</label>
                                <input type="password" id="password" name="password" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                            </div>
                        </div>
                    </div>
                    
                    <div class="border-t border-gray-700 pt-4 mt-4">
                        <h4 class="font-medium mb-2">Membership Details</h4>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label for="plan_id" class="block text-sm font-medium text-gray-400 mb-1">Membership Plan</label>
                                <select id="plan_id" name="plan_id" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500" required>
                                    <option value="">Select a plan...</option>
                                    <?php
                                    // Fetch membership plans for this gym
                                    try {
                                        $stmt = $conn->prepare("
                                            SELECT plan_id, plan_name, price, duration
                                            FROM gym_membership_plans
                                            WHERE gym_id = ? AND status = 'active'
                                            ORDER BY price ASC
                                        ");
                                        $stmt->execute([$gym_id]);
                                        $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                        
                                        foreach ($plans as $plan) {
                                            echo '<option value="' . $plan['plan_id'] . '">' . 
                                                htmlspecialchars($plan['plan_name']) . ' - ₹' . 
                                                number_format($plan['price'], 2) . ' (' . 
                                                htmlspecialchars($plan['duration']) . ')</option>';
                                        }
                                    } catch (PDOException $e) {
                                        // Handle error silently
                                    }
                                    ?>
                                </select>
                            </div>
                            
                            <div>
                                <label for="start_date" class="block text-sm font-medium text-gray-400 mb-1">Start Date</label>
                                <input type="date" id="start_date" name="start_date" value="<?= date('Y-m-d') ?>" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500" required>
                            </div>
                            
                            <div>
                                <label for="payment_status" class="block text-sm font-medium text-gray-400 mb-1">Payment Status</label>
                                <select id="payment_status" name="payment_status" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500" required>
                                    <option value="paid">Paid</option>
                                    <option value="pending">Pending</option>
                                </select>
                            </div>
                            
                            <div>
                                <label for="amount" class="block text-sm font-medium text-gray-400 mb-1">Amount Paid</label>
                                <input type="number" id="amount" name="amount" step="0.01" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500" required>
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <label for="notes" class="block text-sm font-medium text-gray-400 mb-1">Notes</label>
                            <textarea id="notes" name="notes" rows="3" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500"></textarea>
                        </div>
                    </div>
                    
                    <div class="flex justify-end space-x-3 mt-6">
                        <button type="button" id="cancelAddMember" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg">
                            Cancel
                        </button>
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-save mr-2"></i> Add Member
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        // Modal functionality
        const addMemberModal = document.getElementById('addMemberModal');
        const openAddMemberModal = document.getElementById('openAddMemberModal');
        const closeAddMemberModal = document.getElementById('closeAddMemberModal');
        const cancelAddMember = document.getElementById('cancelAddMember');
        const memberTypeSelect = document.getElementById('member_type');
        const existingUserField = document.getElementById('existing_user_field');
        const newUserFields = document.getElementById('new_user_fields');
        const planSelect = document.getElementById('plan_id');
        const amountInput = document.getElementById('amount');
        
        // Open modal
        openAddMemberModal.addEventListener('click', () => {
            addMemberModal.classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
        });
        
        // Close modal
        const closeModal = () => {
            addMemberModal.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        };
        
        closeAddMemberModal.addEventListener('click', closeModal);
        cancelAddMember.addEventListener('click', closeModal);
        
        // Close modal when clicking outside
        addMemberModal.addEventListener('click', (e) => {
            if (e.target === addMemberModal) {
                closeModal();
            }
        });
        
        // Toggle between existing and new user fields
        memberTypeSelect.addEventListener('change', () => {
            if (memberTypeSelect.value === 'existing') {
                existingUserField.classList.remove('hidden');
                newUserFields.classList.add('hidden');
            } else {
                existingUserField.classList.add('hidden');
                newUserFields.classList.remove('hidden');
            }
        });
        
        // Load users via AJAX for the dropdown
        const userSelect = document.getElementById('user_id');
        
        // Simple function to load users
        const loadUsers = () => {
            fetch('ajax_get_users.php')
                .then(response => response.json())
                .then(users => {
                    userSelect.innerHTML = '<option value="">Select a user...</option>';
                    users.forEach(user => {
                        const option = document.createElement('option');
                        option.value = user.id;
                        option.textContent = `${user.username} (${user.email})`;
                        userSelect.appendChild(option);
                    });
                })
                .catch(error => console.error('Error loading users:', error));
        };
        
        // Load users when the page loads
        document.addEventListener('DOMContentLoaded', loadUsers);
        
        // Update amount when plan changes
        planSelect.addEventListener('change', () => {
            if (planSelect.value) {
                const selectedOption = planSelect.options[planSelect.selectedIndex];
                const priceMatch = selectedOption.textContent.match(/₹([\d,]+\.\d+)/);
                if (priceMatch && priceMatch[1]) {
                    // Remove commas and convert to number
                    const price = parseFloat(priceMatch[1].replace(/,/g, ''));
                    amountInput.value = price.toFixed(2);
                }
            }
        });
    </script>
</body>
</html>


