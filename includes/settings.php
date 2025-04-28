<?php
/**
 * Settings Helper Functions
 * 
 * This file contains functions for retrieving and managing site settings
 * from the database. It provides a centralized way to access configuration
 * values throughout the application.
 * 
 * @package FeatureGym
 */

/**
 * Get all site settings from the database
 * 
 * @param PDO $conn Database connection
 * @return array Associative array of all settings organized by category
 */
function getSiteSettings($conn) {
    $settings = [];
    
    try {
        // Query all settings from the system_settings table
        $query = "SELECT setting_key, setting_value FROM system_settings";
        $stmt = $conn->prepare($query);
        $stmt->execute();
        
        // Fetch all settings as key-value pairs
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Process settings into categories
        foreach ($rows as $row) {
            if (isset($row['setting_key']) && isset($row['setting_value'])) {
                $key = $row['setting_key'];
                $value = $row['setting_value'];
                
                // Organize settings into categories based on key prefixes or known categories
                if (strpos($key, 'site_') === 0 || in_array($key, ['site_name', 'site_description', 'maintenance_mode', 'user_registration', 'gym_registration', 'review_moderation', 'default_pagination'])) {
                    $settings['general'][$key] = $value;
                } 
                elseif (strpos($key, 'currency') === 0 || in_array($key, ['tax_rate', 'commission_rate', 'max_booking_days_advance', 'cancellation_policy', 'razorpay_key_id', 'razorpay_key_secret'])) {
                    $settings['financial'][$key] = $value;
                }
                elseif (strpos($key, 'smtp_') === 0) {
                    $settings['email'][$key] = $value;
                }
                elseif (strpos($key, 'google_') === 0 || $key === 'google_maps_api_key') {
                    $settings['api'][$key] = $value;
                }
                elseif (in_array($key, ['facebook_url', 'instagram_url', 'twitter_url', 'youtube_url'])) {
                    $settings['social'][$key] = $value;
                }
                elseif (in_array($key, ['privacy_policy', 'terms_conditions', 'about_us'])) {
                    $settings['content'][$key] = $value;
                }
                elseif (in_array($key, ['logo_path', 'favicon_path']) || strpos($key, 'theme_') === 0) {
                    $settings['appearance'][$key] = $value;
                }
                elseif (in_array($key, ['login_attempts_limit', 'account_lockout_time', 'password_expiry_days', 'require_2fa_for_admin', 'session_timeout', 'allowed_login_ips'])) {
                    $settings['security'][$key] = $value;
                }
                elseif (in_array($key, ['default_language', 'default_timezone', 'date_format', 'time_format', 'default_country'])) {
                    $settings['localization'][$key] = $value;
                }
                elseif (strpos($key, 'notification_') === 0 || in_array($key, ['admin_email_notifications', 'gym_registration_notification', 'user_registration_notification', 'payment_notification', 'low_balance_threshold', 'low_balance_notification', 'review_notification'])) {
                    $settings['notifications'][$key] = $value;
                }
                elseif (in_array($key, ['google_analytics_id', 'facebook_pixel_id', 'enable_user_tracking', 'save_user_activity_days'])) {
                    $settings['seo'][$key] = $value;
                }
                elseif (in_array($key, ['auto_backup_enabled', 'backup_frequency', 'backup_retention_count', 'backup_include_files', 'backup_include_database', 'backup_storage_path'])) {
                    $settings['backup'][$key] = $value;
                }
                elseif (in_array($key, ['enable_caching', 'cache_expiry', 'minify_html', 'minify_css', 'minify_js', 'enable_gzip', 'max_image_upload_size', 'image_quality'])) {
                    $settings['performance'][$key] = $value;
                }
                elseif (strpos($key, 'theme_') === 0 || in_array($key, ['default_theme', 'allow_user_theme'])) {
                    $settings['theme'][$key] = $value;
                }
                else {
                    // Default category for uncategorized settings
                    $settings['other'][$key] = $value;
                }
            }
        }
        
        // Set default values for essential settings if they don't exist
        setDefaultSettings($settings);
        
    } catch (PDOException $e) {
        // Log error and return default settings
        error_log("Error fetching settings: " . $e->getMessage());
        return getDefaultSettings();
    }
    
    return $settings;
}

/**
 * Get a specific setting value
 * 
 * @param PDO $conn Database connection
 * @param string $key Setting key
 * @param mixed $default Default value if setting not found
 * @return mixed Setting value or default
 */
function getSetting($conn, $key, $default = '') {
    try {
        $query = "SELECT setting_value FROM system_settings WHERE setting_key = ?";
        $stmt = $conn->prepare($query);
        $stmt->execute([$key]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && isset($result['setting_value'])) {
            return $result['setting_value'];
        }
    } catch (PDOException $e) {
        error_log("Error fetching setting $key: " . $e->getMessage());
    }
    
    return $default;
}

/**
 * Update a specific setting value
 * 
 * @param PDO $conn Database connection
 * @param string $key Setting key
 * @param mixed $value New setting value
 * @return bool Success or failure
 */
function updateSetting($conn, $key, $value) {
    try {
        // Check if setting exists
        $checkQuery = "SELECT COUNT(*) FROM system_settings WHERE setting_key = ?";
        $checkStmt = $conn->prepare($checkQuery);
        $checkStmt->execute([$key]);
        
        if ($checkStmt->fetchColumn() > 0) {
            // Update existing setting
            $query = "UPDATE system_settings SET setting_value = ? WHERE setting_key = ?";
            $stmt = $conn->prepare($query);
            $stmt->execute([$value, $key]);
        } else {
            // Insert new setting
            $query = "INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)";
            $stmt = $conn->prepare($query);
            $stmt->execute([$key, $value]);
        }
        
        return true;
    } catch (PDOException $e) {
        error_log("Error updating setting $key: " . $e->getMessage());
        return false;
    }
}

/**
 * Update multiple settings at once
 * 
 * @param PDO $conn Database connection
 * @param array $settings Associative array of settings (key => value)
 * @return bool Success or failure
 */
function updateSettings($conn, $settings) {
    try {
        $conn->beginTransaction();
        
        foreach ($settings as $key => $value) {
            // Check if setting exists
            $checkQuery = "SELECT COUNT(*) FROM system_settings WHERE setting_key = ?";
            $checkStmt = $conn->prepare($checkQuery);
            $checkStmt->execute([$key]);
            
            if ($checkStmt->fetchColumn() > 0) {
                // Update existing setting
                $query = "UPDATE system_settings SET setting_value = ? WHERE setting_key = ?";
                $stmt = $conn->prepare($query);
                $stmt->execute([$value, $key]);
            } else {
                // Insert new setting
                $query = "INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)";
                $stmt = $conn->prepare($query);
                $stmt->execute([$key, $value]);
            }
        }
        
        $conn->commit();
        return true;
    } catch (PDOException $e) {
        $conn->rollBack();
        error_log("Error updating multiple settings: " . $e->getMessage());
        return false;
    }
}

/**
 * Set default values for essential settings if they don't exist
 * 
 * @param array &$settings Reference to settings array
 */
function setDefaultSettings(&$settings) {
    $defaults = getDefaultSettings();
    
    // Merge defaults with existing settings for each category
    foreach ($defaults as $category => $categorySettings) {
        if (!isset($settings[$category])) {
            $settings[$category] = [];
        }
        
        foreach ($categorySettings as $key => $value) {
            if (!isset($settings[$category][$key])) {
                $settings[$category][$key] = $value;
            }
        }
    }
}

/**
 * Get default settings for all categories
 * 
 * @return array Default settings
 */
function getDefaultSettings() {
    return [
        'general' => [
            'site_name' => 'FlexFit',
            'site_description' => 'Find and book the best gyms near you',
            'contact_email' => 'support@flexfit.com',
            'contact_phone' => '+91 1234567890',
            'address' => '123 Fitness Street, Mumbai, India',
            'maintenance_mode' => '0',
            'user_registration' => '1',
            'gym_registration' => '1',
            'review_moderation' => '1',
            'default_pagination' => '10'
        ],
        'financial' => [
            'currency' => 'INR',
            'currency_symbol' => '₹',
            'tax_rate' => '18',
            'commission_rate' => '10',
            'max_booking_days_advance' => '30',
            'cancellation_policy' => 'Cancellations must be made at least 4 hours before the scheduled time to receive a full refund.'
        ],
        'email' => [
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => '587',
            'smtp_username' => 'notifications@flexfit.com',
            'smtp_password' => '',
            'smtp_encryption' => 'tls'
        ],
        'api' => [
            'google_maps_api_key' => ''
        ],
        'social' => [
            'facebook_url' => 'https://facebook.com/flexfit',
            'instagram_url' => 'https://instagram.com/flexfit',
            'twitter_url' => 'https://twitter.com/flexfit',
            'youtube_url' => 'https://youtube.com/flexfit'
        ],
        'content' => [
            'privacy_policy' => 'Default privacy policy text...',
            'terms_conditions' => 'Default terms and conditions text...',
            'about_us' => 'Default about us text...'
        ],
        'appearance' => [
            'logo_path' => 'assets/images/logo.png',
            'favicon_path' => 'assets/images/favicon.ico'
        ],
        'security' => [
            'login_attempts_limit' => '5',
            'account_lockout_time' => '30', // minutes
            'password_expiry_days' => '90',
            'require_2fa_for_admin' => '0',
            'session_timeout' => '60', // minutes
            'allowed_login_ips' => ''
        ],
        'localization' => [
            'default_language' => 'en',
            'default_timezone' => 'Asia/Kolkata',
            'date_format' => 'Y-m-d',
            'time_format' => 'H:i',
            'default_country' => 'IN'
        ],
        'notifications' => [
            'admin_email_notifications' => '1',
            'gym_registration_notification' => '1',
            'user_registration_notification' => '1',
            'payment_notification' => '1',
            'low_balance_threshold' => '1000',
            'low_balance_notification' => '1',
            'review_notification' => '1'
        ],
        'seo' => [
            'site_title' => 'FlexFit - Find Your Perfect Gym',
            'meta_description' => 'Discover and book the best gyms near you with FlexFit. Find fitness centers, yoga studios, and more.',
            'meta_keywords' => 'gym, fitness, workout, yoga, exercise, health, wellness',
            'google_analytics_id' => '',
            'facebook_pixel_id' => '',
            'enable_user_tracking' => '1',
            'save_user_activity_days' => '90',
            'og_title' => 'FlexFit - Find Your Perfect Gym',
            'og_description' => 'Discover and book the best gyms near you with FlexFit',
            'og_image' => 'assets/images/og-image.jpg',
            'meta_author' => 'FlexFit'
        ],
        'backup' => [
            'auto_backup_enabled' => '0',
            'backup_frequency' => 'weekly', // daily, weekly, monthly
            'backup_retention_count' => '5',
            'backup_include_files' => '1',
            'backup_include_database' => '1',
            'backup_storage_path' => 'backups/'
        ],
        'performance' => [
            'enable_caching' => '0',
            'cache_expiry' => '3600', // seconds
            'minify_html' => '0',
            'minify_css' => '0',
            'minify_js' => '0',
            'enable_gzip' => '1',
            'max_image_upload_size' => '5', // MB
            'image_quality' => '80' // percent
        ],
        'theme' => [
            'default_theme' => 'dark',
            'allow_user_theme' => '1'
        ]
    ];
}

/**
 * Initialize settings table with default values if empty
 * 
 * @param PDO $conn Database connection
 * @return bool Success or failure
 */
function initializeSettings($conn) {
    try {
        // Check if settings table is empty
        $query = "SELECT COUNT(*) FROM system_settings";
        $stmt = $conn->prepare($query);
        $stmt->execute();
        
        if ($stmt->fetchColumn() > 0) {
            // Settings already exist
            return true;
        }
        
        // Settings table is empty, initialize with defaults
        $defaults = getDefaultSettings();
        $conn->beginTransaction();
        
        foreach ($defaults as $category => $settings) {
            foreach ($settings as $key => $value) {
                $query = "INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)";
                $stmt = $conn->prepare($query);
                $stmt->execute([$key, $value]);
            }
        }
        
        $conn->commit();
        return true;
    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log("Error initializing settings: " . $e->getMessage());
        return false;
    }
}

/**
 * Get settings for a specific category
 * 
 * @param PDO $conn Database connection
 * @param string $category Category name
 * @return array Settings for the specified category
 */
function getCategorySettings($conn, $category) {
    $allSettings = getSiteSettings($conn);
    return $allSettings[$category] ?? [];
}

/**
 * Update settings for a specific category
 * 
 * @param PDO $conn Database connection
 * @param string $category Category name
 * @param array $settings Settings to update
 * @return bool Success or failure
 */
function updateCategorySettings($conn, $category, $settings) {
    try {
        $conn->beginTransaction();
        
        foreach ($settings as $key => $value) {
            // Check if setting exists
            $checkQuery = "SELECT COUNT(*) FROM system_settings WHERE setting_key = ?";
            $checkStmt = $conn->prepare($checkQuery);
            $checkStmt->execute([$key]);
            
            if ($checkStmt->fetchColumn() > 0) {
                // Update existing setting
                $query = "UPDATE system_settings SET setting_value = ? WHERE setting_key = ?";
                $stmt = $conn->prepare($query);
                $stmt->execute([$value, $key]);
            } else {
                // Insert new setting
                $query = "INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)";
                $stmt = $conn->prepare($query);
                $stmt->execute([$key, $value]);
            }
        }
        
        // Log the activity if user is logged in
        if (isset($_SESSION['user_id']) || isset($_SESSION['admin_id']) || isset($_SESSION['owner_id'])) {
            $userId = $_SESSION['admin_id'] ?? ($_SESSION['user_id'] ?? $_SESSION['owner_id']);
            $userType = isset($_SESSION['admin_id']) ? 'admin' : (isset($_SESSION['owner_id']) ? 'gym_owner' : 'user');
            
            $activitySql = "
                INSERT INTO activity_logs (
                    user_id, user_type, action, details, ip_address, user_agent
                ) VALUES (
                    ?, ?, ?, ?, ?, ?
                )
            ";
            $details = "Updated $category settings";
            $activityStmt = $conn->prepare($activitySql);
            $activityStmt->execute([
                $userId,
                $userType,
                'update_settings',
                $details,
                $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            ]);
        }
        
        $conn->commit();
        return true;
    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log("Error updating $category settings: " . $e->getMessage());
        return false;
    }
}

/**
 * Export all settings as JSON
 * 
 * @param PDO $conn Database connection
 * @return string JSON representation of all settings
 */
function exportSettings($conn) {
    $settings = getSiteSettings($conn);
    return json_encode($settings, JSON_PRETTY_PRINT);
}

/**
 * Import settings from JSON
 * 
 * @param PDO $conn Database connection
 * @param string $json JSON string containing settings
 * @return bool Success or failure
 */
function importSettings($conn, $json) {
    try {
        $settings = json_decode($json, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON format: " . json_last_error_msg());
        }
        
        $conn->beginTransaction();
        
        // Clear existing settings
        $clearQuery = "DELETE FROM system_settings";
        $clearStmt = $conn->prepare($clearQuery);
        $clearStmt->execute();
        
        // Insert new settings
        foreach ($settings as $category => $categorySettings) {
            foreach ($categorySettings as $key => $value) {
                $query = "INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)";
                $stmt = $conn->prepare($query);
                $stmt->execute([$key, $value]);
            }
        }
        
        // Log the activity
        if (isset($_SESSION['admin_id'])) {
            $activitySql = "
                INSERT INTO activity_logs (
                    user_id, user_type, action, details, ip_address, user_agent
                ) VALUES (
                    ?, 'admin', ?, ?, ?, ?
                )
            ";
            $details = "Imported settings from JSON";
            $activityStmt = $conn->prepare($activitySql);
            $activityStmt->execute([
                $_SESSION['admin_id'],
                'import_settings',
                $details,
                $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            ]);
        }
        
        $conn->commit();
        return true;
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log("Error importing settings: " . $e->getMessage());
        return false;
    }
}

/**
 * Reset settings to default values
 * 
 * @param PDO $conn Database connection
 * @param string|null $category Optional category to reset (null for all)
 * @return bool Success or failure
 */
function resetSettings($conn, $category = null) {
    try {
        $defaults = getDefaultSettings();
        $conn->beginTransaction();
        
        if ($category === null) {
            // Reset all settings
            $clearQuery = "DELETE FROM system_settings";
            $clearStmt = $conn->prepare($clearQuery);
            $clearStmt->execute();
            
            foreach ($defaults as $cat => $settings) {
                foreach ($settings as $key => $value) {
                    $query = "INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)";
                    $stmt = $conn->prepare($query);
                    $stmt->execute([$key, $value]);
                }
            }
            
            $logDetails = "Reset all settings to default";
        } else {
            // Reset only specified category
            if (!isset($defaults[$category])) {
                throw new Exception("Invalid category: $category");
            }
            
            // Delete category settings
            $deleteQuery = "DELETE FROM system_settings WHERE setting_key IN (";
            $params = [];
            
            foreach ($defaults[$category] as $key => $value) {
                $deleteQuery .= "?, ";
                $params[] = $key;
            }
            
            $deleteQuery = rtrim($deleteQuery, ", ") . ")";
            $deleteStmt = $conn->prepare($deleteQuery);
            $deleteStmt->execute($params);
            
            // Insert default values
            foreach ($defaults[$category] as $key => $value) {
                $query = "INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)";
                $stmt = $conn->prepare($query);
                $stmt->execute([$key, $value]);
            }
            
            $logDetails = "Reset $category settings to default";
        }
        
        // Log the activity
        if (isset($_SESSION['admin_id'])) {
            $activitySql = "
                INSERT INTO activity_logs (
                    user_id, user_type, action, details, ip_address, user_agent
                ) VALUES (
                    ?, 'admin', ?, ?, ?, ?
                )
            ";
            $activityStmt = $conn->prepare($activitySql);
            $activityStmt->execute([
                $_SESSION['admin_id'],
                'reset_settings',
                $logDetails,
                $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            ]);
        }
        
        $conn->commit();
        return true;
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log("Error resetting settings: " . $e->getMessage());
        return false;
    }
}

/**
 * Get a formatted setting value based on its type
 * 
 * @param string $value The raw setting value
 * @param string $type The type of setting (boolean, number, array, json)
 * @return mixed Formatted setting value
 */
function formatSettingValue($value, $type = 'string') {
    switch ($type) {
        case 'boolean':
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        
        case 'number':
        case 'integer':
            return intval($value);
        
        case 'float':
        case 'decimal':
            return floatval($value);
        
        case 'array':
            if (is_string($value) && !empty($value)) {
                return explode(',', $value);
            }
            return [];
        
        case 'json':
            if (is_string($value) && !empty($value)) {
                $decoded = json_decode($value, true);
                return ($decoded !== null) ? $decoded : $value;
            }
            return [];
        
        case 'string':
        default:
            return (string) $value;
    }
}

/**
 * Check if the system is in maintenance mode
 * 
 * @param PDO $conn Database connection
 * @return bool True if in maintenance mode
 */
function isMaintenanceMode($conn) {
    return getSetting($conn, 'maintenance_mode', '0') === '1';
}

/**
 * Get the current theme settings
 * 
 * @param PDO $conn Database connection
 * @return array Theme settings
 */
function getThemeSettings($conn) {
    return [
        'default_theme' => getSetting($conn, 'default_theme', 'dark'),
        'allow_user_theme' => getSetting($conn, 'allow_user_theme', '1') === '1'
    ];
}

/**
 * Get the current localization settings
 * 
 * @param PDO $conn Database connection
 * @return array Localization settings
 */
function getLocalizationSettings($conn) {
    return [
        'default_language' => getSetting($conn, 'default_language', 'en'),
        'default_timezone' => getSetting($conn, 'default_timezone', 'Asia/Kolkata'),
        'date_format' => getSetting($conn, 'date_format', 'Y-m-d'),
        'time_format' => getSetting($conn, 'time_format', 'H:i'),
        'default_country' => getSetting($conn, 'default_country', 'IN')
    ];
}

/**
 * Format a date according to the system's date format setting
 * 
 * @param PDO $conn Database connection
 * @param string|int $date Date string or timestamp
 * @return string Formatted date
 */
function formatDate($conn, $date) {
    $format = getSetting($conn, 'date_format', 'Y-m-d');
    
    if (is_numeric($date)) {
        return date($format, $date);
    } else {
        return date($format, strtotime($date));
    }
}

/**
 * Format a time according to the system's time format setting
 * 
 * @param PDO $conn Database connection
 * @param string|int $time Time string or timestamp
 * @return string Formatted time
 */
function formatTime($conn, $time) {
    $format = getSetting($conn, 'time_format', 'H:i');
    
    if (is_numeric($time)) {
        return date($format, $time);
    } else {
        return date($format, strtotime($time));
    }
}

/**
 * Format a datetime according to the system's date and time format settings
 * 
 * @param PDO $conn Database connection
 * @param string|int $datetime Datetime string or timestamp
 * @return string Formatted datetime
 */
function formatDateTime($conn, $datetime) {
    $dateFormat = getSetting($conn, 'date_format', 'Y-m-d');
    $timeFormat = getSetting($conn, 'time_format', 'H:i');
    $format = "$dateFormat $timeFormat";
    
    if (is_numeric($datetime)) {
        return date($format, $datetime);
    } else {
        return date($format, strtotime($datetime));
    }
}

/**
 * Format a currency amount according to the system's currency settings
 * 
 * @param PDO $conn Database connection
 * @param float $amount Amount to format
 * @param bool $includeSymbol Whether to include the currency symbol
 * @return string Formatted currency amount
 */
function formatCurrency($conn, $amount, $includeSymbol = true) {
    $symbol = getSetting($conn, 'currency_symbol', '₹');
    $currency = getSetting($conn, 'currency', 'INR');
    
    // Format based on currency
    switch ($currency) {
        case 'USD':
            $formatted = number_format($amount, 2, '.', ',');
            return $includeSymbol ? "$symbol$formatted" : $formatted;
        
        case 'EUR':
            $formatted = number_format($amount, 2, ',', '.');
            return $includeSymbol ? "$formatted$symbol" : $formatted;
        
        case 'INR':
        default:
            $formatted = number_format($amount, 2, '.', ',');
            return $includeSymbol ? "$symbol$formatted" : $formatted;
    }
}

/**
 * Clear the system cache
 * 
 * @return bool Success or failure
 */
function clearSystemCache() {
    $cacheDir = __DIR__ . '/../cache/';
    
    if (!is_dir($cacheDir)) {
        return true; // No cache directory exists
    }
    
    $success = true;
    
    try {
        $files = glob($cacheDir . '*');
        foreach ($files as $file) {
            if (is_file($file)) {
                if (!unlink($file)) {
                    $success = false;
                }
            }
        }
    } catch (Exception $e) {
        error_log("Error clearing cache: " . $e->getMessage());
        $success = false;
    }
    
    return $success;
}
