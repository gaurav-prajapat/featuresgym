<?php
session_start();
require_once '../config/database.php';
require_once '../includes/auth.php';

// Initialize database connection
$db = new GymDatabase();
$conn = $db->getConnection();
$auth = new Auth($conn);

// Check if user is a gym owner
if (!$auth->isGymOwner()) {
    $_SESSION['error'] = "You don't have permission to access this page.";
    header('Location: ../login.php');
    exit;
}

// Check if gym_id is provided
if (!isset($_GET['gym_id'])) {
    $_SESSION['error'] = "No gym selected.";
    header('Location: dashboard.php');
    exit;
}

$gym_id = (int)$_GET['gym_id'];

// Switch to the selected gym
if ($auth->switchGym($gym_id)) {
    $_SESSION['success'] = "Successfully switched to " . $_SESSION['gym_name'];
} else {
    $_SESSION['error'] = "Failed to switch gym. Please try again.";
}

// Redirect back to dashboard
header('Location: dashboard.php');
exit;
