<?php
include '../includes/navbar.php';
require_once '../config/database.php';

// Check if gym owner is logged in
if (!isset($_SESSION['owner_id'])) {
    header('Location: ../login.php');
    exit();
}

$db = new GymDatabase();
$conn = $db->getConnection();
$owner_id = $_SESSION['owner_id'];

// Get owner information
$stmt = $conn->prepare("SELECT * FROM gym_owners WHERE id = ?");
$stmt->execute([$owner_id]);
$owner = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$owner) {
    $_SESSION['error'] = "Owner information not found.";
    header('Location: dashboard.php');
    exit();
}

// Get gym information
$stmt = $conn->prepare("SELECT * FROM gyms WHERE owner_id = ?");
$stmt->execute([$owner_id]);
$gym = $stmt->fetch(PDO::FETCH_ASSOC);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Profile update
    if (isset($_POST['update_profile'])) {
        $name = $_POST['name'];
        $email = $_POST['email'];
        $phone = $_POST['phone'];
        $address = $_POST['address'];
        $city = $_POST['city'];
        $state = $_POST['state'];
        $country = $_POST['country'];
        $zip_code = $_POST['zip_code'];
        
        // Check if email is already in use by another owner
        $stmt = $conn->prepare("SELECT id FROM gym_owners WHERE email = ? AND id != ?");
        $stmt->execute([$email, $owner_id]);
        if ($stmt->rowCount() > 0) {
            $_SESSION['error'] = "Email is already in use by another account.";
        } else {
            // Update profile
            $stmt = $conn->prepare("
                UPDATE gym_owners 
                SET name = ?, email = ?, phone = ?, address = ?, city = ?, state = ?, country = ?, zip_code = ?
                WHERE id = ?
            ");
            $result = $stmt->execute([$name, $email, $phone, $address, $city, $state, $country, $zip_code, $owner_id]);
            
            if ($result) {
                $_SESSION['success'] = "Profile updated successfully.";
                $_SESSION['owner_name'] = $name; // Update session name
            } else {
                $_SESSION['error'] = "Failed to update profile.";
            }
        }
    }
    
    // Password update
    else if (isset($_POST['update_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Verify current password
        if (!password_verify($current_password, $owner['password_hash'])) {
            $_SESSION['error'] = "Current password is incorrect.";
        } 
        // Check if new passwords match
        else if ($new_password !== $confirm_password) {
            $_SESSION['error'] = "New passwords do not match.";
        }
        // Check password strength
        else if (strlen($new_password) < 8) {
            $_SESSION['error'] = "Password must be at least 8 characters long.";
        } else {
            // Update password
            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE gym_owners SET password_hash = ? WHERE id = ?");
            $result = $stmt->execute([$password_hash, $owner_id]);
            
            if ($result) {
                $_SESSION['success'] = "Password updated successfully.";
            } else {
                $_SESSION['error'] = "Failed to update password.";
            }
        }
    }
    
    // Profile picture update
    else if (isset($_POST['update_profile_picture'])) {
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === 0) {
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
            $max_size = 5 * 1024 * 1024; // 5MB
            
            $file_type = $_FILES['profile_picture']['type'];
            $file_size = $_FILES['profile_picture']['size'];
            
            if (!in_array($file_type, $allowed_types)) {
                $_SESSION['error'] = "Only JPG, PNG, and GIF images are allowed.";
            } else if ($file_size > $max_size) {
                $_SESSION['error'] = "File size must be less than 5MB.";
            } else {
                $file_name = 'owner_' . $owner_id . '_' . time() . '.' . pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
                $upload_dir = '../uploads/profile_pictures/';
                
                // Create directory if it doesn't exist
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $upload_path = $upload_dir . $file_name;
                
                if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $upload_path)) {
                    // Update profile picture in database
                    $relative_path = 'uploads/profile_pictures/' . $file_name;
                    $stmt = $conn->prepare("UPDATE gym_owners SET profile_picture = ? WHERE id = ?");
                    $result = $stmt->execute([$relative_path, $owner_id]);
                    
                    if ($result) {
                        $_SESSION['success'] = "Profile picture updated successfully.";
                        $_SESSION['owner_profile_pic'] = $relative_path; // Update session profile pic
                    } else {
                        $_SESSION['error'] = "Failed to update profile picture in database.";
                    }
                } else {
                    $_SESSION['error'] = "Failed to upload profile picture.";
                }
            }
        } else {
            $_SESSION['error'] = "No file uploaded or an error occurred.";
        }
    }
    
    // Notification settings update
    else if (isset($_POST['update_notifications'])) {
        $email_notifications = isset($_POST['email_notifications']) ? 1 : 0;
        $sms_notifications = isset($_POST['sms_notifications']) ? 1 : 0;
        
        $stmt = $conn->prepare("
            UPDATE gym_owners 
            SET email_notifications = ?, sms_notifications = ?
            WHERE id = ?
        ");
        $result = $stmt->execute([$email_notifications, $sms_notifications, $owner_id]);
        
        if ($result) {
            $_SESSION['success'] = "Notification settings updated successfully.";
        } else {
            $_SESSION['error'] = "Failed to update notification settings.";
        }
    }
    
    // Refresh owner data after updates
    $stmt = $conn->prepare("SELECT * FROM gym_owners WHERE id = ?");
    $stmt->execute([$owner_id]);
    $owner = $stmt->fetch(PDO::FETCH_ASSOC);
}

?>

<div class="container mx-auto px-4 py-8 pt-16">
    <div class="flex justify-between items-center py-2">
    <h1 class="text-2xl font-bold mb-6">Account Settings</h1>
    <a href="dashboard.php" class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">
   <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
     </a>
    </div>
    
    <?php if (isset($_SESSION['success'])): ?>
        <div class="bg-green-500 text-white p-4 rounded-lg mb-6">
            <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error'])): ?>
        <div class="bg-red-500 text-white p-4 rounded-lg mb-6">
            <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>
    
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <!-- Sidebar -->
        <div class="md:col-span-1">
            <div class="bg-gray-800 rounded-lg shadow-lg p-6 sticky top-24">
                <div class="flex flex-col items-center mb-6">
                    <?php if ($owner['profile_picture']): ?>
                        <img src="<?php echo '../' . $owner['profile_picture']; ?>" alt="Profile Picture" class="w-32 h-32 rounded-full object-cover mb-4">
                    <?php else: ?>
                        <div class="w-32 h-32 rounded-full bg-gray-700 flex items-center justify-center mb-4">
                            <i class="fas fa-user text-gray-500 text-5xl"></i>
                        </div>
                    <?php endif; ?>
                    <h2 class="text-xl font-semibold"><?php echo htmlspecialchars($owner['name']); ?></h2>
                    <p class="text-gray-400"><?php echo htmlspecialchars($owner['email']); ?></p>
                </div>
                
                <nav class="space-y-1">
                    <a href="#profile" class="flex items-center px-4 py-2 text-white bg-gray-700 rounded-lg">
                        <i class="fas fa-user mr-3 text-blue-400"></i>
                        <span>Profile Information</span>
                    </a>
                    <a href="#password" class="flex items-center px-4 py-2 text-white hover:bg-gray-700 rounded-lg">
                        <i class="fas fa-lock mr-3 text-yellow-400"></i>
                        <span>Change Password</span>
                    </a>
                    <a href="#profile-picture" class="flex items-center px-4 py-2 text-white hover:bg-gray-700 rounded-lg">
                        <i class="fas fa-camera mr-3 text-green-400"></i>
                        <span>Profile Picture</span>
                    </a>
                    <a href="#notifications" class="flex items-center px-4 py-2 text-white hover:bg-gray-700 rounded-lg">
                        <i class="fas fa-bell mr-3 text-purple-400"></i>
                        <span>Notification Settings</span>
                    </a>
                </nav>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="md:col-span-2 space-y-6">
            <!-- Profile Information -->
            <div id="profile" class="bg-gray-800 rounded-lg shadow-lg p-6">
                <h2 class="text-xl font-semibold mb-4">Profile Information</h2>
                <form method="POST" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-gray-300 text-sm font-medium mb-2">Full Name</label>
                            <input type="text" name="name" value="<?php echo htmlspecialchars($owner['name']); ?>" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        
                        <div>
                            <label class="block text-gray-300 text-sm font-medium mb-2">Email Address</label>
                            <input type="email" name="email" value="<?php echo htmlspecialchars($owner['email']); ?>" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        
                        <div>
                            <label class="block text-gray-300 text-sm font-medium mb-2">Phone Number</label>
                            <input type="tel" name="phone" value="<?php echo htmlspecialchars($owner['phone']); ?>" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        
                        <div>
                            <label class="block text-gray-300 text-sm font-medium mb-2">Address</label>
                            <input type="text" name="address" value="<?php echo htmlspecialchars($owner['address']); ?>" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        
                        <div>
                            <label class="block text-gray-300 text-sm font-medium mb-2">City</label>
                            <input type="text" name="city" value="<?php echo htmlspecialchars($owner['city']); ?>" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        
                        <div>
                            <label class="block text-gray-300 text-sm font-medium mb-2">State</label>
                            <input type="text" name="state" value="<?php echo htmlspecialchars($owner['state']); ?>" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        
                        <div>
                            <label class="block text-gray-300 text-sm font-medium mb-2">Country</label>
                            <input type="text" name="country" value="<?php echo htmlspecialchars($owner['country']); ?>" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        
                        <div>
                            <label class="block text-gray-300 text-sm font-medium mb-2">ZIP Code</label>
                            <input type="text" name="zip_code" value="<?php echo htmlspecialchars($owner['zip_code']); ?>" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>
                    
                    <div class="flex justify-end">
                        <button type="submit" name="update_profile" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-2 rounded-lg transition-colors duration-200">
                            Save Changes
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Change Password -->
            <div id="password" class="bg-gray-800 rounded-lg shadow-lg p-6">
                <h2 class="text-xl font-semibold mb-4">Change Password</h2>
                <form method="POST" class="space-y-4">
                    <div>
                        <label class="block text-gray-300 text-sm font-medium mb-2">Current Password</label>
                        <input type="password" name="current_password" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    
                    <div>
                        <label class="block text-gray-300 text-sm font-medium mb-2">New Password</label>
                        <input type="password" name="new_password" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <p class="text-gray-400 text-sm mt-1">Password must be at least 8 characters long.</p>
                    </div>
                    
                    <div>
                        <label class="block text-gray-300 text-sm font-medium mb-2">Confirm New Password</label>
                        <input type="password" name="confirm_password" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    
                    <div class="flex justify-end">
                        <button type="submit" name="update_password" class="bg-yellow-500 hover:bg-yellow-600 text-black px-6 py-2 rounded-lg transition-colors duration-200">
                            Update Password
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Profile Picture -->
            <div id="profile-picture" class="bg-gray-800 rounded-lg shadow-lg p-6">
                <h2 class="text-xl font-semibold mb-4">Profile Picture</h2>
                <form method="POST" enctype="multipart/form-data" class="space-y-4">
                    <div class="flex items-center space-x-6">
                        <?php if ($owner['profile_picture']): ?>
                            <img src="<?php echo '../' . $owner['profile_picture']; ?>" alt="Current Profile Picture" class="w-24 h-24 rounded-full object-cover">
                        <?php else: ?>
                            <div class="w-24 h-24 rounded-full bg-gray-700 flex items-center justify-center">
                                <i class="fas fa-user text-gray-500 text-3xl"></i>
                            </div>
                        <?php endif; ?>
                        
                        <div class="flex-1">
                            <label class="block text-gray-300 text-sm font-medium mb-2">Upload New Picture</label>
                            <input type="file" name="profile_picture" accept="image/*" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <p class="text-gray-400 text-sm mt-1">Max file size: 5MB. Supported formats: JPG, PNG, GIF.</p>
                        </div>
                    </div>
                    
                    <div class="flex justify-end">
                        <button type="submit" name="update_profile_picture" class="bg-green-500 hover:bg-green-600 text-white px-6 py-2 rounded-lg transition-colors duration-200">
                            Upload Picture
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Notification Settings -->
            <div id="notifications" class="bg-gray-800 rounded-lg shadow-lg p-6">
                <h2 class="text-xl font-semibold mb-4">Notification Settings</h2>
                <form method="POST" class="space-y-4">
                    <div class="space-y-3">
                        <label class="flex items-center">
                            <input type="checkbox" name="email_notifications" class="form-checkbox h-5 w-5 text-blue-500" <?php echo isset($owner['email_notifications']) && $owner['email_notifications'] ? 'checked' : ''; ?>>
                            <span class="ml-2 text-white">Email Notifications</span>
                        </label>
                        <p class="text-gray-400 text-sm ml-7">Receive notifications about new bookings, cancellations, and important updates via email.</p>
                        
                        <label class="flex items-center">
                            <input type="checkbox" name="sms_notifications" class="form-checkbox h-5 w-5 text-blue-500" <?php echo isset($owner['sms_notifications']) && $owner['sms_notifications'] ? 'checked' : ''; ?>>
                            <span class="ml-2 text-white">SMS Notifications</span>
                        </label>
                        <p class="text-gray-400 text-sm ml-7">Receive important alerts and reminders via SMS to your registered phone number.</p>
                    </div>
                    
                    <div class="flex justify-end">
                        <button type="submit" name="update_notifications" class="bg-purple-500 hover:bg-purple-600 text-white px-6 py-2 rounded-lg transition-colors duration-200">
                            Save Preferences
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Account Information -->
            <div class="bg-gray-800 rounded-lg shadow-lg p-6">
                <h2 class="text-xl font-semibold mb-4">Account Information</h2>
                <div class="space-y-4">
                    <div>
                        <p class="text-gray-400">Account Type</p>
                        <p class="text-white font-medium">
                            <?php echo ucfirst($owner['account_type'] ?? 'Basic'); ?>
                            <?php if (($owner['account_type'] ?? 'basic') !== 'premium'): ?>
                                <a href="upgrade.php" class="ml-2 text-yellow-400 hover:text-yellow-300 text-sm">
                                    <i class="fas fa-crown mr-1"></i> Upgrade to Premium
                                </a>
                            <?php endif; ?>
                        </p>
                    </div>
                    
                    <div>
                        <p class="text-gray-400">Member Since</p>
                        <p class="text-white font-medium"><?php echo date('F d, Y', strtotime($owner['created_at'])); ?></p>
                    </div>
                    
                    <div>
                        <p class="text-gray-400">Gym Limit</p>
                        <p class="text-white font-medium"><?php echo $owner['gym_limit']; ?> gyms</p>
                    </div>
                    
                    <div>
                        <p class="text-gray-400">Account Status</p>
                        <p class="text-white font-medium">
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                <?php echo $owner['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                <?php echo ucfirst($owner['status']); ?>
                            </span>
                        </p>
                    </div>
                </div>
            </div>
            
            <!-- Danger Zone -->
            <div class="bg-red-900 bg-opacity-20 border border-red-800 rounded-lg p-6">
                <h2 class="text-xl font-semibold text-red-400 mb-4">Danger Zone</h2>
                <p class="text-gray-300 mb-4">These actions are irreversible. Please proceed with caution.</p>
                
                <div class="space-y-4">
                    <button type="button" onclick="confirmDeactivate()" class="w-full bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                        Deactivate Account
                    </button>
                    
                    <button type="button" onclick="confirmDelete()" class="w-full bg-red-700 hover:bg-red-600 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                        Delete Account
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // Smooth scroll to sections
    document.querySelectorAll('nav a').forEach(anchor => {
        anchor.addEventListener('click', function(e) {
            e.preventDefault();
            
            const targetId = this.getAttribute('href');
            const targetElement = document.querySelector(targetId);
            
            window.scrollTo({
                top: targetElement.offsetTop - 100,
                behavior: 'smooth'
            });
            
            // Update active state
            document.querySelectorAll('nav a').forEach(a => {
                a.classList.remove('bg-gray-700');
                a.classList.add('hover:bg-gray-700');
            });
            
            this.classList.add('bg-gray-700');
            this.classList.remove('hover:bg-gray-700');
        });
    });
    
    // Confirm deactivate account
    function confirmDeactivate() {
        if (confirm('Are you sure you want to deactivate your account? Your gym listings will be hidden from users.')) {
            window.location.href = 'deactivate_account.php';
        }
    }
    
    // Confirm delete account
    function confirmDelete() {
        if (confirm('WARNING: This action cannot be undone. All your data, including gym listings, will be permanently deleted.')) {
            if (confirm('Are you absolutely sure you want to delete your account?')) {
                window.location.href = 'delete_account.php';
            }
        }
    }
</script>

<?php include '../includes/footer.php'; ?>


