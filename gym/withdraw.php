<?php
include '../includes/navbar.php';

require_once '../config/database.php';

if (!isset($_SESSION['owner_id'])) {
    header('Location: login.php');
    exit;
}

$db = new GymDatabase();
$conn = $db->getConnection();
$owner_id = $_SESSION['owner_id'];

// Get gym details
$gymStmt = $conn->prepare("SELECT gym_id, name, balance, last_payout_date FROM gyms WHERE owner_id = ?");
$gymStmt->execute([$owner_id]);
$gym = $gymStmt->fetch(PDO::FETCH_ASSOC);

if (!$gym) {
    echo "<script>window.location.href = 'add gym.php';</script>";
    exit;
}

$gym_id = $gym['gym_id'];

// Calculate total available balance from completed schedules
$balanceQuery = "
        SELECT 
            COALESCE(SUM(daily_rate), 0) as total_revenue
        FROM schedules
        WHERE gym_id = ?
        AND status = 'completed'
    ";
$balanceStmt = $conn->prepare($balanceQuery);
$balanceStmt->execute([$gym_id]);
$balance = $balanceStmt->fetch(PDO::FETCH_ASSOC);

// Get monthly earnings breakdown
$monthlyQuery = "
        SELECT 
            DATE_FORMAT(start_date, '%Y-%m') as month,
            SUM(daily_rate) as monthly_earnings,
            COUNT(*) as visit_count
        FROM schedules
        WHERE gym_id = ?
        AND status = 'completed'
        GROUP BY DATE_FORMAT(start_date, '%Y-%m')
        ORDER BY month DESC
        LIMIT 6
    ";
$monthlyStmt = $conn->prepare($monthlyQuery);
$monthlyStmt->execute([$gym_id]);
$monthly_earnings = $monthlyStmt->fetchAll(PDO::FETCH_ASSOC);

// Get withdrawal history with pagination
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;
$offset = ($page - 1) * $limit;

// Count total withdrawals
$countStmt = $conn->prepare("SELECT COUNT(*) FROM withdrawals WHERE gym_id = ?");
$countStmt->execute([$gym_id]);
$totalWithdrawals = $countStmt->fetchColumn();
$totalPages = ceil($totalWithdrawals / $limit);

// Get paginated withdrawal history
$historyQuery = "
        SELECT w.*, g.name as gym_name
        FROM withdrawals w
        JOIN gyms g ON w.gym_id = g.gym_id
        WHERE w.gym_id = ?
        ORDER BY w.created_at DESC
        LIMIT " . intval($limit) . " OFFSET " . intval($offset);
$historyStmt = $conn->prepare($historyQuery);
$historyStmt->execute([$gym_id]);
$withdrawals = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

// Check if user has payment methods
$stmt = $conn->prepare("SELECT COUNT(*) FROM payment_methods WHERE owner_id = ?");
$stmt->execute([$_SESSION['owner_id']]);
$hasPaymentMethods = $stmt->fetchColumn() > 0;

// Get payment methods
$stmt = $conn->prepare("SELECT * FROM payment_methods WHERE owner_id = ? ORDER BY is_primary DESC");
$stmt->execute([$_SESSION['owner_id']]);
$payment_methods = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Function to generate pagination links
function generatePaginationLinks($currentPage, $totalPages)
{
    $links = '';
    $queryParams = $_GET;

    // Previous page link
    if ($currentPage > 1) {
        $queryParams['page'] = $currentPage - 1;
        $links .= '<a href="?' . http_build_query($queryParams) . '" class="px-3 py-2 bg-gray-200 text-gray-700 rounded-l hover:bg-gray-300">&laquo;</a>';
    } else {
        $links .= '<span class="px-3 py-2 bg-gray-100 text-gray-400 rounded-l">&laquo;</span>';
    }

    // Page number links
    $startPage = max(1, $currentPage - 2);
    $endPage = min($totalPages, $currentPage + 2);

    if ($startPage > 1) {
        $queryParams['page'] = 1;
        $links .= '<a href="?' . http_build_query($queryParams) . '" class="px-3 py-2 bg-gray-200 text-gray-700 hover:bg-gray-300">1</a>';
        if ($startPage > 2) {
            $links .= '<span class="px-3 py-2">...</span>';
        }
    }

    for ($i = $startPage; $i <= $endPage; $i++) {
        $queryParams['page'] = $i;
        if ($i == $currentPage) {
            $links .= '<span class="px-3 py-2 bg-blue-500 text-white rounded">' . $i . '</span>';
        } else {
            $links .= '<a href="?' . http_build_query($queryParams) . '" class="px-3 py-2 bg-gray-200 text-gray-700 hover:bg-gray-300">' . $i . '</a>';
        }
    }

    if ($endPage < $totalPages) {
        if ($endPage < $totalPages - 1) {
            $links .= '<span class="px-3 py-2">...</span>';
        }
        $queryParams['page'] = $totalPages;
        $links .= '<a href="?' . http_build_query($queryParams) . '" class="px-3 py-2 bg-gray-200 text-gray-700 hover:bg-gray-300">' . $totalPages . '</a>';
    }

    // Next page link
    if ($currentPage < $totalPages) {
        $queryParams['page'] = $currentPage + 1;
        $links .= '<a href="?' . http_build_query($queryParams) . '" class="px-3 py-2 bg-gray-200 text-gray-700 rounded-r hover:bg-gray-300">&raquo;</a>';
    } else {
        $links .= '<span class="px-3 py-2 bg-gray-100 text-gray-400 rounded-r">&raquo;</span>';
    }

    return $links;
}
?>

<div class="container mx-auto px-4 py-20">
    <!-- Header -->
    <div class="bg-white rounded-xl shadow-lg overflow-hidden mb-8">
    <div class="p-6 bg-gradient-to-r from-gray-900 to-gray-800">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <!-- Left Section -->
            <div class="flex items-center space-x-4">
                <div class="h-16 w-16 rounded-full bg-yellow-500 flex items-center justify-center shrink-0">
                    <i class="fas fa-money-bill-wave text-2xl text-white"></i>
                </div>
                <div>
                    <h1 class="text-2xl font-bold text-white">Withdraw Funds</h1>
                    <p class="text-white text-sm"><?= htmlspecialchars($gym['name']) ?></p>
                </div>
            </div>

            <!-- Right Section -->
            <div class="text-white text-left md:text-right">
                <p class="text-sm opacity-75">Last Payout</p>
                <p class="font-semibold">
                    <?= $gym['last_payout_date'] ? date('M d, Y', strtotime($gym['last_payout_date'])) : 'No payouts yet' ?>
                </p>
            </div>
        </div>
    </div>
</div>

    <!-- Monthly Earnings Chart -->
    <div class="bg-white rounded-xl shadow-lg p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-700 mb-6 flex items-center">
            <i class="fas fa-chart-bar text-yellow-500 mr-2"></i>
            Monthly Earnings
        </h2>

        <div class="h-64">
            <canvas id="monthlyEarningsChart"></canvas>
        </div>
    </div>

    <?php if (!$hasPaymentMethods): ?>
        <!-- Payment Method Setup Section -->
        <div class="bg-white rounded-xl shadow-lg p-8">
            <div class="text-center mb-8">
                <div class="h-24 w-24 mx-auto mb-4">
                    <i class="fas fa-university text-yellow-500 text-6xl"></i>
                </div>
                <h2 class="text-2xl font-bold text-gray-800">Set Up Payment Method</h2>
                <p class="text-gray-600 mt-2">Add a payment method to start withdrawing your earnings</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <!-- Bank Account Form -->
                <div class="bg-gray-50 rounded-xl p-6">
                    <h3 class="text-lg font-semibold mb-4 flex items-center">
                        <i class="fas fa-landmark text-yellow-500 mr-2"></i>
                        Bank Account
                    </h3>
                    <form action="add_payment_method.php" method="POST" class="space-y-4">
                        <input type="hidden" name="method_type" value="bank">

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Account Holder Name</label>
                            <input type="text" name="account_name" required
                                class="mt-1 w-full rounded-lg border-gray-300 focus:border-yellow-500 focus:ring focus:ring-yellow-200">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Account Number</label>
                            <input type="text" name="account_number" required
                                class="mt-1 w-full rounded-lg border-gray-300 focus:border-yellow-500 focus:ring focus:ring-yellow-200">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">IFSC Code</label>
                            <input type="text" name="ifsc_code" required
                                class="mt-1 w-full rounded-lg border-gray-300 focus:border-yellow-500 focus:ring focus:ring-yellow-200">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Bank Name</label>
                            <input type="text" name="bank_name" required
                                class="mt-1 w-full rounded-lg border-gray-300 focus:border-yellow-500 focus:ring focus:ring-yellow-200">
                        </div>

                        <button type="submit"
                            class="w-full bg-yellow-500 hover:bg-yellow-600 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                            <i class="fas fa-plus-circle mr-2"></i>Add Bank Account
                        </button>
                    </form>
                </div>

                <!-- UPI Form -->
                <div class="bg-gray-50 rounded-xl p-6">
                    <h3 class="text-lg font-semibold mb-4 flex items-center">
                        <i class="fas fa-mobile-alt text-yellow-500 mr-2"></i>
                        UPI ID
                    </h3>
                    <form action="add_payment_method.php" method="POST" class="space-y-4">
                        <input type="hidden" name="method_type" value="upi">

                        <div>
                            <label class="block text-sm font-medium text-gray-700">UPI ID</label>
                            <input type="text" name="upi_id" required placeholder="example@upi"
                                class="mt-1 w-full rounded-lg border-gray-300 focus:border-yellow-500 focus:ring focus:ring-yellow-200">
                            <p class="mt-1 text-sm text-gray-500">Enter your UPI ID linked with your bank account</p>
                        </div>

                        <button type="submit"
                            class="w-full bg-yellow-500 hover:bg-yellow-600 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                            <i class="fas fa-plus-circle mr-2"></i>Add UPI ID
                        </button>
                    </form>
                </div>
            </div>
        </div>

    <?php else: ?>
        <!-- Withdrawal Section -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- Withdrawal Form -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <div class="mb-8">
                    <h3 class="text-xl font-semibold text-gray-700 mb-2">Available Balance</h3>
                    <div class="flex items-center space-x-4">
                        <div class="h-12 w-12 rounded-full bg-green-100 flex items-center justify-center">
                            <i class="fas fa-wallet text-green-600"></i>
                        </div>
                        <p class="text-3xl font-bold text-green-600">
                            ₹<?php echo number_format($balance['total_revenue'], 2); ?></p>
                    </div>
                    <p class="text-sm text-gray-500 mt-2">From completed visits</p>
                </div>

                <form action="process_withdrawal.php" method="POST" class="space-y-6" id="withdrawalForm">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-money-bill-alt mr-2"></i>Withdrawal Amount
                        </label>
                        <div class="relative">
                            <span class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-500">₹</span>
                            <input type="number" name="amount" id="withdrawalAmount" min="500"
                                max="<?php echo $balance['total_revenue']; ?>" step="0.01" required
                                class="pl-8 w-full rounded-lg border-gray-300 focus:border-yellow-500 focus:ring focus:ring-yellow-200">
                        </div>
                        <div class="flex justify-between mt-1 text-sm">
                            <p class="text-gray-500">Minimum: ₹500</p>
                            <p class="text-gray-500">Maximum: ₹<?php echo number_format($balance['total_revenue'], 2); ?>
                            </p>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-university mr-2"></i>Select Payment Method
                        </label>
                        <select name="payment_method" required
                            class="w-full rounded-lg border-gray-300 focus:border-yellow-500 focus:ring focus:ring-yellow-200">
                            <option value="">Choose a payment method</option>
                            <?php foreach ($payment_methods as $method): ?>
                                <option value="<?php echo $method['id']; ?>">
                                    <?php if ($method['method_type'] === 'bank'): ?>
                                        <?php echo htmlspecialchars($method['bank_name']); ?> (****
                                        <?php echo substr($method['account_number'], -4); ?>)
                                    <?php else: ?>
                                        UPI: <?php echo htmlspecialchars($method['upi_id']); ?>
                                    <?php endif; ?>
                                    <?php echo $method['is_primary'] ? ' (Primary)' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded-md">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <i class="fas fa-info-circle text-yellow-400"></i>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm text-yellow-700">
                                    Withdrawals are typically processed within 1-3 business days. You'll receive a
                                    notification once your withdrawal is processed.
                                </p>
                            </div>
                        </div>
                    </div>

                    <button type="submit" id="withdrawButton"
                        class="w-full bg-yellow-500 hover:bg-yellow-600 text-white px-6 py-3 rounded-lg transition-colors duration-200 flex items-center justify-center">
                        <i class="fas fa-paper-plane mr-2"></i>
                        Request Withdrawal
                    </button>
                </form>
            </div>

            <!-- Withdrawal History -->
            <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                <div class="p-6 border-b border-gray-200">
                    <h2 class="text-xl font-semibold text-gray-700 flex items-center">
                        <i class="fas fa-history text-yellow-500 mr-2"></i>
                        Withdrawal History
                    </h2>
                </div>

                <!-- Pagination Controls - Top -->
                <div class="px-6 py-3 bg-gray-50 flex flex-wrap items-center justify-between">
                    <div class="flex items-center space-x-2">
                        <span class="text-sm text-gray-600">Show</span>
                        <select id="paginationLimit" onchange="changeLimit(this.value)"
                            class="bg-white border border-gray-300 rounded px-2 py-1 text-sm">
                            <option value="10" <?= $limit == 10 ? 'selected' : '' ?>>10</option>
                            <option value="25" <?= $limit == 25 ? 'selected' : '' ?>>25</option>
                            <option value="50" <?= $limit == 50 ? 'selected' : '' ?>>50</option>
                        </select>
                        <span class="text-sm text-gray-600">entries</span>
                    </div>

                    <div class="text-sm text-gray-600">
                        Showing <?= min(($page - 1) * $limit + 1, $totalWithdrawals) ?> to
                        <?= min($page * $limit, $totalWithdrawals) ?> of <?= $totalWithdrawals ?> withdrawals
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Amount</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Payment Method</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if (count($withdrawals) > 0): ?>
                                <?php foreach ($withdrawals as $withdrawal): ?>
                                    <tr class="hover:bg-gray-50 transition-colors duration-200">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900">
                                                <?php echo date('M j, Y', strtotime($withdrawal['created_at'])); ?></div>
                                            <div class="text-xs text-gray-500">
                                                <?php echo date('g:i A', strtotime($withdrawal['created_at'])); ?></div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm font-medium text-gray-900">
                                                ₹<?php echo number_format($withdrawal['amount'], 2); ?></div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full
                                            <?php echo $withdrawal['status'] === 'completed'
                                                ? 'bg-green-100 text-green-800'
                                                : ($withdrawal['status'] === 'pending'
                                                    ? 'bg-yellow-100 text-yellow-800'
                                                    : 'bg-red-100 text-red-800'); ?>">
                                                <?php echo ucfirst($withdrawal['status']); ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php if ($withdrawal['payment_method_type'] === 'bank'): ?>
                                                <div class="flex items-center">
                                                    <i class="fas fa-university text-gray-400 mr-2"></i>
                                                    <span>Bank Transfer</span>
                                                </div>
                                            <?php else: ?>
                                                <div class="flex items-center">
                                                    <i class="fas fa-mobile-alt text-gray-400 mr-2"></i>
                                                    <span>UPI</span>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="px-6 py-12 text-center">
                                        <div class="flex flex-col items-center">
                                            <i class="fas fa-file-invoice-dollar text-6xl text-gray-300 mb-4"></i>
                                            <p class="text-gray-500">No withdrawal history available</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Controls - Bottom -->
                <?php if ($totalWithdrawals > 0): ?>
                    <div class="px-6 py-4 bg-gray-50 flex flex-wrap items-center justify-between">
                        <div class="text-sm text-gray-600">
                            Showing <?= min(($page - 1) * $limit + 1, $totalWithdrawals) ?> to
                            <?= min($page * $limit, $totalWithdrawals) ?> of <?= $totalWithdrawals ?> withdrawals
                        </div>

                        <div class="flex space-x-1 mt-2 sm:mt-0">
                            <?= generatePaginationLinks($page, $totalPages) ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Payment Methods Management -->
        <div class="bg-white rounded-xl shadow-lg p-6 mt-8">
            <h2 class="text-xl font-semibold text-gray-700 mb-6 flex items-center">
                <i class="fas fa-university text-yellow-500 mr-2"></i>
                Manage Payment Methods
            </h2>

            <div class="space-y-4">
                <?php foreach ($payment_methods as $method): ?>
                    <div
                        class="flex flex-col sm:flex-row justify-between items-start sm:items-center p-4 border rounded-lg hover:bg-gray-50 transition-colors duration-200 space-y-4 sm:space-y-0">
                        <div class="flex items-center space-x-4 w-full sm:w-auto">
                            <div class="h-12 w-12 rounded-full bg-gray-100 flex items-center justify-center shrink-0">
                                <i
                                    class="<?php echo $method['method_type'] === 'bank' ? 'fas fa-university' : 'fas fa-mobile-alt'; ?> text-gray-600 text-xl"></i>
                            </div>
                            <div class="flex flex-col">
                                <?php if ($method['method_type'] === 'bank'): ?>
                                    <p class="font-semibold text-base"><?= htmlspecialchars($method['bank_name']) ?></p>
                                    <p class="text-sm text-gray-600">
                                        **** <?= substr($method['account_number'], -4) ?>
                                        <?php if ($method['is_primary']): ?>
                                            <span class="text-green-600 font-semibold">(Primary)</span>
                                        <?php endif; ?>
                                    </p>
                                <?php else: ?>
                                    <p class="font-semibold text-base">UPI ID</p>
                                    <p class="text-sm text-gray-600">
                                        <?= htmlspecialchars($method['upi_id']) ?>
                                        <?php if ($method['is_primary']): ?>
                                            <span class="text-green-600 font-semibold">(Primary)</span>
                                        <?php endif; ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="flex flex-col sm:flex-row gap-2 w-full sm:w-auto">
                            <?php if (!$method['is_primary']): ?>
                                <button onclick="setAsPrimary(<?= $method['id'] ?>)"
                                    class="text-blue-600 hover:text-blue-800 px-3 py-2 rounded-lg hover:bg-blue-50 transition-colors duration-200 text-sm flex items-center justify-center">
                                    <i class="fas fa-star mr-2"></i> Set as Primary
                                </button>
                            <?php endif; ?>
                            <button onclick="deletePaymentMethod(<?= $method['id'] ?>)"
                                class="text-red-600 hover:text-red-800 px-3 py-1 rounded-lg hover:bg-red-50 transition-colors duration-200">
                                <i class="fas fa-trash-alt mr-1"></i> Delete
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>


                <!-- Add New Payment Method Button -->
                <div class="mt-6">
                    <button onclick="toggleAddPaymentForm()"
                        class="bg-gray-100 hover:bg-gray-200 text-gray-800 px-4 py-2 rounded-lg transition-colors duration-200 w-full flex items-center justify-center">
                        <i class="fas fa-plus-circle mr-2"></i> Add Another Payment Method
                    </button>
                </div>

                <!-- Add New Payment Method Form (Hidden by default) -->
                <div id="addPaymentForm" class="hidden mt-6 grid grid-cols-1 md:grid-cols-2 gap-8">
                    <!-- Bank Account Form -->
                    <div class="bg-gray-50 rounded-xl p-6">
                        <h3 class="text-lg font-semibold mb-4 flex items-center">
                            <i class="fas fa-landmark text-yellow-500 mr-2"></i>
                            Bank Account
                        </h3>
                        <form action="add_payment_method.php" method="POST" class="space-y-4">
                            <input type="hidden" name="method_type" value="bank">

                            <div>
                                <label class="block text-sm font-medium text-gray-700">Account Holder Name</label>
                                <input type="text" name="account_name" required
                                    class="mt-1 w-full rounded-lg border-gray-300 focus:border-yellow-500 focus:ring focus:ring-yellow-200">
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700">Account Number</label>
                                <input type="text" name="account_number" required
                                    class="mt-1 w-full rounded-lg border-gray-300 focus:border-yellow-500 focus:ring focus:ring-yellow-200">
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700">IFSC Code</label>
                                <input type="text" name="ifsc_code" required
                                    class="mt-1 w-full rounded-lg border-gray-300 focus:border-yellow-500 focus:ring focus:ring-yellow-200">
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700">Bank Name</label>
                                <input type="text" name="bank_name" required
                                    class="mt-1 w-full rounded-lg border-gray-300 focus:border-yellow-500 focus:ring focus:ring-yellow-200">
                            </div>

                            <button type="submit"
                                class="w-full bg-yellow-500 hover:bg-yellow-600 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                                <i class="fas fa-plus-circle mr-2"></i>Add Bank Account
                            </button>
                        </form>
                    </div>

                    <!-- UPI Form -->
                    <div class="bg-gray-50 rounded-xl p-6">
                        <h3 class="text-lg font-semibold mb-4 flex items-center">
                            <i class="fas fa-mobile-alt text-yellow-500 mr-2"></i>
                            UPI ID
                        </h3>
                        <form action="add_payment_method.php" method="POST" class="space-y-4">
                            <input type="hidden" name="method_type" value="upi">

                            <div>
                                <label class="block text-sm font-medium text-gray-700">UPI ID</label>
                                <input type="text" name="upi_id" required placeholder="example@upi"
                                    class="mt-1 w-full rounded-lg border-gray-300 focus:border-yellow-500 focus:ring focus:ring-yellow-200">
                                <p class="mt-1 text-sm text-gray-500">Enter your UPI ID linked with your bank account</p>
                            </div>

                            <button type="submit"
                                class="w-full bg-yellow-500 hover:bg-yellow-600 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                                <i class="fas fa-plus-circle mr-2"></i>Add UPI ID
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Include Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
    // Monthly Earnings Chart
    document.addEventListener('DOMContentLoaded', function () {
        const ctx = document.getElementById('monthlyEarningsChart').getContext('2d');

        // Parse data from PHP
        const monthlyData = <?php echo json_encode($monthly_earnings); ?>;

        // Format data for Chart.js
        const labels = monthlyData.map(item => {
            const date = new Date(item.month + '-01');
            return date.toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
        });

        const earnings = monthlyData.map(item => parseFloat(item.monthly_earnings));
        const visits = monthlyData.map(item => parseInt(item.visit_count));

        // Create chart
        const chart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Earnings (₹)',
                        data: earnings,
                        backgroundColor: 'rgba(52, 211, 153, 0.7)',
                        borderColor: 'rgba(16, 185, 129, 1)',
                        borderWidth: 1,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Visits',
                        data: visits,
                        type: 'line',
                        backgroundColor: 'rgba(59, 130, 246, 0.2)',
                        borderColor: 'rgba(37, 99, 235, 1)',
                        borderWidth: 2,
                        pointBackgroundColor: 'rgba(37, 99, 235, 1)',
                        pointRadius: 4,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Earnings (₹)'
                        },
                        ticks: {
                            callback: function (value) {
                                return '₹' + value.toLocaleString();
                            }
                        }
                    },
                    y1: {
                        beginAtZero: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Number of Visits'
                        },
                        grid: {
                            drawOnChartArea: false
                        }
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.datasetIndex === 0) {
                                    label += '₹' + context.parsed.y.toLocaleString();
                                } else {
                                    label += context.parsed.y;
                                }
                                return label;
                            }
                        }
                    }
                }
            }
        });
    });

    // Payment Method Management
    function setAsPrimary(methodId) {
        if (confirm('Set this payment method as primary?')) {
            fetch('update_payment_method.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    method_id: methodId,
                    action: 'set_primary'
                })
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        window.location.reload();
                    } else {
                        alert('Error: ' + (data.message || 'Failed to update payment method'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred. Please try again.');
                });
        }
    }

    function deletePaymentMethod(methodId) {
        if (confirm('Are you sure you want to delete this payment method? This action cannot be undone.')) {
            fetch('update_payment_method.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    method_id: methodId,
                    action: 'delete'
                })
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        window.location.reload();
                    } else {
                        alert('Error: ' + (data.message || 'Failed to delete payment method'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred. Please try again.');
                });
        }
    }

    function toggleAddPaymentForm() {
        const form = document.getElementById('addPaymentForm');
        form.classList.toggle('hidden');
    }

    // Withdrawal form validation
    document.addEventListener('DOMContentLoaded', function () {
        const withdrawalForm = document.getElementById('withdrawalForm');
        const withdrawalAmount = document.getElementById('withdrawalAmount');
        const withdrawButton = document.getElementById('withdrawButton');

        if (withdrawalForm) {
            withdrawalForm.addEventListener('submit', function (e) {
                const amount = parseFloat(withdrawalAmount.value);
                const maxAmount = parseFloat(<?php echo $balance['total_revenue']; ?>);

                if (isNaN(amount) || amount < 500) {
                    e.preventDefault();
                    alert('Minimum withdrawal amount is ₹500');
                    return false;
                }

                if (amount > maxAmount) {
                    e.preventDefault();
                    alert('Withdrawal amount cannot exceed your available balance of ₹' + maxAmount.toLocaleString());
                    return false;
                }

                // Disable button to prevent double submission
                withdrawButton.disabled = true;
                withdrawButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Processing...';
                return true;
            });
        }
    });

    // Pagination
    function changeLimit(limit) {
        const urlParams = new URLSearchParams(window.location.search);
        urlParams.set('limit', limit);
        urlParams.set('page', 1); // Reset to first page when changing limit
        window.location.href = window.location.pathname + '?' + urlParams.toString();
    }
</script>