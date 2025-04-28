<?php
// Mail function test script
require_once 'config/database.php';
require_once 'includes/EmailService.php';

// Get database connection
$db = new GymDatabase();
$conn = $db->getConnection();

// Fetch SMTP settings from database
$stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'contact_email')");
$stmt->execute();
$settings = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Override with test settings if needed
$smtpHost = 'smtp.gmail.com'; // Use a real SMTP server
$smtpPort = 587; // Standard TLS port
$smtpUsername = $settings['smtp_username'] ?? '';
$smtpPassword = $settings['smtp_password'] ?? '';
$smtpEncryption = $settings['smtp_encryption'] ?? 'tls';
$fromEmail = $settings['contact_email'] ?? 'test@example.com';

// Create a test email
$to = 'recipient@example.com'; // Replace with your test email
$subject = 'Test Email from Features Gym';
$message = 'This is a test email to verify SMTP configuration is working correctly.';

// Try using EmailService class
try {
    echo "<h2>Testing EmailService class</h2>";
    $emailService = new EmailService();
    
    // Set test configuration
    $emailService->setSmtpSettings([
        'host' => $smtpHost,
        'port' => $smtpPort,
        'username' => $smtpUsername,
        'password' => $smtpPassword,
        'encryption' => $smtpEncryption
    ]);
    
    $emailResult = $emailService->sendEmail($to, $subject, $message);
    echo "EmailService result: " . ($emailResult ? "Success" : "Failed") . "<br>";
    
    if (!$emailResult) {
        echo "Error: " . $emailService->getLastError() . "<br>";
    }
} catch (Exception $e) {
    echo "EmailService error: " . $e->getMessage() . "<br>";
}

// Display current PHP mail configuration
echo "<h2>Current PHP Mail Configuration</h2>";
echo "<pre>";
echo "SMTP = " . ini_get('SMTP') . "\n";
echo "smtp_port = " . ini_get('smtp_port') . "\n";
echo "sendmail_from = " . ini_get('sendmail_from') . "\n";
echo "sendmail_path = " . ini_get('mail.force_extra_parameters') . "\n";
echo "</pre>";

// Log the test attempt
$stmt = $conn->prepare("
    INSERT INTO activity_logs (
        user_id, user_type, action, details, ip_address, user_agent, created_at
    ) VALUES (0, 'system', 'mail_test', ?, ?, ?, NOW())
");
$stmt->execute([
    "Mail test with SMTP=$smtpHost, Port=$smtpPort",
    $_SERVER['REMOTE_ADDR'],
    $_SERVER['HTTP_USER_AGENT']
]);

echo "<p>Test completed and logged.</p>";
?>
