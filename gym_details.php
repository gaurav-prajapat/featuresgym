<?php
include 'includes/navbar.php';
require_once 'config/database.php';

$db = new GymDatabase();
$conn = $db->getConnection();

$gym_id = filter_input(INPUT_GET, 'gym_id', FILTER_VALIDATE_INT);
if (!$gym_id) {
    header('Location: all-gyms.php');
    exit();
}

// Fetch gym details with reviews
$stmt = $conn->prepare("
    SELECT g.*, 
           (SELECT AVG(rating) FROM reviews r WHERE r.gym_id = g.gym_id) as avg_rating,
           (SELECT COUNT(*) FROM reviews r WHERE r.gym_id = g.gym_id) as review_count
    FROM gyms g 
    WHERE g.gym_id = ?
");
$stmt->execute([$gym_id]);
$gym = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch recent reviews
$stmt = $conn->prepare("
    SELECT r.*, u.username 
    FROM reviews r 
    JOIN users u ON r.user_id = u.id 
    WHERE r.gym_id = ? AND r.status = 'approved'
    ORDER BY r.created_at DESC 
    LIMIT 5
");
$stmt->execute([$gym_id]);
$reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch operating hours
$stmt = $conn->prepare("
    SELECT * 
    FROM gym_operating_hours 
    WHERE gym_id = ?
");
$stmt->execute([$gym_id]);
$operating_hours = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch equipment details
$stmt = $conn->prepare("
    SELECT * 
    FROM gym_equipment 
    WHERE gym_id = ?
");
$stmt->execute([$gym_id]);
$equipment = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch gym images
$stmt = $conn->prepare("
    SELECT * 
    FROM gym_images 
    WHERE gym_id = ?
");
$stmt->execute([$gym_id]);
$gym_images = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Filter the operating hours to get daily hours
$daily_hours = array_filter($operating_hours, function ($hour) {
    return $hour['day'] === 'Daily';
});

// Fetch membership plans with proper error handling
$planStmt = $conn->prepare("
    SELECT DISTINCT
        gmp.*,
        coc.admin_cut_percentage,
        coc.gym_owner_cut_percentage
    FROM gym_membership_plans gmp
    LEFT JOIN cut_off_chart coc ON gmp.tier = coc.tier 
    AND gmp.duration = coc.duration
    WHERE gmp.gym_id = ?
    ORDER BY gmp.tier, 
    CASE gmp.duration
        WHEN 'Daily' THEN 1
        WHEN 'Weekly' THEN 2
        WHEN 'Monthly' THEN 3
        WHEN 'Quarterly' THEN 4
        WHEN 'Half Yearly' THEN 5
        WHEN 'Yearly' THEN 6
    END
");
$planStmt->execute([$gym_id]);
$plans = $planStmt->fetchAll(PDO::FETCH_ASSOC);



if (!isset($_SESSION['user_id']) && isset($_GET['gym_id'])) {  // Check for gym_id in GET parameter
    $_SESSION['return_to'] = $_SERVER['REQUEST_URI']; // Store the current URL in the session
}
?><div class="min-h-screen bg-white dark:bg-gradient-to-b dark:from-gray-900 dark:to-black py-12 transition-colors duration-200">
<div class=" mx-auto  sm:px-6 lg:px-8">
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl overflow-hidden">
        <!-- Header Section -->
        <div class="bg-gradient-to-r from-yellow-400 to-yellow-500 dark:from-yellow-500 dark:to-yellow-600 p-8">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900 dark:text-white mb-2">
                        <?php echo htmlspecialchars($gym['name']); ?>
                    </h1>
                    <p class="text-gray-800 dark:text-gray-200">
                        <i class="fas fa-location-dot mr-2"></i>
                        <?php echo htmlspecialchars($gym['address']); ?>
                    </p>
                </div>
                <a href="schedule.php?gym_id=<?php echo $gym['gym_id']; ?>" 
                   class="mt-4 md:mt-0 bg-gray-900 dark:bg-gray-700 text-gray-100 px-6 py-3 rounded-xl 
                          hover:bg-gray-800 dark:hover:bg-gray-600 transition-all duration-300 transform hover:scale-105">
                    <i class="fas fa-calendar-plus mr-2"></i>Schedule Visit
                </a>
            </div>
        </div>
        <!-- Error/Success Messages -->
        <?php if (isset($_SESSION['error'])): ?>
            <div class="bg-red-100 dark:bg-red-900 text-red-800 dark:text-red-100 p-4 rounded-xl my-4">
                <?= htmlspecialchars($_SESSION['error']) ?>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-100 p-4 rounded-xl mb-4">
                <?= htmlspecialchars($_SESSION['success']) ?>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        <!-- Main Content -->
        <div class="p-8">
            <!-- About Section -->
            <div class="bg-gray-50 dark:bg-gray-700 rounded-xl p-6 mb-8">
                <h2 class="text-2xl font-bold text-gray-900 dark:text-white mb-4">About</h2>
                <p class="text-gray-700 dark:text-white ">
                    <?php echo nl2br(htmlspecialchars($gym['description'])); ?>
                </p>
            </div>

            <!-- Amenities Section -->
            <div class="bg-gray-50 dark:bg-gray-700 rounded-xl p-6 mb-8">
                <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-4">Amenities</h3>
                <div class="flex flex-wrap gap-2">
                    <?php
                    $amenities = json_decode($gym['amenities'], true);
                    if ($amenities):
                        foreach ($amenities as $amenity): ?>
                            <span class="px-3 py-1 bg-gray-100 dark:bg-gray-600 text-gray-700 dark:text-white  
                                       rounded-full text-sm">
                                <?php echo ucfirst($amenity); ?>
                            </span>
                    <?php endforeach;
                    endif; ?>
                </div>
            </div>

            <!-- Operating Hours Section -->
            <div class="bg-gray-50 dark:bg-gray-700 rounded-xl p-6 mb-8">
                <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-4">Operating Hours</h3>
                <?php if ($daily_hours): ?>
                    <?php foreach ($daily_hours as $hour): ?>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="space-y-2">
                                <div class="flex items-center">
                                    <i class="fas fa-sun text-yellow-500 mr-2"></i>
                                    <span class="text-gray-700 dark:text-white  font-medium">Morning Hours</span>
                                </div>
                                <p class="text-gray-600 dark:text-gray-400">
                                    <?php echo date("h:i A", strtotime($hour['morning_open_time'])); ?> - 
                                    <?php echo date("h:i A", strtotime($hour['morning_close_time'])); ?>
                                </p>
                            </div>
                            <div class="space-y-2">
                                <div class="flex items-center">
                                    <i class="fas fa-moon text-yellow-500 mr-2"></i>
                                    <span class="text-gray-700 dark:text-white  font-medium">Evening Hours</span>
                                </div>
                                <p class="text-gray-600 dark:text-gray-400">
                                    <?php echo date("h:i A", strtotime($hour['evening_open_time'])); ?> - 
                                    <?php echo date("h:i A", strtotime($hour['evening_close_time'])); ?>
                                </p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Equipment Section -->
            <div class="bg-gray-50 dark:bg-gray-700 rounded-xl p-6 mb-8">
                <h2 class="text-2xl font-bold text-gray-900 dark:text-white mb-6">Equipment</h2>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
                    <?php foreach ($equipment as $item): ?>
                        <div class="bg-white dark:bg-gray-800 rounded-xl p-4 shadow-lg hover:shadow-xl transition-all duration-300">
                            <img src="./gym/uploads/equipments/<?php echo htmlspecialchars($item['image']); ?>" 
                                 alt="Equipment" 
                                 class="w-full h-32 object-cover rounded-lg mb-3">
                            <h4 class="font-semibold text-gray-900 dark:text-white">
                                <?php echo htmlspecialchars($item['name']); ?>
                            </h4>
                            <p class="text-sm text-gray-600 dark:text-gray-400">
                                Quantity: <?php echo htmlspecialchars($item['quantity']); ?>
                            </p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Gallery Section -->
            <div class="bg-gray-50 dark:bg-gray-700 rounded-xl p-6 mb-8">
                <h2 class="text-2xl font-bold text-gray-900 dark:text-white mb-6">Gallery</h2>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <?php foreach ($gym_images as $image): ?>
                        <?php if (file_exists("../gym/gym/uploads/gym_images/" . $image['image_path'])): ?>
                            <img src="../gym/gym/uploads/gym_images/<?php echo htmlspecialchars($image['image_path']); ?>" 
                                 alt="Gym Image" 
                                 class="w-full h-48 object-cover rounded-xl transform hover:scale-105 transition-all duration-300">
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Membership Plans Section -->
            <div class="mt-8"> 
    <h3 class="text-2xl font-bold text-gray-900 dark:text-white mb-6">Membership Plans</h3>
    <?php 
        $groupedPlans = [];
        foreach ($plans as $plan) {
            $groupedPlans[$plan['duration']][] = $plan;
        }
    ?>
    <?php foreach ($groupedPlans as $duration => $plansByDuration): ?>
        <h4 class="text-xl font-bold text-gray-700 dark:text-gray-300 mt-6 mb-4">
            <?php echo ucfirst($duration); ?> Plans
        </h4>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <?php foreach ($plansByDuration as $plan): ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 hover:shadow-xl transition-all duration-300">
                    <div class="text-xl font-bold mb-2 text-yellow-500">
                        <?php echo htmlspecialchars($plan['tier']); ?>
                    </div>
                    <div class="text-3xl font-bold mb-4 text-gray-900 dark:text-white">
                        ₹<?php echo number_format($plan['price'], 2); ?>
                        <span class="text-sm text-gray-600 dark:text-gray-400 font-normal">
                            /<?php echo strtolower($plan['duration']); ?>
                        </span>
                    </div>
                    <div class="text-gray-600 dark:text-white mb-4">
                        <?php 
                        $inclusions = explode(',', $plan['inclusions']);
                        foreach ($inclusions as $inclusion): ?>
                            <div class="flex items-center mb-2">
                                <i class="fas fa-check text-green-500 mr-2"></i>
                                <span><?php echo htmlspecialchars(trim($inclusion)); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                        <p>Best For: <?php echo htmlspecialchars($plan['best_for']); ?></p>
                    </div>
                    
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <a href="buy_membership.php?plan_id=<?php echo $plan['plan_id']; ?>&gym_id=<?php echo $gym_id; ?>"
                            class="block w-full bg-yellow-500 hover:bg-yellow-600 text-gray-900 text-center px-4 py-2 rounded-lg font-medium transition-colors duration-300">
                            Select Plan
                        </a>
                    <?php else: ?>
                        <a href="login.php" 
                            class="block w-full bg-yellow-500 hover:bg-yellow-600 text-gray-900 text-center px-4 py-2 rounded-lg font-medium transition-colors duration-300">
                            Login to Subscribe
                        </a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
</div>
        </div>
    </div>
    <!-- Reviews Section -->
<div class="mt-8 bg-gray-50 dark:bg-gray-700 rounded-xl p-6">
    <div class="flex items-center justify-between mb-6">
        <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Reviews</h2>
        <div class="flex items-center">
            <div class="flex text-yellow-400">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                    <svg class="h-5 w-5 <?php echo $i <= $gym['avg_rating'] ? 'fill-current' : 'fill-gray-300 dark:fill-gray-600'; ?>" viewBox="0 0 20 20">
                        <path d="M10 15l-5.878 3.09 1.123-6.545L.489 6.91l6.572-.955L10 0l2.939 5.955 6.572.955-4.756 4.635 1.123 6.545z" />
                    </svg>
                <?php endfor; ?>
            </div>
            <span class="ml-2 text-gray-700 dark:text-white ">
                <?php echo number_format($gym['avg_rating'], 1); ?> out of 5 (<?php echo $gym['review_count']; ?> reviews)
            </span>
        </div>
    </div>

    <!-- Review List -->
    <div class="space-y-4 mb-8">
        <?php foreach ($reviews as $review): ?>
            <div class="bg-white dark:bg-gray-800 rounded-xl p-4 shadow-lg">
                <div class="flex items-center justify-between mb-2">
                    <span class="font-medium text-gray-900 dark:text-white">
                        <?php echo htmlspecialchars($review['username']); ?>
                    </span>
                    <span class="text-sm text-gray-600 dark:text-gray-400">
                        <?php echo date('M j, Y', strtotime($review['created_at'])); ?>
                    </span>
                </div>
                <div class="flex text-yellow-400 mb-2">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <svg class="h-4 w-4 <?php echo $i <= $review['rating'] ? 'fill-current' : 'fill-gray-300 dark:fill-gray-600'; ?>" viewBox="0 0 20 20">
                            <path d="M10 15l-5.878 3.09 1.123-6.545L.489 6.91l6.572-.955L10 0l2.939 5.955 6.572.955-4.756 4.635 1.123 6.545z" />
                        </svg>
                    <?php endfor; ?>
                </div>
                <p class="text-gray-700 dark:text-white ">
                    <?php echo htmlspecialchars($review['comment']); ?>
                </p>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Write Review Form -->
    <div class="border-t border-gray-200 dark:border-gray-600 pt-8">
        <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-6">Write a Review</h3>
        <?php if (isset($_SESSION['user_id'])): ?>
            <form method="POST" action="submit_review.php" class="space-y-6">
                <input type="hidden" name="gym_id" value="<?php echo $gym_id; ?>">

                <div>
                    <label class="block text-gray-700 dark:text-white  mb-2">Rating</label>
                    <div class="flex items-center space-x-2">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <input type="radio" name="rating" value="<?php echo $i; ?>" required class="hidden peer" id="star<?php echo $i; ?>">
                            <label for="star<?php echo $i; ?>" class="cursor-pointer text-white  dark:text-gray-600 peer-checked:text-yellow-400 transition-colors">
                                <svg class="w-8 h-8" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                                </svg>
                            </label>
                        <?php endfor; ?>
                    </div>
                </div>

                <div>
                    <label class="block text-gray-700 dark:text-white  mb-2">Your Review</label>
                    <textarea name="comment" rows="4" required class="w-full px-4 py-2 rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-yellow-500 transition-all" placeholder="Share your experience..."></textarea>
                </div>

                <button type="submit" class="w-full bg-yellow-500 hover:bg-yellow-600 text-gray-900 font-bold py-3 px-6 rounded-xl transition-all duration-300 transform hover:scale-105">
                    Submit Review
                </button>
            </form>
        <?php else: ?>
            <div class="bg-white dark:bg-gray-800 rounded-xl p-6 text-center">
                <p class="text-gray-600 dark:text-gray-400">
                    Please <a href="login.php" class="text-yellow-500 hover:text-yellow-600 transition-colors">login</a> to write a review.
                </p>
            </div>
        <?php endif; ?>
    </div>
</div>
</div>
</div>
