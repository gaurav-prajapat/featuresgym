<?php
require '../config/database.php';
include '../includes/navbar.php';

if (!isset($_SESSION['owner_id'])) {
    header('Location: login.php');
    exit;
}

$gymOwnerId = $_SESSION['owner_id'];
$db = new GymDatabase();
$conn = $db->getConnection();

// Initialize variables
$member = null;
$memberships = [];
$schedules = [];
$payments = [];
$stats = [];
$error = null;

// Check if member ID is provided
if (isset($_GET['id']) && !empty($_GET['id'])) {
    $memberId = (int) $_GET['id'];

    try {
        // Start transaction for better performance with multiple queries
        $conn->beginTransaction();

        // Verify the member belongs to one of the owner's gyms
        $stmt = $conn->prepare("
            SELECT u.*, 
                   (SELECT COUNT(*) FROM user_memberships WHERE user_id = u.id) as membership_count,
                   (SELECT COUNT(*) FROM schedules WHERE user_id = u.id) as schedule_count,
                   (SELECT COUNT(*) FROM schedules WHERE user_id = u.id AND status = 'completed') as completed_workouts,
                   (SELECT SUM(amount) FROM payments WHERE user_id = u.id) as total_spent
            FROM users u
            WHERE u.id = :member_id
            AND EXISTS (
                SELECT 1 FROM user_memberships um 
                JOIN gyms g ON um.gym_id = g.gym_id 
                WHERE um.user_id = u.id AND g.owner_id = :owner_id
            )
        ");

        $stmt->bindParam(':member_id', $memberId, PDO::PARAM_INT);
        $stmt->bindParam(':owner_id', $gymOwnerId, PDO::PARAM_INT);
        $stmt->execute();
        $member = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$member) {
            throw new Exception("Member not found or doesn't belong to your gyms.");
        }

        // Get member's active and past memberships
        $stmt = $conn->prepare("
            SELECT um.*, 
                   g.name as gym_name, 
                   g.gym_id,
                   gmp.plan_name, 
                   gmp.duration, 
                   gmp.tier
            FROM user_memberships um
            JOIN gyms g ON um.gym_id = g.gym_id
            JOIN gym_membership_plans gmp ON um.plan_id = gmp.plan_id
            WHERE um.user_id = :member_id
            AND g.owner_id = :owner_id
            ORDER BY um.start_date DESC
            LIMIT 10
        ");

        $stmt->bindParam(':member_id', $memberId, PDO::PARAM_INT);
        $stmt->bindParam(':owner_id', $gymOwnerId, PDO::PARAM_INT);
        $stmt->execute();
        $memberships = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get recent schedules
        $stmt = $conn->prepare("
            SELECT s.*, g.name as gym_name
            FROM schedules s
            JOIN gyms g ON s.gym_id = g.gym_id
            WHERE s.user_id = :member_id
            AND g.owner_id = :owner_id
            ORDER BY s.start_date DESC, s.start_time DESC
            LIMIT 5
        ");

        $stmt->bindParam(':member_id', $memberId, PDO::PARAM_INT);
        $stmt->bindParam(':owner_id', $gymOwnerId, PDO::PARAM_INT);
        $stmt->execute();
        $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get recent payments
        $stmt = $conn->prepare("
            SELECT p.*, g.name as gym_name
            FROM payments p
            JOIN gyms g ON p.gym_id = g.gym_id
            WHERE p.user_id = :member_id
            AND g.owner_id = :owner_id
            ORDER BY p.payment_date DESC
            LIMIT 5
        ");

        $stmt->bindParam(':member_id', $memberId, PDO::PARAM_INT);
        $stmt->bindParam(':owner_id', $gymOwnerId, PDO::PARAM_INT);
        $stmt->execute();
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get member statistics
        $stmt = $conn->prepare("
            SELECT 
                COUNT(DISTINCT CASE WHEN s.status = 'completed' THEN s.id END) as completed_workouts,
                COUNT(DISTINCT CASE WHEN s.status = 'missed' THEN s.id END) as missed_workouts,
                COUNT(DISTINCT CASE WHEN s.status = 'cancelled' THEN s.id END) as cancelled_workouts,
                AVG(TIMESTAMPDIFF(MINUTE, s.check_in_time, s.check_out_time)) as avg_workout_duration,
                MAX(s.start_date) as last_visit_date,
                (SELECT COUNT(*) FROM user_memberships WHERE user_id = :member_id) as total_memberships
            FROM schedules s
            JOIN gyms g ON s.gym_id = g.gym_id
            WHERE s.user_id = :member_id
            AND g.owner_id = :owner_id
        ");

        $stmt->bindParam(':member_id', $memberId, PDO::PARAM_INT);
        $stmt->bindParam(':owner_id', $gymOwnerId, PDO::PARAM_INT);
        $stmt->execute();
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        // Commit transaction
        $conn->commit();

    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollBack();
        $error = $e->getMessage();
    }
} else {
    $error = "Member ID is missing.";
}

// Calculate membership status
function getMembershipStatus($startDate, $endDate)
{
    $today = date('Y-m-d');

    if ($today < $startDate) {
        return ['status' => 'upcoming', 'class' => 'bg-blue-100 text-blue-800'];
    } elseif ($today <= $endDate) {
        return ['status' => 'active', 'class' => 'bg-green-100 text-green-800'];
    } else {
        return ['status' => 'expired', 'class' => 'bg-red-100 text-red-800'];
    }
}

// Format date
function formatDate($date)
{
    return date('d M Y', strtotime($date));
}

// Format time
function formatTime($time)
{
    return date('h:i A', strtotime($time));
}
?>


    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Member Details | Gym Management</title>
   
        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .custom-loader {
            border-top-color: #3498db;
            -webkit-animation: spinner 1.5s linear infinite;
            animation: spinner 1.5s linear infinite;
        }

        @-webkit-keyframes spinner {
            0% {
                -webkit-transform: rotate(0deg);
            }

            100% {
                -webkit-transform: rotate(360deg);
            }
        }

        @keyframes spinner {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }
    </style>


    <div class="container mx-auto px-4 py-20">
        <?php if ($error): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-lg shadow">
                <p class="font-bold">Error</p>
                <p><?php echo htmlspecialchars($error); ?></p>
                <a href="member_list.php"
                    class="mt-2 inline-block bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Members
                </a>
            </div>
        <?php elseif ($member): ?>
            <!-- Member Profile Header -->
            <div class="bg-white rounded-xl shadow-lg overflow-hidden mb-8">
                <div class="p-6 bg-gradient-to-r from-gray-900 to-gray-800">
                    <div class="flex flex-col md:flex-row items-center justify-between">
                        <div class="flex items-center mb-4 md:mb-0">
                            <div
                                class="h-20 w-20 rounded-full bg-yellow-500 flex items-center justify-center text-3xl text-white">
                                <?php echo strtoupper(substr($member['username'], 0, 1)); ?>
                            </div>
                            <div class="ml-6">
                                <h1 class="text-2xl font-bold text-white">
                                    <?php echo htmlspecialchars($member['username']); ?></h1>
                                <div class="flex flex-wrap items-center text-gray-300 mt-1">
                                    <p class="mr-4"><i
                                            class="fas fa-envelope mr-2"></i><?php echo htmlspecialchars($member['email']); ?>
                                    </p>
                                    <p><i
                                            class="fas fa-phone mr-2"></i><?php echo htmlspecialchars($member['phone'] ?? 'N/A'); ?>
                                    </p>
                                </div>
                                <p class="text-gray-400 mt-1"><i class="fas fa-calendar mr-2"></i>Joined:
                                    <?php echo formatDate($member['created_at']); ?></p>
                            </div>
                        </div>
                        <div class="flex space-x-2">
                            <a href="member_list.php" class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">
                                <i class="fas fa-arrow-left mr-2"></i>Back
                            </a>
                            <a href="send_message.php?id=<?php echo $member['id']; ?>"
                                class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
                                <i class="fas fa-envelope mr-2"></i>Message
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Member Stats -->
                <div class="grid grid-cols-2 md:grid-cols-4 divide-x divide-y md:divide-y-0 divide-gray-200">
                    <div class="p-6 text-center">
                        <p class="text-gray-500 text-sm">Memberships</p>
                        <p class="text-3xl font-bold text-gray-800"><?php echo $member['membership_count']; ?></p>
                    </div>
                    <div class="p-6 text-center">
                        <p class="text-gray-500 text-sm">Total Workouts</p>
                        <p class="text-3xl font-bold text-gray-800"><?php echo $member['schedule_count']; ?></p>
                    </div>
                    <div class="p-6 text-center">
                        <p class="text-gray-500 text-sm">Completed</p>
                        <p class="text-3xl font-bold text-gray-800"><?php echo $member['completed_workouts']; ?></p>
                    </div>
                    <div class="p-6 text-center">
                        <p class="text-gray-500 text-sm">Total Spent</p>
                        <p class="text-3xl font-bold text-gray-800">
                            ₹<?php echo number_format($member['total_spent'] ?? 0, 2); ?></p>
                    </div>
                </div>
            </div>

            <!-- Tabs Navigation -->
            <div class="mb-6 border-b border-gray-200">
                <ul class="flex flex-wrap -mb-px text-sm font-medium text-center">
                    <li class="mr-2">
                        <a href="#" class="tab-link active inline-block p-4 border-b-2 border-yellow-500 rounded-t-lg"
                            data-tab="overview">
                            <i class="fas fa-user mr-2"></i>Overview
                        </a>
                    </li>
                    <li class="mr-2">
                        <a href="#"
                            class="tab-link inline-block p-4 border-b-2 border-transparent hover:border-gray-300 rounded-t-lg"
                            data-tab="memberships">
                            <i class="fas fa-id-card mr-2"></i>Memberships
                        </a>
                    </li>
                    <li class="mr-2">
                        <a href="#"
                            class="tab-link inline-block p-4 border-b-2 border-transparent hover:border-gray-300 rounded-t-lg"
                            data-tab="schedules">
                            <i class="fas fa-calendar-alt mr-2"></i>Schedules
                        </a>
                    </li>
                    <li class="mr-2">
                        <a href="#"
                            class="tab-link inline-block p-4 border-b-2 border-transparent hover:border-gray-300 rounded-t-lg"
                            data-tab="payments">
                            <i class="fas fa-credit-card mr-2"></i>Payments
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Overview Tab -->
            <div id="overview-tab" class="tab-content active">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <!-- Member Details Card -->
                    <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                        <div class="p-4 bg-gray-50 border-b border-gray-200">
                            <h3 class="text-lg font-semibold text-gray-800">Member Details</h3>
                        </div>
                        <div class="p-6">
                            <div class="space-y-4">
                                <div>
                                    <p class="text-sm text-gray-500">Username</p>
                                    <p class="font-medium"><?php echo htmlspecialchars($member['username']); ?></p>
                                </div>
                                <div>
                                    <p class="text-sm text-gray-500">Email</p>
                                    <p class="font-medium"><?php echo htmlspecialchars($member['email']); ?></p>
                                </div>
                                <div>
                                    <p class="text-sm text-gray-500">Phone</p>
                                    <p class="font-medium">
                                        <?php echo htmlspecialchars($member['phone'] ?? 'Not provided'); ?></p>
                                </div>
                                <div>
                                    <p class="text-sm text-gray-500">City</p>
                                    <p class="font-medium">
                                        <?php echo htmlspecialchars($member['city'] ?? 'Not provided'); ?></p>
                                </div>
                                <div>
                                    <p class="text-sm text-gray-500">Age</p>
                                    <p class="font-medium">
                                        <?php echo $member['age'] ? htmlspecialchars($member['age']) : 'Not provided'; ?>
                                    </p>
                                </div>
                                <div>
                                    <p class="text-sm text-gray-500">Account Status</p>
                                    <span
                                        class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                        <?php echo $member['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                        <?php echo ucfirst(htmlspecialchars($member['status'])); ?>
                                    </span>
                                </div>
                                <div>
                                    <p class="text-sm text-gray-500">Member Since</p>
                                    <p class="font-medium"><?php echo formatDate($member['created_at']); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Activity Stats Card -->
                    <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                        <div class="p-4 bg-gray-50 border-b border-gray-200">
                            <h3 class="text-lg font-semibold text-gray-800">Activity Statistics</h3>
                        </div>
                        <div class="p-6">
                            <div class="space-y-4">
                                <div class="flex justify-between items-center">
                                    <p class="text-sm text-gray-500">Completed Workouts</p>
                                    <p class="font-medium text-green-600"><?php echo $stats['completed_workouts'] ?? 0; ?>
                                    </p>
                                </div>
                                <div class="flex justify-between items-center">
                                    <p class="text-sm text-gray-500">Missed Workouts</p>
                                    <p class="font-medium text-red-600"><?php echo $stats['missed_workouts'] ?? 0; ?></p>
                                </div>
                                <div class="flex justify-between items-center">
                                    <p class="text-sm text-gray-500">Cancelled Workouts</p>
                                    <p class="font-medium text-yellow-600"><?php echo $stats['cancelled_workouts'] ?? 0; ?>
                                    </p>
                                </div>
                                <div class="flex justify-between items-center">
                                    <p class="text-sm text-gray-500">Avg. Workout Duration</p>
                                    <p class="font-medium">
                                        <?php
                                        $avgDuration = $stats['avg_workout_duration'] ?? 0;
                                        echo $avgDuration ? round($avgDuration) . ' mins' : 'N/A';
                                        ?>
                                    </p>
                                </div>
                                <div class="flex justify-between items-center">
                                    <p class="text-sm text-gray-500">Last Visit</p>
                                    <p class="font-medium">
                                        <?php echo $stats['last_visit_date'] ? formatDate($stats['last_visit_date']) : 'Never'; ?>
                                    </p>
                                </div>
                                <div class="flex justify-between items-center">
                                    <p class="text-sm text-gray-500">Total Memberships</p>
                                    <p class="font-medium"><?php echo $stats['total_memberships'] ?? 0; ?></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Actions Card -->
                    <!-- <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                        <div class="p-4 bg-gray-50 border-b border-gray-200">
                            <h3 class="text-lg font-semibold text-gray-800">Quick Actions</h3>
                        </div>
                        <div class="p-6">
                            <div class="space-y-3">
                                <a href="member_schedules.php?id=<?php echo $member['id']; ?>"
                                    class="block w-full bg-blue-500 hover:bg-blue-600 text-white text-center px-4 py-2 rounded-lg">
                                    <i class="fas fa-calendar-alt mr-2"></i>View All Schedules
                                </a>
                                <a href="member_payments.php?id=<?php echo $member['id']; ?>"
                                    class="block w-full bg-green-500 hover:bg-green-600 text-white text-center px-4 py-2 rounded-lg">
                                    <i class="fas fa-credit-card mr-2"></i>View All Payments
                                </a>
                                <a href="add_schedule.php?member_id=<?php echo $member['id']; ?>"
                                    class="block w-full bg-purple-500 hover:bg-purple-600 text-white text-center px-4 py-2 rounded-lg">
                                    <i class="fas fa-plus mr-2"></i>Create Schedule
                                </a>
                                <a href="send_message.php?id=<?php echo $member['id']; ?>"
                                    class="block w-full bg-yellow-500 hover:bg-yellow-600 text-white text-center px-4 py-2 rounded-lg">
                                    <i class="fas fa-envelope mr-2"></i>Send Message
                                </a>
                                <a href="export_member_data.php?id=<?php echo $member['id']; ?>"
                                    class="block w-full bg-gray-500 hover:bg-gray-600 text-white text-center px-4 py-2 rounded-lg">
                                    <i class="fas fa-download mr-2"></i>Export Data
                                </a>
                            </div>
                        </div>
                    </div> -->
                </div>

                <!-- Recent Activity Section -->
                <div class="mt-8 bg-white rounded-xl shadow-lg overflow-hidden">
                    <div class="p-4 bg-gray-50 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-800">Recent Activity</h3>
                    </div>
                    <div class="p-6">
                        <div class="relative">
                            <!-- Activity Timeline -->
                            <div class="border-l-2 border-gray-200 ml-3">
                                <?php if (empty($schedules)): ?>
                                    <div class="ml-6 pb-4">
                                        <p class="text-gray-500">No recent activity found.</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($schedules as $index => $schedule):
                                        $statusClass = 'bg-gray-100 text-gray-800';
                                        $statusIcon = 'fa-calendar';

                                        if ($schedule['status'] === 'completed') {
                                            $statusClass = 'bg-green-100 text-green-800';
                                            $statusIcon = 'fa-check-circle';
                                        } elseif ($schedule['status'] === 'missed') {
                                            $statusClass = 'bg-red-100 text-red-800';
                                            $statusIcon = 'fa-times-circle';
                                        } elseif ($schedule['status'] === 'cancelled') {
                                            $statusClass = 'bg-yellow-100 text-yellow-800';
                                            $statusIcon = 'fa-ban';
                                        } elseif ($schedule['status'] === 'scheduled') {
                                            $statusClass = 'bg-blue-100 text-blue-800';
                                            $statusIcon = 'fa-calendar-check';
                                        }
                                        ?>
                                        <div class="ml-6 mb-6 relative">
                                            <!-- Timeline dot -->
                                            <div
                                                class="absolute -left-9 mt-1.5 w-4 h-4 rounded-full bg-gray-200 border-2 border-white">
                                            </div>

                                            <div class="p-4 bg-gray-50 rounded-lg">
                                                <div class="flex justify-between items-start">
                                                    <div>
                                                        <span
                                                            class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $statusClass; ?>">
                                                            <i class="fas <?php echo $statusIcon; ?> mr-1"></i>
                                                            <?php echo ucfirst($schedule['status']); ?>
                                                        </span>
                                                        <h4 class="text-sm font-medium text-gray-900 mt-1">
                                                            <?php echo htmlspecialchars($schedule['activity_type'] === 'gym_visit' ? 'Gym Visit' : ucfirst($schedule['activity_type'])); ?>
                                                            at <?php echo htmlspecialchars($schedule['gym_name']); ?>
                                                        </h4>
                                                    </div>
                                                    <span class="text-xs text-gray-500">
                                                        <?php echo formatDate($schedule['start_date']); ?> at
                                                        <?php echo formatTime($schedule['start_time']); ?>
                                                    </span>
                                                </div>

                                                <?php if (!empty($schedule['notes'])): ?>
                                                    <p class="text-sm text-gray-600 mt-2">
                                                        <?php echo htmlspecialchars($schedule['notes']); ?></p>
                                                <?php endif; ?>

                                                <?php if ($schedule['status'] === 'completed' && $schedule['check_in_time'] && $schedule['check_out_time']): ?>
                                                    <div class="mt-2 text-xs text-gray-500">
                                                        <span>Check-in:
                                                            <?php echo date('h:i A', strtotime($schedule['check_in_time'])); ?></span>
                                                        <span class="mx-2">•</span>
                                                        <span>Check-out:
                                                            <?php echo date('h:i A', strtotime($schedule['check_out_time'])); ?></span>
                                                        <span class="mx-2">•</span>
                                                        <span>Duration:
                                                            <?php
                                                            $checkIn = new DateTime($schedule['check_in_time']);
                                                            $checkOut = new DateTime($schedule['check_out_time']);
                                                            $duration = $checkIn->diff($checkOut);
                                                            echo $duration->format('%h hrs %i mins');
                                                            ?>
                                                        </span>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if (!empty($schedules)): ?>
                            <div class="mt-4 text-center">
                                <a href="member_schedules.php?id=<?php echo $member['id']; ?>"
                                    class="text-blue-600 hover:text-blue-800">
                                    View All Schedules <i class="fas fa-arrow-right ml-1"></i>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Memberships Tab -->
            <div id="memberships-tab" class="tab-content">
                <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                    <div class="p-4 bg-gray-50 border-b border-gray-200 flex justify-between items-center">
                        <h3 class="text-lg font-semibold text-gray-800">Membership History</h3>
                        <a href="add_membership.php?member_id=<?php echo $member['id']; ?>"
                            class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg text-sm">
                            <i class="fas fa-plus mr-2"></i>Add Membership
                        </a>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col"
                                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Gym</th>
                                    <th scope="col"
                                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Plan</th>
                                    <th scope="col"
                                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Duration</th>
                                    <th scope="col"
                                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Status</th>
                                    <th scope="col"
                                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Payment</th>
                                    <th scope="col"
                                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if (empty($memberships)): ?>
                                    <tr>
                                        <td colspan="6" class="px-6 py-4 text-center text-gray-500">No memberships found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($memberships as $membership):
                                        $status = getMembershipStatus($membership['start_date'], $membership['end_date']);
                                        ?>
                                        <tr>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($membership['gym_name']); ?></div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="text-sm text-gray-900">
                                                    <?php echo htmlspecialchars($membership['plan_name']); ?></div>
                                                <div class="text-xs text-gray-500">
                                                    <?php echo htmlspecialchars($membership['tier']); ?></div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="text-sm text-gray-900">
                                                    <?php echo formatDate($membership['start_date']); ?> to
                                                    <?php echo formatDate($membership['end_date']); ?>
                                                </div>
                                                <div class="text-xs text-gray-500">
                                                    <?php
                                                    $start = new DateTime($membership['start_date']);
                                                    $end = new DateTime($membership['end_date']);
                                                    $interval = $start->diff($end);
                                                    echo $interval->days + 1;
                                                    ?> days
                                                    (<?php echo $membership['duration']; ?>)
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <span
                                                    class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $status['class']; ?>">
                                                    <?php echo ucfirst($status['status']); ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="text-sm text-gray-900">
                                                    ₹<?php echo number_format($membership['amount'] ?? 0, 2); ?></div>
                                                <div class="text-xs text-gray-500">
                                                    <?php
                                                    $paymentStatusClass = 'text-red-600';
                                                    if ($membership['payment_status'] === 'paid') {
                                                        $paymentStatusClass = 'text-green-600';
                                                    }
                                                    ?>
                                                    <span
                                                        class="<?php echo $paymentStatusClass; ?>"><?php echo ucfirst($membership['payment_status']); ?></span>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                <div class="flex space-x-2">
                                                    <a href="view_membership.php?id=<?php echo $membership['id']; ?>"
                                                        class="text-blue-600 hover:text-blue-900">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <?php if ($status['status'] === 'active'): ?>
                                                        <a href="renew_membership.php?id=<?php echo $membership['id']; ?>"
                                                            class="text-green-600 hover:text-green-900">
                                                            <i class="fas fa-sync-alt"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    <?php if ($membership['payment_status'] !== 'paid'): ?>
                                                        <a href="record_payment.php?membership_id=<?php echo $membership['id']; ?>"
                                                            class="text-yellow-600 hover:text-yellow-900">
                                                            <i class="fas fa-money-bill-wave"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if (count($memberships) >= 10): ?>
                        <div class="p-4 border-t border-gray-200 text-center">
                            <a href="member_memberships.php?id=<?php echo $member['id']; ?>"
                                class="text-blue-600 hover:text-blue-800">
                                View All Memberships <i class="fas fa-arrow-right ml-1"></i>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Schedules Tab -->
            <div id="schedules-tab" class="tab-content">
                <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                    <div class="p-4 bg-gray-50 border-b border-gray-200 flex justify-between items-center">
                        <h3 class="text-lg font-semibold text-gray-800">Schedule History</h3>
                        <a href="add_schedule.php?member_id=<?php echo $member['id']; ?>"
                            class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg text-sm">
                            <i class="fas fa-plus mr-2"></i>Add Schedule
                        </a>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col"
                                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Date & Time</th>
                                    <th scope="col"
                                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Gym</th>
                                    <th scope="col"
                                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Activity</th>
                                    <th scope="col"
                                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Status</th>
                                    <th scope="col"
                                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Check In/Out</th>
                                    <th scope="col"
                                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if (empty($schedules)): ?>
                                    <tr>
                                        <td colspan="6" class="px-6 py-4 text-center text-gray-500">No schedules found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($schedules as $schedule):
                                        $statusClass = 'bg-gray-100 text-gray-800';

                                        if ($schedule['status'] === 'completed') {
                                            $statusClass = 'bg-green-100 text-green-800';
                                        } elseif ($schedule['status'] === 'missed') {
                                            $statusClass = 'bg-red-100 text-red-800';
                                        } elseif ($schedule['status'] === 'cancelled') {
                                            $statusClass = 'bg-yellow-100 text-yellow-800';
                                        } elseif ($schedule['status'] === 'scheduled') {
                                            $statusClass = 'bg-blue-100 text-blue-800';
                                        }
                                        ?>
                                        <tr>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="text-sm font-medium text-gray-900">
                                                    <?php echo formatDate($schedule['start_date']); ?></div>
                                                <div class="text-xs text-gray-500">
                                                    <?php echo formatTime($schedule['start_time']); ?></div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="text-sm text-gray-900">
                                                    <?php echo htmlspecialchars($schedule['gym_name']); ?></div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="text-sm text-gray-900">
                                                    <?php
                                                    $activityType = $schedule['activity_type'];
                                                    if ($activityType === 'gym_visit') {
                                                        echo 'Gym Visit';
                                                    } elseif ($activityType === 'class') {
                                                        echo 'Class Session';
                                                    } elseif ($activityType === 'personal_training') {
                                                        echo 'Personal Training';
                                                    } else {
                                                        echo ucfirst($activityType);
                                                    }
                                                    ?>
                                                </div>
                                                <?php if (!empty($schedule['notes'])): ?>
                                                    <div class="text-xs text-gray-500">
                                                        <?php echo htmlspecialchars(substr($schedule['notes'], 0, 30)); ?>                <?php echo strlen($schedule['notes']) > 30 ? '...' : ''; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <span
                                                    class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $statusClass; ?>">
                                                    <?php echo ucfirst($schedule['status']); ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <?php if ($schedule['check_in_time']): ?>
                                                    <div class="text-xs text-gray-500">
                                                        In: <?php echo date('h:i A', strtotime($schedule['check_in_time'])); ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($schedule['check_out_time']): ?>
                                                    <div class="text-xs text-gray-500">
                                                        Out: <?php echo date('h:i A', strtotime($schedule['check_out_time'])); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                <div class="flex space-x-2">
                                                    <a href="view_schedule.php?id=<?php echo $schedule['id']; ?>"
                                                        class="text-blue-600 hover:text-blue-900">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <?php if ($schedule['status'] === 'scheduled'): ?>
                                                        <a href="check_in.php?id=<?php echo $schedule['id']; ?>"
                                                            class="text-green-600 hover:text-green-900">
                                                            <i class="fas fa-sign-in-alt"></i>
                                                        </a>
                                                        <a href="cancel_schedule.php?id=<?php echo $schedule['id']; ?>"
                                                            class="text-red-600 hover:text-red-900">
                                                            <i class="fas fa-times"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="p-4 border-t border-gray-200 text-center">
                        <a href="member_schedules.php?id=<?php echo $member['id']; ?>"
                            class="text-blue-600 hover:text-blue-800">
                            View All Schedules <i class="fas fa-arrow-right ml-1"></i>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Payments Tab -->
            <div id="payments-tab" class="tab-content">
                <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                    <div class="p-4 bg-gray-50 border-b border-gray-200 flex justify-between items-center">
                        <h3 class="text-lg font-semibold text-gray-800">Payment History</h3>
                        <a href="record_payment.php?member_id=<?php echo $member['id']; ?>"
                            class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg text-sm">
                            <i class="fas fa-plus mr-2"></i>Record Payment
                        </a>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col"
                                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Date</th>
                                    <th scope="col"
                                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Amount</th>
                                    <th scope="col"
                                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Gym</th>
                                    <th scope="col"
                                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Payment Method</th>
                                    <th scope="col"
                                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Status</th>
                                    <th scope="col"
                                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if (empty($payments)): ?>
                                    <tr>
                                        <td colspan="6" class="px-6 py-4 text-center text-gray-500">No payments found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($payments as $payment):
                                        $statusClass = 'bg-gray-100 text-gray-800';

                                        if ($payment['status'] === 'completed') {
                                            $statusClass = 'bg-green-100 text-green-800';
                                        } elseif ($payment['status'] === 'failed') {
                                            $statusClass = 'bg-red-100 text-red-800';
                                        } elseif ($payment['status'] === 'pending') {
                                            $statusClass = 'bg-yellow-100 text-yellow-800';
                                        } elseif ($payment['status'] === 'refunded') {
                                            $statusClass = 'bg-blue-100 text-blue-800';
                                        }
                                        ?>
                                        <tr>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="text-sm font-medium text-gray-900"><?php echo formatDate($payment['payment_date']); ?></div>
                                                     <div class="text-xs text-gray-500"><?php echo date('h:i A', strtotime($payment['payment_date'])); ?>
                            </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900">₹<?php echo number_format($payment['amount'], 2); ?>
                                </div>
                                                                        <?php if ($payment['discount_amount'] > 0): ?>
                                    <div class="text-xs text-green-600">
                                                                     Discount: ₹<?php echo number_format($payment['discount_amount'], 2); ?>
                                                                                <?php if (!empty($payment['coupon_code'])): ?>
                                                                                   (
                                            <?php echo $payment['coupon_code']; ?>)
                                       
                                        <?php endif; ?>
                                    </div>
                                   
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                     <div class="text-sm text-gray-900"><?php echo htmlspecialchars($payment['gym_name']); ?>
                        </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-900">
                                                                        <?php echo !empty($payment['payment_method']) ? ucfirst(htmlspecialchars($payment['payment_method'])) : 'N/A'; ?>
                            </div>
                                                                    <?php if (!empty($payment['transaction_id'])): ?>
                                <div class="text-xs text-gray-500">
                                                                        ID: <?php echo substr($payment['transaction_id'], 0, 10); ?>...
                                </div>
                               
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $statusClass; ?>">
                                                                        <?php echo ucfirst($payment['status']); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                            <div class="flex space-x-2">
                                <a href="view_payment.php?id=
                    <?php echo $payment['id']; ?>" class="text-blue-600 hover:text-blue-900">
                                <i class="fas fa-eye"></i>
                                </a>
                                <?php if ($payment['status'] === 'completed'): ?>
                               <a href="generate_receipt.php?id=
                    <?php echo $payment['id']; ?>" class="text-green-600 hover:text-green-900">   <i class="fas fa-receipt"></i>
                                    </a>
                                   
                    <?php endif; ?>
                    <?php if ($payment['status'] === 'pending'): ?>
                    <a href="update_payment.php?id=
                    <?php echo $payment['id']; ?>&status=completed" class="text-yellow-600 hover:text-yellow-900">
                                    <i class="fas fa-check"></i>
                                    </a>
                                   
                                <?php endif; ?>
                            </div>
                        </td>
                        </tr>
                                
                    <?php endforeach; ?>
                           
                <?php endif; ?>
                </tbody>
                </table>
            </div>

            <div class="p-4 border-t border-gray-200 text-center">
                <a href="member_payments.php?id=
            <?php echo $member['id']; ?>" class="text-blue-600 hover:text-blue-800">
                View All Payments <i class="fas fa-arrow-right ml-1"></i>
                </a>
            </div>
        </div>
        </div>
                   
    <?php endif; ?>
    </div>

    <script>
        // Tab switching functionality
        document.addEventListener('DOMContentLoaded', function () {
            const tabLinks = document.querySelectorAll('.tab-link');
            const tabContents = document.querySelectorAll('.tab-content');

            tabLinks.forEach(link => {
                link.addEventListener('click', function (e) {
                    e.preventDefault();

                    // Remove active class from all tabs
                    tabLinks.forEach(tab => tab.classList.remove('active', 'border-yellow-500'));
                    tabLinks.forEach(tab => tab.classList.add('border-transparent'));

                    // Add active class to clicked tab
                    this.classList.add('active', 'border-yellow-500');
                    this.classList.remove('border-transparent');

                    // Hide all tab contents
                    tabContents.forEach(content => content.classList.remove('active'));

                    // Show the corresponding tab content
                    const tabId = this.getAttribute('data-tab');
                    document.getElementById(tabId + '-tab').classList.add('active');
                });
            });

            // Handle loading overlay
            const loadingOverlay = document.getElementById('loadingOverlay');

            // Show loading overlay when navigating away
            document.querySelectorAll('a:not(.tab-link)').forEach(link => {
                link.addEventListener('click', function () {
                    // Don't show loading for same-page anchors
                    if (this.getAttribute('href').startsWith('#')) return;

                    loadingOverlay.classList.remove('hidden');
                });
            });

            // Show loading overlay when submitting forms
            document.querySelectorAll('form').forEach(form => {
                form.addEventListener('submit', function () {
                    loadingOverlay.classList.remove('hidden');
                });
            });
        });

        // Print functionality
        function printMemberProfile() {
            window.print();
        }
    </script>
</body>

</html>