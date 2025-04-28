<?php
session_start();
require_once '.././config/database.php';
// include "../includes/navbar.php";
require_once '.././includes/EmailService.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    $_SESSION['error'] = "Please log in to access the admin dashboard.";
    header("Location: login.php");
    exit();
}

$db = new GymDatabase();
$conn = $db->getConnection();

// Initialize variables
$success = '';
$error = '';

// Generate CSRF token if not exists
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Function to get setting value
function getSetting($key, $default = '') {
    global $conn;
    try {
        $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value !== false ? $value : $default;
    } catch (Exception $e) {
        return $default;
    }
}

// Function to update setting
function updateSetting($key, $value, $group = 'general') {
    global $conn;
    try {
        $stmt = $conn->prepare("
                        INSERT INTO system_settings (setting_key, setting_value, setting_group, created_at, updated_at)
            VALUES (?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()
        ");
        return $stmt->execute([$key, $value, $group, $value]);
    } catch (Exception $e) {
        error_log("Failed to update setting: " . $e->getMessage());
        return false;
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "Invalid request. Please try again.";
    } else {
        // Update email settings
        if (isset($_POST['update_email_settings'])) {
            try {
                $conn->beginTransaction();
                
                // Update SMTP settings
                updateSetting('smtp_host', $_POST['smtp_host'], 'email');
                updateSetting('smtp_port', $_POST['smtp_port'], 'email');
                updateSetting('smtp_username', $_POST['smtp_username'], 'email');
                
                // Only update password if provided
                if (!empty($_POST['smtp_password'])) {
                    updateSetting('smtp_password', $_POST['smtp_password'], 'email');
                }
                
                updateSetting('smtp_encryption', $_POST['smtp_encryption'], 'email');
                
                $conn->commit();
                $success = "Email settings updated successfully.";
            } catch (Exception $e) {
                $conn->rollBack();
                $error = "Failed to update email settings: " . $e->getMessage();
            }
        }
        
        // Send test email
        if (isset($_POST['send_test_email'])) {
            $testEmail = $_POST['test_email'] ?? '';
            
            if (empty($testEmail)) {
                $error = "Please enter a test email address.";
            } else if (!filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
                $error = "Please enter a valid email address.";
            } else {
                $emailService = new EmailService();
                
                // Override with form values for testing
                $emailSettings = [
                    'smtp_host' => $_POST['smtp_host'] ?? getSetting('smtp_host'),
                    'smtp_port' => $_POST['smtp_port'] ?? getSetting('smtp_port'),
                    'smtp_username' => $_POST['smtp_username'] ?? getSetting('smtp_username'),
                    'smtp_password' => !empty($_POST['smtp_password']) ? $_POST['smtp_password'] : getSetting('smtp_password'),
                    'smtp_encryption' => $_POST['smtp_encryption'] ?? getSetting('smtp_encryption')
                ];
                
                $emailService->setSmtpSettings($emailSettings);
                
                if ($emailService->sendTestEmail($testEmail)) {
                    $success = "Test email sent successfully to $testEmail. Please check your inbox.";
                } else {
                    $error = "Failed to send test email. Please check your SMTP settings.";
                }
            }
        }
        
        // Update notification settings
        if (isset($_POST['update_notification_settings'])) {
            try {
                $conn->beginTransaction();
                
                // Email notification settings
                updateSetting('admin_email_notifications', isset($_POST['admin_email_notifications']) ? '1' : '0', 'notifications');
                updateSetting('gym_registration_notification', isset($_POST['gym_registration_notification']) ? '1' : '0', 'notifications');
                updateSetting('user_registration_notification', isset($_POST['user_registration_notification']) ? '1' : '0', 'notifications');
                updateSetting('payment_notification', isset($_POST['payment_notification']) ? '1' : '0', 'notifications');
                updateSetting('review_notification', isset($_POST['review_notification']) ? '1' : '0', 'notifications');
                updateSetting('booking_notification', isset($_POST['booking_notification']) ? '1' : '0', 'notifications');
                
                // Balance notification settings
                updateSetting('low_balance_threshold', $_POST['low_balance_threshold'], 'notifications');
                updateSetting('low_balance_notification', isset($_POST['low_balance_notification']) ? '1' : '0', 'notifications');
                
                $conn->commit();
                $success = "Notification settings updated successfully.";
            } catch (Exception $e) {
                $conn->rollBack();
                $error = "Failed to update notification settings: " . $e->getMessage();
            }
        }
        
        // Send test notification
        if (isset($_POST['send_test_notification'])) {
            $testEmail = $_POST['test_email_address'] ?? '';
            
            if (empty($testEmail)) {
                $error = "Please enter a test email address.";
            } else if (!filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
                $error = "Please enter a valid email address.";
            } else {
                $emailService = new EmailService();
                
                if ($emailService->sendTestEmail($testEmail)) {
                    $success = "Test notification sent successfully to $testEmail.";
                    
                    // Also add a notification to the database
                    try {
                        $stmt = $conn->prepare("
                            INSERT INTO notifications (
                                user_id, type, title, message, created_at, status
                            ) VALUES (?, 'test', ?, ?, NOW(), 'unread')
                        ");
                        
                        $stmt->execute([
                            $_SESSION['admin_id'],
                            'Test Notification',
                            'This is a test notification sent from the admin panel.'
                        ]);
                    } catch (Exception $e) {
                        error_log("Failed to create test notification: " . $e->getMessage());
                    }
                } else {
                    $error = "Failed to send test notification. Please check your email settings.";
                }
            }
        }
    }
    
    // Regenerate CSRF token
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $csrf_token = $_SESSION['csrf_token'];
}

// Get recent email logs
$emailLogs = [];
try {
    $stmt = $conn->prepare("
        SELECT * FROM email_logs 
        ORDER BY sent_at DESC 
        LIMIT 10
    ");
    $stmt->execute();
    $emailLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Failed to fetch email logs: " . $e->getMessage());
}

// Page title
$pageTitle = "Email & Notification Settings";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
    </style>
</head>
<body class="bg-gray-900 text-white">
    <div class="container mx-auto px-4 py-8">
        <h1 class="text-3xl font-bold mb-6"><?php echo $pageTitle; ?></h1>
        
        <?php if (!empty($success)): ?>
        <div class="bg-green-600 bg-opacity-25 border-l-4 border-green-500 text-green-100 p-4 mb-6">
            <div class="flex">
                <div class="flex-shrink-0">
                    <i class="fas fa-check-circle text-green-400"></i>
                </div>
                <div class="ml-3">
                    <p><?php echo $success; ?></p>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($error)): ?>
        <div class="bg-red-600 bg-opacity-25 border-l-4 border-red-500 text-red-100 p-4 mb-6">
            <div class="flex">
                <div class="flex-shrink-0">
                    <i class="fas fa-exclamation-circle text-red-400"></i>
                </div>
                <div class="ml-3">
                    <p><?php echo $error; ?></p>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Tabs -->
        <div class="mb-6 border-b border-gray-700">
            <ul class="flex flex-wrap -mb-px">
                <li class="mr-2">
                    <a href="#email" class="tab-link inline-block py-2 px-4 text-yellow-400 border-b-2 border-yellow-400 rounded-t-lg active" data-tab="email">
                        <i class="fas fa-envelope mr-2"></i> Email Settings
                    </a>
                </li>
                <li class="mr-2">
                    <a href="#notifications" class="tab-link inline-block py-2 px-4 text-gray-400 hover:text-white border-b-2 border-transparent rounded-t-lg" data-tab="notifications">
                        <i class="fas fa-bell mr-2"></i> Notification Settings
                    </a>
                </li>
                <li class="mr-2">
                    <a href="#logs" class="tab-link inline-block py-2 px-4 text-gray-400 hover:text-white border-b-2 border-transparent rounded-t-lg" data-tab="logs">
                        <i class="fas fa-history mr-2"></i> Email Logs
                    </a>
                </li>
            </ul>
        </div>
        
        <!-- Email Settings Tab -->
        <div id="email" class="tab-content active p-6">
            <form method="POST" class="space-y-6">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <h3 class="text-xl font-semibold mb-4">SMTP Configuration</h3>

                        <div class="space-y-4">
                            <div>
                                <label for="smtp_host" class="block text-sm font-medium text-gray-400 mb-1">SMTP Host</label>
                                <input type="text" id="smtp_host" name="smtp_host" value="<?php echo getSetting('smtp_host', 'smtp.example.com'); ?>" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                            </div>

                            <div>
                                <label for="smtp_port" class="block text-sm font-medium text-gray-400 mb-1">SMTP Port</label>
                                <input type="number" id="smtp_port" name="smtp_port" value="<?php echo getSetting('smtp_port', '587'); ?>" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                            </div>

                            <div>
                                <label for="smtp_username" class="block text-sm font-medium text-gray-400 mb-1">SMTP Username</label>
                                <input type="text" id="smtp_username" name="smtp_username" value="<?php echo getSetting('smtp_username', 'notifications@flexfit.com'); ?>" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                            </div>

                            <div>
                                <label for="smtp_password" class="block text-sm font-medium text-gray-400 mb-1">SMTP Password</label>
                                <input type="password" id="smtp_password" name="smtp_password" placeholder="••••••••" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                <p class="text-sm text-gray-400 mt-1">Leave blank to keep current password</p>
                            </div>

                            <div>
                                <label for="smtp_encryption" class="block text-sm font-medium text-gray-400 mb-1">Encryption</label>
                                <select id="smtp_encryption" name="smtp_encryption" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                    <option value="tls" <?php echo getSetting('smtp_encryption') === 'tls' ? 'selected' : ''; ?>>TLS</option>
                                    <option value="ssl" <?php echo getSetting('smtp_encryption') === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                                    <option value="none" <?php echo getSetting('smtp_encryption') === 'none' ? 'selected' : ''; ?>>None</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div>
                        <h3 class="text-xl font-semibold mb-4">Email Testing</h3>

                        <div class="bg-gray-700 rounded-lg p-4">
                            <p class="text-gray-300 mb-4">Send a test email to verify your SMTP configuration.</p>

                            <div class="space-y-4">
                                <div>
                                    <label for="test_email" class="block text-sm font-medium text-gray-400 mb-1">Test Email Address</label>
                                    <input type="email" id="test_email" name="test_email" placeholder="Enter email address" class="w-full bg-gray-600 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                </div>

                                <button type="submit" name="send_test_email" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-lg transition-colors duration-200">
                                    <i class="fas fa-paper-plane mr-2"></i> Send Test Email
                                </button>
                            </div>
                        </div>

                        <div class="mt-6 bg-gray-700 rounded-lg p-4">
                            <h4 class="font-medium text-white mb-2">Email Troubleshooting</h4>
                            <ul class="list-disc list-inside text-gray-300 text-sm space-y-1">
                            <li>Make sure your SMTP credentials are correct</li>
                                <li>Check if your email provider allows SMTP access</li>
                                <li>Some providers require app-specific passwords</li>
                                <li>Gmail users may need to enable "Less secure app access"</li>
                                <li>Verify the port is not blocked by your firewall</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end">
                    <button type="submit" name="update_email_settings" class="bg-yellow-500 hover:bg-yellow-600 text-black font-bold py-2 px-4 rounded-lg transition-colors duration-200">
                        Save Email Settings
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Notifications Settings Tab -->
        <div id="notifications" class="tab-content p-6">
            <form method="POST" class="space-y-6">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <h3 class="text-xl font-semibold text-white mb-4">Email Notifications</h3>

                        <div class="space-y-4">
                            <div class="flex items-center">
                                <input type="checkbox" id="admin_email_notifications" name="admin_email_notifications" value="1" <?php echo getSetting('admin_email_notifications', '1') === '1' ? 'checked' : ''; ?> class="w-4 h-4 text-yellow-500 border-gray-600 rounded focus:ring-yellow-500">
                                <label for="admin_email_notifications" class="ml-2 text-sm font-medium text-gray-300">Admin Email Notifications</label>
                            </div>

                            <div class="flex items-center">
                                <input type="checkbox" id="gym_registration_notification" name="gym_registration_notification" value="1" <?php echo getSetting('gym_registration_notification', '1') === '1' ? 'checked' : ''; ?> class="w-4 h-4 text-yellow-500 border-gray-600 rounded focus:ring-yellow-500">
                                <label for="gym_registration_notification" class="ml-2 text-sm font-medium text-gray-300">New Gym Registration Notifications</label>
                            </div>

                            <div class="flex items-center">
                                <input type="checkbox" id="user_registration_notification" name="user_registration_notification" value="1" <?php echo getSetting('user_registration_notification', '1') === '1' ? 'checked' : ''; ?> class="w-4 h-4 text-yellow-500 border-gray-600 rounded focus:ring-yellow-500">
                                <label for="user_registration_notification" class="ml-2 text-sm font-medium text-gray-300">New User Registration Notifications</label>
                            </div>

                            <div class="flex items-center">
                                <input type="checkbox" id="payment_notification" name="payment_notification" value="1" <?php echo getSetting('payment_notification', '1') === '1' ? 'checked' : ''; ?> class="w-4 h-4 text-yellow-500 border-gray-600 rounded focus:ring-yellow-500">
                                <label for="payment_notification" class="ml-2 text-sm font-medium text-gray-300">Payment Notifications</label>
                            </div>

                            <div class="flex items-center">
                                <input type="checkbox" id="review_notification" name="review_notification" value="1" <?php echo getSetting('review_notification', '1') === '1' ? 'checked' : ''; ?> class="w-4 h-4 text-yellow-500 border-gray-600 rounded focus:ring-yellow-500">
                                <label for="review_notification" class="ml-2 text-sm font-medium text-gray-300">New Review Notifications</label>
                            </div>
                            
                            <div class="flex items-center">
                                <input type="checkbox" id="booking_notification" name="booking_notification" value="1" <?php echo getSetting('booking_notification', '1') === '1' ? 'checked' : ''; ?> class="w-4 h-4 text-yellow-500 border-gray-600 rounded focus:ring-yellow-500">
                                <label for="booking_notification" class="ml-2 text-sm font-medium text-gray-300">New Booking Notifications</label>
                            </div>
                        </div>
                    </div>

                    <div>
                        <h3 class="text-xl font-semibold text-white mb-4">Balance Notifications</h3>

                        <div class="space-y-4">
                            <div>
                                <label for="low_balance_threshold" class="block text-sm font-medium text-gray-400 mb-1">Low Balance Threshold (<?php echo getSetting('currency_symbol', '₹'); ?>)</label>
                                <input type="number" id="low_balance_threshold" name="low_balance_threshold" value="<?php echo getSetting('low_balance_threshold', '1000'); ?>" min="0" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                <p class="text-sm text-gray-500 mt-1">Amount at which low balance notifications are triggered</p>
                            </div>

                            <div class="flex items-center">
                                <input type="checkbox" id="low_balance_notification" name="low_balance_notification" value="1" <?php echo getSetting('low_balance_notification', '1') === '1' ? 'checked' : ''; ?> class="w-4 h-4 text-yellow-500 border-gray-600 rounded focus:ring-yellow-500">
                                <label for="low_balance_notification" class="ml-2 text-sm font-medium text-gray-300">Enable Low Balance Notifications</label>
                            </div>
                        </div>

                        <h3 class="text-xl font-semibold text-white mt-6 mb-4">Test Notifications</h3>

                        <div class="bg-gray-700 rounded-lg p-4">
                            <p class="text-gray-300 mb-4">Send a test notification to verify your notification settings.</p>

                            <div class="space-y-4">
                                <div>
                                    <label for="test_email_address" class="block text-sm font-medium text-gray-400 mb-1">Test Email Address</label>
                                    <input type="email" id="test_email_address" name="test_email_address" placeholder="Enter email address" class="w-full bg-gray-600 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                </div>

                                <button type="submit" name="send_test_notification" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-lg transition-colors duration-200">
                                    <i class="fas fa-paper-plane mr-2"></i> Send Test Notification
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end">
                    <button type="submit" name="update_notification_settings" class="bg-yellow-500 hover:bg-yellow-600 text-black font-bold py-2 px-4 rounded-lg transition-colors duration-200">
                        Save Notification Settings
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Email Logs Tab -->
        <div id="logs" class="tab-content p-6">
            <div class="bg-gray-800 rounded-lg overflow-hidden">
                <div class="p-4 border-b border-gray-700">
                    <h3 class="text-xl font-semibold">Recent Email Logs</h3>
                </div>
                
                <?php if (empty($emailLogs)): ?>
                <div class="p-6 text-center text-gray-400">
                    <i class="fas fa-inbox text-4xl mb-3"></i>
                    <p>No email logs found.</p>
                </div>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-700">
                        <thead class="bg-gray-700">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Recipient</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Subject</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Status</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Sent At</th>
                            </tr>
                        </thead>
                        <tbody class="bg-gray-800 divide-y divide-gray-700">
                            <?php foreach ($emailLogs as $log): ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-300"><?php echo htmlspecialchars($log['recipient']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-300"><?php echo htmlspecialchars($log['subject']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php if ($log['status'] === 'sent'): ?>
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Sent</span>
                                    <?php elseif ($log['status'] === 'failed'): ?>
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">Failed</span>
                                    <?php else: ?>
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800"><?php echo ucfirst($log['status']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-300"><?php echo date('Y-m-d H:i:s', strtotime($log['sent_at'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="mt-6 bg-gray-800 rounded-lg p-6">
                <h3 class="text-xl font-semibold mb-4">Email Settings</h3>
                
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    
                    <div class="flex items-center">
                        <input type="checkbox" id="log_emails" name="log_emails" value="1" <?php echo getSetting('log_emails', '1') === '1' ? 'checked' : ''; ?> class="w-4 h-4 text-yellow-500 border-gray-600 rounded focus:ring-yellow-500">
                        <label for="log_emails" class="ml-2 text-sm font-medium text-gray-300">Enable Email Logging</label>
                    </div>
                    
                    <div class="flex items-center">
                        <input type="checkbox" id="dev_mode" name="dev_mode" value="1" <?php echo getSetting('dev_mode', '0') === '1' ? 'checked' : ''; ?> class="w-4 h-4 text-yellow-500 border-gray-600 rounded focus:ring-yellow-500">
                        <label for="dev_mode" class="ml-2 text-sm font-medium text-gray-300">Development Mode (emails will be logged but not sent)</label>
                    </div>
                    
                    <div>
                        <label for="log_retention_days" class="block text-sm font-medium text-gray-400 mb-1">Log Retention (days)</label>
                        <input type="number" id="log_retention_days" name="log_retention_days" value="<?php echo getSetting('log_retention_days', '30'); ?>" min="1" max="365" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                    </div>
                    
                    <div class="flex justify-end">
                        <button type="submit" name="update_log_settings" class="bg-yellow-500 hover:bg-yellow-600 text-black font-bold py-2 px-4 rounded-lg transition-colors duration-200">
                            Save Log Settings
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        // Tab switching functionality
        document.addEventListener('DOMContentLoaded', function() {
            const tabLinks = document.querySelectorAll('.tab-link');
            const tabContents = document.querySelectorAll('.tab-content');
            
            tabLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    
                    // Get the tab to activate
                    const tabId = this.getAttribute('data-tab');
                    
                    // Remove active class from all tabs
                    tabLinks.forEach(link => {
                        link.classList.remove('text-yellow-400', 'border-yellow-400', 'active');
                        link.classList.add('text-gray-400', 'border-transparent');
                    });
                    
                    // Add active class to clicked tab
                    this.classList.add('text-yellow-400', 'border-yellow-400', 'active');
                    this.classList.remove('text-gray-400', 'border-transparent');
                    
                    // Hide all tab contents
                    tabContents.forEach(content => {
                        content.classList.remove('active');
                    });
                    
                    // Show the selected tab content
                    document.getElementById(tabId).classList.add('active');
                });
            });
            
            // Check if there's a hash in the URL
            if (window.location.hash) {
                const tabId = window.location.hash.substring(1);
                const tabLink = document.querySelector(`.tab-link[data-tab="${tabId}"]`);
                
                if (tabLink) {
                    tabLink.click();
                }
            }
        });
        
        // Password visibility toggle
        const passwordField = document.getElementById('smtp_password');
        if (passwordField) {
            const toggleButton = document.createElement('button');
            toggleButton.type = 'button';
            toggleButton.className = 'absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-white';
            toggleButton.innerHTML = '<i class="far fa-eye"></i>';
            toggleButton.addEventListener('click', function() {
                const type = passwordField.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordField.setAttribute('type', type);
                this.innerHTML = type === 'password' ? '<i class="far fa-eye"></i>' : '<i class="far fa-eye-slash"></i>';
            });
            
            // Add the button to the password field container
            passwordField.parentNode.style.position = 'relative';
            passwordField.parentNode.appendChild(toggleButton);
        }
    </script>
</body>
</html>


