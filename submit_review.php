<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id']) || !isset($_POST['gym_id'])) {
    header('Location: index.php');
    exit;
}

$db = new GymDatabase();
$conn = $db->getConnection();

$user_id = $_SESSION['user_id'];
$gym_id = $_POST['gym_id'];
$rating = $_POST['rating'];
$comment = $_POST['comment'];

$stmt = $conn->prepare("
    INSERT INTO reviews (user_id, gym_id, rating, comment, status, created_at)
    VALUES (:user_id, :gym_id, :rating, :comment, 'pending', CURRENT_TIMESTAMP)
");

$stmt->execute([
    ':user_id' => $user_id,
    ':gym_id' => $gym_id,
    ':rating' => $rating,
    ':comment' => $comment
]);

header("Location: gym-profile.php?gym_id=$gym_id&review=success");
exit;
