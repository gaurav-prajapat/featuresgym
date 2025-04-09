<?php
session_start();
require '../config/database.php';

if (!isset($_SESSION['owner_id'])) {
    header('Location: login.php');
    exit;
}

$gymOwnerId = $_SESSION['owner_id'];
$db = new GymDatabase();
$conn = $db->getConnection();

if (isset($_GET['id'])) {
    $memberId = $_GET['id'];

    $stmt = $conn->prepare("DELETE FROM gym_members WHERE owner_id = :owner_id AND member_id = :member_id");
    $stmt->bindParam(':owner_id', $gymOwnerId);
    $stmt->bindParam(':member_id', $memberId);

    if ($stmt->execute()) {
        echo "<p class='bg-green-500 text-white p-4'>Member deleted successfully!</p>";
    } else {
        echo "<p class='bg-red-500 text-white p-4'>Failed to delete member.</p>";
    }
} else {
    echo "<p class='bg-red-500 text-white p-4'>Member ID is missing.</p>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete Member</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100">
    <div class="container mx-auto p-6">
        <div class="bg-white shadow-lg rounded-lg p-6">
            <h1 class="text-xl font-bold mb-4">Delete Member</h1>
            <p class="text-lg">The selected member has been deleted successfully.</p>
        </div>
    </div>
</body>
</html>
