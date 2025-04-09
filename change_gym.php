<?php
require 'config/database.php';

// Ensure user is logged in before processing anything else
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$db = new GymDatabase();
$conn = $db->getConnection();

// Get schedule ID from URL parameter
$schedule_id = filter_input(INPUT_GET, 'schedule_id', FILTER_VALIDATE_INT);

if (!$schedule_id) {
    $_SESSION['error'] = "Invalid schedule ID.";
    header('Location: schedule-history.php');
    exit;
}

// Fetch the schedule details
$scheduleStmt = $conn->prepare("
    SELECT s.*, g.name as current_gym_name, g.city as current_gym_city, g.state as current_gym_state
    FROM schedules s
    JOIN gyms g ON s.gym_id = g.gym_id
    WHERE s.id = ? AND s.user_id = ? AND s.status = 'scheduled'
");
$scheduleStmt->execute([$schedule_id, $user_id]);
$schedule = $scheduleStmt->fetch(PDO::FETCH_ASSOC);

if (!$schedule) {
    $_SESSION['error'] = "Schedule not found or you don't have permission to modify it.";
    header('Location: schedule-history.php');
    exit;
}

// Check if the workout has already started
$currentDateTime = new DateTime();
$workoutDateTime = new DateTime($schedule['start_date'] . ' ' . $schedule['start_time']);

if ($currentDateTime >= $workoutDateTime) {
    $_SESSION['error'] = "Cannot change gym for a workout that has already started or passed.";
    header('Location: schedule-history.php');
    exit;
}

// Check time policy (2 hours before workout)
$interval = $currentDateTime->diff($workoutDateTime);
$hoursUntilWorkout = ($interval->days * 24) + $interval->h;

$canChangeGym = $hoursUntilWorkout >= 2;
if (!$canChangeGym) {
    $_SESSION['error'] = "Gym changes are only allowed up to 2 hours before your scheduled workout.";
    header('Location: schedule-history.php');
    exit;
}

// Get user's active membership for the current gym
$membershipStmt = $conn->prepare("
    SELECT um.id, um.gym_id, um.plan_id, um.start_date, um.end_date, 
           gmp.plan_name, gmp.tier, gmp.duration, gmp.price, gmp.plan_type,
           g.name as gym_name
    FROM user_memberships um
    JOIN gym_membership_plans gmp ON um.plan_id = gmp.plan_id
    JOIN gyms g ON gmp.gym_id = g.gym_id
    WHERE um.user_id = ?
    AND um.status = 'active'
    AND um.end_date >= CURRENT_DATE()
    AND (
        um.gym_id = ? OR 
        gmp.plan_type = 'multi_gym'
    )
    ORDER BY gmp.price DESC
    LIMIT 1
");
$membershipStmt->execute([$user_id, $schedule['gym_id']]);
$membership = $membershipStmt->fetch(PDO::FETCH_ASSOC);

if (!$membership) {
    $_SESSION['error'] = "You don't have an active membership that allows changing gyms.";
    header('Location: schedule-history.php');
    exit;
}

// Calculate normalized monthly price for comparison
$monthlyPrice = 0;
switch (strtolower($membership['duration'])) {
    case 'daily':
        $monthlyPrice = $membership['price'] * 30;
        break;
    case 'weekly':
        $monthlyPrice = $membership['price'] * 4;
        break;
    case 'monthly':
        $monthlyPrice = $membership['price'];
        break;
    case 'quartrly': // Note: This matches the SQL enum value which has a typo
    case 'quarterly':
        $monthlyPrice = $membership['price'] / 3;
        break;
    case 'half yearly':
        $monthlyPrice = $membership['price'] / 6;
        break;
    case 'yearly':
        $monthlyPrice = $membership['price'] / 12;
        break;
    default:
        $monthlyPrice = $membership['price'];
}

// Process form submission for changing gym
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_gym_id'])) {
    $new_gym_id = filter_input(INPUT_POST, 'new_gym_id', FILTER_VALIDATE_INT);
    $new_time = filter_input(INPUT_POST, 'start_time', FILTER_SANITIZE_STRING);
    $change_reason = filter_input(INPUT_POST, 'change_reason', FILTER_SANITIZE_STRING);
    
    if (!$new_gym_id || !$new_time) {
        $_SESSION['error'] = "Please select a gym and time slot.";
    } else {
        try {
            $conn->beginTransaction();
            
            // Get new gym details
            $newGymStmt = $conn->prepare("SELECT name FROM gyms WHERE gym_id = ?");
            $newGymStmt->execute([$new_gym_id]);
            $newGym = $newGymStmt->fetch(PDO::FETCH_ASSOC);
            
            // Check if the new time slot is available at the new gym
            $occupancyStmt = $conn->prepare("
                SELECT COUNT(*) 
                FROM schedules 
                WHERE gym_id = ? 
                AND start_date = ? 
                AND start_time = ?
            ");
            $occupancyStmt->execute([$new_gym_id, $schedule['start_date'], $new_time]);
            $occupancy = $occupancyStmt->fetchColumn();
            
            if ($occupancy >= 50) { // Assuming max capacity is 50
                throw new Exception("The selected time slot at the new gym is no longer available.");
            }
            
            // Store old values for logging
            $old_gym_id = $schedule['gym_id'];
            $old_time = $schedule['start_time'];
            
            // Update the schedule
            $updateStmt = $conn->prepare("
                UPDATE schedules 
                SET gym_id = ?, start_time = ?, 
                    notes = CONCAT(IFNULL(notes, ''), '\n[Changed Gym] ', ?)
                WHERE id = ?
            ");
            $change_note = "Changed from {$schedule['current_gym_name']} to {$newGym['name']}. " . 
                          "Time changed from " . date('g:i A', strtotime($old_time)) . 
                          " to " . date('g:i A', strtotime($new_time)) . 
                          ". Reason: " . $change_reason;
            $updateStmt->execute([$new_gym_id, $new_time, $change_note, $schedule_id]);
            
            // Log the change
            $logStmt = $conn->prepare("
                INSERT INTO schedule_logs (
                    schedule_id, user_id, action_type, 
                    old_gym_id, new_gym_id, 
                    old_time, new_time, 
                    notes, created_at
                ) VALUES (?, ?, 'update', ?, ?, ?, ?, ?, NOW())
            ");
            $logStmt->execute([
                $schedule_id, $user_id, $old_gym_id, $new_gym_id, 
                $old_time, $new_time, 
                "Changed gym and time. Reason: " . $change_reason
            ]);
            
            $conn->commit();
            
            $_SESSION['success'] = "Your workout has been successfully moved to {$newGym['name']}.";
            header('Location: schedule-history.php');
            exit;
            
        } catch (Exception $e) {
            $conn->rollBack();
            $_SESSION['error'] = $e->getMessage();
        }
    }
}

// Search parameters
$search = $_GET['search'] ?? '';
$searchCity = $_GET['city'] ?? '';
$searchState = $_GET['state'] ?? '';

// Fetch distinct cities for filter
$citiesQuery = "SELECT DISTINCT city FROM gyms WHERE status = 'active' ORDER BY city ASC";
$citiesStmt = $conn->prepare($citiesQuery);
$citiesStmt->execute();
$cities = $citiesStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch compatible gyms based on membership price
$gymsQuery = "
    SELECT DISTINCT g.*, 
           gmp.price as plan_price,
           gmp.duration as plan_duration,
           gmp.plan_name,
           gmp.tier as plan_tier,
           gmp.plan_type,
           CASE 
               WHEN g.is_open = 1 THEN 'Open'
               ELSE 'Closed'
           END as open_status,
           (
               SELECT COUNT(*) 
               FROM schedules 
               WHERE gym_id = g.gym_id 
               AND start_date = :start_date
               AND start_time = :start_time
           ) as current_occupancy
    FROM gyms g
    JOIN gym_membership_plans gmp ON g.gym_id = gmp.gym_id
    WHERE g.status = 'active'
    AND g.gym_id != :current_gym_id -- Exclude current gym
    AND (
        (:search = '' OR g.name LIKE :search)
        AND (:city = '' OR g.city = :city)
        AND (:state = '' OR g.state LIKE :state)
    )
";

$stmt = $conn->prepare($gymsQuery);
$stmt->execute([
    ':start_date' => $schedule['start_date'],
    ':start_time' => $schedule['start_time'],
    ':current_gym_id' => $schedule['gym_id'],
    ':search' => $search ? "%{$search}%" : '',
    ':city' => $searchCity ?: '',
    ':state' => $searchState ? "%{$searchState}%" : '',
]);


$allGyms = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Filter gyms based on membership price
$compatibleGyms = [];
$seenGymIds = [];

foreach ($allGyms as $gym) {
    // Skip if we've already added this gym
    if (in_array($gym['gym_id'], $seenGymIds)) {
        continue;
    }
    
    // Calculate normalized monthly price for this gym's plan
    $gymMonthlyPrice = 0;
    switch (strtolower($gym['plan_duration'])) {
        case 'daily':
            $gymMonthlyPrice = $gym['plan_price'] * 30;
            break;
        case 'weekly':
            $gymMonthlyPrice = $gym['plan_price'] * 4;
            break;
        case 'monthly':
            $gymMonthlyPrice = $gym['plan_price'];
            break;
        case 'quartrly': // Note: This matches the SQL enum value which has a typo
        case 'quarterly':
            $gymMonthlyPrice = $gym['plan_price'] / 3;
            break;
        case 'half yearly':
            $gymMonthlyPrice = $gym['plan_price'] / 6;
            break;
        case 'yearly':
            $gymMonthlyPrice = $gym['plan_price'] / 12;
            break;
        default:
            $gymMonthlyPrice = $gym['plan_price'];
    }
    
    // Check if user's membership price is sufficient for this gym
    if ($gymMonthlyPrice <= $monthlyPrice) {
        // Get operating hours for this gym
        $hoursStmt = $conn->prepare("
            SELECT day, morning_open_time, morning_close_time, evening_open_time, evening_close_time
            FROM gym_operating_hours
            WHERE gym_id = ?
        ");
        $hoursStmt->execute([$gym['gym_id']]);
        $gym['operating_hours'] = $hoursStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get available time slots for this gym
        $timeSlots = [];
        $workoutDate = new DateTime($schedule['start_date']);
        $dayOfWeek = $workoutDate->format('l'); // Get day name (Monday, Tuesday, etc.)
        
        // First check for specific day
        $dayHours = array_filter($gym['operating_hours'], function($hours) use ($dayOfWeek) {
            return $hours['day'] === $dayOfWeek;
        });
        
        // If no specific day found, check for "Daily" hours
        if (empty($dayHours)) {
            $dayHours = array_filter($gym['operating_hours'], function($hours) {
                return $hours['day'] === 'Daily';
            });
        }
        
        if (!empty($dayHours)) {
            $hours = reset($dayHours); // Get first element
            
            // Generate time slots from operating hours
            $morning_start = strtotime($hours['morning_open_time']);
            $morning_end = strtotime($hours['morning_close_time']);
            $evening_start = strtotime($hours['evening_open_time']);
            $evening_end = strtotime($hours['evening_close_time']);
            
            for ($time = $morning_start; $time <= $morning_end; $time += 3600) {
                $timeSlots[] = date('H:i:s', $time);
            }
            for ($time = $evening_start; $time <= $evening_end; $time += 3600) {
                $timeSlots[] = date('H:i:s', $time);
            }
            
            // Check occupancy for each time slot
            $occupancyStmt = $conn->prepare("
                SELECT start_time, COUNT(*) as current_occupancy 
                FROM schedules 
                WHERE gym_id = ? 
                AND start_date = ? 
                GROUP BY start_time
            ");
            $occupancyStmt->execute([$gym['gym_id'], $schedule['start_date']]);
            $occupancyByTime = $occupancyStmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            $gym['time_slots'] = [];
            foreach ($timeSlots as $time) {
                $occupancy = isset($occupancyByTime[$time]) ? $occupancyByTime[$time] : 0;
                $gym['time_slots'][] = [
                    'time' => $time,
                    'formatted_time' => date('g:i A', strtotime($time)),
                    'occupancy' => $occupancy,
                    'is_full' => $occupancy >= 50, // Assuming max capacity is 50
                    'is_current' => $time === $schedule['start_time']
                ];
            }
        }
        
        $compatibleGyms[] = $gym;
        $seenGymIds[] = $gym['gym_id'];
    }
}

// Get incompatible gyms for display
$incompatibleGyms = [];
foreach ($allGyms as $gym) {
    if (!in_array($gym['gym_id'], $seenGymIds)) {
        // Calculate normalized monthly price for this gym's plan
        $gymMonthlyPrice = 0;
        switch (strtolower($gym['plan_duration'])) {
            case 'daily':
                $gymMonthlyPrice = 0;
                switch (strtolower($gym['plan_duration'])) {
                    case 'daily':
                        $gymMonthlyPrice = $gym['plan_price'] * 30;
                        break;
                    case 'weekly':
                        $gymMonthlyPrice = $gym['plan_price'] * 4;
                        break;
                    case 'monthly':
                        $gymMonthlyPrice = $gym['plan_price'];
                        break;
                    case 'quartrly': // Note: This matches the SQL enum value which has a typo
                    case 'quarterly':
                        $gymMonthlyPrice = $gym['plan_price'] / 3;
                        break;
                    case 'half yearly':
                        $gymMonthlyPrice = $gym['plan_price'] / 6;
                        break;
                    case 'yearly':
                        $gymMonthlyPrice = $gym['plan_price'] / 12;
                        break;
                    default:
                        $gymMonthlyPrice = $gym['plan_price'];
                }
                
                $gym['monthly_price'] = $gymMonthlyPrice;
                $incompatibleGyms[] = $gym;
                $seenGymIds[] = $gym['gym_id'];
            }
        }
    }
        include 'includes/navbar.php';
        ?>
        
        <div class="min-h-screen bg-gradient-to-b from-gray-900 to-black py-12">
            <div class="container mx-auto px-4 sm:px-6 lg:px-8 pt-24 pb-12">
                <!-- Page Header -->
                <div class="text-center mb-12">
                    <h1 class="text-4xl font-bold text-white mb-4">Change Gym Location</h1>
                    <p class="text-gray-300 text-lg max-w-3xl mx-auto">
                        Move your scheduled workout to a different gym based on your membership.
                    </p>
                </div>
        
                <!-- Current Schedule Info -->
                <div class="bg-gray-800 rounded-xl p-6 mb-8 shadow-lg">
                    <h2 class="text-2xl font-bold text-white mb-4">Current Workout Details</h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <p class="text-gray-400 text-sm mb-1">Current Gym</p>
                            <p class="text-white text-lg font-medium mb-4"><?= htmlspecialchars($schedule['current_gym_name']) ?></p>
                            
                            <p class="text-gray-400 text-sm mb-1">Location</p>
                            <p class="text-white mb-4"><?= htmlspecialchars($schedule['current_gym_city']) ?>, <?= htmlspecialchars($schedule['current_gym_state']) ?></p>
                        </div>
                        
                        <div>
                            <p class="text-gray-400 text-sm mb-1">Date</p>
                            <p class="text-white text-lg font-medium mb-4"><?= date('l, F j, Y', strtotime($schedule['start_date'])) ?></p>
                            
                            <p class="text-gray-400 text-sm mb-1">Time</p>
                            <p class="text-white mb-4"><?= date('g:i A', strtotime($schedule['start_time'])) ?></p>
                        </div>
                    </div>
                    
                    <div class="mt-4 p-4 bg-yellow-500 bg-opacity-20 rounded-lg">
                        <div class="flex items-start">
                            <div class="text-yellow-500 mr-3 text-xl">
                                <i class="fas fa-info-circle"></i>
                            </div>
                            <div>
                                <p class="text-yellow-500 font-medium">Important Information</p>
                                <p class="text-gray-300">You can only change to gyms that are compatible with your current membership plan. Your current membership allows access to gyms with monthly fees up to ₹<?= number_format($monthlyPrice, 2) ?>.</p>
                            </div>
                        </div>
                    </div>
                </div>
        
                <!-- Membership Info -->
                <div class="bg-gray-800 rounded-xl p-6 mb-8 shadow-lg">
                    <h2 class="text-2xl font-bold text-white mb-4">Your Active Membership</h2>
                    
                    <div class="flex flex-col md:flex-row justify-between items-start md:items-center bg-gray-700 rounded-lg p-4">
                        <div>
                            <p class="text-white font-medium"><?= htmlspecialchars($membership['plan_name']) ?></p>
                            <p class="text-gray-300">
                                <?= htmlspecialchars($membership['gym_name']) ?> • 
                                <?= htmlspecialchars($membership['tier']) ?> • 
                                <?= htmlspecialchars($membership['duration']) ?>
                            </p>
                            <p class="text-yellow-400 mt-1">₹<?= number_format($membership['price'], 2) ?></p>
                        </div>
                        <div class="mt-3 md:mt-0">
                            <span class="px-3 py-1 bg-green-500 text-white rounded-full text-sm">Active</span>
                            <p class="text-gray-400 text-sm mt-1">Valid until: <?= date('M j, Y', strtotime($membership['end_date'])) ?></p>
                        </div>
                    </div>
                </div>
        
                <!-- Search Filters -->
                <div class="bg-gray-800 rounded-xl p-6 mb-8 shadow-lg">
                    <h2 class="text-2xl font-bold text-white mb-4">Find a New Gym</h2>
                    
                    <form method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <input type="hidden" name="schedule_id" value="<?= $schedule_id ?>">
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Gym Name</label>
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                                   class="w-full px-4 py-2 bg-gray-700 border border-gray-600 rounded-lg text-white focus:border-yellow-400 focus:outline-none">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">City</label>
                            <select name="city" class="w-full px-4 py-2 bg-gray-700 border border-gray-600 rounded-lg text-white focus:border-yellow-400 focus:outline-none">
                                <option value="">All Cities</option>
                                <?php foreach ($cities as $city): ?>
                                    <option value="<?= htmlspecialchars($city['city']) ?>" <?= $searchCity === $city['city'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($city['city']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">State</label>
                            <input type="text" name="state" value="<?= htmlspecialchars($searchState) ?>" 
                                   class="w-full px-4 py-2 bg-gray-700 border border-gray-600 rounded-lg text-white focus:border-yellow-400 focus:outline-none">
                        </div>
                        
                        <div class="md:col-span-3">
                            <button type="submit" class="w-full bg-yellow-500 hover:bg-yellow-400 text-black font-bold py-3 px-6 rounded-lg transition-colors duration-300">
                                <i class="fas fa-search mr-2"></i>Search Gyms
                            </button>
                        </div>
                    </form>
                </div>
        
                <!-- Compatible Gyms -->
                <h2 class="text-2xl font-bold text-white mb-6">Compatible Gyms (<?= count($compatibleGyms) ?>)</h2>
                
                <?php if (empty($compatibleGyms)): ?>
                    <div class="bg-gray-800 rounded-xl p-8 text-center mb-12">
                        <div class="text-yellow-500 text-5xl mb-4">
                            <i class="fas fa-search"></i>
                        </div>
                        <h3 class="text-xl font-bold text-white mb-2">No Compatible Gyms Found</h3>
                        <p class="text-gray-400 mb-4">Try adjusting your search criteria or check other membership options.</p>
                        <a href="schedule-history.php" class="inline-block bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded-lg transition-colors duration-300">
                            Back to Schedule
                        </a>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-12">
                        <?php foreach ($compatibleGyms as $gym): ?>
                            <?php 
                                // Check if gym is open
                                $isOpen = $gym['open_status'] === 'Open';
                                $statusClass = $isOpen ? 'bg-green-500' : 'bg-red-500';
                            ?>
                            <div class="bg-gray-800 rounded-xl overflow-hidden shadow-lg transform transition-all duration-300 hover:scale-[1.02] hover:shadow-xl">
                                <div class="relative h-48 overflow-hidden">
                                    <img src="<?= $gym['image_url'] ?? 'assets/images/gym-placeholder.jpg' ?>" 
                                         alt="<?= htmlspecialchars($gym['name']) ?>" 
                                         class="w-full h-full object-cover">
                                    <div class="absolute top-0 left-0 m-3">
                                        <span class="<?= $statusClass ?> text-white px-3 py-1 rounded-full text-xs font-bold">
                                            <?= $gym['open_status'] ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <div class="p-6">
                                    <div class="flex justify-between items-start mb-2">
                                        <h3 class="text-xl font-bold text-white"><?= htmlspecialchars($gym['name']) ?></h3>
                                        <div class="bg-gray-700 rounded-full px-3 py-1 text-sm text-yellow-400 font-medium">
                                            ₹<?= number_format($gym['plan_price'], 2) ?>/<?= strtolower($gym['plan_duration']) ?>
                                        </div>
                                    </div>
                                    
                                    <p class="text-gray-400 mb-4">
                                        <i class="fas fa-map-marker-alt mr-1"></i>
                                        <?= htmlspecialchars($gym['city']) ?>, <?= htmlspecialchars($gym['state']) ?>
                                    </p>
                                    
                                    <div class="mb-4">
                                        <p class="text-sm font-medium text-gray-300 mb-2">Available Time Slots:</p>
                                        <?php if (!empty($gym['time_slots'])): ?>
                                            <form id="gym-form-<?= $gym['gym_id'] ?>" method="POST" action="change_gym.php?schedule_id=<?= $schedule_id ?>">
                                                <input type="hidden" name="new_gym_id" value="<?= $gym['gym_id'] ?>">
                                                <input type="hidden" name="change_reason" value="Preferred this gym location">
                                                
                                                <select name="start_time" class="w-full px-3 py-2 bg-gray-700 border border-gray-600 rounded-lg text-white focus:border-yellow-400 focus:outline-none mb-4">
                                                    <?php foreach ($gym['time_slots'] as $slot): ?>
                                                        <option value="<?= $slot['time'] ?>" <?= $slot['is_full'] ? 'disabled' : '' ?> <?= $slot['is_current'] ? 'selected' : '' ?>>
                                                            <?= $slot['formatted_time'] ?> (<?= $slot['occupancy'] ?>/50 members)
                                                            <?= $slot['is_full'] ? ' - FULL' : '' ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                
                                                <button type="submit" <?= !$isOpen ? 'disabled' : '' ?> 
                                                        class="w-full bg-blue-600 hover:bg-blue-700 text-white px-4 py-3 rounded-lg font-bold transition-colors duration-300 <?= !$isOpen ? 'opacity-50 cursor-not-allowed' : '' ?>">
                                                    <?= $isOpen ? 'Select This Gym' : 'Gym Currently Closed' ?>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <p class="text-red-400">No available time slots for this date.</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
                <!-- Incompatible Gyms -->
                <?php if (!empty($incompatibleGyms)): ?>
                    <h2 class="text-2xl font-bold text-white mb-6">Upgrade Required (<?= count($incompatibleGyms) ?>)</h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-12">
                        <?php foreach ($incompatibleGyms as $gym): ?>
                            <div class="bg-gray-800 rounded-xl overflow-hidden shadow-lg relative">
                                <div class="absolute inset-0 bg-black bg-opacity-50 flex items-center justify-center z-10">
                                    <div class="text-center p-6">
                                        <span class="bg-yellow-500 text-black px-4 py-2 rounded-full text-sm font-bold mb-4 inline-block">
                                            Membership Upgrade Required
                                        </span>
                                        <p class="text-white mb-4">This gym requires a membership of at least ₹<?= number_format($gym['monthly_price'], 2) ?>/month</p>
                                        <a href="view_membership.php?gym_id=<?= $gym['gym_id'] ?>" class="bg-yellow-500 hover:bg-yellow-400 text-black px-4 py-2 rounded-lg font-bold transition-colors duration-300 inline-block">
                                            View Membership Options
                                        </a>
                                    </div>
                                </div>
                                
                                <div class="relative h-48 overflow-hidden opacity-50">
                            <img src="<?= $gym['image_url'] ?? 'assets/images/gym-placeholder.jpg' ?>" 
                                 alt="<?= htmlspecialchars($gym['name']) ?>" 
                                 class="w-full h-full object-cover">
                        </div>
                        
                        <div class="p-6 opacity-50">
                            <div class="flex justify-between items-start mb-2">
                                <h3 class="text-xl font-bold text-white"><?= htmlspecialchars($gym['name']) ?></h3>
                                <div class="bg-gray-700 rounded-full px-3 py-1 text-sm text-yellow-400 font-medium">
                                    ₹<?= number_format($gym['plan_price'], 2) ?>/<?= strtolower($gym['plan_duration']) ?>
                                </div>
                            </div>
                            
                            <p class="text-gray-400 mb-4">
                                <i class="fas fa-map-marker-alt mr-1"></i>
                                <?= htmlspecialchars($gym['city']) ?>, <?= htmlspecialchars($gym['state']) ?>
                            </p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <!-- Back Button -->
        <div class="text-center mt-8">
            <a href="schedule-history.php" class="inline-block bg-gray-700 hover:bg-gray-600 text-white font-bold py-3 px-8 rounded-full transition-colors duration-300">
                <i class="fas fa-arrow-left mr-2"></i>Back to Schedule
            </a>
        </div>
    </div>
</div>

<!-- Error/Success Messages -->
<?php if (isset($_SESSION['error']) || isset($_SESSION['success'])): ?>
<div id="notification" class="fixed bottom-4 right-4 max-w-md p-4 rounded-lg shadow-lg z-50 <?= isset($_SESSION['error']) ? 'bg-red-500' : 'bg-green-500' ?>">
    <div class="flex items-center">
        <div class="flex-shrink-0">
            <i class="fas <?= isset($_SESSION['error']) ? 'fa-exclamation-circle' : 'fa-check-circle' ?> text-white"></i>
        </div>
        <div class="ml-3">
            <p class="text-white font-medium">
                <?= isset($_SESSION['error']) ? htmlspecialchars($_SESSION['error']) : htmlspecialchars($_SESSION['success']) ?>
            </p>
        </div>
        <div class="ml-auto pl-3">
            <button onclick="document.getElementById('notification').style.display='none';" class="text-white hover:text-gray-200">
                <i class="fas fa-times"></i>
            </button>
        </div>
    </div>
</div>
<script>
    // Auto-hide notification after 5 seconds
    setTimeout(() => {
        const notification = document.getElementById('notification');
        if (notification) {
            notification.style.opacity = '0';
            notification.style.transition = 'opacity 0.5s';
            setTimeout(() => {
                notification.style.display = 'none';
            }, 500);
        }
    }, 5000);
</script>
<?php 
    // Clear session messages
    unset($_SESSION['error']);
    unset($_SESSION['success']);
endif; 
?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Lazy load images for better performance
    const lazyImages = document.querySelectorAll('img');
    const imageObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const img = entry.target;
                const src = img.getAttribute('data-src');
                if (src) {
                    img.src = src;
                    img.removeAttribute('data-src');
                }
                observer.unobserve(img);
            }
        });
    });
    
    lazyImages.forEach(img => {
        const src = img.src;
        img.setAttribute('data-src', src);
        img.src = 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"%3E%3C/svg%3E';
        imageObserver.observe(img);
    });
    
    // Form validation
    const forms = document.querySelectorAll('form[id^="gym-form-"]');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            const timeSelect = this.querySelector('select[name="start_time"]');
            if (timeSelect.value === '') {
                e.preventDefault();
                alert('Please select a time slot');
            }
        });
    });
});
</script>


        
