<?php
ob_start();
include '../includes/navbar.php';

if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

require_once '../config/database.php';
$db = new GymDatabase();
$conn = $db->getConnection();

// Initialize variables
$report_type = isset($_GET['type']) ? $_GET['type'] : 'revenue';
$date_range = isset($_GET['date_range']) ? $_GET['date_range'] : 'this_month';
$start_date = '';
$end_date = '';
$gym_id = isset($_GET['gym_id']) ? intval($_GET['gym_id']) : 0;
$tier = isset($_GET['tier']) ? $_GET['tier'] : '';

// Process date range
if ($date_range) {
    switch ($date_range) {
        case 'today':
            $start_date = date('Y-m-d');
            $end_date = date('Y-m-d');
            break;
        case 'yesterday':
            $start_date = date('Y-m-d', strtotime('-1 day'));
            $end_date = date('Y-m-d', strtotime('-1 day'));
            break;
        case 'this_week':
            $start_date = date('Y-m-d', strtotime('monday this week'));
            $end_date = date('Y-m-d', strtotime('sunday this week'));
            break;
        case 'last_week':
            $start_date = date('Y-m-d', strtotime('monday last week'));
            $end_date = date('Y-m-d', strtotime('sunday last week'));
            break;
        case 'this_month':
            $start_date = date('Y-m-01');
            $end_date = date('Y-m-t');
            break;
        case 'last_month':
            $start_date = date('Y-m-01', strtotime('first day of last month'));
            $end_date = date('Y-m-t', strtotime('last day of last month'));
            break;
        case 'this_year':
            $start_date = date('Y-01-01');
            $end_date = date('Y-12-31');
            break;
        case 'last_year':
            $start_date = date('Y-01-01', strtotime('-1 year'));
            $end_date = date('Y-12-31', strtotime('-1 year'));
            break;
        case 'custom':
            $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
            $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';
            break;
    }
}

// If custom dates are not set properly, default to this month
if ($date_range === 'custom' && (empty($start_date) || empty($end_date))) {
    $start_date = date('Y-m-01');
    $end_date = date('Y-m-t');
}

// Get all gyms for filter
$stmt = $conn->query("SELECT gym_id, name FROM gyms ORDER BY name");
$gyms = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Generate report based on type
$report_data = [];
$chart_data = [];

switch ($report_type) {
    case 'revenue':
        // Revenue report
        generateRevenueReport($conn, $start_date, $end_date, $gym_id, $tier, $report_data, $chart_data);
        break;
    
    case 'bookings':
        // Bookings report
        generateBookingsReport($conn, $start_date, $end_date, $gym_id, $report_data, $chart_data);
        break;
    
    case 'memberships':
        // Memberships report
        generateMembershipsReport($conn, $start_date, $end_date, $gym_id, $tier, $report_data, $chart_data);
        break;
    
    case 'users':
        // Users report
        generateUsersReport($conn, $start_date, $end_date, $report_data, $chart_data);
        break;
    
    case 'gyms':
        // Gyms report
        generateGymsReport($conn, $start_date, $end_date, $gym_id, $report_data, $chart_data);
        break;
}

// Function to generate revenue report
function generateRevenueReport($conn, $start_date, $end_date, $gym_id, $tier, &$report_data, &$chart_data) {
    // Base query
    $query = "
        SELECT 
            DATE(gr.date) as date,
            SUM(gr.amount) as total_revenue,
            SUM(gr.admin_cut) as admin_revenue,
            COUNT(gr.id) as transaction_count
        FROM gym_revenue gr
        WHERE gr.date BETWEEN :start_date AND :end_date
    ";
    
    $params = [
        ':start_date' => $start_date,
        ':end_date' => $end_date
    ];
    
    // Add gym filter if specified
    if ($gym_id) {
        $query .= " AND gr.gym_id = :gym_id";
        $params[':gym_id'] = $gym_id;
    }
    
    // Add tier filter if specified
    if ($tier) {
        $query .= " 
            AND gr.schedule_id IN (
                SELECT s.id 
                FROM schedules s
                JOIN user_memberships um ON s.membership_id = um.id
                JOIN gym_membership_plans gmp ON um.plan_id = gmp.plan_id
                WHERE gmp.tier = :tier
            )
        ";
        $params[':tier'] = $tier;
    }
    
    // Group by date
    $query .= " GROUP BY DATE(gr.date) ORDER BY date";
    
    $stmt = $conn->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $daily_revenue = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Summary statistics
    $summary_query = "
        SELECT 
            SUM(gr.amount) as total_revenue,
            SUM(gr.admin_cut) as admin_revenue,
            COUNT(gr.id) as transaction_count,
            COUNT(DISTINCT gr.gym_id) as gym_count,
            COUNT(DISTINCT gr.user_id) as user_count,
            AVG(gr.admin_cut) as avg_admin_revenue_per_transaction
        FROM gym_revenue gr
        WHERE gr.date BETWEEN :start_date AND :end_date
    ";
    
    // Add filters
    if ($gym_id) {
        $summary_query .= " AND gr.gym_id = :gym_id";
    }
    
    if ($tier) {
        $summary_query .= " 
            AND gr.schedule_id IN (
                SELECT s.id 
                FROM schedules s
                JOIN user_memberships um ON s.membership_id = um.id
                JOIN gym_membership_plans gmp ON um.plan_id = gmp.plan_id
                WHERE gmp.tier = :tier
            )
        ";
    }
    
    $stmt = $conn->prepare($summary_query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Revenue by gym
    $gym_query = "
        SELECT 
            g.name as gym_name,
            SUM(gr.amount) as total_revenue,
            SUM(gr.admin_cut) as admin_revenue,
            COUNT(gr.id) as transaction_count
        FROM gym_revenue gr
        JOIN gyms g ON gr.gym_id = g.gym_id
        WHERE gr.date BETWEEN :start_date AND :end_date
    ";
    
    // Add filters
    if ($gym_id) {
        $gym_query .= " AND gr.gym_id = :gym_id";
    }
    
    if ($tier) {
        $gym_query .= " 
            AND gr.schedule_id IN (
                SELECT s.id 
                FROM schedules s
                JOIN user_memberships um ON s.membership_id = um.id
                JOIN gym_membership_plans gmp ON um.plan_id = gmp.plan_id
                WHERE gmp.tier = :tier
            )
        ";
    }
    
    $gym_query .= " GROUP BY gr.gym_id ORDER BY admin_revenue DESC";
    
    $stmt = $conn->prepare($gym_query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $gym_revenue = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Revenue by tier
    $tier_query = "
        SELECT 
            gmp.tier,
            SUM(gr.amount) as total_revenue,
            SUM(gr.admin_cut) as admin_revenue,
            COUNT(gr.id) as transaction_count
        FROM gym_revenue gr
        JOIN schedules s ON gr.schedule_id = s.id
        JOIN user_memberships um ON s.membership_id = um.id
        JOIN gym_membership_plans gmp ON um.plan_id = gmp.plan_id
        WHERE gr.date BETWEEN :start_date AND :end_date
    ";
    
    // Add gym filter if specified
    if ($gym_id) {
        $tier_query .= " AND gr.gym_id = :gym_id";
    }
    
    // Add tier filter if specified
    if ($tier) {
        $tier_query .= " AND gmp.tier = :tier";
    }
    
    $tier_query .= " GROUP BY gmp.tier ORDER BY admin_revenue DESC";
    
    $stmt = $conn->prepare($tier_query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $tier_revenue = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Prepare chart data for daily revenue
    $dates = [];
    $admin_revenue = [];
    $total_revenue = [];
    
    foreach ($daily_revenue as $day) {
        $dates[] = date('M d', strtotime($day['date']));
        $admin_revenue[] = round($day['admin_revenue'], 2);
        $total_revenue[] = round($day['total_revenue'], 2);
    }
    
    $chart_data = [
        'labels' => $dates,
        'datasets' => [
            [
                'label' => 'Admin Revenue',
                'data' => $admin_revenue,
                'backgroundColor' => 'rgba(79, 70, 229, 0.2)',
                'borderColor' => 'rgba(79, 70, 229, 1)',
                'borderWidth' => 2,
                'tension' => 0.4
            ],
            [
                'label' => 'Total Revenue',
                'data' => $total_revenue,
                'backgroundColor' => 'rgba(16, 185, 129, 0.2)',
                'borderColor' => 'rgba(16, 185, 129, 1)',
                'borderWidth' => 2,
                'tension' => 0.4
            ]
        ]
    ];
    
    // Prepare pie chart data for revenue by tier
    $tier_labels = [];
    $tier_values = [];
    $tier_colors = [
        'Tier 1' => 'rgba(59, 130, 246, 0.8)',
        'Tier 2' => 'rgba(139, 92, 246, 0.8)',
        'Tier 3' => 'rgba(236, 72, 153, 0.8)'
    ];
    $tier_chart_colors = [];
    
    foreach ($tier_revenue as $tr) {
        $tier_labels[] = $tr['tier'];
        $tier_values[] = round($tr['admin_revenue'], 2);
        $tier_chart_colors[] = $tier_colors[$tr['tier']] ?? 'rgba(107, 114, 128, 0.8)';
    }
    
    $tier_chart_data = [
        'labels' => $tier_labels,
        'datasets' => [
            [
                'data' => $tier_values,
                'backgroundColor' => $tier_chart_colors,
                'borderWidth' => 1
            ]
        ]
    ];
    
    // Prepare report data
    $report_data = [
        'summary' => $summary,
        'daily_revenue' => $daily_revenue,
        'gym_revenue' => $gym_revenue,
        'tier_revenue' => $tier_revenue,
        'tier_chart_data' => $tier_chart_data
    ];
}

// Function to generate bookings report
function generateBookingsReport($conn, $start_date, $end_date, $gym_id, &$report_data, &$chart_data) {
    // Base query
    $query = "
        SELECT 
            DATE(s.start_date) as date,
            COUNT(*) as booking_count,
            SUM(CASE WHEN s.status = 'completed' THEN 1 ELSE 0 END) as completed_count,
            SUM(CASE WHEN s.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count,
            SUM(CASE WHEN s.status = 'missed' THEN 1 ELSE 0 END) as missed_count,
            SUM(CASE WHEN s.status = 'scheduled' THEN 1 ELSE 0 END) as scheduled_count
        FROM schedules s
        WHERE s.start_date BETWEEN :start_date AND :end_date
    ";
    
    $params = [
        ':start_date' => $start_date,
        ':end_date' => $end_date
    ];
    
    // Add gym filter if specified
    if ($gym_id) {
        $query .= " AND s.gym_id = :gym_id";
        $params[':gym_id'] = $gym_id;
    }
    
    // Group by date
    $query .= " GROUP BY DATE(s.start_date) ORDER BY date";
    
    $stmt = $conn->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $daily_bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Summary statistics
    $summary_query = "
        SELECT 
            COUNT(*) as total_bookings,
            SUM(CASE WHEN s.status = 'completed' THEN 1 ELSE 0 END) as completed_count,
            SUM(CASE WHEN s.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count,
            SUM(CASE WHEN s.status = 'missed' THEN 1 ELSE 0 END) as missed_count,
                        SUM(CASE WHEN s.status = 'scheduled' THEN 1 ELSE 0 END) as scheduled_count,
            COUNT(DISTINCT s.user_id) as unique_users,
            COUNT(DISTINCT s.gym_id) as unique_gyms
        FROM schedules s
        WHERE s.start_date BETWEEN :start_date AND :end_date
    ";
    
    // Add filters
    if ($gym_id) {
        $summary_query .= " AND s.gym_id = :gym_id";
    }
    
    $stmt = $conn->prepare($summary_query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Bookings by gym
    $gym_query = "
        SELECT 
            g.name as gym_name,
            COUNT(*) as booking_count,
            SUM(CASE WHEN s.status = 'completed' THEN 1 ELSE 0 END) as completed_count,
            SUM(CASE WHEN s.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count,
            SUM(CASE WHEN s.status = 'missed' THEN 1 ELSE 0 END) as missed_count,
            SUM(CASE WHEN s.status = 'scheduled' THEN 1 ELSE 0 END) as scheduled_count,
            COUNT(DISTINCT s.user_id) as unique_users
        FROM schedules s
        JOIN gyms g ON s.gym_id = g.gym_id
        WHERE s.start_date BETWEEN :start_date AND :end_date
    ";
    
    // Add filters
    if ($gym_id) {
        $gym_query .= " AND s.gym_id = :gym_id";
    }
    
    $gym_query .= " GROUP BY s.gym_id ORDER BY booking_count DESC";
    
    $stmt = $conn->prepare($gym_query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $gym_bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Bookings by activity type
    $activity_query = "
        SELECT 
            s.activity_type,
            COUNT(*) as booking_count,
            SUM(CASE WHEN s.status = 'completed' THEN 1 ELSE 0 END) as completed_count,
            SUM(CASE WHEN s.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count,
            SUM(CASE WHEN s.status = 'missed' THEN 1 ELSE 0 END) as missed_count,
            SUM(CASE WHEN s.status = 'scheduled' THEN 1 ELSE 0 END) as scheduled_count
        FROM schedules s
        WHERE s.start_date BETWEEN :start_date AND :end_date
    ";
    
    // Add gym filter if specified
    if ($gym_id) {
        $activity_query .= " AND s.gym_id = :gym_id";
    }
    
    $activity_query .= " GROUP BY s.activity_type ORDER BY booking_count DESC";
    
    $stmt = $conn->prepare($activity_query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $activity_bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Prepare chart data for daily bookings
    $dates = [];
    $booking_counts = [];
    $completed_counts = [];
    $cancelled_counts = [];
    
    foreach ($daily_bookings as $day) {
        $dates[] = date('M d', strtotime($day['date']));
        $booking_counts[] = intval($day['booking_count']);
        $completed_counts[] = intval($day['completed_count']);
        $cancelled_counts[] = intval($day['cancelled_count']);
    }
    
    $chart_data = [
        'labels' => $dates,
        'datasets' => [
            [
                'label' => 'Total Bookings',
                'data' => $booking_counts,
                'backgroundColor' => 'rgba(79, 70, 229, 0.2)',
                'borderColor' => 'rgba(79, 70, 229, 1)',
                'borderWidth' => 2,
                'tension' => 0.4
            ],
            [
                'label' => 'Completed',
                'data' => $completed_counts,
                'backgroundColor' => 'rgba(16, 185, 129, 0.2)',
                'borderColor' => 'rgba(16, 185, 129, 1)',
                'borderWidth' => 2,
                'tension' => 0.4
            ],
            [
                'label' => 'Cancelled',
                'data' => $cancelled_counts,
                'backgroundColor' => 'rgba(239, 68, 68, 0.2)',
                'borderColor' => 'rgba(239, 68, 68, 1)',
                'borderWidth' => 2,
                'tension' => 0.4
            ]
        ]
    ];
    
    // Prepare pie chart data for bookings by activity type
    $activity_labels = [];
    $activity_values = [];
    $activity_colors = [
        'gym_visit' => 'rgba(59, 130, 246, 0.8)',
        'class' => 'rgba(139, 92, 246, 0.8)',
        'personal_training' => 'rgba(236, 72, 153, 0.8)'
    ];
    $activity_chart_colors = [];
    
    foreach ($activity_bookings as $ab) {
        $activity_type = $ab['activity_type'] ?: 'Unknown';
        $activity_labels[] = ucfirst(str_replace('_', ' ', $activity_type));
        $activity_values[] = intval($ab['booking_count']);
        $activity_chart_colors[] = $activity_colors[$ab['activity_type']] ?? 'rgba(107, 114, 128, 0.8)';
    }
    
    $activity_chart_data = [
        'labels' => $activity_labels,
        'datasets' => [
            [
                'data' => $activity_values,
                'backgroundColor' => $activity_chart_colors,
                'borderWidth' => 1
            ]
        ]
    ];
    
    // Prepare report data
    $report_data = [
        'summary' => $summary,
        'daily_bookings' => $daily_bookings,
        'gym_bookings' => $gym_bookings,
        'activity_bookings' => $activity_bookings,
        'activity_chart_data' => $activity_chart_data
    ];
}

// Function to generate memberships report
function generateMembershipsReport($conn, $start_date, $end_date, $gym_id, $tier, &$report_data, &$chart_data) {
    // Base query for new memberships
    $query = "
        SELECT 
            DATE(um.created_at) as date,
            COUNT(*) as membership_count,
            SUM(um.amount) as total_revenue
        FROM user_memberships um
        WHERE um.created_at BETWEEN :start_date AND :end_date
    ";
    
    $params = [
        ':start_date' => $start_date . ' 00:00:00',
        ':end_date' => $end_date . ' 23:59:59'
    ];
    
    // Add gym filter if specified
    if ($gym_id) {
        $query .= " AND um.gym_id = :gym_id";
        $params[':gym_id'] = $gym_id;
    }
    
    // Add tier filter if specified
    if ($tier) {
        $query .= " 
            AND um.plan_id IN (
                SELECT plan_id FROM gym_membership_plans WHERE tier = :tier
            )
        ";
        $params[':tier'] = $tier;
    }
    
    // Group by date
    $query .= " GROUP BY DATE(um.created_at) ORDER BY date";
    
    $stmt = $conn->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $daily_memberships = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Summary statistics
    $summary_query = "
        SELECT 
            COUNT(*) as total_memberships,
            SUM(um.amount) as total_revenue,
            COUNT(DISTINCT um.user_id) as unique_users,
            COUNT(DISTINCT um.gym_id) as unique_gyms,
            AVG(um.amount) as avg_membership_price,
            SUM(CASE WHEN um.status = 'active' THEN 1 ELSE 0 END) as active_memberships,
            SUM(CASE WHEN um.status = 'expired' THEN 1 ELSE 0 END) as expired_memberships,
            SUM(CASE WHEN um.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_memberships
        FROM user_memberships um
        WHERE um.created_at BETWEEN :start_date AND :end_date
    ";
    
    // Add filters
    if ($gym_id) {
        $summary_query .= " AND um.gym_id = :gym_id";
    }
    
    if ($tier) {
        $summary_query .= " 
            AND um.plan_id IN (
                SELECT plan_id FROM gym_membership_plans WHERE tier = :tier
            )
        ";
    }
    
    $stmt = $conn->prepare($summary_query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Memberships by gym
    $gym_query = "
        SELECT 
            g.name as gym_name,
            COUNT(*) as membership_count,
            SUM(um.amount) as total_revenue,
            COUNT(DISTINCT um.user_id) as unique_users,
            SUM(CASE WHEN um.status = 'active' THEN 1 ELSE 0 END) as active_memberships
        FROM user_memberships um
        JOIN gyms g ON um.gym_id = g.gym_id
        WHERE um.created_at BETWEEN :start_date AND :end_date
    ";
    
    // Add filters
    if ($gym_id) {
        $gym_query .= " AND um.gym_id = :gym_id";
    }
    
    if ($tier) {
        $gym_query .= " 
            AND um.plan_id IN (
                SELECT plan_id FROM gym_membership_plans WHERE tier = :tier
            )
        ";
    }
    
    $gym_query .= " GROUP BY um.gym_id ORDER BY membership_count DESC";
    
    $stmt = $conn->prepare($gym_query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $gym_memberships = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Memberships by tier
    $tier_query = "
        SELECT 
            gmp.tier,
            COUNT(*) as membership_count,
            SUM(um.amount) as total_revenue,
            COUNT(DISTINCT um.user_id) as unique_users,
            SUM(CASE WHEN um.status = 'active' THEN 1 ELSE 0 END) as active_memberships
        FROM user_memberships um
        JOIN gym_membership_plans gmp ON um.plan_id = gmp.plan_id
        WHERE um.created_at BETWEEN :start_date AND :end_date
    ";
    
    // Add gym filter if specified
    if ($gym_id) {
        $tier_query .= " AND um.gym_id = :gym_id";
    }
    
    // Add tier filter if specified
    if ($tier) {
        $tier_query .= " AND gmp.tier = :tier";
    }
    
    $tier_query .= " GROUP BY gmp.tier ORDER BY membership_count DESC";
    
    $stmt = $conn->prepare($tier_query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $tier_memberships = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Memberships by duration
    $duration_query = "
        SELECT 
            gmp.duration,
            COUNT(*) as membership_count,
            SUM(um.amount) as total_revenue,
            AVG(um.amount) as avg_price
        FROM user_memberships um
        JOIN gym_membership_plans gmp ON um.plan_id = gmp.plan_id
        WHERE um.created_at BETWEEN :start_date AND :end_date
    ";
    
    // Add gym filter if specified
    if ($gym_id) {
        $duration_query .= " AND um.gym_id = :gym_id";
    }
    
    // Add tier filter if specified
    if ($tier) {
        $duration_query .= " AND gmp.tier = :tier";
    }
    
    $duration_query .= " GROUP BY gmp.duration ORDER BY membership_count DESC";
    
    $stmt = $conn->prepare($duration_query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $duration_memberships = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Prepare chart data for daily memberships
    $dates = [];
    $membership_counts = [];
    $revenue_values = [];
    
    foreach ($daily_memberships as $day) {
        $dates[] = date('M d', strtotime($day['date']));
        $membership_counts[] = intval($day['membership_count']);
        $revenue_values[] = round(floatval($day['total_revenue']), 2);
    }
    
    $chart_data = [
        'labels' => $dates,
        'datasets' => [
            [
                'label' => 'New Memberships',
                'data' => $membership_counts,
                'backgroundColor' => 'rgba(79, 70, 229, 0.2)',
                'borderColor' => 'rgba(79, 70, 229, 1)',
                'borderWidth' => 2,
                'tension' => 0.4,
                'yAxisID' => 'y'
            ],
            [
                'label' => 'Revenue (₹)',
                'data' => $revenue_values,
                'backgroundColor' => 'rgba(245, 158, 11, 0.2)',
                'borderColor' => 'rgba(245, 158, 11, 1)',
                'borderWidth' => 2,
                'tension' => 0.4,
                'yAxisID' => 'y1'
            ]
        ]
    ];
    
        // Prepare pie chart data for memberships by tier
        $tier_labels = [];
        $tier_values = [];
        $tier_colors = [
            'Tier 1' => 'rgba(59, 130, 246, 0.8)',
            'Tier 2' => 'rgba(139, 92, 246, 0.8)',
            'Tier 3' => 'rgba(236, 72, 153, 0.8)'
        ];
        $tier_chart_colors = [];
        
        foreach ($tier_memberships as $tm) {
            $tier_labels[] = $tm['tier'];
            $tier_values[] = intval($tm['membership_count']);
            $tier_chart_colors[] = $tier_colors[$tm['tier']] ?? 'rgba(107, 114, 128, 0.8)';
        }
        
        $tier_chart_data = [
            'labels' => $tier_labels,
            'datasets' => [
                [
                    'data' => $tier_values,
                    'backgroundColor' => $tier_chart_colors,
                    'borderWidth' => 1
                ]
            ]
        ];
        
        // Prepare report data
        $report_data = [
            'summary' => $summary,
            'daily_memberships' => $daily_memberships,
            'gym_memberships' => $gym_memberships,
            'tier_memberships' => $tier_memberships,
            'duration_memberships' => $duration_memberships,
            'tier_chart_data' => $tier_chart_data
        ];
    }
    
    // Function to generate users report
    function generateUsersReport($conn, $start_date, $end_date, &$report_data, &$chart_data) {
        // Base query for new users
        $query = "
            SELECT 
                DATE(created_at) as date,
                COUNT(*) as user_count
            FROM users
            WHERE created_at BETWEEN :start_date AND :end_date
            GROUP BY DATE(created_at)
            ORDER BY date
        ";
        
        $params = [
            ':start_date' => $start_date . ' 00:00:00',
            ':end_date' => $end_date . ' 23:59:59'
        ];
        
        $stmt = $conn->prepare($query);
        $stmt->execute($params);
        $daily_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Summary statistics
        $summary_query = "
            SELECT 
                COUNT(*) as total_users,
                SUM(CASE WHEN role = 'member' THEN 1 ELSE 0 END) as member_count,
                SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) as admin_count,
                SUM(CASE WHEN role = 'gym_partner' THEN 1 ELSE 0 END) as partner_count,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_users,
                SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_users,
                SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) as suspended_users,
                COUNT(CASE WHEN created_at BETWEEN :start_date AND :end_date THEN 1 END) as new_users
            FROM users
        ";
        
        $stmt = $conn->prepare($summary_query);
        $stmt->execute($params);
        $summary = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Users by city
        $city_query = "
            SELECT 
                city,
                COUNT(*) as user_count
            FROM users
            WHERE city IS NOT NULL AND city != ''
            GROUP BY city
            ORDER BY user_count DESC
            LIMIT 10
        ";
        
        $stmt = $conn->query($city_query);
        $city_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Users with memberships
        $membership_query = "
            SELECT 
                COUNT(DISTINCT u.id) as user_count,
                COUNT(um.id) as membership_count,
                AVG(um.amount) as avg_membership_value
            FROM users u
            JOIN user_memberships um ON u.id = um.user_id
            WHERE u.status = 'active'
        ";
        
        $stmt = $conn->query($membership_query);
        $membership_stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // User activity stats
        $activity_query = "
            SELECT 
                COUNT(DISTINCT user_id) as active_users,
                COUNT(*) as total_activities,
                AVG(activities_per_user) as avg_activities_per_user
            FROM (
                SELECT 
                    user_id,
                    COUNT(*) as activities_per_user
                FROM schedules
                WHERE start_date BETWEEN :start_date AND :end_date
                GROUP BY user_id
            ) as user_activities
        ";
        
        $stmt = $conn->prepare($activity_query);
        $stmt->execute($params);
        $activity_stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Prepare chart data for daily new users
        $dates = [];
        $user_counts = [];
        
        foreach ($daily_users as $day) {
            $dates[] = date('M d', strtotime($day['date']));
            $user_counts[] = intval($day['user_count']);
        }
        
        $chart_data = [
            'labels' => $dates,
            'datasets' => [
                [
                    'label' => 'New Users',
                    'data' => $user_counts,
                    'backgroundColor' => 'rgba(79, 70, 229, 0.2)',
                    'borderColor' => 'rgba(79, 70, 229, 1)',
                    'borderWidth' => 2,
                    'tension' => 0.4
                ]
            ]
        ];
        
        // Prepare pie chart data for users by city
        $city_labels = [];
        $city_values = [];
        $city_colors = [];
        
        // Generate random colors for cities
        foreach ($city_users as $index => $cu) {
            $city_labels[] = $cu['city'] ?: 'Unknown';
            $city_values[] = intval($cu['user_count']);
            
            // Generate a color based on index
            $hue = ($index * 30) % 360;
            $city_colors[] = "hsla($hue, 70%, 60%, 0.8)";
        }
        
        $city_chart_data = [
            'labels' => $city_labels,
            'datasets' => [
                [
                    'data' => $city_values,
                    'backgroundColor' => $city_colors,
                    'borderWidth' => 1
                ]
            ]
        ];
        
        // Prepare report data
        $report_data = [
            'summary' => $summary,
            'daily_users' => $daily_users,
            'city_users' => $city_users,
            'membership_stats' => $membership_stats,
            'activity_stats' => $activity_stats,
            'city_chart_data' => $city_chart_data
        ];
    }
    
    // Function to generate gyms report
    function generateGymsReport($conn, $start_date, $end_date, $gym_id, &$report_data, &$chart_data) {
        // Base query for new gyms
        $query = "
            SELECT 
                DATE(created_at) as date,
                COUNT(*) as gym_count
            FROM gyms
            WHERE created_at BETWEEN :start_date AND :end_date
        ";
        
        $params = [
            ':start_date' => $start_date . ' 00:00:00',
            ':end_date' => $end_date . ' 23:59:59'
        ];
        
        // Add gym filter if specified
        if ($gym_id) {
            $query .= " AND gym_id = :gym_id";
            $params[':gym_id'] = $gym_id;
        }
        
        // Group by date
        $query .= " GROUP BY DATE(created_at) ORDER BY date";
        
        $stmt = $conn->prepare($query);
        $stmt->execute($params);
        $daily_gyms = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Summary statistics
        $summary_query = "
            SELECT 
                COUNT(*) as total_gyms,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_gyms,
                SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_gyms,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_gyms,
                SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) as suspended_gyms,
                COUNT(CASE WHEN created_at BETWEEN :start_date AND :end_date THEN 1 END) as new_gyms,
                AVG(rating) as avg_rating
            FROM gyms
        ";
        
        // Add gym filter if specified
        if ($gym_id) {
            $summary_query .= " WHERE gym_id = :gym_id";
        }
        
        $stmt = $conn->prepare($summary_query);
        $stmt->execute($params);
        $summary = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Gyms by city
        $city_query = "
            SELECT 
                city,
                COUNT(*) as gym_count,
                AVG(rating) as avg_rating
            FROM gyms
            WHERE city IS NOT NULL AND city != ''
        ";
        
        // Add gym filter if specified
        if ($gym_id) {
            $city_query .= " AND gym_id = :gym_id";
        }
        
        $city_query .= " GROUP BY city ORDER BY gym_count DESC LIMIT 10";
        
        $stmt = $conn->prepare($city_query);
        if ($gym_id) {
            $stmt->bindValue(':gym_id', $gym_id);
        }
        $stmt->execute();
        $city_gyms = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Gym revenue stats
        $revenue_query = "
            SELECT 
                g.gym_id,
                g.name as gym_name,
                SUM(gr.amount) as total_revenue,
                SUM(gr.admin_cut) as admin_revenue,
                COUNT(gr.id) as transaction_count
            FROM gyms g
            LEFT JOIN gym_revenue gr ON g.gym_id = gr.gym_id
            WHERE gr.date BETWEEN :start_date AND :end_date
        ";
        
        // Add gym filter if specified
        if ($gym_id) {
            $revenue_query .= " AND g.gym_id = :gym_id";
        }
        
        $revenue_query .= " GROUP BY g.gym_id ORDER BY total_revenue DESC LIMIT 10";
        
        $stmt = $conn->prepare($revenue_query);
        $stmt->execute($params);
        $revenue_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Gym booking stats
        $booking_query = "
            SELECT 
                g.gym_id,
                g.name as gym_name,
                COUNT(s.id) as booking_count,
                COUNT(DISTINCT s.user_id) as unique_users
            FROM gyms g
            LEFT JOIN schedules s ON g.gym_id = s.gym_id
            WHERE s.start_date BETWEEN :start_date AND :end_date
        ";
        
        // Add gym filter if specified
        if ($gym_id) {
            $booking_query .= " AND g.gym_id = :gym_id";
        }
        
        $booking_query .= " GROUP BY g.gym_id ORDER BY booking_count DESC LIMIT 10";
        
        $stmt = $conn->prepare($booking_query);
        $stmt->execute($params);
        $booking_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Prepare chart data for daily new gyms
        $dates = [];
        $gym_counts = [];
        
        foreach ($daily_gyms as $day) {
            $dates[] = date('M d', strtotime($day['date']));
            $gym_counts[] = intval($day['gym_count']);
        }
        
        $chart_data = [
            'labels' => $dates,
            'datasets' => [
                [
                    'label' => 'New Gyms',
                    'data' => $gym_counts,
                    'backgroundColor' => 'rgba(79, 70, 229, 0.2)',
                    'borderColor' => 'rgba(79, 70, 229, 1)',
                    'borderWidth' => 2,
                    'tension' => 0.4
                ]
            ]
        ];
        
        // Prepare bar chart data for top gyms by revenue
        $gym_names = [];
        $revenue_values = [];
        $admin_revenue_values = [];
        
        foreach ($revenue_stats as $rs) {
            $gym_names[] = $rs['gym_name'];
            $revenue_values[] = round(floatval($rs['total_revenue']), 2);
            $admin_revenue_values[] = round(floatval($rs['admin_revenue']), 2);
        }
        
        $revenue_chart_data = [
            'labels' => $gym_names,
            'datasets' => [
                [
                    'label' => 'Total Revenue',
                    'data' => $revenue_values,
                    'backgroundColor' => 'rgba(16, 185, 129, 0.7)',
                    'borderColor' => 'rgba(16, 185, 129, 1)',
                    'borderWidth' => 1
                ],
                [
                    'label' => 'Admin Revenue',
                    'data' => $admin_revenue_values,
                    'backgroundColor' => 'rgba(79, 70, 229, 0.7)',
                    'borderColor' => 'rgba(79, 70, 229, 1)',
                    'borderWidth' => 1
                ]
            ]
        ];
        
        // Prepare report data
        $report_data = [
            'summary' => $summary,
            'daily_gyms' => $daily_gyms,
            'city_gyms' => $city_gyms,
            'revenue_stats' => $revenue_stats,
            'booking_stats' => $booking_stats,
            'revenue_chart_data' => $revenue_chart_data
        ];
    }
    ?>
    
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Reports - Admin Dashboard</title>
        <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <style>
        @media print {
            .no-print {
                display: none;
            }
            .print-only {
                display: block;
            }
            body {
                background-color: white;
            }
        }
        .print-only {
            display: none;
        }
    </style>
</head>
<body class="bg-gray-100 min-h-screen flex flex-col">
    <div class="flex-grow container mx-auto px-4 py-8">
        <div class="print-only text-center mb-8">
            <h1 class="text-3xl font-bold">Fitness Hub - <?= ucfirst($report_type) ?> Report</h1>
            <p class="text-gray-600">
                <?= date('F j, Y', strtotime($start_date)) ?> to <?= date('F j, Y', strtotime($end_date)) ?>
            </p>
        </div>
        
        <!-- Header -->
        <div class="flex justify-between items-center mb-8 no-print">
            <h1 class="text-3xl font-bold text-gray-800">Reports</h1>
            <div class="flex space-x-2">
                <button onclick="window.print()" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg flex items-center">
                    <i class="fas fa-print mr-2"></i> Print Report
                </button>
                <button onclick="exportToCSV()" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg flex items-center">
                    <i class="fas fa-file-csv mr-2"></i> Export CSV
                </button>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="bg-white rounded-xl shadow-md p-6 mb-8 no-print">
            <h2 class="text-xl font-semibold mb-4">Report Filters</h2>
            <form action="" method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <div>
                    <label for="type" class="block text-sm font-medium text-gray-700 mb-1">Report Type</label>
                    <select id="type" name="type" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                        <option value="revenue" <?= $report_type === 'revenue' ? 'selected' : '' ?>>Revenue</option>
                        <option value="bookings" <?= $report_type === 'bookings' ? 'selected' : '' ?>>Bookings</option>
                        <option value="memberships" <?= $report_type === 'memberships' ? 'selected' : '' ?>>Memberships</option>
                        <option value="users" <?= $report_type === 'users' ? 'selected' : '' ?>>Users</option>
                        <option value="gyms" <?= $report_type === 'gyms' ? 'selected' : '' ?>>Gyms</option>
                    </select>
                </div>
                
                <div>
                    <label for="date_range" class="block text-sm font-medium text-gray-700 mb-1">Date Range</label>
                    <select id="date_range" name="date_range" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                        <option value="today" <?= $date_range === 'today' ? 'selected' : '' ?>>Today</option>
                        <option value="yesterday" <?= $date_range === 'yesterday' ? 'selected' : '' ?>>Yesterday</option>
                        <option value="this_week" <?= $date_range === 'this_week' ? 'selected' : '' ?>>This Week</option>
                        <option value="last_week" <?= $date_range === 'last_week' ? 'selected' : '' ?>>Last Week</option>
                        <option value="this_month" <?= $date_range === 'this_month' ? 'selected' : '' ?>>This Month</option>
                        <option value="last_month" <?= $date_range === 'last_month' ? 'selected' : '' ?>>Last Month</option>
                        <option value="this_year" <?= $date_range === 'this_year' ? 'selected' : '' ?>>This Year</option>
                        <option value="last_year" <?= $date_range === 'last_year' ? 'selected' : '' ?>>Last Year</option>
                        <option value="custom" <?= $date_range === 'custom' ? 'selected' : '' ?>>Custom Range</option>
                    </select>
                </div>
                
                <div id="custom_date_container" class="<?= $date_range === 'custom' ? '' : 'hidden' ?> col-span-2 grid grid-cols-2 gap-4">
                    <div>
                        <label for="start_date" class="block text-sm font-medium text-gray-700 mb-1">Start Date</label>
                        <input type="date" id="start_date" name="start_date" value="<?= htmlspecialchars($start_date) ?>" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                    </div>
                    <div>
                        <label for="end_date" class="block text-sm font-medium text-gray-700 mb-1">End Date</label>
                        <input type="date" id="end_date" name="end_date" value="<?= htmlspecialchars($end_date) ?>" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                    </div>
                </div>
                
                <div>
                    <label for="gym_id" class="block text-sm font-medium text-gray-700 mb-1">Gym</label>
                    <select id="gym_id" name="gym_id" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                        <option value="">All Gyms</option>
                        <?php foreach ($gyms as $gym): ?>
                            <option value="<?= $gym['gym_id'] ?>" <?= $gym_id == $gym['gym_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($gym['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <?php if (in_array($report_type, ['revenue', 'memberships'])): ?>
                <div>
                    <label for="tier" class="block text-sm font-medium text-gray-700 mb-1">Tier</label>
                    <select id="tier" name="tier" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                        <option value="">All Tiers</option>
                        <option value="Tier 1" <?= $tier === 'Tier 1' ? 'selected' : '' ?>>Tier 1</option>
                        <option value="Tier 2" <?= $tier === 'Tier 2' ? 'selected' : '' ?>>Tier 2</option>
                        <option value="Tier 3" <?= $tier === 'Tier 3' ? 'selected' : '' ?>>Tier 3</option>
                    </select>
                </div>
                <?php endif; ?>
                
                <div class="<?= in_array($report_type, ['revenue', 'memberships']) ? 'col-span-1' : 'col-span-2' ?> flex items-end">
                    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg w-full">
                        <i class="fas fa-filter mr-2"></i> Apply Filters
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Report Content -->
        <div class="space-y-8">
            <!-- Report Header -->
            <div class="bg-white rounded-xl shadow-md p-6">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-2xl font-bold text-gray-800">
                        <?= ucfirst($report_type) ?> Report
                        <?php if ($gym_id && isset($gyms)): ?>
                            <?php foreach ($gyms as $gym): ?>
                                <?php if ($gym['gym_id'] == $gym_id): ?>
                                    <span class="text-lg font-medium text-gray-600">
                                        - <?= htmlspecialchars($gym['name']) ?>
                                    </span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </h2>
                    <div class="text-gray-600">
                        <?= date('F j, Y', strtotime($start_date)) ?> to <?= date('F j, Y', strtotime($end_date)) ?>
                    </div>
                </div>
                
                <!-- Report Summary Cards -->
                <?php if ($report_type === 'revenue' && isset($report_data['summary'])): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                    <div class="bg-gradient-to-br from-indigo-500 to-indigo-600 rounded-lg p-4 text-white">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-sm opacity-80">Total Admin Revenue</p>
                                <h3 class="text-2xl font-bold">₹<?= number_format($report_data['summary']['admin_revenue'], 2) ?></h3>
                            </div>
                            <div class="bg-white bg-opacity-20 p-2 rounded-lg">
                                <i class="fas fa-money-bill-wave text-xl"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-lg p-4 text-white">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-sm opacity-80">Total Revenue</p>
                                <h3 class="text-2xl font-bold">₹<?= number_format($report_data['summary']['total_revenue'], 2) ?></h3>
                            </div>
                            <div class="bg-white bg-opacity-20 p-2 rounded-lg">
                                <i class="fas fa-chart-line text-xl"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-lg p-4 text-white">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-sm opacity-80">Transactions</p>
                                <h3 class="text-2xl font-bold"><?= number_format($report_data['summary']['transaction_count']) ?></h3>
                            </div>
                            <div class="bg-white bg-opacity-20 p-2 rounded-lg">
                                <i class="fas fa-exchange-alt text-xl"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-gradient-to-br from-purple-500 to-purple-600 rounded-lg p-4 text-white">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-sm opacity-80">Avg. Admin Revenue</p>
                                <h3 class="text-2xl font-bold">₹<?= number_format($report_data['summary']['avg_admin_revenue_per_transaction'], 2) ?></h3>
                            </div>
                            <div class="bg-white bg-opacity-20 p-2 rounded-lg">
                                <i class="fas fa-calculator text-xl"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($report_type === 'bookings' && isset($report_data['summary'])): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                    <div class="bg-gradient-to-br from-indigo-500 to-indigo-600 rounded-lg p-4 text-white">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-sm opacity-80">Total Bookings</p>
                                <h3 class="text-2xl font-bold"><?= number_format($report_data['summary']['total_bookings']) ?></h3>
                            </div>
                            <div class="bg-white bg-opacity-20 p-2 rounded-lg">
                                <i class="fas fa-calendar-check text-xl"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-lg p-4 text-white">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-sm opacity-80">Completed</p>
                                <h3 class="text-2xl font-bold"><?= number_format($report_data['summary']['completed_count']) ?></h3>
                            </div>
                            <div class="bg-white bg-opacity-20 p-2 rounded-lg">
                                <i class="fas fa-check-circle text-xl"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-gradient-to-br from-red-500 to-red-600 rounded-lg p-4 text-white">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-sm opacity-80">Cancelled</p>
                                <h3 class="text-2xl font-bold"><?= number_format($report_data['summary']['cancelled_count']) ?></h3>
                            </div>
                            <div class="bg-white bg-opacity-20 p-2 rounded-lg">
                            <i class="fas fa-times-circle text-xl"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-gradient-to-br from-yellow-500 to-yellow-600 rounded-lg p-4 text-white">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-sm opacity-80">Unique Users</p>
                                <h3 class="text-2xl font-bold"><?= number_format($report_data['summary']['unique_users']) ?></h3>
                            </div>
                            <div class="bg-white bg-opacity-20 p-2 rounded-lg">
                                <i class="fas fa-users text-xl"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($report_type === 'memberships' && isset($report_data['summary'])): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                    <div class="bg-gradient-to-br from-indigo-500 to-indigo-600 rounded-lg p-4 text-white">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-sm opacity-80">Total Memberships</p>
                                <h3 class="text-2xl font-bold"><?= number_format($report_data['summary']['total_memberships']) ?></h3>
                            </div>
                            <div class="bg-white bg-opacity-20 p-2 rounded-lg">
                                <i class="fas fa-id-card text-xl"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-lg p-4 text-white">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-sm opacity-80">Active Memberships</p>
                                <h3 class="text-2xl font-bold"><?= number_format($report_data['summary']['active_memberships']) ?></h3>
                            </div>
                            <div class="bg-white bg-opacity-20 p-2 rounded-lg">
                                <i class="fas fa-check-circle text-xl"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-lg p-4 text-white">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-sm opacity-80">Total Revenue</p>
                                <h3 class="text-2xl font-bold">₹<?= number_format($report_data['summary']['total_revenue'], 2) ?></h3>
                            </div>
                            <div class="bg-white bg-opacity-20 p-2 rounded-lg">
                                <i class="fas fa-money-bill-wave text-xl"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-gradient-to-br from-purple-500 to-purple-600 rounded-lg p-4 text-white">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-sm opacity-80">Avg. Price</p>
                                <h3 class="text-2xl font-bold">₹<?= number_format($report_data['summary']['avg_membership_price'], 2) ?></h3>
                            </div>
                            <div class="bg-white bg-opacity-20 p-2 rounded-lg">
                                <i class="fas fa-tag text-xl"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($report_type === 'users' && isset($report_data['summary'])): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                    <div class="bg-gradient-to-br from-indigo-500 to-indigo-600 rounded-lg p-4 text-white">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-sm opacity-80">Total Users</p>
                                <h3 class="text-2xl font-bold"><?= number_format($report_data['summary']['total_users']) ?></h3>
                            </div>
                            <div class="bg-white bg-opacity-20 p-2 rounded-lg">
                                <i class="fas fa-users text-xl"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-lg p-4 text-white">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-sm opacity-80">Active Users</p>
                                <h3 class="text-2xl font-bold"><?= number_format($report_data['summary']['active_users']) ?></h3>
                            </div>
                            <div class="bg-white bg-opacity-20 p-2 rounded-lg">
                                <i class="fas fa-user-check text-xl"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-lg p-4 text-white">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-sm opacity-80">New Users</p>
                                <h3 class="text-2xl font-bold"><?= number_format($report_data['summary']['new_users']) ?></h3>
                            </div>
                            <div class="bg-white bg-opacity-20 p-2 rounded-lg">
                                <i class="fas fa-user-plus text-xl"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-gradient-to-br from-purple-500 to-purple-600 rounded-lg p-4 text-white">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-sm opacity-80">Members</p>
                                <h3 class="text-2xl font-bold"><?= number_format($report_data['summary']['member_count']) ?></h3>
                            </div>
                            <div class="bg-white bg-opacity-20 p-2 rounded-lg">
                                <i class="fas fa-user-friends text-xl"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($report_type === 'gyms' && isset($report_data['summary'])): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                    <div class="bg-gradient-to-br from-indigo-500 to-indigo-600 rounded-lg p-4 text-white">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-sm opacity-80">Total Gyms</p>
                                <h3 class="text-2xl font-bold"><?= number_format($report_data['summary']['total_gyms']) ?></h3>
                            </div>
                            <div class="bg-white bg-opacity-20 p-2 rounded-lg">
                                <i class="fas fa-dumbbell text-xl"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-lg p-4 text-white">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-sm opacity-80">Active Gyms</p>
                                <h3 class="text-2xl font-bold"><?= number_format($report_data['summary']['active_gyms']) ?></h3>
                            </div>
                            <div class="bg-white bg-opacity-20 p-2 rounded-lg">
                                <i class="fas fa-check-circle text-xl"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-lg p-4 text-white">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-sm opacity-80">New Gyms</p>
                                <h3 class="text-2xl font-bold"><?= number_format($report_data['summary']['new_gyms']) ?></h3>
                            </div>
                            <div class="bg-white bg-opacity-20 p-2 rounded-lg">
                                <i class="fas fa-plus-circle text-xl"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-gradient-to-br from-yellow-500 to-yellow-600 rounded-lg p-4 text-white">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-sm opacity-80">Avg. Rating</p>
                                <h3 class="text-2xl font-bold"><?= number_format($report_data['summary']['avg_rating'], 1) ?> <i class="fas fa-star text-sm"></i></h3>
                            </div>
                            <div class="bg-white bg-opacity-20 p-2 rounded-lg">
                                <i class="fas fa-star text-xl"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Main Chart -->
                <div class="mt-6">
                    <canvas id="mainChart" height="300"></canvas>
                </div>
            </div>
            
            <!-- Secondary Charts and Tables -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <!-- Left Column -->
                <div class="space-y-8">
                    <?php if ($report_type === 'revenue' && isset($report_data['tier_chart_data'])): ?>
                    <div class="bg-white rounded-xl shadow-md p-6">
                        <h3 class="text-xl font-semibold mb-4">Revenue by Tier</h3>
                        <div class="h-64">
                            <canvas id="tierChart"></canvas>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($report_type === 'bookings' && isset($report_data['activity_chart_data'])): ?>
                    <div class="bg-white rounded-xl shadow-md p-6">
                        <h3 class="text-xl font-semibold mb-4">Bookings by Activity Type</h3>
                        <div class="h-64">
                            <canvas id="activityChart"></canvas>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($report_type === 'memberships' && isset($report_data['tier_chart_data'])): ?>
                    <div class="bg-white rounded-xl shadow-md p-6">
                        <h3 class="text-xl font-semibold mb-4">Memberships by Tier</h3>
                        <div class="h-64">
                            <canvas id="tierChart"></canvas>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($report_type === 'users' && isset($report_data['city_chart_data'])): ?>
                    <div class="bg-white rounded-xl shadow-md p-6">
                        <h3 class="text-xl font-semibold mb-4">Users by City</h3>
                        <div class="h-64">
                            <canvas id="cityChart"></canvas>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($report_type === 'gyms' && isset($report_data['revenue_chart_data'])): ?>
                    <div class="bg-white rounded-xl shadow-md p-6">
                        <h3 class="text-xl font-semibold mb-4">Top Gyms by Revenue</h3>
                        <div class="h-64">
                            <canvas id="gymRevenueChart"></canvas>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Right Column -->
                <div class="space-y-8">
                    <?php if ($report_type === 'revenue' && isset($report_data['gym_revenue'])): ?>
                    <div class="bg-white rounded-xl shadow-md p-6">
                        <h3 class="text-xl font-semibold mb-4">Revenue by Gym</h3>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead>
                                    <tr>
                                        <th class="px-6 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Gym</th>
                                        <th class="px-6 py-3 bg-gray-50 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Total Revenue</th>
                                        <th class="px-6 py-3 bg-gray-50 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Admin Revenue</th>
                                        <th class="px-6 py-3 bg-gray-50 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Transactions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($report_data['gym_revenue'] as $gr): ?>
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?= htmlspecialchars($gr['gym_name']) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-right">₹<?= number_format($gr['total_revenue'], 2) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-right">₹<?= number_format($gr['admin_revenue'], 2) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-right"><?= number_format($gr['transaction_count']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($report_type === 'bookings' && isset($report_data['gym_bookings'])): ?>
                    <div class="bg-white rounded-xl shadow-md p-6">
                        <h3 class="text-xl font-semibold mb-4">Bookings by Gym</h3>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead>
                                    <tr>
                                        <th class="px-6 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Gym</th>
                                        <th class="px-6 py-3 bg-gray-50 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Total</th>
                                        <th class="px-6 py-3 bg-gray-50 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Completed</th>
                                        <th class="px-6 py-3 bg-gray-50 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Cancelled</th>
                                        <th class="px-6 py-3 bg-gray-50 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Unique Users</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($report_data['gym_bookings'] as $gb): ?>
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?= htmlspecialchars($gb['gym_name']) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-right"><?= number_format($gb['booking_count']) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-right"><?= number_format($gb['completed_count']) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-right"><?= number_format($gb['cancelled_count']) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-right"><?= number_format($gb['unique_users']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($report_type === 'memberships' && isset($report_data['gym_memberships'])): ?>
                    <div class="bg-white rounded-xl shadow-md p-6">
                        <h3 class="text-xl font-semibold mb-4">Memberships by Gym</h3>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead>
                                    <tr>
                                        <th class="px-6 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Gym</th>
                                        <th class="px-6 py-3 bg-gray-50 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Total</th>
                                        <th class="px-6 py-3 bg-gray-50 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Active</th>
                                        <th class="px-6 py-3 bg-gray-50 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Revenue</th>
                                        <th class="px-6 py-3 bg-gray-50 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Unique Users</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($report_data['gym_memberships'] as $gm): ?>
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?= htmlspecialchars($gm['gym_name']) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-right"><?= number_format($gm['membership_count']) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-right"><?= number_format($gm['active_memberships']) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-right">₹<?= number_format($gm['total_revenue'], 2) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-right"><?= number_format($gm['unique_users']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($report_type === 'users' && isset($report_data['city_users'])): ?>
                    <div class="bg-white rounded-xl shadow-md p-6">
                        <h3 class="text-xl font-semibold mb-4">Users by City</h3>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead>
                                    <tr>
                                        <th class="px-6 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">City</th>
                                        <th class="px-6 py-3 bg-gray-50 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">User Count</th>
                                        <th class="px-6 py-3 bg-gray-50 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Percentage</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php 
                                    $total_users = array_sum(array_column($report_data['city_users'], 'user_count'));
                                    foreach ($report_data['city_users'] as $cu): 
                                    ?>
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?= htmlspecialchars($cu['city'] ?: 'Unknown') ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-right"><?= number_format($cu['user_count']) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-right">
                                            <?= number_format(($cu['user_count'] / $total_users) * 100, 1) ?>%
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($report_type === 'gyms' && isset($report_data['booking_stats'])): ?>
                    <div class="bg-white rounded-xl shadow-md p-6">
                        <h3 class="text-xl font-semibold mb-4">Top Gyms by Bookings</h3>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead>
                                    <tr>
                                        <th class="px-6 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Gym</th>
                                        <th class="px-6 py-3 bg-gray-50 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Bookings</th>
                                        <th class="px-6 py-3 bg-gray-50 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Unique Users</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($report_data['booking_stats'] as $bs): ?>
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?= htmlspecialchars($bs['gym_name']) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-right"><?= number_format($bs['booking_count']) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-right"><?= number_format($bs['unique_users']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Toggle custom date inputs based on date range selection
        document.getElementById('date_range').addEventListener('change', function() {
            const customDateContainer = document.getElementById('custom_date_container');
            if (this.value === 'custom') {
                customDateContainer.classList.remove('hidden');
            } else {
                customDateContainer.classList.add('hidden');
            }
        });
        
        // Initialize charts
        document.addEventListener('DOMContentLoaded', function() {
            // Main chart
            const mainChartCtx = document.getElementById('mainChart').getContext('2d');
            const mainChartData = <?= json_encode($chart_data) ?>;
            
            const mainChart = new Chart(mainChartCtx, {
                type: 'line',
                data: mainChartData,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                        }
                    },
                    scales: {
                        <?php if ($report_type === 'memberships'): ?>
                        y: {
                            type: 'linear',
                            display: true,
                            position: 'left',
                            title: {
                                display: true,
                                text: 'Memberships'
                            }
                        },
                        y1: {
                            type: 'linear',
                            display: true,
                            position: 'right',
                            grid: {
                                drawOnChartArea: false,
                            },
                            title: {
                                display: true,
                                text: 'Revenue (₹)'
                            }
                        }
                        <?php endif; ?>
                    }
                }
            });
            
            <?php if ($report_type === 'revenue' && isset($report_data['tier_chart_data'])): ?>
            // Tier chart
            const tierChartCtx = document.getElementById('tierChart').getContext('2d');
            const tierChartData = <?= json_encode($report_data['tier_chart_data']) ?>;
            
            const tierChart = new Chart(tierChartCtx, {
                type: 'pie',
                data: tierChartData,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                        }
                    }
                }
            });
            <?php endif; ?>
            
            <?php if ($report_type === 'bookings' && isset($report_data['activity_chart_data'])): ?>
            // Activity chart
            const activityChartCtx = document.getElementById('activityChart').getContext('2d');
            const activityChartData = <?= json_encode($report_data['activity_chart_data']) ?>;
            
            const activityChart = new Chart(activityChartCtx, {
                type: 'pie',
                data: activityChartData,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                        }
                    }
                }
            });
            <?php endif; ?>
            
            <?php if ($report_type === 'memberships' && isset($report_data['tier_chart_data'])): ?>
            // Tier chart for memberships
            const tierChartCtx = document.getElementById('tierChart').getContext('2d');
            const tierChartData = <?= json_encode($report_data['tier_chart_data']) ?>;
            
            const tierChart = new Chart(tierChartCtx, {
                type: 'pie',
                data: tierChartData,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                        }
                    }
                }
            });
            <?php endif; ?>
            
            <?php if ($report_type === 'users' && isset($report_data['city_chart_data'])): ?>
            // City chart
            const cityChartCtx = document.getElementById('cityChart').getContext('2d');
            const cityChartData = <?= json_encode($report_data['city_chart_data']) ?>;
            
            const cityChart = new Chart(cityChartCtx, {
                type: 'pie',
                data: cityChartData,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                        }
                    }
                }
            });
            <?php endif; ?>
            
            <?php if ($report_type === 'gyms' && isset($report_data['revenue_chart_data'])): ?>
            // Gym revenue chart
            const gymRevenueChartCtx = document.getElementById('gymRevenueChart').getContext('2d');
            const gymRevenueChartData = <?= json_encode($report_data['revenue_chart_data']) ?>;
            
            const gymRevenueChart = new Chart(gymRevenueChartCtx, {
                type: 'bar',
                data: gymRevenueChartData,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                        }
                    },
                    scales: {
                        x: {
                            ticks: {
                                maxRotation: 45,
                                minRotation: 45
                            }
                        }
                    }
                }
            });
            <?php endif; ?>
        });
        
        // Export to CSV function
        function exportToCSV() {
            let csvContent = "data:text/csv;charset=utf-8,";
            
            <?php if ($report_type === 'revenue' && isset($report_data['daily_revenue'])): ?>
            // Headers
            csvContent += "Date,Total Revenue,Admin Revenue,Transactions\n";
            
            // Data
            <?php foreach ($report_data['daily_revenue'] as $dr): ?>
            csvContent += "<?= $dr['date'] ?>,<?= $dr['total_revenue'] ?>,<?= $dr['admin_revenue'] ?>,<?= $dr['transaction_count'] ?>\n";
            <?php endforeach; ?>
            <?php endif; ?>
            
            <?php if ($report_type === 'bookings' && isset($report_data['daily_bookings'])): ?>
            // Headers
            csvContent += "Date,Total Bookings,Completed,Cancelled,Unique Users\n";
            
            // Data
            <?php foreach ($report_data['daily_bookings'] as $db): ?>
            csvContent += "<?= $db['date'] ?>,<?= $db['booking_count'] ?>,<?= $db['completed_count'] ?>,<?= $db['cancelled_count'] ?>,<?= $db['unique_users'] ?>\n";
            <?php endforeach; ?>
            <?php endif; ?>
            
            <?php if ($report_type === 'memberships' && isset($report_data['daily_memberships'])): ?>
            // Headers
            csvContent += "Date,New Memberships,Revenue\n";
            
            // Data
            <?php foreach ($report_data['daily_memberships'] as $dm): ?>
            csvContent += "<?= $dm['date'] ?>,<?= $dm['membership_count'] ?>,<?= $dm['total_revenue'] ?>\n";
            <?php endforeach; ?>
            <?php endif; ?>
            
            <?php if ($report_type === 'users' && isset($report_data['daily_users'])): ?>
            // Headers
            csvContent += "Date,New Users\n";
            
            // Data
            <?php foreach ($report_data['daily_users'] as $du): ?>
            csvContent += "<?= $du['date'] ?>,<?= $du['user_count'] ?>\n";
            <?php endforeach; ?>
            <?php endif; ?>
            
            <?php if ($report_type === 'gyms' && isset($report_data['daily_gyms'])): ?>
            // Headers
            csvContent += "Date,New Gyms\n";
            
            // Data
            <?php foreach ($report_data['daily_gyms'] as $dg): ?>
            csvContent += "<?= $dg['date'] ?>,<?= $dg['gym_count'] ?>\n";
            <?php endforeach; ?>
            <?php endif; ?>
            
            // Create download link
            const encodedUri = encodeURI(csvContent);
            const link = document.createElement("a");
            link.setAttribute("href", encodedUri);
            link.setAttribute("download", "<?= $report_type ?>_report_<?= date('Y-m-d') ?>.csv");
            document.body.appendChild(link);
            
            // Trigger download
            link.click();
        }
    </script>
</body>
</html>



    

