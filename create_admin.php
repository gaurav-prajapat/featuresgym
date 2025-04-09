<?php
require_once 'config/database.php';

// Admin credentials
$admin_email = 'admin@example.com';
$admin_password = 'admin123'; // Change this to a secure password
$admin_role = 'admin';

// Hash the password
$hashed_password = password_hash($admin_password, PASSWORD_DEFAULT);

// Connect to database
$db = new GymDatabase();
$conn = $db->getConnection();

// Check if admin already exists
$check_stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND role = 'admin'");
$check_stmt->execute([$admin_email]);
$existing_admin = $check_stmt->fetch(PDO::FETCH_ASSOC);

if ($existing_admin) {
    echo "Admin user already exists with ID: " . $existing_admin['id'];
} else {
    // Insert admin user
    $stmt = $conn->prepare("INSERT INTO users (email, password, role, created_at) VALUES (?, ?, ?, NOW())");
    $result = $stmt->execute([$admin_email, $hashed_password, $admin_role]);
    
    if ($result) {
        echo "Admin user created successfully with email: " . $admin_email;
    } else {
        echo "Error creating admin user: " . implode(", ", $stmt->errorInfo());
    }
}
