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

// Process payout if submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_payout'])) {
    $withdrawal_id = (int)$_POST['withdrawal_id'];
    $transaction_id = trim($_POST['transaction_id']);
    $notes = trim($_POST['notes']);
    
    if (empty($transaction_id)) {
        $_SESSION['error'] = "Transaction ID is required.";
    } else {
        try {
            $conn->beginTransaction();
            
            // Get withdrawal details
            $stmt = $conn->prepare("
                SELECT w.*, g.name as gym_name, u.username as owner_name, u.email as owner_email
                FROM withdrawals w
                JOIN gyms g ON w.gym_id = g.gym_id
                JOIN users u ON g.owner_id = u.id
                WHERE w.id = ? AND w.status = 'pending'
            ");
            $stmt->execute([$withdrawal_id]);
            $withdrawal = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($withdrawal) {
                // Update withdrawal status
                $stmt = $conn->prepare("
                    UPDATE withdrawals 
                    SET status = 'completed', 
                        transaction_id = ?, 
                        notes = ?, 
                        processed_at = NOW(),
                        admin_id = ?
                    WHERE id = ?
                ");
                $stmt->execute([$transaction_id, $notes, $_SESSION['user_id'], $withdrawal_id]);
                
                // Log the activity
                $stmt = $conn->prepare("
                    INSERT INTO activity_logs (
                        user_id, user_type, action, details, ip_address, user_agent
                    ) VALUES (?, 'admin', 'process_payout', ?, ?, ?)
                ");
                
                $details = "Processed payout of ₹" . number_format($withdrawal['amount'], 2) . 
                           " for gym: " . $withdrawal['gym_name'] . " (ID: " . $withdrawal['gym_id'] . ")";
                
                $stmt->execute([
                    $_SESSION['user_id'],
                    $details,
                    $_SERVER['REMOTE_ADDR'],
                    $_SERVER['HTTP_USER_AGENT']
                ]);
                
                // Send notification to gym owner
                $stmt = $conn->prepare("
                    INSERT INTO gym_notifications (
                        gym_id, title, message, created_at
                    ) VALUES (?, ?, ?, NOW())
                ");
                
                $notificationTitle = "Payout Processed";
                $notificationMessage = "Your withdrawal request of ₹" . number_format($withdrawal['amount'], 2) . 
                                      " has been processed. Transaction ID: " . $transaction_id;
                
                $stmt->execute([
                    $withdrawal['gym_id'],
                    $notificationTitle,
                    $notificationMessage
                ]);
                
                $conn->commit();
                $_SESSION['success'] = "Payout processed successfully!";
                
                // Redirect to avoid form resubmission
                header("Location: payouts.php");
                exit();
            } else {
                $conn->rollBack();
                $_SESSION['error'] = "Invalid withdrawal request or already processed.";
            }
        } catch (PDOException $e) {
            $conn->rollBack();
            $_SESSION['error'] = "Database error: " . $e->getMessage();
        }
    }
}

// Reject payout if submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_payout'])) {
    $withdrawal_id = (int)$_POST['withdrawal_id'];
    $reason = trim($_POST['reason']);
    
    if (empty($reason)) {
        $_SESSION['error'] = "Rejection reason is required.";
    } else {
        try {
            $conn->beginTransaction();
            
            // Get withdrawal details
            $stmt = $conn->prepare("
                SELECT w.*, g.name as gym_name, g.balance, u.username as owner_name, u.email as owner_email
                FROM withdrawals w
                JOIN gyms g ON w.gym_id = g.gym_id
                JOIN users u ON g.owner_id = u.id
                WHERE w.id = ? AND w.status = 'pending'
            ");
            $stmt->execute([$withdrawal_id]);
            $withdrawal = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($withdrawal) {
                // Update withdrawal status
                $stmt = $conn->prepare("
                    UPDATE withdrawals 
                    SET status = 'failed', 
                        notes = ?, 
                        processed_at = NOW(),
                        admin_id = ?
                    WHERE id = ?
                ");
                $stmt->execute([$reason, $_SESSION['user_id'], $withdrawal_id]);
                
                // Return the amount to gym balance
                $stmt = $conn->prepare("
                    UPDATE gyms 
                    SET balance = balance + ? 
                    WHERE gym_id = ?
                ");
                $stmt->execute([$withdrawal['amount'], $withdrawal['gym_id']]);
                
                // Log the activity
                $stmt = $conn->prepare("
                    INSERT INTO activity_logs (
                        user_id, user_type, action, details, ip_address, user_agent
                    ) VALUES (?, 'admin', 'reject_payout', ?, ?, ?)
                ");
                
                $details = "Rejected payout of ₹" . number_format($withdrawal['amount'], 2) . 
                           " for gym: " . $withdrawal['gym_name'] . " (ID: " . $withdrawal['gym_id'] . 
                           "). Reason: " . $reason;
                
                $stmt->execute([
                    $_SESSION['user_id'],
                    $details,
                    $_SERVER['REMOTE_ADDR'],
                    $_SERVER['HTTP_USER_AGENT']
                ]);
                
                // Send notification to gym owner
                $stmt = $conn->prepare("
                    INSERT INTO gym_notifications (
                        gym_id, title, message, created_at
                    ) VALUES (?, ?, ?, NOW())
                ");
                
                $notificationTitle = "Payout Request Rejected";
                $notificationMessage = "Your withdrawal request of ₹" . number_format($withdrawal['amount'], 2) . 
                                      " has been rejected. Reason: " . $reason . 
                                      ". The amount has been returned to your gym balance.";
                
                $stmt->execute([
                    $withdrawal['gym_id'],
                    $notificationTitle,
                    $notificationMessage
                ]);
                
                $conn->commit();
                $_SESSION['success'] = "Payout rejected and amount returned to gym balance.";
                
                // Redirect to avoid form resubmission
                header("Location: payouts.php");
                exit();
            } else {
                $conn->rollBack();
                $_SESSION['error'] = "Invalid withdrawal request or already processed.";
            }
        } catch (PDOException $e) {
            $conn->rollBack();
            $_SESSION['error'] = "Database error: " . $e->getMessage();
        }
    }
}

// Get filters
$status = isset($_GET['status']) ? $_GET['status'] : '';
$gym_id = isset($_GET['gym_id']) ? (int)$_GET['gym_id'] : 0;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'w.created_at';
$order = isset($_GET['order']) ? $_GET['order'] : 'DESC';

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Build query
$query = "
    SELECT w.*, g.name as gym_name, g.city, g.state, 
           u.username as owner_name, u.email as owner_email,
           pm.method_type, pm.account_name, pm.bank_name, pm.account_number, pm.ifsc_code, pm.upi_id,
           a.username as admin_name
    FROM withdrawals w
    JOIN gyms g ON w.gym_id = g.gym_id
    JOIN users u ON g.owner_id = u.id
    LEFT JOIN payment_methods pm ON w.payment_method_id = pm.id
    LEFT JOIN users a ON w.admin_id = a.id
    WHERE 1=1
";

$countQuery = "
    SELECT COUNT(*) 
    FROM withdrawals w
    JOIN gyms g ON w.gym_id = g.gym_id
    WHERE 1=1
";

$params = [];
$countParams = [];

if (!empty($status)) {
    $query .= " AND w.status = ?";
    $countQuery .= " AND w.status = ?";
    $params[] = $status;
    $countParams[] = $status;
}

if ($gym_id > 0) {
    $query .= " AND w.gym_id = ?";
    $countQuery .= " AND w.gym_id = ?";
    $params[] = $gym_id;
    $countParams[] = $gym_id;
}

if (!empty($date_from)) {
    $query .= " AND DATE(w.created_at) >= ?";
    $countQuery .= " AND DATE(w.created_at) >= ?";
    $params[] = $date_from;
    $countParams[] = $date_from;
}

if (!empty($date_to)) {
    $query .= " AND DATE(w.created_at) <= ?";
    $countQuery .= " AND DATE(w.created_at) <= ?";
    $params[] = $date_to;
    $countParams[] = $date_to;
}

// Add sorting
$query .= " ORDER BY $sort $order";

// Add pagination
$query .= " LIMIT $per_page OFFSET $offset";

// Execute queries
try {
    // Get total count
    $stmt = $conn->prepare($countQuery);
    $stmt->execute($countParams);
    $total_payouts = $stmt->fetchColumn();
    
    // Get payouts for current page
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $payouts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate total pages
    $total_pages = ceil($total_payouts / $per_page);
} catch (PDOException $e) {
    $_SESSION['error'] = "Database error: " . $e->getMessage();
    $payouts = [];
    $total_payouts = 0;
    $total_pages = 1;
}

// Get payout statistics
try {
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_requests,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_requests,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_requests,
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_requests,
            SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as total_paid_amount,
            SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END) as pending_amount
        FROM withdrawals
    ");
    $stmt->execute();
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $stats = [
        'total_requests' => 0,
        'pending_requests' => 0,
        'completed_requests' => 0,
        'failed_requests' => 0,
        'total_paid_amount' => 0,
        'pending_amount' => 0
    ];
}

// Get gyms for filter dropdown
try {
    $stmt = $conn->prepare("
        SELECT DISTINCT g.gym_id, g.name
        FROM withdrawals w
        JOIN gyms g ON w.gym_id = g.gym_id
        ORDER BY g.name
    ");
    $stmt->execute();
    $gyms = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $gyms = [];
}

// Function to format date
function formatDate($date) {
    return date('M d, Y', strtotime($date));
}

// Function to format datetime
function formatDateTime($datetime) {
    return date('M d, Y h:i A', strtotime($datetime));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Payouts - FlexFit Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
</head>
<body class="bg-gray-900 text-white">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="ml-0 lg:ml-64 p-4 sm:p-6 transition-all duration-200">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold">Manage Payouts</h1>
            <a href="payout_settings.php" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors duration-200">
        <i class="fas fa-cog mr-2"></i> Auto-Payout Settings
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
        
        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div class="bg-gray-800 rounded-xl shadow-lg p-4">
                <div class="flex items-center justify-between">
                    <div>
                    <p class="text-gray-400 text-sm">Total Requests</p>
                        <h3 class="text-2xl font-bold"><?= number_format($stats['total_requests']) ?></h3>
                    </div>
                    <div class="bg-blue-900 bg-opacity-50 p-3 rounded-full">
                        <i class="fas fa-money-bill-wave text-blue-400 text-xl"></i>
                    </div>
                </div>
            </div>
            
            <div class="bg-gray-800 rounded-xl shadow-lg p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-400 text-sm">Pending Requests</p>
                        <h3 class="text-2xl font-bold"><?= number_format($stats['pending_requests']) ?></h3>
                        <p class="text-sm text-gray-400">
                            <span class="text-yellow-400">₹<?= number_format($stats['pending_amount'], 2) ?></span> pending
                        </p>
                    </div>
                    <div class="bg-yellow-900 bg-opacity-50 p-3 rounded-full">
                        <i class="fas fa-clock text-yellow-400 text-xl"></i>
                    </div>
                </div>
            </div>
            
            <div class="bg-gray-800 rounded-xl shadow-lg p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-400 text-sm">Completed Payouts</p>
                        <h3 class="text-2xl font-bold"><?= number_format($stats['completed_requests']) ?></h3>
                        <p class="text-sm text-gray-400">
                            <span class="text-green-400">₹<?= number_format($stats['total_paid_amount'], 2) ?></span> paid
                        </p>
                    </div>
                    <div class="bg-green-900 bg-opacity-50 p-3 rounded-full">
                        <i class="fas fa-check-circle text-green-400 text-xl"></i>
                    </div>
                </div>
            </div>
            
            <div class="bg-gray-800 rounded-xl shadow-lg p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-400 text-sm">Failed Requests</p>
                        <h3 class="text-2xl font-bold"><?= number_format($stats['failed_requests']) ?></h3>
                    </div>
                    <div class="bg-red-900 bg-opacity-50 p-3 rounded-full">
                        <i class="fas fa-times-circle text-red-400 text-xl"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="bg-gray-800 rounded-xl shadow-lg p-6 mb-6">
            <form action="" method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
                <div>
                    <label for="status" class="block text-sm font-medium text-gray-400 mb-1">Status</label>
                    <select id="status" name="status" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                        <option value="">All Statuses</option>
                        <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="failed" <?= $status === 'failed' ? 'selected' : '' ?>>Failed</option>
                    </select>
                </div>
                
                <div>
                    <label for="gym_id" class="block text-sm font-medium text-gray-400 mb-1">Gym</label>
                    <select id="gym_id" name="gym_id" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                        <option value="">All Gyms</option>
                        <?php foreach ($gyms as $gym): ?>
                            <option value="<?= $gym['gym_id'] ?>" <?= $gym_id == $gym['gym_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($gym['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label for="date_from" class="block text-sm font-medium text-gray-400 mb-1">Date From</label>
                    <input type="text" id="date_from" name="date_from" value="<?= htmlspecialchars($date_from) ?>" class="datepicker w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500" placeholder="Select date">
                </div>
                
                <div>
                    <label for="date_to" class="block text-sm font-medium text-gray-400 mb-1">Date To</label>
                    <input type="text" id="date_to" name="date_to" value="<?= htmlspecialchars($date_to) ?>" class="datepicker w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500" placeholder="Select date">
                </div>
                
                <div class="flex items-end">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg w-full">
                        <i class="fas fa-filter mr-2"></i> Apply Filters
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Payouts Table -->
        <div class="bg-gray-800 rounded-xl shadow-lg overflow-hidden">
            <div class="p-6 border-b border-gray-700">
                <div class="flex justify-between items-center">
                    <h2 class="text-xl font-semibold">Payout Requests</h2>
                    <p class="text-gray-400">
                        Total: <?= number_format($total_payouts) ?> requests
                    </p>
                </div>
            </div>
            
            <?php if (empty($payouts)): ?>
                <div class="p-6 text-center text-gray-500">
                    <i class="fas fa-money-bill-wave text-4xl mb-3"></i>
                    <p>No payout requests found matching your criteria.</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-700">
                        <thead>
                            <tr class="bg-gray-700">
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">ID</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Gym</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Amount</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Payment Method</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Requested</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Processed</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-gray-800 divide-y divide-gray-700">
                            <?php foreach ($payouts as $payout): ?>
                                <tr class="hover:bg-gray-700">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-300">
                                        #<?= $payout['id'] ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-white"><?= htmlspecialchars($payout['gym_name']) ?></div>
                                        <div class="text-sm text-gray-400"><?= htmlspecialchars($payout['city']) ?>, <?= htmlspecialchars($payout['state']) ?></div>
                                        <div class="text-xs text-gray-500">Owner: <?= htmlspecialchars($payout['owner_name']) ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-white">₹<?= number_format($payout['amount'], 2) ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php if ($payout['method_type'] === 'bank'): ?>
                                            <div class="text-sm text-white">Bank Transfer</div>
                                            <div class="text-xs text-gray-400"><?= htmlspecialchars($payout['bank_name']) ?></div>
                                            <div class="text-xs text-gray-400">A/C: <?= htmlspecialchars(substr($payout['account_number'], 0, 4) . '******' . substr($payout['account_number'], -4)) ?></div>
                                            <div class="text-xs text-gray-400">IFSC: <?= htmlspecialchars($payout['ifsc_code']) ?></div>
                                        <?php elseif ($payout['method_type'] === 'upi'): ?>
                                            <div class="text-sm text-white">UPI Transfer</div>
                                            <div class="text-xs text-gray-400">ID: <?= htmlspecialchars($payout['upi_id']) ?></div>
                                        <?php else: ?>
                                            <div class="text-sm text-gray-400">Not specified</div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php
                                            $statusClass = '';
                                            $statusText = '';
                                            
                                            switch ($payout['status']) {
                                                case 'pending':
                                                    $statusClass = 'bg-yellow-900 text-yellow-300';
                                                    $statusText = 'Pending';
                                                    break;
                                                case 'completed':
                                                    $statusClass = 'bg-green-900 text-green-300';
                                                    $statusText = 'Completed';
                                                    break;
                                                case 'failed':
                                                    $statusClass = 'bg-red-900 text-red-300';
                                                    $statusText = 'Failed';
                                                    break;
                                                default:
                                                    $statusClass = 'bg-gray-700 text-gray-300';
                                                    $statusText = 'Unknown';
                                            }
                                        ?>
                                        <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?= $statusClass ?>">
                                            <?= $statusText ?>
                                        </span>
                                        
                                        <?php if ($payout['status'] === 'completed' && !empty($payout['transaction_id'])): ?>
                                            <div class="text-xs text-gray-400 mt-1">
                                                Txn: <?= htmlspecialchars($payout['transaction_id']) ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($payout['status'] === 'completed' && !empty($payout['admin_name'])): ?>
                                            <div class="text-xs text-gray-400 mt-1">
                                                By: <?= htmlspecialchars($payout['admin_name']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-300">
                                        <?= formatDateTime($payout['created_at']) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-300">
                                        <?= !empty($payout['processed_at']) ? formatDateTime($payout['processed_at']) : 'N/A' ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <div class="flex space-x-2">
                                            <button class="view-details-btn text-blue-400 hover:text-blue-300" title="View Details" data-id="<?= $payout['id'] ?>">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            
                                            <?php if ($payout['status'] === 'pending'): ?>
                                                <button class="process-payout-btn text-green-400 hover:text-green-300" title="Process Payout" data-id="<?= $payout['id'] ?>" data-amount="<?= $payout['amount'] ?>" data-gym="<?= htmlspecialchars($payout['gym_name']) ?>">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                                
                                                <button class="reject-payout-btn text-red-400 hover:text-red-300" title="Reject Payout" data-id="<?= $payout['id'] ?>" data-amount="<?= $payout['amount'] ?>" data-gym="<?= htmlspecialchars($payout['gym_name']) ?>">
                                                    <i class="fas fa-times"></i>
                                                </button>
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
                            Showing <?= ($page - 1) * $per_page + 1 ?> to <?= min($page * $per_page, $total_payouts) ?> of <?= $total_payouts ?> payouts
                        </div>
                        <div class="flex space-x-1">
                            <?php if ($page > 1): ?>
                                <a href="?page=1&status=<?= urlencode($status) ?>&gym_id=<?= $gym_id ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&sort=<?= urlencode($sort) ?>&order=<?= urlencode($order) ?>" class="px-3 py-1 rounded-md bg-gray-600 text-white hover:bg-gray-500">
                                    <i class="fas fa-angle-double-left"></i>
                                </a>
                                <a href="?page=<?= $page - 1 ?>&status=<?= urlencode($status) ?>&gym_id=<?= $gym_id ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&sort=<?= urlencode($sort) ?>&order=<?= urlencode($order) ?>" class="px-3 py-1 rounded-md bg-gray-600 text-white hover:bg-gray-500">
                                    <i class="fas fa-angle-left"></i>
                                </a>
                            <?php endif; ?>
                            
                            <?php
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);
                            
                            for ($i = $start_page; $i <= $end_page; $i++):
                            ?>
                                <a href="?page=<?= $i ?>&status=<?= urlencode($status) ?>&gym_id=<?= $gym_id ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&sort=<?= urlencode($sort) ?>&order=<?= urlencode($order) ?>" class="px-3 py-1 rounded-md <?= $i === $page ? 'bg-blue-600 text-white' : 'bg-gray-600 text-white hover:bg-gray-500' ?>">
                                    <?= $i ?>
                                </a>
                            <?php endfor; ?>
                            
                            <?php if ($page < $total_pages): ?>
                                <a href="?page=<?= $page + 1 ?>&status=<?= urlencode($status) ?>&gym_id=<?= $gym_id ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&sort=<?= urlencode($sort) ?>&order=<?= urlencode($order) ?>" class="px-3 py-1 rounded-md bg-gray-600 text-white hover:bg-gray-500">
                                    <i class="fas fa-angle-right"></i>
                                </a>
                                <a href="?page=<?= $total_pages ?>&status=<?= urlencode($status) ?>&gym_id=<?= $gym_id ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&sort=<?= urlencode($sort) ?>&order=<?= urlencode($order) ?>" class="px-3 py-1 rounded-md bg-gray-600 text-white hover:bg-gray-500">
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
    
    <!-- View Details Modal -->
    <div id="viewDetailsModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center hidden">
        <div class="bg-gray-800 rounded-xl shadow-lg w-full max-w-2xl mx-4">
            <div class="p-6 border-b border-gray-700 flex justify-between items-center">
                <h3 class="text-xl font-semibold">Payout Request Details</h3>
                <button id="closeViewDetailsModal" class="text-gray-400 hover:text-white">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <div class="p-6" id="payoutDetailsContent">
                <!-- Content will be loaded dynamically -->
                <div class="animate-pulse">
                    <div class="h-4 bg-gray-700 rounded w-3/4 mb-4"></div>
                    <div class="h-4 bg-gray-700 rounded w-1/2 mb-4"></div>
                    <div class="h-4 bg-gray-700 rounded w-5/6 mb-4"></div>
                    <div class="h-4 bg-gray-700 rounded w-2/3 mb-4"></div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Process Payout Modal -->
    <div id="processPayoutModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center hidden">
        <div class="bg-gray-800 rounded-xl shadow-lg w-full max-w-md mx-4">
            <div class="p-6 border-b border-gray-700 flex justify-between items-center">
                <h3 class="text-xl font-semibold">Process Payout</h3>
                <button id="closeProcessPayoutModal" class="text-gray-400 hover:text-white">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form action="" method="POST" class="p-6">
                <input type="hidden" id="process_withdrawal_id" name="withdrawal_id" value="">
                
                <div class="mb-4">
                    <div class="bg-gray-700 p-4 rounded-lg mb-4">
                        <div class="text-sm text-gray-400">Gym</div>
                        <div class="text-lg font-medium" id="process_gym_name">Loading...</div>
                        
                        <div class="text-sm text-gray-400 mt-2">Amount</div>
                        <div class="text-xl font-bold text-green-400" id="process_amount">₹0.00</div>
                    </div>
                    
                    <div class="mb-4">
                        <label for="transaction_id" class="block text-sm font-medium text-gray-400 mb-1">Transaction ID / Reference Number *</label>
                        <input type="text" id="transaction_id" name="transaction_id" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500" required>
                        <p class="text-xs text-gray-500 mt-1">Enter the transaction ID or reference number from your payment gateway/bank.</p>
                    </div>
                    
                    <div class="mb-4">
                        <label for="notes" class="block text-sm font-medium text-gray-400 mb-1">Notes (Optional)</label>
                        <textarea id="notes" name="notes" rows="3" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500"></textarea>
                    </div>
                </div>
                
                <div class="flex justify-end space-x-3">
                    <button type="button" id="cancelProcessPayout" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg">
                        Cancel
                    </button>
                    <button type="submit" name="process_payout" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-check mr-2"></i> Confirm Payout
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Reject Payout Modal -->
    <div id="rejectPayoutModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center hidden">
        <div class="bg-gray-800 rounded-xl shadow-lg w-full max-w-md mx-4">
            <div class="p-6 border-b border-gray-700 flex justify-between items-center">
                <h3 class="text-xl font-semibold">Reject Payout</h3>
                <button id="closeRejectPayoutModal" class="text-gray-400 hover:text-white">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form action="" method="POST" class="p-6">
                <input type="hidden" id="reject_withdrawal_id" name="withdrawal_id" value="">
                
                <div class="mb-4">
                    <div class="bg-gray-700 p-4 rounded-lg mb-4">
                        <div class="text-sm text-gray-400">Gym</div>
                        <div class="text-lg font-medium" id="reject_gym_name">Loading...</div>
                        
                        <div class="text-sm text-gray-400 mt-2">Amount</div>
                        <div class="text-xl font-bold text-red-400" id="reject_amount">₹0.00</div>
                    </div>
                    
                    <div class="bg-red-900 bg-opacity-50 p-4 rounded-lg mb-4">
                        <div class="flex items-start">
                            <i class="fas fa-exclamation-triangle text-red-400 mt-1 mr-3"></i>
                            <div>
                                <p class="text-white font-medium">Are you sure you want to reject this payout?</p>
                                <p class="text-red-300 text-sm mt-1">The amount will be returned to the gym's balance.</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label for="reason" class="block text-sm font-medium text-gray-400 mb-1">Rejection Reason *</label>
                        <textarea id="reason" name="reason" rows="3" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500" required></textarea>
                        <p class="text-xs text-gray-500 mt-1">Please provide a reason for rejecting this payout request.</p>
                    </div>
                </div>
                
                <div class="flex justify-end space-x-3">
                    <button type="button" id="cancelRejectPayout" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg">
                        Cancel
                    </button>
                    <button type="submit" name="reject_payout" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-times mr-2"></i> Reject Payout
                    </button>
                </div>
            </form>
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
        
        // View Details Modal
        const viewDetailsModal = document.getElementById('viewDetailsModal');
        const closeViewDetailsModal = document.getElementById('closeViewDetailsModal');
        const payoutDetailsContent = document.getElementById('payoutDetailsContent');
        
        document.querySelectorAll('.view-details-btn').forEach(button => {
            button.addEventListener('click', function() {
                const payoutId = this.getAttribute('data-id');
                
                // Show loading state
                payoutDetailsContent.innerHTML = `
                    <div class="animate-pulse">
                        <div class="h-4 bg-gray-700 rounded w-3/4 mb-4"></div>
                        <div class="h-4 bg-gray-700 rounded w-1/2 mb-4"></div>
                        <div class="h-4 bg-gray-700 rounded w-5/6 mb-4"></div>
                        <div class="h-4 bg-gray-700 rounded w-2/3 mb-4"></div>
                    </div>
                `;
                
                // Show modal
                viewDetailsModal.classList.remove('hidden');
                document.body.classList.add('overflow-hidden');
                
                // Fetch payout details
                fetch(`ajax_get_payout_details.php?id=${payoutId}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            const payout = data.payout;
                            
                            // Format status
                            let statusClass = '';
                            let statusText = '';
                            
                            switch (payout.status) {
                                case 'pending':
                                    statusClass = 'bg-yellow-900 text-yellow-300';
                                    statusText = 'Pending';
                                    break;
                                case 'completed':
                                    statusClass = 'bg-green-900 text-green-300';
                                    statusText = 'Completed';
                                    break;
                                case 'failed':
                                    statusClass = 'bg-red-900 text-red-300';
                                    statusText = 'Failed';
                                    break;
                                default:
                                    statusClass = 'bg-gray-700 text-gray-300';
                                    statusText = 'Unknown';
                            }
                            
                            // Format payment method
                            let paymentMethodHtml = '';
                            
                            if (payout.method_type === 'bank') {
                                paymentMethodHtml = `
                                    <div class="mb-4">
                                        <h4 class="text-sm font-medium text-gray-400 mb-2">Bank Account Details</h4>
                                        <div class="bg-gray-700 p-4 rounded-lg">
                                            <div class="grid grid-cols-2 gap-4">
                                                                                               <div>
                                                    <div class="text-xs text-gray-400">Account Name</div>
                                                    <div class="text-sm">${payout.account_name || 'N/A'}</div>
                                                </div>
                                                <div>
                                                    <div class="text-xs text-gray-400">Bank Name</div>
                                                    <div class="text-sm">${payout.bank_name || 'N/A'}</div>
                                                </div>
                                                <div>
                                                    <div class="text-xs text-gray-400">Account Number</div>
                                                    <div class="text-sm">${payout.account_number || 'N/A'}</div>
                                                </div>
                                                <div>
                                                    <div class="text-xs text-gray-400">IFSC Code</div>
                                                    <div class="text-sm">${payout.ifsc_code || 'N/A'}</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                `;
                            } else if (payout.method_type === 'upi') {
                                paymentMethodHtml = `
                                    <div class="mb-4">
                                        <h4 class="text-sm font-medium text-gray-400 mb-2">UPI Details</h4>
                                        <div class="bg-gray-700 p-4 rounded-lg">
                                            <div>
                                                <div class="text-xs text-gray-400">UPI ID</div>
                                                <div class="text-sm">${payout.upi_id || 'N/A'}</div>
                                            </div>
                                        </div>
                                    </div>
                                `;
                            } else {
                                paymentMethodHtml = `
                                    <div class="mb-4">
                                        <h4 class="text-sm font-medium text-gray-400 mb-2">Payment Method</h4>
                                        <div class="bg-gray-700 p-4 rounded-lg">
                                            <div class="text-sm">Not specified</div>
                                        </div>
                                    </div>
                                `;
                            }
                            
                            // Build HTML
                            payoutDetailsContent.innerHTML = `
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div>
                                        <h4 class="text-sm font-medium text-gray-400 mb-2">Payout Information</h4>
                                        <div class="bg-gray-700 p-4 rounded-lg">
                                            <div class="mb-3">
                                                <div class="text-xs text-gray-400">Payout ID</div>
                                                <div class="text-sm">#${payout.id}</div>
                                            </div>
                                            <div class="mb-3">
                                                <div class="text-xs text-gray-400">Amount</div>
                                                <div class="text-lg font-bold">₹${parseFloat(payout.amount).toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</div>
                                            </div>
                                            <div class="mb-3">
                                                <div class="text-xs text-gray-400">Status</div>
                                                <div class="mt-1">
                                                    <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full ${statusClass}">
                                                        ${statusText}
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="mb-3">
                                                <div class="text-xs text-gray-400">Requested On</div>
                                                <div class="text-sm">${new Date(payout.created_at).toLocaleString()}</div>
                                            </div>
                                            ${payout.processed_at ? `
                                                <div>
                                                    <div class="text-xs text-gray-400">Processed On</div>
                                                    <div class="text-sm">${new Date(payout.processed_at).toLocaleString()}</div>
                                                </div>
                                            ` : ''}
                                        </div>
                                    </div>
                                    
                                    <div>
                                        <h4 class="text-sm font-medium text-gray-400 mb-2">Gym Information</h4>
                                        <div class="bg-gray-700 p-4 rounded-lg">
                                            <div class="mb-3">
                                                <div class="text-xs text-gray-400">Gym Name</div>
                                                <div class="text-sm">${payout.gym_name}</div>
                                            </div>
                                            <div class="mb-3">
                                                <div class="text-xs text-gray-400">Location</div>
                                                <div class="text-sm">${payout.city}, ${payout.state}</div>
                                            </div>
                                            <div>
                                                <div class="text-xs text-gray-400">Owner</div>
                                                <div class="text-sm">${payout.owner_name}</div>
                                                <div class="text-xs text-gray-500">${payout.owner_email}</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                ${paymentMethodHtml}
                                
                                ${payout.transaction_id ? `
                                    <div class="mb-4">
                                        <h4 class="text-sm font-medium text-gray-400 mb-2">Transaction Details</h4>
                                        <div class="bg-gray-700 p-4 rounded-lg">
                                            <div class="mb-3">
                                                <div class="text-xs text-gray-400">Transaction ID</div>
                                                <div class="text-sm">${payout.transaction_id}</div>
                                            </div>
                                            ${payout.admin_name ? `
                                                <div>
                                                    <div class="text-xs text-gray-400">Processed By</div>
                                                    <div class="text-sm">${payout.admin_name}</div>
                                                </div>
                                            ` : ''}
                                        </div>
                                    </div>
                                ` : ''}
                                
                                ${payout.notes ? `
                                    <div>
                                        <h4 class="text-sm font-medium text-gray-400 mb-2">Notes</h4>
                                        <div class="bg-gray-700 p-4 rounded-lg">
                                            <div class="text-sm">${payout.notes}</div>
                                        </div>
                                    </div>
                                ` : ''}
                            `;
                        } else {
                            payoutDetailsContent.innerHTML = `
                                <div class="text-center text-red-400">
                                    <i class="fas fa-exclamation-circle text-3xl mb-2"></i>
                                    <p>Error loading payout details.</p>
                                </div>
                            `;
                        }
                    })
                    .catch(error => {
                        payoutDetailsContent.innerHTML = `
                            <div class="text-center text-red-400">
                                <i class="fas fa-exclamation-circle text-3xl mb-2"></i>
                                <p>Error loading payout details.</p>
                            </div>
                        `;
                        console.error('Error:', error);
                    });
            });
        });
        
        closeViewDetailsModal.addEventListener('click', () => {
            viewDetailsModal.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        });
        
        // Process Payout Modal
        const processPayoutModal = document.getElementById('processPayoutModal');
        const closeProcessPayoutModal = document.getElementById('closeProcessPayoutModal');
        const cancelProcessPayout = document.getElementById('cancelProcessPayout');
        const processWithdrawalId = document.getElementById('process_withdrawal_id');
        const processGymName = document.getElementById('process_gym_name');
        const processAmount = document.getElementById('process_amount');
        
        document.querySelectorAll('.process-payout-btn').forEach(button => {
            button.addEventListener('click', function() {
                const payoutId = this.getAttribute('data-id');
                const gymName = this.getAttribute('data-gym');
                const amount = this.getAttribute('data-amount');
                
                processWithdrawalId.value = payoutId;
                processGymName.textContent = gymName;
                processAmount.textContent = `₹${parseFloat(amount).toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
                
                processPayoutModal.classList.remove('hidden');
                document.body.classList.add('overflow-hidden');
            });
        });
        
        const closeProcessModal = () => {
            processPayoutModal.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        };
        
        closeProcessPayoutModal.addEventListener('click', closeProcessModal);
        cancelProcessPayout.addEventListener('click', closeProcessModal);
        
        // Reject Payout Modal
        const rejectPayoutModal = document.getElementById('rejectPayoutModal');
        const closeRejectPayoutModal = document.getElementById('closeRejectPayoutModal');
        const cancelRejectPayout = document.getElementById('cancelRejectPayout');
        const rejectWithdrawalId = document.getElementById('reject_withdrawal_id');
        const rejectGymName = document.getElementById('reject_gym_name');
        const rejectAmount = document.getElementById('reject_amount');
        
        document.querySelectorAll('.reject-payout-btn').forEach(button => {
            button.addEventListener('click', function() {
                const payoutId = this.getAttribute('data-id');
                const gymName = this.getAttribute('data-gym');
                const amount = this.getAttribute('data-amount');
                
                rejectWithdrawalId.value = payoutId;
                rejectGymName.textContent = gymName;
                rejectAmount.textContent = `₹${parseFloat(amount).toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
                
                rejectPayoutModal.classList.remove('hidden');
                document.body.classList.add('overflow-hidden');
            });
        });
        
        const closeRejectModal = () => {
            rejectPayoutModal.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        };
        
        closeRejectPayoutModal.addEventListener('click', closeRejectModal);
        cancelRejectPayout.addEventListener('click', closeRejectModal);
        
        // Close modals when clicking outside
        viewDetailsModal.addEventListener('click', (e) => {
            if (e.target === viewDetailsModal) {
                viewDetailsModal.classList.add('hidden');
                document.body.classList.remove('overflow-hidden');
            }
        });
        
        processPayoutModal.addEventListener('click', (e) => {
            if (e.target === processPayoutModal) {
                closeProcessModal();
            }
        });
        
        rejectPayoutModal.addEventListener('click', (e) => {
            if (e.target === rejectPayoutModal) {
                closeRejectModal();
            }
        });
    </script>
</body>
</html>


