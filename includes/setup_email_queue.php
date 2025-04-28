<?php
require_once __DIR__ . '/../config/database.php';

function ensureEmailQueueTableExists() {
    try {
        $db = new GymDatabase();
        $conn = $db->getConnection();
        
        // Check if email_queue table exists
        $tableCheckSql = "SHOW TABLES LIKE 'email_queue'";
        $tableExists = $conn->query($tableCheckSql)->rowCount() > 0;
        
        if (!$tableExists) {
            // Create the email_queue table
            $createTableSql = "
                CREATE TABLE `email_queue` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `recipient` varchar(255) NOT NULL,
                  `subject` varchar(255) NOT NULL,
                  `body` text NOT NULL,
                  `attachments` text DEFAULT NULL,
                  `status` enum('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
                  `priority` tinyint(4) NOT NULL DEFAULT 1,
                  `attempts` tinyint(4) NOT NULL DEFAULT 0,
                  `max_attempts` tinyint(4) NOT NULL DEFAULT 3,
                  `error_message` text DEFAULT NULL,
                  `created_at` datetime NOT NULL,
                  `last_attempt` datetime DEFAULT NULL,
                  `sent_at` datetime DEFAULT NULL,
                  PRIMARY KEY (`id`),
                  KEY `status` (`status`),
                  KEY `priority` (`priority`),
                  KEY `created_at` (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
            ";
            
            $conn->exec($createTableSql);
            echo "Created email_queue table\n";
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Failed to ensure email_queue table exists: " . $e->getMessage());
        return false;
    }
}

// Run the function to ensure table
ensureEmailQueueTableExists();

// Also ensure email_logs table exists
function ensureEmailLogsTableExists() {
    try {
        $db = new GymDatabase();
        $conn = $db->getConnection();
        
        // Check if email_logs table exists
        $tableCheckSql = "SHOW TABLES LIKE 'email_logs'";
        $tableExists = $conn->query($tableCheckSql)->rowCount() > 0;
        
        if (!$tableExists) {
            // Create the email_logs table
            $createTableSql = "
                CREATE TABLE `email_logs` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `recipient` varchar(255) NOT NULL,
                  `subject` varchar(255) NOT NULL,
                  `status` enum('sent','failed','queued') NOT NULL,
                  `error_message` text DEFAULT NULL,
                  `sent_at` datetime NOT NULL,
                  `user_id` int(11) DEFAULT NULL,
                  `email_type` varchar(50) DEFAULT NULL,
                  PRIMARY KEY (`id`),
                  KEY `status` (`status`),
                  KEY `sent_at` (`sent_at`),
                  KEY `user_id` (`user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
            ";
            
            $conn->exec($createTableSql);
            echo "Created email_logs table\n";
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Failed to ensure email_logs table exists: " . $e->getMessage());
        return false;
    }
}

ensureEmailLogsTableExists();

// Ensure otp_verifications table exists
function ensureOtpVerificationsTableExists() {
    try {
        $db = new GymDatabase();
        $conn = $db->getConnection();
        
        // Check if otp_verifications table exists
        $tableCheckSql = "SHOW TABLES LIKE 'otp_verifications'";
        $tableExists = $conn->query($tableCheckSql)->rowCount() > 0;
        
        if (!$tableExists) {
            // Create the otp_verifications table
            $createTableSql = "
                CREATE TABLE `otp_verifications` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `email` varchar(255) NOT NULL,
                  `otp` varchar(10) NOT NULL,
                  `created_at` datetime NOT NULL,
                  `expires_at` datetime NOT NULL,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `email` (`email`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
            ";
            
            $conn->exec($createTableSql);
            echo "Created otp_verifications table\n";
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Failed to ensure otp_verifications table exists: " . $e->getMessage());
        return false;
    }
}

ensureOtpVerificationsTableExists();

// Add email settings to system_settings if they don't exist
function ensureEmailSettingsExist() {
    try {
        $db = new GymDatabase();
        $conn = $db->getConnection();
        
        // Default email settings
        $defaultSettings = [
            ['smtp_host', 'smtp.example.com', 'email'],
            ['smtp_port', '587', 'email'],
            ['smtp_username', 'notifications@featuresgym.com', 'email'],
            ['smtp_password', '', 'email'],
            ['smtp_encryption', 'tls', 'email'],
            ['smtp_from_email', 'notifications@featuresgym.com', 'email'],
            ['smtp_from_name', 'Features Gym', 'email'],
            ['log_emails', '1', 'email'],
            ['dev_mode', '0', 'development'],
            ['log_retention_days', '30', 'email']
        ];
        
        foreach ($defaultSettings as $setting) {
            $stmt = $conn->prepare("
                SELECT COUNT(*) FROM system_settings 
                WHERE setting_key = ?
            ");
            $stmt->execute([$setting[0]]);
            
            if ($stmt->fetchColumn() == 0) {
                $insertStmt = $conn->prepare("
                    INSERT INTO system_settings 
                    (setting_key, setting_value, setting_group, created_at, updated_at) 
                    VALUES (?, ?, ?, NOW(), NOW())
                ");
                $insertStmt->execute($setting);
                echo "Added setting: {$setting[0]}\n";
            }
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Failed to ensure email settings exist: " . $e->getMessage());
        return false;
    }
}

ensureEmailSettingsExist();

echo "Email system setup completed successfully.\n";
?>
