<?php
require './config/GymDatabase.php';
$db = new GymDatabase();
$conn = $db->getConnection();
// Check if the request method is POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Retrieve data from the POST request
    $member_id = $_POST['member_id'];
    $type = $_POST['type']; // 'Email' or 'SMS'
    $message = $_POST['message'];

    // Validate input
    if (empty($member_id) || empty($type) || empty($message)) {
        echo "All fields are required.";
        exit;
    }

    // Insert the notification into the GymDatabase
    $sql = "INSERT INTO notifications (member_id, notification_type, message) VALUES (?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iss", $member_id, $type, $message);

    if ($stmt->execute()) {
        // Optionally, you can implement sending an email or SMS here.
        // For demonstration purposes:
        if ($type === 'Email') {
            // Retrieve member's email
            $emailQuery = "SELECT email FROM members WHERE member_id = ?";
            $emailStmt = $conn->prepare($emailQuery);
            $emailStmt->bind_param("i", $member_id);
            $emailStmt->execute();
            $emailResult = $emailStmt->get_result();
            $member = $emailResult->fetch_assoc();

            // Use PHP's mail function (configure mail server properly)
            $to = $member['email'];
            $subject = "Notification from Gym";
            $headers = "From: no-reply@gym.com";
            mail($to, $subject, $message, $headers);
        }
        echo "Notification sent successfully.";
    } else {
        echo "Failed to send notification. Please try again.";
    }
} else {
    echo "Invalid request method.";
}
?>
