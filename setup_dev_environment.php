<?php
// This script sets up the development environment
require_once 'config/database.php';

// Check if we're in development mode
$serverName = strtolower($_SERVER['SERVER_NAME']);
if (!($serverName === 'localhost' || $serverName === '127.0.0.1' || strpos($serverName, '.local') !== false)) {
    die("This script is only available in development environments.");
}

// Initialize database connection
$db = new GymDatabase();
$conn = $db->getConnection();

// Create necessary tables
$tables = [
    // Email logs table
    "CREATE TABLE IF NOT EXISTS `email_logs` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `recipient_email` varchar(255) NOT NULL,
      `subject` varchar(255) NOT NULL,
      `status` enum('attempted','sent','failed') NOT NULL DEFAULT 'attempted',
      `error_message` text DEFAULT NULL,
      `created_at` datetime NOT NULL,
      `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      PRIMARY KEY (`id`),
      KEY `idx_recipient_email` (`recipient_email`),
      KEY `idx_status` (`status`),
      KEY `idx_created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;",
    
    // OTP verifications table
    "CREATE TABLE IF NOT EXISTS `otp_verifications` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `email` varchar(255) NOT NULL,
      `otp` varchar(10) NOT NULL,
      `created_at` datetime NOT NULL,
      `expires_at` datetime NOT NULL,
      `verified` tinyint(1) NOT NULL DEFAULT 0,
      PRIMARY KEY (`id`),
      KEY `idx_email` (`email`),
      KEY `idx_otp` (`otp`),
      KEY `idx_expires_at` (`expires_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;"
];

// Execute table creation queries
$tableResults = [];
foreach ($tables as $query) {
    try {
        $result = $conn->exec($query);
        $tableResults[] = [
            'success' => true,
            'message' => "Table created successfully"
        ];
    } catch (PDOException $e) {
        $tableResults[] = [
            'success' => false,
            'message' => "Error creating table: " . $e->getMessage()
        ];
    }
}

// Add development settings
$settings = [
    ['dev_mode', '1', 'development'],
    ['log_emails', '1', 'development'],
    ['dev_auto_verify_otp', '1', 'development'],
    ['otp_length', '6', 'security'],
    ['otp_expiry_seconds', '600', 'security']
];

$settingResults = [];
foreach ($settings as $setting) {
    try {
        $stmt = $conn->prepare("
            INSERT INTO system_settings (setting_key, setting_value, setting_group, created_at, updated_at)
            VALUES (?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()
        ");
        $stmt->execute([$setting[0], $setting[1], $setting[2], $setting[1]]);
        
        $settingResults[] = [
            'success' => true,
            'message' => "Setting '{$setting[0]}' added/updated successfully"
        ];
    } catch (PDOException $e) {
        $settingResults[] = [
            'success' => false,
            'message' => "Error adding setting '{$setting[0]}': " . $e->getMessage()
        ];
    }
}

// Create logs directory
$logDir = __DIR__ . '/logs';
if (!file_exists($logDir)) {
    mkdir($logDir, 0755, true);
}

// Output results
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup Development Environment - Features Gym</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100 min-h-screen p-8">
    <div class="max-w-3xl mx-auto bg-white rounded-lg shadow-md p-6">
        <h1 class="text-2xl font-bold mb-6">Development Environment Setup</h1>
        
        <div class="mb-8">
            <h2 class="text-xl font-semibold mb-4">Database Tables</h2>
            <div class="space-y-2">
                <?php foreach ($tableResults as $index => $result): ?>
                <div class="p-3 rounded <?= $result['success'] ? 'bg-green-100' : 'bg-red-100' ?>">
                    <p class="<?= $result['success'] ? 'text-green-800' : 'text-red-800' ?>">
                        <?= htmlspecialchars($result['message']) ?>
                    </p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <div class="mb-8">
            <h2 class="text-xl font-semibold mb-4">System Settings</h2>
            <div class="space-y-2">
                <?php foreach ($settingResults as $result): ?>
                <div class="p-3 rounded <?= $result['success'] ? 'bg-green-100' : 'bg-red-100' ?>">
                    <p class="<?= $result['success'] ? 'text-green-800' : 'text-red-800' ?>">
                        <?= htmlspecialchars($result['message']) ?>
                    </p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <div class="mb-8">
            <h2 class="text-xl font-semibold mb-4">File System</h2>
            <div class="p-3 rounded <?= file_exists($logDir) ? 'bg-green-100' : 'bg-red-100' ?>">
                <p class="<?= file_exists($logDir) ? 'text-green-800' : 'text-red-800' ?>">
                    <?= file_exists($logDir) ? "Logs directory created successfully" : "Failed to create logs directory" ?>
                </p>
            </div>
        </div>
        
        <div class="flex justify-between">
            <a href="index.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded">
                Back to Home
            </a>
            <a href="dev_tools.php" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">
                Go to Development Tools
            </a>
        </div>
    </div>
</body>
</html>