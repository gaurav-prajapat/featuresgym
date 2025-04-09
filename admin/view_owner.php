<?php
ob_start();
require_once '../config/database.php';
session_start();

// Check if user is logged in as admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: /profitmarts/FlexFit/views/auth/login.php');
    exit();
}

// Check if owner ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: gym_owners.php');
    exit();
}

$owner_id = (int)$_GET['id'];
$db = new GymDatabase();
$conn = $db->getConnection();

// Handle gym limit update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_gym_limit'])) {
    $new_limit = (int)$_POST['gym_limit'];
    
    if ($new_limit >= 0) {
        try {
            $stmt = $conn->prepare("UPDATE gym_owners SET gym_limit = ? WHERE id = ?");
            $stmt->execute([$new_limit, $owner_id]);
            
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

// Fetch owner details
try {
    $stmt = $conn->prepare("
        SELECT * FROM gym_owners WHERE id = ?
    ");
    $stmt->execute([$owner_id]);
    $owner = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$owner) {
        $_SESSION['error'] = "Owner not found.";
        header('Location: gym_owners.php');
        exit();
    }
    
    // Fetch owner's gyms
    $stmt = $conn->prepare("
        SELECT * FROM gyms WHERE owner_id = ? ORDER BY created_at DESC
    ");
    $stmt->execute([$owner_id]);
    $gyms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch owner's payment methods
    $stmt = $conn->prepare("
        SELECT * FROM payment_methods WHERE owner_id = ? ORDER BY is_primary DESC
    ");
    $stmt->execute([$owner_id]);
    $payment_methods = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch owner's activity logs
    $stmt = $conn->prepare("
        SELECT * FROM activity_logs 
        WHERE user_id = ? AND user_type = 'owner' 
        ORDER BY created_at DESC 
        LIMIT 50
    ");
    $stmt->execute([$owner_id]);
    $activity_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $_SESSION['error'] = "Database error: " . $e->getMessage();
    header('Location: gym_owners.php');
    exit();
}

// Helper function to format date/time
function formatTime($datetime) {
    $timestamp = strtotime($datetime);
    return date('M d, Y h:i A', $timestamp);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Gym Owner - FlexFit Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
    </style>
</head>
<body class="bg-gray-900 text-white">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="ml-0 lg:ml-64 p-4 sm:p-6 transition-all duration-200">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold">Gym Owner Details</h1>
            <div class="flex space-x-2">
                <a href="gym_owners.php" class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                    <i class="fas fa-arrow-left mr-2"></i> Back to Owners
                </a>
                <a href="edit_owner.php?id=<?php echo $owner_id; ?>" class="bg-yellow-600 hover:bg-yellow-700 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                    <i class="fas fa-edit mr-2"></i> Edit Owner
                </a>
            </div>
        </div>
        
        <?php if (isset($_SESSION['success'])): ?>
            <div class="bg-green-600 text-white p-4 rounded-lg mb-6">
                <?php 
                    echo $_SESSION['success'];
                    unset($_SESSION['success']);
                ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="bg-red-600 text-white p-4 rounded-lg mb-6">
                <?php 
                    echo $_SESSION['error'];
                    unset($_SESSION['error']);
                ?>
            </div>
        <?php endif; ?>
        
        <!-- Owner Profile Header -->
        <div class="bg-gray-800 rounded-xl shadow-lg overflow-hidden mb-6">
            <div class="p-6 bg-gradient-to-r from-blue-900 to-gray-800">
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                    <div class="flex items-center mb-4 md:mb-0">
                        <div class="flex-shrink-0 h-20 w-20">
                            <?php if (!empty($owner['profile_picture'])): ?>
                                <img class="h-20 w-20 rounded-full object-cover" src="<?php echo '../' . htmlspecialchars($owner['profile_picture']); ?>" alt="Profile image">
                            <?php else: ?>
                                <div class="h-20 w-20 rounded-full bg-gray-600 flex items-center justify-center">
                                    <i class="fas fa-user-tie text-3xl text-gray-300"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="ml-4">
                            <h2 class="text-2xl font-bold"><?php echo htmlspecialchars($owner['name']); ?></h2>
                            <div class="flex flex-wrap items-center mt-1">
                                <span class="text-gray-300 mr-4">
                                    <i class="fas fa-envelope mr-1"></i> <?php echo htmlspecialchars($owner['email']); ?>
                                </span>
                                <span class="text-gray-300">
                                    <i class="fas fa-phone mr-1"></i> <?php echo htmlspecialchars($owner['phone']); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="flex flex-col items-end">
                        <div class="flex space-x-2 mb-2">
                            <?php
                                $statusClass = '';
                                $statusText = '';
                                
                                switch ($owner['status']) {
                                    case 'active':
                                        $statusClass = 'bg-green-900 text-green-300';
                                        $statusText = 'Active';
                                        break;
                                        case 'inactive':
                                            $statusClass = 'bg-yellow-900 text-yellow-300';
                                            $statusText = 'Inactive';
                                            break;
                                        case 'suspended':
                                            $statusClass = 'bg-red-900 text-red-300';
                                            $statusText = 'Suspended';
                                            break;
                                        default:
                                            $statusClass = 'bg-gray-700 text-gray-300';
                                            $statusText = 'Unknown';
                                    }
                                ?>
                                <span class="px-3 py-1 rounded-full text-sm font-semibold <?php echo $statusClass; ?>">
                                    <?php echo $statusText; ?>
                                </span>
                                
                                <?php if ($owner['is_verified']): ?>
                                    <span class="px-3 py-1 rounded-full text-sm font-semibold bg-blue-900 text-blue-300">
                                        <i class="fas fa-check-circle mr-1"></i> Verified
                                    </span>
                                <?php endif; ?>
                                
                                <?php if ($owner['is_approved']): ?>
                                    <span class="px-3 py-1 rounded-full text-sm font-semibold bg-indigo-900 text-indigo-300">
                                        <i class="fas fa-thumbs-up mr-1"></i> Approved
                                    </span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="text-sm text-gray-400">
                                <span>Account Type: <span class="font-semibold text-white"><?php echo ucfirst(htmlspecialchars($owner['account_type'])); ?></span></span>
                                <span class="ml-4">Joined: <span class="font-semibold text-white"><?php echo date('M d, Y', strtotime($owner['created_at'])); ?></span></span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Tabs Navigation -->
                <div class="bg-gray-700 px-6 py-3">
                    <div class="flex overflow-x-auto">
                        <button class="tab-btn text-white px-4 py-2 font-medium rounded-lg mr-2 bg-blue-600" data-tab="overview">
                            <i class="fas fa-info-circle mr-2"></i> Overview
                        </button>
                        <button class="tab-btn text-white px-4 py-2 font-medium rounded-lg mr-2 hover:bg-gray-600" data-tab="gyms">
                            <i class="fas fa-dumbbell mr-2"></i> Gyms (<?php echo count($gyms); ?>)
                        </button>
                        <button class="tab-btn text-white px-4 py-2 font-medium rounded-lg mr-2 hover:bg-gray-600" data-tab="payment-methods">
                            <i class="fas fa-credit-card mr-2"></i> Payment Methods
                        </button>
                        <button class="tab-btn text-white px-4 py-2 font-medium rounded-lg mr-2 hover:bg-gray-600" data-tab="activity">
                            <i class="fas fa-history mr-2"></i> Activity Log
                        </button>
                    </div>
                </div>
            </div>
            <?php
// Add this code after fetching owner details and before the HTML output
// Calculate statistics for the owner

try {
    // Get total gyms count
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_gyms,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_gyms
        FROM gyms 
        WHERE owner_id = ?
    ");
    $stmt->execute([$owner_id]);
    $gym_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get total members count across all gyms
    $stmt = $conn->prepare("
        SELECT 
            COUNT(DISTINCT um.user_id) as total_members,
            SUM(CASE WHEN um.status = 'active' THEN 1 ELSE 0 END) as active_members
        FROM user_memberships um
        JOIN gyms g ON um.gym_id = g.gym_id
        WHERE g.owner_id = ?
    ");
    $stmt->execute([$owner_id]);
    $member_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get total balance across all gyms
    $stmt = $conn->prepare("
        SELECT SUM(balance) as total_balance
        FROM gyms
        WHERE owner_id = ?
    ");
    $stmt->execute([$owner_id]);
    $balance_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get total withdrawals
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_withdrawals,
            SUM(w.amount) as total_withdrawn
        FROM withdrawals w
        JOIN gyms g ON w.gym_id = g.gym_id
        WHERE g.owner_id = ? AND w.status = 'completed'
    ");
    $stmt->execute([$owner_id]);
    $withdrawal_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Combine all stats
    $stats = [
        'total_gyms' => $gym_stats['total_gyms'] ?? 0,
        'active_gyms' => $gym_stats['active_gyms'] ?? 0,
        'total_members' => $member_stats['total_members'] ?? 0,
        'active_members' => $member_stats['active_members'] ?? 0,
        'total_balance' => $balance_stats['total_balance'] ?? 0,
        'total_withdrawals' => $withdrawal_stats['total_withdrawals'] ?? 0,
        'total_withdrawn' => $withdrawal_stats['total_withdrawn'] ?? 0
    ];
    
} catch (PDOException $e) {
    // If there's an error, set default values
    $stats = [
        'total_gyms' => count($gyms),
        'active_gyms' => count(array_filter($gyms, function($gym) { return $gym['status'] === 'active'; })),
        'total_members' => 0,
        'active_members' => 0,
        'total_balance' => 0,
        'total_withdrawals' => 0,
        'total_withdrawn' => 0
    ];
}
?>

<!-- Then in the HTML section, update the statistics cards section -->
<!-- Statistics Cards -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="bg-gray-800 rounded-xl shadow-lg p-4">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-gray-400 text-sm">Total Gyms</p>
                <h3 class="text-2xl font-bold"><?php echo $stats['total_gyms']; ?></h3>
                <p class="text-sm text-gray-400">
                    <span class="text-green-400"><?php echo $stats['active_gyms']; ?></span> active
                </p>
            </div>
            <div class="bg-blue-900 bg-opacity-50 p-3 rounded-full">
                <i class="fas fa-dumbbell text-blue-400 text-xl"></i>
            </div>
        </div>
    </div>
    
    <div class="bg-gray-800 rounded-xl shadow-lg p-4">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-gray-400 text-sm">Total Members</p>
                <h3 class="text-2xl font-bold"><?php echo $stats['total_members']; ?></h3>
                <p class="text-sm text-gray-400">
                    <span class="text-green-400"><?php echo $stats['active_members']; ?></span> active
                </p>
            </div>
            <div class="bg-green-900 bg-opacity-50 p-3 rounded-full">
                <i class="fas fa-users text-green-400 text-xl"></i>
            </div>
        </div>
    </div>
    
    <div class="bg-gray-800 rounded-xl shadow-lg p-4">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-gray-400 text-sm">Total Balance</p>
                <h3 class="text-2xl font-bold">₹<?php echo number_format($stats['total_balance'], 2); ?></h3>
                <p class="text-sm text-gray-400">
                    Across all gyms
                </p>
            </div>
            <div class="bg-yellow-900 bg-opacity-50 p-3 rounded-full">
                <i class="fas fa-wallet text-yellow-400 text-xl"></i>
            </div>
        </div>
    </div>
    
    <div class="bg-gray-800 rounded-xl shadow-lg p-4">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-gray-400 text-sm">Total Withdrawn</p>
                <h3 class="text-2xl font-bold">₹<?php echo number_format($stats['total_withdrawn'] ?? 0, 2); ?></h3>
                <p class="text-sm text-gray-400">
                    <?php echo $stats['total_withdrawals']; ?> withdrawals
                </p>
            </div>
            <div class="bg-purple-900 bg-opacity-50 p-3 rounded-full">
                <i class="fas fa-money-bill-wave text-purple-400 text-xl"></i>
            </div>
        </div>
    </div>
</div>

            
            <!-- Tab Content -->
            <div class="tab-content active" id="overview-content">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                    <!-- Owner Stats -->
                    <div class="bg-gray-800 rounded-xl shadow-lg overflow-hidden">
                        <div class="p-4 border-b border-gray-700">
                            <h3 class="text-lg font-semibold">Owner Stats</h3>
                        </div>
                        <div class="p-4">
                            <div class="grid grid-cols-2 gap-4">
                                <div class="bg-gray-700 p-4 rounded-lg">
                                    <div class="text-sm text-gray-400">Total Gyms</div>
                                    <div class="text-2xl font-bold"><?php echo count($gyms); ?></div>
                                </div>
                                <div class="bg-gray-700 p-4 rounded-lg">
                                    <div class="text-sm text-gray-400">Gym Limit</div>
                                    <div class="text-2xl font-bold">
                                        <?php 
                                            if ($owner['gym_limit'] == 0) {
                                                echo 'Unlimited';
                                            } else {
                                                echo $owner['gym_limit'];
                                            }
                                        ?>
                                    </div>
                                </div>
                                <div class="bg-gray-700 p-4 rounded-lg">
                                    <div class="text-sm text-gray-400">Active Gyms</div>
                                    <div class="text-2xl font-bold">
                                        <?php 
                                            $activeGyms = array_filter($gyms, function($gym) {
                                                return $gym['status'] === 'active';
                                            });
                                            echo count($activeGyms);
                                        ?>
                                    </div>
                                </div>
                                <div class="bg-gray-700 p-4 rounded-lg">
                                    <div class="text-sm text-gray-400">Account Age</div>
                                    <div class="text-2xl font-bold">
                                        <?php 
                                            $created = new DateTime($owner['created_at']);
                                            $now = new DateTime();
                                            $diff = $created->diff($now);
                                            echo $diff->days . ' days';
                                        ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Update Gym Limit Form -->
                            <form method="POST" class="mt-4">
                                <div class="flex items-end space-x-2">
                                    <div class="flex-1">
                                        <label for="gym_limit" class="block text-sm font-medium text-gray-400 mb-1">Update Gym Limit</label>
                                        <input type="number" id="gym_limit" name="gym_limit" value="<?php echo $owner['gym_limit']; ?>" min="0" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                                    </div>
                                    <button type="submit" name="update_gym_limit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                                        <i class="fas fa-save mr-2"></i> Update
                                    </button>
                                </div>
                                <p class="text-xs text-gray-400 mt-1">Set to 0 for unlimited gyms</p>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Contact Information -->
                    <div class="bg-gray-800 rounded-xl shadow-lg overflow-hidden">
                        <div class="p-4 border-b border-gray-700">
                            <h3 class="text-lg font-semibold">Contact Information</h3>
                        </div>
                        <div class="p-4">
                            <ul class="space-y-3">
                                <li class="flex items-start">
                                    <i class="fas fa-envelope text-gray-400 mt-1 w-5"></i>
                                    <div class="ml-3">
                                        <div class="text-sm text-gray-400">Email</div>
                                        <div class="font-medium"><?php echo htmlspecialchars($owner['email']); ?></div>
                                    </div>
                                </li>
                                <li class="flex items-start">
                                    <i class="fas fa-phone text-gray-400 mt-1 w-5"></i>
                                    <div class="ml-3">
                                        <div class="text-sm text-gray-400">Phone</div>
                                        <div class="font-medium"><?php echo htmlspecialchars($owner['phone']); ?></div>
                                    </div>
                                </li>
                                <li class="flex items-start">
                                    <i class="fas fa-map-marker-alt text-gray-400 mt-1 w-5"></i>
                                    <div class="ml-3">
                                        <div class="text-sm text-gray-400">Address</div>
                                        <div class="font-medium"><?php echo htmlspecialchars($owner['address']); ?></div>
                                        <div class="text-sm"><?php echo htmlspecialchars($owner['city'] . ', ' . $owner['state'] . ' ' . $owner['zip_code']); ?></div>
                                        <div class="text-sm"><?php echo htmlspecialchars($owner['country']); ?></div>
                                    </div>
                                </li>
                            </ul>
                        </div>
                    </div>
                    
                    <!-- Account Status -->
                    <div class="bg-gray-800 rounded-xl shadow-lg overflow-hidden">
                        <div class="p-4 border-b border-gray-700">
                            <h3 class="text-lg font-semibold">Account Status</h3>
                        </div>
                        <div class="p-4">
                            <ul class="space-y-3">
                                <li class="flex items-center justify-between">
                                    <span class="text-gray-400">Status</span>
                                    <span class="px-3 py-1 rounded-full text-sm font-semibold <?php echo $statusClass; ?>">
                                        <?php echo $statusText; ?>
                                    </span>
                                </li>
                                <li class="flex items-center justify-between">
                                    <span class="text-gray-400">Email Verified</span>
                                    <?php if ($owner['is_verified']): ?>
                                        <span class="px-3 py-1 rounded-full text-sm font-semibold bg-green-900 text-green-300">Yes</span>
                                    <?php else: ?>
                                        <span class="px-3 py-1 rounded-full text-sm font-semibold bg-red-900 text-red-300">No</span>
                                    <?php endif; ?>
                                </li>
                                <li class="flex items-center justify-between">
                                    <span class="text-gray-400">Admin Approved</span>
                                    <?php if ($owner['is_approved']): ?>
                                        <span class="px-3 py-1 rounded-full text-sm font-semibold bg-green-900 text-green-300">Yes</span>
                                    <?php else: ?>
                                        <span class="px-3 py-1 rounded-full text-sm font-semibold bg-red-900 text-red-300">No</span>
                                    <?php endif; ?>
                                </li>
                                <li class="flex items-center justify-between">
                                    <span class="text-gray-400">Terms Agreed</span>
                                    <?php if ($owner['terms_agreed']): ?>
                                        <span class="px-3 py-1 rounded-full text-sm font-semibold bg-green-900 text-green-300">Yes</span>
                                    <?php else: ?>
                                        <span class="px-3 py-1 rounded-full text-sm font-semibold bg-red-900 text-red-300">No</span>
                                    <?php endif; ?>
                                </li>
                                <li class="flex items-center justify-between">
                                    <span class="text-gray-400">Account Type</span>
                                    <span class="px-3 py-1 rounded-full text-sm font-semibold bg-blue-900 text-blue-300">
                                        <?php echo ucfirst(htmlspecialchars($owner['account_type'])); ?>
                                    </span>
                                </li>
                            </ul>
                            
                            <div class="mt-4 grid grid-cols-1 gap-2">
                                <?php if ($owner['status'] === 'active'): ?>
                                    <a href="gym_owners.php?action=deactivate&id=<?php echo $owner_id; ?>" class="bg-yellow-600 hover:bg-yellow-700 text-white text-center px-4 py-2 rounded-lg transition-colors duration-200" onclick="return confirm('Are you sure you want to deactivate this owner?');">
                                        <i class="fas fa-user-slash mr-2"></i> Deactivate Account
                                    </a>
                                <?php elseif ($owner['status'] === 'inactive' || $owner['status'] === 'suspended'): ?>
                                    <a href="gym_owners.php?action=activate&id=<?php echo $owner_id; ?>" class="bg-green-600 hover:bg-green-700 text-white text-center px-4 py-2 rounded-lg transition-colors duration-200" onclick="return confirm('Are you sure you want to activate this owner?');">
                                        <i class="fas fa-user-check mr-2"></i> Activate Account
                                    </a>
                                <?php endif; ?>
                                
                                <?php if ($owner['status'] !== 'suspended'): ?>
                                    <a href="gym_owners.php?action=suspend&id=<?php echo $owner_id; ?>" class="bg-red-600 hover:bg-red-700 text-white text-center px-4 py-2 rounded-lg transition-colors duration-200" onclick="return confirm('Are you sure you want to suspend this owner?');">
                                        <i class="fas fa-ban mr-2"></i> Suspend Account
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Gyms Tab -->
            <div class="tab-content" id="gyms-content">
                <div class="bg-gray-800 rounded-xl shadow-lg overflow-hidden">
                    <div class="p-4 border-b border-gray-700 flex justify-between items-center">
                        <h3 class="text-lg font-semibold">Owner's Gyms</h3>
                        <a href="add_gym.php?owner_id=<?php echo $owner_id; ?>" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                            <i class="fas fa-plus mr-2"></i> Add New Gym
                        </a>
                    </div>
                    
                    <?php if (empty($gyms)): ?>
                        <div class="p-6 text-center text-gray-500">
                            <i class="fas fa-dumbbell text-4xl mb-3"></i>
                            <p>This owner has not created any gyms yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="p-6 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <?php foreach ($gyms as $gym): ?>
                            <?php
                                $statusClass = '';
                                $statusBadge = '';
                                
                                switch ($gym['status']) {
                                    case 'active':
                                        $statusClass = 'bg-green-900 text-green-300';
                                        $statusBadge = '<span class="px-2 py-1 text-xs rounded-full bg-green-900 text-green-300">Active</span>';
                                        break;
                                    case 'inactive':
                                        $statusClass = 'bg-yellow-900 text-yellow-300';
                                        $statusBadge = '<span class="px-2 py-1 text-xs rounded-full bg-yellow-900 text-yellow-300">Inactive</span>';
                                        break;
                                    case 'pending':
                                        $statusClass = 'bg-blue-900 text-blue-300';
                                        $statusBadge = '<span class="px-2 py-1 text-xs rounded-full bg-blue-900 text-blue-300">Pending</span>';
                                        break;
                                    case 'suspended':
                                        $statusClass = 'bg-red-900 text-red-300';
                                        $statusBadge = '<span class="px-2 py-1 text-xs rounded-full bg-red-900 text-red-300">Suspended</span>';
                                        break;
                                    default:
                                        $statusClass = 'bg-gray-700 text-gray-300';
                                        $statusBadge = '<span class="px-2 py-1 text-xs rounded-full bg-gray-700 text-gray-300">Unknown</span>';
                                }
                            ?>
                            <div class="bg-gray-700 rounded-lg overflow-hidden">
                                <div class="h-40 bg-gray-800 relative">
                                    <img src="../uploads/gym_images/<?php echo htmlspecialchars($gym['cover_photo'] ?? 'default_gym.jpg'); ?>" 
                                         alt="<?php echo htmlspecialchars($gym['name']); ?>" 
                                         class="w-full h-full object-cover">
                                    
                                    <?php if ($gym['is_featured']): ?>
                                        <div class="absolute top-2 right-2 bg-yellow-500 text-black text-xs px-2 py-1 rounded-full font-bold">
                                            <i class="fas fa-star mr-1"></i> Featured
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="p-4 border-b border-gray-600 flex justify-between items-center">
                                    <h3 class="font-semibold text-lg"><?php echo htmlspecialchars($gym['name']); ?></h3>
                                    <?php echo $statusBadge; ?>
                                </div>
                                
                                <div class="p-4">
                                    <div class="mb-3">
                                        <div class="text-sm text-gray-400">Location</div>
                                        <div class="font-medium"><?php echo htmlspecialchars($gym['address']); ?></div>
                                        <div class="text-sm"><?php echo htmlspecialchars($gym['city'] . ', ' . $gym['state'] . ' ' . $gym['zip_code']); ?></div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <div class="text-sm text-gray-400">Contact</div>
                                        <div class="font-medium"><?php echo htmlspecialchars($gym['phone']); ?></div>
                                        <div class="text-sm"><?php echo htmlspecialchars($gym['email']); ?></div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <div class="text-sm text-gray-400">Added On</div>
                                        <div class="text-sm"><?php echo date('M d, Y', strtotime($gym['created_at'])); ?></div>
                                    </div>
                                    
                                    <div class="flex flex-wrap gap-2 mt-4">
                                        <a href="view_gym.php?id=<?php echo $gym['gym_id']; ?>" class="bg-blue-600 hover:bg-blue-700 text-white text-sm px-3 py-1 rounded-lg transition-colors duration-200">
                                            <i class="fas fa-eye mr-1"></i> View
                                        </a>
                                        
                                        <a href="edit_gym.php?id=<?php echo $gym['gym_id']; ?>" class="bg-yellow-600 hover:bg-yellow-700 text-white text-sm px-3 py-1 rounded-lg transition-colors duration-200">
                                            <i class="fas fa-edit mr-1"></i> Edit
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Payment Methods Tab -->
        <div class="tab-content" id="payment-methods-content">
            <div class="bg-gray-800 rounded-xl shadow-lg overflow-hidden">
                <div class="p-4 border-b border-gray-700 flex justify-between items-center">
                    <h3 class="text-lg font-semibold">Payment Methods</h3>
                    <a href="add_payment_method.php?owner_id=<?php echo $owner_id; ?>" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                        <i class="fas fa-plus mr-2"></i> Add Payment Method
                    </a>
                </div>
                
                <?php if (empty($payment_methods)): ?>
                    <div class="p-6 text-center text-gray-500">
                        <i class="fas fa-credit-card text-4xl mb-3"></i>
                        <p>No payment methods have been added yet.</p>
                    </div>
                <?php else: ?>
                    <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-6">
                        <?php foreach ($payment_methods as $method): ?>
                            <div class="bg-gray-700 rounded-lg p-4">
                                <?php if ($method['method_type'] === 'bank'): ?>
                                    <div class="flex items-center justify-between mb-3">
                                        <div class="flex items-center">
                                            <i class="fas fa-university text-2xl text-blue-400 mr-3"></i>
                                            <div>
                                                <h4 class="font-semibold"><?php echo htmlspecialchars($method['bank_name']); ?></h4>
                                                <p class="text-sm text-gray-400">Bank Account</p>
                                            </div>
                                        </div>
                                        <?php if ($method['is_primary']): ?>
                                            <span class="px-2 py-1 text-xs rounded-full bg-green-900 text-green-300">Primary</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="space-y-2">
                                        <div>
                                            <div class="text-sm text-gray-400">Account Name</div>
                                            <div class="font-medium"><?php echo htmlspecialchars($method['account_name']); ?></div>
                                        </div>
                                        <div>
                                            <div class="text-sm text-gray-400">Account Number</div>
                                            <div class="font-medium">XXXX<?php echo substr(htmlspecialchars($method['account_number']), -4); ?></div>
                                        </div>
                                        <div>
                                            <div class="text-sm text-gray-400">IFSC Code</div>
                                            <div class="font-medium"><?php echo htmlspecialchars($method['ifsc_code']); ?></div>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="flex items-center justify-between mb-3">
                                        <div class="flex items-center">
                                            <i class="fas fa-mobile-alt text-2xl text-green-400 mr-3"></i>
                                            <div>
                                                <h4 class="font-semibold">UPI</h4>
                                                <p class="text-sm text-gray-400">UPI Payment</p>
                                            </div>
                                        </div>
                                        <?php if ($method['is_primary']): ?>
                                            <span class="px-2 py-1 text-xs rounded-full bg-green-900 text-green-300">Primary</span>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <div class="text-sm text-gray-400">UPI ID</div>
                                        <div class="font-medium"><?php echo htmlspecialchars($method['upi_id']); ?></div>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="flex flex-wrap gap-2 mt-4">
                                    <a href="edit_payment_method.php?id=<?php echo $method['id']; ?>" class="bg-yellow-600 hover:bg-yellow-700 text-white text-sm px-3 py-1 rounded-lg transition-colors duration-200">
                                        <i class="fas fa-edit mr-1"></i> Edit
                                    </a>
                                    
                                    <?php if (!$method['is_primary']): ?>
                                        <a href="set_primary_payment.php?id=<?php echo $method['id']; ?>&owner_id=<?php echo $owner_id; ?>" class="bg-green-600 hover:bg-green-700 text-white text-sm px-3 py-1 rounded-lg transition-colors duration-200">
                                            <i class="fas fa-check-circle mr-1"></i> Set as Primary
                                        </a>
                                    <?php endif; ?>
                                    
                                    <a href="delete_payment_method.php?id=<?php echo $method['id']; ?>&owner_id=<?php echo $owner_id; ?>" class="bg-red-600 hover:bg-red-700 text-white text-sm px-3 py-1 rounded-lg transition-colors duration-200" onclick="return confirm('Are you sure you want to delete this payment method?');">
                                        <i class="fas fa-trash-alt mr-1"></i> Delete
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Activity Log Tab -->
        <div class="tab-content" id="activity-content">
            <div class="bg-gray-800 rounded-xl shadow-lg overflow-hidden">
                <div class="p-4 border-b border-gray-700">
                    <h3 class="text-lg font-semibold">Activity Log</h3>
                </div>
                
                <?php if (empty($activity_logs)): ?>
                    <div class="p-6 text-center text-gray-500">
                        <i class="fas fa-history text-4xl mb-3"></i>
                        <p>No activity logs found for this owner.</p>
                    </div>
                <?php else: ?>
                    <div class="p-6">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-700">
                                <thead>
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Action</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Details</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">IP Address</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Date & Time</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-700">
                                    <?php foreach ($activity_logs as $log): ?>
                                        <tr class="hover:bg-gray-700">
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <?php
                                                    $actionIcon = '';
                                                    $actionClass = '';
                                                    
                                                    switch ($log['action']) {
                                                        case 'login':
                                                            $actionIcon = 'fa-sign-in-alt';
                                                            $actionClass = 'text-green-400';
                                                            break;
                                                        case 'logout':
                                                            $actionIcon = 'fa-sign-out-alt';
                                                            $actionClass = 'text-red-400';
                                                            break;
                                                        case 'add_gym':
                                                            $actionIcon = 'fa-plus-circle';
                                                            $actionClass = 'text-blue-400';
                                                            break;
                                                        case 'update_gym':
                                                            $actionIcon = 'fa-edit';
                                                            $actionClass = 'text-yellow-400';
                                                            break;
                                                        case 'delete_gym':
                                                            $actionIcon = 'fa-trash-alt';
                                                            $actionClass = 'text-red-400';
                                                            break;
                                                        default:
                                                            $actionIcon = 'fa-history';
                                                            $actionClass = 'text-gray-400';
                                                    }
                                                ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $actionClass; ?>">
                                                    <i class="fas <?php echo $actionIcon; ?> mr-1"></i>
                                                    <?php echo str_replace('_', ' ', ucfirst($log['action'])); ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4">
                                                <div class="text-sm"><?php echo htmlspecialchars($log['details']); ?></div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                            <?php echo htmlspecialchars($log['ip_address']); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                <?php echo formatTime($log['created_at']); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
        // Tab functionality
        const tabButtons = document.querySelectorAll('.tab-btn');
        const tabContents = document.querySelectorAll('.tab-content');
        
        tabButtons.forEach(button => {
            button.addEventListener('click', () => {
                // Remove active class from all buttons and contents
                tabButtons.forEach(btn => {
                    btn.classList.remove('bg-blue-600');
                    btn.classList.add('hover:bg-gray-600');
                });
                tabContents.forEach(content => {
                    content.classList.remove('active');
                });
                
                // Add active class to clicked button and corresponding content
                button.classList.add('bg-blue-600');
                button.classList.remove('hover:bg-gray-600');
                
                const tabId = button.getAttribute('data-tab');
                document.getElementById(`${tabId}-content`).classList.add('active');
            });
        });
    </script>
</body>
</html>