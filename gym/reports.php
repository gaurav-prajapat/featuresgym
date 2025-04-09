<?php
include '../includes/navbar.php';
require_once '../config/database.php';

// Check if gym owner is logged in
if (!isset($_SESSION['owner_id'])) {
    header('Location: ../login.php');
    exit();
}

$db = new GymDatabase();
$conn = $db->getConnection();
$owner_id = $_SESSION['owner_id'];

// Get gym information
$stmt = $conn->prepare("SELECT * FROM gyms WHERE owner_id = ?");
$stmt->execute([$owner_id]);
$gym = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$gym) {
    $_SESSION['error'] = "No gym found for this owner.";
    header('Location: dashboard.php');
    exit();
}

$gym_id = $gym['gym_id'];

// Set default date range (last 30 days)
$end_date = date('Y-m-d');
$start_date = date('Y-m-d', strtotime('-30 days'));

// Handle date range filter
if (isset($_GET['start_date']) && isset($_GET['end_date'])) {
    $start_date = $_GET['start_date'];
    $end_date = $_GET['end_date'];
}

// Get revenue data for the selected period
$stmt = $conn->prepare("
    SELECT 
        DATE(created_at) as date,
        SUM(amount) as total_revenue,
        SUM(admin_cut) as admin_cut,
        SUM(amount - admin_cut) as gym_revenue
    FROM gym_revenue
    WHERE gym_id = ? AND DATE(created_at) BETWEEN ? AND ?
    GROUP BY DATE(created_at)
    ORDER BY date
");
$stmt->execute([$gym_id, $start_date, $end_date]);
$revenue_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Prepare data for charts
$dates = [];
$revenue = [];
$admin_cut = [];
$gym_revenue = [];

foreach ($revenue_data as $data) {
    $dates[] = date('M d', strtotime($data['date']));
    $revenue[] = $data['total_revenue'];
    $admin_cut[] = $data['admin_cut'];
    $gym_revenue[] = $data['gym_revenue'];
}

// Get total revenue for the period
$stmt = $conn->prepare("
    SELECT 
        SUM(amount) as total_revenue,
        SUM(admin_cut) as total_admin_cut,
        SUM(amount - admin_cut) as total_gym_revenue
    FROM gym_revenue
    WHERE gym_id = ? AND DATE(created_at) BETWEEN ? AND ?
");
$stmt->execute([$gym_id, $start_date, $end_date]);
$totals = $stmt->fetch(PDO::FETCH_ASSOC);

// Get member statistics
$stmt = $conn->prepare("
    SELECT 
        COUNT(DISTINCT user_id) as total_members,
        COUNT(CASE WHEN status = 'active' THEN 1 END) as active_members,
        COUNT(CASE WHEN status = 'expired' THEN 1 END) as expired_members
    FROM user_memberships
    WHERE gym_id = ?
");
$stmt->execute([$gym_id]);
$member_stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Get visit statistics for the period
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_visits,
        COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_visits,
        COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled_visits,
        COUNT(CASE WHEN status = 'missed' THEN 1 END) as missed_visits
    FROM schedules
    WHERE gym_id = ? AND DATE(start_date) BETWEEN ? AND ?
");
$stmt->execute([$gym_id, $start_date, $end_date]);
$visit_stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Get popular times data
$stmt = $conn->prepare("
    SELECT 
        HOUR(start_time) as hour,
        COUNT(*) as visit_count
    FROM schedules
    WHERE gym_id = ? AND DATE(start_date) BETWEEN ? AND ? AND status = 'completed'
    GROUP BY HOUR(start_time)
    ORDER BY hour
");
$stmt->execute([$gym_id, $start_date, $end_date]);
$popular_times = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Prepare data for popular times chart
$hours = [];
$visits = [];

foreach ($popular_times as $time) {
    $hour_display = date('g A', strtotime($time['hour'] . ':00'));
    $hours[] = $hour_display;
    $visits[] = $time['visit_count'];
}

?>

<div class="container mx-auto px-4 py-8 pt-20">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Gym Reports</h1>
        
        <!-- Date Range Filter -->
        <form method="GET" class="flex items-center space-x-2">
            <div>
                <label class="block text-sm text-gray-400">Start Date</label>
                <input type="date" name="start_date" value="<?php echo $start_date; ?>" class="bg-gray-700 border border-gray-600 rounded px-3 py-1 text-sm">
            </div>
            <div>
                <label class="block text-sm text-gray-400">End Date</label>
                <input type="date" name="end_date" value="<?php echo $end_date; ?>" class="bg-gray-700 border border-gray-600 rounded px-3 py-1 text-sm">
            </div>
            <div class="mt-5">
                <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-1 rounded transition-colors duration-200">
                    Apply
                </button>
            </div>
        </form>
    </div>

    <!-- Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
        <div class="bg-gray-800 rounded-lg shadow-lg p-6">
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-gray-400 text-sm">Total Revenue</p>
                    <h3 class="text-2xl font-bold text-white">₹<?php echo number_format($totals['total_revenue'] ?? 0, 2); ?></h3>
                </div>
                <div class="bg-blue-500 bg-opacity-20 p-3 rounded-full">
                    <i class="fas fa-money-bill-wave text-blue-500 text-xl"></i>
                </div>
            </div>
        </div>
        
        <div class="bg-gray-800 rounded-lg shadow-lg p-6">
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-gray-400 text-sm">Your Earnings</p>
                    <h3 class="text-2xl font-bold text-white">₹<?php echo number_format($totals['total_gym_revenue'] ?? 0, 2); ?></h3>
                </div>
                <div class="bg-green-500 bg-opacity-20 p-3 rounded-full">
                    <i class="fas fa-hand-holding-usd text-green-500 text-xl"></i>
                </div>
            </div>
        </div>
        
        <div class="bg-gray-800 rounded-lg shadow-lg p-6">
            <div class="flex justify-between items-start">
            <div>
                    <p class="text-gray-400 text-sm">Total Visits</p>
                    <h3 class="text-2xl font-bold text-white"><?php echo number_format($visit_stats['total_visits'] ?? 0); ?></h3>
                </div>
                <div class="bg-purple-500 bg-opacity-20 p-3 rounded-full">
                    <i class="fas fa-calendar-check text-purple-500 text-xl"></i>
                </div>
            </div>
        </div>
        
        <div class="bg-gray-800 rounded-lg shadow-lg p-6">
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-gray-400 text-sm">Active Members</p>
                    <h3 class="text-2xl font-bold text-white"><?php echo number_format($member_stats['active_members'] ?? 0); ?></h3>
                </div>
                <div class="bg-yellow-500 bg-opacity-20 p-3 rounded-full">
                    <i class="fas fa-users text-yellow-500 text-xl"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Revenue Chart -->
    <div class="bg-gray-800 rounded-lg shadow-lg p-6 mb-8">
        <h2 class="text-xl font-semibold mb-4">Revenue Overview</h2>
        <div class="h-80">
            <canvas id="revenueChart"></canvas>
        </div>
    </div>

    <!-- Statistics Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-8">
        <!-- Member Statistics -->
        <div class="bg-gray-800 rounded-lg shadow-lg p-6">
            <h2 class="text-xl font-semibold mb-4">Member Statistics</h2>
            <div class="grid grid-cols-2 gap-4">
                <div class="bg-gray-700 rounded-lg p-4">
                    <p class="text-gray-400 text-sm">Total Members</p>
                    <h3 class="text-2xl font-bold text-white"><?php echo number_format($member_stats['total_members'] ?? 0); ?></h3>
                </div>
                <div class="bg-gray-700 rounded-lg p-4">
                    <p class="text-gray-400 text-sm">Active Members</p>
                    <h3 class="text-2xl font-bold text-green-400"><?php echo number_format($member_stats['active_members'] ?? 0); ?></h3>
                </div>
                <div class="bg-gray-700 rounded-lg p-4">
                    <p class="text-gray-400 text-sm">Expired Memberships</p>
                    <h3 class="text-2xl font-bold text-red-400"><?php echo number_format($member_stats['expired_members'] ?? 0); ?></h3>
                </div>
                <div class="bg-gray-700 rounded-lg p-4">
                    <p class="text-gray-400 text-sm">Retention Rate</p>
                    <?php 
                    $retention_rate = 0;
                    if ($member_stats['total_members'] > 0) {
                        $retention_rate = ($member_stats['active_members'] / $member_stats['total_members']) * 100;
                    }
                    ?>
                    <h3 class="text-2xl font-bold text-blue-400"><?php echo number_format($retention_rate, 1); ?>%</h3>
                </div>
            </div>
        </div>

        <!-- Visit Statistics -->
        <div class="bg-gray-800 rounded-lg shadow-lg p-6">
            <h2 class="text-xl font-semibold mb-4">Visit Statistics</h2>
            <div class="grid grid-cols-2 gap-4">
                <div class="bg-gray-700 rounded-lg p-4">
                    <p class="text-gray-400 text-sm">Total Visits</p>
                    <h3 class="text-2xl font-bold text-white"><?php echo number_format($visit_stats['total_visits'] ?? 0); ?></h3>
                </div>
                <div class="bg-gray-700 rounded-lg p-4">
                    <p class="text-gray-400 text-sm">Completed Visits</p>
                    <h3 class="text-2xl font-bold text-green-400"><?php echo number_format($visit_stats['completed_visits'] ?? 0); ?></h3>
                </div>
                <div class="bg-gray-700 rounded-lg p-4">
                    <p class="text-gray-400 text-sm">Cancelled Visits</p>
                    <h3 class="text-2xl font-bold text-yellow-400"><?php echo number_format($visit_stats['cancelled_visits'] ?? 0); ?></h3>
                </div>
                <div class="bg-gray-700 rounded-lg p-4">
                    <p class="text-gray-400 text-sm">Missed Visits</p>
                    <h3 class="text-2xl font-bold text-red-400"><?php echo number_format($visit_stats['missed_visits'] ?? 0); ?></h3>
                </div>
            </div>
        </div>
    </div>

    <!-- Popular Times Chart -->
    <div class="bg-gray-800 rounded-lg shadow-lg p-6 mb-8">
        <h2 class="text-xl font-semibold mb-4">Popular Visit Times</h2>
        <div class="h-80">
            <canvas id="popularTimesChart"></canvas>
        </div>
    </div>
</div>

<!-- Include Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
    // Revenue Chart
    const revenueCtx = document.getElementById('revenueChart').getContext('2d');
    const revenueChart = new Chart(revenueCtx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($dates); ?>,
            datasets: [
                {
                    label: 'Total Revenue',
                    data: <?php echo json_encode($revenue); ?>,
                    backgroundColor: 'rgba(59, 130, 246, 0.2)',
                    borderColor: 'rgba(59, 130, 246, 1)',
                    borderWidth: 2,
                    tension: 0.3,
                    pointBackgroundColor: 'rgba(59, 130, 246, 1)'
                },
                {
                    label: 'Your Earnings',
                    data: <?php echo json_encode($gym_revenue); ?>,
                    backgroundColor: 'rgba(16, 185, 129, 0.2)',
                    borderColor: 'rgba(16, 185, 129, 1)',
                    borderWidth: 2,
                    tension: 0.3,
                    pointBackgroundColor: 'rgba(16, 185, 129, 1)'
                },
                {
                    label: 'Admin Cut',
                    data: <?php echo json_encode($admin_cut); ?>,
                    backgroundColor: 'rgba(239, 68, 68, 0.2)',
                    borderColor: 'rgba(239, 68, 68, 1)',
                    borderWidth: 2,
                    tension: 0.3,
                    pointBackgroundColor: 'rgba(239, 68, 68, 1)'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        color: 'white'
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.dataset.label + ': ₹' + context.raw;
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: {
                        color: 'rgba(255, 255, 255, 0.1)'
                    },
                    ticks: {
                        color: 'rgba(255, 255, 255, 0.7)'
                    }
                },
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(255, 255, 255, 0.1)'
                    },
                    ticks: {
                        color: 'rgba(255, 255, 255, 0.7)',
                        callback: function(value) {
                            return '₹' + value;
                        }
                    }
                }
            }
        }
    });

    // Popular Times Chart
    const popularTimesCtx = document.getElementById('popularTimesChart').getContext('2d');
    const popularTimesChart = new Chart(popularTimesCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($hours); ?>,
            datasets: [{
                label: 'Number of Visits',
                data: <?php echo json_encode($visits); ?>,
                backgroundColor: 'rgba(139, 92, 246, 0.6)',
                borderColor: 'rgba(139, 92, 246, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        color: 'white'
                    }
                }
            },
            scales: {
                x: {
                    grid: {
                        color: 'rgba(255, 255, 255, 0.1)'
                    },
                    ticks: {
                        color: 'rgba(255, 255, 255, 0.7)'
                    }
                },
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(255, 255, 255, 0.1)'
                    },
                    ticks: {
                        color: 'rgba(255, 255, 255, 0.7)',
                        stepSize: 1
                    }
                }
            }
        }
    });
</script>

<?php include '../includes/footer.php'; ?>

