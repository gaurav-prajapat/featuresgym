<?php
session_start();
require_once '../config/database.php';


// Ensure user is authenticated and has admin role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['error'] = "You don't have permission to access this page.";
    header('Location: ../login.php');
    exit();
}

// CSRF protection
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$db = new GymDatabase();
$conn = $db->getConnection();

// Check if owner ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error'] = "Invalid owner ID.";
    header('Location: gym-owners.php');
    exit();
}

$owner_id = (int)$_GET['id'];

// Process owner actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    
    // Approve owner
    if (isset($_POST['approve_owner'])) {
        try {
            $query = "UPDATE gym_owners SET is_approved = 1 WHERE id = :id";
            $stmt = $conn->prepare($query);
            $stmt->execute([':id' => $owner_id]);
            
            // Add notification for the owner
            $notif_query = "INSERT INTO notifications (user_id, type, title, message, gym_id, created_at) 
                           VALUES (:user_id, 'account_approval', 'Account Approved', 
                           'Your gym owner account has been approved. You can now add and manage your gyms.', 
                           0, NOW())";
            $notif_stmt = $conn->prepare($notif_query);
            $notif_stmt->execute([':user_id' => $owner_id]);
            
            // Log the activity
            $log_query = "INSERT INTO activity_logs (user_id, user_type, action, details, ip_address, user_agent) 
                         VALUES (:user_id, 'admin', 'approve_owner', :details, :ip, :user_agent)";
            $log_stmt = $conn->prepare($log_query);
            $log_stmt->execute([
                ':user_id' => $_SESSION['user_id'],
                ':details' => "Approved owner ID: $owner_id",
                ':ip' => $_SERVER['REMOTE_ADDR'],
                ':user_agent' => $_SERVER['HTTP_USER_AGENT']
            ]);
            
            $_SESSION['success'] = "Owner approved successfully!";
        } catch (PDOException $e) {
            $_SESSION['error'] = "Failed to approve owner: " . $e->getMessage();
        }
    }
    
    // Verify owner
    if (isset($_POST['verify_owner'])) {
        try {
            $query = "UPDATE gym_owners SET is_verified = 1 WHERE id = :id";
            $stmt = $conn->prepare($query);
            $stmt->execute([':id' => $owner_id]);
            
            // Add notification for the owner
            $notif_query = "INSERT INTO notifications (user_id, type, title, message, gym_id, created_at) 
                           VALUES (:user_id, 'account_verification', 'Account Verified', 
                           'Your gym owner account has been verified by our admin team.', 
                           0, NOW())";
            $notif_stmt = $conn->prepare($notif_query);
            $notif_stmt->execute([':user_id' => $owner_id]);
            
            // Log the activity
            $log_query = "INSERT INTO activity_logs (user_id, user_type, action, details, ip_address, user_agent) 
                         VALUES (:user_id, 'admin', 'verify_owner', :details, :ip, :user_agent)";
            $log_stmt = $conn->prepare($log_query);
            $log_stmt->execute([
                ':user_id' => $_SESSION['user_id'],
                ':details' => "Verified owner ID: $owner_id",
                ':ip' => $_SERVER['REMOTE_ADDR'],
                ':user_agent' => $_SERVER['HTTP_USER_AGENT']
            ]);
            
            $_SESSION['success'] = "Owner verified successfully!";
        } catch (PDOException $e) {
            $_SESSION['error'] = "Failed to verify owner: " . $e->getMessage();
        }
    }
    
    // Update owner status
    if (isset($_POST['update_status'])) {
        $new_status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);
        $allowed_statuses = ['active', 'inactive', 'suspended'];
        
        if (in_array($new_status, $allowed_statuses)) {
            try {
                $query = "UPDATE gym_owners SET status = :status WHERE id = :id";
                $stmt = $conn->prepare($query);
                $stmt->execute([
                    ':status' => $new_status,
                    ':id' => $owner_id
                ]);
                
                // Add notification for the owner
                $notif_query = "INSERT INTO notifications (user_id, type, title, message, gym_id, created_at) 
                               VALUES (:user_id, 'account_status', 'Account Status Updated', 
                               'Your account status has been updated to: " . ucfirst($new_status) . "', 
                               0, NOW())";
                $notif_stmt = $conn->prepare($notif_query);
                $notif_stmt->execute([':user_id' => $owner_id]);
                
                // Log the activity
                $log_query = "INSERT INTO activity_logs (user_id, user_type, action, details, ip_address, user_agent) 
                             VALUES (:user_id, 'admin', 'update_owner_status', :details, :ip, :user_agent)";
                $log_stmt = $conn->prepare($log_query);
                $log_stmt->execute([
                    ':user_id' => $_SESSION['user_id'],
                    ':details' => "Updated owner ID: $owner_id status to $new_status",
                    ':ip' => $_SERVER['REMOTE_ADDR'],
                    ':user_agent' => $_SERVER['HTTP_USER_AGENT']
                ]);
                
                $_SESSION['success'] = "Owner status updated successfully!";
            } catch (PDOException $e) {
                $_SESSION['error'] = "Failed to update owner status: " . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = "Invalid status value.";
        }
    }
    
    // Update account type
    if (isset($_POST['update_account_type'])) {
        $new_type = filter_input(INPUT_POST, 'account_type', FILTER_SANITIZE_STRING);
        $allowed_types = ['basic', 'premium'];
        
        if (in_array($new_type, $allowed_types)) {
            try {
                $query = "UPDATE gym_owners SET account_type = :account_type WHERE id = :id";
                $stmt = $conn->prepare($query);
                $stmt->execute([
                    ':account_type' => $new_type,
                    ':id' => $owner_id
                ]);
                
                // Add notification for the owner
                $notif_query = "INSERT INTO notifications (user_id, type, title, message, gym_id, created_at) 
                               VALUES (:user_id, 'account_upgrade', 'Account Type Updated', 
                               'Your account has been upgraded to " . ucfirst($new_type) . " by admin.', 
                               0, NOW())";
                $notif_stmt = $conn->prepare($notif_query);
                $notif_stmt->execute([':user_id' => $owner_id]);
                
                // Log the activity
                $log_query = "INSERT INTO activity_logs (user_id, user_type, action, details, ip_address, user_agent) 
                             VALUES (:user_id, 'admin', 'update_account_type', :details, :ip, :user_agent)";
                $log_stmt = $conn->prepare($log_query);
                $log_stmt->execute([
                    ':user_id' => $_SESSION['user_id'],
                    ':details' => "Updated owner ID: $owner_id account type to $new_type",
                    ':ip' => $_SERVER['REMOTE_ADDR'],
                    ':user_agent' => $_SERVER['HTTP_USER_AGENT']
                ]);
                
                $_SESSION['success'] = "Owner account type updated successfully!";
            } catch (PDOException $e) {
                $_SESSION['error'] = "Failed to update account type: " . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = "Invalid account type.";
        }
    }
    
    // Update gym limit
    if (isset($_POST['update_gym_limit'])) {
        $new_limit = filter_input(INPUT_POST, 'gym_limit', FILTER_SANITIZE_NUMBER_INT);
        
        if ($new_limit > 0) {
            try {
                $query = "UPDATE gym_owners SET gym_limit = :gym_limit WHERE id = :id";
                $stmt = $conn->prepare($query);
                $stmt->execute([
                    ':gym_limit' => $new_limit,
                    ':id' => $owner_id
                ]);
                
                // Add notification for the owner
                $notif_query = "INSERT INTO notifications (user_id, type, title, message, gym_id, created_at) 
                               VALUES (:user_id, 'gym_limit', 'Gym Limit Updated', 
                               'Your gym limit has been updated to $new_limit by admin.', 
                               0, NOW())";
                $notif_stmt = $conn->prepare($notif_query);
                $notif_stmt->execute([':user_id' => $owner_id]);
                
                // Log the activity
                $log_query = "INSERT INTO activity_logs (user_id, user_type, action, details, ip_address, user_agent) 
                             VALUES (:user_id, 'admin', 'update_gym_limit', :details, :ip, :user_agent)";
                $log_stmt = $conn->prepare($log_query);
                $log_stmt->execute([
                    ':user_id' => $_SESSION['user_id'],
                    ':details' => "Updated owner ID: $owner_id gym limit to $new_limit",
                    ':ip' => $_SERVER['REMOTE_ADDR'],
                    ':user_agent' => $_SERVER['HTTP_USER_AGENT']
                ]);
                
                $_SESSION['success'] = "Owner gym limit updated successfully!";
            } catch (PDOException $e) {
                $_SESSION['error'] = "Failed to update gym limit: " . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = "Invalid gym limit value.";
        }
    }
    
    // Redirect to refresh the page with updated data
    header("Location: view-owner.php?id=$owner_id");
    exit();
}

// Fetch owner details
$stmt = $conn->prepare("
    SELECT 
        go.*, 
        COALESCE(SUM(g.balance), 0) as total_balance,
        COUNT(g.gym_id) as total_gyms,
        GROUP_CONCAT(g.name SEPARATOR ', ') as gym_names,
        (SELECT COUNT(*) FROM login_history WHERE user_id = go.id AND user_type = 'gym_owner') as login_count,
        (SELECT login_time FROM login_history WHERE user_id = go.id AND user_type = 'gym_owner' ORDER BY login_time DESC LIMIT 1) as last_login
    FROM gym_owners go
    LEFT JOIN gyms g ON go.id = g.owner_id
    WHERE go.id = :id
    GROUP BY go.id
");
$stmt->execute([':id' => $owner_id]);
$owner = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$owner) {
    $_SESSION['error'] = "Owner not found.";
    header('Location: gym-owners.php');
    exit();
}

// Fetch owner's gyms
$stmt = $conn->prepare("
    SELECT 
        g.*, 
        (SELECT COUNT(*) FROM schedules WHERE gym_id = g.gym_id) as total_bookings,
        (SELECT AVG(rating) FROM reviews WHERE gym_id = g.gym_id) as avg_rating,
        (SELECT COUNT(*) FROM reviews WHERE gym_id = g.gym_id) as review_count
    FROM gyms g
    WHERE g.owner_id = :owner_id
    ORDER BY g.created_at DESC
");
$stmt->execute([':owner_id' => $owner_id]);
$gyms = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch owner's login history
$stmt = $conn->prepare("
    SELECT * FROM login_history
    WHERE user_id = :user_id AND user_type = 'gym_owner'
    ORDER BY login_time DESC
    LIMIT 10
");
$stmt->execute([':user_id' => $owner_id]);
$login_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch owner's activity logs
$stmt = $conn->prepare("
    SELECT * FROM activity_logs
    WHERE user_id = :user_id AND user_type = 'owner'
    ORDER BY created_at DESC
    LIMIT 15
");
$stmt->execute([':user_id' => $owner_id]);
$activity_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch owner's payment methods
$stmt = $conn->prepare("
    SELECT * FROM payment_methods
    WHERE owner_id = :owner_id
    ORDER BY is_primary DESC, created_at DESC
");
$stmt->execute([':owner_id' => $owner_id]);
$payment_methods = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
 <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($siteName) ?> - <?= htmlspecialchars($siteTagline) ?></title>
        <link rel="icon" href="<?= htmlspecialchars($faviconPath) ?>" type="image/x-icon">
        <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
        <style>
            .animate-fade-in {
                animation: fadeIn 1s ease-out;
            }

            .animate-fade-in-delay {
                animation: fadeIn 1s ease-out 0.3s both;
            }

            .animate-fade-in-delay-2 {
                animation: fadeIn 1s ease-out 0.6s both;
            }

            @keyframes fadeIn {
                from {
                    opacity: 0;
                    transform: translateY(20px);
                }

                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
        </style>
    </head>
<body class="bg-gray-900 text-white">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="ml-0 lg:ml-64 p-4 sm:p-6 transition-all duration-200">
    <!-- Display success/error messages -->
    <?php if (isset($_SESSION['success'])): ?>
        <div class="bg-green-500 text-white p-4 rounded-lg mb-6 flex items-center justify-between">
            <div>
                <i class="fas fa-check-circle mr-2"></i>
                <?= htmlspecialchars($_SESSION['success']) ?>
            </div>
            <button class="text-white focus:outline-none" onclick="this.parentElement.style.display='none'">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error'])): ?>
        <div class="bg-red-500 text-white p-4 rounded-lg mb-6 flex items-center justify-between">
            <div>
                <i class="fas fa-exclamation-circle mr-2"></i>
                <?= htmlspecialchars($_SESSION['error']) ?>
            </div>
            <button class="text-white focus:outline-none" onclick="this.parentElement.style.display='none'">
                <i class="fas fa-times"></i>
                </button>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <!-- Back button -->
    <div class="mb-6">
        <a href="gym-owners.php" class="inline-flex items-center text-yellow-400 hover:text-yellow-300 transition-colors duration-200">
            <i class="fas fa-arrow-left mr-2"></i> Back to Owners List
        </a>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Owner Profile Card -->
        <div class="bg-gray-800 rounded-lg shadow-lg overflow-hidden">
            <div class="relative">
                <div class="h-32 bg-gradient-to-r from-yellow-500 to-yellow-600"></div>
                <div class="absolute top-16 left-6">
                    <?php if (!empty($owner['profile_picture'])): ?>
                        <img src="../<?= htmlspecialchars($owner['profile_picture']) ?>" alt="<?= htmlspecialchars($owner['name']) ?>" class="h-24 w-24 rounded-full border-4 border-gray-800 object-cover">
                    <?php else: ?>
                        <div class="h-24 w-24 rounded-full border-4 border-gray-800 bg-gray-700 flex items-center justify-center">
                            <i class="fas fa-user text-4xl text-yellow-400"></i>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="p-6 pt-16">
                <div class="flex justify-between items-start">
                    <div>
                        <h2 class="text-2xl font-bold text-white"><?= htmlspecialchars($owner['name']) ?></h2>
                        <p class="text-gray-400 text-sm">
                            Joined: <?= date('M d, Y', strtotime($owner['created_at'])) ?>
                        </p>
                    </div>
                    <div>
                        <?php if ($owner['account_type'] === 'premium'): ?>
                            <span class="bg-yellow-500 text-black text-xs px-2 py-1 rounded-full font-semibold">Premium</span>
                        <?php else: ?>
                            <span class="bg-gray-600 text-white text-xs px-2 py-1 rounded-full">Basic</span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="mt-6 space-y-3">
                    <div class="flex items-center">
                        <i class="fas fa-envelope text-gray-500 w-6"></i>
                        <span class="ml-2 text-white"><?= htmlspecialchars($owner['email']) ?></span>
                    </div>
                    <div class="flex items-center">
                        <i class="fas fa-phone text-gray-500 w-6"></i>
                        <span class="ml-2 text-white"><?= htmlspecialchars($owner['phone']) ?></span>
                    </div>
                    <div class="flex items-center">
                        <i class="fas fa-map-marker-alt text-gray-500 w-6"></i>
                        <span class="ml-2 text-white">
                            <?= htmlspecialchars($owner['address']) ?>, 
                            <?= htmlspecialchars($owner['city']) ?>, 
                            <?= htmlspecialchars($owner['state']) ?>, 
                            <?= htmlspecialchars($owner['country']) ?> - 
                            <?= htmlspecialchars($owner['zip_code']) ?>
                        </span>
                    </div>
                </div>
                
                <div class="mt-6 grid grid-cols-2 gap-4">
                    <div class="bg-gray-700 p-3 rounded-lg text-center">
                        <p class="text-gray-400 text-xs">Total Gyms</p>
                        <p class="text-white text-xl font-bold"><?= $owner['total_gyms'] ?></p>
                        <p class="text-gray-400 text-xs">Limit: <?= $owner['gym_limit'] ?></p>
                    </div>
                    <div class="bg-gray-700 p-3 rounded-lg text-center">
                        <p class="text-gray-400 text-xs">Balance</p>
                        <p class="text-white text-xl font-bold">₹<?= number_format($owner['total_balance'], 2) ?></p>
                    </div>
                </div>
                
                <div class="mt-6">
                    <h3 class="text-white font-semibold mb-2">Account Status</h3>
                    <div class="flex flex-wrap gap-2">
                        <div class="px-3 py-1 rounded-full text-xs font-medium 
                            <?= $owner['status'] === 'active' ? 'bg-green-100 text-green-800' : 
                               ($owner['status'] === 'inactive' ? 'bg-gray-100 text-gray-800' : 'bg-red-100 text-red-800') ?>">
                            <?= ucfirst($owner['status']) ?>
                        </div>
                        
                        <div class="px-3 py-1 rounded-full text-xs font-medium 
                            <?= $owner['is_approved'] ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' ?>">
                            <?= $owner['is_approved'] ? 'Approved' : 'Pending Approval' ?>
                        </div>
                        
                        <div class="px-3 py-1 rounded-full text-xs font-medium 
                            <?= $owner['is_verified'] ? 'bg-blue-100 text-blue-800' : 'bg-red-100 text-red-800' ?>">
                            <?= $owner['is_verified'] ? 'Verified' : 'Unverified' ?>
                        </div>
                    </div>
                </div>
                
                <div class="mt-6 border-t border-gray-700 pt-4">
                    <h3 class="text-white font-semibold mb-3">Quick Actions</h3>
                    <div class="grid grid-cols-1 gap-3">
                        <?php if (!$owner['is_approved']): ?>
                            <form method="POST" action="view-owner.php?id=<?= $owner_id ?>">
                                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                <button type="submit" name="approve_owner" class="w-full bg-green-600 hover:bg-green-700 text-white py-2 px-4 rounded-lg transition-colors duration-200 flex items-center justify-center">
                                    <i class="fas fa-check-circle mr-2"></i> Approve Owner
                                </button>
                            </form>
                        <?php endif; ?>
                        
                        <?php if (!$owner['is_verified']): ?>
                            <form method="POST" action="view-owner.php?id=<?= $owner_id ?>">
                                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                <button type="submit" name="verify_owner" class="w-full bg-blue-600 hover:bg-blue-700 text-white py-2 px-4 rounded-lg transition-colors duration-200 flex items-center justify-center">
                                    <i class="fas fa-user-check mr-2"></i> Verify Owner
                                </button>
                            </form>
                        <?php endif; ?>
                        
                        <form method="POST" action="view-owner.php?id=<?= $owner_id ?>">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                            <input type="hidden" name="update_status" value="1">
                            <div class="flex space-x-2">
                                <select name="status" class="flex-grow border border-gray-600 bg-gray-700 text-white rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                    <option value="active" <?= $owner['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                    <option value="inactive" <?= $owner['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                    <option value="suspended" <?= $owner['status'] === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                                </select>
                                <button type="submit" class="bg-yellow-500 hover:bg-yellow-600 text-black py-2 px-4 rounded-lg transition-colors duration-200">
                                    <i class="fas fa-sync-alt"></i> Update Status
                                </button>
                            </div>
                        </form>
                        
                        <form method="POST" action="view-owner.php?id=<?= $owner_id ?>">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                            <input type="hidden" name="update_account_type" value="1">
                            <div class="flex space-x-2">
                                <select name="account_type" class="flex-grow border border-gray-600 bg-gray-700 text-white rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                    <option value="basic" <?= $owner['account_type'] === 'basic' ? 'selected' : '' ?>>Basic</option>
                                    <option value="premium" <?= $owner['account_type'] === 'premium' ? 'selected' : '' ?>>Premium</option>
                                </select>
                                <button type="submit" class="bg-purple-600 hover:bg-purple-700 text-white py-2 px-4 rounded-lg transition-colors duration-200">
                                    <i class="fas fa-crown"></i> Update Type
                                </button>
                            </div>
                        </form>
                        
                        <form method="POST" action="view-owner.php?id=<?= $owner_id ?>">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                            <input type="hidden" name="update_gym_limit" value="1">
                            <div class="flex space-x-2">
                                <input type="number" name="gym_limit" value="<?= $owner['gym_limit'] ?>" min="1" max="100" class="flex-grow border border-gray-600 bg-gray-700 text-white rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white py-2 px-4 rounded-lg transition-colors duration-200">
                                    <i class="fas fa-dumbbell"></i> Update Limit
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <div class="mt-6 border-t border-gray-700 pt-4">
                    <h3 class="text-white font-semibold mb-3">Management Links</h3>
                    <div class="grid grid-cols-1 gap-3">
                        <a href="edit-owner.php?id=<?= $owner_id ?>" class="bg-blue-600 hover:bg-blue-700 text-white py-2 px-4 rounded-lg transition-colors duration-200 flex items-center justify-center">
                            <i class="fas fa-user-edit mr-2"></i> Edit Owner Details
                        </a>
                        
                        <a href="owner-gyms.php?owner_id=<?= $owner_id ?>" class="bg-green-600 hover:bg-green-700 text-white py-2 px-4 rounded-lg transition-colors duration-200 flex items-center justify-center">
                            <i class="fas fa-dumbbell mr-2"></i> Manage Owner's Gyms
                        </a>
                        
                        <a href="owner-transactions.php?owner_id=<?= $owner_id ?>" class="bg-purple-600 hover:bg-purple-700 text-white py-2 px-4 rounded-lg transition-colors duration-200 flex items-center justify-center">
                            <i class="fas fa-money-bill-wave mr-2"></i> View Financial History
                        </a>
                        
                        <?php if ($owner['total_balance'] > 0): ?>
                            <a href="process_payout.php?owner_id=<?= $owner_id ?>" class="bg-yellow-500 hover:bg-yellow-600 text-black py-2 px-4 rounded-lg transition-colors duration-200 flex items-center justify-center">
                                <i class="fas fa-hand-holding-usd mr-2"></i> Process Payout (₹<?= number_format($owner['total_balance'], 2) ?>)
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Owner's Gyms and Details -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Owner's Gyms -->
            <div class="bg-gray-800 rounded-lg shadow-lg overflow-hidden">
                <div class="p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-xl font-bold text-white">Owner's Gyms (<?= count($gyms) ?>)</h2>
                        <a href="add_gym.php?owner_id=<?= $owner_id ?>" class="bg-green-600 hover:bg-green-700 text-white px-3 py-1 rounded-lg text-sm transition-colors duration-200">
                            <i class="fas fa-plus mr-1"></i> Add Gym
                        </a>
                    </div>
                    
                    <?php if (count($gyms) > 0): ?>
                        <div class="space-y-4">
                            <?php foreach ($gyms as $gym): ?>
                                <div class="bg-gray-700 rounded-lg p-4 flex flex-col md:flex-row">
                                    <div class="w-full md:w-24 h-24 md:mr-4 mb-4 md:mb-0">
                                        <?php if (!empty($gym['cover_photo'])): ?>
                                            <img src="../<?= htmlspecialchars($gym['cover_photo']) ?>" alt="<?= htmlspecialchars($gym['name']) ?>" class="w-full h-full object-cover rounded-lg">
                                        <?php else: ?>
                                            <div class="w-full h-full bg-gray-600 rounded-lg flex items-center justify-center">
                                            <i class="fas fa-dumbbell text-yellow-400 text-3xl"></i>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex-1">
                                        <div class="flex justify-between items-start">
                                            <div>
                                                <h3 class="text-white font-semibold"><?= htmlspecialchars($gym['name']) ?></h3>
                                                <p class="text-gray-400 text-sm">
                                                    <?= htmlspecialchars($gym['city']) ?>, <?= htmlspecialchars($gym['state']) ?>
                                                </p>
                                            </div>
                                            <div class="flex items-center">
                                                <span class="px-2 py-1 rounded-full text-xs font-medium 
                                                    <?= $gym['status'] === 'active' ? 'bg-green-100 text-green-800' : 
                                                    ($gym['status'] === 'pending' ? 'bg-yellow-100 text-yellow-800' : 
                                                    'bg-red-100 text-red-800') ?>">
                                                    <?= ucfirst($gym['status']) ?>
                                                </span>
                                            </div>
                                        </div>
                                        
                                        <div class="mt-2 grid grid-cols-3 gap-2 text-sm">
                                            <div>
                                                <span class="text-gray-400">Capacity:</span>
                                                <span class="text-white ml-1"><?= $gym['capacity'] ?></span>
                                            </div>
                                            <div>
                                                <span class="text-gray-400">Bookings:</span>
                                                <span class="text-white ml-1"><?= $gym['total_bookings'] ?? 0 ?></span>
                                            </div>
                                            <div>
                                                <span class="text-gray-400">Rating:</span>
                                                <span class="text-white ml-1">
                                                    <?= $gym['avg_rating'] ? number_format($gym['avg_rating'], 1) . '/5.0' : 'N/A' ?>
                                                    (<?= $gym['review_count'] ?? 0 ?>)
                                                </span>
                                            </div>
                                        </div>
                                        
                                        <div class="mt-3 flex flex-wrap gap-2">
                                            <a href="edit_gym.php?id=<?= $gym['gym_id'] ?>" class="text-blue-400 hover:text-blue-300 text-sm">
                                                <i class="fas fa-edit"></i> Edit
                                            </a>
                                            <a href="manage_gym_permissions.php?id=<?= $gym['gym_id'] ?>" class="text-purple-400 hover:text-purple-300 text-sm">
                                                <i class="fas fa-key"></i> Permissions
                                            </a>
                                            <a href="../gym-profile.php?id=<?= $gym['gym_id'] ?>" class="text-green-400 hover:text-green-300 text-sm">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                            <?php if ($gym['status'] === 'pending'): ?>
                                                <a href="approve_gym.php?id=<?= $gym['gym_id'] ?>" class="text-yellow-400 hover:text-yellow-300 text-sm">
                                                    <i class="fas fa-check-circle"></i> Approve
                                                </a>
                                            <?php endif; ?>
                                            <a href="gym_revenue.php?gym_id=<?= $gym['gym_id'] ?>" class="text-indigo-400 hover:text-indigo-300 text-sm">
                                                <i class="fas fa-chart-line"></i> Revenue
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="bg-gray-700 rounded-lg p-6 text-center">
                            <i class="fas fa-dumbbell text-gray-500 text-4xl mb-3"></i>
                            <p class="text-gray-300">This owner hasn't added any gyms yet.</p>
                            <a href="add_gym.php?owner_id=<?= $owner_id ?>" class="inline-block mt-3 bg-yellow-500 hover:bg-yellow-600 text-black px-4 py-2 rounded-lg transition-colors duration-200">
                                <i class="fas fa-plus mr-1"></i> Add First Gym
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Login History -->
            <div class="bg-gray-800 rounded-lg shadow-lg overflow-hidden">
                <div class="p-6">
                    <h2 class="text-xl font-bold text-white mb-4">Login History</h2>
                    
                    <?php if (count($login_history) > 0): ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-700">
                                <thead>
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Date & Time</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">IP Address</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Device</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Session Duration</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-700">
                                    <?php foreach ($login_history as $login): ?>
                                        <tr>
                                            <td class="px-4 py-3 whitespace-nowrap text-sm text-white">
                                                <?= date('M d, Y g:i A', strtotime($login['login_time'])) ?>
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-sm text-white">
                                                <?= htmlspecialchars($login['ip_address']) ?>
                                            </td>
                                            <td class="px-4 py-3 text-sm text-white">
                                                <?php
                                                    $user_agent = htmlspecialchars($login['user_agent']);
                                                    // Simple device detection
                                                    if (strpos($user_agent, 'Mobile') !== false) {
                                                        echo '<i class="fas fa-mobile-alt text-gray-400 mr-1"></i> Mobile';
                                                    } elseif (strpos($user_agent, 'Tablet') !== false) {
                                                        echo '<i class="fas fa-tablet-alt text-gray-400 mr-1"></i> Tablet';
                                                    } else {
                                                        echo '<i class="fas fa-desktop text-gray-400 mr-1"></i> Desktop';
                                                    }
                                                    
                                                    // Browser detection
                                                    if (strpos($user_agent, 'Chrome') !== false) {
                                                        echo ' - Chrome';
                                                    } elseif (strpos($user_agent, 'Firefox') !== false) {
                                                        echo ' - Firefox';
                                                    } elseif (strpos($user_agent, 'Safari') !== false) {
                                                        echo ' - Safari';
                                                    } elseif (strpos($user_agent, 'Edge') !== false) {
                                                        echo ' - Edge';
                                                    } elseif (strpos($user_agent, 'MSIE') !== false || strpos($user_agent, 'Trident') !== false) {
                                                        echo ' - Internet Explorer';
                                                    }
                                                ?>
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-sm text-white">
                                                <?php
                                                    if ($login['logout_time']) {
                                                        $login_time = new DateTime($login['login_time']);
                                                        $logout_time = new DateTime($login['logout_time']);
                                                        $diff = $login_time->diff($logout_time);
                                                        
                                                        if ($diff->h > 0) {
                                                            echo $diff->h . 'h ' . $diff->i . 'm';
                                                        } else {
                                                            echo $diff->i . 'm ' . $diff->s . 's';
                                                        }
                                                    } else {
                                                        echo '<span class="text-yellow-400">Session Active</span>';
                                                    }
                                                ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="bg-gray-700 rounded-lg p-4 text-center">
                            <p class="text-gray-300">No login history available.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Activity Logs -->
            <div class="bg-gray-800 rounded-lg shadow-lg overflow-hidden">
                <div class="p-6">
                    <h2 class="text-xl font-bold text-white mb-4">Recent Activity</h2>
                    
                    <?php if (count($activity_logs) > 0): ?>
                        <div class="space-y-3">
                            <?php foreach ($activity_logs as $log): ?>
                                <div class="bg-gray-700 rounded-lg p-3">
                                    <div class="flex justify-between items-start">
                                        <div class="flex items-start">
                                            <?php
                                                $icon_class = 'fas fa-info-circle text-blue-400';
                                                
                                                switch ($log['action']) {
                                                    case 'login':
                                                        $icon_class = 'fas fa-sign-in-alt text-green-400';
                                                        break;
                                                    case 'logout':
                                                        $icon_class = 'fas fa-sign-out-alt text-red-400';
                                                        break;
                                                    case 'add_gym':
                                                        $icon_class = 'fas fa-plus-circle text-green-400';
                                                        break;
                                                    case 'update_gym':
                                                        $icon_class = 'fas fa-edit text-yellow-400';
                                                        break;
                                                    case 'delete_gym':
                                                        $icon_class = 'fas fa-trash-alt text-red-400';
                                                        break;
                                                    case 'payment':
                                                        $icon_class = 'fas fa-money-bill-wave text-green-400';
                                                        break;
                                                    case 'withdrawal':
                                                        $icon_class = 'fas fa-hand-holding-usd text-yellow-400';
                                                        break;
                                                }
                                            ?>
                                            <i class="<?= $icon_class ?> mt-1 mr-3"></i>
                                            <div>
                                                <p class="text-white text-sm">
                                                    <?= ucfirst(str_replace('_', ' ', $log['action'])) ?>
                                                </p>
                                                <p class="text-gray-400 text-xs">
                                                    <?= htmlspecialchars($log['details']) ?>
                                                </p>
                                            </div>
                                        </div>
                                        <div class="text-gray-400 text-xs">
                                            <?= date('M d, Y g:i A', strtotime($log['created_at'])) ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="bg-gray-700 rounded-lg p-4 text-center">
                            <p class="text-gray-300">No activity logs available.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Payment Methods -->
            <div class="bg-gray-800 rounded-lg shadow-lg overflow-hidden">
                <div class="p-6">
                    <h2 class="text-xl font-bold text-white mb-4">Payment Methods</h2>
                    
                    <?php if (count($payment_methods) > 0): ?>
                        <div class="space-y-3">
                            <?php foreach ($payment_methods as $method): ?>
                                <div class="bg-gray-700 rounded-lg p-4">
                                    <div class="flex justify-between items-start">
                                        <div>
                                            <?php if ($method['method_type'] === 'bank'): ?>
                                                <div class="flex items-center">
                                                    <i class="fas fa-university text-blue-400 mr-2"></i>
                                                    <h3 class="text-white font-medium">
                                                        <?= htmlspecialchars($method['bank_name']) ?>
                                                        <?php if ($method['is_primary']): ?>
                                                            <span class="ml-2 bg-yellow-500 text-black text-xs px-2 py-0.5 rounded-full">Primary</span>
                                                        <?php endif; ?>
                                                    </h3>
                                                </div>
                                                <p class="text-gray-400 text-sm mt-1">Account: <?= htmlspecialchars($method['account_name']) ?></p>
                                                <p class="text-gray-400 text-sm">
                                                    Account #: <?= substr(htmlspecialchars($method['account_number']), 0, 4) . '********' ?>
                                                </p>
                                                <p class="text-gray-400 text-sm">IFSC: <?= htmlspecialchars($method['ifsc_code']) ?></p>
                                            <?php else: ?>
                                                <div class="flex items-center">
                                                    <i class="fas fa-mobile-alt text-green-400 mr-2"></i>
                                                    <h3 class="text-white font-medium">
                                                        UPI
                                                        <?php if ($method['is_primary']): ?>
                                                            <span class="ml-2 bg-yellow-500 text-black text-xs px-2 py-0.5 rounded-full">Primary</span>
                                                        <?php endif; ?>
                                                    </h3>
                                                    </div>
                                                <p class="text-gray-400 text-sm mt-1">UPI ID: <?= htmlspecialchars($method['upi_id']) ?></p>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-gray-400 text-xs">
                                            Added: <?= date('M d, Y', strtotime($method['created_at'])) ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="bg-gray-700 rounded-lg p-4 text-center">
                            <p class="text-gray-300">No payment methods added yet.</p>
                            <p class="text-gray-400 text-sm mt-2">The owner needs to add payment methods to receive payouts.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Notes and Admin Comments -->
            <div class="bg-gray-800 rounded-lg shadow-lg overflow-hidden">
                <div class="p-6">
                    <h2 class="text-xl font-bold text-white mb-4">Admin Notes</h2>
                    
                    <form method="POST" action="save_owner_notes.php">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="hidden" name="owner_id" value="<?= $owner_id ?>">
                        
                        <div class="mb-4">
                            <textarea name="admin_notes" rows="4" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500" placeholder="Add private notes about this owner (only visible to admins)"><?= htmlspecialchars($owner['admin_notes'] ?? '') ?></textarea>
                        </div>
                        
                        <div class="flex justify-end">
                            <button type="submit" class="bg-yellow-500 hover:bg-yellow-600 text-black px-4 py-2 rounded-lg transition-colors duration-200">
                                <i class="fas fa-save mr-2"></i> Save Notes
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.bg-green-500, .bg-red-500');
            alerts.forEach(alert => {
                alert.style.display = 'none';
            });
        }, 5000);
        
        // Confirmation for status changes
        const statusForm = document.querySelector('form[name="update_status"]');
        if (statusForm) {
            statusForm.addEventListener('submit', function(e) {
                const newStatus = this.querySelector('select[name="status"]').value;
                if (newStatus === 'suspended') {
                    if (!confirm('Are you sure you want to suspend this owner? This will prevent them from accessing their account.')) {
                        e.preventDefault();
                    }
                }
            });
        }
        
        // Confirmation for account type changes
        const accountTypeForm = document.querySelector('form[name="update_account_type"]');
        if (accountTypeForm) {
            accountTypeForm.addEventListener('submit', function(e) {
                const newType = this.querySelector('select[name="account_type"]').value;
                const currentType = '<?= $owner['account_type'] ?>';
                
                if (currentType === 'premium' && newType === 'basic') {
                    if (!confirm('Downgrading from Premium to Basic will remove premium features. Are you sure?')) {
                        e.preventDefault();
                    }
                }
            });
        }
        
        // Confirmation for gym limit changes
        const gymLimitForm = document.querySelector('form[name="update_gym_limit"]');
        if (gymLimitForm) {
            gymLimitForm.addEventListener('submit', function(e) {
                const newLimit = parseInt(this.querySelector('input[name="gym_limit"]').value);
                const currentGyms = <?= $owner['total_gyms'] ?>;
                
                if (newLimit < currentGyms) {
                    if (!confirm(`This owner currently has ${currentGyms} gyms. Reducing the limit to ${newLimit} may cause issues. Are you sure?`)) {
                        e.preventDefault();
                    }
                }
            });
        }
    });
</script>





