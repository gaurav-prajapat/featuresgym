<?php
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';

$db = new GymDatabase();
$conn = $db->getConnection();
$auth = new Auth($conn);

// If already logged in as admin, redirect to dashboard
if (isset($_SESSION['user_id']) && $_SESSION['role'] === 'admin') {
    header('Location: index.php');
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $email = $_POST['email'];
        $password = $_POST['password'];

        if (empty($email) || empty($password)) {
            throw new Exception("All fields are required");
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid email format");
        }

        // Check for too many failed login attempts
        $stmt = $conn->prepare("SELECT COUNT(*) FROM login_attempts WHERE email = ? AND attempt_time > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
        $stmt->execute([$email]);
        $attempt_count = $stmt->fetchColumn();

        if ($attempt_count >= 5) {
            throw new Exception("Too many failed login attempts. Please try again later.");
        }

        // Verify admin credentials
        $stmt = $conn->prepare("SELECT id, password, role FROM users WHERE email = ? AND role = 'admin'");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password'])) {
            // Log failed attempt
            $stmt = $conn->prepare("INSERT INTO login_attempts (email, attempt_time) VALUES (?, NOW())");
            $stmt->execute([$email]);
            
            throw new Exception("Invalid admin credentials");
        }

        // Clear login attempts on successful login
        $stmt = $conn->prepare("DELETE FROM login_attempts WHERE email = ?");
        $stmt->execute([$email]);

        // Set session variables
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role'] = $user['role'];
        
        // Redirect to admin dashboard
        header('Location: dashboard.php');
        exit();
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - Gym Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body class="bg-gray-900 text-white">
    <div class="min-h-screen flex items-center justify-center">
        <div class="bg-gray-800 p-10 rounded-xl shadow-2xl w-full max-w-md">
            <div class="text-center mb-8">
                <h1 class="text-3xl font-bold text-yellow-400">Admin Login</h1>
                <p class="text-gray-400 mt-2">Access the gym management dashboard</p>
            </div>
            
            <?php if ($error): ?>
                <div class="bg-red-500 text-white p-4 rounded-lg mb-6">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" class="space-y-6">
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-400 mb-2">Email Address</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-envelope text-gray-500"></i>
                        </div>
                        <input type="email" id="email" name="email" class="bg-gray-700 text-white block w-full pl-10 pr-3 py-3 rounded-lg focus:outline-none focus:ring-2 focus:ring-yellow-400" placeholder="admin@example.com" required>
                    </div>
                </div>
                
                <div>
                    <label for="password" class="block text-sm font-medium text-gray-400 mb-2">Password</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-lock text-gray-500"></i>
                        </div>
                        <input type="password" id="password" name="password" class="bg-gray-700 text-white block w-full pl-10 pr-3 py-3 rounded-lg focus:outline-none focus:ring-2 focus:ring-yellow-400" placeholder="••••••••" required>
                    </div>
                </div>
                
                <div>
                    <button type="submit" class="w-full bg-yellow-400 hover:bg-yellow-500 text-black font-bold py-3 px-4 rounded-lg transition duration-300 transform hover:scale-105">
                        Sign In
                    </button>
                </div>
            </form>
            
            <div class="mt-6 text-center">
                <a href="../login.php" class="text-yellow-400 hover:text-yellow-300 text-sm">
                    Return to main login
                </a>
            </div>
        </div>
    </div>
</body>
</html>
