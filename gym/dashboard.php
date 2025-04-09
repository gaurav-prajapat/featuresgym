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

// Get Gym Details
$stmt = $conn->prepare("SELECT *, is_open FROM gyms WHERE owner_id = ?");
$stmt->execute([$owner_id]);
$gym = $stmt->fetch(PDO::FETCH_ASSOC);

$gym_id = ($gym && isset($gym['gym_id'])) ? $gym['gym_id'] : null;

// Check for notifications
$notifications = [];
if ($gym_id) {
    $stmt = $conn->prepare("SELECT * FROM notifications WHERE gym_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([$gym_id]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Analytics Data
$analytics = [
    'daily_visits' => 0,
    'active_members' => 0,
    'monthly_revenue' => 0,
    'total_revenue' => 0
];

// Get Daily Visits
$stmt = $conn->prepare("
    SELECT COUNT(*) as count 
    FROM schedules 
    WHERE gym_id = ? 
    AND DATE(start_date) = CURRENT_DATE
");
$stmt->execute([$gym_id]);
$analytics['daily_visits'] = $stmt->fetchColumn();

// Get Active Members
$stmt = $conn->prepare("
    SELECT COUNT(DISTINCT user_id) as count 
    FROM user_memberships 
    WHERE gym_id = ? 
    AND status = 'active'
");
$stmt->execute([$gym_id]);
$analytics['active_members'] = $stmt->fetchColumn();

// Get Monthly Revenue
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(amount), 0) as total 
    FROM gym_revenue 
    WHERE gym_id = ? 
    AND MONTH(date) = MONTH(CURRENT_DATE)
    AND YEAR(date) = YEAR(CURRENT_DATE)
");
$stmt->execute([$gym_id]);
$analytics['monthly_revenue'] = $stmt->fetchColumn();

// Get Total Revenue
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(amount), 0) as total 
    FROM gym_revenue 
    WHERE gym_id = ?
");
$stmt->execute([$gym_id]);
$analytics['total_revenue'] = $stmt->fetchColumn();

// Current Time Slot Occupancy
$stmt = $conn->prepare("
    SELECT COUNT(*) as count 
    FROM schedules 
    WHERE gym_id = ? 
    AND DATE(start_date) = CURRENT_DATE 
    AND HOUR(start_date) = HOUR(CURRENT_TIME)
");
$stmt->execute([$gym_id]);
$currentSlotOccupancy = $stmt->fetchColumn();

// Daily Activity (Hour-wise visits)
$stmt = $conn->prepare("
    SELECT HOUR(start_date) as hour, COUNT(*) as visit_count 
    FROM schedules 
    WHERE gym_id = ? 
    AND DATE(start_date) = CURRENT_DATE 
    GROUP BY HOUR(start_date)
");
$stmt->execute([$gym_id]);
$dailyActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Today's Class Bookings
$stmt = $conn->prepare("
    SELECT s.*, u.username 
    FROM schedules s 
    JOIN users u ON s.user_id = u.id 
    WHERE s.gym_id = ? 
    AND DATE(s.start_date) = CURRENT_DATE 
    ORDER BY s.start_date ASC
");
$stmt->execute([$gym_id]);
$todayBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Equipment Status
$stmt = $conn->prepare("
    SELECT 
        name as name,
        quantity as total
    FROM gym_equipment
    WHERE gym_id = ?
");
$stmt->execute([$gym_id]);
$equipmentStatus = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent Reviews
$stmt = $conn->prepare("
    SELECT r.*, u.username 
    FROM reviews r 
    JOIN users u ON r.user_id = u.id 
    WHERE r.gym_id = ? 
    ORDER BY r.created_at DESC 
    LIMIT 5
");
$stmt->execute([$gym_id]);
$recentReviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Membership Distribution
$stmt = $conn->prepare("
    SELECT 
        gmp.plan_name,
        COUNT(*) as member_count,
        (COUNT(*) * 100.0 / (
            SELECT COUNT(*) 
            FROM user_memberships 
            WHERE gym_id = ? 
            AND status = 'active'
        )) as percentage
    FROM user_memberships um
    JOIN gym_membership_plans gmp ON um.plan_id = gmp.plan_id
    WHERE um.gym_id = ? 
    AND um.status = 'active'
    GROUP BY gmp.plan_id, gmp.plan_name
");
$stmt->execute([$gym_id, $gym_id]);
$membershipDistribution = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Member Growth Trend
$stmt = $conn->prepare("
    SELECT 
        DATE_FORMAT(created_at, '%Y-%m') as month,
        COUNT(*) as new_members
    FROM user_memberships
    WHERE gym_id = ?
    GROUP BY month
    ORDER BY month DESC
    LIMIT 6
");
$stmt->execute([$gym_id]);
$memberGrowth = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Peak Days Analysis
$stmt = $conn->prepare("
    SELECT 
        DAYNAME(start_date) as day,
        COUNT(*) as visit_count
    FROM schedules
    WHERE gym_id = ?
    AND start_date >= DATE_SUB(CURRENT_DATE, INTERVAL 30 DAY)
    GROUP BY day
    ORDER BY visit_count DESC
");
$stmt->execute([$gym_id]);
$peakDays = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Revenue by Plan Type
$stmt = $conn->prepare("
    SELECT 
        gmp.plan_name,
        SUM(gr.amount) as revenue,
        COUNT(um.user_id) as subscribers
    FROM gym_revenue gr
    JOIN user_memberships um ON gr.id = um.id
    JOIN gym_membership_plans gmp ON um.plan_id = gmp.plan_id
    WHERE gr.gym_id = ?
    AND MONTH(gr.date) = MONTH(CURRENT_DATE)
    GROUP BY gmp.plan_id
");
$stmt->execute([$gym_id]);
$planRevenue = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Member Retention Rate
$stmt = $conn->prepare("
    SELECT 
        COUNT(CASE WHEN status = 'active' AND DATEDIFF(end_date, start_date) > 180 THEN 1 END) * 100.0 / COUNT(*) as retention_rate
    FROM user_memberships
    WHERE gym_id = ?
");
$stmt->execute([$gym_id]);
$retentionRate = $stmt->fetchColumn();

// Age Demographics
$stmt = $conn->prepare("
    SELECT 
        CASE 
            WHEN age < 25 THEN 'Under 25'
            WHEN age BETWEEN 25 AND 34 THEN '25-34'
            WHEN age BETWEEN 35 AND 44 THEN '35-44'
            ELSE '45+'
        END as age_group,
        COUNT(*) as member_count
    FROM users u
    JOIN user_memberships um ON u.id = um.user_id
    WHERE um.gym_id = ? AND um.status = 'active'
    GROUP BY age_group
");
$stmt->execute([$gym_id]);
$ageDemo = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent activity log
$stmt = $conn->prepare("
    SELECT al.*, u.username 
    FROM activity_logs al
    LEFT JOIN users u ON al.user_id = u.id
    WHERE al.user_type = 'owner' AND al.user_id = ?
    ORDER BY al.created_at DESC
    LIMIT 10
");
$stmt->execute([$owner_id]);
$recentActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get Daily Visit Earnings (Today)
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(daily_rate), 0) as total 
    FROM schedules 
    WHERE gym_id = ? 
    AND activity_type = 'gym_visit'
    AND status = 'completed'
    AND payment_status = 'paid'
    AND DATE(start_date) = CURRENT_DATE
");
$stmt->execute([$gym_id]);
$dailyVisitEarningsToday = $stmt->fetchColumn();

// Get Total Earnings from Daily Visits (All time)
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(daily_rate), 0) as total 
    FROM schedules 
    WHERE gym_id = ? 
    AND activity_type = 'gym_visit'
    AND status = 'completed'
    AND payment_status = 'paid'
");
$stmt->execute([$gym_id]);
$totalVisitEarnings = $stmt->fetchColumn();

// Get Monthly Visit Earnings
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(daily_rate), 0) as total 
    FROM schedules 
    WHERE gym_id = ? 
    AND activity_type = 'gym_visit'
    AND status = 'completed'
    AND payment_status = 'paid'
    AND MONTH(start_date) = MONTH(CURRENT_DATE)
    AND YEAR(start_date) = YEAR(CURRENT_DATE)
");
$stmt->execute([$gym_id]);
$monthlyVisitEarnings = $stmt->fetchColumn();

// Get Pending Visit Payments
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(daily_rate), 0) as total 
    FROM schedules 
    WHERE gym_id = ? 
    AND activity_type = 'gym_visit'
    AND status = 'completed'
    AND payment_status = 'pending'
");
$stmt->execute([$gym_id]);
$pendingVisitPayments = $stmt->fetchColumn();

// Get Visit Earnings by Activity Type (This Month)
$stmt = $conn->prepare("
    SELECT 
        activity_type,
        COUNT(*) as visit_count,
        COALESCE(SUM(daily_rate), 0) as total_amount
    FROM schedules 
    WHERE gym_id = ? 
    AND status = 'completed'
    AND payment_status = 'paid'
    AND MONTH(start_date) = MONTH(CURRENT_DATE)
    AND YEAR(start_date) = YEAR(CURRENT_DATE)
    GROUP BY activity_type
");
$stmt->execute([$gym_id]);
$earningsByActivityType = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get Total Withdrawals
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(amount), 0) as total 
    FROM withdrawals 
    WHERE gym_id = ? 
    AND status = 'completed'
");
$stmt->execute([$gym_id]);
$totalWithdrawals = $stmt->fetchColumn();

// Get Pending Withdrawals
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(amount), 0) as total 
    FROM withdrawals 
    WHERE gym_id = ? 
    AND status = 'pending'
");
$stmt->execute([$gym_id]);
$pendingWithdrawals = $stmt->fetchColumn();

// Get Available Balance
$availableBalance = $analytics['total_revenue'] - $totalWithdrawals - $pendingWithdrawals;

// Get Monthly Earnings Breakdown by Source
$stmt = $conn->prepare("
    SELECT 
        source_type,
        SUM(amount) as total_amount
    FROM gym_revenue 
    WHERE gym_id = ? 
    AND MONTH(date) = MONTH(CURRENT_DATE)
    AND YEAR(date) = YEAR(CURRENT_DATE)
    GROUP BY source_type
");
$stmt->execute([$gym_id]);
$monthlyEarningsBreakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get Recent Transactions
$stmt = $conn->prepare("
    SELECT 
        'revenue' as type,
        id,
        amount,
        source_type as description,
        date as transaction_date
    FROM gym_revenue
    WHERE gym_id = ?
    
    UNION ALL
    
    SELECT 
        'withdrawal' as type,
        id,
        amount,
        status as description,
        created_at as transaction_date
    FROM withdrawals
    WHERE gym_id = ?
    
    ORDER BY transaction_date DESC
    LIMIT 10
");
$stmt->execute([$gym_id, $gym_id]);
$recentTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);


if (!$gym): ?>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.10.2/dist/cdn.min.js" defer></script>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <div class="min-h-screen bg-gray-100 py-12 pt-12">
        <div class="max-w-3xl mx-auto px-4">
            <div class="bg-white rounded-lg shadow-lg p-8 mt-10 text-center">
                <h1 class="text-2xl font-bold mb-4">Welcome to Gym Management System</h1>
                <p class="text-gray-600 mb-6">Let\'s get started by setting up your gym profile.</p>

                <div class="space-y-4">
                    <svg class="w-64 h-64 mx-auto text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                    </svg>
                    <a href="add_gym.php"
                        class="inline-block bg-blue-600 text-white px-6 py-3 rounded-lg font-semibold hover:bg-blue-700 transition duration-200">
                        Create Your Gym Profile
                    </a>
                </div>
            </div>
        </div>
    </div>
<?php else:
    ?>
    <div class="container mx-auto px-4 pt-20">
        <!-- Header Section -->
        <div class="bg-white rounded-xl shadow-lg overflow-hidden mb-8">
            <div class="p-6 bg-gradient-to-r from-gray-900 to-gray-800">
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-4">
                        <div class="h-16 w-16 rounded-full bg-yellow-500 flex items-center justify-center">
                            <i class="fas fa-dumbbell text-2xl text-white"></i>
                        </div>
                        <div>
                            <h1 class="text-2xl font-bold text-white"><?php echo htmlspecialchars($gym['name']); ?></h1>
                            <p class="text-white ">Dashboard Overview</p>
                        </div>
                    </div>
                    <div class="flex items-center space-x-4">
                        <!-- Gym Status Toggle -->
                        <div class="flex items-center">
                            <span class="text-white mr-2">Gym Status:</span>
                            <button id="gymStatusToggle"
                                class="px-4 py-2 rounded-full font-medium transition-colors duration-200 <?php echo ($gym['is_open'] ? 'bg-green-500 hover:bg-green-600' : 'bg-red-500 hover:bg-red-600'); ?> text-white">
                                <?php echo ($gym['is_open'] ? 'Open' : 'Closed'); ?>
                            </button>
                        </div>
                        <div class="text-white text-right">
                            <p class="text-sm">Today's Date</p>
                            <p class="text-xl font-bold"><?php echo date('d M Y'); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Financial Overview Section -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-8 hidden">
            <h3 class="text-xl font-semibold mb-6 flex items-center">
                <i class="fas fa-money-bill-alt text-yellow-500 mr-2"></i>
                Visit's Overview
            </h3>

            <!-- Financial Stats Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <!-- Daily Visit Earnings Today -->
                <div class="bg-gray-50 rounded-lg p-4">
                    <p class="text-sm text-gray-500">Daily Visit Earnings (Today)</p>
                    <p class="text-2xl font-bold text-green-600">₹<?= number_format($dailyVisitEarningsToday, 2) ?></p>
                </div>

                <!-- Monthly Visit Earnings -->
                <div class="bg-gray-50 rounded-lg p-4">
                    <p class="text-sm text-gray-500">Monthly Visit Earnings</p>
                    <p class="text-2xl font-bold text-green-600">₹<?= number_format($monthlyVisitEarnings, 2) ?></p>
                </div>

                <!-- Total Visit Earnings -->
                <div class="bg-gray-50 rounded-lg p-4">
                    <p class="text-sm text-gray-500">Total Visit Earnings</p>
                    <p class="text-2xl font-bold text-green-600">₹<?= number_format($totalVisitEarnings, 2) ?></p>
                </div>

                <!-- Pending Visit Payments -->
                <div class="bg-gray-50 rounded-lg p-4">
                    <p class="text-sm text-gray-500">Pending Visit Payments</p>
                    <p class="text-2xl font-bold text-orange-500">₹<?= number_format($pendingVisitPayments, 2) ?></p>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Earnings by Activity Type -->
                <div>
                    <h4 class="text-lg font-medium mb-4">Earnings by Activity Type (This Month)</h4>
                    <?php if (empty($earningsByActivityType)): ?>
                        <p class="text-gray-500">No earnings recorded this month</p>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php
                            $totalMonthly = array_sum(array_column($earningsByActivityType, 'total_amount'));
                            foreach ($earningsByActivityType as $earning):
                                $percentage = $totalMonthly > 0 ? ($earning['total_amount'] / $totalMonthly) * 100 : 0;

                                // Set color based on activity type
                                $barColor = 'bg-blue-600';
                                if ($earning['activity_type'] == 'gym_visit') {
                                    $barColor = 'bg-green-600';
                                } elseif ($earning['activity_type'] == 'class') {
                                    $barColor = 'bg-purple-600';
                                } elseif ($earning['activity_type'] == 'personal_training') {
                                    $barColor = 'bg-yellow-600';
                                }
                                ?>
                                <div>
                                    <div class="flex justify-between mb-1">
                                        <span class="text-sm font-medium">
                                            <?= ucwords(str_replace('_', ' ', $earning['activity_type'])) ?>
                                            <span class="text-gray-500">(<?= $earning['visit_count'] ?> visits)</span>
                                        </span>
                                        <span class="text-sm">₹<?= number_format($earning['total_amount'], 2) ?>
                                            (<?= number_format($percentage, 1) ?>%)</span>
                                    </div>
                                    <div class="w-full bg-gray-200 rounded-full h-2.5">
                                        <div class="<?= $barColor ?> h-2.5 rounded-full" style="width: <?= $percentage ?>%"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Recent Visit Transactions -->
                <div>
                    <div class="flex justify-between items-center mb-4">
                        <h4 class="text-lg font-medium">Recent Visit Transactions</h4>
                        <a href="visit_transactions.php" class="text-sm text-blue-500 hover:text-blue-700">View All</a>
                    </div>

                    <?php
                    // Get Recent Visit Transactions
                    $stmt = $conn->prepare("
                SELECT 
                    s.id,
                    s.daily_rate as amount,
                    s.activity_type,
                    s.payment_status,
                    s.start_date as transaction_date,
                    u.username
                FROM schedules s
                JOIN users u ON s.user_id = u.id
                WHERE s.gym_id = ? 
                AND s.status = 'completed'
                ORDER BY s.start_date DESC
                LIMIT 5
            ");
                    $stmt->execute([$gym_id]);
                    $recentVisitTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    ?>

                    <?php if (empty($recentVisitTransactions)): ?>
                        <p class="text-gray-500">No visit transactions recorded</p>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th
                                            class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Member</th>
                                        <th
                                            class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Amount</th>
                                        <th
                                            class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Type</th>
                                        <th
                                            class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Status</th>
                                        <th
                                            class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Date</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($recentVisitTransactions as $transaction): ?>
                                        <tr>
                                            <td class="px-4 py-2 whitespace-nowrap text-sm font-medium">
                                                <?= htmlspecialchars($transaction['username']) ?>
                                            </td>
                                            <td class="px-4 py-2 whitespace-nowrap text-sm">
                                                ₹<?= number_format($transaction['amount'], 2) ?>
                                            </td>
                                            <td class="px-4 py-2 whitespace-nowrap text-sm">
                                                <?= ucwords(str_replace('_', ' ', $transaction['activity_type'])) ?>
                                            </td>
                                            <td class="px-4 py-2 whitespace-nowrap">
                                                <span
                                                    class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                            <?= $transaction['payment_status'] === 'paid' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' ?>">
                                                    <?= ucfirst($transaction['payment_status']) ?>
                                                </span>
                                            </td>
                                            <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-500">
                                                <?= date('M d, Y', strtotime($transaction['transaction_date'])) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Financial Overview Section -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-8 hidden">
            <h3 class="text-xl font-semibold mb-6 flex items-center">
                <i class="fas fa-money-bill-alt text-yellow-500 mr-2"></i>
                Withdrawal Overview
            </h3>

            <!-- Financial Stats Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">

                <!-- Total Withdrawals -->
                <div class="bg-gray-50 rounded-lg p-4">
                    <p class="text-sm text-gray-500">Total Withdrawals</p>
                    <p class="text-2xl font-bold text-blue-600">₹<?= number_format($totalWithdrawals, 2) ?></p>
                </div>

                <!-- Available Balance -->
                <div class="bg-gray-50 rounded-lg p-4">
                    <p class="text-sm text-gray-500">Available Balance</p>
                    <p class="text-2xl font-bold text-indigo-600">₹<?= number_format($availableBalance, 2) ?></p>
                    <?php if ($availableBalance > 0): ?>
                        <a href="withdraw.php" class="text-xs text-blue-500 hover:text-blue-700">Request Withdrawal</a>
                    <?php endif; ?>
                </div>
            </div>


            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Monthly Earnings Breakdown -->
                <div>
                    <h4 class="text-lg font-medium mb-4">Monthly Earnings Breakdown</h4>
                    <?php if (empty($monthlyEarningsBreakdown)): ?>
                        <p class="text-gray-500">No earnings recorded this month</p>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php
                            $totalMonthly = array_sum(array_column($monthlyEarningsBreakdown, 'total_amount'));
                            foreach ($monthlyEarningsBreakdown as $earning):
                                $percentage = ($earning['total_amount'] / $totalMonthly) * 100;
                                ?>
                                <div>
                                    <div class="flex justify-between mb-1">
                                        <span class="text-sm font-medium"><?= ucfirst($earning['source_type']) ?></span>
                                        <span class="text-sm">₹<?= number_format($earning['total_amount'], 2) ?>
                                            (<?= number_format($percentage, 1) ?>%)</span>
                                    </div>
                                    <div class="w-full bg-gray-200 rounded-full h-2.5">
                                        <div class="bg-blue-600 h-2.5 rounded-full" style="width: <?= $percentage ?>%"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Recent Transactions -->
                <div>
                    <div class="flex justify-between items-center mb-4">
                        <h4 class="text-lg font-medium">Recent Transactions</h4>
                        <a href="transactions.php" class="text-sm text-blue-500 hover:text-blue-700">View All</a>
                    </div>

                    <?php if (empty($recentTransactions)): ?>
                        <p class="text-gray-500">No transactions recorded</p>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th
                                            class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Type</th>
                                        <th
                                            class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Amount</th>
                                        <th
                                            class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Details</th>
                                        <th
                                            class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Date</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($recentTransactions as $transaction): ?>
                                        <tr>
                                            <td class="px-4 py-2 whitespace-nowrap">
                                                <span
                                                    class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                            <?= $transaction['type'] === 'revenue' ? 'bg-green-100 text-green-800' : 'bg-blue-100 text-blue-800' ?>">
                                                    <?= ucfirst($transaction['type']) ?>
                                                </span>
                                            </td>
                                            <td class="px-4 py-2 whitespace-nowrap text-sm">
                                                ₹<?= number_format($transaction['amount'], 2) ?>
                                            </td>
                                            <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-500">
                                                <?= ucfirst($transaction['description']) ?>
                                            </td>
                                            <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-500">
                                                <?= date('M d, Y', strtotime($transaction['transaction_date'])) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($pendingWithdrawals > 0): ?>
                <div class="mt-6 p-4 bg-yellow-50 border-l-4 border-yellow-400 rounded-md">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas fa-exclamation-circle text-yellow-400"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm text-yellow-700">
                                You have pending withdrawals of ₹<?= number_format($pendingWithdrawals, 2) ?>.
                                <a href="withdrawals.php" class="font-medium underline text-yellow-700 hover:text-yellow-600">
                                    Check status
                                </a>
                            </p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <!-- Notification Center -->
        <?php if (!empty($notifications)): ?>
            <div class="bg-white rounded-xl shadow-lg p-6 mb-8">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold flex items-center">
                        <i class="fas fa-bell text-yellow-500 mr-2"></i>
                        Notifications
                    </h3>
                    <a href="notifications.php" class="text-blue-500 hover:text-blue-700 text-sm">View All</a>
                </div>
                <div class="space-y-3">
                    <?php foreach ($notifications as $notification): ?>
                        <div class="p-3 bg-blue-50 rounded-lg border-l-4 border-blue-500">
                            <div class="flex justify-between">
                                <h4 class="font-medium text-gray-700"><?= htmlspecialchars($notification['title']) ?></h4>
                                <span
                                    class="text-xs text-gray-500"><?= date('M d, H:i', strtotime($notification['created_at'])) ?></span>
                            </div>
                            <p class="text-sm text-gray-600 mt-1"><?= htmlspecialchars($notification['message']) ?></p>
                            <div class="mt-2 text-right">
                                <button class="mark-read-btn text-xs text-blue-600 hover:text-blue-800"
                                    data-id="<?= $notification['id'] ?>">
                                    Mark as read
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Quick Stats Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <!-- Daily Visits -->
            <div class="bg-white rounded-xl shadow-lg p-6 transform hover:scale-105 transition-transform duration-200">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-gray-500 text-sm font-medium">Daily Visits</h3>
                    <span class="bg-blue-100 text-blue-800 text-xs font-medium px-2.5 py-0.5 rounded">Today</span>
                </div>
                <div class="flex items-center">
                    <div class="h-12 w-12 rounded-full bg-blue-100 flex items-center justify-center mr-4">
                        <i class="fas fa-users text-blue-600 text-xl"></i>
                    </div>
                    <p class="text-3xl font-bold text-gray-900"><?php echo number_format($analytics['daily_visits']); ?></p>
                </div>
            </div>

            <!-- Active Members -->
            <div class="bg-white rounded-xl shadow-lg p-6 transform hover:scale-105 transition-transform duration-200">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-gray-500 text-sm font-medium">Active Members</h3>
                    <span class="bg-green-100 text-green-800 text-xs font-medium px-2.5 py-0.5 rounded">Current</span>
                </div>
                <div class="flex items-center">
                    <div class="h-12 w-12 rounded-full bg-green-100 flex items-center justify-center mr-4">
                        <i class="fas fa-user-check text-green-600 text-xl"></i>
                    </div>
                    <p class="text-3xl font-bold text-gray-900"><?php echo number_format($analytics['active_members']); ?>
                    </p>
                </div>
            </div>

            <!-- Monthly Revenue -->
            <div class="bg-white rounded-xl shadow-lg p-6 transform hover:scale-105 transition-transform duration-200">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-gray-500 text-sm font-medium">Monthly Revenue</h3>
                    <span class="bg-purple-100 text-purple-800 text-xs font-medium px-2.5 py-0.5 rounded">This Month</span>
                </div>
                <div class="flex items-center">
                    <div class="h-12 w-12 rounded-full bg-purple-100 flex items-center justify-center mr-4">
                        <i class="fas fa-rupee-sign text-purple-600 text-xl"></i>
                    </div>
                    <p class="text-3xl font-bold text-gray-900">₹<?= number_format($monthlyVisitEarnings, 2) ?>
                    </p>
                </div>
            </div>

            <!-- Total Revenue -->
            <div class="bg-white rounded-xl shadow-lg p-6 transform hover:scale-105 transition-transform duration-200">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-gray-500 text-sm font-medium">Total Revenue</h3>
                    <span class="bg-yellow-100 text-yellow-800 text-xs font-medium px-2.5 py-0.5 rounded">All Time</span>
                </div>
                <div class="flex items-center">
                    <div class="h-12 w-12 rounded-full bg-yellow-100 flex items-center justify-center mr-4">
                        <i class="fas fa-chart-line text-yellow-600 text-xl"></i>
                    </div>
                    <p class="text-3xl font-bold text-gray-900">₹<?php echo number_format($analytics['total_revenue']); ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Current Occupancy Section -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
            <!-- Current Time Slot -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h3 class="text-lg font-semibold mb-4 flex items-center">
                    <i class="fas fa-clock text-yellow-500 mr-2"></i>
                    Current Time Slot Occupancy
                </h3>
                <div class="flex items-center justify-between">
                    <div class="flex-1">
                        <div class="h-4 bg-gray-200 rounded-full">
                            <div class="h-4 bg-yellow-500 rounded-full"
                                style="width: <?php echo min(($currentSlotOccupancy / max(1, $gym['capacity'])) * 100, 100); ?>%">
                            </div>
                        </div>
                    </div>
                    <span
                        class="ml-4 text-2xl font-bold"><?php echo $currentSlotOccupancy; ?>/<?php echo $gym['capacity']; ?></span>
                </div>
                <p class="text-sm text-gray-500 mt-2">Current hour capacity utilization</p>
            </div>

            <!-- Peak Hours -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h3 class="text-lg font-semibold mb-4 flex items-center">
                    <i class="fas fa-chart-bar text-yellow-500 mr-2"></i>
                    Peak Hours Today
                </h3>
                <div class="grid grid-cols-12 gap-2 h-32">
                    <?php
                    // Create an array for all hours (0-23)
                    $hourlyData = array_fill(0, 24, 0);

                    // Fill in the data we have
                    foreach ($dailyActivity as $activity) {
                        $hourlyData[$activity['hour']] = $activity['visit_count'];
                    }

                    // Get max value for scaling
                    $maxVisits = max($hourlyData) > 0 ? max($hourlyData) : 1;

                    // Display bars for business hours (6am-10pm)
                    for ($hour = 6; $hour < 22; $hour++):
                        $height = ($hourlyData[$hour] / $maxVisits) * 100;
                        $timeLabel = date('ga', strtotime($hour . ':00'));
                        $isCurrentHour = (int) date('G') === $hour;
                        $barClass = $isCurrentHour ? 'bg-yellow-500' : 'bg-blue-400';
                        ?>
                        <div class="flex flex-col items-center">
                            <div class="flex-1 w-full bg-gray-200 rounded-t relative">
                                <div class="absolute bottom-0 w-full <?php echo $barClass; ?> rounded-t transition-all duration-300"
                                    style="height: <?php echo $height; ?>%"></div>
                            </div>
                            <span
                                class="text-xs mt-1 <?php echo $isCurrentHour ? 'font-bold' : ''; ?>"><?php echo $timeLabel; ?></span>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>
        </div>

        <!-- Main Dashboard Content -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-8">
            <!-- Left Column -->
            <div class="lg:col-span-2 space-y-8">
                <!-- Today's Bookings -->
                <div class="bg-white rounded-xl shadow-lg p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-semibold flex items-center">
                            <i class="fas fa-calendar-alt text-yellow-500 mr-2"></i>
                            Today's Class Bookings
                        </h3>
                        <a href="booking.php" class="text-blue-500 hover:text-blue-700 text-sm">View All</a>
                    </div>

                    <?php if (empty($todayBookings)): ?>
                        <div class="text-center py-8">
                            <div class="text-gray-400 mb-2"><i class="fas fa-calendar-times text-4xl"></i></div>
                            <p class="text-gray-500">No bookings scheduled for today</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($todayBookings as $booking): ?>
                                <div class="flex items-center justify-between p-4 bg-gray-700 rounded-lg">
                                    <div class="flex items-center space-x-3">
                                        <div class="h-10 w-10 rounded-full bg-blue-100 flex items-center justify-center">
                                            <i class="fas fa-user text-blue-600"></i>
                                        </div>
                                        <div>
                                            <p class="font-medium"><?= htmlspecialchars($booking['username']) ?></p>
                                            <p class="text-sm text-gray-500"><?= date('h:i A', strtotime($booking['start_date'])) ?>
                                            </p>
                                        </div>
                                    </div>
                                    <span
                                        class="px-3 py-1 rounded-full text-sm <?= $booking['status'] === 'confirmed' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' ?>">
                                        <?= ucfirst($booking['status']) ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Recent Activity -->
                <div class="bg-white rounded-xl shadow-lg p-6">
                    <h3 class="text-lg font-semibold mb-4 flex items-center">
                        <i class="fas fa-history text-yellow-500 mr-2"></i>
                        Recent Activity
                    </h3>

                    <?php if (empty($recentActivity)): ?>
                        <div class="text-center py-8">
                            <div class="text-gray-400 mb-2"><i class="fas fa-history text-4xl"></i></div>
                            <p class="text-gray-500">No recent activity to display</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($recentActivity as $activity): ?>
                                <div class="flex items-start p-3 border-b border-gray-100">
                                    <div class="h-8 w-8 rounded-full bg-gray-100 flex items-center justify-center mr-3">
                                        <i class="fas fa-<?= getActivityIcon($activity['action']) ?> text-gray-600"></i>
                                    </div>
                                    <div class="flex-1">
                                        <div class="flex justify-between">
                                            <p class="text-sm font-medium">
                                                <?= $activity['username'] ? htmlspecialchars($activity['username']) : 'System' ?>
                                            </p>
                                            <span class="text-xs text-gray-500">
                                                <?= timeAgo($activity['created_at']) ?>
                                            </span>
                                        </div>
                                        <p class="text-sm text-gray-600"><?= htmlspecialchars($activity['details']) ?></p>
                                    </div>
                                </div>

                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Membership Distribution -->
                <div class="bg-white rounded-xl shadow-lg p-6">
                    <h3 class="text-lg font-semibold mb-4 flex items-center">
                        <i class="fas fa-chart-pie text-yellow-500 mr-2"></i>
                        Membership Distribution
                    </h3>

                    <?php if (empty($membershipDistribution)): ?>
                        <div class="text-center py-8">
                            <div class="text-gray-400 mb-2"><i class="fas fa-users-slash text-4xl"></i></div>
                            <p class="text-gray-500">No active memberships to display</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($membershipDistribution as $type): ?>
                                <div class="flex items-center justify-between p-4 bg-gray-700 rounded-lg">
                                    <div>
                                        <p class="font-medium"><?= htmlspecialchars($type['plan_name']) ?></p>
                                        <p class="text-sm text-gray-500"><?= $type['member_count'] ?> members</p>
                                    </div>
                                    <div class="flex items-center space-x-2">
                                        <div class="w-32 bg-gray-200 rounded-full h-2.5">
                                            <div class="bg-yellow-500 h-2.5 rounded-full"
                                                style="width: <?= $type['percentage'] ?>%">
                                            </div>
                                        </div>
                                        <span class="text-sm font-medium"><?= number_format($type['percentage'], 1) ?>%</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right Column -->
            <div class="space-y-8">
                <!-- Latest Reviews -->
                <div class="bg-white rounded-xl shadow-lg p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-semibold flex items-center">
                            <i class="fas fa-star text-yellow-500 mr-2"></i>
                            Latest Reviews
                        </h3>
                        <a href="reviews.php" class="text-blue-500 hover:text-blue-700 text-sm">View All</a>
                    </div>

                    <?php if (empty($recentReviews)): ?>
                        <div class="text-center py-8">
                            <div class="text-gray-400 mb-2"><i class="fas fa-star-half-alt text-4xl"></i></div>
                            <p class="text-gray-500">No reviews yet</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($recentReviews as $review): ?>
                                <div class="p-4 bg-gray-700 rounded-lg">
                                    <div class="flex items-center justify-between mb-2">
                                        <p class="font-medium"><?= htmlspecialchars($review['username']) ?></p>
                                        <div class="flex items-center">
                                            <?php for ($i = 0; $i < 5; $i++): ?>
                                                <i
                                                    class="fas fa-star <?= $i < $review['rating'] ? 'text-yellow-500' : 'text-gray-300' ?>"></i>
                                            <?php endfor; ?>
                                        </div>
                                    </div>
                                    <p class="text-gray-600"><?= htmlspecialchars($review['comment']) ?></p>
                                    <p class="text-xs text-gray-500 mt-2"><?= date('M d, Y', strtotime($review['created_at'])) ?>
                                    </p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Daily Revenue -->
                <div class="bg-white rounded-xl shadow-lg p-6">
                    <h3 class="text-lg font-semibold mb-4 flex items-center">
                        <i class="fas fa-money-bill-wave text-yellow-500 mr-2"></i>
                        Today's Revenue
                    </h3>

                    <?php if (empty($dailyVisitEarningsToday)): ?>
                        <div class="text-center py-8">
                            <div class="text-gray-400 mb-2"><i class="fas fa-coins text-4xl"></i></div>
                            <p class="text-gray-500">No revenue recorded today</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-4">
                            <div class="flex justify-between items-center p-3 bg-green-500 rounded-lg mt-4">
                                <span class="font-bold">Total Today's Revenue</span>
                                <span
                                    class="text-yellow-600 font-bold">₹<?php echo number_format($dailyVisitEarningsToday, 2); ?></span>
                            </div>

                        </div>
                    <?php endif; ?>
                </div>

                <!-- Member Demographics -->
                <div class="bg-white rounded-xl shadow-lg p-6">
                    <h3 class="text-lg font-semibold mb-4 flex items-center">
                        <i class="fas fa-users text-yellow-500 mr-2"></i>
                        Member Demographics
                    </h3>

                    <?php if (empty($ageDemo)): ?>
                        <div class="text-center py-8">
                            <div class="text-gray-400 mb-2"><i class="fas fa-user-friends text-4xl"></i></div>
                            <p class="text-gray-500">No member data available</p>
                        </div>
                    <?php else: ?>
                        <div class="grid grid-cols-2 gap-4 mb-4">
                            <!-- Retention Rate -->
                            <div class="p-4 bg-gray-700 rounded-lg text-center">
                                <p class="text-sm text-gray-500">Retention Rate</p>
                                <p
                                    class="text-3xl font-bold <?= $retentionRate > 70 ? 'text-green-600' : ($retentionRate > 50 ? 'text-yellow-600' : 'text-red-600') ?>">
                                    <?= number_format($retentionRate, 1) ?>%
                                </p>
                            </div>

                            <!-- Gender Distribution -->
                            <div class="p-4 bg-gray-700 rounded-lg text-center">
                                <p class="text-sm text-gray-500">Active Members</p>
                                <p class="text-3xl font-bold text-blue-600"><?= number_format($analytics['active_members']) ?>
                                </p>
                            </div>
                        </div>

                        <!-- Age Distribution -->
                        <div class="p-4 bg-gray-700 rounded-lg">
                            <p class="text-sm font-medium text-gray-700 mb-3">Age Distribution</p>
                            <?php
                            $totalMembers = array_sum(array_column($ageDemo, 'member_count'));
                            foreach ($ageDemo as $age):
                                $percentage = $totalMembers > 0 ? ($age['member_count'] / $totalMembers) * 100 : 0;
                                ?>
                                <div class="mb-2">
                                    <div class="flex justify-between items-center mb-1">
                                        <span class="text-sm"><?= $age['age_group'] ?></span>
                                        <span class="text-sm font-medium"><?= $age['member_count'] ?>
                                            (<?= number_format($percentage, 1) ?>%)</span>
                                    </div>
                                    <div class="w-full bg-gray-200 rounded-full h-2">
                                        <div class="bg-blue-600 h-2 rounded-full" style="width: <?= $percentage ?>%"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Peak Days Analysis -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h3 class="text-lg font-semibold mb-4">
                    <i class="fas fa-calendar-week text-yellow-500 mr-2"></i>
                    Peak Days (Last 30 Days)
                </h3>

                <?php if (empty($peakDays)): ?>
                    <div class="text-center py-8">
                        <div class="text-gray-400 mb-2"><i class="fas fa-calendar text-4xl"></i></div>
                        <p class="text-gray-500">No visit data available</p>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-7 gap-2">
                        <?php
                        // Create an array for all days of the week
                        $daysOfWeek = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                        $dayData = array_fill_keys($daysOfWeek, 0);

                        // Fill in the data we have
                        foreach ($peakDays as $day) {
                            $dayData[$day['day']] = $day['visit_count'];
                        }

                        // Get max value for scaling
                        $maxVisits = max($dayData) > 0 ? max($dayData) : 1;

                        foreach ($daysOfWeek as $day):
                            $visits = $dayData[$day];
                            $height = ($visits / $maxVisits) * 100;
                            $isToday = date('l') === $day;
                            $barClass = $isToday ? 'bg-yellow-500' : 'bg-blue-500';
                            ?>
                            <div class="text-center">
                                <div class="h-32 bg-gray-100 rounded-lg relative mb-2">
                                    <div class="absolute bottom-0 w-full <?= $barClass ?> rounded-b-lg transition-all"
                                        style="height: <?= $height ?>%">
                                    </div>
                                </div>
                                <p class="text-sm <?= $isToday ? 'font-bold' : '' ?>"><?= substr($day, 0, 3) ?></p>
                                <p class="text-xs text-gray-500"><?= $visits ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Member Growth Trend -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h3 class="text-lg font-semibold mb-4">
                    <i class="fas fa-chart-line text-yellow-500 mr-2"></i>
                    Member Growth (Last 6 Months)
                </h3>

                <?php if (empty($memberGrowth)): ?>
                    <div class="text-center py-8">
                        <div class="text-gray-400 mb-2"><i class="fas fa-users text-4xl"></i></div>
                        <p class="text-gray-500">No membership growth data available</p>
                    </div>
                <?php else: ?>
                    <div class="h-64">
                        <canvas id="memberGrowthChart"></canvas>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="bg-white rounded-xl shadow-lg p-6">
            <h3 class="text-lg font-semibold mb-6 flex items-center">
                <i class="fas fa-bolt text-yellow-500 mr-2"></i>
                Quick Actions
            </h3>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <a href="member_list.php"
        class="p-4 bg-blue-500 hover:bg-blue-600 text-white rounded-xl text-center transition-colors duration-200">
        <i class="fas fa-users mb-2 text-2xl"></i>
        <p>Manage Members</p>
    </a>
    <a href="edit_gym_details.php"
        class="p-4 bg-green-500 hover:bg-green-600 text-white rounded-xl text-center transition-colors duration-200">
        <i class="fas fa-cog mb-2 text-2xl"></i>
        <p>Edit Gym Details</p>
    </a>
    <a href="booking.php"
        class="p-4 bg-purple-500 hover:bg-purple-600 text-white rounded-xl text-center transition-colors duration-200">
        <i class="fas fa-calendar-check mb-2 text-2xl"></i>
        <p>Schedules</p>
    </a>
    <a href="earning-history.php"
        class="p-4 bg-yellow-500 hover:bg-yellow-600 text-white rounded-xl text-center transition-colors duration-200">
        <i class="fas fa-chart-line mb-2 text-2xl"></i>
        <p>Earnings</p>
    </a>
    <a href="visit_attendance.php"
        class="p-4 bg-red-500 hover:bg-red-600 text-white rounded-xl text-center transition-colors duration-200">
        <i class="fas fa-clipboard-check mb-2 text-2xl"></i>
        <p>Visit Attendance</p>
    </a>
    <a href="tournaments.php"
        class="p-4 bg-indigo-500 hover:bg-indigo-600 text-white rounded-xl text-center transition-colors duration-200">
        <i class="fas fa-trophy mb-2 text-2xl"></i>
        <p>Tournaments</p>
    </a>
    <a href="notifications.php"
        class="p-4 bg-pink-500 hover:bg-pink-600 text-white rounded-xl text-center transition-colors duration-200">
        <i class="fas fa-bell mb-2 text-2xl"></i>
        <p>Notifications</p>
    </a>
    <a href="payment_methods.php"
        class="p-4 bg-teal-500 hover:bg-teal-600 text-white rounded-xl text-center transition-colors duration-200">
        <i class="fas fa-credit-card mb-2 text-2xl"></i>
        <p>Payment Methods</p>
    </a>
</div>
<!-- Main Dashboard Quick Links with Categories -->
<div class="mb-8">
    <h2 class="text-xl font-bold text-white mb-4">Gym Management</h2>
    
    <!-- Main Categories Tabs -->
    <div class="mb-6 border-b border-gray-700">
        <ul class="flex flex-wrap -mb-px text-sm font-medium text-center" id="dashboardTabs" role="tablist">
            <li class="mr-2" role="presentation">
                <button class="inline-block p-4 border-b-2 border-yellow-500 rounded-t-lg text-yellow-500 active" 
                        id="gym-tab" data-tab="gym" type="button" role="tab" aria-selected="true">
                    <i class="fas fa-dumbbell mr-2"></i>Gym
                </button>
            </li>
            <li class="mr-2" role="presentation">
                <button class="inline-block p-4 border-b-2 border-transparent rounded-t-lg hover:text-gray-300 hover:border-gray-300" 
                        id="members-tab" data-tab="members" type="button" role="tab" aria-selected="false">
                    <i class="fas fa-users mr-2"></i>Members
                </button>
            </li>
            <li class="mr-2" role="presentation">
                <button class="inline-block p-4 border-b-2 border-transparent rounded-t-lg hover:text-gray-300 hover:border-gray-300" 
                        id="schedules-tab" data-tab="schedules" type="button" role="tab" aria-selected="false">
                    <i class="fas fa-calendar-alt mr-2"></i>Schedules
                </button>
            </li>
            <li class="mr-2" role="presentation">
                <button class="inline-block p-4 border-b-2 border-transparent rounded-t-lg hover:text-gray-300 hover:border-gray-300" 
                        id="finance-tab" data-tab="finance" type="button" role="tab" aria-selected="false">
                    <i class="fas fa-money-bill-wave mr-2"></i>Finance
                </button>
            </li>
            <li role="presentation">
                <button class="inline-block p-4 border-b-2 border-transparent rounded-t-lg hover:text-gray-300 hover:border-gray-300" 
                        id="more-tab" data-tab="more" type="button" role="tab" aria-selected="false">
                    <i class="fas fa-ellipsis-h mr-2"></i>More
                </button>
            </li>
        </ul>
    </div>
    
    <!-- Tab Content -->
    <div class="tab-content">
        <!-- Gym Management Tab -->
        <div class="tab-pane active" id="gym-content">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <a href="edit_gym_details.php"
                    class="p-4 bg-blue-500 hover:bg-blue-600 text-white rounded-xl text-center transition-colors duration-200">
                    <i class="fas fa-cog mb-2 text-2xl"></i>
                    <p>Gym Details</p>
                </a>
                <a href="edit_gym_details.php#operating-hours"
                    class="p-4 bg-green-500 hover:bg-green-600 text-white rounded-xl text-center transition-colors duration-200">
                    <i class="fas fa-clock mb-2 text-2xl"></i>
                    <p>Operating Hours</p>
                </a>
                <a href="edit_gym_details.php#amenities"
                    class="p-4 bg-purple-500 hover:bg-purple-600 text-white rounded-xl text-center transition-colors duration-200">
                    <i class="fas fa-spa mb-2 text-2xl"></i>
                    <p>Amenities</p>
                </a>
                <a href="edit_gym_details.php#equipment"
                    class="p-4 bg-yellow-500 hover:bg-yellow-600 text-white rounded-xl text-center transition-colors duration-200">
                    <i class="fas fa-dumbbell mb-2 text-2xl"></i>
                    <p>Equipment</p>
                </a>
            </div>
        </div>
        
        <!-- Members Tab -->
        <div class="tab-pane hidden" id="members-content">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <a href="member_list.php"
                    class="p-4 bg-blue-500 hover:bg-blue-600 text-white rounded-xl text-center transition-colors duration-200">
                    <i class="fas fa-users mb-2 text-2xl"></i>
                    <p>All Members</p>
                </a>
                <a href="member_list.php?membership=active"
                    class="p-4 bg-green-500 hover:bg-green-600 text-white rounded-xl text-center transition-colors duration-200">
                    <i class="fas fa-user-check mb-2 text-2xl"></i>
                    <p>Active Members</p>
                </a>
                <a href="member_list.php?membership=expired"
                    class="p-4 bg-red-500 hover:bg-red-600 text-white rounded-xl text-center transition-colors duration-200">
                    <i class="fas fa-user-times mb-2 text-2xl"></i>
                    <p>Expired Members</p>
                </a>
                <a href="visit_attendance.php"
                    class="p-4 bg-indigo-500 hover:bg-indigo-600 text-white rounded-xl text-center transition-colors duration-200">
                    <i class="fas fa-clipboard-check mb-2 text-2xl"></i>
                    <p>Attendance</p>
                </a>
            </div>
        </div>
        
        <!-- Schedules Tab -->
        <div class="tab-pane hidden" id="schedules-content">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <a href="booking.php"
                    class="p-4 bg-blue-500 hover:bg-blue-600 text-white rounded-xl text-center transition-colors duration-200">
                    <i class="fas fa-calendar-check mb-2 text-2xl"></i>
                    <p>All Schedules</p>
                </a>
                <a href="booking.php?filter=today"
                    class="p-4 bg-green-500 hover:bg-green-600 text-white rounded-xl text-center transition-colors duration-200">
                    <i class="fas fa-calendar-day mb-2 text-2xl"></i>
                    <p>Today's Visits</p>
                </a>
                <a href="class_bookings.php"
                    class="p-4 bg-purple-500 hover:bg-purple-600 text-white rounded-xl text-center transition-colors duration-200">
                    <i class="fas fa-chalkboard-teacher mb-2 text-2xl"></i>
                    <p>Classes</p>
                </a>
                <a href="tournaments.php"
                    class="p-4 bg-yellow-500 hover:bg-yellow-600 text-white rounded-xl text-center transition-colors duration-200">
                    <i class="fas fa-trophy mb-2 text-2xl"></i>
                    <p>Tournaments</p>
                </a>
            </div>
        </div>
        
        <!-- Finance Tab -->
        <div class="tab-pane hidden" id="finance-content">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <a href="earning-history.php"
                    class="p-4 bg-blue-500 hover:bg-blue-600 text-white rounded-xl text-center transition-colors duration-200">
                    <i class="fas fa-chart-line mb-2 text-2xl"></i>
                    <p>Earnings</p>
                </a>
                <a href="payment_methods.php"
                    class="p-4 bg-green-500 hover:bg-green-600 text-white rounded-xl text-center transition-colors duration-200">
                    <i class="fas fa-credit-card mb-2 text-2xl"></i>
                    <p>Payment Methods</p>
                </a>
                <a href="withdrawals.php"
                    class="p-4 bg-purple-500 hover:bg-purple-600 text-white rounded-xl text-center transition-colors duration-200">
                    <i class="fas fa-hand-holding-usd mb-2 text-2xl"></i>
                    <p>Withdrawals</p>
                </a>
                <a href="edit_gym_details.php#membership-plans"
                    class="p-4 bg-yellow-500 hover:bg-yellow-600 text-white rounded-xl text-center transition-colors duration-200">
                    <i class="fas fa-tags mb-2 text-2xl"></i>
                    <p>Membership Plans</p>
                </a>
            </div>
        </div>
        
        <!-- More Tab -->
        <div class="tab-pane hidden" id="more-content">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <a href="view_reviews.php"
                    class="p-4 bg-blue-500 hover:bg-blue-600 text-white rounded-xl text-center transition-colors duration-200">
                    <i class="fas fa-star mb-2 text-2xl"></i>
                    <p>Reviews</p>
                </a>
                <a href="notifications.php"
                    class="p-4 bg-green-500 hover:bg-green-600 text-white rounded-xl text-center transition-colors duration-200">
                    <i class="fas fa-bell mb-2 text-2xl"></i>
                    <p>Notifications</p>
                </a>
                <a href="reports.php"
                    class="p-4 bg-purple-500 hover:bg-purple-600 text-white rounded-xl text-center transition-colors duration-200">
                    <i class="fas fa-chart-bar mb-2 text-2xl"></i>
                    <p>Reports</p>
                </a>
                <a href="settings.php"
                    class="p-4 bg-yellow-500 hover:bg-yellow-600 text-white rounded-xl text-center transition-colors duration-200">
                    <i class="fas fa-cogs mb-2 text-2xl"></i>
                    <p>Settings</p>
                </a>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript for Tab Functionality -->
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const tabs = document.querySelectorAll('#dashboardTabs button');
        
        tabs.forEach(tab => {
            tab.addEventListener('click', function() {
                // Remove active class from all tabs
                tabs.forEach(t => {
                    t.classList.remove('border-yellow-500', 'text-yellow-500');
                    t.classList.add('border-transparent', 'hover:text-gray-300', 'hover:border-gray-300');
                    t.setAttribute('aria-selected', 'false');
                });
                
                // Add active class to clicked tab
                this.classList.remove('border-transparent', 'hover:text-gray-300', 'hover:border-gray-300');
                this.classList.add('border-yellow-500', 'text-yellow-500');
                this.setAttribute('aria-selected', 'true');
                
                // Hide all tab content
                document.querySelectorAll('.tab-pane').forEach(pane => {
                    pane.classList.add('hidden');
                    pane.classList.remove('active');
                });
                
                // Show selected tab content
                const tabName = this.getAttribute('data-tab');
                const tabContent = document.getElementById(tabName + '-content');
                tabContent.classList.remove('hidden');
                tabContent.classList.add('active');
            });
        });
    });
</script>

        </div>
    </div>

    <!-- Chart.js for visualizations -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<?php endif; ?>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const statusToggle = document.getElementById('gymStatusToggle');

        if (statusToggle) {
            statusToggle.addEventListener('click', function () {
                const currentStatus = this.textContent.trim() === 'Open';
                const newStatus = !currentStatus;

                // Send AJAX request to update status
                fetch('update_gym_status.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `gym_id=<?php echo $gym_id; ?>&is_open=${newStatus ? 1 : 0}`
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Update button appearance
                            this.textContent = newStatus ? 'Open' : 'Closed';
                            this.classList.remove(newStatus ? 'bg-red-500' : 'bg-green-500');
                            this.classList.remove(newStatus ? 'hover:bg-red-600' : 'hover:bg-green-600');
                            this.classList.add(newStatus ? 'bg-green-500' : 'bg-red-500');
                            this.classList.add(newStatus ? 'hover:bg-green-600' : 'hover:bg-red-600');
                        } else {
                            alert('Failed to update gym status. Please try again.');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('An error occurred. Please try again.');
                    });
            });
        }

        // Mark notification as read
        const markReadBtns = document.querySelectorAll('.mark-read-btn');
        if (markReadBtns.length > 0) {
            markReadBtns.forEach(btn => {
                btn.addEventListener('click', function () {
                    const notificationId = this.getAttribute('data-id');

                    fetch('mark_notification_read.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `notification_id=${notificationId}`
                    })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                // Remove the notification from the UI
                                this.closest('.p-3').remove();

                                // If no more notifications, hide the notification section
                                const notificationItems = document.querySelectorAll('.notification-item');
                                if (notificationItems.length === 0) {
                                    document.querySelector('.notification-section')?.classList.add('hidden');
                                }
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                        });
                });
            });
        }

        // Initialize Member Growth Chart if canvas exists
        const memberGrowthCanvas = document.getElementById('memberGrowthChart');
        if (memberGrowthCanvas) {
            const memberGrowthData = <?php echo json_encode(array_reverse($memberGrowth)); ?>;

            if (memberGrowthData.length > 0) {
                const labels = memberGrowthData.map(item => {
                    const date = new Date(item.month + '-01');
                    return date.toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
                });

                const data = memberGrowthData.map(item => item.new_members);

                new Chart(memberGrowthCanvas, {
                    type: 'line',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'New Members',
                            data: data,
                            backgroundColor: 'rgba(59, 130, 246, 0.2)',
                            borderColor: 'rgba(59, 130, 246, 1)',
                            borderWidth: 2,
                            tension: 0.3,
                            fill: true,
                            pointBackgroundColor: 'rgba(59, 130, 246, 1)',
                            pointRadius: 4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    precision: 0
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                callbacks: {
                                    title: function (tooltipItems) {
                                        return tooltipItems[0].label;
                                    },
                                    label: function (context) {
                                        return `New members: ${context.raw}`;
                                    }
                                }
                            }
                        }
                    }
                });
            }
        }
    });
</script>

<?php
// Helper functions

/**
 * Get appropriate icon for activity type
 */
function getActivityIcon($action)
{
    $icons = [
        'login' => 'sign-in-alt',
        'logout' => 'sign-out-alt',
        'booking' => 'calendar-check',
        'payment' => 'credit-card',
        'membership' => 'id-card',
        'review' => 'star',
        'equipment' => 'dumbbell',
        'status_change' => 'toggle-on',
        'profile_update' => 'user-edit',
        'register' => 'user-plus',
        'delete' => 'trash',
        'create' => 'plus-circle',
        'update' => 'edit'
    ];

    // Extract the main action type from actions like "create_membership"
    $actionParts = explode('_', $action);
    $actionType = $actionParts[0];

    return $icons[$action] ?? $icons[$actionType] ?? 'circle';
}

/**
 * Convert timestamp to "time ago" format
 */
function timeAgo($timestamp)
{
    $time = strtotime($timestamp);
    $now = time();
    $diff = $now - $time;

    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' min' . ($mins > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 172800) {
        return 'Yesterday';
    } else {
        return date('M j', $time);
    }
}
?>