<?php
ob_start();
include '../includes/navbar.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

require_once '../config/database.php';
$db = new GymDatabase();
$conn = $db->getConnection();

// Initialize variables
$success_message = '';
$error_message = '';

// Process truncate request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_truncate']) && isset($_POST['table_name'])) {
    $table_name = $_POST['table_name'];
    
    // Validate table name (basic security check)
    if (preg_match('/^[a-zA-Z0-9_]+$/', $table_name)) {
        try {
            // Check if table exists
            $table_check = $conn->query("SHOW TABLES LIKE '$table_name'");
            if ($table_check->rowCount() > 0) {
                // Truncate the table
                $conn->exec("TRUNCATE TABLE `$table_name`");
                
                // Log the activity
                $stmt = $conn->prepare("
                    INSERT INTO activity_logs (
                        user_id, user_type, action, details, ip_address, user_agent
                    ) VALUES (:user_id, 'admin', 'truncate_table', :details, :ip, :user_agent)
                ");
                $stmt->execute([
                    ':user_id' => $_SESSION['admin_id'],
                    ':details' => "Truncated table: $table_name",
                    ':ip' => $_SERVER['REMOTE_ADDR'],
                    ':user_agent' => $_SERVER['HTTP_USER_AGENT']
                ]);
                
                $_SESSION['success'] = "Table '$table_name' has been truncated successfully.";
            } else {
                $_SESSION['error'] = "Table '$table_name' does not exist.";
            }
        } catch (PDOException $e) {
            $_SESSION['error'] = "Error truncating table: " . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = "Invalid table name.";
    }
    
    // Redirect back to database manager
    header("Location: database_manager.php?table=$table_name&action=view");
    exit();
} else {
    // Invalid request
    $_SESSION['error'] = "Invalid request.";
    header("Location: database_manager.php");
    exit();
}
