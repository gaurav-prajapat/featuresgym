<?php
include '../includes/navbar.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: /gym/views/auth/login.php');
    exit();
}

require_once '../config/database.php';
$db = new GymDatabase();
$conn = $db->getConnection();

// Get user ID from URL
$user_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$user_id) {
    $_SESSION['error'] = "Invalid user ID.";
    header('Location: users.php');
    exit;
}

// Check if user exists
$stmt = $conn->prepare("SELECT username, email FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    $_SESSION['error'] = "User not found.";
    header('Location: users.php');
    exit;
}

// Process deletion if confirmed
if (isset($_POST['confirm_delete']) && $_POST['confirm_delete'] === 'yes') {
    try {
        $conn->beginTransaction();
        
        // Store user details for logging
        $username = $user['username'];
        $email = $user['email'];
        
        // Delete user
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        
        // Log the activity
        $stmt = $conn->prepare("
            INSERT INTO activity_logs (
                user_id, user_type, action, details, ip_address, user_agent
            ) VALUES (?, 'admin', ?, ?, ?, ?)
        ");
        
        $details = "Deleted user: $username ($email) with ID: $user_id";
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        
        $stmt->execute([$_SESSION['user_id'], "delete_user", $details, $ip, $user_agent]);
        
        $conn->commit();
        
        $_SESSION['success'] = "User deleted successfully.";
        header('Location: users.php');
        exit;
        
    } catch (PDOException $e) {
        $conn->rollBack();
        $_SESSION['error'] = "Error deleting user: " . $e->getMessage();
        header('Location: users.php');
        exit;
    }
}
?>

<div class="container mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Delete User</h1>
        <a href="users.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg flex items-center">
            <i class="fas fa-arrow-left mr-2"></i> Back to Users
        </a>
    </div>
    
    <div class="bg-white shadow-md rounded-lg p-6">
        <div class="text-center mb-6">
            <div class="inline-flex items-center justify-center w-16 h-16 bg-red-100 rounded-full mb-4">
                <i class="fas fa-exclamation-triangle text-red-600 text-2xl"></i>
            </div>
            <h2 class="text-xl font-bold text-gray-900">Confirm User Deletion</h2>
            <p class="text-gray-600 mt-2">
                Are you sure you want to delete the user <strong><?= htmlspecialchars($user['username']) ?></strong> (<?= htmlspecialchars($user['email']) ?>)?
            </p>
        </div>
        
        <div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
            <p class="font-bold">Warning!</p>
            <p>This action cannot be undone. All data associated with this user will be permanently deleted, including:</p>
            <ul class="list-disc list-inside ml-4 mt-2">
                <li>User profile information</li>
                <li>Membership records</li>
                <li>Booking history</li>
                <li>Payment records</li>
                <li>Reviews and ratings</li>
            </ul>
        </div>
        
        <form method="POST" action="" class="mt-8">
            <input type="hidden" name="confirm_delete" value="yes">
            
            <div class="flex justify-center space-x-4">
                <a href="users.php" class="px-6 py-3 bg-gray-300 text-gray-800 rounded-lg hover:bg-gray-400">
                    Cancel
                </a>
                <button type="submit" class="px-6 py-3 bg-red-600 text-white rounded-lg hover:bg-red-700">
                    Delete User
                </button>
            </div>
        </form>
    </div>
</div>
