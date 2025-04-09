<?php
ob_start();
include '../includes/navbar.php';
require_once '../config/database.php';

if (!isset($_SESSION['owner_id'])) {
    header("Location: login.php");
    exit();
}

$db = new GymDatabase();
$conn = $db->getConnection();
$owner_id = $_SESSION['owner_id'];

// Get gym details and balance
$stmt = $conn->prepare("
    SELECT gym_id, name, balance, last_payout_date 
    FROM gyms 
    WHERE owner_id = ?
");
$stmt->execute([$owner_id]);
$gym = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$gym) {
    echo "<script>window.location.href = 'add_gym.php';</script>";
    exit;
}

$gym_id = $gym['gym_id'];

// Fetch membership sales with revenue calculations (for display only)
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
");
$stmt->execute([$gym_id]);
$memberships = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate analytics (for display only)
$total_earnings = array_sum(array_column($memberships, 'gym_earnings'));
$total_memberships = count($memberships);
$membership_by_tier = array_count_values(array_column($memberships, 'tier'));
$membership_by_duration = array_count_values(array_column($memberships, 'duration'));

// Monthly trends
$monthly_sales = [];
foreach($memberships as $membership) {
    $month = date('Y-m', strtotime($membership['created_at']));
    if(!isset($monthly_sales[$month])) {
        $monthly_sales[$month] = [
            'count' => 0,
            'earnings' => 0
        ];
    }
    $monthly_sales[$month]['count']++;
    $monthly_sales[$month]['earnings'] += $membership['gym_earnings'];
}

// PAGINATION SETUP FOR COMPLETED SCHEDULES
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$offset = ($page - 1) * $limit;

// Count total completed schedules for pagination
$countStmt = $conn->prepare("
    SELECT COUNT(*) as total
    FROM schedules s
    WHERE s.gym_id = ? AND s.status = 'completed'
");
$countStmt->execute([$gym_id]);
$totalRecords = $countStmt->fetchColumn();
$totalPages = ceil($totalRecords / $limit);

// Count withdrawal balance from completed schedules ONLY with pagination
$scheduleStmt = $conn->prepare("
    SELECT 
        s.id, 
        s.start_date,
        s.start_time,
        s.status,
        s.gym_id,
        s.membership_id,
        gmp.price as plan_price,
        gmp.duration,
        gmp.tier,
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
    WHERE s.gym_id = :gym_id
    AND s.status = 'completed'
    ORDER BY s.start_date DESC
    LIMIT :limit OFFSET :offset
");
$scheduleStmt->bindParam(':gym_id', $gym_id, PDO::PARAM_INT);
$scheduleStmt->bindParam(':limit', $limit, PDO::PARAM_INT);
$scheduleStmt->bindParam(':offset', $offset, PDO::PARAM_INT);
$scheduleStmt->execute();

$schedules = $scheduleStmt->fetchAll(PDO::FETCH_ASSOC);

// Get all completed schedules for total earnings calculation (no pagination)
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
        case 'Monthly':
            $days = 30;
            break;
        case 'Quarterly':
            $days = 90;
            break;
        case 'Half-yearly':
            $days = 180;
            break;
        case 'Yearly':
            $days = 365;
            break;
        case 'Weekly':
            $days = 7;
            break;
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

// IMPORTANT: Total withdrawable balance is ONLY from schedule earnings, not membership earnings
$total_withdrawable_balance = $start_earnings;

// Function to generate pagination links
function generatePaginationLinks($currentPage, $totalPages, $limit) {
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
<div class="max-w-7xl mx-auto px-4 py-8">
    <!-- Header -->
    <div class="bg-white rounded-xl shadow-lg overflow-hidden my-10">
        <div class="p-6 bg-gradient-to-r from-gray-900 to-gray-800">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    <div class="h-16 w-16 rounded-full bg-yellow-500 flex items-center justify-center">
                        <i class="fas fa-money-bill-wave text-2xl text-white"></i>
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold text-white">Earnings Dashboard</h1>
                        <p class="text-white "><?= htmlspecialchars($gym['name']) ?></p>
                    </div>
                </div>
                <div class="text-white">
                    <p class="text-sm">Last Payout</p>
                    <p class="font-semibold"><?= $gym['last_payout_date'] ? date('M d, Y', strtotime($gym['last_payout_date'])) : 'No payouts yet' ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Stats Grid -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
        <div class="bg-white rounded-xl shadow-lg p-6 transform hover:scale-105 transition-transform duration-200">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-700">Withdrawable Balance</h3>
                <div class="h-12 w-12 rounded-full bg-green-100 flex items-center justify-center">
                    <i class="fas fa-wallet text-green-600"></i>
                </div>
            </div>
            <p class="text-3xl font-bold text-green-600">₹<?= number_format($total_withdrawable_balance, 2) ?></p>
            <p class="mt-2 text-sm text-gray-500">From completed schedules only</p>
            <button onclick="window.location.href='withdraw.php'" 
                    class="mt-4 w-full bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                <i class="fas fa-money-bill-wave mr-2"></i>Withdraw Funds
            </button>
        </div>

        <div class="bg-white rounded-xl shadow-lg p-6 transform hover:scale-105 transition-transform duration-200">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-700">Total Memberships</h3>
                <div class="h-12 w-12 rounded-full bg-blue-100 flex items-center justify-center">
                    <i class="fas fa-users text-blue-600"></i>
                </div>
            </div>
            <p class="text-3xl font-bold text-blue-600"><?= $total_memberships ?></p>
            <p class="mt-2 text-gray-600">Active members this month</p>
        </div>

        <div class="bg-white rounded-xl shadow-lg p-6 transform hover:scale-105 transition-transform duration-200">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-700">Completed Schedules</h3>
                <div class="h-12 w-12 rounded-full bg-purple-100 flex items-center justify-center">
                    <i class="fas fa-calendar-check text-purple-600"></i>
                </div>
            </div>
            <p class="text-3xl font-bold text-purple-600"><?= count($schedules) ?></p>
            <p class="mt-2 text-gray-600">Total completed sessions</p>
        </div>
    </div>

    <!-- Schedule Earnings Section -->
    <div class="bg-white rounded-xl shadow-lg p-6 mb-8">
        <h3 class="text-lg font-semibold text-gray-700 mb-6 flex items-center">
            <i class="fas fa-calendar-check text-yellow-500 mr-2"></i>
            Schedule Earnings (Withdrawable)
        </h3>
        
        <!-- Information Alert -->
        <div class="bg-blue-50 border-l-4 border-blue-500 p-4 mb-6">
            <div class="flex">
                <div class="flex-shrink-0">
                    <i class="fas fa-info-circle text-blue-500"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm text-blue-700">
                        As per policy, gym owners can only withdraw earnings from completed workout schedules. 
                        Membership fees are managed by the platform.
                    </p>
                </div>
            </div>
        </div>
         <!-- Pagination Controls - Top -->
         <div class="px-6 py-3 bg-gray-50 flex flex-wrap items-center justify-between">
            <div class="flex items-center space-x-2">
                <span class="text-sm text-gray-600">Show</span>
                <select id="paginationLimit" onchange="changeLimit(this.value)" class="bg-white border border-gray-300 rounded px-2 py-1 text-sm">
                    <option value="10" <?= $limit == 10 ? 'selected' : '' ?>>10</option>
                    <option value="25" <?= $limit == 25 ? 'selected' : '' ?>>25</option>
                    <option value="50" <?= $limit == 50 ? 'selected' : '' ?>>50</option>
                    <option value="100" <?= $limit == 100 ? 'selected' : '' ?>>100</option>
                </select>
                <span class="text-sm text-gray-600">entries</span>
            </div>
            
            <div class="text-sm text-gray-600">
                Showing <?= min(($page - 1) * $limit + 1, $totalRecords) ?> to <?= min($page * $limit, $totalRecords) ?> of <?= $totalRecords ?> entries
            </div>
        </div>
        <!-- Daily Schedule Earnings Chart -->
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Time</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Plan</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Daily Rate</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Your Cut</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php 
                    if (empty($schedules)) {
                        echo '<tr><td colspan="5" class="px-6 py-4 text-center text-gray-500">No completed schedules found</td></tr>';
                    } else {
                        foreach($schedules as $schedule): 
                            // Check if required keys exist
                            if (!isset($schedule['start_date']) || !isset($schedule['start_time']) || 
                                !isset($schedule['duration']) || !isset($schedule['plan_price'])) {
                                continue;
                            }
                            
                            // Calculate daily rate
                            $days = 0;
                            switch($schedule['duration']) {
                                case 'Monthly': $days = 30; break;
                                case 'Quarterly': $days = 90; break;
                                case 'Half-yearly': $days = 180; break;
                                case 'Yearly': $days = 365; break;
                                case 'Weekly': $days = 7; break;
                            }
                            
                            $daily_rate = ($days > 0) ? ($schedule['plan_price'] / $days) : 0;
                            $daily_gym_earning = $daily_rate * ($schedule['gym_cut_percentage'] / 100);
                        ?>
                            <tr class="hover:bg-gray-500 transition-colors duration-200">
                                <td class="px-6 py-4"><?= date('M d, Y', strtotime($schedule['start_date'])) ?></td>
                                <td class="px-6 py-4"><?= date('h:i A', strtotime($schedule['start_time'])) ?></td>
                                <td class="px-6 py-4">
                                    <span class="px-3 py-1 bg-gray-100 text-gray-800 rounded-full text-sm">
                                        <?= htmlspecialchars($schedule['tier']) ?> (<?= $schedule['duration'] ?>)
                                    </span>
                                </td>
                                <td class="px-6 py-4">₹<?= number_format($daily_rate, 2) ?></td>
                                <td class="px-6 py-4 text-green-600 font-medium">₹<?= number_format($daily_gym_earning, 2) ?></td>
                            </tr>
                        <?php endforeach;
                    } ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination Controls - Bottom -->
        <div class="px-6 py-4 bg-gray-50 flex flex-wrap items-center justify-between">
            <div class="text-sm text-gray-600">
                Showing <?= min(($page - 1) * $limit + 1, $totalRecords) ?> to <?= min($page * $limit, $totalRecords) ?> of <?= $totalRecords ?> entries
            </div>
            
            <div class="flex space-x-1 mt-2 sm:mt-0">
                <?= generatePaginationLinks($page, $totalPages, $limit) ?>
            </div>
        </div>
    </div>

    <!-- Daily Schedule Earnings Breakdown with Pagination -->
    <div class="bg-white rounded-xl shadow-lg p-6 mb-8 mt-8">
        <h3 class="text-lg font-semibold text-gray-700 mb-6 flex items-center">
            <i class="fas fa-calendar-check text-yellow-500 mr-2"></i>
            Daily Schedule Earnings Breakdown
        </h3>
        
        <?php
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
        ?>
        
        <!-- Pagination Controls - Top -->
        <div class="py-3 flex flex-wrap items-center justify-between">
            <div class="flex items-center space-x-2">
                <span class="text-sm text-gray-600">Show</span>
                <select id="dailyPaginationLimit" onchange="changeDailyLimit(this.value)" class="bg-white border border-gray-300 rounded px-2 py-1 text-sm">
                    <option value="10" <?= $dailyEarningsLimit == 10 ? 'selected' : '' ?>>10</option>
                    <option value="25" <?= $dailyEarningsLimit == 25 ? 'selected' : '' ?>>25</option>
                    <option value="50" <?= $dailyEarningsLimit == 50 ? 'selected' : '' ?>>50</option>
                    <option value="100" <?= $dailyEarningsLimit == 100 ? 'selected' : '' ?>>100</option>
                </select>
                <span class="text-sm text-gray-600">entries</span>
            </div>
            
            <div class="text-sm text-gray-600">
                Showing <?= min(($dailyEarningsPage - 1) * $dailyEarningsLimit + 1, $totalDailyRecords) ?> to <?= min($dailyEarningsPage * $dailyEarningsLimit, $totalDailyRecords) ?> of <?= $totalDailyRecords ?> days
            </div>
        </div>
        
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead>
                    <tr class="bg-gray-50">
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Earnings</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php 
                    if (empty($paginatedDailyEarnings)) {
                        echo '<tr><td colspan="2" class="px-6 py-4 text-center text-gray-500">No completed schedules found</td></tr>';
                    } else {
                        foreach($paginatedDailyEarnings as $date => $earnings): 
                        ?>
                            <tr class="hover:bg-gray-500 transition-colors duration-200">
                                <td class="px-6 py-4 whitespace-nowrap"><?= date('F d, Y', strtotime($date)) ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-green-600 font-medium">
                                    ₹<?= number_format($earnings, 2) ?>
                                </td>
                            </tr>
                        <?php endforeach;
                    } ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination Controls - Bottom -->
        <div class="py-4 flex flex-wrap items-center justify-between">
            <div class="text-sm text-gray-600">
                Showing <?= min(($dailyEarningsPage - 1) * $dailyEarningsLimit + 1, $totalDailyRecords) ?> to <?= min($dailyEarningsPage * $dailyEarningsLimit, $totalDailyRecords) ?> of <?= $totalDailyRecords ?> days
            </div>
            
            <div class="flex space-x-1 mt-2 sm:mt-0">
                <?php
                // Function to generate pagination links for daily earnings
                function generateDailyPaginationLinks($currentPage, $totalPages, $limit) {
                    $links = '';
                    $queryParams = $_GET;
                    
                    // Previous page link
                    if ($currentPage > 1) {
                        $queryParams['daily_page'] = $currentPage - 1;
                        $links .= '<a href="?' . http_build_query($queryParams) . '" class="px-3 py-2 bg-gray-200 text-gray-700 rounded-l hover:bg-gray-300">&laquo;</a>';
                    } else {
                        $links .= '<span class="px-3 py-2 bg-gray-100 text-gray-400 rounded-l">&laquo;</span>';
                    }
                    
                    // Page number links
                    $startPage = max(1, $currentPage - 2);
                    $endPage = min($totalPages, $currentPage + 2);
                    
                    if ($startPage > 1) {
                        $queryParams['daily_page'] = 1;
                        $links .= '<a href="?' . http_build_query($queryParams) . '" class="px-3 py-2 bg-gray-200 text-gray-700 hover:bg-gray-300">1</a>';
                        if ($startPage > 2) {
                            $links .= '<span class="px-3 py-2">...</span>';
                        }
                    }
                    
                    for ($i = $startPage; $i <= $endPage; $i++) {
                        $queryParams['daily_page'] = $i;
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
                        $queryParams['daily_page'] = $totalPages;
                        $links .= '<a href="?' . http_build_query($queryParams) . '" class="px-3 py-2 bg-gray-200 text-gray-700 hover:bg-gray-300">' . $totalPages . '</a>';
                    }
                    
                    // Next page link
                    if ($currentPage < $totalPages) {
                        $queryParams['daily_page'] = $currentPage + 1;
                        $links .= '<a href="?' . http_build_query($queryParams) . '" class="px-3 py-2 bg-gray-200 text-gray-700 rounded-r hover:bg-gray-300">&raquo;</a>';
                    } else {
                        $links .= '<span class="px-3 py-2 bg-gray-100 text-gray-400 rounded-r">&raquo;</span>';
                    }
                    
                    return $links;
                }
                
                echo generateDailyPaginationLinks($dailyEarningsPage, $totalDailyPages, $dailyEarningsLimit);
                ?>
            </div>
        </div>
    </div>


<script>
    // JavaScript functions for pagination
    function changeLimit(limit) {
        const urlParams = new URLSearchParams(window.location.search);
        urlParams.set('limit', limit);
        urlParams.set('page', 1); // Reset to first page when changing limit
        window.location.href = window.location.pathname + '?' + urlParams.toString();
    }
    
    function changeDailyLimit(limit) {
        const urlParams = new URLSearchParams(window.location.search);
        urlParams.set('daily_limit', limit);
        urlParams.set('daily_page', 1); // Reset to first page when changing limit
        window.location.href = window.location.pathname + '?' + urlParams.toString();
    }
</script>

    <!-- Membership Analytics (For Information Only) -->
    <div class="bg-white rounded-xl shadow-lg p-6 mb-8">
        <h3 class="text-lg font-semibold text-gray-700 mb-6 flex items-center">
            <i class="fas fa-chart-pie text-yellow-500 mr-2"></i>
            Membership Analytics (For Information Only)
        </h3>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="bg-gray-50 rounded-lg p-6">
                <div class="flex items-center justify-between mb-4">
                    <h4 class="font-medium text-gray-700">Total Membership Revenue</h4>
                    <div class="h-10 w-10 rounded-full bg-blue-100 flex items-center justify-center">
                        <i class="fas fa-money-bill-alt text-blue-600"></i>
                    </div>
                </div>
                <p class="text-2xl font-bold text-blue-600">₹<?= number_format($total_earnings, 2) ?></p>
                <p class="mt-2 text-gray-500 text-sm">Platform managed - not available for withdrawal</p>
            </div>
            
            <div class="bg-gray-50 rounded-lg p-6">
                <div class="flex items-center justify-between mb-4">
                    <h4 class="font-medium text-gray-700">Average Membership Value</h4>
                    <div class="h-10 w-10 rounded-full bg-blue-100 flex items-center justify-center">
                        <i class="fas fa-chart-line text-blue-600"></i>
                    </div>
                </div>
                <p class="text-2xl font-bold text-blue-600">
                    ₹<?= $total_memberships > 0 ? number_format($total_earnings / $total_memberships, 2) : '0.00' ?>
                </p>
                <p class="mt-2 text-gray-500 text-sm">Per membership</p>
            </div>
        </div>
        
        <!-- Distribution Charts -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-6">
            <div>
                <h4 class="font-medium text-gray-700 mb-4 flex items-center">
                    <i class="fas fa-layer-group text-yellow-500 mr-2"></i>
                    Distribution by Tier
                </h4>
                <div class="space-y-4">
                    <?php foreach($membership_by_tier as $tier => $count): ?>
                        <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                            <span class="font-medium text-gray-700"><?= $tier ?></span>
                            <span class="px-4 py-1 bg-blue-100 text-blue-800 rounded-full text-sm font-semibold">
                                <?= $count ?> members
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div>
                <h4 class="font-medium text-gray-700 mb-4 flex items-center">
                    <i class="fas fa-clock text-yellow-500 mr-2"></i>
                    Distribution by Duration
                </h4>
                <div class="space-y-4">
                    <?php foreach($membership_by_duration as $duration => $count): ?>
                        <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                            <span class="font-medium text-gray-700"><?= $duration ?></span>
                            <span class="px-4 py-1 bg-green-100 text-green-800 rounded-full text-sm font-semibold">
                                <?= $count ?> members
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Monthly Trends -->
    <div class="bg-white rounded-xl shadow-lg p-6 mb-8">
        <h3 class="text-lg font-semibold text-gray-700 mb-6 flex items-center">
            <i class="fas fa-chart-bar text-yellow-500 mr-2"></i>
            Monthly Sales Trends
        </h3>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead>
                    <tr class="bg-gray-50">
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Month</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Memberships</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Revenue</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php 
                    if (empty($monthly_sales)) {
                        echo '<tr><td colspan="3" class="px-6 py-4 text-center text-gray-500">No sales data available</td></tr>';
                    } else {
                        foreach($monthly_sales as $month => $data): 
                        ?>
                            <tr class="hover:bg-gray-500 transition-colors duration-200">
                                <td class="px-6 py-4 whitespace-nowrap"><?= date('F Y', strtotime($month)) ?></td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-3 py-1 bg-blue-100 text-blue-800 rounded-full text-sm">
                                        <?= $data['count'] ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-blue-600 font-medium">
                                    ₹<?= number_format($data['earnings'], 2) ?>
                                </td>
                            </tr>
                        <?php endforeach;
                    } ?>
                </tbody>
            </table>
        </div>
    </div>



    <!-- Recent Membership Sales (For Information Only) -->
    <div class="bg-white rounded-xl shadow-lg overflow-hidden mt-8">
        <div class="p-6 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-700 flex items-center">
                <i class="fas fa-receipt text-yellow-500 mr-2"></i>
                Recent Membership Sales (For Information Only)
            </h3>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Member</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Plan</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Amount</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Your Cut</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php 
                    if (empty($memberships)) {
                        echo '<tr><td colspan="5" class="px-6 py-4 text-center text-gray-500">No membership sales found</td></tr>';
                    } else {
                        foreach(array_slice($memberships, 0, 10) as $membership): 
                        ?>
                            <tr class="hover:bg-gray-500 transition-colors duration-200">
                                <td class="px-6 py-4"><?= htmlspecialchars($membership['username']) ?></td>
                                <td class="px-6 py-4">
                                    <span class="px-3 py-1 bg-gray-100 text-gray-800 rounded-full text-sm">
                                        <?= htmlspecialchars($membership['plan_name']) ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4">₹<?= number_format($membership['plan_price'], 2) ?></td>
                                <td class="px-6 py-4 text-blue-600 font-medium">₹<?= number_format($membership['gym_earnings'], 2) ?></td>
                                <td class="px-6 py-4 text-gray-500"><?= date('M d, Y', strtotime($membership['created_at'])) ?></td>
                            </tr>
                        <?php endforeach;
                    } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

