<?php
ob_start();
session_start();

// Check if admin is logged in
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
$affected_gyms = 0;

// Get all gyms that don't have policies yet
$stmt = $conn->query("
    SELECT g.gym_id, g.name 
    FROM gyms g
    LEFT JOIN gym_policies gp ON g.gym_id = gp.gym_id
    WHERE gp.id IS NULL
");
$gyms_without_policies = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['insert_policies'])) {
    try {
        // Begin transaction
        $conn->beginTransaction();
        
        // Get selected gyms or all gyms without policies
        $selected_gyms = isset($_POST['selected_gyms']) ? $_POST['selected_gyms'] : [];
        
        // Get policy values from form
        $cancellation_hours = isset($_POST['cancellation_hours']) ? (int)$_POST['cancellation_hours'] : 4;
        $reschedule_hours = isset($_POST['reschedule_hours']) ? (int)$_POST['reschedule_hours'] : 2;
        $cancellation_fee = isset($_POST['cancellation_fee']) ? (float)$_POST['cancellation_fee'] : 200.00;
        $reschedule_fee = isset($_POST['reschedule_fee']) ? (float)$_POST['reschedule_fee'] : 100.00;
        $late_fee = isset($_POST['late_fee']) ? (float)$_POST['late_fee'] : 300.00;
        
        // Prepare insert statement
        $insert_stmt = $conn->prepare("
            INSERT INTO gym_policies (
                gym_id, 
                cancellation_hours, 
                reschedule_hours, 
                cancellation_fee, 
                reschedule_fee, 
                late_fee, 
                is_active
            ) VALUES (?, ?, ?, ?, ?, ?, 1)
        ");
        
        // Insert policies for each selected gym
        foreach ($selected_gyms as $gym_id) {
            $insert_stmt->execute([
                $gym_id,
                $cancellation_hours,
                $reschedule_hours,
                $cancellation_fee,
                $reschedule_fee,
                $late_fee
            ]);
            $affected_gyms++;
        }
        
        // Log the activity
        $stmt = $conn->prepare("
            INSERT INTO activity_logs (
                user_id, user_type, action, details, ip_address, user_agent
            ) VALUES (:user_id, 'admin', 'insert_gym_policies', :details, :ip, :user_agent)
        ");
        $stmt->execute([
            ':user_id' => $_SESSION['admin_id'],
            ':details' => "Inserted policies for $affected_gyms gyms",
            ':ip' => $_SERVER['REMOTE_ADDR'],
            ':user_agent' => $_SERVER['HTTP_USER_AGENT']
        ]);
        
        // Commit transaction
        $conn->commit();
        
        $success_message = "Successfully inserted policies for $affected_gyms gyms.";
    } catch (PDOException $e) {
        // Rollback transaction on error
        $conn->rollBack();
        $error_message = "Error inserting policies: " . $e->getMessage();
    }
}

// Get all gyms that already have policies
$stmt = $conn->query("
    SELECT g.gym_id, g.name, gp.* 
    FROM gyms g
    JOIN gym_policies gp ON g.gym_id = gp.gym_id
    ORDER BY g.name
");
$gyms_with_policies = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Set page title
$page_title = "Insert Gym Policies";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Insert Gym Policies - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body class="bg-gray-900 text-white">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="ml-0 lg:ml-64 p-4 sm:p-6 transition-all duration-200">
        <!-- Header Section -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8">
            <div>
                <h1 class="text-3xl font-bold ">Insert Gym Policies</h1>
                <p class="text-gray-600">Set up cancellation, rescheduling, and late fee policies for gyms</p>
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

        <!-- Main Content -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- Insert Policies Form -->
            <?php if (!empty($gyms_without_policies)): ?>
                <div class="bg-white rounded-xl shadow-md overflow-hidden">
                    <div class="px-6 py-5 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-800">Insert New Policies</h3>
                    </div>
                    <form method="POST" action="" class="p-6">
                        <input type="hidden" name="insert_policies" value="1">
                        
                        <div class="mb-6">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Select Gyms</label>
                            <div class="max-h-60 overflow-y-auto border border-gray-300 rounded-md p-3">
                                <div class="mb-2">
                                    <label class="inline-flex items-center">
                                        <input type="checkbox" id="select-all" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                                        <span class="ml-2 font-medium text-black">Select All</span>
                                    </label>
                                </div>
                                <div class="border-t border-gray-200 pt-2">
                                    <?php foreach ($gyms_without_policies as $gym): ?>
                                        <div class="py-1">
                                            <label class="inline-flex items-center">
                                                <input type="checkbox" name="selected_gyms[]" value="<?= $gym['gym_id'] ?>" class="gym-checkbox rounded border-gray-300 text-black shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                                                <span class="ml-2 text-black"><?= htmlspecialchars($gym['name']) ?> (ID: <?= $gym['gym_id'] ?>)</span>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                            <div>
                                <label for="cancellation_hours" class="block text-sm font-medium text-gray-700 mb-1">Cancellation Hours</label>
                                <input type="number" id="cancellation_hours" name="cancellation_hours" value="4" min="1" max="72" 
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                                <p class="mt-1 text-xs text-gray-500">Hours before scheduled time when cancellation is allowed</p>
                            </div>
                            
                            <div>
                                <label for="reschedule_hours" class="block text-sm font-medium text-gray-700 mb-1">Reschedule Hours</label>
                                <input type="number" id="reschedule_hours" name="reschedule_hours" value="2" min="1" max="48" 
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                                <p class="mt-1 text-xs text-gray-500">Hours before scheduled time when rescheduling is allowed</p>
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                            <div>
                                <label for="cancellation_fee" class="block text-sm font-medium text-gray-700 mb-1">Cancellation Fee (₹)</label>
                                <input type="number" id="cancellation_fee" name="cancellation_fee" value="200.00" min="0" step="0.01" 
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                            </div>
                            
                            <div>
                                <label for="reschedule_fee" class="block text-sm font-medium text-gray-700 mb-1">Reschedule Fee (₹)</label>
                                <input type="number" id="reschedule_fee" name="reschedule_fee" value="100.00" min="0" step="0.01" 
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                            </div>
                            
                            <div>
                                <label for="late_fee" class="block text-sm font-medium text-gray-700 mb-1">Late Fee (₹)</label>
                                <input type="number" id="late_fee" name="late_fee" value="300.00" min="0" step="0.01" 
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                            </div>
                        </div>
                        
                        <div class="flex justify-end">
                            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-6 rounded-lg transition duration-300">
                                <i class="fas fa-save mr-2"></i> Insert Policies
                            </button>
                        </div>
                    </form>
                </div>
            <?php else: ?>
                <div class="bg-white rounded-xl shadow-md overflow-hidden p-6 text-center">
                    <i class="fas fa-check-circle text-green-500 text-5xl mb-4"></i>
                    <h3 class="text-xl font-semibold text-gray-800 mb-2">All Gyms Have Policies</h3>
                    <p class="text-gray-600">
                        All gyms in the system already have policies set up. You can view and edit them in the table.
                    </p>
                </div>
            <?php endif; ?>
            
            <!-- Statistics Card -->
            <div class="bg-white rounded-xl shadow-md overflow-hidden">
                <div class="px-6 py-5 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-800">Policy Statistics</h3>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="bg-blue-50 rounded-lg p-4">
                            <div class="flex items-center">
                                <div class="p-3 rounded-full bg-blue-100 text-blue-500 mr-4">
                                    <i class="fas fa-check-circle text-xl"></i>
                                </div>
                                <div>
                                    <p class="text-sm text-gray-600">Gyms with Policies</p>
                                    <p class="text-2xl font-bold text-gray-800"><?= count($gyms_with_policies) ?></p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="bg-yellow-50 rounded-lg p-4">
                            <div class="flex items-center">
                                <div class="p-3 rounded-full bg-yellow-100 text-yellow-500 mr-4">
                                    <i class="fas fa-exclamation-triangle text-xl"></i>
            </div>                                <div>
                                    <p class="text-sm text-gray-600">Gyms without Policies</p>
                                    <p class="text-2xl font-bold text-gray-800"><?= count($gyms_without_policies) ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-6">
                        <h4 class="text-md font-semibold text-gray-700 mb-3">Average Policy Settings</h4>
                        <?php
                        // Calculate averages if there are gyms with policies
                        if (!empty($gyms_with_policies)) {
                            $total_cancellation_hours = 0;
                            $total_reschedule_hours = 0;
                            $total_cancellation_fee = 0;
                            $total_reschedule_fee = 0;
                            $total_late_fee = 0;
                            
                            foreach ($gyms_with_policies as $gym) {
                                $total_cancellation_hours += $gym['cancellation_hours'];
                                $total_reschedule_hours += $gym['reschedule_hours'];
                                $total_cancellation_fee += $gym['cancellation_fee'];
                                $total_reschedule_fee += $gym['reschedule_fee'];
                                $total_late_fee += $gym['late_fee'];
                            }
                            
                            $count = count($gyms_with_policies);
                            $avg_cancellation_hours = $total_cancellation_hours / $count;
                            $avg_reschedule_hours = $total_reschedule_hours / $count;
                            $avg_cancellation_fee = $total_cancellation_fee / $count;
                            $avg_reschedule_fee = $total_reschedule_fee / $count;
                            $avg_late_fee = $total_late_fee / $count;
                        ?>
                            <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                                <div class="bg-gray-50 p-3 rounded-lg">
                                    <p class="text-xs text-gray-500">Avg. Cancellation Hours</p>
                                    <p class="text-lg font-semibold text-gray-800"><?= number_format($avg_cancellation_hours, 1) ?></p>
                                </div>
                                <div class="bg-gray-50 p-3 rounded-lg">
                                    <p class="text-xs text-gray-500">Avg. Reschedule Hours</p>
                                    <p class="text-lg font-semibold text-gray-800"><?= number_format($avg_reschedule_hours, 1) ?></p>
                                </div>
                                <div class="bg-gray-50 p-3 rounded-lg">
                                    <p class="text-xs text-gray-500">Avg. Cancellation Fee</p>
                                    <p class="text-lg font-semibold text-gray-800">₹<?= number_format($avg_cancellation_fee, 2) ?></p>
                                </div>
                                <div class="bg-gray-50 p-3 rounded-lg">
                                    <p class="text-xs text-gray-500">Avg. Reschedule Fee</p>
                                    <p class="text-lg font-semibold text-gray-800">₹<?= number_format($avg_reschedule_fee, 2) ?></p>
                                </div>
                                <div class="bg-gray-50 p-3 rounded-lg">
                                    <p class="text-xs text-gray-500">Avg. Late Fee</p>
                                    <p class="text-lg font-semibold text-gray-800">₹<?= number_format($avg_late_fee, 2) ?></p>
                                </div>
                            </div>
                        <?php } else { ?>
                            <p class="text-gray-500 italic">No policy data available yet.</p>
                        <?php } ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Existing Policies Table -->
        <?php if (!empty($gyms_with_policies)): ?>
            <div class="mt-8 bg-white rounded-xl shadow-md overflow-hidden">
                <div class="px-6 py-5 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-800">Existing Gym Policies</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead>
                            <tr class="bg-gray-50">
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Gym</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Cancellation</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Reschedule</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Late Fee</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($gyms_with_policies as $gym): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($gym['name']) ?></div>
                                        <div class="text-xs text-gray-500">ID: <?= $gym['gym_id'] ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900"><?= $gym['cancellation_hours'] ?> hours before</div>
                                        <div class="text-xs text-gray-500">Fee: ₹<?= number_format($gym['cancellation_fee'], 2) ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900"><?= $gym['reschedule_hours'] ?> hours before</div>
                                        <div class="text-xs text-gray-500">Fee: ₹<?= number_format($gym['reschedule_fee'], 2) ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900">₹<?= number_format($gym['late_fee'], 2) ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php if ($gym['is_active']): ?>
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                                Active
                                            </span>
                                        <?php else: ?>
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">
                                                Inactive
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <a href="edit_gym_policy.php?id=<?= $gym['id'] ?>" class="text-indigo-600 hover:text-indigo-900 mr-3">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                        <a href="view_gym.php?id=<?= $gym['gym_id'] ?>" class="text-blue-600 hover:text-blue-900">
                                            <i class="fas fa-eye"></i> View Gym
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>

 

    <script>
        // Handle "Select All" checkbox
        document.addEventListener('DOMContentLoaded', function() {
            const selectAllCheckbox = document.getElementById('select-all');
            const gymCheckboxes = document.querySelectorAll('.gym-checkbox');
            
            if (selectAllCheckbox) {
                selectAllCheckbox.addEventListener('change', function() {
                    const isChecked = this.checked;
                    
                    gymCheckboxes.forEach(function(checkbox) {
                        checkbox.checked = isChecked;
                    });
                });
                
                // Update "Select All" checkbox state when individual checkboxes change
                gymCheckboxes.forEach(function(checkbox) {
                    checkbox.addEventListener('change', function() {
                        const allChecked = Array.from(gymCheckboxes).every(cb => cb.checked);
                        const anyChecked = Array.from(gymCheckboxes).some(cb => cb.checked);
                        
                        selectAllCheckbox.checked = allChecked;
                        selectAllCheckbox.indeterminate = anyChecked && !allChecked;
                    });
                });
            }
        });
    </script>
</body>
</html>

