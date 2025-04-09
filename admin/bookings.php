<?php
ob_start();
include '../includes/navbar.php';

if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

require_once '../config/database.php';
$db = new GymDatabase();
$conn = $db->getConnection();

// Initialize variables
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';
$gym_id = isset($_GET['gym_id']) ? intval($_GET['gym_id']) : 0;
$date_range = isset($_GET['date_range']) ? $_GET['date_range'] : '';
$start_date = '';
$end_date = '';

// Process date range
if ($date_range) {
    switch ($date_range) {
        case 'today':
            $start_date = date('Y-m-d');
            $end_date = date('Y-m-d');
            break;
        case 'yesterday':
            $start_date = date('Y-m-d', strtotime('-1 day'));
            $end_date = date('Y-m-d', strtotime('-1 day'));
            break;
        case 'this_week':
            $start_date = date('Y-m-d', strtotime('monday this week'));
            $end_date = date('Y-m-d', strtotime('sunday this week'));
            break;
        case 'last_week':
            $start_date = date('Y-m-d', strtotime('monday last week'));
            $end_date = date('Y-m-d', strtotime('sunday last week'));
            break;
        case 'this_month':
            $start_date = date('Y-m-01');
            $end_date = date('Y-m-t');
            break;
        case 'last_month':
            $start_date = date('Y-m-01', strtotime('first day of last month'));
            $end_date = date('Y-m-t', strtotime('last day of last month'));
            break;
        case 'custom':
            $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
            $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';
            break;
    }
}

// Build query
$query = "
    SELECT s.*, u.username, u.email, g.name as gym_name, 
           CASE 
               WHEN s.activity_type = 'gym_visit' THEN 'Gym Visit'
               WHEN s.activity_type = 'class' THEN 'Class'
               WHEN s.activity_type = 'personal_training' THEN 'Personal Training'
               ELSE s.activity_type
           END as activity_type_display
    FROM schedules s
    JOIN users u ON s.user_id = u.id
    JOIN gyms g ON s.gym_id = g.gym_id
    WHERE 1=1
";

$count_query = "
    SELECT COUNT(*) as total
    FROM schedules s
    JOIN users u ON s.user_id = u.id
    JOIN gyms g ON s.gym_id = g.gym_id
    WHERE 1=1
";

$params = [];

// Add search condition
if ($search) {
    $query .= " AND (u.username LIKE :search OR u.email LIKE :search OR g.name LIKE :search)";
    $count_query .= " AND (u.username LIKE :search OR u.email LIKE :search OR g.name LIKE :search)";
    $params[':search'] = "%$search%";
}

// Add status filter
if ($status) {
    $query .= " AND s.status = :status";
    $count_query .= " AND s.status = :status";
    $params[':status'] = $status;
}

// Add gym filter
if ($gym_id) {
    $query .= " AND s.gym_id = :gym_id";
    $count_query .= " AND s.gym_id = :gym_id";
    $params[':gym_id'] = $gym_id;
}

// Add date range filter
if ($start_date && $end_date) {
    $query .= " AND s.start_date BETWEEN :start_date AND :end_date";
    $count_query .= " AND s.start_date BETWEEN :start_date AND :end_date";
    $params[':start_date'] = $start_date;
    $params[':end_date'] = $end_date;
}

// Add sorting
$query .= " ORDER BY s.start_date DESC, s.start_time DESC";

// Add pagination
$query .= " LIMIT :offset, :per_page";
$params[':offset'] = (int)$offset;
$params[':per_page'] = (int)$per_page;

// Get total count
$stmt = $conn->prepare($count_query);
foreach ($params as $key => $value) {
    if ($key !== ':offset' && $key !== ':per_page') {
        if (is_int($value)) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($key, $value);
        }
    }
}
$stmt->execute();
$total_records = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_records / $per_page);

// Execute main query
$stmt = $conn->prepare($query);
foreach ($params as $key => $value) {
    if (is_int($value)) {
        $stmt->bindValue($key, $value, PDO::PARAM_INT);
    } else {
        $stmt->bindValue($key, $value);
    }
}
$stmt->execute();
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all gyms for filter
$stmt = $conn->query("SELECT gym_id, name FROM gyms ORDER BY name");
$gyms = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get booking statistics
$stmt = $conn->query("
    SELECT 
        COUNT(*) as total_bookings,
        SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) as scheduled_count,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count,
        SUM(CASE WHEN status = 'missed' THEN 1 ELSE 0 END) as missed_count,
        COUNT(DISTINCT user_id) as unique_users,
        COUNT(DISTINCT gym_id) as unique_gyms
    FROM schedules
");
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Handle booking status updates
if (isset($_POST['update_status'])) {
    $booking_id = filter_input(INPUT_POST, 'booking_id', FILTER_VALIDATE_INT);
    $new_status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);
    
    if ($booking_id && in_array($new_status, ['scheduled', 'completed', 'cancelled', 'missed'])) {
        try {
            $conn->beginTransaction();
            
            // Get current booking details
            $stmt = $conn->prepare("SELECT * FROM schedules WHERE id = ?");
            $stmt->execute([$booking_id]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($booking) {
                // Update booking status
                $stmt = $conn->prepare("UPDATE schedules SET status = ? WHERE id = ?");
                $stmt->execute([$new_status, $booking_id]);
                
                // Create notification for user
                $notification_message = "";
                switch ($new_status) {
                    case 'completed':
                        $notification_message = "Your booking has been marked as completed.";
                        break;
                    case 'cancelled':
                        $notification_message = "Your booking has been cancelled by the administrator.";
                        break;
                    case 'missed':
                        $notification_message = "Your booking has been marked as missed.";
                        break;
                    default:
                        $notification_message = "Your booking status has been updated to " . $new_status . ".";
                }
                
                $stmt = $conn->prepare("
                    INSERT INTO notifications (
                        user_id, type, message, related_id, title, created_at, status, gym_id, is_read
                    ) VALUES (
                        ?, 'booking', ?, ?, 'Booking Update', NOW(), 'unread', ?, 0
                    )
                ");
                $stmt->execute([
                    $booking['user_id'],
                    $notification_message,
                    $booking_id,
                    $booking['gym_id']
                ]);
                
                // Log the activity
                $stmt = $conn->prepare("
                    INSERT INTO activity_logs (
                        user_id, user_type, action, details, ip_address, user_agent
                    ) VALUES (?, 'admin', 'update_booking_status', ?, ?, ?)
                ");
                $stmt->execute([
                    $_SESSION['admin_id'],
                    "Updated booking ID: $booking_id status from {$booking['status']} to $new_status",
                    $_SERVER['REMOTE_ADDR'],
                    $_SERVER['HTTP_USER_AGENT']
                ]);
                
                $conn->commit();
                $_SESSION['success'] = "Booking status updated successfully!";
            } else {
                $_SESSION['error'] = "Booking not found.";
            }
        } catch (PDOException $e) {
            $conn->rollBack();
            $_SESSION['error'] = "Failed to update booking status: " . $e->getMessage();
        }
        
        // Redirect to refresh the page
        header("Location: bookings.php?" . http_build_query($_GET));
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Bookings - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        @media print {
            .no-print {
                display: none;
            }
            body {
                font-size: 12pt;
                color: #000;
                background-color: #fff;
            }
            .container {
                max-width: 100%;
                width: 100%;
            }
        }
        
        @media (max-width: 768px) {
            .responsive-table {
                display: block;
            }
            .responsive-table thead {
                display: none;
            }
            .responsive-table tbody {
                display: block;
            }
            .responsive-table tr {
                display: block;
                margin-bottom: 1rem;
                border: 1px solid #e5e7eb;
                border-radius: 0.5rem;
                padding: 1rem;
                box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
            }
            .responsive-table td {
                display: flex;
                justify-content: space-between;
                padding: 0.5rem 0;
                border-bottom: 1px solid #f3f4f6;
            }
            .responsive-table td:last-child {
                border-bottom: none;
            }
            .responsive-table td:before {
                content: attr(data-label);
                font-weight: 600;
                color: #4b5563;
            }
        }
    </style>
</head>
<body class="bg-gray-100 min-h-screen flex flex-col">
    <div class="container mx-auto px-4 py-8 flex-grow">
        <!-- Header Section -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 no-print">
            <div>
                <h1 class="text-3xl font-bold text-gray-800">Manage Bookings</h1>
                <p class="text-gray-600">View and manage all gym bookings</p>
            </div>
            <div class="mt-4 md:mt-0 flex space-x-3">
                <button onclick="window.print()" class="bg-purple-600 hover:bg-purple-700 text-white font-medium py-2 px-4 rounded-lg transition duration-300 flex items-center">
                    <i class="fas fa-print mr-2"></i> Print
                </button>
                <a href="export_bookings.php<?= isset($_SERVER['QUERY_STRING']) && !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '' ?>" class="bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-4 rounded-lg transition duration-300 flex items-center">
                    <i class="fas fa-file-export mr-2"></i> Export CSV
                </a>
            </div>
        </div>

        <!-- Alerts Section -->
        <?php if (isset($_SESSION['success'])): ?>
        <div class="bg-green-50 border-l-4 border-green-400 p-4 mb-6 rounded-lg no-print">
            <div class="flex items-start">
                <div class="flex-shrink-0">
                    <i class="fas fa-check-circle text-green-400 text-xl"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-green-800"><?= htmlspecialchars($_SESSION['success']) ?></p>
                </div>
            </div>
        </div>
        <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
        <div class="bg-red-50 border-l-4 border-red-400 p-4 mb-6 rounded-lg no-print">
            <div class="flex items-start">
                <div class="flex-shrink-0">
                    <i class="fas fa-exclamation-circle text-red-400 text-xl"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-red-800"><?= htmlspecialchars($_SESSION['error']) ?></p>
                </div>
            </div>
        </div>
        <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

               <!-- Statistics Cards -->
               <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8 no-print">
            <div class="bg-white rounded-xl shadow-md overflow-hidden">
                <div class="p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-blue-100 text-blue-600">
                            <i class="fas fa-calendar-check text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-500">Total Bookings</p>
                            <p class="text-2xl font-bold text-gray-900"><?= number_format($stats['total_bookings']) ?></p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-xl shadow-md overflow-hidden">
                <div class="p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-green-100 text-green-600">
                            <i class="fas fa-check-circle text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-500">Completed</p>
                            <p class="text-2xl font-bold text-gray-900"><?= number_format($stats['completed_count']) ?></p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-xl shadow-md overflow-hidden">
                <div class="p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-yellow-100 text-yellow-600">
                            <i class="fas fa-clock text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-500">Scheduled</p>
                            <p class="text-2xl font-bold text-gray-900"><?= number_format($stats['scheduled_count']) ?></p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-xl shadow-md overflow-hidden">
                <div class="p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-red-100 text-red-600">
                            <i class="fas fa-times-circle text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-500">Cancelled/Missed</p>
                            <p class="text-2xl font-bold text-gray-900"><?= number_format($stats['cancelled_count'] + $stats['missed_count']) ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters Section -->
        <div class="bg-white rounded-xl shadow-md overflow-hidden mb-8 no-print">
            <div class="px-6 py-5 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-800">Filter Bookings</h3>
            </div>
            <div class="p-6">
                <form action="" method="GET" class="space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                        <div>
                            <label for="search" class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                            <input type="text" id="search" name="search" value="<?= htmlspecialchars($search) ?>" 
                                class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50"
                                placeholder="User, Email, Gym...">
                        </div>
                        
                        <div>
                            <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                            <select id="status" name="status" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                                <option value="">All Statuses</option>
                                <option value="scheduled" <?= $status === 'scheduled' ? 'selected' : '' ?>>Scheduled</option>
                                <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>Completed</option>
                                <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                <option value="missed" <?= $status === 'missed' ? 'selected' : '' ?>>Missed</option>
                            </select>
                        </div>
                        
                        <div>
                            <label for="gym_id" class="block text-sm font-medium text-gray-700 mb-1">Gym</label>
                            <select id="gym_id" name="gym_id" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                                <option value="">All Gyms</option>
                                <?php foreach ($gyms as $gym): ?>
                                <option value="<?= $gym['gym_id'] ?>" <?= $gym_id == $gym['gym_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($gym['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label for="date_range" class="block text-sm font-medium text-gray-700 mb-1">Date Range</label>
                            <select id="date_range" name="date_range" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                                <option value="">All Dates</option>
                                <option value="today" <?= $date_range === 'today' ? 'selected' : '' ?>>Today</option>
                                <option value="yesterday" <?= $date_range === 'yesterday' ? 'selected' : '' ?>>Yesterday</option>
                                <option value="this_week" <?= $date_range === 'this_week' ? 'selected' : '' ?>>This Week</option>
                                <option value="last_week" <?= $date_range === 'last_week' ? 'selected' : '' ?>>Last Week</option>
                                <option value="this_month" <?= $date_range === 'this_month' ? 'selected' : '' ?>>This Month</option>
                                <option value="last_month" <?= $date_range === 'last_month' ? 'selected' : '' ?>>Last Month</option>
                                <option value="custom" <?= $date_range === 'custom' ? 'selected' : '' ?>>Custom Range</option>
                            </select>
                        </div>
                    </div>
                    
                    <div id="custom_date_range" class="grid grid-cols-1 md:grid-cols-2 gap-6 <?= $date_range === 'custom' ? '' : 'hidden' ?>">
                        <div>
                            <label for="start_date" class="block text-sm font-medium text-gray-700 mb-1">Start Date</label>
                            <input type="date" id="start_date" name="start_date" value="<?= htmlspecialchars($start_date) ?>" 
                                class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                        </div>
                        
                        <div>
                            <label for="end_date" class="block text-sm font-medium text-gray-700 mb-1">End Date</label>
                            <input type="date" id="end_date" name="end_date" value="<?= htmlspecialchars($end_date) ?>" 
                                class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                        </div>
                    </div>
                    
                    <div class="flex justify-between">
                        <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-lg transition duration-300">
                            <i class="fas fa-filter mr-2"></i> Apply Filters
                        </button>
                        
                        <a href="bookings.php" class="bg-gray-500 hover:bg-gray-600 text-white font-medium py-2 px-4 rounded-lg transition duration-300">
                            <i class="fas fa-times mr-2"></i> Clear Filters
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Bookings Table -->
        <div class="bg-white rounded-xl shadow-md overflow-hidden mb-8">
            <div class="px-6 py-5 border-b border-gray-200 flex justify-between items-center">
                <h3 class="text-lg font-semibold text-gray-800">Booking List</h3>
                <p class="text-sm text-gray-600">Showing <?= min($total_records, 1 + $offset) ?>-<?= min($total_records, $offset + $per_page) ?> of <?= $total_records ?> bookings</p>
            </div>
            <div class="overflow-x-auto">
                <?php if (count($bookings) > 0): ?>
                <table class="min-w-full divide-y divide-gray-200 responsive-table">
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                ID
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                User
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Gym
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Date & Time
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Activity
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Status
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider no-print">
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($bookings as $booking): ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap" data-label="ID">
                                <div class="text-sm font-medium text-gray-900">#<?= $booking['id'] ?></div>
                            </td>
                            <td class="px-6 py-4" data-label="User">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0 h-10 w-10 rounded-full bg-gray-200 flex items-center justify-center">
                                        <i class="fas fa-user text-gray-600"></i>
                                    </div>
                                    <div class="ml-4">
                                        <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($booking['username']) ?></div>
                                        <div class="text-sm text-gray-500"><?= htmlspecialchars($booking['email']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4" data-label="Gym">
                                <div class="text-sm text-gray-900"><?= htmlspecialchars($booking['gym_name']) ?></div>
                            </td>
                            <td class="px-6 py-4" data-label="Date & Time">
                                <div class="text-sm text-gray-900"><?= date('M d, Y', strtotime($booking['start_date'])) ?></div>
                                <div class="text-sm text-gray-500"><?= date('h:i A', strtotime($booking['start_time'])) ?></div>
                            </td>
                            <td class="px-6 py-4" data-label="Activity">
                                <div class="text-sm text-gray-900"><?= htmlspecialchars($booking['activity_type_display']) ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap" data-label="Status">
                                <?php
                                $statusClass = '';
                                $statusIcon = '';
                                
                                switch ($booking['status']) {
                                    case 'scheduled':
                                        $statusClass = 'bg-yellow-100 text-yellow-800';
                                        $statusIcon = 'fa-clock';
                                        break;
                                    case 'completed':
                                        $statusClass = 'bg-green-100 text-green-800';
                                        $statusIcon = 'fa-check-circle';
                                        break;
                                    case 'cancelled':
                                        $statusClass = 'bg-red-100 text-red-800';
                                        $statusIcon = 'fa-times-circle';
                                        break;
                                    case 'missed':
                                        $statusClass = 'bg-gray-100 text-gray-800';
                                        $statusIcon = 'fa-calendar-times';
                                        break;
                                }
                                ?>
                                <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?= $statusClass ?>">
                                <i class="fas <?= $statusIcon ?> mr-1"></i>
                                    <?= ucfirst($booking['status']) ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium no-print" data-label="Actions">
                                <div class="flex space-x-2">
                                    <a href="view_booking.php?id=<?= $booking['id'] ?>" class="text-indigo-600 hover:text-indigo-900">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    <button type="button" onclick="showStatusModal(<?= $booking['id'] ?>, '<?= $booking['status'] ?>')" class="text-blue-600 hover:text-blue-900">
                                        <i class="fas fa-edit"></i> Status
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="p-6 text-center">
                    <div class="py-6">
                        <div class="mb-4">
                            <i class="fas fa-calendar-times text-gray-400 text-5xl"></i>
                        </div>
                        <h3 class="text-lg font-medium text-gray-900 mb-1">No bookings found</h3>
                        <p class="text-gray-500">Try adjusting your search or filter criteria</p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="px-6 py-4 bg-gray-50 border-t border-gray-200 no-print">
                <div class="flex justify-between items-center">
                    <div class="text-sm text-gray-500">
                        Showing <?= min($total_records, 1 + $offset) ?>-<?= min($total_records, $offset + $per_page) ?> of <?= $total_records ?> bookings
                    </div>
                    <div class="flex space-x-1">
                        <?php
                        // Build the query string for pagination links
                        $query_params = $_GET;
                        
                        // Previous page link
                        if ($page > 1) {
                            $query_params['page'] = $page - 1;
                            $prev_link = '?' . http_build_query($query_params);
                            echo '<a href="' . $prev_link . '" class="px-3 py-1 rounded-md bg-white text-gray-600 border border-gray-300 hover:bg-gray-50">Previous</a>';
                        } else {
                            echo '<span class="px-3 py-1 rounded-md bg-gray-100 text-gray-400 border border-gray-300 cursor-not-allowed">Previous</span>';
                        }
                        
                        // Page number links
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        
                        for ($i = $start_page; $i <= $end_page; $i++) {
                            $query_params['page'] = $i;
                            $page_link = '?' . http_build_query($query_params);
                            
                            if ($i == $page) {
                                echo '<span class="px-3 py-1 rounded-md bg-indigo-600 text-white border border-indigo-600">' . $i . '</span>';
                            } else {
                                echo '<a href="' . $page_link . '" class="px-3 py-1 rounded-md bg-white text-gray-600 border border-gray-300 hover:bg-gray-50">' . $i . '</a>';
                            }
                        }
                        
                        // Next page link
                        if ($page < $total_pages) {
                            $query_params['page'] = $page + 1;
                            $next_link = '?' . http_build_query($query_params);
                            echo '<a href="' . $next_link . '" class="px-3 py-1 rounded-md bg-white text-gray-600 border border-gray-300 hover:bg-gray-50">Next</a>';
                        } else {
                            echo '<span class="px-3 py-1 rounded-md bg-gray-100 text-gray-400 border border-gray-300 cursor-not-allowed">Next</span>';
                        }
                        ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Status Update Modal -->
    <div id="statusModal" class="fixed inset-0 bg-gray-900 bg-opacity-50 flex items-center justify-center z-50 hidden no-print">
        <div class="bg-white rounded-xl shadow-xl max-w-md w-full mx-4">
            <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                <h3 class="text-lg font-semibold text-gray-800">Update Booking Status</h3>
                <button type="button" class="text-gray-400 hover:text-gray-500" onclick="hideStatusModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" action="" id="statusForm">
                <input type="hidden" name="update_status" value="1">
                <input type="hidden" name="booking_id" id="booking_id" value="">
                
                <div class="p-6 space-y-4">
                    <div>
                        <label for="modal_status" class="block text-sm font-medium text-gray-700 mb-1">New Status</label>
                        <select id="modal_status" name="status" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                            <option value="scheduled">Scheduled</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                            <option value="missed">Missed</option>
                        </select>
                    </div>
                    
                    <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <i class="fas fa-exclamation-triangle text-yellow-400"></i>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm text-yellow-700">
                                    Changing the status will notify the user and may affect revenue calculations.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="px-6 py-4 border-t border-gray-200 flex justify-end">
                    <button type="button" class="bg-gray-500 hover:bg-gray-600 text-white font-medium py-2 px-4 rounded-lg mr-3 transition duration-300" onclick="hideStatusModal()">
                        Cancel
                    </button>
                    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-lg transition duration-300">
                        Update Status
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Footer -->
    <footer class="bg-white py-6 mt-auto no-print">
        <div class="container mx-auto px-4">
            <div class="flex flex-col md:flex-row justify-between items-center">
                <div class="mb-4 md:mb-0">
                    <p class="text-sm text-gray-600">&copy; <?= date('Y') ?> Fitness Hub. All rights reserved.</p>
                </div>
                <div class="flex space-x-4">
                    <a href="privacy_policy.php" class="text-sm text-gray-600 hover:text-indigo-600">Privacy Policy</a>
                    <a href="terms_of_service.php" class="text-sm text-gray-600 hover:text-indigo-600">Terms of Service</a>
                    <a href="contact.php" class="text-sm text-gray-600 hover:text-indigo-600">Contact Us</a>
                </div>
            </div>
        </div>
    </footer>

    <script>
        // Toggle custom date range fields
        document.getElementById('date_range').addEventListener('change', function() {
            const customDateRange = document.getElementById('custom_date_range');
            if (this.value === 'custom') {
                customDateRange.classList.remove('hidden');
            } else {
                customDateRange.classList.add('hidden');
            }
        });
        
        // Show status update modal
        function showStatusModal(bookingId, currentStatus) {
            document.getElementById('booking_id').value = bookingId;
            document.getElementById('modal_status').value = currentStatus;
            document.getElementById('statusModal').classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
        }
        
        // Hide status update modal
        function hideStatusModal() {
            document.getElementById('statusModal').classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        }
        
        // Close modals when clicking outside
        window.addEventListener('click', function(event) {
            const statusModal = document.getElementById('statusModal');
            if (event.target === statusModal) {
                hideStatusModal();
            }
        });
        
        // Close modals with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                hideStatusModal();
            }
        });
    </script>
</body>
</html>


