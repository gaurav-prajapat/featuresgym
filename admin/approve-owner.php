<?php
session_start();
require '../config/database.php';

// Check if the user is an admin
if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

// Check if the owner ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo "Invalid request. Owner ID is missing.";
    exit();
}

// Get the owner ID from the query parameter
$owner_id = intval($_GET['id']);

// Database connection
$db = new GymDatabase();
$conn = $db->getConnection();

try {
    // Update the owner's status to "approved"
    $query = "UPDATE gym_owners SET is_approved = 1, is_verified = 1 WHERE id = :id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':id', $owner_id, PDO::PARAM_INT);

    if ($stmt->execute()) {
        echo "Owner approved successfully!";
        header('Location: gym-owners.php'); // Redirect to the manage owners page
        exit();
    } else {
        echo "Failed to approve the owner. Please try again.";
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
