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

// Handle booking status change if requested
if (isset($_GET['action']) && isset($_GET['id'])) {
    $booking_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    $action = $_GET['action'];
    
    if ($booking_id) {
        try {
            $conn->beginTransaction();
            
            if ($action === 'complete') {
                $stmt = $conn->prepare("UPDATE schedules SET status = 'completed' WHERE id = ?");
                $stmt->execute([$booking_id]);
                
                // Log in schedule_logs
                $stmt = $conn->prepare("
                    INSERT INTO schedule_logs (user_id, schedule_id, action_type, notes)
                    VALUES (?, ?, 'complete', 'Admin marked booking as completed')
                ");
                $stmt->execute([$_SESSION['user_id'], $booking_id]);
                
                $_SESSION['success'] = "Booking marked as completed.";
            } elseif ($action === 'cancel') {
                $stmt = $conn->prepare("UPDATE schedules SET status = 'cancelled', cancellation_reason = 'Cancelled by admin' WHERE id = ?");
                $stmt->execute([$booking_id]);
                
                // Log in schedule_logs
                $stmt = $conn->prepare("
                    INSERT INTO schedule_logs (user_id, schedule_id, action_type, notes)
                    VALUES (?, ?, 'cancel', 'Admin cancelled booking')
                ");
                $stmt->execute([$_SESSION['user_id'], $booking_id]);
                
                $_SESSION['success'] = "Booking cancelled successfully.";
            }
            
            // Log the activity
            $stmt = $conn->prepare("
                INSERT INTO activity_logs (
                    user_id, user_type, action, details, ip_address, user_agent
                ) VALUES (?, 'admin', ?, ?, ?, ?)
            ");
            
            $details = "Changed booking ID: $booking_id status to " . strtoupper($action);
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            
            $stmt->execute([$_SESSION['user_id'], "update_booking_status", $details, $ip, $user_agent]);
            
            $conn->commit();
            
        } catch (PDOException $e) {
            $conn->rollBack();
            $_SESSION['error'] = "Database error: " . $e->getMessage();
        }
        
        header("Location: all_bookings.php");
        exit;
    }
}

// Get search and filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';
$gym_id = isset($_GET['gym_id']) ? (int)$_GET['gym_id'] : 0;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'start_date';
$order = isset($_GET['order']) ? $_GET['order'] : 'DESC';

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Build query
$query = "
    SELECT s.*, 
           u.username, u.email, u.phone,
           g.name as gym_name, g.city as gym_city,
           gmp.plan_name
    FROM schedules s
    JOIN users u ON s.user_id = u.id
    JOIN gyms g ON s.gym_id = g.gym_id
    LEFT JOIN user_memberships um ON s.membership_id = um.id
    LEFT JOIN gym_membership_plans gmp ON um.plan_id = gmp.plan_id
    WHERE 1=1
";
$countQuery = "SELECT COUNT(*) FROM schedules s WHERE 1=1";
$params = [];
$countParams = [];

if (!empty($search)) {
    $query .= " AND (u.username LIKE ? OR u.email LIKE ? OR g.name LIKE ?)";
    $countQuery .= " AND s.id IN (
        SELECT s.id FROM schedules s 
        JOIN users u ON s.user_id = u.id 
        JOIN gyms g ON s.gym_id = g.gym_id 
        WHERE u.username LIKE ? OR u.email LIKE ? OR g.name LIKE ?
    )";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $countParams[] = $searchParam;
    $countParams[] = $searchParam;
    $countParams[] = $searchParam;
}

if (!empty($status)) {
    $query .= " AND s.status = ?";
    $countQuery .= " AND s.status = ?";
    $params[] = $status;
    $countParams[] = $status;
}

if (!empty($gym_id)) {
    $query .= " AND s.gym_id = ?";
    $countQuery .= " AND s.gym_id = ?";
    $params[] = $gym_id;
    $countParams[] = $gym_id;
}

if (!empty($date_from)) {
    $query .= " AND s.start_date >= ?";
    $countQuery .= " AND s.start_date >= ?";
    $params[] = $date_from;
    $countParams[] = $date_from;
}

if (!empty($date_to)) {
    $query .= " AND s.start_date <= ?";
    $countQuery .= " AND s.start_date <= ?";
    $params[] = $date_to;
    $countParams[] = $date_to;
}

// Add sorting
$query .= " ORDER BY s.$sort $order";

// Add pagination
$query .= " LIMIT $per_page OFFSET $offset";

// Execute queries
try {
    // Get total count
    $stmt = $conn->prepare($countQuery);
    $stmt->execute($countParams);
    $total_bookings = $stmt->fetchColumn();
    
    // Get bookings for current page
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate total pages
    $total_pages = ceil($total_bookings / $per_page);
    
    // Get all gyms for filter dropdown
    $stmt = $conn->prepare("SELECT gym_id, name, city FROM gyms WHERE status = 'active' ORDER BY name");
    $stmt->execute();
    $gyms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $_SESSION['error'] = "Database error: " . $e->getMessage();
    $bookings = [];
    $total_bookings = 0;
    $total_pages = 1;
    $gyms = [];
}

// Function to format date
function formatDate($date) {
    return date('M d, Y', strtotime($date));
}

// Function to format time
function formatTime($time) {
    return date('h:i A', strtotime($time));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Bookings - FlexFit Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
</head>
<body class="bg-gray-900 text-white">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="ml-0 lg:ml-64 p-4 sm:p-6 transition-all duration-200">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold">All Bookings</h1>
            <a href="add_booking.php" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-plus mr-2"></i> Add New Booking
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
        
        <!-- Filters -->
        <div class="bg-gray-800 rounded-xl shadow-lg p-6 mb-6">
            <form action="" method="GET" class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-4">
                <div>
                    <label for="search" class="block text-sm font-medium text-gray-400 mb-1">Search</label>
                    <input type="text" id="search" name="search" value="<?= htmlspecialchars($search) ?>" 
                        class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500"
                        placeholder="Member, email, gym...">
                </div>
                
                <div>
                    <label for="status" class="block text-sm font-medium text-gray-400 mb-1">Status</label>
                    <select id="status" name="status" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                        <option value="">All Statuses</option>
                        <option value="scheduled" <?= $status === 'scheduled' ? 'selected' : '' ?>>Scheduled</option>
                        <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                        <option value="missed" <?= $status === 'missed' ? 'selected' : '' ?>>Missed</option>
                    </select>
                </div>
                
                <div>
                    <label for="gym_id" class="block text-sm font-medium text-gray-400 mb-1">Gym</label>
                    <select id="gym_id" name="gym_id" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                        <option value="">All Gyms</option>
                        <?php foreach ($gyms as $gym): ?>
                            <option value="<?= $gym['gym_id'] ?>" <?= $gym_id == $gym['gym_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($gym['name']) ?> (<?= htmlspecialchars($gym['city']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label for="date_from" class="block text-sm font-medium text-gray-400 mb-1">Date From</label>
                    <input type="text" id="date_from" name="date_from" value="<?= htmlspecialchars($date_from) ?>" 
                        class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500 datepicker"
                        placeholder="YYYY-MM-DD">
                </div>
                
                <div>
                    <label for="date_to" class="block text-sm font-medium text-gray-400 mb-1">Date To</label>
                    <input type="text" id="date_to" name="date_to" value="<?= htmlspecialchars($date_to) ?>" 
                        class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500 datepicker"
                        placeholder="YYYY-MM-DD">
                </div>
                
                <div class="flex items-end">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg w-full">
                        <i class="fas fa-filter mr-2"></i> Apply Filters
                    </button>
                </div>
                
                <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
                <input type="hidden" name="order" value="<?= htmlspecialchars($order) ?>">
            </form>
        </div>
        
               <!-- Bookings Table -->
               <div class="bg-gray-800 rounded-xl shadow-lg overflow-hidden mb-6">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-700">
                    <thead>
                        <tr class="bg-gray-700">
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                <a href="?search=<?= urlencode($search) ?>&status=<?= urlencode($status) ?>&gym_id=<?= $gym_id ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&sort=id&order=<?= $sort === 'id' && $order === 'ASC' ? 'DESC' : 'ASC' ?>" class="flex items-center">
                                    ID
                                    <?php if ($sort === 'id'): ?>
                                        <i class="fas fa-sort-<?= $order === 'ASC' ? 'up' : 'down' ?> ml-1"></i>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                Member
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                <a href="?search=<?= urlencode($search) ?>&status=<?= urlencode($status) ?>&gym_id=<?= $gym_id ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&sort=start_date&order=<?= $sort === 'start_date' && $order === 'ASC' ? 'DESC' : 'ASC' ?>" class="flex items-center">
                                    Date & Time
                                    <?php if ($sort === 'start_date'): ?>
                                        <i class="fas fa-sort-<?= $order === 'ASC' ? 'up' : 'down' ?> ml-1"></i>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                Gym
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                <a href="?search=<?= urlencode($search) ?>&status=<?= urlencode($status) ?>&gym_id=<?= $gym_id ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&sort=status&order=<?= $sort === 'status' && $order === 'ASC' ? 'DESC' : 'ASC' ?>" class="flex items-center">
                                    Status
                                    <?php if ($sort === 'status'): ?>
                                        <i class="fas fa-sort-<?= $order === 'ASC' ? 'up' : 'down' ?> ml-1"></i>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                <a href="?search=<?= urlencode($search) ?>&status=<?= urlencode($status) ?>&gym_id=<?= $gym_id ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&sort=created_at&order=<?= $sort === 'created_at' && $order === 'ASC' ? 'DESC' : 'ASC' ?>" class="flex items-center">
                                    Created
                                    <?php if ($sort === 'created_at'): ?>
                                        <i class="fas fa-sort-<?= $order === 'ASC' ? 'up' : 'down' ?> ml-1"></i>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-300 uppercase tracking-wider">
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-gray-800 divide-y divide-gray-700">
                        <?php foreach ($bookings as $booking): ?>
                        <tr class="hover:bg-gray-700">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-white">
                                #<?= $booking['id'] ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0 h-10 w-10">
                                        <div class="h-10 w-10 rounded-full bg-gray-600 flex items-center justify-center">
                                            <i class="fas fa-user text-gray-300"></i>
                                        </div>
                                    </div>
                                    <div class="ml-4">
                                        <div class="text-sm font-medium text-white"><?= htmlspecialchars($booking['username']) ?></div>
                                        <div class="text-sm text-gray-400"><?= htmlspecialchars($booking['email']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-white"><?= formatDate($booking['start_date']) ?></div>
                                <div class="text-sm text-gray-400"><?= formatTime($booking['start_time']) ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-white"><?= htmlspecialchars($booking['gym_name']) ?></div>
                                <div class="text-sm text-gray-400"><?= htmlspecialchars($booking['gym_city']) ?></div>
                                <?php if (!empty($booking['plan_name'])): ?>
                                <div class="text-xs text-blue-400 mt-1"><?= htmlspecialchars($booking['plan_name']) ?> Plan</div>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php
                                    $statusClass = '';
                                    $statusText = '';
                                    
                                    switch ($booking['status']) {
                                        case 'scheduled':
                                            $statusClass = 'bg-blue-900 text-blue-300';
                                            $statusText = 'Scheduled';
                                            break;
                                        case 'completed':
                                            $statusClass = 'bg-green-900 text-green-300';
                                            $statusText = 'Completed';
                                            break;
                                        case 'cancelled':
                                            $statusClass = 'bg-red-900 text-red-300';
                                            $statusText = 'Cancelled';
                                            break;
                                        case 'missed':
                                            $statusClass = 'bg-yellow-900 text-yellow-300';
                                            $statusText = 'Missed';
                                            break;
                                        default:
                                            $statusClass = 'bg-gray-700 text-gray-300';
                                            $statusText = 'Unknown';
                                    }
                                ?>
                                <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?= $statusClass ?>">
                                    <?= $statusText ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-400">
                                <?= formatDate($booking['created_at']) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <div class="flex justify-end space-x-2">
                                    <a href="view_booking.php?id=<?= $booking['id'] ?>" class="text-blue-400 hover:text-blue-300" title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="edit_booking.php?id=<?= $booking['id'] ?>" class="text-yellow-400 hover:text-yellow-300" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    
                                    <?php if ($booking['status'] === 'scheduled'): ?>
                                        <a href="all_bookings.php?action=complete&id=<?= $booking['id'] ?>" class="text-green-400 hover:text-green-300" title="Mark as Completed" onclick="return confirm('Are you sure you want to mark this booking as completed?');">
                                            <i class="fas fa-check"></i>
                                        </a>
                                        <a href="all_bookings.php?action=cancel&id=<?= $booking['id'] ?>" class="text-red-400 hover:text-red-300" title="Cancel Booking" onclick="return confirm('Are you sure you want to cancel this booking?');">
                                            <i class="fas fa-times"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <?php if (empty($bookings)): ?>
                        <tr>
                            <td colspan="7" class="px-6 py-4 text-center text-gray-400">
                                No bookings found matching your criteria.
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="bg-gray-800 px-4 py-3 flex items-center justify-between border-t border-gray-700 sm:px-6">
                <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                    <div>
                        <p class="text-sm text-gray-400">
                            Showing <span class="font-medium"><?= ($page - 1) * $per_page + 1 ?></span> to 
                            <span class="font-medium"><?= min($page * $per_page, $total_bookings) ?></span> of 
                            <span class="font-medium"><?= $total_bookings ?></span> bookings
                        </p>
                    </div>
                    <div>
                        <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                            <?php if ($page > 1): ?>
                            <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status) ?>&gym_id=<?= $gym_id ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&sort=<?= urlencode($sort) ?>&order=<?= urlencode($order) ?>" 
                               class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-600 bg-gray-700 text-sm font-medium text-gray-300 hover:bg-gray-600">
                                <span class="sr-only">Previous</span>
                                <i class="fas fa-chevron-left"></i>
                            </a>
                            <?php endif; ?>
                            
                            <?php
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);
                            
                            if ($start_page > 1): ?>
                                <a href="?page=1&search=<?= urlencode($search) ?>&status=<?= urlencode($status) ?>&gym_id=<?= $gym_id ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&sort=<?= urlencode($sort) ?>&order=<?= urlencode($order) ?>" 
                                   class="relative inline-flex items-center px-4 py-2 border border-gray-600 bg-gray-700 text-sm font-medium text-gray-300 hover:bg-gray-600">
                                    1
                                </a>
                                <?php if ($start_page > 2): ?>
                                    <span class="relative inline-flex items-center px-4 py-2 border border-gray-600 bg-gray-700 text-sm font-medium text-gray-300">
                                        ...
                                    </span>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status) ?>&gym_id=<?= $gym_id ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&sort=<?= urlencode($sort) ?>&order=<?= urlencode($order) ?>" 
                                   class="relative inline-flex items-center px-4 py-2 border border-gray-600 <?= $i === $page ? 'bg-gray-600 text-white' : 'bg-gray-700 text-gray-300 hover:bg-gray-600' ?> text-sm font-medium">
                                    <?= $i ?>
                                </a>
                            <?php endfor; ?>
                            
                            <?php if ($end_page < $total_pages): ?>
                                <?php if ($end_page < $total_pages - 1): ?>
                                    <span class="relative inline-flex items-center px-4 py-2 border border-gray-600 bg-gray-700 text-sm font-medium text-gray-300">
                                        ...
                                    </span>
                                <?php endif; ?>
                                <a href="?page=<?= $total_pages ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status) ?>&gym_id=<?= $gym_id ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&sort=<?= urlencode($sort) ?>&order=<?= urlencode($order) ?>" 
                                   class="relative inline-flex items-center px-4 py-2 border border-gray-600 bg-gray-700 text-sm font-medium text-gray-300 hover:bg-gray-600">
                                    <?= $total_pages ?>
                                </a>
                            <?php endif; ?>
                            
                            <?php if ($page < $total_pages): ?>
                            <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status) ?>&gym_id=<?= $gym_id ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&sort=<?= urlencode($sort) ?>&order=<?= urlencode($order) ?>" 
                               class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-600 bg-gray-700 text-sm font-medium text-gray-300 hover:bg-gray-600">
                                <span class="sr-only">Next</span>
                                <i class="fas fa-chevron-right"></i>
                            </a>
                            <?php endif; ?>
                        </nav>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Booking Statistics -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <?php
            // Get booking statistics
            try {
                // Total bookings
                $stmt = $conn->prepare("SELECT COUNT(*) FROM schedules");
                $stmt->execute();
                $total = $stmt->fetchColumn();
                
                // Scheduled bookings
                $stmt = $conn->prepare("SELECT COUNT(*) FROM schedules WHERE status = 'scheduled'");
                $stmt->execute();
                $scheduled = $stmt->fetchColumn();
                
                // Completed bookings
                $stmt = $conn->prepare("SELECT COUNT(*) FROM schedules WHERE status = 'completed'");
                $stmt->execute();
                $completed = $stmt->fetchColumn();
                
                // Cancelled bookings
                $stmt = $conn->prepare("SELECT COUNT(*) FROM schedules WHERE status = 'cancelled'");
                $stmt->execute();
                $cancelled = $stmt->fetchColumn();
                
                // Missed bookings
                $stmt = $conn->prepare("SELECT COUNT(*) FROM schedules WHERE status = 'missed'");
                $stmt->execute();
                $missed = $stmt->fetchColumn();
                
                // Today's bookings
                $stmt = $conn->prepare("SELECT COUNT(*) FROM schedules WHERE DATE(start_date) = CURDATE()");
                $stmt->execute();
                $today = $stmt->fetchColumn();
                
                // Tomorrow's bookings
                $stmt = $conn->prepare("SELECT COUNT(*) FROM schedules WHERE DATE(start_date) = DATE_ADD(CURDATE(), INTERVAL 1 DAY)");
                $stmt->execute();
                $tomorrow = $stmt->fetchColumn();
                
                // This week's bookings
                $stmt = $conn->prepare("SELECT COUNT(*) FROM schedules WHERE YEARWEEK(start_date, 1) = YEARWEEK(CURDATE(), 1)");
                $stmt->execute();
                $this_week = $stmt->fetchColumn();
            } catch (PDOException $e) {
                $total = $scheduled = $completed = $cancelled = $missed = $today = $tomorrow = $this_week = 0;
            }
            ?>
            
            <div class="bg-gray-800 rounded-xl shadow-lg p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-400 text-sm">Total Bookings</p>
                        <h3 class="text-2xl font-bold"><?= number_format($total) ?></h3>
                        <p class="text-sm text-gray-400">
                            <span class="text-green-400"><?= number_format($completed) ?></span> completed
                        </p>
                    </div>
                    <div class="bg-blue-900 bg-opacity-50 p-3 rounded-full">
                        <i class="fas fa-calendar-check text-blue-400 text-xl"></i>
                    </div>
                </div>
            </div>
            
            <div class="bg-gray-800 rounded-xl shadow-lg p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-400 text-sm">Upcoming Bookings</p>
                        <h3 class="text-2xl font-bold"><?= number_format($scheduled) ?></h3>
                        <p class="text-sm text-gray-400">
                            <span class="text-yellow-400"><?= number_format($today) ?></span> today, 
                            <span class="text-yellow-400"><?= number_format($tomorrow) ?></span> tomorrow
                        </p>
                    </div>
                    <div class="bg-green-900 bg-opacity-50 p-3 rounded-full">
                        <i class="fas fa-calendar-alt text-green-400 text-xl"></i>
                    </div>
                </div>
            </div>
            
            <div class="bg-gray-800 rounded-xl shadow-lg p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-400 text-sm">Cancelled Bookings</p>
                        <h3 class="text-2xl font-bold"><?= number_format($cancelled) ?></h3>
                        <p class="text-sm text-gray-400">
                            <span class="text-red-400"><?= number_format($missed) ?></span> missed appointments
                        </p>
                    </div>
                    <div class="bg-red-900 bg-opacity-50 p-3 rounded-full">
                        <i class="fas fa-calendar-times text-red-400 text-xl"></i>
                    </div>
                </div>
            </div>
            
            <div class="bg-gray-800 rounded-xl shadow-lg p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-400 text-sm">This Week</p>
                        <h3 class="text-2xl font-bold"><?= number_format($this_week) ?></h3>
                        <p class="text-sm text-gray-400">
                            Bookings this week
                        </p>
                    </div>
                    <div class="bg-purple-900 bg-opacity-50 p-3 rounded-full">
                        <i class="fas fa-calendar-week text-purple-400 text-xl"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Initialize date pickers
        flatpickr(".datepicker", {
            dateFormat: "Y-m-d",
            altInput: true,
            altFormat: "F j, Y",
            theme: "dark"
        });
    </script>
</body>
</html>


