<?php
ob_start();
require_once 'config/database.php';
include 'includes/navbar.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$db = new GymDatabase();
$conn = $db->getConnection();
$user_id = $_SESSION['user_id'];

// Fetch user balance
$balanceStmt = $conn->prepare("SELECT balance FROM users WHERE id = ?");
$balanceStmt->execute([$user_id]);
$userBalance = $balanceStmt->fetchColumn();

// Get membership_id from URL if provided
$selected_membership_id = $_GET['membership_id'] ?? null;

// Fetch all active memberships with additional details for daily passes
$membershipsStmt = $conn->prepare("
    SELECT 
        um.id as membership_id,
        um.start_date,
        um.end_date,
        um.status,
        um.payment_status,
        gmp.tier,
        gmp.duration,
        gmp.price,
        gmp.inclusions,
        g.name as gym_name,
        g.gym_id,
        g.address,
        g.city,
        g.cover_photo,
        (SELECT COUNT(*) FROM schedules WHERE membership_id = um.id) as used_days,
        CASE 
            WHEN gmp.duration = 'Daily' THEN DATEDIFF(um.end_date, um.start_date) + 1
            ELSE NULL
        END as total_days_purchased
    FROM user_memberships um
    JOIN gym_membership_plans gmp ON um.plan_id = gmp.plan_id
    JOIN gyms g ON um.gym_id = g.gym_id
    WHERE um.user_id = ?
    AND um.status = 'active'
    AND um.payment_status = 'paid'
    AND CURRENT_DATE BETWEEN um.start_date AND um.end_date
    ORDER BY um.start_date DESC
");
$membershipsStmt->execute([$user_id]);
$memberships = $membershipsStmt->fetchAll(PDO::FETCH_ASSOC);

// Check if user has any active memberships
if (empty($memberships)) {
    $_SESSION['error'] = "You don't have any active memberships. Please purchase a membership first.";
    header('Location: all-gyms.php');
    exit;
}

// If membership_id is provided in URL, pre-select that membership
$selectedMembership = null;
if ($selected_membership_id) {
    foreach ($memberships as $membership) {
        if ($membership['membership_id'] == $selected_membership_id) {
            $selectedMembership = $membership;
            break;
        }
    }
}

// If no specific membership was selected or found, use the first one
if (!$selectedMembership) {
    $selectedMembership = $memberships[0];
}

$gym_id = $selectedMembership['gym_id'];

// Get gym operating hours
$hoursStmt = $conn->prepare("
    SELECT 
        day,
        morning_open_time, 
        morning_close_time, 
        evening_open_time, 
        evening_close_time
    FROM gym_operating_hours 
    WHERE gym_id = ? AND (day = 'Daily' OR day = ?)
    ORDER BY CASE WHEN day = 'Daily' THEN 0 ELSE 1 END
");

// Get the day of the week for the selected date (default to today)
$selectedDate = $_GET['date'] ?? date('Y-m-d');
$dayOfWeek = date('l', strtotime($selectedDate));

$hoursStmt->execute([$gym_id, $dayOfWeek]);
$hours = $hoursStmt->fetch(PDO::FETCH_ASSOC);

// If no specific day is found, try to get the 'Daily' schedule
if (!$hours) {
    $hoursStmt = $conn->prepare("
        SELECT 
            morning_open_time, 
            morning_close_time, 
            evening_open_time, 
            evening_close_time
        FROM gym_operating_hours 
        WHERE gym_id = ? AND day = 'Daily'
    ");
    $hoursStmt->execute([$gym_id]);
    $hours = $hoursStmt->fetch(PDO::FETCH_ASSOC);
}

// Generate time slots
$timeSlots = [];
$currentDateTime = new DateTime();
$selectedDateTime = new DateTime($selectedDate);
$isToday = $selectedDateTime->format('Y-m-d') === $currentDateTime->format('Y-m-d');

// Add one hour to current time for the minimum bookable slot
$minBookableTime = clone $currentDateTime;
$minBookableTime->modify('+1 hour');
$minBookableTimeStr = $minBookableTime->format('H:i:s');

if ($hours) {
    // Morning slots
    if ($hours['morning_open_time'] && $hours['morning_close_time']) {
        $morning_start = strtotime($hours['morning_open_time']);
        $morning_end = strtotime($hours['morning_close_time']);
        for ($time = $morning_start; $time <= $morning_end; $time += 3600) {
            $timeSlot = date('H:i:s', $time);

            // For today, only include time slots that are at least 1 hour in the future
            if (!$isToday || $timeSlot >= $minBookableTimeStr) {
                $timeSlots[] = $timeSlot;
            }
        }
    }

    // Evening slots
    if ($hours['evening_open_time'] && $hours['evening_close_time']) {
        $evening_start = strtotime($hours['evening_open_time']);
        $evening_end = strtotime($hours['evening_close_time']);
        for ($time = $evening_start; $time <= $evening_end; $time += 3600) {
            $timeSlot = date('H:i:s', $time);

            // For today, only include time slots that are at least 1 hour in the future
            if (!$isToday || $timeSlot >= $minBookableTimeStr) {
                $timeSlots[] = $timeSlot;
            }
        }
    }
}

// Get current occupancy for each time slot
$occupancyStmt = $conn->prepare("
    SELECT start_time, COUNT(*) as current_occupancy 
    FROM schedules 
    WHERE gym_id = ? 
    AND start_date = ?
    GROUP BY start_time
");
$occupancyStmt->execute([$gym_id, $selectedDate]);
$occupancyByTime = $occupancyStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Get gym capacity
$capacityStmt = $conn->prepare("SELECT capacity FROM gyms WHERE gym_id = ?");
$capacityStmt->execute([$gym_id]);
$gymCapacity = $capacityStmt->fetchColumn() ?: 50; // Default to 50 if not set

// Check if user already has a booking for the selected date at the selected gym
$existingBookingStmt = $conn->prepare("
    SELECT COUNT(*) 
    FROM schedules 
    WHERE user_id = ? 
    AND gym_id = ? 
    AND start_date = ?
");
$existingBookingStmt->execute([$user_id, $gym_id, $selectedDate]);
$hasExistingBooking = $existingBookingStmt->fetchColumn() > 0;

// For daily passes, check remaining days
$isDailyPass = strtolower($selectedMembership['duration']) === 'daily';
$totalDaysPurchased = $selectedMembership['total_days_purchased'] ?? 1;
$usedDays = $selectedMembership['used_days'] ?? 0;
$remainingDays = $totalDaysPurchased - $usedDays;

// Calculate daily rate for the selected membership
if ($isDailyPass) {
    $dailyRate = $selectedMembership['price'] / $totalDaysPurchased;
} else {
    // Calculate prorated daily rate based on duration
    switch (strtolower($selectedMembership['duration'])) {
        case 'weekly':
            $durationDays = 7;
            break;
        case 'monthly':
            $durationDays = 30;
            break;
        case 'quarterly':
            $durationDays = 90;
            break;
        case 'half yearly':
            $durationDays = 180;
            break;
        case 'yearly':
            $durationDays = 365;
            break;
        default:
            $durationDays = 30; // Default to monthly
    }
    $dailyRate = $selectedMembership['price'] / $durationDays;
}
$dailyRate = round($dailyRate, 2);

// Define activity types
$activityTypes = [
    'gym_visit' => 'Gym Visit',
    'class' => 'Fitness Class',
    'personal_training' => 'Personal Training'
];

// Get available classes for this gym
$classesStmt = $conn->prepare("
    SELECT id, name, description, instructor, capacity, current_bookings
    FROM gym_classes
    WHERE gym_id = ? AND status = 'active'
    ORDER BY name
");
$classesStmt->execute([$gym_id]);
$availableClasses = $classesStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schedule Workout - <?= htmlspecialchars($selectedMembership['gym_name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Base styles */
        body {
            background-color: #111827;
            color: #f3f4f6;
        }
        
        /* Responsive time slot grid */
        .time-slot-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 0.75rem;
        }
        
        @media (max-width: 640px) {
            .time-slot-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (min-width: 641px) and (max-width: 768px) {
            .time-slot-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }
        
        @media (min-width: 769px) and (max-width: 1024px) {
            .time-slot-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }
        
        @media (min-width: 1025px) {
            .time-slot-grid {
                grid-template-columns: repeat(6, 1fr);
            }
        }
        
        /* Time slot styling */
        .time-slot-item {
            min-height: 70px;
            transition: all 0.2s ease-in-out;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            border-radius: 0.5rem;
            padding: 0.75rem;
            cursor: pointer;
        }
        
        .time-slot-item:not(.disabled):hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }
        
        .time-slot-item.selected {
            transform: scale(1.05);
            box-shadow: 0 0 0 2px #F59E0B;
            background-color: #F59E0B !important;
            color: #000 !important;
        }
        
        .time-slot-item.disabled {
            opacity: 0.5;
            cursor: not-allowed;
            background-color: #4B5563 !important;
            color: #9CA3AF !important;
        }
        
        /* Membership card styling */
        .membership-card {
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .membership-card:hover {
            transform: translateY(-4px);
        }
        
        /* Improved form elements */
        input[type="date"], select, textarea {
            background-color: #374151;
            border-color: #4B5563;
            color: #F9FAFB;
            border-radius: 0.5rem;
            padding: 0.75rem;
            width: 100%;
        }
        
        input[type="date"]:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #F59E0B;
            box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.3);
        }
        
        /* Button styling */
        .btn-primary {
            background-color: #F59E0B;
            color: #000;
            font-weight: 600;
            padding: 0.75rem 1.5rem;
            border-radius: 0.5rem;
            transition: all 0.2s ease;
        }
        
        .btn-primary:hover:not(:disabled) {
            background-color: #D97706;
            transform: translateY(-2px);
        }
        
        .btn-primary:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        /* Toast notification */
        .toast {
            position: fixed;
            top: 1rem;
            right: 1rem;
            z-index: 50;
            max-width: 90vw;
            width: 350px;
            padding: 1rem;
            border-radius: 0.5rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            transform: translateX(100%);
            transition: transform 0.3s ease-in-out;
        }
        
        .toast.show {
            transform: translateX(0);
        }
        
        /* Skeleton loading */
        .skeleton {
            background: linear-gradient(90deg, #374151 25%, #4B5563 50%, #374151 75%);
            background-size: 200% 100%;
            animation: skeleton-loading 1.5s infinite;
        }
        
        @keyframes skeleton-loading {
            0% {
                background-position: 200% 0;
            }
            100% {
                background-position: -200% 0;
            }
        }
        
               /* Responsive adjustments */
               @media (max-width: 640px) {
            .time-slot-item {
                min-height: 60px;
                padding: 0.5rem;
            }
            
            .btn-primary {
                padding: 0.5rem 1rem;
                font-size: 0.875rem;
            }
        }
        
        /* Recurring options styling */
        .recurring-options {
            display: none;
        }
        
        .recurring-options.show {
            display: block;
            animation: fadeIn 0.3s ease-in-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Day of week checkboxes */
        .day-checkbox {
            display: none;
        }
        
        .day-label {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background-color: #374151;
            color: #D1D5DB;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            margin: 0 4px 8px 0;
        }
        
        .day-checkbox:checked + .day-label {
            background-color: #F59E0B;
            color: #000;
        }
        
        /* Activity type tabs */
        .activity-tab {
            cursor: pointer;
            transition: all 0.2s ease;
            border-bottom: 2px solid transparent;
        }
        
        .activity-tab.active {
            border-color: #F59E0B;
            color: #F59E0B;
        }
        
        .activity-content {
            display: none;
        }
        
        .activity-content.active {
            display: block;
            animation: fadeIn 0.3s ease-in-out;
        }
    </style>
</head>
<body class="min-h-screen bg-gradient-to-b from-gray-900 to-black">
    <div class="container mx-auto px-4 py-12 pt-24">
        <!-- Balance Display -->
        <div class="bg-gray-800 rounded-lg p-4 mb-6 shadow-lg flex justify-between items-center">
            <div>
                <h2 class="text-xl font-bold text-white">Your Balance</h2>
                <p class="text-yellow-400 text-2xl font-bold">₹<?= number_format($userBalance, 2) ?></p>
            </div>
            <a href="add_balance.php" class="bg-yellow-500 hover:bg-yellow-600 text-black font-semibold py-2 px-4 rounded-lg transition duration-300">
                <i class="fas fa-plus mr-2"></i> Add Balance
            </a>
        </div>

        <!-- Membership Selection -->
        <div class="mb-8">
            <h2 class="text-2xl font-bold text-white mb-4">Select Membership</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php foreach ($memberships as $membership): ?>
                    <div class="membership-card bg-gray-800 rounded-lg overflow-hidden shadow-lg border-2 <?= $membership['membership_id'] == $selectedMembership['membership_id'] ? 'border-yellow-500' : 'border-gray-700' ?>"
                         onclick="window.location.href='schedule.php?membership_id=<?= $membership['membership_id'] ?>&date=<?= $selectedDate ?>'">
                        <div class="relative h-32 bg-gray-700">
                            <?php if (!empty($membership['cover_photo'])): ?>
                                <img src="<?= htmlspecialchars($membership['cover_photo']) ?>" alt="<?= htmlspecialchars($membership['gym_name']) ?>" class="w-full h-full object-cover">
                            <?php else: ?>
                                <div class="w-full h-full flex items-center justify-center bg-gray-700">
                                    <i class="fas fa-dumbbell text-4xl text-gray-500"></i>
                                </div>
                            <?php endif; ?>
                            <div class="absolute top-0 right-0 bg-yellow-500 text-black px-2 py-1 text-xs font-bold">
                                <?= htmlspecialchars($membership['tier']) ?>
                            </div>
                        </div>
                        <div class="p-4">
                            <h3 class="text-lg font-bold text-white"><?= htmlspecialchars($membership['gym_name']) ?></h3>
                            <p class="text-gray-400 text-sm mb-2"><?= htmlspecialchars($membership['city']) ?></p>
                            <div class="flex justify-between items-center">
                                <span class="text-yellow-400 font-semibold"><?= htmlspecialchars($membership['duration']) ?></span>
                                <?php if ($membership['duration'] === 'Daily' && isset($membership['used_days'], $membership['total_days_purchased'])): ?>
                                    <span class="text-sm text-gray-300">
                                        <?= $membership['total_days_purchased'] - $membership['used_days'] ?> days left
                                    </span>
                                <?php else: ?>
                                    <span class="text-sm text-gray-300">
                                        Expires: <?= date('d M Y', strtotime($membership['end_date'])) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Selected Gym Details -->
        <div class="bg-gray-800 rounded-lg p-6 mb-8 shadow-lg">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between">
                <div>
                    <h2 class="text-2xl font-bold text-white mb-2"><?= htmlspecialchars($selectedMembership['gym_name']) ?></h2>
                    <p class="text-gray-400 mb-4">
                        <i class="fas fa-map-marker-alt mr-2 text-yellow-500"></i>
                        <?= htmlspecialchars($selectedMembership['address']) ?>, <?= htmlspecialchars($selectedMembership['city']) ?>
                    </p>
                </div>
                <div class="mt-4 md:mt-0">
                    <a href="gym-profile.php?id=<?= $selectedMembership['gym_id'] ?>" class="inline-flex items-center text-yellow-400 hover:text-yellow-300 transition duration-300">
                        <span>View Gym Details</span>
                        <i class="fas fa-arrow-right ml-2"></i>
                    </a>
                </div>
            </div>
        </div>

        <!-- Scheduling Form -->
        <div class="bg-gray-800 rounded-lg p-6 shadow-lg">
            <h2 class="text-2xl font-bold text-white mb-6">Schedule Your Workout</h2>
            
            <?php if ($isDailyPass && $remainingDays <= 0): ?>
                <div class="bg-red-900 text-red-200 p-4 rounded-lg mb-6">
                    <div class="flex items-start">
                        <div class="flex-shrink-0">
                            <i class="fas fa-exclamation-circle text-red-300 text-xl"></i>
                        </div>
                        <div class="ml-3">
                            <h3 class="text-lg font-medium text-red-200">No remaining days</h3>
                            <p class="mt-1">You have used all days in your daily pass. Please purchase a new membership to continue.</p>
                        </div>
                    </div>
                </div>
            <?php elseif ($hasExistingBooking): ?>
                <div class="bg-blue-900 text-blue-200 p-4 rounded-lg mb-6">
                    <div class="flex items-start">
                        <div class="flex-shrink-0">
                            <i class="fas fa-info-circle text-blue-300 text-xl"></i>
                        </div>
                        <div class="ml-3">
                            <h3 class="text-lg font-medium text-blue-200">Booking exists</h3>
                            <p class="mt-1">You already have a booking at this gym for the selected date. You can view your schedule in the dashboard.</p>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <!-- Activity Type Tabs -->
                <div class="mb-6 border-b border-gray-700">
                    <div class="flex flex-wrap -mb-px">
                        <?php foreach ($activityTypes as $value => $label): ?>
                            <div class="mr-4 activity-tab <?= $value === 'gym_visit' ? 'active' : '' ?>" data-target="<?= $value ?>">
                                <button type="button" class="inline-block py-3 px-2 text-gray-300 hover:text-yellow-400 font-medium">
                                    <i class="fas fa-<?= $value === 'gym_visit' ? 'dumbbell' : ($value === 'class' ? 'users' : 'user-graduate') ?> mr-2"></i>
                                    <?= $label ?>
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <form id="scheduleForm" method="POST" action="process_schedule.php" class="space-y-6">
                    <input type="hidden" name="membership_id" value="<?= $selectedMembership['membership_id'] ?>">
                    <input type="hidden" name="gym_id" value="<?= $selectedMembership['gym_id'] ?>">
                    <input type="hidden" name="daily_rate" value="<?= $dailyRate ?>">
                    <input type="hidden" name="cut_type" value="tier_based">
                    
                    <!-- Gym Visit Activity Content -->
                    <div id="gym_visit" class="activity-content active">
                        <!-- Date Selection -->
                        <div class="mb-6">
                            <label for="date" class="block text-gray-300 mb-2 font-medium">Select Date</label>
                            <div class="flex flex-col sm:flex-row gap-4">
                                <input type="date" id="date" name="start_date" 
                                       min="<?= date('Y-m-d') ?>" 
                                       max="<?= date('Y-m-d', strtotime('+30 days')) ?>" 
                                       value="<?= $selectedDate ?>" 
                                       class="flex-grow" required>
                                <button type="button" id="loadTimeSlotsBtn" class="bg-gray-700 hover:bg-gray-600 text-white font-medium py-2 px-4 rounded-lg transition duration-300">
                                    <i class="fas fa-sync-alt mr-2"></i> Load Time Slots
                                </button>
                            </div>
                        </div>
                        
                        <!-- Time Slot Selection -->
                        <div class="mb-6">
                            <label class="block text-gray-300 mb-2 font-medium">Select Time Slot</label>
                            
                            <?php if (empty($timeSlots)): ?>
                                <div class="bg-gray-700 p-4 rounded-lg text-center">
                                    <p class="text-gray-400">No time slots available for the selected date. The gym might be closed or fully booked.</p>
                                </div>
                            <?php else: ?>
                                <div class="time-slot-grid">
                                    <?php foreach ($timeSlots as $timeSlot): 
                                        $formattedTime = date('h:i A', strtotime($timeSlot));
                                        $currentOccupancy = $occupancyByTime[$timeSlot] ?? 0;
                                        $isAvailable = $currentOccupancy < $gymCapacity;
                                        $occupancyPercentage = ($currentOccupancy / $gymCapacity) * 100;
                                        
                                        // Determine background color based on occupancy
                                        if ($occupancyPercentage >= 80) {
                                            $bgColor = 'bg-red-800 hover:bg-red-700';
                                        } elseif ($occupancyPercentage >= 50) {
                                            $bgColor = 'bg-yellow-800 hover:bg-yellow-700';
                                        } else {
                                            $bgColor = 'bg-green-800 hover:bg-green-700';
                                        }
                                    ?>
                                        <div class="time-slot-item <?= $bgColor ?> <?= !$isAvailable ? 'disabled' : '' ?>" 
                                             data-time="<?= $timeSlot ?>"
                                             onclick="<?= $isAvailable ? 'selectTimeSlot(this)' : '' ?>">
                                            <span class="text-lg font-bold"><?= $formattedTime ?></span>
                                            <div class="mt-1 text-xs">
                                                <span><?= $currentOccupancy ?>/<?= $gymCapacity ?></span>
                                                <span class="ml-1"><?= $isAvailable ? 'Available' : 'Full' ?></span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <input type="hidden" id="selected_time" name="start_time" required>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Class Activity Content -->
                    <div id="class" class="activity-content">
                        <div class="mb-6">
                            <label for="class_id" class="block text-gray-300 mb-2 font-medium">Select Class</label>
                            <?php if (empty($availableClasses)): ?>
                                <div class="bg-gray-700 p-4 rounded-lg text-center">
                                    <p class="text-gray-400">No classes available at this gym. Please select a different activity type.</p>
                                </div>
                            <?php else: ?>
                                <select id="class_id" name="class_id" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-yellow-500">
                                    <option value="">Select a class...</option>
                                    <?php foreach ($availableClasses as $class): ?>
                                        <option value="<?= $class['id'] ?>">
                                            <?= htmlspecialchars($class['name']) ?> - 
                                            Instructor: <?= htmlspecialchars($class['instructor']) ?> 
                                            (<?= $class['current_bookings'] ?>/<?= $class['capacity'] ?> spots)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div id="class_details" class="mt-3 p-4 bg-gray-700 rounded-lg hidden">
                                    <h4 id="class_name" class="font-bold text-white"></h4>
                                    <p id="class_description" class="text-gray-300 text-sm mt-1"></p>
                                    <div class="flex justify-between mt-2">
                                        <span id="class_instructor" class="text-yellow-400 text-sm"></span>
                                        <span id="class_capacity" class="text-gray-300 text-sm"></span>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="mb-6">
                            <label for="class_date" class="block text-gray-300 mb-2 font-medium">Select Date</label>
                            <input type="date" id="class_date" name="class_date" 
                                   min="<?= date('Y-m-d') ?>" 
                                   max="<?= date('Y-m-d', strtotime('+30 days')) ?>" 
                                   value="<?= $selectedDate ?>" 
                                   class="w-full" required>
                        </div>
                    </div>
                    
                    <!-- Personal Training Activity Content -->
                    <div id="personal_training" class="activity-content">
                        <div class="bg-gray-700 p-4 rounded-lg mb-6">
                            <p class="text-gray-300">
                                <i class="fas fa-info-circle mr-2 text-yellow-500"></i>
                                Personal training sessions require a trainer to be available. Select a date and time, and we'll match you with an available trainer.
                            </p>
                        </div>
                        
                        <div class="mb-6">
                            <label for="training_date" class="block text-gray-300 mb-2 font-medium">Select Date</label>
                            <input type="date" id="training_date" name="training_date" 
                                   min="<?= date('Y-m-d') ?>" 
                                   max="<?= date('Y-m-d', strtotime('+30 days')) ?>" 
                                   value="<?= $selectedDate ?>" 
                                   class="w-full" required>
                        </div>
                        
                        <div class="mb-6">
                            <label for="training_time" class="block text-gray-300 mb-2 font-medium">Select Time</label>
                            <select id="training_time" name="training_time" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-yellow-500" required>
                                <option value="">Select a time...</option>
                                <?php foreach ($timeSlots as $timeSlot): 
                                    $formattedTime = date('h:i A', strtotime($timeSlot));
                                ?>
                                    <option value="<?= $timeSlot ?>"><?= $formattedTime ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-6">
                            <label for="training_focus" class="block text-gray-300 mb-2 font-medium">Training Focus</label>
                            <select id="training_focus" name="training_focus" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-yellow-500">
                                <option value="general">General Fitness</option>
                                <option value="strength">Strength Training</option>
                                <option value="cardio">Cardio & Endurance</option>
                                <option value="flexibility">Flexibility & Mobility</option>
                                <option value="weight_loss">Weight Loss</option>
                                <option value="muscle_gain">Muscle Gain</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Common Fields for All Activity Types -->
                    <div class="mb-6">
                        <label for="activity_type" class="block text-gray-300 mb-2 font-medium">Activity Type</label>
                        <select id="activity_type" name="activity_type" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-yellow-500" required>
                            <?php foreach ($activityTypes as $value => $label): ?>
                                <option value="<?= $value ?>" <?= $value === 'gym_visit' ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Recurring Options -->
                    <div class="mb-6">
                        <div class="flex items-center">
                            <input type="checkbox" id="is_recurring" name="is_recurring" class="h-4 w-4 text-yellow-500 focus:ring-yellow-500 border-gray-600 rounded">
                            <label for="is_recurring" class="ml-2 block text-gray-300 font-medium">Make this a recurring booking</label>
                        </div>
                        
                        <div id="recurring_options" class="mt-4 p-4 bg-gray-700 rounded-lg recurring-options">
                            <div class="mb-4">
                                <label for="recurring_type" class="block text-gray-300 mb-2 font-medium">Recurrence Pattern</label>
                                <select id="recurring_type" name="recurring" class="w-full bg-gray-600 border border-gray-500 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-yellow-500">
                                    <option value="none">None</option>
                                    <option value="daily">Daily</option>
                                    <option value="weekly">Weekly</option>
                                    <option value="monthly">Monthly</option>
                                </select>
                            </div>
                            
                            <div id="days_of_week_container" class="mb-4 hidden">
                                <label class="block text-gray-300 mb-2 font-medium">Days of Week</label>
                                <div class="flex flex-wrap">
                                    <?php 
                                    $daysOfWeek = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                                    foreach ($daysOfWeek as $index => $day): 
                                    ?>
                                        <div>
                                            <input type="checkbox" id="day_<?= $index ?>" name="days_of_week[]" value="<?= $index + 1 ?>" class="day-checkbox">
                                            <label for="day_<?= $index ?>" class="day-label"><?= $day ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <label for="recurring_until" class="block text-gray-300 mb-2 font-medium">Repeat Until</label>
                                <input type="date" id="recurring_until" name="recurring_until" 
                                       min="<?= date('Y-m-d', strtotime('+1 day')) ?>" 
                                       max="<?= date('Y-m-d', strtotime('+90 days')) ?>" 
                                       value="<?= date('Y-m-d', strtotime('+30 days')) ?>" 
                                       class="w-full bg-gray-600 border border-gray-500 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-yellow-500">
                            </div>
                            
                            <div class="text-sm text-gray-400">
                                <p><i class="fas fa-info-circle mr-1"></i> Recurring bookings will be created based on your selected pattern until the end date.</p>
                                <p class="mt-1"><i class="fas fa-exclamation-triangle mr-1 text-yellow-500"></i> Each recurring booking counts as a separate visit for daily passes.</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Notes -->
                    <div class="mb-6">
                        <label for="notes" class="block text-gray-300 mb-2 font-medium">Notes (Optional)</label>
                        <textarea id="notes" name="notes" rows="3" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-yellow-500" placeholder="Any special requests or notes for your visit..."></textarea>
                    </div>
                    
                    <!-- End Date (Same as Start Date for single visits) -->
                    <input type="hidden" id="end_date" name="end_date" value="<?= $selectedDate ?>">
                    
                    <!-- Submit Button -->
                    <div class="flex justify-end">
                        <button type="submit" id="scheduleBtn" class="btn-primary" disabled>
                            <i class="fas fa-calendar-check mr-2"></i> Schedule Workout
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
        
        <!-- Information Card -->
        <div class="mt-8 bg-gray-800 rounded-lg p-6 shadow-lg">
            <h3 class="text-xl font-bold text-white mb-4">Scheduling Information</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <h4 class="text-lg font-semibold text-yellow-400 mb-2">Booking Rules</h4>
                    <ul class="list-disc list-inside text-gray-300 space-y-2">
                        <li>You can only book one time slot per day at each gym</li>
                        <li>Bookings must be made at least 1 hour in advance</li>
                        <li>For daily passes, each booking counts as one day of usage</li>
                        <li>Cancellations must be made at least 4 hours before the scheduled time</li>
                        <li>Recurring bookings will create multiple schedule entries</li>
                    </ul>
                </div>
                <div>
                    <h4 class="text-lg font-semibold text-yellow-400 mb-2">Activity Types</h4>
                    <ul class="space-y-2">
                        <li class="flex items-start">
                            <i class="fas fa-dumbbell text-yellow-500 mt-1 mr-2"></i>
                            <span class="text-gray-300"><strong>Gym Visit:</strong> Regular access to gym facilities and equipment</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-users text-yellow-500 mt-1 mr-2"></i>
                            <span class="text-gray-300"><strong>Fitness Class:</strong> Group classes led by instructors</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-user-graduate text-yellow-500 mt-1 mr-2"></i>
                            <span class="text-gray-300"><strong>Personal Training:</strong> One-on-one sessions with a trainer</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    

    <script>
        // Variables
        let selectedTimeSlotElement = null;
        let classData = <?= json_encode($availableClasses) ?>;
        
        // Functions
        function selectTimeSlot(element) {
            // Remove selected class from previously selected time slot
            if (selectedTimeSlotElement) {
                selectedTimeSlotElement.classList.remove('selected');
            }
            
            // Add selected class to the clicked time slot
            element.classList.add('selected');
            selectedTimeSlotElement = element;
            
            // Update hidden input with selected time
            document.getElementById('selected_time').value = element.dataset.time;
            
            // Enable the schedule button
            document.getElementById('scheduleBtn').disabled = false;
        }
        
        function showToast(message, isError = true) {
            const toast = document.getElementById('toast');
            const toastMessage = document.getElementById('toastMessage');
            
            // Set message
            toastMessage.textContent = message;
            
            // Set toast color based on type
            if (isError) {
                toast.className = 'toast bg-red-900 text-white show';
            } else {
                toast.className = 'toast bg-green-900 text-white show';
            }
            
            // Show toast
            setTimeout(() => {
                toast.classList.add('show');
            }, 100);
            
            // Auto hide after 5 seconds
            setTimeout(hideToast, 5000);
        }
        
        function hideToast() {
            const toast = document.getElementById('toast');
            toast.classList.remove('show');
        }
        
        function toggleRecurringOptions() {
            const isRecurring = document.getElementById('is_recurring').checked;
            const recurringOptions = document.getElementById('recurring_options');
            
            if (isRecurring) {
                recurringOptions.classList.add('show');
            } else {
                recurringOptions.classList.remove('show');
            }
        }
        
        function updateDaysOfWeekVisibility() {
            const recurringType = document.getElementById('recurring_type').value;
            const daysOfWeekContainer = document.getElementById('days_of_week_container');
            
            if (recurringType === 'weekly') {
                daysOfWeekContainer.classList.remove('hidden');
            } else {
                daysOfWeekContainer.classList.add('hidden');
            }
        }
        
        function switchActivityTab(tabElement) {
            // Remove active class from all tabs
            document.querySelectorAll('.activity-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Add active class to clicked tab
            tabElement.classList.add('active');
                        // Hide all content sections
                        document.querySelectorAll('.activity-content').forEach(content => {
                content.classList.remove('active');
            });
            
            // Show the content section corresponding to the clicked tab
            const targetId = tabElement.dataset.target;
            document.getElementById(targetId).classList.add('active');
            
            // Update the activity type select field
            document.getElementById('activity_type').value = targetId;
            
            // Reset the schedule button state
            const scheduleBtn = document.getElementById('scheduleBtn');
            scheduleBtn.disabled = true;
            
            // For gym visit, check if a time slot is selected
            if (targetId === 'gym_visit' && selectedTimeSlotElement) {
                scheduleBtn.disabled = false;
            }
            
            // For class, check if a class is selected
            if (targetId === 'class' && document.getElementById('class_id').value) {
                scheduleBtn.disabled = false;
            }
            
            // For personal training, check if a time is selected
            if (targetId === 'personal_training' && document.getElementById('training_time').value) {
                scheduleBtn.disabled = false;
            }
        }
        
        function updateClassDetails() {
            const classId = document.getElementById('class_id').value;
            const detailsContainer = document.getElementById('class_details');
            
            if (!classId) {
                detailsContainer.classList.add('hidden');
                return;
            }
            
            // Find the selected class in the data
            const selectedClass = classData.find(c => c.id == classId);
            if (!selectedClass) {
                detailsContainer.classList.add('hidden');
                return;
            }
            
            // Update the details
            document.getElementById('class_name').textContent = selectedClass.name;
            document.getElementById('class_description').textContent = selectedClass.description || 'No description available';
            document.getElementById('class_instructor').textContent = 'Instructor: ' + selectedClass.instructor;
            document.getElementById('class_capacity').textContent = `${selectedClass.current_bookings}/${selectedClass.capacity} spots filled`;
            
            // Show the details container
            detailsContainer.classList.remove('hidden');
            
            // Enable the schedule button
            document.getElementById('scheduleBtn').disabled = false;
        }
        
        // Event Listeners
        document.addEventListener('DOMContentLoaded', function() {
            // Date change handler
            const dateInput = document.getElementById('date');
            const loadTimeSlotsBtn = document.getElementById('loadTimeSlotsBtn');
            
            loadTimeSlotsBtn.addEventListener('click', function() {
                const selectedDate = dateInput.value;
                if (!selectedDate) {
                    showToast('Please select a date first');
                    return;
                }
                
                // Redirect to same page with updated date parameter
                window.location.href = 'schedule.php?membership_id=<?= $selectedMembership['membership_id'] ?>&date=' + selectedDate;
            });
            
            // Form submission handler
            const scheduleForm = document.getElementById('scheduleForm');
            if (scheduleForm) {
                scheduleForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    const activityType = document.getElementById('activity_type').value;
                    let isValid = true;
                    let errorMessage = '';
                    
                    // Validate based on activity type
                    if (activityType === 'gym_visit') {
                        const selectedTime = document.getElementById('selected_time').value;
                        if (!selectedTime) {
                            isValid = false;
                            errorMessage = 'Please select a time slot';
                        }
                    } else if (activityType === 'class') {
                        const classId = document.getElementById('class_id').value;
                        if (!classId) {
                            isValid = false;
                            errorMessage = 'Please select a class';
                        }
                    } else if (activityType === 'personal_training') {
                        const trainingTime = document.getElementById('training_time').value;
                        if (!trainingTime) {
                            isValid = false;
                            errorMessage = 'Please select a training time';
                        }
                    }
                    
                    // Validate recurring options if enabled
                    if (document.getElementById('is_recurring').checked) {
                        const recurringType = document.getElementById('recurring_type').value;
                        const recurringUntil = document.getElementById('recurring_until').value;
                        
                        if (recurringType === 'none') {
                            isValid = false;
                            errorMessage = 'Please select a recurrence pattern';
                        } else if (!recurringUntil) {
                            isValid = false;
                            errorMessage = 'Please select an end date for recurring bookings';
                        } else if (recurringType === 'weekly') {
                            const selectedDays = document.querySelectorAll('input[name="days_of_week[]"]:checked');
                            if (selectedDays.length === 0) {
                                isValid = false;
                                errorMessage = 'Please select at least one day of the week';
                            }
                        }
                    }
                    
                    if (!isValid) {
                        showToast(errorMessage);
                        return;
                    }
                    
                    // Set end_date same as start_date for single visits
                    if (activityType === 'gym_visit') {
                        document.getElementById('end_date').value = document.getElementById('date').value;
                    } else if (activityType === 'class') {
                        document.getElementById('end_date').value = document.getElementById('class_date').value;
                    } else if (activityType === 'personal_training') {
                        document.getElementById('end_date').value = document.getElementById('training_date').value;
                    }
                    
                    // Disable the submit button to prevent double submission
                    document.getElementById('scheduleBtn').disabled = true;
                    document.getElementById('scheduleBtn').innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Processing...';
                    
                    // Submit the form
                    this.submit();
                });
            }
            
            // Activity tab click handlers
            document.querySelectorAll('.activity-tab').forEach(tab => {
                tab.addEventListener('click', function() {
                    switchActivityTab(this);
                });
            });
            
            // Recurring checkbox handler
            const isRecurringCheckbox = document.getElementById('is_recurring');
            if (isRecurringCheckbox) {
                isRecurringCheckbox.addEventListener('change', toggleRecurringOptions);
            }
            
            // Recurring type change handler
            const recurringTypeSelect = document.getElementById('recurring_type');
            if (recurringTypeSelect) {
                recurringTypeSelect.addEventListener('change', updateDaysOfWeekVisibility);
            }
            
            // Class selection handler
            const classSelect = document.getElementById('class_id');
            if (classSelect) {
                classSelect.addEventListener('change', updateClassDetails);
            }
            
            // Personal training time selection handler
            const trainingTimeSelect = document.getElementById('training_time');
            if (trainingTimeSelect) {
                trainingTimeSelect.addEventListener('change', function() {
                    document.getElementById('scheduleBtn').disabled = !this.value;
                });
            }
            
            // Handle date input min/max validation
            dateInput.addEventListener('change', function() {
                const selectedDate = new Date(this.value);
                const today = new Date();
                today.setHours(0, 0, 0, 0);
                
                const maxDate = new Date();
                maxDate.setDate(maxDate.getDate() + 30);
                maxDate.setHours(0, 0, 0, 0);
                
                if (selectedDate < today) {
                    showToast('Cannot select a past date');
                    this.value = today.toISOString().split('T')[0];
                } else if (selectedDate > maxDate) {
                    showToast('Cannot book more than 30 days in advance');
                    this.value = maxDate.toISOString().split('T')[0];
                }
            });
            
            // Apply same validation to other date inputs
            ['class_date', 'training_date'].forEach(dateInputId => {
                const input = document.getElementById(dateInputId);
                if (input) {
                    input.addEventListener('change', function() {
                        const selectedDate = new Date(this.value);
                        const today = new Date();
                        today.setHours(0, 0, 0, 0);
                        
                        const maxDate = new Date();
                        maxDate.setDate(maxDate.getDate() + 30);
                        maxDate.setHours(0, 0, 0, 0);
                        
                        if (selectedDate < today) {
                            showToast('Cannot select a past date');
                            this.value = today.toISOString().split('T')[0];
                        } else if (selectedDate > maxDate) {
                            showToast('Cannot book more than 30 days in advance');
                            this.value = maxDate.toISOString().split('T')[0];
                        }
                    });
                }
            });
            
            // Check if there are any available time slots
            const timeSlots = document.querySelectorAll('.time-slot-item:not(.disabled)');
            if (timeSlots.length === 0) {
                const scheduleBtn = document.getElementById('scheduleBtn');
                if (scheduleBtn) {
                    scheduleBtn.disabled = true;
                    scheduleBtn.innerHTML = '<i class="fas fa-calendar-times mr-2"></i> No Available Slots';
                }
            }
        });
        
        // Responsive time slot grid adjustment
        function adjustTimeSlotGrid() {
            const grid = document.querySelector('.time-slot-grid');
            if (!grid) return;
            
            const width = window.innerWidth;
            
            if (width < 640) {
                grid.style.gridTemplateColumns = 'repeat(2, 1fr)';
            } else if (width < 768) {
                grid.style.gridTemplateColumns = 'repeat(3, 1fr)';
            } else if (width < 1024) {
                grid.style.gridTemplateColumns = 'repeat(4, 1fr)';
            } else {
                grid.style.gridTemplateColumns = 'repeat(6, 1fr)';
            }
        }
        
        // Call on load and resize
        window.addEventListener('load', adjustTimeSlotGrid);
        window.addEventListener('resize', adjustTimeSlotGrid);

        
    </script>
</body>
</html>



