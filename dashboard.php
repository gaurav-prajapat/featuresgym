<?php
ob_start();
require 'config/database.php';
include 'includes/navbar.php';


if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$db = new GymDatabase();
$conn = $db->getConnection();

// Fetch upcoming classes
$stmt = $conn->prepare("
    SELECT c.*, g.name as gym_name 
    FROM class_bookings cb 
    JOIN gym_classes c ON cb.class_id = c.id 
    JOIN gyms g ON c.gym_id = g.gym_id 
    WHERE cb.user_id = :user_id AND cb.status = 'booked'
    ORDER BY c.schedule ASC LIMIT 5
");
$stmt->bindParam(':user_id', $user_id);
$stmt->execute();
$upcoming_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch upcoming schedules
$stmt = $conn->prepare("
    SELECT s.*, g.name as gym_name 
    FROM schedules s 
    JOIN gyms g ON s.gym_id = g.gym_id 
    WHERE s.user_id = :user_id AND s.status = 'scheduled' AND s.start_date >= CURDATE()
    ORDER BY s.start_date ASC, s.start_date ASC LIMIT 5
");
$stmt->bindParam(':user_id', $user_id);
$stmt->execute();
$upcoming_schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);


// Fetch recent schedules
$stmt = $conn->prepare("
    SELECT s.*, g.name as gym_name 
    FROM schedules s 
    JOIN gyms g ON s.gym_id = g.gym_id 
    WHERE s.user_id = :user_id AND s.status IN ('completed', 'missed')
    ORDER BY s.start_date DESC, s.start_date DESC LIMIT 5
");
$stmt->bindParam(':user_id', $user_id);
$stmt->execute();
$recent_schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch active memberships with more details
$membershipStmt = $conn->prepare("
    SELECT um.*, g.name as gym_name, g.cover_photo, g.city, g.state, 
           gmp.plan_name, gmp.tier, gmp.duration, gmp.inclusions
    FROM user_memberships um
    JOIN gyms g ON um.gym_id = g.gym_id
    JOIN gym_membership_plans gmp ON um.plan_id = gmp.plan_id
    WHERE um.user_id = ? AND um.status = 'active'
    ORDER BY um.created_at DESC
");
$membershipStmt->execute([$user_id]);
$active_memberships = $membershipStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch recently purchased memberships (within last 30 days)
$recentMembershipStmt = $conn->prepare("
    SELECT um.*, g.name as gym_name, g.cover_photo, g.city, g.state, 
           gmp.plan_name, gmp.tier, gmp.duration, gmp.inclusions
    FROM user_memberships um
    JOIN gyms g ON um.gym_id = g.gym_id
    JOIN gym_membership_plans gmp ON um.plan_id = gmp.plan_id
    WHERE um.user_id = ? AND um.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ORDER BY um.created_at DESC
    LIMIT 3
");
$recentMembershipStmt->execute([$user_id]);
$recent_memberships = $recentMembershipStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch user's workout statistics
$workoutStmt = $conn->prepare("
    SELECT 
        COUNT(*) AS total_workouts,
        COUNT(DISTINCT DATE(start_date)) AS active_days,
        SUM(TIMESTAMPDIFF(MINUTE, check_in_time, check_out_time)) AS total_minutes
    FROM schedules
    WHERE user_id = ? AND status = 'completed'
");
$workoutStmt->execute([$user_id]);
$workoutStats = $workoutStmt->fetch(PDO::FETCH_ASSOC);

// Get achievement count (this would be from a separate achievements system)
// For now, we'll calculate based on activity milestones
$achievements = 0;
if ($workoutStats['total_workouts'] >= 5) $achievements++;
if ($workoutStats['total_workouts'] >= 10) $achievements++;
if ($workoutStats['total_workouts'] >= 25) $achievements++;
if ($workoutStats['active_days'] >= 5) $achievements++;
if ($workoutStats['active_days'] >= 10) $achievements++;
if ($workoutStats['active_days'] >= 20) $achievements++;
if (isset($active_memberships) && count($active_memberships) >= 1) $achievements++;

?>
<div class="min-h-screen bg-gradient-to-b from-gray-900 to-black py-12">
    <div class="container mx-auto px-4 sm:px-6 lg:px-8">
        <!-- Hero Section -->
        <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl overflow-hidden mb-8">
            <div class="p-6 bg-gradient-to-r from-yellow-400 to-yellow-500">
                <h1 class="text-4xl font-bold text-gray-900 text-center">Welcome to Your Fitness Hub</h1>
                <p class="text-lg text-gray-800 text-center mt-2">Track your schedules, book classes, and achieve your fitness goals with ease!</p>
            </div>
        </div>

        <!-- Quick Stats -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6 mb-8">
            <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl overflow-hidden transform hover:scale-[1.02] transition-transform duration-300">
                <div class="p-6">
                    <div class="flex items-center">
                        <div class="p-3 bg-yellow-400 rounded-xl">
                            <i class="fas fa-dumbbell text-gray-900 text-2xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-yellow-400 text-sm">Total Workouts</p>
                            <p class="text-2xl font-bold text-white"><?= $workoutStats['total_workouts'] ?? 0 ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl overflow-hidden transform hover:scale-[1.02] transition-transform duration-300">
                <div class="p-6">
                    <div class="flex items-center">
                        <div class="p-3 bg-yellow-400 rounded-xl">
                            <i class="fas fa-calendar-check text-gray-900 text-2xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-yellow-400 text-sm">Active Days</p>
                            <p class="text-2xl font-bold text-white"><?= $workoutStats['active_days'] ?? 0 ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl overflow-hidden transform hover:scale-[1.02] transition-transform duration-300">
                <div class="p-6">
                    <div class="flex items-center">
                        <div class="p-3 bg-yellow-400 rounded-xl">
                            <i class="fas fa-clock text-gray-900 text-2xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-yellow-400 text-sm">Total Time</p>
                            <p class="text-2xl font-bold text-white">
                                <?php
                                $hours = floor(($workoutStats['total_minutes'] ?? 0) / 60);
                                $minutes = ($workoutStats['total_minutes'] ?? 0) % 60;
                                echo $hours > 0 ? "{$hours}h {$minutes}m" : "{$minutes}m";
                                ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl overflow-hidden transform hover:scale-[1.02] transition-transform duration-300">
                <div class="p-6">
                    <div class="flex items-center">
                        <div class="p-3 bg-yellow-400 rounded-xl">
                            <i class="fas fa-trophy text-gray-900 text-2xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-yellow-400 text-sm">Achievements</p>
                            <p class="text-2xl font-bold text-white"><?= $achievements ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Active Memberships Section -->
        <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl overflow-hidden mb-8">
            <div class="p-6 bg-gradient-to-r from-yellow-400 to-yellow-500">
                <div class="flex justify-between items-center">
                    <h2 class="text-2xl font-bold text-gray-900">Your Active Memberships</h2>
                    <a href="view_membership.php" class="text-gray-900 hover:text-gray-800 transition-colors duration-300">
                        <i class="fas fa-id-card mr-2"></i>View All Memberships
                    </a>
                </div>
            </div>

            <div class="p-6">
                <?php if ($active_memberships): ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <?php foreach ($active_memberships as $membership): ?>
                            <div class="bg-gray-700 rounded-xl overflow-hidden hover:shadow-lg transition-all duration-300">
                                <!-- Membership Header with Gym Image -->
                                <div class="h-40 relative">
                                    <?php 
                                    $image_src = !empty($membership['cover_photo']) 
                                        ? 'gym/uploads/gym_images/' . $membership['cover_photo'] 
                                        : 'assets/images/default-gym.jpg';
                                    ?>
                                    <img src="<?= $image_src ?>" alt="<?= htmlspecialchars($membership['gym_name']) ?>" 
                                        class="w-full h-full object-cover">
                                    
                                    <!-- Tier Badge -->
                                    <div class="absolute top-3 right-3 bg-yellow-400 text-black px-3 py-1 rounded-full text-sm font-bold">
                                        <?= htmlspecialchars($membership['tier']) ?>
                                    </div>
                                </div>
                                
                                <!-- Membership Details -->
                                <div class="p-6">
                                    <h3 class="font-semibold text-xl text-white mb-2">
                                        <?= htmlspecialchars($membership['plan_name']) ?>
                                    </h3>
                                    <p class="text-white mb-2">
                                        <i class="fas fa-dumbbell text-yellow-400 mr-2"></i>
                                        <?= htmlspecialchars($membership['gym_name']) ?>
                                    </p>
                                    <p class="text-white mb-2">
                                        <i class="fas fa-map-marker-alt text-yellow-400 mr-2"></i>
                                        <?= htmlspecialchars($membership['city']) ?>, <?= htmlspecialchars($membership['state']) ?>
                                    </p>
                                    <p class="text-white mb-4">
                                        <i class="fas fa-calendar-alt text-yellow-400 mr-2"></i>
                                        Valid until: <?= date('M j, Y', strtotime($membership['end_date'])) ?>
                                    </p>
                                    
                                    <!-- Progress Bar -->
                                    <?php
                                    $start = strtotime($membership['start_date']);
                                    $end = strtotime($membership['end_date']);
                                    $now = time();
                                    $total = $end - $start;
                                    $elapsed = $now - $start;
                                    $percent = min(100, max(0, ($elapsed / $total) * 100));
                                    $daysLeft = ceil(($end - $now) / (60 * 60 * 24));
                                    ?>
                                    <div class="w-full bg-gray-600 rounded-full h-2.5 mb-2">
                                        <div class="bg-yellow-400 h-2.5 rounded-full" style="width: <?= $percent ?>%"></div>
                                    </div>
                                    <p class="text-sm text-gray-300 mb-4">
                                        <?= $daysLeft ?> days remaining
                                    </p>
                                    
                                    <!-- Action Buttons -->
                                    <div class="flex justify-between">
                                        <a href="gym-profile.php?id=<?= $membership['gym_id'] ?>" class="bg-gray-600 hover:bg-gray-500 text-white px-4 py-2 rounded-lg transition-colors duration-300">
                                            <i class="fas fa-eye mr-2"></i>Visit Gym
                                        </a>
                                        <a href="schedule.php?gym_id=<?= $membership['gym_id'] ?>" class="bg-yellow-500 hover:bg-yellow-400 text-black px-4 py-2 rounded-lg transition-colors duration-300">
                                            <i class="fas fa-calendar-plus mr-2"></i>Schedule
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-8">
                        <div class="bg-gray-700 rounded-xl p-8 inline-block mb-4">
                            <i class="fas fa-id-card text-yellow-400 text-5xl"></i>
                        </div>
                        <h3 class="text-xl font-semibold text-white mb-2">No Active Memberships</h3>
                        <p class="text-gray-300 mb-6">You don't have any active gym memberships at the moment. Explore our gyms and find the perfect fit for your fitness journey.</p>
                        <a href="all-gyms.php" class="bg-yellow-500 hover:bg-yellow-400 text-black px-6 py-3 rounded-full transition-colors duration-300">
                            <i class="fas fa-search mr-2"></i>Find Gyms
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recently Purchased Memberships -->
        <?php if ($recent_memberships && count($recent_memberships) > 0): ?>
        <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl overflow-hidden mb-8">
            <div class="p-6 bg-gradient-to-r from-purple-500 to-indigo-600">
                <div class="flex justify-between items-center">
                    <h2 class="text-2xl font-bold text-white">Recently Purchased</h2>
                    <span class="text-white text-sm">
                        <i class="fas fa-clock mr-2"></i>Last 30 days
                    </span>
                </div>
            </div>

            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php foreach ($recent_memberships as $membership): ?>
                        <?php if ($membership['status'] != 'active'): // Skip if already shown in active memberships ?>
                        <div class="bg-gray-700 rounded-xl overflow-hidden hover:shadow-lg transition-all duration-300">
                            <!-- Membership Header with Gym Image -->
                            <div class="h-40 relative">
                                <?php 
                                $image_src = !empty($membership['cover_photo']) 
                                    ? 'gym/uploads/gym_images/' . $membership['cover_photo'] 
                                    : 'assets/images/default-gym.jpg';
                                ?>
                                <img src="<?= $image_src ?>" alt="<?= htmlspecialchars($membership['gym_name']) ?>" 
                                    class="w-full h-full object-cover">
                                
                                <!-- Status Badge -->
                                <div class="absolute top-3 right-3 
                                    <?= $membership['status'] == 'active' ? 'bg-green-500' : 
                                        ($membership['status'] == 'expired' ? 'bg-red-500' : 'bg-gray-500') ?> 
                                    text-white px-3 py-1 rounded-full text-sm font-bold">
                                    <?= ucfirst(htmlspecialchars($membership['status'])) ?>
                                </div>
                            </div>
                            
                            <!-- Membership Details -->
                            <div class="p-6">
                                <h3 class="font-semibold text-xl text-white mb-2">
                                    <?= htmlspecialchars($membership['plan_name']) ?>
                                </h3>
                                <p class="text-white mb-2">
                                    <i class="fas fa-dumbbell text-purple-400 mr-2"></i>
                                    <?= htmlspecialchars($membership['gym_name']) ?>
                                </p>
                                <p class="text-white mb-2">
                                    <i class="fas fa-calendar-alt text-purple-400 mr-2"></i>
                                    Purchased: <?= date('M j, Y', strtotime($membership['created_at'])) ?>
                                </p>
                                <p class="text-white mb-4">
                                    <i class="fas fa-rupee-sign text-purple-400 mr-2"></i>
                                    <?= number_format($membership['amount'], 2) ?>
                                </p>
                                
                                <!-- Action Buttons -->
                                <div class="flex justify-between">
                                    <a href="gym-profile.php?id=<?= $membership['gym_id'] ?>" class="bg-gray-600 hover:bg-gray-500 text-white px-4 py-2 rounded-lg transition-colors duration-300">
                                        <i class="fas fa-eye mr-2"></i>Visit Gym
                                    </a>
                                    <a href="view_membership.php?id=<?= $membership['id'] ?>" class="bg-purple-500 hover:bg-purple-400 text-white px-4 py-2 rounded-lg transition-colors duration-300">
                                        <i class="fas fa-info-circle mr-2"></i>Details
                                    </a>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Upcoming Schedules Section -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
            <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl overflow-hidden">
                <div class="p-6 bg-gradient-to-r from-blue-500 to-blue-600">
                    <div class="flex justify-between items-center">
                        <h2 class="text-2xl font-bold text-white">Upcoming Schedules</h2>
                        <a href="my_schedules.php" class="text-white hover:text-blue-100 transition-colors duration-300">
                            <i class="fas fa-calendar-alt mr-2"></i>View All
                        </a>
                    </div>
                </div>

                <div class="p-6">
                    <?php if ($upcoming_schedules): ?>
                        <div class="space-y-4">
                            <?php foreach ($upcoming_schedules as $schedule): ?>
                                <div class="bg-gray-700 rounded-xl p-4 hover:bg-gray-600 transition-colors duration-300">
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <h3 class="font-semibold text-white"><?= htmlspecialchars($schedule['gym_name']) ?></h3>
                                            <p class="text-gray-300 text-sm">
                                                <i class="fas fa-calendar text-blue-400 mr-2"></i>
                                                <?= date('M j, Y', strtotime($schedule['start_date'])) ?>
                                            </p>
                                            <p class="text-gray-300 text-sm">
                                                <i class="fas fa-clock text-blue-400 mr-2"></i>
                                                <?= date('g:i A', strtotime($schedule['start_time'])) ?>
                                            </p>
                                        </div>
                                        <a href="view_schedule.php?id=<?= $schedule['id'] ?>" class="bg-blue-500 hover:bg-blue-400 text-white px-3 py-1 rounded-lg text-sm transition-colors duration-300">
                                            <i class="fas fa-eye mr-1"></i> View
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-8">
                            <div class="bg-gray-700 rounded-xl p-6 inline-block mb-4">
                                <i class="fas fa-calendar-alt text-blue-400 text-4xl"></i>
                            </div>
                            <h3 class="text-xl font-semibold text-white mb-2">No Upcoming Schedules</h3>
                            <p class="text-gray-300 mb-4">You don't have any upcoming gym sessions scheduled.</p>
                            <a href="schedule.php" class="bg-blue-500 hover:bg-blue-400 text-white px-4 py-2 rounded-lg transition-colors duration-300">
                                <i class="fas fa-plus mr-2"></i>Schedule a Session
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Activity Section -->
            <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl overflow-hidden">
                <div class="p-6 bg-gradient-to-r from-green-500 to-green-600">
                    <div class="flex justify-between items-center">
                        <h2 class="text-2xl font-bold text-white">Recent Activity</h2>
                        <a href="activity_history.php" class="text-white hover:text-green-100 transition-colors duration-300">
                            <i class="fas fa-history mr-2"></i>View History
                        </a>
                    </div>
                </div>

                <div class="p-6">
                    <?php if ($recent_schedules): ?>
                        <div class="space-y-4">
                            <?php foreach ($recent_schedules as $schedule): ?>
                                <div class="bg-gray-700 rounded-xl p-4 hover:bg-gray-600 transition-colors duration-300">
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <h3 class="font-semibold text-white"><?= htmlspecialchars($schedule['gym_name']) ?></h3>
                                            <p class="text-gray-300 text-sm">
                                                <i class="fas fa-calendar text-green-400 mr-2"></i>
                                                <?= date('M j, Y', strtotime($schedule['start_date'])) ?>
                                            </p>
                                            <p class="text-gray-300 text-sm">
                                                <i class="fas fa-clock text-green-400 mr-2"></i>
                                                <?= date('g:i A', strtotime($schedule['start_time'])) ?>
                                            </p>
                                        </div>
                                        <div>
                                            <span class="inline-block px-3 py-1 rounded-full text-xs font-semibold 
                                                <?= $schedule['status'] == 'completed' ? 'bg-green-200 text-green-800' : 'bg-red-200 text-red-800' ?>">
                                                <?= ucfirst($schedule['status']) ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-8">
                            <div class="bg-gray-700 rounded-xl p-6 inline-block mb-4">
                                <i class="fas fa-history text-green-400 text-4xl"></i>
                            </div>
                            <h3 class="text-xl font-semibold text-white mb-2">No Recent Activity</h3>
                            <p class="text-gray-300 mb-4">You haven't completed any gym sessions yet.</p>
                            <a href="all-gyms.php" class="bg-green-500 hover:bg-green-400 text-white px-4 py-2 rounded-lg transition-colors duration-300">
                                <i class="fas fa-dumbbell mr-2"></i>Find a Gym
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Explore More Section -->
        <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl overflow-hidden">
            <div class="p-6 bg-gradient-to-r from-yellow-400 to-yellow-500">
                <h2 class="text-2xl font-bold text-gray-900 text-center">Explore More</h2>
            </div>

            <div class="p-6">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                    <a href="all-gyms.php" class="bg-gray-700 rounded-xl p-6 text-center hover:bg-gray-600 transition-colors duration-300">
                        <div class="bg-yellow-400 w-16 h-16 mx-auto rounded-full flex items-center justify-center mb-4">
                            <i class="fas fa-dumbbell text-gray-900 text-2xl"></i>
                        </div>
                        <h3 class="text-xl font-semibold text-white mb-2">Find Gyms</h3>
                        <p class="text-gray-300">Discover top-rated gyms in your area</p>
                    </a>

                    <a href="#" class="bg-gray-700 rounded-xl p-6 text-center hover:bg-gray-600 transition-colors duration-300">
                        <div class="bg-yellow-400 w-16 h-16 mx-auto rounded-full flex items-center justify-center mb-4">
                            <i class="fas fa-user-friends text-gray-900 text-2xl"></i>
                        </div>
                        <h3 class="text-xl font-semibold text-white mb-2">Trainers</h3>
                        <p class="text-gray-300">Connect with professional fitness trainers</p>
                        <p>upcoming</p>
                    </a>

                    <a href="tournaments.php" class="bg-gray-700 rounded-xl p-6 text-center hover:bg-gray-600 transition-colors duration-300">
                        <div class="bg-yellow-400 w-16 h-16 mx-auto rounded-full flex items-center justify-center mb-4">
                            <i class="fas fa-trophy text-gray-900 text-2xl"></i>
                        </div>
                        <h3 class="text-xl font-semibold text-white mb-2">Tournaments</h3>
                        <p class="text-gray-300">Join fitness competitions and challenges</p>
                    </a>

                    <a href="profile.php" class="bg-gray-700 rounded-xl p-6 text-center hover:bg-gray-600 transition-colors duration-300">
                        <div class="bg-yellow-400 w-16 h-16 mx-auto rounded-full flex items-center justify-center mb-4">
                            <i class="fas fa-user-cog text-gray-900 text-2xl"></i>
                        </div>
                        <h3 class="text-xl font-semibold text-white mb-2">Profile</h3>
                        <p class="text-gray-300">Update your profile and preferences</p>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

