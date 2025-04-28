<?php
// Check if admin is logged in
session_start();
if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ./login.php');
    exit();
}

require_once '../config/database.php';
$db = new GymDatabase();
$conn = $db->getConnection();

// Get table name from request
$table_name = isset($_GET['table']) ? $_GET['table'] : '';

// Validate table name (basic security check)
if (!preg_match('/^[a-zA-Z0-9_]+$/', $table_name)) {
    die("Invalid table name");
}

// Check if table exists
$table_check = $conn->query("SHOW TABLES LIKE '$table_name'");
if ($table_check->rowCount() === 0) {
    die("Table does not exist");
}

// Set headers for CSV download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $table_name . '_export_' . date('Y-m-d_H-i-s') . '.csv"');

// Create output stream
$output = fopen('php://output', 'w');

try {
    // Get table data
    $stmt = $conn->query("SELECT * FROM `$table_name`");
    
    // If there's data, add headers first
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, array_keys($row));
        // Add the first row
        fputcsv($output, $row);
        // Add the rest of the rows
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, $row);
        }
    }
    
    // Log the activity
    $log_stmt = $conn->prepare("
        INSERT INTO activity_logs (
            user_id, user_type, action, details, ip_address, user_agent
        ) VALUES (:user_id, 'admin', 'export_table', :details, :ip, :user_agent)
    ");
    $log_stmt->execute([
        ':user_id' => $_SESSION['admin_id'],
        ':details' => "Exported table: $table_name",
        ':ip' => $_SERVER['REMOTE_ADDR'],
        ':user_agent' => $_SERVER['HTTP_USER_AGENT']
    ]);
} catch (PDOException $e) {
    die("Error exporting table: " . $e->getMessage());
}

fclose($output);
exit;
