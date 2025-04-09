<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['owner_id'])) {
    header('Location: login.php');
    exit;
}

$review_id = $_GET['id'];
$db = new GymDatabase();
$conn = $db->getConnection();

// Verify the review belongs to the owner's gym
$stmt = $conn->prepare("
    SELECT r.* FROM reviews r
    JOIN gyms g ON r.gym_id = g.gym_id
    WHERE r.id = :review_id AND g.owner_id = :owner_id
");
$stmt->execute([
    ':review_id' => $review_id,
    ':owner_id' => $_SESSION['owner_id']
]);

if ($stmt->fetch()) {
    $updateStmt = $conn->prepare("
        UPDATE reviews 
        SET status = 'approved' 
        WHERE id = :review_id
    ");
    $updateStmt->execute([':review_id' => $review_id]);
}

header('Location: view_reviews.php?success=approved');
exit;
