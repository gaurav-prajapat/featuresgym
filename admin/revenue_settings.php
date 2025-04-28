<?php
ob_start();
include '../includes/navbar.php';
if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ./login.php');
    exit();
}

require_once '../config/database.php';
$db = new GymDatabase();
$conn = $db->getConnection();

// Initialize variables
$success_message = '';
$error_message = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->beginTransaction();
        
        // Handle tier-based cuts update
        if (isset($_POST['update_tier_cuts'])) {
            // Process tier-based cuts
            foreach ($_POST['tier'] as $id => $tier) {
                $admin_cut = $_POST['admin_cut'][$id];
                $gym_cut = $_POST['gym_cut'][$id];
                
                // Validate percentages
                if (!is_numeric($admin_cut) || !is_numeric($gym_cut) || $admin_cut < 0 || $gym_cut < 0) {
                    throw new Exception("Invalid percentage values. Please enter valid numbers.");
                }
                
                // Validate total is 100%
                if (($admin_cut + $gym_cut) != 100) {
                    throw new Exception("The sum of admin cut and gym cut must equal 100%.");
                }
                
                // Update the record
                $stmt = $conn->prepare("
                    UPDATE cut_off_chart 
                    SET admin_cut_percentage = :admin_cut, 
                        gym_owner_cut_percentage = :gym_cut 
                    WHERE id = :id
                ");
                
                $stmt->execute([
                    ':admin_cut' => $admin_cut,
                    ':gym_cut' => $gym_cut,
                    ':id' => $id
                ]);
            }
            
            // Log the activity
            $stmt = $conn->prepare("
                INSERT INTO activity_logs (
                    user_id, user_type, action, details, ip_address, user_agent
                ) VALUES (?, 'admin', 'update_revenue_settings', ?, ?, ?)
            ");
            
            $details = "Updated tier-based revenue distribution settings";
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            
            $stmt->execute([$_SESSION['admin_id'], $details, $ip, $user_agent]);
            
            $success_message = "Tier-based revenue settings updated successfully!";
        }
        
        // Handle fee-based cuts update
        if (isset($_POST['update_fee_cuts'])) {
            // Process fee-based cuts
            foreach ($_POST['fee_id'] as $id => $fee_id) {
                $price_start = $_POST['price_start'][$id];
                $price_end = $_POST['price_end'][$id];
                $admin_cut = $_POST['fee_admin_cut'][$id];
                $gym_cut = $_POST['fee_gym_cut'][$id];
                
                // Validate percentages
                if (!is_numeric($admin_cut) || !is_numeric($gym_cut) || $admin_cut < 0 || $gym_cut < 0) {
                    throw new Exception("Invalid percentage values. Please enter valid numbers.");
                }
                
                // Validate total is 100%
                if (($admin_cut + $gym_cut) != 100) {
                    throw new Exception("The sum of admin cut and gym cut must equal 100%.");
                }
                
                // Validate price ranges
                if (!is_numeric($price_start) || !is_numeric($price_end) || $price_start < 0 || $price_end <= $price_start) {
                    throw new Exception("Invalid price range. End price must be greater than start price.");
                }
                
                // Update the record
                $stmt = $conn->prepare("
                    UPDATE fee_based_cuts 
                    SET price_range_start = :price_start,
                        price_range_end = :price_end,
                        admin_cut_percentage = :admin_cut, 
                        gym_cut_percentage = :gym_cut 
                    WHERE id = :id
                ");
                
                $stmt->execute([
                    ':price_start' => $price_start,
                    ':price_end' => $price_end,
                    ':admin_cut' => $admin_cut,
                    ':gym_cut' => $gym_cut,
                    ':id' => $fee_id
                ]);
            }
            
            // Log the activity
            $stmt = $conn->prepare("
                INSERT INTO activity_logs (
                    user_id, user_type, action, details, ip_address, user_agent
                ) VALUES (?, 'admin', 'update_revenue_settings', ?, ?, ?)
            ");
            
            $details = "Updated fee-based revenue distribution settings";
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            
            $stmt->execute([$_SESSION['admin_id'], $details, $ip, $user_agent]);
            
            $success_message = "Fee-based revenue settings updated successfully!";
        }
        
        // Handle adding new tier-based cut
        if (isset($_POST['add_tier_cut'])) {
            $tier = $_POST['new_tier'];
            $duration = $_POST['new_duration'];
            $admin_cut = $_POST['new_admin_cut'];
            $gym_cut = $_POST['new_gym_cut'];
            
            // Validate percentages
            if (!is_numeric($admin_cut) || !is_numeric($gym_cut) || $admin_cut < 0 || $gym_cut < 0) {
                throw new Exception("Invalid percentage values. Please enter valid numbers.");
            }
            
            // Validate total is 100%
            if (($admin_cut + $gym_cut) != 100) {
                throw new Exception("The sum of admin cut and gym cut must equal 100%.");
            }
            
            // Check if this tier and duration combination already exists
            $stmt = $conn->prepare("
                SELECT COUNT(*) as count FROM cut_off_chart 
                WHERE tier = :tier AND duration = :duration
            ");
            $stmt->execute([':tier' => $tier, ':duration' => $duration]);
            $exists = $stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0;
            
            if ($exists) {
                throw new Exception("A revenue setting for this tier and duration already exists.");
            }
            
            // Insert new record
            $stmt = $conn->prepare("
                INSERT INTO cut_off_chart (
                    tier, duration, admin_cut_percentage, gym_owner_cut_percentage, cut_type
                ) VALUES (
                    :tier, :duration, :admin_cut, :gym_cut, 'tier_based'
                )
            ");
            
            $stmt->execute([
                ':tier' => $tier,
                ':duration' => $duration,
                ':admin_cut' => $admin_cut,
                ':gym_cut' => $gym_cut
            ]);
            
            // Log the activity
            $stmt = $conn->prepare("
                INSERT INTO activity_logs (
                    user_id, user_type, action, details, ip_address, user_agent
                ) VALUES (?, 'admin', 'add_revenue_setting', ?, ?, ?)
            ");
            
            $details = "Added new tier-based revenue setting: $tier - $duration";
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            
            $stmt->execute([$_SESSION['admin_id'], $details, $ip, $user_agent]);
            
            $success_message = "New tier-based revenue setting added successfully!";
        }
        
        // Handle adding new fee-based cut
        if (isset($_POST['add_fee_cut'])) {
            $price_start = $_POST['new_price_start'];
            $price_end = $_POST['new_price_end'];
            $admin_cut = $_POST['new_fee_admin_cut'];
            $gym_cut = $_POST['new_fee_gym_cut'];
            
            // Validate percentages
            if (!is_numeric($admin_cut) || !is_numeric($gym_cut) || $admin_cut < 0 || $gym_cut < 0) {
                throw new Exception("Invalid percentage values. Please enter valid numbers.");
            }
            
            // Validate total is 100%
            if (($admin_cut + $gym_cut) != 100) {
                throw new Exception("The sum of admin cut and gym cut must equal 100%.");
            }
            
            // Validate price ranges
            if (!is_numeric($price_start) || !is_numeric($price_end) || $price_start < 0 || $price_end <= $price_start) {
                throw new Exception("Invalid price range. End price must be greater than start price.");
            }
            
            // Check for overlapping price ranges
            $stmt = $conn->prepare("
                SELECT COUNT(*) as count FROM fee_based_cuts 
                WHERE 
                    (price_range_start <= :price_end AND price_range_end >= :price_start)
            ");
            $stmt->execute([':price_start' => $price_start, ':price_end' => $price_end]);
            $overlaps = $stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0;
            
            if ($overlaps) {
                throw new Exception("This price range overlaps with an existing range.");
            }
            
            // Insert new record
            $stmt = $conn->prepare("
                INSERT INTO fee_based_cuts (
                    price_range_start, price_range_end, admin_cut_percentage, gym_cut_percentage, cut_type
                ) VALUES (
                    :price_start, :price_end, :admin_cut, :gym_cut, 'fee_based'
                )
            ");
            
            $stmt->execute([
                ':price_start' => $price_start,
                ':price_end' => $price_end,
                ':admin_cut' => $admin_cut,
                ':gym_cut' => $gym_cut
            ]);
            
            // Log the activity
            $stmt = $conn->prepare("
                INSERT INTO activity_logs (
                    user_id, user_type, action, details, ip_address, user_agent
                ) VALUES (?, 'admin', 'add_revenue_setting', ?, ?, ?)
            ");
            
            $details = "Added new fee-based revenue setting: ₹$price_start - ₹$price_end";
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            
            $stmt->execute([$_SESSION['admin_id'], $details, $ip, $user_agent]);
            
            $success_message = "New fee-based revenue setting added successfully!";
        }
        
        // Handle deleting tier-based cut
        if (isset($_POST['delete_tier_cut'])) {
            $id = $_POST['delete_tier_id'];
            
            // Get the details before deletion for logging
            $stmt = $conn->prepare("SELECT tier, duration FROM cut_off_chart WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $cut_details = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Delete the record
            $stmt = $conn->prepare("DELETE FROM cut_off_chart WHERE id = :id");
            $stmt->execute([':id' => $id]);
            
            // Log the activity
            $stmt = $conn->prepare("
                INSERT INTO activity_logs (
                    user_id, user_type, action, details, ip_address, user_agent
                ) VALUES (?, 'admin', 'delete_revenue_setting', ?, ?, ?)
            ");
            
            $details = "Deleted tier-based revenue setting: {$cut_details['tier']} - {$cut_details['duration']}";
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            
            $stmt->execute([$_SESSION['admin_id'], $details, $ip, $user_agent]);
            
            $success_message = "Tier-based revenue setting deleted successfully!";
        }
        
        // Handle deleting fee-based cut
        if (isset($_POST['delete_fee_cut'])) {
            $id = $_POST['delete_fee_id'];
            
            // Get the details before deletion for logging
            $stmt = $conn->prepare("SELECT price_range_start, price_range_end FROM fee_based_cuts WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $cut_details = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Delete the record
            $stmt = $conn->prepare("DELETE FROM fee_based_cuts WHERE id = :id");
            $stmt->execute([':id' => $id]);
            
            // Log the activity
            $stmt = $conn->prepare("
                INSERT INTO activity_logs (
                    user_id, user_type, action, details, ip_address, user_agent
                ) VALUES (?, 'admin', 'delete_revenue_setting', ?, ?, ?)
            ");
            
            $details = "Deleted fee-based revenue setting: ₹{$cut_details['price_range_start']} - ₹{$cut_details['price_range_end']}";
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            
            $stmt->execute([$_SESSION['admin_id'], $details, $ip, $user_agent]);
            
            $success_message = "Fee-based revenue setting deleted successfully!";
        }
        
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollBack();
        $error_message = $e->getMessage();
    }
}

// Fetch tier-based revenue settings
$stmt = $conn->query("SELECT * FROM cut_off_chart WHERE cut_type = 'tier_based' ORDER BY tier, duration");
$tier_cuts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch fee-based revenue settings
$stmt = $conn->query("SELECT * FROM fee_based_cuts WHERE cut_type = 'fee_based' ORDER BY price_range_start");
$fee_cuts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get revenue distribution statistics
$stmt = $conn->query("
    SELECT 
        SUM(amount) as total_revenue,
        SUM(admin_cut) as admin_revenue,
        SUM(amount - admin_cut) as gym_revenue
    FROM gym_revenue
");
$revenue_stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Calculate percentages
$total_revenue = $revenue_stats['total_revenue'] ?? 0;
$admin_percentage = $total_revenue > 0 ? ($revenue_stats['admin_revenue'] / $total_revenue) * 100 : 0;
$gym_percentage = $total_revenue > 0 ? ($revenue_stats['gym_revenue'] / $total_revenue) * 100 : 0;

// Get revenue by cut type
$stmt = $conn->query("
    SELECT 
        cut_type,
                SUM(amount) as total,
        SUM(admin_cut) as admin_cut
    FROM gym_revenue
    GROUP BY cut_type
");
$revenue_by_cut_type = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Revenue Settings - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .revenue-card {
            transition: all 0.3s ease;
        }
        .revenue-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }
        .form-input:focus {
            border-color: #4f46e5;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.2);
        }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">
    <div class="container mx-auto px-4 py-8">
        <!-- Header Section -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8">
            <div>
                <h1 class="text-3xl font-bold ">Revenue Settings</h1>
                <p class="text-gray-600">Manage how revenue is distributed between the platform and gym owners</p>
            </div>
            <div class="mt-4 md:mt-0">
                <a href="index.php" class="bg-gray-600 hover:bg-gray-700 text-white font-medium py-2 px-4 rounded-lg transition duration-300 flex items-center">
                    <i class="fas fa-arrow-left mr-2"></i> Back to Dashboard
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

        <!-- Revenue Overview -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <!-- Total Revenue Card -->
            <div class="revenue-card bg-white rounded-xl shadow-md overflow-hidden">
                <div class="px-6 py-5 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-800">Total Revenue</h3>
                </div>
                <div class="p-6">
                    <div class="flex items-center justify-between mb-4">
                        <span class="text-gray-600">Total Revenue</span>
                        <span class="text-2xl font-bold text-gray-800">₹<?= number_format($total_revenue, 2) ?></span>
                    </div>
                    <div class="space-y-4">
                        <div>
                            <div class="flex justify-between items-center mb-1">
                                <span class="text-sm text-gray-600">Admin Revenue</span>
                                <span class="text-sm font-medium text-gray-800">
                                    ₹<?= number_format($revenue_stats['admin_revenue'] ?? 0, 2) ?>
                                    <span class="text-xs text-gray-500">(<?= number_format($admin_percentage, 1) ?>%)</span>
                                </span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-2">
                                <div class="bg-indigo-600 h-2 rounded-full" style="width: <?= $admin_percentage ?>%"></div>
                            </div>
                        </div>
                        <div>
                            <div class="flex justify-between items-center mb-1">
                                <span class="text-sm text-gray-600">Gym Revenue</span>
                                <span class="text-sm font-medium text-gray-800">
                                    ₹<?= number_format($revenue_stats['gym_revenue'] ?? 0, 2) ?>
                                    <span class="text-xs text-gray-500">(<?= number_format($gym_percentage, 1) ?>%)</span>
                                </span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-2">
                                <div class="bg-green-600 h-2 rounded-full" style="width: <?= $gym_percentage ?>%"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Revenue by Cut Type -->
            <div class="revenue-card bg-white rounded-xl shadow-md overflow-hidden">
                <div class="px-6 py-5 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-800">Revenue by Cut Type</h3>
                </div>
                <div class="p-6">
                    <?php if (empty($revenue_by_cut_type)): ?>
                        <p class="text-gray-500 text-center">No revenue data available</p>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($revenue_by_cut_type as $cut_type): ?>
                                <div>
                                    <div class="flex justify-between items-center mb-1">
                                        <span class="text-sm text-gray-600">
                                            <?= $cut_type['cut_type'] === 'tier_based' ? 'Tier Based' : 'Fee Based' ?>
                                        </span>
                                        <span class="text-sm font-medium text-gray-800">
                                            ₹<?= number_format($cut_type['total'] ?? 0, 2) ?>
                                        </span>
                                    </div>
                                    <div class="w-full bg-gray-200 rounded-full h-2">
                                        <?php 
                                        $percentage = $total_revenue > 0 ? ($cut_type['total'] / $total_revenue) * 100 : 0;
                                        $color = $cut_type['cut_type'] === 'tier_based' ? 'bg-purple-600' : 'bg-blue-600';
                                        ?>
                                        <div class="<?= $color ?> h-2 rounded-full" style="width: <?= $percentage ?>%"></div>
                                    </div>
                                    <div class="flex justify-between items-center mt-1">
                                        <span class="text-xs text-gray-500">Admin: ₹<?= number_format($cut_type['admin_cut'], 2) ?></span>
                                        <span class="text-xs text-gray-500"><?= number_format($percentage, 1) ?>%</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick Info -->
            <div class="revenue-card bg-white rounded-xl shadow-md overflow-hidden">
                <div class="px-6 py-5 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-800">Revenue Settings Info</h3>
                </div>
                <div class="p-6">
                    <div class="space-y-4">
                        <div>
                            <h4 class="text-sm font-medium text-gray-700 mb-2">Tier-Based Revenue</h4>
                            <p class="text-xs text-gray-600">
                                Tier-based revenue distribution allocates percentages based on gym membership tiers (Tier 1, 2, 3) and duration.
                            </p>
                        </div>
                        <div>
                            <h4 class="text-sm font-medium text-gray-700 mb-2">Fee-Based Revenue</h4>
                            <p class="text-xs text-gray-600">
                                Fee-based revenue distribution allocates percentages based on the price range of the membership or service.
                            </p>
                        </div>
                        <div class="pt-2">
                            <p class="text-xs text-gray-500 italic">
                                Note: The sum of admin cut and gym cut percentages must always equal 100%.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tier-Based Revenue Settings -->
        <div class="bg-white rounded-xl shadow-md overflow-hidden mb-8">
            <div class="px-6 py-5 border-b border-gray-200 flex justify-between items-center">
                <h3 class="text-lg font-semibold text-gray-800">Tier-Based Revenue Settings</h3>
                <button type="button" id="addTierCutBtn" class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium py-2 px-4 rounded-lg transition duration-300">
                    <i class="fas fa-plus mr-2"></i> Add New
                </button>
            </div>
            <div class="p-6">
                <?php if (empty($tier_cuts)): ?>
                    <p class="text-gray-500 text-center">No tier-based revenue settings found</p>
                <?php else: ?>
                    <form method="POST" action="">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead>
                                    <tr>
                                        <th class="px-6 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tier</th>
                                        <th class="px-6 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Duration</th>
                                        <th class="px-6 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Admin Cut (%)</th>
                                        <th class="px-6 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Gym Cut (%)</th>
                                        <th class="px-6 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($tier_cuts as $cut): ?>
                                        <tr>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-800">
                                                <?= htmlspecialchars($cut['tier']) ?>
                                                <input type="hidden" name="tier[<?= $cut['id'] ?>]" value="<?= htmlspecialchars($cut['tier']) ?>">
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-800">
                                                <?= htmlspecialchars($cut['duration']) ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <input type="number" name="admin_cut[<?= $cut['id'] ?>]" value="<?= htmlspecialchars($cut['admin_cut_percentage']) ?>" 
                                                    min="0" max="100" step="0.01" required
                                                    class="form-input w-20 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <input type="number" name="gym_cut[<?= $cut['id'] ?>]" value="<?= htmlspecialchars($cut['gym_owner_cut_percentage']) ?>" 
                                                    min="0" max="100" step="0.01" required
                                                    class="form-input w-20 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <button type="button" onclick="confirmDeleteTierCut(<?= $cut['id'] ?>)" 
                                                    class="text-red-600 hover:text-red-900 mr-3">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-6 flex justify-end">
                            <button type="submit" name="update_tier_cuts" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-lg transition duration-300">
                                <i class="fas fa-save mr-2"></i> Save Changes
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>

                <!-- Fee-Based Revenue Settings -->
                <div class="bg-white rounded-xl shadow-md overflow-hidden mb-8">
            <div class="px-6 py-5 border-b border-gray-200 flex justify-between items-center">
                <h3 class="text-lg font-semibold text-gray-800">Fee-Based Revenue Settings</h3>
                <button type="button" id="addFeeCutBtn" class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium py-2 px-4 rounded-lg transition duration-300">
                    <i class="fas fa-plus mr-2"></i> Add New
                </button>
            </div>
            <div class="p-6">
                <?php if (empty($fee_cuts)): ?>
                    <p class="text-gray-500 text-center">No fee-based revenue settings found</p>
                <?php else: ?>
                    <form method="POST" action="">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead>
                                    <tr>
                                        <th class="px-6 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Price Range (₹)</th>
                                        <th class="px-6 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Admin Cut (%)</th>
                                        <th class="px-6 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Gym Cut (%)</th>
                                        <th class="px-6 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($fee_cuts as $cut): ?>
                                        <tr>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <input type="hidden" name="fee_id[<?= $cut['id'] ?>]" value="<?= $cut['id'] ?>">
                                                <div class="flex items-center space-x-2">
                                                    <input type="number" name="price_start[<?= $cut['id'] ?>]" value="<?= htmlspecialchars($cut['price_range_start']) ?>" 
                                                        min="0" step="0.01" required
                                                        class="form-input w-24 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                                                    <span class="text-gray-500">to</span>
                                                    <input type="number" name="price_end[<?= $cut['id'] ?>]" value="<?= htmlspecialchars($cut['price_range_end']) ?>" 
                                                        min="0" step="0.01" required
                                                        class="form-input w-24 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <input type="number" name="fee_admin_cut[<?= $cut['id'] ?>]" value="<?= htmlspecialchars($cut['admin_cut_percentage']) ?>" 
                                                    min="0" max="100" step="0.01" required
                                                    class="form-input w-20 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <input type="number" name="fee_gym_cut[<?= $cut['id'] ?>]" value="<?= htmlspecialchars($cut['gym_cut_percentage']) ?>" 
                                                    min="0" max="100" step="0.01" required
                                                    class="form-input w-20 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <button type="button" onclick="confirmDeleteFeeCut(<?= $cut['id'] ?>)" 
                                                    class="text-red-600 hover:text-red-900 mr-3">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-6 flex justify-end">
                            <button type="submit" name="update_fee_cuts" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-lg transition duration-300">
                                <i class="fas fa-save mr-2"></i> Save Changes
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Add New Tier-Based Cut Modal -->
        <div id="addTierCutModal" class="fixed inset-0 bg-gray-900 bg-opacity-50 flex items-center justify-center z-50 hidden">
            <div class="bg-white rounded-xl shadow-xl max-w-md w-full mx-4">
                <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                    <h3 class="text-lg font-semibold text-gray-800">Add New Tier-Based Revenue Setting</h3>
                    <button type="button" class="text-gray-400 hover:text-gray-500" onclick="toggleModal('addTierCutModal')">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <form method="POST" action="">
                    <div class="p-6 space-y-4">
                        <div>
                            <label for="new_tier" class="block text-sm font-medium text-gray-700 mb-1">Tier</label>
                            <select id="new_tier" name="new_tier" required class="form-select block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                                <option value="Tier 1">Tier 1</option>
                                <option value="Tier 2">Tier 2</option>
                                <option value="Tier 3">Tier 3</option>
                            </select>
                        </div>
                        <div>
                            <label for="new_duration" class="block text-sm font-medium text-gray-700 mb-1">Duration</label>
                            <select id="new_duration" name="new_duration" required class="form-select block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                                <option value="Daily">Daily</option>
                                <option value="Weekly">Weekly</option>
                                <option value="Monthly">Monthly</option>
                                <option value="Quarterly">Quarterly</option>
                                <option value="Half-Yearly">Half-Yearly</option>
                                <option value="Yearly">Yearly</option>
                            </select>
                        </div>
                        <div>
                            <label for="new_admin_cut" class="block text-sm font-medium text-gray-700 mb-1">Admin Cut (%)</label>
                            <input type="number" id="new_admin_cut" name="new_admin_cut" required min="0" max="100" step="0.01" value="30"
                                class="form-input block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50"
                                onchange="updateGymCut('new_admin_cut', 'new_gym_cut')">
                        </div>
                        <div>
                            <label for="new_gym_cut" class="block text-sm font-medium text-gray-700 mb-1">Gym Cut (%)</label>
                            <input type="number" id="new_gym_cut" name="new_gym_cut" required min="0" max="100" step="0.01" value="70"
                                class="form-input block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50"
                                onchange="updateAdminCut('new_admin_cut', 'new_gym_cut')">
                        </div>
                        <div class="text-xs text-gray-500 italic">
                            Note: The sum of admin cut and gym cut percentages must equal 100%.
                        </div>
                    </div>
                    <div class="px-6 py-4 border-t border-gray-200 flex justify-end">
                        <button type="button" class="bg-gray-500 hover:bg-gray-600 text-white font-medium py-2 px-4 rounded-lg mr-3 transition duration-300" onclick="toggleModal('addTierCutModal')">
                            Cancel
                        </button>
                        <button type="submit" name="add_tier_cut" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-lg transition duration-300">
                            Add Setting
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Add New Fee-Based Cut Modal -->
        <div id="addFeeCutModal" class="fixed inset-0 bg-gray-900 bg-opacity-50 flex items-center justify-center z-50 hidden">
            <div class="bg-white rounded-xl shadow-xl max-w-md w-full mx-4">
                <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                    <h3 class="text-lg font-semibold text-gray-800">Add New Fee-Based Revenue Setting</h3>
                    <button type="button" class="text-gray-400 hover:text-gray-500" onclick="toggleModal('addFeeCutModal')">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <form method="POST" action="">
                    <div class="p-6 space-y-4">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label for="new_price_start" class="block text-sm font-medium text-gray-700 mb-1">Price Range Start (₹)</label>
                                <input type="number" id="new_price_start" name="new_price_start" required min="0" step="0.01"
                                    class="form-input block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                            </div>
                            <div>
                                <label for="new_price_end" class="block text-sm font-medium text-gray-700 mb-1">Price Range End (₹)</label>
                                <input type="number" id="new_price_end" name="new_price_end" required min="0" step="0.01"
                                    class="form-input block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                            </div>
                        </div>
                        <div>
                            <label for="new_fee_admin_cut" class="block text-sm font-medium text-gray-700 mb-1">Admin Cut (%)</label>
                            <input type="number" id="new_fee_admin_cut" name="new_fee_admin_cut" required min="0" max="100" step="0.01" value="30"
                                class="form-input block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50"
                                onchange="updateGymCut('new_fee_admin_cut', 'new_fee_gym_cut')">
                        </div>
                        <div>
                            <label for="new_fee_gym_cut" class="block text-sm font-medium text-gray-700 mb-1">Gym Cut (%)</label>
                            <input type="number" id="new_fee_gym_cut" name="new_fee_gym_cut" required min="0" max="100" step="0.01" value="70"
                                class="form-input block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50"
                                onchange="updateAdminCut('new_fee_admin_cut', 'new_fee_gym_cut')">
                        </div>
                        <div class="text-xs text-gray-500 italic">
                            Note: The sum of admin cut and gym cut percentages must equal 100%.
                        </div>
                    </div>
                    <div class="px-6 py-4 border-t border-gray-200 flex justify-end">
                        <button type="button" class="bg-gray-500 hover:bg-gray-600 text-white font-medium py-2 px-4 rounded-lg mr-3 transition duration-300" onclick="toggleModal('addFeeCutModal')">
                            Cancel
                        </button>
                        <button type="submit" name="add_fee_cut" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-lg transition duration-300">
                            Add Setting
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Delete Tier Cut Form (Hidden) -->
        <form id="deleteTierCutForm" method="POST" action="" class="hidden">
            <input type="hidden" id="delete_tier_id" name="delete_tier_id">
            <input type="hidden" name="delete_tier_cut" value="1">
        </form>

        <!-- Delete Fee Cut Form (Hidden) -->
        <form id="deleteFeeCutForm" method="POST" action="" class="hidden">
        <input type="hidden" id="delete_fee_id" name="delete_fee_id">
            <input type="hidden" name="delete_fee_cut" value="1">
        </form>
    </div>

 

    <script>
        // Toggle modals
        function toggleModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal.classList.contains('hidden')) {
                modal.classList.remove('hidden');
                document.body.classList.add('overflow-hidden');
            } else {
                modal.classList.add('hidden');
                document.body.classList.remove('overflow-hidden');
            }
        }

        // Update gym cut when admin cut changes
        function updateGymCut(adminCutId, gymCutId) {
            const adminCut = parseFloat(document.getElementById(adminCutId).value) || 0;
            document.getElementById(gymCutId).value = (100 - adminCut).toFixed(2);
        }

        // Update admin cut when gym cut changes
        function updateAdminCut(adminCutId, gymCutId) {
            const gymCut = parseFloat(document.getElementById(gymCutId).value) || 0;
            document.getElementById(adminCutId).value = (100 - gymCut).toFixed(2);
        }

        // Confirm delete tier cut
        function confirmDeleteTierCut(id) {
            if (confirm('Are you sure you want to delete this tier-based revenue setting? This action cannot be undone.')) {
                document.getElementById('delete_tier_id').value = id;
                document.getElementById('deleteTierCutForm').submit();
            }
        }

        // Confirm delete fee cut
        function confirmDeleteFeeCut(id) {
            if (confirm('Are you sure you want to delete this fee-based revenue setting? This action cannot be undone.')) {
                document.getElementById('delete_fee_id').value = id;
                document.getElementById('deleteFeeCutForm').submit();
            }
        }

        // Add event listeners for modal buttons
        document.getElementById('addTierCutBtn').addEventListener('click', function() {
            toggleModal('addTierCutModal');
        });

        document.getElementById('addFeeCutBtn').addEventListener('click', function() {
            toggleModal('addFeeCutModal');
        });

        // Add event listeners for percentage inputs in the main forms
        document.querySelectorAll('input[name^="admin_cut"]').forEach(input => {
            input.addEventListener('change', function() {
                const id = this.name.match(/\[(\d+)\]/)[1];
                const adminCut = parseFloat(this.value) || 0;
                const gymCutInput = document.querySelector(`input[name="gym_cut[${id}]"]`);
                gymCutInput.value = (100 - adminCut).toFixed(2);
            });
        });

        document.querySelectorAll('input[name^="gym_cut"]').forEach(input => {
            input.addEventListener('change', function() {
                const id = this.name.match(/\[(\d+)\]/)[1];
                const gymCut = parseFloat(this.value) || 0;
                const adminCutInput = document.querySelector(`input[name="admin_cut[${id}]"]`);
                adminCutInput.value = (100 - gymCut).toFixed(2);
            });
        });

        document.querySelectorAll('input[name^="fee_admin_cut"]').forEach(input => {
            input.addEventListener('change', function() {
                const id = this.name.match(/\[(\d+)\]/)[1];
                const adminCut = parseFloat(this.value) || 0;
                const gymCutInput = document.querySelector(`input[name="fee_gym_cut[${id}]"]`);
                gymCutInput.value = (100 - adminCut).toFixed(2);
            });
        });

        document.querySelectorAll('input[name^="fee_gym_cut"]').forEach(input => {
            input.addEventListener('change', function() {
                const id = this.name.match(/\[(\d+)\]/)[1];
                const gymCut = parseFloat(this.value) || 0;
                const adminCutInput = document.querySelector(`input[name="fee_admin_cut[${id}]"]`);
                adminCutInput.value = (100 - gymCut).toFixed(2);
            });
        });

        // Validate forms before submission
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(event) {
                // Skip validation for delete forms
                if (form.id === 'deleteTierCutForm' || form.id === 'deleteFeeCutForm') {
                    return true;
                }
                
                let isValid = true;
                
                // Validate tier-based cuts
                const adminCuts = form.querySelectorAll('input[name^="admin_cut"]');
                const gymCuts = form.querySelectorAll('input[name^="gym_cut"]');
                
                for (let i = 0; i < adminCuts.length; i++) {
                    const adminCut = parseFloat(adminCuts[i].value) || 0;
                    const gymCut = parseFloat(gymCuts[i].value) || 0;
                    
                    if (Math.abs((adminCut + gymCut) - 100) > 0.01) {
                        alert('The sum of admin cut and gym cut percentages must equal 100%.');
                        isValid = false;
                        break;
                    }
                }
                
                // Validate fee-based cuts
                const feeAdminCuts = form.querySelectorAll('input[name^="fee_admin_cut"]');
                const feeGymCuts = form.querySelectorAll('input[name^="fee_gym_cut"]');
                
                for (let i = 0; i < feeAdminCuts.length; i++) {
                    const adminCut = parseFloat(feeAdminCuts[i].value) || 0;
                    const gymCut = parseFloat(feeGymCuts[i].value) || 0;
                    
                    if (Math.abs((adminCut + gymCut) - 100) > 0.01) {
                        alert('The sum of admin cut and gym cut percentages must equal 100%.');
                        isValid = false;
                        break;
                    }
                }
                
                // Validate price ranges for fee-based cuts
                const priceStarts = form.querySelectorAll('input[name^="price_start"]');
                const priceEnds = form.querySelectorAll('input[name^="price_end"]');
                
                for (let i = 0; i < priceStarts.length; i++) {
                    const start = parseFloat(priceStarts[i].value) || 0;
                    const end = parseFloat(priceEnds[i].value) || 0;
                    
                    if (end <= start) {
                        alert('Price range end must be greater than price range start.');
                        isValid = false;
                        break;
                    }
                }
                
                if (!isValid) {
                    event.preventDefault();
                }
            });
        });
    </script>
</body>
</html>



