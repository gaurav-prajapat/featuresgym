<?php
session_start();
require_once 'config/database.php';
require_once 'includes/EmailService.php';
require_once 'includes/OTPService.php';

// Only process POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('HTTP/1.1 405 Method Not Allowed');
    exit(json_encode(['success' => false, 'message' => 'Method not allowed']));
}

// Check if email is in session
$email = $_SESSION['temp_user_email'] ?? '';

if (empty($email)) {
    exit(json_encode(['success' => false, 'message' => 'Session expired. Please try registering again.']));
}

try {
    $db = new GymDatabase();
    $conn = $db->getConnection();
    $otpService = new OTPService($conn);
    
    // Check for rate limiting (prevent abuse)
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM otp_verifications 
        WHERE email = ? AND updated_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
    ");
    $stmt->execute([$email]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result['count'] >= 3) {
        exit(json_encode([
            'success' => false, 
            'message' => 'Too many attempts. Please try again after 5 minutes.'
        ]));
    }

       // Generate and send new OTP
       $otp = $otpService->generateOTP();
       if ($otpService->storeOTP($email, $otp) && $otpService->sendOTPEmail($email, $otp)) {
           exit(json_encode(['success' => true, 'message' => 'Verification code has been resent to your email.']));
       } else {
           exit(json_encode(['success' => false, 'message' => 'Failed to send verification code. Please try again.']));
       }
   } catch (Exception $e) {
       error_log("Error in resend_otp.php: " . $e->getMessage());
       exit(json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']));
   }
   
?>
