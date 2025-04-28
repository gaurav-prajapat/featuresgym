<?php
/**
 * Email Queue Processor
 * 
 * This script processes queued emails in the background.
 * It can be run via cron job or manually.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/EmailService.php';

// Set execution time to unlimited for large queues
set_time_limit(0);

// Initialize database connection
$db = new GymDatabase();
$conn = $db->getConnection();

// Initialize email service
$emailService = new EmailService();

// Get queue processing settings
$batchSize = 50; // Process 50 emails at a time
$maxAttempts = 3; // Maximum retry attempts

// Log start of processing
echo "Starting email queue processing at " . date('Y-m-d H:i:s') . "\n";

try {
    // Get emails from queue that need processing
    $stmt = $conn->prepare("
        SELECT * FROM email_queue 
        WHERE (status = 'pending' OR (status = 'failed' AND attempts < ?))
        ORDER BY priority DESC, created_at ASC
        LIMIT ?
    ");
    $stmt->execute([$maxAttempts, $batchSize]);
    
    $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $totalEmails = count($emails);
    
    echo "Found $totalEmails emails to process\n";
    
    $successCount = 0;
    $failureCount = 0;
    
    foreach ($emails as $email) {
        echo "Processing email ID: {$email['id']} to: {$email['recipient']}\n";
        
        // Mark as processing
        $updateStmt = $conn->prepare("
            UPDATE email_queue 
            SET status = 'processing', 
                attempts = attempts + 1,
                last_attempt = NOW() 
            WHERE id = ?
        ");
        $updateStmt->execute([$email['id']]);
        
        // Try to send the email
        $success = $emailService->sendEmail(
            $email['recipient'],
            $email['subject'],
            $email['body'],
            json_decode($email['attachments'], true)
        );
        
        if ($success) {
            // Mark as sent
            $updateStmt = $conn->prepare("
                UPDATE email_queue 
                SET status = 'sent', 
                    sent_at = NOW() 
                WHERE id = ?
            ");
            $updateStmt->execute([$email['id']]);
            
            $successCount++;
            echo "  - Success: Email sent\n";
        } else {
            // Mark as failed
            $updateStmt = $conn->prepare("
                UPDATE email_queue 
                SET status = 'failed', 
                    error_message = ? 
                WHERE id = ?
            ");
            $updateStmt->execute([$emailService->getLastError(), $email['id']]);
            
            $failureCount++;
            echo "  - Failed: " . $emailService->getLastError() . "\n";
        }
    }
    
    echo "Email processing completed: $successCount succeeded, $failureCount failed\n";
    
    // Clean up old sent emails from the queue
    $cleanupStmt = $conn->prepare("
        DELETE FROM email_queue 
        WHERE status = 'sent' 
        AND sent_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    $cleanupStmt->execute();
    $cleanedUp = $cleanupStmt->rowCount();
    
    echo "Cleaned up $cleanedUp old sent emails from the queue\n";
    
} catch (Exception $e) {
    echo "Error processing email queue: " . $e->getMessage() . "\n";
}

echo "Email queue processing finished at " . date('Y-m-d H:i:s') . "\n";
?>