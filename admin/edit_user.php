<?php
session_start();

if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: /login.php');
    exit();
}

require_once '../config/database.php';
require_once '../includes/EmailService.php';

$db = new GymDatabase();
$conn = $db->getConnection();
$emailService = new EmailService();

// Initialize variables
$user = [];
$errors = [];
$success = '';

// Get user ID from URL
$user_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$user_id) {
    $_SESSION['error'] = "Invalid user ID.";
    header('Location: users.php');
    exit;
}

// Fetch user data
try {
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        $_SESSION['error'] = "User not found.";
        header('Location: users.php');
        exit;
    }
} catch (PDOException $e) {
    $_SESSION['error'] = "Database error: " . $e->getMessage();
    header('Location: users.php');
    exit;
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate input
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $role = $_POST['role'] ?? '';
    $status = $_POST['status'] ?? '';
    $balance = filter_input(INPUT_POST, 'balance', FILTER_VALIDATE_FLOAT);
    $age = filter_input(INPUT_POST, 'age', FILTER_VALIDATE_INT);
    
    // Check if password should be updated
    $update_password = !empty($_POST['password']);
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Validation
    if (empty($username)) {
        $errors['username'] = "Username is required.";
    }
    
    if (empty($email)) {
        $errors['email'] = "Email is required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = "Invalid email format.";
    } else {
        // Check if email exists for another user
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$email, $user_id]);
        if ($stmt->fetchColumn()) {
            $errors['email'] = "Email is already in use by another user.";
        }
    }
    
    if (!empty($phone) && !preg_match('/^[0-9]{10,15}$/', $phone)) {
        $errors['phone'] = "Phone number must be 10-15 digits.";
    }
    
    if ($update_password) {
        if (strlen($password) < 8) {
            $errors['password'] = "Password must be at least 8 characters.";
        } elseif ($password !== $confirm_password) {
            $errors['confirm_password'] = "Passwords do not match.";
        }
    }
    
    if ($balance === false) {
        $errors['balance'] = "Balance must be a valid number.";
    }
    
    if ($age !== false && ($age < 0 || $age > 120)) {
        $errors['age'] = "Age must be between 0 and 120.";
    }
    
    // If no errors, update user
    if (empty($errors)) {
        try {
            $conn->beginTransaction();
            
            // Build update query
            $query = "UPDATE users SET 
                username = ?, 
                email = ?, 
                phone = ?, 
                city = ?, 
                role = ?, 
                status = ?, 
                balance = ?, 
                age = ?, 
                updated_at = NOW()";
            
            $params = [
                $username,
                $email,
                $phone,
                $city,
                $role,
                $status,
                $balance,
                $age
            ];
            
            // Add password update if needed
            if ($update_password) {
                $query .= ", password = ?";
                $params[] = password_hash($password, PASSWORD_DEFAULT);
            }
            
            $query .= " WHERE id = ?";
            $params[] = $user_id;
            
            // Execute update
            $stmt = $conn->prepare($query);
            $stmt->execute($params);
            
            // Log the activity
            $stmt = $conn->prepare("
                INSERT INTO activity_logs (
                    user_id, user_type, action, details, ip_address, user_agent
                ) VALUES (?, 'admin', ?, ?, ?, ?)
            ");
            
            $details = "Updated user ID: $user_id";
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            
            $stmt->execute([$_SESSION['user_id'], "update_user", $details, $ip, $user_agent]);
            
            $conn->commit();
            
            // Send notification email if status changed
            if ($user['status'] !== $status) {
                $subject = "Your Account Status Has Changed";
                $message = "
                <p>Hello " . htmlspecialchars($username) . ",</p>
                <p>Your account status has been changed to <strong>" . htmlspecialchars($status) . "</strong>.</p>
                <p>If you have any questions, please contact our support team.</p>
                <p>Thank you,<br>The Fitness Hub Team</p>
                ";
                
                $emailService->sendNotificationEmail($email, $subject, $message);
            }
            
            $success = "User updated successfully.";
            
            // Refresh user data
            $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            $conn->rollBack();
            $errors['database'] = "Database error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - FlexFit</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .dashboard-card {
            transition: all 0.3s ease;
        }
        .dashboard-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
        }
        body{
            color: black;
        }
    </style>
</head>
<body class="bg-gray-900 text-white">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="ml-0 lg:ml-64 p-4 sm:p-6 transition-all duration-200">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold text-white">Edit User</h1>
        <div class="flex space-x-2">
            <a href="users.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-arrow-left mr-2"></i> Back to Users
            </a>
            <a href="view_user.php?id=<?= $user_id ?>" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-eye mr-2"></i> View User
            </a>
        </div>
    </div>
    
    <?php if (!empty($errors['database'])): ?>
    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded" role="alert">
        <p><?= htmlspecialchars($errors['database']) ?></p>
    </div>
    <?php endif; ?>
    
    <?php if ($success): ?>
    <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded" role="alert">
        <p><?= htmlspecialchars($success) ?></p>
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
                        <input type="text" id="phone" name="phone" value="<?= htmlspecialchars($user['phone'] ?? '') ?>" 
                            class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50 <?= isset($errors['phone']) ? 'border-red-500' : '' ?>">
                        <?php if (isset($errors['phone'])): ?>
                            <p class="text-red-500 text-sm mt-1"><?= htmlspecialchars($errors['phone']) ?></p>
                        <?php endif; ?>
                    </div>
                    
                    <div>
                        <label for="city" class="block text-sm font-medium text-gray-700 mb-1">City</label>
                        <input type="text" id="city" name="city" value="<?= htmlspecialchars($user['city'] ?? '') ?>" 
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
                        <label for="password" class="block text-sm font-medium text-gray-700 mb-1">New Password</label>
                        <input type="password" id="password" name="password" 
                            class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50 <?= isset($errors['password']) ? 'border-red-500' : '' ?>">
                        <p class="text-sm text-gray-500 mt-1">Leave blank to keep current password</p>
                        <?php if (isset($errors['password'])): ?>
                            <p class="text-red-500 text-sm mt-1"><?= htmlspecialchars($errors['password']) ?></p>
                        <?php endif; ?>
                    </div>
                    
                    <div>
                        <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-1">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" 
                            class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50 <?= isset($errors['confirm_password']) ? 'border-red-500' : '' ?>">
                        <?php if (isset($errors['confirm_password'])): ?>
                            <p class="text-red-500 text-sm mt-1"><?= htmlspecialchars($errors['confirm_password']) ?></p>
                        <?php endif; ?>
                    </div>
                    
                    <div>
                        <p class="text-sm text-gray-500">
                            <strong>Created:</strong> <?= date('M d, Y H:i', strtotime($user['created_at'])) ?><br>
                            <strong>Last Updated:</strong> <?= date('M d, Y H:i', strtotime($user['updated_at'])) ?>
                        </p>
                    </div>
                </div>
            </div>
            
            <div class="flex justify-end space-x-4 pt-4 border-t">
                <a href="users.php" class="px-4 py-2 bg-gray-300 text-gray-800 rounded-lg hover:bg-gray-400">
                    Cancel
                </a>
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                    Update User
                </button>
            </div>
        </form>
    </div>
</div>

