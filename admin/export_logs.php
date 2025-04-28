<?php
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ./login.php');
    exit();
}

require_once '../config/database.php';
$db = new GymDatabase();
$conn = $db->getConnection();

// Filter parameters
$user_type = isset($_GET['user_type']) ? $_GET['user_type'] : '';
$action = isset($_GET['action']) ? $_GET['action'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$search = isset($_GET['search']) ? $_GET['search'] : '';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'date_desc';

// Base query
$sql = "SELECT al.id, al.user_id, al.user_type, al.action, al.details, al.ip_address, al.created_at,
        CASE 
            WHEN al.user_type = 'member' THEN u.username
            WHEN al.user_type = 'owner' THEN go.name
            WHEN al.user_type = 'admin' THEN 'Admin'
        END as user_name
        FROM activity_logs al
        LEFT JOIN users u ON al.user_id = u.id AND al.user_type = 'member'
        LEFT JOIN gym_owners go ON al.user_id = go.id AND al.user_type = 'owner'
        WHERE 1=1";
$params = [];

// Apply filters
if ($user_type) {
    $sql .= " AND al.user_type = ?";
    $params[] = $user_type;
}

if ($action) {
    $sql .= " AND al.action = ?";
    $params[] = $action;
}

if ($date_from) {
    $sql .= " AND al.created_at >= ?";
    $params[] = $date_from . ' 00:00:00';
}

if ($date_to) {
    $sql .= " AND al.created_at <= ?";
    $params[] = $date_to . ' 23:59:59';
}

if ($search) {
    $sql .= " AND (al.details LIKE ? OR al.ip_address LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
}

// Apply sorting
switch ($sort) {
    case 'date_asc':
        $sql .= " ORDER BY al.created_at ASC";
        break;
    case 'user_type':
        $sql .= " ORDER BY al.user_type ASC, al.created_at DESC";
        break;
    case 'action':
        $sql .= " ORDER BY al.action ASC, al.created_at DESC";
        break;
    case 'date_desc':
    default:
        $sql .= " ORDER BY al.created_at DESC";
        break;
}

// Execute the query
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Set headers for CSV download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="activity_logs_export_' . date('Y-m-d_H-i-s') . '.csv"');

// Create output stream
$output = fopen('php://output', 'w');

// Add CSV headers
fputcsv($output, ['ID', 'Date & Time', 'User Type', 'User ID', 'User Name', 'Action', 'Details', 'IP Address']);

// Add data rows
foreach ($logs as $log) {
    fputcsv($output, [
        $log['id'],
        $log['created_at'],
        ucfirst($log['user_type']),
        $log['user_id'],
        $log['user_name'] ?? 'Unknown',
        ucwords(str_replace('_', ' ', $log['action'])),
        $log['details'],
        $log['ip_address']
    ]);
}

// Log the export activity
$stmt = $conn->prepare("
    INSERT INTO activity_logs (
        user_id, user_type, action, details, ip_address, user_agent
    ) VALUES (?, 'admin', 'export_logs', ?, ?, ?)
");
$stmt->execute([
    $_SESSION['admin_id'],
    "Exported activity logs with filters: " . json_encode([
        'user_type' => $user_type,
        'action' => $action,
        'date_from' => $date_from,
        'date_to' => $date_to,
        'search' => $search
    ]),
    $_SERVER['REMOTE_ADDR'],
    $_SERVER['HTTP_USER_AGENT']
]);

fclose($output);
exit;
