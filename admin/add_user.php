<?php
ob_start();
include '../includes/navbar.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: /gym/views/auth/login.php');
    exit();
}

require_once '../config/database.php';
require_once '../includes/EmailService.php';

$db = new GymDatabase();
$conn = $db->getConnection();
$emailService = new EmailService();

// Initialize variables
$errors = [];
$success = '';
$user = [
    'username' => '',
    'email' => '',
    'phone' => '',
    'city' => '',
    'role' => 'member',
    'status' => 'active',
    'balance' => 0,
    'age' => ''
];

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate input
    $user['username'] = trim($_POST['username'] ?? '');
    $user['email'] = trim($_POST['email'] ?? '');
    $user['phone'] = trim($_POST['phone'] ?? '');
    $user['city'] = trim($_POST['city'] ?? '');
    $user['role'] = $_POST['role'] ?? 'member';
    $user['status'] = $_POST['status'] ?? 'active';
    $user['balance'] = filter_input(INPUT_POST, 'balance', FILTER_VALIDATE_FLOAT) ?? 0;
    $user['age'] = filter_input(INPUT_POST, 'age', FILTER_VALIDATE_INT);
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Validation
    if (empty($user['username'])) {
        $errors['username'] = "Username is required.";
    }
    
    if (empty($user['email'])) {
        $errors['email'] = "Email is required.";
    } elseif (!filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = "Invalid email format.";
    } else {
        // Check if email exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$user['email']]);
        if ($stmt->fetchColumn()) {
            $errors['email'] = "Email is already in use.";
        }
    }
    
    if (!empty($user['phone']) && !preg_match('/^[0-9]{10,15}$/', $user['phone'])) {
        $errors['phone'] = "Phone number must be 10-15 digits.";
    }
    
    if (empty($password)) {
        $errors['password'] = "Password is required.";
    } elseif (strlen($password) < 8) {
        $errors['password'] = "Password must be at least 8 characters.";
    } elseif ($password !== $confirm_password) {
        $errors['confirm_password'] = "Passwords do not match.";
    }
    
    if ($user['balance'] === false) {
        $errors['balance'] = "Balance must be a valid number.";
    }
    
    if ($user['age'] !== false && ($user['age'] < 0 || $user['age'] > 120)) {
        $errors['age'] = "Age must be between 0 and 120.";
    }
    
    // If no errors, create user
    if (empty($errors)) {
        try {
            $conn->beginTransaction();
            
            // Hash password
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            
            // Insert user
            $stmt = $conn->prepare("
                INSERT INTO users (
                    username, email, password, phone, city, role, status, balance, age, created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()
                )
            ");
            
            $stmt->execute([
                $user['username'],
                $user['email'],
                $password_hash,
                $user['phone'],
                $user['city'],
                $user['role'],
                $user['status'],
                $user['balance'],
                $user['age']
            ]);
            
            $new_user_id = $conn->lastInsertId();
            
            // Log the activity
            $stmt = $conn->prepare("
                INSERT INTO activity_logs (
                    user_id, user_type, action, details, ip_address, user_agent
                ) VALUES (?, 'admin', ?, ?, ?, ?)
            ");
            
            $details = "Created new user ID: $new_user_id";
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            
            $stmt->execute([$_SESSION['user_id'], "create_user", $details, $ip, $user_agent]);
            
            $conn->commit();
            
            // Send welcome email
            $emailService->sendWelcomeEmail($user['email'], $user['username']);
            
            // Set success message and redirect
            $_SESSION['success'] = "User created successfully.";
            header('Location: users.php');
            exit;
            
        } catch (PDOException $e) {
            $conn->rollBack();
            $errors['database'] = "Database error: " . $e->getMessage();
        }
    }
}
?>

<div class="container mx-auto px-4 py-8 pt-20">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Add New User</h1>
        <a href="users.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg flex items-center">
            <i class="fas fa-arrow-left mr-2"></i> Back to Users
        </a>
    </div>
    
    <?php if (!empty($errors['database'])): ?>
    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded" role="alert">
        <p><?= htmlspecialchars($errors['database']) ?></p>
    </div>
    <?php endif; ?>
    
    <div class="bg-white shadow-md rounded-lg p-6">
        <form method="POST" action="" class="space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- User Information -->
                <div class="space-y-6">
                    <h2 class="text-xl font-semibold border-b pb-2">User Information</h2>
                    
                    <div>
                        <label for="username" class="block text-sm font-medium text-gray-700 mb-1">Username *</label>
                        <input type="text" id="username" name="username" value="<?= htmlspecialchars($user['username']) ?>" 
                            class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50 <?= isset($errors['username']) ? 'border-red-500' : '' ?>">
                        <?php if (isset($errors['username'])): ?>
                            <p class="text-red-500 text-sm mt-1"><?= htmlspecialchars($errors['username']) ?></p>
                        <?php endif; ?>
                    </div>
                    
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email *</label>
                        <input type="email" id="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" 
                            class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50 <?= isset($errors['email']) ? 'border-red-500' : '' ?>">
                        <?php if (isset($errors['email'])): ?>
                            <p class="text-red-500 text-sm mt-1"><?= htmlspecialchars($errors['email']) ?></p>
                        <?php endif; ?>
                    </div>
                    
                    <div>
                        <label for="phone" class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                        <input type="text" id="phone" name="phone" value="<?= htmlspecialchars($user['phone']) ?>" 
                        class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50 <?= isset($errors['phone']) ? 'border-red-500' : '' ?>">
                        <?php if (isset($errors['phone'])): ?>
                            <p class="text-red-500 text-sm mt-1"><?= htmlspecialchars($errors['phone']) ?></p>
                        <?php endif; ?>
                    </div>
                    
                    <div>
                        <label for="city" class="block text-sm font-medium text-gray-700 mb-1">City</label>
                        <input type="text" id="city" name="city" value="<?= htmlspecialchars($user['city']) ?>" 
                            class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                    </div>
                    
                    <div>
                        <label for="age" class="block text-sm font-medium text-gray-700 mb-1">Age</label>
                        <input type="number" id="age" name="age" value="<?= htmlspecialchars($user['age'] ?? '') ?>" 
                            class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50 <?= isset($errors['age']) ? 'border-red-500' : '' ?>">
                        <?php if (isset($errors['age'])): ?>
                            <p class="text-red-500 text-sm mt-1"><?= htmlspecialchars($errors['age']) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Account Settings -->
                <div class="space-y-6">
                    <h2 class="text-xl font-semibold border-b pb-2">Account Settings</h2>
                    
                    <div>
                        <label for="role" class="block text-sm font-medium text-gray-700 mb-1">Role *</label>
                        <select id="role" name="role" class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                            <option value="member" <?= $user['role'] === 'member' ? 'selected' : '' ?>>Member</option>
                            <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                            <option value="gym_partner" <?= $user['role'] === 'gym_partner' ? 'selected' : '' ?>>Gym Partner</option>
                        </select>
                    </div>
                    
                    <div>
                        <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Status *</label>
                        <select id="status" name="status" class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                            <option value="active" <?= $user['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $user['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            <option value="suspended" <?= $user['status'] === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                        </select>
                    </div>
                    
                    <div>
                        <label for="balance" class="block text-sm font-medium text-gray-700 mb-1">Balance (₹)</label>
                        <input type="number" id="balance" name="balance" value="<?= htmlspecialchars($user['balance']) ?>" step="0.01" 
                            class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50 <?= isset($errors['balance']) ? 'border-red-500' : '' ?>">
                        <?php if (isset($errors['balance'])): ?>
                            <p class="text-red-500 text-sm mt-1"><?= htmlspecialchars($errors['balance']) ?></p>
                        <?php endif; ?>
                    </div>
                    
                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Password *</label>
                        <input type="password" id="password" name="password" 
                            class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50 <?= isset($errors['password']) ? 'border-red-500' : '' ?>">
                        <?php if (isset($errors['password'])): ?>
                            <p class="text-red-500 text-sm mt-1"><?= htmlspecialchars($errors['password']) ?></p>
                        <?php endif; ?>
                    </div>
                    
                    <div>
                        <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-1">Confirm Password *</label>
                        <input type="password" id="confirm_password" name="confirm_password" 
                            class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50 <?= isset($errors['confirm_password']) ? 'border-red-500' : '' ?>">
                        <?php if (isset($errors['confirm_password'])): ?>
                            <p class="text-red-500 text-sm mt-1"><?= htmlspecialchars($errors['confirm_password']) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="flex justify-end space-x-4 pt-4 border-t">
                <a href="users.php" class="px-4 py-2 bg-gray-300 text-gray-800 rounded-lg hover:bg-gray-400">
                    Cancel
                </a>
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                    Create User
                </button>
            </div>
        </form>
    </div>
</div>

