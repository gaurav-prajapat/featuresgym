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
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;
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

    // Get today's scheduled visits
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

    foreach ($allSchedules as $schedule) {
        // Check if the required keys exist
        if (!isset($schedule['start_date']) || !isset($schedule['duration']) || !isset($schedule['plan_price'])) {
            continue; // Skip this record if missing required data
        }

        // Calculate the daily rate based on plan price and duration
        $daily_rate = 0;

        // Convert duration to days for calculation
        $days = 0;
        switch ($schedule['duration']) {
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

        if ($days > 0) {
            $daily_rate = $schedule['plan_price'] / $days;

            // Apply gym cut percentage
            $daily_gym_earning = $daily_rate * ($schedule['gym_cut_percentage'] / 100);

            // Add to total schedule earnings
            $start_earnings += $daily_gym_earning;

            // Group by date
            $date = date('Y-m-d', strtotime($schedule['start_date']));
            if (!isset($daily_start_earnings[$date])) {
                $daily_start_earnings[$date] = 0;
            }
            $daily_start_earnings[$date] += $daily_gym_earning;
        }
    }

    // Total withdrawable balance is ONLY from schedule earnings
    $total_withdrawable_balance = $start_earnings;

    // Pagination for daily earnings
    $dailyEarningsPage = isset($_GET['daily_page']) ? (int) $_GET['daily_page'] : 1;
    $dailyEarningsLimit = isset($_GET['daily_limit']) ? (int) $_GET['daily_limit'] : 10;
    $dailyEarningsOffset = ($dailyEarningsPage - 1) * $dailyEarningsLimit;

    // Sort by date in descending order
    krsort($daily_start_earnings);
    $totalDailyRecords = count($daily_start_earnings);
    $totalDailyPages = ceil($totalDailyRecords / $dailyEarningsLimit);

    // Get paginated slice
    $paginatedDailyEarnings = array_slice($daily_start_earnings, $dailyEarningsOffset, $dailyEarningsLimit, true);
}

// Function to generate pagination links
function generatePaginationLinks($currentPage, $totalPages, $paramName = 'page')
{
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
    $start = max(1, $currentPage - 2);
    $end = min($totalPages, $currentPage + 2);

    for ($i = $start; $i <= $end; $i++) {
        $queryParams[$paramName] = $i;
        if ($i == $currentPage) {
            $links .= '<span class="px-3 py-2 bg-blue-500 text-white rounded">' . $i . '</span>';
        } else {
            $links .= '<a href="?' . http_build_query($queryParams) . '" class="px-3 py-2 bg-gray-200 text-gray-700 rounded hover:bg-gray-300">' . $i . '</a>';
        }
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
    <title>Gym Bookings Management</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Custom styles for better mobile responsiveness */
        @media (max-width: 640px) {
            .responsive-table {
                display: block;
                width: 100%;
                overflow-x: auto;
            }

            .tab-content {
                padding: 1rem;
            }

            .card-stats {
                grid-template-columns: 1fr;
            }

            .pagination {
                flex-wrap: wrap;
                justify-content: center;
            }

            .pagination a,
            .pagination span {
                margin-bottom: 0.5rem;
            }
        }

        /* Tab styling */
        .tab-button {
            position: relative;
            transition: all 0.3s ease;
        }

        .tab-button.active {
            color: #3B82F6;
            font-weight: 600;
        }

        .tab-button.active:after {
            content: '';
            position: absolute;
            bottom: -1px;
            left: 0;
            width: 100%;
            height: 2px;
            background-color: #3B82F6;
        }

        /* Card hover effects */
        .stat-card {
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }

        /* Button styling */
        .action-button {
            transition: all 0.2s ease;
        }

        .action-button:hover {
            transform: scale(1.05);
        }

        /* Loading animation */
        .loading-spinner {
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top: 3px solid #fff;
            width: 24px;
            height: 24px;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        /* Status badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        /* Responsive table improvements */
        @media (max-width: 768px) {

            .responsive-table th,
            .responsive-table td {
                padding: 0.5rem;
            }

            .responsive-table th:nth-child(n+4),
            .responsive-table td:nth-child(n+4) {
                display: none;
            }

            .action-buttons {
                flex-direction: column;
                align-items: flex-start;
            }

            .action-buttons button {
                margin-top: 0.25rem;
                margin-left: 0 !important;
            }
        }
    </style>
</head>

<body class="bg-gray-100 min-h-screen">
    <div class="container mx-auto px-4 py-8 pt-24">
        <!-- Gym Info Card -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-800"><?= htmlspecialchars($gym['name']) ?> Management</h1>
                    <p class="text-gray-600 mt-1">Manage bookings, attendance, and earnings</p>
                </div>
                <div class="mt-4 md:mt-0">
                    <div class="flex items-center">
                        <div class="bg-blue-100 text-blue-800 px-3 py-1 rounded-full text-sm font-semibold">
                            Balance: ₹<?= number_format($gym['balance'], 2) ?>
                        </div>
                        <a href="finances.php" class="ml-3 text-blue-600 hover:text-blue-800 text-sm">
                            <i class="fas fa-wallet mr-1"></i> Finances
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabs Navigation -->
        <div class="bg-white rounded-lg shadow-md mb-6">
            <div class="border-b border-gray-200">
                <nav class="flex -mb-px">
                    <a href="?tab=bookings"
                        class="tab-button py-4 px-6 <?= $active_tab == 'bookings' ? 'active' : 'text-gray-500 hover:text-gray-700' ?>">
                        <i class="fas fa-calendar-check mr-2"></i> Bookings
                    </a>
                    <a href="?tab=attendance"
                        class="tab-button py-4 px-6 <?= $active_tab == 'attendance' ? 'active' : 'text-gray-500 hover:text-gray-700' ?>">
                        <i class="fas fa-clipboard-list mr-2"></i> Attendance
                    </a>
                    <a href="?tab=earnings"
                        class="tab-button py-4 px-6 <?= $active_tab == 'earnings' ? 'active' : 'text-gray-500 hover:text-gray-700' ?>">
                        <i class="fas fa-chart-line mr-2"></i> Earnings
                    </a>
                </nav>
            </div>
        </div>

        <!-- Tab Content -->
        <div class="tab-content">
            <?php if ($active_tab == 'bookings'): ?>
                <!-- Bookings Tab Content -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                    <div class="bg-white rounded-lg shadow-md p-6 stat-card">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-blue-100 text-blue-500">
                                <i class="fas fa-calendar-day text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h2 class="text-gray-600 text-sm">Today's Bookings</h2>
                                <p class="text-2xl font-semibold text-gray-800"><?= $totalTodayBookings ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-lg shadow-md p-6 stat-card">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-green-100 text-green-500">
                                <i class="fas fa-calendar-alt text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h2 class="text-gray-600 text-sm">Tomorrow's Bookings</h2>
                                <p class="text-2xl font-semibold text-gray-800"><?= $totalTomorrowBookings ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Today's Bookings -->
                <div class="bg-white rounded-lg shadow-md mb-6">
                    <div class="border-b border-gray-200 px-6 py-4">
                        <h2 class="text-lg font-semibold text-gray-800">Today's Bookings</h2>
                    </div>
                    <div class="p-6">
                        <?php if (empty($todayBookings)): ?>
                            <div class="text-center py-4">
                                <p class="text-gray-500">No bookings scheduled for today</p>
                            </div>
                        <?php else: ?>
                            <div class="overflow-x-auto responsive-table">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th scope="col"
                                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Member</th>
                                            <th scope="col"
                                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Time</th>
                                            <th scope="col"
                                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Status</th>
                                            <th scope="col"
                                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Activity</th>
                                            <th scope="col"
                                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($todayBookings as $booking): ?>
                                            <tr>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="flex items-center">
                                                        <div
                                                            class="flex-shrink-0 h-10 w-10 rounded-full bg-gray-200 flex items-center justify-center">
                                                            <i class="fas fa-user text-gray-500"></i>
                                                        </div>
                                                        <div class="ml-4">
                                                            <div class="text-sm font-medium text-gray-900">
                                                                <?= htmlspecialchars($booking['username']) ?>
                                                            </div>
                                                            <div class="text-sm text-gray-500">
                                                                <?= htmlspecialchars($booking['email']) ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="text-sm text-gray-900">
                                                        <?= date('h:i A', strtotime($booking['start_time'])) ?>
                                                    </div>
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
                                                    <span class="status-badge <?= $statusColor ?>">
                                                        <?= ucfirst($booking['status']) ?>
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?= ucfirst(str_replace('_', ' ', $booking['activity_type'])) ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                    <div class="flex items-center action-buttons">
                                                        <?php if ($booking['status'] === 'scheduled' && !$booking['check_in_time']): ?>
                                                            <button onclick="checkInMember(<?= $booking['id'] ?>)"
                                                                class="action-button bg-green-500 hover:bg-green-600 text-white px-3 py-1 rounded-md">
                                                                <i class="fas fa-sign-in-alt mr-1"></i> Check In
                                                            </button>
                                                        <?php endif; ?>

                                                        <?php if ($booking['status'] === 'scheduled' && $booking['check_in_time'] && !$booking['check_out_time']): ?>
                                                            <button onclick="checkOutMember(<?= $booking['id'] ?>)"
                                                                class="action-button bg-purple-500 hover:bg-purple-600 text-white px-3 py-1 rounded-md ml-2">
                                                                <i class="fas fa-sign-out-alt mr-1"></i> Check Out
                                                            </button>
                                                        <?php endif; ?>
                                                        <?php if ($booking['status'] === 'scheduled'): ?>
                                                            <button onclick="acceptBooking(<?= $booking['id'] ?>)"
                                                                class="action-button bg-green-600 hover:bg-green-700 text-white px-3 py-1 rounded-md mr-2">
                                                                <i class="fas fa-check mr-1"></i> Accept
                                                            </button>
                                                        <?php endif; ?>

                                                        <?php if ($booking['status'] === 'scheduled'): ?>
                                                            <button onclick="cancelBooking(<?= $booking['id'] ?>)"
                                                                class="action-button bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded-md ml-2">
                                                                <i class="fas fa-times mr-1"></i> Cancel
                                                            </button>
                                                        <?php endif; ?>

                                                        <a href="view_schedule.php?id=<?= $booking['id'] ?>"
                                                            class="action-button bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded-md ml-2">
                                                            <i class="fas fa-eye mr-1"></i> View
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <!-- Pagination -->
                            <div class="mt-4 flex justify-center pagination">
                                <?= generatePaginationLinks($page, $totalPages) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Tomorrow's Bookings -->
                <div class="bg-white rounded-lg shadow-md">
                    <div class="border-b border-gray-200 px-6 py-4">
                        <h2 class="text-lg font-semibold text-gray-800">Tomorrow's Bookings</h2>
                    </div>
                    <div class="p-6">
                        <?php if (empty($tomorrowBookings)): ?>
                            <div class="text-center py-4">
                                <p class="text-gray-500">No bookings scheduled for tomorrow</p>
                            </div>
                        <?php else: ?>
                            <div class="overflow-x-auto responsive-table">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th scope="col"
                                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Member</th>
                                            <th scope="col"
                                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Time</th>
                                            <th scope="col"
                                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Status</th>
                                            <th scope="col"
                                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Activity</th>
                                            <th scope="col"
                                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($tomorrowBookings as $booking): ?>
                                            <tr>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="flex items-center">
                                                        <div
                                                            class="flex-shrink-0 h-10 w-10 rounded-full bg-gray-200 flex items-center justify-center">
                                                            <i class="fas fa-user text-gray-500"></i>
                                                        </div>
                                                        <div class="ml-4">
                                                            <div class="text-sm font-medium text-gray-900">
                                                                <?= htmlspecialchars($booking['username']) ?>
                                                            </div>
                                                            <div class="text-sm text-gray-500">
                                                                <?= htmlspecialchars($booking['email']) ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="text-sm text-gray-900">
                                                        <?= date('h:i A', strtotime($booking['start_time'])) ?>
                                                    </div>
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
                                                    <span class="status-badge <?= $statusColor ?>">
                                                        <?= ucfirst($booking['status']) ?>
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?= ucfirst(str_replace('_', ' ', $booking['activity_type'])) ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                    <div class="flex items-center action-buttons">
                                                        <?php if ($booking['status'] === 'scheduled'): ?>
                                                            <button onclick="cancelBooking(<?= $booking['id'] ?>)"
                                                                class="action-button bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded-md">
                                                                <i class="fas fa-times mr-1"></i> Cancel
                                                            </button>

                                                        <?php endif; ?>
                                                        <?php if ($booking['status'] === 'scheduled'): ?>
                                                            <button onclick="acceptBooking(<?= $booking['id'] ?>)"
                                                                class="action-button bg-green-600 hover:bg-green-700 text-white px-3 py-1 rounded-md mr-2">
                                                                <i class="fas fa-check mr-1"></i> Accept
                                                            </button>
                                                        <?php endif; ?>

                                                        <a href="view_schedule.php?id=<?= $booking['id'] ?>"
                                                            class="action-button bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded-md ml-2">
                                                            <i class="fas fa-eye mr-1"></i> View
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <!-- Pagination -->
                            <div class="mt-4 flex justify-center pagination">
                                <?= generatePaginationLinks($page, $totalPages) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php elseif ($active_tab == 'attendance'): ?>
                <!-- Attendance Tab Content -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6 card-stats">
                    <div class="bg-white rounded-lg shadow-md p-6 stat-card">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-blue-100 text-blue-500">
                                <i class="fas fa-users text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h2 class="text-gray-600 text-sm">Today's Visits</h2>
                                <p class="text-2xl font-semibold text-gray-800"><?= $today_stats['total_visits'] ?? 0 ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-lg shadow-md p-6 stat-card">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-green-100 text-green-500">
                                <i class="fas fa-check-circle text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h2 class="text-gray-600 text-sm">Completed Visits</h2>
                                <p class="text-2xl font-semibold text-gray-800"><?= $today_stats['completed_visits'] ?? 0 ?>
                                </p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-lg shadow-md p-6 stat-card">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-yellow-100 text-yellow-500">
                                <i class="fas fa-clock text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h2 class="text-gray-600 text-sm">Pending Visits</h2>
                                <p class="text-2xl font-semibold text-gray-800"><?= $today_stats['pending_visits'] ?? 0 ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Today's Attendance -->
                <div class="bg-white rounded-lg shadow-md mb-6">
                    <div class="border-b border-gray-200 px-6 py-4">
                        <h2 class="text-lg font-semibold text-gray-800">Today's Attendance</h2>
                    </div>
                    <div class="p-6">
                        <?php if (empty($today_visits)): ?>
                            <div class="text-center py-4">
                                <p class="text-gray-500">No visits recorded for today</p>
                            </div>
                        <?php else: ?>
                            <div class="overflow-x-auto responsive-table">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th scope="col"
                                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Member</th>
                                            <th scope="col"
                                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Time</th>
                                            <th scope="col"
                                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Status</th>
                                            <th scope="col"
                                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Check-In</th>
                                            <th scope="col"
                                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Check-Out</th>
                                            <th scope="col"
                                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($today_visits as $visit): ?>
                                            <tr>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="flex items-center">
                                                        <div
                                                            class="flex-shrink-0 h-10 w-10 rounded-full bg-gray-200 flex items-center justify-center">
                                                            <i class="fas fa-user text-gray-500"></i>
                                                        </div>
                                                        <div class="ml-4">
                                                            <div class="text-sm font-medium text-gray-900">
                                                                <?= htmlspecialchars($visit['username']) ?>
                                                            </div>
                                                            <div class="text-sm text-gray-500">
                                                                <?= htmlspecialchars($visit['email']) ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="text-sm text-gray-900">
                                                        <?= date('h:i A', strtotime($visit['start_time'])) ?>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <?php
                                                    $statusColors = [
                                                        'scheduled' => 'bg-yellow-100 text-yellow-800',
                                                        'accepted' => 'bg-blue-100 text-blue-800',
                                                        'completed' => 'bg-green-100 text-green-800',
                                                        'cancelled' => 'bg-red-100 text-red-800',
                                                        'missed' => 'bg-gray-100 text-gray-800'
                                                    ];
                                                    $statusIcons = [
                                                        'scheduled' => '<i class="fas fa-clock mr-1"></i>',
                                                        'accepted' => '<i class="fas fa-check-circle mr-1"></i>',
                                                        'completed' => '<i class="fas fa-check-double mr-1"></i>',
                                                        'cancelled' => '<i class="fas fa-times-circle mr-1"></i>',
                                                        'missed' => '<i class="fas fa-exclamation-circle mr-1"></i>'
                                                    ];
                                                    $statusColor = $statusColors[$visit['status']] ?? 'bg-gray-100 text-gray-800';
                                                    $statusIcon = $statusIcons[$visit['status']] ?? '';
                                                    ?>
                                                    <span class="status-badge <?= $statusColor ?>">
                                                        <?= $statusIcon ?>             <?= ucfirst($visit['status']) ?>
                                                    </span>
                                                </td>

                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?= $visit['check_in_time'] ? date('h:i A', strtotime($visit['check_in_time'])) : 'Not checked in' ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?= $visit['check_out_time'] ? date('h:i A', strtotime($visit['check_out_time'])) : 'Not checked out' ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                    <div class="flex items-center action-buttons">
                                                        <?php if ($visit['status'] === 'accepted' && !$visit['check_in_time']): ?>
                                                            <button onclick="checkInMember(<?= $visit['id'] ?>)"
                                                                class="action-button bg-green-500 hover:bg-green-600 text-white px-3 py-1 rounded-md">
                                                                <i class="fas fa-sign-in-alt mr-1"></i> Check In
                                                            </button>
                                                        <?php endif; ?>

                                                        <?php if ($visit['status'] === 'accepted' && $visit['check_in_time'] && !$visit['check_out_time']): ?>
                                                            <button onclick="checkOutMember(<?= $visit['id'] ?>)"
                                                                class="action-button bg-purple-500 hover:bg-purple-600 text-white px-3 py-1 rounded-md ml-2">
                                                                <i class="fas fa-sign-out-alt mr-1"></i> Check Out
                                                            </button>
                                                        <?php endif; ?>

                                                        <a href="view_schedule.php?id=<?= $visit['id'] ?>"
                                                            class="action-button bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded-md ml-2">
                                                            <i class="fas fa-eye mr-1"></i> View
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <!-- Pagination -->
                            <div class="mt-4 flex justify-center pagination">
                                <?= generatePaginationLinks($page, $totalVisitsPages) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Attendance History -->
                <div class="bg-white rounded-lg shadow-md">
                    <div class="border-b border-gray-200 px-6 py-4">
                        <h2 class="text-lg font-semibold text-gray-800">Attendance History</h2>
                    </div>
                    <div class="p-6">
                        <?php if (empty($earnings_history)): ?>
                            <div class="text-center py-4">
                                <p class="text-gray-500">No attendance history available</p>
                            </div>
                        <?php else: ?>
                            <div class="overflow-x-auto responsive-table">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th scope="col"
                                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Date</th>
                                            <th scope="col"
                                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Total Visits</th>
                                            <th scope="col"
                                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Earnings</th>
                                            <th scope="col"
                                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($earnings_history as $history): ?>
                                            <tr>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="text-sm font-medium text-gray-900">
                                                        <?= date('M d, Y', strtotime($history['date'])) ?>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="text-sm text-gray-900"><?= $history['visit_count'] ?> visits</div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="text-sm text-gray-900">
                                                        ₹<?= number_format($history['total_earnings'], 2) ?></div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                    <a href="daily_report.php?date=<?= $history['date'] ?>"
                                                        class="action-button bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded-md">
                                                        <i class="fas fa-file-alt mr-1"></i> View Report
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <!-- Pagination -->
                            <div class="mt-4 flex justify-center pagination">
                                <?= generatePaginationLinks($page, $totalEarningsPages) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php elseif ($active_tab == 'earnings'): ?>
                <!-- Earnings Tab Content -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6 card-stats">
                    <div class="bg-white rounded-lg shadow-md p-6 stat-card">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-green-100 text-green-500">
                                <i class="fas fa-wallet text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h2 class="text-gray-600 text-sm">Total Earnings</h2>
                                <p class="text-2xl font-semibold text-gray-800">
                                    ₹<?= number_format($total_withdrawable_balance, 2) ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-lg shadow-md p-6 stat-card">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-blue-100 text-blue-500">
                                <i class="fas fa-credit-card text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h2 class="text-gray-600 text-sm">Today's Earnings</h2>
                                <p class="text-2xl font-semibold text-gray-800">
                                    ₹<?= number_format($today_stats['today_earnings'] ?? 0, 2) ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-lg shadow-md p-6 stat-card">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-purple-100 text-purple-500">
                                <i class="fas fa-users text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h2 class="text-gray-600 text-sm">Total Memberships</h2>
                                <p class="text-2xl font-semibold text-gray-800"><?= $total_memberships ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Daily Earnings -->
                <div class="bg-white rounded-lg shadow-md mb-6">
                    <div class="border-b border-gray-200 px-6 py-4">
                        <h2 class="text-lg font-semibold text-gray-800">Daily Earnings</h2>
                    </div>
                    <div class="p-6">
                        <?php if (empty($paginatedDailyEarnings)): ?>
                            <div class="text-center py-4">
                                <p class="text-gray-500">No daily earnings data available</p>
                            </div>
                        <?php else: ?>
                            <div class="overflow-x-auto responsive-table">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th scope="col"
                                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Date</th>
                                            <th scope="col"
                                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Earnings</th>
                                            <th scope="col"
                                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($paginatedDailyEarnings as $date => $earnings): ?>
                                            <tr>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="text-sm font-medium text-gray-900">
                                                        <?= date('M d, Y', strtotime($date)) ?>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="text-sm text-gray-900">₹<?= number_format($earnings, 2) ?></div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                    <a href="daily_report.php?date=<?= $date ?>"
                                                        class="action-button bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded-md">
                                                        <i class="fas fa-file-alt mr-1"></i> View Report
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <!-- Pagination -->
                            <div class="mt-4 flex justify-center pagination">
                                <?= generatePaginationLinks($dailyEarningsPage, $totalDailyPages, 'daily_page') ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Withdrawals -->
                <div class="bg-white rounded-lg shadow-md mb-6">
                    <div class="border-b border-gray-200 px-6 py-4 flex justify-between items-center">
                        <h2 class="text-lg font-semibold text-gray-800">Recent Withdrawals</h2>
                        <a href="finances.php" class="text-blue-600 hover:text-blue-800 text-sm">
                            <i class="fas fa-external-link-alt mr-1"></i> Manage Finances
                        </a>
                    </div>
                    <div class="p-6">
                        <?php if (empty($recent_withdrawals)): ?>
                            <div class="text-center py-4">
                                <p class="text-gray-500">No recent withdrawals</p>
                            </div>
                        <?php else: ?>
                            <div class="overflow-x-auto responsive-table">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th scope="col"
                                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Date</th>
                                            <th scope="col"
                                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Amount</th>
                                            <th scope="col"
                                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Status</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($recent_withdrawals as $withdrawal): ?>
                                            <tr>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="text-sm font-medium text-gray-900">
                                                        <?= date('M d, Y', strtotime($withdrawal['created_at'])) ?>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="text-sm text-gray-900">
                                                        ₹<?= number_format($withdrawal['amount'], 2) ?></div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <?php
                                                    $statusColors = [
                                                        'pending' => 'bg-yellow-100 text-yellow-800',
                                                        'completed' => 'bg-green-100 text-green-800',
                                                        'failed' => 'bg-red-100 text-red-800'
                                                    ];
                                                    $statusColor = $statusColors[$withdrawal['status']] ?? 'bg-gray-100 text-gray-800';
                                                    ?>
                                                    <span class="status-badge <?= $statusColor ?>">
                                                        <?= ucfirst($withdrawal['status']) ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Membership Sales -->
                <div class="bg-white rounded-lg shadow-md">
                    <div class="border-b border-gray-200 px-6 py-4">
                        <h2 class="text-lg font-semibold text-gray-800">Membership Sales</h2>
                    </div>
                    <div class="p-6">
                        <?php if (empty($memberships)): ?>
                            <div class="text-center py-4">
                                <p class="text-gray-500">No membership sales data available</p>
                            </div>
                        <?php else: ?>
                            <div class="overflow-x-auto responsive-table">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th scope="col"
                                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Member</th>
                                            <th scope="col"
                                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Plan</th>
                                            <th scope="col"
                                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Duration</th>
                                            <th scope="col"
                                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Price</th>
                                            <th scope="col"
                                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Your Earnings</th>
                                            <th scope="col"
                                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Date</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($memberships as $membership): ?>
                                            <tr>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="text-sm font-medium text-gray-900">
                                                        <?= htmlspecialchars($membership['username']) ?>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="text-sm text-gray-900">
                                                        <?= htmlspecialchars($membership['plan_name']) ?>
                                                    </div>
                                                    <div class="text-xs text-gray-500"><?= htmlspecialchars($membership['tier']) ?>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="text-sm text-gray-900">
                                                        <?= htmlspecialchars($membership['duration']) ?>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="text-sm text-gray-900">
                                                        ₹<?= number_format($membership['plan_price'], 2) ?></div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="text-sm text-gray-900">
                                                        ₹<?= number_format($membership['gym_earnings'], 2) ?></div>
                                                    <div class="text-xs text-gray-500">
                                                        (<?= number_format($membership['gym_cut_percentage']) ?>%)</div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="text-sm text-gray-900">
                                                        <?= date('M d, Y', strtotime($membership['created_at'])) ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <!-- Pagination -->
                            <div class="mt-4 flex justify-center pagination">
                                <?= generatePaginationLinks($page, ceil(count($memberships) / $limit)) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Loading Overlay -->
    <div id="loadingOverlay" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
        <div class="bg-white p-5 rounded-lg flex items-center">
            <div class="loading-spinner mr-3"></div>
            <p class="text-gray-800 font-medium">Processing...</p>
        </div>
    </div>

    <script>
        // Accept booking functionality (without confirmation)
        function acceptBooking(scheduleId) {
            showLoading();
            fetch('process_accept.php', {
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
                        // Success message without alert
                        const successToast = document.createElement('div');
                        successToast.className = 'fixed bottom-4 right-4 bg-green-500 text-white px-4 py-2 rounded shadow-lg z-50';
                        successToast.innerHTML = '<i class="fas fa-check-circle mr-2"></i> Booking accepted successfully!';
                        document.body.appendChild(successToast);

                        // Auto-remove the toast after 3 seconds
                        setTimeout(() => {
                            successToast.remove();
                            location.reload();
                        }, 1500);
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
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok');
                        }
                        return response.json();
                    })
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

        // Add responsive handling for tables on small screens
        document.addEventListener('DOMContentLoaded', function () {
            const tables = document.querySelectorAll('.responsive-table table');

            if (window.innerWidth < 768) {
                tables.forEach(table => {
                    const headerRow = table.querySelector('thead tr');
                    const dataRows = table.querySelectorAll('tbody tr');

                    // Get all header texts
                    const headers = [];
                    headerRow.querySelectorAll('th').forEach(th => {
                        headers.push(th.textContent.trim());
                    });

                    // Add data attributes to each cell for mobile view
                    dataRows.forEach(row => {
                        const cells = row.querySelectorAll('td');
                        cells.forEach((cell, index) => {
                            if (headers[index]) {
                                cell.setAttribute('data-label', headers[index]);
                            }
                        });
                    });
                });
            }

            // Handle tab navigation with URL parameters
            const tabButtons = document.querySelectorAll('.tab-button');
            tabButtons.forEach(button => {
                button.addEventListener('click', function (e) {
                    e.preventDefault();
                    const tab = this.getAttribute('href').split('=')[1];
                    window.location.href = `?tab=${tab}`;
                });
            });
        });
    </script>
</body>

</html>