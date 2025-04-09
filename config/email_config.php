<?php
// Email configuration - Keep this file secure and outside web root if possible
return [
    'host' => 'smtp.gmail.com',  // Your SMTP server
    'username' => 'your_email@gmail.com', // Your email address
    'password' => 'your_app_password',    // Your app password (for Gmail)
    'port' => 587,
    'encryption' => 'tls',
    'from_email' => 'prajaptgulshan0@gmail.com',
    'from_name' => 'Fitness Hub',
    'reply_to' => 'support@yourgymsite.com',
    'sendgrid_api_key' => 'YOUR_SENDGRID_API_KEY'
];

// Look for settings like these in your codebase
$mail->Host = 'smtp.example.com';
$mail->SMTPAuth = true;
$mail->Username = 'your-email@example.com';
$mail->Password = 'your-password';
$mail->SMTPSecure = 'tls'; // Try changing to 'ssl' if needed
$mail->Port = 587; // Or 465 for SSL
