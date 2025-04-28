<?php
// Set script execution time limit to 5 minutes
set_time_limit(300);

// Define base path
define('BASE_PATH', dirname(__DIR__));

// Include necessary files
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/EmailService.php';

// Initialize email service
$emailService = new EmailService();

// Process the queue
$batchSize = 50; // Process 50 emails at a time
$maxBatches = 10; // Process up to 10 batches (500 emails)

$totalProcessed = 0;
$totalSuccess = 0;
$totalFailure = 0;

for ($i = 0; $i < $maxBatches; $i++) {
    $result = $emailService->processQueue($batchSize);
    
    $totalProcessed += ($result['success'] + $result['failure']);
    $totalSuccess += $result['success'];
    $totalFailure += $result['failure'];
    
    // If we processed fewer emails than the batch size, we're done
    if (($result['success'] + $result['failure']) < $batchSize) {
        break;
    }
}

// Log the results
$logMessage = date('Y-m-d H:i:s') . " - Processed $totalProcessed emails: $totalSuccess successful, $totalFailure failed";
if (isset($result['error'])) {
    $logMessage .= " - Error: " . $result['error'];
}

// Write to log file
$logFile = BASE_PATH . '/logs/email_queue.log';
$logDir = dirname($logFile);

// Create logs directory if it doesn't exist
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);

// Output results if run from command line
if (php_sapi_name() === 'cli') {
    echo $logMessage . PHP_EOL;
}

// Create a function to ensure the email_queue table exists
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
                  `attempts` int(11) NOT NULL DEFAULT 0,
                  `max_attempts` int(11) NOT NULL DEFAULT 3,
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
?>