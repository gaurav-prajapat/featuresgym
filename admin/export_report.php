<?php
require_once '../config/database.php';
session_start();

// Check if user is logged in as admin
if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ./login.php');
    exit();
}

// Get parameters
$format = isset($_GET['format']) ? $_GET['format'] : 'csv';
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'revenue';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-30 days'));
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$gym_id = isset($_GET['gym_id']) ? (int)$_GET['gym_id'] : 0;

$db = new GymDatabase();
$conn = $db->getConnection();

// Function to format currency
function formatCurrency($amount) {
    return '₹' . number_format($amount, 2);
}

// Get report data
try {
    switch ($report_type) {
        case 'revenue':
            $query = "
                SELECT 
                    DATE(gr.date) as date,
                    SUM(gr.amount) as total_amount,
                    SUM(gr.admin_cut) as admin_revenue,
                    SUM(gr.amount - gr.admin_cut) as gym_revenue,
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
            
            // Set headers for CSV
            $headers = ['Date', 'Gym', 'Source', 'Total Amount', 'Admin Revenue', 'Gym Revenue'];
            
            // Format data for export
            $export_data = [];
            foreach ($report_data as $row) {
                $export_data[] = [
                    date('Y-m-d', strtotime($row['date'])),
                    $row['gym_name'],
                    ucfirst(str_replace('_', ' ', $row['source_type'])),
                    $row['total_amount'],
                    $row['admin_revenue'],
                    $row['gym_revenue']
                ];
            }
            
            $filename = 'revenue_report_' . $start_date . '_to_' . $end_date;
            break;
            
        case 'memberships':
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
            
            // Set headers for CSV
            $headers = ['ID', 'Member', 'Email', 'Gym', 'Plan', 'Duration', 'Start Date', 'End Date', 'Amount', 'Status'];
            
            // Format data for export
            $export_data = [];
            foreach ($report_data as $row) {
                $export_data[] = [
                    $row['id'],
                    $row['member_name'],
                    $row['member_email'],
                    $row['gym_name'],
                    $row['plan_name'],
                    $row['duration'],
                    $row['start_date'],
                    $row['end_date'],
                    $row['amount'],
                    ucfirst($row['payment_status'])
                ];
            }
            
            $filename = 'membership_report_' . $start_date . '_to_' . $end_date;
            break;
            
        case 'payouts':
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
            
            // Set headers for CSV
            $headers = ['ID', 'Gym', 'Owner', 'Email', 'Amount', 'Status', 'Requested On', 'Processed On', 'Transaction ID', 'Processed By'];
            
            // Format data for export
            $export_data = [];
            foreach ($report_data as $row) {
                $export_data[] = [
                    $row['id'],
                    $row['gym_name'],
                    $row['owner_name'],
                    $row['owner_email'],
                    $row['amount'],
                    ucfirst($row['status']),
                    date('Y-m-d H:i:s', strtotime($row['created_at'])),
                    $row['processed_at'] ? date('Y-m-d H:i:s', strtotime($row['processed_at'])) : 'N/A',
                    $row['transaction_id'] ?: 'N/A',
                    $row['admin_name'] ?: 'N/A'
                ];
            }
            
            $filename = 'payout_report_' . $start_date . '_to_' . $end_date;
            break;
            
        case 'gym_performance':
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
            
            // Set headers for CSV
            $headers = ['Gym ID', 'Gym Name', 'Location', 'Status', 'Featured', 'Members', 'Schedules', 'Total Revenue', 'Admin Revenue', 'Average Rating', 'Reviews'];
            
            // Format data for export
            $export_data = [];
            foreach ($report_data as $row) {
                $export_data[] = [
                    $row['gym_id'],
                    $row['gym_name'],
                    $row['city'] . ', ' . $row['state'],
                    ucfirst($row['status']),
                    $row['is_featured'] ? 'Yes' : 'No',
                    $row['total_members'],
                    $row['total_schedules'],
                    $row['total_revenue'] ?: '0',
                    $row['admin_revenue'] ?: '0',
                    $row['avg_rating'] ? number_format($row['avg_rating'], 1) : 'N/A',
                    $row['review_count']
                ];
            }
            
            $filename = 'gym_performance_report_' . $start_date . '_to_' . $end_date;
            break;
            
        case 'transactions':
        default:
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
            
            // Set headers for CSV
            $headers = ['ID', 'User', 'Email', 'Gym', 'Type', 'Amount', 'Status', 'Date', 'Payment Method', 'Transaction ID', 'Description'];
            
            // Format data for export
            $export_data = [];
            foreach ($report_data as $row) {
                $export_data[] = [
                    $row['id'],
                    $row['user_name'],
                    $row['user_email'],
                    $row['gym_name'],
                    ucfirst(str_replace('_', ' ', $row['transaction_type'])),
                    $row['amount'],
                    ucfirst($row['status']),
                    date('Y-m-d H:i:s', strtotime($row['transaction_date'])),
                    $row['payment_method'] ?: 'N/A',
                    $row['transaction_id'] ?: 'N/A',
                    $row['description'] ?: 'N/A'
                ];
            }
            
            $filename = 'transaction_report_' . $start_date . '_to_' . $end_date;
            break;
    }
    
    // Export based on format
    if ($format === 'csv') {
        // Set headers for CSV download
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        
        // Open output stream
        $output = fopen('php://output', 'w');
        
        // Add UTF-8 BOM for Excel compatibility
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Add headers
        fputcsv($output, $headers);
        
        // Add data rows
        foreach ($export_data as $row) {
            fputcsv($output, $row);
        }
        
        // Close output stream
        fclose($output);
        exit;
    } else if ($format === 'pdf') {
        // Require TCPDF library
        require_once '../vendor/tecnickcom/tcpdf/tcpdf.php';
        
        // Create new PDF document
        $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
        
        // Set document information
        $pdf->SetCreator('FlexFit Admin');
        $pdf->SetAuthor('FlexFit Admin');
        $pdf->SetTitle(ucfirst($report_type) . ' Report');
        $pdf->SetSubject(ucfirst($report_type) . ' Report ' . $start_date . ' to ' . $end_date);
        
        // Set default header data
        $pdf->SetHeaderData('', 0, 'FlexFit ' . ucfirst($report_type) . ' Report', 'Period: ' . $start_date . ' to ' . $end_date);
        
        // Set header and footer fonts
        $pdf->setHeaderFont(Array('helvetica', '', 10));
        $pdf->setFooterFont(Array('helvetica', '', 8));
        
        // Set default monospaced font
        $pdf->SetDefaultMonospacedFont('courier');
        
        // Set margins
        $pdf->SetMargins(10, 20, 10);
        $pdf->SetHeaderMargin(10);
        $pdf->SetFooterMargin(10);
        
        // Set auto page breaks
        $pdf->SetAutoPageBreak(TRUE, 15);
        
        // Add a page
        $pdf->AddPage();
        
        // Set font
        $pdf->SetFont('helvetica', '', 8);
        
        // Create table header
        $html = '<table border="1" cellpadding="3" cellspacing="0">';
        $html .= '<tr style="background-color:#f0f0f0;">';
        foreach ($headers as $header) {
            $html .= '<th>' . $header . '</th>';
        }
        $html .= '</tr>';
        
        // Add data rows
        foreach ($export_data as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>' . $cell . '</td>';
            }
            $html .= '</tr>';
        }
        
        $html .= '</table>';
        
        // Output HTML content
        $pdf->writeHTML($html, true, false, true, false, '');
        
        // Close and output PDF document
        $pdf->Output($filename . '.pdf', 'D');
        exit;
    }
    
} catch (PDOException $e) {
    // Log error
    error_log("Export error: " . $e->getMessage());
    
    // Redirect back with error
    $_SESSION['error'] = "Error generating export: " . $e->getMessage();
    header('Location: financial_reports.php');
    exit;
}

