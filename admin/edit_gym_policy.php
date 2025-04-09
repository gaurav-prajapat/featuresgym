<?php
ob_start();
include '../includes/navbar.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

require_once '../config/database.php';
$db = new GymDatabase();
$conn = $db->getConnection();

// Initialize variables
$success_message = '';
$error_message = '';
$policy = null;
$gym = null;

// Get policy ID from URL
$policy_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Fetch policy data
if ($policy_id > 0) {
    $stmt = $conn->prepare("
        SELECT gp.*, g.name as gym_name 
        FROM gym_policies gp
        JOIN gyms g ON gp.gym_id = g.gym_id
        WHERE gp.id = ?
    ");
    $stmt->execute([$policy_id]);
    $policy = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$policy) {
        $_SESSION['error'] = "Policy not found.";
        header('Location: insert_gym_policies.php');
        exit();
    }
    
    // Fetch gym details
    $stmt = $conn->prepare("SELECT * FROM gyms WHERE gym_id = ?");
    $stmt->execute([$policy['gym_id']]);
    $gym = $stmt->fetch(PDO::FETCH_ASSOC);
} else {
    $_SESSION['error'] = "Invalid policy ID.";
    header('Location: insert_gym_policies.php');
    exit();
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_policy'])) {
    try {
        // Get policy values from form
        $cancellation_hours = isset($_POST['cancellation_hours']) ? (int)$_POST['cancellation_hours'] : 4;
        $reschedule_hours = isset($_POST['reschedule_hours']) ? (int)$_POST['reschedule_hours'] : 2;
        $cancellation_fee = isset($_POST['cancellation_fee']) ? (float)$_POST['cancellation_fee'] : 200.00;
        $reschedule_fee = isset($_POST['reschedule_fee']) ? (float)$_POST['reschedule_fee'] : 100.00;
        $late_fee = isset($_POST['late_fee']) ? (float)$_POST['late_fee'] : 300.00;
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        // Update policy
        $stmt = $conn->prepare("
            UPDATE gym_policies 
            SET cancellation_hours = ?,
                reschedule_hours = ?,
                cancellation_fee = ?,
                reschedule_fee = ?,
                late_fee = ?,
                is_active = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $cancellation_hours,
            $reschedule_hours,
            $cancellation_fee,
            $reschedule_fee,
            $late_fee,
            $is_active,
            $policy_id
        ]);
        
        // Log the activity
        $stmt = $conn->prepare("
            INSERT INTO activity_logs (
                user_id, user_type, action, details, ip_address, user_agent
            ) VALUES (:user_id, 'admin', 'update_gym_policy', :details, :ip, :user_agent)
        ");
        $stmt->execute([
            ':user_id' => $_SESSION['admin_id'],
            ':details' => "Updated policy for gym: {$policy['gym_name']} (ID: {$policy['gym_id']})",
            ':ip' => $_SERVER['REMOTE_ADDR'],
            ':user_agent' => $_SERVER['HTTP_USER_AGENT']
        ]);
        
        $success_message = "Policy updated successfully.";
        
        // Refresh policy data
        $stmt = $conn->prepare("
            SELECT gp.*, g.name as gym_name 
            FROM gym_policies gp
                        JOIN gyms g ON gp.gym_id = g.gym_id
            WHERE gp.id = ?
        ");
        $stmt->execute([$policy_id]);
        $policy = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error_message = "Error updating policy: " . $e->getMessage();
    }
}

// Set page title
$page_title = "Edit Gym Policy";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Gym Policy - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body class="bg-gray-100 min-h-screen">
    <div class="container mx-auto px-4 py-8">
        <!-- Header Section -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8">
            <div>
                <h1 class="text-3xl font-bold text-gray-800">Edit Gym Policy</h1>
                <p class="text-gray-600">Update cancellation, rescheduling, and late fee policies for <?= htmlspecialchars($policy['gym_name']) ?></p>
            </div>
            <div class="mt-4 md:mt-0">
                <a href="insert_gym_policies.php" class="bg-gray-600 hover:bg-gray-700 text-white font-medium py-2 px-4 rounded-lg transition duration-300 flex items-center">
                    <i class="fas fa-arrow-left mr-2"></i> Back to Policies
                </a>
            </div>
        </div>

        <!-- Alerts Section -->
        <?php if ($success_message): ?>
        <div class="bg-green-50 border-l-4 border-green-400 p-4 mb-6 rounded-lg">
            <div class="flex items-start">
                <div class="flex-shrink-0">
                    <i class="fas fa-check-circle text-green-400 text-xl"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-green-800"><?= htmlspecialchars($success_message) ?></p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
        <div class="bg-red-50 border-l-4 border-red-400 p-4 mb-6 rounded-lg">
            <div class="flex items-start">
                <div class="flex-shrink-0">
                    <i class="fas fa-exclamation-circle text-red-400 text-xl"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-red-800"><?= htmlspecialchars($error_message) ?></p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Main Content -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Edit Policy Form -->
            <div class="lg:col-span-2 bg-white rounded-xl shadow-md overflow-hidden">
                <div class="px-6 py-5 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-800">Policy Settings</h3>
                </div>
                <form method="POST" action="" class="p-6">
                    <input type="hidden" name="update_policy" value="1">
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <div>
                            <label for="cancellation_hours" class="block text-sm font-medium text-gray-700 mb-1">Cancellation Hours</label>
                            <input type="number" id="cancellation_hours" name="cancellation_hours" value="<?= $policy['cancellation_hours'] ?>" min="1" max="72" 
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                            <p class="mt-1 text-xs text-gray-500">Hours before scheduled time when cancellation is allowed</p>
                        </div>
                        
                        <div>
                            <label for="reschedule_hours" class="block text-sm font-medium text-gray-700 mb-1">Reschedule Hours</label>
                            <input type="number" id="reschedule_hours" name="reschedule_hours" value="<?= $policy['reschedule_hours'] ?>" min="1" max="48" 
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                            <p class="mt-1 text-xs text-gray-500">Hours before scheduled time when rescheduling is allowed</p>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                        <div>
                            <label for="cancellation_fee" class="block text-sm font-medium text-gray-700 mb-1">Cancellation Fee (₹)</label>
                            <input type="number" id="cancellation_fee" name="cancellation_fee" value="<?= $policy['cancellation_fee'] ?>" min="0" step="0.01" 
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                        </div>
                        
                        <div>
                            <label for="reschedule_fee" class="block text-sm font-medium text-gray-700 mb-1">Reschedule Fee (₹)</label>
                            <input type="number" id="reschedule_fee" name="reschedule_fee" value="<?= $policy['reschedule_fee'] ?>" min="0" step="0.01" 
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                        </div>
                        
                        <div>
                            <label for="late_fee" class="block text-sm font-medium text-gray-700 mb-1">Late Fee (₹)</label>
                            <input type="number" id="late_fee" name="late_fee" value="<?= $policy['late_fee'] ?>" min="0" step="0.01" 
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                        </div>
                    </div>
                    
                    <div class="mb-6">
                        <label class="inline-flex items-center">
                            <input type="checkbox" name="is_active" <?= $policy['is_active'] ? 'checked' : '' ?> 
                                class="rounded border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                            <span class="ml-2 text-sm text-gray-700">Policy is active</span>
                        </label>
                        <p class="mt-1 text-xs text-gray-500">When inactive, default system policies will be used</p>
                    </div>
                    
                    <div class="flex justify-end">
                        <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-6 rounded-lg transition duration-300">
                            <i class="fas fa-save mr-2"></i> Update Policy
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Gym Info Card -->
            <div class="bg-white rounded-xl shadow-md overflow-hidden">
                <div class="px-6 py-5 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-800">Gym Information</h3>
                </div>
                <div class="p-6">
                    <?php if ($gym): ?>
                        <div class="mb-6">
                            <h4 class="text-lg font-medium text-gray-800 mb-2"><?= htmlspecialchars($gym['name']) ?></h4>
                            <p class="text-sm text-gray-600 mb-4"><?= htmlspecialchars($gym['description'] ?? 'No description available.') ?></p>
                            
                            <div class="flex items-center text-sm text-gray-600 mb-2">
                                <i class="fas fa-map-marker-alt w-5 text-gray-400"></i>
                                <span><?= htmlspecialchars($gym['address']) ?>, <?= htmlspecialchars($gym['city']) ?>, <?= htmlspecialchars($gym['state']) ?></span>
                            </div>
                            
                            <div class="flex items-center text-sm text-gray-600 mb-2">
                                <i class="fas fa-phone w-5 text-gray-400"></i>
                                <span><?= htmlspecialchars($gym['phone'] ?? 'N/A') ?></span>
                            </div>
                            
                            <div class="flex items-center text-sm text-gray-600 mb-2">
                                <i class="fas fa-envelope w-5 text-gray-400"></i>
                                <span><?= htmlspecialchars($gym['email'] ?? 'N/A') ?></span>
                            </div>
                            
                            <div class="flex items-center text-sm text-gray-600 mb-2">
                                <i class="fas fa-users w-5 text-gray-400"></i>
                                <span>Capacity: <?= $gym['capacity'] ?> (Current: <?= $gym['current_occupancy'] ?>)</span>
                            </div>
                            
                            <div class="flex items-center text-sm text-gray-600">
                                <i class="fas fa-star w-5 text-gray-400"></i>
                                <span>Rating: <?= $gym['rating'] ? number_format($gym['rating'], 1) . '/5.0' : 'No ratings yet' ?></span>
                            </div>
                        </div>
                        
                        <div class="pt-4 border-t border-gray-200">
                            <div class="flex items-center mb-3">
                                <div class="w-3 h-3 rounded-full <?= $gym['status'] === 'active' ? 'bg-green-500' : 'bg-red-500' ?> mr-2"></div>
                                <span class="text-sm font-medium"><?= ucfirst($gym['status']) ?></span>
                            </div>
                            
                            <div class="flex items-center">
                                <div class="w-3 h-3 rounded-full <?= $gym['is_open'] ? 'bg-green-500' : 'bg-red-500' ?> mr-2"></div>
                                <span class="text-sm font-medium"><?= $gym['is_open'] ? 'Open Now' : 'Closed' ?></span>
                            </div>
                        </div>
                        
                        <div class="mt-6">
                            <a href="view_gym.php?id=<?= $gym['gym_id'] ?>" class="inline-flex items-center text-indigo-600 hover:text-indigo-900">
                                <i class="fas fa-external-link-alt mr-2"></i> View Full Gym Profile
                            </a>
                        </div>
                    <?php else: ?>
                        <p class="text-gray-500 italic">Gym information not available.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Policy History Section -->
        <div class="mt-8 bg-white rounded-xl shadow-md overflow-hidden">
            <div class="px-6 py-5 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-800">Policy Update History</h3>
            </div>
            <div class="p-6">
                <?php
                // Fetch policy update history from activity logs
                $stmt = $conn->prepare("
                    SELECT * FROM activity_logs 
                    WHERE action = 'update_gym_policy' 
                    AND details LIKE ? 
                    ORDER BY created_at DESC 
                    LIMIT 10
                ");
                $stmt->execute(["%gym: {$policy['gym_name']} (ID: {$policy['gym_id']})%"]);
                $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (!empty($history)):
                ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead>
                                <tr class="bg-gray-50">
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date & Time</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Admin</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Details</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">IP Address</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($history as $log): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?= date('M d, Y H:i:s', strtotime($log['created_at'])) ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            Admin ID: <?= $log['user_id'] ?>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-900">
                                            <?= htmlspecialchars($log['details']) ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?= htmlspecialchars($log['ip_address']) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-gray-500 italic">No policy update history available.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="bg-white py-6 mt-8">
        <div class="container mx-auto px-4">
            <div class="flex flex-col md:flex-row justify-between items-center">
                <div class="mb-4 md:mb-0">
                    <p class="text-sm text-gray-600">&copy; <?= date('Y') ?> Fitness Hub. All rights reserved.</p>
                </div>
                <div class="flex space-x-4">
                    <a href="index.php" class="text-sm text-gray-600 hover:text-indigo-600">Dashboard</a>
                    <a href="privacy_policy.php" class="text-sm text-gray-600 hover:text-indigo-600">Privacy Policy</a>
                    <a href="terms_of_service.php" class="text-sm text-gray-600 hover:text-indigo-600">Terms of Service</a>
                </div>
            </div>
        </div>
    </footer>
</body>
</html>


