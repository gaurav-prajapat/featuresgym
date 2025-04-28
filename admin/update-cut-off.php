<?php
session_start();
require_once '../config/database.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ./login.php');
    exit();
}

$db = new GymDatabase();
$conn = $db->getConnection();

// Initialize messages
$success_message = '';
$error_message = '';

// Fetch available tiers and durations
$tierStmt = $conn->prepare("SELECT DISTINCT tier FROM cut_off_chart ORDER BY 
    CASE 
        WHEN tier = 'Tier 1' THEN 1
        WHEN tier = 'Tier 2' THEN 2
        WHEN tier = 'Tier 3' THEN 3
        ELSE 4
    END");
$tierStmt->execute();
$tiers = $tierStmt->fetchAll(PDO::FETCH_COLUMN);

$durationStmt = $conn->prepare("SELECT DISTINCT duration FROM cut_off_chart ORDER BY 
    CASE 
        WHEN duration = 'Daily' THEN 1
        WHEN duration = 'Weekly' THEN 2
        WHEN duration = 'Monthly' THEN 3
        WHEN duration = 'Quarterly' THEN 4
        WHEN duration = 'Half-Yearly' THEN 5
        WHEN duration = 'Yearly' THEN 6
        ELSE 7
    END");
$durationStmt->execute();
$durations = $durationStmt->fetchAll(PDO::FETCH_COLUMN);

// Handle form submission for tier-based cutoff update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_tier_cutoff'])) {
    try {
        $tier = $_POST['tier'];
        $duration = $_POST['duration'];
        $admin_cut = (float)$_POST['admin_cut'];
        $gym_owner_cut = (float)$_POST['gym_owner_cut'];

        // Validate percentages
        if (abs($admin_cut + $gym_owner_cut - 100) > 0.01) { // Allow small floating point errors
            throw new Exception('Admin Cut and Gym Owner Cut must add up to 100%.');
        }

        // Update the cut-off chart
        $updateStmt = $conn->prepare("
            UPDATE cut_off_chart
            SET admin_cut_percentage = ?, gym_owner_cut_percentage = ?
            WHERE tier = ? AND duration = ?
        ");
        $updateStmt->execute([$admin_cut, $gym_owner_cut, $tier, $duration]);

        if ($updateStmt->rowCount() > 0) {
            // Log the activity
            $stmt = $conn->prepare("
                INSERT INTO activity_logs (
                    user_id, user_type, action, details, ip_address, user_agent
                ) VALUES (?, 'admin', 'update_tier_cutoff', ?, ?, ?)
            ");
            $stmt->execute([
                $_SESSION['admin_id'],
                "Updated tier-based cutoff for $tier, $duration: Admin $admin_cut%, Gym $gym_owner_cut%",
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT']
            ]);
            
            $success_message = "Cut-off percentages updated successfully!";
        } else {
            $error_message = "No changes were made or the specified tier and duration combination doesn't exist.";
        }
    } catch (Exception $e) {
        $error_message = "Error: " . $e->getMessage();
    }
}

// Handle form submission for fee-based cutoff update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_fee_cutoff'])) {
    try {
        $cutoff_id = (int)$_POST['cutoff_id'];
        $price_start = (float)$_POST['price_start'];
        $price_end = (float)$_POST['price_end'];
        $admin_cut = (float)$_POST['admin_cut'];
        $gym_cut = (float)$_POST['gym_cut'];

        // Validate percentages
        if (abs($admin_cut + $gym_cut - 100) > 0.01) { // Allow small floating point errors
            throw new Exception('Admin Cut and Gym Cut must add up to 100%.');
        }

        // Validate price range
        if ($price_start >= $price_end) {
            throw new Exception('Price range end must be greater than price range start.');
        }

        // Check for overlapping price ranges (excluding the current one)
        $checkStmt = $conn->prepare("
            SELECT id FROM fee_based_cuts 
            WHERE id != ? AND (
                (? BETWEEN price_range_start AND price_range_end) 
                OR (? BETWEEN price_range_start AND price_range_end)
                OR (price_range_start BETWEEN ? AND ?)
                OR (price_range_end BETWEEN ? AND ?)
            )
        ");
        $checkStmt->execute([$cutoff_id, $price_start, $price_end, $price_start, $price_end, $price_start, $price_end]);
        
        if ($checkStmt->rowCount() > 0) {
            throw new Exception('Price range overlaps with an existing range. Please choose a different range.');
        }

        // Update the fee-based cutoff
        $updateStmt = $conn->prepare("
            UPDATE fee_based_cuts
            SET price_range_start = ?, price_range_end = ?, 
                admin_cut_percentage = ?, gym_cut_percentage = ?
            WHERE id = ?
        ");
        $updateStmt->execute([$price_start, $price_end, $admin_cut, $gym_cut, $cutoff_id]);

        if ($updateStmt->rowCount() > 0) {
            // Log the activity
            $stmt = $conn->prepare("
                INSERT INTO activity_logs (
                    user_id, user_type, action, details, ip_address, user_agent
                ) VALUES (?, 'admin', 'update_fee_cutoff', ?, ?, ?)
            ");
            $stmt->execute([
                $_SESSION['admin_id'],
                "Updated fee-based cutoff ID $cutoff_id: Range ₹$price_start - ₹$price_end, Admin $admin_cut%, Gym $gym_cut%",
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT']
            ]);
            
            $success_message = "Fee-based cut-off updated successfully!";
        } else {
            $error_message = "No changes were made or the specified fee-based cutoff doesn't exist.";
        }
    } catch (Exception $e) {
        $error_message = "Error: " . $e->getMessage();
    }
}

// Fetch tier-based cutoffs for display
$tierBasedQuery = "SELECT * FROM cut_off_chart ORDER BY 
    CASE 
        WHEN tier = 'Tier 1' THEN 1
        WHEN tier = 'Tier 2' THEN 2
        WHEN tier = 'Tier 3' THEN 3
        ELSE 4
    END,
    CASE 
        WHEN duration = 'Daily' THEN 1
        WHEN duration = 'Weekly' THEN 2
        WHEN duration = 'Monthly' THEN 3
        WHEN duration = 'Quarterly' THEN 4
        WHEN duration = 'Half-Yearly' THEN 5
        WHEN duration = 'Yearly' THEN 6
        ELSE 7
    END";
$tierBasedStmt = $conn->prepare($tierBasedQuery);
$tierBasedStmt->execute();
$tierBasedCutoffs = $tierBasedStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch fee-based cutoffs for display
$feeBasedQuery = "SELECT * FROM fee_based_cuts ORDER BY price_range_start";
$feeBasedStmt = $conn->prepare($feeBasedQuery);
$feeBasedStmt->execute();
$feeBasedCutoffs = $feeBasedStmt->fetchAll(PDO::FETCH_ASSOC);

// Get cutoff details for pre-filling the form
$selectedTierCutoff = null;
if (isset($_GET['tier']) && isset($_GET['duration'])) {
    $tier = $_GET['tier'];
    $duration = $_GET['duration'];
    
    $stmt = $conn->prepare("SELECT * FROM cut_off_chart WHERE tier = ? AND duration = ?");
    $stmt->execute([$tier, $duration]);
    $selectedTierCutoff = $stmt->fetch(PDO::FETCH_ASSOC);
}

$selectedFeeCutoff = null;
if (isset($_GET['fee_id'])) {
    $fee_id = (int)$_GET['fee_id'];
    
    $stmt = $conn->prepare("SELECT * FROM fee_based_cuts WHERE id = ?");
    $stmt->execute([$fee_id]);
    $selectedFeeCutoff = $stmt->fetch(PDO::FETCH_ASSOC);
}

$page_title = "Update Cut-Off Settings";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Gyms - FlexFit Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
</head>
<body class="bg-gray-900 text-white">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="ml-0 lg:ml-64 p-4 sm:p-6 transition-all duration-200">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold text-white"><?= htmlspecialchars($page_title) ?></h1>
        <nav class="text-gray-400 text-sm">
            <a href="dashboard.php" class="hover:text-white">Dashboard</a>
            <span class="mx-2">/</span>
            <a href="cut-off-chart.php" class="hover:text-white">Cut-off Chart</a>
            <span class="mx-2">/</span>
            <span class="text-gray-300">Update Cut-Off</span>
        </nav>
    </div>

    <?php if ($success_message): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-6" role="alert">
            <span class="block sm:inline"><?= htmlspecialchars($success_message) ?></span>
            <button type="button" class="absolute top-0 bottom-0 right-0 px-4 py-3" onclick="this.parentElement.style.display='none'">
                <svg class="fill-current h-6 w-6 text-green-500" role="button" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                    <title>Close</title>
                    <path d="M14.348 14.849a1.2 1.2 0 0 1-1.697 0L10 11.819l-2.651 3.029a1.2 1.2 0 1 1-1.697-1.697l2.758-3.15-2.759-3.152a1.2 1.2 0 1 1 1.697-1.697L10 8.183l2.651-3.031a1.2 1.2 0 1 1 1.697 1.697l-2.758 3.152 2.758 3.15a1.2 1.2 0 0 1 0 1.698z"/>
                </svg>
            </button>
        </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-6" role="alert">
            <span class="block sm:inline"><?= htmlspecialchars($error_message) ?></span>
            <button type="button" class="absolute top-0 bottom-0 right-0 px-4 py-3" onclick="this.parentElement.style.display='none'">
                <svg class="fill-current h-6 w-6 text-red-500" role="button" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                    <title>Close</title>
                    <path d="M14.348 14.849a1.2 1.2 0 0 1-1.697 0L10 11.819l-2.651 3.029a1.2 1.2 0 1 1-1.697-1.697l2.758-3.15-2.759-3.152a1.2 1.2 0 1 1 1.697-1.697L10 8.183l2.651-3.031a1.2 1.2 0 1 1 1.697 1.697l-2.758 3.152 2.758 3.15a1.2 1.2 0 0 1 0 1.698z"/>
                </svg>
            </button>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Tier Based Cut-Off Update Form -->
        <div class="bg-gray-800 rounded-lg shadow-lg overflow-hidden">
            <div class="p-6 border-b border-gray-700">
                <h2 class="text-xl font-semibold text-white flex items-center">
                    <i class="fas fa-layer-group text-blue-400 mr-2"></i>
                    Update Tier Based Cut-Off
                </h2>
                <p class="text-gray-400 mt-1">Modify revenue distribution for tier and duration combinations</p>
            </div>
            
            <div class="p-6">
                <form method="POST" class="space-y-4" id="tierBasedForm">
                    <input type="hidden" name="update_tier_cutoff" value="1">
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="tier" class="block text-gray-300 mb-2">Tier</label>
                            <select name="tier" id="tier" required 
                                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Select Tier</option>
                                <?php foreach ($tiers as $tier): ?>
                                    <option value="<?= htmlspecialchars($tier) ?>" <?= ($selectedTierCutoff && $selectedTierCutoff['tier'] === $tier) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($tier) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label for="duration" class="block text-gray-300 mb-2">Duration</label>
                            <select name="duration" id="duration" required 
                                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Select Duration</option>
                                <?php foreach ($durations as $duration): ?>
                                    <option value="<?= htmlspecialchars($duration) ?>" <?= ($selectedTierCutoff && $selectedTierCutoff['duration'] === $duration) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($duration) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label for="admin_cut" class="block text-gray-300 mb-2">Admin Cut (%)</label>
                            <input type="number" name="admin_cut" id="admin_cut" required 
                                   class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500" 
                                   min="0" max="100" step="0.01" value="<?= $selectedTierCutoff ? htmlspecialchars($selectedTierCutoff['admin_cut_percentage']) : '' ?>">
                        </div>
                        
                        <div>
                            <label for="gym_owner_cut" class="block text-gray-300 mb-2">Gym Owner Cut (%)</label>
                            <input type="number" name="gym_owner_cut" id="gym_owner_cut" required 
                                   class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500" 
                                   min="0" max="100" step="0.01" value="<?= $selectedTierCutoff ? htmlspecialchars($selectedTierCutoff['gym_owner_cut_percentage']) : '' ?>">
                        </div>
                    </div>
                    
                    <div class="flex justify-end">
                        <a href="cut-off-chart.php" class="px-4 py-2 bg-gray-700 hover:bg-gray-600 text-white rounded-lg mr-3 transition-colors duration-200">
                            Cancel
                        </a>
                        <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors duration-200">
                            <i class="fas fa-save mr-2"></i> Update Tier Based Cut-Off
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Tier Based Cutoffs Table -->
            <div class="p-6 border-t border-gray-700">
                <h3 class="text-lg font-semibold text-white mb-4">Current Tier Based Cutoffs</h3>
                
                <?php if (empty($tierBasedCutoffs)): ?>
                    <p class="text-gray-400 text-center py-4">No tier-based cutoffs defined yet.</p>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-700">
                            <thead class="bg-gray-700">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                        Tier
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                        Duration
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                        Admin Cut (%)
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                        Gym Cut (%)
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-gray-800 divide-y divide-gray-700">
                                <?php foreach ($tierBasedCutoffs as $cutoff): ?>
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                                <?php
                                                switch ($cutoff['tier']) {
                                                    case 'Tier 1':
                                                        echo 'bg-blue-900 text-blue-300';
                                                        break;
                                                    case 'Tier 2':
                                                        echo 'bg-green-900 text-green-300';
                                                        break;
                                                    case 'Tier 3':
                                                        echo 'bg-purple-900 text-purple-300';
                                                        break;
                                                    default:
                                                        echo 'bg-gray-700 text-gray-300';
                                                }
                                                ?>">
                                                <?= htmlspecialchars($cutoff['tier']) ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-white"><?= htmlspecialchars($cutoff['duration']) ?></div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-white"><?= number_format($cutoff['admin_cut_percentage'], 2) ?>%</div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-white"><?= number_format($cutoff['gym_owner_cut_percentage'], 2) ?>%</div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <a href="?tier=<?= urlencode($cutoff['tier']) ?>&duration=<?= urlencode($cutoff['duration']) ?>" 
                                               class="text-blue-400 hover:text-blue-300 mr-3">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Fee Based Cut-Off Update Form -->
        <div class="bg-gray-800 rounded-lg shadow-lg overflow-hidden">
            <div class="p-6 border-b border-gray-700">
                <h2 class="text-xl font-semibold text-white flex items-center">
                    <i class="fas fa-money-bill-wave text-green-400 mr-2"></i>
                    Update Fee Based Cut-Off
                </h2>
                <p class="text-gray-400 mt-1">Modify revenue distribution for price ranges</p>
            </div>
            
            <div class="p-6">
                <form method="POST" class="space-y-4" id="feeBasedForm">
                    <input type="hidden" name="update_fee_cutoff" value="1">
                    <input type="hidden" name="cutoff_id" value="<?= $selectedFeeCutoff ? htmlspecialchars($selectedFeeCutoff['id']) : '' ?>">
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="price_start" class="block text-gray-300 mb-2">Price Range Start (₹)</label>
                            <input type="number" name="price_start" id="price_start" required 
                                   class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-green-500" 
                                   min="0" step="0.01" value="<?= $selectedFeeCutoff ? htmlspecialchars($selectedFeeCutoff['price_range_start']) : '' ?>">
                        </div>
                        
                        <div>
                            <label for="price_end" class="block text-gray-300 mb-2">Price Range End (₹)</label>
                            <input type="number" name="price_end" id="price_end" required 
                                   class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-green-500" 
                                   min="0" step="0.01" value="<?= $selectedFeeCutoff ? htmlspecialchars($selectedFeeCutoff['price_range_end']) : '' ?>">
                        </div>
                        
                        <div>
                            <label for="admin_cut_fee" class="block text-gray-300 mb-2">Admin Cut (%)</label>
                            <input type="number" name="admin_cut" id="admin_cut_fee" required 
                                   class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-green-500" 
                                   min="0" max="100" step="0.01" value="<?= $selectedFeeCutoff ? htmlspecialchars($selectedFeeCutoff['admin_cut_percentage']) : '' ?>">
                        </div>
                        
                        <div>
                            <label for="gym_cut" class="block text-gray-300 mb-2">Gym Cut (%)</label>
                            <input type="number" name="gym_cut" id="gym_cut_fee" required 
                                   class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-green-500" 
                                   min="0" max="100" step="0.01" value="<?= $selectedFeeCutoff ? htmlspecialchars($selectedFeeCutoff['gym_cut_percentage']) : '' ?>">
                        </div>
                    </div>
                    
                    <div class="flex justify-end">
                        <a href="cut-off-chart.php" class="px-4 py-2 bg-gray-700 hover:bg-gray-600 text-white rounded-lg mr-3 transition-colors duration-200">
                            Cancel
                        </a>
                        <button type="submit" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg transition-colors duration-200" 
                                <?= $selectedFeeCutoff ? '' : 'disabled' ?>>
                            <i class="fas fa-save mr-2"></i> Update Fee Based Cut-Off
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Fee Based Cutoffs Table -->
            <div class="p-6 border-t border-gray-700">
                <h3 class="text-lg font-semibold text-white mb-4">Current Fee Based Cutoffs</h3>
                
                <?php if (empty($feeBasedCutoffs)): ?>
                    <p class="text-gray-400 text-center py-4">No fee-based cutoffs defined yet.</p>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-700">
                            <thead class="bg-gray-700">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                        Price Range (₹)
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                        Admin Cut (%)
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                        Gym Cut (%)
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-gray-800 divide-y divide-gray-700">
                                <?php foreach ($feeBasedCutoffs as $cutoff): ?>
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-white">
                                                ₹<?= number_format($cutoff['price_range_start'], 2) ?> - 
                                                ₹<?= number_format($cutoff['price_range_end'], 2) ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-white"><?= number_format($cutoff['admin_cut_percentage'], 2) ?>%</div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-white"><?= number_format($cutoff['gym_cut_percentage'], 2) ?>%</div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <a href="?fee_id=<?= $cutoff['id'] ?>" class="text-blue-400 hover:text-blue-300 mr-3">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Information Card -->
    <div class="bg-gray-800 rounded-lg shadow-lg p-6 mt-6">
        <h2 class="text-xl font-semibold text-white mb-4 flex items-center">
            <i class="fas fa-info-circle text-yellow-400 mr-2"></i>
            Cut-Off Settings Information
        </h2>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="bg-gray-700 rounded-lg p-4">
            <h3 class="text-lg font-semibold text-white mb-2">Tier Based Cut-Off</h3>
                <p class="text-gray-300 mb-3">
                    Tier based cut-offs determine revenue distribution based on the membership tier and duration.
                </p>
                <ul class="list-disc list-inside text-gray-300 space-y-1">
                    <li>Select an existing tier and duration combination to update</li>
                    <li>Ensure the admin cut and gym owner cut percentages add up to 100%</li>
                    <li>Changes will affect all future revenue calculations for this tier/duration</li>
                </ul>
            </div>
            
            <div class="bg-gray-700 rounded-lg p-4">
                <h3 class="text-lg font-semibold text-white mb-2">Fee Based Cut-Off</h3>
                <p class="text-gray-300 mb-3">
                    Fee based cut-offs determine revenue distribution based on the price range of the membership.
                </p>
                <ul class="list-disc list-inside text-gray-300 space-y-1">
                    <li>Select an existing fee-based cutoff to update</li>
                    <li>Ensure the price ranges don't overlap with other existing ranges</li>
                    <li>Admin cut and gym cut percentages must add up to 100%</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
    // Validate total percentage equals 100 for tier based form
    document.getElementById('tierBasedForm').addEventListener('submit', function(e) {
        const adminCut = parseFloat(document.getElementById('admin_cut').value);
        const gymCut = parseFloat(document.getElementById('gym_owner_cut').value);
        
        if (isNaN(adminCut) || isNaN(gymCut)) {
            e.preventDefault();
            alert('Please enter valid percentage values.');
            return;
        }
        
        if (Math.abs(adminCut + gymCut - 100) > 0.01) { // Allow small floating point errors
            e.preventDefault();
            alert('Total percentage must equal 100%. Current total: ' + (adminCut + gymCut).toFixed(2) + '%');
        }
    });
    
    // Validate total percentage equals 100 for fee based form
    document.getElementById('feeBasedForm').addEventListener('submit', function(e) {
        const adminCut = parseFloat(document.getElementById('admin_cut_fee').value);
        const gymCut = parseFloat(document.getElementById('gym_cut_fee').value);
        
        if (isNaN(adminCut) || isNaN(gymCut)) {
            e.preventDefault();
            alert('Please enter valid percentage values.');
            return;
        }
        
        if (Math.abs(adminCut + gymCut - 100) > 0.01) { // Allow small floating point errors
            e.preventDefault();
            alert('Total percentage must equal 100%. Current total: ' + (adminCut + gymCut).toFixed(2) + '%');
        }
        
        // Validate price range
        const priceStart = parseFloat(document.getElementById('price_start').value);
        const priceEnd = parseFloat(document.getElementById('price_end').value);
        
        if (isNaN(priceStart) || isNaN(priceEnd)) {
            e.preventDefault();
            alert('Please enter valid price range values.');
            return;
        }
        
        if (priceStart >= priceEnd) {
            e.preventDefault();
            alert('Price range end must be greater than price range start.');
        }
    });
    
    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        const alerts = document.querySelectorAll('.bg-green-100, .bg-red-100');
        alerts.forEach(function(alert) {
            alert.style.display = 'none';
        });
    }, 5000);
    
    // Auto-calculate gym cut when admin cut changes for tier based form
    document.getElementById('admin_cut').addEventListener('input', function() {
        const adminCut = parseFloat(this.value) || 0;
        document.getElementById('gym_owner_cut').value = (100 - adminCut).toFixed(2);
    });
    
    // Auto-calculate admin cut when gym cut changes for tier based form
    document.getElementById('gym_owner_cut').addEventListener('input', function() {
        const gymCut = parseFloat(this.value) || 0;
        document.getElementById('admin_cut').value = (100 - gymCut).toFixed(2);
    });
    
    // Auto-calculate gym cut when admin cut changes for fee based form
    document.getElementById('admin_cut_fee').addEventListener('input', function() {
        const adminCut = parseFloat(this.value) || 0;
        document.getElementById('gym_cut_fee').value = (100 - adminCut).toFixed(2);
    });
    
    // Auto-calculate admin cut when gym cut changes for fee based form
    document.getElementById('gym_cut_fee').addEventListener('input', function() {
        const gymCut = parseFloat(this.value) || 0;
        document.getElementById('admin_cut_fee').value = (100 - gymCut).toFixed(2);
    });
</script>

</body>
</html>


