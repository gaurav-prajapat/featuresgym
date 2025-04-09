<?php
class EmailService {
    private $smtpHost;
    private $smtpPort;
    private $smtpUsername;
    private $smtpPassword;
    private $fromEmail;
    private $fromName;
    
    public function __construct() {
        // Configure SMTP from environment variables
        $this->smtpHost = $_ENV['SMTP_HOST'] ?? 'smtp.gmail.com';
        $this->smtpPort = $_ENV['SMTP_PORT'] ?? 587;
        $this->smtpUsername = $_ENV['SMTP_USERNAME'] ?? '';
        $this->smtpPassword = $_ENV['SMTP_PASSWORD'] ?? '';
        $this->fromEmail = $_ENV['SMTP_FROM_EMAIL'] ?? 'noreply@profitmart.com';
        $this->fromName = $_ENV['SMTP_FROM_NAME'] ?? 'ProFitMart';
    }
    
    /**
     * Send an email using PHP's mail() function
     * 
     * @param string $to Recipient email
     * @param string $subject Email subject
     * @param string $body Email body (HTML)
     * @param string $altBody Plain text alternative (optional)
     * @param array $attachments Array of file paths to attach (optional)
     * @return bool Success status
     */
    public function sendEmail($to, $subject, $body, $altBody = '', $attachments = []) {
        try {
            // Headers
            $headers = "From: {$this->fromName} <{$this->fromEmail}>\r\n";
            $headers .= "Reply-To: {$this->fromEmail}\r\n";
            $headers .= "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            
            // Send email using PHP's mail() function
            return mail($to, $subject, $body, $headers);
        } catch (Exception $e) {
            // Log the error
            error_log("Email sending failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send an email
     * 
     * @param string $to Recipient email
     * @param string $subject Email subject
     * @param string $body Email body (HTML)
     * @param string $altBody Plain text alternative (optional)
     * @param array $attachments Array of file paths to attach (optional)
     * @return bool Success status
     */
   public function send($to, $subject, $body, $altBody = '', $attachments = []) {
        try {
            // Headers
            $headers = "From: {$this->fromName} <{$this->fromEmail}>\r\n";
            $headers .= "Reply-To: {$this->fromEmail}\r\n";
            $headers .= "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";

            // Send email using PHP's mail() function
            return mail($to, $subject, $body, $headers);
        } catch (Exception $e) {
            // Log the error
            error_log("Email sending failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send a welcome email to a new user
     * 
     * @param string $to Recipient email
     * @param string $username Username
     * @return bool Success status
     */
    public function sendWelcomeEmail($to, $username) {
        $subject = "Welcome to ProFitMart!";
        
        $body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e5e7eb; border-radius: 8px;'>
            <div style='text-align: center; margin-bottom: 20px;'>
                <div style='display: inline-block; width: 80px; height: 80px; background: linear-gradient(to right, #4f46e5, #3b82f6); border-radius: 12px; position: relative;'>
                    <div style='position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: white; font-size: 24px; font-weight: bold;'>PFM</div>
                    <div style='position: absolute; bottom: 8px; right: 8px; width: 12px; height: 12px; background-color: #f97316; border-radius: 50%;'></div>
                </div>
            </div>
            
            <h1 style='color: #1f2937; text-align: center; margin-bottom: 20px;'>Welcome to ProFitMart!</h1>
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>Hello <strong>$username</strong>,</p>
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>Thank you for joining ProFitMart! We're excited to have you as part of our fitness community.</p>
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>With your new account, you can:</p>
            
            <ul style='color: #4b5563; line-height: 1.6; margin-bottom: 20px;'>
                <li>Discover top-rated gyms near you</li>
                <li>Book workout sessions with flexible membership plans</li>
                <li>Track your fitness progress</li>
                <li>Connect with other fitness enthusiasts</li>
            </ul>
            
            <div style='text-align: center; margin: 30px 0;'>
                <a href='" . ($_ENV['APP_URL'] ?? 'https://profitmart.com') . "/login.php' style='display: inline-block; background: linear-gradient(to right, #4f46e5, #3b82f6); color: white; text-decoration: none; padding: 12px 24px; border-radius: 6px; font-weight: bold;'>Login to Your Account</a>
            </div>
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>If you have any questions or need assistance, please don't hesitate to contact our support team.</p>
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>Best regards,<br>The ProFitMart Team</p>
            
            <div style='margin-top: 30px; padding-top: 20px; border-top: 1px solid #e5e7eb; text-align: center; color: #9ca3af; font-size: 12px;'>
                <p>© " . date('Y') . " ProFitMart. All rights reserved.</p>
                <p>If you did not create this account, please ignore this email or <a href='" . ($_ENV['APP_URL'] ?? 'https://profitmart.com') . "/contact.php' style='color: #4f46e5;'>contact us</a>.</p>
            </div>
        </div>
        ";
        
        return $this->sendEmail($to, $subject, $body);
    }
    
    /**
     * Send an OTP verification email
     * 
     * @param string $to Recipient email
     * @param string $otp One-time password
     * @return bool Success status
     */
    public function sendOTPEmail($to, $otp) {
        $subject = "Your Verification Code";
        
        $body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e5e7eb; border-radius: 8px;'>
            <div style='text-align: center; margin-bottom: 20px;'>
                <div style='display: inline-block; width: 80px; height: 80px; background: linear-gradient(to right, #4f46e5, #3b82f6); border-radius: 12px; position: relative;'>
                    <div style='position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: white; font-size: 24px; font-weight: bold;'>PFM</div>
                    <div style='position: absolute; bottom: 8px; right: 8px; width: 12px; height: 12px; background-color: #f97316; border-radius: 50%;'></div>
                </div>
            </div>
            
            <h1 style='color: #1f2937; text-align: center; margin-bottom: 20px;'>Verify Your Email</h1>
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>To complete your registration, please use the following verification code:</p>
            
            <div style='text-align: center; margin: 30px 0;'>
                <div style='display: inline-block; background-color: #f3f4f6; padding: 15px 30px; border-radius: 6px; letter-spacing: 8px; font-size: 24px; font-weight: bold; color: #1f2937;'>$otp</div>
            </div>
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>This code will expire in 10 minutes for security reasons.</p>
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>If you did not request this code, please ignore this email or contact support if you have concerns.</p>
            
            <div style='margin-top: 30px; padding-top: 20px; border-top: 1px solid #e5e7eb; text-align: center; color: #9ca3af; font-size: 12px;'>
                <p>© " . date('Y') . " ProFitMart. All rights reserved.</p>
                <p>This is an automated message, please do not reply.</p>
            </div>
        </div>
        ";
        
        return $this->sendEmail($to, $subject, $body);
    }
    
    /**
     * Send a password reset email
     * 
     * @param string $to Recipient email
     * @param string $resetToken Reset token
     * @return bool Success status
     */
    public function sendPasswordResetEmail($to, $resetToken) {
        $subject = "Reset Your Password";
        $resetLink = ($_ENV['APP_URL'] ?? 'https://profitmart.com') . "/reset-password.php?token=" . urlencode($resetToken);
        
        $body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e5e7eb; border-radius: 8px;'>
            <div style='text-align: center; margin-bottom: 20px;'>
                <div style='display: inline-block; width: 80px; height: 80px; background: linear-gradient(to right, #4f46e5, #3b82f6); border-radius: 12px; position: relative;'>
                    <div style='position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: white; font-size: 24px; font-weight: bold;'>PFM</div>
                    <div style='position: absolute; bottom: 8px; right: 8px; width: 12px; height: 12px; background-color: #f97316; border-radius: 50%;'></div>
                </div>
            </div>
            
            <h1 style='color: #1f2937; text-align: center; margin-bottom: 20px;'>Reset Your Password</h1>
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>We received a request to reset your password. Click the button below to create a new password:</p>
            
            <div style='text-align: center; margin: 30px 0;'>
                <a href='$resetLink' style='display: inline-block; background: linear-gradient(to right, #4f46e5, #3b82f6); color: white; text-decoration: none; padding: 12px 24px; border-radius: 6px; font-weight: bold;'>Reset Password</a>
            </div>
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>If the button doesn't work, you can copy and paste this link into your browser:</p>
            
            <p style='background-color: #f3f4f6; padding: 10px; border-radius: 4px; word-break: break-all; font-size: 14px;'>$resetLink</p>
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>This link will expire in 1 hour for security reasons.</p>
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>If you did not request a password reset, please ignore this email or contact support if you have concerns.</p>
            
            <div style='margin-top: 30px; padding-top: 20px; border-top: 1px solid #e5e7eb; text-align: center; color: #9ca3af; font-size: 12px;'>
                <p>© " . date('Y') . " ProFitMart. All rights reserved.</p>
                <p>This is an automated message, please do not reply.</p>
            </div>
        </div>
        ";
        
        return $this->sendEmail($to, $subject, $body);
    }
    
    /**
     * Send a membership confirmation email
     * 
     * @param string $to Recipient email
     * @param array $membershipDetails Membership details
     * @return bool Success status
     */
    public function sendMembershipConfirmationEmail($to, $membershipDetails) {
        $subject = "Membership Confirmation";
        
        $gymName = htmlspecialchars($membershipDetails['gym_name'] ?? 'Our Gym');
        $planName = htmlspecialchars($membershipDetails['plan_name'] ?? 'Standard');
        $startDate = htmlspecialchars($membershipDetails['start_date'] ?? date('Y-m-d'));
        $endDate = htmlspecialchars($membershipDetails['end_date'] ?? date('Y-m-d', strtotime('+30 days')));
        $amount = htmlspecialchars($membershipDetails['amount'] ?? '0.00');
        $transactionId = htmlspecialchars($membershipDetails['transaction_id'] ?? 'N/A');
        
        $body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e5e7eb; border-radius: 8px;'>
            <div style='text-align: center; margin-bottom: 20px;'>
                <div style='display: inline-block; width: 80px; height: 80px; background: linear-gradient(to right, #4f46e5, #3b82f6); border-radius: 12px; position: relative;'>
                    <div style='position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: white; font-size: 24px; font-weight: bold;'>PFM</div>
                    <div style='position: absolute; bottom: 8px; right: 8px; width: 12px; height: 12px; background-color: #f97316; border-radius: 50%;'></div>
                </div>
            </div>
            
            <h1 style='color: #1f2937; text-align: center; margin-bottom: 20px;'>Membership Confirmation</h1>
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>Thank you for purchasing a membership at <strong>$gymName</strong>!</p>
            
            <div style='background-color: #f9fafb; border-radius: 8px; padding: 20px; margin-bottom: 20px;'>
                <h2 style='color: #1f2937; font-size: 18px; margin-bottom: 15px;'>Membership Details:</h2>
                
                <table style='width: 100%; border-collapse: collapse;'>
                    <tr>
                        <td style='padding: 8px 0; color: #6b7280; width: 40%;'>Gym:</td>
                        <td style='padding: 8px 0; color: #1f2937; font-weight: 500;'>$gymName</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px 0; color: #6b7280;'>Plan:</td>
                        <td style='padding: 8px 0; color: #1f2937; font-weight: 500;'>$planName</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px 0; color: #6b7280;'>Start Date:</td>
                        <td style='padding: 8px 0; color: #1f2937; font-weight: 500;'>$startDate</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px 0; color: #6b7280;'>End Date:</td>
                        <td style='padding: 8px 0; color: #1f2937; font-weight: 500;'>$endDate</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px 0; color: #6b7280;'>Amount Paid:</td>
                        <td style='padding: 8px 0; color: #1f2937; font-weight: 500;'>₹$amount</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px 0; color: #6b7280;'>Transaction ID:</td>
                        <td style='padding: 8px 0; color: #1f2937; font-weight: 500;'>$transactionId</td>
                    </tr>
                </table>
            </div>
            
            <div style='text-align: center; margin: 30px 0;'>
                <a href='" . ($_ENV['APP_URL'] ?? 'https://profitmart.com') . "/my-memberships.php' style='display: inline-block; background: linear-gradient(to right, #4f46e5, #3b82f6); color: white; text-decoration: none; padding: 12px 24px; border-radius: 6px; font-weight: bold;'>View My Memberships</a>
            </div>
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>If you have any questions about your membership, please contact the gym directly or our support team.</p>
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>Enjoy your workouts!<br>The ProFitMart Team</p>
            
            <div style='margin-top: 30px; padding-top: 20px; border-top: 1px solid #e5e7eb; text-align: center; color: #9ca3af; font-size: 12px;'>
                <p>© " . date('Y') . " ProFitMart. All rights reserved.</p>
                <p>This email serves as your official receipt. Please keep it for your records.</p>
            </div>
        </div>
        ";
        
        return $this->sendEmail($to, $subject, $body);
    }
    
    /**
     * Send a notification email
     * 
     * @param string $to Recipient email
     * @param string $subject Email subject
     * @param string $message Email message
     * @return bool Success status
     */
    public function sendNotificationEmail($to, $subject, $message) {
        $body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e5e7eb; border-radius: 8px;'>
            <div style='text-align: center; margin-bottom: 20px;'>
                <div style='display: inline-block; width: 80px; height: 80px; background: linear-gradient(to right, #4f46e5, #3b82f6); border-radius: 12px; position: relative;'>
                    <div style='position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: white; font-size: 24px; font-weight: bold;'>PFM</div>
                    <div style='position: absolute; bottom: 8px; right: 8px; width: 12px; height: 12px; background-color: #f97316; border-radius: 50%;'></div>
                </div>
            </div>
            
            <h1 style='color: #1f2937; text-align: center; margin-bottom: 20px;'>" . htmlspecialchars($subject) . "</h1>
            
            <div style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>
                " . $message . "
            </div>
            
            <div style='text-align: center; margin: 30px 0;'>
                <a href='" . ($_ENV['APP_URL'] ?? 'https://profitmart.com') . "/notifications.php' style='display: inline-block; background: linear-gradient(to right, #4f46e5, #3b82f6); color: white; text-decoration: none; padding: 12px 24px; border-radius: 6px; font-weight: bold;'>View All Notifications</a>
            </div>
            
            <div style='margin-top: 30px; padding-top: 20px; border-top: 1px solid #e5e7eb; text-align: center; color: #9ca3af; font-size: 12px;'>
                <p>© " . date('Y') . " ProFitMart. All rights reserved.</p>
                <p>You received this email because you have notifications enabled. <a href='" . ($_ENV['APP_URL'] ?? 'https://profitmart.com') . "/settings.php' style='color: #4f46e5;'>Manage email preferences</a>.</p>
            </div>
        </div>
        ";
        
        return $this->sendEmail($to, $subject, $body);
    }
    
    /**
     * Send a booking confirmation email
     * 
     * @param string $to Recipient email
     * @param array $bookingDetails Booking details
     * @return bool Success status
     */
    public function sendBookingConfirmationEmail($to, $bookingDetails) {
        $subject = "Booking Confirmation";
        
        $gymName = htmlspecialchars($bookingDetails['gym_name'] ?? 'Our Gym');
        $bookingDate = htmlspecialchars($bookingDetails['date'] ?? date('Y-m-d'));
        $bookingTime = htmlspecialchars($bookingDetails['time'] ?? '00:00');
        $bookingId = htmlspecialchars($bookingDetails['booking_id'] ?? 'N/A');
        
        $body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e5e7eb; border-radius: 8px;'>
            <div style='text-align: center; margin-bottom: 20px;'>
                <div style='display: inline-block; width: 80px; height: 80px; background: linear-gradient(to right, #4f46e5, #3b82f6); border-radius: 12px; position: relative;'>
                    <div style='position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: white; font-size: 24px; font-weight: bold;'>PFM</div>
                    <div style='position: absolute; bottom: 8px; right: 8px; width: 12px; height: 12px; background-color: #f97316; border-radius: 50%;'></div>
                </div>
            </div>
            
            <h1 style='color: #1f2937; text-align: center; margin-bottom: 20px;'>Booking Confirmation</h1>
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>Your booking at <strong>$gymName</strong> has been confirmed!</p>
            
            <div style='background-color: #f9fafb; border-radius: 8px; padding: 20px; margin-bottom: 20px;'>
                <h2 style='color: #1f2937; font-size: 18px; margin-bottom: 15px;'>Booking Details:</h2>
                
                <table style='width: 100%; border-collapse: collapse;'>
                    <tr>
                        <td style='padding: 8px 0; color: #6b7280; width: 40%;'>Gym:</td>
                        <td style='padding: 8px 0; color: #1f2937; font-weight: 500;'>$gymName</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px 0; color: #6b7280;'>Date:</td>
                        <td style='padding: 8px 0; color: #1f2937; font-weight: 500;'>$bookingDate</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px 0; color: #6b7280;'>Time:</td>
                        <td style='padding: 8px 0; color: #1f2937; font-weight: 500;'>$bookingTime</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px 0; color: #6b7280;'>Booking ID:</td>
                        <td style='padding: 8px 0; color: #1f2937; font-weight: 500;'>$bookingId</td>
                    </tr>
                </table>
            </div>
            
            <div style='text-align: center; margin: 30px 0;'>
                <a href='" . ($_ENV['APP_URL'] ?? 'https://profitmart.com') . "/my-bookings.php' style='display: inline-block; background: linear-gradient(to right, #4f46e5, #3b82f6); color: white; text-decoration: none; padding: 12px 24px; border-radius: 6px; font-weight: bold;'>View My Bookings</a>
            </div>
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>If you need to cancel or reschedule, please do so at least 24 hours in advance.</p>
            
            <p style='color: #4b5563; line-height: 1.6; margin-bottom: 15px;'>Enjoy your workout!<br>The ProFitMart Team</p>
            
            <div style='margin-top: 30px; padding-top: 20px; border-top: 1px solid #e5e7eb; text-align: center; color: #9ca3af; font-size: 12px;'>
                <p>© " . date('Y') . " ProFitMart. All rights reserved.</p>
                <p>This email serves as your booking confirmation. Please keep it for your records.</p>
            </div>
        </div>
        ";
        
        return $this->sendEmail($to, $subject, $body);
    }
}
?>

