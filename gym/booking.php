<?php
ob_start();
include '../includes/navbar.php';
require_once '../config/database.php';

// Check authentication
if (!isset($_SESSION['owner_id'])) {
    header('Location: ../login.php');
    exit;
}

$db = new GymDatabase();
$conn = $db->getConnection();
$owner_id = $_SESSION['owner_id'];

// Get gym details
$stmt = $conn->prepare("SELECT gym_id, name, balance, last_payout_date FROM gyms WHERE owner_id = ?");
$stmt->execute([$owner_id]);
$gym = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$gym) {
    echo "<script>window.location.href = 'add_gym.php';</script>";
    exit;
}

$gym_id = $gym['gym_id'];

// Active tab handling
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'bookings';
$valid_tabs = ['bookings', 'attendance', 'earnings'];
if (!in_array($active_tab, $valid_tabs)) {
    $active_tab = 'bookings';
}

// Pagination setup
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$offset = ($page - 1) * $limit;

// ===== BOOKINGS TAB DATA =====
if ($active_tab == 'bookings') {
    // Get total count for today's bookings
    $stmt = $conn->prepare("
        SELECT COUNT(*) 
        FROM schedules s 
        WHERE s.gym_id = ?
        AND DATE(s.start_date) = CURRENT_DATE
        AND s.status = 'scheduled'
    ");
    $stmt->execute([$gym_id]);
    $totalTodayBookings = $stmt->fetchColumn();

    // Get total count for tomorrow's bookings
    $stmt = $conn->prepare("
        SELECT COUNT(*) 
        FROM schedules s 
        WHERE s.gym_id = ?
        AND DATE(s.start_date) = DATE_ADD(CURRENT_DATE, INTERVAL 1 DAY)
        AND s.status = 'scheduled'
    ");
    $stmt->execute([$gym_id]);
    $totalTomorrowBookings = $stmt->fetchColumn();

    // Calculate total pages
    $totalPages = ceil(max($totalTodayBookings, $totalTomorrowBookings) / $limit);

    $stmt = $conn->prepare("
    SELECT s.*, u.username, u.email, u.phone
    FROM schedules s 
    JOIN users u ON s.user_id = u.id
    WHERE s.gym_id = ?
    AND DATE(s.start_date) = CURRENT_DATE
    AND s.status = 'scheduled'
    ORDER BY s.start_time ASC
    LIMIT ? OFFSET ?
");
$stmt->bindParam(1, $gym_id, PDO::PARAM_INT);
$stmt->bindParam(2, $limit, PDO::PARAM_INT);
$stmt->bindParam(3, $offset, PDO::PARAM_INT);
$stmt->execute();
$todayBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $todayBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get tomorrow's scheduled visits
    $stmt = $conn->prepare("
        SELECT s.*, u.username, u.email, u.phone
        FROM schedules s 
        JOIN users u ON s.user_id = u.id
        WHERE s.gym_id = ?
        AND DATE(s.start_date) = DATE_ADD(CURRENT_DATE, INTERVAL 1 DAY)
        AND s.status = 'scheduled'
        ORDER BY s.start_time ASC
        LIMIT ? OFFSET ?
    ");
    $stmt->bindParam(1, $gym_id, PDO::PARAM_INT);
    $stmt->bindParam(2, $limit, PDO::PARAM_INT);
    $stmt->bindParam(3, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $tomorrowBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===== ATTENDANCE TAB DATA =====
if ($active_tab == 'attendance') {
    // Count total today's visits
    $countVisitsStmt = $conn->prepare("
        SELECT COUNT(*) as total
        FROM schedules s 
        JOIN users u ON s.user_id = u.id
        WHERE s.gym_id = ? 
        AND DATE(s.start_date) = CURRENT_DATE
    ");
    $countVisitsStmt->execute([$gym_id]);
    $totalVisits = $countVisitsStmt->fetchColumn();
    $totalVisitsPages = ceil($totalVisits / $limit);

    // Get today's visits with pagination
    $stmt = $conn->prepare("
        SELECT s.*, u.username, u.email, u.phone
        FROM schedules s 
        JOIN users u ON s.user_id = u.id
        WHERE s.gym_id = ? 
        AND DATE(s.start_date) = CURRENT_DATE
        ORDER BY s.start_time ASC
        LIMIT ? OFFSET ?
    ");
    $stmt->bindParam(1, $gym_id, PDO::PARAM_INT);
    $stmt->bindParam(2, $limit, PDO::PARAM_INT);
    $stmt->bindParam(3, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $today_visits = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Count total earnings records
    $countEarningsStmt = $conn->prepare("
        SELECT COUNT(DISTINCT DATE(s.start_date)) as total
        FROM schedules s
        WHERE s.gym_id = ?
    ");
    $countEarningsStmt->execute([$gym_id]);
    $totalEarnings = $countEarningsStmt->fetchColumn();
    $totalEarningsPages = ceil($totalEarnings / $limit);

    // Get earnings history with pagination
    $stmt = $conn->prepare("
        SELECT DATE(s.start_date) as date,
               COUNT(*) as visit_count,
               SUM(s.daily_rate) as total_earnings
        FROM schedules s
        WHERE s.gym_id = ?
        GROUP BY DATE(s.start_date)
        ORDER BY date DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->bindParam(1, $gym_id, PDO::PARAM_INT);
$stmt->bindParam(2, $limit, PDO::PARAM_INT);
$stmt->bindParam(3, $offset, PDO::PARAM_INT);
$stmt->execute();
    $earnings_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate today's stats
    $todayStatsStmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_visits,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_visits,
            SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) as pending_visits,
            SUM(daily_rate) as today_earnings
        FROM schedules
        WHERE gym_id = ? AND DATE(start_date) = CURRENT_DATE
    ");
    $todayStatsStmt->execute([$gym_id]);
    $today_stats = $todayStatsStmt->fetch(PDO::FETCH_ASSOC);
}

// ===== EARNINGS TAB DATA =====
if ($active_tab == 'earnings') {
    // Calculate total withdrawable balance from completed schedules
    $withdrawableStmt = $conn->prepare("
        SELECT SUM(daily_rate) as total_withdrawable
        FROM schedules
        WHERE gym_id = ? AND status = 'completed'
    ");
    $withdrawableStmt->execute([$gym_id]);
    $withdrawableBalance = $withdrawableStmt->fetchColumn() ?: 0;

    // Get recent withdrawals
    $withdrawalsStmt = $conn->prepare("
        SELECT amount, status, created_at
        FROM withdrawals
        WHERE gym_id = ?
        ORDER BY created_at DESC
        LIMIT 5
    ");
    $withdrawalsStmt->execute([$gym_id]);
    $recent_withdrawals = $withdrawalsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch membership sales with revenue calculations
    $stmt = $conn->prepare("
        SELECT 
            um.*,
            gmp.plan_name as plan_name,
            gmp.tier,
            gmp.duration,
            gmp.price as plan_price,
            u.username,
            CASE 
                WHEN gmp.price BETWEEN fbc.price_range_start AND fbc.price_range_end 
                THEN fbc.gym_cut_percentage
                ELSE coc.gym_owner_cut_percentage
            END as gym_cut_percentage,
            CASE 
                WHEN gmp.price BETWEEN fbc.price_range_start AND fbc.price_range_end 
                THEN (gmp.price * fbc.gym_cut_percentage / 100)
                ELSE (gmp.price * coc.gym_owner_cut_percentage / 100)
            END as gym_earnings
        FROM user_memberships um
        JOIN gym_membership_plans gmp ON um.plan_id = gmp.plan_id
        JOIN users u ON um.user_id = u.id
        LEFT JOIN cut_off_chart coc ON gmp.tier = coc.tier AND gmp.duration = coc.duration
        LEFT JOIN fee_based_cuts fbc ON gmp.price BETWEEN fbc.price_range_start AND fbc.price_range_end
        WHERE um.gym_id = ? AND um.payment_status = 'paid'
        ORDER BY um.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->bindParam(1, $gym_id, PDO::PARAM_INT);
$stmt->bindParam(2, $limit, PDO::PARAM_INT);
$stmt->bindParam(3, $offset, PDO::PARAM_INT);
$stmt->execute();
    $memberships = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate analytics
    $total_earnings = array_sum(array_column($memberships, 'gym_earnings'));
    $total_memberships = count($memberships);
    $membership_by_tier = array_count_values(array_column($memberships, 'tier'));
    $membership_by_duration = array_count_values(array_column($memberships, 'duration'));

    // Get all completed schedules for total earnings calculation
    $allSchedulesStmt = $conn->prepare("
        SELECT 
            s.id, 
            s.start_date,
            s.gym_id,
            s.membership_id,
            gmp.price as plan_price,
            gmp.duration,
            CASE 
                WHEN gmp.price BETWEEN fbc.price_range_start AND fbc.price_range_end 
                THEN fbc.gym_cut_percentage
                ELSE coc.gym_owner_cut_percentage
            END as gym_cut_percentage
        FROM schedules s
        JOIN user_memberships um ON s.membership_id = um.id
        JOIN gym_membership_plans gmp ON um.plan_id = gmp.plan_id
        LEFT JOIN cut_off_chart coc ON gmp.tier = coc.tier AND gmp.duration = coc.duration
        LEFT JOIN fee_based_cuts fbc ON gmp.price BETWEEN fbc.price_range_start AND fbc.price_range_end
        WHERE s.gym_id = ? AND s.status = 'completed'
    ");
    $allSchedulesStmt->execute([$gym_id]);
    $allSchedules = $allSchedulesStmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate daily rate earnings from completed schedules
    $start_earnings = 0;
    $daily_start_earnings = [];

    foreach($allSchedules as $schedule) {
        // Check if the required keys exist
        if (!isset($schedule['start_date']) || !isset($schedule['duration']) || !isset($schedule['plan_price'])) {
            continue; // Skip this record if missing required data
        }
        
        // Calculate the daily rate based on plan price and duration
        $daily_rate = 0;
        
        // Convert duration to days for calculation
        $days = 0;
        switch($schedule['duration']) {
            case 'Monthly': $days = 30; break;
            case 'Quarterly': $days = 90; break;
            case 'Half-yearly': $days = 180; break;
            case 'Yearly': $days = 365; break;
            case 'Weekly': $days = 7; break;
        }
        
        if($days > 0) {
            $daily_rate = $schedule['plan_price'] / $days;
            
            // Apply gym cut percentage
            $daily_gym_earning = $daily_rate * ($schedule['gym_cut_percentage'] / 100);
            
            // Add to total schedule earnings
            $start_earnings += $daily_gym_earning;
            
            // Group by date
            $date = date('Y-m-d', strtotime($schedule['start_date']));
            if(!isset($daily_start_earnings[$date])) {
                $daily_start_earnings[$date] = 0;
            }
            $daily_start_earnings[$date] += $daily_gym_earning;
        }
    }

    // Total withdrawable balance is ONLY from schedule earnings
    $total_withdrawable_balance = $start_earnings;

    // Pagination for daily earnings
    $dailyEarningsPage = isset($_GET['daily_page']) ? (int)$_GET['daily_page'] : 1;
    $dailyEarningsLimit = isset($_GET['daily_limit']) ? (int)$_GET['daily_limit'] : 10;
    $dailyEarningsOffset = ($dailyEarningsPage - 1) * $dailyEarningsLimit;
    
    // Sort by date in descending order
    krsort($daily_start_earnings);
    $totalDailyRecords = count($daily_start_earnings);
    $totalDailyPages = ceil($totalDailyRecords / $dailyEarningsLimit);
    
    // Get paginated slice
    $paginatedDailyEarnings = array_slice($daily_start_earnings, $dailyEarningsOffset, $dailyEarningsLimit, true);
}

// Function to generate pagination links
function generatePaginationLinks($currentPage, $totalPages, $paramName = 'page') {
    $links = '';
    $queryParams = $_GET;
    
    // Previous page link
    if ($currentPage > 1) {
        $queryParams[$paramName] = $currentPage - 1;
        $links .= '<a href="?' . http_build_query($queryParams) . '" class="px-3 py-2 bg-gray-200 text-gray-700 rounded-l hover:bg-gray-300">&laquo;</a>';
    } else {
        $links .= '<span class="px-3 py-2 bg-gray-100 text-gray-400 rounded-l">&laquo;</span>';
    }
    
    // Page number links
    $startPage = max(1, $currentPage - 2);
    $endPage = min($totalPages, $currentPage + 2);
    
    if ($startPage > 1) {
        $queryParams[$paramName] = 1;
        $links .= '<a href="?' . http_build_query($queryParams) . '" class="px-3 py-2 bg-gray-200 text-gray-700 hover:bg-gray-300">1</a>';
        if ($startPage > 2) {
            $links .= '<span class="px-3 py-2">...</span>';
        }
    }
    
    for ($i = $startPage; $i <= $endPage; $i++) {
        $queryParams[$paramName] = $i;
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
        $queryParams[$paramName] = $totalPages;
        $links .= '<a href="?' . http_build_query($queryParams) . '" class="px-3 py-2 bg-gray-200 text-gray-700 hover:bg-gray-300">' . $totalPages . '</a>';
    }
    
    // Next page link
    if ($currentPage < $totalPages) {
        $queryParams[$paramName] = $currentPage + 1;
        $links .= '<a href="?' . http_build_query($queryParams) . '" class="px-3 py-2 bg-gray-200 text-gray-700 rounded-r hover:bg-gray-300">&raquo;</a>';
    } else {
        $links .= '<span class="px-3 py-2 bg-gray-100 text-gray-400 rounded-r">&raquo;</span>';
    }
    
    return $links;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gym Management Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/2.9.4/Chart.min.css">
    <!-- Preload critical resources -->
    <link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/2.9.4/Chart.min.js" as="script">
    <style>
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        /* Loading indicator styles */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }
        .spinner {
            border: 5px solid #f3f3f3;
            border-top: 5px solid #3498db;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body class="bg-gray-100 dark:bg-gray-900 transition-colors duration-200">
    <!-- Loading Overlay -->
    <!-- <div id="loadingOverlay" class="loading-overlay hidden">
        <div class="spinner"></div>
    </div> -->

    <div class="container mx-auto px-4 py-8">
        <!-- Dashboard Header -->
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 mb-6">
            <div class="flex flex-col md:flex-row justify-between items-center">
                <div>
                    <h1 class="text-2xl font-bold text-gray-800 dark:text-white"><?= htmlspecialchars($gym['name']) ?> Dashboard</h1>
                    <p class="text-gray-600 dark:text-gray-300">Manage your gym operations efficiently</p>
                </div>
                <div class="mt-4 md:mt-0 flex flex-col items-end">
                    <div class="text-lg font-semibold text-gray-700 dark:text-gray-200">
                        Current Balance: <span class="text-green-600 dark:text-green-400">₹<?= number_format($gym['balance'], 2) ?></span>
                    </div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">
                        Last Payout: <?= $gym['last_payout_date'] ? date('d M Y', strtotime($gym['last_payout_date'])) : 'No payouts yet' ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab Navigation -->
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md mb-6 overflow-hidden">
            <div class="flex flex-wrap border-b border-gray-200 dark:border-gray-700">
                <a href="?tab=bookings" class="tab-link px-6 py-3 text-center <?= $active_tab == 'bookings' ? 'bg-blue-500 text-white' : 'text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700' ?>">
                    <i class="fas fa-calendar-alt mr-2"></i>Bookings
                </a>
                <a href="?tab=attendance" class="tab-link px-6 py-3 text-center <?= $active_tab == 'attendance' ? 'bg-blue-500 text-white' : 'text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700' ?>">
                    <i class="fas fa-clipboard-check mr-2"></i>Attendance
                </a>
                <a href="?tab=earnings" class="tab-link px-6 py-3 text-center <?= $active_tab == 'earnings' ? 'bg-blue-500 text-white' : 'text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700' ?>">
                    <i class="fas fa-chart-line mr-2"></i>Earnings
                </a>
            </div>
        </div>

        <!-- Bookings Tab Content -->
        <div id="bookingsTab" class="tab-content <?= $active_tab == 'bookings' ? 'active' : '' ?>">
            <!-- Today's Bookings -->
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 mb-6">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-xl font-semibold text-gray-800 dark:text-white">Today's Bookings</h2>
                    <span class="px-3 py-1 bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200 rounded-full text-sm font-medium">
                        Total: <?= $totalTodayBookings ?>
                    </span>
                </div>

                <?php if (empty($todayBookings)): ?>
                    <div class="text-center py-8 text-gray-500 dark:text-gray-400">
                        <i class="fas fa-calendar-times text-4xl mb-3"></i>
                        <p>No bookings scheduled for today.</p>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Member</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Time</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                <?php foreach ($todayBookings as $booking): ?>
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="ml-4">
                                                    <div class="text-sm font-medium text-gray-900 dark:text-white"><?= htmlspecialchars($booking['username']) ?></div>
                                                    <div class="text-sm text-gray-500 dark:text-gray-400"><?= htmlspecialchars($booking['email']) ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900 dark:text-white"><?= date('h:i A', strtotime($booking['start_time'])) ?></div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                                <?php 
                                                switch($booking['status']) {
                                                    case 'scheduled': echo 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200'; break;
                                                    case 'completed': echo 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'; break;
                                                    case 'cancelled': echo 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'; break;
                                                    default: echo 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300';
                                                }
                                                ?>">
                                                <?= ucfirst($booking['status']) ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <?php if ($booking['status'] == 'scheduled'): ?>
                                                <button onclick="checkInMember(<?= $booking['id'] ?>)" class="text-green-600 hover:text-green-900 dark:text-green-400 dark:hover:text-green-300 mr-3">
                                                    <i class="fas fa-check-circle mr-1"></i> Check In
                                                </button>
                                                <button onclick="cancelBooking(<?= $booking['id'] ?>)" class="text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300">
                                                    <i class="fas fa-times-circle mr-1"></i> Cancel
                                                </button>
                                            <?php elseif ($booking['status'] == 'completed'): ?>
                                                <button onclick="checkOutMember(<?= $booking['id'] ?>)" class="text-blue-600 hover:text-blue-900 dark:text-blue-400 dark:hover:text-blue-300">
                                                    <i class="fas fa-sign-out-alt mr-1"></i> Check Out
                                                </button>
                                            <?php else: ?>
                                                <span class="text-gray-500 dark:text-gray-400">No actions available</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination -->
                    <div class="mt-4 flex justify-center">
                        <div class="flex">
                            <?= generatePaginationLinks($page, $totalPages) ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Tomorrow's Bookings -->
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-xl font-semibold text-gray-800 dark:text-white">Tomorrow's Bookings</h2>
                    <span class="px-3 py-1 bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200 rounded-full text-sm font-medium">
                        Total: <?= $totalTomorrowBookings ?>
                    </span>
                </div>

                <?php if (empty($tomorrowBookings)): ?>
                    <div class="text-center py-8 text-gray-500 dark:text-gray-400">
                        <i class="fas fa-calendar-day text-4xl mb-3"></i>
                        <p>No bookings scheduled for tomorrow.</p>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Member</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Time</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                <?php foreach ($tomorrowBookings as $booking): ?>
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="ml-4">
                                                    <div class="text-sm font-medium text-gray-900 dark:text-white"><?= htmlspecialchars($booking['username']) ?></div>
                                                    <div class="text-sm text-gray-500 dark:text-gray-400"><?= htmlspecialchars($booking['email']) ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900 dark:text-white"><?= date('h:i A', strtotime($booking['start_time'])) ?></div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200">
                                                <?= ucfirst($booking['status']) ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <button onclick="cancelBooking(<?= $booking['id'] ?>)" class="text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300">
                                                <i class="fas fa-times-circle mr-1"></i> Cancel
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Attendance Tab Content -->
        <div id="attendanceTab" class="tab-content <?= $active_tab == 'attendance' ? 'active' : '' ?>">
            <!-- Today's Stats -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-blue-100 text-blue-500 dark:bg-blue-900 dark:text-blue-300">
                            <i class="fas fa-users text-2xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Visits</p>
                            <p class="text-lg font-semibold text-gray-700 dark:text-gray-200"><?= $today_stats['total_visits'] ?? 0 ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-green-100 text-green-500 dark:bg-green-900 dark:text-green-300">
                            <i class="fas fa-check-circle text-2xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Completed</p>
                            <p class="text-lg font-semibold text-gray-700 dark:text-gray-200"><?= $today_stats['completed_visits'] ?? 0 ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-yellow-100 text-yellow-500 dark:bg-yellow-900 dark:text-yellow-300">
                            <i class="fas fa-clock text-2xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Pending</p>
                            <p class="text-lg font-semibold text-gray-700 dark:text-gray-200"><?= $today_stats['pending_visits'] ?? 0 ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-purple-100 text-purple-500 dark:bg-purple-900 dark:text-purple-300">
                            <i class="fas fa-rupee-sign text-2xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Today's Earnings</p>
                            <p class="text-lg font-semibold text-gray-700 dark:text-gray-200">₹<?= number_format($today_stats['today_earnings'] ?? 0, 2) ?></p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Today's Visits -->
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 mb-6">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-xl font-semibold text-gray-800 dark:text-white">Today's Visits</h2>
                    <div class="flex space-x-2">
                        <button id="printAttendanceBtn" class="px-3 py-1 bg-blue-500 text-white rounded hover:bg-blue-600 transition-colors">
                            <i class="fas fa-print mr-1"></i> Print
                        </button>
                        <button id="exportAttendanceBtn" class="px-3 py-1 bg-green-500 text-white rounded hover:bg-green-600 transition-colors">
                            <i class="fas fa-file-export mr-1"></i> Export
                        </button>
                    </div>
                </div>

                <?php if (empty($today_visits)): ?>
                    <div class="text-center py-8 text-gray-500 dark:text-gray-400">
                        <i class="fas fa-clipboard text-4xl mb-3"></i>
                        <p>No visits recorded for today.</p>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700" id="attendanceTable">
                            <thead class="bg-gray-50 dark:bg-gray-700">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Member</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Time</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Check-in</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Check-out</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                <?php foreach ($today_visits as $visit): ?>
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="ml-4">
                                                    <div class="text-sm font-medium text-gray-900 dark:text-white"><?= htmlspecialchars($visit['username']) ?></div>
                                                    <div class="text-sm text-gray-500 dark:text-gray-400"><?= htmlspecialchars($visit['email']) ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900 dark:text-white"><?= date('h:i A', strtotime($visit['start_time'])) ?></div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                                <?php 
                                                switch($visit['status']) {
                                                    case 'scheduled': echo 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200'; break;
                                                    case 'completed': echo 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'; break;
                                                    case 'cancelled': echo 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'; break;
                                                    case 'missed': echo 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300'; break;
                                                    default: echo 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300';
                                                }
                                                ?>">
                                                <?= ucfirst($visit['status']) ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900 dark:text-white">
                                                <?= $visit['check_in_time'] ? date('h:i A', strtotime($visit['check_in_time'])) : '-' ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900 dark:text-white">
                                                <?= $visit['check_out_time'] ? date('h:i A', strtotime($visit['check_out_time'])) : '-' ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <?php if ($visit['status'] == 'scheduled'): ?>
                                                <button onclick="checkInMember(<?= $visit['id'] ?>)" class="text-green-600 hover:text-green-900 dark:text-green-400 dark:hover:text-green-300 mr-3">
                                                    <i class="fas fa-check-circle mr-1"></i> Check In
                                                </button>
                                            <?php elseif ($visit['status'] == 'completed' && !$visit['check_out_time']): ?>
                                                <button onclick="checkOutMember(<?= $visit['id'] ?>)" class="text-blue-600 hover:text-blue-900 dark:text-blue-400 dark:hover:text-blue-300">
                                                    <i class="fas fa-sign-out-alt mr-1"></i> Check Out
                                                </button>
                                            <?php else: ?>
                                                <span class="text-gray-500 dark:text-gray-400">No actions available</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination -->
                    <div class="mt-4 flex justify-center">
                        <div class="flex">
                            <?= generatePaginationLinks($page, $totalVisitsPages) ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Attendance History -->
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-xl font-semibold text-gray-800 dark:text-white">Attendance History</h2>
                    <div>
                        <input type="date" id="historyDateFilter" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md dark:bg-gray-700 dark:text-white">
                        <button id="filterHistoryBtn" class="px-3 py-2 bg-blue-500 text-white rounded hover:bg-blue-600 transition-colors ml-2">
                            <i class="fas fa-filter mr-1"></i> Filter
                        </button>
                    </div>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Total Visits</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Completed</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Earnings</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700" id="historyTableBody">
                            <?php foreach ($earnings_history as $history): ?>
                                <tr>
                                <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900 dark:text-white"><?= date('d M Y', strtotime($history['date'])) ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900 dark:text-white"><?= $history['visit_count'] ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php
                                        // Get completed visits count for this date
                                        $completedStmt = $conn->prepare("
                                            SELECT COUNT(*) FROM schedules 
                                            WHERE gym_id = ? AND DATE(start_date) = ? AND status = 'completed'
                                        ");
                                        $completedStmt->execute([$gym_id, $history['date']]);
                                        $completedCount = $completedStmt->fetchColumn();
                                        ?>
                                        <div class="text-sm text-gray-900 dark:text-white"><?= $completedCount ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900 dark:text-white">₹<?= number_format($history['total_earnings'], 2) ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <a href="daily_report.php?date=<?= $history['date'] ?>" class="text-blue-600 hover:text-blue-900 dark:text-blue-400 dark:hover:text-blue-300">
                                            <i class="fas fa-file-alt mr-1"></i> View Report
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <div class="mt-4 flex justify-center">
                    <div class="flex">
                        <?= generatePaginationLinks($page, $totalEarningsPages) ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Earnings Tab Content -->
        <div id="earningsTab" class="tab-content <?= $active_tab == 'earnings' ? 'active' : '' ?>">
            <!-- Earnings Overview -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-green-100 text-green-500 dark:bg-green-900 dark:text-green-300">
                            <i class="fas fa-wallet text-2xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Current Balance</p>
                            <p class="text-lg font-semibold text-gray-700 dark:text-gray-200">₹<?= number_format($gym['balance'], 2) ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-blue-100 text-blue-500 dark:bg-blue-900 dark:text-blue-300">
                            <i class="fas fa-hand-holding-usd text-2xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Withdrawable</p>
                            <p class="text-lg font-semibold text-gray-700 dark:text-gray-200">₹<?= number_format($total_withdrawable_balance, 2) ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-purple-100 text-purple-500 dark:bg-purple-900 dark:text-purple-300">
                                <i class="fas fa-money-bill-wave text-2xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Request Withdrawal</p>
                            </div>
                        </div>
                        <button id="withdrawBtn" class="px-3 py-1 bg-purple-500 text-white rounded hover:bg-purple-600 transition-colors">
                            <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Earnings Charts -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-white mb-4">Monthly Earnings</h3>
                    <canvas id="monthlyEarningsChart" height="250"></canvas>
                </div>
                
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-white mb-4">Membership Distribution</h3>
                    <canvas id="membershipDistributionChart" height="250"></canvas>
                </div>
            </div>
            
            <!-- Daily Earnings -->
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 mb-6">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-xl font-semibold text-gray-800 dark:text-white">Daily Earnings</h2>
                    <div class="flex space-x-2">
                        <button id="printEarningsBtn" class="px-3 py-1 bg-blue-500 text-white rounded hover:bg-blue-600 transition-colors">
                            <i class="fas fa-print mr-1"></i> Print
                        </button>
                        <button id="exportEarningsBtn" class="px-3 py-1 bg-green-500 text-white rounded hover:bg-green-600 transition-colors">
                            <i class="fas fa-file-export mr-1"></i> Export
                        </button>
                    </div>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700" id="earningsTable">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Earnings</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                            <?php foreach ($paginatedDailyEarnings as $date => $amount): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900 dark:text-white"><?= date('d M Y', strtotime($date)) ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900 dark:text-white">₹<?= number_format($amount, 2) ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php
                                        // Check if this date's earnings have been paid out
                                        $paidStmt = $conn->prepare("
                                            SELECT COUNT(*) FROM gym_revenue_distribution 
                                            WHERE gym_id = ? AND distribution_date = ? AND status = 'paid'
                                        ");
                                        $paidStmt->execute([$gym_id, $date]);
                                        $isPaid = $paidStmt->fetchColumn() > 0;
                                        ?>
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                            <?= $isPaid ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200' ?>">
                                            <?= $isPaid ? 'Paid' : 'Pending' ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <a href="daily_earnings.php?date=<?= $date ?>" class="text-blue-600 hover:text-blue-900 dark:text-blue-400 dark:hover:text-blue-300">
                                            <i class="fas fa-file-alt mr-1"></i> View Details
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <div class="mt-4 flex justify-center">
                    <div class="flex">
                        <?= generatePaginationLinks($dailyEarningsPage, $totalDailyPages, 'daily_page') ?>
                    </div>
                </div>
            </div>
            
            <!-- Membership Sales -->
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-xl font-semibold text-gray-800 dark:text-white">Membership Sales</h2>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Member</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Plan</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Duration</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Price</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Gym Earnings</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Date</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                            <?php foreach ($memberships as $membership): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900 dark:text-white"><?= htmlspecialchars($membership['username']) ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900 dark:text-white"><?= htmlspecialchars($membership['plan_name']) ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900 dark:text-white"><?= htmlspecialchars($membership['duration']) ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900 dark:text-white">₹<?= number_format($membership['plan_price'], 2) ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900 dark:text-white">₹<?= number_format($membership['gym_earnings'], 2) ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900 dark:text-white"><?= date('d M Y', strtotime($membership['created_at'])) ?></div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Withdrawal Modal -->
    <div id="withdrawalModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-lg p-6 w-full max-w-md">
        <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-semibold text-gray-800 dark:text-white">Request Withdrawal</h3>
                <button id="closeWithdrawalModal" class="text-gray-500 hover:text-gray-700 dark:text-gray-300 dark:hover:text-gray-100">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form id="withdrawalForm" action="process_withdrawal.php" method="POST">
                <div class="mb-4">
                    <label for="withdrawalAmount" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Amount</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-gray-500 dark:text-gray-400">₹</span>
                        <input type="number" id="withdrawalAmount" name="amount" min="100" max="<?= $total_withdrawable_balance ?>" step="0.01" required
                            class="pl-8 w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                            placeholder="Enter amount">
                    </div>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Maximum: ₹<?= number_format($total_withdrawable_balance, 2) ?></p>
                </div>
                
                <div class="mb-4">
                    <label for="paymentMethod" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Payment Method</label>
                    <select id="paymentMethod" name="payment_method" required
                        class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                        <option value="">Select payment method</option>
                        <?php
                        // Get payment methods for this owner
                        $paymentMethodsStmt = $conn->prepare("
                            SELECT id, method_type, account_name, account_number, bank_name, upi_id 
                            FROM payment_methods 
                            WHERE owner_id = ?
                        ");
                        $paymentMethodsStmt->execute([$owner_id]);
                        $paymentMethods = $paymentMethodsStmt->fetchAll(PDO::FETCH_ASSOC);
                        
                        foreach ($paymentMethods as $method):
                            if ($method['method_type'] == 'bank'):
                        ?>
                            <option value="<?= $method['id'] ?>">
                                Bank: <?= htmlspecialchars($method['bank_name']) ?> - <?= substr($method['account_number'], -4) ?>
                            </option>
                        <?php elseif ($method['method_type'] == 'upi'): ?>
                            <option value="<?= $method['id'] ?>">
                                UPI: <?= htmlspecialchars($method['upi_id']) ?>
                            </option>
                        <?php endif; endforeach; ?>
                        <option value="new">+ Add new payment method</option>
                    </select>
                </div>
                
                <div id="newPaymentMethodFields" class="hidden">
                    <div class="mb-4">
                        <label for="methodType" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Method Type</label>
                        <select id="methodType" name="method_type" 
                            class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            <option value="bank">Bank Account</option>
                            <option value="upi">UPI</option>
                        </select>
                    </div>
                    
                    <div id="bankFields">
                        <div class="mb-4">
                            <label for="accountName" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Account Holder Name</label>
                            <input type="text" id="accountName" name="account_name" 
                                class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                                placeholder="Enter account holder name">
                        </div>
                        
                        <div class="mb-4">
                            <label for="accountNumber" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Account Number</label>
                            <input type="text" id="accountNumber" name="account_number" 
                                class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                                placeholder="Enter account number">
                        </div>
                        
                        <div class="mb-4">
                            <label for="ifscCode" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">IFSC Code</label>
                            <input type="text" id="ifscCode" name="ifsc_code" 
                                class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                                placeholder="Enter IFSC code">
                        </div>
                        
                        <div class="mb-4">
                            <label for="bankName" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Bank Name</label>
                            <input type="text" id="bankName" name="bank_name" 
                                class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                                placeholder="Enter bank name">
                        </div>
                    </div>
                    
                    <div id="upiFields" class="hidden">
                        <div class="mb-4">
                            <label for="upiId" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">UPI ID</label>
                            <input type="text" id="upiId" name="upi_id" 
                                class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                                placeholder="Enter UPI ID">
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label class="flex items-center">
                            <input type="checkbox" name="save_payment_method" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                            <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">Save this payment method for future withdrawals</span>
                        </label>
                    </div>
                </div>
                
                <div class="mt-6 flex justify-end">
                    <button type="button" id="cancelWithdrawal" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300 mr-2">
                        Cancel
                    </button>
                    <button type="submit" class="px-4 py-2 bg-blue-500 text-white rounded-md hover:bg-blue-600">
                        Request Withdrawal
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/2.9.4/Chart.min.js"></script>
    <script>
        // Show/hide loading overlay
        function showLoading() {
            document.getElementById('loadingOverlay').classList.remove('hidden');
        }
        
        function hideLoading() {
            document.getElementById('loadingOverlay').classList.add('hidden');
        }
        
        // Hide loading when page is fully loaded
        window.addEventListener('load', hideLoading);
        
        // Check-in functionality
        function checkInMember(scheduleId) {
            if (confirm('Are you sure you want to check in this member?')) {
                showLoading();
                fetch('process_checkin.php', {
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
        
        // Print functionality
        document.getElementById('printAttendanceBtn')?.addEventListener('click', function() {
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <html>
                <head>
                    <title>Attendance Report - ${new Date().toLocaleDateString()}</title>
                    <style>
                        body { font-family: Arial, sans-serif; }
                        table { width: 100%; border-collapse: collapse; }
                        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                        th { background-color: #f2f2f2; }
                        .header { text-align: center; margin-bottom: 20px; }
                        .header h1 { margin-bottom: 5px; }
                        .header p { margin-top: 0; color: #666; }
                    </style>
                </head>
                <body>
                    <div class="header">
                        <h1>${<?= json_encode(htmlspecialchars($gym['name'])) ?>} - Attendance Report</h1>
                        <p>Date: ${new Date().toLocaleDateString()}</p>
                    </div>
                    ${document.getElementById('attendanceTable').outerHTML}
                </body>
                </html>
            `);
            printWindow.document.close();
            printWindow.focus();
            printWindow.print();
            printWindow.close();
        });
        
        document.getElementById('printEarningsBtn')?.addEventListener('click', function() {
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <html>
                <head>
                    <title>Earnings Report - ${new Date().toLocaleDateString()}</title>
                    <style>
                        body { font-family: Arial, sans-serif; }
                        table { width: 100%; border-collapse: collapse; }
                        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                        th { background-color: #f2f2f2; }
                        .header { text-align: center; margin-bottom: 20px; }
                        .header h1 { margin-bottom: 5px; }
                        .header p { margin-top: 0; color: #666; }
                    </style>
                </head>
                <body>
                    <div class="header">
                        <h1>${<?= json_encode(htmlspecialchars($gym['name'])) ?>} - Earnings Report</h1>
                        <p>Date: ${new Date().toLocaleDateString()}</p>
                    </div>
                    ${document.getElementById('earningsTable').outerHTML}
                </body>
                </html>
            `);
            printWindow.document.close();
            printWindow.focus();
            printWindow.print();
            printWindow.close();
        });
        
        // Export functionality
        document.getElementById('exportAttendanceBtn')?.addEventListener('click', function() {
            window.location.href = 'export_attendance.php';
        });
        
        document.getElementById('exportEarningsBtn')?.addEventListener('click', function() {
            window.location.href = 'export_earnings.php';
        });
        
                // Withdrawal modal functionality
                const withdrawalModal = document.getElementById('withdrawalModal');
        const withdrawBtn = document.getElementById('withdrawBtn');
        const closeWithdrawalModal = document.getElementById('closeWithdrawalModal');
        const cancelWithdrawal = document.getElementById('cancelWithdrawal');
        const paymentMethod = document.getElementById('paymentMethod');
        const newPaymentMethodFields = document.getElementById('newPaymentMethodFields');
        const methodType = document.getElementById('methodType');
        const bankFields = document.getElementById('bankFields');
        const upiFields = document.getElementById('upiFields');
        
        if (withdrawBtn) {
            withdrawBtn.addEventListener('click', function() {
                withdrawalModal.classList.remove('hidden');
            });
        }
        
        if (closeWithdrawalModal) {
            closeWithdrawalModal.addEventListener('click', function() {
                withdrawalModal.classList.add('hidden');
            });
        }
        
        if (cancelWithdrawal) {
            cancelWithdrawal.addEventListener('click', function() {
                withdrawalModal.classList.add('hidden');
            });
        }
        
        if (paymentMethod) {
            paymentMethod.addEventListener('change', function() {
                if (this.value === 'new') {
                    newPaymentMethodFields.classList.remove('hidden');
                } else {
                    newPaymentMethodFields.classList.add('hidden');
                }
            });
        }
        
        if (methodType) {
            methodType.addEventListener('change', function() {
                if (this.value === 'bank') {
                    bankFields.classList.remove('hidden');
                    upiFields.classList.add('hidden');
                } else {
                    bankFields.classList.add('hidden');
                    upiFields.classList.remove('hidden');
                }
            });
        }
        
        // Initialize charts
        document.addEventListener('DOMContentLoaded', function() {
            // Only initialize charts if we're on the earnings tab
            if (document.getElementById('earningsTab').classList.contains('active')) {
                initializeCharts();
            }
            
            // Add tab switching functionality
            const tabLinks = document.querySelectorAll('.tab-link');
            tabLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    // We're using server-side tab switching, so we don't need to prevent default
                    // But we'll show loading indicator
                    showLoading();
                });
            });
        });
        
        function initializeCharts() {
            // Monthly Earnings Chart
            const monthlyEarningsCtx = document.getElementById('monthlyEarningsChart')?.getContext('2d');
            if (monthlyEarningsCtx) {
                // Get monthly data from PHP
                <?php
                // Calculate monthly earnings for the last 6 months
                $monthlyEarningsData = [];
                $monthLabels = [];
                
                for ($i = 5; $i >= 0; $i--) {
                    $month = date('Y-m', strtotime("-$i months"));
                    $monthStart = date('Y-m-01', strtotime("-$i months"));
                    $monthEnd = date('Y-m-t', strtotime("-$i months"));
                    
                    $monthlyStmt = $conn->prepare("
                        SELECT SUM(daily_rate) as monthly_earnings
                        FROM schedules
                        WHERE gym_id = ? AND status = 'completed'
                        AND start_date BETWEEN ? AND ?
                    ");
                    $monthlyStmt->execute([$gym_id, $monthStart, $monthEnd]);
                    $monthlyEarnings = $monthlyStmt->fetchColumn() ?: 0;
                    
                    $monthlyEarningsData[] = $monthlyEarnings;
                    $monthLabels[] = date('M Y', strtotime($month));
                }
                ?>
                
                const monthlyData = <?= json_encode($monthlyEarningsData) ?>;
                const monthLabels = <?= json_encode($monthLabels) ?>;
                
                new Chart(monthlyEarningsCtx, {
                    type: 'line',
                    data: {
                        labels: monthLabels,
                        datasets: [{
                            label: 'Monthly Earnings (₹)',
                            data: monthlyData,
                            backgroundColor: 'rgba(54, 162, 235, 0.2)',
                            borderColor: 'rgba(54, 162, 235, 1)',
                            borderWidth: 2,
                            pointBackgroundColor: 'rgba(54, 162, 235, 1)',
                            tension: 0.4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function(value) {
                                        return '₹' + value;
                                    }
                                }
                            }
                        },
                        tooltips: {
                            callbacks: {
                                label: function(tooltipItem, data) {
                                    return '₹' + tooltipItem.yLabel.toFixed(2);
                                }
                            }
                        }
                    }
                });
            }
            
            // Membership Distribution Chart
            const membershipDistributionCtx = document.getElementById('membershipDistributionChart')?.getContext('2d');
            if (membershipDistributionCtx) {
                <?php
                // Get membership distribution data
                $tierData = [];
                $tierLabels = [];
                
                foreach ($membership_by_tier as $tier => $count) {
                    $tierLabels[] = $tier;
                    $tierData[] = $count;
                }
                ?>
                
                const tierData = <?= json_encode($tierData) ?>;
                const tierLabels = <?= json_encode($tierLabels) ?>;
                
                new Chart(membershipDistributionCtx, {
                    type: 'doughnut',
                    data: {
                        labels: tierLabels,
                        datasets: [{
                            data: tierData,
                            backgroundColor: [
                                'rgba(255, 99, 132, 0.7)',
                                'rgba(54, 162, 235, 0.7)',
                                'rgba(255, 206, 86, 0.7)',
                                'rgba(75, 192, 192, 0.7)',
                                'rgba(153, 102, 255, 0.7)'
                            ],
                            borderColor: [
                                'rgba(255, 99, 132, 1)',
                                'rgba(54, 162, 235, 1)',
                                'rgba(255, 206, 86, 1)',
                                'rgba(75, 192, 192, 1)',
                                'rgba(153, 102, 255, 1)'
                            ],
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        legend: {
                            position: 'right'
                        },
                        tooltips: {
                            callbacks: {
                                label: function(tooltipItem, data) {
                                    const dataset = data.datasets[tooltipItem.datasetIndex];
                                    const total = dataset.data.reduce((previousValue, currentValue) => previousValue + currentValue);
                                    const currentValue = dataset.data[tooltipItem.index];
                                    const percentage = Math.round((currentValue / total) * 100);
                                    return data.labels[tooltipItem.index] + ': ' + currentValue + ' (' + percentage + '%)';
                                }
                            }
                        }
                    }
                });
            }
        }
    </script>
</body>
</html>





