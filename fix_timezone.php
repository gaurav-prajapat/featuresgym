<?php
// This script checks and fixes timezone settings
require_once 'config/database.php';

// Initialize database connection
$db = new GymDatabase();
$conn = $db->getConnection();

// Get current PHP timezone
$currentPhpTimezone = date_default_timezone_get();
echo "Current PHP timezone: " . $currentPhpTimezone . "<br>";

// Get current MySQL timezone
$stmt = $conn->query("SELECT @@global.time_zone, @@session.time_zone");
$timezones = $stmt->fetch(PDO::FETCH_ASSOC);
echo "MySQL global timezone: " . $timezones['@@global.time_zone'] . "<br>";
echo "MySQL session timezone: " . $timezones['@@session.time_zone'] . "<br>";

// Get current times
echo "Current PHP time: " . date('Y-m-d H:i:s') . "<br>";
$stmt = $conn->query("SELECT NOW() as mysql_time");
$mysqlTime = $stmt->fetch(PDO::FETCH_ASSOC);
echo "Current MySQL time: " . $mysqlTime['mysql_time'] . "<br>";

// Check if timezone is set in system_settings
$stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'default_timezone'");
$stmt->execute();
$defaultTimezone = $stmt->fetchColumn();
echo "System default timezone: " . ($defaultTimezone ?: "Not set") . "<br>";

// Set timezone in system_settings if not already set
if (!$defaultTimezone) {
    $stmt = $conn->prepare("
        INSERT INTO system_settings (setting_key, setting_value, setting_group, created_at, updated_at)
        VALUES ('default_timezone', ?, 'general', NOW(), NOW())
        ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()
    ");
    $stmt->execute(['Asia/Kolkata', 'Asia/Kolkata']);
    echo "Set system default timezone to Asia/Kolkata<br>";
}

// Set PHP timezone to match system setting
if ($defaultTimezone) {
    date_default_timezone_set($defaultTimezone);
    echo "PHP timezone set to: " . date_default_timezone_get() . "<br>";
}

// Test OTP expiration calculation
$otpExpiry = 600; // 10 minutes
$currentTime = new DateTime('now', new DateTimeZone(date_default_timezone_get()));
$expiryTime = clone $currentTime;
$expiryTime->add(new DateInterval('PT' . $otpExpiry . 'S'));

echo "<br>OTP Expiration Test:<br>";
echo "Current time: " . $currentTime->format('Y-m-d H:i:s') . "<br>";
echo "Expiry time: " . $expiryTime->format('Y-m-d H:i:s') . "<br>";
echo "Difference in seconds: " . ($expiryTime->getTimestamp() - $currentTime->getTimestamp()) . "<br>";

// Check existing OTPs in the database
echo "<br>Existing OTPs:<br>";
$stmt = $conn->query("
    SELECT email, otp, created_at, expires_at, 
           IF(expires_at > NOW(), 'Valid', 'Expired') as status
    FROM otp_verifications
    ORDER BY created_at DESC
    LIMIT 10
");

if ($stmt->rowCount() > 0) {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Email</th><th>OTP</th><th>Created</th><th>Expires</th><th>Status</th></tr>";
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['email']) . "</td>";
        echo "<td>" . htmlspecialchars($row['otp']) . "</td>";
        echo "<td>" . htmlspecialchars($row['created_at']) . "</td>";
        echo "<td>" . htmlspecialchars($row['expires_at']) . "</td>";
        echo "<td>" . htmlspecialchars($row['status']) . "</td>";
        echo "</tr>";
    }
    
    echo "</table>";
} else {
    echo "No OTPs found in the database.";
}
?>