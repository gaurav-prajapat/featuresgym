<?php
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/EmailService.php';

// Initialize database connection
$db = new GymDatabase();
$conn = $db->getConnection();
$auth = new Auth($conn);
$emailService = new EmailService();

$error = '';
$success = '';
$email = '';
$userType = 'member'; // Default user type

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $userType = $_POST['user_type'] ?? 'member';
    
    if (empty($email)) {
        $error = "Please enter your email address.";
    } else {
        try {
            // Generate a unique token
            $token = bin2hex(random_bytes(32));
            $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
            
            // Check if email exists based on user type
            if ($userType === 'gym_owner') {
                $stmt = $conn->prepare("SELECT id, name FROM gym_owners WHERE email = ?");
                $stmt->execute([$email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user) {
                    // Store token in database
                    $stmt = $conn->prepare("
                        INSERT INTO password_resets (email, token, expiry, user_type) 
                        VALUES (?, ?, ?, 'gym_owner')
                        ON DUPLICATE KEY UPDATE token = ?, expiry = ?
                    ");
                    $stmt->execute([$email, $token, $expiry, $token, $expiry]);
                    
                    // Send email with reset link
                    $resetLink = "https://" . $_SERVER['HTTP_HOST'] . "/reset-password.php?token=" . $token . "&type=gym_owner";
                    $subject = "Password Reset Request - Fitness Hub";
                    $message = "
                        <html>
                        <head>
                            <title>Password Reset Request</title>
                        </head>
                        <body>
                            <h2>Hello " . htmlspecialchars($user['name']) . ",</h2>
                            <p>We received a request to reset your password for your Fitness Hub gym owner account.</p>
                            <p>To reset your password, please click the link below:</p>
                            <p><a href='" . $resetLink . "'>Reset Your Password</a></p>
                            <p>This link will expire in 1 hour.</p>
                            <p>If you did not request a password reset, please ignore this email or contact support if you have concerns.</p>
                            <p>Regards,<br>Fitness Hub Team</p>
                        </body>
                        </html>
                    ";
                    
                    // Send email using EmailService
                    if ($emailService->send($email, $subject, $message)) {
                        $success = "A password reset link has been sent to your email address.";
                    } else {
                        $error = "Failed to send password reset email. Please try again later.";
                    }
                } else {
                    // Don't reveal if email exists or not for security
                    $success = "If your email is registered, you will receive a password reset link shortly.";
                }
            } else {
                // Regular user
                $stmt = $conn->prepare("SELECT id, username FROM users WHERE email = ?");
                $stmt->execute([$email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user) {
                    // Store token in database
                    $stmt = $conn->prepare("
                        INSERT INTO password_resets (email, token, expiry, user_type) 
                        VALUES (?, ?, ?, 'member')
                        ON DUPLICATE KEY UPDATE token = ?, expiry = ?
                    ");
                    $stmt->execute([$email, $token, $expiry, $token, $expiry]);
                    
                    // Send email with reset link
                    $resetLink = "https://" . $_SERVER['HTTP_HOST'] . "/reset-password.php?token=" . $token . "&type=member";
                    $subject = "Password Reset Request - Fitness Hub";
                    $message = "
                        <html>
                        <head>
                            <title>Password Reset Request</title>
                        </head>
                        <body>
                            <h2>Hello " . htmlspecialchars($user['username']) . ",</h2>
                            <p>We received a request to reset your password for your Fitness Hub account.</p>
                            <p>To reset your password, please click the link below:</p>
                            <p><a href='" . $resetLink . "'>Reset Your Password</a></p>
                            <p>This link will expire in 1 hour.</p>
                            <p>If you did not request a password reset, please ignore this email or contact support if you have concerns.</p>
                            <p>Regards,<br>Fitness Hub Team</p>
                        </body>
                        </html>
                    ";
                    
                    // Send email using EmailService
                    if ($emailService->send($email, $subject, $message)) {
                        $success = "A password reset link has been sent to your email address.";
                    } else {
                        $error = "Failed to send password reset email. Please try again later.";
                    }
                } else {
                    // Don't reveal if email exists or not for security
                    $success = "If your email is registered, you will receive a password reset link shortly.";
                }
            }
        } catch (Exception $e) {
            error_log("Password reset error: " . $e->getMessage());
            $error = "An error occurred. Please try again later.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Fitness Hub</title>
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
                    <h2 class="text-3xl font-bold text-white">Forgot Password</h2>
                    <p class="text-gray-300 mt-2">Enter your email to reset your password</p>
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
                <?php endif; ?>
                
                <!-- User Type Selector -->
                <div class="flex justify-center mb-6">
                    <div class="inline-flex rounded-md shadow-sm" role="group">
                        <button type="button" id="memberBtn" 
                            class="px-4 py-2 text-sm font-medium text-white bg-yellow-500 rounded-l-lg focus:z-10 focus:ring-2 focus:ring-yellow-400 active-tab">
                            Member
                        </button>
                        <button type="button" id="gymOwnerBtn"
                            class="px-4 py-2 text-sm font-medium text-white bg-gray-700 rounded-r-lg focus:z-10 focus:ring-2 focus:ring-yellow-400">
                            Gym Owner
                        </button>
                    </div>
                </div>
                
                <?php if (!$success): ?>
                <form method="POST" action="" class="space-y-6">
                    <input type="hidden" name="user_type" id="userType" value="member">
                    
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-300 mb-2">Email Address</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="fas fa-envelope text-gray-400"></i>
                            </div>
                            <input type="email" id="email" name="email" required value="<?= htmlspecialchars($email) ?>"
                                class="w-full pl-10 px-4 py-3 bg-black bg-opacity-50 border border-gray-700 rounded-xl text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:border-transparent"
                                placeholder="Enter your email">
                        </div>
                    </div>
                    
                    <div>
                        <button type="submit" 
                            class="w-full flex justify-center py-3 px-4 border border-transparent rounded-xl shadow-sm text-sm font-medium text-black bg-yellow-400 hover:bg-yellow-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-yellow-500 transition duration-150 ease-in-out">
                            Send Reset Link
                        </button>
                    </div>
                </form>
                <?php else: ?>
                <div class="text-center mt-6">
                    <p class="text-gray-300 mb-4">Please check your email for the password reset link.</p>
                    <a href="login.php" class="inline-block py-3 px-6 border border-transparent rounded-xl shadow-sm text-sm font-medium text-black bg-yellow-400 hover:bg-yellow-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-yellow-500 transition duration-150 ease-in-out">
                        Back to Login
                    </a>
                </div>
                <?php endif; ?>
                
                <div class="mt-6 text-center">
                    <p class="text-sm text-gray-300">
                        Remember your password? 
                        <a href="login.php" class="font-medium text-yellow-400 hover:text-yellow-300">
                            Sign in
                        </a>
                    </p>
                </div>
            </div>
        </div>
        
        <div class="mt-8 text-center">
            <p class="text-xs text-gray-400">
                &copy; <?= date('Y') ?> Fitness Hub. All rights reserved.
            </p>
        </div>
    </div>
    
    <script>
        // User type toggle
        const memberBtn = document.getElementById('memberBtn');
        const gymOwnerBtn = document.getElementById('gymOwnerBtn');
        const userTypeInput = document.getElementById('userType');
        
        memberBtn.addEventListener('click', function() {
            memberBtn.classList.add('bg-yellow-500');
            memberBtn.classList.remove('bg-gray-700');
            gymOwnerBtn.classList.add('bg-gray-700');
            gymOwnerBtn.classList.remove('bg-yellow-500');
            userTypeInput.value = 'member';
        });
        
        gymOwnerBtn.addEventListener('click', function() {
            gymOwnerBtn.classList.add('bg-yellow-500');
            gymOwnerBtn.classList.remove('bg-gray-700');
            memberBtn.classList.add('bg-gray-700');
            memberBtn.classList.remove('bg-yellow-500');
            userTypeInput.value = 'gym_owner';
        });
    </script>
</body>
</html>
