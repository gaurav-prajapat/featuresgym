<?php
require '../config/database.php';
include '../includes/navbar.php';

if (!isset($_SESSION['owner_id'])) {
    header('Location: login.html');
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

// Pagination for today's visits
$visitsPage = isset($_GET['visits_page']) ? (int)$_GET['visits_page'] : 1;
$visitsLimit = isset($_GET['visits_limit']) ? (int)$_GET['visits_limit'] : 10;
$visitsOffset = ($visitsPage - 1) * $visitsLimit;

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
$totalVisitsPages = ceil($totalVisits / $visitsLimit);

// Get today's visits with pagination
$stmt = $conn->prepare("
    SELECT s.*, u.username, u.email, u.phone
    FROM schedules s 
    JOIN users u ON s.user_id = u.id
    WHERE s.gym_id = ? 
    AND DATE(s.start_date) = CURRENT_DATE
    ORDER BY s.start_time ASC
    LIMIT " . intval($visitsLimit) . " OFFSET " . intval($visitsOffset)
);
$stmt->execute([$gym_id]);
$today_visits = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Pagination for earnings history
$earningsPage = isset($_GET['earnings_page']) ? (int)$_GET['earnings_page'] : 1;
$earningsLimit = isset($_GET['earnings_limit']) ? (int)$_GET['earnings_limit'] : 10;
$earningsOffset = ($earningsPage - 1) * $earningsLimit;

// Count total earnings records
$countEarningsStmt = $conn->prepare("
    SELECT COUNT(DISTINCT DATE(s.start_date)) as total
    FROM schedules s
    WHERE s.gym_id = ?
");
$countEarningsStmt->execute([$gym_id]);
$totalEarnings = $countEarningsStmt->fetchColumn();
$totalEarningsPages = ceil($totalEarnings / $earningsLimit);

// Get earnings history with pagination
$stmt = $conn->prepare("
    SELECT DATE(s.start_date) as date,
           COUNT(*) as visit_count,
           SUM(s.daily_rate) as total_earnings
    FROM schedules s
    WHERE s.gym_id = ?
    GROUP BY DATE(s.start_date)
    ORDER BY date DESC
    LIMIT " . intval($earningsLimit) . " OFFSET " . intval($earningsOffset)
);
$stmt->execute([$gym_id]);
$earnings_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

// Function to generate pagination links
function generatePaginationLinks($currentPage, $totalPages, $paramName) {
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

<div class="container mx-auto px-4 py-20">
    <!-- Header -->
    <div class="bg-white rounded-xl shadow-lg overflow-hidden mb-8">
        <div class="p-6 bg-gradient-to-r from-gray-900 to-gray-800">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between">
                <div class="flex items-center space-x-4 mb-4 md:mb-0">
                    <div class="h-16 w-16 rounded-full bg-yellow-500 flex items-center justify-center">
                        <i class="fas fa-clipboard-check text-2xl text-white"></i>
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold text-white">Visit Attendance</h1>
                        <p class="text-white "><?php echo date('l, F j, Y'); ?></p>
                    </div>
                </div>
                <div class="flex flex-col md:flex-row md:items-center md:space-x-4">
                    <div class="text-white text-center md:text-right mb-4 md:mb-0">
                        <p class="text-sm">Withdrawable Balance</p>
                        <p class="text-xl font-bold">₹<?php echo number_format($withdrawableBalance, 2); ?></p>
                        <p class="text-xs text-gray-300">From completed visits</p>
                    </div>
                    <button onclick="initiateWithdrawal()" 
                            class="bg-green-500 hover:bg-green-600 text-white px-6 py-3 rounded-lg transition-colors duration-200 w-full md:w-auto">
                        <i class="fas fa-money-bill-wave mr-2"></i>Withdraw
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Today's Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
        <div class="bg-white rounded-xl shadow-lg p-6 transform hover:scale-105 transition-transform duration-200">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-700">Total Visits</h3>
                <div class="h-12 w-12 rounded-full bg-blue-100 flex items-center justify-center">
                    <i class="fas fa-users text-blue-600"></i>
                </div>
            </div>
            <p class="text-3xl font-bold text-blue-600"><?= $today_stats['total_visits'] ?? 0 ?></p>
            <p class="mt-2 text-gray-600">Scheduled for today</p>
        </div>

        <div class="bg-white rounded-xl shadow-lg p-6 transform hover:scale-105 transition-transform duration-200">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-700">Completed</h3>
                <div class="h-12 w-12 rounded-full bg-green-100 flex items-center justify-center">
                    <i class="fas fa-check-circle text-green-600"></i>
                </div>
            </div>
            <p class="text-3xl font-bold text-green-600"><?= $today_stats['completed_visits'] ?? 0 ?></p>
            <p class="mt-2 text-gray-600">Visits completed</p>
        </div>

        <div class="bg-white rounded-xl shadow-lg p-6 transform hover:scale-105 transition-transform duration-200">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-700">Pending</h3>
                <div class="h-12 w-12 rounded-full bg-yellow-100 flex items-center justify-center">
                    <i class="fas fa-clock text-yellow-600"></i>
                </div>
            </div>
            <p class="text-3xl font-bold text-yellow-600"><?= $today_stats['pending_visits'] ?? 0 ?></p>
            <p class="mt-2 text-gray-600">Awaiting check-in</p>
        </div>

        <div class="bg-white rounded-xl shadow-lg p-6 transform hover:scale-105 transition-transform duration-200">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-700">Today's Earnings</h3>
                <div class="h-12 w-12 rounded-full bg-purple-100 flex items-center justify-center">
                    <i class="fas fa-rupee-sign text-purple-600"></i>
                </div>
            </div>
            <p class="text-3xl font-bold text-purple-600">₹<?= number_format($today_stats['today_earnings'] ?? 0, 2) ?></p>
            <p class="mt-2 text-gray-600">Potential earnings</p>
        </div>
    </div>

    <!-- Today's Visits with Pagination -->
    <div class="bg-white rounded-xl shadow-lg overflow-hidden mb-8">
        <div class="p-6 border-b border-gray-200">
            <h2 class="text-xl font-semibold flex items-center">
                <i class="fas fa-calendar-day text-yellow-500 mr-2"></i>
                Today's Visits
            </h2>
        </div>
        
        <!-- Pagination Controls - Top -->
        <div class="px-6 py-3 bg-gray-50 flex flex-wrap items-center justify-between">
            <div class="flex items-center space-x-2">
                <span class="text-sm text-gray-600">Show</span>
                <select id="visitsLimit" onchange="changeVisitsLimit(this.value)" class="bg-white border border-gray-300 rounded px-2 py-1 text-sm">
                    <option value="10" <?= $visitsLimit == 10 ? 'selected' : '' ?>>10</option>
                    <option value="25" <?= $visitsLimit == 25 ? 'selected' : '' ?>>25</option>
                    <option value="50" <?= $visitsLimit == 50 ? 'selected' : '' ?>>50</option>
                </select>
                <span class="text-sm text-gray-600">entries</span>
            </div>
            
            <div class="text-sm text-gray-600">
                Showing <?= min(($visitsPage - 1) * $visitsLimit + 1, $totalVisits) ?> to <?= min($visitsPage * $visitsLimit, $totalVisits) ?> of <?= $totalVisits ?> visits
            </div>
        </div>
        
        <div class="overflow-x-auto">
            
        <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Time</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Member</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (count($today_visits) > 0): ?>
                        <?php foreach ($today_visits as $visit): ?>
                            <tr class="hover:bg-gray-500 transition-colors duration-200">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php echo date('h:i A', strtotime($visit['start_time'])); ?>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center">
                                        <div class="flex-shrink-0 h-10 w-10 rounded-full bg-gray-100 flex items-center justify-center">
                                            <i class="fas fa-user text-gray-500"></i>
                                        </div>
                                        <div class="ml-4">
                                            <div class="text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($visit['username']); ?>
                                            </div>
                                            <div class="text-sm text-gray-500">
                                                <?php echo htmlspecialchars($visit['email']); ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full 
                                        <?php echo $visit['status'] === 'completed' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                                        <?php echo ucfirst($visit['status']); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-900">
                                    ₹<?php echo number_format($visit['daily_rate'], 2); ?>
                                </td>
                                <td class="px-6 py-4">
                                    <?php if ($visit['status'] === 'scheduled'): ?>
                                        <button onclick="markAttendance(<?php echo $visit['id']; ?>)" 
                                                class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                                            <i class="fas fa-check mr-2"></i>Mark Present
                                        </button>
                                    <?php else: ?>
                                        <span class="text-green-600"><i class="fas fa-check-circle mr-1"></i> Completed</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center">
                                <div class="flex flex-col items-center">
                                    <i class="fas fa-calendar-times text-4xl text-gray-300 mb-4"></i>
                                    <p class="text-gray-500">No visits scheduled for today</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination Controls - Bottom -->
        <?php if ($totalVisits > 0): ?>
        <div class="px-6 py-4 bg-gray-50 flex flex-wrap items-center justify-between">
            <div class="text-sm text-gray-600">
                Showing <?= min(($visitsPage - 1) * $visitsLimit + 1, $totalVisits) ?> to <?= min($visitsPage * $visitsLimit, $totalVisits) ?> of <?= $totalVisits ?> visits
            </div>
            
            <div class="flex space-x-1 mt-2 sm:mt-0">
                <?= generatePaginationLinks($visitsPage, $totalVisitsPages, 'visits_page') ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Earnings History with Pagination -->
    <div class="bg-white rounded-xl shadow-lg overflow-hidden">
        <div class="p-6 border-b border-gray-200">
            <h2 class="text-xl font-semibold flex items-center">
                <i class="fas fa-chart-line text-yellow-500 mr-2"></i>
                Earnings History
            </h2>
        </div>
        
        <!-- Pagination Controls - Top -->
        <div class="px-6 py-3 bg-gray-50 flex flex-wrap items-center justify-between">
            <div class="flex items-center space-x-2">
                <span class="text-sm text-gray-600">Show</span>
                <select id="earningsLimit" onchange="changeEarningsLimit(this.value)" class="bg-white border border-gray-300 rounded px-2 py-1 text-sm">
                    <option value="10" <?= $earningsLimit == 10 ? 'selected' : '' ?>>10</option>
                    <option value="25" <?= $earningsLimit == 25 ? 'selected' : '' ?>>25</option>
                    <option value="50" <?= $earningsLimit == 50 ? 'selected' : '' ?>>50</option>
                </select>
                <span class="text-sm text-gray-600">entries</span>
            </div>
            
            <div class="text-sm text-gray-600">
                Showing <?= min(($earningsPage - 1) * $earningsLimit + 1, $totalEarnings) ?> to <?= min($earningsPage * $earningsLimit, $totalEarnings) ?> of <?= $totalEarnings ?> days
            </div>
        </div>
        
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Visits</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Earnings</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (count($earnings_history) > 0): ?>
                        <?php foreach ($earnings_history as $earning): ?>
                            <tr class="hover:bg-gray-500 transition-colors duration-200">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php echo date('M d, Y', strtotime($earning['date'])); ?>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="px-3 py-1 bg-blue-100 text-blue-800 rounded-full text-sm">
                                        <?php echo $earning['visit_count']; ?> visits
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-green-600 font-medium">
                                    ₹<?php echo number_format($earning['total_earnings'], 2); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="3" class="px-6 py-12 text-center">
                                <div class="flex flex-col items-center">
                                    <i class="fas fa-chart-line text-4xl text-gray-300 mb-4"></i>
                                    <p class="text-gray-500">No earnings history available</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination Controls - Bottom -->
        <?php if ($totalEarnings > 0): ?>
        <div class="px-6 py-4 bg-gray-50 flex flex-wrap items-center justify-between">
            <div class="text-sm text-gray-600">
                Showing <?= min(($earningsPage - 1) * $earningsLimit + 1, $totalEarnings) ?> to <?= min($earningsPage * $earningsLimit, $totalEarnings) ?> of <?= $totalEarnings ?> days
            </div>
            
            <div class="flex space-x-1 mt-2 sm:mt-0">
                <?= generatePaginationLinks($earningsPage, $totalEarningsPages, 'earnings_page') ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
     <!-- Recent Withdrawals -->
     <div class="bg-white rounded-xl shadow-lg p-6 mt-8">
        <h2 class="text-xl font-semibold mb-6 flex items-center">
            <i class="fas fa-money-bill-wave text-yellow-500 mr-2"></i>
            Recent Withdrawals
        </h2>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (count($recent_withdrawals) > 0): ?>
                        <?php foreach ($recent_withdrawals as $withdrawal): ?>
                            <tr class="hover:bg-gray-500 transition-colors duration-200">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php echo date('M d, Y', strtotime($withdrawal['created_at'])); ?>
                                </td>
                                <td class="px-6 py-4 text-green-600 font-medium">
                                    ₹<?php echo number_format($withdrawal['amount'], 2); ?>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full 
                                        <?php 
                                        if ($withdrawal['status'] === 'completed') echo 'bg-green-100 text-green-800';
                                        elseif ($withdrawal['status'] === 'pending') echo 'bg-yellow-100 text-yellow-800';
                                        else echo 'bg-red-100 text-red-800';
                                        ?>">
                                        <?php echo ucfirst($withdrawal['status']); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="3" class="px-6 py-12 text-center">
                                <div class="flex flex-col items-center">
                                    <i class="fas fa-money-bill-wave text-4xl text-gray-300 mb-4"></i>
                                    <p class="text-gray-500">No recent withdrawals</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function markAttendance(visitId) {
    fetch('mark_attendance.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ visit_id: visitId })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Failed to mark attendance'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred. Please try again.');
    });
}

function initiateWithdrawal() {
    window.location.href = 'withdraw.php';
}

// JavaScript functions for pagination
function changeVisitsLimit(limit) {
    const urlParams = new URLSearchParams(window.location.search);
    urlParams.set('visits_limit', limit);
    urlParams.set('visits_page', 1); // Reset to first page when changing limit
    window.location.href = window.location.pathname + '?' + urlParams.toString();
}

function changeEarningsLimit(limit) {
    const urlParams = new URLSearchParams(window.location.search);
    urlParams.set('earnings_limit', limit);
    urlParams.set('earnings_page', 1); // Reset to first page when changing limit
    window.location.href = window.location.pathname + '?' + urlParams.toString();
}
</script>
