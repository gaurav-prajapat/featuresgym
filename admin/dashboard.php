<?php
require_once '../config/database.php';
include "../includes/navbar.php";

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    $_SESSION['error'] = "Please log in to access the admin dashboard.";
    header("Location: login.php");
    exit();
}

$db = new GymDatabase();
$conn = $db->getConnection();

// Fetch dashboard statistics
try {
    // Total users count
    $userStmt = $conn->prepare("SELECT COUNT(*) as total_users FROM users WHERE role = 'member'");
    $userStmt->execute();
    $totalUsers = $userStmt->fetch(PDO::FETCH_ASSOC)['total_users'];

    // Total gyms count
    $gymStmt = $conn->prepare("SELECT COUNT(*) as total_gyms FROM gyms");
    $gymStmt->execute();
    $totalGyms = $gymStmt->fetch(PDO::FETCH_ASSOC)['total_gyms'];

    // Active memberships count
    $membershipStmt = $conn->prepare("SELECT COUNT(*) as active_memberships FROM user_memberships WHERE status = 'active'");
    $membershipStmt->execute();
    $activeMemberships = $membershipStmt->fetch(PDO::FETCH_ASSOC)['active_memberships'];

    // Pending gym approvals
    $pendingGymsStmt = $conn->prepare("SELECT COUNT(*) as pending_gyms FROM gyms WHERE status = 'pending'");
    $pendingGymsStmt->execute();
    $pendingGyms = $pendingGymsStmt->fetch(PDO::FETCH_ASSOC)['pending_gyms'];

    // Pending reviews
    $pendingReviewsStmt = $conn->prepare("SELECT COUNT(*) as pending_reviews FROM reviews WHERE status = 'pending'");
    $pendingReviewsStmt->execute();
    $pendingReviews = $pendingReviewsStmt->fetch(PDO::FETCH_ASSOC)['pending_reviews'];

    // Recent transactions
    $transactionsStmt = $conn->prepare("
        SELECT t.*, u.username, g.name as gym_name 
        FROM transactions t
        JOIN users u ON t.user_id = u.id
        JOIN gyms g ON t.gym_id = g.gym_id
        ORDER BY t.transaction_date DESC
        LIMIT 5
    ");
    $transactionsStmt->execute();
    $recentTransactions = $transactionsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Recent activity logs
    $logsStmt = $conn->prepare("
        SELECT * FROM activity_logs
        WHERE user_type = 'admin'
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $logsStmt->execute();
    $recentLogs = $logsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Total revenue
    $revenueStmt = $conn->prepare("
        SELECT SUM(amount) as total_revenue 
        FROM payments 
        WHERE status = 'completed'
    ");
    $revenueStmt->execute();
    $totalRevenue = $revenueStmt->fetch(PDO::FETCH_ASSOC)['total_revenue'] ?? 0;

    // Log this admin activity
    $stmt = $conn->prepare("
        INSERT INTO activity_logs (
            user_id, user_type, action, details, ip_address, user_agent
        ) VALUES (?, 'admin', 'view_dashboard', ?, ?, ?)
    ");
    $details = "Admin viewed dashboard";
    $stmt->execute([
        $_SESSION['admin_id'] ?? 0,
        $details,
        $_SERVER['REMOTE_ADDR'],
        $_SERVER['HTTP_USER_AGENT']
    ]);

} catch (PDOException $e) {
    error_log("Dashboard error: " . $e->getMessage());
    $error = "An error occurred while fetching dashboard data.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - FlexFit</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .dashboard-card {
            transition: all 0.3s ease;
        }
        .dashboard-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
        }
    </style>
</head>
<body class="bg-gray-900 text-white">
    
    <!-- Include sidebar -->
    <?php include 'includes/sidebar.php'; ?>
    
    <!-- Main content -->
    <div class="lg:ml-64 p-8">
        <div class="container mx-auto">
            <h1 class="text-3xl font-bold mb-8">Admin Dashboard</h1>
            
            <?php if (isset($error)): ?>
                <div class="bg-red-500 text-white p-4 rounded-lg mb-6">
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <!-- Stats Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <!-- Users Card -->
                <div class="dashboard-card bg-gradient-to-r from-blue-600 to-blue-800 rounded-lg shadow-lg p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-blue-100 text-sm">Total Members</p>
                            <h2 class="text-4xl font-bold"><?php echo number_format($totalUsers); ?></h2>
                        </div>
                        <div class="bg-blue-500 rounded-full p-3">
                            <i class="fas fa-users text-2xl text-white"></i>
                        </div>
                    </div>
                    <div class="mt-4">
                        <a href="members.php" class="text-blue-100 text-sm hover:text-white">View all members <i class="fas fa-arrow-right ml-1"></i></a>
                    </div>
                </div>
                
                <!-- Gyms Card -->
                <div class="dashboard-card bg-gradient-to-r from-purple-600 to-purple-800 rounded-lg shadow-lg p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-purple-100 text-sm">Total Gyms</p>
                            <h2 class="text-4xl font-bold"><?php echo number_format($totalGyms); ?></h2>
                        </div>
                        <div class="bg-purple-500 rounded-full p-3">
                            <i class="fas fa-dumbbell text-2xl text-white"></i>
                        </div>
                    </div>
                    <div class="mt-4">
                        <a href="gyms.php" class="text-purple-100 text-sm hover:text-white">View all gyms <i class="fas fa-arrow-right ml-1"></i></a>
                    </div>
                </div>
                
                <!-- Memberships Card -->
                <div class="dashboard-card bg-gradient-to-r from-green-600 to-green-800 rounded-lg shadow-lg p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-green-100 text-sm">Active Memberships</p>
                            <h2 class="text-4xl font-bold"><?php echo number_format($activeMemberships); ?></h2>
                        </div>
                        <div class="bg-green-500 rounded-full p-3">
                            <i class="fas fa-id-card text-2xl text-white"></i>
                        </div>
                    </div>
                    <div class="mt-4">
                        <a href="active_memberships.php" class="text-green-100 text-sm hover:text-white">View memberships <i class="fas fa-arrow-right ml-1"></i></a>
                    </div>
                </div>
                
                <!-- Revenue Card -->
                <div class="dashboard-card bg-gradient-to-r from-yellow-600 to-yellow-800 rounded-lg shadow-lg p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-yellow-100 text-sm">Total Revenue</p>
                            <h2 class="text-4xl font-bold">₹<?php echo number_format($totalRevenue, 2); ?></h2>
                        </div>
                        <div class="bg-yellow-500 rounded-full p-3">
                            <i class="fas fa-money-bill-wave text-2xl text-white"></i>
                        </div>
                    </div>
                    <div class="mt-4">
                        <a href="financial_reports.php" class="text-yellow-100 text-sm hover:text-white">View financial reports <i class="fas fa-arrow-right ml-1"></i></a>
                    </div>
                </div>
            </div>
            
            <!-- Alerts Section -->
            <div class="mb-8">
                <h2 class="text-xl font-semibold mb-4">Alerts & Notifications</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <?php if ($pendingGyms > 0): ?>
                    <div class="bg-yellow-900 bg-opacity-50 border border-yellow-800 rounded-lg p-4 flex items-center">
                        <div class="bg-yellow-600 rounded-full p-3 mr-4">
                            <i class="fas fa-exclamation-triangle text-xl text-white"></i>
                        </div>
                        <div>
                            <h3 class="font-semibold text-yellow-300"><?php echo $pendingGyms; ?> Pending Gym Approvals</h3>
                            <p class="text-yellow-200 text-sm">New gyms waiting for your approval</p>
                            <a href="pending_gyms.php" class="text-yellow-300 text-sm hover:text-yellow-100 mt-1 inline-block">Review now <i class="fas fa-arrow-right ml-1"></i></a>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($pendingReviews > 0): ?>
                    <div class="bg-blue-900 bg-opacity-50 border border-blue-800 rounded-lg p-4 flex items-center">
                        <div class="bg-blue-600 rounded-full p-3 mr-4">
                            <i class="fas fa-star text-xl text-white"></i>
                        </div>
                        <div>
                            <h3 class="font-semibold text-blue-300"><?php echo $pendingReviews; ?> Pending Reviews</h3>
                            <p class="text-blue-200 text-sm">Reviews waiting for moderation</p>
                            <a href="pending_reviews.php" class="text-blue-300 text-sm hover:text-blue-100 mt-1 inline-block">Moderate now <i class="fas fa-arrow-right ml-1"></i></a>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Charts Section -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
                <div class="bg-gray-800 rounded-lg shadow-lg p-6">
                    <h2 class="text-xl font-semibold mb-4">Revenue Overview</h2>
                    <canvas id="revenueChart" height="300"></canvas>
                </div>
                
                <div class="bg-gray-800 rounded-lg shadow-lg p-6">
                    <h2 class="text-xl font-semibold mb-4">User Growth</h2>
                    <canvas id="userGrowthChart" height="300"></canvas>
                </div>
            </div>
            
            <!-- Recent Transactions -->
            <div class="bg-gray-800 rounded-lg shadow-lg p-6 mb-8">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-xl font-semibold">Recent Transactions</h2>
                    <a href="transactions.php" class="text-blue-400 hover:text-blue-300 text-sm">View all <i class="fas fa-arrow-right ml-1"></i></a>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="text-left text-gray-400 text-sm">
                                <th class="pb-3 pr-4">User</th>
                                <th class="pb-3 pr-4">Gym</th>
                                <th class="pb-3 pr-4">Amount</th>
                                <th class="pb-3 pr-4">Type</th>
                                <th class="pb-3 pr-4">Status</th>
                                <th class="pb-3 pr-4">Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentTransactions)): ?>
                                <tr>
                                    <td colspan="6" class="py-4 text-center text-gray-500">No recent transactions found</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recentTransactions as $transaction): ?>
                                    <tr class="border-t border-gray-700">
                                        <td class="py-3 pr-4"><?php echo htmlspecialchars($transaction['username']); ?></td>
                                        <td class="py-3 pr-4"><?php echo htmlspecialchars($transaction['gym_name']); ?></td>
                                        <td class="py-3 pr-4">₹<?php echo number_format($transaction['amount'], 2); ?></td>
                                        <td class="py-3 pr-4">
                                            <span class="px-2 py-1 rounded-full text-xs 
                                                <?php echo $transaction['transaction_type'] == 'payment' ? 'bg-green-900 text-green-300' : 'bg-blue-900 text-blue-300'; ?>">
                                                <?php echo ucfirst($transaction['transaction_type']); ?>
                                                </span>
                                        </td>
                                        <td class="py-3 pr-4">
                                            <span class="px-2 py-1 rounded-full text-xs 
                                                <?php 
                                                    if ($transaction['status'] == 'completed') echo 'bg-green-900 text-green-300';
                                                    elseif ($transaction['status'] == 'pending') echo 'bg-yellow-900 text-yellow-300';
                                                    else echo 'bg-red-900 text-red-300';
                                                ?>">
                                                <?php echo ucfirst($transaction['status']); ?>
                                            </span>
                                        </td>
                                        <td class="py-3 pr-4 text-gray-400">
                                            <?php echo date('M d, Y', strtotime($transaction['transaction_date'])); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Recent Activity Logs -->
            <div class="bg-gray-800 rounded-lg shadow-lg p-6">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-xl font-semibold">Recent Admin Activity</h2>
                    <a href="activity_logs.php" class="text-blue-400 hover:text-blue-300 text-sm">View all <i class="fas fa-arrow-right ml-1"></i></a>
                </div>
                
                <div class="space-y-4">
                    <?php if (empty($recentLogs)): ?>
                        <p class="text-center text-gray-500">No recent activity found</p>
                    <?php else: ?>
                        <?php foreach ($recentLogs as $log): ?>
                            <div class="flex items-start border-l-4 border-blue-500 pl-4 py-2">
                                <div class="bg-blue-500 rounded-full p-2 mr-4">
                                    <i class="fas fa-user-shield text-white"></i>
                                </div>
                                <div>
                                    <p class="font-medium"><?php echo htmlspecialchars($log['action']); ?></p>
                                    <p class="text-sm text-gray-400"><?php echo htmlspecialchars($log['details']); ?></p>
                                    <p class="text-xs text-gray-500 mt-1">
                                        <?php echo date('M d, Y h:i A', strtotime($log['created_at'])); ?> • 
                                        IP: <?php echo htmlspecialchars($log['ip_address']); ?>
                                    </p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Sample data for charts - in a real application, this would come from the database
        // Revenue Chart
        const revenueCtx = document.getElementById('revenueChart').getContext('2d');
        const revenueChart = new Chart(revenueCtx, {
            type: 'line',
            data: {
                labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
                datasets: [{
                    label: 'Monthly Revenue (₹)',
                    data: [12000, 19000, 15000, 25000, 22000, 30000],
                    backgroundColor: 'rgba(59, 130, 246, 0.2)',
                    borderColor: 'rgba(59, 130, 246, 1)',
                    borderWidth: 2,
                    tension: 0.4,
                    pointBackgroundColor: 'rgba(59, 130, 246, 1)',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(255, 255, 255, 0.1)'
                        },
                        ticks: {
                            color: 'rgba(255, 255, 255, 0.7)'
                        }
                    },
                    x: {
                        grid: {
                            color: 'rgba(255, 255, 255, 0.1)'
                        },
                        ticks: {
                            color: 'rgba(255, 255, 255, 0.7)'
                        }
                    }
                },
                plugins: {
                    legend: {
                        labels: {
                            color: 'rgba(255, 255, 255, 0.7)'
                        }
                    }
                }
            }
        });

        // User Growth Chart
        const userGrowthCtx = document.getElementById('userGrowthChart').getContext('2d');
        const userGrowthChart = new Chart(userGrowthCtx, {
            type: 'bar',
            data: {
                labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
                datasets: [{
                    label: 'New Users',
                    data: [50, 75, 60, 90, 110, 95],
                    backgroundColor: 'rgba(139, 92, 246, 0.7)',
                    borderColor: 'rgba(139, 92, 246, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(255, 255, 255, 0.1)'
                        },
                        ticks: {
                            color: 'rgba(255, 255, 255, 0.7)'
                        }
                    },
                    x: {
                        grid: {
                            color: 'rgba(255, 255, 255, 0.1)'
                        },
                        ticks: {
                            color: 'rgba(255, 255, 255, 0.7)'
                        }
                    }
                },
                plugins: {
                    legend: {
                        labels: {
                            color: 'rgba(255, 255, 255, 0.7)'
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>

