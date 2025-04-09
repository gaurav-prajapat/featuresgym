<?php
require_once '../config/database.php';

// Initialize database connection
$db = new GymDatabase();
$conn = $db->getConnection();

// Get parameters
$gymId = isset($_GET['gym_id']) ? (int)$_GET['gym_id'] : 0;
$city = isset($_GET['city']) ? $_GET['city'] : '';

if ($gymId <= 0 || empty($city)) {
    echo '<div class="col-span-full text-center py-4"><p class="text-gray-400">No similar gyms found.</p></div>';
    exit;
}

// Fetch similar gyms in the same city, excluding the current gym
$sql = "
    SELECT g.*, 
           (SELECT AVG(rating) FROM reviews r WHERE r.gym_id = g.gym_id AND r.status = 'approved') as avg_rating,
           (SELECT COUNT(*) FROM reviews r WHERE r.gym_id = g.gym_id AND r.status = 'approved') as review_count,
           (SELECT MIN(price) FROM gym_membership_plans gmp WHERE gmp.gym_id = g.gym_id AND gmp.duration = 'Daily') as daily_price
    FROM gyms g 
    WHERE g.city = ? 
    AND g.gym_id != ? 
    AND g.status = 'active'
    ORDER BY avg_rating DESC
    LIMIT 3
";

$stmt = $conn->prepare($sql);
$stmt->execute([$city, $gymId]);
$gyms = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($gyms)) {
    echo '<div class="col-span-full text-center py-4"><p class="text-gray-400">No similar gyms found in this area.</p></div>';
    exit;
}

// Output similar gyms
foreach ($gyms as $gym):
?>
    <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl overflow-hidden transform hover:scale-[1.02] transition-transform duration-300">
        <div class="relative">
            <img src="./gym/uploads/gym_images/<?php echo htmlspecialchars($gym['cover_photo'] ?? 'default_gym.jpg'); ?>"
                alt="<?php echo htmlspecialchars($gym['name']); ?>" class="w-full h-48 object-cover">
            
            <!-- Open/Closed Status Badge -->
            <div class="absolute top-3 left-3 <?= isset($gym['is_open']) && $gym['is_open'] ? 'bg-green-500' : 'bg-red-500' ?> text-white px-3 py-1 rounded-full text-sm font-bold">
                <?= isset($gym['is_open']) && $gym['is_open'] ? 'Open Now' : 'Closed' ?>
            </div>
        </div>

        <div class="p-6">
            <h3 class="text-xl font-bold text-white mb-2">
                <?php echo htmlspecialchars($gym['name']); ?>
            </h3>

            <div class="flex items-center space-x-2 mb-3">
                <?php
                $rating = round($gym['avg_rating'] ?? 0);
                for ($i = 1; $i <= 5; $i++):
                ?>
                    <svg class="h-5 w-5 <?php echo $i <= $rating ? 'text-yellow-400' : 'text-gray-600'; ?>"
                        fill="currentColor" viewBox="0 0 20 20">
                        <path d="M10 15l-5.878 3.09 1.123-6.545L.489 6.91l6.572-.955L10 0l2.939 5.955 6.572.955-4.756 4.635 1.123 6.545z" />
                    </svg>
                <?php endfor; ?>
                <span class="text-sm text-gray-400">(<?php echo $gym['review_count'] ?? 0; ?> reviews)</span>
            </div>

            <p class="text-gray-400 text-sm mb-3">
                <i class="fas fa-map-marker-alt text-yellow-400 mr-2"></i>
                <?php echo htmlspecialchars($gym['city']); ?>
            </p>

            <div class="flex flex-wrap gap-2 mb-4">
                <?php 
                $amenities = json_decode($gym['amenities'] ?? '[]', true);
                if (is_array($amenities)):
                    foreach (array_slice($amenities, 0, 3) as $amenity): 
                ?>
                    <span class="px-3 py-1 text-sm bg-gray-700 text-yellow-400 rounded-full">
                        <?php echo ucfirst($amenity); ?>
                    </span>
                <?php 
                    endforeach;
                    if (count($amenities) > 3): 
                ?>
                    <span class="px-3 py-1 text-sm bg-gray-700 text-yellow-400 rounded-full">
                        +<?php echo count($amenities) - 3; ?> more
                    </span>
                <?php 
                    endif;
                endif; 
                ?>
            </div>

            <div class="space-y-2 mb-4">
                <div class="flex justify-between items-center">
                    <span class="text-gray-400">Daily Rate</span>
                    <span class="text-lg font-bold text-yellow-400">
                        ₹<?php echo number_format($gym['daily_price'] ?? 0, 2); ?>
                    </span>
                </div>
            </div>

            <div class="flex justify-between items-center">
                <a href="gym-profile.php?id=<?php echo $gym['gym_id']; ?>"
                    class="text-yellow-400 hover:text-yellow-500 transition-colors">
                    View Details
                </a>
                <a href="schedule.php?gym_id=<?php echo $gym['gym_id']; ?>"
                    class="bg-yellow-400 text-black px-4 py-2 rounded-full font-bold hover:bg-yellow-500 transform hover:scale-105 transition-all duration-300">
                    Schedule Visit
                </a>
            </div>
        </div>
    </div>
<?php endforeach; ?>
