<?php
require_once '../config/database.php';
require_once '../includes/EmailService.php';
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['error'] = "Unauthorized access.";
    header("Location: login.php");
    exit();
}

// Check if email address is provided
if (!isset($_POST['test_email']) || empty($_POST['test_email'])) {
    $_SESSION['error'] = "Email address is required.";
    header("Location: email_logs.php");
    exit();
}

$testEmail = filter_var($_POST['test_email'], FILTER_VALIDATE_EMAIL);
if (!$testEmail) {
    $_SESSION['error'] = "Invalid email address.";
    header("Location: email_logs.php");
    exit();
}

// Send test email
$emailService = new EmailService();
$result = $emailService->sendTestEmail($testEmail);

if ($result) {
    $_SESSION['success'] = "Test email sent successfully to $testEmail.";
} else {
    $_SESSION['error'] = "Failed to send test email: " . $emailService->getLastError();
}

header("Location: email_logs.php");
exit();
?>