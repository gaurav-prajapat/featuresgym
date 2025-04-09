<?php
include 'includes/navbar.php';
require_once 'config/database.php';

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Get membership ID from URL
$membership_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$membership_id) {
    header('Location: dashboard.php');
    exit();
}

// Fetch membership details
$db = new GymDatabase();
$conn = $db->getConnection();

$stmt = $conn->prepare("
    SELECT 
        um.*, 
        g.name as gym_name,
        gmp.plan_name,
        gmp.duration
    FROM user_memberships um
    JOIN gyms g ON um.gym_id = g.gym_id
    JOIN gym_membership_plans gmp ON um.plan_id = gmp.plan_id
    WHERE um.id = ? AND um.user_id = ?
");
$stmt->execute([$membership_id, $_SESSION['user_id']]);
$membership = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$membership) {
    header('Location: dashboard.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Membership Confirmed</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body class="bg-gray-900 text-white">

<div class="min-h-screen py-12">
    <div class="max-w-3xl mx-auto px-4">
        <div class="bg-gray-800 rounded-2xl shadow-xl overflow-hidden">
            <div class="p-8">
                <div class="text-center mb-8">
                    <div class="inline-flex items-center justify-center w-20 h-20 bg-green-500 rounded-full mb-4">
                        <i class="fas fa-check text-white text-4xl"></i>
                    </div>
                    <h1 class="text-3xl font-bold text-white">Membership Confirmed!</h1>
                    <p class="text-gray-400 mt-2">Your membership has been successfully activated.</p>
                </div>

                <div class="bg-gray-700 rounded-xl p-6 mb-8">
                    <h2 class="text-xl font-semibold text-white mb-4">Membership Details</h2>
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <div class="text-sm text-gray-400">Gym</div>
                            <div class="font-semibold text-white"><?php echo htmlspecialchars($membership['gym_name']); ?></div>
                        </div>
                        <div>
                            <div class="text-sm text-gray-400">Plan</div>
                            <div class="font-semibold text-white"><?php echo htmlspecialchars($membership['plan_name']); ?></div>
                        </div>
                        <div>
                            <div class="text-sm text-gray-400">Duration</div>
                            <div class="font-semibold text-white"><?php echo htmlspecialchars($membership['duration']); ?></div>
                        </div>
                        <div>
                            <div class="text-sm text-gray-400">Status</div>
                            <div class="font-semibold text-green-400">Active</div>
                        </div>
                        <div>
                            <div class="text-sm text-gray-400">Start Date</div>
                            <div class="font-semibold text-white"><?php echo date('d M Y', strtotime($membership['start_date'])); ?></div>
                        </div>
                        <div>
                            <div class="text-sm text-gray-400">End Date</div>
                            <div class="font-semibold text-white"><?php echo date('d M Y', strtotime($membership['end_date'])); ?></div>
                        </div>
                    </div>
                </div>

                <div class="flex justify-center space-x-4">
                    <a href="view_membership.php" class="bg-yellow-500 text-black px-6 py-3 rounded-lg font-medium hover:bg-yellow-400 transition-colors duration-200">
                        View My Memberships
                    </a>
                    <a href="dashboard.php" class="bg-gray-600 text-white px-6 py-3 rounded-lg font-medium hover:bg-gray-500 transition-colors duration-200">
                        Go to Dashboard
                    </a>
                </div>
            </div>
        </div>
        
        <div class="mt-8 text-center">
            <h3 class="text-xl font-semibold text-white mb-4">What's Next?</h3>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="bg-gray-800 p-5 rounded-xl">
                    <div class="text-yellow-400 text-3xl mb-3">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <h4 class="font-semibold text-white mb-2">Schedule Your Visit</h4>
                    <p class="text-gray-400 text-sm">Book your gym sessions in advance to secure your spot.</p>
                    <a href="schedule.php" class="inline-block mt-3 text-yellow-400 hover:underline">Schedule Now</a>
                </div>
                
                <div class="bg-gray-800 p-5 rounded-xl">
                    <div class="text-yellow-400 text-3xl mb-3">
                        <i class="fas fa-dumbbell"></i>
                    </div>
                    <h4 class="font-semibold text-white mb-2">Explore Facilities</h4>
                    <p class="text-gray-400 text-sm">Check out all the equipment and amenities available at your gym.</p>
                    <a href="gym-profile.php?id=<?php echo $membership['gym_id']; ?>" class="inline-block mt-3 text-yellow-400 hover:underline">View Gym</a>
                </div>
                
                <div class="bg-gray-800 p-5 rounded-xl">
                    <div class="text-yellow-400 text-3xl mb-3">
                        <i class="fas fa-user-circle"></i>
                    </div>
                    <h4 class="font-semibold text-white mb-2">Complete Your Profile</h4>
                    <p class="text-gray-400 text-sm">Update your fitness goals and preferences for a better experience.</p>
                    <a href="profile.php" class="inline-block mt-3 text-yellow-400 hover:underline">Update Profile</a>
                </div>
            </div>
        </div>
    </div>
</div>

</body>
</html>
