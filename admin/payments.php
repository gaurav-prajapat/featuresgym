<?php
ob_start();
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

// Handle payment status updates
if (isset($_POST['update_status'])) {
    $payment_id = filter_input(INPUT_POST, 'payment_id', FILTER_VALIDATE_INT);
    $new_status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);
    
    if ($payment_id && in_array($new_status, ['pending', 'completed', 'failed', 'refunded'])) {
        try {
            $conn->beginTransaction();
            
            // Get payment details before update
            $stmt = $conn->prepare("SELECT * FROM payments WHERE id = :id");
            $stmt->execute([':id' => $payment_id]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Update payment status
            $stmt = $conn->prepare("UPDATE payments SET status = :status WHERE id = :id");
            $stmt->execute([
                ':status' => $new_status,
                ':id' => $payment_id
            ]);
            
            // If payment is for a membership, update membership payment status
            if ($payment['membership_id']) {
                $membership_status = ($new_status === 'completed') ? 'active' : 
                                    (($new_status === 'failed') ? 'cancelled' : 
                                    (($new_status === 'refunded') ? 'cancelled' : 'pending'));
                
                $payment_status = ($new_status === 'completed') ? 'paid' : 
                                 (($new_status === 'failed') ? 'failed' : 
                                 (($new_status === 'refunded') ? 'refunded' : 'pending'));
                
                $stmt = $conn->prepare("
                    UPDATE user_memberships 
                    SET status = :status, payment_status = :payment_status 
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':status' => $membership_status,
                    ':payment_status' => $payment_status,
                    ':id' => $payment['membership_id']
                ]);
            }
            
            // Create notification for user
            $notification_message = "";
            switch ($new_status) {
                case 'completed':
                    $notification_message = "Your payment of ₹" . number_format($payment['amount'], 2) . " has been confirmed.";
                    break;
                case 'failed':
                    $notification_message = "Your payment of ₹" . number_format($payment['amount'], 2) . " has failed. Please try again.";
                    break;
                case 'refunded':
                    $notification_message = "Your payment of ₹" . number_format($payment['amount'], 2) . " has been refunded.";
                    break;
                default:
                    $notification_message = "Your payment status has been updated to " . $new_status . ".";
            }
            
            $stmt = $conn->prepare("
                INSERT INTO notifications (
                    user_id, type, message, related_id, title, created_at, status, gym_id, is_read
                ) VALUES (
                    :user_id, 'payment', :message, :payment_id, 'Payment Update', NOW(), 'unread', :gym_id, 0
                )
            ");
            $stmt->execute([
                ':user_id' => $payment['user_id'],
                ':message' => $notification_message,
                ':payment_id' => $payment_id,
                ':gym_id' => $payment['gym_id']
            ]);
            
            // Log the activity
            $stmt = $conn->prepare("
                INSERT INTO activity_logs (
                    user_id, user_type, action, details, ip_address, user_agent
                ) VALUES (:user_id, 'admin', 'update_payment_status', :details, :ip, :user_agent)
            ");
            $stmt->execute([
                ':user_id' => $_SESSION['admin_id'],
                ':details' => "Updated payment ID: $payment_id status from {$payment['status']} to $new_status",
                ':ip' => $_SERVER['REMOTE_ADDR'],
                ':user_agent' => $_SERVER['HTTP_USER_AGENT']
            ]);
            
            $conn->commit();
            $success_message = "Payment status updated successfully!";
        } catch (PDOException $e) {
            $conn->rollBack();
            $error_message = "Failed to update payment status: " . $e->getMessage();
        }
    }
}

// Get search and filter parameters
$search = filter_input(INPUT_GET, 'search', FILTER_SANITIZE_STRING);
$status_filter = filter_input(INPUT_GET, 'status', FILTER_SANITIZE_STRING);
$gym_filter = filter_input(INPUT_GET, 'gym_id', FILTER_VALIDATE_INT);
$date_range = filter_input(INPUT_GET, 'date_range', FILTER_SANITIZE_STRING);
$payment_method = filter_input(INPUT_GET, 'payment_method', FILTER_SANITIZE_STRING);
$min_amount = filter_input(INPUT_GET, 'min_amount', FILTER_VALIDATE_FLOAT);
$max_amount = filter_input(INPUT_GET, 'max_amount', FILTER_VALIDATE_FLOAT);

// Pagination
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Build the query
$query = "
    SELECT p.*, u.username, u.email, g.name as gym_name, 
           CASE WHEN um.id IS NOT NULL THEN 'Membership' ELSE 'Other' END as payment_type
    FROM payments p
    JOIN users u ON p.user_id = u.id
    JOIN gyms g ON p.gym_id = g.gym_id
    LEFT JOIN user_memberships um ON p.membership_id = um.id
    WHERE 1=1
";
$count_query = "
    SELECT COUNT(*) as total
    FROM payments p
    JOIN users u ON p.user_id = u.id
    JOIN gyms g ON p.gym_id = g.gym_id
    LEFT JOIN user_memberships um ON p.membership_id = um.id
    WHERE 1=1
";
$params = [];

if ($search) {
    $search_condition = " AND (u.username LIKE :search OR u.email LIKE :search OR g.name LIKE :search OR p.transaction_id LIKE :search OR p.payment_id LIKE :search)";
    $query .= $search_condition;
    $count_query .= $search_condition;
    $params[':search'] = "%$search%";
}

if ($status_filter && in_array($status_filter, ['pending', 'completed', 'failed', 'refunded'])) {
    $status_condition = " AND p.status = :status";
    $query .= $status_condition;
    $count_query .= $status_condition;
    $params[':status'] = $status_filter;
}

if ($gym_filter) {
    $gym_condition = " AND p.gym_id = :gym_id";
    $query .= $gym_condition;
    $count_query .= $gym_condition;
    $params[':gym_id'] = $gym_filter;
}

if ($date_range) {
    switch ($date_range) {
        case 'today':
            $date_condition = " AND DATE(p.payment_date) = CURRENT_DATE";
            break;
        case 'yesterday':
            $date_condition = " AND DATE(p.payment_date) = DATE_SUB(CURRENT_DATE, INTERVAL 1 DAY)";
            break;
        case 'this_week':
            $date_condition = " AND YEARWEEK(p.payment_date, 1) = YEARWEEK(CURRENT_DATE, 1)";
            break;
        case 'this_month':
            $date_condition = " AND YEAR(p.payment_date) = YEAR(CURRENT_DATE) AND MONTH(p.payment_date) = MONTH(CURRENT_DATE)";
            break;
        case 'last_month':
            $date_condition = " AND YEAR(p.payment_date) = YEAR(DATE_SUB(CURRENT_DATE, INTERVAL 1 MONTH)) AND MONTH(p.payment_date) = MONTH(DATE_SUB(CURRENT_DATE, INTERVAL 1 MONTH))";
            break;
        case 'last_30_days':
            $date_condition = " AND p.payment_date >= DATE_SUB(CURRENT_DATE, INTERVAL 30 DAY)";
            break;
        case 'last_90_days':
            $date_condition = " AND p.payment_date >= DATE_SUB(CURRENT_DATE, INTERVAL 90 DAY)";
            break;
        default:
            $date_condition = "";
    }
    
    if ($date_condition) {
        $query .= $date_condition;
        $count_query .= $date_condition;
    }
}

if ($payment_method) {
    $method_condition = " AND p.payment_method = :payment_method";
    $query .= $method_condition;
    $count_query .= $method_condition;
    $params[':payment_method'] = $payment_method;
}

if ($min_amount) {
    $min_amount_condition = " AND p.amount >= :min_amount";
    $query .= $min_amount_condition;
    $count_query .= $min_amount_condition;
    $params[':min_amount'] = $min_amount;
}

if ($max_amount) {
    $max_amount_condition = " AND p.amount <= :max_amount";
    $query .= $max_amount_condition;
    $count_query .= $max_amount_condition;
    $params[':max_amount'] = $max_amount;
}

// Add sorting
$query .= " ORDER BY p.payment_date DESC";

// Add pagination
$query .= " LIMIT :offset, :per_page";
$params[':offset'] = $offset;
$params[':per_page'] = $per_page;

// Get total count for pagination
$stmt = $conn->prepare($count_query);
foreach ($params as $key => $value) {
    if ($key !== ':offset' && $key !== ':per_page') {
        $stmt->bindValue($key, $value);
    }
}
$stmt->execute();
$total_records = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_records / $per_page);


// Prepare and execute the query
$stmt = $conn->prepare($query);
foreach ($params as $key => $value) {
    // Make sure key includes the colon for named parameters
    if (strpos($key, ':') !== 0) {
        $key = ':' . $key;
    }
    $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmt->execute();

$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get payment statistics
$stmt = $conn->query("SELECT COUNT(*) as total FROM payments");
$total_payments = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $conn->query("SELECT COUNT(*) as completed FROM payments WHERE status = 'completed'");
$completed_payments = $stmt->fetch(PDO::FETCH_ASSOC)['completed'];

$stmt = $conn->query("SELECT COUNT(*) as pending FROM payments WHERE status = 'pending'");
$pending_payments = $stmt->fetch(PDO::FETCH_ASSOC)['pending'];

$stmt = $conn->query("SELECT COUNT(*) as failed FROM payments WHERE status = 'failed'");
$failed_payments = $stmt->fetch(PDO::FETCH_ASSOC)['failed'];

$stmt = $conn->query("SELECT COUNT(*) as refunded FROM payments WHERE status = 'refunded'");
$refunded_payments = $stmt->fetch(PDO::FETCH_ASSOC)['refunded'];

$stmt = $conn->query("SELECT SUM(amount) as total_amount FROM payments WHERE status = 'completed'");
$total_revenue = $stmt->fetch(PDO::FETCH_ASSOC)['total_amount'] ?? 0;

$stmt = $conn->query("
    SELECT SUM(amount) as monthly_amount 
    FROM payments 
    WHERE status = 'completed' 
    AND YEAR(payment_date) = YEAR(CURRENT_DATE) 
    AND MONTH(payment_date) = MONTH(CURRENT_DATE)
");
$monthly_revenue = $stmt->fetch(PDO::FETCH_ASSOC)['monthly_amount'] ?? 0;

$stmt = $conn->query("
    SELECT SUM(amount) as today_amount 
    FROM payments 
    WHERE status = 'completed' 
    AND DATE(payment_date) = CURRENT_DATE
");
$today_revenue = $stmt->fetch(PDO::FETCH_ASSOC)['today_amount'] ?? 0;

// Get all gyms for filter dropdown
$stmt = $conn->query("SELECT gym_id, name FROM gyms ORDER BY name");
$gyms = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get unique payment methods
$stmt = $conn->query("SELECT DISTINCT payment_method FROM payments WHERE payment_method IS NOT NULL");
$payment_methods = $stmt->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Management - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Optimize for performance */
        .payment-card {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            will-change: transform, box-shadow;
        }
        .payment-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }
        
        /* Responsive table */
        @media (max-width: 768px) {
            .responsive-table-card {
                display: block;
                margin-bottom: 1rem;
                border-radius: 0.5rem;
                overflow: hidden;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            }
            .responsive-table-card .card-header {
                background-color: #f9fafb;
                padding: 0.75rem 1rem;
                border-bottom: 1px solid #e5e7eb;
                font-weight: 500;
            }
            .responsive-table-card .card-body {
                padding: 1rem;
            }
            .responsive-table-card .card-row {
                display: flex;
                justify-content: space-between;
                padding: 0.5rem 0;
                border-bottom: 1px solid #f3f4f6;
            }
            .responsive-table-card .card-label {
                font-weight: 500;
                color: #6b7280;
            }
            .responsive-table-card .card-value {
                text-align: right;
            }
            .table-container {
                overflow-x: auto;
            }
        }
        
        /* Loading optimization */
        img, svg {
            display: block;
            max-width: 100%;
        }
        
        /* Print styles */
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
    </style>
</head>
<body class="bg-gray-900 text-white">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="ml-0 lg:ml-64 p-4 sm:p-6 transition-all duration-200">
        <!-- Header Section -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8">
            <div>
                <h1 class="text-3xl font-bold ">Payment Management</h1>
                <p class="text-gray-600">View and manage all payment transactions</p>
            </div>
            <div class="mt-4 md:mt-0 flex space-x-3">
                <a href="index.php" class="bg-gray-600 hover:bg-gray-700 text-white font-medium py-2 px-4 rounded-lg transition duration-300 flex items-center">
                    <i class="fas fa-arrow-left mr-2"></i> Dashboard
                </a>
                <button onclick="window.print()" class="bg-purple-600 hover:bg-purple-700 text-white font-medium py-2 px-4 rounded-lg transition duration-300 flex items-center no-print">
                    <i class="fas fa-print mr-2"></i> Print
                </button>
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

        <!-- Payment Statistics -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="payment-card bg-white rounded-xl shadow-md overflow-hidden">
                <div class="p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-green-100 text-green-500 mr-4">
                            <i class="fas fa-rupee-sign text-xl"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Total Revenue</p>
                            <p class="text-2xl font-bold text-gray-800">₹<?= number_format($total_revenue, 2) ?></p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="payment-card bg-white rounded-xl shadow-md overflow-hidden">
                <div class="p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-blue-100 text-blue-500 mr-4">
                            <i class="fas fa-calendar-alt text-xl"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Monthly Revenue</p>
                            <p class="text-2xl font-bold text-gray-800">₹<?= number_format($monthly_revenue, 2) ?></p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="payment-card bg-white rounded-xl shadow-md overflow-hidden">
                <div class="p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-purple-100 text-purple-500 mr-4">
                            <i class="fas fa-sun text-xl"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Today's Revenue</p>
                            <p class="text-2xl font-bold text-gray-800">₹<?= number_format($today_revenue, 2) ?></p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="payment-card bg-white rounded-xl shadow-md overflow-hidden">
                <div class="p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-indigo-100 text-indigo-500 mr-4">
                            <i class="fas fa-credit-card text-xl"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Total Transactions</p>
                            <p class="text-2xl font-bold text-gray-800"><?= number_format($total_payments) ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Payment Status Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="payment-card bg-white rounded-xl shadow-md overflow-hidden">
                <div class="p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-green-100 text-green-500 mr-4">
                            <i class="fas fa-check-circle text-xl"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Completed</p>
                            <p class="text-2xl font-bold text-gray-800"><?= number_format($completed_payments) ?></p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="payment-card bg-white rounded-xl shadow-md overflow-hidden">
                <div class="p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-yellow-100 text-yellow-500 mr-4">
                            <i class="fas fa-clock text-xl"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Pending</p>
                            <p class="text-2xl font-bold text-gray-800"><?= number_format($pending_payments) ?></p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="payment-card bg-white rounded-xl shadow-md overflow-hidden">
                <div class="p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-red-100 text-red-500 mr-4">
                            <i class="fas fa-times-circle text-xl"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Failed</p>
                            <p class="text-2xl font-bold text-gray-800"><?= number_format($failed_payments) ?></p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="payment-card bg-white rounded-xl shadow-md overflow-hidden">
                <div class="p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-blue-100 text-blue-500 mr-4">
                            <i class="fas fa-undo text-xl"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Refunded</p>
                            <p class="text-2xl font-bold text-gray-800"><?= number_format($refunded_payments) ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Search and Filters -->
        <div class="bg-white rounded-xl shadow-md overflow-hidden mb-8 no-print">
            <div class="px-6 py-5 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-800">Search and Filter Payments</h3>
            </div>
            <div class="p-6">
                <form method="GET" action="" class="space-y-4 md:space-y-0 md:grid md:grid-cols-2 lg:grid-cols-3 md:gap-4">
                    <div>
                        <label for="search" class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                        <input type="text" id="search" name="search" value="<?= htmlspecialchars($search ?? '') ?>" 
                            placeholder="Username, email, transaction ID..."
                            class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                    </div>
                    
                    <div>
                        <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                        <select id="status" name="status" class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                            <option value="">All Statuses</option>
                            <option value="completed" <?= ($status_filter === 'completed') ? 'selected' : '' ?>>Completed</option>
                            <option value="pending" <?= ($status_filter === 'pending') ? 'selected' : '' ?>>Pending</option>
                            <option value="failed" <?= ($status_filter === 'failed') ? 'selected' : '' ?>>Failed</option>
                            <option value="refunded" <?= ($status_filter === 'refunded') ? 'selected' : '' ?>>Refunded</option>
                        </select>
                    </div>
                    
                    <div>
                        <label for="gym_id" class="block text-sm font-medium text-gray-700 mb-1">Gym</label>
                        <select id="gym_id" name="gym_id" class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                            <option value="">All Gyms</option>
                            <?php foreach ($gyms as $gym): ?>
                                <option value="<?= $gym['gym_id'] ?>" <?= ($gym_filter == $gym['gym_id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($gym['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label for="date_range" class="block text-sm font-medium text-gray-700 mb-1">Date Range</label>
                        <select id="date_range" name="date_range" class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                            <option value="">All Time</option>
                            <option value="today" <?= ($date_range === 'today') ? 'selected' : '' ?>>Today</option>
                            <option value="yesterday" <?= ($date_range === 'yesterday') ? 'selected' : '' ?>>Yesterday</option>
                            <option value="this_week" <?= ($date_range === 'this_week') ? 'selected' : '' ?>>This Week</option>
                            <option value="this_month" <?= ($date_range === 'this_month') ? 'selected' : '' ?>>This Month</option>
                            <option value="last_month" <?= ($date_range === 'last_month') ? 'selected' : '' ?>>Last Month</option>
                            <option value="last_30_days" <?= ($date_range === 'last_30_days') ? 'selected' : '' ?>>Last 30 Days</option>
                            <option value="last_90_days" <?= ($date_range === 'last_90_days') ? 'selected' : '' ?>>Last 90 Days</option>
                        </select>
                    </div>
                    
                    <div>
                        <label for="payment_method" class="block text-sm font-medium text-gray-700 mb-1">Payment Method</label>
                        <select id="payment_method" name="payment_method" class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                            <option value="">All Methods</option>
                            <?php foreach ($payment_methods as $method): ?>
                                <option value="<?= htmlspecialchars($method) ?>" <?= ($payment_method === $method) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(ucfirst($method)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="flex space-x-2 items-end">
                        <div class="flex-1">
                            <label for="min_amount" class="block text-sm font-medium text-gray-700 mb-1">Min Amount (₹)</label>
                            <input type="number" id="min_amount" name="min_amount" value="<?= htmlspecialchars($min_amount ?? '') ?>" 
                                placeholder="Min"
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                        </div>
                        <div class="flex-1">
                            <label for="max_amount" class="block text-sm font-medium text-gray-700 mb-1">Max Amount (₹)</label>
                            <input type="number" id="max_amount" name="max_amount" value="<?= htmlspecialchars($max_amount ?? '') ?>" 
                                placeholder="Max"
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                        </div>
                    </div>
                    
                    <div class="md:col-span-2 lg:col-span-3 flex space-x-2">
                        <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-lg transition duration-300">
                            <i class="fas fa-search mr-2"></i> Search
                        </button>
                        <a href="payments.php" class="bg-gray-500 hover:bg-gray-600 text-white font-medium py-2 px-4 rounded-lg transition duration-300">
                            <i class="fas fa-redo mr-2"></i> Reset
                        </a>
                        <a href="export_payments.php<?= !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '' ?>" class="ml-auto bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-4 rounded-lg transition duration-300">
                            <i class="fas fa-file-export mr-2"></i> Export CSV
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Payments Table -->
        <div class="bg-white rounded-xl shadow-md overflow-hidden mb-8">
            <div class="px-6 py-5 border-b border-gray-200 flex justify-between items-center">
                <h3 class="text-lg font-semibold text-gray-800">Payment Transactions</h3>
                <div class="text-sm text-gray-600">
                    Showing <?= min(($page - 1) * $per_page + 1, $total_records) ?> - 
                    <?= min($page * $per_page, $total_records) ?> of 
                    <?= number_format($total_records) ?> payments
                </div>
            </div>
            
            <?php if (empty($payments)): ?>
                <div class="p-6 text-center text-gray-500">
                    <i class="fas fa-search text-4xl mb-4 block"></i>
                    <p>No payments found matching your criteria.</p>
                </div>
            <?php else: ?>
                <!-- Desktop Table View -->
                <div class="table-container hidden md:block">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead>
                            <tr class="bg-gray-50">
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Transaction</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Gym</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Method</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider no-print">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($payments as $payment): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900">
                                            <?= $payment['payment_type'] ?>
                                        </div>
                                        <div class="text-xs text-gray-500">
                                            <?php if ($payment['transaction_id']): ?>
                                                ID: <?= htmlspecialchars($payment['transaction_id']) ?>
                                            <?php elseif ($payment['payment_id']): ?>
                                                ID: <?= htmlspecialchars($payment['payment_id']) ?>
                                            <?php else: ?>
                                                No ID
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($payment['username']) ?></div>
                                        <div class="text-xs text-gray-500"><?= htmlspecialchars($payment['email']) ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900"><?= htmlspecialchars($payment['gym_name']) ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900">₹<?= number_format($payment['amount'], 2) ?></div>
                                        <?php if ($payment['discount_amount'] > 0): ?>
                                            <div class="text-xs text-green-600">
                                                Discount: ₹<?= number_format($payment['discount_amount'], 2) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900">
                                            <?= date('M d, Y', strtotime($payment['payment_date'])) ?>
                                        </div>
                                        <div class="text-xs text-gray-500">
                                            <?= date('h:i A', strtotime($payment['payment_date'])) ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php
                                        $statusClass = '';
                                        $statusIcon = '';
                                        
                                        switch ($payment['status']) {
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
                                        }
                                        ?>
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?= $statusClass ?>">
                                            <i class="fas <?= $statusIcon ?> mr-1"></i>
                                            <?= ucfirst($payment['status']) ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900">
                                            <?= $payment['payment_method'] ? htmlspecialchars(ucfirst($payment['payment_method'])) : 'N/A' ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium no-print">
                                        <div class="flex space-x-2">
                                            <a href="view_payment.php?id=<?= $payment['id'] ?>" class="text-blue-600 hover:text-blue-900" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <button type="button" onclick="showStatusModal(<?= $payment['id'] ?>, '<?= $payment['status'] ?>')" class="text-yellow-600 hover:text-yellow-900" title="Change Status">
                                                <i class="fas fa-exchange-alt"></i>
                                            </button>
                                            <a href="payment_receipt.php?id=<?= $payment['id'] ?>" target="_blank" class="text-green-600 hover:text-green-900" title="Generate Receipt">
                                                <i class="fas fa-file-invoice"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Mobile Card View -->
                <div class="md:hidden">
                    <?php foreach ($payments as $payment): ?>
                        <div class="responsive-table-card">
                            <div class="card-header flex justify-between items-center">
                                <div>
                                    <span class="font-medium"><?= htmlspecialchars($payment['username']) ?></span>
                                </div>
                                <?php
                                $statusClass = '';
                                $statusIcon = '';
                                
                                switch ($payment['status']) {
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
                                }
                                ?>
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?= $statusClass ?>">
                                    <i class="fas <?= $statusIcon ?> mr-1"></i>
                                    <?= ucfirst($payment['status']) ?>
                                </span>
                            </div>
                            <div class="card-body">
                                <div class="card-row">
                                    <div class="card-label">Amount</div>
                                    <div class="card-value font-medium">₹<?= number_format($payment['amount'], 2) ?></div>
                                </div>
                                <div class="card-row">
                                    <div class="card-label">Date</div>
                                    <div class="card-value"><?= date('M d, Y h:i A', strtotime($payment['payment_date'])) ?></div>
                                </div>
                                <div class="card-row">
                                    <div class="card-label">Gym</div>
                                    <div class="card-value"><?= htmlspecialchars($payment['gym_name']) ?></div>
                                </div>
                                <div class="card-row">
                                    <div class="card-label">Type</div>
                                    <div class="card-value"><?= $payment['payment_type'] ?></div>
                                </div>
                                <div class="card-row">
                                    <div class="card-label">Method</div>
                                    <div class="card-value"><?= $payment['payment_method'] ? htmlspecialchars(ucfirst($payment['payment_method'])) : 'N/A' ?></div>
                                </div>
                                <div class="card-row">
                                    <div class="card-label">Transaction ID</div>
                                    <div class="card-value text-xs">
                                        <?php if ($payment['transaction_id']): ?>
                                            <?= htmlspecialchars($payment['transaction_id']) ?>
                                        <?php elseif ($payment['payment_id']): ?>
                                            <?= htmlspecialchars($payment['payment_id']) ?>
                                        <?php else: ?>
                                            No ID
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="mt-4 flex justify-between">
                                    <a href="view_payment.php?id=<?= $payment['id'] ?>" class="text-blue-600 hover:text-blue-900 flex items-center">
                                        <i class="fas fa-eye mr-1"></i> View
                                    </a>
                                    <button type="button" onclick="showStatusModal(<?= $payment['id'] ?>, '<?= $payment['status'] ?>')" class="text-yellow-600 hover:text-yellow-900 flex items-center">
                                        <i class="fas fa-exchange-alt mr-1"></i> Status
                                    </button>
                                    <a href="payment_receipt.php?id=<?= $payment['id'] ?>" target="_blank" class="text-green-600 hover:text-green-900 flex items-center">
                                        <i class="fas fa-file-invoice mr-1"></i> Receipt
                                    </a>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="px-6 py-4 bg-gray-50 border-t border-gray-200 no-print">
                    <div class="flex justify-between items-center">
                        <div class="text-sm text-gray-600">
                            Showing <?= min(($page - 1) * $per_page + 1, $total_records) ?> - 
                            <?= min($page * $per_page, $total_records) ?> of 
                            <?= number_format($total_records) ?> payments
                        </div>
                        <div class="flex space-x-1">
                            <?php if ($page > 1): ?>
                                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="px-3 py-1 rounded-md bg-white border border-gray-300 text-gray-600 hover:bg-gray-50">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            <?php endif; ?>
                            
                            <?php
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $start_page + 4);
                            if ($end_page - $start_page < 4) {
                                $start_page = max(1, $end_page - 4);
                            }
                            ?>
                            
                            <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" 
                                   class="px-3 py-1 rounded-md <?= $i === $page ? 'bg-indigo-600 text-white' : 'bg-white border border-gray-300 text-gray-600 hover:bg-gray-50' ?>">
                                    <?= $i ?>
                                </a>
                            <?php endfor; ?>
                            
                            <?php if ($page < $total_pages): ?>
                                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="px-3 py-1 rounded-md bg-white border border-gray-300 text-gray-600 hover:bg-gray-50">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Status Change Modal -->
    <div id="statusModal" class="fixed inset-0 bg-gray-900 bg-opacity-50 flex items-center justify-center z-50 hidden no-print">
        <div class="bg-white rounded-xl shadow-xl max-w-md w-full mx-4">
            <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                <h3 class="text-lg font-semibold text-gray-800">Change Payment Status</h3>
                <button type="button" class="text-gray-400 hover:text-gray-500" onclick="hideStatusModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="statusForm" method="POST" action="">
                <input type="hidden" id="status_payment_id" name="payment_id">
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
                    <div class="text-sm text-gray-600">
                        <p><strong>Note:</strong> Changing payment status will also update related membership status if applicable.</p>
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



    <script>
        // Show status change modal
        function showStatusModal(paymentId, currentStatus) {
            document.getElementById('status_payment_id').value = paymentId;
            document.getElementById('status_select').value = currentStatus;
            document.getElementById('statusModal').classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
        }
        
        // Hide status change modal
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
        
        // Optimize performance by deferring non-critical JavaScript
        document.addEventListener('DOMContentLoaded', function() {
            // Add any additional JavaScript that can be deferred
            
            // Add print event listener
            window.addEventListener('beforeprint', function() {
                // Any preparation before printing
                document.querySelectorAll('.no-print').forEach(function(el) {
                    el.style.display = 'none';
                });
            });
            
            window.addEventListener('afterprint', function() {
                // Restore after printing
                document.querySelectorAll('.no-print').forEach(function(el) {
                    el.style.display = '';
                });
            });
        });
    </script>
</body>
</html>




