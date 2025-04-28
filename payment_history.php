<?php
require_once 'config/database.php';
include 'includes/navbar.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$db = new GymDatabase();
$conn = $db->getConnection();

// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$date_filter = isset($_GET['date_range']) ? $_GET['date_range'] : 'all';
$gym_filter = isset($_GET['gym_id']) ? (int)$_GET['gym_id'] : 0;

// Build the query with filters
$query = "
    SELECT 
        p.*,
        um.start_date,
        um.end_date,
        um.status as membership_status,
        um.plan_id as membership_plan_id,
        g.name as gym_name,
        g.cover_photo,
        g.gym_id,
        gmp.tier as plan_name,
        gmp.duration,
        gmp.plan_id as gym_plan_id,
        gmp.price as plan_price,
        DATEDIFF(um.end_date, um.start_date) AS total_days
    FROM payments p
    JOIN user_memberships um ON p.membership_id = um.id
    JOIN gyms g ON p.gym_id = g.gym_id
    JOIN gym_membership_plans gmp ON um.plan_id = gmp.plan_id
    WHERE p.user_id = :user_id 
";

// Add status filter
if ($status_filter !== 'all') {
    $query .= " AND p.status = :status";
}

// Add date filter
if ($date_filter !== 'all') {
    switch ($date_filter) {
        case 'last_30_days':
            $query .= " AND p.payment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
            break;
        case 'last_90_days':
            $query .= " AND p.payment_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)";
            break;
        case 'this_year':
            $query .= " AND YEAR(p.payment_date) = YEAR(CURDATE())";
            break;
    }
}

// Add gym filter
if ($gym_filter > 0) {
    $query .= " AND g.gym_id = :gym_id";
}

$query .= " ORDER BY p.payment_date DESC";

$stmt = $conn->prepare($query);
$stmt->bindParam(':user_id', $user_id);

if ($status_filter !== 'all') {
    $stmt->bindParam(':status', $status_filter);
}

if ($gym_filter > 0) {
    $stmt->bindParam(':gym_id', $gym_filter);
}

$stmt->execute();
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all gyms the user has paid for (for filter dropdown)
$gymsStmt = $conn->prepare("
    SELECT DISTINCT g.gym_id, g.name 
    FROM payments p
    JOIN gyms g ON p.gym_id = g.gym_id
    WHERE p.user_id = :user_id
    ORDER BY g.name
");
$gymsStmt->bindParam(':user_id', $user_id);
$gymsStmt->execute();
$userGyms = $gymsStmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate total spending
$totalSpendingStmt = $conn->prepare("
    SELECT SUM(amount) as total 
    FROM payments 
    WHERE user_id = :user_id AND status = 'completed'
");
$totalSpendingStmt->bindParam(':user_id', $user_id);
$totalSpendingStmt->execute();
$totalSpending = $totalSpendingStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Get payment statistics
$statsStmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_payments,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_payments,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_payments,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_payments,
        MAX(payment_date) as last_payment_date
    FROM payments
    WHERE user_id = :user_id
");
$statsStmt->bindParam(':user_id', $user_id);
$statsStmt->execute();
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

?>
<div class="min-h-screen bg-gradient-to-b from-gray-900 to-black py-12 sm:py-16 lg:py-20">
    <div class="container mx-auto px-4 sm:px-6 lg:px-8">
        <h1 class="text-3xl font-bold text-white mb-8 text-center lg:mt-0 sm:mt-5 mt-8">Payment History</h1>

        <!-- Payment Statistics -->
        <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl p-6 mb-8">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div class="bg-gray-700 rounded-xl p-4 text-center">
                    <p class="text-yellow-400 text-sm">Total Spent</p>
                    <p class="text-white text-2xl font-bold">₹<?php echo number_format($totalSpending, 2); ?></p>
                </div>
                <div class="bg-gray-700 rounded-xl p-4 text-center">
                    <p class="text-yellow-400 text-sm">Total Payments</p>
                    <p class="text-white text-2xl font-bold"><?php echo $stats['total_payments']; ?></p>
                </div>
                <div class="bg-gray-700 rounded-xl p-4 text-center">
                    <p class="text-yellow-400 text-sm">Pending Payments</p>
                    <p class="text-white text-2xl font-bold"><?php echo $stats['pending_payments']; ?></p>
                </div>
                <div class="bg-gray-700 rounded-xl p-4 text-center">
                    <p class="text-yellow-400 text-sm">Last Payment</p>
                    <p class="text-white text-lg font-bold">
                        <?php echo $stats['last_payment_date'] ? date('d M Y', strtotime($stats['last_payment_date'])) : 'N/A'; ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl p-6 mb-8">
            <h2 class="text-xl font-bold text-white mb-4">Filter Payments</h2>
            <form action="" method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label for="status" class="block text-yellow-400 text-sm mb-2">Payment Status</label>
                    <select id="status" name="status" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-400">
                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                        <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="failed" <?php echo $status_filter === 'failed' ? 'selected' : ''; ?>>Failed</option>
                    </select>
                </div>
                <div>
                    <label for="date_range" class="block text-yellow-400 text-sm mb-2">Date Range</label>
                    <select id="date_range" name="date_range" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-400">
                        <option value="all" <?php echo $date_filter === 'all' ? 'selected' : ''; ?>>All Time</option>
                        <option value="last_30_days" <?php echo $date_filter === 'last_30_days' ? 'selected' : ''; ?>>Last 30 Days</option>
                        <option value="last_90_days" <?php echo $date_filter === 'last_90_days' ? 'selected' : ''; ?>>Last 90 Days</option>
                        <option value="this_year" <?php echo $date_filter === 'this_year' ? 'selected' : ''; ?>>This Year</option>
                    </select>
                </div>
                <div>
                    <label for="gym_id" class="block text-yellow-400 text-sm mb-2">Gym</label>
                    <select id="gym_id" name="gym_id" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-400">
                        <option value="0" <?php echo $gym_filter === 0 ? 'selected' : ''; ?>>All Gyms</option>
                        <?php foreach ($userGyms as $gym): ?>
                            <option value="<?php echo $gym['gym_id']; ?>" <?php echo $gym_filter === (int)$gym['gym_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($gym['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="md:col-span-3 flex justify-end">
                    <button type="submit" class="bg-yellow-500 hover:bg-yellow-600 text-gray-900 font-bold py-2 px-6 rounded-lg transition-colors">
                        Apply Filters
                    </button>
                    <a href="payment_history.php" class="ml-4 bg-gray-700 hover:bg-gray-600 text-white py-2 px-6 rounded-lg transition-colors">
                        Reset
                    </a>
                </div>
            </form>
        </div>

        <?php if (empty($payments)): ?>
            <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl p-8 text-center">
                <div class="text-yellow-400 text-5xl mb-4">
                    <i class="fas fa-receipt"></i>
                </div>
                <h3 class="text-2xl font-bold text-white mb-2">No Payment History Found</h3>
                <p class="text-gray-400 mb-6">
                    <?php if ($status_filter !== 'all' || $date_filter !== 'all' || $gym_filter > 0): ?>
                        No payments match your current filters. Try adjusting your search criteria.
                    <?php else: ?>
                        You haven't made any payments yet. Start your fitness journey today!
                    <?php endif; ?>
                </p>
                <?php if ($status_filter !== 'all' || $date_filter !== 'all' || $gym_filter > 0): ?>
                    <a href="payment_history.php" class="inline-block bg-gray-700 hover:bg-gray-600 text-white px-6 py-3 rounded-lg transition-colors">
                        Clear Filters
                    </a>
                <?php else: ?>
                    <a href="all-gyms.php" class="inline-block bg-yellow-500 hover:bg-yellow-600 text-gray-900 font-bold px-6 py-3 rounded-lg transition-colors">
                        Browse Gyms
                    </a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="space-y-6">
                <?php foreach ($payments as $payment): ?>
                    <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl overflow-hidden transform hover:scale-[1.01] transition-transform duration-300">
                        <!-- Header Section -->
                        <div class="p-6 bg-gradient-to-r from-yellow-400 to-yellow-500">
                            <div class="flex justify-between items-center">
                                <div class="flex items-center">
                                    <?php if (!empty($payment['cover_photo'])): ?>
                                        <div class="w-10 h-10 rounded-full overflow-hidden mr-4 border-2 border-gray-900">
                                            <img src="./gym/uploads/gym_images/<?php echo htmlspecialchars($payment['cover_photo']); ?>" 
                                                 alt="<?php echo htmlspecialchars($payment['gym_name']); ?>" 
                                                 class="w-full h-full object-cover">
                                        </div>
                                    <?php endif; ?>
                                    <h2 class="text-xl font-bold text-gray-900">
                                        <?php echo htmlspecialchars($payment['gym_name']); ?>
                                    </h2>
                                </div>
                                <span class="px-4 py-1 rounded-full text-sm font-medium
                                    <?php echo $payment['status'] === 'completed' 
                                        ? 'bg-green-900 text-green-100' 
                                        : ($payment['status'] === 'pending' 
                                            ? 'bg-yellow-900 text-yellow-100' 
                                            : 'bg-red-900 text-red-100'); ?>">
                                    <?php echo ucfirst($payment['status']); ?>
                                </span>
                            </div>
                        </div>

                        <!-- Details Section -->
                        <div class="p-6">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div class="space-y-4">
                                    <div>
                                        <label class="text-yellow-400 text-sm">Plan Details</label>
                                        <p class="text-white text-lg font-medium">
                                            <?php echo htmlspecialchars($payment['plan_name']); ?> - 
                                            <?php echo htmlspecialchars($payment['duration']); ?>
                                        </p>
                                    </div>
                                    <div>
                                        <label class="text-yellow-400 text-sm">Membership Period</label>
                                        <p class="text-white">
                                            <?php echo date('d M Y', strtotime($payment['start_date'])); ?> - 
                                            <?php echo date('d M Y', strtotime($payment['end_date'])); ?>
                                            <span class="text-gray-400 text-sm ml-2">
                                                (<?php echo $payment['total_days']; ?> days)
                                            </span>
                                        </p>
                                    </div>
                                    <div>
                                        <label class="text-yellow-400 text-sm">Payment Method</label>
                                        <p class="text-white flex items-center">
                                            <?php if ($payment['payment_method'] === 'card'): ?>
                                                <i class="fas fa-credit-card mr-2"></i>
                                            <?php elseif ($payment['payment_method'] === 'upi'): ?>
                                                <i class="fas fa-mobile-alt mr-2"></i>
                                            <?php else: ?>
                                                <i class="fas fa-money-bill-wave mr-2"></i>
                                            <?php endif; ?>
                                            <?php echo ucfirst($payment['payment_method']); ?>
                                        </p>
                                    </div>
                                </div>
                                <div class="space-y-4">
                                    <div>
                                        <label class="text-yellow-400 text-sm">Payment Date</label>
                                        <p class="text-white">
                                            <?php echo date('d M Y, h:i A', strtotime($payment['payment_date'])); ?>
                                        </p>
                                    </div>
                                    <div>
                                        <label class="text-yellow-400 text-sm">Transaction ID</label>
                                        <p class="text-white text-sm font-mono">
                                            <?php echo $payment['transaction_id'] ? htmlspecialchars($payment['transaction_id']) : 'N/A'; ?>
                                        </p>
                                    </div>
                                    <div>
                                        <label class="text-yellow-400 text-sm">Amount Paid</label>
                                        <p class="text-2xl font-bold text-white">
                                            ₹<?php echo number_format($payment['amount'], 2); ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Action Buttons -->
                            <div class="mt-6 pt-4 border-t border-gray-700 flex justify-between">
                                <a href="gym-profile.php?id=<?php echo $payment['gym_id']; ?>" 
                                   class="bg-gray-700 hover:bg-gray-600 text-white py-2 px-4 rounded-lg transition-colors">
                                    <i class="fas fa-dumbbell mr-2"></i> View Gym
                                </a>
                                
                                <?php if ($payment['status'] === 'completed'): ?>
                                    <a href="invoice.php?payment_id=<?php echo $payment['id']; ?>" 
                                       class="bg-blue-600 hover:bg-blue-700 text-white py-2 px-4 rounded-lg transition-colors">
                                        <i class="fas fa-file-invoice mr-2"></i> View Invoice
                                    </a>
                                <?php elseif ($payment['status'] === 'pending'): ?>
                                    <a href="process_payment.php?payment_id=<?php echo $payment['id']; ?>" 
                                       class="bg-yellow-500 hover:bg-yellow-600 text-gray-900 font-bold py-2 px-4 rounded-lg transition-colors">
                                        <i class="fas fa-credit-card mr-2"></i> Complete Payment
                                    </a>
                                <?php elseif ($payment['status'] === 'failed'): ?>
                                    <a href="process_payment.php?payment_id=<?php echo $payment['id']; ?>&retry=1" 
                                       class="bg-red-600 hover:bg-red-700 text-white py-2 px-4 rounded-lg transition-colors">
                                        <i class="fas fa-redo mr-2"></i> Retry Payment
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Export Options -->
            <div class="mt-8 flex justify-end">
                <div class="dropdown relative">
                    <button class="bg-gray-700 hover:bg-gray-600 text-white py-2 px-4 rounded-lg transition-colors flex items-center" id="exportDropdown">
                        <i class="fas fa-download mr-2"></i> Export
                        <i class="fas fa-chevron-down ml-2"></i>
                    </button>
                    <div class="dropdown-menu hidden absolute right-0 mt-2 w-48 bg-gray-800 rounded-lg shadow-lg z-10" id="exportMenu">
                        <a href="export_payments.php?format=pdf<?php echo $status_filter !== 'all' ? '&status='.$status_filter : ''; ?><?php echo $date_filter !== 'all' ? '&date_range='.$date_filter : ''; ?><?php echo $gym_filter > 0 ? '&gym_id='.$gym_filter : ''; ?>" 
                           class="block px-4 py-2 text-white hover:bg-gray-700 rounded-t-lg">
                            <i class="fas fa-file-pdf mr-2 text-red-400"></i> Export as PDF
                        </a>
                        <a href="export_payments.php?format=csv<?php echo $status_filter !== 'all' ? '&status='.$status_filter : ''; ?><?php echo $date_filter !== 'all' ? '&date_range='.$date_filter : ''; ?><?php echo $gym_filter > 0 ? '&gym_id='.$gym_filter : ''; ?>" 
                           class="block px-4 py-2 text-white hover:bg-gray-700">
                            <i class="fas fa-file-csv mr-2 text-green-400"></i> Export as CSV
                        </a>
                        <a href="export_payments.php?format=excel<?php echo $status_filter !== 'all' ? '&status='.$status_filter : ''; ?><?php echo $date_filter !== 'all' ? '&date_range='.$date_filter : ''; ?><?php echo $gym_filter > 0 ? '&gym_id='.$gym_filter : ''; ?>" 
                           class="block px-4 py-2 text-white hover:bg-gray-700 rounded-b-lg">
                            <i class="fas fa-file-excel mr-2 text-blue-400"></i> Export as Excel
                        </a>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Handle export dropdown
        const exportDropdown = document.getElementById('exportDropdown');
        const exportMenu = document.getElementById('exportMenu');
        
        if (exportDropdown && exportMenu) {
            exportDropdown.addEventListener('click', function() {
                exportMenu.classList.toggle('hidden');
            });
            
            // Close dropdown when clicking outside
            document.addEventListener('click', function(event) {
                if (!exportDropdown.contains(event.target) && !exportMenu.contains(event.target)) {
                    exportMenu.classList.add('hidden');
                }
            });
        }
        
        // Auto-submit form when filters change
        const filterForm = document.querySelector('form');
        const filterSelects = document.querySelectorAll('select[name="status"], select[name="date_range"], select[name="gym_id"]');
        
        filterSelects.forEach(select => {
            select.addEventListener('change', function() {
                filterForm.submit();
            });
        });
    });
</script>

<?php include 'includes/footer.php'; ?>

