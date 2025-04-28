<?php
ob_start();
require_once '../config/database.php';
session_start();

// Check if user is logged in as admin
if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ./login.php');
    exit();
}

$db = new GymDatabase();
$conn = $db->getConnection();

// Get filters
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'revenue';
$date_range = isset($_GET['date_range']) ? $_GET['date_range'] : 'this_month';
$gym_id = isset($_GET['gym_id']) ? (int)$_GET['gym_id'] : 0;
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';

// Set default date range if custom range not specified
if ($date_range !== 'custom' || empty($start_date) || empty($end_date)) {
    $today = new DateTime();
    
    switch ($date_range) {
        case 'today':
            $start_date = $today->format('Y-m-d');
            $end_date = $today->format('Y-m-d');
            break;
        case 'yesterday':
            $yesterday = clone $today;
            $yesterday->modify('-1 day');
            $start_date = $yesterday->format('Y-m-d');
            $end_date = $yesterday->format('Y-m-d');
            break;
        case 'this_week':
            $start_of_week = clone $today;
            $start_of_week->modify('this week monday');
            $start_date = $start_of_week->format('Y-m-d');
            $end_date = $today->format('Y-m-d');
            break;
        case 'last_week':
            $start_of_last_week = clone $today;
            $start_of_last_week->modify('last week monday');
            $end_of_last_week = clone $start_of_last_week;
            $end_of_last_week->modify('+6 days');
            $start_date = $start_of_last_week->format('Y-m-d');
            $end_date = $end_of_last_week->format('Y-m-d');
            break;
        case 'last_month':
            $start_of_last_month = clone $today;
            $start_of_last_month->modify('first day of last month');
            $end_of_last_month = clone $start_of_last_month;
            $end_of_last_month->modify('last day of this month');
            $start_date = $start_of_last_month->format('Y-m-d');
            $end_date = $end_of_last_month->format('Y-m-d');
            break;
        case 'last_quarter':
            $current_month = (int)$today->format('n');
            $current_quarter = ceil($current_month / 3) - 1;
            if ($current_quarter < 1) $current_quarter = 4;
            $start_month = ($current_quarter - 1) * 3 + 1;
            $end_month = $current_quarter * 3;
            $year = (int)$today->format('Y');
            if ($current_quarter === 4 && $current_month < 12) $year--;
            $start_date = sprintf('%d-%02d-01', $year, $start_month);
            $end_date = date('Y-m-t', strtotime(sprintf('%d-%02d-01', $year, $end_month)));
            break;
        case 'last_year':
            $last_year = (int)$today->format('Y') - 1;
            $start_date = $last_year . '-01-01';
            $end_date = $last_year . '-12-31';
            break;
        case 'this_month':
        default:
            $start_of_month = clone $today;
            $start_of_month->modify('first day of this month');
            $start_date = $start_of_month->format('Y-m-d');
            $end_date = $today->format('Y-m-d');
            break;
    }
}

// Get gyms for filter dropdown
try {
    $stmt = $conn->prepare("
        SELECT gym_id, name
        FROM gyms
        ORDER BY name
    ");
    $stmt->execute();
    $gyms = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $gyms = [];
}

// Fetch report data based on type
$report_data = [];
$chart_data = [];
$summary = [];

try {
    switch ($report_type) {
        case 'revenue':
            // Revenue report
            $query = "
                SELECT 
                    DATE(gr.date) as date,
                    SUM(gr.amount+ gr.admin_cut) as gym_revenue,
                    SUM(gr.admin_cut) as admin_revenue,
                    SUM(gr.amount) as total_amount,
                    g.name as gym_name,
                    gr.source_type
                FROM gym_revenue gr
                JOIN gyms g ON gr.gym_id = g.gym_id
                WHERE DATE(gr.date) BETWEEN ? AND ?
            ";
            
            $params = [$start_date, $end_date];
            
            if ($gym_id > 0) {
                $query .= " AND gr.gym_id = ?";
                $params[] = $gym_id;
            }
            
            $query .= " GROUP BY DATE(gr.date), gr.gym_id, gr.source_type ORDER BY DATE(gr.date) DESC";
            
            $stmt = $conn->prepare($query);
            $stmt->execute($params);
            $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get summary
            $summary_query = "
                SELECT 
                    SUM(gr.amount + gr.admin_cut) as total_revenue,
                    SUM(gr.admin_cut) as admin_revenue,
                    SUM(gr.amount) as gym_revenue,
                    COUNT(DISTINCT gr.gym_id) as total_gyms,
                    COUNT(DISTINCT DATE(gr.date)) as total_days
                FROM gym_revenue gr
                WHERE DATE(gr.date) BETWEEN ? AND ?
            ";
            
            $summary_params = [$start_date, $end_date];
            
            if ($gym_id > 0) {
                $summary_query .= " AND gr.gym_id = ?";
                $summary_params[] = $gym_id;
            }
            
            $stmt = $conn->prepare($summary_query);
            $stmt->execute($summary_params);
            $summary = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get chart data - daily revenue
            $chart_query = "
                SELECT 
                    DATE(gr.date) as date,
                    SUM(gr.amount + gr.admin_cut) as total_amount,
                    SUM(gr.admin_cut) as admin_revenue,
                    SUM(gr.amount) as gym_revenue
                FROM gym_revenue gr
                WHERE DATE(gr.date) BETWEEN ? AND ?
            ";
            
            $chart_params = [$start_date, $end_date];
            
            if ($gym_id > 0) {
                $chart_query .= " AND gr.gym_id = ?";
                $chart_params[] = $gym_id;
            }
            
            $chart_query .= " GROUP BY DATE(gr.date) ORDER BY DATE(gr.date)";
            
            $stmt = $conn->prepare($chart_query);
            $stmt->execute($chart_params);
            $chart_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        case 'memberships':
            // Membership report
            $query = "
                SELECT 
                    um.id,
                    um.start_date,
                    um.end_date,
                    um.amount,
                    um.payment_status,
                    g.name as gym_name,
                    g.gym_id,
                    u.username as member_name,
                    u.email as member_email,
                    gmp.plan_name,
                    gmp.duration
                FROM user_memberships um
                JOIN gyms g ON um.gym_id = g.gym_id
                JOIN users u ON um.user_id = u.id
                JOIN gym_membership_plans gmp ON um.plan_id = gmp.plan_id
                WHERE DATE(um.created_at) BETWEEN ? AND ?
            ";
            
            $params = [$start_date, $end_date];
            
            if ($gym_id > 0) {
                $query .= " AND um.gym_id = ?";
                $params[] = $gym_id;
            }
            
            $query .= " ORDER BY um.created_at DESC";
            
            $stmt = $conn->prepare($query);
            $stmt->execute($params);
            $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get summary
            $summary_query = "
                SELECT 
                    COUNT(*) as total_memberships,
                    SUM(CASE WHEN payment_status = 'paid' THEN amount ELSE 0 END) as total_paid,
                    SUM(CASE WHEN payment_status = 'pending' THEN amount ELSE 0 END) as total_pending,
                    COUNT(DISTINCT user_id) as total_members,
                    COUNT(DISTINCT gym_id) as total_gyms
                FROM user_memberships
                WHERE DATE(created_at) BETWEEN ? AND ?
            ";
            
            $summary_params = [$start_date, $end_date];
            
            if ($gym_id > 0) {
                $summary_query .= " AND gym_id = ?";
                $summary_params[] = $gym_id;
            }
            
            $stmt = $conn->prepare($summary_query);
            $stmt->execute($summary_params);
            $summary = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get chart data - memberships by plan
            $chart_query = "
                SELECT 
                    gmp.plan_name,
                    COUNT(*) as count,
                    SUM(um.amount) as total_amount
                FROM user_memberships um
                JOIN gym_membership_plans gmp ON um.plan_id = gmp.plan_id
                WHERE DATE(um.created_at) BETWEEN ? AND ?
            ";
            
            $chart_params = [$start_date, $end_date];
            
            if ($gym_id > 0) {
                $chart_query .= " AND um.gym_id = ?";
                $chart_params[] = $gym_id;
            }
            
            $chart_query .= " GROUP BY gmp.plan_name ORDER BY COUNT(*) DESC";
            
            $stmt = $conn->prepare($chart_query);
            $stmt->execute($chart_params);
            $chart_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        case 'payouts':
            // Payouts report
            $query = "
                SELECT 
                    w.id,
                    w.amount,
                    w.status,
                    w.created_at,
                    w.processed_at,
                    w.transaction_id,
                    g.name as gym_name,
                    g.gym_id,
                    u.username as owner_name,
                    u.email as owner_email,
                    a.username as admin_name
                FROM withdrawals w
                JOIN gyms g ON w.gym_id = g.gym_id
                JOIN users u ON g.owner_id = u.id
                LEFT JOIN users a ON w.admin_id = a.id
                WHERE DATE(w.created_at) BETWEEN ? AND ?
            ";
            
            $params = [$start_date, $end_date];
            
            if ($gym_id > 0) {
                $query .= " AND w.gym_id = ?";
                $params[] = $gym_id;
            }
            
            $query .= " ORDER BY w.created_at DESC";
            
            $stmt = $conn->prepare($query);
            $stmt->execute($params);
            $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get summary
            $summary_query = "
                SELECT 
                    COUNT(*) as total_payouts,
                    SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as total_completed,
                    SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END) as total_pending,
                    SUM(CASE WHEN status = 'failed' THEN amount ELSE 0 END) as total_failed,
                    COUNT(DISTINCT gym_id) as total_gyms
                FROM withdrawals
                WHERE DATE(created_at) BETWEEN ? AND ?
            ";
            
            $summary_params = [$start_date, $end_date];
            
            if ($gym_id > 0) {
                $summary_query .= " AND gym_id = ?";
                $summary_params[] = $gym_id;
            }
            
            $stmt = $conn->prepare($summary_query);
            $stmt->execute($summary_params);
            $summary = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get chart data - payouts by status
            $chart_query = "
                SELECT 
                    status,
                    COUNT(*) as count,
                    SUM(amount) as total_amount
                FROM withdrawals
                WHERE DATE(created_at) BETWEEN ? AND ?
            ";
            
            $chart_params = [$start_date, $end_date];
            
            if ($gym_id > 0) {
                $chart_query .= " AND gym_id = ?";
                $chart_params[] = $gym_id;
            }
            
            $chart_query .= " GROUP BY status";
            
            $stmt = $conn->prepare($chart_query);
            $stmt->execute($chart_params);
            $chart_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        case 'gym_performance':
            // Gym performance report
            $query = "
                SELECT 
                    g.gym_id,
                    g.name as gym_name,
                    g.city,
                    g.state,
                    g.status,
                    g.is_featured,
                    COUNT(DISTINCT um.user_id) as total_members,
                    COUNT(DISTINCT s.id) as total_schedules,
                    SUM(gr.amount) as total_revenue,
                    SUM(gr.admin_cut) as admin_revenue,
                    AVG(r.rating) as avg_rating,
                    COUNT(r.id) as review_count
                FROM gyms g
                LEFT JOIN user_memberships um ON g.gym_id = um.gym_id AND um.status = 'active'
                                LEFT JOIN schedules s ON g.gym_id = s.gym_id AND DATE(s.start_date) BETWEEN ? AND ?
                LEFT JOIN gym_revenue gr ON g.gym_id = gr.gym_id AND DATE(gr.date) BETWEEN ? AND ?
                LEFT JOIN reviews r ON g.gym_id = r.gym_id
                GROUP BY g.gym_id
                ORDER BY total_revenue DESC
            ";
            
            $params = [$start_date, $end_date, $start_date, $end_date];
            
            if ($gym_id > 0) {
                $query = "
                    SELECT 
                        g.gym_id,
                        g.name as gym_name,
                        g.city,
                        g.state,
                        g.status,
                        g.is_featured,
                        COUNT(DISTINCT um.user_id) as total_members,
                        COUNT(DISTINCT s.id) as total_schedules,
                        SUM(gr.amount) as total_revenue,
                        SUM(gr.admin_cut) as admin_revenue,
                        AVG(r.rating) as avg_rating,
                        COUNT(r.id) as review_count
                    FROM gyms g
                    LEFT JOIN user_memberships um ON g.gym_id = um.gym_id AND um.status = 'active'
                    LEFT JOIN schedules s ON g.gym_id = s.gym_id AND DATE(s.start_date) BETWEEN ? AND ?
                    LEFT JOIN gym_revenue gr ON g.gym_id = gr.gym_id AND DATE(gr.date) BETWEEN ? AND ?
                    LEFT JOIN reviews r ON g.gym_id = r.gym_id
                    WHERE g.gym_id = ?
                    GROUP BY g.gym_id
                ";
                $params = [$start_date, $end_date, $start_date, $end_date, $gym_id];
            }
            
            $stmt = $conn->prepare($query);
            $stmt->execute($params);
            $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get summary
            $summary_query = "
                SELECT 
                    COUNT(DISTINCT g.gym_id) as total_gyms,
                    SUM(gr.amount) as total_revenue,
                    SUM(gr.admin_cut) as admin_revenue,
                    COUNT(DISTINCT um.user_id) as total_members,
                    COUNT(DISTINCT s.id) as total_schedules
                FROM gyms g
                LEFT JOIN user_memberships um ON g.gym_id = um.gym_id AND um.status = 'active'
                LEFT JOIN schedules s ON g.gym_id = s.gym_id AND DATE(s.start_date) BETWEEN ? AND ?
                LEFT JOIN gym_revenue gr ON g.gym_id = gr.gym_id AND DATE(gr.date) BETWEEN ? AND ?
            ";
            
            $summary_params = [$start_date, $end_date, $start_date, $end_date];
            
            if ($gym_id > 0) {
                $summary_query .= " WHERE g.gym_id = ?";
                $summary_params[] = $gym_id;
            }
            
            $stmt = $conn->prepare($summary_query);
            $stmt->execute($summary_params);
            $summary = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get chart data - top performing gyms
            $chart_query = "
                SELECT 
                    g.name as gym_name,
                    SUM(gr.amount) as total_revenue
                FROM gyms g
                JOIN gym_revenue gr ON g.gym_id = gr.gym_id
                WHERE DATE(gr.date) BETWEEN ? AND ?
            ";
            
            $chart_params = [$start_date, $end_date];
            
            if ($gym_id > 0) {
                $chart_query .= " AND g.gym_id = ?";
                $chart_params[] = $gym_id;
            }
            
            $chart_query .= " GROUP BY g.gym_id ORDER BY total_revenue DESC LIMIT 10";
            
            $stmt = $conn->prepare($chart_query);
            $stmt->execute($chart_params);
            $chart_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        case 'transactions':
        default:
            // Transactions report
            $query = "
                SELECT 
                    t.id,
                    t.amount,
                    t.transaction_type,
                    t.status,
                    t.description,
                    t.transaction_date,
                    t.payment_method,
                    t.transaction_id,
                    g.name as gym_name,
                    g.gym_id,
                    u.username as user_name,
                    u.email as user_email
                FROM transactions t
                JOIN gyms g ON t.gym_id = g.gym_id
                JOIN users u ON t.user_id = u.id
                WHERE DATE(t.transaction_date) BETWEEN ? AND ?
            ";
            
            $params = [$start_date, $end_date];
            
            if ($gym_id > 0) {
                $query .= " AND t.gym_id = ?";
                $params[] = $gym_id;
            }
            
            $query .= " ORDER BY t.transaction_date DESC";
            
            $stmt = $conn->prepare($query);
            $stmt->execute($params);
            $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get summary
            $summary_query = "
                SELECT 
                    COUNT(*) as total_transactions,
                    SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as total_completed,
                    SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END) as total_pending,
                    SUM(CASE WHEN status = 'failed' THEN amount ELSE 0 END) as total_failed,
                    COUNT(DISTINCT user_id) as total_users,
                    COUNT(DISTINCT gym_id) as total_gyms
                FROM transactions
                WHERE DATE(transaction_date) BETWEEN ? AND ?
            ";
            
            $summary_params = [$start_date, $end_date];
            
            if ($gym_id > 0) {
                $summary_query .= " AND gym_id = ?";
                $summary_params[] = $gym_id;
            }
            
            $stmt = $conn->prepare($summary_query);
            $stmt->execute($summary_params);
            $summary = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get chart data - transactions by type
            $chart_query = "
                SELECT 
                    transaction_type,
                    COUNT(*) as count,
                    SUM(amount) as total_amount
                FROM transactions
                WHERE DATE(transaction_date) BETWEEN ? AND ?
            ";
            
            $chart_params = [$start_date, $end_date];
            
            if ($gym_id > 0) {
                $chart_query .= " AND gym_id = ?";
                $chart_params[] = $gym_id;
            }
            
            $chart_query .= " GROUP BY transaction_type";
            
            $stmt = $conn->prepare($chart_query);
            $stmt->execute($chart_params);
            $chart_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
    }
} catch (PDOException $e) {
    $_SESSION['error'] = "Database error: " . $e->getMessage();
    $report_data = [];
    $chart_data = [];
    $summary = [];
}

// Function to format date
function formatDate($date) {
    return date('M d, Y', strtotime($date));
}

// Function to format datetime
function formatDateTime($datetime) {
    return date('M d, Y h:i A', strtotime($datetime));
}

// Function to format currency
function formatCurrency($amount) {
    return '₹' . number_format($amount, 2);
}

// Prepare chart data for JavaScript
$chart_labels = [];
$chart_values = [];
$chart_colors = ['#4F46E5', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#EC4899'];

switch ($report_type) {
    case 'revenue':
        foreach ($chart_data as $data) {
            $chart_labels[] = formatDate($data['date']);
            $chart_values['total'][] = floatval($data['total_amount']);
            $chart_values['admin'][] = floatval($data['admin_revenue']);
            $chart_values['gym'][] = floatval($data['gym_revenue']);
        }
        break;
        
    case 'memberships':
        foreach ($chart_data as $index => $data) {
            $chart_labels[] = $data['plan_name'];
            $chart_values['count'][] = intval($data['count']);
            $chart_values['amount'][] = floatval($data['total_amount']);
            $chart_colors_index = $index % count($chart_colors);
            $chart_colors_final[] = $chart_colors[$chart_colors_index];
        }
        break;
        
    case 'payouts':
        $status_colors = [
            'completed' => '#10B981',
            'pending' => '#F59E0B',
            'failed' => '#EF4444'
        ];
        
        foreach ($chart_data as $data) {
            $chart_labels[] = ucfirst($data['status']);
            $chart_values['count'][] = intval($data['count']);
            $chart_values['amount'][] = floatval($data['total_amount']);
            $chart_colors_final[] = $status_colors[$data['status']] ?? '#4F46E5';
        }
        break;
        
    case 'gym_performance':
        foreach ($chart_data as $index => $data) {
            $chart_labels[] = $data['gym_name'];
            $chart_values['revenue'][] = floatval($data['total_revenue']);
            $chart_colors_index = $index % count($chart_colors);
            $chart_colors_final[] = $chart_colors[$chart_colors_index];
        }
        break;
        
    case 'transactions':
    default:
        $type_colors = [
            'membership_purchase' => '#4F46E5',
            'schedule_booking' => '#10B981',
            'refund' => '#F59E0B',
            'withdrawal' => '#EF4444',
            'deposit' => '#8B5CF6'
        ];
        
        foreach ($chart_data as $data) {
            $chart_labels[] = ucfirst(str_replace('_', ' ', $data['transaction_type']));
            $chart_values['count'][] = intval($data['count']);
            $chart_values['amount'][] = floatval($data['total_amount']);
            $chart_colors_final[] = $type_colors[$data['transaction_type']] ?? '#4F46E5';
        }
        break;
}

// Convert to JSON for JavaScript
$chart_labels_json = json_encode($chart_labels);
$chart_values_json = json_encode($chart_values);
$chart_colors_json = isset($chart_colors_final) ? json_encode($chart_colors_final) : json_encode($chart_colors);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Reports - FlexFit Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-gray-900 text-white">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="ml-0 lg:ml-64 p-4 sm:p-6 transition-all duration-200">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold">Financial Reports</h1>
            
            <div class="flex space-x-2">
                <button id="exportPDF" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg flex items-center">
                    <i class="fas fa-file-pdf mr-2"></i> Export PDF
                </button>
                <button id="exportCSV" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg flex items-center">
                    <i class="fas fa-file-csv mr-2"></i> Export CSV
                </button>
            </div>
        </div>
        
        <?php if (isset($_SESSION['success'])): ?>
        <div class="bg-green-600 text-white p-4 rounded-lg mb-6">
            <p><?= htmlspecialchars($_SESSION['success']) ?></p>
            <?php unset($_SESSION['success']); ?>
        </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
        <div class="bg-red-600 text-white p-4 rounded-lg mb-6">
            <p><?= htmlspecialchars($_SESSION['error']) ?></p>
            <?php unset($_SESSION['error']); ?>
        </div>
        <?php endif; ?>
        
        <!-- Report Filters -->
        <div class="bg-gray-800 rounded-xl shadow-lg p-6 mb-6">
            <form action="" method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
                <div>
                    <label for="report_type" class="block text-sm font-medium text-gray-400 mb-1">Report Type</label>
                    <select id="report_type" name="report_type" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                        <option value="revenue" <?= $report_type === 'revenue' ? 'selected' : '' ?>>Revenue Report</option>
                        <option value="memberships" <?= $report_type === 'memberships' ? 'selected' : '' ?>>Membership Report</option>
                        <option value="payouts" <?= $report_type === 'payouts' ? 'selected' : '' ?>>Payouts Report</option>
                        <option value="gym_performance" <?= $report_type === 'gym_performance' ? 'selected' : '' ?>>Gym Performance</option>
                        <option value="transactions" <?= $report_type === 'transactions' ? 'selected' : '' ?>>Transactions Report</option>
                    </select>
                </div>
                
                <div>
                    <label for="date_range" class="block text-sm font-medium text-gray-400 mb-1">Date Range</label>
                    <select id="date_range" name="date_range" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                        <option value="today" <?= $date_range === 'today' ? 'selected' : '' ?>>Today</option>
                        <option value="yesterday" <?= $date_range === 'yesterday' ? 'selected' : '' ?>>Yesterday</option>
                        <option value="this_week" <?= $date_range === 'this_week' ? 'selected' : '' ?>>This Week</option>
                        <option value="last_week" <?= $date_range === 'last_week' ? 'selected' : '' ?>>Last Week</option>
                        <option value="this_month" <?= $date_range === 'this_month' ? 'selected' : '' ?>>This Month</option>
                        <option value="last_month" <?= $date_range === 'last_month' ? 'selected' : '' ?>>Last Month</option>
                        <option value="last_quarter" <?= $date_range === 'last_quarter' ? 'selected' : '' ?>>Last Quarter</option>
                        <option value="last_year" <?= $date_range === 'last_year' ? 'selected' : '' ?>>Last Year</option>
                        <option value="custom" <?= $date_range === 'custom' ? 'selected' : '' ?>>Custom Range</option>
                    </select>
                </div>
                
                <div>
                    <label for="gym_id" class="block text-sm font-medium text-gray-400 mb-1">Gym</label>
                    <select id="gym_id" name="gym_id" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                        <option value="0">All Gyms</option>
                        <?php foreach ($gyms as $gym): ?>
                            <option value="<?= $gym['gym_id'] ?>" <?= $gym_id == $gym['gym_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($gym['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div id="custom_date_container" class="<?= $date_range === 'custom' ? '' : 'hidden' ?> grid grid-cols-2 gap-2">
                    <div>
                        <label for="start_date" class="block text-sm font-medium text-gray-400 mb-1">Start Date</label>
                        <input type="text" id="start_date" name="start_date" value="<?= htmlspecialchars($start_date) ?>" class="datepicker w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500" placeholder="YYYY-MM-DD">
                    </div>
                    <div>
                        <label for="end_date" class="block text-sm font-medium text-gray-400 mb-1">End Date</label>
                        <input type="text" id="end_date" name="end_date" value="<?= htmlspecialchars($end_date) ?>" class="datepicker w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500" placeholder="YYYY-MM-DD">
                    </div>
                </div>
                
                <div class="flex items-end">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg w-full">
                        <i class="fas fa-filter mr-2"></i> Generate Report
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Report Summary -->
        <div class="bg-gray-800 rounded-xl shadow-lg p-6 mb-6">
            <h2 class="text-xl font-semibold mb-4">Report Summary</h2>
            <div class="text-sm text-gray-400 mb-4">
                <p>Period: <?= formatDate($start_date) ?> to <?= formatDate($end_date) ?></p>
                <?php if ($gym_id > 0): ?>
                    <?php foreach ($gyms as $gym): ?>
                        <?php if ($gym['gym_id'] == $gym_id): ?>
                            <p>Gym: <?= htmlspecialchars($gym['name']) ?></p>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p>Gym: All Gyms</p>
                <?php endif; ?>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <?php if ($report_type === 'revenue'): ?>
                    <div class="bg-gray-700 rounded-lg p-4">
                        <p class="text-gray-400 text-sm">Total Revenue</p>
                        <h3 class="text-2xl font-bold"><?= formatCurrency($summary['total_revenue'] ?? 0) ?></h3>
                    </div>
                    <div class="bg-gray-700 rounded-lg p-4">
                        <p class="text-gray-400 text-sm">Admin Revenue</p>
                        <h3 class="text-2xl font-bold"><?= formatCurrency($summary['admin_revenue'] ?? 0) ?></h3>
                    </div>
                    <div class="bg-gray-700 rounded-lg p-4">
                        <p class="text-gray-400 text-sm">Gym Revenue</p>
                        <h3 class="text-2xl font-bold"><?= formatCurrency($summary['gym_revenue'] ?? 0) ?></h3>
                    </div>
                    <div class="bg-gray-700 rounded-lg p-4">
                        <p class="text-gray-400 text-sm">Active Gyms</p>
                        <h3 class="text-2xl font-bold"><?= number_format($summary['total_gyms'] ?? 0) ?></h3>
                    </div>
                <?php elseif ($report_type === 'memberships'): ?>
                    <div class="bg-gray-700 rounded-lg p-4">
                        <p class="text-gray-400 text-sm">Total Memberships</p>
                        <h3 class="text-2xl font-bold"><?= number_format($summary['total_memberships'] ?? 0) ?></h3>
                    </div>
                    <div class="bg-gray-700 rounded-lg p-4">
                        <p class="text-gray-400 text-sm">Total Paid</p>
                        <h3 class="text-2xl font-bold"><?= formatCurrency($summary['total_paid'] ?? 0) ?></h3>
                    </div>
                    <div class="bg-gray-700 rounded-lg p-4">
                        <p class="text-gray-400 text-sm">Total Pending</p>
                        <h3 class="text-2xl font-bold"><?= formatCurrency($summary['total_pending'] ?? 0) ?></h3>
                    </div>
                    <div class="bg-gray-700 rounded-lg p-4">
                        <p class="text-gray-400 text-sm">Unique Members</p>
                        <h3 class="text-2xl font-bold"><?= number_format($summary['total_members'] ?? 0) ?></h3>
                    </div>
                <?php elseif ($report_type === 'payouts'): ?>
                    <div class="bg-gray-700 rounded-lg p-4">
                        <p class="text-gray-400 text-sm">Total Payouts</p>
                        <h3 class="text-2xl font-bold"><?= number_format($summary['total_payouts'] ?? 0) ?></h3>
                    </div>
                    <div class="bg-gray-700 rounded-lg p-4">
                        <p class="text-gray-400 text-sm">Completed Payouts</p>
                        <h3 class="text-2xl font-bold"><?= formatCurrency($summary['total_completed'] ?? 0) ?></h3>
                    </div>
                    <div class="bg-gray-700 rounded-lg p-4">
                        <p class="text-gray-400 text-sm">Pending Payouts</p>
                        <h3 class="text-2xl font-bold"><?= formatCurrency($summary['total_pending'] ?? 0) ?></h3>
                    </div>
                    <div class="bg-gray-700 rounded-lg p-4">
                        <p class="text-gray-400 text-sm">Failed Payouts</p>
                        <h3 class="text-2xl font-bold"><?= formatCurrency($summary['total_failed'] ?? 0) ?></h3>
                    </div>
                <?php elseif ($report_type === 'gym_performance'): ?>
                    <div class="bg-gray-700 rounded-lg p-4">
                        <p class="text-gray-400 text-sm">Total Gyms</p>
                        <h3 class="text-2xl font-bold"><?= number_format($summary['total_gyms'] ?? 0) ?></h3>
                    </div>
                    <div class="bg-gray-700 rounded-lg p-4">
                        <p class="text-gray-400 text-sm">Total Revenue</p>
                        <h3 class="text-2xl font-bold"><?= formatCurrency($summary['total_revenue'] ?? 0) ?></h3>
                    </div>
                    <div class="bg-gray-700 rounded-lg p-4">
                        <p class="text-gray-400 text-sm">Admin Revenue</p>
                        <h3 class="text-2xl font-bold"><?= formatCurrency($summary['admin_revenue'] ?? 0) ?></h3>
                    </div>
                    <div class="bg-gray-700 rounded-lg p-4">
                        <p class="text-gray-400 text-sm">Total Members</p>
                        <h3 class="text-2xl font-bold"><?= number_format($summary['total_members'] ?? 0) ?></h3>
                    </div>
                <?php else: ?>
                    <div class="bg-gray-700 rounded-lg p-4">
                        <p class="text-gray-400 text-sm">Total Transactions</p>
                        <h3 class="text-2xl font-bold"><?= number_format($summary['total_transactions'] ?? 0) ?></h3>
                    </div>
                    <div class="bg-gray-700 rounded-lg p-4">
                        <p class="text-gray-400 text-sm">Completed Amount</p>
                        <h3 class="text-2xl font-bold"><?= formatCurrency($summary['total_completed'] ?? 0) ?></h3>
                    </div>
                    <div class="bg-gray-700 rounded-lg p-4">
                        <p class="text-gray-400 text-sm">Pending Amount</p>
                        <h3 class="text-2xl font-bold"><?= formatCurrency($summary['total_pending'] ?? 0) ?></h3>
                    </div>
                    <div class="bg-gray-700 rounded-lg p-4">
                        <p class="text-gray-400 text-sm">Failed Amount</p>
                        <h3 class="text-2xl font-bold"><?= formatCurrency($summary['total_failed'] ?? 0) ?></h3>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Chart Section -->
        <div class="bg-gray-800 rounded-xl shadow-lg p-6 mb-6">
            <h2 class="text-xl font-semibold mb-4">
                <?php
                switch ($report_type) {
                    case 'revenue':
                        echo 'Revenue Trends';
                        break;
                    case 'memberships':
                        echo 'Membership Distribution';
                        break;
                    case 'payouts':
                        echo 'Payout Status';
                        break;
                    case 'gym_performance':
                        echo 'Top Performing Gyms';
                        break;
                    case 'transactions':
                    default:
                        echo 'Transaction Types';
                        break;
                }
                ?>
            </h2>
            
            <div class="w-full h-80">
                <canvas id="reportChart"></canvas>
            </div>
        </div>
        
        <!-- Detailed Report -->
        <div class="bg-gray-800 rounded-xl shadow-lg overflow-hidden">
            <div class="p-6 border-b border-gray-700">
                <h2 class="text-xl font-semibold">Detailed Report</h2>
            </div>
            
            <?php if (empty($report_data)): ?>
                <div class="p-6 text-center text-gray-500">
                    <i class="fas fa-chart-line text-4xl mb-3"></i>
                    <p>No data found for the selected criteria.</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-700">
                        <thead>
                            <tr class="bg-gray-700">
                                <?php if ($report_type === 'revenue'): ?>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Gym</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Source</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Gym Revenue</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Admin Revenue</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Total Amount</th>
                                    <?php elseif ($report_type === 'memberships'): ?>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Member</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Gym</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Plan</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Duration</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Start Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">End Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Amount</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Status</th>
                                <?php elseif ($report_type === 'payouts'): ?>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">ID</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Gym</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Owner</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Amount</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Requested On</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Processed On</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Transaction ID</th>
                                <?php elseif ($report_type === 'gym_performance'): ?>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Gym</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Location</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Members</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Schedules</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Total Revenue</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Admin Revenue</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Rating</th>
                                <?php else: ?>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">ID</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">User</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Gym</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Type</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Amount</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Payment Method</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody class="bg-gray-800 divide-y divide-gray-700">
                            <?php foreach ($report_data as $row): ?>
                                <tr class="hover:bg-gray-700">
                                    <?php if ($report_type === 'revenue'): ?>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm"><?= formatDate($row['date']) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm"><?= htmlspecialchars($row['gym_name']) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                                            <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-900 text-blue-300">
                                                <?= ucfirst(str_replace('_', ' ', $row['source_type'])) ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm"><?= formatCurrency($row['total_amount']) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm"><?= formatCurrency($row['admin_revenue']) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm"><?= formatCurrency($row['gym_revenue']) ?></td>
                                    <?php elseif ($report_type === 'memberships'): ?>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm"><?= htmlspecialchars($row['member_name']) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm"><?= htmlspecialchars($row['gym_name']) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm"><?= htmlspecialchars($row['plan_name']) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm"><?= htmlspecialchars($row['duration']) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm"><?= formatDate($row['start_date']) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm"><?= formatDate($row['end_date']) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm"><?= formatCurrency($row['amount']) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                                            <?php
                                            $statusClass = '';
                                            switch ($row['payment_status']) {
                                                case 'paid':
                                                    $statusClass = 'bg-green-900 text-green-300';
                                                    break;
                                                case 'pending':
                                                    $statusClass = 'bg-yellow-900 text-yellow-300';
                                                    break;
                                                case 'failed':
                                                    $statusClass = 'bg-red-900 text-red-300';
                                                    break;
                                                default:
                                                    $statusClass = 'bg-gray-700 text-gray-300';
                                            }
                                            ?>
                                            <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?= $statusClass ?>">
                                                <?= ucfirst($row['payment_status']) ?>
                                            </span>
                                        </td>
                                    <?php elseif ($report_type === 'payouts'): ?>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">#<?= $row['id'] ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm"><?= htmlspecialchars($row['gym_name']) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                                            <div><?= htmlspecialchars($row['owner_name']) ?></div>
                                            <div class="text-xs text-gray-500"><?= htmlspecialchars($row['owner_email']) ?></div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium"><?= formatCurrency($row['amount']) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                                            <?php
                                            $statusClass = '';
                                            switch ($row['status']) {
                                                case 'completed':
                                                    $statusClass = 'bg-green-900 text-green-300';
                                                    break;
                                                case 'pending':
                                                    $statusClass = 'bg-yellow-900 text-yellow-300';
                                                    break;
                                                case 'failed':
                                                    $statusClass = 'bg-red-900 text-red-300';
                                                    break;
                                                default:
                                                    $statusClass = 'bg-gray-700 text-gray-300';
                                            }
                                            ?>
                                            <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?= $statusClass ?>">
                                                <?= ucfirst($row['status']) ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm"><?= formatDateTime($row['created_at']) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                                            <?= $row['processed_at'] ? formatDateTime($row['processed_at']) : 'N/A' ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                                            <?= $row['transaction_id'] ? htmlspecialchars($row['transaction_id']) : 'N/A' ?>
                                        </td>
                                    <?php elseif ($report_type === 'gym_performance'): ?>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium"><?= htmlspecialchars($row['gym_name']) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm"><?= htmlspecialchars($row['city'] . ', ' . $row['state']) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                                            <?php
                                            $statusClass = '';
                                            switch ($row['status']) {
                                                case 'active':
                                                    $statusClass = 'bg-green-900 text-green-300';
                                                    break;
                                                case 'inactive':
                                                    $statusClass = 'bg-yellow-900 text-yellow-300';
                                                    break;
                                                case 'pending':
                                                    $statusClass = 'bg-blue-900 text-blue-300';
                                                    break;
                                                case 'suspended':
                                                    $statusClass = 'bg-red-900 text-red-300';
                                                    break;
                                                default:
                                                    $statusClass = 'bg-gray-700 text-gray-300';
                                            }
                                            ?>
                                            <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?= $statusClass ?>">
                                                <?= ucfirst($row['status']) ?>
                                            </span>
                                            <?php if ($row['is_featured']): ?>
                                                <span class="ml-1 px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-900 text-yellow-300">
                                                    Featured
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm"><?= number_format($row['total_members']) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm"><?= number_format($row['total_schedules']) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium"><?= formatCurrency($row['total_revenue']) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm"><?= formatCurrency($row['admin_revenue']) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                                            <?php if ($row['avg_rating']): ?>
                                                <div class="flex items-center">
                                                    <?= number_format($row['avg_rating'], 1) ?>
                                                    <i class="fas fa-star text-yellow-500 ml-1"></i>
                                                    <span class="text-xs text-gray-500 ml-1">(<?= $row['review_count'] ?>)</span>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-gray-500">No ratings</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php else: ?>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">#<?= $row['id'] ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                                            <div><?= htmlspecialchars($row['user_name']) ?></div>
                                            <div class="text-xs text-gray-500"><?= htmlspecialchars($row['user_email']) ?></div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm"><?= htmlspecialchars($row['gym_name']) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                                            <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-900 text-blue-300">
                                                <?= ucfirst(str_replace('_', ' ', $row['transaction_type'])) ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium"><?= formatCurrency($row['amount']) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                                            <?php
                                            $statusClass = '';
                                            switch ($row['status']) {
                                                case 'completed':
                                                    $statusClass = 'bg-green-900 text-green-300';
                                                    break;
                                                case 'pending':
                                                    $statusClass = 'bg-yellow-900 text-yellow-300';
                                                    break;
                                                case 'failed':
                                                    $statusClass = 'bg-red-900 text-red-300';
                                                    break;
                                                default:
                                                    $statusClass = 'bg-gray-700 text-gray-300';
                                            }
                                            ?>
                                            <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?= $statusClass ?>">
                                                <?= ucfirst($row['status']) ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm"><?= formatDateTime($row['transaction_date']) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm"><?= htmlspecialchars($row['payment_method'] ?? 'N/A') ?></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        // Initialize date pickers
        flatpickr('.datepicker', {
            dateFormat: 'Y-m-d',
            altInput: true,
            altFormat: 'F j, Y',
            theme: 'dark'
        });
        
        // Show/hide custom date range
        document.getElementById('date_range').addEventListener('change', function() {
            const customDateContainer = document.getElementById('custom_date_container');
            if (this.value === 'custom') {
                customDateContainer.classList.remove('hidden');
            } else {
                customDateContainer.classList.add('hidden');
            }
        });
        
        // Initialize chart
        const ctx = document.getElementById('reportChart').getContext('2d');
        const reportType = '<?= $report_type ?>';
        const chartLabels = <?= $chart_labels_json ?>;
        const chartValues = <?= $chart_values_json ?>;
        const chartColors = <?= $chart_colors_json ?>;
        
        let chartConfig = {};
        
        switch (reportType) {
            case 'revenue':
                chartConfig = {
                    type: 'line',
                    data: {
                        labels: chartLabels,
                        datasets: [
                            {
                                label: 'Total Revenue',
                                data: chartValues.total || [],
                                borderColor: '#4F46E5',
                                backgroundColor: 'rgba(79, 70, 229, 0.1)',
                                borderWidth: 2,
                                fill: true,
                                tension: 0.4
                            },
                            {
                                label: 'Admin Revenue',
                                data: chartValues.admin || [],
                                borderColor: '#10B981',
                                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                                borderWidth: 2,
                                fill: true,
                                tension: 0.4
                            },
                            {
                                label: 'Gym Revenue',
                                data: chartValues.gym || [],
                                borderColor: '#F59E0B',
                                backgroundColor: 'rgba(245, 158, 11, 0.1)',
                                borderWidth: 2,
                                fill: true,
                                tension: 0.4
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
                                    color: '#E5E7EB'
                                }
                            },
                            tooltip: {
                                mode: 'index',
                                intersect: false
                            }
                        },
                        scales: {
                            x: {
                                grid: {
                                    color: 'rgba(75, 85, 99, 0.2)'
                                },
                                ticks: {
                                    color: '#9CA3AF'
                                }
                            },
                            y: {
                                grid: {
                                    color: 'rgba(75, 85, 99, 0.2)'
                                },
                                ticks: {
                                    color: '#9CA3AF',
                                    callback: function(value) {
                                        return '₹' + value.toLocaleString();
                                    }
                                }
                            }
                        }
                    }
                };
                break;
                
            case 'memberships':
                chartConfig = {
                    type: 'bar',
                    data: {
                        labels: chartLabels,
                        datasets: [
                            {
                                label: 'Number of Memberships',
                                data: chartValues.count || [],
                                backgroundColor: chartColors,
                                borderWidth: 0
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
                                    color: '#E5E7EB'
                                }
                            }
                        },
                        scales: {
                            x: {
                                grid: {
                                    color: 'rgba(75, 85, 99, 0.2)'
                                },
                                ticks: {
                                    color: '#9CA3AF'
                                }
                            },
                            y: {
                                grid: {
                                    color: 'rgba(75, 85, 99, 0.2)'
                                },
                                ticks: {
                                    color: '#9CA3AF',
                                    stepSize: 1
                                }
                            }
                        }
                    }
                };
                break;
                
            case 'payouts':
                chartConfig = {
                    type: 'doughnut',
                    data: {
                        labels: chartLabels,
                        datasets: [
                            {
                                data: chartValues.amount || [],
                                backgroundColor: chartColors,
                                borderWidth: 0
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'right',
                                labels: {
                                    color: '#E5E7EB'
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const label = context.label || '';
                                        const value = context.raw || 0;
                                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                        const percentage = Math.round((value / total) * 100);
                                        return `${label}: ₹${value.toLocaleString()} (${percentage}%)`;
                                    }
                                }
                            }
                        }
                    }
                };
                break;
                
            case 'gym_performance':
                chartConfig = {
                    type: 'bar',
                    data: {
                        labels: chartLabels,
                        datasets: [
                            {
                                label: 'Revenue',
                                data: chartValues.revenue || [],
                                backgroundColor: chartColors,
                                borderWidth: 0
                            }
                        ]
                    },
                    options: {
                        indexAxis: 'y',
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'top',
                                labels: {
                                    color: '#E5E7EB'
                                }
                            }
                        },
                        scales: {
                            x: {
                                grid: {
                                    color: 'rgba(75, 85, 99, 0.2)'
                                },
                                ticks: {
                                    color: '#9CA3AF',
                                    callback: function(value) {
                                        return '₹' + value.toLocaleString();
                                    }
                                }
                            },
                            y: {
                                grid: {
                                    color: 'rgba(75, 85, 99, 0.2)'
                                },
                                ticks: {
                                    color: '#9CA3AF'
                                }
                            }
                        }
                    }
                };
                break;
                
            case 'transactions':
            default:
                chartConfig = {
                    type: 'pie',
                    data: {
                        labels: chartLabels,
                        datasets: [
                            {
                                data: chartValues.amount || [],
                                backgroundColor: chartColors,
                                borderWidth: 0
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'right',
                                labels: {
                                    color: '#E5E7EB'
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const label = context.label || '';
                                        const value = context.raw || 0;
                                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                        const percentage = Math.round((value / total) * 100);
                                        return `${label}: ₹${value.toLocaleString()} (${percentage}%)`;
                                    }
                                }
                            }
                        }
                    }
                };
                break;
        }
        
        const reportChart = new Chart(ctx, chartConfig);
        
        // Export to CSV
        document.getElementById('exportCSV').addEventListener('click', function() {
            const reportType = '<?= $report_type ?>';
            const startDate = '<?= $start_date ?>';
            const endDate = '<?= $end_date ?>';
            const gymId = '<?= $gym_id ?>';
            
            window.location.href = `export_report.php?format=csv&report_type=${reportType}&start_date=${startDate}&end_date=${endDate}&gym_id=${gymId}`;
        });
        
        // Export to PDF
        document.getElementById('exportPDF').addEventListener('click', function() {
            const reportType = '<?= $report_type ?>';
            const startDate = '<?= $start_date ?>';
            const endDate = '<?= $end_date ?>';
            const gymId = '<?= $gym_id ?>';
            
            window.location.href = `export_report.php?format=pdf&report_type=${reportType}&start_date=${startDate}&end_date=${endDate}&gym_id=${gymId}`;
        });
    </script>
</body>
</html>




