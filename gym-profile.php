<?php
ob_start();
// Add page caching headers
header("Cache-Control: private, max-age=300"); // Cache for 5 minutes

include 'includes/navbar.php';
require_once 'config/database.php';

// Initialize database connection
$db = new GymDatabase();
$conn = $db->getConnection();

// Get gym ID from URL
$gymId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($gymId <= 0) {
    // Redirect to gyms page if invalid ID
    header('Location: all-gyms.php');
    exit;
}

// Fetch gym details with real-time data
$sql = "
    SELECT g.*, 
           (SELECT AVG(rating) FROM reviews r WHERE r.gym_id = g.gym_id AND r.status = 'approved') as avg_rating,
           (SELECT COUNT(*) FROM reviews r WHERE r.gym_id = g.gym_id AND r.status = 'approved') as review_count,
           (SELECT COUNT(*) FROM schedules s WHERE s.gym_id = g.gym_id AND s.status = 'scheduled' 
            AND DATE(s.start_date) = CURDATE()) as today_visits,
           gp.cancellation_hours, gp.reschedule_hours, gp.cancellation_fee, gp.reschedule_fee, gp.late_fee
    FROM gyms g 
    LEFT JOIN gym_policies gp ON g.gym_id = gp.gym_id
    WHERE g.gym_id = ? AND g.status = 'active'
";

$stmt = $conn->prepare($sql);
$stmt->execute([$gymId]);
$gym = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$gym) {
    // Redirect if gym not found or not active
    header('Location: all-gyms.php');
    exit;
}

// Fetch membership plans
$plansSql = "
    SELECT * FROM gym_membership_plans
    WHERE gym_id = ?
    ORDER BY price ASC
";

$plansStmt = $conn->prepare($plansSql);
$plansStmt->execute([$gymId]);
$plans = $plansStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch reviews with user information
$reviewsSql = "
    SELECT r.*, u.username as user_name, u.profile_image
    FROM reviews r
    LEFT JOIN users u ON r.user_id = u.id
    WHERE r.gym_id = ? AND r.status = 'approved'
    ORDER BY r.created_at DESC
    LIMIT 10
";

$reviewsStmt = $conn->prepare($reviewsSql);
$reviewsStmt->execute([$gymId]);
$reviews = $reviewsStmt->fetchAll(PDO::FETCH_ASSOC);

// Check if user has already reviewed this gym
$userHasReviewed = false;
if (isset($_SESSION['user_id'])) {
    $checkReviewSql = "
        SELECT COUNT(*) FROM reviews 
        WHERE gym_id = ? AND user_id = ?
    ";
    $checkReviewStmt = $conn->prepare($checkReviewSql);
    $checkReviewStmt->execute([$gymId, $_SESSION['user_id']]);
    $userHasReviewed = ($checkReviewStmt->fetchColumn() > 0);
}

// Check if user has scheduled visits to this gym
$userSchedules = [];
if (isset($_SESSION['user_id'])) {
    $schedulesSql = "
        SELECT * FROM schedules
        WHERE gym_id = ? AND user_id = ? AND status = 'scheduled'
        ORDER BY start_date ASC
    ";
    $schedulesStmt = $conn->prepare($schedulesSql);
    $schedulesStmt->execute([$gymId, $_SESSION['user_id']]);
    $userSchedules = $schedulesStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch operating hours
$hoursSql = "
    SELECT * 
    FROM gym_operating_hours 
    WHERE gym_id = ?
    ORDER BY FIELD(day, 'Daily', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday')
";
$hoursStmt = $conn->prepare($hoursSql);
$hoursStmt->execute([$gymId]);
$operatingHours = $hoursStmt->fetchAll(PDO::FETCH_ASSOC);

// Add default days if any are missing (to ensure all days are represented)
$allDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
$existingDays = array_column($operatingHours, 'day');

// If there's a "Daily" schedule, it applies to all days unless overridden
$dailySchedule = null;
foreach ($operatingHours as $hours) {
    if ($hours['day'] === 'Daily') {
        $dailySchedule = $hours;
        break;
    }
}

// Add missing days with closed status or daily schedule
foreach ($allDays as $day) {
    if (!in_array($day, $existingDays) && $day !== 'Daily') {
        if ($dailySchedule) {
            // Use daily schedule for this day
            $dailySchedule['day'] = $day;
            $operatingHours[] = $dailySchedule;
        } else {
            // Add as closed - using empty or '00:00:00' time values to indicate closed
            $operatingHours[] = [
                'day' => $day,
                'morning_open_time' => '00:00:00',
                'morning_close_time' => '00:00:00',
                'evening_open_time' => '00:00:00',
                'evening_close_time' => '00:00:00'
            ];
        }
    }
}

// Sort the hours again after adding missing days
usort($operatingHours, function ($a, $b) use ($allDays) {
    // Daily always comes first
    if ($a['day'] === 'Daily')
        return -1;
    if ($b['day'] === 'Daily')
        return 1;

    // Otherwise sort by day of week
    return array_search($a['day'], $allDays) - array_search($b['day'], $allDays);
});

// Process each day to determine if it's closed
foreach ($operatingHours as &$hours) {
    // Check if times are empty/null or all zeros which would indicate closed
    $morningClosed = empty($hours['morning_open_time']) ||
        empty($hours['morning_close_time']) ||
        $hours['morning_open_time'] === '00:00:00' ||
        $hours['morning_close_time'] === '00:00:00';

    $eveningClosed = empty($hours['evening_open_time']) ||
        empty($hours['evening_close_time']) ||
        $hours['evening_open_time'] === '00:00:00' ||
        $hours['evening_close_time'] === '00:00:00';

    $hours['is_closed'] = $morningClosed && $eveningClosed;
}
unset($hours); // Break the reference

// Fetch equipment
$equipmentSql = "
    SELECT * 
    FROM gym_equipment 
    WHERE gym_id = ? AND status = 'active'
    ORDER BY category, name
";
$equipmentStmt = $conn->prepare($equipmentSql);
$equipmentStmt->execute([$gymId]);
$equipment = $equipmentStmt->fetchAll(PDO::FETCH_ASSOC);

// Group equipment by category
$groupedEquipment = [];
foreach ($equipment as $item) {
    $category = $item['category'] ?: 'Other';
    if (!isset($groupedEquipment[$category])) {
        $groupedEquipment[$category] = [];
    }
    $groupedEquipment[$category][] = $item;
}

// Fetch gallery images
$gallerySql = "
    SELECT * 
    FROM gym_gallery 
    WHERE gym_id = ? AND status = 'active'
    ORDER BY display_order ASC
";
$galleryStmt = $conn->prepare($gallerySql);
$galleryStmt->execute([$gymId]);
$galleryImages = $galleryStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch amenities data
$amenitiesData = [];
if (!empty($gym['amenities'])) {
    $amenitiesArray = json_decode($gym['amenities'], true);
    if (is_array($amenitiesArray) && !empty($amenitiesArray)) {
        $placeholders = implode(',', array_fill(0, count($amenitiesArray), '?'));
        $amenitiesSql = "SELECT id, name, category FROM amenities WHERE id IN ($placeholders) AND availability = 1";
        $amenitiesStmt = $conn->prepare($amenitiesSql);
        $amenitiesStmt->execute($amenitiesArray);
        $amenitiesData = $amenitiesStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Fetch page settings to determine section visibility and order
$settingsSql = "
    SELECT * 
    FROM gym_page_settings 
    WHERE gym_id = ?
    ORDER BY display_order ASC
";
$settingsStmt = $conn->prepare($settingsSql);
$settingsStmt->execute([$gymId]);
$pageSettings = $settingsStmt->fetchAll(PDO::FETCH_ASSOC);

// Create a map of section settings
$sectionSettings = [];
$defaultSections = [
    'about' => ['title' => 'About This Gym', 'order' => 1, 'visible' => true],
    'amenities' => ['title' => 'Amenities', 'order' => 2, 'visible' => true],
    'operating_hours' => ['title' => 'Operating Hours', 'order' => 3, 'visible' => true],
    'membership_plans' => ['title' => 'Membership Plans', 'order' => 4, 'visible' => true],
    'equipment' => ['title' => 'Equipment', 'order' => 5, 'visible' => true],
    'gallery' => ['title' => 'Gallery', 'order' => 6, 'visible' => true],
    'reviews' => ['title' => 'Reviews', 'order' => 7, 'visible' => true],
    'similar_gyms' => ['title' => 'Similar Gyms Nearby', 'order' => 8, 'visible' => true],
    'policies' => ['title' => 'Gym Policies', 'order' => 9, 'visible' => true]
];

// Initialize with defaults
foreach ($defaultSections as $section => $defaults) {
    $sectionSettings[$section] = [
        'title' => $defaults['title'],
        'order' => $defaults['order'],
        'visible' => $defaults['visible']
    ];
}

// Override with database settings if available
foreach ($pageSettings as $setting) {
    $sectionName = $setting['section_name'];
    if (isset($sectionSettings[$sectionName])) {
        $sectionSettings[$sectionName]['title'] = $setting['custom_title'] ?: $sectionSettings[$sectionName]['title'];
        $sectionSettings[$sectionName]['order'] = $setting['display_order'];
        $sectionSettings[$sectionName]['visible'] = (bool) $setting['is_visible'];
    }
}

// Sort sections by display order
uasort($sectionSettings, function ($a, $b) {
    return $a['order'] - $b['order'];
});

// Process review submission
$reviewMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review']) && isset($_SESSION['user_id'])) {
    $rating = isset($_POST['rating']) ? (int) $_POST['rating'] : 0;
    $comment = isset($_POST['comment']) ? trim($_POST['comment']) : '';

    if ($rating < 1 || $rating > 5) {
        $reviewMessage = '<div class="bg-red-500 text-white p-3 rounded-lg mb-4">Please select a rating between 1 and 5.</div>';
    } elseif (empty($comment)) {
        $reviewMessage = '<div class="bg-red-500 text-white p-3 rounded-lg mb-4">Please provide a comment for your review.</div>';
    } else {
        // Check if user has already reviewed
        if ($userHasReviewed) {
            // Update existing review
            $updateReviewSql = "
                UPDATE reviews 
                SET rating = ?, comment = ?, created_at = NOW()
                WHERE gym_id = ? AND user_id = ?
            ";
            $updateReviewStmt = $conn->prepare($updateReviewSql);
            $updateReviewStmt->execute([$rating, $comment, $gymId, $_SESSION['user_id']]);
            $reviewMessage = '<div class="bg-green-500 text-white p-3 rounded-lg mb-4">Your review has been updated and will be visible after approval.</div>';
        } else {
            // Insert new review
            $insertReviewSql = "
                INSERT INTO reviews (user_id, gym_id, rating, comment, visit_date, status)
                VALUES (?, ?, ?, ?, CURDATE(), 'pending')
            ";
            $insertReviewStmt = $conn->prepare($insertReviewSql);
            $insertReviewStmt->execute([$_SESSION['user_id'], $gymId, $rating, $comment]);
            $reviewMessage = '<div class="bg-green-500 text-white p-3 rounded-lg mb-4">Your review has been submitted and will be visible after approval.</div>';
            $userHasReviewed = true;
        }

        // Log the activity
        $activitySql = "
            INSERT INTO activity_logs (
                user_id, user_type, action, details, ip_address, user_agent
            ) VALUES (
                ?, 'member', ?, ?, ?, ?
            )
        ";
        $action = $userHasReviewed ? 'update_review' : 'add_review';
        $details = "User ID: {$_SESSION['user_id']} " . ($userHasReviewed ? "updated" : "added") . " review for Gym ID: {$gymId}";
        $activityStmt = $conn->prepare($activitySql);
        $activityStmt->execute([
            $_SESSION['user_id'],
            $action,
            $details,
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT']
        ]);
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($gym['name']); ?> - Gym Profile</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* Custom scrollbar for webkit browsers */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: rgba(31, 41, 55, 0.8);
        }

        ::-webkit-scrollbar-thumb {
            background: rgba(245, 158, 11, 0.8);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: rgba(245, 158, 11, 1);
        }

        /* Smooth scrolling for the entire page */
        html {
            scroll-behavior: smooth;
        }

        /* Responsive image gallery */
        .gallery-container {
            scroll-snap-type: x mandatory;
            scroll-padding: 1rem;
        }

        .gallery-item {
            scroll-snap-align: start;
        }

        /* Animated elements */
        .animate-fade-in {
            animation: fadeIn 0.5s ease-in-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Responsive typography */
        @media (max-width: 640px) {
            .text-responsive {
                font-size: 0.875rem;
            }
        }

        /* Add these styles to your existing styles */
        .toast-notification {
            max-width: 90vw;
            width: 350px;
        }

        .time-slot-item.selected {
            transform: scale(1.05);
            box-shadow: 0 0 0 2px #F59E0B;
        }

        .time-slot-item:not([data-full="1"]):hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }

        .time-slot-item:focus {
            outline: none;
            box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.5);
        }

        #selected-time-display {
            animation: fadeIn 0.3s ease-in-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .animate-pulse {
            animation: pulse 1.5s cubic-bezier(0.4, 0, 0.6, 1) 1;
        }

        @keyframes pulse {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: 0.7;
            }
        }

        /* Improved scrolling experience */
        #time-slot-container {
            scrollbar-width: thin;
            scrollbar-color: rgba(245, 158, 11, 0.5) rgba(31, 41, 55, 0.5);
        }

        #time-slot-container::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }

        #time-slot-container::-webkit-scrollbar-track {
            background: rgba(31, 41, 55, 0.5);
            border-radius: 4px;
        }

        #time-slot-container::-webkit-scrollbar-thumb {
            background-color: rgba(245, 158, 11, 0.5);
            border-radius: 4px;
        }

        #time-slot-container::-webkit-scrollbar-thumb:hover {
            background-color: rgba(245, 158, 11, 0.8);
        }
    </style>
</head>

<body class="bg-gradient-to-b from-gray-900 to-black min-h-screen text-responsive">
    <div class="pt-16 sm:pt-20 md:pt-24 pb-12">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">
            <!-- Gym Header Section -->
            <div class="relative rounded-3xl overflow-hidden mb-8 animate-fade-in">
                <div class="h-48 sm:h-64 md:h-80 bg-gray-800">
                    <img src="./uploads/gym_images/<?php echo htmlspecialchars($gym['cover_photo'] ?? 'default_gym.jpg'); ?>"
                        alt="<?php echo htmlspecialchars($gym['name']); ?>" class="w-full h-full object-cover">
                </div>

                <div class="absolute inset-0 bg-gradient-to-t from-black to-transparent"></div>

                <div class="absolute bottom-0 left-0 right-0 p-4 sm:p-6 md:p-8">
                    <div class="flex flex-col md:flex-row md:items-end justify-between">
                        <div>
                            <h1 class="text-2xl sm:text-3xl md:text-4xl font-bold text-white mb-2">
                                <?php echo htmlspecialchars($gym['name']); ?>
                            </h1>
                            <div class="flex items-center mb-2">
                                <div class="flex items-center mr-4">
                                    <?php
                                    $avgRating = round($gym['avg_rating'] ?? 0, 1);
                                    $fullStars = floor($avgRating);
                                    $halfStar = $avgRating - $fullStars >= 0.5;
                                    $emptyStars = 5 - $fullStars - ($halfStar ? 1 : 0);

                                    for ($i = 0; $i < $fullStars; $i++) {
                                        echo '<i class="fas fa-star text-yellow-500 mr-1"></i>';
                                    }

                                    if ($halfStar) {
                                        echo '<i class="fas fa-star-half-alt text-yellow-500 mr-1"></i>';
                                    }

                                    for ($i = 0; $i < $emptyStars; $i++) {
                                        echo '<i class="far fa-star text-yellow-500 mr-1"></i>';
                                    }
                                    ?>
                                    <span class="text-white"><?php echo $avgRating; ?>
                                        (<?php echo $gym['review_count'] ?? 0; ?> reviews)</span>
                                </div>
                                <div class="text-white">
                                    <i class="fas fa-users mr-1"></i> <?php echo $gym['today_visits'] ?? 0; ?> today
                                </div>
                            </div>
                            <p class="text-gray-300 text-sm sm:text-base">
                                <i class="fas fa-map-marker-alt mr-1 text-yellow-500"></i>
                                <?php echo htmlspecialchars($gym['address'] . ', ' . $gym['city'] . ', ' . $gym['state']); ?>
                            </p>
                        </div>

                        <div class="mt-4 md:mt-0 flex flex-wrap gap-2">
                            <?php if (isset($_SESSION['user_id'])): ?>
                                <a href="#book-visit"
                                    class="bg-yellow-500 hover:bg-yellow-400 text-black font-bold py-2 px-4 rounded-full transition duration-300 flex items-center">
                                    <i class="fas fa-calendar-plus mr-2"></i> Book a Visit
                                </a>

                                <?php if (!$userHasReviewed): ?>
                                    <a href="#write-review"
                                        class="bg-transparent hover:bg-yellow-500 text-yellow-500 hover:text-black font-bold py-2 px-4 border border-yellow-500 hover:border-transparent rounded-full transition duration-300 flex items-center">
                                        <i class="fas fa-star mr-2"></i> Write a Review
                                    </a>
                                <?php endif; ?>
                            <?php else: ?>
                                <a href="login.php?redirect=gym-profile.php?id=<?php echo $gymId; ?>"
                                    class="bg-yellow-500 hover:bg-yellow-400 text-black font-bold py-2 px-4 rounded-full transition duration-300 flex items-center">
                                    <i class="fas fa-sign-in-alt mr-2"></i> Login to Book
                                </a>
                            <?php endif; ?>

                            <button onclick="shareGym()"
                                class="bg-transparent hover:bg-blue-500 text-blue-500 hover:text-white font-bold py-2 px-4 border border-blue-500 hover:border-transparent rounded-full transition duration-300 flex items-center">
                                <i class="fas fa-share-alt mr-2"></i> Share
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Content -->
            <div class="flex flex-col lg:flex-row gap-8">
                <!-- Left Column (2/3 width) -->
                <div class="w-full lg:w-2/3 space-y-8">
                    <?php foreach ($sectionSettings as $sectionKey => $section): ?>
                        <?php if (!$section['visible'])
                            continue; ?>

                        <?php if ($sectionKey === 'about' && $section['visible']): ?>
                            <!-- About Section -->
                            <section id="about" class="bg-gray-800 rounded-xl p-6 shadow-lg animate-fade-in">
                                <h2 class="text-xl font-bold text-white mb-4 flex items-center">
                                    <i class="fas fa-info-circle text-yellow-500 mr-2"></i>
                                    <?php echo htmlspecialchars($section['title']); ?>
                                </h2>
                                <p class="text-gray-300 mb-4">
                                    <?php echo nl2br(htmlspecialchars($gym['description'])); ?>
                                </p>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-6">
                                    <div class="bg-gray-700 rounded-lg p-4">
                                        <h3 class="text-lg font-semibold text-white mb-2">Contact Information</h3>
                                        <ul class="space-y-2 text-gray-300">
                                            <li class="flex items-center">
                                                <i class="fas fa-phone text-yellow-500 mr-2 w-5"></i>
                                                <a href="tel:<?php echo htmlspecialchars($gym['phone']); ?>"
                                                    class="hover:text-yellow-500 transition">
                                                    <?php echo htmlspecialchars($gym['phone']); ?>
                                                </a>
                                            </li>
                                            <li class="flex items-center">
                                                <i class="fas fa-envelope text-yellow-500 mr-2 w-5"></i>
                                                <a href="mailto:<?php echo htmlspecialchars($gym['email']); ?>"
                                                    class="hover:text-yellow-500 transition">
                                                    <?php echo htmlspecialchars($gym['email']); ?>
                                                </a>
                                            </li>
                                            <li class="flex items-center">
                                                <i class="fas fa-map-marker-alt text-yellow-500 mr-2 w-5"></i>
                                                <span><?php echo htmlspecialchars($gym['address'] . ', ' . $gym['city'] . ', ' . $gym['state'] . ' ' . $gym['zip_code']); ?></span>
                                            </li>
                                        </ul>
                                    </div>

                                    <div class="bg-gray-700 rounded-lg p-4">
                                        <h3 class="text-lg font-semibold text-white mb-2">Capacity Information</h3>
                                        <div class="mb-2">
                                            <div class="flex justify-between mb-1">
                                                <span class="text-gray-300">Current Occupancy</span>
                                                <span class="text-yellow-500 font-medium">
                                                    <?php echo $gym['current_occupancy']; ?>/<?php echo $gym['capacity']; ?>
                                                </span>
                                            </div>
                                            <div class="w-full bg-gray-600 rounded-full h-2.5">
                                                <?php $occupancyPercentage = ($gym['capacity'] > 0) ? min(100, ($gym['current_occupancy'] / $gym['capacity']) * 100) : 0; ?>
                                                <div class="bg-yellow-500 h-2.5 rounded-full"
                                                    style="width: <?php echo $occupancyPercentage; ?>%"></div>
                                            </div>
                                        </div>
                                        <p class="text-sm text-gray-400 mt-2">
                                            <?php if ($occupancyPercentage < 50): ?>
                                                <i class="fas fa-check-circle text-green-500 mr-1"></i> Plenty of space available
                                            <?php elseif ($occupancyPercentage < 80): ?>
                                                <i class="fas fa-info-circle text-yellow-500 mr-1"></i> Moderately busy
                                            <?php else: ?>
                                                <i class="fas fa-exclamation-circle text-red-500 mr-1"></i> Almost at capacity
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                </div>
                            </section>
                        <?php endif; ?>

                        <?php if ($sectionKey === 'amenities' && $section['visible'] && !empty($amenitiesData)): ?>
                            <!-- Amenities Section -->
                            <section id="amenities" class="bg-gray-800 rounded-xl p-6 shadow-lg animate-fade-in">
                                <h2 class="text-xl font-bold text-white mb-4 flex items-center">
                                    <i class="fas fa-spa text-yellow-500 mr-2"></i>
                                    <?php echo htmlspecialchars($section['title']); ?>
                                </h2>

                                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4">
                                    <?php foreach ($amenitiesData as $amenity): ?>
                                        <div class="bg-gray-700 rounded-lg p-3 flex items-center">
                                            <i class="fas fa-check-circle text-yellow-500 mr-2"></i>
                                            <span class="text-gray-300"><?php echo htmlspecialchars($amenity['name']); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                        <?php endif; ?>

                        <?php if ($sectionKey === 'operating_hours' && $section['visible'] && !empty($operatingHours)): ?>
                            <!-- Operating Hours Section -->
                            <section id="hours" class="bg-gray-800 rounded-xl p-6 shadow-lg animate-fade-in">
                                <h2 class="text-xl font-bold text-white mb-4 flex items-center">
                                    <i class="fas fa-clock text-yellow-500 mr-2"></i>
                                    <?php echo htmlspecialchars($section['title']); ?>
                                </h2>

                                <div class="overflow-x-auto">
                                    <table class="min-w-full bg-gray-700 rounded-lg overflow-hidden">
                                        <thead>
                                            <tr>
                                                <th
                                                    class="py-3 px-4 bg-gray-600 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                                    Day</th>
                                                <th
                                                    class="py-3 px-4 bg-gray-600 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                                    Morning Hours</th>
                                                <th
                                                    class="py-3 px-4 bg-gray-600 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                                    Evening Hours</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-600">
                                            <?php
                                            $today = date('l');
                                            foreach ($operatingHours as $hours):
                                                // Skip "Daily" in the display if we have specific days
                                                if ($hours['day'] === 'Daily' && count($operatingHours) > 1 && count(array_diff(array_column($operatingHours, 'day'), ['Daily'])) >= 7) {
                                                    continue;
                                                }

                                                $isToday = $hours['day'] === $today;
                                                $isClosed = $hours['is_closed'] ?? false;

                                                // Also check if times are empty/null which would indicate closed
                                                $morningClosed = empty($hours['morning_open_time']) ||
                                                    empty($hours['morning_close_time']) ||
                                                    $hours['morning_open_time'] === '00:00:00' ||
                                                    $hours['morning_close_time'] === '00:00:00';

                                                $eveningClosed = empty($hours['evening_open_time']) ||
                                                    empty($hours['evening_close_time']) ||
                                                    $hours['evening_open_time'] === '00:00:00' ||
                                                    $hours['evening_close_time'] === '00:00:00';

                                                $fullyClosed = $morningClosed && $eveningClosed;

                                                // Combine the checks
                                                $showAsClosed = $isClosed || $fullyClosed;
                                                ?>
                                                <tr class="<?php echo $isToday ? 'bg-yellow-900 bg-opacity-30' : ''; ?>">
                                                    <td class="py-3 px-4 text-sm text-gray-300 font-medium">
                                                        <?php echo htmlspecialchars($hours['day']); ?>
                                                        <?php if ($isToday): ?>
                                                            <span
                                                                class="ml-2 bg-yellow-500 text-black text-xs px-2 py-0.5 rounded-full">Today</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="py-3 px-4 text-sm text-gray-300">
                                                        <?php if ($showAsClosed || $morningClosed): ?>
                                                            <span class="text-red-400">Closed</span>
                                                        <?php else: ?>
                                                            <?php
                                                            $morningOpen = date('g:i A', strtotime($hours['morning_open_time']));
                                                            $morningClose = date('g:i A', strtotime($hours['morning_close_time']));
                                                            echo "$morningOpen - $morningClose";
                                                            ?>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="py-3 px-4 text-sm text-gray-300">
                                                        <?php if ($showAsClosed || $eveningClosed): ?>
                                                            <span class="text-red-400">Closed</span>
                                                        <?php else: ?>
                                                            <?php
                                                            $eveningOpen = date('g:i A', strtotime($hours['evening_open_time']));
                                                            $eveningClose = date('g:i A', strtotime($hours['evening_close_time']));
                                                            echo "$eveningOpen - $eveningClose";
                                                            ?>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>

                                        </tbody>
                                    </table>
                                </div>

                                <div class="mt-4 flex items-center">
                                    <div
                                        class="w-3 h-3 rounded-full <?php echo $gym['is_open'] ? 'bg-green-500' : 'bg-red-500'; ?> mr-2">
                                    </div>
                                    <span class="text-gray-300">
                                        <?php echo $gym['is_open'] ? 'Currently Open' : 'Currently Closed'; ?>
                                    </span>
                                </div>
                            </section>
                        <?php endif; ?>

                        <?php if ($sectionKey === 'equipment' && $section['visible'] && !empty($groupedEquipment)): ?>
                            <!-- Equipment Section -->
                            <section id="equipment" class="bg-gray-800 rounded-xl p-6 shadow-lg animate-fade-in">
                                <h2 class="text-xl font-bold text-white mb-4 flex items-center">
                                    <i class="fas fa-dumbbell text-yellow-500 mr-2"></i>
                                    <?php echo htmlspecialchars($section['title']); ?>
                                </h2>

                                <div class="space-y-6">
                                    <?php foreach ($groupedEquipment as $category => $items): ?>
                                        <div>
                                            <h3 class="text-lg font-semibold text-yellow-500 mb-3">
                                                <?php echo htmlspecialchars($category); ?>
                                            </h3>
                                            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                                                <?php foreach ($items as $item): ?>
                                                    <div class="bg-gray-700 rounded-lg p-3">
                                                        <div class="flex justify-between items-center">
                                                            <span
                                                                class="text-gray-300"><?php echo htmlspecialchars($item['name']); ?></span>
                                                            <span class="bg-gray-600 text-yellow-500 text-xs px-2 py-1 rounded-full">
                                                                <?php echo htmlspecialchars($item['quantity']); ?> units
                                                            </span>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                        <?php endif; ?>

                        <?php if ($sectionKey === 'gallery' && $section['visible'] && !empty($galleryImages)): ?>
                            <!-- Gallery Section -->
                            <section id="gallery" class="bg-gray-800 rounded-xl p-6 shadow-lg animate-fade-in">
                                <h2 class="text-xl font-bold text-white mb-4 flex items-center">
                                    <i class="fas fa-images text-yellow-500 mr-2"></i>
                                    <?php echo htmlspecialchars($section['title']); ?>
                                </h2>

                                <div class="gallery-container flex overflow-x-auto pb-4 space-x-4">
                                    <?php foreach ($galleryImages as $image): ?>
                                        <div
                                            class="gallery-item flex-shrink-0 w-60 h-40 md:w-72 md:h-48 rounded-lg overflow-hidden">
                                            <img src="./uploads/gym_images/<?php echo htmlspecialchars($image['image_path']); ?>"
                                                alt="<?php echo htmlspecialchars($image['caption'] ?? 'Gym image'); ?>"
                                                class="w-full h-full object-cover hover:scale-110 transition duration-500 cursor-pointer"
                                                onclick="openLightbox('<?php echo htmlspecialchars($image['image_path']); ?>')">
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                        <?php endif; ?>

                        <?php if ($sectionKey === 'reviews' && $section['visible']): ?>
                            <!-- Reviews Section -->
                            <section id="reviews" class="bg-gray-800 rounded-xl p-6 shadow-lg animate-fade-in">
                                <h2 class="text-xl font-bold text-white mb-4 flex items-center">
                                    <i class="fas fa-star text-yellow-500 mr-2"></i>
                                    <?php echo htmlspecialchars($section['title']); ?>
                                </h2>

                                <?php if (!empty($reviews)): ?>
                                    <div class="space-y-4 mb-6">
                                        <?php foreach ($reviews as $review): ?>
                                            <div class="bg-gray-700 rounded-lg p-4">
                                                <div class="flex items-center justify-between mb-2">
                                                    <div class="flex items-center">
                                                        <div class="w-10 h-10 rounded-full overflow-hidden mr-3">
                                                            <img src="<?php echo !empty($review['profile_image']) ? './uploads/profile_images/' . htmlspecialchars($review['profile_image']) : './assets/images/default_avatar.png'; ?>"
                                                                alt="User" class="w-full h-full object-cover">
                                                        </div>
                                                        <div>
                                                            <div class="font-medium text-white">
                                                                <?php echo htmlspecialchars($review['user_name']); ?>
                                                            </div>
                                                            <div class="text-xs text-gray-400">
                                                                <?php echo date('M d, Y', strtotime($review['created_at'])); ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="flex">
                                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                                            <i
                                                                class="<?php echo $i <= $review['rating'] ? 'fas' : 'far'; ?> fa-star text-yellow-500"></i>
                                                        <?php endfor; ?>
                                                    </div>
                                                </div>
                                                <p class="text-gray-300"><?php echo nl2br(htmlspecialchars($review['comment'])); ?></p>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="bg-gray-700 rounded-lg p-4 text-center">
                                        <p class="text-gray-400">No reviews yet. Be the first to review this gym!</p>
                                    </div>
                                <?php endif; ?>

                                <?php if (isset($_SESSION['user_id'])): ?>
                                    <div id="write-review" class="mt-6">
                                        <h3 class="text-lg font-semibold text-white mb-3">
                                            <?php echo $userHasReviewed ? 'Update Your Review' : 'Write a Review'; ?>
                                        </h3>

                                        <?php echo $reviewMessage; ?>

                                        <form action="<?php echo $_SERVER['REQUEST_URI']; ?>#write-review" method="POST"
                                            class="space-y-4">
                                            <div>
                                                <label class="block text-sm font-medium text-gray-300 mb-1">Rating</label>
                                                <div class="flex space-x-2">
                                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                                        <label class="cursor-pointer">
                                                            <input type="radio" name="rating" value="<?php echo $i; ?>"
                                                                class="hidden peer" required>
                                                            <i
                                                                class="far fa-star text-2xl text-yellow-500 peer-checked:fas transition-all duration-200"></i>
                                                        </label>
                                                    <?php endfor; ?>
                                                </div>
                                            </div>

                                            <div>
                                                <label for="comment" class="block text-sm font-medium text-gray-300 mb-1">Your
                                                    Review</label>
                                                <textarea id="comment" name="comment" rows="4"
                                                    class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500"
                                                    placeholder="Share your experience at this gym..." required></textarea>
                                            </div>

                                            <div>
                                                <button type="submit" name="submit_review"
                                                    class="bg-yellow-500 hover:bg-yellow-400 text-black font-bold py-2 px-4 rounded-lg transition duration-300">
                                                    <?php echo $userHasReviewed ? 'Update Review' : 'Submit Review'; ?>
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <div class="mt-6 bg-gray-700 rounded-lg p-4 text-center">
                                        <p class="text-gray-300 mb-2">Want to share your experience?</p>
                                        <a href="login.php?redirect=gym-profile.php?id=<?php echo $gymId; ?>#write-review"
                                            class="inline-block bg-yellow-500 hover:bg-yellow-400 text-black font-bold py-2 px-4 rounded-lg transition duration-300">
                                            Login to Write a Review
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </section>
                        <?php endif; ?>


                        <?php if ($sectionKey === 'policies' && $section['visible']): ?>
                            <!-- Policies Section -->
                            <section id="policies" class="bg-gray-800 rounded-xl p-6 shadow-lg animate-fade-in">
                                <h2 class="text-xl font-bold text-white mb-4 flex items-center">
                                    <i class="fas fa-clipboard-list text-yellow-500 mr-2"></i>
                                    <?php echo htmlspecialchars($section['title']); ?>
                                </h2>

                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    <div class="bg-gray-700 rounded-lg p-4">
                                        <h3 class="text-lg font-semibold text-white mb-2">Cancellation Policy</h3>
                                        <p class="text-gray-300 mb-2">
                                            You must cancel at least <span
                                                class="text-yellow-500 font-medium"><?php echo $gym['cancellation_hours'] ?? 4; ?>
                                                hours</span> before your scheduled visit.
                                        </p>
                                        <p class="text-sm text-gray-400">
                                            Cancellation fee: ₹<?php echo number_format($gym['cancellation_fee'] ?? 200, 2); ?>
                                        </p>
                                    </div>

                                    <div class="bg-gray-700 rounded-lg p-4">
                                        <h3 class="text-lg font-semibold text-white mb-2">Reschedule Policy</h3>
                                        <p class="text-gray-300 mb-2">
                                            You must reschedule at least <span
                                                class="text-yellow-500 font-medium"><?php echo $gym['reschedule_hours'] ?? 2; ?>
                                                hours</span> before your scheduled visit.
                                        </p>
                                        <p class="text-sm text-gray-400">
                                            Reschedule fee: ₹<?php echo number_format($gym['reschedule_fee'] ?? 100, 2); ?>
                                        </p>
                                    </div>

                                    <div class="bg-gray-700 rounded-lg p-4">
                                        <h3 class="text-lg font-semibold text-white mb-2">Late Fee Policy</h3>
                                        <p class="text-gray-300 mb-2">
                                            A late fee applies if you arrive more than 15 minutes late for your scheduled visit.
                                        </p>
                                        <p class="text-sm text-gray-400">
                                            Late fee: ₹<?php echo number_format($gym['late_fee'] ?? 300, 2); ?>
                                        </p>
                                    </div>
                                </div>
                            </section>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>

                <!-- Right Column (1/3 width) -->
                <div class="w-full lg:w-1/3 space-y-8">
                    <!-- Membership Plans Section -->
                    <?php if ($sectionSettings['membership_plans']['visible'] && !empty($plans)): ?>
                        <section id="membership-plans" class="bg-gray-800 rounded-xl p-6 shadow-lg animate-fade-in">
                            <h2 class="text-xl font-bold text-white mb-4 flex items-center">
                                <i class="fas fa-id-card text-yellow-500 mr-2"></i>
                                <?php echo htmlspecialchars($sectionSettings['membership_plans']['title']); ?>
                            </h2>

                            <div class="space-y-4">
                                <?php foreach ($plans as $plan): ?>
                                    <div class="bg-gray-700 rounded-lg p-4 border-l-4 
                                        <?php
                                        switch ($plan['tier']) {
                                            case 'Tier 1':
                                                echo 'border-green-500';
                                                break;
                                            case 'Tier 2':
                                                echo 'border-blue-500';
                                                break;
                                            case 'Tier 3':
                                                echo 'border-purple-500';
                                                break;
                                            default:
                                                echo 'border-gray-500';
                                        }
                                        ?>">
                                        <div class="flex justify-between items-start mb-2">
                                            <h3 class="text-lg font-semibold text-white">
                                                <?php echo htmlspecialchars($plan['plan_name']); ?>
                                            </h3>
                                            <div class="bg-yellow-500 text-black font-bold px-2 py-1 rounded-full text-sm">
                                                ₹<?php echo number_format($plan['price'], 2); ?>
                                            </div>
                                        </div>

                                        <div class="flex items-center mb-2">
                                            <span class="text-xs px-2 py-0.5 rounded-full mr-2
                                                <?php
                                                switch ($plan['tier']) {
                                                    case 'Tier 1':
                                                        echo 'bg-green-500 bg-opacity-20 text-green-400';
                                                        break;
                                                    case 'Tier 2':
                                                        echo 'bg-blue-500 bg-opacity-20 text-blue-400';
                                                        break;
                                                    case 'Tier 3':
                                                        echo 'bg-purple-500 bg-opacity-20 text-purple-400';
                                                        break;
                                                    default:
                                                        echo 'bg-gray-500 bg-opacity-20 text-gray-400';
                                                }
                                                ?>">
                                                <?php echo htmlspecialchars($plan['tier']); ?>
                                            </span>
                                            <span class="text-xs px-2 py-0.5 bg-gray-600 text-gray-300 rounded-full">
                                                <?php echo htmlspecialchars($plan['duration']); ?>
                                            </span>
                                        </div>

                                        <?php if (!empty($plan['inclusions'])): ?>
                                            <div class="text-sm text-gray-300 mb-3">
                                                <?php echo nl2br(htmlspecialchars($plan['inclusions'])); ?>
                                            </div>
                                        <?php endif; ?>

                                        <div class="text-xs text-gray-400 mb-3">
                                            <i class="fas fa-user-check mr-1"></i> Best for:
                                            <?php echo htmlspecialchars($plan['best_for']); ?>
                                        </div>

                                        <?php if (isset($_SESSION['user_id'])): ?>
                                            <a href="buy_membership.php?plan_id=<?php echo $plan['plan_id']; ?>&gym_id=<?php echo $plan['gym_id']; ?>"
                                                class="block w-full bg-gray-600 hover:bg-yellow-500 text-white hover:text-black text-center py-2 rounded-lg transition duration-300 text-sm font-medium">
                                                Purchase Plan
                                            </a>
                                        <?php else: ?>
                                            <a href="login.php?redirect=gym-profile.php?id=<?php echo $gymId; ?>"
                                                class="block w-full bg-gray-600 hover:bg-yellow-500 text-white hover:text-black text-center py-2 rounded-lg transition duration-300 text-sm font-medium">
                                                Login to Purchase
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endif; ?>

                    <!-- Replace the existing Book a Visit Section with this optimized version -->
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <section id="book-visit" class="bg-gray-800 rounded-xl p-6 shadow-lg animate-fade-in">
                            <h2 class="text-xl font-bold text-white mb-4 flex items-center">
                                <i class="fas fa-calendar-alt text-yellow-500 mr-2"></i> Book a Visit
                            </h2>

                            <!-- Check if user has active memberships for this gym -->
                            <?php
                            $membershipsSql = "
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
                CASE 
                    WHEN gmp.duration = 'Daily' THEN DATEDIFF(um.end_date, um.start_date) + 1
                    ELSE NULL
                END as total_days_purchased,
                (SELECT COUNT(*) FROM schedules WHERE membership_id = um.id) as used_days
            FROM user_memberships um
            JOIN gym_membership_plans gmp ON um.plan_id = gmp.plan_id
            WHERE um.user_id = ? AND um.gym_id = ?
            AND um.status = 'active'
            AND um.payment_status = 'paid'
            AND CURRENT_DATE BETWEEN um.start_date AND um.end_date
            ORDER BY um.start_date DESC
        ";
                            $membershipsStmt = $conn->prepare($membershipsSql);
                            $membershipsStmt->execute([$_SESSION['user_id'], $gymId]);
                            $memberships = $membershipsStmt->fetchAll(PDO::FETCH_ASSOC);

                            // Get gym operating hours
                            $hoursStmt = $conn->prepare("
            SELECT 
                morning_open_time, 
                morning_close_time, 
                evening_open_time, 
                evening_close_time
            FROM gym_operating_hours 
            WHERE gym_id = ? AND day = 'Daily'
        ");
                            $hoursStmt->execute([$gymId]);
                            $hours = $hoursStmt->fetch(PDO::FETCH_ASSOC);

                            // Generate time slots
                            $timeSlots = [];
                            $currentTime = new DateTime();
                            // Add one hour to current time for the minimum bookable slot
                            $minBookableTime = clone $currentTime;
                            $minBookableTime->modify('+1 hour');
                            $minBookableTimeStr = $minBookableTime->format('H:i:s');
                            $isToday = true; // Flag to check if we're generating slots for today
                        
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
            AND start_date = CURRENT_DATE
            GROUP BY start_time
        ");
                            $occupancyStmt->execute([$gymId]);
                            $occupancyByTime = $occupancyStmt->fetchAll(PDO::FETCH_KEY_PAIR);

                            // Check if user already has a booking for today at this gym
                            $existingBookingStmt = $conn->prepare("
            SELECT COUNT(*) 
            FROM schedules 
            WHERE user_id = ? 
            AND gym_id = ? 
            AND start_date = CURRENT_DATE
        ");
                            $existingBookingStmt->execute([$_SESSION['user_id'], $gymId]);
                            $hasExistingBooking = $existingBookingStmt->fetchColumn() > 0;

                            // Fetch user balance
                            $balanceStmt = $conn->prepare("SELECT balance FROM users WHERE id = ?");
                            $balanceStmt->execute([$_SESSION['user_id']]);
                            $userBalance = $balanceStmt->fetchColumn();
                            ?>

                            <?php if (empty($memberships)): ?>
                                <div class="bg-yellow-900 text-yellow-100 p-4 rounded-lg mb-4">
                                    <p class="font-bold">You don't have an active membership for this gym.</p>
                                    <p class="mt-2">Please purchase a membership plan to schedule visits.</p>
                                    <a href="#membership-plans"
                                        class="inline-block mt-3 bg-yellow-500 hover:bg-yellow-400 text-black px-4 py-2 rounded-lg font-medium transition duration-300">
                                        View Membership Plans
                                    </a>
                                </div>
                            <?php elseif ($hasExistingBooking): ?>
                                <div class="bg-yellow-900 text-yellow-100 p-4 rounded-lg mb-4">
                                    <p class="font-bold">You already have a booking for today at this gym.</p>
                                    <p>You can schedule for future dates or check your <a href="schedule-history.php"
                                            class="underline">schedule history</a>.</p>
                                </div>
                            <?php else: ?>
                                <!-- Balance Display -->
                                <div class="bg-gray-700 bg-opacity-50 rounded-lg p-4 mb-4">
                                    <h4 class="text-lg font-medium text-white flex items-center">
                                        <i class="fas fa-wallet text-yellow-400 mr-2"></i>
                                        Your Balance: ₹<?= number_format($userBalance, 2) ?>
                                    </h4>
                                </div>

                                <!-- Membership Selection -->
                                <?php if (count($memberships) > 1): ?>
                                    <div class="mb-4">
                                        <label class="block text-sm font-medium text-gray-300 mb-2">Select Membership</label>
                                        <select id="membership-select"
                                            class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                            <?php foreach ($memberships as $index => $membership):
                                                $isDaily = strtolower($membership['duration']) === 'daily';
                                                $membershipTotalDays = $membership['total_days_purchased'] ?? 1;
                                                $membershipUsedDays = $membership['used_days'] ?? 0;
                                                $membershipRemainingDays = $membershipTotalDays - $membershipUsedDays;
                                                $isFullyUtilized = $isDaily && $membershipRemainingDays <= 0;

                                                if ($isFullyUtilized)
                                                    continue; // Skip fully utilized daily passes
                                                ?>
                                                <option value="<?= $membership['membership_id'] ?>"
                                                    data-daily="<?= $isDaily ? '1' : '0' ?>"
                                                    data-remaining="<?= $membershipRemainingDays ?>" <?= $index === 0 ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($membership['tier']) ?> -
                                                    <?= htmlspecialchars($membership['duration']) ?>
                                                    <?= $isDaily ? "($membershipRemainingDays days left)" : '' ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                <?php endif; ?>

                                <form id="booking-form" action="process_schedule.php" method="POST" class="space-y-4">
                                    <input type="hidden" name="membership_id" id="selected-membership-id"
                                        value="<?= $memberships[0]['membership_id'] ?>">
                                    <input type="hidden" name="gym_id" value="<?= $gymId ?>">

                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <label for="start-date" class="block text-sm font-medium text-gray-300 mb-2">Start
                                                Date</label>
                                            <input type="date" id="start-date" name="start_date" min="<?= date('Y-m-d') ?>"
                                                max="<?= $memberships[0]['end_date'] ?>" value="<?= date('Y-m-d') ?>"
                                                class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500"
                                                required>
                                        </div>

                                        <div>
                                            <label for="end-date" class="block text-sm font-medium text-gray-300 mb-2">End
                                                Date</label>
                                            <input type="date" id="end-date" name="end_date" min="<?= date('Y-m-d') ?>"
                                                max="<?= $memberships[0]['end_date'] ?>" value="<?= date('Y-m-d') ?>"
                                                class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500"
                                                required>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <label for="activity-type"
                                                class="block text-sm font-medium text-gray-300 mb-2">Activity Type</label>
                                            <select id="activity-type" name="activity_type"
                                                class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500"
                                                required>
                                                <option value="gym_visit">General Workout</option>
                                                <option value="class">Class Session</option>
                                                <option value="personal_training">Personal Training</option>
                                            </select>
                                        </div>

                                        <div>
                                            <label for="recurring-select"
                                                class="block text-sm font-medium text-gray-300 mb-2">Schedule Type</label>
                                            <select id="recurring-select" name="recurring"
                                                class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                                <option value="none">Today Only</option>
                                                <option value="daily">Daily</option>
                                                <option value="weekly">Weekly</option>
                                            </select>
                                        </div>
                                    </div>

                                    <!-- Time Slot Selection -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-300 mb-2">Select Time Slot</label>
                                        <div id="time-slot-container"
                                            class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2 max-h-48 overflow-y-auto">
                                            <?php if (empty($timeSlots)): ?>
                                                <div
                                                    class="col-span-full text-center py-4 bg-gray-700 bg-opacity-50 rounded-lg text-gray-300">
                                                    No available time slots for today
                                                </div>
                                            <?php else: ?>
                                                <?php foreach ($timeSlots as $time):
                                                    $currentOccupancy = $occupancyByTime[$time] ?? 0;
                                                    $available = 50 - $currentOccupancy; // Assuming max capacity is 50
                                                    $isSlotFull = $currentOccupancy >= 50;
                                                    $formattedTime = date('g:i A', strtotime($time));
                                                    $slotClass = $isSlotFull
                                                        ? "bg-gray-700 text-gray-500 cursor-not-allowed opacity-50"
                                                        : "bg-gray-700 hover:bg-yellow-500 hover:text-black text-white cursor-pointer";
                                                    ?>
                                                    <div class="time-slot-item <?= $slotClass ?> rounded-lg p-2 text-center transition-all duration-200"
                                                        data-time="<?= $time ?>" data-available="<?= $available ?>"
                                                        data-full="<?= $isSlotFull ? '1' : '0' ?>"
                                                        onclick="<?= $isSlotFull ? '' : "selectTimeSlot('$time', '$formattedTime', $available)" ?>">
                                                        <div class="font-medium text-sm"><?= $formattedTime ?></div>
                                                        <div
                                                            class="text-xs mt-1 <?= $available < 10 ? 'text-red-400' : 'text-gray-300' ?>">
                                                            <?= $available ?> spots
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                        <input type="hidden" name="time_slot" id="selected-time-slot" required>
                                        <div id="selected-time-display"
                                            class="hidden mt-3 p-2 bg-yellow-500 text-black rounded-lg text-center font-medium">
                                        </div>
                                    </div>

                                    <!-- Weekly Days Selection (Hidden by default) -->
                                    <div id="days-selection" class="hidden">
                                        <label class="block text-sm font-medium text-gray-300 mb-2">Select Days of Week</label>
                                        <div class="grid grid-cols-2 sm:grid-cols-4 md:grid-cols-7 gap-2">
                                            <?php foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $day): ?>
                                                <label
                                                    class="flex items-center space-x-2 bg-gray-700 p-2 rounded-lg cursor-pointer hover:bg-gray-600 transition-colors">
                                                    <input type="checkbox" name="days[]" value="<?= strtolower($day) ?>"
                                                        class="rounded border-gray-600 bg-gray-800 text-yellow-400 focus:ring-yellow-400"
                                                        onchange="calculateCost()">
                                                    <span class="text-white text-sm"><?= $day ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>

                                    <!-- Notes -->
                                    <div>
                                        <label for="notes" class="block text-sm font-medium text-gray-300 mb-2">Additional
                                            Notes</label>
                                        <textarea id="notes" name="notes" rows="2"
                                            class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500"
                                            placeholder="Any special requests or notes..."></textarea>
                                    </div>

                                    <!-- Estimated Cost Section -->
                                    <div class="bg-gray-700 bg-opacity-50 rounded-lg p-4">
                                        <h3 class="text-sm font-medium text-yellow-400 mb-3">Estimated Cost</h3>
                                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                            <div class="bg-gray-700 rounded-lg p-3">
                                                <div class="text-gray-300 text-xs mb-1">Daily Rate:</div>
                                                <div id="daily-rate-display" class="text-white text-base font-medium">₹0.00
                                                </div>
                                            </div>
                                            <div class="bg-gray-700 rounded-lg p-3">
                                                <div class="text-gray-300 text-xs mb-1">Total Days:</div>
                                                <div id="total-days-display" class="text-white text-base font-medium">0</div>
                                            </div>
                                            <div class="bg-gray-700 rounded-lg p-3">
                                                <div class="text-gray-300 text-xs mb-1">Total Cost:</div>
                                                <div id="total-cost-display" class="text-white text-base font-medium">₹0.00
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Daily Pass Warning (Hidden by default) -->
                                    <div id="daily-pass-warning" class="hidden bg-yellow-900 bg-opacity-50 rounded-lg p-4">
                                        <div class="flex items-start">
                                            <i class="fas fa-exclamation-triangle text-yellow-400 mt-1 mr-3"></i>
                                            <div>
                                                <h3 class="text-yellow-400 font-medium mb-1">Daily Pass Limit</h3>
                                                <p class="text-yellow-100 text-sm">
                                                    You have <span id="remaining-days-display">0</span> day(s) remaining on your
                                                    pass.
                                                    Your current selection requires <span id="required-days-display">1</span>
                                                    day(s).
                                                </p>
                                            </div>
                                        </div>
                                    </div>

                                    <button type="submit" id="submit-button"
                                        class="w-full bg-yellow-500 hover:bg-yellow-400 text-black font-bold py-3 px-4 rounded-lg transition duration-300">
                                        <i class="fas fa-calendar-check mr-2"></i> Create Schedule
                                    </button>
                                </form>
                            <?php endif; ?>
                        </section>
                    <?php endif; ?>
                    <script>
                        // Booking form functionality
                        document.addEventListener('DOMContentLoaded', function () {
                            // Initialize variables for booking form
                            const bookingForm = document.getElementById('booking-form');
                            if (!bookingForm) return; // Exit if booking form doesn't exist

                            // Cache DOM elements
                            const startDateInput = document.getElementById('start-date');
                            const endDateInput = document.getElementById('end-date');
                            const recurringSelect = document.getElementById('recurring-select');
                            const daysSelection = document.getElementById('days-selection');
                            const timeSlotContainer = document.getElementById('time-slot-container');
                            const selectedTimeSlotInput = document.getElementById('selected-time-slot');
                            const selectedTimeDisplay = document.getElementById('selected-time-display');
                            const submitButton = document.getElementById('submit-button');
                            const membershipSelect = document.getElementById('membership-select');
                            const selectedMembershipIdInput = document.getElementById('selected-membership-id');

                            // Initialize membership data
                            let memberships = <?php echo json_encode($memberships ?? []); ?>;
                            let timeSlots = <?php echo json_encode($timeSlots ?? []); ?>;
                            let occupancyByTime = <?php echo json_encode($occupancyByTime ?? []); ?>;
                            let userBalance = <?php echo $userBalance ?? 0; ?>;

                            // Set initial selected membership
                            let selectedMembership = memberships.length > 0 ? memberships[0] : null;
                            let isDailyPass = selectedMembership && selectedMembership.duration.toLowerCase() === 'daily';
                            let remainingDays = isDailyPass ? (selectedMembership.total_days_purchased - selectedMembership.used_days) : 0;

                            // Initialize form
                            if (startDateInput && endDateInput) {
                                // Set min/max dates
                                const today = new Date().toISOString().split('T')[0];
                                startDateInput.min = today;
                                endDateInput.min = today;

                                if (selectedMembership) {
                                    startDateInput.max = selectedMembership.end_date;
                                    endDateInput.max = selectedMembership.end_date;
                                }

                                // Event listeners
                                startDateInput.addEventListener('change', function () {
                                    // End date should be at least the start date
                                    endDateInput.min = this.value;

                                    // If end date is before start date, update it
                                    if (endDateInput.value < this.value) {
                                        endDateInput.value = this.value;
                                    }

                                    updateTimeSlots();
                                    calculateCost();
                                });

                                endDateInput.addEventListener('change', calculateCost);

                                // Handle recurring selection
                                recurringSelect.addEventListener('change', function () {
                                    daysSelection.classList.toggle('hidden', this.value !== 'weekly');
                                    calculateCost();
                                });

                                // Handle membership selection change
                                if (membershipSelect) {
                                    membershipSelect.addEventListener('change', function () {
                                        const membershipId = this.value;
                                        selectedMembership = memberships.find(m => m.membership_id == membershipId);
                                        selectedMembershipIdInput.value = membershipId;

                                        isDailyPass = selectedMembership && selectedMembership.duration.toLowerCase() === 'daily';
                                        remainingDays = isDailyPass ? (selectedMembership.total_days_purchased - selectedMembership.used_days) : 0;

                                        // Update date constraints
                                        startDateInput.max = selectedMembership.end_date;
                                        endDateInput.max = selectedMembership.end_date;

                                        calculateCost();
                                    });
                                }

                                // Initialize time slot selection
                                initializeTimeSlots();

                                // Calculate cost on page load
                                calculateCost();

                                // Form submission
                                bookingForm.addEventListener('submit', function (e) {
                                    // Validate form
                                    if (!validateBookingForm()) {
                                        e.preventDefault();
                                        return false;
                                    }

                                    // Show loading state
                                    submitButton.disabled = true;
                                    submitButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Processing...';

                                    // Add timestamp to prevent duplicate submissions
                                    const timestampField = document.createElement('input');
                                    timestampField.type = 'hidden';
                                    timestampField.name = 'submission_timestamp';
                                    timestampField.value = Date.now();
                                    this.appendChild(timestampField);
                                });
                            }

                            // Function to initialize time slots
                            function initializeTimeSlots() {
                                // Pre-select the first available time slot if any
                                const availableSlots = document.querySelectorAll('.time-slot-item:not([data-full="1"])');
                                if (availableSlots.length > 0) {
                                    const firstSlot = availableSlots[0];
                                    const time = firstSlot.getAttribute('data-time');
                                    const formattedTime = firstSlot.querySelector('div:first-child').textContent;
                                    const available = firstSlot.getAttribute('data-available');

                                    selectTimeSlot(time, formattedTime, available);
                                }
                            }

                            // Function to select time slot
                            window.selectTimeSlot = function (time, formattedTime, available) {
                                // Update hidden input value
                                selectedTimeSlotInput.value = time;

                                // Update visual display
                                selectedTimeDisplay.innerHTML = `<i class="fas fa-clock mr-2"></i> Selected: <strong>${formattedTime}</strong> (${available} spots available)`;
                                selectedTimeDisplay.classList.remove('hidden');

                                // Reset all time slots to unselected state
                                document.querySelectorAll('.time-slot-item').forEach(slot => {
                                    slot.classList.remove('selected');
                                    if (slot.classList.contains('bg-yellow-500')) {
                                        slot.classList.remove('bg-yellow-500', 'text-black');
                                        slot.classList.add('bg-gray-700', 'text-white');
                                    }
                                });

                                // Highlight the selected time slot
                                const selectedSlot = document.querySelector(`.time-slot-item[data-time="${time}"]`);
                                if (selectedSlot) {
                                    selectedSlot.classList.add('selected');
                                    selectedSlot.classList.remove('bg-gray-700', 'text-white');
                                    selectedSlot.classList.add('bg-yellow-500', 'text-black');
                                }

                                // Calculate cost after time slot selection
                                calculateCost();
                            }

                            // Function to update time slots based on selected date
                            function updateTimeSlots() {
                                const selectedDate = startDateInput.value;
                                const today = new Date().toISOString().split('T')[0];
                                const isToday = selectedDate === today;

                                // Show loading state
                                timeSlotContainer.innerHTML = '<div class="col-span-full text-center py-4"><i class="fas fa-spinner fa-spin text-yellow-400"></i><p class="text-white mt-2">Loading available time slots...</p></div>';

                                // In a real implementation, you would fetch time slots from the server
                                // For now, we'll simulate with the existing data
                                setTimeout(() => {
                                    let availableSlots = 0;
                                    let slotsHTML = '';

                                    if (timeSlots.length === 0) {
                                        slotsHTML = '<div class="col-span-full text-center py-4 bg-gray-700 bg-opacity-50 rounded-lg text-gray-300">No available time slots for the selected date</div>';
                                    } else {
                                        timeSlots.forEach(time => {
                                            // For today, filter out slots that are less than 1 hour ahead
                                            const currentTime = new Date();
                                            currentTime.setHours(currentTime.getHours() + 1);
                                            const minBookableTime = currentTime.getHours() + ':' + currentTime.getMinutes() + ':00';

                                            if (isToday && time < minBookableTime) {
                                                return;
                                            }

                                            const currentOccupancy = occupancyByTime[time] || 0;
                                            const available = 50 - currentOccupancy;
                                            const isSlotFull = currentOccupancy >= 50;

                                            if (!isSlotFull) {
                                                availableSlots++;
                                            }

                                            const formattedTime = new Date(`2000-01-01T${time}`).toLocaleTimeString('en-US', {
                                                hour: 'numeric',
                                                minute: 'numeric',
                                                hour12: true
                                            });

                                            const slotClass = isSlotFull
                                                ? "bg-gray-700 text-gray-500 cursor-not-allowed opacity-50"
                                                : "bg-gray-700 hover:bg-yellow-500 hover:text-black text-white cursor-pointer";

                                            slotsHTML += `
                        <div class="time-slot-item ${slotClass} rounded-lg p-2 text-center transition-all duration-200"
                             data-time="${time}"
                             data-available="${available}"
                             data-full="${isSlotFull ? '1' : '0'}"
                             onclick="${isSlotFull ? '' : `selectTimeSlot('${time}', '${formattedTime}', ${available})`}">
                            <div class="font-medium text-sm">${formattedTime}</div>
                            <div class="text-xs mt-1 ${available < 10 ? 'text-red-400' : 'text-gray-300'}">
                                ${available} spots
                            </div>
                        </div>
                    `;
                                        });
                                    }

                                    timeSlotContainer.innerHTML = slotsHTML;

                                    // Disable submit button if no slots available
                                    if (availableSlots === 0) {
                                        submitButton.disabled = true;
                                        submitButton.classList.add('opacity-50', 'cursor-not-allowed');
                                    } else {
                                        if (!isDailyPass || remainingDays > 0) {
                                            submitButton.disabled = false;
                                            submitButton.classList.remove('opacity-50', 'cursor-not-allowed');
                                        }

                                        // Initialize time slot selection
                                        initializeTimeSlots();
                                    }
                                }, 300);
                            }

                            // Function to calculate cost
                            function calculateCost() {
                                if (!selectedMembership) return;

                                const startDate = new Date(startDateInput.value);
                                const endDate = new Date(endDateInput.value);
                                const recurringType = recurringSelect.value;

                                // Calculate days between dates (inclusive)
                                const timeDiff = endDate.getTime() - startDate.getTime();
                                const dayDiff = Math.floor(timeDiff / (1000 * 3600 * 24)) + 1;

                                let totalDays = dayDiff;

                                // Adjust for weekly scheduling
                                if (recurringType === 'weekly') {
                                    const selectedDays = Array.from(document.querySelectorAll('input[name="days[]"]:checked')).length;
                                    if (selectedDays === 0) {
                                        totalDays = 0;
                                    } else {
                                        // Calculate how many of each selected day occur in the date range
                                        const checkboxes = document.querySelectorAll('input[name="days[]"]:checked');
                                        const selectedDayIndices = Array.from(checkboxes).map(cb => {
                                            const day = cb.value.toLowerCase();
                                            return ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'].indexOf(day);
                                        });

                                        totalDays = 0;
                                        for (let d = new Date(startDate); d <= endDate; d.setDate(d.getDate() + 1)) {
                                            if (selectedDayIndices.includes(d.getDay())) {
                                                totalDays++;
                                            }
                                        }
                                    }
                                } else if (recurringType === 'none') {
                                    totalDays = 1; // Just one day
                                }

                                // Calculate daily rate based on membership
                                let dailyRate = 0;
                                if (selectedMembership.duration.toLowerCase() === 'daily') {
                                    dailyRate = parseFloat(selectedMembership.price);
                                } else {
                                    // Calculate prorated daily rate
                                    let durationDays = 30; // Default to monthly

                                    switch (selectedMembership.duration.toLowerCase()) {
                                        case 'weekly':
                                            durationDays = 7;
                                            break;
                                        case 'monthly':
                                            durationDays = 30;
                                            break;
                                        case 'quarterly':
                                            durationDays = 90;
                                            break;
                                        case 'half yearly':
                                            durationDays = 180;
                                            break;
                                        case 'yearly':
                                            durationDays = 365;
                                            break;
                                    }

                                    dailyRate = parseFloat(selectedMembership.price) / durationDays;
                                }

                                // Round to 2 decimal places
                                dailyRate = Math.floor(dailyRate * 100) / 100;
                                const totalCost = dailyRate * totalDays;

                                // Update display with animation
                                animateValue('daily-rate-display', '₹' + dailyRate.toFixed(2));
                                animateValue('total-days-display', totalDays);
                                animateValue('total-cost-display', '₹' + totalCost.toFixed(2));

                                // Check daily pass limits if applicable
                                if (isDailyPass) {
                                    checkDailyPassLimits(totalDays);
                                }

                                // Update submit button state with visual feedback
                                if (totalCost > userBalance) {
                                    submitButton.disabled = true;
                                    submitButton.classList.add('opacity-50', 'cursor-not-allowed', 'bg-red-500');
                                    submitButton.classList.remove('bg-yellow-500', 'hover:bg-yellow-400');
                                    submitButton.innerHTML = '<i class="fas fa-exclamation-circle mr-2"></i> Insufficient Balance';
                                } else if (isDailyPass && totalDays > remainingDays) {
                                    submitButton.disabled = true;
                                    submitButton.classList.add('opacity-50', 'cursor-not-allowed', 'bg-red-500');
                                    submitButton.classList.remove('bg-yellow-500', 'hover:bg-yellow-400');
                                    submitButton.innerHTML = '<i class="fas fa-exclamation-circle mr-2"></i> Exceeds Daily Pass Limit';
                                } else if (!selectedTimeSlotInput.value) {
                                    submitButton.disabled = true;
                                    submitButton.classList.add('opacity-50', 'cursor-not-allowed');
                                    submitButton.classList.remove('bg-red-500');
                                    submitButton.classList.add('bg-yellow-500');
                                    submitButton.innerHTML = '<i class="fas fa-clock mr-2"></i> Select a Time Slot';
                                } else {
                                    submitButton.disabled = false;
                                    submitButton.classList.remove('opacity-50', 'cursor-not-allowed', 'bg-red-500');
                                    submitButton.classList.add('bg-yellow-500', 'hover:bg-yellow-400');
                                    submitButton.innerHTML = '<i class="fas fa-calendar-check mr-2"></i> Create Schedule';
                                }
                            }

                            // Function to animate value changes
                            function animateValue(elementId, newValue) {
                                const element = document.getElementById(elementId);
                                if (!element) return;

                                // Add a subtle highlight effect
                                element.classList.add('animate-pulse', 'text-yellow-400');

                                // Update the value
                                element.textContent = newValue;

                                // Remove the highlight effect after animation
                                setTimeout(() => {
                                    element.classList.remove('animate-pulse', 'text-yellow-400');
                                }, 700);
                            }

                            // Function to check daily pass limits
                            function checkDailyPassLimits(totalDays = null) {
                                if (!isDailyPass) return;

                                if (totalDays === null) {
                                    // Calculate total days if not provided
                                    const startDate = new Date(startDateInput.value);
                                    const endDate = new Date(endDateInput.value);
                                    const recurringType = recurringSelect.value;

                                    // Calculate days between dates (inclusive)
                                    const timeDiff = endDate.getTime() - startDate.getTime();
                                    const dayDiff = Math.floor(timeDiff / (1000 * 3600 * 24)) + 1;

                                    totalDays = dayDiff;

                                    if (recurringType === 'weekly') {
                                        const selectedDays = Array.from(document.querySelectorAll('input[name="days[]"]:checked')).length;
                                        if (selectedDays === 0) {
                                            totalDays = 0;
                                        } else {
                                            // Calculate how many of each selected day occur in the date range
                                            const checkboxes = document.querySelectorAll('input[name="days[]"]:checked');
                                            const selectedDayIndices = Array.from(checkboxes).map(cb => {
                                                const day = cb.value.toLowerCase();
                                                return ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'].indexOf(day);
                                            });

                                            totalDays = 0;
                                            for (let d = new Date(startDate); d <= endDate; d.setDate(d.getDate() + 1)) {
                                                if (selectedDayIndices.includes(d.getDay())) {
                                                    totalDays++;
                                                }
                                            }
                                        }
                                    } else if (recurringType === 'none') {
                                        totalDays = 1;
                                    }
                                }

                                const dailyPassWarning = document.getElementById('daily-pass-warning');
                                const remainingDaysDisplay = document.getElementById('remaining-days-display');
                                const requiredDaysDisplay = document.getElementById('required-days-display');

                                if (totalDays > remainingDays) {
                                    dailyPassWarning.classList.remove('hidden');
                                    remainingDaysDisplay.textContent = remainingDays;
                                    requiredDaysDisplay.textContent = totalDays;

                                    // Add animation to highlight the warning
                                    dailyPassWarning.classList.add('animate-pulse');
                                    setTimeout(() => {
                                        dailyPassWarning.classList.remove('animate-pulse');
                                    }, 1000);

                                    submitButton.disabled = true;
                                    submitButton.classList.add('opacity-50', 'cursor-not-allowed', 'bg-red-500');
                                    submitButton.classList.remove('bg-yellow-500', 'hover:bg-yellow-400');
                                    submitButton.innerHTML = '<i class="fas fa-exclamation-circle mr-2"></i> Exceeds Daily Pass Limit';
                                } else if (remainingDays <= 0) {
                                    dailyPassWarning.classList.remove('hidden');
                                    remainingDaysDisplay.textContent = 0;
                                    requiredDaysDisplay.textContent = totalDays;

                                    submitButton.disabled = true;
                                    submitButton.classList.add('opacity-50', 'cursor-not-allowed', 'bg-red-500');
                                    submitButton.classList.remove('bg-yellow-500', 'hover:bg-yellow-400');
                                    submitButton.innerHTML = '<i class="fas fa-exclamation-circle mr-2"></i> Daily Pass Fully Used';
                                } else {
                                    dailyPassWarning.classList.add('hidden');

                                    if (selectedTimeSlotInput.value) {
                                        submitButton.disabled = false;
                                        submitButton.classList.remove('opacity-50', 'cursor-not-allowed', 'bg-red-500');
                                        submitButton.classList.add('bg-yellow-500', 'hover:bg-yellow-400');
                                        submitButton.innerHTML = '<i class="fas fa-calendar-check mr-2"></i> Create Schedule';
                                    } else {
                                        submitButton.disabled = true;
                                        submitButton.classList.add('opacity-50', 'cursor-not-allowed');
                                        submitButton.classList.remove('bg-red-500');
                                        submitButton.classList.add('bg-yellow-500');
                                        submitButton.innerHTML = '<i class="fas fa-clock mr-2"></i> Select a Time Slot';
                                    }
                                }
                            }

                            // Function to validate booking form
                            function validateBookingForm() {
                                // Check if time slot is selected
                                if (!selectedTimeSlotInput.value) {
                                    showToast('Please select a time slot.', 'error');
                                    return false;
                                }

                                const recurringType = recurringSelect.value;

                                if (recurringType === 'weekly') {
                                    const selectedDays = document.querySelectorAll('input[name="days[]"]:checked');
                                    if (selectedDays.length === 0) {
                                        showToast('Please select at least one day of the week for weekly scheduling.', 'error');
                                        return false;
                                    }
                                }

                                // Final balance check
                                const totalCostText = document.getElementById('total-cost-display').textContent;
                                const totalCost = parseFloat(totalCostText.replace('₹', ''));
                                if (totalCost > userBalance) {
                                    showToast('Insufficient balance. Please top up your account.', 'error');
                                    return false;
                                }

                                // Daily pass limit check
                                if (isDailyPass) {
                                    const totalDays = parseInt(document.getElementById('total-days-display').textContent);
                                    if (totalDays > remainingDays) {
                                        showToast(`Your daily pass only has ${remainingDays} day(s) remaining, but you're trying to schedule for ${totalDays} day(s).`, 'error');
                                        return false;
                                    }

                                    if (remainingDays <= 0) {
                                        showToast('Your daily pass has been fully utilized. Please purchase a new membership.', 'error');
                                        return false;
                                    }
                                }

                                return true;
                            }

                            // Function to show toast notifications
                            function showToast(message, type = 'info') {
                                // Remove any existing toasts
                                const existingToasts = document.querySelectorAll('.toast-notification');
                                existingToasts.forEach(toast => toast.remove());

                                // Create toast element
                                const toast = document.createElement('div');
                                toast.className = 'toast-notification fixed top-20 right-4 z-50 p-4 rounded-lg shadow-lg transform transition-all duration-300 translate-x-full';

                                // Set background color based on type
                                if (type === 'error') {
                                    toast.classList.add('bg-red-600', 'text-white');
                                } else if (type === 'success') {
                                    toast.classList.add('bg-green-600', 'text-white');
                                } else {
                                    toast.classList.add('bg-blue-600', 'text-white');
                                }

                                // Set icon based on type
                                let icon = 'fa-info-circle';
                                if (type === 'error') icon = 'fa-exclamation-circle';
                                if (type === 'success') icon = 'fa-check-circle';

                                // Set content
                                toast.innerHTML = `
            <div class="flex items-center">
                <i class="fas ${icon} text-2xl mr-3"></i>
                <div>${message}</div>
            </div>
        `;

                                // Add to document
                                document.body.appendChild(toast);

                                // Animate in
                                setTimeout(() => {
                                    toast.classList.remove('translate-x-full');
                                    toast.classList.add('translate-x-0');
                                }, 10);

                                // Animate out after delay
                                setTimeout(() => {
                                    toast.classList.remove('translate-x-0');
                                    toast.classList.add('translate-x-full');

                                    // Remove from DOM after animation
                                    setTimeout(() => {
                                        toast.remove();
                                    }, 300);
                                }, 5000);
                            }
                        });
                    </script>



                    <!-- Map Section -->
                    <?php if ($gym['latitude'] && $gym['longitude']): ?>
                        <section class="bg-gray-800 rounded-xl p-6 shadow-lg animate-fade-in">
                            <h2 class="text-xl font-bold text-white mb-4 flex items-center">
                                <i class="fas fa-map-marked-alt text-yellow-500 mr-2"></i> Location
                            </h2>

                            <div class="rounded-lg overflow-hidden h-64">
                                <div id="map" class="w-full h-full"></div>
                            </div>

                            <div class="mt-3">
                                <a href="https://maps.google.com/?q=<?php echo $gym['latitude']; ?>,<?php echo $gym['longitude']; ?>"
                                    target="_blank" class="text-blue-400 hover:text-blue-300 text-sm flex items-center">
                                    <i class="fas fa-directions mr-1"></i> Get Directions
                                </a>
                            </div>
                        </section>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Image Lightbox -->
    <div id="lightbox" class="fixed inset-0 bg-black bg-opacity-90 z-50 flex items-center justify-center hidden">
        <button id="close-lightbox" class="absolute top-4 right-4 text-white text-2xl">
            <i class="fas fa-times"></i>
        </button>
        <img id="lightbox-image" src="" alt="Gym image" class="max-w-full max-h-[90vh] object-contain">
    </div>

    <!-- Share Modal -->
    <div id="share-modal" class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center hidden">
        <div class="bg-gray-800 rounded-xl p-6 max-w-md w-full mx-4">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-bold text-white">Share This Gym</h3>
                <button id="close-share" class="text-gray-400 hover:text-white">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="space-y-4">
                <div class="flex items-center justify-between bg-gray-700 rounded-lg p-3">
                    <input id="share-url" type="text"
                        value="<?php echo 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']; ?>"
                        class="bg-transparent text-white w-full mr-2 outline-none" readonly>
                    <button id="copy-url"
                        class="bg-yellow-500 hover:bg-yellow-400 text-black px-3 py-1 rounded-lg text-sm font-medium">
                        Copy
                    </button>
                </div>

                <div class="flex justify-center space-x-4">
                    <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode('https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']); ?>"
                        target="_blank"
                        class="bg-blue-600 hover:bg-blue-700 text-white w-10 h-10 rounded-full flex items-center justify-center">
                        <i class="fab fa-facebook-f"></i>
                    </a>
                    <a href="https://twitter.com/intent/tweet?url=<?php echo urlencode('https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']); ?>&text=<?php echo urlencode('Check out ' . $gym['name'] . ' on Fitness Hub!'); ?>"
                        target="_blank"
                        class="bg-blue-400 hover:bg-blue-500 text-white w-10 h-10 rounded-full flex items-center justify-center">
                        <i class="fab fa-twitter"></i>
                    </a>
                    <a href="https://api.whatsapp.com/send?text=<?php echo urlencode('Check out ' . $gym['name'] . ' on Fitness Hub! ' . 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']); ?>"
                        target="_blank"
                        class="bg-green-500 hover:bg-green-600 text-white w-10 h-10 rounded-full flex items-center justify-center">
                        <i class="fab fa-whatsapp"></i>
                    </a>
                    <a href="mailto:?subject=<?php echo urlencode('Check out this gym on Fitness Hub'); ?>&body=<?php echo urlencode('I thought you might be interested in ' . $gym['name'] . ': ' . 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']); ?>"
                        class="bg-red-500 hover:bg-red-600 text-white w-10 h-10 rounded-full flex items-center justify-center">
                        <i class="fas fa-envelope"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Initialize map if coordinates are available
        <?php if ($gym['latitude'] && $gym['longitude']): ?>
            function initMap() {
                const gymLocation = { lat: <?php echo $gym['latitude']; ?>, lng: <?php echo $gym['longitude']; ?> };
                const map = new google.maps.Map(document.getElementById("map"), {
                    zoom: 15,
                    center: gymLocation,
                    styles: [
                        { elementType: "geometry", stylers: [{ color: "#242f3e" }] },
                        { elementType: "labels.text.stroke", stylers: [{ color: "#242f3e" }] },
                        { elementType: "labels.text.fill", stylers: [{ color: "#746855" }] },
                        {
                            featureType: "administrative.locality",
                            elementType: "labels.text.fill",
                            stylers: [{ color: "#d59563" }],
                        },
                        {
                            featureType: "poi",
                            elementType: "labels.text.fill",
                            stylers: [{ color: "#d59563" }],
                        },
                        {
                            featureType: "poi.park",
                            elementType: "geometry",
                            stylers: [{ color: "#263c3f" }],
                        },
                        {
                            featureType: "poi.park",
                            elementType: "labels.text.fill",
                            stylers: [{ color: "#6b9a76" }],
                        },
                        {
                            featureType: "road",
                            elementType: "geometry",
                            stylers: [{ color: "#38414e" }],
                        },
                        {
                            featureType: "road",
                            elementType: "geometry.stroke",
                            stylers: [{ color: "#212a37" }],
                        },
                        {
                            featureType: "road",
                            elementType: "labels.text.fill",
                            stylers: [{ color: "#9ca5b3" }],
                        },
                        {
                            featureType: "road.highway",
                            elementType: "geometry",
                            stylers: [{ color: "#746855" }],
                        },
                        {
                            featureType: "road.highway",
                            elementType: "geometry.stroke",
                            stylers: [{ color: "#1f2835" }],
                        },
                        {
                            featureType: "road.highway",
                            elementType: "labels.text.fill",
                            stylers: [{ color: "#f3d19c" }],
                        },
                        {
                            featureType: "transit",
                            elementType: "geometry",
                            stylers: [{ color: "#2f3948" }],
                        },
                        {
                            featureType: "transit.station",
                            elementType: "labels.text.fill",
                            stylers: [{ color: "#d59563" }],
                        },
                        {
                            featureType: "water",
                            elementType: "geometry",
                            stylers: [{ color: "#17263c" }],
                        },
                        {
                            featureType: "water",
                            elementType: "labels.text.fill",
                            stylers: [{ color: "#515c6d" }],
                        },
                        {
                            featureType: "water",
                            elementType: "labels.text.stroke",
                            stylers: [{ color: "#17263c" }],
                        },
                    ]
                });

                const marker = new google.maps.Marker({
                    position: gymLocation,
                    map: map,
                    title: "<?php echo addslashes($gym['name']); ?>"
                });

                const infoWindow = new google.maps.InfoWindow({
                    content: `
                    <div style="color: #333; padding: 5px;">
                        <strong><?php echo addslashes($gym['name']); ?></strong><br>
                        <?php echo addslashes($gym['address']); ?><br>
                        <?php echo addslashes($gym['city'] . ', ' . $gym['state']); ?>
                    </div>
                `
                });

                marker.addListener("click", () => {
                    infoWindow.open(map, marker);
                });
            }
        <?php endif; ?>

        // Lightbox functionality
        function openLightbox(imagePath) {
            const lightbox = document.getElementById('lightbox');
            const lightboxImage = document.getElementById('lightbox-image');

            lightboxImage.src = './uploads/gym_images/' + imagePath;
            lightbox.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        document.getElementById('close-lightbox').addEventListener('click', function () {
            document.getElementById('lightbox').classList.add('hidden');
            document.body.style.overflow = '';
        });

        // Share functionality
        function shareGym() {
            document.getElementById('share-modal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        document.getElementById('close-share').addEventListener('click', function () {
            document.getElementById('share-modal').classList.add('hidden');
            document.body.style.overflow = '';
        });

        document.getElementById('copy-url').addEventListener('click', function () {
            const shareUrl = document.getElementById('share-url');
            shareUrl.select();
            document.execCommand('copy');

            // Show copied feedback
            this.textContent = 'Copied!';
            setTimeout(() => {
                this.textContent = 'Copy';
            }, 2000);
        });

        // Star rating functionality
        document.querySelectorAll('input[name="rating"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                const stars = document.querySelectorAll('label i.fa-star');
                const rating = parseInt(this.value);

                stars.forEach(function (star, index) {
                    if (index < rating) {
                        star.classList.remove('far');
                        star.classList.add('fas');
                    } else {
                        star.classList.remove('fas');
                        star.classList.add('far');
                    }
                });
            });
        });

        // Close modals when clicking outside
        window.addEventListener('click', function (event) {
            const lightbox = document.getElementById('lightbox');
            const shareModal = document.getElementById('share-modal');

            if (event.target === lightbox) {
                lightbox.classList.add('hidden');
                document.body.style.overflow = '';
            }

            if (event.target === shareModal) {
                shareModal.classList.add('hidden');
                document.body.style.overflow = '';
            }
        });

        // Escape key to close modals
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                document.getElementById('lightbox').classList.add('hidden');
                document.getElementById('share-modal').classList.add('hidden');
                document.body.style.overflow = '';
            }
        });

        // Booking date validation
        document.getElementById('visit_date').addEventListener('change', function () {
            const selectedDate = new Date(this.value);
            const today = new Date();
            today.setHours(0, 0, 0, 0);

            if (selectedDate < today) {
                alert('Please select today or a future date.');
                this.value = '';
            }
        });

        // Smooth scroll to sections
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();

                const targetId = this.getAttribute('href');
                if (targetId === '#') return;

                const targetElement = document.querySelector(targetId);
                if (targetElement) {
                    const headerOffset = 80;
                    const elementPosition = targetElement.getBoundingClientRect().top;
                    const offsetPosition = elementPosition + window.pageYOffset - headerOffset;

                    window.scrollTo({
                        top: offsetPosition,
                        behavior: 'smooth'
                    });
                }
            });
        });
    </script>

    <?php if ($gym['latitude'] && $gym['longitude']): ?>
        <script src="https://maps.googleapis.com/maps/api/js?key=YOUR_GOOGLE_MAPS_API_KEY&callback=initMap" async
            defer></script>
    <?php endif; ?>

    <?php include 'includes/footer.php'; ?>
</body>

</html>