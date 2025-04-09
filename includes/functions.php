<?php
/**
 * Common functions for the gym management system
 */

// Function to sanitize input data
function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Function to format currency
function format_currency($amount) {
    return '₹' . number_format($amount, 2);
}

// Function to format date
function format_date($date, $format = 'd M, Y') {
    return date($format, strtotime($date));
}

// Function to check if a user has permission
function has_permission($permission_name, $user_id = null) {
    // Implement permission checking logic here
    return true; // Default to true for now
}

// Function to generate a random string
function generate_random_string($length = 10) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $random_string = '';
    for ($i = 0; $i < $length; $i++) {
        $random_string .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $random_string;
}

// Function to log activity
function log_activity($user_id, $user_type, $action, $details = null) {
    global $conn;
    
    if (!isset($conn)) {
        return false;
    }
    
    $query = "INSERT INTO activity_logs (user_id, user_type, action, details, ip_address, user_agent) 
              VALUES (:user_id, :user_type, :action, :details, :ip, :user_agent)";
    
    try {
        $stmt = $conn->prepare($query);
        $stmt->execute([
            ':user_id' => $user_id,
            ':user_type' => $user_type,
            ':action' => $action,
            ':details' => $details,
            ':ip' => $_SERVER['REMOTE_ADDR'],
            ':user_agent' => $_SERVER['HTTP_USER_AGENT']
        ]);
        return true;
    } catch (PDOException $e) {
        return false;
    }
}
?>
