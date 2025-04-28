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

// Handle form submission if redirected from process_cutoff.php
if (isset($_SESSION['cutoff_success'])) {
    $success_message = $_SESSION['cutoff_success'];
    unset($_SESSION['cutoff_success']);
}

if (isset($_SESSION['cutoff_error'])) {
    $error_message = $_SESSION['cutoff_error'];
    unset($_SESSION['cutoff_error']);
}

// Fetch existing tier-based cutoffs
$tierBasedQuery = "SELECT * FROM cut_off_chart ORDER BY tier, duration";
$tierBasedStmt = $conn->prepare($tierBasedQuery);
$tierBasedStmt->execute();
$tierBasedCutoffs = $tierBasedStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch existing fee-based cutoffs
$feeBasedQuery = "SELECT * FROM fee_based_cuts ORDER BY price_range_start";
$feeBasedStmt = $conn->prepare($feeBasedQuery);
$feeBasedStmt->execute();
$feeBasedCutoffs = $feeBasedStmt->fetchAll(PDO::FETCH_ASSOC);

// Handle delete tier-based cutoff
if (isset($_POST['delete_tier_cutoff']) && isset($_POST['cutoff_id'])) {
    $cutoffId = (int)$_POST['cutoff_id'];
    
    try {
        $stmt = $conn->prepare("DELETE FROM cut_off_chart WHERE id = ?");
        $result = $stmt->execute([$cutoffId]);
        
        if ($result) {
            // Log the activity
            $stmt = $conn->prepare("
                INSERT INTO activity_logs (
                    user_id, user_type, action, details, ip_address, user_agent
                ) VALUES (?, 'admin', 'delete_tier_cutoff', ?, ?, ?)
            ");
            $stmt->execute([
                $_SESSION['admin_id'],
                "Deleted tier-based cutoff ID: $cutoffId",
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT']
            ]);
            
            $success_message = "Tier-based cutoff deleted successfully!";
            
            // Refresh the cutoffs list
            $tierBasedStmt->execute();
            $tierBasedCutoffs = $tierBasedStmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $error_message = "Failed to delete tier-based cutoff.";
        }
    } catch (PDOException $e) {
        $error_message = "Error: " . $e->getMessage();
    }
}

// Handle delete fee-based cutoff
if (isset($_POST['delete_fee_cutoff']) && isset($_POST['cutoff_id'])) {
    $cutoffId = (int)$_POST['cutoff_id'];
    
    try {
        $stmt = $conn->prepare("DELETE FROM fee_based_cuts WHERE id = ?");
        $result = $stmt->execute([$cutoffId]);
        
        if ($result) {
            // Log the activity
            $stmt = $conn->prepare("
                INSERT INTO activity_logs (
                    user_id, user_type, action, details, ip_address, user_agent
                ) VALUES (?, 'admin', 'delete_fee_cutoff', ?, ?, ?)
            ");
            $stmt->execute([
                $_SESSION['admin_id'],
                "Deleted fee-based cutoff ID: $cutoffId",
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT']
            ]);
            
            $success_message = "Fee-based cutoff deleted successfully!";
            
            // Refresh the cutoffs list
            $feeBasedStmt->execute();
            $feeBasedCutoffs = $feeBasedStmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $error_message = "Failed to delete fee-based cutoff.";
        }
    } catch (PDOException $e) {
        $error_message = "Error: " . $e->getMessage();
    }
}

$page_title = "Add Cut-Off Settings";
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
            <span class="text-gray-300">Add Cut-Off</span>
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
        <!-- Tier Based Cut-Off Form -->
        <div class="bg-gray-800 rounded-lg shadow-lg overflow-hidden">
            <div class="p-6 border-b border-gray-700">
                <h2 class="text-xl font-semibold text-white flex items-center">
                    <i class="fas fa-layer-group text-blue-400 mr-2"></i>
                    Tier Based Cut-Off
                </h2>
                <p class="text-gray-400 mt-1">Define revenue distribution based on membership tier and duration</p>
            </div>
            
            <div class="p-6">
                <form method="POST" action="process_cutoff.php" class="space-y-4" id="tierBasedForm">
                    <input type="hidden" name="cut_type" value="tier_based">
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="tier" class="block text-gray-300 mb-2">Tier</label>
                            <select name="tier" id="tier" required 
                                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Select Tier</option>
                                <option value="Tier 1">Tier 1</option>
                                <option value="Tier 2">Tier 2</option>
                                <option value="Tier 3">Tier 3</option>
                            </select>
                        </div>
                        
                        <div>
                            <label for="duration" class="block text-gray-300 mb-2">Duration</label>
                            <select name="duration" id="duration" required 
                                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Select Duration</option>
                                <option value="Daily">Daily</option>
                                <option value="Weekly">Weekly</option>
                                <option value="Monthly">Monthly</option>
                                <option value="Quarterly">Quarterly</option>
                                <option value="Half-Yearly">Half-Yearly</option>
                                <option value="Yearly">Yearly</option>
                            </select>
                        </div>
                        
                        <div>
                            <label for="admin_cut" class="block text-gray-300 mb-2">Admin Cut (%)</label>
                            <input type="number" name="admin_cut" id="admin_cut" required 
                                   class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500" 
                                   min="0" max="100" step="0.01">
                        </div>
                        
                        <div>
                            <label for="gym_cut" class="block text-gray-300 mb-2">Gym Cut (%)</label>
                            <input type="number" name="gym_cut" id="gym_cut" required 
                                   class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500" 
                                   min="0" max="100" step="0.01">
                        </div>
                    </div>
                    
                    <div class="flex justify-end">
                        <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors duration-200">
                            <i class="fas fa-plus mr-2"></i> Add Tier Based Cut-Off
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Existing Tier Based Cutoffs -->
            <div class="p-6 border-t border-gray-700">
                <h3 class="text-lg font-semibold text-white mb-4">Existing Tier Based Cutoffs</h3>
                
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
                                            <form method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete this tier-based cutoff?');">
                                                <input type="hidden" name="cutoff_id" value="<?= $cutoff['id'] ?>">
                                                <button type="submit" name="delete_tier_cutoff" class="text-red-400 hover:text-red-300">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Fee Based Cut-Off Form -->
        <div class="bg-gray-800 rounded-lg shadow-lg overflow-hidden">
            <div class="p-6 border-b border-gray-700">
                <h2 class="text-xl font-semibold text-white flex items-center">
                    <i class="fas fa-money-bill-wave text-green-400 mr-2"></i>
                    Fee Based Cut-Off
                </h2>
                <p class="text-gray-400 mt-1">Define revenue distribution based on membership price ranges</p>
            </div>
            
            <div class="p-6">
                <form method="POST" action="process_cutoff.php" class="space-y-4" id="feeBasedForm">
                    <input type="hidden" name="cut_type" value="fee_based">
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="price_start" class="block text-gray-300 mb-2">Price Range Start (₹)</label>
                            <input type="number" name="price_start" id="price_start" required 
                                   class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-green-500" 
                                   min="0" step="0.01">
                        </div>
                        
                        <div>
                            <label for="price_end" class="block text-gray-300 mb-2">Price Range End (₹)</label>
                            <input type="number" name="price_end" id="price_end" required 
                                   class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-green-500" 
                                   min="0" step="0.01">
                        </div>
                        
                        <div>
                            <label for="admin_cut_fee" class="block text-gray-300 mb-2">Admin Cut (%)</label>
                            <input type="number" name="admin_cut" id="admin_cut_fee" required 
                                   class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-green-500" 
                                   min="0" max="100" step="0.01">
                        </div>
                        
                        <div>
                            <label for="gym_cut_fee" class="block text-gray-300 mb-2">Gym Cut (%)</label>
                            <input type="number" name="gym_cut" id="gym_cut_fee" required 
                                   class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-green-500" 
                                   min="0" max="100" step="0.01">
                        </div>
                    </div>
                    
                    <div class="flex justify-end">
                        <button type="submit" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg transition-colors duration-200">
                            <i class="fas fa-plus mr-2"></i> Add Fee Based Cut-Off
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Existing Fee Based Cutoffs -->
            <div class="p-6 border-t border-gray-700">
                <h3 class="text-lg font-semibold text-white mb-4">Existing Fee Based Cutoffs</h3>
                
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
                                            <form method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete this fee-based cutoff?');">
                                                <input type="hidden" name="cutoff_id" value="<?= $cutoff['id'] ?>">
                                                <button type="submit" name="delete_fee_cutoff" class="text-red-400 hover:text-red-300">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </form>
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
                    <li>Tier 1: Premium memberships with highest revenue share</li>
                    <li>Tier 2: Standard memberships with balanced revenue share</li>
                    <li>Tier 3: Basic memberships with lower revenue share</li>
                </ul>
            </div>
            
            <div class="bg-gray-700 rounded-lg p-4">
                <h3 class="text-lg font-semibold text-white mb-2">Fee Based Cut-Off</h3>
                <p class="text-gray-300 mb-3">
                    Fee based cut-offs determine revenue distribution based on the price range of the membership.
                </p>
                <ul class="list-disc list-inside text-gray-300 space-y-1">
                    <li>Higher priced memberships can have different revenue sharing</li>
                    <li>Price ranges should not overlap to avoid conflicts</li>
                    <li>Useful for special promotions or premium offerings</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
    // Validate total percentage equals 100 for tier based form
    document.getElementById('tierBasedForm').addEventListener('submit', function(e) {
        const adminCut = parseFloat(document.getElementById('admin_cut').value);
        const gymCut = parseFloat(document.getElementById('gym_cut').value);
        
        if (adminCut + gymCut !== 100) {
            e.preventDefault();
            alert('Total percentage must equal 100%. Current total: ' + (adminCut + gymCut) + '%');
        }
    });
    
    // Validate total percentage equals 100 for fee based form
    document.getElementById('feeBasedForm').addEventListener('submit', function(e) {
        const adminCut = parseFloat(document.getElementById('admin_cut_fee').value);
        const gymCut = parseFloat(document.getElementById('gym_cut_fee').value);
        
        if (adminCut + gymCut !== 100) {
            e.preventDefault();
            alert('Total percentage must equal 100%. Current total: ' + (adminCut + gymCut) + '%');
        }
        
        // Validate price range
        const priceStart = parseFloat(document.getElementById('price_start').value);
        const priceEnd = parseFloat(document.getElementById('price_end').value);
        
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
</script>

</body>
</html>

