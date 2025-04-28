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

// Fetch total counts for all tables
$counts = [];

// Users and Members
$stmt = $conn->query("SELECT COUNT(*) as total FROM users");
$counts['users'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Gyms and Related Tables
$stmt = $conn->query("SELECT COUNT(*) as total FROM gyms");
$counts['gyms'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $conn->query("SELECT COUNT(*) as total FROM gym_classes");
$counts['classes'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $conn->query("SELECT COUNT(*) as total FROM gym_equipment");
$counts['equipment'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $conn->query("SELECT COUNT(*) as total FROM gym_owners");
$counts['gym_owners'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Bookings and Schedules
$stmt = $conn->query("SELECT COUNT(*) as total FROM class_bookings");
$counts['bookings'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $conn->query("SELECT COUNT(*) as total FROM schedules");
$counts['schedules'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Memberships and Plans
$stmt = $conn->query("SELECT COUNT(*) as total FROM user_memberships");
$counts['user_memberships'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Other Tables
$stmt = $conn->query("SELECT COUNT(*) as total FROM reviews");
$counts['reviews'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $conn->query("SELECT COUNT(*) as total FROM payments");
$counts['payments'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Fetch admin revenue statistics
$stmt = $conn->query("
    SELECT 
        SUM(admin_cut) as total_admin_revenue,
        COUNT(*) as total_transactions,
        SUM(CASE WHEN DATE(date) = CURRENT_DATE THEN admin_cut ELSE 0 END) as today_revenue,
        SUM(CASE WHEN MONTH(date) = MONTH(CURRENT_DATE) THEN admin_cut ELSE 0 END) as monthly_revenue
    FROM gym_revenue
");
$revenue_stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch revenue by tier
$stmt = $conn->query("
    SELECT 
        gmp.tier,
        COUNT(*) as visit_count,
        SUM(gr.admin_cut) as tier_revenue
    FROM gym_revenue gr
    JOIN schedules s ON gr.schedule_id = s.id
    JOIN user_memberships um ON s.membership_id = um.id
    JOIN gym_membership_plans gmp ON um.plan_id = gmp.plan_id
    GROUP BY gmp.tier
");
$tier_revenue = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch recent transactions
$stmt = $conn->query("
    SELECT 
        gr.date,
        gr.admin_cut,
        g.name as gym_name,
        gmp.tier
    FROM gym_revenue gr
    JOIN gyms g ON gr.gym_id = g.gym_id
    JOIN schedules s ON gr.schedule_id = s.id
    JOIN user_memberships um ON s.membership_id = um.id
    JOIN gym_membership_plans gmp ON um.plan_id = gmp.plan_id
    ORDER BY gr.date DESC
    LIMIT 10
");
$recent_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get monthly revenue
$stmt = $conn->query("
    SELECT COALESCE(SUM(amount), 0) as total 
    FROM payments 
    WHERE status = 'completed' 
    AND MONTH(payment_date) = MONTH(CURRENT_DATE)
");
$counts['monthly_revenue'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Get recent activity logs
$stmt = $conn->query("
    SELECT al.*, 
           CASE 
               WHEN al.user_type = 'member' THEN u.username
               WHEN al.user_type = 'owner' THEN go.name
               ELSE 'Admin'
           END as user_name
    FROM activity_logs al
    LEFT JOIN users u ON al.user_id = u.id AND al.user_type = 'member'
    LEFT JOIN gym_owners go ON al.user_id = go.id AND al.user_type = 'owner'
    ORDER BY al.created_at DESC
    LIMIT 10
");
$recent_activity = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get gym status distribution
$stmt = $conn->query("
    SELECT status, COUNT(*) as count
    FROM gyms
    GROUP BY status
");
$gym_status = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get user membership distribution
$stmt = $conn->query("
    SELECT status, COUNT(*) as count
    FROM user_memberships
    GROUP BY status
");
$membership_status = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get payment method distribution
$stmt = $conn->query("
    SELECT payment_method, COUNT(*) as count
    FROM payments
    WHERE payment_method IS NOT NULL
    GROUP BY payment_method
");
$payment_methods = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get monthly revenue for the last 6 months
$stmt = $conn->query("
    SELECT 
        DATE_FORMAT(payment_date, '%Y-%m') as month,
        SUM(amount) as total
    FROM payments
    WHERE status = 'completed'
    AND payment_date >= DATE_SUB(CURRENT_DATE, INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
    ORDER BY month
");
$monthly_revenue_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Format data for charts
$months = [];
$revenue_values = [];
foreach ($monthly_revenue_data as $data) {
    $months[] = date('M Y', strtotime($data['month'] . '-01'));
    $revenue_values[] = $data['total'];
}

// Get pending approvals
$stmt = $conn->query("
    SELECT COUNT(*) as count FROM gym_owners WHERE is_approved = 0
");
$pending_owner_approvals = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

$stmt = $conn->query("
    SELECT COUNT(*) as count FROM gyms WHERE status = 'pending'
");
$pending_gym_approvals = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

$stmt = $conn->query("
    SELECT COUNT(*) as count FROM reviews WHERE status = 'pending'
");
$pending_reviews = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Get top gyms by revenue
$stmt = $conn->query("
    SELECT 
        g.gym_id,
        g.name,
        SUM(gr.amount) as total_revenue
    FROM gym_revenue gr
    JOIN gyms g ON gr.gym_id = g.gym_id
    GROUP BY g.gym_id, g.name
    ORDER BY total_revenue DESC
    LIMIT 5
");
$top_gyms = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Fitness Hub</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .dashboard-card {
            transition: all 0.3s ease;
        }
        .dashboard-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }
        .scrollbar-thin::-webkit-scrollbar {
            width: 4px;
            height: 4px;
        }
        .scrollbar-thin::-webkit-scrollbar-track {
            background: #f1f1f1;
        }
        .scrollbar-thin::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 2px;
        }
        .scrollbar-thin::-webkit-scrollbar-thumb:hover {
            background: #555;
        }
    </style>
</head>
<body class="bg-gray-900 text-white">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="ml-0 lg:ml-64 p-4 sm:p-6 transition-all duration-200">
        <!-- Header Section -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8">
            <div>
                <h1 class="text-3xl font-bold ">Admin Dashboard</h1>
                <p class="text-gray-600">Welcome back, Admin! Here's what's happening today.</p>
            </div>
            <div class="mt-4 md:mt-0 flex space-x-3">
                <button id="distribute-revenue" onclick="distributeRevenue()" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-lg transition duration-300 flex items-center">
                    <i class="fas fa-money-bill-wave mr-2"></i> Distribute Revenue
                </button>
                <a href="reports.php" class="bg-gray-700 hover:bg-gray-800 text-white font-medium py-2 px-4 rounded-lg transition duration-300 flex items-center">
                    <i class="fas fa-chart-line mr-2"></i> View Reports
                </a>
            </div>
        </div>

        <!-- Alerts Section -->
        <div class="mb-8">
            <?php if ($pending_owner_approvals > 0 || $pending_gym_approvals > 0 || $pending_reviews > 0): ?>
            <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded-lg">
                <div class="flex items-start">
                    <div class="flex-shrink-0">
                        <i class="fas fa-exclamation-circle text-yellow-400 text-xl"></i>
                    </div>
                    <div class="ml-3">
                        <h3 class="text-sm font-medium text-yellow-800">Attention needed</h3>
                        <div class="mt-2 text-sm text-yellow-700">
                            <ul class="list-disc pl-5 space-y-1">
                                <?php if ($pending_owner_approvals > 0): ?>
                                <li>
                                    <a href="pending_owners.php" class="underline hover:text-yellow-800">
                                        <?= $pending_owner_approvals ?> gym owner<?= $pending_owner_approvals > 1 ? 's' : '' ?> pending approval
                                    </a>
                                </li>
                                <?php endif; ?>
                                
                                <?php if ($pending_gym_approvals > 0): ?>
                                <li>
                                    <a href="pending_gyms.php" class="underline hover:text-yellow-800">
                                        <?= $pending_gym_approvals ?> gym<?= $pending_gym_approvals > 1 ? 's' : '' ?> pending approval
                                    </a>
                                </li>
                                <?php endif; ?>
                                
                                <?php if ($pending_reviews > 0): ?>
                                <li>
                                    <a href="pending_reviews.php" class="underline hover:text-yellow-800">
                                        <?= $pending_reviews ?> review<?= $pending_reviews > 1 ? 's' : '' ?> pending moderation
                                    </a>
                                </li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Stats Overview -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <!-- Total Revenue Card -->
            <div class="dashboard-card bg-gradient-to-r from-blue-500 to-blue-600 rounded-xl shadow-lg overflow-hidden">
                <div class="px-6 py-5">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-blue-100 text-sm font-medium">Total Revenue</p>
                            <h3 class="text-white text-2xl font-bold mt-1">₹<?= number_format($revenue_stats['total_admin_revenue'] ?? 0, 2) ?></h3>
                        </div>
                        <div class="bg-blue-400 bg-opacity-30 rounded-full p-3">
                            <i class="fas fa-coins text-white text-xl"></i>
                        </div>
                    </div>
                    <div class="mt-4">
                        <p class="text-blue-100 text-xs">
                            <i class="fas fa-exchange-alt mr-1"></i>
                            <?= number_format($revenue_stats['total_transactions'] ?? 0) ?> transactions
                        </p>
                    </div>
                </div>
            </div>

            <!-- Monthly Revenue Card -->
            <div class="dashboard-card bg-gradient-to-r from-purple-500 to-purple-600 rounded-xl shadow-lg overflow-hidden">
                <div class="px-6 py-5">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-purple-100 text-sm font-medium">Monthly Revenue</p>
                            <h3 class="text-white text-2xl font-bold mt-1">₹<?= number_format($revenue_stats['monthly_revenue'] ?? 0, 2) ?></h3>
                        </div>
                        <div class="bg-purple-400 bg-opacity-30 rounded-full p-3">
                            <i class="fas fa-calendar-alt text-white text-xl"></i>
                        </div>
                    </div>
                    <div class="mt-4">
                    <p class="text-purple-100 text-xs">
                            <i class="fas fa-chart-line mr-1"></i>
                            <?= date('F Y') ?> earnings
                        </p>
                    </div>
                </div>
            </div>

            <!-- Today's Revenue Card -->
            <div class="dashboard-card bg-gradient-to-r from-green-500 to-green-600 rounded-xl shadow-lg overflow-hidden">
                <div class="px-6 py-5">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-green-100 text-sm font-medium">Today's Revenue</p>
                            <h3 class="text-white text-2xl font-bold mt-1">₹<?= number_format($revenue_stats['today_revenue'] ?? 0, 2) ?></h3>
                        </div>
                        <div class="bg-green-400 bg-opacity-30 rounded-full p-3">
                            <i class="fas fa-hand-holding-usd text-white text-xl"></i>
                        </div>
                    </div>
                    <div class="mt-4">
                        <p class="text-green-100 text-xs">
                            <i class="fas fa-clock mr-1"></i>
                            <?= date('d M Y') ?> earnings
                        </p>
                    </div>
                </div>
            </div>

            <!-- Total Users Card -->
            <div class="dashboard-card bg-gradient-to-r from-red-500 to-red-600 rounded-xl shadow-lg overflow-hidden">
                <div class="px-6 py-5">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-red-100 text-sm font-medium">Total Users</p>
                            <h3 class="text-white text-2xl font-bold mt-1"><?= number_format($counts['users']) ?></h3>
                        </div>
                        <div class="bg-red-400 bg-opacity-30 rounded-full p-3">
                            <i class="fas fa-users text-white text-xl"></i>
                        </div>
                    </div>
                    <div class="mt-4">
                        <p class="text-red-100 text-xs">
                            <i class="fas fa-building mr-1"></i>
                            <?= number_format($counts['gym_owners']) ?> gym owners
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-8">
            <!-- Revenue Chart -->
            <div class="lg:col-span-2 bg-white rounded-xl shadow-md overflow-hidden">
                <div class="px-6 py-5 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-800">Revenue Trends</h3>
                </div>
                <div class="p-6">
                    <canvas id="revenueChart" height="300"></canvas>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="bg-white rounded-xl shadow-md overflow-hidden">
                <div class="px-6 py-5 border-b border-gray-200 flex justify-between items-center">
                    <h3 class="text-lg font-semibold text-gray-800">Recent Activity</h3>
                    <a href="activity_logs.php" class="text-sm text-indigo-600 hover:text-indigo-800">View All</a>
                </div>
                <div class="p-6 h-[300px] overflow-y-auto scrollbar-thin">
                    <?php if (empty($recent_activity)): ?>
                        <p class="text-gray-500 text-center">No recent activity found</p>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($recent_activity as $activity): ?>
                                <div class="flex items-start">
                                    <div class="flex-shrink-0 mr-3">
                                        <?php if ($activity['user_type'] === 'admin'): ?>
                                            <div class="w-8 h-8 rounded-full bg-indigo-100 flex items-center justify-center">
                                                <i class="fas fa-user-shield text-indigo-600"></i>
                                            </div>
                                        <?php elseif ($activity['user_type'] === 'owner'): ?>
                                            <div class="w-8 h-8 rounded-full bg-amber-100 flex items-center justify-center">
                                                <i class="fas fa-dumbbell text-amber-600"></i>
                                            </div>
                                        <?php else: ?>
                                            <div class="w-8 h-8 rounded-full bg-blue-100 flex items-center justify-center">
                                                <i class="fas fa-user text-blue-600"></i>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-gray-800">
                                            <?= htmlspecialchars($activity['user_name']) ?>
                                            <span class="font-normal text-gray-600">
                                                <?= htmlspecialchars($activity['action']) ?>
                                            </span>
                                        </p>
                                        <p class="text-xs text-gray-500 mt-1">
                                            <?= htmlspecialchars($activity['details']) ?>
                                        </p>
                                        <p class="text-xs text-gray-400 mt-1">
                                            <?= date('M d, Y g:i A', strtotime($activity['created_at'])) ?>
                                        </p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Secondary Stats -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <!-- Gyms Card -->
            <div class="dashboard-card bg-white rounded-xl shadow-md overflow-hidden">
                <div class="px-6 py-5 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-gray-800">Gyms</h3>
                        <span class="text-2xl font-bold text-indigo-600"><?= number_format($counts['gyms']) ?></span>
                    </div>
                </div>
                <div class="p-4">
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-gray-600">Classes</span>
                        <span class="font-medium text-gray-800"><?= number_format($counts['classes']) ?></span>
                    </div>
                    <div class="flex items-center justify-between text-sm mt-2">
                        <span class="text-gray-600">Equipment</span>
                        <span class="font-medium text-gray-800"><?= number_format($counts['equipment']) ?></span>
                    </div>
                    <div class="mt-4">
                        <a href="manage_gym.php" class="text-sm text-indigo-600 hover:text-indigo-800 flex items-center">
                            <span>Manage Gyms</span>
                            <i class="fas fa-arrow-right ml-1 text-xs"></i>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Memberships Card -->
            <div class="dashboard-card bg-white rounded-xl shadow-md overflow-hidden">
                <div class="px-6 py-5 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-gray-800">Memberships</h3>
                        <span class="text-2xl font-bold text-purple-600"><?= number_format($counts['user_memberships']) ?></span>
                    </div>
                </div>
                <div class="p-4">
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-gray-600">Active</span>
                        <span class="font-medium text-gray-800">
                            <?php 
                            $active = 0;
                            foreach ($membership_status as $status) {
                                if ($status['status'] === 'active') {
                                    $active = $status['count'];
                                    break;
                                }
                            }
                            echo number_format($active);
                            ?>
                        </span>
                    </div>
                    <div class="flex items-center justify-between text-sm mt-2">
                        <span class="text-gray-600">Expired</span>
                        <span class="font-medium text-gray-800">
                            <?php 
                            $expired = 0;
                            foreach ($membership_status as $status) {
                                if ($status['status'] === 'expired') {
                                    $expired = $status['count'];
                                    break;
                                }
                            }
                            echo number_format($expired);
                            ?>
                        </span>
                    </div>
                    <div class="mt-4">
                        <a href="memberships.php" class="text-sm text-indigo-600 hover:text-indigo-800 flex items-center">
                            <span>View Memberships</span>
                            <i class="fas fa-arrow-right ml-1 text-xs"></i>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Bookings Card -->
            <div class="dashboard-card bg-white rounded-xl shadow-md overflow-hidden">
                <div class="px-6 py-5 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-gray-800">Bookings</h3>
                        <span class="text-2xl font-bold text-green-600"><?= number_format($counts['bookings']) ?></span>
                    </div>
                </div>
                <div class="p-4">
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-gray-600">Schedules</span>
                        <span class="font-medium text-gray-800"><?= number_format($counts['schedules']) ?></span>
                    </div>
                    <div class="flex items-center justify-between text-sm mt-2">
                        <span class="text-gray-600">Reviews</span>
                        <span class="font-medium text-gray-800"><?= number_format($counts['reviews']) ?></span>
                    </div>
                    <div class="mt-4">
                        <a href="all_bookings.php" class="text-sm text-indigo-600 hover:text-indigo-800 flex items-center">
                            <span>View Bookings</span>
                            <i class="fas fa-arrow-right ml-1 text-xs"></i>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Payments Card -->
            <div class="dashboard-card bg-white rounded-xl shadow-md overflow-hidden">
                <div class="px-6 py-5 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-gray-800">Payments</h3>
                        <span class="text-2xl font-bold text-amber-600"><?= number_format($counts['payments']) ?></span>
                    </div>
                </div>
                <div class="p-4">
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-gray-600">Monthly Revenue</span>
                        <span class="font-medium text-gray-800">₹<?= number_format($counts['monthly_revenue'], 2) ?></span>
                    </div>
                    <div class="flex items-center justify-between text-sm mt-2">
                        <span class="text-gray-600">Transactions</span>
                        <span class="font-medium text-gray-800"><?= number_format($revenue_stats['total_transactions'] ?? 0) ?></span>
                    </div>
                    <div class="mt-4">
                        <a href="payments.php" class="text-sm text-indigo-600 hover:text-indigo-800 flex items-center">
                            <span>View Payments</span>
                            <i class="fas fa-arrow-right ml-1 text-xs"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Revenue Details -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
            <!-- Revenue by Tier -->
            <div class="bg-white rounded-xl shadow-md overflow-hidden">
                <div class="px-6 py-5 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-800">Revenue by Tier</h3>
                </div>
                <div class="p-6">
                    <?php if (empty($tier_revenue)): ?>
                        <p class="text-gray-500 text-center">No tier revenue data available</p>
                    <?php else: ?>
                        <div class="space-y-6">
                            <?php foreach ($tier_revenue as $tier): ?>
                                <div>
                                    <div class="flex justify-between items-center mb-2">
                                        <span class="text-sm font-medium text-gray-700"><?= htmlspecialchars($tier['tier']) ?></span>
                                        <span class="text-sm font-medium text-gray-900">₹<?= number_format($tier['tier_revenue'], 2) ?></span>
                                    </div>
                                    <div class="w-full bg-gray-200 rounded-full h-2.5">
                                        <?php 
                                        $total = array_sum(array_column($tier_revenue, 'tier_revenue'));
                                        $percentage = $total > 0 ? ($tier['tier_revenue'] / $total) * 100 : 0;
                                        $color = '';
                                        
                                        switch($tier['tier']) {
                                            case 'Tier 1':
                                                $color = 'bg-blue-600';
                                                break;
                                            case 'Tier 2':
                                                $color = 'bg-purple-600';
                                                break;
                                            case 'Tier 3':
                                                $color = 'bg-green-600';
                                                break;
                                                default:
                                                $color = 'bg-gray-600';
                                        }
                                        ?>
                                        <div class="<?= $color ?> h-2.5 rounded-full" style="width: <?= $percentage ?>%"></div>
                                    </div>
                                    <div class="flex justify-between items-center mt-1">
                                        <span class="text-xs text-gray-500"><?= $tier['visit_count'] ?> visits</span>
                                        <span class="text-xs text-gray-500"><?= number_format($percentage, 1) ?>%</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Transactions -->
            <div class="bg-white rounded-xl shadow-md overflow-hidden">
                <div class="px-6 py-5 border-b border-gray-200 flex justify-between items-center">
                    <h3 class="text-lg font-semibold text-gray-800">Recent Transactions</h3>
                    <a href="transactions.php" class="text-sm text-indigo-600 hover:text-indigo-800">View All</a>
                </div>
                <div class="p-6">
                    <?php if (empty($recent_transactions)): ?>
                        <p class="text-gray-500 text-center">No recent transactions found</p>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($recent_transactions as $transaction): ?>
                                <div class="flex justify-between items-center p-3 hover:bg-gray-50 rounded-lg transition duration-150">
                                    <div>
                                        <h4 class="text-sm font-medium text-gray-800"><?= htmlspecialchars($transaction['gym_name']) ?></h4>
                                        <p class="text-xs text-gray-500 mt-1"><?= date('d M Y', strtotime($transaction['date'])) ?></p>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-sm font-medium text-gray-800">₹<?= number_format($transaction['admin_cut'], 2) ?></p>
                                        <p class="text-xs text-gray-500 mt-1">
                                            <?php
                                            $tierClass = '';
                                            switch($transaction['tier']) {
                                                case 'Tier 1':
                                                    $tierClass = 'bg-blue-100 text-blue-800';
                                                    break;
                                                case 'Tier 2':
                                                    $tierClass = 'bg-purple-100 text-purple-800';
                                                    break;
                                                case 'Tier 3':
                                                    $tierClass = 'bg-green-100 text-green-800';
                                                    break;
                                                default:
                                                    $tierClass = 'bg-gray-100 text-gray-800';
                                            }
                                            ?>
                                            <span class="<?= $tierClass ?> text-xs font-medium px-2 py-0.5 rounded-full">
                                                <?= htmlspecialchars($transaction['tier']) ?>
                                            </span>
                                        </p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Top Gyms and Payment Methods -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
            <!-- Top Gyms -->
            <div class="bg-white rounded-xl shadow-md overflow-hidden">
                <div class="px-6 py-5 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-800">Top Performing Gyms</h3>
                </div>
                <div class="p-6">
                    <?php if (empty($top_gyms)): ?>
                        <p class="text-gray-500 text-center">No gym data available</p>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($top_gyms as $index => $gym): ?>
                                <div class="flex items-center">
                                    <div class="flex-shrink-0 w-8 h-8 rounded-full bg-indigo-100 flex items-center justify-center mr-3">
                                        <span class="text-indigo-800 font-medium text-sm"><?= $index + 1 ?></span>
                                    </div>
                                    <div class="flex-grow">
                                        <div class="flex justify-between items-center">
                                            <h4 class="text-sm font-medium text-gray-800"><?= htmlspecialchars($gym['name']) ?></h4>
                                            <span class="text-sm font-medium text-gray-900">₹<?= number_format($gym['total_revenue'], 2) ?></span>
                                        </div>
                                        <div class="w-full bg-gray-200 rounded-full h-1.5 mt-2">
                                            <?php 
                                            $maxRevenue = $top_gyms[0]['total_revenue'];
                                            $percentage = $maxRevenue > 0 ? ($gym['total_revenue'] / $maxRevenue) * 100 : 0;
                                            ?>
                                            <div class="bg-indigo-600 h-1.5 rounded-full" style="width: <?= $percentage ?>%"></div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Payment Methods -->
            <div class="bg-white rounded-xl shadow-md overflow-hidden">
                <div class="px-6 py-5 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-800">Payment Methods</h3>
                </div>
                <div class="p-6">
                    <?php if (empty($payment_methods)): ?>
                        <p class="text-gray-500 text-center">No payment method data available</p>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($payment_methods as $method): ?>
                                <div>
                                    <div class="flex justify-between items-center mb-2">
                                        <span class="text-sm font-medium text-gray-700">
                                            <?php
                                            $icon = '';
                                            switch(strtolower($method['payment_method'])) {
                                                case 'credit card':
                                                    $icon = '<i class="fas fa-credit-card mr-2"></i>';
                                                    break;
                                                case 'debit card':
                                                    $icon = '<i class="fas fa-credit-card mr-2"></i>';
                                                    break;
                                                case 'upi':
                                                    $icon = '<i class="fas fa-mobile-alt mr-2"></i>';
                                                    break;
                                                case 'netbanking':
                                                    $icon = '<i class="fas fa-university mr-2"></i>';
                                                    break;
                                                case 'wallet':
                                                    $icon = '<i class="fas fa-wallet mr-2"></i>';
                                                    break;
                                                default:
                                                    $icon = '<i class="fas fa-money-bill-wave mr-2"></i>';
                                            }
                                            echo $icon . htmlspecialchars(ucfirst($method['payment_method']));
                                            ?>
                                        </span>
                                        <span class="text-sm font-medium text-gray-900"><?= number_format($method['count']) ?></span>
                                    </div>
                                    <div class="w-full bg-gray-200 rounded-full h-2">
                                        <?php 
                                        $total = array_sum(array_column($payment_methods, 'count'));
                                        $percentage = $total > 0 ? ($method['count'] / $total) * 100 : 0;
                                        
                                        $color = '';
                                        switch(strtolower($method['payment_method'])) {
                                            case 'credit card':
                                                $color = 'bg-blue-600';
                                                break;
                                            case 'debit card':
                                                $color = 'bg-green-600';
                                                break;
                                            case 'upi':
                                                $color = 'bg-purple-600';
                                                break;
                                            case 'netbanking':
                                                $color = 'bg-yellow-600';
                                                break;
                                            case 'wallet':
                                                $color = 'bg-pink-600';
                                                break;
                                            default:
                                                $color = 'bg-gray-600';
                                        }
                                        ?>
                                        <div class="<?= $color ?> h-2 rounded-full" style="width: <?= $percentage ?>%"></div>
                                    </div>
                                    <div class="flex justify-end mt-1">
                                        <span class="text-xs text-gray-500"><?= number_format($percentage, 1) ?>%</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="bg-white rounded-xl shadow-md overflow-hidden mb-8">
            <div class="px-6 py-5 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-800">Quick Actions</h3>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <a href="manage_users.php" class="flex flex-col items-center p-4 bg-gray-50 rounded-lg hover:bg-gray-100 transition duration-150">
                        <div class="w-12 h-12 rounded-full bg-blue-100 flex items-center justify-center mb-3">
                            <i class="fas fa-users text-blue-600"></i>
                        </div>
                        <span class="text-sm font-medium text-gray-800">Manage Users</span>
                    </a>
                    
                    <a href="manage_gym.php" class="flex flex-col items-center p-4 bg-gray-50 rounded-lg hover:bg-gray-100 transition duration-150">
                        <div class="w-12 h-12 rounded-full bg-purple-100 flex items-center justify-center mb-3">
                            <i class="fas fa-dumbbell text-purple-600"></i>
                        </div>
                        <span class="text-sm font-medium text-gray-800">Manage Gyms</span>
                    </a>
                    
                    <a href="revenue_settings.php" class="flex flex-col items-center p-4 bg-gray-50 rounded-lg hover:bg-gray-100 transition duration-150">
                        <div class="w-12 h-12 rounded-full bg-green-100 flex items-center justify-center mb-3">
                            <i class="fas fa-percentage text-green-600"></i>
                        </div>
                        <span class="text-sm font-medium text-gray-800">Revenue Settings</span>
                    </a>
                    
                    <a href="reports.php" class="flex flex-col items-center p-4 bg-gray-50 rounded-lg hover:bg-gray-100 transition duration-150">
                        <div class="w-12 h-12 rounded-full bg-amber-100 flex items-center justify-center mb-3">
                            <i class="fas fa-chart-bar text-amber-600"></i>
                        </div>
                        <span class="text-sm font-medium text-gray-800">Generate Reports</span>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Revenue Chart
        const ctx = document.getElementById('revenueChart').getContext('2d');
        const revenueChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?= json_encode($months) ?>,
                datasets: [{
                    label: 'Monthly Revenue',
                    data: <?= json_encode($revenue_values) ?>,
                    backgroundColor: 'rgba(79, 70, 229, 0.1)',
                    borderColor: 'rgba(79, 70, 229, 1)',
                    borderWidth: 2,
                    tension: 0.3,
                    pointBackgroundColor: 'rgba(79, 70, 229, 1)',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.7)',
                        padding: 10,
                        titleFont: {
                            size: 14,
                            weight: 'bold'
                        },
                        bodyFont: {
                            size: 13
                        },
                        callbacks: {
                            label: function(context) {
                                return '₹' + context.parsed.y.toLocaleString();
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            font: {
                                size: 11
                            }
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        },
                        ticks: {
                            font: {
                                size: 11
                            },
                            callback: function(value) {
                                return '₹' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });

        function distributeRevenue() {
            if (confirm('Are you sure you want to distribute revenue to gym owners?')) {
                fetch('distribute_revenue.php', {
                    method: 'POST',
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message);
                        window.location.reload();
                    } else {
                        alert('Error: ' + data.error);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An unexpected error occurred. Please try again.');
                });
            }
        }
    </script>

 
</body>
</html>
