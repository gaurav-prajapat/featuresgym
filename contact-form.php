<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php'; // Include PHPMailer (or include the PHPMailer files manually if not using Composer)

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get form data
    $name = $_POST['name'];
    $email = $_POST['email'];
    $message = $_POST['message'];

    // Validate data
    if (empty($name) || empty($email) || empty($message)) {
        echo "All fields are required.";
        exit;
    }

    // Create an instance of PHPMailer
    $mail = new PHPMailer(true);

    try {
        //Server settings
        $mail->isSMTP();
        $mail->Host = 'mysmtp@gmail.com'; // Use your SMTP server (Gmail for this example)
        $mail->SMTPAuth = true;
        $mail->Username = 'your-email@gmail.com'; // Your Gmail address
        $mail->Password = 'your-email-password';  // Your Gmail password or app-specific password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        //Recipients
        $mail->setFrom($email, $name); 
        $mail->addAddress('your-email@example.com', 'Gym Admin'); // Your email

        //Content
        $mail->isHTML(true);
        $mail->Subject = "New Contact Us Message from $name";
        $mail->Body    = "Name: $name<br>Email: $email<br>Message: <br>$message";

        // Send the email
        $mail->send();
        echo 'Thank you for contacting us! We will get back to you soon.';
        header('Location: contact.php');

    } catch (Exception $e) {
        echo "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
    }
}
?>
