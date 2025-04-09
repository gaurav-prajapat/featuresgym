<?php
include '../includes/navbar.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: /gym/views/auth/login.php');
    exit();
}

require_once '../config/database.php';
$db = new GymDatabase();
$conn = $db->getConnection();

// Get user ID from URL
$user_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$user_id) {
    $_SESSION['error'] = "Invalid user ID.";
    header('Location: users.php');
    exit;
}

// Fetch user data
try {
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        $_SESSION['error'] = "User not found.";
        header('Location: users.php');
        exit;
    }
    
    // Fetch user memberships
    $stmt = $conn->prepare("
        SELECT um.*, g.name as gym_name, gmp.plan_name, gmp.tier
        FROM user_memberships um
        JOIN gyms g ON um.gym_id = g.gym_id
        JOIN gym_membership_plans gmp ON um.plan_id = gmp.plan_id
        WHERE um.user_id = ?
        ORDER BY um.created_at DESC
    ");
    $stmt->execute([$user_id]);
    $memberships = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch user bookings
    $stmt = $conn->prepare("
        SELECT s.*, g.name as gym_name
        FROM schedules s
        JOIN gyms g ON s.gym_id = g.gym_id
        WHERE s.user_id = ?
        ORDER BY s.start_date DESC, s.start_time DESC
        LIMIT 10
    ");
    $stmt->execute([$user_id]);
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch user payments
    $stmt = $conn->prepare("
        SELECT p.*, g.name as gym_name
        FROM payments p
        JOIN gyms g ON p.gym_id = g.gym_id
        WHERE p.user_id = ?
        ORDER BY p.payment_date DESC
        LIMIT 10
    ");
    $stmt->execute([$user_id]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch user reviews
    $stmt = $conn->prepare("
        SELECT r.*, g.name as gym_name
        FROM reviews r
        JOIN gyms g ON r.gym_id = g.gym_id
        WHERE r.user_id = ?
        ORDER BY r.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$user_id]);
    $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch activity logs
    $stmt = $conn->prepare("
        SELECT *
        FROM activity_logs
        WHERE user_id = ? AND user_type = 'member'
        ORDER BY created_at DESC
        LIMIT 20
    ");
    $stmt->execute([$user_id]);
    $activity_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $_SESSION['error'] = "Database error: " . $e->getMessage();
    header('Location: users.php');
    exit;
}
?>

<div class="container mx-auto px-4 py-8 pt-20">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">User Details</h1>
        <div class="flex space-x-2">
            <a href="users.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-arrow-left mr-2"></i> Back to Users
            </a>
            <a href="edit_user.php?id=<?= $user_id ?>" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-edit mr-2"></i> Edit User
            </a>
        </div>
    </div>
    
    <!-- User Profile Card -->
    <div class="bg-white shadow-md rounded-lg overflow-hidden mb-8">
        <div class="md:flex">
            <div class="md:w-1/3 bg-gray-50 p-8 flex flex-col items-center justify-center border-r">
                <?php if (!empty($user['profile_image'])): ?>
                    <img src="<?= htmlspecialchars($user['profile_image']) ?>" alt="Profile" class="h-48 w-48 rounded-full object-cover mb-4">
                <?php else: ?>
                    <div class="h-48 w-48 rounded-full bg-gray-200 flex items-center justify-center mb-4">
                        <i class="fas fa-user text-gray-400 text-6xl"></i>
                    </div>
                <?php endif; ?>
                
                <h2 class="text-2xl font-bold text-center"><?= htmlspecialchars($user['username']) ?></h2>
                <p class="text-gray-600 text-center mt-1">
                    <?php if ($user['role'] === 'admin'): ?>
                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-purple-100 text-purple-800">
                            Admin
                        </span>
                    <?php elseif ($user['role'] === 'gym_partner'): ?>
                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">
                            Gym Partner
                        </span>
                    <?php else: ?>
                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                            Member
                        </span>
                    <?php endif; ?>
                </p>
                
                <p class="text-gray-600 text-center mt-4">
                    <?php if ($user['status'] === 'active'): ?>
                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                            Active
                        </span>
                    <?php elseif ($user['status'] === 'inactive'): ?>
                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">
                            Inactive
                        </span>
                    <?php else: ?>
                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">
                            Suspended
                        </span>
                    <?php endif; ?>
                </p>
                
                <div class="mt-6 text-center">
                    <p class="text-gray-600">
                        <strong>Member since:</strong><br>
                        <?= date('F d, Y', strtotime($user['created_at'])) ?>
                    </p>
                </div>
            </div>
            
            <div class="md:w-2/3 p-8">
                <h3 class="text-xl font-semibold mb-4">User Information</h3>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <p class="text-gray-600 text-sm">Email</p>
                        <p class="font-medium"><?= htmlspecialchars($user['email']) ?></p>
                    </div>
                    
                    <div>
                        <p class="text-gray-600 text-sm">Phone</p>
                        <p class="font-medium"><?= htmlspecialchars($user['phone'] ?? 'Not provided') ?></p>
                    </div>
                    
                    <div>
                        <p class="text-gray-600 text-sm">City</p>
                        <p class="font-medium"><?= htmlspecialchars($user['city'] ?? 'Not provided') ?></p>
                    </div>
                    
                    <div>
                        <p class="text-gray-600 text-sm">Age</p>
                        <p class="font-medium"><?= $user['age'] ? htmlspecialchars($user['age']) : 'Not provided' ?></p>
                    </div>
                    
                    <div>
                        <p class="text-gray-600 text-sm">Balance</p>
                        <p class="font-medium">₹<?= number_format($user['balance'], 2) ?></p>
                    </div>
                    
                    <div>
                        <p class="text-gray-600 text-sm">Last Updated</p>
                        <p class="font-medium"><?= date('M d, Y H:i', strtotime($user['updated_at'])) ?></p>
                    </div>
                </div>
                
                <div class="mt-8 flex space-x-4">
                    <?php if ($user['status'] === 'active'): ?>
                        <a href="users.php?action=deactivate&id=<?= $user['id'] ?>" 
                           class="px-4 py-2 bg-yellow-500 text-white rounded-lg hover:bg-yellow-600"
                           onclick="return confirm('Are you sure you want to deactivate this user?');">
                            <i class="fas fa-user-slash mr-2"></i> Deactivate
                        </a>
                    <?php elseif ($user['status'] === 'inactive' || $user['status'] === 'suspended'): ?>
                        <a href="users.php?action=activate&id=<?= $user['id'] ?>" 
                           class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600"
                           onclick="return confirm('Are you sure you want to activate this user?');">
                            <i class="fas fa-user-check mr-2"></i> Activate
                        </a>
                    <?php endif; ?>
                    
                    <?php if ($user['status'] !== 'suspended'): ?>
                        <a href="users.php?action=suspend&id=<?= $user['id'] ?>" 
                           class="px-4 py-2 bg-red-500 text-white rounded-lg hover:bg-red-600"
                           onclick="return confirm('Are you sure you want to suspend this user?');">
                            <i class="fas fa-ban mr-2"></i> Suspend
                        </a>
                    <?php endif; ?>
                    
                    <a href="delete_user.php?id=<?= $user['id'] ?>" 
                       class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700"
                       onclick="return confirm('Are you sure you want to delete this user? This action cannot be undone.');">
                        <i class="fas fa-trash-alt mr-2"></i> Delete
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Tabs for different sections -->
    <div class="mb-6">
        <div class="border-b border-gray-200">
            <nav class="-mb-px flex space-x-8">
                <button id="tab-memberships" class="tab-button whitespace-nowrap py-4 px-1 border-b-2 border-indigo-500 font-medium text-sm text-indigo-600">
                    Memberships
                </button>
                <button id="tab-bookings" class="tab-button whitespace-nowrap py-4 px-1 border-b-2 border-transparent font-medium text-sm text-gray-500 hover:text-gray-700 hover:border-gray-300">
                    Bookings
                </button>
                <button id="tab-payments" class="tab-button whitespace-nowrap py-4 px-1 border-b-2 border-transparent font-medium text-sm text-gray-500 hover:text-gray-700 hover:border-gray-300">
                    Payments
                </button>
                <button id="tab-reviews" class="tab-button whitespace-nowrap py-4 px-1 border-b-2 border-transparent font-medium text-sm text-gray-500 hover:text-gray-700 hover:border-gray-300">
                    Reviews
                </button>
                <button id="tab-activity" class="tab-button whitespace-nowrap py-4 px-1 border-b-2 border-transparent font-medium text-sm text-gray-500 hover:text-gray-700 hover:border-gray-300">
                    Activity Log
                </button>
            </nav>
        </div>
    </div>
    
    <!-- Memberships Tab Content -->
    <div id="content-memberships" class="tab-content">
        <div class="bg-white shadow-md rounded-lg overflow-hidden">
            <div class="p-6 border-b">
                <h3 class="text-lg font-semibold">User Memberships</h3>
                <p class="text-sm text-gray-500">All memberships purchased by this user</p>
            </div>
            
            <?php if (empty($memberships)): ?>
                <div class="p-6 text-center text-gray-500">
                    <p>No memberships found for this user.</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead>
                            <tr class="bg-gray-50">
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Gym</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Plan</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tier</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Period</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Purchased</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($memberships as $membership): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        <?= htmlspecialchars($membership['gym_name']) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= htmlspecialchars($membership['plan_name']) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= htmlspecialchars($membership['tier']) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= date('M d, Y', strtotime($membership['start_date'])) ?> - 
                                        <?= date('M d, Y', strtotime($membership['end_date'])) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        ₹<?= number_format($membership['amount'], 2) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php if ($membership['status'] === 'active'): ?>
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                                Active
                                            </span>
                                        <?php elseif ($membership['status'] === 'expired'): ?>
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">
                                                Expired
                                            </span>
                                        <?php else: ?>
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">
                                                Cancelled
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= date('M d, Y', strtotime($membership['created_at'])) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Bookings Tab Content -->
    <div id="content-bookings" class="tab-content hidden">
        <div class="bg-white shadow-md rounded-lg overflow-hidden">
            <div class="p-6 border-b">
                <h3 class="text-lg font-semibold">Recent Bookings</h3>
                <p class="text-sm text-gray-500">Last 10 bookings made by this user</p>
            </div>
            
            <?php if (empty($bookings)): ?>
                <div class="p-6 text-center text-gray-500">
                    <p>No bookings found for this user.</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead>
                            <tr class="bg-gray-50">
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Gym</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Time</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Activity</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($bookings as $booking): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        <?= htmlspecialchars($booking['gym_name']) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= date('M d, Y', strtotime($booking['start_date'])) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= date('h:i A', strtotime($booking['start_time'])) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= htmlspecialchars(ucfirst($booking['activity_type'] ?? 'Gym Visit')) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php if ($booking['status'] === 'scheduled'): ?>
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">
                                                Scheduled
                                            </span>
                                        <?php elseif ($booking['status'] === 'completed'): ?>
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                                Completed
                                            </span>
                                        <?php elseif ($booking['status'] === 'cancelled'): ?>
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">
                                                Cancelled
                                            </span>
                                        <?php else: ?>
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">
                                                Missed
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= date('M d, Y', strtotime($booking['created_at'])) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Payments Tab Content -->
    <div id="content-payments" class="tab-content hidden">
        <div class="bg-white shadow-md rounded-lg overflow-hidden">
            <div class="p-6 border-b">
                <h3 class="text-lg font-semibold">Recent Payments</h3>
                <p class="text-sm text-gray-500">Last 10 payments made by this user</p>
            </div>
            
            <?php if (empty($payments)): ?>
                <div class="p-6 text-center text-gray-500">
                    <p>No payments found for this user.</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead>
                            <tr class="bg-gray-50">
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Gym</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Payment Method</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Transaction ID</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($payments as $payment): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        <?= htmlspecialchars($payment['gym_name']) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        ₹<?= number_format($payment['amount'], 2) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= htmlspecialchars($payment['payment_method'] ?? 'N/A') ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= htmlspecialchars($payment['transaction_id'] ?? 'N/A') ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php if ($payment['status'] === 'completed'): ?>
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                                Completed
                                            </span>
                                        <?php elseif ($payment['status'] === 'pending'): ?>
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">
                                                Pending
                                            </span>
                                        <?php elseif ($payment['status'] === 'failed'): ?>
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">
                                                Failed
                                            </span>
                                        <?php else: ?>
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">
                                                Refunded
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= date('M d, Y', strtotime($payment['payment_date'])) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Reviews Tab Content -->
    <div id="content-reviews" class="tab-content hidden">
        <div class="bg-white shadow-md rounded-lg overflow-hidden">
            <div class="p-6 border-b">
                <h3 class="text-lg font-semibold">User Reviews</h3>
                <p class="text-sm text-gray-500">Reviews submitted by this user</p>
            </div>
            
            <?php if (empty($reviews)): ?>
                <div class="p-6 text-center text-gray-500">
                    <p>No reviews found for this user.</p>
                </div>
            <?php else: ?>
                <div class="divide-y divide-gray-200">
                    <?php foreach ($reviews as $review): ?>
                        <div class="p-6">
                            <div class="flex justify-between items-start">
                                <div>
                                    <h4 class="font-medium text-gray-900"><?= htmlspecialchars($review['gym_name']) ?></h4>
                                    <div class="flex items-center mt-1">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <?php if ($i <= $review['rating']): ?>
                                                <i class="fas fa-star text-yellow-400"></i>
                                            <?php else: ?>
                                                <i class="far fa-star text-yellow-400"></i>
                                            <?php endif; ?>
                                        <?php endfor; ?>
                                        <span class="ml-2 text-sm text-gray-500">
                                            <?= date('M d, Y', strtotime($review['created_at'])) ?>
                                        </span>
                                    </div>
                                </div>
                                <div>
                                    <?php if ($review['status'] === 'approved'): ?>
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                            Approved
                                        </span>
                                    <?php elseif ($review['status'] === 'rejected'): ?>
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">
                                            Rejected
                                        </span>
                                    <?php else: ?>
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">
                                            Pending
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="mt-3 text-sm text-gray-700">
                                <?= nl2br(htmlspecialchars($review['comment'])) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Activity Log Tab Content -->
    <div id="content-activity" class="tab-content hidden">
        <div class="bg-white shadow-md rounded-lg overflow-hidden">
            <div class="p-6 border-b">
                <h3 class="text-lg font-semibold">Activity Log</h3>
                <p class="text-sm text-gray-500">Recent user activities</p>
            </div>
            
            <?php if (empty($activity_logs)): ?>
                <div class="p-6 text-center text-gray-500">
                    <p>No activity logs found for this user.</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead>
                            <tr class="bg-gray-50">
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Action</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Details</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">IP Address</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date & Time</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($activity_logs as $log): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        <?= htmlspecialchars($log['action']) ?>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-500">
                                        <?= htmlspecialchars($log['details'] ?? 'N/A') ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= htmlspecialchars($log['ip_address'] ?? 'N/A') ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= date('M d, Y H:i:s', strtotime($log['created_at'])) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    // Tab functionality
    const tabButtons = document.querySelectorAll('.tab-button');
    const tabContents = document.querySelectorAll('.tab-content');
    
    tabButtons.forEach(button => {
        button.addEventListener('click', () => {
            // Remove active class from all buttons
            tabButtons.forEach(btn => {
                btn.classList.remove('border-indigo-500', 'text-indigo-600');
                btn.classList.add('border-transparent', 'text-gray-500');
            });
            
            // Add active class to clicked button
            button.classList.remove('border-transparent', 'text-gray-500');
            button.classList.add('border-indigo-500', 'text-indigo-600');
            
            // Hide all tab contents
            tabContents.forEach(content => {
                content.classList.add('hidden');
            });
            
            // Show corresponding tab content
            const contentId = 'content-' + button.id.split('-')[1];
            document.getElementById(contentId).classList.remove('hidden');
        });
    });
</script>


