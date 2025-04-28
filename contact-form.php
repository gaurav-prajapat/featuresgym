<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php'; // Include PHPMailer (or include the PHPMailer files manually if not using Composer)
require_once 'config/database.php';

// Get email settings from database
function getSettings() {
    $db = new GymDatabase();
    $conn = $db->getConnection();
    
    $settings = [];
    try {
        $settingsQuery = "SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN 
            ('smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 
             'contact_email', 'site_name')";
        $settingsStmt = $conn->prepare($settingsQuery);
        $settingsStmt->execute();
        
        $settingsRows = $settingsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Transform the result into key-value pairs
        foreach ($settingsRows as $row) {
            if (isset($row['setting_key']) && isset($row['setting_value'])) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        }
    } catch (PDOException $e) {
        error_log("Error fetching email settings: " . $e->getMessage());
    }
    
    return $settings;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get form data
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $subject = isset($_POST['subject']) ? trim($_POST['subject']) : 'New Contact Form Message';
    $message = isset($_POST['message']) ? trim($_POST['message']) : '';

    // Validate data
    if (empty($name) || empty($email) || empty($message)) {
        $error = "All fields are required.";
        // Redirect back with error
        header('Location: contact.php?error=' . urlencode($error));
        exit;
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
        header('Location: contact.php?error=' . urlencode($error));
        exit;
    }

    // Get settings from database
    $settings = getSettings();
    
    // Create an instance of PHPMailer
    $mail = new PHPMailer(true);

    try {
        //Server settings
        $mail->isSMTP();
        $mail->Host = $settings['smtp_host'] ?? 'smtp.example.com';
        $mail->SMTPAuth = true;
        $mail->Username = $settings['smtp_username'] ?? 'notifications@example.com';
        $mail->Password = $settings['smtp_password'] ?? '';
        
        // Set encryption based on settings
        $encryption = $settings['smtp_encryption'] ?? 'tls';
        if ($encryption === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($encryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPAutoTLS = false;
            $mail->SMTPSecure = false;
        }
        
        $mail->Port = $settings['smtp_port'] ?? 587;

        //Recipients
        $mail->setFrom($email, $name);
        $mail->addAddress($settings['contact_email'] ?? 'support@featuresgym.com', $settings['site_name'] ?? 'FeatureGym Admin');
        $mail->addReplyTo($email, $name);

        //Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        
        // Create HTML message body
        $htmlMessage = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                h2 { color: #3182ce; }
                .info { margin-bottom: 20px; }
                .label { font-weight: bold; }
                .message { background-color: #f7fafc; padding: 15px; border-left: 4px solid #3182ce; }
            </style>
        </head>
        <body>
            <div class='container'>
                <h2>New Contact Form Message</h2>
                <div class='info'>
                    <p><span class='label'>Name:</span> {$name}</p>
                    <p><span class='label'>Email:</span> {$email}</p>
                </div>
                <div class='message'>
                    <p>{$message}</p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        $mail->Body = $htmlMessage;
        $mail->AltBody = "Name: $name\nEmail: $email\nMessage: $message";

        // Send the email
        $mail->send();
        
        // Log the contact submission
        $db = new GymDatabase();
        $conn = $db->getConnection();
        
        $logQuery = "INSERT INTO contact_submissions (name, email, subject, message, ip_address, user_agent, status) 
                    VALUES (?, ?, ?, ?, ?, ?, 'received')";
        $stmt = $conn->prepare($logQuery);
        $stmt->execute([
            $name, 
            $email, 
            $subject, 
            $message, 
            $_SERVER['REMOTE_ADDR'], 
            $_SERVER['HTTP_USER_AGENT']
        ]);
        
        // Redirect with success message
        header('Location: contact.php?success=1');
        exit;

    } catch (Exception $e) {
        $errorMessage = "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
        error_log($errorMessage);
        header('Location: contact.php?error=' . urlencode($errorMessage));
        exit;
    }
} else {
    // If not a POST request, redirect to contact page
    header('Location: contact.php');
    exit;
}
?>
