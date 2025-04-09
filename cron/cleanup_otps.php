<?php
require_once __DIR__ . '/../config/database.php';

// This script should be run via cron job to clean up expired OTPs
// Recommended: Run once per day

try {
    $db = new GymDatabase();
    $conn = $db->getConnection();
    
    // Delete expired OTPs older than 24 hours
    $stmt = $conn->prepare("
        DELETE FROM otp_verifications 
        WHERE expires_at < NOW() - INTERVAL 24 HOUR
    ");
    
    $stmt->execute();
    
    $count = $stmt->rowCount();
    echo "Cleaned up $count expired OTP records.\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
