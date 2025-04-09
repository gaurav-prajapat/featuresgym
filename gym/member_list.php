<?php
include '../includes/navbar.php';
require_once '../config/database.php';

$db = new GymDatabase();
$conn = $db->getConnection();

if (!isset($_SESSION['owner_id'])) {
    header('Location: login.php');
    exit;
}

$owner_id = $_SESSION['owner_id'];

// Get gym details
$gymsStmt = $conn->prepare("SELECT gym_id, name FROM gyms WHERE owner_id = :owner_id");
$gymsStmt->bindValue(':owner_id', $owner_id);
$gymsStmt->execute();
$gyms = $gymsStmt->fetchAll(PDO::FETCH_ASSOC);

$gym_id = isset($_GET['gym_id']) && !empty($_GET['gym_id']) ? (int)$_GET['gym_id'] : 
          (isset($gyms[0]['gym_id']) ? $gyms[0]['gym_id'] : null);

if (!$gym_id) {
    echo "<div class='container mx-auto px-4 py-20'><div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded'>No gym found for this owner.</div></div>";
    exit;
}

// Pagination
$limit = 10;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

// Filters
$membership = isset($_GET['membership']) ? $_GET['membership'] : 'all';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$plan_id = isset($_GET['plan_id']) ? (int)$_GET['plan_id'] : '';
$sort_by = isset($_GET['sort_by']) && in_array($_GET['sort_by'], ['username', 'visit_count', 'start_date', 'end_date']) ? $_GET['sort_by'] : 'username';
$sort_order = isset($_GET['sort_order']) && in_array(strtoupper($_GET['sort_order']), ['ASC', 'DESC']) ? strtoupper($_GET['sort_order']) : 'ASC';
$date_from = isset($_GET['date_from']) && !empty($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) && !empty($_GET['date_to']) ? $_GET['date_to'] : '';

// Get membership plans
$planStmt = $conn->prepare("SELECT plan_id, plan_name FROM gym_membership_plans WHERE gym_id = :gym_id");
$planStmt->bindValue(':gym_id', $gym_id);
$planStmt->execute();
$membershipPlans = $planStmt->fetchAll(PDO::FETCH_ASSOC);

// Build the base query
$baseQuery = "
    SELECT 
        u.id, u.username, u.email, u.phone,
        um.status as membership_status, 
        um.start_date, 
        um.end_date,
        gmp.plan_name, 
        gmp.duration, 
        gmp.price,
        g.name as gym_name,
        COUNT(DISTINCT s.id) as visit_count,
        COUNT(DISTINCT CASE WHEN s.status = 'completed' THEN s.id END) as completed_workouts
    FROM users u
    LEFT JOIN user_memberships um ON u.id = um.user_id
    LEFT JOIN gym_membership_plans gmp ON um.plan_id = gmp.plan_id
    LEFT JOIN gyms g ON um.gym_id = g.gym_id
    LEFT JOIN schedules s ON u.id = s.user_id 
    WHERE u.role = 'member' 
    AND g.owner_id = :owner_id
";

// Initialize params with owner_id
$params = [':owner_id' => $owner_id];

// Add other conditions
if ($membership !== 'all') {
    $baseQuery .= " AND um.status = :membership";
    $params[':membership'] = $membership;
}

if (!empty($search)) {
    $baseQuery .= " AND (u.username LIKE :search OR u.email LIKE :search OR u.phone LIKE :search)";
    $params[':search'] = "%$search%";
}

if (!empty($plan_id)) {
    $baseQuery .= " AND gmp.plan_id = :plan_id";
    $params[':plan_id'] = $plan_id;
}

if (!empty($date_from)) {
    $baseQuery .= " AND um.start_date >= :date_from";
    $params[':date_from'] = $date_from;
}

if (!empty($date_to)) {
    $baseQuery .= " AND um.end_date <= :date_to";
    $params[':date_to'] = $date_to;
}

// Group by and add sorting
$baseQuery .= " GROUP BY u.id ORDER BY $sort_by $sort_order";

// Count total records (without pagination)
$countQuery = "SELECT COUNT(*) FROM (" . $baseQuery . ") as count_table";
$countStmt = $conn->prepare($countQuery);
$countStmt->execute($params);
$totalRecords = $countStmt->fetchColumn();
$totalPages = ceil($totalRecords / $limit);

// Add pagination to the main query
$baseQuery .= " LIMIT " . (int)$limit . " OFFSET " . (int)$offset;

// Execute query with matched parameters
$stmt = $conn->prepare($baseQuery);
$stmt->execute($params);
$members = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$statsQuery = "
    SELECT 
        COUNT(DISTINCT u.id) as total_members,
        SUM(CASE WHEN um.status = 'active' THEN 1 ELSE 0 END) as active_members,
        COUNT(DISTINCT CASE WHEN DATE(s.start_date) = CURRENT_DATE THEN s.id END) as today_visits,
        COUNT(DISTINCT gmp.plan_id) as total_plans
    FROM users u
    LEFT JOIN user_memberships um ON u.id = um.user_id
    LEFT JOIN gym_membership_plans gmp ON um.plan_id = gmp.plan_id
    LEFT JOIN schedules s ON u.id = s.user_id
    WHERE u.role = 'member' AND um.gym_id = :gym_id
";

$statsStmt = $conn->prepare($statsQuery);
$statsStmt->bindValue(':gym_id', $gym_id);
$statsStmt->execute();
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Function to generate pagination links
function generatePaginationLinks($currentPage, $totalPages, $queryParams) {
    if ($totalPages <= 1) return '';
    
    $links = '<div class="flex">';
    
    // Previous page link
    if ($currentPage > 1) {
        $prevParams = $queryParams;
        $prevParams['page'] = $currentPage - 1;
        $links .= '<a href="?' . http_build_query($prevParams) . '" class="px-3 py-2 bg-gray-200 text-gray-700 rounded-l hover:bg-gray-300">&laquo;</a>';
    } else {
        $links .= '<span class="px-3 py-2 bg-gray-100 text-gray-400 rounded-l">&laquo;</span>';
    }
    
    // Page number links
    $startPage = max(1, $currentPage - 2);
    $endPage = min($totalPages, $startPage + 4);
    
    if ($startPage > 1) {
        $firstParams = $queryParams;
        $firstParams['page'] = 1;
        $links .= '<a href="?' . http_build_query($firstParams) . '" class="px-3 py-2 bg-gray-200 text-gray-700 hover:bg-gray-300">1</a>';
        if ($startPage > 2) {
            $links .= '<span class="px-3 py-2">...</span>';
        }
    }
    
    for ($i = $startPage; $i <= $endPage; $i++) {
        $pageParams = $queryParams;
        $pageParams['page'] = $i;
        if ($i == $currentPage) {
            $links .= '<span class="px-3 py-2 bg-yellow-500 text-white">' . $i . '</span>';
        } else {
            $links .= '<a href="?' . http_build_query($pageParams) . '" class="px-3 py-2 bg-gray-200 text-gray-700 hover:bg-gray-300">' . $i . '</a>';
        }
    }
    
    if ($endPage < $totalPages) {
        if ($endPage < $totalPages - 1) {
            $links .= '<span class="px-3 py-2">...</span>';
        }
        $lastParams = $queryParams;
        $lastParams['page'] = $totalPages;
        $links .= '<a href="?' . http_build_query($lastParams) . '" class="px-3 py-2 bg-gray-200 text-gray-700 hover:bg-gray-300">' . $totalPages . '</a>';
    }
    
    // Next page link
    if ($currentPage < $totalPages) {
        $nextParams = $queryParams;
        $nextParams['page'] = $currentPage + 1;
        $links .= '<a href="?' . http_build_query($nextParams) . '" class="px-3 py-2 bg-gray-200 text-gray-700 rounded-r hover:bg-gray-300">&raquo;</a>';
    } else {
        $links .= '<span class="px-3 py-2 bg-gray-100 text-gray-400 rounded-r">&raquo;</span>';
    }
    
    $links .= '</div>';
    return $links;
}

// Get current query parameters for pagination links
$queryParams = $_GET;
unset($queryParams['page']); // Remove page from the array to avoid duplicate
?>

<div class="container mx-auto px-4 py-20">
    <!-- Stats Overview -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
        <div class="bg-white rounded-xl shadow-lg p-6">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-blue-100 text-blue-500">
                    <i class="fas fa-users text-2xl"></i>
                </div>
                <div class="ml-4">
                    <p class="text-gray-500">Total Members</p>
                    <h3 class="text-2xl font-bold"><?php echo $stats['total_members'] ?? 0; ?></h3>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-xl shadow-lg p-6">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-green-100 text-green-500">
                    <i class="fas fa-user-check text-2xl"></i>
                </div>
                <div class="ml-4">
                    <p class="text-gray-500">Active Members</p>
                    <h3 class="text-2xl font-bold"><?php echo $stats['active_members'] ?? 0; ?></h3>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-xl shadow-lg p-6">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-yellow-100 text-yellow-500">
                    <i class="fas fa-calendar-check text-2xl"></i>
                </div>
                <div class="ml-4">
                    <p class="text-gray-500">Today's Visits</p>
                    <h3 class="text-2xl font-bold"><?php echo $stats['today_visits'] ?? 0; ?></h3>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-xl shadow-lg p-6">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-purple-100 text-purple-500">
                    <i class="fas fa-dumbbell text-2xl"></i>
                </div>
                <div class="ml-4">
                    <p class="text-gray-500">Active Plans</p>
                    <h3 class="text-2xl font-bold"><?php echo $stats['total_plans'] ?? 0; ?></h3>
                </div>
            </div>
        </div>
    </div>

    <!-- Advanced Filters -->
    <div class="bg-white rounded-xl shadow-lg p-6 mb-8">
        <form class="grid grid-cols-1 md:grid-cols-6 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Membership Status</label>
                <select name="membership" class="w-full rounded-lg border-gray-300">
                    <option value="all">All Status</option>
                    <option value="active" <?php echo $membership === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="expired" <?php echo $membership === 'expired' ? 'selected' : ''; ?>>Expired</option>
                    <option value="pending" <?php echo $membership === 'pending' ? 'selected' : ''; ?>>Pending</option>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Plan Type</label>
                <select name="plan_id" class="w-full rounded-lg border-gray-300">
                    <option value="">All Plans</option>
                    <?php foreach ($membershipPlans as $plan): ?>
                        <option value="<?php echo $plan['plan_id']; ?>" <?php echo ($plan_id == $plan['plan_id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($plan['plan_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Sort By</label>
                <select name="sort_by" class="w-full rounded-lg border-gray-300">
                    <option value="username" <?php echo $sort_by === 'username' ? 'selected' : ''; ?>>Name</option>
                    <option value="visit_count" <?php echo $sort_by === 'visit_count' ? 'selected' : ''; ?>>Visit Count</option>
                    <option value="start_date" <?php echo $sort_by === 'start_date' ? 'selected' : ''; ?>>Join Date</option>
                    <option value="end_date" <?php echo $sort_by === 'end_date' ? 'selected' : ''; ?>>Expiry Date</option>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Sort Order</label>
                <select name="sort_order" class="w-full rounded-lg border-gray-300">
                    <option value="ASC" <?php echo $sort_order === 'ASC' ? 'selected' : ''; ?>>Ascending</option>
                    <option value="DESC" <?php echo $sort_order === 'DESC' ? 'selected' : ''; ?>>Descending</option>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Date From</label>
                <input type="date" name="date_from" value="<?php echo $date_from; ?>" class="w-full rounded-lg border-gray-300">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Date To</label>
                <input type="date" name="date_to" value="<?php echo $date_to; ?>" class="w-full rounded-lg border-gray-300">
            </div>

            <!-- Hidden gym_id field -->
            <input type="hidden" name="gym_id" value="<?php echo $gym_id; ?>">

            <div class="md:col-span-6 flex items-end justify-between">
                <div class="flex-grow mr-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Search</label>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by name, email or phone" class="w-full rounded-lg border-gray-300">
                </div>
                <div class="flex space-x-2">
                    <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-search mr-2"></i>Filter
                    </button>
                    <a href="member_list.php?gym_id=<?php echo $gym_id; ?>" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-redo mr-2"></i>Reset
                    </a>
                    <a href="export_members.php?gym_id=<?php echo $gym_id; ?>&format=csv" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-file-csv mr-2"></i>Export
                    </a>
                </div>
            </div>
        </form>
    </div>

    <!-- Gym Selector -->
    <?php if (count($gyms) > 1): ?>
    <div class="mb-6">
        <label class="block text-sm font-medium text-gray-700 mb-2">Select Gym:</label>
        <div class="flex flex-wrap gap-2">
            <?php foreach ($gyms as $g): ?>
                <a href="?gym_id=<?php echo $g['gym_id']; ?>" 
                   class="px-4 py-2 rounded-lg <?php echo $g['gym_id'] == $gym_id ? 'bg-yellow-500 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'; ?>">
                    <?php echo htmlspecialchars($g['name']); ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Members Table -->
    <div class="bg-white rounded-xl shadow-lg overflow-hidden">
        <div class="p-6 border-b border-gray-200">
            <h2 class="text-xl font-semibold text-gray-800">Members List</h2>
            <p class="text-gray-500">Showing <?php echo count($members); ?> of <?php echo $totalRecords; ?> members</p>
        </div>
        
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Member</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Membership</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Duration</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Visits</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($members)): ?>
                        <tr>
                            <td colspan="6" class="px-6 py-4 text-center text-gray-500">
                                No members found matching your criteria.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($members as $member): ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="flex-shrink-0 h-10 w-10 bg-gray-200 rounded-full flex items-center justify-center">
                                            <i class="fas fa-user text-gray-500"></i>
                                        </div>
                                        <div class="ml-4">
                                            <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($member['username']); ?></div>
                                            <div class="text-sm text-gray-500"><?php echo htmlspecialchars($member['email']); ?></div>
                                            <div class="text-sm text-gray-500"><?php echo htmlspecialchars($member['phone'] ?? 'N/A'); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900"><?php echo htmlspecialchars($member['plan_name'] ?? 'N/A'); ?></div>
                                    <div class="text-sm text-gray-500">₹<?php echo number_format($member['price'] ?? 0, 2); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php if (!empty($member['start_date']) && !empty($member['end_date'])): ?>
                                        <div class="text-sm text-gray-900">
                                            <?php echo date('d M Y', strtotime($member['start_date'])); ?> to
                                            <?php echo date('d M Y', strtotime($member['end_date'])); ?>
                                        </div>
                                        <div class="text-sm text-gray-500">
                                            <?php 
                                                $start = new DateTime($member['start_date']);
                                                $end = new DateTime($member['end_date']);
                                                $interval = $start->diff($end);
                                                echo $interval->days + 1; ?> days
                                        </div>
                                    <?php else: ?>
                                        <div class="text-sm text-gray-500">No active membership</div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php 
                                    $statusClass = 'bg-gray-100 text-gray-800';
                                    if ($member['membership_status'] === 'active') {
                                        $statusClass = 'bg-green-100 text-green-800';
                                    } elseif ($member['membership_status'] === 'expired') {
                                        $statusClass = 'bg-red-100 text-red-800';
                                    } elseif ($member['membership_status'] === 'cancelled') {
                                        $statusClass = 'bg-yellow-100 text-yellow-800';
                                    }
                                    ?>
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $statusClass; ?>">
                                        <?php echo ucfirst($member['membership_status'] ?? 'N/A'); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900"><?php echo $member['visit_count']; ?> total</div>
                                    <div class="text-sm text-gray-500"><?php echo $member['completed_workouts']; ?> completed</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <div class="flex space-x-2">
                                        <a href="view_member.php?id=<?php echo $member['id']; ?>&gym_id=<?php echo $gym_id; ?>" class="text-blue-600 hover:text-blue-900">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="member_schedules.php?id=<?php echo $member['id']; ?>&gym_id=<?php echo $gym_id; ?>" class="text-green-600 hover:text-green-900">
                                            <i class="fas fa-calendar-alt"></i>
                                        </a>
                                        <a href="member_payments.php?id=<?php echo $member['id']; ?>&gym_id=<?php echo $gym_id; ?>" class="text-purple-600 hover:text-purple-900">
                                            <i class="fas fa-credit-card"></i>
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
        <?php if ($totalPages > 1): ?>
            <div class="px-6 py-4 bg-gray-50 border-t border-gray-200">
                <div class="flex justify-between items-center">
                    <div class="text-sm text-gray-700">
                        Showing <span class="font-medium"><?php echo min(($page - 1) * $limit + 1, $totalRecords); ?></span> to 
                        <span class="font-medium"><?php echo min($page * $limit, $totalRecords); ?></span> of 
                        <span class="font-medium"><?php echo $totalRecords; ?></span> results
                    </div>
                    <?php echo generatePaginationLinks($page, $totalPages, $queryParams); ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    // Date range validation
    document.addEventListener('DOMContentLoaded', function() {
        const dateFrom = document.querySelector('input[name="date_from"]');
        const dateTo = document.querySelector('input[name="date_to"]');
        
        if (dateFrom && dateTo) {
            dateFrom.addEventListener('change', function() {
                if (dateTo.value && dateFrom.value > dateTo.value) {
                    dateTo.value = dateFrom.value;
                }
                dateTo.min = dateFrom.value;
            });
            
            if (dateFrom.value) {
                dateTo.min = dateFrom.value;
            }
        }
    });
</script>

