<?php
include '../includes/navbar.php';

require_once '../config/database.php';

$db = new GymDatabase();
$conn = $db->getConnection();
$member_id = $_GET['id'];

// Fetch comprehensive member details with additional metrics
$stmt = $conn->prepare("
    SELECT 
        u.*,
        um.status as membership_status,
        um.start_date,
        um.end_date,
        mp.plan_name as plan_name,
        mp.price as plan_price,
        g.name as gym_name,
        g.address as gym_address,
        COUNT(DISTINCT s.id) as total_schedules,
        COUNT(DISTINCT CASE WHEN s.status = 'completed' THEN s.id END) as completed_workouts,
        (SELECT SUM(amount) FROM payments WHERE user_id = u.id) as total_payments,
        (SELECT COUNT(*) FROM schedules WHERE user_id = u.id) as total_visits
    FROM users u
    LEFT JOIN user_memberships um ON u.id = um.user_id
    LEFT JOIN gym_membership_plans mp ON um.plan_id = mp.plan_id
    LEFT JOIN gyms g ON um.gym_id = g.gym_id
    LEFT JOIN schedules s ON u.id = s.user_id
    WHERE u.id = ?
    GROUP BY u.id
");
$stmt->execute([$member_id]);
$member = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch recent activities
$stmt = $conn->prepare("
    SELECT 
        'schedule' as type,
        s.start_date as date,
        s.status,
        g.name as gym_name,
        s.start_time
    FROM schedules s
    JOIN gyms g ON s.gym_id = g.gym_id
    WHERE s.user_id = ?
    UNION ALL
    SELECT 
        'payment' as type,
        p.payment_date as date,
        p.status,
        p.amount as gym_name,
        NULL as start_time
    FROM payments p
    WHERE p.user_id = ?
    ORDER BY date ASC
    LIMIT 10
");
$stmt->execute([$member_id, $member_id]);
$recent_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<div class="container mx-auto px-4 py-20">
    <!-- Member Profile Header -->
    <div class="bg-white rounded-xl shadow-lg overflow-hidden mb-6">
        <div class="p-6 bg-gradient-to-r from-gray-900 to-gray-800 text-white">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    <div class="h-20 w-20 rounded-full bg-yellow-500 flex items-center justify-center">
                        <i class="fas fa-user-circle text-4xl text-white"></i>
                    </div>
                    <div>
                        <h1 class="text-3xl font-bold"><?php echo htmlspecialchars($member['username']); ?></h1>
                        <p class="text-white ">
                            <i class="fas fa-envelope mr-2"></i><?php echo htmlspecialchars($member['email']); ?>
                        </p>
                    </div>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="px-4 py-2 rounded-full <?php 
                        echo $member['membership_status'] === 'active' 
                            ? 'bg-green-500 text-white' 
                            : 'bg-red-500 text-white'; ?> font-semibold">
                        <i class="fas <?php echo $member['membership_status'] === 'active' ? 'fa-check-circle' : 'fa-times-circle'; ?> mr-2"></i>
                        <?php echo ucfirst($member['membership_status']); ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Key Metrics -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 p-6">
            <div class="bg-gray-50 rounded-lg p-4 text-center">
                <i class="fas fa-calendar-check text-2xl text-yellow-500 mb-2"></i>
                <h3 class="text-xl font-bold"><?php echo $member['total_visits']; ?></h3>
                <p class="text-gray-600">Total Visits</p>
            </div>
            <div class="bg-gray-50 rounded-lg p-4 text-center">
                <i class="fas fa-dumbbell text-2xl text-yellow-500 mb-2"></i>
                <h3 class="text-xl font-bold"><?php echo $member['completed_workouts']; ?></h3>
                <p class="text-gray-600">Completed Workouts</p>
            </div>
            <div class="bg-gray-50 rounded-lg p-4 text-center">
                <i class="fas fa-rupee-sign text-2xl text-yellow-500 mb-2"></i>
                <h3 class="text-xl font-bold">₹<?php echo number_format($member['total_payments'], 2); ?></h3>
                <p class="text-gray-600">Total Payments</p>
            </div>
            <div class="bg-gray-50 rounded-lg p-4 text-center">
                <i class="fas fa-clock text-2xl text-yellow-500 mb-2"></i>
                <h3 class="text-xl font-bold"><?php echo $member['visit_limit'] ?? 'Unlimited'; ?></h3>
                <p class="text-gray-600">Visit Limit</p>
            </div>
        </div>
    </div>

    <!-- Membership & Gym Details -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
        <div class="bg-white rounded-xl shadow-lg p-6">
            <h2 class="text-xl font-bold mb-4 flex items-center">
                <i class="fas fa-id-card text-yellow-500 mr-2"></i>
                Membership Details
            </h2>
            <div class="space-y-3">
                <div class="flex justify-between items-center border-b pb-2">
                    <span class="text-gray-600">Plan Name</span>
                    <span class="font-semibold"><?php echo htmlspecialchars($member['plan_name']); ?></span>
                </div>
                <div class="flex justify-between items-center border-b pb-2">
                    <span class="text-gray-600">Start Date</span>
                    <span class="font-semibold"><?php echo date('M d, Y', strtotime($member['start_date'])); ?></span>
                </div>
                <div class="flex justify-between items-center border-b pb-2">
                    <span class="text-gray-600">End Date</span>
                    <span class="font-semibold"><?php echo date('M d, Y', strtotime($member['end_date'])); ?></span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-gray-600">Plan Price</span>
                    <span class="font-semibold">₹<?php echo number_format($member['plan_price'], 2); ?></span>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-lg p-6">
            <h2 class="text-xl font-bold mb-4 flex items-center">
                <i class="fas fa-dumbbell text-yellow-500 mr-2"></i>
                Gym Information
            </h2>
            <div class="space-y-3">
                <div class="flex justify-between items-center border-b pb-2">
                    <span class="text-gray-600">Gym Name</span>
                    <span class="font-semibold"><?php echo htmlspecialchars($member['gym_name']); ?></span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-gray-600">Location</span>
                    <span class="font-semibold"><?php echo htmlspecialchars($member['gym_address']); ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Activities -->
    <div class="bg-white rounded-xl shadow-lg p-6">
        <h2 class="text-xl font-bold mb-4 flex items-center">
            <i class="fas fa-history text-yellow-500 mr-2"></i>
            Recent Activities
        </h2>
        <div class="space-y-4">
            <?php foreach ($recent_activities as $activity): ?>
                <div class="flex items-center justify-between bg-gray-50 p-4 rounded-lg">
                    <div class="flex items-center space-x-4">
                        <div class="rounded-full bg-yellow-100 p-2">
                            <i class="fas <?php echo $activity['type'] === 'schedule' ? 'fa-calendar' : 'fa-money-bill'; ?> text-yellow-500"></i>
                        </div>
                        <div>
                            <p class="font-medium">
                                <?php echo $activity['type'] === 'schedule' 
                                    ? htmlspecialchars($activity['gym_name']) 
                                    : '₹' . number_format($activity['gym_name'], 2); ?>
                            </p>
                            <p class="text-sm text-gray-600">
                                <?php echo date('M d, Y', strtotime($activity['date'])); ?>
                                <?php if ($activity['start_time']): ?>
                                    at <?php echo date('h:i A', strtotime($activity['start_time'])); ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                    <span class="px-3 py-1 rounded-full text-sm font-semibold <?php 
                        echo $activity['status'] === 'completed' || $activity['status'] === 'paid'
                            ? 'bg-green-100 text-green-800' 
                            : 'bg-yellow-100 text-yellow-800'; ?>">
                        <?php echo ucfirst($activity['status']); ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
