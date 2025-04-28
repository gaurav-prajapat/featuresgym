<?php
// This file is for development purposes only
session_start();
require_once 'config/database.php';

// Check if we're in development mode
$serverName = strtolower($_SERVER['SERVER_NAME']);
if (!($serverName === 'localhost' || $serverName === '127.0.0.1' || strpos($serverName, '.local') !== false)) {
    die("This tool is only available in development environments.");
}

// Initialize database connection
$db = new GymDatabase();
$conn = $db->getConnection();

// Handle actions
$action = $_GET['action'] ?? '';
$message = '';

if ($action === 'view_emails') {
    // View email logs
    $logDir = __DIR__ . '/logs';
    $emailLogs = [];
    
    if (file_exists($logDir)) {
        $files = glob($logDir . '/email_*.log');
        rsort($files); // Most recent first
        
        foreach ($files as $file) {
            $content = file_get_contents($file);
            $emailLogs[basename($file)] = $content;
        }
    }
} elseif ($action === 'toggle_auto_verify') {
    // Toggle auto-verification of OTPs
    $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'dev_auto_verify_otp'");
    $stmt->execute();
    $currentValue = $stmt->fetchColumn();
    
    $newValue = $currentValue == '1' ? '0' : '1';
    
    $stmt = $conn->prepare("
        INSERT INTO system_settings (setting_key, setting_value, setting_group, created_at, updated_at)
        VALUES ('dev_auto_verify_otp', ?, 'development', NOW(), NOW())
        ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()
    ");
    $stmt->execute([$newValue, $newValue]);
    
    $message = "Auto-verification of OTPs is now " . ($newValue == '1' ? 'enabled' : 'disabled');
} elseif ($action === 'view_otps') {
    // View recent OTPs
    $stmt = $conn->prepare("
        SELECT email, otp, created_at, expires_at, 
               IF(expires_at > NOW(), 'Valid', 'Expired') as status
        FROM otp_verifications
        ORDER BY created_at DESC
        LIMIT 20
    ");
    $stmt->execute();
    $otps = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($action === 'test_email') {
    // Send a test email
    require_once 'includes/EmailService.php';
    
    $to = $_POST['email'] ?? '';
    $subject = $_POST['subject'] ?? 'Test Email';
    $message = $_POST['message'] ?? 'This is a test email from Features Gym.';
    
    if (!empty($to)) {
        $emailService = new EmailService();
        $result = $emailService->sendEmail($to, $subject, $message);
        
        $message = $result 
            ? "Test email sent successfully. Check the email logs for details." 
            : "Failed to send test email. Check the error logs.";
    } else {
        $message = "Please provide an email address.";
    }
}

// Get current settings
$stmt = $conn->prepare("
    SELECT setting_key, setting_value 
    FROM system_settings 
    WHERE setting_group = 'development' OR setting_key LIKE 'smtp_%'
");
$stmt->execute();
$settings = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Development Tools - Features Gym</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100 min-h-screen">
    <div class="container mx-auto px-4 py-8">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold text-gray-800">Development Tools</h1>
            <a href="index.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded">Back to Site</a>
        </div>
        
        <?php if (!empty($message)): ?>
        <div class="bg-blue-100 border-l-4 border-blue-500 text-blue-700 p-4 mb-6">
            <p><?= htmlspecialchars($message) ?></p>
        </div>
        <?php endif; ?>
        
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <!-- Navigation Sidebar -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <h2 class="text-xl font-semibold mb-4">Tools</h2>
                <ul class="space-y-2">
                    <li><a href="?action=view_emails" class="text-blue-600 hover:underline">View Email Logs</a></li>
                    <li><a href="?action=view_otps" class="text-blue-600 hover:underline">View Recent OTPs</a></li>
                    <li><a href="?action=toggle_auto_verify" class="text-blue-600 hover:underline">
                        <?= ($settings['dev_auto_verify_otp'] ?? '0') == '1' ? 'Disable' : 'Enable' ?> Auto-Verify OTPs
                    </a></li>
                    <li><a href="?action=test_email" class="text-blue-600 hover:underline">Send Test Email</a></li>
                </ul>
                
                <h2 class="text-xl font-semibold mt-6 mb-4">Current Settings</h2>
                <div class="text-sm">
                    <p><strong>Environment:</strong> Development</p>
                    <p><strong>Auto-Verify OTPs:</strong> <?= ($settings['dev_auto_verify_otp'] ?? '0') == '1' ? 'Enabled' : 'Disabled' ?></p>
                    <p><strong>Log Emails:</strong> <?= ($settings['log_emails'] ?? '0') == '1' ? 'Enabled' : 'Disabled' ?></p>
                    <p><strong>SMTP Host:</strong> <?= htmlspecialchars($settings['smtp_host'] ?? 'Not set') ?></p>
                    <p><strong>SMTP Port:</strong> <?= htmlspecialchars($settings['smtp_port'] ?? 'Not set') ?></p>
                </div>
            </div>
            
            <!-- Main Content Area -->
            <div class="md:col-span-2 bg-white rounded-lg shadow-md p-6">
                <?php if ($action === 'view_emails' && !empty($emailLogs)): ?>
                    <h2 class="text-xl font-semibold mb-4">Email Logs</h2>
                    <div class="space-y-4">
                        <?php foreach ($emailLogs as $filename => $content): ?>
                            <div class="border rounded p-4">
                                <h3 class="font-medium"><?= htmlspecialchars($filename) ?></h3>
                                <pre class="mt-2 bg-gray-100 p-3 rounded text-sm overflow-x-auto"><?= htmlspecialchars($content) ?></pre>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ($action === 'view_emails' && empty($emailLogs)): ?>
                    <div class="text-center py-8">
                        <p class="text-gray-500">No email logs found.</p>
                    </div>
                <?php elseif ($action === 'view_otps'): ?>
                    <h2 class="text-xl font-semibold mb-4">Recent OTPs</h2>
                    <?php if (!empty($otps)): ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full bg-white">
                                <thead>
                                    <tr>
                                        <th class="py-2 px-4 border-b text-left">Email</th>
                                        <th class="py-2 px-4 border-b text-left">OTP</th>
                                        <th class="py-2 px-4 border-b text-left">Created</th>
                                        <th class="py-2 px-4 border-b text-left">Expires</th>
                                        <th class="py-2 px-4 border-b text-left">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($otps as $otp): ?>
                                        <tr>
                                            <td class="py-2 px-4 border-b"><?= htmlspecialchars($otp['email']) ?></td>
                                            <td class="py-2 px-4 border-b font-mono"><?= htmlspecialchars($otp['otp']) ?></td>
                                            <td class="py-2 px-4 border-b"><?= htmlspecialchars($otp['created_at']) ?></td>
                                            <td class="py-2 px-4 border-b"><?= htmlspecialchars($otp['expires_at']) ?></td>
                                            <td class="py-2 px-4 border-b">
                                                <span class="px-2 py-1 rounded text-xs <?= $otp['status'] === 'Valid' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                                    <?= htmlspecialchars($otp['status']) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-8">
                            <p class="text-gray-500">No OTPs found.</p>
                        </div>
                    <?php endif; ?>
                <?php elseif ($action === 'test_email'): ?>
                    <h2 class="text-xl font-semibold mb-4">Send Test Email</h2>
                    <form method="POST" action="?action=test_email" class="space-y-4">
                        <div>
                            <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Recipient Email</label>
                            <input type="email" id="email" name="email" required
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div>
                            <label for="subject" class="block text-sm font-medium text-gray-700 mb-1">Subject</label>
                            <input type="text" id="subject" name="subject" value="Test Email from Features Gym"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div>
                        <label for="message" class="block text-sm font-medium text-gray-700 mb-1">Message</label>
                            <textarea id="message" name="message" rows="5"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">This is a test email from Features Gym.</textarea>
                        </div>
                        <div>
                            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">
                                Send Test Email
                            </button>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="text-center py-12">
                        <h2 class="text-xl font-semibold mb-2">Development Environment</h2>
                        <p class="text-gray-600 mb-6">Select a tool from the sidebar to get started.</p>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 max-w-2xl mx-auto">
                            <div class="border rounded-lg p-4 hover:bg-gray-50">
                                <h3 class="font-medium mb-2">Email Testing</h3>
                                <p class="text-sm text-gray-500 mb-3">In development mode, emails are logged to files instead of being sent.</p>
                                <a href="?action=view_emails" class="text-blue-600 hover:underline text-sm">View Email Logs →</a>
                            </div>
                            
                            <div class="border rounded-lg p-4 hover:bg-gray-50">
                                <h3 class="font-medium mb-2">OTP Verification</h3>
                                <p class="text-sm text-gray-500 mb-3">Enable auto-verification to bypass email OTP checks during testing.</p>
                                <a href="?action=toggle_auto_verify" class="text-blue-600 hover:underline text-sm">
                                    <?= ($settings['dev_auto_verify_otp'] ?? '0') == '1' ? 'Disable' : 'Enable' ?> Auto-Verify →
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
