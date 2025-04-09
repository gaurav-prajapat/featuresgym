<?php
include '../includes/navbar.php';
require_once '../config/database.php';

// Initialize database connection
$db = new GymDatabase();
$conn = $db->getConnection();

// Validate member ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo '<div class="container mx-auto px-4 py-8"><div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">Invalid member ID</div></div>';
    exit;
}

$member_id = $_GET['id'];

try {
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
            g.gym_id,
            COUNT(DISTINCT s.id) as total_schedules,
            COUNT(DISTINCT CASE WHEN s.status = 'completed' THEN s.id END) as completed_workouts,
            COUNT(DISTINCT CASE WHEN s.status = 'missed' THEN s.id END) as missed_workouts,
            (SELECT SUM(amount) FROM payments WHERE user_id = u.id AND status = 'completed') as total_payments,
            (SELECT COUNT(*) FROM schedules WHERE user_id = u.id) as total_visits
        FROM users u
        LEFT JOIN user_memberships um ON u.id = um.user_id AND um.status = 'active'
        LEFT JOIN gym_membership_plans mp ON um.plan_id = mp.plan_id
        LEFT JOIN gyms g ON um.gym_id = g.gym_id
        LEFT JOIN schedules s ON u.id = s.user_id
        WHERE u.id = ?
        GROUP BY u.id
    ");
    $stmt->execute([$member_id]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$member) {
        echo '<div class="container mx-auto px-4 py-8"><div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">Member not found</div></div>';
        exit;
    }

    // Fetch recent activities (schedules and payments only)
    $stmt = $conn->prepare("
        SELECT 
            'schedule' as type,
            s.start_date as date,
            s.status,
            g.name as gym_name,
            s.start_time,
            NULL as amount
        FROM schedules s
        JOIN gyms g ON s.gym_id = g.gym_id
        WHERE s.user_id = ?
        
        UNION ALL
        
        SELECT 
            'payment' as type,
            p.payment_date as date,
            p.status,
            NULL as gym_name,
            NULL as start_time,
            p.amount
        FROM payments p
        WHERE p.user_id = ?
        
        ORDER BY date DESC
        LIMIT 10
    ");
    $stmt->execute([$member_id, $member_id]);
    $recent_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Log this admin activity
    $stmt = $conn->prepare("
        INSERT INTO activity_logs (
            user_id, user_type, action, details, ip_address, user_agent
        ) VALUES (?, 'admin', 'view_member_details', ?, ?, ?)
    ");
    $details = "Viewed member details for user ID: " . $member_id;
    $stmt->execute([
        $_SESSION['admin_id'] ?? 0,
        $details,
        $_SERVER['REMOTE_ADDR'],
        $_SERVER['HTTP_USER_AGENT']
    ]);

} catch (PDOException $e) {
    echo '<div class="container mx-auto px-4 py-8"><div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">Database error: ' . $e->getMessage() . '</div></div>';
    exit;
}
?>

<div class="container mx-auto px-4 py-8">
    <!-- Member Profile Header -->
    <div class="bg-white rounded-xl shadow-lg overflow-hidden mb-6">
        <div class="p-6 bg-gradient-to-r from-gray-900 to-gray-800 text-white">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    <div class="h-20 w-20 rounded-full overflow-hidden bg-yellow-500 flex items-center justify-center">
                        <?php if (!empty($member['profile_image'])): ?>
                            <img src="../uploads/profile_images/<?php echo htmlspecialchars($member['profile_image']); ?>" alt="Profile" class="h-full w-full object-cover">
                        <?php else: ?>
                            <i class="fas fa-user-circle text-4xl text-white"></i>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h1 class="text-3xl font-bold"><?php echo htmlspecialchars($member['username']); ?></h1>
                        <p class="text-white">
                            <i class="fas fa-envelope mr-2"></i><?php echo htmlspecialchars($member['email']); ?>
                        </p>
                        <?php if (!empty($member['phone'])): ?>
                        <p class="text-white">
                            <i class="fas fa-phone mr-2"></i><?php echo htmlspecialchars($member['phone']); ?>
                        </p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="px-4 py-2 rounded-full <?php 
                        echo $member['membership_status'] === 'active' 
                            ? 'bg-green-500 text-white' 
                            : 'bg-red-500 text-white'; ?> font-semibold">
                        <i class="fas <?php echo $member['membership_status'] === 'active' ? 'fa-check-circle' : 'fa-times-circle'; ?> mr-2"></i>
                        <?php echo ucfirst($member['membership_status'] ?? 'Inactive'); ?>
                    </span>
                    <div class="flex space-x-2">
                        <button onclick="window.location.href='update_member_status.php?id=<?php echo $member_id; ?>&status=<?php echo $member['status'] === 'active' ? 'inactive' : 'active'; ?>'" 
                                class="bg-<?php echo $member['status'] === 'active' ? 'red' : 'green'; ?>-500 hover:bg-<?php echo $member['status'] === 'active' ? 'red' : 'green'; ?>-600 text-white px-4 py-2 rounded-lg transition-colors duration-300">
                            <i class="fas fa-<?php echo $member['status'] === 'active' ? 'ban' : 'check'; ?> mr-1"></i> 
                            <?php echo $member['status'] === 'active' ? 'Suspend' : 'Activate'; ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Key Metrics -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 p-6">
            <div class="bg-gray-50 rounded-lg p-4 text-center">
                <i class="fas fa-calendar-check text-2xl text-yellow-500 mb-2"></i>
                <h3 class="text-xl font-bold"><?php echo $member['total_visits'] ?? 0; ?></h3>
                <p class="text-gray-600">Total Visits</p>
            </div>
            <div class="bg-gray-50 rounded-lg p-4 text-center">
                <i class="fas fa-dumbbell text-2xl text-yellow-500 mb-2"></i>
                <h3 class="text-xl font-bold"><?php echo $member['completed_workouts'] ?? 0; ?></h3>
                <p class="text-gray-600">Completed Workouts</p>
            </div>
            <div class="bg-gray-50 rounded-lg p-4 text-center">
                <i class="fas fa-times-circle text-2xl text-yellow-500 mb-2"></i>
                <h3 class="text-xl font-bold"><?php echo $member['missed_workouts'] ?? 0; ?></h3>
                <p class="text-gray-600">Missed Workouts</p>
            </div>
            <div class="bg-gray-50 rounded-lg p-4 text-center">
                <i class="fas fa-rupee-sign text-2xl text-yellow-500 mb-2"></i>
                <h3 class="text-xl font-bold">₹<?php echo number_format($member['total_payments'] ?? 0, 2); ?></h3>
                <p class="text-gray-600">Total Payments</p>
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
            <?php if ($member['membership_status'] === 'active'): ?>
                <div class="space-y-3">
                    <div class="flex justify-between items-center border-b pb-2">
                        <span class="text-gray-600">Plan Name</span>
                        <span class="font-semibold"><?php echo htmlspecialchars($member['plan_name'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="flex justify-between items-center border-b pb-2">
                        <span class="text-gray-600">Start Date</span>
                        <span class="font-semibold"><?php echo $member['start_date'] ? date('M d, Y', strtotime($member['start_date'])) : 'N/A'; ?></span>
                    </div>
                    <div class="flex justify-between items-center border-b pb-2">
                        <span class="text-gray-600">End Date</span>
                        <span class="font-semibold"><?php echo $member['end_date'] ? date('M d, Y', strtotime($member['end_date'])) : 'N/A'; ?></span>
                    </div>
                    <div class="flex justify-between items-center border-b pb-2">
                        <span class="text-gray-600">Plan Price</span>
                        <span class="font-semibold">₹<?php echo number_format($member['plan_price'] ?? 0, 2); ?></span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">Account Balance</span>
                        <span class="font-semibold">₹<?php echo number_format($member['balance'] ?? 0, 2); ?></span>
                    </div>
                </div>
            <?php else: ?>
                <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas fa-exclamation-triangle text-yellow-400"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm text-yellow-700">
                                This member doesn't have an active membership.
                            </p>
                        </div>
                    </div>
                </div>
                <div class="mt-4">
                    <button onclick="window.location.href='members.php?action=assign_membership&user_id=<?php echo $member_id; ?>'" class="bg-yellow-500 hover:bg-yellow-600 text-white px-4 py-2 rounded-lg transition-colors duration-300 inline-block">
                        <i class="fas fa-plus-circle mr-1"></i> Assign Membership
                    </button>
                </div>
            <?php endif; ?>
        </div>

        <div class="bg-white rounded-xl shadow-lg p-6">
            <h2 class="text-xl font-bold mb-4 flex items-center">
                <i class="fas fa-dumbbell text-yellow-500 mr-2"></i>
                Gym Information
            </h2>
            <?php if (!empty($member['gym_name'])): ?>
                <div class="space-y-3">
                    <div class="flex justify-between items-center border-b pb-2">
                        <span class="text-gray-600">Gym Name</span>
                        <span class="font-semibold"><?php echo htmlspecialchars($member['gym_name']); ?></span>
                    </div>
                    <div class="flex justify-between items-center border-b pb-2">
                        <span class="text-gray-600">Location</span>
                        <span class="font-semibold"><?php echo htmlspecialchars($member['gym_address']); ?></span>
                    </div>
                    <div class="mt-4">
                        <button onclick="window.location.href='manage_gym.php?id=<?php echo $member['gym_id']; ?>'" class="text-blue-500 hover:text-blue-700 transition-colors duration-300">
                            <i class="fas fa-external-link-alt mr-1"></i> View Gym Details
                        </button>
                    </div>
                </div>
            <?php else: ?>
                <div class="bg-gray-50 p-4 rounded-lg text-center">
                    <p class="text-gray-600">No gym information available</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Activities -->
    <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
        <h2 class="text-xl font-bold mb-4 flex items-center">
            <i class="fas fa-history text-yellow-500 mr-2"></i>
            Recent Activities
        </h2>
        <?php if (count($recent_activities) > 0): ?>
            <div class="space-y-4">
                <?php foreach ($recent_activities as $activity): ?>
                    <div class="flex items-center justify-between bg-gray-50 p-4 rounded-lg">
                        <div class="flex items-center space-x-4">
                            <div class="rounded-full bg-yellow-100 p-2">
                                <i class="fas <?php 
                                    echo $activity['type'] === 'schedule' 
                                        ? 'fa-calendar' 
                                        : 'fa-money-bill'; ?> text-yellow-500"></i>
                            </div>
                            <div>
                                <?php if ($activity['type'] === 'schedule'): ?>
                                    <p class="font-medium">
                                        Workout at <?php echo htmlspecialchars($activity['gym_name']); ?>
                                    </p>
                                    <p class="text-sm text-gray-600">
                                        <?php echo date('M d, Y', strtotime($activity['date'])); ?>
                                        at <?php echo date('h:i A', strtotime($activity['start_time'])); ?>
                                    </p>
                                <?php else: ?>
                                    <p class="font-medium">
                                        Payment: ₹<?php echo number_format($activity['amount'], 2); ?>
                                    </p>
                                    <p class="text-sm text-gray-600">
                                        <?php echo date('M d, Y', strtotime($activity['date'])); ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <span class="px-3 py-1 rounded-full text-sm font-semibold <?php 
                            echo $activity['status'] === 'completed' || $activity['status'] === 'paid'
                                ? 'bg-green-100 text-green-800' 
                                : ($activity['status'] === 'missed' || $activity['status'] === 'failed'
                                    ? 'bg-red-100 text-red-800'
                                    : 'bg-yellow-100 text-yellow-800'); ?>">
                            <?php echo ucfirst($activity['status']); ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="bg-gray-50 p-4 rounded-lg text-center">
                <p class="text-gray-600">No recent activities found</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Scheduled Workouts -->
    <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
        <h2 class="text-xl font-bold mb-4 flex items-center">
            <i class="fas fa-calendar-alt text-yellow-500 mr-2"></i>
            Upcoming Workouts
        </h2>
        <?php
        // Fetch upcoming workouts
        $stmt = $conn->prepare("
            SELECT 
                s.id, s.start_date, s.start_time, s.status,
                g.name as gym_name
            FROM schedules s
            JOIN gyms g ON s.gym_id = g.gym_id
            WHERE s.user_id = ? 
            AND s.status = 'scheduled'
            AND (s.start_date > CURDATE() OR (s.start_date = CURDATE() AND s.start_time > CURTIME()))
            ORDER BY s.start_date ASC, s.start_time ASC
            LIMIT 5
        ");
        $stmt->execute([$member_id]);
        $upcoming_workouts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        ?>

        <?php if (count($upcoming_workouts) > 0): ?>
            <div class="overflow-x-auto">
                <table class="min-w-full bg-white">
                    <thead>
                        <tr>
                            <th class="py-2 px-4 border-b border-gray-200 bg-gray-50 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                Date
                            </th>
                            <th class="py-2 px-4 border-b border-gray-200 bg-gray-50 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                Time
                            </th>
                            <th class="py-2 px-4 border-b border-gray-200 bg-gray-50 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                Gym
                            </th>
                            <th class="py-2 px-4 border-b border-gray-200 bg-gray-50 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                Status
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($upcoming_workouts as $workout): ?>
                            <tr>
                                <td class="py-2 px-4 border-b border-gray-200">
                                    <?php echo date('M d, Y', strtotime($workout['start_date'])); ?>
                                </td>
                                <td class="py-2 px-4 border-b border-gray-200">
                                    <?php echo date('h:i A', strtotime($workout['start_time'])); ?>
                                </td>
                                <td class="py-2 px-4 border-b border-gray-200">
                                    <?php echo htmlspecialchars($workout['gym_name']); ?>
                                </td>
                                <td class="py-2 px-4 border-b border-gray-200">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                        <?php echo ucfirst($workout['status']); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="bg-gray-50 p-4 rounded-lg text-center">
                <p class="text-gray-600">No upcoming workouts scheduled</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Admin Actions -->
    <div class="bg-white rounded-xl shadow-lg p-6">
        <h2 class="text-xl font-bold mb-4 flex items-center">
            <i class="fas fa-cog text-yellow-500 mr-2"></i>
            Admin Actions
        </h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <form action="update_member_balance.php" method="POST" class="bg-blue-500 hover:bg-blue-600 text-white p-4 rounded-lg transition-colors duration-300 text-center">
                <input type="hidden" name="user_id" value="<?php echo $member_id; ?>">
                <div class="flex flex-col items-center">
                    <i class="fas fa-wallet text-2xl mb-2"></i>
                    <p class="mb-2">Adjust Balance</p>
                    <div class="flex items-center mt-2">
                        <input type="number" name="amount" step="0.01" class="text-black px-2 py-1 rounded-l w-24" placeholder="Amount" required>
                        <button type="submit" name="action" value="add" class="bg-green-600 hover:bg-green-700 px-2 py-1">Add</button>
                        <button type="submit" name="action" value="subtract" class="bg-red-600 hover:bg-red-700 px-2 py-1 rounded-r">Subtract</button>
                    </div>
                </div>
            </form>
            
            <form action="update_member_status.php" method="POST" class="bg-yellow-500 hover:bg-yellow-600 text-white p-4 rounded-lg transition-colors duration-300 text-center">
                <input type="hidden" name="user_id" value="<?php echo $member_id; ?>">
                <div class="flex flex-col items-center">
                    <i class="fas fa-user-shield text-2xl mb-2"></i>
                    <p class="mb-2">Change Status</p>
                    <div class="flex items-center mt-2">
                        <select name="status" class="text-black px-2 py-1 rounded-l">
                            <option value="active" <?php echo $member['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $member['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            <option value="suspended" <?php echo $member['status'] === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                        </select>
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 px-3 py-1 rounded-r">Update</button>
                    </div>
                </div>
            </form>
        </div>
        
        <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
            <form action="send_notification.php" method="POST" class="bg-green-500 hover:bg-green-600 text-white p-4 rounded-lg transition-colors duration-300 text-center">
                <input type="hidden" name="user_id" value="<?php echo $member_id; ?>">
                <div class="flex flex-col items-center">
                    <i class="fas fa-bell text-2xl mb-2"></i>
                    <p class="mb-2">Send Notification</p>
                    <div class="flex flex-col w-full mt-2">
                        <input type="text" name="title" class="text-black px-2 py-1 rounded-t w-full" placeholder="Title" required>
                        <textarea name="message" class="text-black px-2 py-1 rounded-b w-full" placeholder="Message" rows="2" required></textarea>
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 px-3 py-1 rounded mt-2">Send</button>
                    </div>
                </div>
            </form>
            
            <div class="bg-red-500 hover:bg-red-600 text-white p-4 rounded-lg transition-colors duration-300 text-center">
                <div class="flex flex-col items-center">
                    <i class="fas fa-trash-alt text-2xl mb-2"></i>
                    <p class="mb-2">Delete Member</p>
                    <div class="mt-2">
                        <button onclick="confirmDelete(<?php echo $member_id; ?>)" class="bg-gray-800 hover:bg-gray-900 px-3 py-1 rounded">Delete Account</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function confirmDelete(userId) {
    if (confirm('Are you sure you want to delete this member? This action cannot be undone and will remove all associated data.')) {
        window.location.href = 'delete_member.php?id=' + userId + '&confirm=yes';
    }
}
</script>

<?php include '../includes/footer.php'; ?>

