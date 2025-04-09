<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = "Please login to view your transactions";
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Pagination setup
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Filter parameters
$status = isset($_GET['status']) ? $_GET['status'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'date_desc';

// Base query
$sql = "SELECT t.*, g.name as gym_name, mp.plan_name 
        FROM transactions t
        LEFT JOIN gyms g ON t.gym_id = g.gym_id
        LEFT JOIN gym_membership_plans mp ON t.plan_id = mp.plan_id
        WHERE t.user_id = ?";
$params = [$user_id];

// Apply filters
if ($status) {
    $sql .= " AND t.status = ?";
    $params[] = $status;
}

if ($date_from) {
    $sql .= " AND t.transaction_date >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $sql .= " AND t.transaction_date <= ?";
    $params[] = $date_to . ' 23:59:59';
}

// Apply sorting
switch ($sort) {
    case 'date_asc':
        $sql .= " ORDER BY t.transaction_date ASC";
        break;
    case 'amount_desc':
        $sql .= " ORDER BY t.amount DESC";
        break;
    case 'amount_asc':
        $sql .= " ORDER BY t.amount ASC";
        break;
    case 'date_desc':
    default:
        $sql .= " ORDER BY t.transaction_date DESC";
        break;
}

// Count total records for pagination
$countSql = str_replace("SELECT t.*, g.name as gym_name, mp.plan_name", "SELECT COUNT(*)", $sql);
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$total_records = $countStmt->fetchColumn();
$total_pages = ceil($total_records / $limit);

// Add pagination to the main query
$sql .= " LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

// Execute the query
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get transaction statuses for filter dropdown
$statusStmt = $pdo->prepare("SELECT DISTINCT status FROM transactions WHERE user_id = ?");
$statusStmt->execute([$user_id]);
$statuses = $statusStmt->fetchAll(PDO::FETCH_COLUMN);

$page_title = "Transaction History";
include 'includes/header.php';
include 'includes/navbar.php';
?>

<div class="bg-gray-900 min-h-screen pt-24 pb-16">
    <div class="container mx-auto px-4 sm:px-6 lg:px-8">
        <div class="max-w-7xl mx-auto">
            <h1 class="text-3xl sm:text-4xl font-extrabold text-white mb-8 text-center">Transaction History</h1>
            
            <?php if (isset($_SESSION['success'])): ?>
                <div class="bg-green-900 text-green-100 p-6 rounded-3xl mb-6">
                    <?= htmlspecialchars($_SESSION['success']) ?>
                </div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="bg-red-900 text-red-100 p-6 rounded-3xl mb-6">
                    <?= htmlspecialchars($_SESSION['error']) ?>
                </div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>
            
            <!-- Filters -->
            <div class="bg-gray-800 rounded-3xl p-6 mb-8">
                <h2 class="text-xl font-bold text-white mb-4">Filter Transactions</h2>
                <form action="" method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Status</label>
                        <select name="status" class="w-full bg-gray-700 border-gray-600 text-white rounded-lg">
                            <option value="">All Statuses</option>
                            <?php foreach ($statuses as $statusOption): ?>
                                <option value="<?= htmlspecialchars($statusOption) ?>" <?= $status === $statusOption ? 'selected' : '' ?>>
                                    <?= ucfirst(htmlspecialchars($statusOption)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">From Date</label>
                        <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" class="w-full bg-gray-700 border-gray-600 text-white rounded-lg">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">To Date</label>
                        <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" class="w-full bg-gray-700 border-gray-600 text-white rounded-lg">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Sort By</label>
                        <select name="sort" class="w-full bg-gray-700 border-gray-600 text-white rounded-lg">
                            <option value="date_desc" <?= $sort === 'date_desc' ? 'selected' : '' ?>>Date (Newest First)</option>
                            <option value="date_asc" <?= $sort === 'date_asc' ? 'selected' : '' ?>>Date (Oldest First)</option>
                            <option value="amount_desc" <?= $sort === 'amount_desc' ? 'selected' : '' ?>>Amount (Highest First)</option>
                            <option value="amount_asc" <?= $sort === 'amount_asc' ? 'selected' : '' ?>>Amount (Lowest First)</option>
                        </select>
                    </div>
                    
                    <div class="flex items-end">
                        <button type="submit" class="bg-yellow-500 hover:bg-yellow-400 text-black font-bold py-2 px-4 rounded-lg w-full">
                            <i class="fas fa-filter mr-2"></i>Apply Filters
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Transactions List -->
            <?php if (empty($transactions)): ?>
                <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl p-8 text-center">
                    <i class="fas fa-receipt text-yellow-500 text-5xl mb-4"></i>
                    <h3 class="text-xl font-bold text-white mb-2">No Transactions Found</h3>
                    <p class="text-gray-400">You don't have any transactions matching your filters.</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl overflow-hidden">
                        <thead>
                            <tr class="bg-gray-700">
                                <th class="px-6 py-4 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Transaction ID</th>
                                <th class="px-6 py-4 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Date</th>
                                <th class="px-6 py-4 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Gym</th>
                                <th class="px-6 py-4 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Plan</th>
                                <th class="px-6 py-4 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Amount</th>
                                <th class="px-6 py-4 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-4 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-700">
                            <?php foreach ($transactions as $transaction): ?>
                                <tr class="hover:bg-gray-700 transition-colors duration-200">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-300"><?= htmlspecialchars($transaction['transaction_id']) ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-300"><?= date('M d, Y h:i A', strtotime($transaction['transaction_date'])) ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-300"><?= htmlspecialchars($transaction['gym_name']) ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-300"><?= htmlspecialchars($transaction['plan_name']) ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-yellow-400">$<?= number_format($transaction['amount'], 2) ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full 
                                            <?php 
                                            switch($transaction['status']) {
                                                case 'completed':
                                                    echo 'bg-green-900 text-green-200';
                                                    break;
                                                case 'pending':
                                                    echo 'bg-yellow-900 text-yellow-200';
                                                    break;
                                                case 'failed':
                                                    echo 'bg-red-900 text-red-200';
                                                    break;
                                                case 'refunded':
                                                    echo 'bg-blue-900 text-blue-200';
                                                    break;
                                                default:
                                                    echo 'bg-gray-900 text-gray-200';
                                            }
                                            ?>">
                                            <?= ucfirst(htmlspecialchars($transaction['status'])) ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-300">
                                        <a href="view_transaction.php?id=<?= $transaction['transaction_id'] ?>" class="text-blue-400 hover:text-blue-300">
                                            <i class="fas fa-eye mr-1"></i> View
                                        </a>
                                        
                                        <?php if ($transaction['status'] === 'completed'): ?>
                                            <a href="download_receipt.php?id=<?= $transaction['transaction_id'] ?>" class="text-green-400 hover:text-green-300 ml-3">
                                                <i class="fas fa-download mr-1"></i> Receipt
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="flex justify-center mt-8">
                        <nav class="inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?= $page - 1 ?>&status=<?= urlencode($status) ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&sort=<?= urlencode($sort) ?>" class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-700 bg-gray-800 text-sm font-medium text-gray-400 hover:bg-gray-700">
                                    <span class="sr-only">Previous</span>
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            <?php endif; ?>
                            
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <a href="?page=<?= $i ?>&status=<?= urlencode($status) ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&sort=<?= urlencode($sort) ?>" class="relative inline-flex items-center px-4 py-2 border border-gray-700 <?= $i === $page ? 'bg-gray-700 text-white' : 'bg-gray-800 text-gray-400 hover:bg-gray-700' ?> text-sm font-medium">
                                    <?= $i ?>
                                </a>
                            <?php endfor; ?>
                            
                            <?php if ($page < $total_pages): ?>
                                <a href="?page=<?= $page + 1 ?>&status=<?= urlencode($status) ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&sort=<?= urlencode($sort) ?>" class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-700 bg-gray-800 text-sm font-medium text-gray-400 hover:bg-gray-700">
                                    <span class="sr-only">Next</span>
                                    <i class="fas fa-chevron-right"></i>
                                    </a>
                            <?php endif; ?>
                        </nav>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            
            <!-- Summary Section -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mt-10">
                <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl p-6 transform hover:scale-[1.02] transition-all duration-300">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-gray-400 text-sm">Total Spent</p>
                            <?php
                            $totalSpentStmt = $pdo->prepare("SELECT SUM(amount) FROM transactions WHERE user_id = ? AND status = 'completed'");
                            $totalSpentStmt->execute([$user_id]);
                            $totalSpent = $totalSpentStmt->fetchColumn() ?: 0;
                            ?>
                            <h3 class="text-2xl font-bold text-white mt-1">$<?= number_format($totalSpent, 2) ?></h3>
                        </div>
                        <div class="w-12 h-12 rounded-full bg-green-900 flex items-center justify-center">
                            <i class="fas fa-dollar-sign text-green-400 text-xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl p-6 transform hover:scale-[1.02] transition-all duration-300">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-gray-400 text-sm">Total Transactions</p>
                            <?php
                            $totalTransStmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE user_id = ?");
                            $totalTransStmt->execute([$user_id]);
                            $totalTrans = $totalTransStmt->fetchColumn() ?: 0;
                            ?>
                            <h3 class="text-2xl font-bold text-white mt-1"><?= number_format($totalTrans) ?></h3>
                        </div>
                        <div class="w-12 h-12 rounded-full bg-blue-900 flex items-center justify-center">
                            <i class="fas fa-receipt text-blue-400 text-xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl p-6 transform hover:scale-[1.02] transition-all duration-300">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-gray-400 text-sm">Active Memberships</p>
                            <?php
                            $activeMembershipsStmt = $pdo->prepare("SELECT COUNT(*) FROM memberships WHERE user_id = ? AND status = 'active'");
                            $activeMembershipsStmt->execute([$user_id]);
                            $activeMemberships = $activeMembershipsStmt->fetchColumn() ?: 0;
                            ?>
                            <h3 class="text-2xl font-bold text-white mt-1"><?= number_format($activeMemberships) ?></h3>
                        </div>
                        <div class="w-12 h-12 rounded-full bg-yellow-900 flex items-center justify-center">
                            <i class="fas fa-dumbbell text-yellow-400 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Help Section -->
            <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl p-6 mt-10">
                <h3 class="text-xl font-bold text-white mb-4">Need Help with a Transaction?</h3>
                <p class="text-gray-300 mb-4">If you have any questions about your transactions or need assistance with a payment, our support team is here to help.</p>
                <div class="flex flex-wrap gap-4">
                    <a href="contact.php" class="inline-flex items-center bg-yellow-500 hover:bg-yellow-400 text-black font-bold py-2 px-4 rounded-lg transition-colors duration-300">
                        <i class="fas fa-envelope mr-2"></i>Contact Support
                    </a>
                    <a href="faq.php" class="inline-flex items-center bg-gray-700 hover:bg-gray-600 text-white font-bold py-2 px-4 rounded-lg transition-colors duration-300">
                        <i class="fas fa-question-circle mr-2"></i>View FAQs
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

