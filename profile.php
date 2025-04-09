<?php
require_once 'config/database.php';
include 'includes/navbar.php';


if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$db = new GymDatabase();
$conn = $db->getConnection();
$user_id = $_SESSION['user_id'];

// Fetch user details
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch active memberships
$membershipStmt = $conn->prepare("
    SELECT * FROM user_memberships 
    WHERE user_id = ? AND status = 'active'
");
$membershipStmt->execute([$user_id]);
$active_memberships = $membershipStmt->fetchAll(PDO::FETCH_ASSOC);
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
<div class="min-h-screen bg-gradient-to-b from-gray-900 to-black py-12 sm:py-16 lg:py-20">
    <div class="container mx-auto px-4 sm:px-6 lg:px-8">
        <h1 class="text-3xl font-bold text-white mb-8 text-center">My Profile</h1>

        <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl overflow-hidden transform hover:scale-[1.02] transition-transform duration-300">
            <!-- Header Section with Yellow Gradient -->
<div class="p-6 bg-gradient-to-r from-yellow-400 to-yellow-500 rounded-lg shadow-md">
    <div class="flex flex-col md:flex-row items-center justify-between">
        
        <!-- User Info -->
        <div class="flex items-center gap-6">
            <!-- Profile Image -->
            <div class="w-28 h-28 rounded-full border-4 border-gray-900 overflow-hidden shadow-lg">
                <img src="<?= htmlspecialchars($user['profile_image'] ?? 'assets/images/default-profile.png') ?>" 
                     alt="Profile" 
                     class="w-full h-full object-cover">
            </div>

            <!-- User Details -->
            <div>
                <h2 class="text-2xl font-semibold text-gray-900"><?= htmlspecialchars($user['username']) ?></h2>
                <p class="text-gray-700 text-lg"><?= ucfirst(htmlspecialchars($user['role'])) ?></p>
            </div>

            <!-- Membership Date -->
            <div class="text-center md:text-left">
                <h2 class="text-lg font-semibold text-gray-900">Member Since</h2>
                <p class="text-black text-lg font-medium"><?= date('d M Y', strtotime($user['created_at'])) ?></p>
            </div>
        </div>

        <!-- Status Badge -->
        <span class="px-5 py-2 rounded-full text-sm font-medium bg-green-900 text-green-100 shadow-md">
            <?= ucfirst(htmlspecialchars($user['status'])) ?>
        </span>

    </div>
</div>


            <!-- Personal Information Section -->
            <div class="p-6">
                <h3 class="text-xl font-bold text-yellow-400 mb-6">Personal Information</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="space-y-4">
                        <div>
                            <label class="text-yellow-400 text-sm">Email</label>
                            <p class="text-white text-lg"><?= htmlspecialchars($user['email']) ?></p>
                        </div>
                        <div>
                            <label class="text-yellow-400 text-sm">Phone</label>
                            <p class="text-white text-lg"><?= htmlspecialchars($user['phone'] ?? 'Not provided') ?></p>
                        </div>
                    </div>
                    <div class="space-y-4">
                        <div>
                            <label class="text-yellow-400 text-sm">Member Since</label>
                            <p class="text-white text-lg"><?= date('d M Y', strtotime($user['created_at'])) ?></p>
                        </div>
                        <div>
                            <label class="text-yellow-400 text-sm">Balance</label>
                            <p class="text-2xl font-bold text-white">₹<?= number_format($user['balance'], 2) ?></p>
                        </div>
                    </div>
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

            <!-- Action Buttons -->
            <div class="p-6 flex justify-end space-x-4 border-t border-gray-700">
                <a href="edit_profile.php" 
                   class="bg-yellow-400 text-black px-6 py-3 rounded-full font-bold hover:bg-yellow-500 transform hover:scale-105 transition-all duration-300">
                    Edit Profile
                </a>
                <a href="change_password.php" 
                   class="bg-gray-600 text-white px-6 py-3 rounded-full font-bold hover:bg-gray-700 transform hover:scale-105 transition-all duration-300">
                    Change Password
                </a>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
