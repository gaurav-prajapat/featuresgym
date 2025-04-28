<?php
require_once 'EmailService.php';

class OTPService {
    private $conn;
    private $emailService;
    private $otpLength = 6;
    private $otpExpiry = 600; // 10 minutes in seconds
    private $timezone = 'Asia/Kolkata'; // Default to Indian timezone
    private $settings = [];
    
    public function __construct($conn) {
        $this->conn = $conn;
        $this->emailService = new EmailService();
        
        // Load all relevant settings from system_settings table
        $this->loadSettings();
        
        // Set timezone based on system settings
        date_default_timezone_set($this->timezone);
    }
    
    private function loadSettings() {
        try {
            // Get all relevant settings from system_settings table
            $stmt = $this->conn->prepare("
                SELECT setting_key, setting_value 
                FROM system_settings 
                WHERE setting_key IN (
                    'otp_length', 
                    'otp_expiry_seconds', 
                    'default_timezone',
                    'dev_mode',
                    'dev_auto_verify_otp'
                )
            ");
            $stmt->execute();
            
            // Store all settings in the settings array
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $this->settings[$row['setting_key']] = $row['setting_value'];
            }
            
            // Apply specific settings
            if (isset($this->settings['otp_length'])) {
                $this->otpLength = (int)$this->settings['otp_length'];
            }
            
            if (isset($this->settings['otp_expiry_seconds'])) {
                $this->otpExpiry = (int)$this->settings['otp_expiry_seconds'];
            }
            
            if (isset($this->settings['default_timezone'])) {
                $this->timezone = $this->settings['default_timezone'];
            }
            
            // If OTP settings don't exist, create them with default values
            if (!isset($this->settings['otp_length']) || !isset($this->settings['otp_expiry_seconds'])) {
                $this->createDefaultOtpSettings();
            }
        } catch (Exception $e) {
            error_log("Failed to load OTP settings: " . $e->getMessage());
            // Use default values if settings can't be loaded
        }
    }
    
    private function createDefaultOtpSettings() {
        try {
            $defaultSettings = [
                ['otp_length', '6', 'security'],
                ['otp_expiry_seconds', '600', 'security'],
                ['dev_auto_verify_otp', '0', 'development']
            ];
            
            foreach ($defaultSettings as $setting) {
                $stmt = $this->conn->prepare("
                    INSERT IGNORE INTO system_settings 
                    (setting_key, setting_value, setting_group, created_at, updated_at) 
                    VALUES (?, ?, ?, NOW(), NOW())
                ");
                $stmt->execute($setting);
            }
        } catch (Exception $e) {
            error_log("Failed to create default OTP settings: " . $e->getMessage());
        }
    }
    
    public function generateOTP() {
        // Check if we're in development mode with auto-verify enabled
        if (isset($this->settings['dev_mode']) && 
            $this->settings['dev_mode'] === '1' && 
            isset($this->settings['dev_auto_verify_otp']) && 
            $this->settings['dev_auto_verify_otp'] === '1') {
            // In development mode with auto-verify, always use 123456 for easy testing
            return '123456';
        }
        
        // Generate a random OTP of specified length
        $otp = '';
        for ($i = 0; $i < $this->otpLength; $i++) {
            $otp .= mt_rand(0, 9);
        }
        return $otp;
    }
    
    public function storeOTP($email, $otp) {
        try {
            // First, delete any existing OTPs for this email
            $stmt = $this->conn->prepare("DELETE FROM otp_verifications WHERE email = ?");
            $stmt->execute([$email]);
            
            // Calculate expiry time correctly
            // Use server's timezone for consistent datetime calculations
            $currentTime = new DateTime('now', new DateTimeZone($this->timezone));
            $expiryTime = clone $currentTime;
            $expiryTime->add(new DateInterval('PT' . $this->otpExpiry . 'S'));
            
            // Format times for database
            $currentTimeStr = $currentTime->format('Y-m-d H:i:s');
            $expiryTimeStr = $expiryTime->format('Y-m-d H:i:s');
            
            // Insert new OTP with correct timestamps
            $stmt = $this->conn->prepare("
                INSERT INTO otp_verifications (
                    email, 
                    otp, 
                    created_at, 
                    expires_at
                ) VALUES (?, ?, ?, ?)
            ");
            
            $result = $stmt->execute([$email, $otp, $currentTimeStr, $expiryTimeStr]);
            
            // Log the OTP creation for debugging
            error_log("OTP created for $email: $otp, Created: $currentTimeStr, Expires: $expiryTimeStr");
            
            return $result;
        } catch (Exception $e) {
            error_log("Failed to store OTP: " . $e->getMessage());
            return false;
        }
    }
    
    public function verifyOTP($email, $otp) {
        // Check if we're in development mode with auto-verify enabled
        if (isset($this->settings['dev_mode']) && 
            $this->settings['dev_mode'] === '1' && 
            isset($this->settings['dev_auto_verify_otp']) && 
            $this->settings['dev_auto_verify_otp'] === '1') {
            // In development mode with auto-verify, any OTP is valid
            return ['success' => true, 'message' => 'OTP verified successfully (Development Mode)'];
        }
        
        try {
            $stmt = $this->conn->prepare("
                SELECT * FROM otp_verifications 
                WHERE email = ? AND otp = ? AND expires_at > NOW()
            ");
            $stmt->execute([$email, $otp]);
            
            if ($stmt->rowCount() > 0) {
                // OTP is valid, delete it to prevent reuse
                $deleteStmt = $this->conn->prepare("DELETE FROM otp_verifications WHERE email = ?");
                $deleteStmt->execute([$email]);
                
                // Log the successful verification
                $this->logVerification($email, true, 'OTP verified successfully');
                
                return ['success' => true, 'message' => 'OTP verified successfully'];
            } else {
                // Check if OTP exists but expired
                $stmt = $this->conn->prepare("
                    SELECT * FROM otp_verifications 
                    WHERE email = ? AND otp = ? AND expires_at <= NOW()
                ");
                $stmt->execute([$email, $otp]);
                
                if ($stmt->rowCount() > 0) {
                    $this->logVerification($email, false, 'OTP expired');
                    return ['success' => false, 'message' => 'Verification code has expired. Please request a new one.'];
                } else {
                    // Check if any OTP exists for this email
                    $stmt = $this->conn->prepare("
                        SELECT * FROM otp_verifications 
                        WHERE email = ?
                    ");
                    $stmt->execute([$email]);
                    
                    if ($stmt->rowCount() > 0) {
                        $this->logVerification($email, false, 'Invalid OTP');
                        return ['success' => false, 'message' => 'Invalid verification code. Please try again.'];
                    } else {
                        $this->logVerification($email, false, 'No OTP found');
                        return ['success' => false, 'message' => 'No verification code found. Please request a new one.'];
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Failed to verify OTP: " . $e->getMessage());
            return ['success' => false, 'message' => 'An error occurred. Please try again later.'];
        }
    }
    
    private function logVerification($email, $success, $message) {
        try {
            // Log verification attempt to activity_logs table
            $stmt = $this->conn->prepare("
                INSERT INTO activity_logs (
                    user_id, 
                    user_type, 
                    action, 
                    details, 
                    ip_address, 
                    user_agent, 
                    created_at
                ) VALUES (
                    0, 
                    'system', 
                    ?, 
                    ?, 
                    ?, 
                    ?, 
                    NOW()
                )
            ");
            
            $action = $success ? 'otp_verification_success' : 'otp_verification_failed';
            $details = "Email: $email, Message: $message";
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
            
            $stmt->execute([$action, $details, $ipAddress, $userAgent]);
        } catch (Exception $e) {
            error_log("Failed to log OTP verification: " . $e->getMessage());
        }
    }
    
    public function sendOTPEmail($email, $otp) {
        // Check if we're in development mode
        if (isset($this->settings['dev_mode']) && $this->settings['dev_mode'] === '1') {
            // In development mode, log the OTP instead of sending an email
            error_log("DEVELOPMENT MODE: OTP for $email is $otp");
            return true;
        }
        
        return $this->emailService->sendOTPEmail($email, $otp);
    }
    
    /**
     * Get remaining time for an OTP in seconds
     * 
     * @param string $email The email address
     * @return int|false Remaining time in seconds or false if no OTP found
     */
    public function getRemainingTime($email) {
        try {
            $stmt = $this->conn->prepare("
                SELECT TIMESTAMPDIFF(SECOND, NOW(), expires_at) as remaining_seconds
                FROM otp_verifications 
                WHERE email = ?
            ");
            $stmt->execute([$email]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                return max(0, (int)$result['remaining_seconds']);
            } else {
                return false;
            }
        } catch (Exception $e) {
            error_log("Failed to get OTP remaining time: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if an email has a valid OTP
     * 
     * @param string $email The email address
     * @return bool Whether a valid OTP exists
     */
    public function hasValidOTP($email) {
        try {
            $stmt = $this->conn->prepare("
                SELECT COUNT(*) 
                FROM otp_verifications 
                WHERE email = ? AND expires_at > NOW()
            ");
            $stmt->execute([$email]);
            
            return $stmt->fetchColumn() > 0;
        } catch (Exception $e) {
            error_log("Failed to check valid OTP: " . $e->getMessage());
            return false;
        }
    }
}
?>
