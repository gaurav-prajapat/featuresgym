<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class EmailService {
    private $mailer;
    private $lastError = '';
    private $settings = [];
    private $conn = null;
    
    public function __construct() {
        // Initialize database connection
        $this->initDatabase();
        
        // Load settings
        $this->loadSettings();
        
        // Initialize PHPMailer if available
        $this->initMailer();
    }
    
    private function initDatabase() {
        try {
            if (class_exists('GymDatabase')) {
                $db = new GymDatabase();
                $this->conn = $db->getConnection();
            } else {
                // Fallback to direct connection if GymDatabase class is not available
                require_once __DIR__ . '/../config/database.php';
                $db = new GymDatabase();
                $this->conn = $db->getConnection();
            }
        } catch (Exception $e) {
            error_log("Failed to initialize database connection in EmailService: " . $e->getMessage());
        }
    }
    
    private function loadSettings() {
        if ($this->conn) {
            try {
                $stmt = $this->conn->prepare("
                    SELECT setting_key, setting_value, setting_group 
                    FROM system_settings 
                    WHERE setting_group = 'email' 
                    OR setting_key IN (
                        'dev_mode', 'site_name', 'contact_email', 'smtp_host', 
                        'smtp_port', 'smtp_username', 'smtp_password', 
                        'smtp_encryption', 'smtp_from_email', 'smtp_from_name',
                        'log_emails', 'log_retention_days'
                    )
                ");
                $stmt->execute();
                
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $this->settings[$row['setting_key']] = $row['setting_value'];
                }
            } catch (Exception $e) {
                error_log("Failed to load email settings: " . $e->getMessage());
            }
        }
    }
    
    private function initMailer() {
        if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            // Try to load PHPMailer from vendor directory
            $vendorAutoload = __DIR__ . '/../vendor/autoload.php';
            if (file_exists($vendorAutoload)) {
                require_once $vendorAutoload;
            } else {
                error_log("PHPMailer not found. Please install it using Composer.");
                return;
            }
        }
        
        try {
            $this->mailer = new PHPMailer(true);
            
            // Set default settings
            $this->mailer->isSMTP();
            $this->mailer->Host = $this->settings['smtp_host'] ?? 'localhost';
            $this->mailer->Port = $this->settings['smtp_port'] ?? 25;
            $this->mailer->SMTPAuth = !empty($this->settings['smtp_username']);
            $this->mailer->Username = $this->settings['smtp_username'] ?? '';
            $this->mailer->Password = $this->settings['smtp_password'] ?? '';
            
            // Enable SMTP debugging in development mode
            if (isset($this->settings['dev_mode']) && $this->settings['dev_mode'] === '1') {
                $this->mailer->SMTPDebug = 2; // Output debug info
                $this->mailer->Debugoutput = function($str, $level) {
                    error_log("SMTP DEBUG [$level]: $str");
                };
            }
            
            // Set encryption
            $encryption = $this->settings['smtp_encryption'] ?? '';
            if ($encryption === 'tls') {
                $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } elseif ($encryption === 'ssl') {
                $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                // Add this to fix SSL certificate verification issues
                $this->mailer->SMTPOptions = [
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true
                    ]
                ];
            } else {
                $this->mailer->SMTPSecure = '';
                $this->mailer->SMTPAutoTLS = false;
            }
            
            // Set default sender
            $fromEmail = $this->settings['smtp_from_email'] ?? 'notifications@featuresgym.com';
            $fromName = $this->settings['smtp_from_name'] ?? 'Features Gym';
            $this->mailer->setFrom($fromEmail, $fromName);
            
            // Set character set
            $this->mailer->CharSet = 'UTF-8';
            
            // Enable HTML emails by default
            $this->mailer->isHTML(true);
            
        } catch (Exception $e) {
            error_log("Failed to initialize PHPMailer: " . $e->getMessage());
            $this->lastError = $e->getMessage();
        }
    }
    
    /**
     * Set custom SMTP settings
     * 
     * @param array $settings SMTP settings
     * @return void
     */
    public function setSmtpSettings($settings) {
        if (!$this->mailer) {
            $this->initMailer();
        }
        
        if ($this->mailer) {
            try {
                $this->mailer->Host = $settings['smtp_host'] ?? $this->mailer->Host;
                $this->mailer->Port = $settings['smtp_port'] ?? $this->mailer->Port;
                $this->mailer->Username = $settings['smtp_username'] ?? $this->mailer->Username;
                $this->mailer->Password = $settings['smtp_password'] ?? $this->mailer->Password;
                
                // Set encryption
                $encryption = $settings['smtp_encryption'] ?? 'tls';
                if ($encryption === 'tls') {
                    $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                } elseif ($encryption === 'ssl') {
                    $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                    // Add SSL options
                    $this->mailer->SMTPOptions = [
                        'ssl' => [
                            'verify_peer' => false,
                            'verify_peer_name' => false,
                            'allow_self_signed' => true
                        ]
                    ];
                } else {
                    $this->mailer->SMTPSecure = '';
                    $this->mailer->SMTPAutoTLS = false;
                }
                
                // Update settings array
                foreach ($settings as $key => $value) {
                    $this->settings[$key] = $value;
                }
            } catch (Exception $e) {
                error_log("Failed to set SMTP settings: " . $e->getMessage());
                $this->lastError = $e->getMessage();
            }
        }
    }
    
    /**
     * Send an email
     * 
     * @param string $to Recipient email address
     * @param string $subject Email subject
     * @param string $body Email body (HTML)
     * @param array $attachments Optional array of attachments
     * @param array $options Additional options (cc, bcc, etc.)
     * @return bool Whether the email was sent successfully
     */
    public function sendEmail($to, $subject, $body, $attachments = [], $options = []) {
        // Check if we're in development mode
        if (isset($this->settings['dev_mode']) && $this->settings['dev_mode'] === '1') {
            // Log email instead of sending
            error_log("DEVELOPMENT MODE: Email to $to, Subject: $subject");
            error_log("EMAIL DEBUG: SMTP Settings - Host: {$this->settings['smtp_host']}, Port: {$this->settings['smtp_port']}, Username: {$this->settings['smtp_username']}");
            error_log("EMAIL DEBUG: From Email: {$this->settings['smtp_from_email']}, From Name: {$this->settings['smtp_from_name']}");
            error_log("EMAIL DEBUG: Encryption: {$this->settings['smtp_encryption']}");
            $this->logEmail($to, $subject, 'sent', null, 0, $options['email_type'] ?? 'development');
            return true;
        }
        
        // Check if we should queue this email
        if (isset($options['queue']) && $options['queue'] === true) {
            return $this->queueEmail($to, $subject, $body, $attachments, $options);
        }
        
        if (!$this->mailer) {
            $this->initMailer();
            
            if (!$this->mailer) {
                $this->lastError = "Mailer not initialized";
                $this->logEmail($to, $subject, 'failed', $this->lastError, 0, $options['email_type'] ?? null);
                error_log("EMAIL ERROR: Mailer not initialized");
                return false;
            }
        }
        
        try {
            // Reset mailer
            $this->mailer->clearAddresses();
            $this->mailer->clearAttachments();
            $this->mailer->clearCCs();
            $this->mailer->clearBCCs();
            $this->mailer->clearReplyTos();
            
            // Set recipient
            $this->mailer->addAddress($to);
            
            // Set subject and body
            $this->mailer->Subject = $subject;
            $this->mailer->Body = $body;
            
            // Set plain text version if provided
            if (isset($options['text_body'])) {
                $this->mailer->AltBody = $options['text_body'];
            } else {
                // Generate plain text version from HTML
                $this->mailer->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $body));
            }
            
            // Add CC recipients if provided
            if (isset($options['cc']) && is_array($options['cc'])) {
                foreach ($options['cc'] as $cc) {
                    $this->mailer->addCC($cc);
                }
            }
            
            // Add BCC recipients if provided
            if (isset($options['bcc']) && is_array($options['bcc'])) {
                foreach ($options['bcc'] as $bcc) {
                    $this->mailer->addBCC($bcc);
                }
            }
            
            // Add reply-to if provided
            if (isset($options['reply_to'])) {
                $replyToName = $options['reply_to_name'] ?? '';
                $this->mailer->addReplyTo($options['reply_to'], $replyToName);
            }
            
            // Add attachments if provided
            if (!empty($attachments) && is_array($attachments)) {
                foreach ($attachments as $attachment) {
                    if (isset($attachment['path'])) {
                        $name = $attachment['name'] ?? basename($attachment['path']);
                        $this->mailer->addAttachment($attachment['path'], $name);
                    }
                }
            }
            
            // Send the email
            $result = $this->mailer->send();
            
            // Log the email
            $userId = $options['user_id'] ?? 0;
            $emailType = $options['email_type'] ?? null;
            $this->logEmail($to, $subject, 'sent', null, $userId, $emailType);
            
            return $result;
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            error_log("Failed to send email: " . $this->lastError);
            
            // Log the failed email
            $userId = $options['user_id'] ?? 0;
            $emailType = $options['email_type'] ?? null;
            $this->logEmail($to, $subject, 'failed', $this->lastError, $userId, $emailType);
            
            return false;
        }
    }
    
    /**
     * Queue an email for later sending
     * 
     * @param string $to Recipient email address
     * @param string $subject Email subject
     * @param string $body Email body (HTML)
     * @param array $attachments Optional array of attachments
     * @param array $options Additional options (priority, etc.)
     * @return bool Whether the email was queued successfully
     */
    public function queueEmail($to, $subject, $body, $attachments = [], $options = []) {
        if (!$this->conn) {
            $this->initDatabase();
            
            if (!$this->conn) {
                $this->lastError = "Database connection not available";
                return false;
            }
        }
        
        try {
            $priority = isset($options['priority']) ? (int)$options['priority'] : 1;
            $maxAttempts = isset($options['max_attempts']) ? (int)$options['max_attempts'] : 3;
            
            // Convert attachments to JSON
            $attachmentsJson = !empty($attachments) ? json_encode($attachments) : null;
            
            $stmt = $this->conn->prepare("
                INSERT INTO email_queue (
                    recipient, 
                    subject, 
                    body, 
                    attachments, 
                    status, 
                    priority, 
                    max_attempts, 
                    created_at
                ) VALUES (?, ?, ?, ?, 'pending', ?, ?, NOW())
            ");
            
            $result = $stmt->execute([
                $to,
                $subject,
                $body,
                $attachmentsJson,
                $priority,
                $maxAttempts
            ]);
            
            if ($result) {
                // Log the queued email
                $userId = $options['user_id'] ?? 0;
                $emailType = $options['email_type'] ?? null;
                $this->logEmail($to, $subject, 'queued', null, $userId, $emailType);
            }
            
            return $result;
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            error_log("Failed to queue email: " . $this->lastError);
            return false;
        }
    }
    
    /**
     * Log an email to the database
     * 
     * @param string $recipient Recipient email address
     * @param string $subject Email subject
     * @param string $status Email status (sent, failed, queued)
     * @param string|null $errorMessage Error message if failed
     * @param int $userId User ID if applicable
     * @param string|null $emailType Type of email (welcome, reset, etc.)
     * @return bool Whether the log was saved successfully
     */
    private function logEmail($recipient, $subject, $status, $errorMessage = null, $userId = 0, $emailType = null) {
        // Check if email logging is enabled
        if (isset($this->settings['log_emails']) && $this->settings['log_emails'] !== '1') {
            return true; // Skip logging if disabled
        }
        
        if (!$this->conn) {
            return false;
        }
        
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO email_logs (
                                    recipient_email, 
                    subject, 
                    status, 
                    error_message, 
                    created_at,
                    user_id,
                    email_type
                ) VALUES (?, ?, ?, ?, NOW(), ?, ?)
            ");
            
            return $stmt->execute([
                $recipient,
                $subject,
                $status,
                $errorMessage,
                $userId,
                $emailType
            ]);
        } catch (Exception $e) {
            error_log("Failed to log email: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get the last error message
     * 
     * @return string Last error message
     */
    public function getLastError() {
        return $this->lastError;
    }
    
    /**
     * Send a welcome email to a new user
     * 
     * @param array $userData User data including email, username, etc.
     * @return bool Whether the email was sent successfully
     */
    public function sendWelcomeEmail($userData) {
        $to = $userData['email'];
        $subject = "Welcome to " . ($this->settings['site_name'] ?? 'Features Gym');
        
        // Create email body
        $body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e5e7eb; border-radius: 8px;'>
            <div style='text-align: center; margin-bottom: 20px;'>
                <img src='" . ($_ENV['APP_URL'] ?? 'https://featuresgym.com') . "/assets/images/logo.png' alt='Features Gym Logo' style='max-width: 150px;'>
            </div>
            
            <h1 style='color: #4f46e5; text-align: center; margin-bottom: 20px;'>Welcome to Features Gym!</h1>
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>Hello " . htmlspecialchars($userData['username']) . ",</p>
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>Thank you for joining Features Gym! We're excited to have you as part of our fitness community.</p>
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>With your new account, you can:</p>
            
            <ul style='color: #4b5563; line-height: 1.6; margin-bottom: 20px;'>
                <li>Discover top-rated gyms near you</li>
                <li>Book workout sessions with flexible membership plans</li>
                <li>Track your fitness progress</li>
                <li>Connect with other fitness enthusiasts</li>
            </ul>
            
            <div style='text-align: center; margin: 30px 0;'>
                <a href='" . ($_ENV['APP_URL'] ?? 'https://featuresgym.com') . "/login.php' style='display: inline-block; background: linear-gradient(to right, #4f46e5, #3b82f6); color: white; text-decoration: none; padding: 12px 24px; border-radius: 6px; font-weight: bold;'>Login to Your Account</a>
            </div>
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>If you have any questions or need assistance, please don't hesitate to contact our support team.</p>
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>Best regards,<br>The Features Gym Team</p>
            
            <div style='margin-top: 30px; padding-top: 20px; border-top: 1px solid #e5e7eb; text-align: center; color: #9ca3af; font-size: 12px;'>
                <p>© " . date('Y') . " Features Gym. All rights reserved.</p>
                <p>If you did not create this account, please ignore this email or <a href='" . ($_ENV['APP_URL'] ?? 'https://featuresgym.com') . "/contact.php' style='color: #4f46e5;'>contact us</a>.</p>
            </div>
        </div>
        ";
        
        // Send the email
        return $this->sendEmail(
            $to, 
            $subject, 
            $body, 
            [], 
            [
                'user_id' => $userData['id'] ?? 0,
                'email_type' => 'welcome',
                'queue' => true, // Queue for better performance
                'priority' => 2 // Higher priority for welcome emails
            ]
        );
    }
    
    /**
     * Send a password reset email
     * 
     * @param string $email User's email address
     * @param string $resetToken Password reset token
     * @param string $resetLink Full URL to reset password
     * @return bool Whether the email was sent successfully
     */
    public function sendPasswordResetEmail($email, $resetToken, $resetLink) {
        $subject = "Password Reset Request - " . ($this->settings['site_name'] ?? 'Features Gym');
        
        // Create email body
        $body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e5e7eb; border-radius: 8px;'>
            <div style='text-align: center; margin-bottom: 20px;'>
                <img src='" . ($_ENV['APP_URL'] ?? 'https://featuresgym.com') . "/assets/images/logo.png' alt='Features Gym Logo' style='max-width: 150px;'>
            </div>
            
            <h1 style='color: #4f46e5; text-align: center; margin-bottom: 20px;'>Password Reset Request</h1>
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>Hello,</p>
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>We received a request to reset your password. If you didn't make this request, you can safely ignore this email.</p>
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>To reset your password, click the button below:</p>
            
            <div style='text-align: center; margin: 30px 0;'>
                <a href='" . htmlspecialchars($resetLink) . "' style='display: inline-block; background: linear-gradient(to right, #4f46e5, #3b82f6); color: white; text-decoration: none; padding: 12px 24px; border-radius: 6px; font-weight: bold;'>Reset Password</a>
            </div>
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>Or copy and paste this link into your browser:</p>
            
            <p style='background-color: #f3f4f6; padding: 10px; border-radius: 4px; word-break: break-all; font-size: 14px;'>" . htmlspecialchars($resetLink) . "</p>
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>This link will expire in 1 hour for security reasons.</p>
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>If you have any questions, please contact our support team.</p>
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>Best regards,<br>The Features Gym Team</p>
            
            <div style='margin-top: 30px; padding-top: 20px; border-top: 1px solid #e5e7eb; text-align: center; color: #9ca3af; font-size: 12px;'>
                <p>© " . date('Y') . " Features Gym. All rights reserved.</p>
                <p>If you did not request a password reset, please <a href='" . ($_ENV['APP_URL'] ?? 'https://featuresgym.com') . "/contact.php' style='color: #4f46e5;'>contact us</a> immediately.</p>
            </div>
        </div>
        ";
        
        // Send the email with high priority (not queued for password resets)
        return $this->sendEmail(
            $email, 
            $subject, 
            $body, 
            [], 
            [
                'email_type' => 'password_reset',
                'priority' => 3 // Highest priority
            ]
        );
    }
    
    /**
     * Send an OTP verification email
     * 
     * @param string $email User's email address
     * @param string $otp One-time password for verification
     * @return bool Whether the email was sent successfully
     */
    public function sendOTPEmail($email, $otp) {
        $subject = "Your Verification Code - " . ($this->settings['site_name'] ?? 'Features Gym');
        
        // Create email body
        $body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e5e7eb; border-radius: 8px;'>
            <div style='text-align: center; margin-bottom: 20px;'>
                <img src='" . ($_ENV['APP_URL'] ?? 'https://featuresgym.com') . "/assets/images/logo.png' alt='Features Gym Logo' style='max-width: 150px;'>
            </div>
            
            <h1 style='color: #4f46e5; text-align: center; margin-bottom: 20px;'>Your Verification Code</h1>
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>Hello,</p>
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>Please use the following verification code to complete your request:</p>
            
            <div style='text-align: center; margin: 30px 0;'>
                <div style='font-size: 32px; letter-spacing: 8px; font-weight: bold; background-color: #f3f4f6; padding: 15px; border-radius: 8px; display: inline-block;'>" . htmlspecialchars($otp) . "</div>
            </div>
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>This code will expire in 10 minutes for security reasons.</p>
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>If you did not request this code, please ignore this email or contact our support team if you have concerns.</p>
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>Best regards,<br>The Features Gym Team</p>
            
            <div style='margin-top: 30px; padding-top: 20px; border-top: 1px solid #e5e7eb; text-align: center; color: #9ca3af; font-size: 12px;'>
                <p>© " . date('Y') . " Features Gym. All rights reserved.</p>
                <p>This is an automated message, please do not reply.</p>
            </div>
        </div>
        ";
        
        // Send the email with high priority (not queued for OTP)
        return $this->sendEmail(
            $email, 
            $subject, 
            $body, 
            [], 
            [
                'email_type' => 'otp_verification',
                'priority' => 3 // Highest priority
            ]
        );
    }
    
    /**
     * Send a test email
     * 
     * @param string $to Recipient email address
     * @return bool Whether the email was sent successfully
     */
    public function sendTestEmail($to) {
        $subject = "Test Email from " . ($this->settings['site_name'] ?? 'Features Gym');
        
        // Create email body
        $body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e5e7eb; border-radius: 8px;'>
            <div style='text-align: center; margin-bottom: 20px;'>
                <img src='" . ($_ENV['APP_URL'] ?? 'https://featuresgym.com') . "/assets/images/logo.png' alt='Features Gym Logo' style='max-width: 150px;'>
            </div>
            
            <h1 style='color: #4f46e5; text-align: center; margin-bottom: 20px;'>Test Email</h1>
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>Hello,</p>
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>This is a test email from Features Gym to verify that your email configuration is working correctly.</p>
            
            <div style='background-color: #f3f4f6; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                <h3 style='color: #374151; margin-top: 0;'>Email Configuration Details:</h3>
                <ul style='color: #4b5563; padding-left: 20px;'>
                    <li>SMTP Host: " . htmlspecialchars($this->settings['smtp_host'] ?? 'Not configured') . "</li>
                    <li>SMTP Port: " . htmlspecialchars($this->settings['smtp_port'] ?? 'Not configured') . "</li>
                    <li>SMTP Encryption: " . htmlspecialchars($this->settings['smtp_encryption'] ?? 'Not configured') . "</li>
                    <li>From Email: " . htmlspecialchars($this->settings['smtp_from_email'] ?? 'Not configured') . "</li>
                </ul>
            </div>
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>If you received this email, your email configuration is working correctly!</p>
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>Best regards,<br>The Features Gym Team</p>
            
                        <div style='margin-top: 30px; padding-top: 20px; border-top: 1px solid #e5e7eb; text-align: center; color: #9ca3af; font-size: 12px;'>
                <p>© " . date('Y') . " Features Gym. All rights reserved.</p>
                <p>This is a test email sent at " . date('Y-m-d H:i:s') . "</p>
            </div>
        </div>
        ";
        
        // Send the email with high priority
        return $this->sendEmail(
            $to, 
            $subject, 
            $body, 
            [], 
            [
                'email_type' => 'test_email',
                'priority' => 3 // Highest priority
            ]
        );
    }
    
    /**
     * Send a notification email to gym owner about new booking
     * 
     * @param array $bookingData Booking details
     * @param array $userData User data
     * @param array $gymData Gym data
     * @return bool Whether the email was sent successfully
     */
    public function sendBookingNotificationToOwner($bookingData, $userData, $gymData) {
        $to = $gymData['owner_email'];
        $subject = "New Booking Notification - " . ($this->settings['site_name'] ?? 'Features Gym');
        
        // Create email body
        $body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e5e7eb; border-radius: 8px;'>
            <div style='text-align: center; margin-bottom: 20px;'>
                <img src='" . ($_ENV['APP_URL'] ?? 'https://featuresgym.com') . "/assets/images/logo.png' alt='Features Gym Logo' style='max-width: 150px;'>
            </div>
            
            <h1 style='color: #4f46e5; text-align: center; margin-bottom: 20px;'>New Booking Notification</h1>
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>Hello " . htmlspecialchars($gymData['owner_name']) . ",</p>
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>You have received a new booking for your gym " . htmlspecialchars($gymData['name']) . ".</p>
            
            <div style='background-color: #f3f4f6; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                <h3 style='color: #374151; margin-top: 0;'>Booking Details:</h3>
                <ul style='color: #4b5563; padding-left: 20px;'>
                    <li><strong>Booking ID:</strong> " . htmlspecialchars($bookingData['booking_id']) . "</li>
                    <li><strong>Date:</strong> " . htmlspecialchars($bookingData['date']) . "</li>
                    <li><strong>Time Slot:</strong> " . htmlspecialchars($bookingData['time_slot']) . "</li>
                    <li><strong>User:</strong> " . htmlspecialchars($userData['username']) . "</li>
                    <li><strong>User Email:</strong> " . htmlspecialchars($userData['email']) . "</li>
                    <li><strong>User Phone:</strong> " . htmlspecialchars($userData['phone'] ?? 'Not provided') . "</li>
                </ul>
            </div>
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>You can view and manage all bookings from your gym dashboard.</p>
            
            <div style='text-align: center; margin: 30px 0;'>
                <a href='" . ($_ENV['APP_URL'] ?? 'https://featuresgym.com') . "/gym/bookings.php' style='display: inline-block; background: linear-gradient(to right, #4f46e5, #3b82f6); color: white; text-decoration: none; padding: 12px 24px; border-radius: 6px; font-weight: bold;'>View Bookings</a>
            </div>
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>Thank you for being a partner with Features Gym!</p>
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>Best regards,<br>The Features Gym Team</p>
            
            <div style='margin-top: 30px; padding-top: 20px; border-top: 1px solid #e5e7eb; text-align: center; color: #9ca3af; font-size: 12px;'>
                <p>© " . date('Y') . " Features Gym. All rights reserved.</p>
                <p>This is an automated message, please do not reply.</p>
            </div>
        </div>
        ";
        
        // Send the email
        return $this->sendEmail(
            $to, 
            $subject, 
            $body, 
            [], 
            [
                'email_type' => 'booking_notification',
                'queue' => true, // Queue for better performance
                'priority' => 2 // Medium priority
            ]
        );
    }
    
    /**
     * Send a membership expiry reminder email
     * 
     * @param array $membershipData Membership details
     * @param array $userData User data
     * @param array $gymData Gym data
     * @return bool Whether the email was sent successfully
     */
    public function sendMembershipExpiryReminder($membershipData, $userData, $gymData) {
        $to = $userData['email'];
        $subject = "Membership Expiry Reminder - " . ($this->settings['site_name'] ?? 'Features Gym');
        
        // Calculate days remaining
        $expiryDate = new DateTime($membershipData['end_date']);
        $currentDate = new DateTime();
        $daysRemaining = $currentDate->diff($expiryDate)->days;
        
        // Create email body
        $body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e5e7eb; border-radius: 8px;'>
            <div style='text-align: center; margin-bottom: 20px;'>
                <img src='" . ($_ENV['APP_URL'] ?? 'https://featuresgym.com') . "/assets/images/logo.png' alt='Features Gym Logo' style='max-width: 150px;'>
            </div>
            
            <h1 style='color: #4f46e5; text-align: center; margin-bottom: 20px;'>Membership Expiry Reminder</h1>
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>Hello " . htmlspecialchars($userData['username']) . ",</p>
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>This is a friendly reminder that your membership at " . htmlspecialchars($gymData['name']) . " will expire in <strong>" . $daysRemaining . " days</strong>.</p>
            
            <div style='background-color: #f3f4f6; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                <h3 style='color: #374151; margin-top: 0;'>Membership Details:</h3>
                <ul style='color: #4b5563; padding-left: 20px;'>
                    <li><strong>Membership Plan:</strong> " . htmlspecialchars($membershipData['plan_name']) . "</li>
                    <li><strong>Start Date:</strong> " . htmlspecialchars($membershipData['start_date']) . "</li>
                    <li><strong>End Date:</strong> " . htmlspecialchars($membershipData['end_date']) . "</li>
                    <li><strong>Gym:</strong> " . htmlspecialchars($gymData['name']) . "</li>
                </ul>
            </div>
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>To ensure uninterrupted access to the gym facilities, please renew your membership before it expires.</p>
            
            <div style='text-align: center; margin: 30px 0;'>
                <a href='" . ($_ENV['APP_URL'] ?? 'https://featuresgym.com') . "/renew_membership.php?id=" . $membershipData['membership_id'] . "' style='display: inline-block; background: linear-gradient(to right, #4f46e5, #3b82f6); color: white; text-decoration: none; padding: 12px 24px; border-radius: 6px; font-weight: bold;'>Renew Membership</a>
            </div>
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>If you have any questions or need assistance, please don't hesitate to contact our support team.</p>
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>Best regards,<br>The Features Gym Team</p>
            
            <div style='margin-top: 30px; padding-top: 20px; border-top: 1px solid #e5e7eb; text-align: center; color: #9ca3af; font-size: 12px;'>
                <p>© " . date('Y') . " Features Gym. All rights reserved.</p>
                <p>This is an automated message, please do not reply.</p>
            </div>
        </div>
        ";
        
        // Send the email
        return $this->sendEmail(
            $to, 
            $subject, 
            $body, 
            [], 
            [
                'user_id' => $userData['id'] ?? 0,
                'email_type' => 'membership_expiry',
                'queue' => true, // Queue for better performance
                'priority' => 2 // Medium priority
            ]
        );
    }
    
    /**
     * Send a notification email
     * 
     * @param string $to Recipient email address
     * @param string $subject Email subject
     * @param string $message Notification message
     * @param array $options Additional options
     * @return bool Whether the email was sent successfully
     */
    public function sendNotificationEmail($to, $subject, $message, $options = []) {
        // Create email body
        $body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e5e7eb; border-radius: 8px;'>
            <div style='text-align: center; margin-bottom: 20px;'>
                <img src='" . ($_ENV['APP_URL'] ?? 'https://featuresgym.com') . "/assets/images/logo.png' alt='Features Gym Logo' style='max-width: 150px;'>
            </div>
            
            <h1 style='color: #4f46e5; text-align: center; margin-bottom: 20px;'>" . htmlspecialchars($subject) . "</h1>
            
            <div style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>
                " . $message . "
            </div>
            
            " . (isset($options['action_url']) ? "
            <div style='text-align: center; margin: 30px 0;'>
                <a href='" . htmlspecialchars($options['action_url']) . "' style='display: inline-block; background: linear-gradient(to right, #4f46e5, #3b82f6); color: white; text-decoration: none; padding: 12px 24px; border-radius: 6px; font-weight: bold;'>" . htmlspecialchars($options['action_text'] ?? 'View Details') . "</a>
            </div>
            " : "") . "
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>If you have any questions or need assistance, please don't hesitate to contact our support team.</p>
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>Best regards,<br>The Features Gym Team</p>
            
            <div style='margin-top: 30px; padding-top: 20px; border-top: 1px solid #e5e7eb; text-align: center; color: #9ca3af; font-size: 12px;'>
                <p>© " . date('Y') . " Features Gym. All rights reserved.</p>
                <p>This is an automated message, please do not reply.</p>
            </div>
        </div>
        ";
        
        // Set default options
        $emailOptions = [
            'email_type' => 'notification',
            'queue' => isset($options['urgent']) && $options['urgent'] ? false : true,
            'priority' => isset($options['urgent']) && $options['urgent'] ? 3 : 1
        ];
        
        // Merge with provided options
        if (isset($options['user_id'])) {
            $emailOptions['user_id'] = $options['user_id'];
        }
        
        // Send the email
        return $this->sendEmail(
            $to, 
            $subject, 
            $body, 
            [], 
            $emailOptions
        );
    }
    
    /**
     * Process the email queue
     * 
     * @param int $limit Maximum number of emails to process
     * @return array Result with count of processed emails and any errors
     */
    public function processEmailQueue($limit = 50) {
        if (!$this->conn) {
            $this->initDatabase();
            
            if (!$this->conn) {
                return [
                    'success' => false,
                    'processed' => 0,
                    'errors' => ["Database connection not available"]
                ];
            }
        }
        
        $processed = 0;
        $errors = [];
        
        try {
            // Get emails from queue, ordered by priority (higher first) and creation time
            $stmt = $this->conn->prepare("
                SELECT * FROM email_queue 
                WHERE status = 'pending' 
                AND (attempts < max_attempts OR max_attempts = 0)
                ORDER BY priority DESC, created_at ASC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            
            while ($email = $stmt->fetch(PDO::FETCH_ASSOC)) {
                try {
                                        // Update status to processing
                                        $updateStmt = $this->conn->prepare("
                                        UPDATE email_queue 
                                        SET status = 'processing', 
                                            processing_started_at = NOW(),
                                            attempts = attempts + 1
                                        WHERE id = ?
                                    ");
                                    $updateStmt->execute([$email['id']]);
                                    
                                    // Parse attachments if any
                                    $attachments = [];
                                    if (!empty($email['attachments'])) {
                                        $attachments = json_decode($email['attachments'], true) ?: [];
                                    }
                                    
                                    // Parse additional options if any
                                    $options = [];
                                    if (!empty($email['options'])) {
                                        $options = json_decode($email['options'], true) ?: [];
                                    }
                                    
                                    // Send the email
                                    $result = $this->sendEmail(
                                        $email['recipient'],
                                        $email['subject'],
                                        $email['body'],
                                        $attachments,
                                        $options
                                    );
                                    
                                    if ($result) {
                                        // Update status to sent
                                        $updateStmt = $this->conn->prepare("
                                            UPDATE email_queue 
                                            SET status = 'sent', 
                                                sent_at = NOW(),
                                                error_message = NULL
                                            WHERE id = ?
                                        ");
                                        $updateStmt->execute([$email['id']]);
                                        $processed++;
                                    } else {
                                        // Update with error
                                        $updateStmt = $this->conn->prepare("
                                            UPDATE email_queue 
                                            SET status = ?, 
                                                error_message = ?
                                            WHERE id = ?
                                        ");
                                        
                                        // If max attempts reached, mark as failed, otherwise back to pending
                                        $newStatus = ($email['attempts'] >= $email['max_attempts'] && $email['max_attempts'] > 0) 
                                            ? 'failed' 
                                            : 'pending';
                                        
                                        $updateStmt->execute([$newStatus, $this->lastError, $email['id']]);
                                        $errors[] = "Failed to send email ID {$email['id']} to {$email['recipient']}: {$this->lastError}";
                                    }
                                } catch (Exception $e) {
                                    // Handle exception for this specific email
                                    $updateStmt = $this->conn->prepare("
                                        UPDATE email_queue 
                                        SET status = 'error', 
                                            error_message = ?
                                        WHERE id = ?
                                    ");
                                    $updateStmt->execute([$e->getMessage(), $email['id']]);
                                    $errors[] = "Exception processing email ID {$email['id']}: " . $e->getMessage();
                                }
                            }
                            
                            return [
                                'success' => true,
                                'processed' => $processed,
                                'errors' => $errors
                            ];
                        } catch (Exception $e) {
                            return [
                                'success' => false,
                                'processed' => $processed,
                                'errors' => ["Failed to process email queue: " . $e->getMessage()]
                            ];
                        }
                    }
                    
                    /**
                     * Clean up old emails from the queue
                     * 
                     * @param int $days Number of days to keep emails
                     * @return bool Whether the cleanup was successful
                     */
                    public function cleanupEmailQueue($days = 30) {
                        if (!$this->conn) {
                            $this->initDatabase();
                            
                            if (!$this->conn) {
                                return false;
                            }
                        }
                        
                        try {
                            // Delete old sent or failed emails
                            $stmt = $this->conn->prepare("
                                DELETE FROM email_queue 
                                WHERE (status = 'sent' OR status = 'failed') 
                                AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
                            ");
                            $stmt->execute([$days]);
                            
                            return true;
                        } catch (Exception $e) {
                            error_log("Failed to clean up email queue: " . $e->getMessage());
                            return false;
                        }
                    }
                    
                    /**
                     * Get email queue statistics
                     * 
                     * @return array Statistics about the email queue
                     */
                    public function getQueueStatistics() {
                        if (!$this->conn) {
                            $this->initDatabase();
                            
                            if (!$this->conn) {
                                return [
                                    'total' => 0,
                                    'pending' => 0,
                                    'processing' => 0,
                                    'sent' => 0,
                                    'failed' => 0,
                                    'error' => 0
                                ];
                            }
                        }
                        
                        try {
                            $stats = [
                                'total' => 0,
                                'pending' => 0,
                                'processing' => 0,
                                'sent' => 0,
                                'failed' => 0,
                                'error' => 0
                            ];
                            
                            // Get total count
                            $stmt = $this->conn->prepare("SELECT COUNT(*) FROM email_queue");
                            $stmt->execute();
                            $stats['total'] = $stmt->fetchColumn();
                            
                            // Get counts by status
                            $stmt = $this->conn->prepare("
                                SELECT status, COUNT(*) as count 
                                FROM email_queue 
                                GROUP BY status
                            ");
                            $stmt->execute();
                            
                            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                $status = $row['status'];
                                if (isset($stats[$status])) {
                                    $stats[$status] = (int)$row['count'];
                                }
                            }
                            
                            return $stats;
                        } catch (Exception $e) {
                            error_log("Failed to get email queue statistics: " . $e->getMessage());
                            return [
                                'total' => 0,
                                'pending' => 0,
                                'processing' => 0,
                                'sent' => 0,
                                'failed' => 0,
                                'error' => 0,
                                'error_message' => $e->getMessage()
                            ];
                        }
                    }
                }
                ?>
                


