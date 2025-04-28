<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is logged in as admin
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

// Create database connection
$db = new GymDatabase();
$conn = $db->getConnection();

// Generate CSRF token if not exists
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Load all settings from database
$stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings");
$stmt->execute();
$settings = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Process form submissions
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "Security validation failed. Please try again.";
    } else {
        // Handle different form submissions based on action
        if (isset($_POST['update_general_settings'])) {
            try {
                $conn->beginTransaction();
                
                // Update site name
                $site_name = trim($_POST['site_name']);
                updateSetting($conn, 'site_name', $site_name);
                
                // Update site description
                $site_description = trim($_POST['site_description']);
                updateSetting($conn, 'site_description', $site_description);
                
                // Update contact information
                $contact_email = trim($_POST['contact_email']);
                updateSetting($conn, 'contact_email', $contact_email);
                
                $contact_phone = trim($_POST['contact_phone']);
                updateSetting($conn, 'contact_phone', $contact_phone);
                
                $address = trim($_POST['address']);
                updateSetting($conn, 'address', $address);
                
                // Update logo if uploaded
                if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                    $logo_path = uploadImage($_FILES['logo'], '../assets/images/', 'logo');
                    if ($logo_path) {
                        updateSetting($conn, 'logo_path', $logo_path);
                    }
                }
                
                // Update favicon if uploaded
                if (isset($_FILES['favicon']) && $_FILES['favicon']['error'] === UPLOAD_ERR_OK) {
                    $favicon_path = uploadImage($_FILES['favicon'], '../assets/images/', 'favicon');
                    if ($favicon_path) {
                        updateSetting($conn, 'favicon_path', $favicon_path);
                    }
                }
                
                // Update maintenance mode
                $maintenance_mode = isset($_POST['maintenance_mode']) ? '1' : '0';
                updateSetting($conn, 'maintenance_mode', $maintenance_mode);
                
                $conn->commit();
                $message = "General settings updated successfully.";
                
                // Refresh settings array
                $stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings");
                $stmt->execute();
                $settings = [];
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $settings[$row['setting_key']] = $row['setting_value'];
                }
            } catch (Exception $e) {
                $conn->rollBack();
                $error = "Error updating general settings: " . $e->getMessage();
            }
        } elseif (isset($_POST['update_financial_settings'])) {
            try {
                $conn->beginTransaction();
                
                // Update currency
                $currency = trim($_POST['currency']);
                updateSetting($conn, 'currency', $currency);
                
                // Update currency symbol
                $currency_symbol = trim($_POST['currency_symbol']);
                updateSetting($conn, 'currency_symbol', $currency_symbol);
                
                // Update tax rate
                $tax_rate = trim($_POST['tax_rate']);
                updateSetting($conn, 'tax_rate', $tax_rate);
                
                // Update commission rate
                $commission_rate = trim($_POST['commission_rate']);
                updateSetting($conn, 'commission_rate', $commission_rate);
                
                // Update auto payout settings
                $auto_payout_enabled = isset($_POST['auto_payout_enabled']) ? '1' : '0';
                updateSetting($conn, 'auto_payout_enabled', $auto_payout_enabled);
                
                $auto_payout_min_hours = trim($_POST['auto_payout_min_hours']);
                updateSetting($conn, 'auto_payout_min_hours', $auto_payout_min_hours);
                
                $auto_payout_max_amount = trim($_POST['auto_payout_max_amount']);
                updateSetting($conn, 'auto_payout_max_amount', $auto_payout_max_amount);
                
                $auto_payout_schedule = trim($_POST['auto_payout_schedule']);
                updateSetting($conn, 'auto_payout_schedule', $auto_payout_schedule);
                
                $auto_payout_payment_gateway = trim($_POST['auto_payout_payment_gateway']);
                updateSetting($conn, 'auto_payout_payment_gateway', $auto_payout_payment_gateway);
                
                $conn->commit();
                $message = "Financial settings updated successfully.";
                
                // Refresh settings array
                $stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings");
                $stmt->execute();
                $settings = [];
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $settings[$row['setting_key']] = $row['setting_value'];
                }
            } catch (Exception $e) {
                $conn->rollBack();
                $error = "Error updating financial settings: " . $e->getMessage();
            }
        } elseif (isset($_POST['update_email_settings'])) {
            try {
                $conn->beginTransaction();
                
                // Update SMTP settings
                $smtp_host = trim($_POST['smtp_host']);
                updateSetting($conn, 'smtp_host', $smtp_host);
                
                $smtp_port = trim($_POST['smtp_port']);
                updateSetting($conn, 'smtp_port', $smtp_port);
                
                $smtp_username = trim($_POST['smtp_username']);
                updateSetting($conn, 'smtp_username', $smtp_username);
                
                // Only update password if provided
                if (!empty($_POST['smtp_password'])) {
                    $smtp_password = trim($_POST['smtp_password']);
                    updateSetting($conn, 'smtp_password', $smtp_password);
                }
                
                $smtp_encryption = trim($_POST['smtp_encryption']);
                updateSetting($conn, 'smtp_encryption', $smtp_encryption);
                
                $conn->commit();
                $message = "Email settings updated successfully.";
                
                // Refresh settings array
                $stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings");
                $stmt->execute();
                $settings = [];
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $settings[$row['setting_key']] = $row['setting_value'];
                }
            } catch (Exception $e) {
                $conn->rollBack();
                $error = "Error updating email settings: " . $e->getMessage();
            }
        } elseif (isset($_POST['update_api_settings'])) {
            try {
                $conn->beginTransaction();
                
                // Update Google Maps API key
                $google_maps_api_key = trim($_POST['google_maps_api_key']);
                updateSetting($conn, 'google_maps_api_key', $google_maps_api_key);
                
                // Update Razorpay keys
                $razorpay_key_id = trim($_POST['razorpay_key_id']);
                updateSetting($conn, 'razorpay_key_id', $razorpay_key_id);
                
                // Only update secret if provided
                if (!empty($_POST['razorpay_key_secret'])) {
                    $razorpay_key_secret = trim($_POST['razorpay_key_secret']);
                    updateSetting($conn, 'razorpay_key_secret', $razorpay_key_secret);
                }
                
                $conn->commit();
                $message = "API settings updated successfully.";
                
                // Refresh settings array
                $stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings");
                $stmt->execute();
                $settings = [];
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $settings[$row['setting_key']] = $row['setting_value'];
                }
            } catch (Exception $e) {
                $conn->rollBack();
                $error = "Error updating API settings: " . $e->getMessage();
            }
        } elseif (isset($_POST['update_social_settings'])) {
            try {
                $conn->beginTransaction();
                
                // Update social media links
                $facebook_url = trim($_POST['facebook_url']);
                updateSetting($conn, 'facebook_url', $facebook_url);
                
                $instagram_url = trim($_POST['instagram_url']);
                updateSetting($conn, 'instagram_url', $instagram_url);
                
                $twitter_url = trim($_POST['twitter_url']);
                updateSetting($conn, 'twitter_url', $twitter_url);
                
                $youtube_url = trim($_POST['youtube_url']);
                updateSetting($conn, 'youtube_url', $youtube_url);
                
                $conn->commit();
                $message = "Social media settings updated successfully.";
                
                // Refresh settings array
                $stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings");
                $stmt->execute();
                $settings = [];
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $settings[$row['setting_key']] = $row['setting_value'];
                }
            } catch (Exception $e) {
                $conn->rollBack();
                $error = "Error updating social media settings: " . $e->getMessage();
            }
        } elseif (isset($_POST['update_content_settings'])) {
            try {
                $conn->beginTransaction();
                
                // Update privacy policy
                $privacy_policy = $_POST['privacy_policy'];
                updateSetting($conn, 'privacy_policy', $privacy_policy);
                
                // Update terms and conditions
                $terms_conditions = $_POST['terms_conditions'];
                updateSetting($conn, 'terms_conditions', $terms_conditions);
                
                // Update about us
                $about_us = $_POST['about_us'];
                updateSetting($conn, 'about_us', $about_us);
                
                $conn->commit();
                $message = "Content settings updated successfully.";
                
                // Refresh settings array
                $stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings");
                $stmt->execute();
                $settings = [];
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $settings[$row['setting_key']] = $row['setting_value'];
                }
            } catch (Exception $e) {
                $conn->rollBack();
                $error = "Error updating content settings: " . $e->getMessage();
            }
        } elseif (isset($_POST['update_security_settings'])) {
            try {
                $conn->beginTransaction();
                
                // Update security settings
                $login_attempts_limit = trim($_POST['login_attempts_limit']);
                updateSetting($conn, 'login_attempts_limit', $login_attempts_limit);
                
                $account_lockout_time = trim($_POST['account_lockout_time']);
                updateSetting($conn, 'account_lockout_time', $account_lockout_time);
                
                $password_expiry_days = trim($_POST['password_expiry_days']);
                updateSetting($conn, 'password_expiry_days', $password_expiry_days);
                
                $require_2fa_for_admin = isset($_POST['require_2fa_for_admin']) ? '1' : '0';
                updateSetting($conn, 'require_2fa_for_admin', $require_2fa_for_admin);
                
                $session_timeout = trim($_POST['session_timeout']);
                updateSetting($conn, 'session_timeout', $session_timeout);
                
                $allowed_login_ips = trim($_POST['allowed_login_ips']);
                updateSetting($conn, 'allowed_login_ips', $allowed_login_ips);
                
                $conn->commit();
                $message = "Security settings updated successfully.";
                
                // Refresh settings array
                $stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings");
                $stmt->execute();
                $settings = [];
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $settings[$row['setting_key']] = $row['setting_value'];
                }
            } catch (Exception $e) {
                $conn->rollBack();
                $error = "Error updating security settings: " . $e->getMessage();
            }
        } elseif (isset($_POST['update_localization_settings'])) {
            try {
                $conn->beginTransaction();
                
                // Update localization settings
                $default_language = trim($_POST['default_language']);
                updateSetting($conn, 'default_language', $default_language);
                
                $default_timezone = trim($_POST['default_timezone']);
                updateSetting($conn, 'default_timezone', $default_timezone);
                
                $date_format = trim($_POST['date_format']);
                updateSetting($conn, 'date_format', $date_format);
                
                $time_format = trim($_POST['time_format']);
                updateSetting($conn, 'time_format', $time_format);
                
                $default_country = trim($_POST['default_country']);
                updateSetting($conn, 'default_country', $default_country);
                
                $conn->commit();
                $message = "Localization settings updated successfully.";
                
                // Refresh settings array
                $stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings");
                $stmt->execute();
                $settings = [];
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $settings[$row['setting_key']] = $row['setting_value'];
                }
            } catch (Exception $e) {
                $conn->rollBack();
                $error = "Error updating localization settings: " . $e->getMessage();
            }
        } elseif (isset($_POST['update_notification_settings'])) {
            try {
                $conn->beginTransaction();
                
                // Update notification settings
                $admin_email_notifications = isset($_POST['admin_email_notifications']) ? '1' : '0';
                updateSetting($conn, 'admin_email_notifications', $admin_email_notifications);
                
                $gym_registration_notification = isset($_POST['gym_registration_notification']) ? '1' : '0';
                updateSetting($conn, 'gym_registration_notification', $gym_registration_notification);
                
                $user_registration_notification = isset($_POST['user_registration_notification']) ? '1' : '0';
                updateSetting($conn, 'user_registration_notification', $user_registration_notification);
                
                $payment_notification = isset($_POST['payment_notification']) ? '1' : '0';
                updateSetting($conn, 'payment_notification', $payment_notification);
                
                $low_balance_threshold = trim($_POST['low_balance_threshold']);
                updateSetting($conn, 'low_balance_threshold', $low_balance_threshold);
                
                $low_balance_notification = isset($_POST['low_balance_notification']) ? '1' : '0';
                updateSetting($conn, 'low_balance_notification', $low_balance_notification);
                
                $review_notification = isset($_POST['review_notification']) ? '1' : '0';
                updateSetting($conn, 'review_notification', $review_notification);
                
                $conn->commit();
                $message = "Notification settings updated successfully.";
                
                // Refresh settings array
                $stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings");
                $stmt->execute();
                $settings = [];
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $settings[$row['setting_key']] = $row['setting_value'];
                }
            } catch (Exception $e) {
                $conn->rollBack();
                $error = "Error updating notification settings: " . $e->getMessage();
            }
        } elseif (isset($_POST['update_analytics_settings'])) {
            try {
                $conn->beginTransaction();
                
                // Update analytics settings
                $google_analytics_id = trim($_POST['google_analytics_id']);
                updateSetting($conn, 'google_analytics_id', $google_analytics_id);
                
                $facebook_pixel_id = trim($_POST['facebook_pixel_id']);
                updateSetting($conn, 'facebook_pixel_id', $facebook_pixel_id);
                
                $enable_user_tracking = isset($_POST['enable_user_tracking']) ? '1' : '0';
                updateSetting($conn, 'enable_user_tracking', $enable_user_tracking);
                
                $save_user_activity_days = trim($_POST['save_user_activity_days']);
                updateSetting($conn, 'save_user_activity_days', $save_user_activity_days);
                
                $conn->commit();
                $message = "Analytics settings updated successfully.";
                
                // Refresh settings array
                $stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings");
                $stmt->execute();
                $settings = [];
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $settings[$row['setting_key']] = $row['setting_value'];
                }
            } catch (Exception $e) {
                $conn->rollBack();
                $error = "Error updating analytics settings: " . $e->getMessage();
            }
        } elseif (isset($_POST['update_backup_settings'])) {
            try {
                $conn->beginTransaction();
                
                // Update backup settings
                $auto_backup_enabled = isset($_POST['auto_backup_enabled']) ? '1' : '0';
                updateSetting($conn, 'auto_backup_enabled', $auto_backup_enabled);
                
                $backup_frequency = trim($_POST['backup_frequency']);
                updateSetting($conn, 'backup_frequency', $backup_frequency);
                
                $backup_retention_count = trim($_POST['backup_retention_count']);
                updateSetting($conn, 'backup_retention_count', $backup_retention_count);
                
                $backup_include_files = isset($_POST['backup_include_files']) ? '1' : '0';
                updateSetting($conn, 'backup_include_files', $backup_include_files);
                
                $backup_include_database = isset($_POST['backup_include_database']) ? '1' : '0';
                updateSetting($conn, 'backup_include_database', $backup_include_database);
                
                $backup_storage_path = trim($_POST['backup_storage_path']);
                updateSetting($conn, 'backup_storage_path', $backup_storage_path);
                
                $conn->commit();
                $message = "Backup settings updated successfully.";
                
                // Refresh settings array
                $stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings");
                $stmt->execute();
                $settings = [];
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $settings[$row['setting_key']] = $row['setting_value'];
                }
            } catch (Exception $e) {
                $conn->rollBack();
                $error = "Error updating backup settings: " . $e->getMessage();
            }
        } elseif (isset($_POST['update_performance_settings'])) {
            try {
                $conn->beginTransaction();
                
                // Update performance settings
                $enable_caching = isset($_POST['enable_caching']) ? '1' : '0';
                updateSetting($conn, 'enable_caching', $enable_caching);
                
                $cache_expiry = trim($_POST['cache_expiry']);
                updateSetting($conn, 'cache_expiry', $cache_expiry);
                
                $minify_html = isset($_POST['minify_html']) ? '1' : '0';
                updateSetting($conn, 'minify_html', $minify_html);
                
                $minify_css = isset($_POST['minify_css']) ? '1' : '0';
                updateSetting($conn, 'minify_css', $minify_css);
                
                $minify_js = isset($_POST['minify_js']) ? '1' : '0';
                updateSetting($conn, 'minify_js', $minify_js);
                
                $enable_gzip = isset($_POST['enable_gzip']) ? '1' : '0';
                updateSetting($conn, 'enable_gzip', $enable_gzip);
                
                $max_image_upload_size = trim($_POST['max_image_upload_size']);
                updateSetting($conn, 'max_image_upload_size', $max_image_upload_size);
                
                $image_quality = trim($_POST['image_quality']);
                updateSetting($conn, 'image_quality', $image_quality);
                
                // Clear cache if requested
                if (isset($_POST['clear_cache']) && $_POST['clear_cache'] == '1') {
                    clearCache();
                }
                
                $conn->commit();
                $message = "Performance settings updated successfully.";
                
                // Refresh settings array
                $stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings");
                $stmt->execute();
                $settings = [];
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $settings[$row['setting_key']] = $row['setting_value'];
                }
            } catch (Exception $e) {
                $conn->rollBack();
                $error = "Error updating performance settings: " . $e->getMessage();
            }
        } elseif (isset($_POST['send_test_email'])) {
            $test_email = trim($_POST['test_email']);
            
            if (empty($test_email)) {
                $error = "Please enter a valid email address.";
            } else {
                // Load email helper
                if (file_exists('../includes/email_helper.php')) {
                    require_once '../includes/email_helper.php';
                    
                    // Get email settings
                    $email_settings = [
                        'smtp_host' => $settings['smtp_host'] ?? '',
                        'smtp_port' => $settings['smtp_port'] ?? '',
                        'smtp_username' => $settings['smtp_username'] ?? '',
                        'smtp_password' => $settings['smtp_password'] ?? '',
                        'smtp_encryption' => $settings['smtp_encryption'] ?? '',
                        'from_email' => $settings['contact_email'] ?? '',
                        'from_name' => $settings['site_name'] ?? 'FlexFit Gym'
                    ];
                    
                    // Send test email
                    $result = sendTestEmail($email_settings, $test_email);
                    
                    if ($result['success']) {
                        $message = "Test email sent successfully to {$test_email}.";
                    } else {
                        $error = "Failed to send test email: " . $result['message'];
                    }
                } else {
                    $error = "Email helper file not found. Please make sure it exists at ../includes/email_helper.php";
                }
            }
        } elseif (isset($_POST['create_backup'])) {
            try {
                // Create backup directory if it doesn't exist
                $backup_dir = '../' . ($settings['backup_storage_path'] ?? 'backups/');
                if (!file_exists($backup_dir)) {
                    mkdir($backup_dir, 0755, true);
                }
                
                $timestamp = date('Y-m-d_H-i-s');
                $backup_file = $backup_dir . 'flexfit_backup_' . $timestamp . '.sql';
                
                // Get database credentials
                $db_host = $db->getHost();
                $db_name = $db->getDbName();
                $db_user = $db->getUsername();
                $db_pass = $db->getPassword();
                
                // Create database backup command
                $command = "mysqldump --host={$db_host} --user={$db_user} --password={$db_pass} {$db_name} > {$backup_file}";
                
                // Execute backup command
                exec($command, $output, $return_var);
                
                if ($return_var !== 0) {
                    throw new Exception("Database backup failed with error code: {$return_var}");
                }
                
                // Backup files if requested
                if (isset($_POST['backup_include_files']) && $_POST['backup_include_files'] == '1') {
                    $files_backup_dir = $backup_dir . 'files_' . $timestamp . '/';
                    mkdir($files_backup_dir, 0755, true);
                    
                    // Directories to backup
                    $dirs_to_backup = ['assets', 'uploads', 'config'];
                    
                    foreach ($dirs_to_backup as $dir) {
                        $source_path = '../' . $dir;
                        $dest_path = $files_backup_dir . $dir;
                        
                        if (is_dir($source_path)) {
                            recursiveCopy($source_path, $dest_path);
                        }
                    }
                    
                    // Create zip archive
                    $zip_file = $backup_dir . 'files_' . $timestamp . '.zip';
                    $zip = new ZipArchive();
                    
                    if ($zip->open($zip_file, ZipArchive::CREATE) === TRUE) {
                        addDirToZip($files_backup_dir, $zip, strlen($files_backup_dir));
                        $zip->close();
                        
                        // Remove temporary directory
                        recursiveDelete($files_backup_dir);
                    }
                }
                
                // Clean up old backups if needed
                $retention_count = intval($settings['backup_retention_count'] ?? 5);
                if ($retention_count > 0) {
                    $sql_files = glob($backup_dir . 'flexfit_backup_*.sql');
                    $zip_files = glob($backup_dir . 'files_*.zip');
                    
                    // Sort by filename (which includes timestamp)
                    usort($sql_files, function($a, $b) {
                        return strcmp($b, $a); // Descending order
                    });
                    
                    usort($zip_files, function($a, $b) {
                        return strcmp($b, $a); // Descending order
                    });
                    
                    // Remove excess SQL backups
                    if (count($sql_files) > $retention_count) {
                        for ($i = $retention_count; $i < count($sql_files); $i++) {
                            unlink($sql_files[$i]);
                        }
                    }
                    
                    // Remove excess ZIP backups
                    if (count($zip_files) > $retention_count) {
                        for ($i = $retention_count; $i < count($zip_files); $i++) {
                            unlink($zip_files[$i]);
                        }
                    }
                }
                
                $message = "Backup created successfully. Database backup saved to {$backup_file}";
                if (isset($_POST['backup_include_files']) && $_POST['backup_include_files'] == '1') {
                    $message .= " and files backup saved to {$zip_file}";
                }
                $message .= ".";
                
            } catch (Exception $e) {
                $error = "Error creating backup: " . $e->getMessage();
            }
        } elseif (isset($_POST['clear_system_cache'])) {
            try {
                $cache_cleared = clearCache();
                $message = "System cache cleared successfully. {$cache_cleared} cache files removed.";
            } catch (Exception $e) {
                $error = "Error clearing cache: " . $e->getMessage();
            }
        }
    }
}

// Helper function to update a setting
function updateSetting($conn, $key, $value) {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM system_settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    
    if ($stmt->fetchColumn() > 0) {
        $stmt = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?");
        $stmt->execute([$value, $key]);
    } else {
        $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
        $stmt->execute([$key, $value]);
    }
}

// Helper function to upload an image
function uploadImage($file, $target_dir, $prefix = '') {
    // Create directory if it doesn't exist
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0755, true);
    }
    
    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = $prefix . '_' . time() . '.' . $extension;
    $target_file = $target_dir . $filename;
    
    // Move uploaded file
    if (move_uploaded_file($file['tmp_name'], $target_file)) {
        return str_replace('../', '', $target_file); // Return relative path
    }
    
    return false;
}

// Helper function to clear cache
function clearCache() {
    $cache_dir = '../cache/';
    $count = 0;
    
    if (is_dir($cache_dir)) {
        $files = glob($cache_dir . '*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
                $count++;
            }
        }
    }
    
    return $count;
}

    // Helper function to recursively copy directories
    function recursiveCopy($src, $dst) {
        $dir = opendir($src);
        @mkdir($dst, 0755, true);
        
        while (($file = readdir($dir)) !== false) {
            if ($file != '.' && $file != '..') {
                if (is_dir($src . '/' . $file)) {
                    recursiveCopy($src . '/' . $file, $dst . '/' . $file);
                } else {
                    copy($src . '/' . $file, $dst . '/' . $file);
                }
            }
        }
        
        closedir($dir);
    }
    
    // Helper function to recursively delete directories
    function recursiveDelete($dir) {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != "." && $object != "..") {
                    if (is_dir($dir . "/" . $object)) {
                        recursiveDelete($dir . "/" . $object);
                    } else {
                        unlink($dir . "/" . $object);
                    }
                }
            }
            rmdir($dir);
        }
    }
    
    // Helper function to add directory to zip archive
    function addDirToZip($dir, $zipArchive, $exclusiveLength) {
        $handle = opendir($dir);
        while (false !== ($entry = readdir($handle))) {
            if ($entry != "." && $entry != "..") {
                $filePath = "$dir/$entry";
                $localPath = substr($filePath, $exclusiveLength);
                
                if (is_file($filePath)) {
                    $zipArchive->addFile($filePath, $localPath);
                } elseif (is_dir($filePath)) {
                    $zipArchive->addEmptyDir($localPath);
                    addDirToZip($filePath, $zipArchive, $exclusiveLength);
                }
            }
        }
        closedir($handle);
    }
    
    // Helper function to get system information
    function getSystemInfo() {
        global $conn;
        
        $info = [
            'php_version' => phpversion(),
            'mysql_version' => $conn->query('SELECT VERSION()')->fetchColumn(),
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'operating_system' => php_uname('s') . ' ' . php_uname('r'),
            'server_ip' => $_SERVER['SERVER_ADDR'] ?? 'Unknown',
            'max_upload_size' => ini_get('upload_max_filesize'),
            'max_post_size' => ini_get('post_max_size'),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time') . ' seconds',
            'php_extensions' => implode(', ', get_loaded_extensions()),
            'disabled_functions' => ini_get('disable_functions') ?: 'None'
        ];
        
        // Get database size
        try {
            $db_name = $conn->query('SELECT DATABASE()')->fetchColumn();
            $query = "SELECT SUM(data_length + index_length) / 1024 / 1024 AS size_mb 
                     FROM information_schema.TABLES 
                     WHERE table_schema = ?";
            $stmt = $conn->prepare($query);
            $stmt->execute([$db_name]);
            $info['database_size'] = round($stmt->fetchColumn(), 2) . ' MB';
        } catch (Exception $e) {
            $info['database_size'] = 'Unable to determine';
        }
        
        // Get table count
        try {
            $db_name = $conn->query('SELECT DATABASE()')->fetchColumn();
            $query = "SELECT COUNT(*) AS table_count
                     FROM information_schema.TABLES 
                     WHERE table_schema = ?";
            $stmt = $conn->prepare($query);
            $stmt->execute([$db_name]);
            $info['table_count'] = $stmt->fetchColumn();
        } catch (Exception $e) {
            $info['table_count'] = 'Unable to determine';
        }
        
        return $info;
    }    
    // Debug function to check for common issues
    function debugSystemSettings() {
        $debug_info = [];
        
        // Check if required directories exist and are writable
        $directories = [
            '../assets/images/' => 'Asset directory for storing logos and images',
            '../backups/' => 'Backup directory for database and file backups',
            '../cache/' => 'Cache directory for performance optimization',
            '../logs/' => 'Log directory for system logs',
            '../uploads/' => 'Upload directory for user uploads'
        ];
        
        foreach ($directories as $dir => $description) {
            if (!file_exists($dir)) {
                $debug_info[] = [
                    'type' => 'error',
                    'message' => "Directory not found: {$dir}",
                    'description' => $description,
                    'solution' => "Create the directory with: <code>mkdir -p {$dir}</code> and set permissions with: <code>chmod 755 {$dir}</code>"
                ];
            } elseif (!is_writable($dir)) {
                $debug_info[] = [
                    'type' => 'error',
                    'message' => "Directory not writable: {$dir}",
                    'description' => $description,
                    'solution' => "Set proper permissions with: <code>chmod 755 {$dir}</code> or <code>chown www-data:www-data {$dir}</code>"
                ];
            }
        }
        
        // Check if required PHP extensions are installed
        $required_extensions = [
            'pdo' => 'Required for database connections',
            'pdo_mysql' => 'Required for MySQL database connections',
            'gd' => 'Required for image processing',
            'zip' => 'Required for backup functionality',
            'curl' => 'Required for API integrations',
            'mbstring' => 'Required for UTF-8 string handling',
            'json' => 'Required for API responses'
        ];
        
        foreach ($required_extensions as $ext => $description) {
            if (!extension_loaded($ext)) {
                $debug_info[] = [
                    'type' => 'error',
                    'message' => "PHP extension not loaded: {$ext}",
                    'description' => $description,
                    'solution' => "Install the extension with: <code>sudo apt-get install php-{$ext}</code> or enable it in php.ini"
                ];
            }
        }
        
        // Check if email helper file exists
        if (!file_exists('../includes/email_helper.php')) {
            $debug_info[] = [
                'type' => 'error',
                'message' => "Email helper file not found: ../includes/email_helper.php",
                'description' => "Required for sending emails and test email functionality",
                'solution' => "Create the file with the provided email helper code"
            ];
        }
        
        // Check if database tables exist
        global $conn;
        $required_tables = [
            'system_settings' => 'Stores system configuration',
            'users' => 'Stores user accounts',
            'gyms' => 'Stores gym information',
            'activity_logs' => 'Stores system activity logs'
        ];
        
        foreach ($required_tables as $table => $description) {
            try {
                $stmt = $conn->prepare("SHOW TABLES LIKE ?");
                $stmt->execute([$table]);
                if ($stmt->rowCount() == 0) {
                    $debug_info[] = [
                        'type' => 'error',
                        'message' => "Database table not found: {$table}",
                        'description' => $description,
                        'solution' => "Run the database setup script or import the SQL file to create this table"
                    ];
                }
            } catch (Exception $e) {
                $debug_info[] = [
                    'type' => 'error',
                    'message' => "Error checking table {$table}: " . $e->getMessage(),
                    'description' => $description,
                    'solution' => "Check database connection and permissions"
                ];
            }
        }
        
        // Check PHP configuration
        $php_settings = [
            'file_uploads' => ['expected' => '1', 'description' => 'Required for file uploads'],
            'post_max_size' => ['min' => '8M', 'description' => 'Minimum recommended post size'],
            'upload_max_filesize' => ['min' => '2M', 'description' => 'Minimum recommended upload size'],
            'memory_limit' => ['min' => '128M', 'description' => 'Minimum recommended memory limit'],
            'max_execution_time' => ['min' => 30, 'description' => 'Minimum recommended execution time']
        ];
        
        foreach ($php_settings as $setting => $config) {
            if (isset($config['expected'])) {
                if (ini_get($setting) != $config['expected']) {
                    $debug_info[] = [
                        'type' => 'warning',
                        'message' => "PHP setting {$setting} is " . ini_get($setting) . ", expected {$config['expected']}",
                        'description' => $config['description'],
                        'solution' => "Update php.ini to set {$setting} = {$config['expected']}"
                    ];
                }
            } elseif (isset($config['min'])) {
                $current = ini_get($setting);
                $current_value = intval($current);
                $min_value = intval($config['min']);
                
                if (strpos($current, 'M') !== false) {
                    $current_value = intval($current) * 1024 * 1024;
                } elseif (strpos($current, 'G') !== false) {
                    $current_value = intval($current) * 1024 * 1024 * 1024;
                }
                
                if (strpos($config['min'], 'M') !== false) {
                    $min_value = intval($config['min']) * 1024 * 1024;
                } elseif (strpos($config['min'], 'G') !== false) {
                    $min_value = intval($config['min']) * 1024 * 1024 * 1024;
                }
                
                if ($current_value < $min_value) {
                    $debug_info[] = [
                        'type' => 'warning',
                        'message' => "PHP setting {$setting} is {$current}, recommended minimum is {$config['min']}",
                        'description' => $config['description'],
                        'solution' => "Update php.ini to increase {$setting} to at least {$config['min']}"
                    ];
                }
            }
        }
        
        return $debug_info;
    }
    
    // Get system information
    $system_info = getSystemInfo();
    
    // Run diagnostics if debug mode is enabled or if there was an error
    $debug_info = [];
    if (isset($_GET['debug']) || !empty($error)) {
        $debug_info = debugSystemSettings();
    }
    
    // Helper function to get setting value with default
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
    
    <div class="ml-0 lg:ml-64 p-4 sm:p-6 transition-all duration-200">
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-3xl font-bold">System Settings</h1>
                <?php if (isset($_GET['debug'])): ?>
                    <a href="settings.php" class="bg-yellow-500 hover:bg-yellow-600 text-black px-4 py-2 rounded-lg">
                        <i class="fas fa-times-circle mr-2"></i> Exit Debug Mode
                    </a>
                <?php else: ?>
                    <a href="settings.php?debug=1" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-bug mr-2"></i> Debug Mode
                    </a>
                <?php endif; ?>
            </div>
    
            <?php if (!empty($message)): ?>
                <div class="bg-green-600 text-white p-4 rounded-lg mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle mr-2"></i>
                        <span><?php echo $message; ?></span>
                    </div>
                </div>
            <?php endif; ?>
    
            <?php if (!empty($error)): ?>
                <div class="bg-red-600 text-white p-4 rounded-lg mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <span><?php echo $error; ?></span>
                    </div>
                    <div class="mt-2 text-sm">
                        <a href="settings.php?debug=1" class="underline">Click here to run diagnostics</a>
                    </div>
                </div>
            <?php endif; ?>
    
            <?php if (!empty($debug_info)): ?>
                <div class="bg-gray-800 rounded-xl shadow-lg overflow-hidden mb-6">
                    <div class="bg-yellow-600 text-black px-6 py-3">
                        <h2 class="text-xl font-bold"><i class="fas fa-bug mr-2"></i> System Diagnostics</h2>
                    </div>
                    <div class="p-6">
                    <p class="mb-4">The following issues were detected with your system configuration:</p>
                    
                    <div class="space-y-4">
                        <?php foreach ($debug_info as $issue): ?>
                            <div class="bg-gray-700 rounded-lg p-4 border-l-4 <?php echo $issue['type'] === 'error' ? 'border-red-500' : 'border-yellow-500'; ?>">
                                <h3 class="font-bold <?php echo $issue['type'] === 'error' ? 'text-red-400' : 'text-yellow-400'; ?>">
                                    <i class="fas <?php echo $issue['type'] === 'error' ? 'fa-times-circle' : 'fa-exclamation-triangle'; ?> mr-2"></i>
                                    <?php echo $issue['message']; ?>
                                </h3>
                                <p class="text-gray-300 mt-1"><?php echo $issue['description']; ?></p>
                                <div class="mt-2 bg-gray-800 p-3 rounded">
                                    <p class="text-sm text-gray-400 mb-1">Solution:</p>
                                    <p class="text-white"><?php echo $issue['solution']; ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="bg-gray-800 rounded-xl shadow-lg overflow-hidden">
            <div class="flex border-b border-gray-700 overflow-x-auto">
                <button class="settings-tab active px-6 py-4 text-lg font-medium focus:outline-none whitespace-nowrap" data-tab="general">
                    <i class="fas fa-cog mr-2"></i> General
                </button>
                <button class="settings-tab px-6 py-4 text-lg font-medium focus:outline-none whitespace-nowrap" data-tab="financial">
                    <i class="fas fa-money-bill-wave mr-2"></i> Financial
                </button>
                <button class="settings-tab px-6 py-4 text-lg font-medium focus:outline-none whitespace-nowrap" data-tab="email">
                    <i class="fas fa-envelope mr-2"></i> Email
                </button>
                <button class="settings-tab px-6 py-4 text-lg font-medium focus:outline-none whitespace-nowrap" data-tab="api">
                    <i class="fas fa-plug mr-2"></i> API
                </button>
                <button class="settings-tab px-6 py-4 text-lg font-medium focus:outline-none whitespace-nowrap" data-tab="social">
                    <i class="fas fa-share-alt mr-2"></i> Social
                </button>
                <button class="settings-tab px-6 py-4 text-lg font-medium focus:outline-none whitespace-nowrap" data-tab="content">
                    <i class="fas fa-file-alt mr-2"></i> Content
                </button>
                <button class="settings-tab px-6 py-4 text-lg font-medium focus:outline-none whitespace-nowrap" data-tab="security">
                    <i class="fas fa-shield-alt mr-2"></i> Security
                </button>
                <button class="settings-tab px-6 py-4 text-lg font-medium focus:outline-none whitespace-nowrap" data-tab="localization">
                    <i class="fas fa-globe mr-2"></i> Localization
                </button>
                <button class="settings-tab px-6 py-4 text-lg font-medium focus:outline-none whitespace-nowrap" data-tab="notifications">
                    <i class="fas fa-bell mr-2"></i> Notifications
                </button>
                <button class="settings-tab px-6 py-4 text-lg font-medium focus:outline-none whitespace-nowrap" data-tab="analytics">
                    <i class="fas fa-chart-line mr-2"></i> Analytics
                </button>
                <button class="settings-tab px-6 py-4 text-lg font-medium focus:outline-none whitespace-nowrap" data-tab="backup">
                    <i class="fas fa-database mr-2"></i> Backup
                </button>
                <button class="settings-tab px-6 py-4 text-lg font-medium focus:outline-none whitespace-nowrap" data-tab="performance">
                    <i class="fas fa-tachometer-alt mr-2"></i> Performance
                </button>
                <button class="settings-tab px-6 py-4 text-lg font-medium focus:outline-none whitespace-nowrap" data-tab="system">
                    <i class="fas fa-server mr-2"></i> System Info
                </button>
            </div>

            <!-- General Settings -->
            <div id="general" class="tab-content active p-6">
                <form method="POST" enctype="multipart/form-data" class="space-y-6">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <h3 class="text-xl font-semibold mb-4">Site Information</h3>

                            <div class="space-y-4">
                                <div>
                                    <label for="site_name" class="block text-sm font-medium text-gray-400 mb-1">Site Name</label>
                                    <input type="text" id="site_name" name="site_name" value="<?php echo getSetting('site_name', 'FlexFit'); ?>" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                </div>

                                <div>
                                    <label for="site_description" class="block text-sm font-medium text-gray-400 mb-1">Site Description</label>
                                    <textarea id="site_description" name="site_description" rows="2" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500"><?php echo getSetting('site_description', 'Find and book the best gyms near you'); ?></textarea>
                                </div>

                                <div>
                                    <label for="contact_email" class="block text-sm font-medium text-gray-400 mb-1">Contact Email</label>
                                    <input type="email" id="contact_email" name="contact_email" value="<?php echo getSetting('contact_email', 'support@flexfit.com'); ?>" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                </div>

                                <div>
                                    <label for="contact_phone" class="block text-sm font-medium text-gray-400 mb-1">Contact Phone</label>
                                    <input type="text" id="contact_phone" name="contact_phone" value="<?php echo getSetting('contact_phone', '+91 1234567890'); ?>" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                </div>

                                <div>
                                    <label for="address" class="block text-sm font-medium text-gray-400 mb-1">Address</label>
                                    <textarea id="address" name="address" rows="2" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500"><?php echo getSetting('address', '123 Fitness Street, Mumbai, India'); ?></textarea>
                                </div>
                            </div>
                        </div>

                        <div>
                            <h3 class="text-xl font-semibold mb-4">System Settings</h3>

                            <div class="space-y-4">
                                <div class="flex items-center">
                                    <input type="checkbox" id="maintenance_mode" name="maintenance_mode" value="1" <?php echo getSetting('maintenance_mode') === '1' ? 'checked' : ''; ?> class="w-4 h-4 text-yellow-500 border-gray-600 rounded focus:ring-yellow-500">
                                    <label for="maintenance_mode" class="ml-2 text-sm font-medium text-gray-300">Maintenance Mode</label>
                                </div>
                                <p class="text-sm text-gray-400 ml-6">When enabled, only administrators can access the site.</p>

                                <div>
                                    <label for="logo" class="block text-sm font-medium text-gray-400 mb-1">Site Logo</label>
                                    <div class="flex items-center space-x-4">
                                        <img src="../<?php echo getSetting('logo_path', 'assets/images/logo.png'); ?>" alt="Site Logo" class="h-12 bg-gray-700 p-1 rounded">
                                        <input type="file" id="logo" name="logo" accept="image/*" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-2 text-white">
                                    </div>
                                </div>

                                <div>
                                    <label for="favicon" class="block text-sm font-medium text-gray-400 mb-1">Favicon</label>
                                    <div class="flex items-center space-x-4">
                                        <img src="../<?php echo getSetting('favicon_path', 'assets/images/favicon.ico'); ?>" alt="Favicon" class="h-8 bg-gray-700 p-1 rounded">
                                        <input type="file" id="favicon" name="favicon" accept="image/*" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-2 text-white">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" name="update_general_settings" class="bg-yellow-500 hover:bg-yellow-600 text-black font-bold py-2 px-4 rounded-lg transition-colors duration-200">
                            Save General Settings
                        </button>
                    </div>
                </form>
            </div>

            <!-- Financial Settings -->
            <div id="financial" class="tab-content p-6">
                <form method="POST" class="space-y-6">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <h3 class="text-xl font-semibold mb-4">Currency Settings</h3>

                            <div class="space-y-4">
                                <div>
                                    <label for="currency" class="block text-sm font-medium text-gray-400 mb-1">Currency</label>
                                    <select id="currency" name="currency" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                        <option value="INR" <?php echo getSetting('currency') === 'INR' ? 'selected' : ''; ?>>Indian Rupee (INR)</option>
                                        <option value="USD" <?php echo getSetting('currency') === 'USD' ? 'selected' : ''; ?>>US Dollar (USD)</option>
                                        <option value="EUR" <?php echo getSetting('currency') === 'EUR' ? 'selected' : ''; ?>>Euro (EUR)</option>
                                        <option value="GBP" <?php echo getSetting('currency') === 'GBP' ? 'selected' : ''; ?>>British Pound (GBP)</option>
                                    </select>
                                </div>

                                <div>
                                    <label for="currency_symbol" class="block text-sm font-medium text-gray-400 mb-1">Currency Symbol</label>
                                    <input type="text" id="currency_symbol" name="currency_symbol" value="<?php echo getSetting('currency_symbol', '₹'); ?>" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                </div>

                                <div>
                                    <label for="tax_rate" class="block text-sm font-medium text-gray-400 mb-1">Tax Rate (%)</label>
                                    <input type="number" id="tax_rate" name="tax_rate" value="<?php echo getSetting('tax_rate', '18'); ?>" min="0" max="100" step="0.01" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                </div>

                                <div>
                                    <label for="commission_rate" class="block text-sm font-medium text-gray-400 mb-1">Commission Rate (%)</label>
                                    <input type="number" id="commission_rate" name="commission_rate" value="<?php echo getSetting('commission_rate', '10'); ?>" min="0" max="100" step="0.01" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                </div>
                            </div>
                        </div>

                        <div>
                            <h3 class="text-xl font-semibold mb-4">Automatic Payouts</h3>

                            <div class="space-y-4">
                                <div class="flex items-center">
                                    <input type="checkbox" id="auto_payout_enabled" name="auto_payout_enabled" value="1" <?php echo getSetting('auto_payout_enabled') === '1' ? 'checked' : ''; ?> class="w-4 h-4 text-yellow-500 border-gray-600 rounded focus:ring-yellow-500">
                                    <label for="auto_payout_enabled" class="ml-2 text-sm font-medium text-gray-300">Enable Automatic Payouts</label>
                                </div>

                                <div>
                                    <label for="auto_payout_min_hours" class="block text-sm font-medium text-gray-400 mb-1">Minimum Hours After Booking</label>
                                    <input type="number" id="auto_payout_min_hours" name="auto_payout_min_hours" value="<?php echo getSetting('auto_payout_min_hours', '24'); ?>" min="0" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                    <p class="text-sm text-gray-400 mt-1">Hours to wait after booking completion before processing payout</p>
                                </div>

                                <div>
                                    <label for="auto_payout_max_amount" class="block text-sm font-medium text-gray-400 mb-1">Maximum Automatic Payout Amount</label>
                                    <input type="number" id="auto_payout_max_amount" name="auto_payout_max_amount" value="<?php echo getSetting('auto_payout_max_amount', '10000'); ?>" min="0" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                    <p class="text-sm text-gray-400 mt-1">Payouts above this amount require manual approval</p>
                                </div>

                                <div>
                                    <label for="auto_payout_schedule" class="block text-sm font-medium text-gray-400 mb-1">Payout Schedule</label>
                                    <select id="auto_payout_schedule" name="auto_payout_schedule" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                        <option value="daily" <?php echo getSetting('auto_payout_schedule') === 'daily' ? 'selected' : ''; ?>>Daily</option>
                                        <option value="weekly" <?php echo getSetting('auto_payout_schedule') === 'weekly' ? 'selected' : ''; ?>>Weekly</option>
                                        <option value="biweekly" <?php echo getSetting('auto_payout_schedule') === 'biweekly' ? 'selected' : ''; ?>>Bi-weekly</option>
                                        <option value="monthly" <?php echo getSetting('auto_payout_schedule') === 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                                    </select>
                                </div>

                                <div>
                                    <label for="auto_payout_payment_gateway" class="block text-sm font-medium text-gray-400 mb-1">Default Payment Gateway</label>
                                    <select id="auto_payout_payment_gateway" name="auto_payout_payment_gateway" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                        <option value="razorpay" <?php echo getSetting('auto_payout_payment_gateway') === 'razorpay' ? 'selected' : ''; ?>>Razorpay</option>
                                        <option value="bank_transfer" <?php echo getSetting('auto_payout_payment_gateway') === 'bank_transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                                        <option value="paypal" <?php echo getSetting('auto_payout_payment_gateway') === 'paypal' ? 'selected' : ''; ?>>PayPal</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" name="update_financial_settings" class="bg-yellow-500 hover:bg-yellow-600 text-black font-bold py-2 px-4 rounded-lg transition-colors duration-200">
                            Save Financial Settings
                        </button>
                    </div>
                </form>
            </div>

            <!-- Email Settings -->
            <div id="email" class="tab-content p-6">
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

            <!-- API Settings -->
            <div id="api" class="tab-content p-6">
                <form method="POST" class="space-y-6">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <h3 class="text-xl font-semibold mb-4">Google Maps API</h3>

                            <div class="space-y-4">
                                <div>
                                    <label for="google_maps_api_key" class="block text-sm font-medium text-gray-400 mb-1">Google Maps API Key</label>
                                    <input type="text" id="google_maps_api_key" name="google_maps_api_key" value="<?php echo getSetting('google_maps_api_key'); ?>" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                    <p class="text-sm text-gray-400 mt-1">Used for displaying maps and calculating distances</p>
                                </div>
                            </div>
                        </div>

                        <div>
                            <h3 class="text-xl font-semibold mb-4">Razorpay Integration</h3>

                            <div class="space-y-4">
                                <div>
                                    <label for="razorpay_key_id" class="block text-sm font-medium text-gray-400 mb-1">Razorpay Key ID</label>
                                    <input type="text" id="razorpay_key_id" name="razorpay_key_id" value="<?php echo getSetting('razorpay_key_id'); ?>" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                </div>

                                <div>
                                    <label for="razorpay_key_secret" class="block text-sm font-medium text-gray-400 mb-1">Razorpay Key Secret</label>
                                    <input type="password" id="razorpay_key_secret" name="razorpay_key_secret" placeholder="••••••••" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                    <p class="text-sm text-gray-400 mt-1">Leave blank to keep current secret key</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" name="update_api_settings" class="bg-yellow-500 hover:bg-yellow-600 text-black font-bold py-2 px-4 rounded-lg transition-colors duration-200">
                            Save API Settings
                        </button>
                    </div>
                </form>
            </div>

            <!-- Social Media Settings -->
            <div id="social" class="tab-content p-6">
                <form method="POST" class="space-y-6">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <div>
                        <h3 class="text-xl font-semibold mb-4">Social Media Links</h3>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="facebook_url" class="block text-sm font-medium text-gray-400 mb-1">
                                    <i class="fab fa-facebook text-blue-500 mr-2"></i> Facebook URL
                                </label>
                                <input type="url" id="facebook_url" name="facebook_url" value="<?php echo getSetting('facebook_url', 'https://facebook.com/flexfit'); ?>" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                            </div>

                            <div>
                                <label for="instagram_url" class="block text-sm font-medium text-gray-400 mb-1">
                                    <i class="fab fa-instagram text-pink-500 mr-2"></i> Instagram URL
                                </label>
                                <input type="url" id="instagram_url" name="instagram_url" value="<?php echo getSetting('instagram_url', 'https://instagram.com/flexfit'); ?>" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                            </div>

                            <div>
                            <label for="twitter_url" class="block text-sm font-medium text-gray-400 mb-1">
                                    <i class="fab fa-twitter text-blue-400 mr-2"></i> Twitter URL
                                </label>
                                <input type="url" id="twitter_url" name="twitter_url" value="<?php echo getSetting('twitter_url', 'https://twitter.com/flexfit'); ?>" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                            </div>

                            <div>
                                <label for="youtube_url" class="block text-sm font-medium text-gray-400 mb-1">
                                    <i class="fab fa-youtube text-red-500 mr-2"></i> YouTube URL
                                </label>
                                <input type="url" id="youtube_url" name="youtube_url" value="<?php echo getSetting('youtube_url', 'https://youtube.com/flexfit'); ?>" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                            </div>
                            
                            <div>
                                <label for="linkedin_url" class="block text-sm font-medium text-gray-400 mb-1">
                                    <i class="fab fa-linkedin text-blue-600 mr-2"></i> LinkedIn URL
                                </label>
                                <input type="url" id="linkedin_url" name="linkedin_url" value="<?php echo getSetting('linkedin_url', ''); ?>" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                            </div>
                            
                            <div>
                                <label for="pinterest_url" class="block text-sm font-medium text-gray-400 mb-1">
                                    <i class="fab fa-pinterest text-red-600 mr-2"></i> Pinterest URL
                                </label>
                                <input type="url" id="pinterest_url" name="pinterest_url" value="<?php echo getSetting('pinterest_url', ''); ?>" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                            </div>
                        </div>
                    </div>
                    
                    <div>
                        <h3 class="text-xl font-semibold mb-4">Social Sharing</h3>
                        
                        <div class="space-y-4">
                            <div class="flex items-center">
                                <input type="checkbox" id="enable_social_sharing" name="enable_social_sharing" value="1" <?php echo getSetting('enable_social_sharing', '1') === '1' ? 'checked' : ''; ?> class="w-4 h-4 text-yellow-500 border-gray-600 rounded focus:ring-yellow-500">
                                <label for="enable_social_sharing" class="ml-2 text-sm font-medium text-gray-300">Enable Social Sharing Buttons</label>
                            </div>
                            
                            <div>
                                <label for="social_share_text" class="block text-sm font-medium text-gray-400 mb-1">Default Share Text</label>
                                <input type="text" id="social_share_text" name="social_share_text" value="<?php echo getSetting('social_share_text', 'Check out this awesome gym on FlexFit!'); ?>" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                <p class="text-sm text-gray-400 mt-1">Default text used when sharing gym listings</p>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" name="update_social_settings" class="bg-yellow-500 hover:bg-yellow-600 text-black font-bold py-2 px-4 rounded-lg transition-colors duration-200">
                            Save Social Media Settings
                        </button>
                    </div>
                </form>
            </div>

            <!-- Content Settings -->
            <div id="content" class="tab-content p-6">
                <form method="POST" class="space-y-6">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <div>
                        <h3 class="text-xl font-semibold mb-4">Legal Pages</h3>

                        <div class="space-y-6">
                            <div>
                                <label for="privacy_policy" class="block text-sm font-medium text-gray-400 mb-1">Privacy Policy</label>
                                <textarea id="privacy_policy" name="privacy_policy" class="rich-editor w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500"><?php echo getSetting('privacy_policy', 'Default privacy policy text...'); ?></textarea>
                            </div>

                            <div>
                                <label for="terms_conditions" class="block text-sm font-medium text-gray-400 mb-1">Terms & Conditions</label>
                                <textarea id="terms_conditions" name="terms_conditions" class="rich-editor w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500"><?php echo getSetting('terms_conditions', 'Default terms and conditions text...'); ?></textarea>
                            </div>

                            <div>
                                <label for="about_us" class="block text-sm font-medium text-gray-400 mb-1">About Us</label>
                                <textarea id="about_us" name="about_us" class="rich-editor w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500"><?php echo getSetting('about_us', 'Default about us text...'); ?></textarea>
                            </div>
                            
                            <div>
                                <label for="faq_content" class="block text-sm font-medium text-gray-400 mb-1">FAQ Content</label>
                                <textarea id="faq_content" name="faq_content" class="rich-editor w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500"><?php echo getSetting('faq_content', 'Default FAQ content...'); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" name="update_content_settings" class="bg-yellow-500 hover:bg-yellow-600 text-black font-bold py-2 px-4 rounded-lg transition-colors duration-200">
                            Save Content Settings
                        </button>
                    </div>
                </form>
            </div>

            <!-- Security Settings Tab -->
            <div id="security" class="tab-content p-6">
                <form method="POST" class="space-y-6">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <h3 class="text-xl font-semibold text-white mb-4">Login Security</h3>

                            <div class="space-y-4">
                                <div>
                                    <label for="login_attempts_limit" class="block text-sm font-medium text-gray-400 mb-1">Max Login Attempts</label>
                                    <input type="number" id="login_attempts_limit" name="login_attempts_limit" value="<?php echo getSetting('login_attempts_limit', '5'); ?>" min="1" max="20" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                    <p class="text-sm text-gray-500 mt-1">Number of failed login attempts before account lockout</p>
                                </div>

                                <div>
                                    <label for="account_lockout_time" class="block text-sm font-medium text-gray-400 mb-1">Account Lockout Time (minutes)</label>
                                    <input type="number" id="account_lockout_time" name="account_lockout_time" value="<?php echo getSetting('account_lockout_time', '30'); ?>" min="5" max="1440" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                    <p class="text-sm text-gray-500 mt-1">Duration of account lockout after exceeding max login attempts</p>
                                </div>

                                <div>
                                    <label for="session_timeout" class="block text-sm font-medium text-gray-400 mb-1">Session Timeout (minutes)</label>
                                    <input type="number" id="session_timeout" name="session_timeout" value="<?php echo getSetting('session_timeout', '60'); ?>" min="5" max="1440" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                    <p class="text-sm text-gray-500 mt-1">Time of inactivity before user is logged out</p>
                                </div>
                            </div>
                        </div>
                        <div>
                            <h3 class="text-xl font-semibold text-white mb-4">Advanced Security</h3>

                            <div class="space-y-4">
                                <div>
                                    <label for="password_expiry_days" class="block text-sm font-medium text-gray-400 mb-1">Password Expiry (days)</label>
                                    <input type="number" id="password_expiry_days" name="password_expiry_days" value="<?php echo getSetting('password_expiry_days', '90'); ?>" min="0" max="365" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                    <p class="text-sm text-gray-500 mt-1">Days before password expires (0 to disable)</p>
                                </div>

                                <div class="flex items-center">
                                    <input type="checkbox" id="require_2fa_for_admin" name="require_2fa_for_admin" value="1" <?php echo getSetting('require_2fa_for_admin') === '1' ? 'checked' : ''; ?> class="w-4 h-4 text-yellow-500 border-gray-600 rounded focus:ring-yellow-500">
                                    <label for="require_2fa_for_admin" class="ml-2 text-sm font-medium text-gray-300">Require 2FA for Admin Access</label>
                                </div>

                                <div>
                                    <label for="allowed_login_ips" class="block text-sm font-medium text-gray-400 mb-1">Allowed Admin Login IPs</label>
                                    <textarea id="allowed_login_ips" name="allowed_login_ips" rows="3" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500"><?php echo getSetting('allowed_login_ips'); ?></textarea>
                                    <p class="text-sm text-gray-500 mt-1">Comma-separated list of IPs allowed to access admin (leave empty to allow all)</p>
                                </div>
                                
                                <div class="flex items-center">
                                    <input type="checkbox" id="enable_recaptcha" name="enable_recaptcha" value="1" <?php echo getSetting('enable_recaptcha') === '1' ? 'checked' : ''; ?> class="w-4 h-4 text-yellow-500 border-gray-600 rounded focus:ring-yellow-500">
                                    <label for="enable_recaptcha" class="ml-2 text-sm font-medium text-gray-300">Enable reCAPTCHA on Forms</label>
                                </div>
                                
                                <div>
                                    <label for="recaptcha_site_key" class="block text-sm font-medium text-gray-400 mb-1">reCAPTCHA Site Key</label>
                                    <input type="text" id="recaptcha_site_key" name="recaptcha_site_key" value="<?php echo getSetting('recaptcha_site_key'); ?>" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                </div>
                                
                                <div>
                                    <label for="recaptcha_secret_key" class="block text-sm font-medium text-gray-400 mb-1">reCAPTCHA Secret Key</label>
                                    <input type="password" id="recaptcha_secret_key" name="recaptcha_secret_key" placeholder="••••••••" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                    <p class="text-sm text-gray-500 mt-1">Leave blank to keep current secret key</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" name="update_security_settings" class="bg-yellow-500 hover:bg-yellow-600 text-black font-bold py-2 px-4 rounded-lg transition-colors duration-200">
                            Save Security Settings
                        </button>
                    </div>
                </form>
            </div>

            <!-- Localization Settings Tab -->
            <div id="localization" class="tab-content p-6">
                <form method="POST" class="space-y-6">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <h3 class="text-xl font-semibold text-white mb-4">Regional Settings</h3>

                            <div class="space-y-4">
                                <div>
                                    <label for="default_language" class="block text-sm font-medium text-gray-400 mb-1">Default Language</label>
                                    <select id="default_language" name="default_language" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                        <option value="en" <?php echo getSetting('default_language', 'en') === 'en' ? 'selected' : ''; ?>>English</option>
                                        <option value="hi" <?php echo getSetting('default_language') === 'hi' ? 'selected' : ''; ?>>Hindi</option>
                                        <option value="es" <?php echo getSetting('default_language') === 'es' ? 'selected' : ''; ?>>Spanish</option>
                                        <option value="fr" <?php echo getSetting('default_language') === 'fr' ? 'selected' : ''; ?>>French</option>
                                        <option value="de" <?php echo getSetting('default_language') === 'de' ? 'selected' : ''; ?>>German</option>
                                        <option value="zh" <?php echo getSetting('default_language') === 'zh' ? 'selected' : ''; ?>>Chinese</option>
                                    </select>
                                </div>

                                <div>
                                    <label for="default_timezone" class="block text-sm font-medium text-gray-400 mb-1">Default Timezone</label>
                                    <select id="default_timezone" name="default_timezone" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                        <?php
                                        $timezones = DateTimeZone::listIdentifiers();
                                        $current_timezone = getSetting('default_timezone', 'Asia/Kolkata');
                                        foreach ($timezones as $timezone) {
                                            echo '<option value="' . $timezone . '"' . ($timezone === $current_timezone ? ' selected' : '') . '>' . $timezone . '</option>';
                                        }
                                        ?>
                                    </select>
                                </div>

                                <div>
                                    <label for="default_country" class="block text-sm font-medium text-gray-400 mb-1">Default Country</label>
                                    <select id="default_country" name="default_country" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                        <option value="IN" <?php echo getSetting('default_country', 'IN') === 'IN' ? 'selected' : ''; ?>>India</option>
                                        <option value="US" <?php echo getSetting('default_country') === 'US' ? 'selected' : ''; ?>>United States</option>
                                        <option value="GB" <?php echo getSetting('default_country') === 'GB' ? 'selected' : ''; ?>>United Kingdom</option>
                                        <option value="CA" <?php echo getSetting('default_country') === 'CA' ? 'selected' : ''; ?>>Canada</option>
                                        <option value="AU" <?php echo getSetting('default_country') === 'AU' ? 'selected' : ''; ?>>Australia</option>
                                        <!-- Add more countries as needed -->
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div>
                            <h3 class="text-xl font-semibold text-white mb-4">Date & Time Format</h3>

                            <div class="space-y-4">
                                <div>
                                    <label for="date_format" class="block text-sm font-medium text-gray-400 mb-1">Date Format</label>
                                    <select id="date_format" name="date_format" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                        <option value="Y-m-d" <?php echo getSetting('date_format', 'Y-m-d') === 'Y-m-d' ? 'selected' : ''; ?>>YYYY-MM-DD (<?php echo date('Y-m-d'); ?>)</option>
                                        <option value="d-m-Y" <?php echo getSetting('date_format') === 'd-m-Y' ? 'selected' : ''; ?>>DD-MM-YYYY (<?php echo date('d-m-Y'); ?>)</option>
                                        <option value="m/d/Y" <?php echo getSetting('date_format') === 'm/d/Y' ? 'selected' : ''; ?>>MM/DD/YYYY (<?php echo date('m/d/Y'); ?>)</option>
                                        <option value="d/m/Y" <?php echo getSetting('date_format') === 'd/m/Y' ? 'selected' : ''; ?>>DD/MM/YYYY (<?php echo date('d/m/Y'); ?>)</option>
                                        <option value="F j, Y" <?php echo getSetting('date_format') === 'F j, Y' ? 'selected' : ''; ?>>Month Day, Year (<?php echo date('F j, Y'); ?>)</option>
                                    </select>
                                </div>

                                <div>
                                    <label for="time_format" class="block text-sm font-medium text-gray-400 mb-1">Time Format</label>
                                    <select id="time_format" name="time_format" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                        <option value="H:i" <?php echo getSetting('time_format', 'H:i') === 'H:i' ? 'selected' : ''; ?>>24-hour (<?php echo date('H:i'); ?>)</option>
                                        <option value="h:i A" <?php echo getSetting('time_format') === 'h:i A' ? 'selected' : ''; ?>>12-hour (<?php echo date('h:i A'); ?>)</option>
                                    </select>
                                </div>
                                
                                <div>
                                    <label for="first_day_of_week" class="block text-sm font-medium text-gray-400 mb-1">First Day of Week</label>
                                    <select id="first_day_of_week" name="first_day_of_week" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                        <option value="0" <?php echo getSetting('first_day_of_week', '0') === '0' ? 'selected' : ''; ?>>Sunday</option>
                                        <option value="1" <?php echo getSetting('first_day_of_week') === '1' ? 'selected' : ''; ?>>Monday</option>
                                    </select>
                                </div>
                                
                                <div>
                                    <label for="distance_unit" class="block text-sm font-medium text-gray-400 mb-1">Distance Unit</label>
                                    <select id="distance_unit" name="distance_unit" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                        <option value="km" <?php echo getSetting('distance_unit', 'km') === 'km' ? 'selected' : ''; ?>>Kilometers</option>
                                        <option value="mi" <?php echo getSetting('distance_unit') === 'mi' ? 'selected' : ''; ?>>Miles</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" name="update_localization_settings" class="bg-yellow-500 hover:bg-yellow-600 text-black font-bold py-2 px-4 rounded-lg transition-colors duration-200">
                            Save Localization Settings
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

            <!-- Analytics Settings Tab -->
            <div id="analytics" class="tab-content p-6">
                <form method="POST" class="space-y-6">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <h3 class="text-xl font-semibold text-white mb-4">Tracking Codes</h3>

                            <div class="space-y-4">
                                <div>
                                    <label for="google_analytics_id" class="block text-sm font-medium text-gray-400 mb-1">Google Analytics ID</label>
                                    <input type="text" id="google_analytics_id" name="google_analytics_id" value="<?php echo getSetting('google_analytics_id'); ?>" placeholder="UA-XXXXXXXXX-X or G-XXXXXXXXXX" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                    <p class="text-sm text-gray-500 mt-1">Your Google Analytics tracking ID</p>
                                </div>

                                <div>
                                    <label for="facebook_pixel_id" class="block text-sm font-medium text-gray-400 mb-1">Facebook Pixel ID</label>
                                    <input type="text" id="facebook_pixel_id" name="facebook_pixel_id" value="<?php echo getSetting('facebook_pixel_id'); ?>" placeholder="XXXXXXXXXXXXXXXXXX" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                    <p class="text-sm text-gray-500 mt-1">Your Facebook Pixel tracking ID</p>
                                </div>
                                
                                <div>
                                    <label for="google_tag_manager_id" class="block text-sm font-medium text-gray-400 mb-1">Google Tag Manager ID</label>
                                    <input type="text" id="google_tag_manager_id" name="google_tag_manager_id" value="<?php echo getSetting('google_tag_manager_id'); ?>" placeholder="GTM-XXXXXXX" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                    <p class="text-sm text-gray-500 mt-1">Your Google Tag Manager container ID</p>
                                </div>
                                
                                <div>
                                    <label for="custom_tracking_code" class="block text-sm font-medium text-gray-400 mb-1">Custom Tracking Code</label>
                                    <textarea id="custom_tracking_code" name="custom_tracking_code" rows="4" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500"><?php echo getSetting('custom_tracking_code'); ?></textarea>
                                    <p class="text-sm text-gray-500 mt-1">Additional tracking code to be added to the site header</p>
                                </div>
                            </div>
                        </div>

                        <div>
                            <h3 class="text-xl font-semibold text-white mb-4">User Activity Tracking</h3>

                            <div class="space-y-4">
                                <div class="flex items-center">
                                    <input type="checkbox" id="enable_user_tracking" name="enable_user_tracking" value="1" <?php echo getSetting('enable_user_tracking', '1') === '1' ? 'checked' : ''; ?> class="w-4 h-4 text-yellow-500 border-gray-600 rounded focus:ring-yellow-500">
                                    <label for="enable_user_tracking" class="ml-2 text-sm font-medium text-gray-300">Enable User Activity Tracking</label>
                                </div>

                                <div>
                                    <label for="save_user_activity_days" class="block text-sm font-medium text-gray-400 mb-1">Retain User Activity Data (days)</label>
                                    <input type="number" id="save_user_activity_days" name="save_user_activity_days" value="<?php echo getSetting('save_user_activity_days', '90'); ?>" min="1" max="365" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                    <p class="text-sm text-gray-500 mt-1">Number of days to keep user activity logs</p>
                                </div>
                                
                                <div class="flex items-center">
                                    <input type="checkbox" id="track_search_queries" name="track_search_queries" value="1" <?php echo getSetting('track_search_queries', '1') === '1' ? 'checked' : ''; ?> class="w-4 h-4 text-yellow-500 border-gray-600 rounded focus:ring-yellow-500">
                                    <label for="track_search_queries" class="ml-2 text-sm font-medium text-gray-300">Track User Search Queries</label>
                                </div>
                                
                                <div class="flex items-center">
                                    <input type="checkbox" id="track_page_views" name="track_page_views" value="1" <?php echo getSetting('track_page_views', '1') === '1' ? 'checked' : ''; ?> class="w-4 h-4 text-yellow-500 border-gray-600 rounded focus:ring-yellow-500">
                                    <label for="track_page_views" class="ml-2 text-sm font-medium text-gray-300">Track Page Views</label>
                                </div>
                                
                                <div class="flex items-center">
                                    <input type="checkbox" id="track_gym_views" name="track_gym_views" value="1" <?php echo getSetting('track_gym_views', '1') === '1' ? 'checked' : ''; ?> class="w-4 h-4 text-yellow-500 border-gray-600 rounded focus:ring-yellow-500">
                                    <label for="track_gym_views" class="ml-2 text-sm font-medium text-gray-300">Track Gym Profile Views</label>
                                </div>
                            </div>

                            <div class="mt-6 p-4 bg-gray-700 rounded-lg">
                                <p class="text-yellow-400 font-medium mb-2"><i class="fas fa-info-circle mr-2"></i> Privacy Notice</p>
                                <p class="text-gray-300 text-sm">Enabling user tracking collects data about user behavior. Ensure your privacy policy complies with local regulations like GDPR and informs users about data collection.</p>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" name="update_analytics_settings" class="bg-yellow-500 hover:bg-yellow-600 text-black font-bold py-2 px-4 rounded-lg transition-colors duration-200">
                            Save Analytics Settings
                        </button>
                    </div>
                </form>
            </div>

            <!-- Backup Settings Tab -->
            <div id="backup" class="tab-content p-6">
                <form method="POST" class="space-y-6">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <h3 class="text-xl font-semibold text-white mb-4">Automated Backups</h3>

                            <div class="space-y-4">
                                <div class="flex items-center">
                                    <input type="checkbox" id="auto_backup_enabled" name="auto_backup_enabled" value="1" <?php echo getSetting('auto_backup_enabled', '0') === '1' ? 'checked' : ''; ?> class="w-4 h-4 text-yellow-500 border-gray-600 rounded focus:ring-yellow-500">
                                    <label for="auto_backup_enabled" class="ml-2 text-sm font-medium text-gray-300">Enable Automated Backups</label>
                                </div>

                                <div>
                                    <label for="backup_frequency" class="block text-sm font-medium text-gray-400 mb-1">Backup Frequency</label>
                                    <select id="backup_frequency" name="backup_frequency" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                        <option value="daily" <?php echo getSetting('backup_frequency', 'weekly') === 'daily' ? 'selected' : ''; ?>>Daily</option>
                                        <option value="weekly" <?php echo getSetting('backup_frequency') === 'weekly' ? 'selected' : ''; ?>>Weekly</option>
                                        <option value="monthly" <?php echo getSetting('backup_frequency') === 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                                    </select>
                                </div>

                                <div>
                                    <label for="backup_retention_count" class="block text-sm font-medium text-gray-400 mb-1">Number of Backups to Keep</label>
                                    <input type="number" id="backup_retention_count" name="backup_retention_count" value="<?php echo getSetting('backup_retention_count', '5'); ?>" min="1" max="30" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                    <p class="text-sm text-gray-500 mt-1">Older backups will be automatically deleted</p>
                                </div>

                                <div>
                                    <label for="backup_storage_path" class="block text-sm font-medium text-gray-400 mb-1">Backup Storage Path</label>
                                    <input type="text" id="backup_storage_path" name="backup_storage_path" value="<?php echo getSetting('backup_storage_path', 'backups/'); ?>" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                    <p class="text-sm text-gray-500 mt-1">Relative to site root directory</p>
                                </div>
                                
                                <div>
                                    <label for="backup_time" class="block text-sm font-medium text-gray-400 mb-1">Backup Time (24-hour format)</label>
                                    <input type="time" id="backup_time" name="backup_time" value="<?php echo getSetting('backup_time', '02:00'); ?>" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                    <p class="text-sm text-gray-500 mt-1">Time of day to run automated backups</p>
                                </div>
                            </div>
                        </div>

                        <div>
                            <h3 class="text-xl font-semibold text-white mb-4">Backup Content</h3>

                            <div class="space-y-4">
                                <div class="flex items-center">
                                    <input type="checkbox" id="backup_include_database" name="backup_include_database" value="1" <?php echo getSetting('backup_include_database', '1') === '1' ? 'checked' : ''; ?> class="w-4 h-4 text-yellow-500 border-gray-600 rounded focus:ring-yellow-500">
                                    <label for="backup_include_database" class="ml-2 text-sm font-medium text-gray-300">Include Database</label>
                                </div>

                                <div class="flex items-center">
                                    <input type="checkbox" id="backup_include_files" name="backup_include_files" value="1" <?php echo getSetting('backup_include_files', '1') === '1' ? 'checked' : ''; ?> class="w-4 h-4 text-yellow-500 border-gray-600 rounded focus:ring-yellow-500">
                                    <label for="backup_include_files" class="ml-2 text-sm font-medium text-gray-300">Include Files (images, uploads, etc.)</label>
                                </div>
                                
                                <div class="flex items-center">
                                    <input type="checkbox" id="backup_compress" name="backup_compress" value="1" <?php echo getSetting('backup_compress', '1') === '1' ? 'checked' : ''; ?> class="w-4 h-4 text-yellow-500 border-gray-600 rounded focus:ring-yellow-500">
                                    <label for="backup_compress" class="ml-2 text-sm font-medium text-gray-300">Compress Backups</label>
                                </div>
                                
                                <div class="flex items-center">
                                    <input type="checkbox" id="backup_encrypt" name="backup_encrypt" value="1" <?php echo getSetting('backup_encrypt', '0') === '1' ? 'checked' : ''; ?> class="w-4 h-4 text-yellow-500 border-gray-600 rounded focus:ring-yellow-500">
                                    <label for="backup_encrypt" class="ml-2 text-sm font-medium text-gray-300">Encrypt Backups</label>
                                </div>
                            </div>

                            <h3 class="text-xl font-semibold text-white mt-6 mb-4">Manual Backup</h3>

                            <div class="bg-gray-700 rounded-lg p-4">
                                <p class="text-gray-300 mb-4">Create a manual backup of your system right now.</p>

                                <div class="space-y-4">
                                    <div class="flex items-center">
                                    <input type="checkbox" id="backup_include_files_manual" name="backup_include_files_manual" value="1" checked class="w-4 h-4 text-yellow-500 border-gray-600 rounded focus:ring-yellow-500">
                                        <label for="backup_include_files_manual" class="ml-2 text-sm font-medium text-gray-300">Include Files in Manual Backup</label>
                                    </div>

                                    <button type="submit" name="create_backup" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-lg transition-colors duration-200">
                                        <i class="fas fa-download mr-2"></i> Create Backup Now
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" name="update_backup_settings" class="bg-yellow-500 hover:bg-yellow-600 text-black font-bold py-2 px-4 rounded-lg transition-colors duration-200">
                            Save Backup Settings
                        </button>
                    </div>
                </form>
            </div>

            <!-- Performance Settings Tab -->
            <div id="performance" class="tab-content p-6">
                <form method="POST" class="space-y-6">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <h3 class="text-xl font-semibold text-white mb-4">Caching</h3>

                            <div class="space-y-4">
                                <div class="flex items-center">
                                    <input type="checkbox" id="enable_caching" name="enable_caching" value="1" <?php echo getSetting('enable_caching', '0') === '1' ? 'checked' : ''; ?> class="w-4 h-4 text-yellow-500 border-gray-600 rounded focus:ring-yellow-500">
                                    <label for="enable_caching" class="ml-2 text-sm font-medium text-gray-300">Enable Page Caching</label>
                                </div>

                                <div>
                                    <label for="cache_expiry" class="block text-sm font-medium text-gray-400 mb-1">Cache Expiry (seconds)</label>
                                    <input type="number" id="cache_expiry" name="cache_expiry" value="<?php echo getSetting('cache_expiry', '3600'); ?>" min="60" max="86400" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                    <p class="text-sm text-gray-500 mt-1">How long to keep cached content</p>
                                </div>
                                
                                <div class="flex items-center">
                                    <input type="checkbox" id="enable_memcached" name="enable_memcached" value="1" <?php echo getSetting('enable_memcached', '0') === '1' ? 'checked' : ''; ?> class="w-4 h-4 text-yellow-500 border-gray-600 rounded focus:ring-yellow-500">
                                    <label for="enable_memcached" class="ml-2 text-sm font-medium text-gray-300">Use Memcached (if available)</label>
                                </div>
                                
                                <div class="flex items-center">
                                    <input type="checkbox" id="enable_redis" name="enable_redis" value="1" <?php echo getSetting('enable_redis', '0') === '1' ? 'checked' : ''; ?> class="w-4 h-4 text-yellow-500 border-gray-600 rounded focus:ring-yellow-500">
                                    <label for="enable_redis" class="ml-2 text-sm font-medium text-gray-300">Use Redis (if available)</label>
                                </div>
                            </div>

                            <h3 class="text-xl font-semibold text-white mt-6 mb-4">Compression</h3>

                            <div class="space-y-4">
                                <div class="flex items-center">
                                    <input type="checkbox" id="enable_gzip" name="enable_gzip" value="1" <?php echo getSetting('enable_gzip', '1') === '1' ? 'checked' : ''; ?> class="w-4 h-4 text-yellow-500 border-gray-600 rounded focus:ring-yellow-500">
                                    <label for="enable_gzip" class="ml-2 text-sm font-medium text-gray-300">Enable GZIP Compression</label>
                                </div>
                            </div>
                        </div>

                        <div>
                            <h3 class="text-xl font-semibold text-white mb-4">Optimization</h3>

                            <div class="space-y-4">
                                <div class="flex items-center">
                                    <input type="checkbox" id="minify_html" name="minify_html" value="1" <?php echo getSetting('minify_html', '0') === '1' ? 'checked' : ''; ?> class="w-4 h-4 text-yellow-500 border-gray-600 rounded focus:ring-yellow-500">
                                    <label for="minify_html" class="ml-2 text-sm font-medium text-gray-300">Minify HTML</label>
                                </div>

                                <div class="flex items-center">
                                    <input type="checkbox" id="minify_css" name="minify_css" value="1" <?php echo getSetting('minify_css', '0') === '1' ? 'checked' : ''; ?> class="w-4 h-4 text-yellow-500 border-gray-600 rounded focus:ring-yellow-500">
                                    <label for="minify_css" class="ml-2 text-sm font-medium text-gray-300">Minify CSS</label>
                                </div>

                                <div class="flex items-center">
                                    <input type="checkbox" id="minify_js" name="minify_js" value="1" <?php echo getSetting('minify_js', '0') === '1' ? 'checked' : ''; ?> class="w-4 h-4 text-yellow-500 border-gray-600 rounded focus:ring-yellow-500">
                                    <label for="minify_js" class="ml-2 text-sm font-medium text-gray-300">Minify JavaScript</label>
                                </div>
                                
                                <div class="flex items-center">
                                    <input type="checkbox" id="combine_css" name="combine_css" value="1" <?php echo getSetting('combine_css', '0') === '1' ? 'checked' : ''; ?> class="w-4 h-4 text-yellow-500 border-gray-600 rounded focus:ring-yellow-500">
                                    <label for="combine_css" class="ml-2 text-sm font-medium text-gray-300">Combine CSS Files</label>
                                </div>
                                
                                <div class="flex items-center">
                                    <input type="checkbox" id="combine_js" name="combine_js" value="1" <?php echo getSetting('combine_js', '0') === '1' ? 'checked' : ''; ?> class="w-4 h-4 text-yellow-500 border-gray-600 rounded focus:ring-yellow-500">
                                    <label for="combine_js" class="ml-2 text-sm font-medium text-gray-300">Combine JavaScript Files</label>
                                </div>
                            </div>

                            <h3 class="text-xl font-semibold text-white mt-6 mb-4">Image Optimization</h3>

                            <div class="space-y-4">
                                <div>
                                    <label for="max_image_upload_size" class="block text-sm font-medium text-gray-400 mb-1">Max Image Upload Size (MB)</label>
                                    <input type="number" id="max_image_upload_size" name="max_image_upload_size" value="<?php echo getSetting('max_image_upload_size', '5'); ?>" min="1" max="20" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                </div>

                                <div>
                                    <label for="image_quality" class="block text-sm font-medium text-gray-400 mb-1">Image Compression Quality (%)</label>
                                    <input type="number" id="image_quality" name="image_quality" value="<?php echo getSetting('image_quality', '80'); ?>" min="50" max="100" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                    <p class="text-sm text-gray-500 mt-1">Lower values = smaller file size but lower quality</p>
                                </div>
                                
                                <div class="flex items-center">
                                    <input type="checkbox" id="auto_resize_images" name="auto_resize_images" value="1" <?php echo getSetting('auto_resize_images', '1') === '1' ? 'checked' : ''; ?> class="w-4 h-4 text-yellow-500 border-gray-600 rounded focus:ring-yellow-500">
                                    <label for="auto_resize_images" class="ml-2 text-sm font-medium text-gray-300">Auto-resize Large Images</label>
                                </div>
                                
                                <div>
                                    <label for="max_image_dimensions" class="block text-sm font-medium text-gray-400 mb-1">Max Image Dimensions (pixels)</label>
                                    <input type="number" id="max_image_dimensions" name="max_image_dimensions" value="<?php echo getSetting('max_image_dimensions', '2000'); ?>" min="800" max="4000" class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                    <p class="text-sm text-gray-500 mt-1">Maximum width/height for uploaded images</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="bg-gray-700 rounded-lg p-4 mt-6">
                        <h3 class="text-lg font-semibold text-white mb-3">Cache Management</h3>
                        <p class="text-gray-300 mb-4">Clear the system cache to apply changes or refresh content.</p>

                        <div class="flex items-center">
                            <input type="checkbox" id="clear_cache" name="clear_cache" value="1" class="w-4 h-4 text-yellow-500 border-gray-600 rounded focus:ring-yellow-500">
                            <label for="clear_cache" class="ml-2 text-sm font-medium text-gray-300">Clear cache when saving settings</label>
                        </div>

                        <button type="submit" name="clear_system_cache" class="mt-4 bg-red-600 hover:bg-red-700 text-white font-medium py-2 px-4 rounded-lg transition-colors duration-200">
                            <i class="fas fa-trash-alt mr-2"></i> Clear All Cache Now
                        </button>
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" name="update_performance_settings" class="bg-yellow-500 hover:bg-yellow-600 text-black font-bold py-2 px-4 rounded-lg transition-colors duration-200">
                            Save Performance Settings
                        </button>
                    </div>
                </form>
            </div>

            <!-- System Information Tab -->
            <div id="system" class="tab-content p-6">
                <div class="bg-gray-800 rounded-lg overflow-hidden">
                    <div class="px-6 py-4 bg-gray-700">
                        <h3 class="text-xl font-semibold text-white">System Information</h3>
                        <p class="text-gray-300 text-sm mt-1">Technical details about your server environment</p>
                    </div>

                    <div class="p-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <h4 class="text-lg font-medium text-yellow-400 mb-3">Server Environment</h4>

                                <div class="space-y-2">
                                    <div class="flex justify-between">
                                        <span class="text-gray-400">PHP Version:</span>
                                        <span class="text-white"><?php echo $systemInfo['php_version']; ?></span>
                                    </div>

                                    <div class="flex justify-between">
                                        <span class="text-gray-400">MySQL Version:</span>
                                        <span class="text-white"><?php echo $systemInfo['mysql_version']; ?></span>
                                    </div>

                                    <div class="flex justify-between">
                                        <span class="text-gray-400">Server Software:</span>
                                        <span class="text-white"><?php echo $systemInfo['server_software']; ?></span>
                                    </div>

                                    <div class="flex justify-between">
                                        <span class="text-gray-400">Operating System:</span>
                                        <span class="text-white"><?php echo $systemInfo['operating_system']; ?></span>
                                    </div>

                                    <div class="flex justify-between">
                                        <span class="text-gray-400">Server IP:</span>
                                        <span class="text-white"><?php echo $systemInfo['server_ip']; ?></span>
                                    </div>
                                </div>

                                <h4 class="text-lg font-medium text-yellow-400 mt-6 mb-3">PHP Configuration</h4>

                                <div class="space-y-2">
                                    <div class="flex justify-between">
                                        <span class="text-gray-400">Max Upload Size:</span>
                                        <span class="text-white"><?php echo $systemInfo['max_upload_size']; ?></span>
                                    </div>

                                    <div class="flex justify-between">
                                        <span class="text-gray-400">Max POST Size:</span>
                                        <span class="text-white"><?php echo $systemInfo['max_post_size']; ?></span>
                                    </div>

                                    <div class="flex justify-between">
                                    <span class="text-gray-400">Memory Limit:</span>
                                        <span class="text-white"><?php echo $systemInfo['memory_limit']; ?></span>
                                    </div>

                                    <div class="flex justify-between">
                                        <span class="text-gray-400">Max Execution Time:</span>
                                        <span class="text-white"><?php echo $systemInfo['max_execution_time']; ?></span>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <h4 class="text-lg font-medium text-yellow-400 mb-3">Database Information</h4>

                                <div class="space-y-2">
                                    <div class="flex justify-between">
                                        <span class="text-gray-400">Database Size:</span>
                                        <span class="text-white"><?php echo $systemInfo['database_size']; ?></span>
                                    </div>

                                    <div class="flex justify-between">
                                        <span class="text-gray-400">Total Tables:</span>
                                        <span class="text-white"><?php echo $systemInfo['table_count']; ?></span>
                                    </div>
                                </div>

                                <h4 class="text-lg font-medium text-yellow-400 mt-6 mb-3">Application Information</h4>

                                <div class="space-y-2">
                                    <div class="flex justify-between">
                                        <span class="text-gray-400">Application Version:</span>
                                        <span class="text-white"><?php echo getSetting('app_version', '1.0.0'); ?></span>
                                    </div>

                                    <div class="flex justify-between">
                                        <span class="text-gray-400">Last Updated:</span>
                                        <span class="text-white"><?php echo getSetting('last_update_date', date('Y-m-d')); ?></span>
                                    </div>
                                </div>

                                <h4 class="text-lg font-medium text-yellow-400 mt-6 mb-3">Required PHP Extensions</h4>

                                <div class="grid grid-cols-2 gap-2">
                                    <?php
                                    $requiredExtensions = ['pdo', 'pdo_mysql', 'json', 'gd', 'curl', 'mbstring', 'xml', 'fileinfo', 'zip'];
                                    foreach ($requiredExtensions as $ext) {
                                        $installed = extension_loaded($ext);
                                        echo '<div class="flex items-center">';
                                        echo '<span class="' . ($installed ? 'text-green-500' : 'text-red-500') . ' mr-2">';
                                        echo $installed ? '<i class="fas fa-check-circle"></i>' : '<i class="fas fa-times-circle"></i>';
                                        echo '</span>';
                                        echo '<span class="text-gray-300">' . $ext . '</span>';
                                        echo '</div>';
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>

                        <div class="mt-8">
                            <h4 class="text-lg font-medium text-yellow-400 mb-3">PHP Extensions</h4>

                            <div class="bg-gray-700 rounded-lg p-4 max-h-60 overflow-y-auto">
                                <p class="text-gray-300 text-sm"><?php echo $systemInfo['php_extensions']; ?></p>
                            </div>
                        </div>

                        <div class="mt-6">
                            <h4 class="text-lg font-medium text-yellow-400 mb-3">Disabled PHP Functions</h4>

                            <div class="bg-gray-700 rounded-lg p-4">
                                <p class="text-gray-300 text-sm"><?php echo $systemInfo['disabled_functions']; ?></p>
                            </div>
                        </div>

                        <div class="mt-8 flex justify-center">
                            <a href="phpinfo.php" target="_blank" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-lg transition-colors duration-200">
                                <i class="fas fa-info-circle mr-2"></i> View Full PHP Info
                            </a>
                        </div>
                    </div>
                </div>
                
                <div class="mt-6 bg-gray-800 rounded-lg overflow-hidden">
                    <div class="px-6 py-4 bg-gray-700">
                        <h3 class="text-xl font-semibold text-white">System Maintenance</h3>
                        <p class="text-gray-300 text-sm mt-1">Tools for maintaining system health</p>
                    </div>
                    
                    <div class="p-6">
                        <form method="POST" class="space-y-6">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <h4 class="text-lg font-medium text-yellow-400 mb-3">Database Maintenance</h4>
                                    
                                    <div class="space-y-4">
                                        <button type="submit" name="optimize_database" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-lg transition-colors duration-200">
                                            <i class="fas fa-database mr-2"></i> Optimize Database Tables
                                        </button>
                                        
                                        <button type="submit" name="repair_database" class="w-full bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-4 rounded-lg transition-colors duration-200">
                                            <i class="fas fa-wrench mr-2"></i> Repair Database Tables
                                        </button>
                                    </div>
                                </div>
                                
                                <div>
                                    <h4 class="text-lg font-medium text-yellow-400 mb-3">File System Maintenance</h4>
                                    
                                    <div class="space-y-4">
                                        <button type="submit" name="clear_temp_files" class="w-full bg-purple-600 hover:bg-purple-700 text-white font-medium py-2 px-4 rounded-lg transition-colors duration-200">
                                            <i class="fas fa-broom mr-2"></i> Clear Temporary Files
                                        </button>
                                        
                                        <button type="submit" name="clear_logs" class="w-full bg-yellow-600 hover:bg-yellow-700 text-white font-medium py-2 px-4 rounded-lg transition-colors duration-200">
                                            <i class="fas fa-file-alt mr-2"></i> Clear Old Log Files
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mt-6">
                                <h4 class="text-lg font-medium text-yellow-400 mb-3">Advanced Maintenance</h4>
                                
                                <div class="bg-gray-700 rounded-lg p-4">
                                    <p class="text-yellow-400 font-medium mb-2"><i class="fas fa-exclamation-triangle mr-2"></i> Warning</p>
                                    <p class="text-gray-300 text-sm mb-4">The following actions are potentially destructive and should be used with caution.</p>
                                    
                                    <div class="space-y-4">
                                        <button type="submit" name="reset_activity_logs" onclick="return confirm('Are you sure you want to delete all activity logs? This cannot be undone.')" class="w-full bg-orange-600 hover:bg-orange-700 text-white font-medium py-2 px-4 rounded-lg transition-colors duration-200">
                                            <i class="fas fa-history mr-2"></i> Reset Activity Logs
                                        </button>
                                        
                                        <button type="submit" name="purge_cache_files" onclick="return confirm('Are you sure you want to purge all cache files? This may temporarily slow down the site.')" class="w-full bg-red-600 hover:bg-red-700 text-white font-medium py-2 px-4 rounded-lg transition-colors duration-200">
                                            <i class="fas fa-trash-alt mr-2"></i> Purge All Cache Files
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <script>
                // Add this to the existing script section
                document.addEventListener('DOMContentLoaded', function () {
                    // Initialize select2 for timezone dropdown
                    if (typeof $.fn.select2 !== 'undefined') {
                        $('#default_timezone').select2({
                            theme: 'dark',
                            dropdownParent: $('#default_timezone').parent(),
                            width: '100%'
                        });
                    }

                    // Toggle visibility of cache expiry field based on caching checkbox
                    const enableCachingCheckbox = document.getElementById('enable_caching');
                    const cacheExpiryField = document.getElementById('cache_expiry').parentNode;

                    if (enableCachingCheckbox && cacheExpiryField) {
                        function toggleCacheExpiry() {
                            cacheExpiryField.style.display = enableCachingCheckbox.checked ? 'block' : 'none';
                        }

                        enableCachingCheckbox.addEventListener('change', toggleCacheExpiry);
                        toggleCacheExpiry(); // Initial state
                    }

                    // Confirm before clearing cache
                    const clearCacheButton = document.querySelector('button[name="clear_system_cache"]');
                    if (clearCacheButton) {
                        clearCacheButton.addEventListener('click', function (e) {
                            if (!confirm('Are you sure you want to clear all system cache? This may temporarily slow down the site.')) {
                                e.preventDefault();
                            }
                        });
                    }

                    // Confirm before enabling maintenance mode
                    const maintenanceModeCheckbox = document.getElementById('maintenance_mode');
                    if (maintenanceModeCheckbox) {
                        const originalCheckedState = maintenanceModeCheckbox.checked;

                        maintenanceModeCheckbox.addEventListener('change', function () {
                            if (this.checked && !originalCheckedState) {
                                if (!confirm('Enabling maintenance mode will make the site inaccessible to regular users. Only administrators will be able to access the site. Are you sure you want to continue?')) {
                                    this.checked = false;
                                }
                            }
                        });
                    }

                    // Show warning for low image quality
                    const imageQualityInput = document.getElementById('image_quality');
                    if (imageQualityInput) {
                        imageQualityInput.addEventListener('input', function () {
                            const value = parseInt(this.value);
                            if (value < 70) {
                                this.parentNode.querySelector('.text-gray-500').innerHTML = '<span class="text-yellow-500">Warning: Quality below 70% may result in visibly degraded images</span>';
                            } else {
                                this.parentNode.querySelector('.text-gray-500').innerHTML = 'Lower values = smaller file size but lower quality';
                            }
                        });
                    }
                    
                    // Handle backup encryption toggle
                    const backupEncryptCheckbox = document.getElementById('backup_encrypt');
                    if (backupEncryptCheckbox) {
                        backupEncryptCheckbox.addEventListener('change', function() {
                            if (this.checked) {
                                alert('Note: If you enable backup encryption, you will need to provide the encryption key when restoring backups. If you lose this key, your backups cannot be restored.');
                            }
                        });
                    }
                    
                    // Create backup confirmation
                    const createBackupButton = document.querySelector('button[name="create_backup"]');
                    if (createBackupButton) {
                        createBackupButton.addEventListener('click', function(e) {
                            if (!confirm('Are you sure you want to create a backup now? This may take some time depending on your database size.')) {
                                e.preventDefault();
                            }
                        });
                    }
                    
                    // Database maintenance confirmations
                    const optimizeDatabaseButton = document.querySelector('button[name="optimize_database"]');
                    if (optimizeDatabaseButton) {
                        optimizeDatabaseButton.addEventListener('click', function(e) {
                            if (!confirm('Are you sure you want to optimize database tables? This may take some time for large databases.')) {
                                e.preventDefault();
                            }
                        });
                    }
                    
                    const repairDatabaseButton = document.querySelector('button[name="repair_database"]');
                    if (repairDatabaseButton) {
                        repairDatabaseButton.addEventListener('click', function(e) {
                            if (!confirm('Are you sure you want to repair database tables? This should only be used if you suspect database corruption.')) {
                                e.preventDefault();
                            }
                        });
                    }
                });
            </script>

        </div>
    </div>

    <script>
        // Tab switching functionality
        document.addEventListener('DOMContentLoaded', function () {
            const tabs = document.querySelectorAll('.settings-tab');
            const tabContents = document.querySelectorAll('.tab-content');

            tabs.forEach(tab => {
                tab.addEventListener('click', function () {
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
                sendTestEmailBtn.addEventListener('click', function () {
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
                maintenanceCheckbox.addEventListener('change', function () {
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
                currencySelect.addEventListener('change', function () {
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
                input.addEventListener('change', function () {
                    if (this.files && this.files[0]) {
                        const img = this.previousElementSibling;
                        const reader = new FileReader();

                        reader.onload = function (e) {
                            img.src = e.target.result;
                        };

                        reader.readAsDataURL(this.files[0]);
                    }
                });
            });
            
            // Toggle Redis/Memcached options
            const enableCaching = document.getElementById('enable_caching');
            const cacheOptions = document.querySelectorAll('#enable_memcached, #enable_redis');
            
            if (enableCaching && cacheOptions.length) {
                function toggleCacheOptions() {
                    cacheOptions.forEach(option => {
                        option.parentNode.style.display = enableCaching.checked ? 'flex' : 'none';
                    });
                }
                
                enableCaching.addEventListener('change', toggleCacheOptions);
                toggleCacheOptions(); // Initial state
            }
            
            // Prevent both Redis and Memcached being enabled simultaneously
            const redisCheckbox = document.getElementById('enable_redis');
            const memcachedCheckbox = document.getElementById('enable_memcached');
            
            if (redisCheckbox && memcachedCheckbox) {
                redisCheckbox.addEventListener('change', function() {
                    if (this.checked) {
                        memcachedCheckbox.checked = false;
                    }
                });
                
                memcachedCheckbox.addEventListener('change', function() {
                    if (this.checked) {
                        redisCheckbox.checked = false;
                    }
                });
            }
            
            // Handle theme settings
            const allowUserThemeCheckbox = document.getElementById('allow_user_theme');
            const themeOptions = document.getElementById('theme_options');
            
            if (allowUserThemeCheckbox && themeOptions) {
                function toggleThemeOptions() {
                    themeOptions.style.display = allowUserThemeCheckbox.checked ? 'block' : 'none';
                }
                
                allowUserThemeCheckbox.addEventListener('change', toggleThemeOptions);
                toggleThemeOptions(); // Initial state
            }
        });
    </script>
</body>

</html>