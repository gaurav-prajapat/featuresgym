<?php
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ./login.php');
    exit();
}

require_once '../config/database.php';
$db = new GymDatabase();
$conn = $db->getConnection();

// Initialize variables
$success_message = '';
$error_message = '';

// Pagination setup
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Filter parameters
$user_type = isset($_GET['user_type']) ? $_GET['user_type'] : '';
$action = isset($_GET['action']) ? $_GET['action'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$search = isset($_GET['search']) ? $_GET['search'] : '';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'date_desc';

// Base query
$sql = "SELECT al.*, 
        CASE 
            WHEN al.user_type = 'member' THEN u.username
            WHEN al.user_type = 'owner' THEN go.name
            WHEN al.user_type = 'admin' THEN 'Admin'
        END as user_name
        FROM activity_logs al
        LEFT JOIN users u ON al.user_id = u.id AND al.user_type = 'member'
        LEFT JOIN gym_owners go ON al.user_id = go.id AND al.user_type = 'owner'
        WHERE 1=1";
$params = [];

// Apply filters
if ($user_type) {
    $sql .= " AND al.user_type = ?";
    $params[] = $user_type;
}

if ($action) {
    $sql .= " AND al.action = ?";
    $params[] = $action;
}

if ($date_from) {
    $sql .= " AND al.created_at >= ?";
    $params[] = $date_from . ' 00:00:00';
}

if ($date_to) {
    $sql .= " AND al.created_at <= ?";
    $params[] = $date_to . ' 23:59:59';
}

if ($search) {
    $sql .= " AND (al.details LIKE ? OR al.ip_address LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
}

// Apply sorting
switch ($sort) {
    case 'date_asc':
        $sql .= " ORDER BY al.created_at ASC";
        break;
    case 'user_type':
        $sql .= " ORDER BY al.user_type ASC, al.created_at DESC";
        break;
    case 'action':
        $sql .= " ORDER BY al.action ASC, al.created_at DESC";
        break;
    case 'date_desc':
    default:
        $sql .= " ORDER BY al.created_at DESC";
        break;
}

// Count total records for pagination
$countSql = str_replace("SELECT al.*, CASE WHEN al.user_type = 'member' THEN u.username WHEN al.user_type = 'owner' THEN go.name WHEN al.user_type = 'admin' THEN 'Admin' END as user_name", "SELECT COUNT(*)", $sql);
$countStmt = $conn->prepare($countSql);
$countStmt->execute($params);
$total_records = $countStmt->fetchColumn();
$total_pages = ceil($total_records / $limit);

$sql .= " LIMIT $limit OFFSET $offset";
// Don't add these to $params
$stmt = $conn->prepare($sql);
$stmt->execute($params);

$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get distinct actions for filter dropdown
$actionStmt = $conn->query("SELECT DISTINCT action FROM activity_logs ORDER BY action");
$actions = $actionStmt->fetchAll(PDO::FETCH_COLUMN);

// Get statistics
$statsStmt = $conn->query("
    SELECT 
        COUNT(*) as total_count,
        SUM(CASE WHEN user_type = 'member' THEN 1 ELSE 0 END) as member_count,
        SUM(CASE WHEN user_type = 'owner' THEN 1 ELSE 0 END) as owner_count,
        SUM(CASE WHEN user_type = 'admin' THEN 1 ELSE 0 END) as admin_count,
        COUNT(DISTINCT ip_address) as unique_ips,
        DATE_FORMAT(MAX(created_at), '%Y-%m-%d %H:%i:%s') as latest_activity
    FROM activity_logs
");
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Handle log deletion if requested
if (isset($_POST['delete_logs']) && isset($_POST['confirm_delete'])) {
    try {
        // Delete logs based on filters
        $deleteSql = "DELETE FROM activity_logs WHERE 1=1";
        $deleteParams = [];
        
        if ($user_type) {
            $deleteSql .= " AND user_type = ?";
            $deleteParams[] = $user_type;
        }
        
        if ($action) {
            $deleteSql .= " AND action = ?";
            $deleteParams[] = $action;
        }
        
        if ($date_from) {
            $deleteSql .= " AND created_at >= ?";
            $deleteParams[] = $date_from . ' 00:00:00';
        }
        
        if ($date_to) {
            $deleteSql .= " AND created_at <= ?";
            $deleteParams[] = $date_to . ' 23:59:59';
        }
        
        $deleteStmt = $conn->prepare($deleteSql);
        $deleteStmt->execute($deleteParams);
        
        $affected = $deleteStmt->rowCount();
        $success_message = "Successfully deleted $affected activity logs.";
        
        // Log this action
        $logStmt = $conn->prepare("
            INSERT INTO activity_logs (
                user_id, user_type, action, details, ip_address, user_agent
            ) VALUES (?, 'admin', 'delete_logs', ?, ?, ?)
        ");
        $logStmt->execute([
            $_SESSION['admin_id'],
            "Deleted $affected logs with filters: " . json_encode([
                'user_type' => $user_type,
                'action' => $action,
                'date_from' => $date_from,
                'date_to' => $date_to
            ]),
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT']
        ]);
        
        // Redirect to refresh the page
        header("Location: activity_logs.php?success=1");
        exit();
    } catch (PDOException $e) {
        $error_message = "Error deleting logs: " . $e->getMessage();
    }
}

// Set page title
$page_title = "Activity Logs";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Logs - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .activity-card {
            transition: all 0.3s ease;
        }
        .activity-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }
    </style>
</head>
<body class="bg-gray-900 text-white">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="ml-0 lg:ml-64 p-4 sm:p-6 transition-all duration-200">
        <!-- Header Section -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8">
            <div>
                <h1 class="text-3xl font-bold ">Activity Logs</h1>
                <p class="text-gray-600">Monitor all system activities and user actions</p>
            </div>
            <div class="mt-4 md:mt-0">
                <a href="index.php" class="bg-gray-600 hover:bg-gray-700 text-white font-medium py-2 px-4 rounded-lg transition duration-300 flex items-center">
                    <i class="fas fa-arrow-left mr-2"></i> Back to Dashboard
                </a>
            </div>
        </div>

        <!-- Alerts Section -->
        <?php if ($success_message): ?>
        <div class="bg-green-50 border-l-4 border-green-400 p-4 mb-6 rounded-lg">
            <div class="flex items-start">
                <div class="flex-shrink-0">
                    <i class="fas fa-check-circle text-green-400 text-xl"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-green-800"><?= htmlspecialchars($success_message) ?></p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
        <div class="bg-red-50 border-l-4 border-red-400 p-4 mb-6 rounded-lg">
            <div class="flex items-start">
                <div class="flex-shrink-0">
                    <i class="fas fa-exclamation-circle text-red-400 text-xl"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-red-800"><?= htmlspecialchars($error_message) ?></p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Activity Statistics -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="activity-card bg-white rounded-xl shadow-md overflow-hidden">
                <div class="p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-blue-100 text-blue-500 mr-4">
                            <i class="fas fa-history text-xl"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Total Activities</p>
                            <p class="text-2xl font-bold text-gray-800"><?= number_format($stats['total_count']) ?></p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="activity-card bg-white rounded-xl shadow-md overflow-hidden">
                <div class="p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-green-100 text-green-500 mr-4">
                            <i class="fas fa-users text-xl"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">User Activities</p>
                            <p class="text-2xl font-bold text-gray-800"><?= number_format($stats['member_count']) ?></p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="activity-card bg-white rounded-xl shadow-md overflow-hidden">
                <div class="p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-yellow-100 text-yellow-500 mr-4">
                            <i class="fas fa-user-tie text-xl"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Owner Activities</p>
                            <p class="text-2xl font-bold text-gray-800"><?= number_format($stats['owner_count']) ?></p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="activity-card bg-white rounded-xl shadow-md overflow-hidden">
                <div class="p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-purple-100 text-purple-500 mr-4">
                            <i class="fas fa-user-shield text-xl"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Admin Activities</p>
                            <p class="text-2xl font-bold text-gray-800"><?= number_format($stats['admin_count']) ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Search and Filters -->
        <div class="bg-white rounded-xl shadow-md overflow-hidden mb-8">
            <div class="px-6 py-5 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-800">Search and Filter Logs</h3>
            </div>
            <div class="p-6">
                <form method="GET" action="" class="space-y-4 md:space-y-0 md:flex md:flex-wrap md:items-end md:gap-4">
                    <div class="flex-grow min-w-[200px]">
                        <label for="search" class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                        <input type="text" id="search" name="search" value="<?= htmlspecialchars($search ?? '') ?>" 
                            placeholder="Search in details or IP address"
                            class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                    </div>
                    
                    <div class="w-full md:w-auto">
                        <label for="user_type" class="block text-sm font-medium text-gray-700 mb-1">User Type</label>
                        <select id="user_type" name="user_type" class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                            <option value="">All Types</option>
                            <option value="member" <?= ($user_type === 'member') ? 'selected' : '' ?>>Members</option>
                            <option value="owner" <?= ($user_type === 'owner') ? 'selected' : '' ?>>Gym Owners</option>
                            <option value="admin" <?= ($user_type === 'admin') ? 'selected' : '' ?>>Administrators</option>
                        </select>
                    </div>
                    
                    <div class="w-full md:w-auto">
                        <label for="action" class="block text-sm font-medium text-gray-700 mb-1">Action</label>
                        <select id="action" name="action" class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                            <option value="">All Actions</option>
                            <?php foreach ($actions as $actionOption): ?>
                                <option value="<?= htmlspecialchars($actionOption) ?>" <?= ($action === $actionOption) ? 'selected' : '' ?>>
                                    <?= ucwords(str_replace('_', ' ', htmlspecialchars($actionOption))) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="w-full md:w-auto">
                        <label for="date_from" class="block text-sm font-medium text-gray-700 mb-1">From Date</label>
                        <input type="date" id="date_from" name="date_from" value="<?= htmlspecialchars($date_from ?? '') ?>" 
                            class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                    </div>
                    
                    <div class="w-full md:w-auto">
                        <label for="date_to" class="block text-sm font-medium text-gray-700 mb-1">To Date</label>
                        <input type="date" id="date_to" name="date_to" value="<?= htmlspecialchars($date_to ?? '') ?>" 
                            class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                    </div>
                    
                    <div class="w-full md:w-auto">
                        <label for="sort" class="block text-sm font-medium text-gray-700 mb-1">Sort By</label>
                        <select id="sort" name="sort" class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                            <option value="date_desc" <?= ($sort === 'date_desc') ? 'selected' : '' ?>>Date (Newest First)</option>
                            <option value="date_asc" <?= ($sort === 'date_asc') ? 'selected' : '' ?>>Date (Oldest First)</option>
                            <option value="user_type" <?= ($sort === 'user_type') ? 'selected' : '' ?>>User Type</option>
                            <option value="action" <?= ($sort === 'action') ? 'selected' : '' ?>>Action</option>
                        </select>
                    </div>
                    
                    <div class="flex space-x-2">
                        <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-lg transition duration-300">
                            <i class="fas fa-search mr-2"></i> Search
                        </button>
                        <a href="activity_logs.php" class="bg-gray-500 hover:bg-gray-600 text-white font-medium py-2 px-4 rounded-lg transition duration-300">
                            <i class="fas fa-redo mr-2"></i> Reset
                        </a>
                        <a href="export_logs.php<?= !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '' ?>" class="bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-4 rounded-lg transition duration-300">
                            <i class="fas fa-file-export mr-2"></i> Export
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Activity Logs Table -->
        <div class="bg-white rounded-xl shadow-md overflow-hidden mb-8">
            <div class="px-6 py-5 border-b border-gray-200 flex justify-between items-center">
                <h3 class="text-lg font-semibold text-gray-800">Activity Logs</h3>
                <button type="button" onclick="showDeleteModal()" class="bg-red-600 hover:bg-red-700 text-white text-sm font-medium py-2 px-4 rounded-lg transition duration-300">
                    <i class="fas fa-trash-alt mr-2"></i> Delete Logs
                </button>
            </div>
            <div class="overflow-x-auto">
                <?php if (empty($logs)): ?>
                    <div class="p-6 text-center text-gray-500">
                        <i class="fas fa-history text-4xl mb-4 block"></i>
                        <p>No activity logs found matching your criteria.</p>
                    </div>
                <?php else: ?>
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead>
                            <tr class="bg-gray-50">
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date & Time</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Action</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Details</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">IP Address</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User Agent</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($logs as $log): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= date('M d, Y H:i:s', strtotime($log['created_at'])) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <?php 
                                            $userTypeIcon = '';
                                            $userTypeClass = '';
                                            
                                            switch ($log['user_type']) {
                                                case 'member':
                                                    $userTypeIcon = 'fa-user';
                                                    $userTypeClass = 'bg-blue-100 text-blue-800';
                                                    break;
                                                case 'owner':
                                                    $userTypeIcon = 'fa-user-tie';
                                                    $userTypeClass = 'bg-yellow-100 text-yellow-800';
                                                    break;
                                                case 'admin':
                                                    $userTypeIcon = 'fa-user-shield';
                                                    $userTypeClass = 'bg-purple-100 text-purple-800';
                                                    break;
                                            }
                                            ?>
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?= $userTypeClass ?> mr-2">
                                                <i class="fas <?= $userTypeIcon ?> mr-1"></i>
                                                <?= ucfirst($log['user_type']) ?>
                                            </span>
                                            <?php if ($log['user_name']): ?>
                                                <span class="text-sm font-medium text-gray-900">
                                                    <?= htmlspecialchars($log['user_name']) ?>
                                                </span>
                                                <span class="text-sm text-gray-500 ml-1">
                                                    (ID: <?= $log['user_id'] ?>)
                                                </span>
                                            <?php else: ?>
                                                <span class="text-sm text-gray-500">Unknown User</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">
                                            <?= ucwords(str_replace('_', ' ', htmlspecialchars($log['action']))) ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="text-sm text-gray-900 max-w-md break-words">
                                            <?= htmlspecialchars($log['details']) ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= htmlspecialchars($log['ip_address']) ?>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="text-sm text-gray-500 max-w-xs truncate" title="<?= htmlspecialchars($log['user_agent']) ?>">
                                            <?= htmlspecialchars($log['user_agent']) ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="px-6 py-4 bg-gray-50 border-t border-gray-200">
                    <div class="flex justify-between items-center">
                        <div class="text-sm text-gray-700">
                            Showing <?= ($offset + 1) ?> to <?= min($offset + $limit, $total_records) ?> of <?= $total_records ?> logs
                        </div>
                        <div class="flex space-x-2">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?= $page - 1 ?>&user_type=<?= urlencode($user_type) ?>&action=<?= urlencode($action) ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&search=<?= urlencode($search) ?>&sort=<?= urlencode($sort) ?>" 
                                   class="px-3 py-1 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 transition duration-150">
                                    <i class="fas fa-chevron-left mr-1"></i> Previous
                                </a>
                            <?php endif; ?>
                            
                            <div class="flex space-x-1">
                                <?php
                                $range = 2; // Show 2 pages before and after current page
                                $start_page = max(1, $page - $range);
                                $end_page = min($total_pages, $page + $range);
                                
                                if ($start_page > 1) {
                                    echo '<a href="?page=1&user_type=' . urlencode($user_type) . '&action=' . urlencode($action) . '&date_from=' . urlencode($date_from) . '&date_to=' . urlencode($date_to) . '&search=' . urlencode($search) . '&sort=' . urlencode($sort) . '" 
                                        class="px-3 py-1 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 transition duration-150">1</a>';
                                    
                                    if ($start_page > 2) {
                                        echo '<span class="px-3 py-1">...</span>';
                                    }
                                }
                                
                                for ($i = $start_page; $i <= $end_page; $i++) {
                                    $active_class = ($i === $page) ? 'bg-indigo-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300';
                                    echo '<a href="?page=' . $i . '&user_type=' . urlencode($user_type) . '&action=' . urlencode($action) . '&date_from=' . urlencode($date_from) . '&date_to=' . urlencode($date_to) . '&search=' . urlencode($search) . '&sort=' . urlencode($sort) . '" 
                                        class="px-3 py-1 ' . $active_class . ' rounded-md transition duration-150">' . $i . '</a>';
                                }
                                
                                if ($end_page < $total_pages) {
                                    if ($end_page < $total_pages - 1) {
                                        echo '<span class="px-3 py-1">...</span>';
                                    }
                                    
                                    echo '<a href="?page=' . $total_pages . '&user_type=' . urlencode($user_type) . '&action=' . urlencode($action) . '&date_from=' . urlencode($date_from) . '&date_to=' . urlencode($date_to) . '&search=' . urlencode($search) . '&sort=' . urlencode($sort) . '" 
                                        class="px-3 py-1 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 transition duration-150">' . $total_pages . '</a>';
                                }
                                ?>
                            </div>
                            
                            <?php if ($page < $total_pages): ?>
                                <a href="?page=<?= $page + 1 ?>&user_type=<?= urlencode($user_type) ?>&action=<?= urlencode($action) ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&search=<?= urlencode($search) ?>&sort=<?= urlencode($sort) ?>" 
                                   class="px-3 py-1 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 transition duration-150">
                                   Next <i class="fas fa-chevron-right ml-1"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Delete Logs Modal -->
    <div id="deleteModal" class="fixed inset-0 bg-gray-900 bg-opacity-50 flex items-center justify-center z-50 hidden">
        <div class="bg-white rounded-xl shadow-xl max-w-md w-full mx-4">
            <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                <h3 class="text-lg font-semibold text-gray-800">Delete Activity Logs</h3>
                <button type="button" class="text-gray-400 hover:text-gray-500" onclick="hideDeleteModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="delete_logs" value="1">
                
                <div class="p-6 space-y-4">
                    <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <i class="fas fa-exclamation-triangle text-yellow-400"></i>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm text-yellow-700">
                                    Warning: This will permanently delete all activity logs matching your current filters.
                                    <?php if (empty($user_type) && empty($action) && empty($date_from) && empty($date_to)): ?>
                                        <strong>You are about to delete ALL activity logs!</strong>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <div>
                        <p class="text-sm text-gray-600 mb-2">Current filters:</p>
                        <ul class="list-disc list-inside text-sm text-gray-600">
                            <li>User Type: <?= $user_type ? ucfirst($user_type) : 'All' ?></li>
                            <li>Action: <?= $action ? ucwords(str_replace('_', ' ', $action)) : 'All' ?></li>
                            <li>Date Range: <?= ($date_from || $date_to) ? ($date_from ?: 'Any') . ' to ' . ($date_to ?: 'Any') : 'All dates' ?></li>
                        </ul>
                    </div>
                    
                    <div class="mt-4">
                        <label class="inline-flex items-center">
                            <input type="checkbox" name="confirm_delete" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50" required>
                            <span class="ml-2 text-sm text-gray-700">I understand this action cannot be undone</span>
                        </label>
                    </div>
                </div>
                
                <div class="px-6 py-4 border-t border-gray-200 flex justify-end">
                    <button type="button" class="bg-gray-500 hover:bg-gray-600 text-white font-medium py-2 px-4 rounded-lg mr-3 transition duration-300" onclick="hideDeleteModal()">
                        Cancel
                    </button>
                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white font-medium py-2 px-4 rounded-lg transition duration-300">
                        Delete Logs
                    </button>
                </div>
            </form>
        </div>
    </div>

 

    <script>
        // Show delete modal
        function showDeleteModal() {
            document.getElementById('deleteModal').classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
        }
        
        // Hide delete modal
        function hideDeleteModal() {
            document.getElementById('deleteModal').classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        }
        
        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            const deleteModal = document.getElementById('deleteModal');
            if (event.target === deleteModal) {
                hideDeleteModal();
            }
        });
        
        // Close modal with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                hideDeleteModal();
            }
        });
        
        // Initialize datepicker if available
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof flatpickr !== 'undefined') {
                flatpickr('#date_from', {
                    dateFormat: 'Y-m-d'
                });
                
                flatpickr('#date_to', {
                    dateFormat: 'Y-m-d'
                });
            }
        });
    </script>
</body>
</html>


