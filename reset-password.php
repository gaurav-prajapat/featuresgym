<?php
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';

// Initialize database connection
$db = new GymDatabase();
$conn = $db->getConnection();
$auth = new Auth($conn);

$error = '';
$success = '';
$token = '';
$userType = '';
$validToken = false;

// Get token from URL
$token = $_GET['token'] ?? '';
$userType = $_GET['type'] ?? 'member';

if (empty($token)) {
    $error = "Invalid or missing reset token.";
} else {
    try {
        // Verify token is valid and not expired
        $stmt = $conn->prepare("
            SELECT * FROM password_resets 
            WHERE token = ? AND user_type = ? AND expiry > NOW()
        ");
        $stmt->execute([$token, $userType]);
        $reset = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($reset) {
            $validToken = true;
            $email = $reset['email'];
        } else {
            $error = "Invalid or expired reset token. Please request a new password reset link.";
        }
    } catch (Exception $e) {
        error_log("Token verification error: " . $e->getMessage());
        $error = "An error occurred. Please try again later.";
    }
}

// Process password reset form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken) {
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    if (empty($password) || strlen($password) < 8) {
        $error = "Password must be at least 8 characters long.";
    } elseif ($password !== $confirmPassword) {
        $error = "Passwords do not match.";
    } else {
        try {
            // Hash the new password
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            
            // Update password based on user type
            if ($userType === 'gym_owner') {
                $stmt = $conn->prepare("UPDATE gym_owners SET password = ? WHERE email = ?");
                $stmt->execute([$hashedPassword, $email]);
                
                // Log the activity
                $stmt = $conn->prepare("
                    SELECT id FROM gym_owners WHERE email = ?
                ");
                $stmt->execute([$email]);
                $owner = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($owner) {
                    $stmt = $conn->prepare("
                        INSERT INTO activity_logs (
                            user_id, user_type, action, details, ip_address, user_agent
                        ) VALUES (?, 'owner', 'password_reset', ?, ?, ?)
                    ");
                    
                    $details = "Password reset via email";
                    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
                    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
                    
                    $stmt->execute([$owner['id'], $details, $ip, $user_agent]);
                }
            } else {
                $stmt = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
                $stmt->execute([$hashedPassword, $email]);
                
                // Log the activity for regular users
                $stmt = $conn->prepare("
                    SELECT id FROM users WHERE email = ?
                ");
                $stmt->execute([$email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user) {
                    $stmt = $conn->prepare("
                        INSERT INTO activity_logs (
                            user_id, user_type, action, details, ip_address, user_agent
                        ) VALUES (?, 'member', 'password_reset', ?, ?, ?)
                    ");
                    
                    $details = "Password reset via email";
                    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
                    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
                    
                    $stmt->execute([$user['id'], $details, $ip, $user_agent]);
                }
            }
            
            // Delete the used token
            $stmt = $conn->prepare("DELETE FROM password_resets WHERE token = ?");
            $stmt->execute([$token]);
            
            $success = "Your password has been reset successfully. You can now login with your new password.";
        } catch (Exception $e) {
            error_log("Password reset error: " . $e->getMessage());
            $error = "An error occurred while resetting your password. Please try again later.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Fitness Hub</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .bg-fitness {
            background-image: linear-gradient(rgba(0, 0, 0, 0.7), rgba(0, 0, 0, 0.7)), url('assets/images/gym-background.jpg');
            background-size: cover;
            background-position: center;
        }
    </style>
</head>
<body class="bg-fitness min-h-screen flex items-center justify-center">
    <div class="max-w-md w-full mx-4">
        <div class="bg-white bg-opacity-10 backdrop-filter backdrop-blur-lg rounded-3xl overflow-hidden shadow-2xl border border-gray-200 border-opacity-20">
            <div class="p-8">
                <div class="text-center mb-8">
                    <img src="assets/images/logo.png" alt="Fitness Hub Logo" class="h-16 mx-auto mb-4">
                    <h2 class="text-3xl font-bold text-white">Reset Password</h2>
                    <p class="text-gray-300 mt-2">Create a new password for your account</p>
                </div>
                
                <?php if ($error): ?>
                <div class="bg-red-500 bg-opacity-20 text-red-100 px-4 py-3 rounded-lg mb-6">
                    <?= htmlspecialchars($error) ?>
                </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                <div class="bg-green-500 bg-opacity-20 text-green-100 px-4 py-3 rounded-lg mb-6">
                    <?= htmlspecialchars($success) ?>
                </div>
                <div class="text-center mt-6">
                    <a href="login.php" class="inline-block py-3 px-6 border border-transparent rounded-xl shadow-sm text-sm font-medium text-black bg-yellow-400 hover:bg-yellow-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-yellow-500 transition duration-150 ease-in-out">
                        Back to Login
                    </a>
                </div>
                <?php elseif ($validToken): ?>
                <form method="POST" action="" class="space-y-6">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                    <input type="hidden" name="user_type" value="<?= htmlspecialchars($userType) ?>">
                    
                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-300 mb-2">New Password</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="fas fa-lock text-gray-400"></i>
                            </div>
                            <input type="password" id="password" name="password" required
                                class="w-full pl-10 px-4 py-3 bg-black bg-opacity-50 border border-gray-700 rounded-xl text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:border-transparent"
                                placeholder="Enter new password">
                            <div class="absolute inset-y-0 right-0 pr-3 flex items-center">
                                <button type="button" id="togglePassword" class="text-gray-400 hover:text-white focus:outline-none">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                        <p class="mt-1 text-sm text-gray-400">Password must be at least 8 characters long</p>
                    </div>
                    
                    <div>
                        <label for="confirm_password" class="block text-sm font-medium text-gray-300 mb-2">Confirm Password</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="fas fa-lock text-gray-400"></i>
                            </div>
                            <input type="password" id="confirm_password" name="confirm_password" required
                                class="w-full pl-10 px-4 py-3 bg-black bg-opacity-50 border border-gray-700 rounded-xl text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:border-transparent"
                                placeholder="Confirm new password">
                            <div class="absolute inset-y-0 right-0 pr-3 flex items-center">
                                <button type="button" id="toggleConfirmPassword" class="text-gray-400 hover:text-white focus:outline-none">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <div>
                        <button type="submit" 
                            class="w-full flex justify-center py-3 px-4 border border-transparent rounded-xl shadow-sm text-sm font-medium text-black bg-yellow-400 hover:bg-yellow-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-yellow-500 transition duration-150 ease-in-out">
                            Reset Password
                        </button>
                    </div>
                </form>
                <?php else: ?>
                <div class="text-center mt-6">
                    <p class="text-gray-300 mb-4">The password reset link is invalid or has expired.</p>
                    <a href="forgot-password.php" class="inline-block py-3 px-6 border border-transparent rounded-xl shadow-sm text-sm font-medium text-black bg-yellow-400 hover:bg-yellow-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-yellow-500 transition duration-150 ease-in-out">
                        Request New Link
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="mt-8 text-center">
            <p class="text-xs text-gray-400">
                © <?= date('Y') ?> Fitness Hub. All rights reserved.
            </p>
        </div>
    </div>
    
    <script>
        // Toggle password visibility
        document.getElementById('togglePassword')?.addEventListener('click', function() {
            const passwordInput = document.getElementById('password');
            const icon = this.querySelector('i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });
        
        document.getElementById('toggleConfirmPassword')?.addEventListener('click', function() {
            const passwordInput = document.getElementById('confirm_password');
            const icon = this.querySelector('i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });
    </script>
</body>
</html>
