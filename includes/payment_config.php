<?php
// Helper function to get payment settings from database
function getPaymentSettings() {
    $db = new GymDatabase();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("SELECT setting_key, setting_value FROM payment_settings");
    $stmt->execute();
    $settings = [];
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    
    return $settings;
}
