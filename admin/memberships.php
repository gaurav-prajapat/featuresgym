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
$success_message = '';
$error_message = '';

// Handle membership status updates
if (isset($_POST['update_status'])) {
    $membership_id = filter_input(INPUT_POST, 'membership_id', FILTER_VALIDATE_INT);
    $new_status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);
    
    if ($membership_id && in_array($new_status, ['active', 'expired', 'cancelled'])) {
        try {
            $stmt = $conn->prepare("UPDATE user_memberships SET status = :status WHERE id = :id");
            $stmt->execute([
                ':status' => $new_status,
                ':id' => $membership_id
            ]);
            
            // Log the activity
            $stmt = $conn->prepare("
                INSERT INTO activity_logs (
                    user_id, user_type, action, details, ip_address, user_agent
                ) VALUES (:user_id, 'admin', 'update_membership_status', :details, :ip, :user_agent)
            ");
            $stmt->execute([
                ':user_id' => $_SESSION['admin_id'],
                ':details' => "Updated membership ID: $membership_id status to $new_status",
                ':ip' => $_SERVER['REMOTE_ADDR'],
                ':user_agent' => $_SERVER['HTTP_USER_AGENT']
            ]);
            
            $success_message = "Membership status updated successfully!";
        } catch (PDOException $e) {
            $error_message = "Failed to update membership status: " . $e->getMessage();
        }
    }
}

// Handle membership deletion
if (isset($_POST['delete_membership'])) {
    $membership_id = filter_input(INPUT_POST, 'membership_id', FILTER_VALIDATE_INT);
    
    if ($membership_id) {
        try {
            // Get membership details for logging
            $stmt = $conn->prepare("
                SELECT um.id, u.username, g.name as gym_name 
                FROM user_memberships um
                JOIN users u ON um.user_id = u.id
                JOIN gyms g ON um.gym_id = g.gym_id
                WHERE um.id = :id
            ");
            $stmt->execute([':id' => $membership_id]);
            $membership_details = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Delete membership
            $stmt = $conn->prepare("DELETE FROM user_memberships WHERE id = :id");
            $stmt->execute([':id' => $membership_id]);
            
            // Log the activity
            $stmt = $conn->prepare("
                INSERT INTO activity_logs (
                    user_id, user_type, action, details, ip_address, user_agent
                ) VALUES (:user_id, 'admin', 'delete_membership', :details, :ip, :user_agent)
            ");
            $stmt->execute([
                ':user_id' => $_SESSION['admin_id'],
                ':details' => "Deleted membership ID: {$membership_details['id']} for user: {$membership_details['username']} at gym: {$membership_details['gym_name']}",
                ':ip' => $_SERVER['REMOTE_ADDR'],
                ':user_agent' => $_SERVER['HTTP_USER_AGENT']
            ]);
            
            $success_message = "Membership deleted successfully!";
        } catch (PDOException $e) {
            $error_message = "Failed to delete membership: " . $e->getMessage();
        }
    }
}

// Get search and filter parameters
$search = filter_input(INPUT_GET, 'search', FILTER_SANITIZE_STRING);
$status_filter = filter_input(INPUT_GET, 'status', FILTER_SANITIZE_STRING);
$gym_filter = filter_input(INPUT_GET, 'gym_id', FILTER_VALIDATE_INT);
$date_filter = filter_input(INPUT_GET, 'date_filter', FILTER_SANITIZE_STRING);

// Build the query
$query = "
    SELECT um.*, u.username, u.email, g.name as gym_name, gmp.tier, gmp.plan_name, gmp.duration
    FROM user_memberships um
    JOIN users u ON um.user_id = u.id
    JOIN gyms g ON um.gym_id = g.gym_id
    JOIN gym_membership_plans gmp ON um.plan_id = gmp.plan_id
    WHERE 1=1
";
$params = [];

if ($search) {
    $query .= " AND (u.username LIKE :search OR u.email LIKE :search OR g.name LIKE :search OR gmp.plan_name LIKE :search)";
    $params[':search'] = "%$search%";
}

if ($status_filter && in_array($status_filter, ['active', 'expired', 'cancelled'])) {
    $query .= " AND um.status = :status";
    $params[':status'] = $status_filter;
}

if ($gym_filter) {
    $query .= " AND um.gym_id = :gym_id";
    $params[':gym_id'] = $gym_filter;
}

if ($date_filter) {
    switch ($date_filter) {
        case 'active_now':
            $query .= " AND um.start_date <= CURRENT_DATE AND um.end_date >= CURRENT_DATE";
            break;
        case 'expiring_soon':
            $query .= " AND um.end_date BETWEEN CURRENT_DATE AND DATE_ADD(CURRENT_DATE, INTERVAL 7 DAY)";
            break;
        case 'expired':
            $query .= " AND um.end_date < CURRENT_DATE";
            break;
        case 'recent':
            $query .= " AND um.created_at >= DATE_SUB(CURRENT_DATE, INTERVAL 30 DAY)";
            break;
    }
}

// Add sorting
$query .= " ORDER BY um.created_at DESC";

// Prepare and execute the query
$stmt = $conn->prepare($query);
$stmt->execute($params);
$memberships = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get membership statistics
$stmt = $conn->query("SELECT COUNT(*) as total FROM user_memberships");
$total_memberships = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $conn->query("SELECT COUNT(*) as active FROM user_memberships WHERE status = 'active'");
$active_memberships = $stmt->fetch(PDO::FETCH_ASSOC)['active'];

$stmt = $conn->query("SELECT COUNT(*) as expired FROM user_memberships WHERE status = 'expired'");
$expired_memberships = $stmt->fetch(PDO::FETCH_ASSOC)['expired'];

$stmt = $conn->query("SELECT COUNT(*) as cancelled FROM user_memberships WHERE status = 'cancelled'");
$cancelled_memberships = $stmt->fetch(PDO::FETCH_ASSOC)['cancelled'];

$stmt = $conn->query("SELECT COUNT(*) as expiring_soon FROM user_memberships WHERE end_date BETWEEN CURRENT_DATE AND DATE_ADD(CURRENT_DATE, INTERVAL 7 DAY)");
$expiring_soon = $stmt->fetch(PDO::FETCH_ASSOC)['expiring_soon'];

// Get revenue statistics
$stmt = $conn->query("
    SELECT SUM(amount) as total_revenue 
    FROM user_memberships 
    WHERE payment_status = 'paid'
");
$total_revenue = $stmt->fetch(PDO::FETCH_ASSOC)['total_revenue'] ?? 0;

$stmt = $conn->query("
    SELECT SUM(amount) as monthly_revenue 
    FROM user_memberships 
    WHERE payment_status = 'paid' 
    AND MONTH(created_at) = MONTH(CURRENT_DATE)
    AND YEAR(created_at) = YEAR(CURRENT_DATE)
");
$monthly_revenue = $stmt->fetch(PDO::FETCH_ASSOC)['monthly_revenue'] ?? 0;

// Get all gyms for filter dropdown
$stmt = $conn->query("SELECT gym_id, name FROM gyms ORDER BY name");
$gyms = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Memberships - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .membership-card {
            transition: all 0.3s ease;
        }
        .membership-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">
    <div class="container mx-auto px-4 py-8">
        <!-- Header Section -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8">
            <div>
                <h1 class="text-3xl font-bold text-gray-800">Manage Memberships</h1>
                <p class="text-gray-600">View and manage all user memberships on the platform</p>
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

        <!-- Membership Statistics -->
        <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-6 mb-8">
            <div class="membership-card bg-white rounded-xl shadow-md overflow-hidden">
                <div class="p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-indigo-100 text-indigo-500 mr-4">
                            <i class="fas fa-id-card text-xl"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Total Memberships</p>
                            <p class="text-2xl font-bold text-gray-800"><?= number_format($total_memberships) ?></p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="membership-card bg-white rounded-xl shadow-md overflow-hidden">
                <div class="p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-green-100 text-green-500 mr-4">
                            <i class="fas fa-check-circle text-xl"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Active</p>
                            <p class="text-2xl font-bold text-gray-800"><?= number_format($active_memberships) ?></p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="membership-card bg-white rounded-xl shadow-md overflow-hidden">
                <div class="p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-yellow-100 text-yellow-500 mr-4">
                            <i class="fas fa-hourglass-end text-xl"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Expired</p>
                            <p class="text-2xl font-bold text-gray-800"><?= number_format($expired_memberships) ?></p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="membership-card bg-white rounded-xl shadow-md overflow-hidden">
                <div class="p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-red-100 text-red-500 mr-4">
                            <i class="fas fa-ban text-xl"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Cancelled</p>
                            <p class="text-2xl font-bold text-gray-800"><?= number_format($cancelled_memberships) ?></p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="membership-card bg-white rounded-xl shadow-md overflow-hidden">
                <div class="p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-orange-100 text-orange-500 mr-4">
                            <i class="fas fa-exclamation-triangle text-xl"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Expiring Soon</p>
                            <p class="text-2xl font-bold text-gray-800"><?= number_format($expiring_soon) ?></p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="membership-card bg-white rounded-xl shadow-md overflow-hidden">
                <div class="p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-purple-100 text-purple-500 mr-4">
                            <i class="fas fa-rupee-sign text-xl"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Monthly Revenue</p>
                            <p class="text-2xl font-bold text-gray-800">₹<?= number_format($monthly_revenue, 2) ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Search and Filters -->
        <div class="bg-white rounded-xl shadow-md overflow-hidden mb-8">
            <div class="px-6 py-5 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-800">Search and Filter Memberships</h3>
            </div>
            <div class="p-6">
                <form method="GET" action="" class="space-y-4 md:space-y-0 md:flex md:flex-wrap md:items-end md:gap-4">
                    <div class="flex-grow min-w-[200px]">
                        <label for="search" class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                        <input type="text" id="search" name="search" value="<?= htmlspecialchars($search ?? '') ?>" 
                            placeholder="Search by username, email, gym, or plan"
                            class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                    </div>
                    
                    <div class="w-full md:w-auto">
                        <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                        <select id="status" name="status" class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                            <option value="">All Statuses</option>
                            <option value="active" <?= ($status_filter === 'active') ? 'selected' : '' ?>>Active</option>
                            <option value="expired" <?= ($status_filter === 'expired') ? 'selected' : '' ?>>Expired</option>
                            <option value="cancelled" <?= ($status_filter === 'cancelled') ? 'selected' : '' ?>>Cancelled</option>
                        </select>
                    </div>
                    
                    <div class="w-full md:w-auto">
                        <label for="gym_id" class="block text-sm font-medium text-gray-700 mb-1">Gym</label>
                        <select id="gym_id" name="gym_id" class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                            <option value="">All Gyms</option>
                            <?php foreach ($gyms as $gym): ?>
                                <option value="<?= $gym['gym_id'] ?>" <?= ($gym_filter == $gym['gym_id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($gym['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="w-full md:w-auto">
                        <label for="date_filter" class="block text-sm font-medium text-gray-700 mb-1">Date Filter</label>
                        <select id="date_filter" name="date_filter" class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                            <option value="">All Dates</option>
                            <option value="active_now" <?= ($date_filter === 'active_now') ? 'selected' : '' ?>>Active Now</option>
                            <option value="expiring_soon" <?= ($date_filter === 'expiring_soon') ? 'selected' : '' ?>>Expiring Soon (7 days)</option>
                            <option value="expired" <?= ($date_filter === 'expired') ? 'selected' : '' ?>>Already Expired</option>
                            <option value="recent" <?= ($date_filter === 'recent') ? 'selected' : '' ?>>Recent (30 days)</option>
                        </select>
                    </div>
                    
                    <div class="flex space-x-2">
                        <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-lg transition duration-300">
                            <i class="fas fa-search mr-2"></i> Search
                        </button>
                        <a href="memberships.php" class="bg-gray-500 hover:bg-gray-600 text-white font-medium py-2 px-4 rounded-lg transition duration-300">
                            <i class="fas fa-redo mr-2"></i> Reset
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Memberships Table -->
        <div class="bg-white rounded-xl shadow-md overflow-hidden mb-8">
            <div class="px-6 py-5 border-b border-gray-200 flex justify-between items-center">
                <h3 class="text-lg font-semibold text-gray-800">Membership List</h3>
                <a href="export_memberships.php" class="bg-green-600 hover:bg-green-700 text-white text-sm font-medium py-2 px-4 rounded-lg transition duration-300">
                    <i class="fas fa-file-export mr-2"></i> Export Data
                </a>
            </div>
            <div class="overflow-x-auto">
                <?php if (empty($memberships)): ?>
                    <div class="p-6 text-center text-gray-500">
                        <i class="fas fa-id-card text-4xl mb-4 block"></i>
                        <p>No memberships found matching your criteria.</p>
                    </div>
                <?php else: ?>
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead>
                            <tr class="bg-gray-50">
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Member</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Gym</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Plan Details</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Duration</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Payment</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($memberships as $membership): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($membership['username']) ?></div>
                                        <div class="text-sm text-gray-500"><?= htmlspecialchars($membership['email']) ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900"><?= htmlspecialchars($membership['gym_name']) ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900"><?= htmlspecialchars($membership['plan_name']) ?></div>
                                        <div class="text-sm text-gray-500">
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">
                                                <?= htmlspecialchars($membership['tier']) ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900">
                                            <?= date('M d, Y', strtotime($membership['start_date'])) ?> - 
                                            <?= date('M d, Y', strtotime($membership['end_date'])) ?>
                                        </div>
                                        <div class="text-sm text-gray-500"><?= htmlspecialchars($membership['duration']) ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900">₹<?= number_format($membership['amount'], 2) ?></div>
                                        <?php if ($membership['discount_amount'] > 0): ?>
                                            <div class="text-xs text-green-600">
                                                Discount: ₹<?= number_format($membership['discount_amount'], 2) ?>
                                                <?php if ($membership['coupon_code']): ?>
                                                    (<?= htmlspecialchars($membership['coupon_code']) ?>)
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php
                                        $statusClass = '';
                                        $statusIcon = '';
                                        
                                        switch ($membership['status']) {
                                            case 'active':
                                                $statusClass = 'bg-green-100 text-green-800';
                                                $statusIcon = 'fa-check-circle';
                                                break;
                                            case 'expired':
                                                $statusClass = 'bg-yellow-100 text-yellow-800';
                                                $statusIcon = 'fa-hourglass-end';
                                                break;
                                            case 'cancelled':
                                                $statusClass = 'bg-red-100 text-red-800';
                                                $statusIcon = 'fa-ban';
                                                break;
                                        }
                                        ?>
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?= $statusClass ?>">
                                            <i class="fas <?= $statusIcon ?> mr-1"></i>
                                            <?= ucfirst($membership['status']) ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php
                                        $paymentClass = '';
                                        $paymentIcon = '';
                                        
                                        switch ($membership['payment_status']) {
                                            case 'paid':
                                                $paymentClass = 'bg-green-100 text-green-800';
                                                $paymentIcon = 'fa-check-circle';
                                                break;
                                            case 'pending':
                                                $paymentClass = 'bg-yellow-100 text-yellow-800';
                                                $paymentIcon = 'fa-clock';
                                                break;
                                            case 'failed':
                                                $paymentClass = 'bg-red-100 text-red-800';
                                                $paymentIcon = 'fa-times-circle';
                                                break;
                                        }
                                        ?>
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?= $paymentClass ?>">
                                            <i class="fas <?= $paymentIcon ?> mr-1"></i>
                                            <?= ucfirst($membership['payment_status']) ?>
                                        </span>
                                        <?php if ($membership['payment_id']): ?>
                                            <div class="text-xs text-gray-500 mt-1">ID: <?= htmlspecialchars($membership['payment_id']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <div class="flex space-x-2">
                                            <a href="view_membership.php?id=<?= $membership['id'] ?>" class="text-blue-600 hover:text-blue-900" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <button type="button" onclick="showStatusModal(<?= $membership['id'] ?>, '<?= $membership['status'] ?>')" class="text-yellow-600 hover:text-yellow-900" title="Change Status">
                                                <i class="fas fa-exchange-alt"></i>
                                            </button>
                                            <button type="button" onclick="confirmDelete(<?= $membership['id'] ?>, '<?= htmlspecialchars($membership['username']) ?>', '<?= htmlspecialchars($membership['gym_name']) ?>')" class="text-red-600 hover:text-red-900" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Status Change Modal -->
        <div id="statusModal" class="fixed inset-0 bg-gray-900 bg-opacity-50 flex items-center justify-center z-50 hidden">
            <div class="bg-white rounded-xl shadow-xl max-w-md w-full mx-4">
            <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                    <h3 class="text-lg font-semibold text-gray-800">Change Membership Status</h3>
                    <button type="button" class="text-gray-400 hover:text-gray-500" onclick="hideStatusModal()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <form id="statusForm" method="POST" action="">
                    <input type="hidden" id="status_membership_id" name="membership_id">
                    <input type="hidden" name="update_status" value="1">
                    
                    <div class="p-6 space-y-4">
                        <div>
                            <label for="status" class="block text-sm font-medium text-gray-700 mb-1">New Status</label>
                            <select id="status_select" name="status" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                                <option value="active">Active</option>
                                <option value="expired">Expired</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
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

        <!-- Delete Membership Form (Hidden) -->
        <form id="deleteMembershipForm" method="POST" action="" class="hidden">
            <input type="hidden" id="delete_membership_id" name="membership_id">
            <input type="hidden" name="delete_membership" value="1">
        </form>
    </div>

    <!-- Footer -->
    <footer class="bg-white py-6 mt-auto">
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
        // Show status change modal
        function showStatusModal(membershipId, currentStatus) {
            document.getElementById('status_membership_id').value = membershipId;
            document.getElementById('status_select').value = currentStatus;
            document.getElementById('statusModal').classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
        }
        
        // Hide status change modal
        function hideStatusModal() {
            document.getElementById('statusModal').classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        }
        
        // Confirm membership deletion
        function confirmDelete(membershipId, username, gymName) {
            if (confirm(`Are you sure you want to delete the membership for "${username}" at "${gymName}"? This action cannot be undone.`)) {
                document.getElementById('delete_membership_id').value = membershipId;
                document.getElementById('deleteMembershipForm').submit();
            }
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

