<?php
require_once 'config/database.php';
include 'includes/navbar.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$db = new GymDatabase();
$conn = $db->getConnection();
$user_id = $_SESSION['user_id'];

// Get the current page from the URL (default is page 1)
$upcoming_page = isset($_GET['upcoming_page']) ? (int) $_GET['upcoming_page'] : 1;
$past_page = isset($_GET['past_page']) ? (int) $_GET['past_page'] : 1;
$limit = 9;  // Number of results per page

// Get total number of upcoming schedules
$stmt = $conn->prepare("
    SELECT COUNT(*) 
    FROM schedules 
    WHERE user_id = ? 
    AND start_date >= CURDATE()
");
$stmt->execute([$user_id]);
$total_upcoming = $stmt->fetchColumn();
$total_upcoming_pages = ceil($total_upcoming / $limit);

// Get total number of past schedules
$stmt = $conn->prepare("
    SELECT COUNT(*) 
    FROM schedules 
    WHERE user_id = ? 
    AND start_date < CURDATE()
");
$stmt->execute([$user_id]);
$total_past = $stmt->fetchColumn();
$total_past_pages = ceil($total_past / $limit);

// Get upcoming schedules for the current page
$upcoming_offset = ($upcoming_page - 1) * $limit;
$stmt = $conn->prepare("
    SELECT s.*, g.name as gym_name, g.address, g.city, g.state, g.zip_code,
           g.cancellation_policy, g.reschedule_policy, g.late_fee_policy
    FROM schedules s
    JOIN gyms g ON s.gym_id = g.gym_id
    WHERE s.user_id = :user_id
    AND s.start_date >= CURDATE()
    ORDER BY s.start_date ASC, s.start_time ASC
    LIMIT :limit OFFSET :offset
");

$stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
$stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
$stmt->bindParam(':offset', $upcoming_offset, PDO::PARAM_INT);
$stmt->execute();

$upcoming_schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get past schedules for the current page
$past_offset = ($past_page - 1) * $limit;
$stmt = $conn->prepare("
    SELECT s.*, g.name as gym_name, g.address, g.city, g.state, g.zip_code,
           g.cancellation_policy, g.reschedule_policy, g.late_fee_policy
    FROM schedules s
    JOIN gyms g ON s.gym_id = g.gym_id
    WHERE s.user_id = :user_id
    AND s.start_date < CURDATE()
    ORDER BY s.start_date DESC, s.start_time DESC
    LIMIT :limit OFFSET :offset
");

$stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
$stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
$stmt->bindParam(':offset', $past_offset, PDO::PARAM_INT);
$stmt->execute();

$past_schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch user's active membership with completed payment
$stmt = $conn->prepare("
 SELECT um.*, gmp.tier as plan_name, gmp.inclusions, gmp.duration,
        g.name as gym_name, g.address, p.status as payment_status
 FROM user_memberships um
 JOIN gym_membership_plans gmp ON um.plan_id = gmp.plan_id
 JOIN gyms g ON gmp.gym_id = g.gym_id
 JOIN payments p ON um.id = p.membership_id
 WHERE um.user_id = ?
 AND um.status = 'active'
 AND p.status = 'completed'
 ORDER BY um.start_date DESC
");
$stmt->execute([$user_id]);
$membership = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch gym policies
$stmt = $conn->prepare("
    SELECT * FROM gym_policies WHERE is_active = 1 LIMIT 1
");
$stmt->execute();
$policies = $stmt->fetch(PDO::FETCH_ASSOC);

// default
$policies = [
    'cancellation_hours' => 4,
    'reschedule_hours' => 2,
    'cancellation_fee' => 200,
    'reschedule_fee' => 100,
    'late_fee' => 300,
    'is_active' => 1
];


// Auto-update schedule statuses and charge fees if needed
updateScheduleStatuses($conn, $user_id);

/**
 * Update schedule statuses and charge fees if needed
 */
function updateScheduleStatuses($conn, $user_id)
{
    // Get current date and time
    $currentDateTime = new DateTime();
    $currentDate = $currentDateTime->format('Y-m-d');
    $currentTime = $currentDateTime->format('H:i:s');

    // 1. Update status to 'completed' for past schedules that were 'scheduled'
    $stmt = $conn->prepare("
        UPDATE schedules 
        SET status = 'completed' 
        WHERE user_id = ? 
        AND status = 'scheduled' 
        AND (
            (start_date < ?) 
            OR (start_date = ? AND start_time < ?)
        )
    ");
    $stmt->execute([$user_id, $currentDate, $currentDate, $currentTime]);

    // 2. Check for missed workouts and charge late fees
    $stmt = $conn->prepare("
        SELECT s.*, g.name as gym_name, g.late_fee_amount, u.balance, u.email
        FROM schedules s
        JOIN gyms g ON s.gym_id = g.gym_id
        JOIN users u ON s.user_id = u.id
        WHERE s.user_id = ?
        AND s.status = 'scheduled'
        AND s.start_date = ?
        AND s.start_time < ?
        AND TIMESTAMPDIFF(MINUTE, CONCAT(s.start_date, ' ', s.start_time), ?) > 15
    ");
    $stmt->execute([$user_id, $currentDate, $currentTime, $currentDateTime->format('Y-m-d H:i:s')]);
    $missedWorkouts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($missedWorkouts as $workout) {
        // Mark as missed
        $updateStmt = $conn->prepare("
            UPDATE schedules SET status = 'missed' WHERE id = ?
        ");
        $updateStmt->execute([$workout['id']]);

        // Charge late fee if applicable
        if ($workout['late_fee_amount'] > 0) {
            // Deduct from user balance
            $newBalance = $workout['balance'] - $workout['late_fee_amount'];
            $updateBalanceStmt = $conn->prepare("
                UPDATE users SET balance = ? WHERE id = ?
            ");
            $updateBalanceStmt->execute([$newBalance, $user_id]);

            // Record the transaction
            $transactionStmt = $conn->prepare("
                INSERT INTO transactions (user_id, amount, type, description, status, created_at)
                VALUES (?, ?, 'fee', ?, 'completed', NOW())
            ");
            $description = "Late fee for missed workout at " . $workout['gym_name'] . " on " .
                date('M j, Y', strtotime($workout['start_date'])) . " at " .
                date('g:i A', strtotime($workout['start_time']));
            $transactionStmt->execute([$user_id, $workout['late_fee_amount'], $description]);

            // Send email notification
            // This would typically use a mail library like PHPMailer
            // For now, we'll just log it
            error_log("Late fee of {$workout['late_fee_amount']} charged to user {$user_id} for missed workout");
        }
    }
}
?>

<div class="min-h-screen bg-gradient-to-b from-gray-900 to-black pt-24">
    <div class="container mx-auto px-4 sm:px-6 lg:px-8">
        <?php if ($upcoming_schedules || $past_schedules): ?>
            <!-- Header Section -->
            <div class="flex justify-between items-center mb-10">
                <h1 class="text-3xl font-bold text-white">My Workout Schedule</h1>
                <div class="flex space-x-4">
                    <button id="refresh-schedules"
                        class="bg-blue-500 text-white px-6 py-3 rounded-full font-bold hover:bg-blue-600 transform hover:scale-105 transition-all duration-300">
                        <i class="fas fa-sync-alt mr-2"></i>Refresh
                    </button>
                    <a href="schedule.php"
                        class="bg-yellow-400 text-black px-6 py-3 rounded-full font-bold hover:bg-yellow-500 transform hover:scale-105 transition-all duration-300">
                        <i class="fas fa-plus mr-2"></i>Schedule New Workout
                    </a>
                </div>
            </div>

            <!-- Scheduling Policies Section -->
            <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl overflow-hidden mb-10">
                <div class="p-6 bg-gradient-to-r from-blue-500 to-blue-600">
                    <h3 class="text-xl font-bold text-white">Scheduling Policies</h3>
                </div>
                <div class="p-6 space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div class="bg-gray-700 bg-opacity-50 p-4 rounded-xl">
                            <h4 class="text-yellow-400 font-semibold mb-2">
                                <i class="fas fa-calendar-times mr-2"></i>Cancellation Policy
                            </h4>
                            <p class="text-white text-sm">
                                <?= $policies['cancellation_policy'] ?? 'Cancellations must be made at least 4 hours before the scheduled time to avoid cancellation fees. Late cancellations may incur a fee of up to 50% of the session cost.' ?>
                            </p>
                        </div>
                        <div class="bg-gray-700 bg-opacity-50 p-4 rounded-xl">
                            <h4 class="text-yellow-400 font-semibold mb-2">
                                <i class="fas fa-calendar-alt mr-2"></i>Rescheduling Policy
                            </h4>
                            <p class="text-white text-sm">
                                <?= $policies['reschedule_policy'] ?? 'Workouts can be rescheduled up to 2 hours before the scheduled time without penalty. Each membership allows up to 3 free reschedules per month.' ?>
                            </p>
                        </div>
                        <div class="bg-gray-700 bg-opacity-50 p-4 rounded-xl">
                            <h4 class="text-yellow-400 font-semibold mb-2">
                                <i class="fas fa-clock mr-2"></i>Late Fee Policy
                            </h4>
                            <p class="text-white text-sm">
                                <?= $policies['late_fee_policy'] ?? 'Missing a scheduled workout without cancellation will result in a late fee of ₹200. Repeated no-shows may affect membership privileges.' ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Upcoming Workouts -->
            <h2 class="text-3xl font-bold text-white mb-8 text-center">Upcoming Workouts</h2>
            <div id="upcoming-schedules-container">
                <?php if ($upcoming_schedules): ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-12">
                        <?php foreach ($upcoming_schedules as $schedule): ?>
                            <div
                                class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl overflow-hidden transform hover:scale-[1.02] transition-transform duration-300">
                                <!-- Header Section -->
                                <div class="p-6 bg-gradient-to-r from-yellow-400 to-yellow-500">
                                    <div class="flex justify-between items-center">
                                        <h3 class="text-xl font-bold text-gray-900">
                                            <?= htmlspecialchars($schedule['gym_name']) ?>
                                        </h3>
                                        <span class="px-4 py-1 rounded-full text-sm font-medium
                                            <?= match ($schedule['status']) {
                                                'scheduled' => 'bg-green-900 text-green-100',
                                                'completed' => 'bg-blue-900 text-blue-100',
                                                'cancelled' => 'bg-red-900 text-red-100',
                                                'missed' => 'bg-yellow-900 text-yellow-100',
                                                default => 'bg-gray-900 text-gray-100'
                                            } ?>">
                                            <?= ucfirst($schedule['status']) ?>
                                        </span>
                                    </div>
                                </div>

                                <!-- Details Section -->
                                <div class="p-6">
                                    <div class="">
                                        <div class="space-y-4">
                                            <div>
                                                <label class="text-yellow-400 text-sm">Date & Time</label>
                                                <p class="text-white text-lg">
                                                    <?= date('M j, Y', strtotime($schedule['start_date'])) ?><br>
                                                    <?= date('g:i A', strtotime($schedule['start_time'])) ?>
                                                </p>
                                            </div>
                                            <div class="mt-4 p-3 bg-gray-900 bg-opacity-50 rounded-lg">
                                                <label class="text-yellow-400 text-sm">Time Until Workout</label>
                                                <div class="countdown-timer text-white font-mono text-lg"
                                                    data-date="<?= $schedule['start_date'] ?>"
                                                    data-time="<?= $schedule['start_time'] ?>">
                                                    Loading...
                                                </div>
                                            </div>

                                            <div>
                                                <label class="text-yellow-400 text-sm">Location</label>
                                                <p class="text-white">
                                                    <?= htmlspecialchars($schedule['address']) ?><br>
                                                    <?= htmlspecialchars($schedule['city']) ?>,
                                                    <?= htmlspecialchars($schedule['state']) ?>
                                                    <?= htmlspecialchars($schedule['zip_code']) ?>
                                                </p>
                                            </div>
                                            <?php if (isset($schedule['trainer_name']) && $schedule['trainer_name']): ?>
                                                <div>
                                                    <label class="text-yellow-400 text-sm">Trainer</label>
                                                    <p class="text-white">
                                                        <?= htmlspecialchars($schedule['trainer_name']) ?>
                                                    </p>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="space-y-4">
                                            <?php if ($schedule['recurring'] !== 'none'): ?>
                                                <div>
                                                    <label class="text-yellow-400 text-sm">Recurring Schedule</label>
                                                    <p class="text-white">
                                                        <?= ucfirst($schedule['recurring']) ?> workout until
                                                        <?= date('M j, Y', strtotime($schedule['recurring_until'])) ?>
                                                        <?php if ($schedule['days_of_week']): ?>
                                                            <br>Days:
                                                            <?= implode(', ', array_map('ucfirst', json_decode($schedule['days_of_week'], true))) ?>
                                                        <?php endif; ?>
                                                    </p>
                                                </div>
                                            <?php endif; ?>

                                            <?php if ($schedule['notes']): ?>
                                                <div>
                                                    <label class="text-yellow-400 text-sm">Notes</label>
                                                    <p class="text-white italic"><?= htmlspecialchars($schedule['notes']) ?></p>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <?php if ($schedule['status'] === 'scheduled'): ?>
                                        <!-- Calculate time until workout for policy enforcement -->
                                        <?php
                                        $workoutDateTime = new DateTime($schedule['start_date'] . ' ' . $schedule['start_time']);
                                        $currentDateTime = new DateTime();
                                        $interval = $currentDateTime->diff($workoutDateTime);
                                        $hoursUntilWorkout = ($interval->days * 24) + $interval->h;

                                        // Determine if we can reschedule or cancel based on policies
                                        $canReschedule = $hoursUntilWorkout >= 2; // 2 hours before
                                        $canCancel = $hoursUntilWorkout >= 4; // 4 hours before
                                        $rescheduleMessage = !$canReschedule ? "Rescheduling is only available up to 2 hours before your workout." : "";
                                        $cancelMessage = !$canCancel ? "Cancellation is only available up to 4 hours before your workout to avoid fees." : "";

                                        // Check if workout is in the future (even if within policy limits)
                                        $isWorkoutInFuture = $workoutDateTime > $currentDateTime;
                                        ?>
                                        <div class="mt-6 flex flex-col space-y-4">
                                            <div class="flex flex-wrap justify-end gap-4">
                                                <?php if ($isWorkoutInFuture): ?>
                                                    <!-- Reschedule Button - Disabled if within policy limits but still visible -->
                                                    <button
                                                        onclick="rescheduleWorkout(<?= $schedule['id'] ?>, <?= $canReschedule ? 'true' : 'false' ?>, '<?= $rescheduleMessage ?>')"
                                                        class="bg-blue-600 text-white px-6 py-3 rounded-full font-bold hover:bg-blue-700 transform hover:scale-105 transition-all duration-300 <?= !$canReschedule ? 'opacity-50 cursor-not-allowed' : '' ?>">
                                                        <i class="fas fa-calendar-alt mr-2"></i>Reschedule Time
                                                    </button>

                                                    <!-- Change Gym Button - New button to change gym -->
                                                    <a href="change_gym.php?schedule_id=<?= $schedule['id'] ?>"
                                                        class="bg-purple-600 text-white px-6 py-3 rounded-full font-bold hover:bg-purple-700 transform hover:scale-105 transition-all duration-300 text-center">
                                                        <i class="fas fa-map-marker-alt mr-2"></i>Change Gym
                                                    </a>

                                                    <!-- Cancel Button - Disabled if within policy limits but still visible -->
                                                    <button
                                                        onclick="cancelSchedule(<?= $schedule['id'] ?>, <?= $canCancel ? 'true' : 'false' ?>, '<?= $cancelMessage ?>')"
                                                        class="bg-red-700 text-white px-6 py-3 rounded-full font-bold hover:bg-red-600 transform hover:scale-105 transition-all duration-300 <?= !$canCancel ? 'opacity-50 cursor-not-allowed' : '' ?>">
                                                        <i class="fas fa-times mr-2"></i>Cancel
                                                    </button>
                                                <?php else: ?>
                                                    <!-- If workout is in the past or ongoing, show message instead of buttons -->
                                                    <div class="text-yellow-400 text-sm text-center">
                                                        <i class="fas fa-exclamation-triangle mr-1"></i>
                                                        This workout has already started or passed and cannot be modified.
                                                    </div>
                                                <?php endif; ?>
                                            </div>

                                            <?php if ($isWorkoutInFuture && (!$canReschedule || !$canCancel)): ?>
                                                <div class="text-yellow-400 text-sm text-center mt-2">
                                                    <i class="fas fa-exclamation-triangle mr-1"></i>
                                                    <?= !$canReschedule ? $rescheduleMessage : '' ?>
                                                    <?= !$canCancel ? $cancelMessage : '' ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>

                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl p-8 text-center mb-12">
                        <div class="text-yellow-400 text-lg mb-4">
                            No upcoming workouts scheduled.
                        </div>
                        <a href="schedule.php"
                            class="bg-yellow-400 text-black px-8 py-4 rounded-full font-bold hover:bg-yellow-500 transform hover:scale-105 transition-all duration-300 inline-block">
                            Schedule a Workout
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Upcoming Pagination -->
            <div id="upcoming-pagination" class="flex justify-center my-8">
                <nav class="flex items-center space-x-4">
                    <?php if ($upcoming_page > 1): ?>
                        <a href="?upcoming_page=<?= $upcoming_page - 1 ?>&past_page=<?= $past_page ?>"
                            class="bg-gray-700 text-white px-6 py-3 rounded-full font-bold hover:bg-gray-600 transform hover:scale-105 transition-all duration-300">
                            Previous
                        </a>
                    <?php endif; ?>

                    <?php for ($i = max(1, $upcoming_page - 1); $i <= min($total_upcoming_pages, $upcoming_page + 1); $i++): ?>
                        <a href="?upcoming_page=<?= $i ?>&past_page=<?= $past_page ?>" class="<?= $i == $upcoming_page
                                ? 'bg-yellow-400 text-black'
                                : 'bg-gray-700 text-white hover:bg-gray-600' ?> 
                               px-6 py-3 rounded-full font-bold transform hover:scale-105 transition-all duration-300">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($upcoming_page < $total_upcoming_pages): ?>
                        <a href="?upcoming_page=<?= $upcoming_page + 1 ?>&past_page=<?= $past_page ?>"
                            class="bg-gray-700 text-white px-6 py-3 rounded-full font-bold hover:bg-gray-600 transform hover:scale-105 transition-all duration-300">
                            Next
                        </a>
                    <?php endif; ?>
                </nav>
            </div>

            <!-- Past Workouts -->
            <h2 class="text-3xl font-bold text-white mb-8 text-center">Past Workouts</h2>
            <div id="past-schedules-container">
                <?php if ($past_schedules): ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <?php foreach ($past_schedules as $schedule): ?>
                            <div
                                class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl overflow-hidden transform hover:scale-[1.02] transition-transform duration-300">
                                <!-- Header Section -->
                                <div class="p-6 bg-gradient-to-r from-gray-700 to-gray-600">
                                    <div class="flex justify-between items-center">
                                        <h3 class="text-xl font-bold text-white">
                                            <?= htmlspecialchars($schedule['gym_name']) ?>
                                        </h3>
                                        <span class="px-4 py-1 rounded-full text-sm font-medium
                                            <?= match ($schedule['status']) {
                                                'completed' => 'bg-blue-900 text-blue-100',
                                                'cancelled' => 'bg-red-900 text-red-100',
                                                'missed' => 'bg-yellow-900 text-yellow-100',
                                                default => 'bg-gray-900 text-gray-100'
                                            } ?>">
                                            <?= ucfirst($schedule['status']) ?>
                                        </span>
                                    </div>
                                </div>

                                <!-- Details Section -->
                                <div class="p-6">
                                    <div class="grid grid-cols gap-6">
                                        <div class="space-y-4">
                                            <div>
                                                <label class="text-yellow-400 text-sm">Date & Time</label>
                                                <p class="text-white text-lg">
                                                    <?= date('M j, Y', strtotime($schedule['start_date'])) ?><br>
                                                    <?= date('g:i A', strtotime($schedule['start_time'])) ?>
                                                </p>
                                            </div>
                                            <div>
                                                <label class="text-yellow-400 text-sm">Location</label>
                                                <p class="text-white">
                                                <p class="text-white">
                                                    <?= htmlspecialchars($schedule['address']) ?><br>
                                                    <?= htmlspecialchars($schedule['city']) ?>,
                                                    <?= htmlspecialchars($schedule['state']) ?>
                                                    <?= htmlspecialchars($schedule['zip_code']) ?>
                                                </p>
                                            </div>
                                            <?php if (isset($schedule['trainer_name']) && $schedule['trainer_name']): ?>
                                                <div>
                                                    <label class="text-yellow-400 text-sm">Trainer</label>
                                                    <p class="text-white">
                                                        <?= htmlspecialchars($schedule['trainer_name']) ?>
                                                    </p>
                                                </div>
                                            <?php endif; ?>

                                            <?php if ($schedule['status'] === 'missed'): ?>
                                                <div>
                                                    <label class="text-red-400 text-sm">Late Fee</label>
                                                    <p class="text-white">
                                                        A late fee may have been charged for this missed workout.
                                                        Check your transaction history for details.
                                                    </p>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="space-y-4">
                                            <?php if ($schedule['recurring'] !== 'none'): ?>
                                                <div>
                                                    <label class="text-yellow-400 text-sm">Recurring Schedule</label>
                                                    <p class="text-white">
                                                        <?= ucfirst($schedule['recurring']) ?> workout until
                                                        <?= date('M j, Y', strtotime($schedule['recurring_until'])) ?>
                                                        <?php if ($schedule['days_of_week']): ?>
                                                            <br>Days:
                                                            <?= implode(', ', array_map('ucfirst', json_decode($schedule['days_of_week'], true))) ?>
                                                        <?php endif; ?>
                                                    </p>
                                                </div>
                                            <?php endif; ?>

                                            <?php if ($schedule['notes']): ?>
                                                <div>
                                                    <label class="text-yellow-400 text-sm">Notes</label>
                                                    <p class="text-white italic"><?= htmlspecialchars($schedule['notes']) ?></p>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl p-8 text-center">
                        <div class="text-yellow-400 text-lg mb-4">
                            No past workout history found.
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Past Workouts Pagination -->
            <div id="past-pagination" class="flex justify-center my-8">
                <nav class="flex items-center space-x-4">
                    <?php if ($past_page > 1): ?>
                        <a href="?upcoming_page=<?= $upcoming_page ?>&past_page=<?= $past_page - 1 ?>"
                            class="bg-gray-700 text-white px-6 py-3 rounded-full font-bold hover:bg-gray-600 transform hover:scale-105 transition-all duration-300">
                            Previous
                        </a>
                    <?php endif; ?>

                    <?php for ($i = max(1, $past_page - 1); $i <= min($total_past_pages, $past_page + 1); $i++): ?>
                        <a href="?upcoming_page=<?= $upcoming_page ?>&past_page=<?= $i ?>" class="<?= $i == $past_page
                                ? 'bg-yellow-400 text-black'
                                : 'bg-gray-700 text-white hover:bg-gray-600' ?> 
                               px-6 py-3 rounded-full font-bold transform hover:scale-105 transition-all duration-300">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($past_page < $total_past_pages): ?>
                        <a href="?upcoming_page=<?= $upcoming_page ?>&past_page=<?= $past_page + 1 ?>"
                            class="bg-gray-700 text-white px-6 py-3 rounded-full font-bold hover:bg-gray-600 transform hover:scale-105 transition-all duration-300">
                            Next
                        </a>
                    <?php endif; ?>
                </nav>
            </div>

        <?php else: ?>
            <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl p-8 text-center">
                <h1 class="text-3xl font-bold text-white mb-6">My Workout Schedule</h1>
                <div class="text-yellow-400 text-lg mb-8">
                    No workouts scheduled yet. Start your fitness journey today!
                </div>
                <a href="schedule.php<?php echo isset($membership['gym_id']) ? '?gym_id=' . $membership['gym_id'] : ''; ?>"
                    class="bg-yellow-400 text-black px-8 py-4 rounded-full font-bold hover:bg-yellow-500 transform hover:scale-105 transition-all duration-300 inline-block">
                    Create Schedule
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Cancel Popup HTML -->
<div id="cancel-popup" class="hidden fixed inset-0 bg-gray-800 bg-opacity-75 flex items-center justify-center z-50">
    <div class="bg-white rounded-lg shadow-lg w-96 p-6">
        <h2 class="text-lg font-semibold mb-4">Cancel Workout</h2>
        <form action="cancel_schedule.php" method="POST">
            <input type="hidden" name="schedule_id" id="cancel-schedule-id" value="">
            <input type="hidden" name="can_cancel_free" id="can-cancel-free" value="1">

            <div id="cancel-warning" class="mb-4 p-3 bg-yellow-100 text-yellow-800 rounded-lg hidden">
                <i class="fas fa-exclamation-triangle mr-2"></i>
                <span id="cancel-warning-text"></span>
            </div>

            <div id="cancel-fee-notice" class="mb-4 p-3 bg-red-100 text-red-800 rounded-lg hidden">
                <p><i class="fas fa-exclamation-circle mr-2"></i>Late cancellation fee will apply</p>
                <p class="font-semibold">A cancellation fee of ₹<span id="cancel-fee-amount">200</span> will be charged
                    to your account.</p>
            </div>

            <label for="cancel-reason" class="block text-sm font-medium text-gray-700 mb-2">Reason for
                cancellation:</label>
            <select name="cancel_reason" id="cancel-reason" class="w-full px-3 py-2 border rounded-lg mb-4"
                onchange="handleOtherReason(this.value)" required>
                <option value="" disabled selected>Select a reason</option>
                <option value="not feeling well">Not feeling well</option>
                <option value="schedule conflict">Schedule conflict</option>
                <option value="transportation issue">Transportation issue</option>
                <option value="other">Other</option>
            </select>
            <input type="text" name="other_reason" id="other-reason-input"
                class="w-full px-3 py-2 border rounded-lg hidden mb-4" placeholder="Enter your reason">
            <div class="flex justify-end gap-2">
                <button type="button" onclick="closeCancelPopup()"
                    class="bg-gray-500 text-white px-3 py-2 rounded-lg hover:bg-gray-600">Close</button>
                <button type="submit" id="cancel-submit-btn"
                    class="bg-red-500 text-white px-3 py-2 rounded-lg hover:bg-red-600">Confirm Cancellation</button>
            </div>
        </form>
    </div>
</div>

<!-- Reschedule Popup HTML -->
<div id="reschedule-popup" class="hidden fixed inset-0 bg-gray-800 bg-opacity-75 flex items-center justify-center z-50">
    <div class="bg-white rounded-lg shadow-lg w-full max-w-md p-6">
        <h2 class="text-lg font-semibold mb-4">Reschedule Workout</h2>
        <form action="reschedule_workout.php" method="POST">
            <input type="hidden" name="schedule_id" id="reschedule-schedule-id" value="">
            <input type="hidden" name="can_reschedule_free" id="can-reschedule-free" value="1">

            <div id="reschedule-warning" class="mb-4 p-3 bg-yellow-100 text-yellow-800 rounded-lg hidden">
                <i class="fas fa-exclamation-triangle mr-2"></i>
                <span id="reschedule-warning-text"></span>
            </div>

            <div id="reschedule-fee-notice" class="mb-4 p-3 bg-red-100 text-red-800 rounded-lg hidden">
                <p><i class="fas fa-exclamation-circle mr-2"></i>Late rescheduling fee may apply</p>
                <p class="font-semibold">A rescheduling fee of ₹<span id="reschedule-fee-amount">100</span> may be
                    charged to your account.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div>
                    <label for="reschedule-date" class="block text-sm font-medium text-gray-700 mb-2">New Date:</label>
                    <input type="date" name="new_date" id="reschedule-date" class="w-full px-3 py-2 border rounded-lg"
                        min="<?= date('Y-m-d', strtotime('+1 day')) ?>" required>
                </div>
                <div>
                    <label for="reschedule-time" class="block text-sm font-medium text-gray-700 mb-2">New Time:</label>
                    <select name="new_time" id="reschedule-time" class="w-full px-3 py-2 border rounded-lg" required>
                        <option value="" disabled selected>Select a time</option>
                        <!-- Time slots will be populated dynamically -->
                    </select>
                </div>
            </div>

            <label for="reschedule-reason" class="block text-sm font-medium text-gray-700 mb-2">Reason for
                rescheduling:</label>
            <select name="reschedule_reason" id="reschedule-reason" class="w-full px-3 py-2 border rounded-lg mb-4"
                onchange="handleOtherRescheduleReason(this.value)" required>
                <option value="" disabled selected>Select a reason</option>
                <option value="not feeling well">Not feeling well</option>
                <option value="schedule conflict">Schedule conflict</option>
                <option value="transportation issue">Transportation issue</option>
                <option value="other">Other</option>
            </select>
            <input type="text" name="other_reschedule_reason" id="other-reschedule-reason-input"
                class="w-full px-3 py-2 border rounded-lg hidden mb-4" placeholder="Enter your reason">

            <div class="flex justify-end gap-2">
                <button type="button" onclick="closeReschedulePopup()"
                    class="bg-gray-500 text-white px-3 py-2 rounded-lg hover:bg-gray-600">Close</button>
                <button type="submit" id="reschedule-submit-btn"
                    class="bg-blue-500 text-white px-3 py-2 rounded-lg hover:bg-blue-600">Confirm Reschedule</button>
            </div>
        </form>
    </div>
</div>

<!-- Loading Indicator -->
<div id="loading-indicator" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
    <div class="bg-white p-5 rounded-lg flex flex-col items-center">
        <div class="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-yellow-400 mb-4"></div>
        <p class="text-gray-800 font-semibold">Updating schedules...</p>
    </div>
</div>

<script>
    // Existing functions
    function change(scheduleId) {
        window.location.href = `schedule_workout.php?schedule_id=${scheduleId}`;
    }

    function cancelSchedule(scheduleId, canCancelFree, message) {
        // Display the cancellation popup
        const popup = document.getElementById('cancel-popup');
        const scheduleInput = document.getElementById('cancel-schedule-id');
        const canCancelFreeInput = document.getElementById('can-cancel-free');
        const warningDiv = document.getElementById('cancel-warning');
        const warningText = document.getElementById('cancel-warning-text');
        const feeNotice = document.getElementById('cancel-fee-notice');

        scheduleInput.value = scheduleId; // Set the schedule ID in the hidden input
        canCancelFreeInput.value = canCancelFree ? "1" : "0"; // Set whether cancellation is free

        // Show warning if applicable
        if (!canCancelFree && message) {
            warningDiv.classList.remove('hidden');
            warningText.textContent = message;
            feeNotice.classList.remove('hidden');
        } else {
            warningDiv.classList.add('hidden');
            feeNotice.classList.add('hidden');
        }

        popup.classList.remove('hidden'); // Show the popup
    }

    function closeCancelPopup() {
        const popup = document.getElementById('cancel-popup');
        popup.classList.add('hidden'); // Hide the popup
    }

    function handleOtherReason(value) {
        const otherReasonInput = document.getElementById('other-reason-input');
        if (value === 'other') {
            otherReasonInput.classList.remove('hidden'); // Show the text input
            otherReasonInput.setAttribute('required', 'required');
        } else {
            otherReasonInput.classList.add('hidden'); // Hide the text input
            otherReasonInput.removeAttribute('required');
        }
    }

    // New functions for rescheduling
    function rescheduleWorkout(scheduleId, canRescheduleFree, message) {
        // Display the rescheduling popup
        const popup = document.getElementById('reschedule-popup');
        const scheduleInput = document.getElementById('reschedule-schedule-id');
        const canRescheduleFreeInput = document.getElementById('can-reschedule-free');
        const warningDiv = document.getElementById('reschedule-warning');
        const warningText = document.getElementById('reschedule-warning-text');
        const feeNotice = document.getElementById('reschedule-fee-notice');

        scheduleInput.value = scheduleId; // Set the schedule ID in the hidden input
        canRescheduleFreeInput.value = canRescheduleFree ? "1" : "0"; // Set whether rescheduling is free

        // Show warning if applicable
        if (!canRescheduleFree && message) {
            warningDiv.classList.remove('hidden');
            warningText.textContent = message;
            feeNotice.classList.remove('hidden');
        } else {
            warningDiv.classList.add('hidden');
            feeNotice.classList.add('hidden');
        }

        // Set minimum date to tomorrow
        const tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 1);
        document.getElementById('reschedule-date').min = tomorrow.toISOString().split('T')[0];

        // Load available time slots for the selected date
        loadTimeSlots(scheduleId);

        popup.classList.remove('hidden'); // Show the popup
    }

    function closeReschedulePopup() {
        const popup = document.getElementById('reschedule-popup');
        popup.classList.add('hidden'); // Hide the popup
    }

    function handleOtherRescheduleReason(value) {
        const otherReasonInput = document.getElementById('other-reschedule-reason-input');
        if (value === 'other') {
            otherReasonInput.classList.remove('hidden'); // Show the text input
            otherReasonInput.setAttribute('required', 'required');
        } else {
            otherReasonInput.classList.add('hidden'); // Hide the text input
            otherReasonInput.removeAttribute('required');
        }
    }

    // Add or update this function in the JavaScript section of schedule-history.php
    function loadTimeSlots(scheduleId) {
        const dateInput = document.getElementById('reschedule-date');
        const timeSelect = document.getElementById('reschedule-time');

        // Clear existing options
        timeSelect.innerHTML = '<option value="" disabled selected>Loading time slots...</option>';

        // Get the selected date
        const selectedDate = dateInput.value;

        if (!selectedDate) {
            timeSelect.innerHTML = '<option value="" disabled selected>Please select a date first</option>';
            return;
        }

        // Show loading indicator
        const loadingIndicator = document.getElementById('loading-indicator');
        if (loadingIndicator) loadingIndicator.classList.remove('hidden');

        // Fetch available time slots for the selected date and gym
        fetch(`get_available_slots.php?schedule_id=${scheduleId}&date=${selectedDate}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                // Hide loading indicator
                if (loadingIndicator) loadingIndicator.classList.add('hidden');

                timeSelect.innerHTML = '<option value="" disabled selected>Select a time</option>';

                if (data.slots && data.slots.length > 0) {
                    data.slots.forEach(slot => {
                        const option = document.createElement('option');
                        option.value = slot.time;
                        option.textContent = `${slot.formatted_time} (${slot.available} spots available)`;
                        timeSelect.appendChild(option);
                    });
                } else {
                    timeSelect.innerHTML = '<option value="" disabled selected>No available time slots</option>';
                }
            })
            .catch(error => {
                console.error('Error loading time slots:', error);
                timeSelect.innerHTML = '<option value="" disabled selected>Error loading time slots</option>';
                if (loadingIndicator) loadingIndicator.classList.add('hidden');
            });
    }

    // Make sure this event listener is properly set up
    document.addEventListener('DOMContentLoaded', function () {
        const dateInput = document.getElementById('reschedule-date');
        if (dateInput) {
            dateInput.addEventListener('change', function () {
                const scheduleId = document.getElementById('reschedule-schedule-id').value;
                if (scheduleId) {
                    loadTimeSlots(scheduleId);
                }
            });
        }

        // Set default date when opening the reschedule modal
        const reschedulePopup = document.getElementById('reschedule-popup');
        if (reschedulePopup) {
            const observer = new MutationObserver(function (mutations) {
                mutations.forEach(function (mutation) {
                    if (mutation.attributeName === 'class' &&
                        !reschedulePopup.classList.contains('hidden')) {
                        // Modal was just opened
                        const tomorrow = new Date();
                        tomorrow.setDate(tomorrow.getDate() + 1);
                        const tomorrowStr = tomorrow.toISOString().split('T')[0];

                        if (dateInput && !dateInput.value) {
                            dateInput.value = tomorrowStr;
                            const scheduleId = document.getElementById('reschedule-schedule-id').value;
                            if (scheduleId) {
                                loadTimeSlots(scheduleId);
                            }
                        }
                    }
                });
            });

            observer.observe(reschedulePopup, { attributes: true });
        }
    });


    // Add event listener to date input to reload time slots when date changes
    document.addEventListener('DOMContentLoaded', function () {
        const dateInput = document.getElementById('reschedule-date');
        if (dateInput) {
            dateInput.addEventListener('change', function () {
                const scheduleId = document.getElementById('reschedule-schedule-id').value;
                loadTimeSlots(scheduleId);
            });
        }
    });

    // New functions for real-time updates
    document.addEventListener('DOMContentLoaded', function () {
        // Set up refresh button
        const refreshButton = document.getElementById('refresh-schedules');
        if (refreshButton) {
            refreshButton.addEventListener('click', function () {
                fetchSchedules();
            });
        }

        // Auto-refresh every 5 minutes (300000 ms)
        setInterval(fetchSchedules, 300000);

        // Initialize countdown timers
        updateCountdowns();
        setInterval(updateCountdowns, 1000);
    });

    function fetchSchedules() {
        const loadingIndicator = document.getElementById('loading-indicator');
        loadingIndicator.classList.remove('hidden');

        // Get current page parameters
        const urlParams = new URLSearchParams(window.location.search);
        const upcomingPage = urlParams.get('upcoming_page') || 1;
        const pastPage = urlParams.get('past_page') || 1;

        fetch(`/get_schedules.php?upcoming_page=${upcomingPage}&past_page=${pastPage}`)
            .then(response => response.json())
            .then(data => {
                updateScheduleDisplay(data);
                loadingIndicator.classList.add('hidden');
            })
            .catch(error => {
                console.error('Error fetching schedules:', error);
                loadingIndicator.classList.add('hidden');
            });
    }

    function updateScheduleDisplay(data) {
        // Update upcoming schedules
        updateScheduleSection('upcoming', data.upcoming.schedules, data.upcoming.pagination);

        // Update past schedules
        updateScheduleSection('past', data.past.schedules, data.past.pagination);
    }

    function updateScheduleSection(section, schedules, pagination) {
        const sectionContainer = document.getElementById(`${section}-schedules-container`);
        if (!sectionContainer) return;

        // Clear existing content
        sectionContainer.innerHTML = '';

        if (schedules.length === 0) {
            sectionContainer.innerHTML = `
            <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl p-8 text-center mb-12">
                <div class="text-yellow-400 text-lg mb-4">
                    No ${section} workouts ${section === 'upcoming' ? 'scheduled' : 'found'}.
                </div>
                ${section === 'upcoming' ? `
                <a href="schedule.php"
                   class="bg-yellow-400 text-black px-8 py-4 rounded-full font-bold hover:bg-yellow-500 transform hover:scale-105 transition-all duration-300 inline-block">
                    Schedule a Workout
                </a>
                ` : ''}
            </div>
        `;
            return;
        }

        // Create grid for schedules
        const gridDiv = document.createElement('div');
        gridDiv.className = 'grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-12';

        // Add each schedule card
        schedules.forEach(schedule => {
            const card = createScheduleCard(schedule, section);
            gridDiv.appendChild(card);
        });

        sectionContainer.appendChild(gridDiv);

        // Update pagination
        const paginationContainer = document.getElementById(`${section}-pagination`);
        if (paginationContainer) {
            updatePagination(paginationContainer, pagination, section);
        }
    }

    function createScheduleCard(schedule, section) {
        const isUpcoming = section === 'upcoming';
        const cardDiv = document.createElement('div');
        cardDiv.className = 'bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl overflow-hidden transform hover:scale-[1.02] transition-transform duration-300';

        // Determine header style based on section
        const headerClass = isUpcoming ? 'bg-gradient-to-r from-yellow-400 to-yellow-500' : 'bg-gradient-to-r from-gray-700 to-gray-600';
        const headerTextClass = isUpcoming ? 'text-gray-900' : 'text-white';

        // Determine status badge color
        let statusBadgeClass = '';
        switch (schedule.status) {
            case 'scheduled':
                statusBadgeClass = 'bg-green-900 text-green-100';
                break;
            case 'completed':
                statusBadgeClass = 'bg-blue-900 text-blue-100';
                break;
            case 'cancelled':
                statusBadgeClass = 'bg-red-900 text-red-100';
                break;
            case 'missed':
                statusBadgeClass = 'bg-yellow-900 text-yellow-100';
                break;
            default:
                statusBadgeClass = 'bg-gray-900 text-gray-100';
        }

        // Format date and time
        const formattedDate = new Date(schedule.start_date).toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric'
        });

        const formattedTime = new Date(`${schedule.start_date}T${schedule.start_time}`).toLocaleTimeString('en-US', {
            hour: 'numeric',
            minute: '2-digit',
            hour12: true
        });

        // Calculate time until workout for policy enforcement
        let canReschedule = true;
        let canCancel = true;
        let rescheduleMessage = "";
        let cancelMessage = "";

        if (isUpcoming && schedule.status === 'scheduled') {
            const workoutDateTime = new Date(`${schedule.start_date}T${schedule.start_time}`);
            const currentDateTime = new Date();
            const hoursUntilWorkout = (workoutDateTime - currentDateTime) / (1000 * 60 * 60);

            canReschedule = hoursUntilWorkout >= 2;
            canCancel = hoursUntilWorkout >= 4;
            rescheduleMessage = !canReschedule ? "Rescheduling is only available up to 2 hours before your workout." : "";
            cancelMessage = !canCancel ? "Cancellation is only available up to 4 hours before your workout to avoid fees." : "";
        }

        // Build card HTML
        let cardHTML = `
        <!-- Header Section -->
        <div class="p-6 ${headerClass}">
            <div class="flex justify-between items-center">
                <h3 class="text-xl font-bold ${headerTextClass}">
                    ${escapeHtml(schedule.gym_name)}
                </h3>
                <span class="px-4 py-1 rounded-full text-sm font-medium ${statusBadgeClass}">
                    ${capitalizeFirstLetter(schedule.status)}
                </span>
            </div>
        </div>

        <!-- Details Section -->
        <div class="p-6">
            <div class="${isUpcoming ? '' : 'grid grid-cols gap-6'}">
                <div class="space-y-4">
                    <div>
                        <label class="text-yellow-400 text-sm">Date & Time</label>
                        <p class="text-white text-lg">
                            ${formattedDate}<br>
                            ${formattedTime}
                        </p>
                    </div>`;

        // Add countdown timer for upcoming workouts
        if (isUpcoming && schedule.status === 'scheduled') {
            cardHTML += `
            <div class="mt-4 p-3 bg-gray-900 bg-opacity-50 rounded-lg">
                <label class="text-yellow-400 text-sm">Time Until Workout</label>
                <div class="countdown-timer text-white font-mono text-lg" 
                     data-date="${schedule.start_date}" 
                     data-time="${schedule.start_time}">
                    Loading...
                </div>
            </div>`;
        }

        cardHTML += `
                    <div>
                        <label class="text-yellow-400 text-sm">Location</label>
                        <p class="text-white">
                            ${escapeHtml(schedule.address)}<br>
                            ${escapeHtml(schedule.city)}, ${escapeHtml(schedule.state)}
                            ${escapeHtml(schedule.zip_code)}
                        </p>
                    </div>
                    ${schedule.trainer_name ? `
                    <div>
                        <label class="text-yellow-400 text-sm">Trainer</label>
                        <p class="text-white">
                            ${escapeHtml(schedule.trainer_name)}
                        </p>
                    </div>
                    ` : ''}`;

        // Add missed workout fee notice
        if (schedule.status === 'missed') {
            cardHTML += `
            <div>
                <label class="text-red-400 text-sm">Late Fee</label>
                <p class="text-white">
                    A late fee may have been charged for this missed workout.
                    Check your transaction history for details.
                </p>
            </div>`;
        }

        cardHTML += `
                </div>

                <div class="space-y-4">
                    ${schedule.recurring !== 'none' ? `
                        <div>
                            <label class="text-yellow-400 text-sm">Recurring Schedule</label>
                            <p class="text-white">
                                ${capitalizeFirstLetter(schedule.recurring)} workout until
                                ${new Date(schedule.recurring_until).toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric'
        })}
                                ${schedule.days_of_week ? `
                                    <br>Days: ${JSON.parse(schedule.days_of_week).map(day => capitalizeFirstLetter(day)).join(', ')}
                                ` : ''}
                            </p>
                        </div>
                    ` : ''}

                    ${schedule.notes ? `
                        <div>
                            <label class="text-yellow-400 text-sm">Notes</label>
                            <p class="text-white italic">${escapeHtml(schedule.notes)}</p>
                        </div>
                    ` : ''}
                </div>
            </div>`;

        // Add action buttons for upcoming scheduled workouts
        if (isUpcoming && schedule.status === 'scheduled') {
            cardHTML += `
            <div class="mt-6 flex flex-col space-y-4">
                <div class="flex justify-end space-x-4">
                    <button onclick="rescheduleWorkout(${schedule.id}, ${canReschedule}, '${rescheduleMessage}')"
                            class="bg-blue-600 text-white px-6 py-3 rounded-full font-bold hover:bg-blue-700 transform hover:scale-105 transition-all duration-300 ${!canReschedule ? 'opacity-50 cursor-not-allowed' : ''}">
                        <i class="fas fa-calendar-alt mr-2"></i>Reschedule
                    </button>
                    <button onclick="cancelSchedule(${schedule.id}, ${canCancel}, '${cancelMessage}')"
                            class="bg-red-700 text-white px-6 py-3 rounded-full font-bold hover:bg-red-600 transform hover:scale-105 transition-all duration-300 ${!canCancel ? 'opacity-50 cursor-not-allowed' : ''}">
                        <i class="fas fa-times mr-2"></i>Cancel
                    </button>
                </div>
                ${(!canReschedule || !canCancel) ? `
                    <div class="text-yellow-400 text-sm text-center mt-2">
                        <i class="fas fa-exclamation-triangle mr-1"></i>
                        ${!canReschedule ? rescheduleMessage : ''}
                        ${!canCancel ? cancelMessage : ''}
                    </div>
                ` : ''}
            </div>`;
        }

        cardHTML += `
        </div>
    `;

        cardDiv.innerHTML = cardHTML;
        return cardDiv;
    }

    function updatePagination(container, pagination, section) {
        const { current_page, total_pages } = pagination;
        const otherSection = section === 'upcoming' ? 'past' : 'upcoming';
        const otherPage = new URLSearchParams(window.location.search).get(`${otherSection}_page`) || 1;

        container.innerHTML = '';

        if (total_pages <= 1) return;

        const nav = document.createElement('nav');
        nav.className = 'flex items-center space-x-4';

        // Previous button
        if (current_page > 1) {
            const prevLink = document.createElement('a');
            prevLink.href = `?${section}_page=${current_page - 1}&${otherSection}_page=${otherPage}`;
            prevLink.className = 'bg-gray-700 text-white px-6 py-3 rounded-full font-bold hover:bg-gray-600 transform hover:scale-105 transition-all duration-300';
            prevLink.textContent = 'Previous';
            prevLink.addEventListener('click', function (e) {
                e.preventDefault();
                updateUrlParam(`${section}_page`, current_page - 1);
                fetchSchedules();
            });
            nav.appendChild(prevLink);
        }

        // Page numbers
        for (let i = Math.max(1, current_page - 1); i <= Math.min(total_pages, current_page + 1); i++) {
            const pageLink = document.createElement('a');
            pageLink.href = `?${section}_page=${i}&${otherSection}_page=${otherPage}`;
            pageLink.className = `${i == current_page
                ? 'bg-yellow-400 text-black'
                : 'bg-gray-700 text-white hover:bg-gray-600'} 
            px-6 py-3 rounded-full font-bold transform hover:scale-105 transition-all duration-300`;
            pageLink.textContent = i;
            pageLink.addEventListener('click', function (e) {
                e.preventDefault();
                updateUrlParam(`${section}_page`, i);
                fetchSchedules();
            });
            nav.appendChild(pageLink);
        }

        // Next button
        if (current_page < total_pages) {
            const nextLink = document.createElement('a');
            nextLink.href = `?${section}_page=${current_page + 1}&${otherSection}_page=${otherPage}`;
            nextLink.className = 'bg-gray-700 text-white px-6 py-3 rounded-full font-bold hover:bg-gray-600 transform hover:scale-105 transition-all duration-300';
            nextLink.textContent = 'Next';
            nextLink.addEventListener('click', function (e) {
                e.preventDefault();
                updateUrlParam(`${section}_page`, current_page + 1);
                fetchSchedules();
            });
            nav.appendChild(nextLink);
        }

        container.appendChild(nav);
    }

    function updateUrlParam(param, value) {
        const url = new URL(window.location);
        url.searchParams.set(param, value);
        window.history.pushState({}, '', url);
    }

    function escapeHtml(unsafe) {
        return unsafe
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    function capitalizeFirstLetter(string) {
        return string.charAt(0).toUpperCase() + string.slice(1);
    }

    // Function to update all countdown timers
    function updateCountdowns() {
        const countdownElements = document.querySelectorAll('.countdown-timer');

        countdownElements.forEach(element => {
            const workoutDate = element.getAttribute('data-date');
            const workoutTime = element.getAttribute('data-time');

            const workoutDateTime = new Date(`${workoutDate}T${workoutTime}`);
            const now = new Date();

            const diff = workoutDateTime - now;

            if (diff <= 0) {
                element.innerHTML = '<span class="text-red-400">Workout time has arrived!</span>';
                return;
            }

            // Calculate days, hours, minutes, seconds
            const days = Math.floor(diff / (1000 * 60 * 60 * 24));
            const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((diff % (1000 * 60)) / 1000);

            // Format the countdown display
            let countdownText = '';

            if (days > 0) {
                countdownText += `${days}d `;
            }

            countdownText += `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;

            element.textContent = countdownText;
        });
    }

    // Check for browser notification support and request permission
    document.addEventListener('DOMContentLoaded', function () {
        if ('Notification' in window) {
            if (Notification.permission !== 'granted' && Notification.permission !== 'denied') {
                // Request permission for notifications
                Notification.requestPermission().then(function (permission) {
                    if (permission === 'granted') {
                        console.log('Notification permission granted');
                    }
                });
            }
        }

        // Check for upcoming workouts and set notifications
        checkUpcomingWorkouts();
    });

    function checkUpcomingWorkouts() {
        fetch('get_upcoming_notifications.php')
            .then(response => response.json())
            .then(data => {
                if (data.upcoming_workouts) {
                    data.upcoming_workouts.forEach(workout => {
                        scheduleWorkoutNotification(workout);
                    });
                }
            })
            .catch(error => console.error('Error fetching upcoming workouts:', error));
    }

    function scheduleWorkoutNotification(workout) {
        const workoutTime = new Date(`${workout.start_date}T${workout.start_time}`);
        const now = new Date();

        // Calculate time until workout in milliseconds
        const timeUntilWorkout = workoutTime - now;

        // If workout is in the future and within 24 hours
        if (timeUntilWorkout > 0 && timeUntilWorkout <= 24 * 60 * 60 * 1000) {
            // Schedule notification for 1 hour before workout
            const notificationTime = timeUntilWorkout - (60 * 60 * 1000);

            if (notificationTime > 0) {
                setTimeout(() => {
                    showWorkoutNotification(workout);
                }, notificationTime);
            } else {
                // If less than 1 hour until workout, notify now
                showWorkoutNotification(workout);
            }
        }
    }

    function showWorkoutNotification(workout) {
        if (Notification.permission === 'granted') {
            const workoutTime = new Date(`${workout.start_date}T${workout.start_time}`);
            const formattedTime = workoutTime.toLocaleTimeString('en-US', {
                hour: 'numeric',
                minute: '2-digit',
                hour12: true
            });

            const notification = new Notification('Upcoming Workout Reminder', {
                body: `You have a workout scheduled at ${formattedTime} at ${workout.gym_name}`,
                icon: '/assets/images/logo.png'
            });

            notification.onclick = function () {
                window.focus();
                this.close();
            };
        }
    }
</script>