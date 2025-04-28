<?php
session_start();

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

// Handle transaction status updates
if (isset($_POST['update_status'])) {
    $transaction_id = filter_input(INPUT_POST, 'transaction_id', FILTER_VALIDATE_INT);
    $new_status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);
    
    if ($transaction_id && in_array($new_status, ['completed', 'pending', 'failed', 'refunded'])) {
        try {
            $stmt = $conn->prepare("UPDATE transactions SET status = :status WHERE transaction_id = :id");
            $stmt->execute([
                ':status' => $new_status,
                ':id' => $transaction_id
            ]);
            
            // Log the activity
            $stmt = $conn->prepare("
                INSERT INTO activity_logs (
                    user_id, user_type, action, details, ip_address, user_agent
                ) VALUES (:user_id, 'admin', 'update_transaction_status', :details, :ip, :user_agent)
            ");
            $stmt->execute([
                ':user_id' => $_SESSION['admin_id'],
                ':details' => "Updated transaction ID: $transaction_id status to $new_status",
                ':ip' => $_SERVER['REMOTE_ADDR'],
                ':user_agent' => $_SERVER['HTTP_USER_AGENT']
            ]);
            
            $success_message = "Transaction status updated successfully!";
        } catch (PDOException $e) {
            $error_message = "Failed to update transaction status: " . $e->getMessage();
        }
    }
}

// Handle transaction deletion
if (isset($_POST['delete_transaction'])) {
    $transaction_id = filter_input(INPUT_POST, 'transaction_id', FILTER_VALIDATE_INT);
    
    if ($transaction_id) {
        try {
            // Get transaction details for logging
            $stmt = $conn->prepare("
                SELECT t.transaction_id, u.username, g.name as gym_name 
                FROM transactions t
                LEFT JOIN users u ON t.user_id = u.id
                LEFT JOIN gyms g ON t.gym_id = g.gym_id
                WHERE t.transaction_id = :id
            ");
            $stmt->execute([':id' => $transaction_id]);
            $transaction_details = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Delete transaction
            $stmt = $conn->prepare("DELETE FROM transactions WHERE transaction_id = :id");
            $stmt->execute([':id' => $transaction_id]);
            
            // Log the activity
            $stmt = $conn->prepare("
                INSERT INTO activity_logs (
                    user_id, user_type, action, details, ip_address, user_agent
                ) VALUES (:user_id, 'admin', 'delete_transaction', :details, :ip, :user_agent)
            ");
            $stmt->execute([
                ':user_id' => $_SESSION['admin_id'],
                ':details' => "Deleted transaction ID: {$transaction_details['transaction_id']} for user: {$transaction_details['username']} at gym: {$transaction_details['gym_name']}",
                ':ip' => $_SERVER['REMOTE_ADDR'],
                ':user_agent' => $_SERVER['HTTP_USER_AGENT']
            ]);
            
            $success_message = "Transaction deleted successfully!";
        } catch (PDOException $e) {
            $error_message = "Failed to delete transaction: " . $e->getMessage();
        }
    }
}

// Get search and filter parameters
$search = filter_input(INPUT_GET, 'search', FILTER_SANITIZE_STRING);
$status_filter = filter_input(INPUT_GET, 'status', FILTER_SANITIZE_STRING);
$gym_filter = filter_input(INPUT_GET, 'gym_id', FILTER_VALIDATE_INT);
$date_from = filter_input(INPUT_GET, 'date_from', FILTER_SANITIZE_STRING);
$date_to = filter_input(INPUT_GET, 'date_to', FILTER_SANITIZE_STRING);
$sort = filter_input(INPUT_GET, 'sort', FILTER_SANITIZE_STRING) ?: 'date_desc';

// Build the query
$query = "
    SELECT t.*, u.username, u.email, g.name as gym_name
    FROM transactions t
    LEFT JOIN users u ON t.user_id = u.id
    LEFT JOIN gyms g ON t.gym_id = g.gym_id
    WHERE 1=1
";

$params = [];

if ($search) {
    $query .= " AND (t.transaction_id LIKE :search OR u.username LIKE :search OR u.email LIKE :search OR g.name LIKE :search)";
    $params[':search'] = "%$search%";
}

if ($status_filter && in_array($status_filter, ['completed', 'pending', 'failed', 'refunded'])) {
    $query .= " AND t.status = :status";
    $params[':status'] = $status_filter;
}

if ($gym_filter) {
    $query .= " AND t.gym_id = :gym_id";
    $params[':gym_id'] = $gym_filter;
}

if ($date_from) {
    $query .= " AND t.transaction_date >= :date_from";
    $params[':date_from'] = $date_from;
}

if ($date_to) {
    $query .= " AND t.transaction_date <= :date_to";
    $params[':date_to'] = $date_to . ' 23:59:59';
}

// Add sorting
switch ($sort) {
    case 'date_asc':
        $query .= " ORDER BY t.transaction_date ASC";
        break;
    case 'amount_desc':
        $query .= " ORDER BY t.amount DESC";
        break;
    case 'amount_asc':
        $query .= " ORDER BY t.amount ASC";
        break;
    case 'date_desc':
    default:
        $query .= " ORDER BY t.transaction_date DESC";
        break;
}

// Prepare and execute the query
$stmt = $conn->prepare($query);
$stmt->execute($params);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get transaction statistics
$stmt = $conn->query("SELECT COUNT(*) as total FROM transactions");
$total_transactions = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $conn->query("SELECT COUNT(*) as completed FROM transactions WHERE status = 'completed'");
$completed_transactions = $stmt->fetch(PDO::FETCH_ASSOC)['completed'];

$stmt = $conn->query("SELECT COUNT(*) as pending FROM transactions WHERE status = 'pending'");
$pending_transactions = $stmt->fetch(PDO::FETCH_ASSOC)['pending'];

$stmt = $conn->query("SELECT COUNT(*) as failed FROM transactions WHERE status = 'failed'");
$failed_transactions = $stmt->fetch(PDO::FETCH_ASSOC)['failed'];

$stmt = $conn->query("SELECT COUNT(*) as refunded FROM transactions WHERE status = 'refunded'");
$refunded_transactions = $stmt->fetch(PDO::FETCH_ASSOC)['refunded'];

// Get revenue statistics
$stmt = $conn->query("
    SELECT SUM(amount) as total_revenue 
    FROM transactions 
    WHERE status = 'completed'
");
$total_revenue = $stmt->fetch(PDO::FETCH_ASSOC)['total_revenue'] ?? 0;

$stmt = $conn->query("
    SELECT SUM(amount) as monthly_revenue 
    FROM transactions 
    WHERE status = 'completed' 
    AND MONTH(transaction_date) = MONTH(CURRENT_DATE)
    AND YEAR(transaction_date) = YEAR(CURRENT_DATE)
");
$monthly_revenue = $stmt->fetch(PDO::FETCH_ASSOC)['monthly_revenue'] ?? 0;

// Get all gyms for filter dropdown
$stmt = $conn->query("SELECT gym_id, name FROM gyms ORDER BY name");
$gyms = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all transaction statuses for filter dropdown
$stmt = $conn->query("SELECT DISTINCT status FROM transactions");
$statuses = $stmt->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Transactions - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .transaction-card {
            transition: all 0.3s ease;
        }
        .transaction-card:hover {
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
                <h1 class="text-3xl font-bold ">Manage Transactions</h1>
                <p class="text-gray-600">View and manage all transactions on the platform</p>
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

        <!-- Transaction Statistics -->
        <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-6 mb-8">
            <div class="transaction-card bg-white rounded-xl shadow-md overflow-hidden">
                <div class="p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-indigo-100 text-indigo-500 mr-4">
                            <i class="fas fa-receipt text-xl"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Total Transactions</p>
                            <p class="text-2xl font-bold text-gray-800"><?= number_format($total_transactions) ?></p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="transaction-card bg-white rounded-xl shadow-md overflow-hidden">
                <div class="p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-green-100 text-green-500 mr-4">
                            <i class="fas fa-check-circle text-xl"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Completed</p>
                            <p class="text-2xl font-bold text-gray-800"><?= number_format($completed_transactions) ?></p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="transaction-card bg-white rounded-xl shadow-md overflow-hidden">
                <div class="p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-yellow-100 text-yellow-500 mr-4">
                            <i class="fas fa-clock text-xl"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Pending</p>
                            <p class="text-2xl font-bold text-gray-800"><?= number_format($pending_transactions) ?></p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="transaction-card bg-white rounded-xl shadow-md overflow-hidden">
                <div class="p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-red-100 text-red-500 mr-4">
                            <i class="fas fa-times-circle text-xl"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Failed</p>
                            <p class="text-2xl font-bold text-gray-800"><?= number_format($failed_transactions) ?></p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="transaction-card bg-white rounded-xl shadow-md overflow-hidden">
                <div class="p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-blue-100 text-blue-500 mr-4">
                            <i class="fas fa-undo text-xl"></i>
                            </div>
                        <div>
                            <p class="text-sm text-gray-600">Refunded</p>
                            <p class="text-2xl font-bold text-gray-800"><?= number_format($refunded_transactions) ?></p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="transaction-card bg-white rounded-xl shadow-md overflow-hidden">
                <div class="p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-purple-100 text-purple-500 mr-4">
                            <i class="fas fa-rupee-sign text-xl"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Total Revenue</p>
                            <p class="text-2xl font-bold text-gray-800">₹<?= number_format($total_revenue, 2) ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Search and Filters -->
        <div class="bg-white rounded-xl shadow-md overflow-hidden mb-8">
            <div class="px-6 py-5 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-800">Search and Filter Transactions</h3>
            </div>
            <div class="p-6">
                <form method="GET" action="" class="space-y-4 md:space-y-0 md:flex md:flex-wrap md:items-end md:gap-4 text-black">
                    <div class="flex-grow min-w-[200px]">
                        <label for="search" class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                        <input type="text" id="search" name="search" value="<?= htmlspecialchars($search ?? '') ?>" 
                            placeholder="Search by ID, username, email, or gym"
                            class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                    </div>
                    
                    <div class="w-full md:w-auto">
                        <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                        <select id="status" name="status" class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                            <option value="">All Statuses</option>
                            <?php foreach ($statuses as $status): ?>
                                <option value="<?= htmlspecialchars($status) ?>" <?= ($status_filter === $status) ? 'selected' : '' ?>>
                                    <?= ucfirst(htmlspecialchars($status)) ?>
                                </option>
                            <?php endforeach; ?>
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
                            <option value="amount_desc" <?= ($sort === 'amount_desc') ? 'selected' : '' ?>>Amount (Highest First)</option>
                            <option value="amount_asc" <?= ($sort === 'amount_asc') ? 'selected' : '' ?>>Amount (Lowest First)</option>
                        </select>
                    </div>
                    
                    <div class="flex space-x-2">
                        <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-lg transition duration-300">
                            <i class="fas fa-search mr-2"></i> Search
                        </button>
                        <a href="transactions.php" class="bg-gray-500 hover:bg-gray-600 text-white font-medium py-2 px-4 rounded-lg transition duration-300">
                            <i class="fas fa-redo mr-2"></i> Reset
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Transactions Table -->
        <div class="bg-white rounded-xl shadow-md overflow-hidden mb-8">
            <div class="px-6 py-5 border-b border-gray-200 flex justify-between items-center">
                <h3 class="text-lg font-semibold text-gray-800">Transaction List</h3>
                <a href="export_transactions.php" class="bg-green-600 hover:bg-green-700 text-white text-sm font-medium py-2 px-4 rounded-lg transition duration-300">
                    <i class="fas fa-file-export mr-2"></i> Export Data
                </a>
            </div>
            <div class="overflow-x-auto">
                <?php if (empty($transactions)): ?>
                    <div class="p-6 text-center text-gray-500">
                        <i class="fas fa-receipt text-4xl mb-4 block"></i>
                        <p>No transactions found matching your criteria.</p>
                    </div>
                <?php else: ?>
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead>
                            <tr class="bg-gray-50">
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Transaction ID</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Gym</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Plan</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($transactions as $transaction): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($transaction['transaction_id']) ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900"><?= date('M d, Y h:i A', strtotime($transaction['transaction_date'])) ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php if (isset($transaction['username'])): ?>
                                            <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($transaction['username']) ?></div>
                                            <div class="text-sm text-gray-500"><?= htmlspecialchars($transaction['email']) ?></div>
                                        <?php else: ?>
                                            <div class="text-sm text-gray-500">User deleted</div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900"><?= htmlspecialchars($transaction['gym_name'] ?? 'N/A') ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900"><?= htmlspecialchars($transaction['plan_name'] ?? 'N/A') ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900">₹<?= number_format($transaction['amount'], 2) ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php
                                        $statusClass = '';
                                        $statusIcon = '';
                                        
                                        switch ($transaction['status']) {
                                            case 'completed':
                                                $statusClass = 'bg-green-100 text-green-800';
                                                $statusIcon = 'fa-check-circle';
                                                break;
                                            case 'pending':
                                                $statusClass = 'bg-yellow-100 text-yellow-800';
                                                $statusIcon = 'fa-clock';
                                                break;
                                            case 'failed':
                                                $statusClass = 'bg-red-100 text-red-800';
                                                $statusIcon = 'fa-times-circle';
                                                break;
                                            case 'refunded':
                                                $statusClass = 'bg-blue-100 text-blue-800';
                                                $statusIcon = 'fa-undo';
                                                break;
                                            default:
                                                $statusClass = 'bg-gray-100 text-gray-800';
                                                $statusIcon = 'fa-question-circle';
                                        }
                                        ?>
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?= $statusClass ?>">
                                            <i class="fas <?= $statusIcon ?> mr-1"></i>
                                            <?= ucfirst($transaction['status']) ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <div class="flex space-x-2">
                                            <a href="view_transaction.php?id=<?= $transaction['transaction_id'] ?>" class="text-blue-600 hover:text-blue-900" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <button type="button" onclick="showStatusModal(<?= $transaction['transaction_id'] ?>, '<?= $transaction['status'] ?>')" class="text-yellow-600 hover:text-yellow-900" title="Change Status">
                                                <i class="fas fa-exchange-alt"></i>
                                            </button>
                                            <?php if ($transaction['status'] === 'completed'): ?>
                                                <a href="generate_receipt.php?id=<?= $transaction['transaction_id'] ?>" class="text-green-600 hover:text-green-900" title="Generate Receipt">
                                                    <i class="fas fa-file-pdf"></i>
                                                </a>
                                            <?php endif; ?>
                                            <button type="button" onclick="confirmDelete(<?= $transaction['transaction_id'] ?>)" class="text-red-600 hover:text-red-900" title="Delete">
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
                    <h3 class="text-lg font-semibold text-gray-800">Change Transaction Status</h3>
                    <button type="button" class="text-gray-400 hover:text-gray-500" onclick="hideStatusModal()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <form id="statusForm" method="POST" action="">
                    <input type="hidden" id="status_transaction_id" name="transaction_id">
                    <input type="hidden" name="update_status" value="1">
                    
                    <div class="p-6 space-y-4">
                        <div>
                            <label for="status" class="block text-sm font-medium text-gray-700 mb-1">New Status</label>
                            <select id="status_select" name="status" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                                <option value="completed">Completed</option>
                                <option value="pending">Pending</option>
                                <option value="failed">Failed</option>
                                <option value="refunded">Refunded</option>
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

        <!-- Delete Transaction Form (Hidden) -->
        <form id="deleteTransactionForm" method="POST" action="" class="hidden">
            <input type="hidden" id="delete_transaction_id" name="transaction_id">
            <input type="hidden" name="delete_transaction" value="1">
        </form>
    </div>

 

    <script>
        // Show status change modal
        function showStatusModal(transactionId, currentStatus) {
            document.getElementById('status_transaction_id').value = transactionId;
            document.getElementById('status_select').value = currentStatus;
            document.getElementById('statusModal').classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
        }
        
        // Hide status change modal
        function hideStatusModal() {
            document.getElementById('statusModal').classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        }
        
        // Confirm transaction deletion
        function confirmDelete(transactionId) {
            if (confirm(`Are you sure you want to delete transaction #${transactionId}? This action cannot be undone.`)) {
                document.getElementById('delete_transaction_id').value = transactionId;
                document.getElementById('deleteTransactionForm').submit();
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


