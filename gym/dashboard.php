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

// Get Monthly Revenue - Updated to use gym_revenue table
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(amount), 0) as total 
    FROM gym_revenue 
    WHERE gym_id = ? 
    AND MONTH(date) = MONTH(CURRENT_DATE)
    AND YEAR(date) = YEAR(CURRENT_DATE)
    AND source_type != 'tournament'
");
$stmt->execute([$gym_id]);
$analytics['monthly_revenue'] = $stmt->fetchColumn();

// Get Total Revenue - Updated to use gym_revenue table
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(amount), 0) as total 
    FROM gym_revenue 
    WHERE gym_id = ?
    AND source_type != 'tournament'
");
$stmt->execute([$gym_id]);
$analytics['total_revenue'] = $stmt->fetchColumn();

// Current Time Slot Occupancy
$stmt = $conn->prepare("
    SELECT COUNT(*) as count 
    FROM schedules 
    WHERE gym_id = ? 
    AND DATE(start_date) = CURRENT_DATE 
    AND HOUR(start_time) = HOUR(CURRENT_TIME)
");
$stmt->execute([$gym_id]);
$currentSlotOccupancy = $stmt->fetchColumn();

// Daily Activity (Hour-wise visits)
$stmt = $conn->prepare("
    SELECT HOUR(start_time) as hour, COUNT(*) as visit_count 
    FROM schedules 
    WHERE gym_id = ? 
    AND DATE(start_date) = CURRENT_DATE 
    GROUP BY HOUR(start_time)
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
    ORDER BY s.start_time ASC
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

// Revenue by Plan Type - Updated to use gym_revenue table
$stmt = $conn->prepare("
    SELECT 
        gmp.plan_name,
        SUM(gr.amount) as revenue,
        COUNT(DISTINCT gr.user_id) as subscribers
    FROM gym_revenue gr
    JOIN user_memberships um ON gr.user_id = um.user_id AND gr.gym_id = um.gym_id
    JOIN gym_membership_plans gmp ON um.plan_id = gmp.plan_id
    WHERE gr.gym_id = ?
    AND MONTH(gr.date) = MONTH(CURRENT_DATE)
    AND YEAR(gr.date) = YEAR(CURRENT_DATE)
    GROUP BY gmp.plan_id
");
$stmt->execute([$gym_id]);
$planRevenue = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Member Retention Rate
$stmt = $conn->prepare("
    SELECT 
        COUNT(CASE WHEN status = 'active' AND DATEDIFF(end_date, start_date) > 180 THEN 1 END) * 100.0 / 
        NULLIF(COUNT(*), 0) as retention_rate
    FROM user_memberships
    WHERE gym_id = ?
");
$stmt->execute([$gym_id]);
$retentionRate = $stmt->fetchColumn() ?: 0;

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

// Get Daily Visit Earnings (Today) - Updated to use gym_revenue table
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(amount), 0) as total 
    FROM gym_revenue 
    WHERE gym_id = ? 
    AND DATE(date) = CURRENT_DATE
    AND source_type != 'tournament'
");
$stmt->execute([$gym_id]);
$dailyVisitEarningsToday = $stmt->fetchColumn();

// Get Total Earnings from Daily Visits (All time) - Updated to use gym_revenue table
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(amount), 0) as total 
    FROM gym_revenue 
    WHERE gym_id = ?
    AND source_type != 'tournament'
");
$stmt->execute([$gym_id]);
$totalVisitEarnings = $stmt->fetchColumn();

// Get Monthly Visit Earnings - Updated to use gym_revenue table
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(amount), 0) as total 
    FROM gym_revenue 
    WHERE gym_id = ? 
    AND MONTH(date) = MONTH(CURRENT_DATE)
    AND YEAR(date) = YEAR(CURRENT_DATE)
    AND source_type != 'tournament'
");
$stmt->execute([$gym_id]);
$monthlyVisitEarnings = $stmt->fetchColumn();

// Get Pending Visit Payments - Updated to use gym_revenue table
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(amount), 0) as total 
    FROM gym_revenue 
    WHERE gym_id = ? 
    AND payment_status = 'pending'
    AND source_type != 'tournament'
");
$stmt->execute([$gym_id]);
$pendingVisitPayments = $stmt->fetchColumn();

// Get Visit Earnings by Activity Type (This Month) - Updated to use gym_revenue table
$stmt = $conn->prepare("
    SELECT 
        source_type as activity_type,
        COUNT(*) as visit_count,
        COALESCE(SUM(amount), 0) as total_amount
    FROM gym_revenue 
    WHERE gym_id = ? 
    AND MONTH(date) = MONTH(CURRENT_DATE)
    AND YEAR(date) = YEAR(CURRENT_DATE)
    AND source_type != 'tournament'
    GROUP BY source_type
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

// Get Monthly Earnings Breakdown by Source - Updated to use gym_revenue table
$stmt = $conn->prepare("
    SELECT 
        source_type,
        SUM(amount) as total_amount
    FROM gym_revenue 
    WHERE gym_id = ? 
    AND MONTH(date) = MONTH(CURRENT_DATE)
    AND YEAR(date) = YEAR(CURRENT_DATE)
    AND source_type != 'tournament'
    GROUP BY source_type
");
$stmt->execute([$gym_id]);
$monthlyEarningsBreakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get Recent Transactions - Updated to include payment_status
$stmt = $conn->prepare("
    SELECT 
        'revenue' as type,
        id,
        amount,
        source_type as description,
        date as transaction_date,
        payment_status
    FROM gym_revenue
    WHERE gym_id = ?
    AND source_type != 'tournament'
    
    UNION ALL
    
    SELECT 
        'withdrawal' as type,
        id,
        amount,
        status as description,
        created_at as transaction_date,
        status as payment_status
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
                    <a href="add_gym.php" class="inline-block bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-6 rounded-lg transition duration-300 ease-in-out transform hover:-translate-y-1">
                        <i class="fas fa-plus-circle mr-2"></i> Add Your Gym
                    </a>
                </div>
            </div>
        </div>
    </div>
<?php else: ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.10.2/dist/cdn.min.js" defer></script>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <div class="min-h-screen bg-gray-900 text-black py-12 pt-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <!-- Gym Status Toggle -->
            <div class="bg-white rounded-lg shadow-md p-4 mb-6 flex justify-between items-center">
                <div>
                    <h2 class="text-xl font-semibold"><?= htmlspecialchars($gym['name']) ?></h2>
                    <p class="text-gray-500">
                        <i class="fas fa-map-marker-alt text-red-500"></i> 
                        <?= htmlspecialchars($gym['city']) ?>, <?= htmlspecialchars($gym['state']) ?>
                    </p>
                </div>
                <div class="flex items-center">
                    <span class="mr-3 text-sm font-medium text-gray-700">Gym Status:</span>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" id="gymStatusToggle" class="sr-only" <?= $gym['is_open'] ? 'checked' : '' ?>>
                        <div class="w-11 h-6 bg-gray-200 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-green-600"></div>
                        <span class="ml-3 text-sm font-medium text-gray-700" id="gymStatusText">
                            <?= $gym['is_open'] ? 'Open' : 'Closed' ?>
                        </span>
                    </label>
                </div>
            </div>

            <!-- Quick Stats -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
                <!-- Daily Visits -->
                <div class="bg-white rounded-lg shadow-md p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-blue-100 text-blue-600">
                            <i class="fas fa-users text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-gray-500 text-sm">Daily Visits</p>
                            <h3 class="text-2xl font-bold"><?= $analytics['daily_visits'] ?></h3>
                        </div>
                    </div>
                    <div class="mt-4">
                        <div class="flex justify-between items-center">
                            <span class="text-xs text-gray-500">Current Occupancy</span>
                            <span class="text-xs font-semibold"><?= $currentSlotOccupancy ?> / <?= $gym['capacity'] ?></span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2 mt-1">
                            <div class="bg-blue-600 h-2 rounded-full" style="width: <?= ($gym['capacity'] > 0) ? min(100, ($currentSlotOccupancy / $gym['capacity']) * 100) : 0 ?>%"></div>
                        </div>
                    </div>
                </div>

                <!-- Active Members -->
                <div class="bg-white rounded-lg shadow-md p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-green-100 text-green-600">
                            <i class="fas fa-user-check text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-gray-500 text-sm">Active Members</p>
                            <h3 class="text-2xl font-bold"><?= $analytics['active_members'] ?></h3>
                        </div>
                    </div>
                    <div class="mt-4">
                        <div class="flex justify-between items-center">
                            <span class="text-xs text-gray-500">Retention Rate</span>
                            <span class="text-xs font-semibold"><?= number_format($retentionRate, 1) ?>%</span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2 mt-1">
                            <div class="bg-green-600 h-2 rounded-full" style="width: <?= min(100, $retentionRate) ?>%"></div>
                        </div>
                    </div>
                </div>

                <!-- Monthly Revenue -->
                <div class="bg-white rounded-lg shadow-md p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-yellow-100 text-yellow-600">
                            <i class="fas fa-chart-line text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-gray-500 text-sm">Monthly Revenue</p>
                            <h3 class="text-2xl font-bold">₹<?= number_format($analytics['monthly_revenue'], 2) ?></h3>
                        </div>
                    </div>
                    <div class="mt-4">
                        <div class="flex justify-between items-center">
                            <span class="text-xs text-gray-500">Today's Earnings</span>
                            <span class="text-xs font-semibold">₹<?= number_format($dailyVisitEarningsToday, 2) ?></span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2 mt-1">
                            <div class="bg-yellow-600 h-2 rounded-full" style="width: <?= ($analytics['monthly_revenue'] > 0) ? min(100, ($dailyVisitEarningsToday / $analytics['monthly_revenue']) * 100) : 0 ?>%"></div>
                        </div>
                    </div>
                </div>

                <!-- Total Revenue -->
                <div class="bg-white rounded-lg shadow-md p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-purple-100 text-purple-600">
                            <i class="fas fa-wallet text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-gray-500 text-sm">Total Revenue</p>
                            <h3 class="text-2xl font-bold">₹<?= number_format($analytics['total_revenue'], 2) ?></h3>
                        </div>
                    </div>
                    <div class="mt-4">
                        <div class="flex justify-between items-center">
                            <span class="text-xs text-gray-500">Available Balance</span>
                            <span class="text-xs font-semibold">₹<?= number_format($availableBalance, 2) ?></span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2 mt-1">
                            <div class="bg-purple-600 h-2 rounded-full" style="width: <?= ($analytics['total_revenue'] > 0) ? min(100, ($availableBalance / $analytics['total_revenue']) * 100) : 0 ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Content -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Left Column -->
                <div class="lg:col-span-2 space-y-6">
                    <!-- Today's Bookings -->
                    <div class="bg-white rounded-lg shadow-md p-6">
                        <div class="flex justify-between items-center mb-4">
                            <h2 class="text-lg font-semibold">Today's Bookings</h2>
                            <a href="booking.php" class="text-blue-600 hover:text-blue-800 text-sm">View All</a>
                        </div>
                        
                        <?php if (empty($todayBookings)): ?>
                            <div class="text-center py-4">
                                <p class="text-gray-500">No bookings for today</p>
                            </div>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Member</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Time</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach (array_slice($todayBookings, 0, 5) as $booking): ?>
                                            <tr>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="flex items-center">
                                                        <div class="flex-shrink-0 h-10 w-10 bg-gray-200 rounded-full flex items-center justify-center">
                                                            <i class="fas fa-user text-gray-500"></i>
                                                        </div>
                                                        <div class="ml-4">
                                                            <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($booking['username']) ?></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="text-sm text-gray-900"><?= date('h:i A', strtotime($booking['start_time'])) ?></div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <?php 
                                                    $statusColors = [
                                                        'scheduled' => 'bg-yellow-100 text-yellow-800',
                                                        'completed' => 'bg-green-100 text-green-800',
                                                        'cancelled' => 'bg-red-100 text-red-800',
                                                        'missed' => 'bg-gray-100 text-gray-800'
                                                    ];
                                                    $statusColor = $statusColors[$booking['status']] ?? 'bg-gray-100 text-gray-800';
                                                    ?>
                                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?= $statusColor ?>">
                                                        <?= ucfirst($booking['status']) ?>
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                    <a href="view_schedule.php?id=<?= $booking['id'] ?>" class="text-blue-600 hover:text-blue-900 mr-3">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <?php if ($booking['status'] === 'scheduled' && !$booking['check_in_time']): ?>
                                                        <button onclick="checkInMember(<?= $booking['id'] ?>)" class="text-green-600 hover:text-green-900 mr-3">
                                                            <i class="fas fa-sign-in-alt"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <?php if ($booking['status'] === 'scheduled' && $booking['check_in_time'] && !$booking['check_out_time']): ?>
                                                        <button onclick="checkOutMember(<?= $booking['id'] ?>)" class="text-purple-600 hover:text-purple-900 mr-3">
                                                            <i class="fas fa-sign-out-alt"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <?php if ($booking['status'] === 'scheduled'): ?>
                                                        <button onclick="cancelBooking(<?= $booking['id'] ?>)" class="text-red-600 hover:text-red-900">
                                                            <i class="fas fa-times"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Revenue Analytics -->
                    <div class="bg-white rounded-lg shadow-md p-6">
                        <h2 class="text-lg font-semibold mb-4">Revenue Analytics</h2>
                        <div class="mb-6">
                            <canvas id="revenueChart" height="200"></canvas>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div class="bg-blue-50 rounded-lg p-4">
                                <p class="text-sm text-gray-500">Today's Earnings</p>
                                <h3 class="text-xl font-bold text-blue-700">₹<?= number_format($dailyVisitEarningsToday, 2) ?></h3>
                            </div>
                            <div class="bg-green-50 rounded-lg p-4">
                                <p class="text-sm text-gray-500">Monthly Earnings</p>
                                <h3 class="text-xl font-bold text-green-700">₹<?= number_format($monthlyVisitEarnings, 2) ?></h3>
                            </div>
                            <div class="bg-purple-50 rounded-lg p-4">
                                <p class="text-sm text-gray-500">Pending Payments</p>
                                <h3 class="text-xl font-bold text-purple-700">₹<?= number_format($pendingVisitPayments, 2) ?></h3>
                            </div>
                        </div>
                    </div>

                    <!-- Membership Distribution -->
                    <div class="bg-white rounded-lg shadow-md p-6">
                        <h2 class="text-lg font-semibold mb-4">Membership Distribution</h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <canvas id="membershipChart" height="200"></canvas>
                            </div>
                            <div class="space-y-4">
                                <?php foreach ($membershipDistribution as $index => $plan): 
                                    $colors = ['bg-blue-100 text-blue-800', 'bg-green-100 text-green-800', 'bg-yellow-100 text-yellow-800', 'bg-purple-100 text-purple-800', 'bg-red-100 text-red-800'];
                                    $color = $colors[$index % count($colors)];
                                ?>
                                <div class="flex items-center">
                                    <div class="w-2 h-2 rounded-full <?= str_replace('text-', 'bg-', $color) ?> mr-2"></div>
                                    <div class="flex-1">
                                        <div class="flex justify-between items-center">
                                            <span class="text-sm font-medium"><?= htmlspecialchars($plan['plan_name']) ?></span>
                                            <span class="text-sm text-gray-500"><?= $plan['member_count'] ?> members</span>
                                        </div>
                                        <div class="w-full bg-gray-200 rounded-full h-2 mt-1">
                                            <div class="<?= str_replace('text-', 'bg-', $color) ?> h-2 rounded-full" style="width: <?= number_format($plan['percentage'], 1) ?>%"></div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="space-y-6">
                    <!-- Quick Actions -->
                    <div class="bg-white rounded-lg shadow-md p-6">
                        <h2 class="text-lg font-semibold mb-4">Quick Actions</h2>
                        <div class="grid grid-cols-2 gap-4">
                            <a href="add_membership.php" class="bg-blue-50 hover:bg-blue-100 p-4 rounded-lg text-center transition duration-300">
                                <i class="fas fa-id-card text-blue-600 text-2xl mb-2"></i>
                                <p class="text-sm font-medium">Add Membership</p>
                            </a>
                            <a href="booking.php" class="bg-green-50 hover:bg-green-100 p-4 rounded-lg text-center transition duration-300">
                                <i class="fas fa-calendar-check text-green-600 text-2xl mb-2"></i>
                                <p class="text-sm font-medium">Manage Bookings</p>
                            </a>
                            <a href="equipment.php" class="bg-yellow-50 hover:bg-yellow-100 p-4 rounded-lg text-center transition duration-300">
                                <i class="fas fa-dumbbell text-yellow-600 text-2xl mb-2"></i>
                                <p class="text-sm font-medium">Equipment</p>
                            </a>
                            <a href="finances.php" class="bg-purple-50 hover:bg-purple-100 p-4 rounded-lg text-center transition duration-300">
                                <i class="fas fa-chart-pie text-purple-600 text-2xl mb-2"></i>
                                <p class="text-sm font-medium">Finances</p>
                            </a>
                        </div>
                    </div>

                    <!-- Recent Transactions -->
                    <div class="bg-white rounded-lg shadow-md p-6">
                        <h2 class="text-lg font-semibold mb-4">Recent Transactions</h2>
                        <?php if (empty($recentTransactions)): ?>
                            <div class="text-center py-4">
                                <p class="text-gray-500">No recent transactions</p>
                            </div>
                        <?php else: ?>
                            <div class="space-y-3">
                                <?php foreach ($recentTransactions as $transaction): 
                                    $isRevenue = $transaction['type'] === 'revenue';
                                    $isPending = $transaction['payment_status'] === 'pending';
                                    $icon = $isRevenue ? 'fa-arrow-down' : 'fa-arrow-up';
                                    $color = $isRevenue ? 'text-green-600' : 'text-red-600';
                                    $bgColor = $isRevenue ? 'bg-green-100' : 'bg-red-100';
                                    
                                    if ($isPending) {
                                        $color = 'text-yellow-600';
                                        $bgColor = 'bg-yellow-100';
                                    }
                                ?>
                                <div class="flex items-center p-3 rounded-lg <?= $isPending ? 'bg-gray-50' : '' ?>">
                                    <div class="p-2 rounded-full <?= $bgColor ?> mr-3">
                                        <i class="fas <?= $icon ?> <?= $color ?>"></i>
                                    </div>
                                    <div class="flex-1">
                                        <div class="flex justify-between items-center">
                                            <span class="text-sm font-medium">
                                                <?= $isRevenue ? 'Revenue' : 'Withdrawal' ?>
                                                <?php if ($isPending): ?>
                                                    <span class="text-xs bg-yellow-200 text-yellow-800 px-2 py-0.5 rounded-full ml-2">Pending</span>
                                                <?php endif; ?>
                                            </span>
                                            <span class="text-sm font-bold <?= $color ?>">
                                                <?= $isRevenue ? '+' : '-' ?>₹<?= number_format($transaction['amount'], 2) ?>
                                            </span>
                                        </div>
                                        <div class="flex justify-between items-center mt-1">
                                            <span class="text-xs text-gray-500">
                                                <?= $transaction['description'] ?>
                                            </span>
                                            <span class="text-xs text-gray-500">
                                                <?= date('M d, Y', strtotime($transaction['transaction_date'])) ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="mt-4 text-center">
                                <a href="finances.php" class="text-blue-600 hover:text-blue-800 text-sm">View All Transactions</a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Notifications -->
                    <div class="bg-white rounded-lg shadow-md p-6">
                        <h2 class="text-lg font-semibold mb-4">Notifications</h2>
                        <?php if (empty($notifications)): ?>
                            <div class="text-center py-4">
                                <p class="text-gray-500">No new notifications</p>
                            </div>
                        <?php else: ?>
                            <div class="space-y-3">
                                <?php foreach ($notifications as $notification): 
                                    $typeIcons = [
                                        'booking' => 'fa-calendar-check text-blue-600 bg-blue-100',
                                        'payment' => 'fa-credit-card text-green-600 bg-green-100',
                                        'system' => 'fa-bell text-yellow-600 bg-yellow-100',
                                        'alert' => 'fa-exclamation-circle text-red-600 bg-red-100'
                                    ];
                                    $iconClass = $typeIcons[$notification['type']] ?? 'fa-bell text-gray-600 bg-gray-100';
                                ?>
                                <div class="flex items-start p-3 rounded-lg bg-gray-50">
                                    <div class="p-2 rounded-full <?= explode(' ', $iconClass)[1] ?> mr-3">
                                        <i class="fas <?= explode(' ', $iconClass)[0] ?> <?= explode(' ', $iconClass)[1] ?>"></i>
                                    </div>
                                    <div class="flex-1">
                                        <div class="flex justify-between items-start">
                                            <span class="text-sm font-medium"><?= htmlspecialchars($notification['title']) ?></span>
                                            <span class="text-xs text-gray-500"><?= date('M d, g:i A', strtotime($notification['created_at'])) ?></span>
                                        </div>
                                        <p class="text-xs text-gray-600 mt-1"><?= htmlspecialchars($notification['message']) ?></p>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="mt-4 text-center">
                                <a href="notifications.php" class="text-blue-600 hover:text-blue-800 text-sm">View All Notifications</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Loading Overlay -->
    <div id="loadingOverlay" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
        <div class="bg-white p-5 rounded-lg shadow-lg flex items-center">
            <svg class="animate-spin h-6 w-6 text-blue-600 mr-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <span>Processing...</span>
        </div>
    </div>

    <script>
        // Gym Status Toggle
        document.getElementById('gymStatusToggle').addEventListener('change', function() {
            const isOpen = this.checked;
            const statusText = document.getElementById('gymStatusText');
            
            // Show loading
            document.getElementById('loadingOverlay').classList.remove('hidden');
            
            // Update gym status via AJAX
            fetch('update_gym_status.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'is_open=' + (isOpen ? 1 : 0)
            })
            .then(response => response.json())
            .then(data => {
                document.getElementById('loadingOverlay').classList.add('hidden');
                if (data.success) {
                    statusText.textContent = isOpen ? 'Open' : 'Closed';
                } else {
                    alert('Error: ' + data.message);
                    this.checked = !isOpen; // Revert toggle
                    statusText.textContent = !isOpen ? 'Open' : 'Closed';
                }
            })
            .catch(error => {
                document.getElementById('loadingOverlay').classList.add('hidden');
                alert('An error occurred. Please try again.');
                console.error('Error:', error);
                this.checked = !isOpen; // Revert toggle
                statusText.textContent = !isOpen ? 'Open' : 'Closed';
            });
        });

        // Check-in functionality
        function checkInMember(scheduleId) {
            if (confirm('Are you sure you want to check in this member?')) {
                showLoading();
                fetch('process_checkin.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'schedule_id=' + scheduleId + '&action=check_in'
                })
                .then(response => response.json())
                .then(data => {
                    hideLoading();
                    if (data.success) {
                        alert('Member checked in successfully!');
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    hideLoading();
                    alert('An error occurred. Please try again.');
                    console.error('Error:', error);
                });
            }
        }
        
        // Check-out functionality
        function checkOutMember(scheduleId) {
            if (confirm('Are you sure you want to check out this member?')) {
                showLoading();
                fetch('process_checkout.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'schedule_id=' + scheduleId
                })
                .then(response => response.json())
                .then(data => {
                    hideLoading();
                    if (data.success) {
                        alert('Member checked out successfully!');
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    hideLoading();
                    alert('An error occurred. Please try again.');
                    console.error('Error:', error);
                });
            }
        }
        
               // Cancel booking functionality
               function cancelBooking(scheduleId) {
            if (confirm('Are you sure you want to cancel this booking?')) {
                showLoading();
                fetch('process_cancel.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'schedule_id=' + scheduleId
                })
                .then(response => response.json())
                .then(data => {
                    hideLoading();
                    if (data.success) {
                        alert('Booking cancelled successfully!');
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    hideLoading();
                    alert('An error occurred. Please try again.');
                    console.error('Error:', error);
                });
            }
        }

        function showLoading() {
            document.getElementById('loadingOverlay').classList.remove('hidden');
        }

        function hideLoading() {
            document.getElementById('loadingOverlay').classList.add('hidden');
        }

        // Charts
        document.addEventListener('DOMContentLoaded', function() {
            // Revenue Chart
            const revenueCtx = document.getElementById('revenueChart').getContext('2d');
            const revenueChart = new Chart(revenueCtx, {
                type: 'line',
                data: {
                    labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
                    datasets: [{
                        label: 'Monthly Revenue',
                        data: [
                            <?php 
                            // Sample data - in a real app, you would fetch this from the database
                            echo implode(',', array_map(function() { 
                                return rand(5000, 20000); 
                            }, range(1, 6)));
                            ?>
                        ],
                        backgroundColor: 'rgba(66, 135, 245, 0.2)',
                        borderColor: 'rgba(66, 135, 245, 1)',
                        borderWidth: 2,
                        tension: 0.3,
                        pointBackgroundColor: 'rgba(66, 135, 245, 1)',
                        pointRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)'
                            },
                            ticks: {
                                callback: function(value) {
                                    return '₹' + value;
                                }
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return '₹' + context.parsed.y;
                                }
                            }
                        }
                    }
                }
            });

            // Membership Distribution Chart
            const membershipCtx = document.getElementById('membershipChart').getContext('2d');
            const membershipChart = new Chart(membershipCtx, {
                type: 'doughnut',
                data: {
                    labels: [
                        <?php 
                        echo implode(',', array_map(function($plan) { 
                            return "'" . addslashes($plan['plan_name']) . "'"; 
                        }, $membershipDistribution));
                        ?>
                    ],
                    datasets: [{
                        data: [
                            <?php 
                            echo implode(',', array_map(function($plan) { 
                                return $plan['member_count']; 
                            }, $membershipDistribution));
                            ?>
                        ],
                        backgroundColor: [
                            'rgba(66, 135, 245, 0.8)',
                            'rgba(52, 211, 153, 0.8)',
                            'rgba(251, 191, 36, 0.8)',
                            'rgba(139, 92, 246, 0.8)',
                            'rgba(239, 68, 68, 0.8)'
                        ],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    cutout: '70%'
                }
            });

            // Daily Activity Chart - can be added if needed
            // const activityCtx = document.getElementById('activityChart').getContext('2d');
            // ...
        });
    </script>
<?php endif; ?>
</body>
</html>

