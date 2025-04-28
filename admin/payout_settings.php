<?php
ob_start();
require_once '../config/database.php';
session_start();

// Check if user is logged in as admin
if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ./login.php');
    exit();
}

$db = new GymDatabase();
$conn = $db->getConnection();

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->beginTransaction();
        
        // Update auto-payout settings
        $settings = [
            'auto_payout_enabled' => isset($_POST['auto_payout_enabled']) ? 1 : 0,
            'auto_payout_min_hours' => (int)$_POST['auto_payout_min_hours'],
            'auto_payout_max_amount' => (float)$_POST['auto_payout_max_amount'],
            'auto_payout_schedule' => $_POST['auto_payout_schedule'],
            'auto_payout_payment_gateway' => $_POST['auto_payout_payment_gateway']
        ];
        
        foreach ($settings as $key => $value) {
            $stmt = $conn->prepare("
                INSERT INTO system_settings (setting_key, setting_value, updated_at)
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()
            ");
            $stmt->execute([$key, $value, $value]);
        }
        
        $conn->commit();
        $_SESSION['success'] = "Auto-payout settings updated successfully!";
        
    } catch (PDOException $e) {
        $conn->rollBack();
        $_SESSION['error'] = "Database error: " . $e->getMessage();
    }
}

// Get current settings
try {
    $stmt = $conn->prepare("
        SELECT setting_key, setting_value
        FROM system_settings
        WHERE setting_key LIKE 'auto_payout_%'
    ");
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $settings = [];
    foreach ($results as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    
    // Set defaults if not found
    $settings['auto_payout_enabled'] = $settings['auto_payout_enabled'] ?? 0;
    $settings['auto_payout_min_hours'] = $settings['auto_payout_min_hours'] ?? 24;
    $settings['auto_payout_max_amount'] = $settings['auto_payout_max_amount'] ?? 10000;
    $settings['auto_payout_schedule'] = $settings['auto_payout_schedule'] ?? 'daily';
    $settings['auto_payout_payment_gateway'] = $settings['auto_payout_payment_gateway'] ?? 'razorpay';
    
} catch (PDOException $e) {
    $_SESSION['error'] = "Error loading settings: " . $e->getMessage();
    $settings = [
        'auto_payout_enabled' => 0,
        'auto_payout_min_hours' => 24,
        'auto_payout_max_amount' => 10000,
        'auto_payout_schedule' => 'daily',
        'auto_payout_payment_gateway' => 'razorpay'
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auto-Payout Settings - FlexFit Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body class="bg-gray-900 text-white">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="ml-0 lg:ml-64 p-4 sm:p-6 transition-all duration-200">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold">Auto-Payout Settings</h1>
            <a href="payouts.php" class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                <i class="fas fa-arrow-left mr-2"></i> Back to Payouts
            </a>
        </div>
        
        <?php if (isset($_SESSION['success'])): ?>
        <div class="bg-green-600 text-white p-4 rounded-lg mb-6">
            <p><?= htmlspecialchars($_SESSION['success']) ?></p>
            <?php unset($_SESSION['success']); ?>
        </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
        <div class="bg-red-600 text-white p-4 rounded-lg mb-6">
            <p><?= htmlspecialchars($_SESSION['error']) ?></p>
            <?php unset($_SESSION['error']); ?>
        </div>
        <?php endif; ?>
        
        <div class="bg-gray-800 rounded-xl shadow-lg p-6">
            <form action="" method="POST">
                <div class="mb-6">
                    <div class="flex items-center mb-4">
                        <input type="checkbox" id="auto_payout_enabled" name="auto_payout_enabled" value="1" <?= $settings['auto_payout_enabled'] ? 'checked' : '' ?> class="w-5 h-5 rounded bg-gray-700 border-gray-600 text-blue-600 focus:ring-blue-500">
                        <label for="auto_payout_enabled" class="ml-2 text-lg font-medium">Enable Automatic Payouts</label>
                    </div>
                    <p class="text-gray-400 text-sm mb-4">When enabled, the system will automatically process eligible payout requests based on the criteria below.</p>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                    <div>
                        <label for="auto_payout_min_hours" class="block text-sm font-medium text-gray-400 mb-1">Minimum Hours Before Auto-Processing</label>
                        <div class="relative">
                            <input type="number" id="auto_payout_min_hours" name="auto_payout_min_hours" value="<?= htmlspecialchars($settings['auto_payout_min_hours']) ?>" min="1" max="168" class="w-full bg-gray-700 border border-gray-600 rounded-lg pl-3 pr-12 py-2 text-white focus:outline-none focus:border-blue-500">
                            <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none">
                                <span class="text-gray-400">hours</span>
                            </div>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">Wait this many hours after a withdrawal request before auto-processing.</p>
                    </div>
                    
                    <div>
                        <label for="auto_payout_max_amount" class="block text-sm font-medium text-gray-400 mb-1">Maximum Amount for Auto-Processing</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                                <span class="text-gray-400">₹</span>
                            </div>
                            <input type="number" id="auto_payout_max_amount" name="auto_payout_max_amount" value="<?= htmlspecialchars($settings['auto_payout_max_amount']) ?>" min="0" step="100" class="w-full bg-gray-700 border border-gray-600 rounded-lg pl-8 py-2 text-white focus:outline-none focus:border-blue-500">
                        </div>
                        <p class="text-xs text-gray-500 mt-1">Requests above this amount will require manual approval.</p>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                    <div>
                        <label for="auto_payout_schedule" class="block text-sm font-medium text-gray-400 mb-1">Processing Schedule</label>
                        <select id="auto_payout_schedule" name="auto_payout_schedule" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                            <option value="hourly" <?= $settings['auto_payout_schedule'] === 'hourly' ? 'selected' : '' ?>>Hourly</option>
                            <option value="daily" <?= $settings['auto_payout_schedule'] === 'daily' ? 'selected' : '' ?>>Daily (Recommended)</option>
                            <option value="weekly" <?= $settings['auto_payout_schedule'] === 'weekly' ? 'selected' : '' ?>>Weekly</option>
                        </select>
                        <p class="text-xs text-gray-500 mt-1">How often the auto-payout system should run.</p>
                    </div>
                    
                    <div>
                        <label for="auto_payout_payment_gateway" class="block text-sm font-medium text-gray-400 mb-1">Payment Gateway</label>
                        <select id="auto_payout_payment_gateway" name="auto_payout_payment_gateway" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                            <option value="razorpay" <?= $settings['auto_payout_payment_gateway'] === 'razorpay' ? 'selected' : '' ?>>Razorpay</option>
                            <option value="payu" <?= $settings['auto_payout_payment_gateway'] === 'payu' ? 'selected' : '' ?>>PayU</option>
                            <option value="manual" <?= $settings['auto_payout_payment_gateway'] === 'manual' ? 'selected' : '' ?>>Manual (No Automatic Processing)</option>
                        </select>
                        <p class="text-xs text-gray-500 mt-1">Payment gateway to use for automatic payouts.</p>
                    </div>
                </div>
                
                <div class="bg-gray-700 p-4 rounded-lg mb-6">
                    <div class="flex items-start">
                        <i class="fas fa-info-circle text-blue-400 mt-1 mr-3"></i>
                        <div>
                            <p class="text-white font-medium">How Auto-Payouts Work</p>
                            <p class="text-gray-300 text-sm mt-1">
                                The system will automatically process eligible payout requests based on the criteria above. 
                                Only requests that are pending, below the maximum amount, and older than the minimum hours will be processed.
                                For each eligible request, the system will attempt to process the payment through the selected gateway.
                                If successful, the request will be marked as completed. If unsuccessful, the amount will be returned to the gym's balance.
                            </p>
                        </div>
                    </div>
                </div>
                
                <div class="flex justify-end">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg transition-colors duration-200">
                        <i class="fas fa-save mr-2"></i> Save Settings
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Auto-Payout Logs -->
        <div class="mt-8">
            <h2 class="text-xl font-semibold mb-4">Auto-Payout Logs</h2>
            
            <div class="bg-gray-800 rounded-xl shadow-lg overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-700">
                        <thead>
                            <tr class="bg-gray-700">
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Date & Time</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Action</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Details</th>
                            </tr>
                        </thead>
                        <tbody class="bg-gray-800 divide-y divide-gray-700">
                            <?php
                            // Get auto-payout logs
                            try {
                                $stmt = $conn->prepare("
                                    SELECT * FROM activity_logs 
                                    WHERE user_type = 'system' 
                                    AND action LIKE 'auto_payout%'
                                    ORDER BY created_at DESC
                                    LIMIT 20
                                ");
                                $stmt->execute();
                                $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                
                                if (count($logs) > 0) {
                                    foreach ($logs as $log) {
                                        echo '<tr class="hover:bg-gray-700">';
                                        echo '<td class="px-6 py-4 whitespace-nowrap text-sm text-gray-300">' . date('M d, Y h:i A', strtotime($log['created_at'])) . '</td>';
                                        echo '<td class="px-6 py-4 whitespace-nowrap text-sm text-gray-300">' . htmlspecialchars($log['action']) . '</td>';
                                        echo '<td class="px-6 py-4 text-sm text-gray-300">' . htmlspecialchars($log['details']) . '</td>';
                                        echo '</tr>';
                                    }
                                } else {
                                    echo '<tr><td colspan="3" class="px-6 py-4 text-center text-gray-500">No auto-payout logs found.</td></tr>';
                                }
                            } catch (PDOException $e) {
                                echo '<tr><td colspan="3" class="px-6 py-4 text-center text-red-500">Error loading logs: ' . htmlspecialchars($e->getMessage()) . '</td></tr>';
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Toggle dependent fields based on auto-payout enabled status
        const autoPayoutEnabled = document.getElementById('auto_payout_enabled');
        const dependentFields = document.querySelectorAll('#auto_payout_min_hours, #auto_payout_max_amount, #auto_payout_schedule, #auto_payout_payment_gateway');
        
        function toggleDependentFields() {
            const isEnabled = autoPayoutEnabled.checked;
            dependentFields.forEach(field => {
                field.disabled = !isEnabled;
                field.parentElement.classList.toggle('opacity-50', !isEnabled);
            });
        }
        
        // Initial setup
        toggleDependentFields();
        
        // Add event listener
        autoPayoutEnabled.addEventListener('change', toggleDependentFields);
    </script>
</body>
</html>
