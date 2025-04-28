<?php
session_start();
require_once '../config/database.php';
require_once '../includes/PaymentGateway.php';

if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ./login.php');
    exit();
}

$db = new GymDatabase();
$conn = $db->getConnection();

// Process a withdrawal if action is taken
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['process_withdrawal'])) {
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
                    $stmt->execute([$transaction_id, $notes, $_SESSION['admin_id'], $withdrawal_id]);
                    
                    // Log the activity
                    $stmt = $conn->prepare("
                        INSERT INTO activity_logs (
                            user_id, user_type, action, details, ip_address, user_agent
                        ) VALUES (?, 'admin', 'process_withdrawal', ?, ?, ?)
                    ");
                    
                    $details = "Processed payout of ₹" . number_format($withdrawal['amount'], 2) . 
                               " for gym: " . $withdrawal['gym_name'] . " (ID: " . $withdrawal['gym_id'] . ")";
                    
                    $stmt->execute([
                        $_SESSION['admin_id'],
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
                } else {
                    $conn->rollBack();
                    $_SESSION['error'] = "Invalid withdrawal request or already processed.";
                }
            } catch (PDOException $e) {
                $conn->rollBack();
                $_SESSION['error'] = "Database error: " . $e->getMessage();
            }
        }
    } elseif (isset($_POST['reject_withdrawal'])) {
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
                    $stmt->execute([$reason, $_SESSION['admin_id'], $withdrawal_id]);
                    
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
                        ) VALUES (?, 'admin', 'reject_withdrawal', ?, ?, ?)
                    ");
                    
                    $details = "Rejected payout of ₹" . number_format($withdrawal['amount'], 2) . 
                               " for gym: " . $withdrawal['gym_name'] . " (ID: " . $withdrawal['gym_id'] . 
                               "). Reason: " . $reason;
                    
                    $stmt->execute([
                        $_SESSION['admin_id'],
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
                } else {
                    $conn->rollBack();
                    $_SESSION['error'] = "Invalid withdrawal request or already processed.";
                }
            } catch (PDOException $e) {
                $conn->rollBack();
                $_SESSION['error'] = "Database error: " . $e->getMessage();
            }
        }
    } elseif (isset($_POST['process_all'])) {
        // Process all pending withdrawals
        try {
            $conn->beginTransaction();
            
            // Get all pending withdrawals
            $stmt = $conn->prepare("
                SELECT w.*, g.name as gym_name, pm.method_type, pm.account_name, pm.bank_name, 
                       pm.account_number, pm.ifsc_code, pm.upi_id
                FROM withdrawals w
                JOIN gyms g ON w.gym_id = g.gym_id
                LEFT JOIN payment_methods pm ON w.payment_method_id = pm.id
                WHERE w.status = 'pending'
                ORDER BY w.created_at ASC
            ");
            $stmt->execute();
            $withdrawals = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $processed = 0;
            $failed = 0;
            
            foreach ($withdrawals as $withdrawal) {
                // Generate a transaction ID
                $transaction_id = 'BATCH-' . date('YmdHis') . '-' . $withdrawal['id'];
                
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
                $stmt->execute([
                    $transaction_id, 
                    'Batch processed by admin', 
                    $_SESSION['admin_id'], 
                    $withdrawal['id']
                ]);
                
                // Log the activity
                $stmt = $conn->prepare("
                    INSERT INTO activity_logs (
                        user_id, user_type, action, details, ip_address, user_agent
                    ) VALUES (?, 'admin', 'batch_process_withdrawal', ?, ?, ?)
                ");
                
                $details = "Batch processed payout of ₹" . number_format($withdrawal['amount'], 2) . 
                           " for gym: " . $withdrawal['gym_name'] . " (ID: " . $withdrawal['gym_id'] . ")";
                
                $stmt->execute([
                    $_SESSION['admin_id'],
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
                
                $processed++;
            }
            
            $conn->commit();
            $_SESSION['success'] = "Batch processing complete. Processed $processed payouts.";
            
        } catch (PDOException $e) {
            $conn->rollBack();
            $_SESSION['error'] = "Database error: " . $e->getMessage();
        }
    }
    
    // Redirect to avoid form resubmission
    header("Location: manage_withdrawals.php");
    exit();
}

// Get all withdrawal requests with payment method details
$withdrawalQuery = "
    SELECT w.*, g.name as gym_name, pm.method_type, pm.account_name, pm.bank_name, 
           pm.account_number, pm.ifsc_code, pm.upi_id, u.username as owner_name
    FROM withdrawals w
    JOIN gyms g ON w.gym_id = g.gym_id
    JOIN users u ON g.owner_id = u.id
    LEFT JOIN payment_methods pm ON w.payment_method_id = pm.id
    ORDER BY w.created_at DESC";

$stmt = $conn->prepare($withdrawalQuery);
$stmt->execute();
$withdrawals = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count pending withdrawals
$pendingCount = 0;
$pendingAmount = 0;
foreach ($withdrawals as $withdrawal) {
    if ($withdrawal['status'] === 'pending') {
        $pendingCount++;
        $pendingAmount += $withdrawal['amount'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Withdrawal Management - FlexFit Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body class="bg-gray-900 text-white">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="ml-0 lg:ml-64 p-4 sm:p-6 transition-all duration-200">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold">Withdrawal Management</h1>
            <?php if ($pendingCount > 0): ?>
                <button onclick="confirmProcessAll()" class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded-lg">
                    <i class="fas fa-money-bill-wave mr-2"></i> Process All Pending (<?= $pendingCount ?>)
                </button>
            <?php endif; ?>
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
        
        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="bg-gray-800 rounded-xl shadow-lg p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-400 text-sm">Pending Withdrawals</p>
                        <h3 class="text-2xl font-bold"><?= $pendingCount ?></h3>
                    </div>
                    <div class="bg-yellow-900 bg-opacity-50 p-3 rounded-full">
                        <i class="fas fa-clock text-yellow-400 text-xl"></i>
                    </div>
                </div>
            </div>
            
            <div class="bg-gray-800 rounded-xl shadow-lg p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-400 text-sm">Pending Amount</p>
                        <h3 class="text-2xl font-bold">₹<?= number_format($pendingAmount, 2) ?></h3>
                    </div>
                    <div class="bg-blue-900 bg-opacity-50 p-3 rounded-full">
                        <i class="fas fa-rupee-sign text-blue-400 text-xl"></i>
                    </div>
                </div>
            </div>
            
            <div class="bg-gray-800 rounded-xl shadow-lg p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-400 text-sm">Total Withdrawals</p>
                        <h3 class="text-2xl font-bold"><?= count($withdrawals) ?></h3>
                    </div>
                    <div class="bg-purple-900 bg-opacity-50 p-3 rounded-full">
                        <i class="fas fa-money-bill-wave text-purple-400 text-xl"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Withdrawals Table -->
        <div class="bg-gray-800 rounded-xl shadow-lg overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-700">
                    <thead>
                        <tr class="bg-gray-700">
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Gym</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Amount</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Payment Method</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Requested On</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-gray-800 divide-y divide-gray-700">
                        <?php if (empty($withdrawals)): ?>
                            <tr>
                                <td colspan="6" class="px-6 py-4 text-center text-gray-500">No withdrawal requests found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($withdrawals as $withdrawal): ?>
                                <tr class="hover:bg-gray-700">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-white"><?= htmlspecialchars($withdrawal['gym_name']) ?></div>
                                        <div class="text-xs text-gray-400">Owner: <?= htmlspecialchars($withdrawal['owner_name']) ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-white">₹<?= number_format($withdrawal['amount'], 2) ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php if ($withdrawal['method_type'] === 'bank'): ?>
                                            <div class="text-sm text-white">Bank Transfer</div>
                                            <div class="text-xs text-gray-400"><?= htmlspecialchars($withdrawal['bank_name']) ?></div>
                                            <div class="text-xs text-gray-400">A/C: <?= htmlspecialchars(substr($withdrawal['account_number'], 0, 4) . '******' . substr($withdrawal['account_number'], -4)) ?></div>
                                            <div class="text-xs text-gray-400">IFSC: <?= htmlspecialchars($withdrawal['ifsc_code']) ?></div>
                                        <?php elseif ($withdrawal['method_type'] === 'upi'): ?>
                                            <div class="text-sm text-white">UPI Transfer</div>
                                            <div class="text-xs text-gray-400">ID: <?= htmlspecialchars($withdrawal['upi_id']) ?></div>
                                        <?php else: ?>
                                            <div class="text-sm text-gray-400">Not specified</div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php
                                            $statusClass = '';
                                            $statusText = '';
                                            
                                            switch ($withdrawal['status']) {
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
                                        
                                        <?php if ($withdrawal['status'] === 'completed' && !empty($withdrawal['transaction_id'])): ?>
                                            <div class="text-xs text-gray-400 mt-1">
                                                Txn: <?= htmlspecialchars($withdrawal['transaction_id']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-300">
                                        <?= date('M j, Y g:i A', strtotime($withdrawal['created_at'])) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <div class="flex space-x-2">
                                            <button class="view-details-btn text-blue-400 hover:text-blue-300" 
                                                    data-id="<?= $withdrawal['id'] ?>"
                                                    data-gym="<?= htmlspecialchars($withdrawal['gym_name']) ?>"
                                                    data-amount="<?= $withdrawal['amount'] ?>"
                                                    data-method="<?= $withdrawal['method_type'] ?>"
                                                    data-bank="<?= htmlspecialchars($withdrawal['bank_name'] ?? '') ?>"
                                                    data-account="<?= htmlspecialchars($withdrawal['account_name'] ?? '') ?>"
                                                    data-account-number="<?= htmlspecialchars($withdrawal['account_number'] ?? '') ?>"
                                                    data-ifsc="<?= htmlspecialchars($withdrawal['ifsc_code'] ?? '') ?>"
                                                    data-upi="<?= htmlspecialchars($withdrawal['upi_id'] ?? '') ?>"
                                                    data-status="<?= $withdrawal['status'] ?>"
                                                    data-created="<?= date('M j, Y g:i A', strtotime($withdrawal['created_at'])) ?>"
                                                    data-processed="<?= !empty($withdrawal['processed_at']) ? date('M j, Y g:i A', strtotime($withdrawal['processed_at'])) : '' ?>"
                                                    data-txn="<?= htmlspecialchars($withdrawal['transaction_id'] ?? '') ?>"
                                                    data-notes="<?= htmlspecialchars($withdrawal['notes'] ?? '') ?>">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            
                                            <?php if ($withdrawal['status'] === 'pending'): ?>
                                                <button class="process-btn text-green-400 hover:text-green-300" 
                                                        data-id="<?= $withdrawal['id'] ?>"
                                                        data-gym="<?= htmlspecialchars($withdrawal['gym_name']) ?>"
                                                        data-amount="<?= $withdrawal['amount'] ?>"
                                                        data-method="<?= $withdrawal['method_type'] ?>"
                                                        data-bank="<?= htmlspecialchars($withdrawal['bank_name'] ?? '') ?>"
                                                        data-account="<?= htmlspecialchars($withdrawal['account_name'] ?? '') ?>"
                                                        data-account-number="<?= htmlspecialchars($withdrawal['account_number'] ?? '') ?>"
                                                        data-ifsc="<?= htmlspecialchars($withdrawal['ifsc_code'] ?? '') ?>"
                                                        data-upi="<?= htmlspecialchars($withdrawal['upi_id'] ?? '') ?>">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                                
                                                <button class="reject-btn text-red-400 hover:text-red-300" 
                                                        data-id="<?= $withdrawal['id'] ?>"
                                                        data-gym="<?= htmlspecialchars($withdrawal['gym_name']) ?>"
                                                        data-amount="<?= $withdrawal['amount'] ?>">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- View Details Modal -->
    <div id="viewDetailsModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center hidden">
        <div class="bg-gray-800 rounded-xl shadow-lg w-full max-w-2xl mx-4">
            <div class="p-6 border-b border-gray-700 flex justify-between items-center">
                <h3 class="text-xl font-semibold">Withdrawal Details</h3>
                <button id="closeViewDetailsModal" class="text-gray-400 hover:text-white">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <h4 class="text-sm font-medium text-gray-400 mb-2">Withdrawal Information</h4>
                        <div class="bg-gray-700 p-4 rounded-lg">
                            <div class="mb-3">
                                <div class="text-xs text-gray-400">Gym</div>
                                <div class="text-sm" id="view-gym-name"></div>
                            </div>
                            <div class="mb-3">
                                <div class="text-xs text-gray-400">Amount</div>
                                <div class="text-lg font-bold" id="view-amount"></div>
                            </div>
                            <div class="mb-3">
                                <div class="text-xs text-gray-400">Status</div>
                                <div class="mt-1">
                                    <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full" id="view-status-badge"></span>
                                </div>
                            </div>
                            <div class="mb-3">
                                <div class="text-xs text-gray-400">Requested On</div>
                                <div class="text-sm" id="view-created-at"></div>
                            </div>
                            <div id="view-processed-container" class="hidden">
                                <div class="text-xs text-gray-400">Processed On</div>
                                <div class="text-sm" id="view-processed-at"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div>
                        <h4 class="text-sm font-medium text-gray-400 mb-2">Payment Method</h4>
                        <div class="bg-gray-700 p-4 rounded-lg">
                            <div id="view-bank-details">
                                <div class="mb-3">
                                    <div class="text-xs text-gray-400">Method Type</div>
                                    <div class="text-sm" id="view-method-type"></div>
                                </div>
                                <div class="mb-3" id="view-bank-name-container">
                                    <div class="text-xs text-gray-400">Bank Name</div>
                                    <div class="text-sm" id="view-bank-name"></div>
                                </div>
                                <div class="mb-3" id="view-account-name-container">
                                    <div class="text-xs text-gray-400">Account Name</div>
                                    <div class="text-sm" id="view-account-name"></div>
                                </div>
                                <div class="mb-3" id="view-account-number-container">
                                    <div class="text-xs text-gray-400">Account Number</div>
                                    <div class="text-sm" id="view-account-number"></div>
                                </div>
                                <div id="view-ifsc-container">
                                    <div class="text-xs text-gray-400">IFSC Code</div>
                                    <div class="text-sm" id="view-ifsc"></div>
                                </div>
                                <div id="view-upi-container">
                                    <div class="text-xs text-gray-400">UPI ID</div>
                                    <div class="text-sm" id="view-upi"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div id="view-transaction-container" class="mt-6 hidden">
                    <h4 class="text-sm font-medium text-gray-400 mb-2">Transaction Details</h4>
                    <div class="bg-gray-700 p-4 rounded-lg">
                        <div class="mb-3">
                            <div class="text-xs text-gray-400">Transaction ID</div>
                            <div class="text-sm" id="view-transaction-id"></div>
                        </div>
                        <div id="view-notes-container">
                            <div class="text-xs text-gray-400">Notes</div>
                            <div class="text-sm" id="view-notes"></div>
                        </div>
                        </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Process Withdrawal Modal -->
    <div id="processModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center hidden">
        <div class="bg-gray-800 rounded-xl shadow-lg w-full max-w-md mx-4">
            <div class="p-6 border-b border-gray-700 flex justify-between items-center">
                <h3 class="text-xl font-semibold">Process Withdrawal</h3>
                <button id="closeProcessModal" class="text-gray-400 hover:text-white">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form action="" method="POST" class="p-6">
                <input type="hidden" id="process-withdrawal-id" name="withdrawal_id">
                
                <div class="bg-gray-700 p-4 rounded-lg mb-4">
                    <div class="mb-3">
                        <div class="text-xs text-gray-400">Gym</div>
                        <div class="text-sm font-medium" id="process-gym-name"></div>
                    </div>
                    <div class="mb-3">
                        <div class="text-xs text-gray-400">Amount</div>
                        <div class="text-lg font-bold text-green-400" id="process-amount"></div>
                    </div>
                    <div id="process-payment-details">
                        <div class="text-xs text-gray-400 mb-1">Payment Method</div>
                        <div id="process-bank-details" class="hidden">
                            <div class="text-sm">Bank Transfer</div>
                            <div class="text-xs text-gray-400" id="process-bank-name"></div>
                            <div class="text-xs text-gray-400" id="process-account-name"></div>
                            <div class="text-xs text-gray-400" id="process-account-number"></div>
                            <div class="text-xs text-gray-400" id="process-ifsc"></div>
                        </div>
                        <div id="process-upi-details" class="hidden">
                            <div class="text-sm">UPI Transfer</div>
                            <div class="text-xs text-gray-400" id="process-upi"></div>
                        </div>
                    </div>
                </div>
                
                <div class="mb-4">
                    <label for="transaction_id" class="block text-sm font-medium text-gray-400 mb-1">Transaction ID / Reference Number *</label>
                    <input type="text" id="transaction_id" name="transaction_id" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                    <p class="text-xs text-gray-500 mt-1">Enter the transaction ID or reference number from your payment gateway/bank.</p>
                </div>
                
                <div class="mb-4">
                    <label for="notes" class="block text-sm font-medium text-gray-400 mb-1">Notes (Optional)</label>
                    <textarea id="notes" name="notes" rows="3" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500"></textarea>
                </div>
                
                <div class="flex justify-end space-x-3">
                    <button type="button" id="cancelProcess" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg">
                        Cancel
                    </button>
                    <button type="submit" name="process_withdrawal" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-check mr-2"></i> Confirm Payment
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Reject Withdrawal Modal -->
    <div id="rejectModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center hidden">
        <div class="bg-gray-800 rounded-xl shadow-lg w-full max-w-md mx-4">
            <div class="p-6 border-b border-gray-700 flex justify-between items-center">
                <h3 class="text-xl font-semibold">Reject Withdrawal</h3>
                <button id="closeRejectModal" class="text-gray-400 hover:text-white">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form action="" method="POST" class="p-6">
                <input type="hidden" id="reject-withdrawal-id" name="withdrawal_id">
                
                <div class="bg-gray-700 p-4 rounded-lg mb-4">
                    <div class="mb-3">
                        <div class="text-xs text-gray-400">Gym</div>
                        <div class="text-sm font-medium" id="reject-gym-name"></div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-400">Amount</div>
                        <div class="text-lg font-bold text-red-400" id="reject-amount"></div>
                    </div>
                </div>
                
                <div class="bg-red-900 bg-opacity-50 p-4 rounded-lg mb-4">
                    <div class="flex items-start">
                        <i class="fas fa-exclamation-triangle text-red-400 mt-1 mr-3"></i>
                        <div>
                            <p class="text-white font-medium">Are you sure you want to reject this withdrawal?</p>
                            <p class="text-red-300 text-sm mt-1">The amount will be returned to the gym's balance.</p>
                        </div>
                    </div>
                </div>
                
                <div class="mb-4">
                    <label for="reason" class="block text-sm font-medium text-gray-400 mb-1">Rejection Reason *</label>
                    <textarea id="reason" name="reason" rows="3" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500"></textarea>
                </div>
                
                <div class="flex justify-end space-x-3">
                    <button type="button" id="cancelReject" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg">
                        Cancel
                    </button>
                    <button type="submit" name="reject_withdrawal" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-times mr-2"></i> Reject Withdrawal
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Process All Confirmation Modal -->
    <div id="processAllModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center hidden">
        <div class="bg-gray-800 rounded-xl shadow-lg w-full max-w-md mx-4">
            <div class="p-6 border-b border-gray-700 flex justify-between items-center">
                <h3 class="text-xl font-semibold">Process All Withdrawals</h3>
                <button id="closeProcessAllModal" class="text-gray-400 hover:text-white">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form action="" method="POST" class="p-6">
                <div class="bg-blue-900 bg-opacity-50 p-4 rounded-lg mb-4">
                    <div class="flex items-start">
                        <i class="fas fa-info-circle text-blue-400 mt-1 mr-3"></i>
                        <div>
                            <p class="text-white font-medium">Process All Pending Withdrawals</p>
                            <p class="text-blue-300 text-sm mt-1">
                                You are about to process <?= $pendingCount ?> pending withdrawals totaling ₹<?= number_format($pendingAmount, 2) ?>.
                                This action cannot be undone.
                            </p>
                        </div>
                    </div>
                </div>
                
                <div class="flex justify-end space-x-3">
                    <button type="button" id="cancelProcessAll" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg">
                        Cancel
                    </button>
                    <button type="submit" name="process_all" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-check mr-2"></i> Confirm Process All
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // View Details Modal
        const viewDetailsModal = document.getElementById('viewDetailsModal');
        const closeViewDetailsModal = document.getElementById('closeViewDetailsModal');
        
        document.querySelectorAll('.view-details-btn').forEach(button => {
            button.addEventListener('click', function() {
                // Get data from button attributes
                const id = this.getAttribute('data-id');
                const gym = this.getAttribute('data-gym');
                const amount = parseFloat(this.getAttribute('data-amount'));
                const method = this.getAttribute('data-method');
                const bank = this.getAttribute('data-bank');
                const account = this.getAttribute('data-account');
                const accountNumber = this.getAttribute('data-account-number');
                const ifsc = this.getAttribute('data-ifsc');
                const upi = this.getAttribute('data-upi');
                const status = this.getAttribute('data-status');
                const created = this.getAttribute('data-created');
                const processed = this.getAttribute('data-processed');
                const txn = this.getAttribute('data-txn');
                const notes = this.getAttribute('data-notes');
                
                // Populate modal with data
                document.getElementById('view-gym-name').textContent = gym;
                document.getElementById('view-amount').textContent = '₹' + amount.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                
                // Set status badge
                const statusBadge = document.getElementById('view-status-badge');
                let statusClass = '';
                let statusText = '';
                
                switch (status) {
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
                
                statusBadge.className = 'px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full ' + statusClass;
                statusBadge.textContent = statusText;
                
                document.getElementById('view-created-at').textContent = created;
                
                // Show/hide processed date
                const processedContainer = document.getElementById('view-processed-container');
                if (processed) {
                    document.getElementById('view-processed-at').textContent = processed;
                    processedContainer.classList.remove('hidden');
                } else {
                    processedContainer.classList.add('hidden');
                }
                
                // Set payment method details
                document.getElementById('view-method-type').textContent = method === 'bank' ? 'Bank Transfer' : (method === 'upi' ? 'UPI Transfer' : 'Not specified');
                
                // Show/hide bank details
                const bankNameContainer = document.getElementById('view-bank-name-container');
                const accountNameContainer = document.getElementById('view-account-name-container');
                const accountNumberContainer = document.getElementById('view-account-number-container');
                const ifscContainer = document.getElementById('view-ifsc-container');
                const upiContainer = document.getElementById('view-upi-container');
                
                if (method === 'bank') {
                    document.getElementById('view-bank-name').textContent = bank;
                    document.getElementById('view-account-name').textContent = account;
                    document.getElementById('view-account-number').textContent = accountNumber;
                    document.getElementById('view-ifsc').textContent = ifsc;
                    
                    bankNameContainer.classList.remove('hidden');
                    accountNameContainer.classList.remove('hidden');
                    accountNumberContainer.classList.remove('hidden');
                    ifscContainer.classList.remove('hidden');
                    upiContainer.classList.add('hidden');
                } else if (method === 'upi') {
                    document.getElementById('view-upi').textContent = upi;
                    
                    bankNameContainer.classList.add('hidden');
                    accountNameContainer.classList.add('hidden');
                    accountNumberContainer.classList.add('hidden');
                    ifscContainer.classList.add('hidden');
                    upiContainer.classList.remove('hidden');
                } else {
                    bankNameContainer.classList.add('hidden');
                    accountNameContainer.classList.add('hidden');
                    accountNumberContainer.classList.add('hidden');
                    ifscContainer.classList.add('hidden');
                    upiContainer.classList.add('hidden');
                }
                
                // Show/hide transaction details
                const transactionContainer = document.getElementById('view-transaction-container');
                const notesContainer = document.getElementById('view-notes-container');
                
                if (txn) {
                    document.getElementById('view-transaction-id').textContent = txn;
                    transactionContainer.classList.remove('hidden');
                    
                    if (notes) {
                        document.getElementById('view-notes').textContent = notes;
                        notesContainer.classList.remove('hidden');
                    } else {
                        notesContainer.classList.add('hidden');
                    }
                } else {
                    transactionContainer.classList.add('hidden');
                }
                
                // Show modal
                viewDetailsModal.classList.remove('hidden');
            });
        });
        
        closeViewDetailsModal.addEventListener('click', () => {
            viewDetailsModal.classList.add('hidden');
        });
        
        // Process Withdrawal Modal
        const processModal = document.getElementById('processModal');
        const closeProcessModal = document.getElementById('closeProcessModal');
        const cancelProcess = document.getElementById('cancelProcess');

        document.querySelectorAll('.process-btn').forEach(button => {
            button.addEventListener('click', function() {
                // Get data from button attributes
                const id = this.getAttribute('data-id');
                const gym = this.getAttribute('data-gym');
                const amount = parseFloat(this.getAttribute('data-amount'));
                const method = this.getAttribute('data-method');
                const bank = this.getAttribute('data-bank');
                const account = this.getAttribute('data-account');
                const accountNumber = this.getAttribute('data-account-number');
                const ifsc = this.getAttribute('data-ifsc');
                const upi = this.getAttribute('data-upi');
                
                // Populate modal with data
                document.getElementById('process-withdrawal-id').value = id;
                document.getElementById('process-gym-name').textContent = gym;
                document.getElementById('process-amount').textContent = '₹' + amount.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                
                // Show appropriate payment method details
                const bankDetails = document.getElementById('process-bank-details');
                const upiDetails = document.getElementById('process-upi-details');
                
                if (method === 'bank') {
                    document.getElementById('process-bank-name').textContent = 'Bank: ' + bank;
                    document.getElementById('process-account-name').textContent = 'Account Name: ' + account;
                    document.getElementById('process-account-number').textContent = 'Account Number: ' + accountNumber;
                    document.getElementById('process-ifsc').textContent = 'IFSC: ' + ifsc;
                    
                    bankDetails.classList.remove('hidden');
                    upiDetails.classList.add('hidden');
                } else if (method === 'upi') {
                    document.getElementById('process-upi').textContent = 'UPI ID: ' + upi;
                    
                    bankDetails.classList.add('hidden');
                    upiDetails.classList.remove('hidden');
                } else {
                    bankDetails.classList.add('hidden');
                    upiDetails.classList.add('hidden');
                }
                
                // Show modal
                processModal.classList.remove('hidden');
            });
        });
        
        const closeProcessModalFn = () => {
            processModal.classList.add('hidden');
        };
        
        closeProcessModal.addEventListener('click', closeProcessModalFn);
        cancelProcess.addEventListener('click', closeProcessModalFn);
        
        // Reject Withdrawal Modal
        const rejectModal = document.getElementById('rejectModal');
        const closeRejectModal = document.getElementById('closeRejectModal');
        const cancelReject = document.getElementById('cancelReject');
        
        document.querySelectorAll('.reject-btn').forEach(button => {
            button.addEventListener('click', function() {
                // Get data from button attributes
                const id = this.getAttribute('data-id');
                const gym = this.getAttribute('data-gym');
                const amount = parseFloat(this.getAttribute('data-amount'));
                
                // Populate modal with data
                document.getElementById('reject-withdrawal-id').value = id;
                document.getElementById('reject-gym-name').textContent = gym;
                document.getElementById('reject-amount').textContent = '₹' + amount.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                
                // Show modal
                rejectModal.classList.remove('hidden');
            });
        });
        
        const closeRejectModalFn = () => {
            rejectModal.classList.add('hidden');
        };
        
        closeRejectModal.addEventListener('click', closeRejectModalFn);
        cancelReject.addEventListener('click', closeRejectModalFn);
        
        // Process All Modal
        const processAllModal = document.getElementById('processAllModal');
        const closeProcessAllModal = document.getElementById('closeProcessAllModal');
        const cancelProcessAll = document.getElementById('cancelProcessAll');
        
        function confirmProcessAll() {
            processAllModal.classList.remove('hidden');
        }
        
        const closeProcessAllModalFn = () => {
            processAllModal.classList.add('hidden');
        };
        
        closeProcessAllModal.addEventListener('click', closeProcessAllModalFn);
        cancelProcessAll.addEventListener('click', closeProcessAllModalFn);
        
        // Close modals when clicking outside
        window.addEventListener('click', (e) => {
            if (e.target === viewDetailsModal) {
                viewDetailsModal.classList.add('hidden');
            } else if (e.target === processModal) {
                closeProcessModalFn();
            } else if (e.target === rejectModal) {
                closeRejectModalFn();
            } else if (e.target === processAllModal) {
                closeProcessAllModalFn();
            }
        });
    </script>
</body>
</html>



