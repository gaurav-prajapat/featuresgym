<?php
// Database connection
require_once '../config/database.php';

$db = new GymDatabase();
$pdo = $db->getConnection();

// Function to update schedules hourly
function updateSchedulesHourly($pdo) {
    $currentTime = date('Y-m-d H:i:s');

    // Query to fetch schedules that need updates
    $query = "SELECT schedules.id, schedules.gym_id, schedules.user_id, schedules.start_date, schedules.start_time, schedules.status, schedules.daily_rate
              FROM schedules
              JOIN gyms ON schedules.gym_id = gyms.gym_id
              WHERE schedules.status = 'scheduled' AND CONCAT(schedules.start_date, ' ', schedules.start_time) <= :currentTime";

    $stmt = $pdo->prepare($query);
    $stmt->execute([':currentTime' => $currentTime]);

    while ($schedule = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $scheduleId = $schedule['id'];
        $gymId = $schedule['gym_id'];
        $userId = $schedule['user_id'];
        $dailyRate = $schedule['daily_rate'];

        // Update the schedule status to 'completed' and payment status to 'paid'
        $updateQuery = "UPDATE schedules SET status = 'completed', payment_status = 'paid' WHERE id = :scheduleId AND status = 'scheduled'";
        $updateStmt = $pdo->prepare($updateQuery);
        $updateStmt->execute([':scheduleId' => $scheduleId]);

        // Credit the payment to the gym's account (assumes a gyms table with a balance column)
        $creditQuery = "UPDATE gyms SET balance = balance + :dailyRate WHERE gym_id = :gymId";
        $creditStmt = $pdo->prepare($creditQuery);
        $creditStmt->execute([':dailyRate' => $dailyRate, ':gymId' => $gymId]);

    }

    echo "Schedules updated successfully.";
}

// Call the function
updateSchedulesHourly($pdo);
?>
