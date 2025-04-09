<?php
require_once 'config/database.php';
include 'includes/navbar.php';

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$db = new GymDatabase();
$conn = $db->getConnection();

// Initialize variables
$success_message = '';
$error_message = '';
$user = null;

try {
    // Fetch user data
    $stmt = $conn->prepare("
        SELECT id, username, email, phone, profile_image, city, status, created_at, role
        FROM users 
        WHERE id = ?
    ");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        throw new Exception("User not found");
    }
    
    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Generate CSRF token if not exists
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        
        // Verify CSRF token
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            throw new Exception("Security validation failed. Please try again.");
        }
        
        // Determine which form was submitted
        if (isset($_POST['update_profile'])) {
            // Profile update
            $username = trim(filter_input(INPUT_POST, 'username', FILTER_SANITIZE_STRING));
            $email = trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL));
            $phone = trim(filter_input(INPUT_POST, 'phone', FILTER_SANITIZE_STRING));
            $city = trim(filter_input(INPUT_POST, 'city', FILTER_SANITIZE_STRING));
            
            // Validate inputs
            if (empty($username)) {
                throw new Exception("Username is required");
            }
            
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Valid email is required");
            }
            
            // Check if email is already in use by another user
            $emailCheckStmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $emailCheckStmt->execute([$email, $user_id]);
            if ($emailCheckStmt->rowCount() > 0) {
                throw new Exception("Email is already in use by another account");
            }
            
            // Begin transaction
            $conn->beginTransaction();
            
            // Update profile
            $updateStmt = $conn->prepare("
                UPDATE users 
                SET username = ?, email = ?, phone = ?, city = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $updateStmt->execute([$username, $email, $phone, $city, $user_id]);
            
            // Handle profile image upload
            if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
                $file_type = $_FILES['profile_image']['type'];
                
                if (!in_array($file_type, $allowed_types)) {
                    throw new Exception("Only JPG, PNG and GIF images are allowed");
                }
                
                $max_size = 5 * 1024 * 1024; // 5MB
                if ($_FILES['profile_image']['size'] > $max_size) {
                    throw new Exception("Image size should not exceed 5MB");
                }
                
                $upload_dir = 'uploads/profile_images/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $file_extension = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
                $new_filename = 'profile_' . $user_id . '_' . time() . '.' . $file_extension;
                $destination = $upload_dir . $new_filename;
                
                if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $destination)) {
                    // Update profile image in database
                    $imageStmt = $conn->prepare("UPDATE users SET profile_image = ? WHERE id = ?");
                    $imageStmt->execute([$new_filename, $user_id]);
                    
                    // Delete old profile image if exists
                    if (!empty($user['profile_image']) && $user['profile_image'] !== $new_filename) {
                        $old_image_path = $upload_dir . $user['profile_image'];
                        if (file_exists($old_image_path)) {
                            unlink($old_image_path);
                        }
                    }
                } else {
                    throw new Exception("Failed to upload profile image");
                }
            }
            
            // Log the activity
            $logStmt = $conn->prepare("
                INSERT INTO activity_logs (
                    user_id, user_type, action, details, ip_address, user_agent
                ) VALUES (?, 'member', 'update_profile', 'Updated profile settings', ?, ?)
            ");
            $logStmt->execute([
                $user_id,
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT']
            ]);
            
            // Commit transaction
            $conn->commit();
            
            // Update session data
            $_SESSION['username'] = $username;
            $_SESSION['email'] = $email;
            
            $success_message = "Profile updated successfully!";
            
            // Refresh user data
            $stmt->execute([$user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
        } elseif (isset($_POST['change_password'])) {
            // Password change
            $current_password = $_POST['current_password'];
            $new_password = $_POST['new_password'];
            $confirm_password = $_POST['confirm_password'];
            
            // Validate inputs
            if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
                throw new Exception("All password fields are required");
            }
            
            if ($new_password !== $confirm_password) {
                throw new Exception("New passwords do not match");
            }
            
            if (strlen($new_password) < 8) {
                throw new Exception("Password must be at least 8 characters long");
            }
            
            // Verify current password
            $passwordStmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
            $passwordStmt->execute([$user_id]);
            $current_hash = $passwordStmt->fetchColumn();
            
            if (!password_verify($current_password, $current_hash)) {
                throw new Exception("Current password is incorrect");
            }
            
            // Hash new password
            $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
            
            // Begin transaction
            $conn->beginTransaction();
            
            // Update password
            $updateStmt = $conn->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
            $updateStmt->execute([$new_hash, $user_id]);
            
            // Log the activity
            $logStmt = $conn->prepare("
                INSERT INTO activity_logs (
                    user_id, user_type, action, details, ip_address, user_agent
                ) VALUES (?, 'member', 'change_password', 'Changed account password', ?, ?)
            ");
            $logStmt->execute([
                $user_id,
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT']
            ]);
            
            // Commit transaction
            $conn->commit();
            
            $success_message = "Password changed successfully!";
            
        } elseif (isset($_POST['notification_preferences'])) {
            // Notification preferences
            $email_notifications = isset($_POST['email_notifications']) ? 1 : 0;
            $sms_notifications = isset($_POST['sms_notifications']) ? 1 : 0;
            $push_notifications = isset($_POST['push_notifications']) ? 1 : 0;
            
            // Begin transaction
            $conn->beginTransaction();
            
            // Check if preferences already exist
            $checkStmt = $conn->prepare("
                SELECT id FROM user_preferences WHERE user_id = ?
            ");
            $checkStmt->execute([$user_id]);
            
            if ($checkStmt->rowCount() > 0) {
                // Update existing preferences
                $updateStmt = $conn->prepare("
                    UPDATE user_preferences 
                    SET email_notifications = ?, sms_notifications = ?, push_notifications = ?
                    WHERE user_id = ?
                ");
                $updateStmt->execute([$email_notifications, $sms_notifications, $push_notifications, $user_id]);
            } else {
                // Insert new preferences
                $insertStmt = $conn->prepare("
                    INSERT INTO user_preferences (
                        user_id, email_notifications, sms_notifications, push_notifications
                    ) VALUES (?, ?, ?, ?)
                ");
                $insertStmt->execute([$user_id, $email_notifications, $sms_notifications, $push_notifications]);
            }
            
            // Log the activity
            $logStmt = $conn->prepare("
                INSERT INTO activity_logs (
                    user_id, user_type, action, details, ip_address, user_agent
                ) VALUES (?, 'member', 'update_preferences', 'Updated notification preferences', ?, ?)
            ");
            $logStmt->execute([
                $user_id,
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT']
            ]);
            
            // Commit transaction
            $conn->commit();
            
            $success_message = "Notification preferences updated successfully!";
        }
    }
    
    // Fetch notification preferences
    $prefStmt = $conn->prepare("
        SELECT email_notifications, sms_notifications, push_notifications
        FROM user_preferences
        WHERE user_id = ?
    ");
    $prefStmt->execute([$user_id]);
    $preferences = $prefStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$preferences) {
        // Default preferences if not set
        $preferences = [
            'email_notifications' => 1,
            'sms_notifications' => 0,
            'push_notifications' => 1
        ];
    }
    
    // Generate CSRF token
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    
} catch (Exception $e) {
    $error_message = $e->getMessage();
    
    // Log the error
    error_log("Settings page error: " . $e->getMessage());
    
    // Rollback transaction if active
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
}
?>

<div class="min-h-screen bg-gradient-to-b from-gray-900 to-black pt-24 pb-12">
    <div class="container mx-auto px-4 sm:px-6 lg:px-8 max-w-6xl">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-3xl font-bold text-white">Account Settings</h1>
            <a href="dashboard.php" class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                <i class="fas fa-arrow-left mr-2"></i> Back to Dashboard
            </a>
        </div>
        
        <?php if (!empty($success_message)): ?>
            <div class="bg-green-500 bg-opacity-80 text-white p-4 rounded-xl mb-6 animate-fade-in">
                <div class="flex items-center">
                    <i class="fas fa-check-circle mr-2"></i>
                    <?= htmlspecialchars($success_message) ?>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($error_message)): ?>
            <div class="bg-red-500 bg-opacity-80 text-white p-4 rounded-xl mb-6 animate-fade-in">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    <?= htmlspecialchars($error_message) ?>
                </div>
            </div>
        <?php endif; ?>
        
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Sidebar -->
            <div class="lg:col-span-1">
                <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl overflow-hidden shadow-xl">
                    <div class="p-6 text-center">
                        <div class="relative inline-block mb-4">
                            <div class="w-32 h-32 rounded-full overflow-hidden mx-auto border-4 border-yellow-500">
                                <?php if (!empty($user['profile_image'])): ?>
                                    <img src="uploads/profile_images/<?= htmlspecialchars($user['profile_image']) ?>" alt="Profile" class="w-full h-full object-cover">
                                <?php else: ?>
                                    <div class="w-full h-full bg-gray-700 flex items-center justify-center">
                                        <i class="fas fa-user text-4xl text-gray-400"></i>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <label for="profile_image_upload" class="absolute bottom-0 right-0 bg-yellow-500 hover:bg-yellow-600 text-black p-2 rounded-full cursor-pointer transition-colors duration-200">
                                <i class="fas fa-camera"></i>
                                <span class="sr-only">Change profile picture</span>
                            </label>
                        </div>
                        
                        <h2 class="text-xl font-bold text-white mb-1"><?= htmlspecialchars($user['username']) ?></h2>
                        <p class="text-gray-400 mb-4"><?= htmlspecialchars($user['email']) ?></p>
                        
                        <div class="flex justify-center space-x-2 mb-6">
                            <span class="px-3 py-1 bg-blue-500 bg-opacity-20 text-blue-300 rounded-full text-sm">
                                <i class="fas fa-user mr-1"></i> <?= ucfirst($user['role']) ?>
                            </span>
                            <span class="px-3 py-1 bg-green-500 bg-opacity-20 text-green-300 rounded-full text-sm">
                                <i class="fas fa-circle mr-1 <?= $user['status'] === 'active' ? 'text-green-500' : 'text-gray-500' ?>"></i> 
                                <?= ucfirst($user['status']) ?>
                            </span>
                        </div>
                        
                        <div class="border-t border-gray-700 pt-4">
                            <div class="text-left">
                                <div class="flex items-center justify-between mb-3">
                                    <span class="text-gray-400">Member since</span>
                                    <span class="text-white"><?= date('M d, Y', strtotime($user['created_at'])) ?></span>
                                </div>
                                <?php if (!empty($user['city'])): ?>
                                <div class="flex items-center justify-between mb-3">
                                    <span class="text-gray-400">Location</span>
                                    <span class="text-white"><?= htmlspecialchars($user['city']) ?></span>
                                </div>
                                <?php endif; ?>
                                <div class="flex items-center justify-between">
                                    <span class="text-gray-400">Last login</span>
                                    <span class="text-white">
                                        <?php
                                        // Get last login time
                                        $loginStmt = $conn->prepare("
                                            SELECT login_time FROM login_history 
                                            WHERE user_id = ? AND user_type = 'member'
                                            ORDER BY login_time DESC LIMIT 1
                                        ");
                                        $loginStmt->execute([$user_id]);
                                        $lastLogin = $loginStmt->fetchColumn();
                                        echo $lastLogin ? date('M d, Y h:i A', strtotime($lastLogin)) : 'N/A';
                                        ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-gray-900 p-6">
                        <h3 class="text-lg font-semibold text-white mb-4">Navigation</h3>
                        <nav class="space-y-2">
                            <a href="#profile" class="nav-link flex items-center px-4 py-3 rounded-lg bg-gray-800 text-white">
                                <i class="fas fa-user-circle w-6"></i>
                                <span>Profile Information</span>
                            </a>
                            <a href="#security" class="nav-link flex items-center px-4 py-3 rounded-lg text-gray-400 hover:bg-gray-800 hover:text-white transition-colors duration-200">
                                <i class="fas fa-lock w-6"></i>
                                <span>Security</span>
                            </a>
                            <a href="#notifications" class="nav-link flex items-center px-4 py-3 rounded-lg text-gray-400 hover:bg-gray-800 hover:text-white transition-colors duration-200">
                                <i class="fas fa-bell w-6"></i>
                                <span>Notifications</span>
                            </a>
                            <a href="#activity" class="nav-link flex items-center px-4 py-3 rounded-lg text-gray-400 hover:bg-gray-800 hover:text-white transition-colors duration-200">
                                <i class="fas fa-history w-6"></i>
                                <span>Activity Log</span>
                            </a>
                        </nav>
                    </div>
                </div>
            </div>
            
            <!-- Main Content -->
            <div class="lg:col-span-2 space-y-8">
                <!-- Profile Information -->
                <section id="profile" class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl overflow-hidden shadow-xl">
                    <div class="p-6 border-b border-gray-700">
                        <h2 class="text-xl font-bold text-white">Profile Information</h2>
                        <p class="text-gray-400 text-sm">Update your account's profile information</p>
                    </div>
                    
                    <div class="p-6">
                        <form method="POST" action="settings.php" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="update_profile" value="1">
                            
                            <div class="hidden">
                                <input type="file" id="profile_image_upload" name="profile_image" accept="image/*">
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                                <div>
                                    <label for="username" class="block text-sm font-medium text-gray-400 mb-1">Username</label>
                                    <input type="text" id="username" name="username" value="<?= htmlspecialchars($user['username']) ?>" required
                                        class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                </div>
                                
                                <div>
                                    <label for="email" class="block text-sm font-medium text-gray-400 mb-1">Email Address</label>
                                    <input type="email" id="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required
                                        class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                </div>
                                
                                <div>
                                    <label for="phone" class="block text-sm font-medium text-gray-400 mb-1">Phone Number</label>
                                    <input type="tel" id="phone" name="phone" value="<?= htmlspecialchars($user['phone'] ?? '') ?>"
                                        class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                </div>
                                
                                <div>
                                    <label for="city" class="block text-sm font-medium text-gray-400 mb-1">City</label>
                                    <input type="text" id="city" name="city" value="<?= htmlspecialchars($user['city'] ?? '') ?>"
                                        class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                </div>
                            </div>
                            
                            <div class="flex justify-end">
                                <button type="submit" class="bg-yellow-500 hover:bg-yellow-600 text-black font-medium px-6 py-2 rounded-lg transition-colors duration-200">
                                    Save Changes
                                </button>
                            </div>
                        </form>
                    </div>
                </section>
                
                <!-- Security -->
                <section id="security" class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl overflow-hidden shadow-xl">
                    <div class="p-6 border-b border-gray-700">
                        <h2 class="text-xl font-bold text-white">Security</h2>
                        <p class="text-gray-400 text-sm">Update your password and manage your account security</p>
                    </div>
                    
                    <div class="p-6">
                        <form method="POST" action="settings.php">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="change_password" value="1">
                            
                            <div class="space-y-6 mb-6">
                                <div>
                                    <label for="current_password" class="block text-sm font-medium text-gray-400 mb-1">Current Password</label>
                                    <input type="password" id="current_password" name="current_password" required
                                        class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                </div>
                                
                                <div>
                                    <label for="new_password" class="block text-sm font-medium text-gray-400 mb-1">New Password</label>
                                    <input type="password" id="new_password" name="new_password" required
                                        class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                    <p class="text-sm text-gray-500 mt-1">Password must be at least 8 characters long</p>
                                </div>
                                
                                <div>
                                    <label for="confirm_password" class="block text-sm font-medium text-gray-400 mb-1">Confirm Password</label>
                                    <input type="password" id="confirm_password" name="confirm_password" required
                                        class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                </div>
                            </div>
                            
                            <div class="flex justify-end">
                                <button type="submit" class="bg-yellow-500 hover:bg-yellow-600 text-black font-medium px-6 py-2 rounded-lg transition-colors duration-200">
                                    Change Password
                                </button>
                            </div>
                        </form>
                        
                        <div class="mt-8 pt-6 border-t border-gray-700">
                            <h3 class="text-lg font-semibold text-white mb-4">Login Sessions</h3>
                            
                            <div class="space-y-4">
                                <?php
                                // Get recent login sessions
                                $sessionsStmt = $conn->prepare("
                                    SELECT ip_address, user_agent, login_time, logout_time
                                    FROM login_history
                                    WHERE user_id = ? AND user_type = 'member'
                                    ORDER BY login_time DESC
                                    LIMIT 3
                                ");
                                $sessionsStmt->execute([$user_id]);
                                $sessions = $sessionsStmt->fetchAll(PDO::FETCH_ASSOC);
                                
                                if (count($sessions) > 0):
                                    foreach ($sessions as $session):
                                        // Parse user agent
                                        $ua = $session['user_agent'];
                                        $browser = 'Unknown Browser';
                                        $os = 'Unknown OS';
                                        
                                        if (strpos($ua, 'Firefox') !== false) {
                                            $browser = 'Firefox';
                                        } elseif (strpos($ua, 'Chrome') !== false) {
                                            $browser = 'Chrome';
                                        } elseif (strpos($ua, 'Safari') !== false) {
                                            $browser = 'Safari';
                                        } elseif (strpos($ua, 'Edge') !== false) {
                                            $browser = 'Edge';
                                        } elseif (strpos($ua, 'MSIE') !== false || strpos($ua, 'Trident') !== false) {
                                            $browser = 'Internet Explorer';
                                        }
                                        
                                        if (strpos($ua, 'Windows') !== false) {
                                            $os = 'Windows';
                                        } elseif (strpos($ua, 'Mac') !== false) {
                                            $os = 'macOS';
                                        } elseif (strpos($ua, 'Linux') !== false) {
                                            $os = 'Linux';
                                        } elseif (strpos($ua, 'Android') !== false) {
                                            $os = 'Android';
                                        } elseif (strpos($ua, 'iPhone') !== false || strpos($ua, 'iPad') !== false) {
                                            $os = 'iOS';
                                        }
                                ?>
                                <div class="flex items-center justify-between bg-gray-700 bg-opacity-50 p-4 rounded-lg">
                                    <div class="flex items-center">
                                        <div class="bg-gray-600 p-3 rounded-lg mr-4">
                                            <i class="fas fa-<?= $browser === 'Chrome' ? 'chrome' : ($browser === 'Firefox' ? 'firefox' : ($browser === 'Safari' ? 'safari' : ($browser === 'Edge' ? 'edge' : 'globe'))) ?> text-gray-300"></i>
                                        </div>
                                        <div>
                                            <h4 class="text-white font-medium"><?= $browser ?> on <?= $os ?></h4>
                                            <p class="text-gray-400 text-sm">IP: <?= htmlspecialchars($session['ip_address']) ?></p>
                                            <p class="text-gray-500 text-xs">
                                                <?= date('M d, Y h:i A', strtotime($session['login_time'])) ?>
                                                <?php if ($session['logout_time']): ?>
                                                    - <?= date('h:i A', strtotime($session['logout_time'])) ?>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                    </div>
                                    <?php if (!$session['logout_time'] && $session === $sessions[0]): ?>
                                    <span class="px-3 py-1 bg-green-500 bg-opacity-20 text-green-300 rounded-full text-xs">
                                        Current Session
                                    </span>
                                    <?php endif; ?>
                                </div>
                                <?php 
                                    endforeach;
                                else:
                                ?>
                                <div class="text-center py-4">
                                    <p class="text-gray-400">No recent login sessions found</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </section>
                
                <!-- Notifications -->
                <section id="notifications" class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl overflow-hidden shadow-xl">
                    <div class="p-6 border-b border-gray-700">
                        <h2 class="text-xl font-bold text-white">Notification Preferences</h2>
                        <p class="text-gray-400 text-sm">Manage how you receive notifications</p>
                    </div>
                    
                    <div class="p-6">
                        <form method="POST" action="settings.php">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="notification_preferences" value="1">
                            
                            <div class="space-y-6 mb-6">
                                <div class="flex items-center justify-between p-4 bg-gray-700 bg-opacity-50 rounded-lg">
                                    <div>
                                        <h4 class="text-white font-medium">Email Notifications</h4>
                                        <p class="text-gray-400 text-sm">Receive notifications via email</p>
                                    </div>
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" name="email_notifications" class="sr-only peer" <?= $preferences['email_notifications'] ? 'checked' : '' ?>>
                                        <div class="w-11 h-6 bg-gray-600 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-yellow-500"></div>
                                    </label>
                                </div>
                                
                                <div class="flex items-center justify-between p-4 bg-gray-700 bg-opacity-50 rounded-lg">
                                    <div>
                                        <h4 class="text-white font-medium">SMS Notifications</h4>
                                        <p class="text-gray-400 text-sm">Receive notifications via SMS</p>
                                    </div>
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" name="sms_notifications" class="sr-only peer" <?= $preferences['sms_notifications'] ? 'checked' : '' ?>>
                                        <div class="w-11 h-6 bg-gray-600 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-yellow-500"></div>
                                    </label>
                                </div>
                                
                                <div class="flex items-center justify-between p-4 bg-gray-700 bg-opacity-50 rounded-lg">
                                    <div>
                                        <h4 class="text-white font-medium">Push Notifications</h4>
                                        <p class="text-gray-400 text-sm">Receive push notifications in browser</p>
                                    </div>
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" name="push_notifications" class="sr-only peer" <?= $preferences['push_notifications'] ? 'checked' : '' ?>>
                                        <div class="w-11 h-6 bg-gray-600 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-yellow-500"></div>
                                    </label>
                                </div>
                            </div>
                            
                            <div class="flex justify-end">
                                <button type="submit" class="bg-yellow-500 hover:bg-yellow-600 text-black font-medium px-6 py-2 rounded-lg transition-colors duration-200">
                                    Save Preferences
                                </button>
                            </div>
                        </form>
                    </div>
                </section>
                
                <!-- Activity Log -->
                <section id="activity" class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl overflow-hidden shadow-xl">
                    <div class="p-6 border-b border-gray-700">
                        <h2 class="text-xl font-bold text-white">Activity Log</h2>
                        <p class="text-gray-400 text-sm">Recent activity on your account</p>
                    </div>
                    
                    <div class="p-6">
                        <div class="relative">
                            <div class="absolute left-4 inset-y-0 w-0.5 bg-gray-700"></div>
                            
                            <div class="space-y-8 relative">
                                <?php
                                // Get recent activity logs
                                $activityStmt = $conn->prepare("
                                    SELECT action, details, ip_address, created_at
                                    FROM activity_logs
                                    WHERE user_id = ? AND user_type = 'member'
                                    ORDER BY created_at DESC
                                    LIMIT 10
                                ");
                                $activityStmt->execute([$user_id]);
                                $activities = $activityStmt->fetchAll(PDO::FETCH_ASSOC);
                                
                                if (count($activities) > 0):
                                    foreach ($activities as $activity):
                                        // Determine icon based on action
                                        $icon = 'circle';
                                        $iconColor = 'text-blue-400';
                                        
                                        if (strpos($activity['action'], 'login') !== false) {
                                            $icon = 'sign-in-alt';
                                            $iconColor = 'text-green-400';
                                        } elseif (strpos($activity['action'], 'logout') !== false) {
                                            $icon = 'sign-out-alt';
                                            $iconColor = 'text-red-400';
                                        } elseif (strpos($activity['action'], 'update') !== false) {
                                            $icon = 'edit';
                                            $iconColor = 'text-yellow-400';
                                        } elseif (strpos($activity['action'], 'password') !== false) {
                                            $icon = 'key';
                                            $iconColor = 'text-purple-400';
                                        } elseif (strpos($activity['action'], 'schedule') !== false) {
                                            $icon = 'calendar-alt';
                                            $iconColor = 'text-blue-400';
                                        } elseif (strpos($activity['action'], 'payment') !== false) {
                                            $icon = 'credit-card';
                                            $iconColor = 'text-green-400';
                                        }
                                ?>
                                <div class="flex">
                                    <div class="flex-shrink-0 w-8 h-8 rounded-full bg-gray-900 flex items-center justify-center relative z-10 mr-4">
                                        <i class="fas fa-<?= $icon ?> <?= $iconColor ?>"></i>
                                    </div>
                                    <div class="flex-grow">
                                        <h4 class="text-white font-medium"><?= ucwords(str_replace('_', ' ', $activity['action'])) ?></h4>
                                        <p class="text-gray-400 text-sm"><?= htmlspecialchars($activity['details']) ?></p>
                                        <div class="flex items-center mt-1 text-xs text-gray-500">
                                            <span><?= date('M d, Y h:i A', strtotime($activity['created_at'])) ?></span>
                                            <span class="mx-2">•</span>
                                            <span>IP: <?= htmlspecialchars($activity['ip_address']) ?></span>
                                        </div>
                                    </div>
                                </div>
                                <?php 
                                    endforeach;
                                else:
                                ?>
                                <div class="text-center py-4 pl-8">
                                    <p class="text-gray-400">No recent activity found</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <?php if (count($activities) > 0): ?>
                        <div class="mt-6 text-center">
                            <a href="activity_log.php" class="text-yellow-500 hover:text-yellow-400 transition-colors duration-200">
                                View Full Activity Log <i class="fas fa-arrow-right ml-1"></i>
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </section>
                
                <!-- Account Management -->
                <section class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl overflow-hidden shadow-xl">
                    <div class="p-6 border-b border-gray-700">
                        <h2 class="text-xl font-bold text-white">Account Management</h2>
                        <p class="text-gray-400 text-sm">Manage your account settings</p>
                    </div>
                    
                    <div class="p-6">
                        <div class="space-y-6">
                            <div class="flex items-center justify-between p-4 bg-gray-700 bg-opacity-50 rounded-lg">
                                <div>
                                    <h4 class="text-white font-medium">Download Your Data</h4>
                                    <p class="text-gray-400 text-sm">Download a copy of your personal data</p>
                                </div>
                                <a href="download_data.php" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors duration-200 text-sm">
                                    Download
                                </a>
                            </div>
                            
                            <div class="flex items-center justify-between p-4 bg-gray-700 bg-opacity-50 rounded-lg">
                                <div>
                                    <h4 class="text-white font-medium">Delete Account</h4>
                                    <p class="text-gray-400 text-sm">Permanently delete your account and all data</p>
                                </div>
                                <button id="delete-account-btn" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg transition-colors duration-200 text-sm">
                                    Delete Account
                                </button>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </div>
</div>

<!-- Delete Account Modal -->
<div id="delete-account-modal" class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center hidden">
    <div class="bg-gray-800 rounded-2xl p-6 max-w-md w-full mx-4">
        <h3 class="text-xl font-bold text-white mb-4">Delete Account</h3>
        <p class="text-gray-300 mb-6">
            Are you sure you want to delete your account? This action is irreversible and all your data will be permanently deleted.
        </p>
        
        <form method="POST" action="delete_account.php" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            
            <div>
                <label for="delete_password" class="block text-sm font-medium text-gray-400 mb-1">Enter your password to confirm</label>
                <input type="password" id="delete_password" name="password" required
                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-red-500">
            </div>
            
            <div class="flex items-center">
                <input type="checkbox" id="confirm_delete" name="confirm_delete" required class="w-4 h-4 text-red-600 border-gray-600 rounded focus:ring-red-500">
                <label for="confirm_delete" class="ml-2 text-sm text-gray-300">
                    I understand this action cannot be undone
                </label>
            </div>
            
            <div class="flex justify-end space-x-4">
                <button type="button" id="cancel-delete" class="bg-gray-600 hover:bg-gray-500 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                    Cancel
                </button>
                <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                    Delete Account
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    // Profile image upload
    const profileImageUpload = document.getElementById('profile_image_upload');
    const profileImageLabel = document.querySelector('label[for="profile_image_upload"]');
    
    if (profileImageUpload && profileImageLabel) {
        profileImageLabel.addEventListener('click', function() {
            profileImageUpload.click();
        });
        
        profileImageUpload.addEventListener('change', function() {
            if (this.files && this.files[0]) {
                // Show preview
                const reader = new FileReader();
                reader.onload = function(e) {
                    const preview = document.querySelector('.w-32.h-32 img, .w-32.h-32 div');
                    if (preview.tagName === 'IMG') {
                        preview.src = e.target.result;
                    } else {
                        const img = document.createElement('img');
                        img.src = e.target.result;
                        img.className = 'w-full h-full object-cover';
                        preview.parentNode.replaceChild(img, preview);
                    }
                }
                reader.readAsDataURL(this.files[0]);
                
                // Auto-submit the form
                document.querySelector('form[name="update_profile"]').submit();
            }
        });
    }
    
    // Navigation tabs
    const navLinks = document.querySelectorAll('.nav-link');
    const sections = document.querySelectorAll('section[id]');
    
    function setActiveTab() {
        let scrollY = window.scrollY;
        
        sections.forEach(section => {
            const sectionHeight = section.offsetHeight;
            const sectionTop = section.offsetTop - 100;
            const sectionId = section.getAttribute('id');
            
            if (scrollY > sectionTop && scrollY <= sectionTop + sectionHeight) {
                navLinks.forEach(link => {
                    link.classList.remove('bg-gray-800', 'text-white');
                    link.classList.add('text-gray-400', 'hover:bg-gray-800', 'hover:text-white');
                });
                
                const activeLink = document.querySelector(`.nav-link[href="#${sectionId}"]`);
                if (activeLink) {
                    activeLink.classList.remove('text-gray-400', 'hover:bg-gray-800', 'hover:text-white');
                    activeLink.classList.add('bg-gray-800', 'text-white');
                }
            }
        });
    }
    
    window.addEventListener('scroll', setActiveTab);
    
    navLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const targetId = this.getAttribute('href');
            const targetSection = document.querySelector(targetId);
            
            if (targetSection) {
                window.scrollTo({
                    top: targetSection.offsetTop - 80,
                    behavior: 'smooth'
                });
            }
        });
    });
    
    // Delete account modal
    const deleteAccountBtn = document.getElementById('delete-account-btn');
    const deleteAccountModal = document.getElementById('delete-account-modal');
    const cancelDeleteBtn = document.getElementById('cancel-delete');
    
    if (deleteAccountBtn && deleteAccountModal && cancelDeleteBtn) {
        deleteAccountBtn.addEventListener('click', function() {
            deleteAccountModal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        });
        
        cancelDeleteBtn.addEventListener('click', function() {
            deleteAccountModal.classList.add('hidden');
            document.body.style.overflow = 'auto';
        });
        
        // Close modal when clicking outside
        deleteAccountModal.addEventListener('click', function(e) {
            if (e.target === deleteAccountModal) {
                deleteAccountModal.classList.add('hidden');
                document.body.style.overflow = 'auto';
            }
        });
    }
    
    // Password strength meter
    const newPasswordInput = document.getElementById('new_password');
    const confirmPasswordInput = document.getElementById('confirm_password');
    
    if (newPasswordInput && confirmPasswordInput) {
        newPasswordInput.addEventListener('input', function() {
            validatePassword();
        });
        
        confirmPasswordInput.addEventListener('input', function() {
            validatePasswordMatch();
        });
        
        function validatePassword() {
            const password = newPasswordInput.value;
            let strength = 0;
            
            // Length check
            if (password.length >= 8) {
                strength += 1;
            }
            
            // Contains lowercase
            if (/[a-z]/.test(password)) {
                strength += 1;
            }
            
            // Contains uppercase
            if (/[A-Z]/.test(password)) {
                strength += 1;
            }
            
            // Contains number
            if (/[0-9]/.test(password)) {
                strength += 1;
            }
            
            // Contains special character
            if (/[^a-zA-Z0-9]/.test(password)) {
                strength += 1;
            }
            
            // Update UI based on strength
            const strengthText = newPasswordInput.parentNode.querySelector('.text-sm.text-gray-500');
            if (strengthText) {
                if (strength < 2) {
                    strengthText.textContent = 'Password is weak';
                    strengthText.className = 'text-sm text-red-500 mt-1';
                } else if (strength < 4) {
                    strengthText.textContent = 'Password is moderate';
                    strengthText.className = 'text-sm text-yellow-500 mt-1';
                } else {
                    strengthText.textContent = 'Password is strong';
                    strengthText.className = 'text-sm text-green-500 mt-1';
                }
            }
        }
        
        function validatePasswordMatch() {
            const password = newPasswordInput.value;
            const confirmPassword = confirmPasswordInput.value;
            
            if (confirmPassword && password !== confirmPassword) {
                confirmPasswordInput.setCustomValidity("Passwords don't match");
                
                // Add error message if not exists
                let errorMsg = confirmPasswordInput.parentNode.querySelector('.text-sm.text-red-500');
                if (!errorMsg) {
                    errorMsg = document.createElement('p');
                    errorMsg.className = 'text-sm text-red-500 mt-1';
                    errorMsg.textContent = "Passwords don't match";
                    confirmPasswordInput.parentNode.appendChild(errorMsg);
                }
            } else {
                confirmPasswordInput.setCustomValidity('');
                
                // Remove error message if exists
                const errorMsg = confirmPasswordInput.parentNode.querySelector('.text-sm.text-red-500');
                if (errorMsg) {
                    errorMsg.remove();
                }
            }
        }
    }
</script>



