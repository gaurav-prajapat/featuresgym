<?php
require_once '../config/database.php';
session_start();

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

class GymMembershipPlans {
    private $conn;

    public function __construct($dbConnection) {
        $this->conn = $dbConnection;
    }

    public function fetchPlans($search = '', $tierFilter = '', $durationFilter = '', $cutTypeFilter = '', $sortColumn = 'plan_id', $sortOrder = 'ASC') {
        $query = "
           SELECT 
            gmp.plan_id, 
            gmp.gym_id, 
            gmp.tier, 
            gmp.duration, 
            gmp.price, 
            gmp.inclusions,
            CASE 
                WHEN gmp.price BETWEEN fbc.price_range_start AND fbc.price_range_end THEN 'fee_based'
                ELSE 'tier_based'
            END as cut_type,
            CASE 
                WHEN gmp.price BETWEEN fbc.price_range_start AND fbc.price_range_end THEN fbc.admin_cut_percentage
                ELSE coc.admin_cut_percentage
            END as admin_cut_percentage,
            CASE 
                WHEN gmp.price BETWEEN fbc.price_range_start AND fbc.price_range_end THEN fbc.gym_cut_percentage
                ELSE coc.gym_owner_cut_percentage
            END as gym_owner_cut_percentage
        FROM gym_membership_plans gmp
        LEFT JOIN cut_off_chart coc ON gmp.tier = coc.tier AND gmp.duration = coc.duration
        LEFT JOIN fee_based_cuts fbc ON gmp.price BETWEEN fbc.price_range_start AND fbc.price_range_end";

        $where_conditions = [];
        $params = [];

        if (!empty($search)) {
            $where_conditions[] = "(gmp.plan_id LIKE ? OR gmp.gym_id LIKE ? OR gmp.tier LIKE ?)";
            $search_param = "%$search%";
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = $search_param;
        }
        
        if (!empty($tierFilter)) {
            $where_conditions[] = "gmp.tier = ?";
            $params[] = $tierFilter;
        }
        
        if (!empty($durationFilter)) {
            $where_conditions[] = "gmp.duration = ?";
            $params[] = $durationFilter;
        }
        
        if (!empty($cutTypeFilter)) {
            $where_conditions[] = "CASE 
                WHEN gmp.price BETWEEN fbc.price_range_start AND fbc.price_range_end THEN 'fee_based'
                ELSE 'tier_based'
            END = ?";
            $params[] = $cutTypeFilter;
        }

        if (!empty($where_conditions)) {
            $query .= " WHERE " . implode(" AND ", $where_conditions);
        }

        $query .= " ORDER BY $sortColumn $sortOrder";

        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

try {
    $gymPlans = new GymMembershipPlans($conn);
    
    // Get filter parameters
    $search = isset($_POST['search']) ? trim($_POST['search']) : '';
    $tierFilter = isset($_POST['tier']) ? $_POST['tier'] : '';
    $durationFilter = isset($_POST['duration']) ? $_POST['duration'] : '';
    $cutTypeFilter = isset($_POST['cut_type']) ? $_POST['cut_type'] : '';
    $sortColumn = isset($_GET['sort']) ? $_GET['sort'] : 'price';
    $sortOrder = isset($_GET['order']) ? $_GET['order'] : 'ASC';

    // Fetch plans with filters
    $plans = $gymPlans->fetchPlans($search, $tierFilter, $durationFilter, $cutTypeFilter, $sortColumn, $sortOrder);

    // Calculate total revenue
    $totalAdminRevenue = 0;
    $totalGymRevenue = 0;
    foreach ($plans as $plan) {
        $totalAdminRevenue += ($plan['price'] * $plan['admin_cut_percentage']) / 100;
        $totalGymRevenue += ($plan['price'] * $plan['gym_owner_cut_percentage']) / 100;
    }
    
    // Fetch all gyms for reference
    $stmt = $conn->prepare("SELECT gym_id, name FROM gyms ORDER BY name");
    $stmt->execute();
    $gyms = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $gyms[$row['gym_id']] = $row['name'];
    }
    
    // Handle update cut type for a specific plan
    if (isset($_POST['update_cut_type']) && isset($_POST['plan_id']) && isset($_POST['cut_type'])) {
        $plan_id = (int)$_POST['plan_id'];
        $cut_type = $_POST['cut_type'];
        
        try {
            $stmt = $conn->prepare("UPDATE gym_membership_plans SET cut_type = ? WHERE plan_id = ?");
            $stmt->execute([$cut_type, $plan_id]);
            
            // Log the activity
            $stmt = $conn->prepare("
                INSERT INTO activity_logs (
                    user_id, user_type, action, details, ip_address, user_agent
                ) VALUES (?, 'admin', 'update_cut_type', ?, ?, ?)
            ");
            $stmt->execute([
                $_SESSION['admin_id'],
                "Updated cut type for plan ID $plan_id to $cut_type",
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT']
            ]);
            
            $success_message = "Cut type updated successfully for plan ID: $plan_id";
        } catch (PDOException $e) {
            $error_message = "Error updating cut type: " . $e->getMessage();
        }
    }
    
    // Handle update all plans cut type
    if (isset($_POST['update_all_cutoffs']) && isset($_POST['global_cut_type'])) {
        $cut_type = $_POST['global_cut_type'];
        
        try {
            $stmt = $conn->prepare("UPDATE gym_membership_plans SET cut_type = ?");
            $stmt->execute([$cut_type]);
            
            // Log the activity
            $stmt = $conn->prepare("
                INSERT INTO activity_logs (
                    user_id, user_type, action, details, ip_address, user_agent
                ) VALUES (?, 'admin', 'update_all_cut_types', ?, ?, ?)
            ");
            $stmt->execute([
                $_SESSION['admin_id'],
                "Updated all plans cut type to $cut_type",
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT']
            ]);
            
            $success_message = "All plans cut type updated successfully to: $cut_type";
        } catch (PDOException $e) {
            $error_message = "Error updating all cut types: " . $e->getMessage();
        }
    }
    
    $page_title = "Cut-off Chart Management";
} catch (Exception $e) {
    $error_message = "Error: " . $e->getMessage();
}
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
            <span class="text-gray-300">Cut-off Chart</span>
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

    <!-- Filters and Actions -->
    <div class="bg-gray-800 rounded-lg shadow-lg p-6 mb-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <h2 class="text-lg font-semibold text-white mb-4">Filter Plans</h2>
                <form method="post" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="search" class="block text-gray-300 mb-2">Search</label>
                            <input type="text" id="search" name="search" value="<?= htmlspecialchars($search) ?>" 
                                   placeholder="Plan ID, Gym ID, Tier" 
                                   class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        
                        <div>
                            <label for="tier" class="block text-gray-300 mb-2">Tier</label>
                            <select id="tier" name="tier" 
                                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">All Tiers</option>
                                <option value="Tier 1" <?= $tierFilter === 'Tier 1' ? 'selected' : '' ?>>Tier 1</option>
                                <option value="Tier 2" <?= $tierFilter === 'Tier 2' ? 'selected' : '' ?>>Tier 2</option>
                                <option value="Tier 3" <?= $tierFilter === 'Tier 3' ? 'selected' : '' ?>>Tier 3</option>
                            </select>
                        </div>
                        
                        <div>
                            <label for="duration" class="block text-gray-300 mb-2">Duration</label>
                            <select id="duration" name="duration" 
                                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">All Durations</option>
                                <option value="Daily" <?= $durationFilter === 'Daily' ? 'selected' : '' ?>>Daily</option>
                                <option value="Weekly" <?= $durationFilter === 'Weekly' ? 'selected' : '' ?>>Weekly</option>
                                <option value="Monthly" <?= $durationFilter === 'Monthly' ? 'selected' : '' ?>>Monthly</option>
                                <option value="Quarterly" <?= $durationFilter === 'Quarterly' ? 'selected' : '' ?>>Quarterly</option>
                                <option value="Half-Yearly" <?= $durationFilter === 'Half-Yearly' ? 'selected' : '' ?>>Half-Yearly</option>
                                <option value="Yearly" <?= $durationFilter === 'Yearly' ? 'selected' : '' ?>>Yearly</option>
                            </select>
                        </div>
                        
                        <div>
                            <label for="cut_type" class="block text-gray-300 mb-2">Cut Type</label>
                            <select id="cut_type" name="cut_type" 
                                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <option value="">All Cut Types</option>
                                <option value="tier_based" <?= $cutTypeFilter === 'tier_based' ? 'selected' : '' ?>>Tier Based</option>
                                <option value="fee_based" <?= $cutTypeFilter === 'fee_based' ? 'selected' : '' ?>>Fee Based</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="flex justify-end">
                        <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors duration-200">
                            <i class="fas fa-filter mr-2"></i> Apply Filters
                        </button>
                        <a href="cut-off-chart.php" class="ml-2 px-4 py-2 bg-gray-700 hover:bg-gray-600 text-white rounded-lg transition-colors duration-200">
                            <i class="fas fa-sync-alt mr-2"></i> Reset
                        </a>
                    </div>
                </form>
            </div>
            
            <div>
                <h2 class="text-lg font-semibold text-white mb-4">Global Cut Type Update</h2>
                <form method="post" class="space-y-4">
                    <div>
                        <label for="global_cut_type" class="block text-gray-300 mb-2">Set Cut Type for All Plans</label>
                        <select id="global_cut_type" name="global_cut_type" 
                                class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="tier_based">Tier Based</option>
                            <option value="fee_based">Fee Based</option>
                        </select>
                        <p class="text-gray-400 text-sm mt-2">
                            <i class="fas fa-info-circle mr-1"></i>
                            This will update the cut type for all membership plans in the system.
                        </p>
                    </div>
                    
                    <div class="flex justify-end">
                        <button type="submit" name="update_all_cutoffs" value="1" class="px-4 py-2 bg-yellow-600 hover:bg-yellow-700 text-white rounded-lg transition-colors duration-200">
                            <i class="fas fa-sync-alt mr-2"></i> Update All Plans
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Revenue Summary -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
        <div class="bg-gray-800 rounded-lg shadow-lg p-6">
            <h2 class="text-lg font-semibold text-white mb-4">Admin Revenue Summary</h2>
            <div class="flex items-center">
                <div class="w-16 h-16 rounded-full bg-blue-900 flex items-center justify-center mr-4">
                    <i class="fas fa-chart-line text-blue-300 text-2xl"></i>
                </div>
                <div>
                    <p class="text-gray-400">Total Admin Revenue</p>
                    <p class="text-2xl font-bold text-white">₹<?= number_format($totalAdminRevenue, 2) ?></p>
                </div>
            </div>
        </div>
        
        <div class="bg-gray-800 rounded-lg shadow-lg p-6">
            <h2 class="text-lg font-semibold text-white mb-4">Gym Revenue Summary</h2>
            <div class="flex items-center">
                <div class="w-16 h-16 rounded-full bg-green-900 flex items-center justify-center mr-4">
                    <i class="fas fa-dumbbell text-green-300 text-2xl"></i>
                </div>
                <div>
                    <p class="text-gray-400">Total Gym Revenue</p>
                    <p class="text-2xl font-bold text-white">₹<?= number_format($totalGymRevenue, 2) ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Cut-off Chart Table -->
    <div class="bg-gray-800 rounded-lg shadow-lg overflow-hidden">
        <div class="p-6 border-b border-gray-700">
            <h2 class="text-xl font-semibold text-white">Membership Plans Cut-off Chart</h2>
            <p class="text-gray-400 mt-1">Manage revenue distribution between admin and gym owners</p>
        </div>
        
        <?php if (empty($plans)): ?>
            <div class="p-6 text-center text-gray-400">
                <i class="fas fa-chart-pie text-4xl mb-4"></i>
                <p>No plans found. Try adjusting your filters.</p>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-700">
                    <thead class="bg-gray-700">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                <a href="?sort=plan_id&order=<?= $sortColumn === 'plan_id' && $sortOrder === 'ASC' ? 'DESC' : 'ASC' ?>" class="flex items-center">
                                    Plan ID
                                    <?php if ($sortColumn === 'plan_id'): ?>
                                        <i class="fas fa-sort-<?= $sortOrder === 'ASC' ? 'up' : 'down' ?> ml-1"></i>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                <a href="?sort=gym_id&order=<?= $sortColumn === 'gym_id' && $sortOrder === 'ASC' ? 'DESC' : 'ASC' ?>" class="flex items-center">
                                    Gym
                                    <?php if ($sortColumn === 'gym_id'): ?>
                                        <i class="fas fa-sort-<?= $sortOrder === 'ASC' ? 'up' : 'down' ?> ml-1"></i>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                <a href="?sort=tier&order=<?= $sortColumn === 'tier' && $sortOrder === 'ASC' ? 'DESC' : 'ASC' ?>" class="flex items-center">
                                    Tier
                                    <?php if ($sortColumn === 'tier'): ?>
                                        <i class="fas fa-sort-<?= $sortOrder === 'ASC' ? 'up' : 'down' ?> ml-1"></i>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                <a href="?sort=duration&order=<?= $sortColumn === 'duration' && $sortOrder === 'ASC' ? 'DESC' : 'ASC' ?>" class="flex items-center">
                                    Duration
                                    <?php if ($sortColumn === 'duration'): ?>
                                        <i class="fas fa-sort-<?= $sortOrder === 'ASC' ? 'up' : 'down' ?> ml-1"></i>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                <a href="?sort=price&order=<?= $sortColumn === 'price' && $sortOrder === 'ASC' ? 'DESC' : 'ASC' ?>" class="flex items-center">
                                    Price (₹)
                                    <?php if ($sortColumn === 'price'): ?>
                                        <i class="fas fa-sort-<?= $sortOrder === 'ASC' ? 'up' : 'down' ?> ml-1"></i>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                <a href="?sort=cut_type&order=<?= $sortColumn === 'cut_type' && $sortOrder === 'ASC' ? 'DESC' : 'ASC' ?>" class="flex items-center">
                                    Cut Type
                                    <?php if ($sortColumn === 'cut_type'): ?>
                                        <i class="fas fa-sort-<?= $sortOrder === 'ASC' ? 'up' : 'down' ?> ml-1"></i>
                                    <?php endif; ?>
                                </a>
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
                        <?php foreach ($plans as $plan): ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-white"><?= $plan['plan_id'] ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-white"><?= htmlspecialchars($gyms[$plan['gym_id']] ?? "Gym ID: {$plan['gym_id']}") ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                        <?php
                                        switch ($plan['tier']) {
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
                                        <?= htmlspecialchars($plan['tier']) ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-white"><?= htmlspecialchars($plan['duration']) ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-white">₹<?= number_format($plan['price'], 2) ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                        <?= $plan['cut_type'] === 'fee_based' ? 'bg-yellow-900 text-yellow-300' : 'bg-indigo-900 text-indigo-300' ?>">
                                        <?= ucfirst(str_replace('_', ' ', $plan['cut_type'])) ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-white"><?= number_format($plan['admin_cut_percentage'], 2) ?>%</div>
                                    <div class="text-xs text-gray-400">₹<?= number_format(($plan['price'] * $plan['admin_cut_percentage']) / 100, 2) ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-white"><?= number_format($plan['gym_owner_cut_percentage'], 2) ?>%</div>
                                    <div class="text-xs text-gray-400">₹<?= number_format(($plan['price'] * $plan['gym_owner_cut_percentage']) / 100, 2) ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <button type="button" class="text-blue-400 hover:text-blue-300 change-cut-type-btn" 
                                            data-plan-id="<?= $plan['plan_id'] ?>"
                                            data-current-cut-type="<?= $plan['cut_type'] ?>"
                                            title="Change Cut Type">
                                        <i class="fas fa-exchange-alt"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Change Cut Type Modal -->
<div id="changeCutTypeModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
    <div class="bg-gray-800 rounded-lg shadow-lg p-6 max-w-md w-full mx-4">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-semibold text-white">Change Cut Type</h3>
            <button type="button" id="closeCutTypeModal" class="text-gray-400 hover:text-white">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <form id="changeCutTypeForm" method="POST" action="">
        <input type="hidden" name="plan_id" id="cut_type_plan_id">
            <input type="hidden" name="update_cut_type" value="1">
            
            <div class="mb-4">
                <label for="new_cut_type" class="block text-gray-300 mb-2">New Cut Type</label>
                <select id="new_cut_type" name="cut_type" 
                        class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="tier_based">Tier Based</option>
                    <option value="fee_based">Fee Based</option>
                </select>
                <p class="text-gray-400 text-sm mt-2">
                    <i class="fas fa-info-circle mr-1"></i>
                    <span id="cut_type_explanation">
                        Tier Based: Revenue distribution is determined by the plan's tier and duration.
                    </span>
                </p>
            </div>
            
            <div class="flex justify-end">
                <button type="button" id="cancelCutTypeChange" class="px-4 py-2 bg-gray-700 hover:bg-gray-600 text-white rounded-lg mr-3 transition-colors duration-200">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors duration-200">
                    <i class="fas fa-save mr-2"></i> Update Cut Type
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    // Change Cut Type Modal
    const changeCutTypeBtns = document.querySelectorAll('.change-cut-type-btn');
    const changeCutTypeModal = document.getElementById('changeCutTypeModal');
    const closeCutTypeModal = document.getElementById('closeCutTypeModal');
    const cancelCutTypeChange = document.getElementById('cancelCutTypeChange');
    const cutTypePlanId = document.getElementById('cut_type_plan_id');
    const newCutTypeSelect = document.getElementById('new_cut_type');
    const cutTypeExplanation = document.getElementById('cut_type_explanation');
    
    changeCutTypeBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const planId = this.getAttribute('data-plan-id');
            const currentCutType = this.getAttribute('data-current-cut-type');
            
            cutTypePlanId.value = planId;
            newCutTypeSelect.value = currentCutType;
            updateCutTypeExplanation(currentCutType);
            
            changeCutTypeModal.classList.remove('hidden');
        });
    });
    
    if (closeCutTypeModal) {
        closeCutTypeModal.addEventListener('click', function() {
            changeCutTypeModal.classList.add('hidden');
        });
    }
    
    if (cancelCutTypeChange) {
        cancelCutTypeChange.addEventListener('click', function() {
            changeCutTypeModal.classList.add('hidden');
        });
    }
    
    if (newCutTypeSelect) {
        newCutTypeSelect.addEventListener('change', function() {
            updateCutTypeExplanation(this.value);
        });
    }
    
    function updateCutTypeExplanation(cutType) {
        if (cutType === 'tier_based') {
            cutTypeExplanation.textContent = 'Tier Based: Revenue distribution is determined by the plan\'s tier and duration.';
        } else {
            cutTypeExplanation.textContent = 'Fee Based: Revenue distribution is determined by the plan\'s price range.';
        }
    }
    
    // Close modal when clicking outside
    window.addEventListener('click', function(event) {
        if (event.target === changeCutTypeModal) {
            changeCutTypeModal.classList.add('hidden');
        }
    });
    
    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        const alerts = document.querySelectorAll('.bg-green-100, .bg-red-100');
        alerts.forEach(function(alert) {
            alert.style.display = 'none';
        });
    }, 5000);
    
    // Auto-submit form when filters change
    document.querySelectorAll('select[name="tier"], select[name="duration"], select[name="cut_type"]').forEach(select => {
        select.addEventListener('change', function() {
            this.form.submit();
        });
    });
</script>



