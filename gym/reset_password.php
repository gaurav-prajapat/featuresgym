<?php
require './config/GymDatabase.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $token = $_POST['token'];
    $newPassword = $_POST['new_password'];

    // Validate token
    $stmt = $pdo->prepare("SELECT * FROM password_resets WHERE token = ? AND expires_at > NOW()");
    $stmt->execute([$token]);
    $resetRequest = $stmt->fetch();

    if ($resetRequest) {
        $email = $resetRequest['email'];
        $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT);

        // Update password
        $stmt = $pdo->prepare("UPDATE gym_owners SET password_hash = ? WHERE email = ?");
        $stmt->execute([$passwordHash, $email]);

        // Delete reset token
        $stmt = $pdo->prepare("DELETE FROM password_resets WHERE email = ?");
        $stmt->execute([$email]);

        echo "Password reset successful.";
    } else {
        echo "Invalid or expired token.";
    }
}
?>
