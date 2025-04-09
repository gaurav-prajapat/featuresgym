<?php
ob_start();

require_once '../config/database.php';
session_start();

// Check if user is logged in as admin
if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

$db = new GymDatabase();
$conn = $db->getConnection();

// Initialize variables
$message = '';
$error = '';

// Get current settings
$settings = [];
try {
    $settingsQuery = "SELECT setting_key, setting_value FROM system_settings";
    $settingsStmt = $conn->prepare($settingsQuery);
    $settingsStmt->execute();
    
    // Change from FETCH_KEY_PAIR to FETCH_ASSOC and then transform the result
    $settingsRows = $settingsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Transform the result into key-value pairs
    foreach ($settingsRows as $row) {
        if (isset($row['setting_key']) && isset($row['setting_value'])) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }
} catch (PDOException $e) {
    $error = "Error fetching settings: " . $e->getMessage();
}


// If settings table doesn't exist or is empty, initialize with defaults
if (empty($settings)) {
    $settings = [
        'site_name' => 'FlexFit',
        'site_description' => 'Find and book the best gyms near you',
        'contact_email' => 'support@flexfit.com',
        'contact_phone' => '+91 1234567890',
        'address' => '123 Fitness Street, Mumbai, India',
        'currency' => 'INR',
        'currency_symbol' => '₹',
        'tax_rate' => '18',
        'commission_rate' => '10',
        'max_booking_days_advance' => '30',
        'cancellation_policy' => 'Cancellations must be made at least 4 hours before the scheduled time to receive a full refund.',
        'maintenance_mode' => '0',
        'user_registration' => '1',
        'gym_registration' => '1',
        'review_moderation' => '1',
        'default_pagination' => '10',
        'smtp_host' => 'smtp.example.com',
        'smtp_port' => '587',
        'smtp_username' => 'notifications@flexfit.com',
        'smtp_password' => '',
        'smtp_encryption' => 'tls',
        'google_maps_api_key' => '',
        'razorpay_key_id' => '',
        'razorpay_key_secret' => '',
        'facebook_url' => 'https://facebook.com/flexfit',
        'instagram_url' => 'https://instagram.com/flexfit',
        'twitter_url' => 'https://twitter.com/flexfit',
        'youtube_url' => 'https://youtube.com/flexfit',
        'privacy_policy' => 'Default privacy policy text...',
        'terms_conditions' => 'Default terms and conditions text...',
        'about_us' => 'Default about us text...',
        'logo_path' => 'assets/images/logo.png',
        'favicon_path' => 'assets/images/favicon.ico'
    ];
    
    // Insert default settings
    foreach ($settings as $key => $value) {
        $insertQuery = "INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)";
        $insertStmt = $conn->prepare($insertQuery);
        $insertStmt->execute([$key, $value]);
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->beginTransaction();
        
        // General Settings
        if (isset($_POST['update_general'])) {
            $updateFields = [
                'site_name', 'site_description', 'contact_email', 'contact_phone', 
                'address', 'currency', 'currency_symbol', 'maintenance_mode',
                'user_registration', 'gym_registration', 'review_moderation',
                'default_pagination'
            ];
            
            foreach ($updateFields as $field) {
                if (isset($_POST[$field])) {
                    $updateQuery = "UPDATE system_settings SET setting_value = ? WHERE setting_key = ?";
                    $updateStmt = $conn->prepare($updateQuery);
                    $updateStmt->execute([$_POST[$field], $field]);
                    $settings[$field] = $_POST[$field];
                }
            }
            
            // Handle logo upload
if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
    $logoName = 'logo_' . time() . '.' . pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
    
    // Create directory if it doesn't exist
    $uploadDir = __DIR__ . "/../assets/images/";
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    $destination = $uploadDir . $logoName;
    
    // Use the temporary file path from $_FILES array
    if (move_uploaded_file($_FILES['logo']['tmp_name'], $destination)) {
        $updateQuery = "UPDATE system_settings SET setting_value = ? WHERE setting_key = ?";
        $updateStmt = $conn->prepare($updateQuery);
        $updateStmt->execute(['assets/images/' . $logoName, 'logo_path']);
        $settings['logo_path'] = 'assets/images/' . $logoName;
    } else {
        $error = error_get_last();
        $_SESSION['error'] = "Failed to upload logo: " . ($error ? $error['message'] : "Unknown error");
    }
}

// Handle favicon upload
if (isset($_FILES['favicon']) && $_FILES['favicon']['error'] === UPLOAD_ERR_OK) {
    $faviconName = 'favicon_' . time() . '.' . pathinfo($_FILES['favicon']['name'], PATHINFO_EXTENSION);
    
    // Create directory if it doesn't exist
    $uploadDir = __DIR__ . "/../assets/images/";
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    $destination = $uploadDir . $faviconName;
    
    // Use the temporary file path from $_FILES array
    if (move_uploaded_file($_FILES['favicon']['tmp_name'], $destination)) {
        $updateQuery = "UPDATE system_settings SET setting_value = ? WHERE setting_key = ?";
        $updateStmt = $conn->prepare($updateQuery);
        $updateStmt->execute(['assets/images/' . $faviconName, 'favicon_path']);
        $settings['favicon_path'] = 'assets/images/' . $faviconName;
    } else {
        $error = error_get_last();
        $_SESSION['error'] = "Failed to upload favicon: " . ($error ? $error['message'] : "Unknown error");
    }
}
            
            $message = "General settings updated successfully.";
        }
        
        // Financial Settings
        if (isset($_POST['update_financial'])) {
            $updateFields = [
                'tax_rate', 'commission_rate', 'max_booking_days_advance',
                'cancellation_policy', 'razorpay_key_id', 'razorpay_key_secret'
            ];
            
            foreach ($updateFields as $field) {
                if (isset($_POST[$field])) {
                    $updateQuery = "UPDATE system_settings SET setting_value = ? WHERE setting_key = ?";
                    $updateStmt = $conn->prepare($updateQuery);
                    $updateStmt->execute([$_POST[$field], $field]);
                    $settings[$field] = $_POST[$field];
                }
            }
            
            $message = "Financial settings updated successfully.";
        }
        
        // Email Settings
        if (isset($_POST['update_email'])) {
            $updateFields = [
                'smtp_host', 'smtp_port', 'smtp_username', 
                'smtp_password', 'smtp_encryption'
            ];
            
            foreach ($updateFields as $field) {
                if (isset($_POST[$field])) {
                    $updateQuery = "UPDATE system_settings SET setting_value = ? WHERE setting_key = ?";
                    $updateStmt = $conn->prepare($updateQuery);
                    $updateStmt->execute([$_POST[$field], $field]);
                    $settings[$field] = $_POST[$field];
                }
            }
            
            $message = "Email settings updated successfully.";
        }
        
        // API Settings
        if (isset($_POST['update_api'])) {
            $updateFields = ['google_maps_api_key'];
            
            foreach ($updateFields as $field) {
                if (isset($_POST[$field])) {
                    $updateQuery = "UPDATE system_settings SET setting_value = ? WHERE setting_key = ?";
                    $updateStmt = $conn->prepare($updateQuery);
                    $updateStmt->execute([$_POST[$field], $field]);
                    $settings[$field] = $_POST[$field];
                }
            }
            
            $message = "API settings updated successfully.";
        }
        
        // Social Media Settings
        if (isset($_POST['update_social'])) {
            $updateFields = [
                'facebook_url', 'instagram_url', 'twitter_url', 'youtube_url'
            ];
            
            foreach ($updateFields as $field) {
                if (isset($_POST[$field])) {
                    $updateQuery = "UPDATE system_settings SET setting_value = ? WHERE setting_key = ?";
                    $updateStmt = $conn->prepare($updateQuery);
                    $updateStmt->execute([$_POST[$field], $field]);
                    $settings[$field] = $_POST[$field];
                }
            }
            
            $message = "Social media settings updated successfully.";
        }
        
        // Content Settings
        if (isset($_POST['update_content'])) {
            $updateFields = [
                'privacy_policy', 'terms_conditions', 'about_us'
            ];
            
            foreach ($updateFields as $field) {
                if (isset($_POST[$field])) {
                    $updateQuery = "UPDATE system_settings SET setting_value = ? WHERE setting_key = ?";
                    $updateStmt = $conn->prepare($updateQuery);
                    $updateStmt->execute([$_POST[$field], $field]);
                    $settings[$field] = $_POST[$field];
                }
            }
            
            $message = "Content settings updated successfully.";
        }
        
        // Log the activity
        $adminId = $_SESSION['admin_id'];
        $activitySql = "
            INSERT INTO activity_logs (
                user_id, user_type, action, details, ip_address, user_agent
            ) VALUES (
                ?, 'admin', ?, ?, ?, ?
            )
        ";
        $details = "Admin ID: {$adminId} updated system settings";
        $activityStmt = $conn->prepare($activitySql);
        $activityStmt->execute([
            $adminId,
            'update_settings',
            $details,
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT']
        ]);
        
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollBack();
        $error = "Error updating settings: " . $e->getMessage();
    }
}

// Function to get setting value with default fallback
function getSetting($key, $default = '') {
    global $settings;
    return isset($settings[$key]) ? $settings[$key] : $default;
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings - FlexFit Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.tiny.cloud/1/no-api-key/tinymce/5/tinymce.min.js" referrerpolicy="origin"></script>
    <script>
        tinymce.init({
            selector: 'textarea.rich-editor',
            height: 300,
            menubar: false,
            plugins: [
                'advlist autolink lists link image charmap print preview anchor',
                'searchreplace visualblocks code fullscreen',
                'insertdatetime media table paste code help wordcount'
            ],
            toolbar: 'undo redo | formatselect | ' +
                'bold italic backcolor | alignleft aligncenter ' +
                'alignright alignjustify | bullist numlist outdent indent | ' +
                'removeformat | help',
            content_style: 'body { font-family:Helvetica,Arial,sans-serif; font-size:14px }'
        });
    </script>
    <style>
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        .settings-tab.active {
            background-color: #374151;
            color: white;
        }
    </style>
</head>
<body class="bg-gray-900 text-white">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="ml-64 p-8">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl font-bold">System Settings</h1>
        </div>
        
        <?php if (!empty($message)): ?>
            <div class="bg-green-600 text-white p-4 rounded-lg mb-6">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($error)): ?>
            <div class="bg-red-600 text-white p-4 rounded-lg mb-6">
                <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <div class="bg-gray-800 rounded-xl shadow-lg overflow-hidden">
            <div class="flex border-b border-gray-700">
                <button class="settings-tab active px-6 py-4 text-lg font-medium focus:outline-none" data-tab="general">
                    <i class="fas fa-cog mr-2"></i> General
                </button>
                <button class="settings-tab px-6 py-4 text-lg font-medium focus:outline-none" data-tab="financial">
                    <i class="fas fa-money-bill-wave mr-2"></i> Financial
                </button>
                <button class="settings-tab px-6 py-4 text-lg font-medium focus:outline-none" data-tab="email">
                    <i class="fas fa-envelope mr-2"></i> Email
                </button>
                <button class="settings-tab px-6 py-4 text-lg font-medium focus:outline-none" data-tab="api">
                    <i class="fas fa-plug mr-2"></i> API
                </button>
                <button class="settings-tab px-6 py-4 text-lg font-medium focus:outline-none" data-tab="social">
                    <i class="fas fa-share-alt mr-2"></i> Social
                </button>
                <button class="settings-tab px-6 py-4 text-lg font-medium focus:outline-none" data-tab="content">
                    <i class="fas fa-file-alt mr-2"></i> Content
                </button>
            </div>
            
            <!-- General Settings -->
            <div id="general" class="tab-content active p-6">
                <form method="POST" enctype="multipart/form-data" class="space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <h3 class="text-xl font-semibold mb-4">Site Information</h3>
                            
                            <div class="space-y-4">
                                <div>
                                    <label for="site_name" class="block text-sm font-medium text-gray-400 mb-1">Site Name</label>
                                    <input type="text" id="site_name" name="site_name" value="<?php echo getSetting('site_name', 'FlexFit'); ?>"
                                           class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                </div>
                                
                                <div>
                                    <label for="site_description" class="block text-sm font-medium text-gray-400 mb-1">Site Description</label>
                                    <textarea id="site_description" name="site_description" rows="2"
                                              class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500"><?php echo getSetting('site_description'); ?></textarea>
                                </div>
                                
                                <div>
                                    <label for="contact_email" class="block text-sm font-medium text-gray-400 mb-1">Contact Email</label>
                                    <input type="email" id="contact_email" name="contact_email" value="<?php echo getSetting('contact_email'); ?>"
                                           class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                </div>
                                
                                <div>
                                    <label for="contact_phone" class="block text-sm font-medium text-gray-400 mb-1">Contact Phone</label>
                                    <input type="text" id="contact_phone" name="contact_phone" value="<?php echo getSetting('contact_phone'); ?>"
                                           class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                </div>
                                
                                <div>
                                    <label for="address" class="block text-sm font-medium text-gray-400 mb-1">Address</label>
                                    <textarea id="address" name="address" rows="2"
                                              class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500"><?php echo getSetting('address'); ?></textarea>
                                </div>
                            </div>
                        </div>
                        
                        <div>
                            <h3 class="text-xl font-semibold mb-4">System Settings</h3>
                            
                            <div class="space-y-4">
                                <div>
                                    <label for="currency" class="block text-sm font-medium text-gray-400 mb-1">Currency</label>
                                    <select id="currency" name="currency"
                                            class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                        <option value="INR" <?php echo getSetting('currency') === 'INR' ? 'selected' : ''; ?>>Indian Rupee (INR)</option>
                                        <option value="USD" <?php echo getSetting('currency') === 'USD' ? 'selected' : ''; ?>>US Dollar (USD)</option>
                                        <option value="EUR" <?php echo getSetting('currency') === 'EUR' ? 'selected' : ''; ?>>Euro (EUR)</option>
                                        <option value="GBP" <?php echo getSetting('currency') === 'GBP' ? 'selected' : ''; ?>>British Pound (GBP)</option>
                                    </select>
                                </div>
                                
                                <div>
                                    <label for="currency_symbol" class="block text-sm font-medium text-gray-400 mb-1">Currency Symbol</label>
                                    <input type="text" id="currency_symbol" name="currency_symbol" value="<?php echo getSetting('currency_symbol', '₹'); ?>"
                                           class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                </div>
                                
                                <div>
                                    <label for="default_pagination" class="block text-sm font-medium text-gray-400 mb-1">Default Pagination</label>
                                    <select id="default_pagination" name="default_pagination"
                                            class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                        <option value="10" <?php echo getSetting('default_pagination') === '10' ? 'selected' : ''; ?>>10 items</option>
                                        <option value="20" <?php echo getSetting('default_pagination') === '20' ? 'selected' : ''; ?>>20 items</option>
                                        <option value="50" <?php echo getSetting('default_pagination') === '50' ? 'selected' : ''; ?>>50 items</option>
                                        <option value="100" <?php echo getSetting('default_pagination') === '100' ? 'selected' : ''; ?>>100 items</option>
                                    </select>
                                </div>
                                
                                <div class="flex items-center">
                                    <input type="checkbox" id="maintenance_mode" name="maintenance_mode" value="1" <?php echo getSetting('maintenance_mode') === '1' ? 'checked' : ''; ?>
                                           class="w-4 h-4 text-yellow-500 border-gray-600 rounded focus:ring-yellow-500">
                                    <label for="maintenance_mode" class="ml-2 text-sm font-medium text-gray-300">Maintenance Mode</label>
                                </div>
                                
                                <div class="flex items-center">
                                    <input type="checkbox" id="user_registration" name="user_registration" value="1" <?php echo getSetting('user_registration') === '1' ? 'checked' : ''; ?>
                                           class="w-4 h-4 text-yellow-500 border-gray-600 rounded focus:ring-yellow-500">
                                    <label for="user_registration" class="ml-2 text-sm font-medium text-gray-300">Allow User Registration</label>
                                </div>
                                
                                <div class="flex items-center">
                                    <input type="checkbox" id="gym_registration" name="gym_registration" value="1" <?php echo getSetting('gym_registration') === '1' ? 'checked' : ''; ?>
                                           class="w-4 h-4 text-yellow-500 border-gray-600 rounded focus:ring-yellow-500">
                                    <label for="gym_registration" class="ml-2 text-sm font-medium text-gray-300">Allow Gym Registration</label>
                                </div>
                                
                                <div class="flex items-center">
                                    <input type="checkbox" id="review_moderation" name="review_moderation" value="1" <?php echo getSetting('review_moderation') === '1' ? 'checked' : ''; ?>
                                           class="w-4 h-4 text-yellow-500 border-gray-600 rounded focus:ring-yellow-500">
                                    <label for="review_moderation" class="ml-2 text-sm font-medium text-gray-300">Enable Review Moderation</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-6">
                        <div>
                            <h3 class="text-xl font-semibold mb-4">Logo & Favicon</h3>
                            
                            <div class="space-y-4">
                                <div>
                                    <label for="logo" class="block text-sm font-medium text-gray-400 mb-1">Site Logo</label>
                                    <div class="flex items-center space-x-4">
                                        <img src="../<?php echo getSetting('logo_path', 'assets/images/logo.png'); ?>" alt="Site Logo" class="h-12 bg-gray-700 p-1 rounded">
                                        <input type="file" id="logo" name="logo" accept="image/*"
                                               class="w-full bg-gray-700 border border-gray-600 rounded-lg p-2 text-white">
                                    </div>
                                </div>
                                
                                <div>
                                    <label for="favicon" class="block text-sm font-medium text-gray-400 mb-1">Favicon</label>
                                    <div class="flex items-center space-x-4">
                                        <img src="../<?php echo getSetting('favicon_path', 'assets/images/favicon.ico'); ?>" alt="Favicon" class="h-8 bg-gray-700 p-1 rounded">
                                        <input type="file" id="favicon" name="favicon" accept="image/*"
                                               class="w-full bg-gray-700 border border-gray-600 rounded-lg p-2 text-white">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="flex justify-end">
                        <button type="submit" name="update_general" class="bg-yellow-500 hover:bg-yellow-600 text-black font-bold py-2 px-4 rounded-lg transition-colors duration-200">
                            Save General Settings
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Financial Settings -->
            <div id="financial" class="tab-content p-6">
                <form method="POST" class="space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <h3 class="text-xl font-semibold mb-4">Financial Settings</h3>
                            
                            <div class="space-y-4">
                                <div>
                                    <label for="tax_rate" class="block text-sm font-medium text-gray-400 mb-1">Tax Rate (%)</label>
                                    <input type="number" id="tax_rate" name="tax_rate" value="<?php echo getSetting('tax_rate', '18'); ?>" min="0" max="100" step="0.01"
                                           class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                </div>
                                
                                <div>
                                    <label for="commission_rate" class="block text-sm font-medium text-gray-400 mb-1">Commission Rate (%)</label>
                                    <input type="number" id="commission_rate" name="commission_rate" value="<?php echo getSetting('commission_rate', '10'); ?>" min="0" max="100" step="0.01"
                                           class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                </div>
                                
                                <div>
                                    <label for="max_booking_days_advance" class="block text-sm font-medium text-gray-400 mb-1">Max Booking Days in Advance</label>
                                    <input type="number" id="max_booking_days_advance" name="max_booking_days_advance" value="<?php echo getSetting('max_booking_days_advance', '30'); ?>" min="1" max="365"
                                           class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                </div>
                            </div>
                        </div>
                        
                        <div>
                            <h3 class="text-xl font-semibold mb-4">Payment Gateway</h3>
                            
                            <div class="space-y-4">
                                <div>
                                    <label for="razorpay_key_id" class="block text-sm font-medium text-gray-400 mb-1">Razorpay Key ID</label>
                                    <input type="text" id="razorpay_key_id" name="razorpay_key_id" value="<?php echo getSetting('razorpay_key_id'); ?>"
                                           class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                </div>
                                
                                <div>
                                    <label for="razorpay_key_secret" class="block text-sm font-medium text-gray-400 mb-1">Razorpay Key Secret</label>
                                    <input type="password" id="razorpay_key_secret" name="razorpay_key_secret" value="<?php echo getSetting('razorpay_key_secret'); ?>"
                                           class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div>
                        <h3 class="text-xl font-semibold mb-4">Cancellation Policy</h3>
                        
                        <div>
                            <label for="cancellation_policy" class="block text-sm font-medium text-gray-400 mb-1">Default Cancellation Policy</label>
                            <textarea id="cancellation_policy" name="cancellation_policy" rows="4"
                                      class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500"><?php echo getSetting('cancellation_policy'); ?></textarea>
                        </div>
                    </div>
                    
                    <div class="flex justify-end">
                        <button type="submit" name="update_financial" class="bg-yellow-500 hover:bg-yellow-600 text-black font-bold py-2 px-4 rounded-lg transition-colors duration-200">
                            Save Financial Settings
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Email Settings -->
            <div id="email" class="tab-content p-6">
                <form method="POST" class="space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <h3 class="text-xl font-semibold mb-4">SMTP Configuration</h3>
                            
                            <div class="space-y-4">
                                <div>
                                    <label for="smtp_host" class="block text-sm font-medium text-gray-400 mb-1">SMTP Host</label>
                                    <input type="text" id="smtp_host" name="smtp_host" value="<?php echo getSetting('smtp_host'); ?>"
                                           class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                </div>
                                
                                <div>
                                    <label for="smtp_port" class="block text-sm font-medium text-gray-400 mb-1">SMTP Port</label>
                                    <input type="number" id="smtp_port" name="smtp_port" value="<?php echo getSetting('smtp_port', '587'); ?>"
                                           class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                </div>
                                
                                <div>
                                    <label for="smtp_username" class="block text-sm font-medium text-gray-400 mb-1">SMTP Username</label>
                                    <input type="text" id="smtp_username" name="smtp_username" value="<?php echo getSetting('smtp_username'); ?>"
                                           class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                </div>
                                
                                <div>
                                    <label for="smtp_password" class="block text-sm font-medium text-gray-400 mb-1">SMTP Password</label>
                                    <input type="password" id="smtp_password" name="smtp_password" value="<?php echo getSetting('smtp_password'); ?>"
                                           class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                </div>
                                
                                <div>
                                    <label for="smtp_encryption" class="block text-sm font-medium text-gray-400 mb-1">Encryption</label>
                                    <select id="smtp_encryption" name="smtp_encryption"
                                            class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
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
                                        <input type="email" id="test_email" name="test_email" placeholder="Enter email address"
                                               class="w-full bg-gray-600 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                    </div>
                                    
                                    <button type="button" id="send_test_email" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-lg transition-colors duration-200">
                                        Send Test Email
                                    </button>
                                    
                                    <div id="test_email_result" class="hidden p-3 rounded-lg"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="flex justify-end">
                        <button type="submit" name="update_email" class="bg-yellow-500 hover:bg-yellow-600 text-black font-bold py-2 px-4 rounded-lg transition-colors duration-200">
                            Save Email Settings
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- API Settings -->
            <div id="api" class="tab-content p-6">
                <form method="POST" class="space-y-6">
                    <div>
                        <h3 class="text-xl font-semibold mb-4">Google Maps API</h3>
                        
                        <div class="space-y-4">
                            <div>
                                <label for="google_maps_api_key" class="block text-sm font-medium text-gray-400 mb-1">Google Maps API Key</label>
                                <input type="text" id="google_maps_api_key" name="google_maps_api_key" value="<?php echo getSetting('google_maps_api_key'); ?>"
                                       class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                <p class="text-sm text-gray-400 mt-1">Used for displaying maps and calculating distances.</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="flex justify-end">
                        <button type="submit" name="update_api" class="bg-yellow-500 hover:bg-yellow-600 text-black font-bold py-2 px-4 rounded-lg transition-colors duration-200">
                            Save API Settings
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Social Media Settings -->
            <div id="social" class="tab-content p-6">
                <form method="POST" class="space-y-6">
                    <div>
                        <h3 class="text-xl font-semibold mb-4">Social Media Links</h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="facebook_url" class="block text-sm font-medium text-gray-400 mb-1">
                                    <i class="fab fa-facebook text-blue-500 mr-2"></i> Facebook URL
                                </label>
                                <input type="url" id="facebook_url" name="facebook_url" value="<?php echo getSetting('facebook_url'); ?>"
                                       class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                            </div>
                            
                            <div>
                                <label for="instagram_url" class="block text-sm font-medium text-gray-400 mb-1">
                                    <i class="fab fa-instagram text-pink-500 mr-2"></i> Instagram URL
                                </label>
                                <input type="url" id="instagram_url" name="instagram_url" value="<?php echo getSetting('instagram_url'); ?>"
                                       class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                            </div>
                            
                            <div>
                                <label for="twitter_url" class="block text-sm font-medium text-gray-400 mb-1">
                                    <i class="fab fa-twitter text-blue-400 mr-2"></i> Twitter URL
                                </label>
                                <input type="url" id="twitter_url" name="twitter_url" value="<?php echo getSetting('twitter_url'); ?>"
                                       class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                            </div>
                            
                            <div>
                                <label for="youtube_url" class="block text-sm font-medium text-gray-400 mb-1">
                                    <i class="fab fa-youtube text-red-500 mr-2"></i> YouTube URL
                                </label>
                                <input type="url" id="youtube_url" name="youtube_url" value="<?php echo getSetting('youtube_url'); ?>"
                                       class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                            </div>
                        </div>
                    </div>
                    
                    <div class="flex justify-end">
                        <button type="submit" name="update_social" class="bg-yellow-500 hover:bg-yellow-600 text-black font-bold py-2 px-4 rounded-lg transition-colors duration-200">
                            Save Social Media Settings
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Content Settings -->
            <div id="content" class="tab-content p-6">
                <form method="POST" class="space-y-6">
                    <div>
                        <h3 class="text-xl font-semibold mb-4">Legal Pages</h3>
                        
                        <div class="space-y-6">
                            <div>
                                <label for="privacy_policy" class="block text-sm font-medium text-gray-400 mb-1">Privacy Policy</label>
                                <textarea id="privacy_policy" name="privacy_policy" class="rich-editor w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500"><?php echo getSetting('privacy_policy'); ?></textarea>
                            </div>
                            
                            <div>
                                <label for="terms_conditions" class="block text-sm font-medium text-gray-400 mb-1">Terms & Conditions</label>
                                <textarea id="terms_conditions" name="terms_conditions" class="rich-editor w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500"><?php echo getSetting('terms_conditions'); ?></textarea>
                            </div>
                            
                            <div>
                                <label for="about_us" class="block text-sm font-medium text-gray-400 mb-1">About Us</label>
                                <textarea id="about_us" name="about_us" class="rich-editor w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500"><?php echo getSetting('about_us'); ?></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <div class="flex justify-end">
                        <button type="submit" name="update_content" class="bg-yellow-500 hover:bg-yellow-600 text-black font-bold py-2 px-4 rounded-lg transition-colors duration-200">
                            Save Content Settings
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        // Tab switching functionality
        document.addEventListener('DOMContentLoaded', function() {
            const tabs = document.querySelectorAll('.settings-tab');
            const tabContents = document.querySelectorAll('.tab-content');
            
            tabs.forEach(tab => {
                tab.addEventListener('click', function() {
                    const tabId = this.getAttribute('data-tab');
                    
                    // Hide all tab contents
                    tabContents.forEach(content => {
                        content.classList.remove('active');
                    });
                    
                    // Remove active class from all tabs
                    tabs.forEach(tab => {
                        tab.classList.remove('active');
                    });
                    
                    // Show the selected tab content
                    document.getElementById(tabId).classList.add('active');
                    
                    // Add active class to the clicked tab
                    this.classList.add('active');
                });
            });
            
            // Send test email functionality
            const sendTestEmailBtn = document.getElementById('send_test_email');
            if (sendTestEmailBtn) {
                sendTestEmailBtn.addEventListener('click', function() {
                    const testEmail = document.getElementById('test_email').value;
                    const resultDiv = document.getElementById('test_email_result');
                    
                    if (!testEmail) {
                        resultDiv.textContent = 'Please enter an email address.';
                        resultDiv.classList.add('bg-red-600');
                        resultDiv.classList.remove('bg-green-600', 'hidden');
                        return;
                    }
                    
                    // Show loading state
                    sendTestEmailBtn.disabled = true;
                    sendTestEmailBtn.textContent = 'Sending...';
                    resultDiv.textContent = 'Sending test email...';
                    resultDiv.classList.add('bg-blue-600');
                    resultDiv.classList.remove('bg-red-600', 'bg-green-600', 'hidden');
                    
                    // Send AJAX request
                    fetch('ajax/send_test_email.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'email=' + encodeURIComponent(testEmail) + 
                              '&smtp_host=' + encodeURIComponent(document.getElementById('smtp_host').value) +
                              '&smtp_port=' + encodeURIComponent(document.getElementById('smtp_port').value) +
                              '&smtp_username=' + encodeURIComponent(document.getElementById('smtp_username').value) +
                              '&smtp_password=' + encodeURIComponent(document.getElementById('smtp_password').value) +
                              '&smtp_encryption=' + encodeURIComponent(document.getElementById('smtp_encryption').value)
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            resultDiv.textContent = 'Test email sent successfully!';
                            resultDiv.classList.add('bg-green-600');
                            resultDiv.classList.remove('bg-red-600', 'bg-blue-600');
                        } else {
                            resultDiv.textContent = 'Error: ' + data.message;
                            resultDiv.classList.add('bg-red-600');
                            resultDiv.classList.remove('bg-green-600', 'bg-blue-600');
                        }
                    })
                    .catch(error => {
                        resultDiv.textContent = 'Error: ' + error.message;
                        resultDiv.classList.add('bg-red-600');
                        resultDiv.classList.remove('bg-green-600', 'bg-blue-600');
                    })
                    .finally(() => {
                        // Reset button state
                        sendTestEmailBtn.disabled = false;
                        sendTestEmailBtn.textContent = 'Send Test Email';
                    });
                });
            }
            
            // Show warning when enabling maintenance mode
            const maintenanceCheckbox = document.getElementById('maintenance_mode');
            if (maintenanceCheckbox) {
                maintenanceCheckbox.addEventListener('change', function() {
                    if (this.checked) {
                        if (!confirm('Enabling maintenance mode will make the site inaccessible to regular users. Only administrators will be able to access the site. Are you sure you want to continue?')) {
                            this.checked = false;
                        }
                    }
                });
            }
            
            // Currency selection affects currency symbol
            const currencySelect = document.getElementById('currency');
            const currencySymbolInput = document.getElementById('currency_symbol');
            
            if (currencySelect && currencySymbolInput) {
                currencySelect.addEventListener('change', function() {
                    const currency = this.value;
                    let symbol = '₹';
                    
                    switch (currency) {
                        case 'USD':
                            symbol = '$';
                            break;
                        case 'EUR':
                            symbol = '€';
                            break;
                        case 'GBP':
                            symbol = '£';
                            break;
                        case 'INR':
                            symbol = '₹';
                            break;
                    }
                    
                    currencySymbolInput.value = symbol;
                });
            }
            
            // File input preview for logo and favicon
            const fileInputs = document.querySelectorAll('input[type="file"]');
            fileInputs.forEach(input => {
                input.addEventListener('change', function() {
                    if (this.files && this.files[0]) {
                        const img = this.previousElementSibling;
                        const reader = new FileReader();
                        
                        reader.onload = function(e) {
                            img.src = e.target.result;
                        };
                        
                        reader.readAsDataURL(this.files[0]);
                    }
                });
            });
        });
    </script>
</body>
</html>



