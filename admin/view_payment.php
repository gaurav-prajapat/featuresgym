<?php
ob_start();
include '../includes/navbar.php';

if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ./login.php');
    exit();
}

require_once '../config/database.php';
$db = new GymDatabase();
$conn = $db->getConnection();

// Get payment ID from URL
$payment_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$payment_id) {
    $_SESSION['error'] = "Invalid payment ID.";
    header('Location: payments.php');
    exit();
}

// Get payment details
$stmt = $conn->prepare("
    SELECT p.*, u.username, u.email, u.phone, g.name as gym_name, g.address as gym_address, 
           CASE WHEN um.id IS NOT NULL THEN 'Membership' ELSE 'Other' END as payment_type,
           um.plan_id, gmp.plan_name, gmp.tier, gmp.duration, um.start_date, um.end_date
    FROM payments p
    JOIN users u ON p.user_id = u.id
    JOIN gyms g ON p.gym_id = g.gym_id
    LEFT JOIN user_memberships um ON p.membership_id = um.id
    LEFT JOIN gym_membership_plans gmp ON um.plan_id = gmp.plan_id
    WHERE p.id = :payment_id
");
$stmt->execute([':payment_id' => $payment_id]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$payment) {
    $_SESSION['error'] = "Payment not found.";
    header('Location: payments.php');
    exit();
}

// Get payment activity logs
$stmt = $conn->prepare("
    SELECT al.*, u.username
    FROM activity_logs al
    LEFT JOIN users u ON al.user_id = u.id
    WHERE (al.action LIKE '%payment%' OR al.details LIKE :payment_id_search)
    AND al.details LIKE :payment_id_exact
    ORDER BY al.created_at DESC
    LIMIT 10
");
$stmt->execute([
    ':payment_id_search' => '%payment ID: ' . $payment_id . '%',
    ':payment_id_exact' => '%' . $payment_id . '%'
]);
$activity_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle payment status updates
$success_message = '';
$error_message = '';

if (isset($_POST['update_status'])) {
    $new_status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);
    
    if (in_array($new_status, ['pending', 'completed', 'failed', 'refunded'])) {
        try {
            $conn->beginTransaction();
            
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
            
            // Refresh payment data
            $stmt = $conn->prepare("
                SELECT p.*, u.username, u.email, u.phone, g.name as gym_name, g.address as gym_address, 
                       CASE WHEN um.id IS NOT NULL THEN 'Membership' ELSE 'Other' END as payment_type,
                       um.plan_id, gmp.plan_name, gmp.tier, gmp.duration, um.start_date, um.end_date
                FROM payments p
                JOIN users u ON p.user_id = u.id
                JOIN gyms g ON p.gym_id = g.gym_id
                LEFT JOIN user_memberships um ON p.membership_id = um.id
                LEFT JOIN gym_membership_plans gmp ON um.plan_id = gmp.plan_id
                WHERE p.id = :payment_id
            ");
            $stmt->execute([':payment_id' => $payment_id]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $success_message = "Payment status updated successfully!";
        } catch (PDOException $e) {
            $conn->rollBack();
            $error_message = "Failed to update payment status: " . $e->getMessage();
        }
    } else {
        $error_message = "Invalid payment status.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Details - Admin Dashboard</title>
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
    </style>
</head>
<body class="bg-gray-100 min-h-screen flex flex-col">
    <div class="container mx-auto px-4 py-8 flex-grow">
        <!-- Header Section -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 no-print">
            <div>
                <h1 class="text-3xl font-bold ">Payment Details</h1>
                <p class="text-gray-600">View detailed information about this payment</p>
            </div>
            <div class="mt-4 md:mt-0 flex space-x-3">
                <a href="payments.php" class="bg-gray-600 hover:bg-gray-700 text-white font-medium py-2 px-4 rounded-lg transition duration-300 flex items-center">
                    <i class="fas fa-arrow-left mr-2"></i> Back to Payments
                </a>
                <button onclick="window.print()" class="bg-purple-600 hover:bg-purple-700 text-white font-medium py-2 px-4 rounded-lg transition duration-300 flex items-center">
                    <i class="fas fa-print mr-2"></i> Print
                </button>
                <a href="payment_receipt.php?id=<?= $payment_id ?>" target="_blank" class="bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-4 rounded-lg transition duration-300 flex items-center">
                    <i class="fas fa-file-invoice mr-2"></i> Generate Receipt
                </a>
            </div>
        </div>

        <!-- Alerts Section -->
        <?php if ($success_message): ?>
        <div class="bg-green-50 border-l-4 border-green-400 p-4 mb-6 rounded-lg no-print">
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
        <div class="bg-red-50 border-l-4 border-red-400 p-4 mb-6 rounded-lg no-print">
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

        <!-- Payment Details -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <!-- Main Payment Info -->
            <div class="md:col-span-2">
                <div class="bg-white rounded-xl shadow-md overflow-hidden">
                    <div class="px-6 py-5 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-800">Payment Information</h3>
                    </div>
                    <div class="p-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <div class="mb-4">
                                    <h4 class="text-sm font-medium text-gray-500 mb-1">Payment ID</h4>
                                    <p class="text-base font-medium text-gray-900">#<?= $payment_id ?></p>
                                </div>
                                <div class="mb-4">
                                    <h4 class="text-sm font-medium text-gray-500 mb-1">Amount</h4>
                                    <p class="text-xl font-bold text-gray-900">₹<?= number_format($payment['amount'], 2) ?></p>
                                </div>
                                <div class="mb-4">
                                    <h4 class="text-sm font-medium text-gray-500 mb-1">Status</h4>
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
                                    <span class="px-3 py-1 inline-flex text-sm leading-5 font-semibold rounded-full <?= $statusClass ?>">
                                        <i class="fas <?= $statusIcon ?> mr-1"></i>
                                        <?= ucfirst($payment['status']) ?>
                                    </span>
                                </div>
                                <div class="mb-4">
                                    <h4 class="text-sm font-medium text-gray-500 mb-1">Payment Date</h4>
                                    <p class="text-base text-gray-900"><?= date('F j, Y, g:i a', strtotime($payment['payment_date'])) ?></p>
                                </div>
                                <div class="mb-4">
                                    <h4 class="text-sm font-medium text-gray-500 mb-1">Payment Method</h4>
                                    <p class="text-base text-gray-900"><?= $payment['payment_method'] ? htmlspecialchars(ucfirst($payment['payment_method'])) : 'N/A' ?></p>
                                </div>
                            </div>
                            <div>
                            <div class="mb-4">
                                    <h4 class="text-sm font-medium text-gray-500 mb-1">Transaction ID</h4>
                                    <p class="text-base text-gray-900">
                                        <?php if ($payment['transaction_id']): ?>
                                            <?= htmlspecialchars($payment['transaction_id']) ?>
                                        <?php elseif ($payment['payment_id']): ?>
                                            <?= htmlspecialchars($payment['payment_id']) ?>
                                        <?php else: ?>
                                            N/A
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <div class="mb-4">
                                    <h4 class="text-sm font-medium text-gray-500 mb-1">Payment Type</h4>
                                    <p class="text-base text-gray-900"><?= $payment['payment_type'] ?></p>
                                </div>
                                <?php if ($payment['discount_amount'] > 0): ?>
                                <div class="mb-4">
                                    <h4 class="text-sm font-medium text-gray-500 mb-1">Discount Applied</h4>
                                    <p class="text-base text-green-600">₹<?= number_format($payment['discount_amount'], 2) ?></p>
                                </div>
                                <?php endif; ?>
                                <?php if ($payment['coupon_code']): ?>
                                <div class="mb-4">
                                    <h4 class="text-sm font-medium text-gray-500 mb-1">Coupon Code</h4>
                                    <p class="text-base text-gray-900"><?= htmlspecialchars($payment['coupon_code']) ?></p>
                                </div>
                                <?php endif; ?>
                                <?php if ($payment['base_amount']): ?>
                                <div class="mb-4">
                                    <h4 class="text-sm font-medium text-gray-500 mb-1">Base Amount</h4>
                                    <p class="text-base text-gray-900">₹<?= number_format($payment['base_amount'], 2) ?></p>
                                </div>
                                <?php endif; ?>
                                <?php if ($payment['govt_tax'] > 0 || $payment['gateway_tax'] > 0): ?>
                                <div class="mb-4">
                                    <h4 class="text-sm font-medium text-gray-500 mb-1">Taxes</h4>
                                    <?php if ($payment['govt_tax'] > 0): ?>
                                    <p class="text-sm text-gray-900">Govt Tax: ₹<?= number_format($payment['govt_tax'], 2) ?></p>
                                    <?php endif; ?>
                                    <?php if ($payment['gateway_tax'] > 0): ?>
                                    <p class="text-sm text-gray-900">Gateway Fee: ₹<?= number_format($payment['gateway_tax'], 2) ?></p>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Update Status Form -->
                        <div class="mt-6 pt-6 border-t border-gray-200 no-print">
                            <h4 class="text-base font-medium text-gray-800 mb-3">Update Payment Status</h4>
                            <form method="POST" action="" class="flex items-end space-x-3">
                                <input type="hidden" name="update_status" value="1">
                                <div class="flex-grow">
                                    <label for="status" class="block text-sm font-medium text-gray-700 mb-1">New Status</label>
                                    <select id="status" name="status" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                                        <option value="completed" <?= $payment['status'] === 'completed' ? 'selected' : '' ?>>Completed</option>
                                        <option value="pending" <?= $payment['status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                                        <option value="failed" <?= $payment['status'] === 'failed' ? 'selected' : '' ?>>Failed</option>
                                        <option value="refunded" <?= $payment['status'] === 'refunded' ? 'selected' : '' ?>>Refunded</option>
                                    </select>
                                </div>
                                <div>
                                    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-lg transition duration-300">
                                        Update Status
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Membership Details (if applicable) -->
                <?php if ($payment['payment_type'] === 'Membership' && $payment['plan_name']): ?>
                <div class="bg-white rounded-xl shadow-md overflow-hidden mt-6">
                    <div class="px-6 py-5 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-800">Membership Details</h3>
                    </div>
                    <div class="p-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <div class="mb-4">
                                    <h4 class="text-sm font-medium text-gray-500 mb-1">Plan Name</h4>
                                    <p class="text-base font-medium text-gray-900"><?= htmlspecialchars($payment['plan_name']) ?></p>
                                </div>
                                <div class="mb-4">
                                    <h4 class="text-sm font-medium text-gray-500 mb-1">Tier</h4>
                                    <p class="text-base text-gray-900"><?= htmlspecialchars($payment['tier']) ?></p>
                                </div>
                                <div class="mb-4">
                                    <h4 class="text-sm font-medium text-gray-500 mb-1">Duration</h4>
                                    <p class="text-base text-gray-900"><?= htmlspecialchars($payment['duration']) ?></p>
                                </div>
                            </div>
                            <div>
                                <div class="mb-4">
                                    <h4 class="text-sm font-medium text-gray-500 mb-1">Start Date</h4>
                                    <p class="text-base text-gray-900"><?= date('F j, Y', strtotime($payment['start_date'])) ?></p>
                                </div>
                                <div class="mb-4">
                                    <h4 class="text-sm font-medium text-gray-500 mb-1">End Date</h4>
                                    <p class="text-base text-gray-900"><?= date('F j, Y', strtotime($payment['end_date'])) ?></p>
                                </div>
                                <div class="mb-4">
                                    <h4 class="text-sm font-medium text-gray-500 mb-1">Membership ID</h4>
                                    <p class="text-base text-gray-900">#<?= $payment['membership_id'] ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Activity Logs -->
                <?php if (!empty($activity_logs)): ?>
                <div class="bg-white rounded-xl shadow-md overflow-hidden mt-6 no-print">
                    <div class="px-6 py-5 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-800">Activity History</h3>
                    </div>
                    <div class="p-6">
                        <div class="space-y-4">
                            <?php foreach ($activity_logs as $log): ?>
                                <div class="flex items-start">
                                    <div class="flex-shrink-0 h-10 w-10 rounded-full bg-gray-200 flex items-center justify-center">
                                        <i class="fas fa-history text-gray-600"></i>
                                    </div>
                                    <div class="ml-4">
                                        <div class="text-sm font-medium text-gray-900">
                                            <?= htmlspecialchars($log['action']) ?>
                                        </div>
                                        <div class="text-sm text-gray-500">
                                            <?= htmlspecialchars($log['details']) ?>
                                        </div>
                                        <div class="text-xs text-gray-400 mt-1">
                                            <?= date('F j, Y, g:i a', strtotime($log['created_at'])) ?> by 
                                            <?= $log['username'] ? htmlspecialchars($log['username']) : 'System' ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- User and Gym Info Sidebar -->
            <div>
                <!-- User Info -->
                <div class="bg-white rounded-xl shadow-md overflow-hidden">
                    <div class="px-6 py-5 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-800">Customer Information</h3>
                    </div>
                    <div class="p-6">
                        <div class="flex items-center mb-4">
                            <div class="h-12 w-12 rounded-full bg-gray-200 flex items-center justify-center">
                                <i class="fas fa-user text-gray-600 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h4 class="text-base font-medium text-gray-900"><?= htmlspecialchars($payment['username']) ?></h4>
                                <p class="text-sm text-gray-600">User ID: <?= $payment['user_id'] ?></p>
                            </div>
                        </div>
                        <div class="space-y-3 mt-6">
                            <div>
                                <h4 class="text-sm font-medium text-gray-500 mb-1">Email</h4>
                                <p class="text-base text-gray-900"><?= htmlspecialchars($payment['email']) ?></p>
                            </div>
                            <?php if ($payment['phone']): ?>
                            <div>
                                <h4 class="text-sm font-medium text-gray-500 mb-1">Phone</h4>
                                <p class="text-base text-gray-900"><?= htmlspecialchars($payment['phone']) ?></p>
                            </div>
                            <?php endif; ?>
                            <div class="pt-4 mt-4 border-t border-gray-200 no-print">
                                <a href="view_user.php?id=<?= $payment['user_id'] ?>" class="inline-flex items-center text-indigo-600 hover:text-indigo-900">
                                    <i class="fas fa-user mr-2"></i> View User Profile
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Gym Info -->
                <div class="bg-white rounded-xl shadow-md overflow-hidden mt-6">
                    <div class="px-6 py-5 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-800">Gym Information</h3>
                    </div>
                    <div class="p-6">
                        <div class="flex items-center mb-4">
                            <div class="h-12 w-12 rounded-full bg-gray-200 flex items-center justify-center">
                                <i class="fas fa-dumbbell text-gray-600 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h4 class="text-base font-medium text-gray-900"><?= htmlspecialchars($payment['gym_name']) ?></h4>
                                <p class="text-sm text-gray-600">Gym ID: <?= $payment['gym_id'] ?></p>
                            </div>
                        </div>
                        <div class="space-y-3 mt-6">
                            <div>
                                <h4 class="text-sm font-medium text-gray-500 mb-1">Address</h4>
                                <p class="text-base text-gray-900"><?= htmlspecialchars($payment['gym_address']) ?></p>
                            </div>
                            <div class="pt-4 mt-4 border-t border-gray-200 no-print">
                                <a href="manage_gym.php?id=<?= $payment['gym_id'] ?>" class="inline-flex items-center text-indigo-600 hover:text-indigo-900">
                                    <i class="fas fa-dumbbell mr-2"></i> View Gym Details
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Quick Actions -->
                <div class="bg-white rounded-xl shadow-md overflow-hidden mt-6 no-print">
                    <div class="px-6 py-5 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-800">Quick Actions</h3>
                    </div>
                    <div class="p-6">
                        <div class="space-y-3">
                            <a href="payment_receipt.php?id=<?= $payment_id ?>" target="_blank" class="block w-full bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-4 rounded-lg transition duration-300 text-center">
                                <i class="fas fa-file-invoice mr-2"></i> Generate Receipt
                            </a>
                            <a href="email_receipt.php?id=<?= $payment_id ?>" class="block w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-lg transition duration-300 text-center">
                                <i class="fas fa-envelope mr-2"></i> Email Receipt
                            </a>
                            <button onclick="window.print()" class="block w-full bg-purple-600 hover:bg-purple-700 text-white font-medium py-2 px-4 rounded-lg transition duration-300 text-center">
                                <i class="fas fa-print mr-2"></i> Print Details
                            </button>
                            <?php if ($payment['status'] === 'completed'): ?>
                            <button type="button" onclick="showRefundModal()" class="block w-full bg-yellow-600 hover:bg-yellow-700 text-white font-medium py-2 px-4 rounded-lg transition duration-300 text-center">
                                <i class="fas fa-undo mr-2"></i> Process Refund
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Refund Modal -->
    <div id="refundModal" class="fixed inset-0 bg-gray-900 bg-opacity-50 flex items-center justify-center z-50 hidden no-print">
        <div class="bg-white rounded-xl shadow-xl max-w-md w-full mx-4">
            <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                <h3 class="text-lg font-semibold text-gray-800">Process Refund</h3>
                <button type="button" class="text-gray-400 hover:text-gray-500" onclick="hideRefundModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="update_status" value="1">
                <input type="hidden" name="status" value="refunded">
                
                <div class="p-6 space-y-4">
                    <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <i class="fas fa-exclamation-triangle text-yellow-400"></i>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm text-yellow-700">
                                    You are about to refund ₹<?= number_format($payment['amount'], 2) ?> to <?= htmlspecialchars($payment['username']) ?>.
                                </p>
                                <p class="text-sm text-yellow-700 mt-2">
                                    This will mark the payment as refunded and cancel any associated memberships.
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="text-sm text-gray-600">
                        <p>Please note that this action only updates the status in our system. You will need to process the actual refund through your payment gateway separately.</p>
                    </div>
                </div>
                
                <div class="px-6 py-4 border-t border-gray-200 flex justify-end">
                    <button type="button" class="bg-gray-500 hover:bg-gray-600 text-white font-medium py-2 px-4 rounded-lg mr-3 transition duration-300" onclick="hideRefundModal()">
                        Cancel
                    </button>
                    <button type="submit" class="bg-yellow-600 hover:bg-yellow-700 text-white font-medium py-2 px-4 rounded-lg transition duration-300">
                        Confirm Refund
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
        // Show refund modal
        function showRefundModal() {
            document.getElementById('refundModal').classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
        }
        
        // Hide refund modal
        function hideRefundModal() {
            document.getElementById('refundModal').classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        }
        
        // Close modals when clicking outside
        window.addEventListener('click', function(event) {
            const refundModal = document.getElementById('refundModal');
            if (event.target === refundModal) {
                hideRefundModal();
            }
        });
        
        // Close modals with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                hideRefundModal();
            }
        });
    </script>
</body>
</html>


