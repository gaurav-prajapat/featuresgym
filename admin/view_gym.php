<?php
ob_start();
require_once '../config/database.php';
session_start();

// Check if user is logged in as admin
if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ./login.php');
    exit();
}

$db = new GymDatabase();
$conn = $db->getConnection();

// Get gym ID from URL
$gymId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($gymId <= 0) {
    header('Location: gyms.php');
    exit();
}

// Fetch gym details with owner information
$sql = "SELECT g.*, 
        u.name as owner_name, 
        u.email as owner_email,
        u.phone as owner_phone,
        (SELECT COUNT(*) FROM user_memberships um WHERE um.gym_id = g.gym_id AND um.status = 'active') as active_memberships,
        (SELECT COUNT(*) FROM reviews r WHERE r.gym_id = g.gym_id) as review_count,
        (SELECT AVG(rating) FROM reviews r WHERE r.gym_id = g.gym_id AND r.status = 'approved') as avg_rating
        FROM gyms g
        LEFT JOIN gym_owners u ON g.owner_id = u.id
        WHERE g.gym_id = ?";

$stmt = $conn->prepare($sql);
$stmt->execute([$gymId]);
$gym = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$gym) {
    header('Location: gyms.php');
    exit();
}

// Fetch membership plans
$plansSql = "SELECT *, 
            (SELECT COUNT(*) FROM user_memberships um WHERE um.plan_id = gmp.plan_id AND um.status = 'active') as active_members
            FROM gym_membership_plans gmp
            WHERE gym_id = ?
            ORDER BY price ASC";
$plansStmt = $conn->prepare($plansSql);
$plansStmt->execute([$gymId]);
$plans = $plansStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch operating hours
$hoursSql = "SELECT * FROM gym_operating_hours WHERE gym_id = ? ORDER BY FIELD(day, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday', 'Daily')";
$hoursStmt = $conn->prepare($hoursSql);
$hoursStmt->execute([$gymId]);
$operatingHours = $hoursStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch equipment
$equipmentSql = "SELECT * FROM gym_equipment WHERE gym_id = ? ORDER BY category, name";
$equipmentStmt = $conn->prepare($equipmentSql);
$equipmentStmt->execute([$gymId]);
$equipment = $equipmentStmt->fetchAll(PDO::FETCH_ASSOC);

// Group equipment by category
$groupedEquipment = [];
foreach ($equipment as $item) {
    $category = $item['category'] ?: 'Other';
    if (!isset($groupedEquipment[$category])) {
        $groupedEquipment[$category] = [];
    }
    $groupedEquipment[$category][] = $item;
}

// Fetch gallery images
$gallerySql = "SELECT * FROM gym_gallery WHERE gym_id = ? ORDER BY display_order ASC";
$galleryStmt = $conn->prepare($gallerySql);
$galleryStmt->execute([$gymId]);
$galleryImages = $galleryStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch recent reviews
$reviewsSql = "SELECT r.*, u.username, u.profile_image 
              FROM reviews r 
              LEFT JOIN users u ON r.user_id = u.id 
              WHERE r.gym_id = ? 
              ORDER BY r.created_at DESC 
              LIMIT 5";
$reviewsStmt = $conn->prepare($reviewsSql);
$reviewsStmt->execute([$gymId]);
$reviews = $reviewsStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch amenities data
$amenitiesData = [];
if (!empty($gym['amenities'])) {
    $amenitiesArray = json_decode($gym['amenities'], true);
    if (is_array($amenitiesArray) && !empty($amenitiesArray)) {
        $placeholders = implode(',', array_fill(0, count($amenitiesArray), '?'));
        $amenitiesSql = "SELECT id, name, category FROM amenities WHERE id IN ($placeholders)";
        $amenitiesStmt = $conn->prepare($amenitiesSql);
        $amenitiesStmt->execute($amenitiesArray);
        $amenitiesData = $amenitiesStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Fetch recent activity
$activitySql = "SELECT al.*, u.username 
               FROM activity_logs al 
               LEFT JOIN users u ON al.user_id = u.id 
               WHERE al.details LIKE ? 
               ORDER BY al.created_at DESC 
               LIMIT 10";
$activityStmt = $conn->prepare($activitySql);
$activityStmt->execute(['%gym (ID: ' . $gymId . ')%']);
$activities = $activityStmt->fetchAll(PDO::FETCH_ASSOC);

// Format date
function formatDate($date) {
    return date('M d, Y', strtotime($date));
}

// Format time
function formatTime($time) {
    return date('g:i A', strtotime($time));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Gym: <?php echo htmlspecialchars($gym['name']); ?> - FlexFit Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        .gallery-container {
            scroll-snap-type: x mandatory;
            scroll-padding: 1rem;
        }
        .gallery-item {
            scroll-snap-align: start;
        }
        .animate-fade-in {
            animation: fadeIn 0.5s ease-in-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body class="bg-gray-900 text-white">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="ml-0 lg:ml-64 p-4 sm:p-6 transition-all duration-200">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
            <div>
                <a href="gyms.php" class="text-gray-400 hover:text-white mb-2 inline-block">
                    <i class="fas fa-arrow-left mr-1"></i> Back to Gyms
                </a>
                <h1 class="text-2xl font-bold"><?php echo htmlspecialchars($gym['name']); ?></h1>
            </div>
            
            <div class="flex flex-wrap gap-2">
                <a href="edit_gym.php?id=<?php echo $gymId; ?>" class="bg-yellow-600 hover:bg-yellow-700 text-white font-bold py-2 px-4 rounded-lg transition-colors duration-200 flex items-center">
                    <i class="fas fa-edit mr-2"></i> Edit Gym
                </a>
                
                <a href="../gym-profile.php?id=<?php echo $gymId; ?>" target="_blank" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg transition-colors duration-200 flex items-center">
                    <i class="fas fa-external-link-alt mr-2"></i> View Public Page
                </a>
            </div>
        </div>
        
        <!-- Gym Header -->
        <div class="bg-gray-800 rounded-xl overflow-hidden mb-6 shadow-lg">
            <div class="h-64 bg-gray-700 relative">
                <img src="../uploads/gym_images/<?php echo htmlspecialchars($gym['cover_photo'] ?? 'default_gym.jpg'); ?>" 
                     alt="<?php echo htmlspecialchars($gym['name']); ?>" 
                     class="w-full h-full object-cover">
                
                <div class="absolute inset-0 bg-gradient-to-t from-gray-900 to-transparent"></div>
                
                <div class="absolute bottom-0 left-0 right-0 p-6">
                    <div class="flex flex-col md:flex-row md:items-end justify-between">
                        <div>
                            <h1 class="text-3xl font-bold text-white mb-2"><?php echo htmlspecialchars($gym['name']); ?></h1>
                            <div class="flex items-center mb-2">
                                <div class="flex items-center mr-4">
                                    <?php
                                    $avgRating = round($gym['avg_rating'] ?? 0, 1);
                                    $fullStars = floor($avgRating);
                                    $halfStar = $avgRating - $fullStars >= 0.5;
                                    $emptyStars = 5 - $fullStars - ($halfStar ? 1 : 0);
                                    
                                    for ($i = 0; $i < $fullStars; $i++) {
                                        echo '<i class="fas fa-star text-yellow-500 mr-1"></i>';
                                    }
                                    
                                    if ($halfStar) {
                                        echo '<i class="fas fa-star-half-alt text-yellow-500 mr-1"></i>';
                                    }
                                    
                                    for ($i = 0; $i < $emptyStars; $i++) {
                                        echo '<i class="far fa-star text-yellow-500 mr-1"></i>';
                                    }
                                    ?>
                                    <span class="text-white"><?php echo $avgRating; ?> (<?php echo $gym['review_count'] ?? 0; ?> reviews)</span>
                                </div>
                                <div class="text-white">
                                    <i class="fas fa-users mr-1"></i> <?php echo $gym['active_memberships'] ?? 0; ?> members
                                </div>
                            </div>
                            <p class="text-gray-300">
                                <i class="fas fa-map-marker-alt mr-1 text-yellow-500"></i> 
                                <?php echo htmlspecialchars($gym['address'] . ', ' . $gym['city'] . ', ' . $gym['state']); ?>
                            </p>
                        </div>
                        
                        <div class="mt-4 md:mt-0">
                            <?php
                            $statusClass = '';
                            $statusText = '';
                            
                            switch ($gym['status']) {
                                case 'active':
                                    $statusClass = 'bg-green-500 text-green-900';
                                    $statusText = 'Active';
                                    break;
                                case 'inactive':
                                    $statusClass = 'bg-yellow-500 text-yellow-900';
                                    $statusText = 'Inactive';
                                    break;
                                case 'pending':
                                    $statusClass = 'bg-blue-500 text-blue-900';
                                    $statusText = 'Pending';
                                    break;
                                case 'suspended':
                                    $statusClass = 'bg-red-500 text-red-900';
                                    $statusText = 'Suspended';
                                    break;
                                default:
                                    $statusClass = 'bg-gray-500 text-gray-900';
                                    $statusText = 'Unknown';
                            }
                            ?>
                            <span class="<?php echo $statusClass; ?> px-3 py-1 rounded-full text-sm font-bold">
                                <?php echo $statusText; ?>
                            </span>
                            
                            <?php if ($gym['is_featured']): ?>
                                <span class="bg-yellow-500 text-yellow-900 px-3 py-1 rounded-full text-sm font-bold ml-2">
                                    <i class="fas fa-star mr-1"></i> Featured
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Quick Stats -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 p-6">
                <div class="bg-gray-700 rounded-lg p-4">
                    <div class="text-gray-400 text-sm mb-1">Owner</div>
                    <div class="font-semibold"><?php echo htmlspecialchars($gym['owner_name'] ?? 'N/A'); ?></div>
                    <div class="text-sm text-gray-400"><?php echo htmlspecialchars($gym['owner_email'] ?? 'N/A'); ?></div>
                </div>
                
                <div class="bg-gray-700 rounded-lg p-4">
                    <div class="text-gray-400 text-sm mb-1">Contact</div>
                    <div class="font-semibold"><?php echo htmlspecialchars($gym['phone']); ?></div>
                    <div class="text-sm text-gray-400"><?php echo htmlspecialchars($gym['email']); ?></div>
                </div>
                
                <div class="bg-gray-700 rounded-lg p-4">
                    <div class="text-gray-400 text-sm mb-1">Capacity</div>
                    <div class="font-semibold"><?php echo $gym['current_occupancy']; ?> / <?php echo $gym['capacity']; ?></div>
                    <div class="w-full bg-gray-600 rounded-full h-2 mt-2">
                        <?php $occupancyPercentage = ($gym['capacity'] > 0) ? min(100, ($gym['current_occupancy'] / $gym['capacity']) * 100) : 0; ?>
                        <div class="bg-blue-500 h-2 rounded-full" style="width: <?php echo $occupancyPercentage; ?>%"></div>
                    </div>
                </div>
                
                <div class="bg-gray-700 rounded-lg p-4">
                    <div class="text-gray-400 text-sm mb-1">Added On</div>
                    <div class="font-semibold"><?php echo formatDate($gym['created_at']); ?></div>
                    <div class="text-sm text-gray-400">
                        <?php echo $gym['is_open'] ? '<span class="text-green-400">Currently Open</span>' : '<span class="text-red-400">Currently Closed</span>'; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Tab Navigation -->
        <div class="bg-gray-800 rounded-xl overflow-hidden mb-6 shadow-lg">
            <div class="border-b border-gray-700">
                <nav class="flex overflow-x-auto">
                    <button class="tab-btn px-6 py-4 text-center whitespace-nowrap border-b-2 border-blue-500 text-blue-500 font-medium" data-tab="details">
                        <i class="fas fa-info-circle mr-2"></i> Details
                    </button>
                    <button class="tab-btn px-6 py-4 text-center whitespace-nowrap border-b-2 border-transparent hover:text-gray-300 font-medium" data-tab="plans">
                        <i class="fas fa-id-card mr-2"></i> Membership Plans
                    </button>
                    <button class="tab-btn px-6 py-4 text-center whitespace-nowrap border-b-2 border-transparent hover:text-gray-300 font-medium" data-tab="hours">
                        <i class="fas fa-clock mr-2"></i> Operating Hours
                    </button>
                    <button class="tab-btn px-6 py-4 text-center whitespace-nowrap border-b-2 border-transparent hover:text-gray-300 font-medium" data-tab="equipment">
                        <i class="fas fa-dumbbell mr-2"></i> Equipment
                    </button>
                    <button class="tab-btn px-6 py-4 text-center whitespace-nowrap border-b-2 border-transparent hover:text-gray-300 font-medium" data-tab="gallery">
                        <i class="fas fa-images mr-2"></i> Gallery
                    </button>
                    <button class="tab-btn px-6 py-4 text-center whitespace-nowrap border-b-2 border-transparent hover:text-gray-300 font-medium" data-tab="reviews">
                        <i class="fas fa-star mr-2"></i> Reviews
                    </button>
                    <button class="tab-btn px-6 py-4 text-center whitespace-nowrap border-b-2 border-transparent hover:text-gray-300 font-medium" data-tab="activity">
                        <i class="fas fa-history mr-2"></i> Activity
                    </button>
                </nav>
            </div>
            
            <!-- Tab Content -->
            <div class="p-6">
                <!-- Details Tab -->
                <div id="details-tab" class="tab-content active animate-fade-in">
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <div>
                            <h2 class="text-xl font-semibold mb-4">Gym Information</h2>
                            <div class="bg-gray-700 rounded-lg p-4 mb-6">
                                <h3 class="text-lg font-medium mb-3">Description</h3>
                                <p class="text-gray-300"><?php echo nl2br(htmlspecialchars($gym['description'])); ?></p>
                            </div>
                            
                            <div class="bg-gray-700 rounded-lg p-4">
                                <h3 class="text-lg font-medium mb-3">Location Details</h3>
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <div class="text-gray-400 text-sm">Address</div>
                                        <div class="font-medium"><?php echo htmlspecialchars($gym['address']); ?></div>
                                    </div>
                                    <div>
                                        <div class="text-gray-400 text-sm">City</div>
                                        <div class="font-medium"><?php echo htmlspecialchars($gym['city']); ?></div>
                                    </div>
                                    <div>
                                        <div class="text-gray-400 text-sm">State</div>
                                        <div class="font-medium"><?php echo htmlspecialchars($gym['state']); ?></div>
                                    </div>
                                    <div>
                                        <div class="text-gray-400 text-sm">Zip Code</div>
                                        <div class="font-medium"><?php echo htmlspecialchars($gym['zip_code']); ?></div>
                                    </div>
                                </div>
                                
                                <?php if ($gym['latitude'] && $gym['longitude']): ?>
                                    <div class="mt-4">
                                        <div class="text-gray-400 text-sm mb-1">Map Coordinates</div>
                                        <div class="font-medium">
                                            Lat: <?php echo $gym['latitude']; ?>, Lng: <?php echo $gym['longitude']; ?>
                                            <a href="https://maps.google.com/?q=<?php echo $gym['latitude']; ?>,<?php echo $gym['longitude']; ?>" target="_blank" class="text-blue-400 hover:text-blue-300 ml-2">
                                                <i class="fas fa-external-link-alt"></i> View on Map
                                            </a>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div>
                            <h2 class="text-xl font-semibold mb-4">Owner Information</h2>
                            <div class="bg-gray-700 rounded-lg p-4 mb-6">
                                <div class="flex items-center mb-4">
                                    <div class="w-12 h-12 rounded-full bg-blue-500 flex items-center justify-center text-white font-bold text-xl mr-3">
                                        <?php echo substr($gym['owner_name'] ?? 'U', 0, 1); ?>
                                    </div>
                                    <div>
                                        <div class="font-semibold text-lg"><?php echo htmlspecialchars($gym['owner_name'] ?? 'Unknown'); ?></div>
                                        <div class="text-gray-400">Gym Owner</div>
                                    </div>
                                </div>
                                
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <div class="text-gray-400 text-sm">Email</div>
                                        <div class="font-medium"><?php echo htmlspecialchars($gym['owner_email'] ?? 'N/A'); ?></div>
                                    </div>
                                    <div>
                                        <div class="text-gray-400 text-sm">Phone</div>
                                        <div class="font-medium"><?php echo htmlspecialchars($gym['owner_phone'] ?? 'N/A'); ?></div>
                                    </div>
                                </div>
                                
                                <div class="mt-4">
                                    <a href="gym_owners.php?id=<?php echo $gym['owner_id']; ?>" class="text-blue-400 hover:text-blue-300">
                                        <i class="fas fa-user mr-1"></i> View Owner Profile
                                    </a>
                                </div>
                            </div>
                            
                            <h2 class="text-xl font-semibold mb-4">Amenities</h2>
                            <div class="bg-gray-700 rounded-lg p-4">
                                <?php if (empty($amenitiesData)): ?>
                                    <p class="text-gray-400">No amenities listed for this gym.</p>
                                <?php else: ?>
                                    <div class="grid grid-cols-2 gap-2">
                                        <?php foreach ($amenitiesData as $amenity): ?>
                                            <div class="flex items-center">
                                                <i class="fas fa-check-circle text-green-500 mr-2"></i>
                                                <span><?php echo htmlspecialchars($amenity['name']); ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Membership Plans Tab -->
                <div id="plans-tab" class="tab-content animate-fade-in">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-xl font-semibold">Membership Plans</h2>
                        <a href="membership_plans.php?gym_id=<?php echo $gymId; ?>" class="text-blue-400 hover:text-blue-300">
                            <i class="fas fa-external-link-alt mr-1"></i> Manage Plans
                        </a>
                    </div>
                    
                    <?php if (empty($plans)): ?>
                        <div class="bg-gray-700 rounded-lg p-6 text-center">
                            <p class="text-gray-400 mb-4">No membership plans found for this gym.</p>
                            <a href="membership_plans.php?gym_id=<?php echo $gymId; ?>" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg transition-colors duration-200">
                                <i class="fas fa-plus mr-2"></i> Add Membership Plan
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            <?php foreach ($plans as $plan): ?>
                                <div class="bg-gray-700 rounded-lg p-4 border-l-4 
                                    <?php 
                                    switch ($plan['tier']) {
                                        case 'Tier 1': echo 'border-green-500'; break;
                                        case 'Tier 2': echo 'border-blue-500'; break;
                                        case 'Tier 3': echo 'border-purple-500'; break;
                                        default: echo 'border-gray-500';
                                    }
                                    ?>">
                                    <div class="flex justify-between items-start mb-2">
                                        <h3 class="text-lg font-semibold"><?php echo htmlspecialchars($plan['plan_name']); ?></h3>
                                        <div class="bg-yellow-500 text-black font-bold px-2 py-1 rounded-full text-sm">
                                            ₹<?php echo number_format($plan['price'], 2); ?>
                                        </div>
                                    </div>
                                    
                                    <div class="flex items-center mb-2">
                                        <span class="text-xs px-2 py-0.5 rounded-full mr-2
                                            <?php 
                                            switch ($plan['tier']) {
                                                case 'Tier 1': echo 'bg-green-500 bg-opacity-20 text-green-400'; break;
                                                case 'Tier 2': echo 'bg-blue-500 bg-opacity-20 text-blue-400'; break;
                                                case 'Tier 3': echo 'bg-purple-500 bg-opacity-20 text-purple-400'; break;
                                                default: echo 'bg-gray-500 bg-opacity-20 text-gray-400';
                                            }
                                            ?>">
                                            <?php echo htmlspecialchars($plan['tier']); ?>
                                        </span>
                                        <span class="text-xs px-2 py-0.5 bg-gray-600 text-gray-300 rounded-full">
                                            <?php echo htmlspecialchars($plan['duration']); ?>
                                        </span>
                                    </div>
                                    
                                    <?php if (!empty($plan['inclusions'])): ?>
                                        <div class="text-sm text-gray-300 mb-3">
                                            <?php echo nl2br(htmlspecialchars($plan['inclusions'])); ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="text-xs text-gray-400 mb-3">
                                        <i class="fas fa-user-check mr-1"></i> Best for: <?php echo htmlspecialchars($plan['best_for'] ?? 'All members'); ?>
                                    </div>
                                    
                                    <div class="text-sm bg-blue-900 bg-opacity-30 p-2 rounded-lg">
                                        <i class="fas fa-users mr-1 text-blue-400"></i>
                                        <span class="text-blue-300"><?php echo $plan['active_members']; ?> active members</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Operating Hours Tab -->
                <div id="hours-tab" class="tab-content animate-fade-in">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-xl font-semibold">Operating Hours</h2>
                        <a href="edit_gym.php?id=<?php echo $gymId; ?>#operating-hours" class="text-blue-400 hover:text-blue-300">
                            <i class="fas fa-edit mr-1"></i> Edit Hours
                        </a>
                    </div>
                    
                    <?php if (empty($operatingHours)): ?>
                        <div class="bg-gray-700 rounded-lg p-6 text-center">
                            <p class="text-gray-400">No operating hours set for this gym.</p>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full bg-gray-700 rounded-lg overflow-hidden">
                                <thead>
                                    <tr>
                                        <th class="py-3 px-4 bg-gray-600 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Day</th>
                                        <th class="py-3 px-4 bg-gray-600 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Morning Hours</th>
                                        <th                                         <th class="py-3 px-4 bg-gray-600 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Evening Hours</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-600">
                                    <?php 
                                    $today = date('l');
                                    foreach ($operatingHours as $hours): 
                                        $isToday = $hours['day'] === $today;
                                    ?>
                                        <tr class="<?php echo $isToday ? 'bg-blue-900 bg-opacity-20' : ''; ?>">
                                            <td class="py-3 px-4 text-sm text-gray-300 font-medium">
                                                <?php echo htmlspecialchars($hours['day']); ?>
                                                <?php if ($isToday): ?>
                                                    <span class="ml-2 bg-blue-500 text-white text-xs px-2 py-0.5 rounded-full">Today</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="py-3 px-4 text-sm text-gray-300">
                                                <?php 
                                                if ($hours['morning_open_time'] && $hours['morning_close_time']) {
                                                    echo formatTime($hours['morning_open_time']) . ' - ' . formatTime($hours['morning_close_time']);
                                                } else {
                                                    echo '<span class="text-gray-500">Closed</span>';
                                                }
                                                ?>
                                            </td>
                                            <td class="py-3 px-4 text-sm text-gray-300">
                                                <?php 
                                                if ($hours['evening_open_time'] && $hours['evening_close_time']) {
                                                    echo formatTime($hours['evening_open_time']) . ' - ' . formatTime($hours['evening_close_time']);
                                                } else {
                                                    echo '<span class="text-gray-500">Closed</span>';
                                                }
                                                ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Equipment Tab -->
                <div id="equipment-tab" class="tab-content animate-fade-in">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-xl font-semibold">Equipment</h2>
                        <a href="equipment.php?gym_id=<?php echo $gymId; ?>" class="text-blue-400 hover:text-blue-300">
                            <i class="fas fa-external-link-alt mr-1"></i> Manage Equipment
                        </a>
                    </div>
                    
                    <?php if (empty($equipment)): ?>
                        <div class="bg-gray-700 rounded-lg p-6 text-center">
                            <p class="text-gray-400 mb-4">No equipment listed for this gym.</p>
                            <a href="equipment.php?gym_id=<?php echo $gymId; ?>" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg transition-colors duration-200">
                                <i class="fas fa-plus mr-2"></i> Add Equipment
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="space-y-6">
                            <?php foreach ($groupedEquipment as $category => $items): ?>
                                <div>
                                    <h3 class="text-lg font-semibold text-blue-400 mb-3"><?php echo htmlspecialchars($category); ?></h3>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                                        <?php foreach ($items as $item): ?>
                                            <div class="bg-gray-700 rounded-lg p-3">
                                                <div class="flex justify-between items-center">
                                                    <span class="text-gray-300"><?php echo htmlspecialchars($item['name']); ?></span>
                                                    <span class="bg-gray-600 text-yellow-500 text-xs px-2 py-1 rounded-full">
                                                        <?php echo htmlspecialchars($item['quantity']); ?> units
                                                    </span>
                                                </div>
                                                <?php if (!empty($item['description'])): ?>
                                                    <div class="text-xs text-gray-400 mt-1"><?php echo htmlspecialchars($item['description']); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Gallery Tab -->
                <div id="gallery-tab" class="tab-content animate-fade-in">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-xl font-semibold">Gallery</h2>
                        <a href="gallery.php?gym_id=<?php echo $gymId; ?>" class="text-blue-400 hover:text-blue-300">
                            <i class="fas fa-external-link-alt mr-1"></i> Manage Gallery
                        </a>
                    </div>
                    
                    <?php if (empty($galleryImages)): ?>
                        <div class="bg-gray-700 rounded-lg p-6 text-center">
                            <p class="text-gray-400 mb-4">No gallery images found for this gym.</p>
                            <a href="gallery.php?gym_id=<?php echo $gymId; ?>" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg transition-colors duration-200">
                                <i class="fas fa-plus mr-2"></i> Add Images
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="gallery-container flex overflow-x-auto pb-4 space-x-4">
                            <?php foreach ($galleryImages as $image): ?>
                                <div class="gallery-item flex-shrink-0 w-60 h-40 md:w-72 md:h-48 rounded-lg overflow-hidden">
                                    <img src="../uploads/gym_images/<?php echo htmlspecialchars($image['image_path']); ?>" 
                                         alt="<?php echo htmlspecialchars($image['caption'] ?? 'Gym image'); ?>" 
                                         class="w-full h-full object-cover hover:scale-110 transition duration-500 cursor-pointer"
                                         onclick="openLightbox('<?php echo htmlspecialchars($image['image_path']); ?>')">
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Reviews Tab -->
                <div id="reviews-tab" class="tab-content animate-fade-in">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-xl font-semibold">Recent Reviews</h2>
                        <a href="reviews.php?gym_id=<?php echo $gymId; ?>" class="text-blue-400 hover:text-blue-300">
                            <i class="fas fa-external-link-alt mr-1"></i> View All Reviews
                        </a>
                    </div>
                    
                    <?php if (empty($reviews)): ?>
                        <div class="bg-gray-700 rounded-lg p-6 text-center">
                            <p class="text-gray-400">No reviews found for this gym.</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($reviews as $review): ?>
                                <div class="bg-gray-700 rounded-lg p-4">
                                    <div class="flex items-center justify-between mb-2">
                                        <div class="flex items-center">
                                            <div class="w-10 h-10 rounded-full overflow-hidden mr-3">
                                                <img src="<?php echo !empty($review['profile_image']) ? '../uploads/profile_images/' . htmlspecialchars($review['profile_image']) : '../assets/images/default_avatar.png'; ?>" 
                                                     alt="User" class="w-full h-full object-cover">
                                            </div>
                                            <div>
                                                <div class="font-medium"><?php echo htmlspecialchars($review['username']); ?></div>
                                                <div class="text-xs text-gray-400"><?php echo date('M d, Y', strtotime($review['created_at'])); ?></div>
                                            </div>
                                        </div>
                                        <div class="flex">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <i class="<?php echo $i <= $review['rating'] ? 'fas' : 'far'; ?> fa-star text-yellow-500"></i>
                                            <?php endfor; ?>
                                        </div>
                                    </div>
                                    <p class="text-gray-300"><?php echo nl2br(htmlspecialchars($review['comment'])); ?></p>
                                    
                                    <div class="mt-3 flex justify-between items-center">
                                        <div class="text-xs text-gray-400">
                                            Status: 
                                            <span class="px-2 py-0.5 rounded-full 
                                                <?php echo $review['status'] === 'approved' ? 'bg-green-900 text-green-300' : 'bg-yellow-900 text-yellow-300'; ?>">
                                                <?php echo ucfirst($review['status']); ?>
                                            </span>
                                        </div>
                                        <a href="reviews.php?id=<?php echo $review['id']; ?>" class="text-blue-400 hover:text-blue-300 text-sm">
                                            <i class="fas fa-edit mr-1"></i> Manage
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Activity Tab -->
                <div id="activity-tab" class="tab-content animate-fade-in">
                    <h2 class="text-xl font-semibold mb-4">Recent Activity</h2>
                    
                    <?php if (empty($activities)): ?>
                        <div class="bg-gray-700 rounded-lg p-6 text-center">
                            <p class="text-gray-400">No activity recorded for this gym.</p>
                        </div>
                    <?php else: ?>
                        <div class="bg-gray-700 rounded-lg overflow-hidden">
                            <div class="overflow-x-auto">
                                <table class="min-w-full">
                                    <thead>
                                        <tr>
                                            <th class="py-3 px-4 bg-gray-600 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Timestamp</th>
                                            <th class="py-3 px-4 bg-gray-600 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">User</th>
                                            <th class="py-3 px-4 bg-gray-600 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Action</th>
                                            <th class="py-3 px-4 bg-gray-600 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Details</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-600">
                                        <?php foreach ($activities as $activity): ?>
                                            <tr>
                                                <td class="py-3 px-4 text-sm text-gray-300">
                                                    <?php echo date('M d, Y g:i A', strtotime($activity['timestamp'])); ?>
                                                </td>
                                                <td class="py-3 px-4 text-sm text-gray-300">
                                                    <?php echo htmlspecialchars($activity['username'] ?? 'Unknown User'); ?>
                                                    <span class="text-xs text-gray-400">(<?php echo $activity['user_type']; ?>)</span>
                                                </td>
                                                <td class="py-3 px-4 text-sm">
                                                    <?php
                                                    $actionClass = '';
                                                    switch ($activity['action']) {
                                                        case 'create_gym':
                                                            $actionClass = 'text-green-400';
                                                            break;
                                                        case 'update_gym':
                                                        case 'update_gym_status':
                                                            $actionClass = 'text-blue-400';
                                                            break;
                                                        case 'delete_gym':
                                                            $actionClass = 'text-red-400';
                                                            break;
                                                        case 'feature_gym':
                                                        case 'unfeature_gym':
                                                            $actionClass = 'text-yellow-400';
                                                            break;
                                                        default:
                                                            $actionClass = 'text-gray-300';
                                                    }
                                                    ?>
                                                    <span class="<?php echo $actionClass; ?>">
                                                        <?php echo str_replace('_', ' ', ucfirst($activity['action'])); ?>
                                                    </span>
                                                </td>
                                                <td class="py-3 px-4 text-sm text-gray-300">
                                                    <?php echo htmlspecialchars($activity['details']); ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Image Lightbox -->
    <div id="lightbox" class="fixed inset-0 bg-black bg-opacity-90 z-50 flex items-center justify-center hidden">
        <button id="close-lightbox" class="absolute top-4 right-4 text-white text-2xl">
            <i class="fas fa-times"></i>
        </button>
        <img id="lightbox-image" src="" alt="Gym image" class="max-w-full max-h-[90vh] object-contain">
    </div>
    
    <script>
                // Tab functionality
                document.addEventListener('DOMContentLoaded', function() {
            const tabButtons = document.querySelectorAll('.tab-btn');
            const tabContents = document.querySelectorAll('.tab-content');
            
            tabButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const tabName = this.getAttribute('data-tab');
                    
                    // Update active tab button
                    tabButtons.forEach(btn => {
                        btn.classList.remove('border-blue-500', 'text-blue-500');
                        btn.classList.add('border-transparent', 'hover:text-gray-300');
                    });
                    this.classList.remove('border-transparent', 'hover:text-gray-300');
                    this.classList.add('border-blue-500', 'text-blue-500');
                    
                    // Show active tab content
                    tabContents.forEach(content => {
                        content.classList.remove('active');
                    });
                    document.getElementById(`${tabName}-tab`).classList.add('active');
                });
            });
            
            // Check if URL has a hash to activate specific tab
            if (window.location.hash) {
                const tabName = window.location.hash.substring(1);
                const tabButton = document.querySelector(`.tab-btn[data-tab="${tabName}"]`);
                if (tabButton) {
                    tabButton.click();
                }
            }
        });
        
        // Lightbox functionality
        function openLightbox(imagePath) {
            const lightbox = document.getElementById('lightbox');
            const lightboxImage = document.getElementById('lightbox-image');
            
            lightboxImage.src = '../uploads/gym_images/' + imagePath;
            lightbox.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }
        
        document.getElementById('close-lightbox').addEventListener('click', function() {
            document.getElementById('lightbox').classList.add('hidden');
            document.body.style.overflow = '';
        });
        
        // Close lightbox when clicking outside the image
        document.getElementById('lightbox').addEventListener('click', function(e) {
            if (e.target === this) {
                this.classList.add('hidden');
                document.body.style.overflow = '';
            }
        });
        
        // Close lightbox with ESC key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && !document.getElementById('lightbox').classList.contains('hidden')) {
                document.getElementById('lightbox').classList.add('hidden');
                document.body.style.overflow = '';
            }
        });
        
        // Smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function(e) {
                const targetId = this.getAttribute('href');
                
                if (targetId === '#') return;
                
                const targetElement = document.querySelector(targetId);
                
                if (targetElement) {
                    e.preventDefault();
                    
                    const tabId = targetId.split('-')[0].substring(1);
                    const tabButton = document.querySelector(`.tab-btn[data-tab="${tabId}"]`);
                    
                    if (tabButton) {
                        tabButton.click();
                    }
                    
                    targetElement.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });
    </script>
</body>
</html>



