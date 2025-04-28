<?php
ob_start();
require_once '../config/database.php';
require_once '../includes/PaymentGateway.php';
session_start();

// Check if user is logged in as admin
if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ./login.php');
    exit();
}

$db = new GymDatabase();
$conn = $db->getConnection();

// Get selected gateway
$selectedGateway = isset($_GET['gateway']) ? $_GET['gateway'] : 'razorpay';
$availableGateways = PaymentGateway::getAvailableGateways();

// Validate selected gateway
if (!array_key_exists($selectedGateway, $availableGateways)) {
    $selectedGateway = 'razorpay';
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->beginTransaction();
        
        // Get all form fields
        $fields = $_POST;
        unset($fields['submit']); // Remove submit button from fields
        
        foreach ($fields as $key => $value) {
            // Determine if this is a sensitive field that should be encrypted
            $isSensitive = (strpos($key, 'key_secret') !== false || 
                           strpos($key, 'password') !== false || 
                           strpos($key, 'token') !== false);
            
            // In a production environment, you would encrypt sensitive values
            // $encryptedValue = $isSensitive ? encryptData($value) : $value;
            $encryptedValue = $value; // For demo purposes
            
            $settingKey = $selectedGateway . '_' . $key;
            
            $stmt = $conn->prepare("
                INSERT INTO payment_settings (setting_key, setting_value, is_encrypted, description)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE setting_value = ?, is_encrypted = ?, updated_at = NOW()
            ");
            
            $description = ucfirst(str_replace('_', ' ', $key)) . ' for ' . $availableGateways[$selectedGateway];
            
            $stmt->execute([
                $settingKey, 
                $encryptedValue, 
                $isSensitive ? 1 : 0, 
                $description,
                $encryptedValue,
                $isSensitive ? 1 : 0
            ]);
        }
        
        $conn->commit();
        $_SESSION['success'] = "Payment gateway settings updated successfully!";
        
    } catch (PDOException $e) {
        $conn->rollBack();
        $_SESSION['error'] = "Database error: " . $e->getMessage();
    }
}

// Get current settings for selected gateway
try {
    $stmt = $conn->prepare("
        SELECT setting_key, setting_value, is_encrypted
        FROM payment_settings
        WHERE setting_key LIKE ?
    ");
    $stmt->execute([$selectedGateway . '_%']);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $settings = [];
    foreach ($results as $row) {
        $key = str_replace($selectedGateway . '_', '', $row['setting_key']);
        // In production, you would decrypt sensitive values here
        $settings[$key] = $row['setting_value'];
    }
} catch (PDOException $e) {
    $_SESSION['error'] = "Error loading settings: " . $e->getMessage();
    $settings = [];
}

// Define gateway-specific fields
$gatewayFields = [
    'razorpay' => [
        'key_id' => [
            'label' => 'API Key ID',
            'type' => 'text',
            'required' => true,
            'placeholder' => 'e.g., rzp_live_xxxxxxxxxxxxxxx'
        ],
        'key_secret' => [
            'label' => 'API Key Secret',
            'type' => 'password',
            'required' => true,
            'placeholder' => 'Enter API Key Secret'
        ],
        'account_number' => [
            'label' => 'Razorpay Account Number',
            'type' => 'text',
            'required' => true,
            'placeholder' => 'Your Razorpay account number'
        ],
        'webhook_secret' => [
            'label' => 'Webhook Secret',
            'type' => 'password',
            'required' => false,
            'placeholder' => 'For webhook verification (optional)'
        ],
        'test_mode' => [
            'label' => 'Test Mode',
            'type' => 'checkbox',
            'required' => false,
            'description' => 'Enable test mode (no real transactions)'
        ]
    ],
    'payu' => [
        'merchant_key' => [
            'label' => 'Merchant Key',
            'type' => 'text',
            'required' => true,
            'placeholder' => 'Your PayU merchant key'
        ],
        'merchant_salt' => [
            'label' => 'Merchant Salt',
            'type' => 'password',
            'required' => true,
            'placeholder' => 'Your PayU merchant salt'
        ],
        'auth_header' => [
            'label' => 'Authorization Header',
            'type' => 'text',
            'required' => false,
            'placeholder' => 'For API authentication'
        ],
        'test_mode' => [
            'label' => 'Test Mode',
            'type' => 'checkbox',
            'required' => false,
            'description' => 'Enable test mode (no real transactions)'
        ]
    ],
    'manual' => [
        'notification_email' => [
            'label' => 'Notification Email',
            'type' => 'email',
            'required' => true,
            'placeholder' => 'Email to notify for manual payouts'
        ],
        'auto_notify' => [
            'label' => 'Auto Notify',
            'type' => 'checkbox',
            'required' => false,
            'description' => 'Automatically send email notifications for pending payouts'
        ]
    ]
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Gateway Settings - FlexFit Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body class="bg-gray-900 text-white">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="ml-0 lg:ml-64 p-4 sm:p-6 transition-all duration-200">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold">Payment Gateway Settings</h1>
            <a href="payout_settings.php" class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                <i class="fas fa-arrow-left mr-2"></i> Back to Auto-Payout Settings
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
        
        <!-- Gateway Selector Tabs -->
        <div class="mb-6">
            <div class="flex flex-wrap border-b border-gray-700">
                <?php foreach ($availableGateways as $key => $name): ?>
                    <a href="?gateway=<?= $key ?>" class="px-4 py-2 font-medium text-sm rounded-t-lg <?= $key === $selectedGateway ? 'bg-blue-600 text-white' : 'bg-gray-800 text-gray-400 hover:bg-gray-700' ?>">
                        <?php if ($key === 'razorpay'): ?>
                            <i class="fas fa-money-bill-wave mr-2"></i>
                        <?php elseif ($key === 'payu'): ?>
                            <i class="fas fa-credit-card mr-2"></i>
                        <?php else: ?>
                            <i class="fas fa-hand-holding-usd mr-2"></i>
                        <?php endif; ?>
                        <?= $name ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        
        <div class="bg-gray-800 rounded-xl shadow-lg p-6">
            <form action="?gateway=<?= $selectedGateway ?>" method="POST">
                <h2 class="text-xl font-semibold mb-4"><?= $availableGateways[$selectedGateway] ?> Configuration</h2>
                
                <?php if ($selectedGateway === 'manual'): ?>
                    <div class="bg-gray-700 p-4 rounded-lg mb-6">
                        <div class="flex items-start">
                            <i class="fas fa-info-circle text-blue-400 mt-1 mr-3"></i>
                            <div>
                                <p class="text-white font-medium">Manual Processing Mode</p>
                                <p class="text-gray-300 text-sm mt-1">
                                    In manual mode, the system will not attempt to process payouts automatically.
                                    Instead, it will notify administrators of pending payouts that need manual processing.
                                </p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <?php foreach ($gatewayFields[$selectedGateway] as $fieldName => $field): ?>
                        <div>
                            <?php if ($field['type'] === 'checkbox'): ?>
                                <div class="flex items-center">
                                    <input type="checkbox" id="<?= $fieldName ?>" name="<?= $fieldName ?>" value="1" <?= isset($settings[$fieldName]) && $settings[$fieldName] ? 'checked' : '' ?> class="w-5 h-5 rounded bg-gray-700 border-gray-600 text-blue-600 focus:ring-blue-500">
                                    <label for="<?= $fieldName ?>" class="ml-2 text-sm font-medium text-gray-300"><?= $field['label'] ?></label>
                                </div>
                                <?php if (isset($field['description'])): ?>
                                    <p class="text-xs text-gray-500 mt-1"><?= $field['description'] ?></p>
                                <?php endif; ?>
                            <?php else: ?>
                                <label for="<?= $fieldName ?>" class="block text-sm font-medium text-gray-400 mb-1">
                                    <?= $field['label'] ?> <?= $field['required'] ? '<span class="text-red-500">*</span>' : '' ?>
                                </label>
                                <input 
                                    type="<?= $field['type'] ?>" 
                                    id="<?= $fieldName ?>" 
                                    name="<?= $fieldName ?>" 
                                    value="<?= isset($settings[$fieldName]) ? htmlspecialchars($settings[$fieldName]) : '' ?>" 
                                    placeholder="<?= $field['placeholder'] ?>" 
                                    <?= $field['required'] ? 'required' : '' ?> 
                                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500"
                                >
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="mt-6 flex justify-end">
                    <button type="submit" name="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg transition-colors duration-200">
                        <i class="fas fa-save mr-2"></i> Save Settings
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Test Connection Section -->
        <?php if ($selectedGateway !== 'manual' && !empty($settings)): ?>
        <div class="mt-8">
            <h2 class="text-xl font-semibold mb-4">Test Connection</h2>
            
            <div class="bg-gray-800 rounded-xl shadow-lg p-6">
                <p class="text-gray-300 mb-4">Test your payment gateway connection to ensure it's properly configured.</p>
                
                <button id="testConnectionBtn" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                    <i class="fas fa-plug mr-2"></i> Test Connection
                </button>
                
                <div id="testResult" class="mt-4 hidden"></div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <script>
        // Test connection functionality
        document.getElementById('testConnectionBtn')?.addEventListener('click', function() {
            const resultDiv = document.getElementById('testResult');
            resultDiv.innerHTML = `
                <div class="bg-gray-700 p-4 rounded-lg">
                    <div class="flex items-center">
                        <div class="animate-spin rounded-full h-5 w-5 border-b-2 border-white mr-3"></div>
                        <p>Testing connection to <?= $availableGateways[$selectedGateway] ?>...</p>
                    </div>
                </div>
            `;
            resultDiv.classList.remove('hidden');
            
            // Make AJAX request to test connection
            fetch('ajax_test_gateway.php?gateway=<?= $selectedGateway ?>')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        resultDiv.innerHTML = `
                            <div class="bg-green-900 bg-opacity-50 p-4 rounded-lg">
                                <div class="flex items-start">
                                    <i class="fas fa-check-circle text-green-400 mt-1 mr-3"></i>
                                    <div>
                                        <p class="text-white font-medium">Connection Successful!</p>
                                        <p class="text-green-300 text-sm mt-1">${data.message}</p>
                                    </div>
                                </div>
                            </div>
                        `;
                    } else {
                        resultDiv.innerHTML = `
                            <div class="bg-red-900 bg-opacity-50 p-4 rounded-lg">
                                <div class="flex items-start">
                                    <i class="fas fa-times-circle text-red-400 mt-1 mr-3"></i>
                                    <div>
                                        <p class="text-white font-medium">Connection Failed</p>
                                        <p class="text-red-300 text-sm mt-1">${data.message}</p>
                                    </div>
                                </div>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    resultDiv.innerHTML = `
                        <div class="bg-red-900 bg-opacity-50 p-4 rounded-lg">
                            <div class="flex items-start">
                                <i class="fas fa-times-circle text-red-400 mt-1 mr-3"></i>
                                <div>
                                    <p class="text-white font-medium">Error</p>
                                    <p class="text-red-300 text-sm mt-1">An error occurred while testing the connection.</p>
                                </div>
                            </div>
                        </div>
                    `;
                });
        });
    </script>
</body>
</html>

