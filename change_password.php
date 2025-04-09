<?php
require_once 'config/database.php';
include 'includes/navbar.php';


if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$db = new GymDatabase();
$conn = $db->getConnection();
$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Verify current password
    $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (password_verify($current_password, $user['password'])) {
        if ($new_password === $confirm_password) {
            // Update password
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hashed_password, $user_id]);
            
            header('Location: profile.php?password_updated=1');
            exit;
        } else {
            $error = "New passwords do not match";
        }
    } else {
        $error = "Current password is incorrect";
    }
}
?>

<div class="min-h-screen bg-gradient-to-b from-gray-900 to-black py-12 sm:py-16 lg:py-20">
    <div class="container mx-auto px-4 sm:px-6 lg:px-8">
        <div class="max-w-4xl mx-auto">
            <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl">
                <div class="p-6 sm:p-8 lg:p-10">
                    <h2 class="text-2xl sm:text-3xl font-bold text-white mb-8">Change Password</h2>
                    
                    <?php if (isset($error)): ?>
                        <div class="bg-red-900 border border-red-700 text-red-100 px-4 py-3 rounded-xl mb-6">
                            <?= htmlspecialchars($error) ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST">
                        <div class="mb-6">
                            <label class="block text-yellow-400 text-sm font-bold mb-2" for="current_password">
                                Current Password
                            </label>
                            <input type="password" 
                                   name="current_password" 
                                   required
                                   class="w-full px-4 py-3 bg-gray-700 border border-gray-600 rounded-xl text-white focus:border-yellow-400 focus:outline-none">
                        </div>

                        <div class="mb-6">
                            <label class="block text-yellow-400 text-sm font-bold mb-2" for="new_password">
                                New Password
                            </label>
                            <input type="password" 
                                   name="new_password" 
                                   required
                                   class="w-full px-4 py-3 bg-gray-700 border border-gray-600 rounded-xl text-white focus:border-yellow-400 focus:outline-none">
                        </div>

                        <div class="mb-8">
                            <label class="block text-yellow-400 text-sm font-bold mb-2" for="confirm_password">
                                Confirm New Password
                            </label>
                            <input type="password" 
                                   name="confirm_password" 
                                   required
                                   class="w-full px-4 py-3 bg-gray-700 border border-gray-600 rounded-xl text-white focus:border-yellow-400 focus:outline-none">
                        </div>

                        <div class="flex flex-col sm:flex-row justify-end space-y-4 sm:space-y-0 sm:space-x-4">
                            <a href="profile.php" 
                               class="bg-gray-600 text-white px-6 py-3 rounded-full font-bold text-center
                                      hover:bg-gray-700 transform hover:scale-105 transition-all duration-300">
                                Cancel
                            </a>
                            <button type="submit" 
                                    class="bg-yellow-400 text-black px-6 py-3 rounded-full font-bold text-center
                                           hover:bg-yellow-500 transform hover:scale-105 transition-all duration-300">
                                Update Password
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
