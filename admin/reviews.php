<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: /gym/views/auth/login.php');
    exit();
}

require_once '../config/database.php';
$db = new GymDatabase();
$conn = $db->getConnection();

$stmt = $conn->query("
    SELECT r.*, u.username, g.name as gym_name 
    FROM reviews r 
    JOIN users u ON r.user_id = u.id 
    JOIN gyms g ON r.gym_id = g.gym_id 
    ORDER BY r.created_at DESC
");
$reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Gyms - FlexFit Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
</head>
<body class="bg-gray-900 text-white">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="ml-0 lg:ml-64 p-4 sm:p-6 transition-all duration-200">
    <h1 class="text-2xl font-bold mb-6">Manage Reviews</h1>

    <div class="bg-white shadow-md rounded">
        <table class="min-w-full">
            <thead>
                <tr class="bg-gray-100">
                    <th class="px-6 py-3">User</th>
                    <th class="px-6 py-3">Gym</th>
                    <th class="px-6 py-3">Rating</th>
                    <th class="px-6 py-3">Status</th>
                    <th class="px-6 py-3">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reviews as $review): ?>
                <tr class="border-b">
                    <td class="px-6 py-4"><?php echo $review['username']; ?></td>
                    <td class="px-6 py-4"><?php echo $review['gym_name']; ?></td>
                    <td class="px-6 py-4"><?php echo $review['rating']; ?>/5</td>
                    <td class="px-6 py-4"><?php echo $review['status']; ?></td>
                    <td class="px-6 py-4">
                        <a href="approve.php?id=<?php echo $review['id']; ?>" class="text-green-500">Approve</a>
                        <a href="reject.php?id=<?php echo $review['id']; ?>" class="text-red-500 ml-3">Reject</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
