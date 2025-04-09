<?php
ob_start();
require 'config/database.php';
include 'includes/navbar.php';

$user_id = $_SESSION['user_id'] ?? null;

if (!$user_id) {
    header('Location: login.php');
    exit;
}

$db = new GymDatabase();
$conn = $db->getConnection();

// Get all user memberships with additional details
$stmt = $conn->prepare("
    SELECT 
        um.*, 
        gmp.tier AS plan_name, 
        gmp.duration, 
        gmp.price, 
        gmp.inclusions,
        g.name AS gym_name, 
        g.address, 
        g.city,
        g.cover_photo,
        p.status AS payment_status,
        (SELECT COUNT(*) FROM schedules WHERE membership_id = um.id) as used_days,
        CASE 
            WHEN gmp.duration = 'Daily' THEN DATEDIFF(um.end_date, um.start_date) + 1
            ELSE NULL
        END as total_days_purchased
    FROM user_memberships um
    JOIN gym_membership_plans gmp ON um.plan_id = gmp.plan_id
    JOIN gyms g ON gmp.gym_id = g.gym_id
    LEFT JOIN payments p ON um.id = p.membership_id
    WHERE um.user_id = ?
    ORDER BY um.status = 'active' DESC, um.start_date DESC
");
$stmt->execute([$user_id]);
$memberships = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group memberships by status
$activeMemberships = array_filter($memberships, function($m) {
    return $m['status'] === 'active';
});

$expiredMemberships = array_filter($memberships, function($m) {
    return $m['status'] === 'expired' || $m['status'] === 'cancelled';
});

?>

<div class="min-h-screen bg-gradient-to-b from-gray-900 to-black py-12">
    <div class="container mx-auto px-4 sm:px-6 lg:px-8">
        <!-- Messages -->
        <?php if (isset($_SESSION['error'])): ?>
            <div class="bg-red-900 text-red-100 p-6 rounded-3xl mb-6">
                <?= htmlspecialchars($_SESSION['error']) ?>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="bg-green-900 text-green-100 p-6 rounded-3xl mb-6">
                <?= htmlspecialchars($_SESSION['success']) ?>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <!-- Active Memberships Section -->
        <?php if ($activeMemberships): ?>
            <h2 class="text-3xl font-extrabold text-white my-10 text-center">Your Active Memberships</h2>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-16">
                <?php foreach ($activeMemberships as $membership): ?>
                    <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl overflow-hidden transform hover:scale-[1.02] transition-all duration-300">
                        <!-- Membership Header -->
                        <div class="bg-gradient-to-r from-yellow-400 to-yellow-500 p-6">
                            <div class="flex justify-between items-center">
                                <h3 class="text-2xl font-bold text-gray-900">
                                    <?php echo htmlspecialchars($membership['plan_name']); ?> Plan
                                </h3>
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-green-900 text-green-100">
                                    Active
                                </span>
                            </div>
                            <p class="text-gray-800 mt-1">
                                <?php echo htmlspecialchars($membership['gym_name']); ?>
                            </p>
                        </div>

                        <!-- Membership Details -->
                        <div class="p-6 space-y-6">
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <p class="text-yellow-400 text-sm">Valid Until</p>
                                    <p class="text-white text-lg">
                                        <?php echo date('F j, Y', strtotime($membership['end_date'])); ?>
                                    </p>
                                </div>
                                <div>
                                    <p class="text-yellow-400 text-sm">Payment Status</p>
                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium
                                        <?php echo $membership['payment_status'] === 'completed' 
                                            ? 'bg-green-900 text-green-100' 
                                            : 'bg-red-900 text-red-100'; ?>">
                                        <?php echo ucfirst($membership['payment_status'] ?? 'pending'); ?>
                                    </span>
                                </div>
                            </div>

                            <?php if (strtolower($membership['duration']) === 'daily'): ?>
                                <!-- Daily Pass Usage Info -->
                                <div class="bg-gray-700 bg-opacity-50 rounded-xl p-4">
                                    <h4 class="text-yellow-400 font-semibold mb-2">Daily Pass Usage</h4>
                                    <?php 
                                        $totalDays = $membership['total_days_purchased'] ?? 1;
                                        $usedDays = $membership['used_days'] ?? 0;
                                        $remainingDays = $totalDays - $usedDays;
                                        $usagePercentage = ($usedDays / $totalDays) * 100;
                                    ?>
                                    <div class="flex justify-between text-white mb-2">
                                        <span>Used: <?= $usedDays ?> day(s)</span>
                                        <span>Remaining: <?= $remainingDays ?> day(s)</span>
                                    </div>
                                    <div class="w-full bg-gray-600 rounded-full h-2.5">
                                        <div class="bg-yellow-400 h-2.5 rounded-full" style="width: <?= $usagePercentage ?>%"></div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Inclusions -->
                            <div>
                                <p class="text-yellow-400 text-sm mb-3">Plan Includes</p>
                                <ul class="space-y-2">
                                    <?php foreach (explode(',', $membership['inclusions']) as $inclusion): ?>
                                        <li class="text-white flex items-center">
                                            <i class="fas fa-check text-yellow-400 mr-2"></i>
                                            <?php echo htmlspecialchars(trim($inclusion)); ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>

                            <!-- Price Info -->
                            <div class="flex justify-between items-center pt-4 border-t border-gray-700">
                                <div>
                                    <p class="text-yellow-400 text-sm">Price</p>
                                    <p class="text-white text-2xl font-bold">
                                        ₹<?php echo number_format($membership['price'], 2); ?>
                                    </p>
                                </div>
                                <div class="text-right">
                                    <p class="text-yellow-400 text-sm">Plan Type</p>
                                    <p class="text-white">
                                        <?php echo htmlspecialchars($membership['duration']); ?>
                                    </p>
                                </div>
                            </div>

                            <!-- Action Buttons -->
                            <div class="flex space-x-4 pt-4">
                                <a href="schedule.php?membership_id=<?= $membership['id'] ?>" 
                                   class="flex-1 bg-yellow-400 hover:bg-yellow-500 text-black font-bold py-2 px-4 rounded-full text-center transition duration-300">
                                    Schedule Workout
                                </a>
                                <?php if (strtolower($membership['duration']) === 'daily' && $remainingDays > 0): ?>
                                    <a href="gym-profile.php?gym_id=<?= $membership['gym_id'] ?>" 
                                       class="flex-1 bg-blue-500 hover:bg-blue-600 text-white font-bold py-2 px-4 rounded-full text-center transition duration-300">
                                        View Gym
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl p-8 text-center mb-16">
                <h2 class="text-3xl font-extrabold text-white mb-4">No Active Memberships</h2>
                <p class="text-gray-300 mb-6">You don't have any active gym memberships at the moment.</p>
                <a href="all-gyms.php" class="inline-block bg-yellow-400 hover:bg-yellow-500 text-black font-bold py-3 px-6 rounded-full transition duration-300">
                    Browse Gyms
                </a>
            </div>
        <?php endif; ?>

        <!-- Expired/Cancelled Memberships Section -->
        <?php if ($expiredMemberships): ?>
            <h2 class="text-3xl font-extrabold text-white my-10 text-center">Past Memberships</h2>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <?php foreach ($expiredMemberships as $membership): ?>
                    <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl overflow-hidden opacity-75">
                        <!-- Membership Header -->
                        <div class="bg-gradient-to-r from-gray-500 to-gray-600 p-6">
                            <div class="flex justify-between items-center">
                                <h3 class="text-2xl font-bold text-white">
                                    <?php echo htmlspecialchars($membership['plan_name']); ?> Plan
                                </h3>
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-red-900 text-red-100">
                                    <?php echo ucfirst($membership['status']); ?>
                                </span>
                            </div>
                            <p class="text-gray-200 mt-1">
                                <?php echo htmlspecialchars($membership['gym_name']); ?>
                            </p>
                        </div>

                        <!-- Membership Details -->
                        <div class="p-6 space-y-4">
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <p class="text-yellow-400 text-sm">Valid From</p>
                                    <p class="text-white">
                                        <?php echo date('M j, Y', strtotime($membership['start_date'])); ?>
                                    </p>
                                </div>
                                <div>
                                    <p class="text-yellow-400 text-sm">Valid Until</p>
                                    <p class="text-white">
                                        <?php echo date('M j, Y', strtotime($membership['end_date'])); ?>
                                    </p>
                                </div>
                            </div>

                            <?php if (strtolower($membership['duration']) === 'daily'): ?>
                                <!-- Daily Pass Usage Info -->
                                <div class="bg-gray-700 bg-opacity-50 rounded-xl p-4">
                                    <h4 class="text-yellow-400 font-semibold mb-2">Daily Pass Usage</h4>
                                    <?php 
                                        $totalDays = $membership['total_days_purchased'] ?? 1;
                                        $usedDays = $membership['used_days'] ?? 0;
                                        $usagePercentage = ($usedDays / $totalDays) * 100;
                                    ?>
                                    <div class="flex justify-between text-white mb-2">
                                        <span>Used: <?= $usedDays ?> day(s)</span>
                                        <span>Total: <?= $totalDays ?> day(s)</span>
                                    </div>
                                    <div class="w-full bg-gray-600 rounded-full h-2.5">
                                        <div class="bg-gray-400 h-2.5 rounded-full" style="width: <?= $usagePercentage ?>%"></div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Price Info -->
                            <div class="flex justify-between items-center pt-4 border-t border-gray-700">
                                <div>
                                    <p class="text-yellow-400 text-sm">Price</p>
                                    <p class="text-white text-xl">
                                        ₹<?php echo number_format($membership['price'], 2); ?>
                                    </p>
                                </div>
                                <div>
                                    <a href="gym-profile.php?gym_id=<?= $membership['gym_id'] ?>" 
                                       class="inline-block bg-gray-600 hover:bg-gray-500 text-white font-bold py-2 px-4 rounded-full text-center transition duration-300">
                                        View Gym
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- No Memberships Message -->
        <?php if (empty($memberships)): ?>
            <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl p-8 text-center">
                <h2 class="text-3xl font-extrabold text-white mb-4">No Memberships Found</h2>
                <p class="text-gray-300 mb-6">You haven't purchased any gym memberships yet.</p>
                <a href="all-gyms.php" class="inline-block bg-yellow-400 hover:bg-yellow-500 text-black font-bold py-3 px-6 rounded-full transition duration-300">
                    Browse Gyms
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php include 'includes/footer.php'; ?>
