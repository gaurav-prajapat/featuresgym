<?php
require_once '../../config/database.php';
session_start();

// Check if user is logged in as admin
if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Check if required parameters are provided
if (!isset($_POST['email']) || !isset($_POST['smtp_host']) || !isset($_POST['smtp_port']) || 
    !isset($_POST['smtp_username']) || !isset($_POST['smtp_encryption'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit();
}

$to = $_POST['email'];
$smtp_host = $_POST['smtp_host'];
$smtp_port = $_POST['smtp_port'];
$smtp_username = $_POST['smtp_username'];
$smtp_password = $_POST['smtp_password'];
$smtp_encryption = $_POST['smtp_encryption'];

// Validate email
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email address']);
    exit();
}

// Validate SMTP settings
if (empty($smtp_host) || empty($smtp_port) || empty($smtp_username)) {
    echo json_encode(['success' => false, 'message' => 'SMTP settings are incomplete']);
    exit();
}

try {
    // Include PHPMailer
    require '../../vendor/autoload.php';
    
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    
    // Server settings
    $mail->SMTPDebug = 0;
    $mail->isSMTP();
    $mail->Host = $smtp_host;
    $mail->Port = $smtp_port;
    $mail->SMTPAuth = true;
    $mail->Username = $smtp_username;
    $mail->Password = $smtp_password;
    
    if ($smtp_encryption === 'tls') {
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    } elseif ($smtp_encryption === 'ssl') {
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    }
    
    // Recipients
    $mail->setFrom($smtp_username, 'FlexFit Admin');
    $mail->addAddress($to);
    
    // Content
    $mail->isHTML(true);
    $mail->Subject = 'FlexFit SMTP Test Email';
    $mail->Body = '
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px;">
            <h2 style="color: #333;">SMTP Test Successful!</h2>
            <p style="color: #666; line-height: 1.5;">
                This email confirms that your SMTP settings are configured correctly in the FlexFit admin panel.
            </p>
            <div style="background-color: #f5f5f5; padding: 15px; border-radius: 5px; margin-top: 20px;">
                <p style="margin: 0; color: #888;">
                    This is an automated message sent from the FlexFit admin panel. Please do not reply to this email.
                </p>
            </div>
        </div>
    ';
    $mail->AltBody = 'SMTP Test Successful! This email confirms that your SMTP settings are configured correctly in the FlexFit admin panel.';
    
    $mail->send();
    
    // Log the activity
    $db = new GymDatabase();
    $conn = $db->getConnection();
    
    $adminId = $_SESSION['admin_id'];
    $activitySql = "
        INSERT INTO activity_logs (
            user_id, user_type, action, details, ip_address, user_agent
        ) VALUES (
            ?, 'admin', ?, ?, ?, ?
        )
    ";
    $details = "Admin ID: {$adminId} sent a test email to: {$to}";
    $activityStmt = $conn->prepare($activitySql);
    $activityStmt->execute([
        $adminId,
        'send_test_email',
        $details,
        $_SERVER['REMOTE_ADDR'],
        $_SERVER['HTTP_USER_AGENT']
    ]);
    
    echo json_encode(['success' => true, 'message' => 'Test email sent successfully']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
