<?php
/**
 * Application Configuration
 */

// Define environment
define('ENVIRONMENT', 'production'); // Options: 'development', 'production'

// Email configuration
define('EMAIL_FROM', 'noreply@yourgymapp.com');
define('EMAIL_FROM_NAME', 'Gym App');

// OTP configuration
define('OTP_LENGTH', 6);
define('OTP_EXPIRY_MINUTES', 15);
define('OTP_MAX_ATTEMPTS', 5);

// Security settings
define('PASSWORD_MIN_LENGTH', 8);
define('SESSION_LIFETIME', 3600); // 1 hour in seconds
define('CSRF_TOKEN_EXPIRY', 3600); // 1 hour in seconds

// File upload settings
define('MAX_UPLOAD_SIZE', 2 * 1024 * 1024); // 2MB
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/jpg']);
define('UPLOAD_DIR', 'uploads/');
define('PROFILE_PICTURES_DIR', UPLOAD_DIR . 'profile_pictures/');

// Error reporting
if (ENVIRONMENT === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

?>
