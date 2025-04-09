<?php
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';

// Initialize database connection
$db = new GymDatabase();
$conn = $db->getConnection();
$auth = new Auth($conn);

// Log the user out
$auth->logout();

// Set success message
$_SESSION['success'] = "You have been successfully logged out.";

// Redirect to login page
header('Location: login.php');
exit;
