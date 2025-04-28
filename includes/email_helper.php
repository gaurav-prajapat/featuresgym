<?php
require_once 'EmailService.php';

/**
 * Email Helper Functions
 * 
 * This file contains helper functions for sending various types of emails
 * throughout the application.
 */

/**
 * Send a welcome email to a new user
 * 
 * @param array $userData User data including email, name, etc.
 * @return bool Whether the email was sent successfully
 */
function sendWelcomeEmail($userData) {
    $emailService = new EmailService();
    return $emailService->sendWelcomeEmail($userData);
}

/**
 * Send a password reset email
 * 
 * @param string $email User's email address
 * @param string $resetToken Password reset token
 * @param string $resetLink Full URL to reset password
 * @return bool Whether the email was sent successfully
 */
function sendPasswordResetEmail($email, $resetToken, $resetLink) {
    $emailService = new EmailService();
    return $emailService->sendPasswordResetEmail($email, $resetToken, $resetLink);
}

/**
 * Send a verification email with OTP
 * 
 * @param string $email User's email address
 * @param string $otp One-time password for verification
 * @return bool Whether the email was sent successfully
 */
function sendVerificationEmail($email, $otp) {
    $emailService = new EmailService();
    return $emailService->sendOTPEmail($email, $otp);
}

/**
 * Send a booking confirmation email
 * 
 * @param array $bookingData Booking details
 * @param array $userData User data
 * @param array $gymData Gym data
 * @return bool Whether the email was sent successfully
 */
function sendBookingConfirmationEmail($bookingData, $userData, $gymData) {
    $emailService = new EmailService();
    
    $subject = "Booking Confirmation - " . $gymData['name'];
    
    // Create email body
    $body = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                h2 { color: #3182ce; }
                .booking-details { background-color: #f7fafc; padding: 15px; border-radius: 5px; margin: 20px 0; }
                .booking-details p { margin: 5px 0; }
                .footer { margin-top: 30px; font-size: 12px; color: #666; }
            </style>
        </head>
        <body>
            <div class='container'>
                <h2>Booking Confirmation</h2>
                <p>Hello " . htmlspecialchars($userData['username']) . ",</p>
                <p>Your booking at " . htmlspecialchars($gymData['name']) . " has been confirmed.</p>
                
                <div class='booking-details'>
                    <p><strong>Booking ID:</strong> " . htmlspecialchars($bookingData['booking_id']) . "</p>
                    <p><strong>Date:</strong> " . htmlspecialchars($bookingData['date']) . "</p>
                    <p><strong>Time:</strong> " . htmlspecialchars($bookingData['time_slot']) . "</p>
                    <p><strong>Gym:</strong> " . htmlspecialchars($gymData['name']) . "</p>
                    <p><strong>Address:</strong> " . htmlspecialchars($gymData['address']) . "</p>
                </div>
                
                <p>Please arrive 10-15 minutes before your scheduled time.</p>
                <p>If you need to cancel or reschedule, please do so at least 4 hours in advance.</p>
                
                <p>Thank you for using our service!</p>
                
                <div class='footer'>
                    <p>This is an automated message, please do not reply.</p>
                </div>
            </div>
        </body>
        </html>
    ";
    
    return $emailService->sendEmail($userData['email'], $subject, $body);
}

/**
 * Send a payment receipt email
 * 
 * @param array $paymentData Payment details
 * @param array $userData User data
 * @return bool Whether the email was sent successfully
 */
function sendPaymentReceiptEmail($paymentData, $userData) {
    $emailService = new EmailService();
    
    $subject = "Payment Receipt - " . $paymentData['transaction_id'];
    
    // Format amount with currency symbol
    $db = new GymDatabase();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'currency_symbol'");
    $stmt->execute();
    $currencySymbol = $stmt->fetchColumn() ?: '₹';
    
    $formattedAmount = $currencySymbol . number_format($paymentData['amount'], 2);
    
    // Create email body
    $body = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                h2 { color: #3182ce; }
                .receipt { background-color: #f7fafc; padding: 15px; border-radius: 5px; margin: 20px 0; }
                .receipt p { margin: 5px 0; }
                .amount { font-size: 24px; font-weight: bold; color: #2d3748; margin: 15px 0; }
                .footer { margin-top: 30px; font-size: 12px; color: #666; }
            </style>
        </head>
        <body>
            <div class='container'>
                <h2>Payment Receipt</h2>
                <p>Hello " . htmlspecialchars($userData['username']) . ",</p>
                <p>Thank you for your payment. Here are your transaction details:</p>
                
                <div class='receipt'>
                    <p><strong>Transaction ID:</strong> " . htmlspecialchars($paymentData['transaction_id']) . "</p>
                    <p><strong>Date:</strong> " . htmlspecialchars(date('Y-m-d H:i:s', strtotime($paymentData['created_at']))) . "</p>
                    <p><strong>Payment Method:</strong> " . htmlspecialchars($paymentData['payment_method']) . "</p>
                    <div class='amount'>" . $formattedAmount . "</div>
                    <p><strong>Status:</strong> " . htmlspecialchars(ucfirst($paymentData['status'])) . "</p>
                </div>
                
                <p>If you have any questions about this payment, please contact our support team.</p>
                
                <div class='footer'>
                    <p>This is an automated message, please do not reply.</p>
                </div>
            </div>
        </body>
        </html>
    ";
    
    return $emailService->sendEmail($userData['email'], $subject, $body);
}

/**
 * Send a notification to admin about new gym registration
 * 
 * @param array $gymData Gym registration data
 * @return bool Whether the email was sent successfully
 */
function notifyAdminAboutGymRegistration($gymData) {
    // Check if admin notifications are enabled
    $db = new GymDatabase();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'gym_registration_notification'");
    $stmt->execute();
    $notificationsEnabled = $stmt->fetchColumn();
    
    if ($notificationsEnabled !== '1') {
        return true; // Skip sending if notifications are disabled
    }
    
    // Get admin email
    $stmt = $conn->prepare("
        SELECT u.email 
        FROM users u 
        WHERE u.role = 'admin' 
        LIMIT 1
    ");
    $stmt->execute();
    $adminEmail = $stmt->fetchColumn();
    
    if (!$adminEmail) {
        error_log("No admin email found for gym registration notification");
        return false;
    }
    
    $emailService = new EmailService();
    
    $subject = "New Gym Registration: " . $gymData['name'];
    
    // Create email body
    $body = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                h2 { color: #3182ce; }
                .gym-details { background-color: #f7fafc; padding: 15px; border-radius: 5px; margin: 20px 0; }
                .gym-details p { margin: 5px 0; }
                .button { display: inline-block; background-color: #3182ce; color: white; 
                          padding: 10px 20px; text-decoration: none; border-radius: 4px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <h2>New Gym Registration</h2>
                <p>A new gym has registered on the platform:</p>
                
                <div class='gym-details'>
                    <p><strong>Gym Name:</strong> " . htmlspecialchars($gymData['name']) . "</p>
                    <p><strong>Owner:</strong> " . htmlspecialchars($gymData['owner_name']) . "</p>
                    <p><strong>Email:</strong> " . htmlspecialchars($gymData['email']) . "</p>
                    <p><strong>Phone:</strong> " . htmlspecialchars($gymData['phone']) . "</p>
                    <p><strong>Address:</strong> " . htmlspecialchars($gymData['address']) . "</p>
                    <p><strong>City:</strong> " . htmlspecialchars($gymData['city']) . "</p>
                </div>
                
                <p>Please review this registration and approve or reject it from the admin dashboard.</p>
                
                <p><a href='" . ($_SERVER['HTTP_HOST'] ?? 'https://featuresgym.com') . "/admin/gyms.php' class='button'>View in Admin Panel</a></p>
            </div>
        </body>
        </html>
    ";
    
    return $emailService->sendEmail($adminEmail, $subject, $body);
}

/**
 * Clean up old email logs based on retention policy
 * 
 * @return bool Whether the cleanup was successful
 */
function cleanupOldEmailLogs() {
    $db = new GymDatabase();
    $conn = $db->getConnection();
    
    // Get retention period from settings
    $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'log_retention_days'");
    $stmt->execute();
    $retentionDays = (int)($stmt->fetchColumn() ?: 30);
    
    try {
        $stmt = $conn->prepare("
            DELETE FROM email_logs 
            WHERE sent_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([$retentionDays]);
        
        return true;
    } catch (Exception $e) {
        error_log("Failed to clean up old email logs: " . $e->getMessage());
        return false;
    }
}
?>
