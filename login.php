<?php
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    // Redirect based on role
    if (isset($_SESSION['role'])) {
        switch ($_SESSION['role']) {
            case 'admin':
                header('Location: admin');
                exit;
            case 'member':
                header('Location: dashboard.php');
                exit;
            default:
                header('Location: dashboard.php');
                exit;
        }
    } else {
        header('Location: dashboard.php');
        exit;
    }
} elseif (isset($_SESSION['owner_id'])) {
    // Redirect gym owner
    header('Location: gym/dashboard.php');
    exit;
}

// Initialize database connection
$db = new GymDatabase();
$conn = $db->getConnection();
$auth = new Auth($conn);

$error = '';
$success = '';

// Process login form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $userType = $_POST['user_type'] ?? 'member'; // Default to member
    
    if (empty($email) || empty($password)) {
        $error = "Please enter both email and password.";
    } else {
        $loginSuccess = false;
        
        if ($userType === 'gym_owner') {
            // Try gym owner login
            $loginSuccess = $auth->loginGymOwner($email, $password);
            if ($loginSuccess) {
                header('Location: gym/dashboard.php');
                exit;
            }
        } else {
            // Try regular user login
            $loginSuccess = $auth->login($email, $password);
            if ($loginSuccess) {
                // Redirect based on role
                if ($_SESSION['role'] === 'admin') {
                    header('Location: admin');
                } else {
                    header('Location: dashboard.php');
                }
                exit;
            }
        }
        
        if (!$loginSuccess) {
            $error = "Invalid email or password. Please try again.";
        }
    }
}

// Check for messages from other pages
if (isset($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}

if (isset($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Fitness Hub</title>
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
                    <h2 class="text-3xl font-bold text-white">Welcome Back</h2>
                    <p class="text-gray-300 mt-2">Sign in to your account</p>
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
                
                <form method="POST" action="" class="space-y-6">
                    <input type="hidden" name="user_type" id="userType" value="member">
                    
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-300 mb-2">Email Address</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="fas fa-envelope text-gray-400"></i>
                            </div>
                            <input type="email" id="email" name="email" required
                                class="w-full pl-10 px-4 py-3 bg-black bg-opacity-50 border border-gray-700 rounded-xl text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:border-transparent"
                                placeholder="Enter your email">
                        </div>
                    </div>
                    
                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-300 mb-2">Password</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="fas fa-lock text-gray-400"></i>
                            </div>
                            <input type="password" id="password" name="password" required
                                class="w-full pl-10 px-4 py-3 bg-black bg-opacity-50 border border-gray-700 rounded-xl text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:border-transparent"
                                placeholder="Enter your password">
                            <div class="absolute inset-y-0 right-0 pr-3 flex items-center">
                                <button type="button" id="togglePassword" class="text-gray-400 hover:text-white focus:outline-none">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <input id="remember" name="remember" type="checkbox" 
                                class="h-4 w-4 text-yellow-500 focus:ring-yellow-500 border-gray-700 rounded bg-black bg-opacity-50">
                            <label for="remember" class="ml-2 block text-sm text-gray-300">
                                Remember me
                            </label>
                        </div>
                        
                        <a href="forgot-password.php" class="text-sm font-medium text-yellow-400 hover:text-yellow-300">
                            Forgot your password?
                        </a>
                    </div>
                    
                    <div>
                        <button type="submit" 
                            class="w-full flex justify-center py-3 px-4 border border-transparent rounded-xl shadow-sm text-sm font-medium text-black bg-yellow-400 hover:bg-yellow-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-yellow-500 transition duration-150 ease-in-out">
                            Sign in
                        </button>
                    </div>
                </form>
                
                <div class="mt-6 text-center">
                    <p class="text-sm text-gray-300" id="registerText">
                        Don't have an account? 
                        <a href="register.php" class="font-medium text-yellow-400 hover:text-yellow-300">
                            Sign up
                        </a>
                    </p>
                    <p class="text-sm text-gray-300 hidden" id="registerGymOwnerText">
                        Want to list your gym? 
                        <a href="gym/register.html" class="font-medium text-yellow-400 hover:text-yellow-300">
                            Register as a gym owner
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
        // Toggle password visibility
        document.getElementById('togglePassword').addEventListener('click', function() {
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
        
        // User type toggle
        const memberBtn = document.getElementById('memberBtn');
        const gymOwnerBtn = document.getElementById('gymOwnerBtn');
        const userTypeInput = document.getElementById('userType');
        const registerText = document.getElementById('registerText');
        const registerGymOwnerText = document.getElementById('registerGymOwnerText');
        
        memberBtn.addEventListener('click', function() {
            memberBtn.classList.add('bg-yellow-500');
            memberBtn.classList.remove('bg-gray-700');
            gymOwnerBtn.classList.add('bg-gray-700');
            gymOwnerBtn.classList.remove('bg-yellow-500');
            userTypeInput.value = 'member';
            registerText.classList.remove('hidden');
            registerGymOwnerText.classList.add('hidden');
        });
        
        gymOwnerBtn.addEventListener('click', function() {
            gymOwnerBtn.classList.add('bg-yellow-500');
            gymOwnerBtn.classList.remove('bg-gray-700');
            memberBtn.classList.add('bg-gray-700');
            memberBtn.classList.remove('bg-yellow-500');
            userTypeInput.value = 'gym_owner';
            registerText.classList.add('hidden');
            registerGymOwnerText.classList.remove('hidden');
        });
    </script>
</body>
</html>

