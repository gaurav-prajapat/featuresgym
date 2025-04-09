<?php
ob_start();
require_once '../config/database.php';
session_start();

// Check if user is logged in as admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: /profitmarts/FlexFit/views/auth/login.php');
    exit();
}

$db = new GymDatabase();
$conn = $db->getConnection();

$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

if (!$user_id) {
    $_SESSION['error'] = "Invalid user ID.";
    header('Location: all_bookings.php');
    exit();
}

// Get user details
try {
    $stmt = $conn->prepare("
        SELECT id, username, email, phone, profile_image, status
        FROM users
        WHERE id = ?
    ");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        $_SESSION['error'] = "User not found.";
        header('Location: all_bookings.php');
        exit();
    }
} catch (PDOException $e) {
    $_SESSION['error'] = "Database error: " . $e->getMessage();
    header('Location: all_bookings.php');
    exit();
}

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Filters
$status = isset($_GET['status']) ? $_GET['status'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'start_date';
$order = isset($_GET['order']) ? $_GET['order'] : 'DESC';

// Build query
$query = "
    SELECT s.*, g.name as gym_name, g.city as gym_city
    FROM schedules s
    JOIN gyms g ON s.gym_id = g.gym_id
    WHERE s.user_id = ?
";
$countQuery = "
    SELECT COUNT(*) 
    FROM schedules s
    WHERE s.user_id = ?
";
$params = [$user_id];
$countParams = [$user_id];

if (!empty($status)) {
    $query .= " AND s.status = ?";
    $countQuery .= " AND s.status = ?";
    $params[] = $status;
    $countParams[] = $status;
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
} catch (PDOException $e) {
    $_SESSION['error'] = "Database error: " . $e->getMessage();
    $bookings = [];
    $total_bookings = 0;
    $total_pages = 1;
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
    <title>User Bookings - FlexFit Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
</head>
<body class="bg-gray-900 text-white">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="ml-0 lg:ml-64 p-4 sm:p-6 transition-all duration-200">
        <div class="flex justify-between items-center mb-6">
            <div class="flex items-center">
                <a href="all_bookings.php" class="mr-4 text-gray-400 hover:text-white">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <h1 class="text-2xl font-bold">Bookings for <?= htmlspecialchars($user['username']) ?></h1>
            </div>
            <a href="add_booking.php?user_id=<?= $user_id ?>" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-plus mr-2"></i> Add Booking
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
        
        <!-- User Profile Card -->
        <div class="bg-gray-800 rounded-xl shadow-lg overflow-hidden mb-6">
            <div class="p-6 border-b border-gray-700">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <?php if (!empty($user['profile_image'])): ?>
                            <img src="<?= htmlspecialchars($user['profile_image']) ?>" alt="Profile" class="h-16 w-16 rounded-full object-cover">
                        <?php else: ?>
                            <div class="h-16 w-16 rounded-full bg-gray-600 flex items-center justify-center">
                                <i class="fas fa-user text-2xl text-gray-300"></i>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="ml-4">
                        <h2 class="text-xl font-semibold"><?= htmlspecialchars($user['username']) ?></h2>
                        <p class="text-gray-400">
                            <i class="fas fa-envelope mr-2"></i><?= htmlspecialchars($user['email']) ?>
                        </p>
                        <?php if (!empty($user['phone'])): ?>
                        <p class="text-gray-400">
                            <i class="fas fa-phone mr-2"></i><?= htmlspecialchars($user['phone']) ?>
                        </p>
                        <?php endif; ?>
                    </div>
                    <div class="ml-auto">
                        <?php
                            $statusClass = '';
                            $statusText = '';
                            
                            switch ($user['status']) {
                                case 'active':
                                    $statusClass = 'bg-green-900 text-green-300';
                                    $statusText = 'Active';
                                    break;
                                case 'inactive':
                                    $statusClass = 'bg-yellow-900 text-yellow-300';
                                    $statusText = 'Inactive';
                                    break;
                                case 'suspended':
                                    $statusClass = 'bg-red-900 text-red-300';
                                    $statusText = 'Suspended';
                                    break;
                                default:
                                    $statusClass = 'bg-gray-700 text-gray-300';
                                    $statusText = 'Unknown';
                            }
                        ?>
                        <span class="px-3 py-1 rounded-full text-sm font-semibold <?= $statusClass ?>">
                            <?= $statusText ?>
                        </span>
                    </div>
                </div>
            </div>
            <div class="p-6 bg-gray-700 bg-opacity-50">
                <div class="flex space-x-2">
                    <a href="view_user.php?id=<?= $user_id ?>" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center">
                        <i class="fas fa-user mr-2"></i> View Profile
                    </a>
                    <a href="edit_user.php?id=<?= $user_id ?>" class="bg-yellow-600 hover:bg-yellow-700 text-white px-4 py-2 rounded-lg flex items-center">
                        <i class="fas fa-edit mr-2"></i> Edit User
                    </a>
                    <a href="member_schedule.php?id=<?= $user_id ?>" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg flex items-center">
                        <i class="fas fa-calendar-alt mr-2"></i> View Schedule
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="bg-gray-800 rounded-xl shadow-lg p-6 mb-6">
            <form action="" method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
                <input type="hidden" name="user_id" value="<?= $user_id ?>">
                
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
                
                <div>
                    <label for="sort" class="block text-sm font-medium text-gray-400 mb-1">Sort By</label>
                    <select id="sort" name="sort" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                        <option value="start_date" <?= $sort === 'start_date' ? 'selected' : '' ?>>Date</option>
                        <option value="start_time" <?= $sort === 'start_time' ? 'selected' : '' ?>>Time</option>
                        <option value="created_at" <?= $sort === 'created_at' ? 'selected' : '' ?>>Created At</option>
                    </select>
                </div>
                
                <div class="flex items-end">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg w-full">
                        <i class="fas fa-filter mr-2"></i> Apply Filters
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Bookings Table -->
        <div class="bg-gray-800 rounded-xl shadow-lg overflow-hidden">
            <div class="p-6 border-b border-gray-700">
                <div class="flex justify-between items-center">
                    <h2 class="text-xl font-semibold">Booking History</h2>
                    <p class="text-gray-400">
                        Total: <?= number_format($total_bookings) ?> bookings
                    </p>
                </div>
            </div>
            
            <?php if (empty($bookings)): ?>
                <div class="p-6 text-center text-gray-500">
                    <i class="fas fa-calendar-times text-4xl mb-3"></i>
                    <p>No bookings found matching your criteria.</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-700">
                        <thead>
                            <tr class="bg-gray-700">
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">ID</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Gym</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Date & Time</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Created</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-gray-800 divide-y divide-gray-700">
                            <?php foreach ($bookings as $booking): ?>
                                <tr class="hover:bg-gray-700">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-white">
                                        <?= $booking['id'] ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-300">
                                        <?= htmlspecialchars($booking['gym_name']) ?>
                                        <div class="text-xs text-gray-500"><?= htmlspecialchars($booking['gym_city']) ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-300">
                                        <?= formatDate($booking['start_date']) ?><br>
                                        <span class="text-gray-500"><?= formatTime($booking['start_time']) ?></span>
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
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-300">
                                        <?= formatDate($booking['created_at']) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <div class="flex space-x-2">
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
                                                <a href="all_bookings.php?action=cancel&id=<?= $booking['id'] ?>" class="text-red-400 hover:text-red-300" title="Cancel" onclick="return confirm('Are you sure you want to cancel this booking?');">
                                                    <i class="fas fa-times"></i>
                                                </a>
                                            <?php endif; ?>
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
                            Showing <?= ($page - 1) * $per_page + 1 ?> to <?= min($page * $per_page, $total_bookings) ?> of <?= $total_bookings ?> bookings
                        </div>
                        <div class="flex space-x-1">
                            <?php if ($page > 1): ?>
                                <a href="?user_id=<?= $user_id ?>&page=1&status=<?= urlencode($status) ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&sort=<?= urlencode($sort) ?>&order=<?= urlencode($order) ?>" class="px-3 py-1 rounded-md bg-gray-600 text-white hover:bg-gray-500">
                                    <i class="fas fa-angle-double-left"></i>
                                </a>
                                <a href="?user_id=<?= $user_id ?>&page=<?= $page - 1 ?>&status=<?= urlencode($status) ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&sort=<?= urlencode($sort) ?>&order=<?= urlencode($order) ?>" class="px-3 py-1 rounded-md bg-gray-600 text-white hover:bg-gray-500">
                                    <i class="fas fa-angle-left"></i>
                                </a>
                            <?php endif; ?>
                            
                            <?php
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);
                            
                            for ($i = $start_page; $i <= $end_page; $i++):
                            ?>
                                <a href="?user_id=<?= $user_id ?>&page=<?= $i ?>&status=<?= urlencode($status) ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&sort=<?= urlencode($sort) ?>&order=<?= urlencode($order) ?>" class="px-3 py-1 rounded-md <?= $i === $page ? 'bg-blue-600 text-white' : 'bg-gray-600 text-white hover:bg-gray-500' ?>">
                                    <?= $i ?>
                                </a>
                            <?php endfor; ?>
                            
                            <?php if ($page < $total_pages): ?>
                                <a href="?user_id=<?= $user_id ?>&page=<?= $page + 1 ?>&status=<?= urlencode($status) ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&sort=<?= urlencode($sort) ?>&order=<?= urlencode($order) ?>" class="px-3 py-1 rounded-md bg-gray-600 text-white hover:bg-gray-500">
                                    <i class="fas fa-angle-right"></i>
                                </a>
                                <a href="?user_id=<?= $user_id ?>&page=<?= $total_pages ?>&status=<?= urlencode($status) ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&sort=<?= urlencode($sort) ?>&order=<?= urlencode($order) ?>" class="px-3 py-1 rounded-md bg-gray-600 text-white hover:bg-gray-500">
                                    <i class="fas fa-angle-double-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            <?php endif; ?>
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


